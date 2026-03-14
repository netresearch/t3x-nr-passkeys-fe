<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Domain\Dto;

use Netresearch\NrPasskeysFe\Domain\Dto\FrontendAdoptionStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrontendAdoptionStats::class)]
final class FrontendAdoptionStatsTest extends TestCase
{
    #[Test]
    public function constructionWithAllFieldsSetsReadonlyProperties(): void
    {
        $perGroupStats = [
            '42' => [
                'groupName' => 'Editors',
                'userCount' => 10,
                'withPasskeys' => 6,
                'enforcement' => 'encourage',
            ],
        ];

        $stats = new FrontendAdoptionStats(
            totalUsers: 100,
            usersWithPasskeys: 60,
            adoptionPercentage: 60.0,
            perGroupStats: $perGroupStats,
        );

        self::assertSame(100, $stats->totalUsers);
        self::assertSame(60, $stats->usersWithPasskeys);
        self::assertSame(60.0, $stats->adoptionPercentage);
        self::assertSame($perGroupStats, $stats->perGroupStats);
    }

    #[Test]
    public function zeroUsersEdgeCaseAcceptsZeroAdoptionPercentage(): void
    {
        $stats = new FrontendAdoptionStats(
            totalUsers: 0,
            usersWithPasskeys: 0,
            adoptionPercentage: 0.0,
            perGroupStats: [],
        );

        self::assertSame(0, $stats->totalUsers);
        self::assertSame(0, $stats->usersWithPasskeys);
        self::assertSame(0.0, $stats->adoptionPercentage);
        self::assertSame([], $stats->perGroupStats);
    }

    #[Test]
    public function perGroupStatsArrayStructureIsPreserved(): void
    {
        $perGroupStats = [
            '1' => [
                'groupName' => 'Administrators',
                'userCount' => 5,
                'withPasskeys' => 5,
                'enforcement' => 'enforced',
            ],
            '2' => [
                'groupName' => 'Members',
                'userCount' => 200,
                'withPasskeys' => 80,
                'enforcement' => 'encourage',
            ],
        ];

        $stats = new FrontendAdoptionStats(
            totalUsers: 205,
            usersWithPasskeys: 85,
            adoptionPercentage: 41.46,
            perGroupStats: $perGroupStats,
        );

        self::assertArrayHasKey('1', $stats->perGroupStats);
        self::assertArrayHasKey('2', $stats->perGroupStats);
        self::assertSame('Administrators', $stats->perGroupStats['1']['groupName']);
        self::assertSame(5, $stats->perGroupStats['1']['userCount']);
        self::assertSame(5, $stats->perGroupStats['1']['withPasskeys']);
        self::assertSame('enforced', $stats->perGroupStats['1']['enforcement']);
        self::assertSame('Members', $stats->perGroupStats['2']['groupName']);
        self::assertSame(200, $stats->perGroupStats['2']['userCount']);
    }

    #[Test]
    public function readonlyPropertiesCannotBeWritten(): void
    {
        $stats = new FrontendAdoptionStats(
            totalUsers: 10,
            usersWithPasskeys: 5,
            adoptionPercentage: 50.0,
            perGroupStats: [],
        );

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $stats->totalUsers = 99;
    }

    #[Test]
    public function fullAdoptionPercentageCanBeOneHundred(): void
    {
        $stats = new FrontendAdoptionStats(
            totalUsers: 10,
            usersWithPasskeys: 10,
            adoptionPercentage: 100.0,
            perGroupStats: [],
        );

        self::assertSame(100.0, $stats->adoptionPercentage);
    }
}
