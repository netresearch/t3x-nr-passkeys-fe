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
     * fe_group values a login target may carry: none, or "any logged-in user".
     */
    private const LOGIN_TARGET_ACCESS = ['', '0', '-2'];

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
     * A login target must be visible to the visitor once logged in: a page
     * without access restriction, or one restricted to "any logged-in user"
     * (fe_group -2), which is linked although the anonymous visitor the
     * plugin is rendered for cannot see it yet. A group, "hide at login" (-1)
     * or a list is refused, because the login may not grant it. Core adds -2
     * only for a user with at least one group
     * (FrontendUserAuthentication::createUserAspect()), so a group-less user
     * still gets 403 on a -2 page.
     */
    private function resolveSameSitePageUri(
        mixed $pageReference,
        ?SiteInterface $site,
        bool $isLoginTarget = false,
    ): ?string {
        $page = $this->standardPage($this->pageUidFrom($pageReference));
        $pageUid = $page['uid'] ?? 0;
        $feGroup = $page['fe_group'] ?? '';
        if (
            $pageUid <= 0
            || !$site instanceof SiteInterface
            || !$this->isPageOfSite($pageUid, $site)
            || ($isLoginTarget && !\in_array($feGroup, self::LOGIN_TARGET_ACCESS, true))
        ) {
            return null;
        }

        $uri = $this->uriBuilder
            ->reset()
            ->setTargetPageUid($pageUid)
            // After the check above, the only restriction left is -2.
            ->setLinkAccessRestrictedPages($isLoginTarget)
            ->build();

        return $this->staysOnSite($uri, $site) ? $uri : null;
    }

    /**
     * The record of a standard page, following a shortcut through core's own
     * resolution; null for anything else.
     *
     * @return array{uid: int, fe_group: string}|null
     */
    private function standardPage(?int $pageUid): ?array
    {
        $page = $pageUid !== null && $pageUid > 0 ? $this->pageRepository->getPage($pageUid, true) : [];
        if ($this->intOf($page['doktype'] ?? null) === PageRepository::DOKTYPE_SHORTCUT) {
            try {
                $page = $this->pageRepository->resolveShortcutPage($page);
            } catch (PageNotFoundException|RuntimeException) {
                // A missing target, or (13.4: \RuntimeException, 14.3: its
                // PageNotFoundException subclasses) a loop or chain too long.
                $page = [];
            }
        }

        if ($this->intOf($page['doktype'] ?? null) !== PageRepository::DOKTYPE_DEFAULT) {
            return null;
        }

        $feGroup = $page['fe_group'] ?? '';

        return ['uid' => $this->intOf($page['uid'] ?? null), 'fe_group' => \is_scalar($feGroup) ? (string) $feGroup : ''];
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
