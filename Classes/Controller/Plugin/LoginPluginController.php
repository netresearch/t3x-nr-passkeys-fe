<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller\Plugin;

use Psr\Http\Message\ResponseInterface;
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
    public function __construct(
        private readonly SiteFinder $siteFinder,
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
            // current one, so the login lands the visitor there directly. The
            // visitor is logged in once the form arrives, so a page only
            // logged-in users may see is a valid target and is linked.
            'redirectUrl' => $this->resolveSameSitePageUri(
                $this->settings['redirectAfterLogin'] ?? null,
                $site,
                linkAccessRestrictedPages: true,
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
     * The value comes from the content element, so it is only trusted as far
     * as it names a page of the site the plugin is rendered on, and the link
     * TYPO3 builds for it is only used when it stays on that site's hosts:
     * a page of this site can still lead elsewhere (an external-URL page, a
     * shortcut into another site). Anything else yields null and the caller
     * falls back to its default.
     */
    private function resolveSameSitePageUri(
        mixed $pageReference,
        ?SiteInterface $site,
        bool $linkAccessRestrictedPages = false,
    ): ?string {
        $pageUid = $this->pageUidFrom($pageReference);
        if ($pageUid === null || !$site instanceof SiteInterface || !$this->isPageOfSite($pageUid, $site)) {
            return null;
        }

        $uri = $this->uriBuilder
            ->reset()
            ->setTargetPageUid($pageUid)
            ->setLinkAccessRestrictedPages($linkAccessRestrictedPages)
            ->build();

        return $this->staysOnSite($uri, $site) ? $uri : null;
    }

    /**
     * Whether a link TYPO3 built stays on the site: a path on the current
     * host (UriBuilder builds same-host links without scheme and host), or an
     * http(s) URL on the host of the site's base or of one of its languages.
     * A leading "//" or "/\" is a host in a browser, not a path.
     */
    private function staysOnSite(string $uri, SiteInterface $site): bool
    {
        if (\str_starts_with($uri, '/')) {
            return !\str_starts_with($uri, '//') && !\str_starts_with($uri, '/\\');
        }

        $scheme = \strtolower((string) \parse_url($uri, PHP_URL_SCHEME));
        $host = \strtolower((string) \parse_url($uri, PHP_URL_HOST));

        return \in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && \in_array($host, $this->siteHosts($site), true);
    }

    /**
     * @return list<string>
     */
    private function siteHosts(SiteInterface $site): array
    {
        $hosts = [\strtolower($site->getBase()->getHost())];
        foreach ($site->getLanguages() as $language) {
            $hosts[] = \strtolower($language->getBase()->getHost());
        }

        return \array_values(\array_unique(\array_filter($hosts, static fn(string $host): bool => $host !== '')));
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
