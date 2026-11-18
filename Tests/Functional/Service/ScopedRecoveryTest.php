<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Service;

use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

#[CoversClass(RecoveryCodeService::class)]
final class ScopedRecoveryTest extends AbstractPasskeyFunctionalTestCase
{
    #[Test]
    public function existingUnscopedCodesMoveOnlyToTheHomeSiteOfTheOwnerFolder(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $codes    = $this->get(RecoveryCodeService::class);
        [$legacy] = $codes->generate(1, 1);
        $shared   = SiteSettings::createFromSettingsTree(['nr_passkeys_fe' => ['userStoragePids' => '1']]);
        $home     = new Site('home', 1, ['base' => 'https://home.example/'], $shared);
        $other    = new Site('other', 2, ['base' => 'https://other.example/'], $shared);
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Home', 'is_siteroot' => 1, 'doktype' => 1, 'slug' => '/',
        ]);
        $directory = Environment::getConfigPath() . '/sites/home';
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        copy(__DIR__ . '/Fixtures/home-site.yaml', $directory . '/config.yaml');
        $this->get(SiteConfiguration::class)->siteConfigurationChanged();
        self::assertFalse($codes->verify(1, $legacy, $other));
        self::assertSame(1, $codes->countRemaining(1, $home));
        self::assertTrue($codes->verify(1, $legacy, $home));
        self::assertFalse($codes->verify(1, $legacy, $home));
        self::assertSame(0, $codes->countRemaining(1, $other));
    }

    #[Test]
    public function rotationForAnUnavailableOwnerRollsBackTheTransaction(): void
    {
        $codes = $this->get(RecoveryCodeService::class);
        $table = 'tx_nrpasskeysfe_recovery_code';
        $db    = $this->getConnectionPool()->getConnectionForTable($table);
        $level = $db->getTransactionNestingLevel();
        try {
            $codes->generate(9999, 1);
            self::fail('A missing owner must prevent recovery code rotation.');
        } catch (RuntimeException $error) {
            self::assertSame('Recovery code owner is unavailable', $error->getMessage());
        }

        self::assertSame($level, $db->getTransactionNestingLevel());
        self::assertSame(0, (int) $db->count('*', $table, ['fe_user' => 9999]));
    }

    #[Test]
    public function rotatingOneSitePreservesTheOtherSitesCodes(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $codes    = $this->get(RecoveryCodeService::class);
        $a        = new Site('a', 1, ['base' => 'https://a.example/']);
        $b        = new Site('b', 2, ['base' => 'https://b.example/']);
        [$first]  = $codes->generate(1, 1, $a);
        [$second] = $codes->generate(1, 1, $b);
        self::assertFalse($codes->verify(1, $first, $b));
        self::assertSame(1, $codes->countRemaining(1, $a));
        self::assertTrue($codes->verify(1, $second, $b));
        self::assertFalse($codes->verify(1, $second, $b));
        self::assertTrue($codes->verify(1, $first, $a));
    }
}
