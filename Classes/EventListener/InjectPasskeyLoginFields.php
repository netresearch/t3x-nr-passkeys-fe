<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\EventListener;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use TYPO3\CMS\Core\Page\AssetCollector;

/**
 * Injects passkey login fields into the felogin form view.
 *
 * Listens to felogin's ModifyLoginFormViewEvent (when ext:felogin is installed)
 * and injects JavaScript configuration and the passkey button partial into the
 * login form. Gracefully does nothing when felogin is not installed.
 *
 * The JavaScript module is loaded via AssetCollector to ensure it is only
 * included once per page render.
 */
final readonly class InjectPasskeyLoginFields
{
    /**
     * FQCN of felogin's ModifyLoginFormViewEvent.
     *
     * Used with class_exists() to guard against felogin not being installed.
     */
    private const FELOGIN_EVENT_CLASS = 'TYPO3\\CMS\\FrontendLogin\\Event\\ModifyLoginFormViewEvent';

    public function __construct(
        private SiteConfigurationService $siteConfigurationService,
        private FrontendConfiguration $frontendConfiguration,
        private AssetCollector $assetCollector,
    ) {}

    /**
     * Invoked by PSR-14 event dispatcher.
     *
     * The parameter type is `object` to allow graceful handling when felogin
     * is not installed (the event class would not exist). If felogin is present,
     * the event will always be an instance of ModifyLoginFormViewEvent.
     */
    public function __invoke(object $event): void
    {
        // Guard: felogin may not be installed
        if (!\class_exists(self::FELOGIN_EVENT_CLASS)) {
            return;
        }

        if (!($event instanceof \TYPO3\CMS\FrontendLogin\Event\ModifyLoginFormViewEvent)) {
            return;
        }

        if (!$this->frontendConfiguration->isEnableFePasskeys()) {
            return;
        }

        $request = $event->getRequest();
        $site = $request->getAttribute('site');

        $rpId = '';
        $origin = '';

        if ($site instanceof \TYPO3\CMS\Core\Site\Entity\SiteInterface) {
            $rpId = $this->siteConfigurationService->getRpId($site);
            $origin = $this->siteConfigurationService->getOrigin($site);
        }

        // Build configuration for the JavaScript module
        $passkeyConfig = [
            'eIdUrl' => '?eID=nr_passkeys_fe',
            'rpId' => $rpId,
            'origin' => $origin,
        ];

        // Inject configuration as a JSON script tag
        $configJson = \json_encode($passkeyConfig, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP);
        $this->assetCollector->addInlineJavaScript(
            'nr-passkeys-fe-config',
            'window.NrPasskeysFeConfig = ' . $configJson . ';',
            ['type' => 'text/javascript'],
            ['priority' => true],
        );

        // Load the passkey login JavaScript module
        $this->assetCollector->addJavaScript(
            'nr-passkeys-fe-login',
            'EXT:nr_passkeys_fe/Resources/Public/JavaScript/PasskeyLogin.js',
            ['type' => 'module'],
            ['priority' => false],
        );

        // Add passkey button to the login form via view variable
        $view = $event->getView();
        $view->assign('passkeyLoginEnabled', true);
        $view->assign('passkeyRpId', $rpId);
        $view->assign('passkeyEidUrl', '?eID=nr_passkeys_fe');
    }
}
