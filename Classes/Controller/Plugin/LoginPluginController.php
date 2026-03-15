<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller\Plugin;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

/**
 * Extbase controller for the PasskeyLogin frontend plugin.
 * Assigns template variables for the eID URL and site config,
 * then renders the Fluid template. Actual WebAuthn logic runs via eID/JavaScript.
 */
final class LoginPluginController extends ActionController
{
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
            'showPasswordFallback' => $showPasswordFallback,
            'passwordFallbackUrl' => $baseUrl . '/passkey-login',
            'recoveryUrl' => '#nr-passkeys-fe-recovery',
        ]);

        return $this->htmlResponse();
    }
}
