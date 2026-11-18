<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\SiteConfigurationLoadedEvent;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;

#[AsEventListener(identifier: 'nr-passkeys-fe/preserve-recovery-methods')]
final readonly class PreserveRecoveryMethods
{
    public function __construct(private SiteSettingsFactory $settingsFactory) {}

    public function __invoke(SiteConfigurationLoadedEvent $event): void
    {
        $configuration = $event->getConfiguration();
        // Use Core's local-file precedence and YAML loader (including imports).
        $settings = $this->settingsFactory->loadLocalSettings($event->getSiteIdentifier()) ?? $configuration['settings'] ?? [];
        if (!is_array($settings)) {
            return;
        }

        $section = $settings['nr_passkeys_fe'] ?? [];
        if (array_key_exists('nr_passkeys_fe.enabledRecoveryMethods', $settings)) {
            $methods = $settings['nr_passkeys_fe.enabledRecoveryMethods'];
        } elseif (is_array($section) && array_key_exists('enabledRecoveryMethods', $section)) {
            $methods = $section['enabledRecoveryMethods'];
        } else {
            return;
        }

        // Anonymous array settings lose their empty value when Core flattens them.
        $configuration['nr_passkeys_fe_localRecoveryMethods'] = is_array($methods) ? $methods : [];
        $event->setConfiguration($configuration);
    }
}
