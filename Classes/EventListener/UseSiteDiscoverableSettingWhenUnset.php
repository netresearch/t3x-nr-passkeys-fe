<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Extbase\Event\Configuration\BeforeFlexFormConfigurationOverrideEvent;

/**
 * Lets the login plugin follow the discoverableEnabled constant unless the
 * content element chose a value of its own.
 *
 * Extbase merges FlexForm settings over TypoScript, so a stored value always
 * wins. The field offers an empty "use the site setting" choice, and this
 * listener removes exactly that empty value before the merge, leaving the
 * TypoScript value (plugin.tx_nrpasskeysfe.settings.discoverableEnabled) in
 * place. Core's ignoreFlexFormSettingsIfEmpty cannot do it: it also treats
 * "0" as empty, which would turn an element's explicit "off" into the
 * constant's value.
 */
#[AsEventListener(identifier: 'nr-passkeys-fe/use-site-discoverable-setting-when-unset')]
final readonly class UseSiteDiscoverableSettingWhenUnset
{
    public function __invoke(BeforeFlexFormConfigurationOverrideEvent $event): void
    {
        $framework = $event->getFrameworkConfiguration();
        if (($framework['extensionName'] ?? null) !== 'NrPasskeysFe'
            || ($framework['pluginName'] ?? null) !== 'PasskeyLogin'
        ) {
            return;
        }

        $flexForm = $event->getFlexFormConfiguration();
        $settings = $flexForm['settings'] ?? null;
        if (!\is_array($settings) || ($settings['discoverableEnabled'] ?? null) !== '') {
            return;
        }

        unset($settings['discoverableEnabled']);
        $flexForm['settings'] = $settings;
        $event->setFlexFormConfiguration($flexForm);
    }
}
