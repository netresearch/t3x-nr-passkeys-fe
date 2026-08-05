<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Service;

use Netresearch\NrPasskeysFe\Service\FrontendAdoptionStatsService;
use Netresearch\NrPasskeysFe\Tests\FunctionalTestExtensionsTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for the adoption statistics queries.
 *
 * These run against a real database (MySQL in CI, see runTests.sh) and guard
 * the actual SQL: the COUNT(DISTINCT ...) aggregation used to be expressed as
 * QueryBuilder::count('DISTINCT fe_user'), which Doctrine quotes as ONE
 * identifier and which therefore failed on MySQL with
 * "Unknown column 'DISTINCT fe_user' in 'SELECT'".
 */
#[CoversClass(FrontendAdoptionStatsService::class)]
final class FrontendAdoptionStatsServiceTest extends FunctionalTestCase
{
    use FunctionalTestExtensionsTrait;

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'nr_passkeys_fe_nonce' => [
                        'backend' => \TYPO3\CMS\Core\Cache\Backend\NullBackend::class,
                    ],
                ],
            ],
        ],
    ];

    private FrontendAdoptionStatsService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');
        $this->subject = $this->get(FrontendAdoptionStatsService::class);
    }

    #[Test]
    public function getStatsCountsDistinctUsersWithActivePasskeys(): void
    {
        // User 1 has two active credentials plus one revoked credential,
        // user 2 has one active credential -> 2 distinct users with passkeys.
        $stats = $this->subject->getStats();

        self::assertSame(5, $stats->totalUsers);
        self::assertSame(2, $stats->usersWithPasskeys);
        self::assertSame(40.0, $stats->adoptionPercentage);
    }

    #[Test]
    public function getStatsScopedToSiteCountsDistinctUsers(): void
    {
        // Users 1 and 2 have credentials on site-a; user 1 twice (active)
        // and once revoked -> 2 distinct users, both with active passkeys.
        $stats = $this->subject->getStats('site-a');

        self::assertSame(2, $stats->totalUsers);
        self::assertSame(2, $stats->usersWithPasskeys);
        self::assertSame(100.0, $stats->adoptionPercentage);
    }

    #[Test]
    public function getStatsScopedToOtherSiteCountsOnlyThatSitesUsers(): void
    {
        // Only user 1 has a credential on site-b.
        $stats = $this->subject->getStats('site-b');

        self::assertSame(1, $stats->totalUsers);
        self::assertSame(1, $stats->usersWithPasskeys);
        self::assertSame(100.0, $stats->adoptionPercentage);
    }

    #[Test]
    public function countTotalActiveUsersExcludesDisabledAndDeletedUsers(): void
    {
        // The fixture holds 7 fe_users rows: 5 active plus one disabled
        // (uid 20) and one deleted (uid 21). Default QueryBuilder
        // restrictions must exclude the latter two.
        self::assertSame(5, $this->subject->countTotalActiveUsers());
    }

    #[Test]
    public function countUsersWithActivePasskeyCountsDistinctUsersExcludingRevoked(): void
    {
        // User 1 has two active credentials plus one revoked credential,
        // user 2 has one active credential -> 2 distinct users with passkeys.
        self::assertSame(2, $this->subject->countUsersWithActivePasskey());
    }

    #[Test]
    public function countActiveCredentialsExcludesRevokedCredentials(): void
    {
        // Five credential rows exist; one (uid 3) is revoked -> 4 active.
        self::assertSame(4, $this->subject->countActiveCredentials());
    }

    #[Test]
    public function getStatsProvidesPerGroupBreakdown(): void
    {
        $stats = $this->subject->getStats();

        self::assertArrayHasKey('1', $stats->perGroupStats);
        self::assertSame('Members', $stats->perGroupStats['1']['groupName']);
        self::assertSame('required', $stats->perGroupStats['1']['enforcement']);
        // Users 1, 2, 3 and 10 are in group 1; users 1 and 2 have active passkeys.
        self::assertSame(4, $stats->perGroupStats['1']['userCount']);
        self::assertSame(2, $stats->perGroupStats['1']['withPasskeys']);

        self::assertArrayHasKey('2', $stats->perGroupStats);
        self::assertSame(1, $stats->perGroupStats['2']['userCount']);
        self::assertSame(1, $stats->perGroupStats['2']['withPasskeys']);

        self::assertArrayHasKey('3', $stats->perGroupStats);
        self::assertSame(1, $stats->perGroupStats['3']['userCount']);
        self::assertSame(0, $stats->perGroupStats['3']['withPasskeys']);
    }
}
