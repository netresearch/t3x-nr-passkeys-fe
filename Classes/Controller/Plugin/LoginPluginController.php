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
            // current one, so the login lands the visitor there directly.
            'redirectUrl' => $this->resolveSameSitePageUri($this->settings['redirectAfterLogin'] ?? null, $site),
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
     * as it names a page of the site the plugin is rendered on: anything else
     * (no page, a page of another site, a page typolink will not link to)
     * yields null and the caller falls back to its default. The link is built
     * by TYPO3 from the page uid, never taken from the record as a URL.
     */
    private function resolveSameSitePageUri(mixed $pageReference, ?SiteInterface $site): ?string
    {
        if (!$site instanceof SiteInterface) {
            return null;
        }

        // A group field stores "pages_<uid>" or the bare uid.
        if (\is_string($pageReference) && \str_starts_with($pageReference, 'pages_')) {
            $pageReference = \substr($pageReference, 6);
        }

        if (\is_bool($pageReference) || !MathUtility::canBeInterpretedAsInteger($pageReference)) {
            return null;
        }

        // Uid 0 and negative uids have no root line, so SiteFinder refuses
        // them like any other page outside a site.
        $pageUid = (int) $pageReference;

        try {
            $targetSite = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return null;
        }

        if ($targetSite->getIdentifier() !== $site->getIdentifier()) {
            return null;
        }

        $uri = $this->uriBuilder->reset()->setTargetPageUid($pageUid)->build();

        return $uri !== '' ? $uri : null;
    }
}
