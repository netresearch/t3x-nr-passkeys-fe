<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Adoption;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use Netresearch\NrPasskeysBe\Widgets\Adoption\PasskeyAdoptionStatsProviderInterface;
use Netresearch\NrPasskeysFe\Adoption\FrontendPasskeyAdoptionStatsProvider;
use Netresearch\NrPasskeysFe\Service\FrontendAdoptionStatsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrontendPasskeyAdoptionStatsProvider::class)]
final class FrontendPasskeyAdoptionStatsProviderTest extends TestCase
{
    #[Test]
    public function implementsProviderInterface(): void
    {
        $subject = new FrontendPasskeyAdoptionStatsProvider(
            $this->createStub(FrontendAdoptionStatsService::class),
        );

        self::assertInstanceOf(PasskeyAdoptionStatsProviderInterface::class, $subject);
    }

    #[Test]
    public function getAudienceStatsUsesFrontendAudienceKey(): void
    {
        $subject = new FrontendPasskeyAdoptionStatsProvider(
            $this->createStatsService(totalUsers: 0, usersWithPasskeys: 0, activeCredentials: 0),
        );

        self::assertSame('frontend', $subject->getAudienceStats()->audienceKey);
    }

    #[Test]
    public function getAudienceStatsMapsEachCountToItsDtoField(): void
    {
        // Distinct values so a mis-wired argument order is detectable.
        $subject = new FrontendPasskeyAdoptionStatsProvider(
            $this->createStatsService(totalUsers: 17, usersWithPasskeys: 5, activeCredentials: 23),
        );

        $stats = $subject->getAudienceStats();

        self::assertSame(17, $stats->totalActiveUsers);
        self::assertSame(5, $stats->usersWithPasskeys);
        self::assertSame(23, $stats->activeCredentials);
    }

    #[Test]
    public function getAudienceStatsReturnsPasskeyAudienceStats(): void
    {
        $subject = new FrontendPasskeyAdoptionStatsProvider(
            $this->createStatsService(totalUsers: 3, usersWithPasskeys: 1, activeCredentials: 2),
        );

        self::assertInstanceOf(PasskeyAudienceStats::class, $subject->getAudienceStats());
    }

    private function createStatsService(
        int $totalUsers,
        int $usersWithPasskeys,
        int $activeCredentials,
    ): FrontendAdoptionStatsService&Stub {
        $service = $this->createStub(FrontendAdoptionStatsService::class);
        $service->method('countTotalActiveUsers')->willReturn($totalUsers);
        $service->method('countUsersWithActivePasskey')->willReturn($usersWithPasskeys);
        $service->method('countActiveCredentials')->willReturn($activeCredentials);

        return $service;
    }
}
