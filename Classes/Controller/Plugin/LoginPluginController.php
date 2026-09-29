<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller\Plugin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Error\Http\PageNotFoundException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Extbase controller for the PasskeyLogin frontend plugin.
 * Assigns template variables for the eID URL and site config,
 * then renders the Fluid template. Actual WebAuthn logic runs via eID/JavaScript.
 */
final class LoginPluginController extends ActionController
{
    /**
     * fe_group values a login target may carry, on the page itself and on
     * every ancestor that extends its access to subpages: none, or "any
     * logged-in user".
     */
    private const ACCESS_AFTER_LOGIN = ['', '0', '-2'];

    /**
     * The same for the password page, which is followed before the login:
     * none, or "hide at login".
     */
    private const ACCESS_BEFORE_LOGIN = ['', '0', '-1'];

    /**
     * pages.shortcut_mode "random subpage": which page the visitor lands on is
     * not known when the link is built.
     */
    private const SHORTCUT_MODE_RANDOM_SUBPAGE = 2;

    /**
     * Upper bound for the rootline walk, far above any real page tree depth.
     */
    private const MAX_ROOTLINE_DEPTH = 100;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly PageRepository $pageRepository,
    ) {}

    public function indexAction(): ResponseInterface
    {
        /** @var SiteInterface|null $site */
        $site = $this->request->getAttribute('site');
        $siteIdentifier = $site?->getIdentifier() ?? '';
        $baseUrl = \rtrim((string) ($site?->getBase() ?? ''), '/');
        $eidUrl = $baseUrl . '/?eID=nr_passkeys_fe';

        // Default to discoverable (passkey-first). Empty string/null/missing = use default (true).
        $discoverableRaw = $this->settings['discoverableEnabled'] ?? '';
        $discoverableEnabled = $discoverableRaw === '' || $discoverableRaw === null ? true : (bool) $discoverableRaw;

        $showPasswordRaw = $this->settings['showPasswordFallback'] ?? '';
        $showPasswordFallback = $showPasswordRaw === '' || $showPasswordRaw === null ? true : (bool) $showPasswordRaw;

        $this->view->assignMultiple([
            'eidUrl' => $eidUrl,
            'siteIdentifier' => $siteIdentifier,
            'showUsernameField' => !$discoverableEnabled,
            'discoverableEnabled' => $discoverableEnabled,
            // The link needs a page to point to: without one, or with one
            // this plugin must not link to, the switch has nothing to show.
            'passwordFallbackUrl' => $showPasswordFallback
                ? $this->resolveSameSitePageUri($this->settings['passwordLoginPage'] ?? null, $site)
                : null,
            // The hidden token form below posts to this page instead of the
            // current one, so the login lands the visitor there directly.
            'redirectUrl' => $this->resolveSameSitePageUri(
                $this->settings['redirectAfterLogin'] ?? null,
                $site,
                isLoginTarget: true,
            ),
            'recoveryUrl' => '#nr-passkeys-fe-recovery',
            // The hidden form the script submits after a successful ceremony
            // needs the token the core user authentication accepts. Its scope
            // is fixed: AbstractUserAuthentication compares it against
            // 'core/user-auth/' plus the login type and refuses anything else,
            // so a token rendered for the page would be rejected and the
            // visitor would stay anonymous after a ceremony that succeeded.
            // An empty pid leaves the storage-folder restriction off, which is
            // what felogin also sends when no pages are configured; the
            // passkey service resolves the user from the credential and never
            // reads it.
            'loginRequestToken' => RequestToken::create('core/user-auth/fe')
                ->withMergedParams(['pid' => '']),
        ]);

        return $this->htmlResponse();
    }

    /**
     * Resolve a FlexForm page reference to a link on the current site.
     *
     * Safe by construction: only a standard page (doktype 1) of this site is
     * linked, directly or as the target core's shortcut resolution reaches,
     * and the link comes from TYPO3's routing for that page. Pages whose link
     * an editor types (external URL / link pages), folders, spacers and every
     * other doktype are refused, so no stored URL reaches the form. The built
     * link must still pass staysOnSite(). Anything that fails yields null and
     * the caller falls back to its default.
     *
     * The page must be visible to the visitor when the link is followed,
     * checked as core checks it: its own fe_group, and the fe_group of every
     * ancestor with "extend to subpages". A login target is followed after
     * the login, so it may be unrestricted or restricted to "any logged-in
     * user" (fe_group -2), which is linked although the anonymous visitor the
     * plugin is rendered for cannot see it yet; a group, "hide at login" (-1)
     * or a list is refused, because the login may not grant it. The password
     * page is followed before the login: unrestricted or "hide at login".
     * Core adds -2 only for a user with at least one group
     * (FrontendUserAuthentication::createUserAspect()), so a user without a
     * group still gets 403 on a -2 page.
     */
    private function resolveSameSitePageUri(
        mixed $pageReference,
        ?SiteInterface $site,
        bool $isLoginTarget = false,
    ): ?string {
        $page = $this->standardPage($this->pageUidFrom($pageReference));
        $pageUid = $page['uid'] ?? 0;
        if (
            $page === null
            || $pageUid <= 0
            || !$site instanceof SiteInterface
            || !$this->isPageOfSite($pageUid, $site)
            || !$this->isAccessible($page, $isLoginTarget ? self::ACCESS_AFTER_LOGIN : self::ACCESS_BEFORE_LOGIN)
        ) {
            return null;
        }

        $uri = $this->uriBuilder
            ->reset()
            ->setTargetPageUid($pageUid)
            // After the check above, the only restriction a login target
            // can carry is -2.
            ->setLinkAccessRestrictedPages($isLoginTarget)
            ->build();

        return $this->staysOnSite($uri, $site) ? $uri : null;
    }

    /**
     * The record of a standard page, following a shortcut through core's own
     * resolution; null for anything else. The shortcut is resolved without
     * core's group check, so its target is judged by the same access rule
     * as a page chosen directly; a "random subpage" shortcut is refused.
     *
     * @return array{uid: int, pid: int, fe_group: string}|null
     */
    private function standardPage(?int $pageUid): ?array
    {
        $page = $pageUid !== null && $pageUid > 0 ? $this->pageRepository->getPage($pageUid, true) : [];
        if ($this->intOf($page['doktype'] ?? null) === PageRepository::DOKTYPE_SHORTCUT) {
            try {
                $page = $this->intOf($page['shortcut_mode'] ?? null) === self::SHORTCUT_MODE_RANDOM_SUBPAGE
                    ? []
                    : $this->pageRepository->resolveShortcutPage($page, disableGroupAccessCheck: true);
            } catch (PageNotFoundException|RuntimeException) {
                // A missing target, or (13.4: \RuntimeException, 14.3: its
                // PageNotFoundException subclasses) a loop or chain too long.
                $page = [];
            }
        }

        if ($this->intOf($page['doktype'] ?? null) !== PageRepository::DOKTYPE_DEFAULT) {
            return null;
        }

        return [
            'uid' => $this->intOf($page['uid'] ?? null),
            'pid' => $this->intOf($page['pid'] ?? null),
            'fe_group' => $this->feGroupOf($page),
        ];
    }

    /**
     * Whether the page's own fe_group, and that of every ancestor that
     * extends its access to subpages, is one of $allowed.
     *
     * @param array{uid: int, pid: int, fe_group: string} $page
     * @param list<string> $allowed
     */
    private function isAccessible(array $page, array $allowed): bool
    {
        $accessible = \in_array($page['fe_group'], $allowed, true);
        $pid = $page['pid'];
        for ($depth = 0; $accessible && $pid > 0 && $depth < self::MAX_ROOTLINE_DEPTH; $depth++) {
            $ancestor = $this->pageRepository->getRawRecord('pages', $pid, ['pid', 'fe_group', 'extendToSubpages']) ?? [];
            $accessible = $this->intOf($ancestor['extendToSubpages'] ?? null) !== 1
                || \in_array($this->feGroupOf($ancestor), $allowed, true);
            $pid = $this->intOf($ancestor['pid'] ?? null);
        }

        return $accessible;
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private function feGroupOf(array $record): string
    {
        $feGroup = $record['fe_group'] ?? '';

        return \is_scalar($feGroup) ? (string) $feGroup : '';
    }

    private function intOf(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Defence in depth on the link TYPO3 built: a path on the current origin,
     * or an http(s) URL whose origin (scheme, host, port) is the origin of
     * the site's base or of one of its languages. A
     * backslash, a space or a control character anywhere refuses the link
     * outright: browsers strip or reinterpret them, which is how
     * "/<TAB>/host" and "http://host\@site" read as another host.
     */
    private function staysOnSite(string $uri, SiteInterface $site): bool
    {
        if ($uri === '' || \preg_match('/[\x00-\x20\x7f\\\\]/', $uri) === 1) {
            return false;
        }

        if (\str_starts_with($uri, '/')) {
            return !\str_starts_with($uri, '//');
        }

        $origin = $this->originOf(
            \parse_url($uri, PHP_URL_SCHEME),
            \parse_url($uri, PHP_URL_HOST),
            \parse_url($uri, PHP_URL_PORT),
        );

        return $origin !== null && \in_array($origin, $this->allowedOrigins($site), true);
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(SiteInterface $site): array
    {
        $bases = [$site->getBase()];
        foreach ($site->getLanguages() as $language) {
            $bases[] = $language->getBase();
        }

        $origins = \array_map(
            fn(UriInterface $base): ?string => $this->originOf($base->getScheme(), $base->getHost(), $base->getPort()),
            $bases,
        );

        return \array_values(\array_unique(\array_filter($origins, static fn(?string $origin): bool => $origin !== null)));
    }

    /**
     * "scheme://host:port" with the default port filled in, or null unless
     * the scheme is http(s) and a host is present.
     */
    private function originOf(mixed $scheme, mixed $host, mixed $port): ?string
    {
        $scheme = \is_string($scheme) ? \strtolower($scheme) : '';
        $host = \is_string($host) ? \strtolower($host) : '';
        if ($host === '' || !\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = \is_int($port) ? $port : ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . $host . ':' . $port;
    }

    /**
     * A group field stores "pages_<uid>" or the bare uid; anything else is no page.
     */
    private function pageUidFrom(mixed $pageReference): ?int
    {
        if (\is_string($pageReference) && \str_starts_with($pageReference, 'pages_')) {
            $pageReference = \substr($pageReference, 6);
        }

        return !\is_bool($pageReference) && MathUtility::canBeInterpretedAsInteger($pageReference)
            ? (int) $pageReference
            : null;
    }

    /**
     * Uid 0 and negative uids have no root line, so SiteFinder refuses them
     * like any other page outside a site.
     */
    private function isPageOfSite(int $pageUid, SiteInterface $site): bool
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageUid)->getIdentifier() === $site->getIdentifier();
        } catch (SiteNotFoundException) {
            return false;
        }
    }
}
