<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\EventListener;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use TYPO3\CMS\FrontendLogin\Event\ModifyLoginFormViewEvent;

/**
 * Hands the felogin template override the eID URL of its passkey tab.
 *
 * Listens to felogin's ModifyLoginFormViewEvent (when ext:felogin is installed)
 * and does nothing when felogin is not installed. The template override loads
 * the passkey scripts itself, through the partial it shares with the login
 * plugin (Partials/NrPasskeysFe/LoginAssets.html).
 */
final readonly class InjectPasskeyLoginFields
{
    /**
     * FQCN of felogin's ModifyLoginFormViewEvent.
     *
     * Used with class_exists() to guard against felogin not being installed.
     */
    private const FELOGIN_EVENT_CLASS = ModifyLoginFormViewEvent::class;

    public function __construct(
        private FrontendConfiguration $frontendConfiguration,
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

        if (!($event instanceof ModifyLoginFormViewEvent)) {
            return;
        }

        if (!$this->frontendConfiguration->isEnableFePasskeys()) {
            return;
        }

        // The felogin template override reads the eID URL for its passkey tab.
        $event->getView()->assign('passkeyEidUrl', '?eID=nr_passkeys_fe');
    }
}
