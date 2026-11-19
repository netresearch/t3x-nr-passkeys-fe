<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Service;

use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\SiteFinder;

final class RecoverySiteSettingsTest extends AbstractPasskeyFunctionalTestCase
{
    #[Test]
    public function anEmptyLocalListOverridesInlineSettingsWhileAnOmittedListKeepsTheDefault(): void
    {
        foreach (['omitted', 'empty', 'inline'] as $index => $identifier) {
            $directory = Environment::getConfigPath() . '/sites/' . $identifier;
            if (!is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }

            $fixture = (string) file_get_contents(__DIR__ . '/Fixtures/home-site.yaml');
            $config  = str_replace('rootPageId: 1', 'rootPageId: ' . ($index + 1), $fixture);
            if ($identifier !== 'omitted') {
                $config .= "settings:\n  nr_passkeys_fe:\n    enabledRecoveryMethods: []\n";
            }

            if ($identifier === 'empty') {
                $config = str_replace('enabledRecoveryMethods: []', 'enabledRecoveryMethods: [password]', $config);
                file_put_contents($directory . '/settings.yaml', "nr_passkeys_fe.enabledRecoveryMethods: []\n");
            }

            file_put_contents($directory . '/config.yaml', $config);
        }

        $this->get(SiteConfiguration::class)->siteConfigurationChanged();
        $finder = $this->get(SiteFinder::class);
        self::assertTrue(SiteConfigurationService::allowsRecoveryMethod($finder->getSiteByIdentifier('omitted'), 'password'));
        foreach (['empty', 'inline'] as $identifier) {
            $site = $finder->getSiteByIdentifier($identifier);
            self::assertFalse(SiteConfigurationService::allowsRecoveryMethod($site, 'password'));
            self::assertFalse(SiteConfigurationService::allowsRecoveryMethod($site, 'recovery_code'));
        }
    }
}
