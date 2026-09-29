<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Domain\Access\RecordAccessVoter;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\Page\RootLineException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Decides which page, if any, a page chosen in a plugin setting may be
 * linked as.
 *
 * Only a standard page (doktype 1) is linked, chosen directly or reached
 * through shortcuts. Every other page type is refused, so no URL an editor
 * types reaches a link. Whether the visitor who follows the link may see the
 * page is decided by core, for a context that differs from the current one
 * only in the frontend user aspect: a visitor holding "any login" for the
 * login target, an anonymous one for the password page. Core's PageRepository
 * reads the pages for that visitor (enable fields, groups, workspace,
 * language), and core's RecordAccessVoter judges every page of its rootline
 * as core's RootlineUtility returns it.
 */
final readonly class LinkablePageResolver
{
    /**
     * Groups of a visitor who has just logged in: core's "any login" group.
     * A group the login may or may not grant is not assumed.
     */
    private const GROUPS_AFTER_LOGIN = [0, -2];

    /**
     * Groups of a visitor who is not logged in: core's "hide at login" group.
     */
    private const GROUPS_BEFORE_LOGIN = [0, -1];

    /**
     * pages.shortcut_mode "random subpage". TYPO3 13.4 names it
     * PageRepository::SHORTCUT_MODE_RANDOM_SUBPAGE; 14.3 no longer has the
     * constant, but a stored value 2 may remain.
     */
    private const SHORTCUT_MODE_RANDOM_SUBPAGE = 2;

    /**
     * Upper bound for the shortcut hops followed, as core's own resolution.
     */
    private const MAX_SHORTCUT_HOPS = 20;

    public function __construct(
        private RecordAccessVoter $accessVoter,
        private Context $context,
    ) {}

    /**
     * The uid of the standard page a visitor reaches through $pageUid, or
     * null when there is none or the visitor may not see it.
     *
     * @param bool $afterLogin whether the link is followed after a login (the
     *                         login target) or before one (the password page)
     */
    public function resolve(int $pageUid, bool $afterLogin): ?int
    {
        if ($pageUid <= 0) {
            return null;
        }

        $context = clone $this->context;
        $context->setAspect(
            'frontend.user',
            new UserAspect(null, $afterLogin ? self::GROUPS_AFTER_LOGIN : self::GROUPS_BEFORE_LOGIN),
        );
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);
        $page = $this->standardPage($pageUid, $pageRepository);

        return $page !== null && $this->isVisible($page, $context) ? $this->intOf($page['uid'] ?? null) : null;
    }

    /**
     * The record of the standard page $pageUid is or leads to through
     * shortcuts, followed one hop at a time; null for anything else. A
     * "random subpage" shortcut is refused at every hop: which page it leads
     * to is not known when the link is built.
     *
     * @return array<array-key, mixed>|null
     */
    private function standardPage(int $pageUid, PageRepository $pageRepository): ?array
    {
        // A loop ends as a shortcut after the last hop and is refused below.
        $page = $pageRepository->getPage($pageUid);
        for ($hop = 0; $this->doktypeOf($page) === PageRepository::DOKTYPE_SHORTCUT && $hop < self::MAX_SHORTCUT_HOPS; $hop++) {
            $page = $this->shortcutTarget($page, $pageRepository);
        }

        return $this->doktypeOf($page) === PageRepository::DOKTYPE_DEFAULT ? $page : null;
    }

    /**
     * The next page of a shortcut, read as core reads it for that shortcut
     * mode; an empty array for a random subpage or a target the visitor
     * cannot reach.
     *
     * @param array<array-key, mixed> $shortcut
     *
     * @return array<array-key, mixed>
     */
    private function shortcutTarget(array $shortcut, PageRepository $pageRepository): array
    {
        $mode = $this->intOf($shortcut['shortcut_mode'] ?? null);
        $target = $this->intOf($shortcut['shortcut'] ?? null);
        $uid = $this->intOf($shortcut['uid'] ?? null);

        return match ($mode) {
            self::SHORTCUT_MODE_RANDOM_SUBPAGE => [],
            PageRepository::SHORTCUT_MODE_FIRST_SUBPAGE => $this->firstSubpage($target > 0 ? $target : $uid, $pageRepository),
            PageRepository::SHORTCUT_MODE_PARENT_PAGE => $this->parentPage($target > 0 ? $target : $uid, $pageRepository),
            default => $target > 0 ? $pageRepository->getPage($target) : [],
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private function firstSubpage(int $pageUid, PageRepository $pageRepository): array
    {
        // The query core's shortcut resolution runs for "first subpage".
        $excludedDoktypes = [
            PageRepository::DOKTYPE_SPACER,
            PageRepository::DOKTYPE_SYSFOLDER,
            PageRepository::DOKTYPE_BE_USER_SECTION,
        ];
        $subpages = $pageRepository->getMenu(
            $pageUid,
            '*',
            'sorting',
            'AND pages.doktype NOT IN (' . \implode(', ', $excludedDoktypes) . ')',
        );
        $first = \reset($subpages);

        return \is_array($first) ? $first : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parentPage(int $pageUid, PageRepository $pageRepository): array
    {
        $page = $pageRepository->getPage($pageUid);
        $parentUid = $this->intOf($page['pid'] ?? null);

        return $parentUid > 0 ? $pageRepository->getPage($parentUid) : [];
    }

    /**
     * Whether core grants the visitor every page of the page's rootline that
     * extends its access to subpages, as its frontend does for a page
     * request. The page itself was read for the visitor by core's
     * PageRepository, which applies its hidden flag, start and end time and
     * access groups already.
     *
     * @param array<array-key, mixed> $page
     */
    private function isVisible(array $page, Context $context): bool
    {
        try {
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $this->intOf($page['uid'] ?? null), '', $context)->get();
        } catch (RootLineException) {
            return false;
        }

        foreach ($rootline as $rootlinePage) {
            if (!\is_array($rootlinePage) || !$this->accessVoter->accessGrantedForPageInRootLine($rootlinePage, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $page
     */
    private function doktypeOf(array $page): int
    {
        return $this->intOf($page['doktype'] ?? null);
    }

    private function intOf(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }
}
