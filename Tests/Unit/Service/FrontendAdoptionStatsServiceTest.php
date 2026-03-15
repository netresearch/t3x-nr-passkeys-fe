<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendAdoptionStats;
use Netresearch\NrPasskeysFe\Service\FrontendAdoptionStatsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(FrontendAdoptionStatsService::class)]
final class FrontendAdoptionStatsServiceTest extends TestCase
{
    private ConnectionPool&MockObject $connectionPool;
    private FrontendAdoptionStatsService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->subject = new FrontendAdoptionStatsService($this->connectionPool);
    }

    // ---------------------------------------------------------------
    // getStats()
    // ---------------------------------------------------------------

    #[Test]
    public function getStatsReturnsFrontendAdoptionStatsInstance(): void
    {
        $this->setupMocks(
            totalFeUsers: 100,
            usersWithPasskeys: 25,
            groups: [],
        );

        $stats = $this->subject->getStats();

        self::assertInstanceOf(FrontendAdoptionStats::class, $stats);
    }

    #[Test]
    public function getStatsCalculatesCorrectAdoptionPercentage(): void
    {
        $this->setupMocks(
            totalFeUsers: 200,
            usersWithPasskeys: 50,
            groups: [],
        );

        $stats = $this->subject->getStats();

        self::assertSame(200, $stats->totalUsers);
        self::assertSame(50, $stats->usersWithPasskeys);
        self::assertSame(25.0, $stats->adoptionPercentage);
    }

    #[Test]
    public function getStatsReturnsZeroPercentageWhenNoUsers(): void
    {
        $this->setupMocks(
            totalFeUsers: 0,
            usersWithPasskeys: 0,
            groups: [],
        );

        $stats = $this->subject->getStats();

        self::assertSame(0, $stats->totalUsers);
        self::assertSame(0, $stats->usersWithPasskeys);
        self::assertSame(0.0, $stats->adoptionPercentage);
    }

    #[Test]
    public function getStatsReturnsHundredPercentWhenAllUsersHavePasskeys(): void
    {
        $this->setupMocks(
            totalFeUsers: 10,
            usersWithPasskeys: 10,
            groups: [],
        );

        $stats = $this->subject->getStats();

        self::assertSame(100.0, $stats->adoptionPercentage);
    }

    #[Test]
    public function getStatsIncludesPerGroupBreakdown(): void
    {
        $this->setupMocks(
            totalFeUsers: 100,
            usersWithPasskeys: 30,
            groups: [
                ['uid' => 1, 'title' => 'Editors', 'passkey_enforcement' => 'required'],
                ['uid' => 2, 'title' => 'Readers', 'passkey_enforcement' => 'off'],
            ],
            usersPerGroup: [1 => 40, 2 => 60],
            usersWithPasskeysPerGroup: [1 => 20, 2 => 10],
        );

        $stats = $this->subject->getStats();

        self::assertArrayHasKey('1', $stats->perGroupStats);
        self::assertArrayHasKey('2', $stats->perGroupStats);

        self::assertSame('Editors', $stats->perGroupStats['1']['groupName']);
        self::assertSame(40, $stats->perGroupStats['1']['userCount']);
        self::assertSame(20, $stats->perGroupStats['1']['withPasskeys']);
        self::assertSame('required', $stats->perGroupStats['1']['enforcement']);

        self::assertSame('Readers', $stats->perGroupStats['2']['groupName']);
        self::assertSame(60, $stats->perGroupStats['2']['userCount']);
        self::assertSame(10, $stats->perGroupStats['2']['withPasskeys']);
        self::assertSame('off', $stats->perGroupStats['2']['enforcement']);
    }

    #[Test]
    public function getStatsReturnsEmptyPerGroupStatsWhenNoGroups(): void
    {
        $this->setupMocks(
            totalFeUsers: 50,
            usersWithPasskeys: 5,
            groups: [],
        );

        $stats = $this->subject->getStats();

        self::assertSame([], $stats->perGroupStats);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param list<array<string, mixed>>     $groups
     * @param array<int, int>                $usersPerGroup
     * @param array<int, int>                $usersWithPasskeysPerGroup
     */
    private function setupMocks(
        int $totalFeUsers,
        int $usersWithPasskeys,
        array $groups,
        array $usersPerGroup = [],
        array $usersWithPasskeysPerGroup = [],
    ): void {
        // fe_users count query builder
        $feUsersCountResult = $this->createMock(Result::class);
        $feUsersCountResult->method('fetchOne')->willReturn($totalFeUsers);

        $feUsersCountQb = $this->createQueryBuilderMock($feUsersCountResult);

        // fe_groups query builder
        $feGroupsResult = $this->createMock(Result::class);
        $feGroupsResult->method('fetchAllAssociative')->willReturn($groups);
        $feGroupsQb = $this->createQueryBuilderMock($feGroupsResult);

        // Per-group user count query builders (one per group)
        $groupCountQbs = [];
        foreach ($groups as $group) {
            $groupUid = (int) $group['uid'];
            $count = $usersPerGroup[$groupUid] ?? 0;

            $countResult = $this->createMock(Result::class);
            $countResult->method('fetchOne')->willReturn($count);

            $groupCountQbs[] = $this->createQueryBuilderMock($countResult);
        }

        // Build the callback for getQueryBuilderForTable
        $feUsersCallCount = 0;
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(
                static function (string $table) use (
                    $feUsersCountQb,
                    $feGroupsQb,
                    &$groupCountQbs,
                    &$feUsersCallCount,
                ): QueryBuilder {
                    if ($table === 'fe_groups') {
                        return $feGroupsQb;
                    }

                    // fe_users: first call is the total count, subsequent calls are per-group counts
                    if ($feUsersCallCount === 0) {
                        $feUsersCallCount++;

                        return $feUsersCountQb;
                    }

                    // Per-group user counts
                    return \array_shift($groupCountQbs) ?? $feUsersCountQb;
                },
            );

        // Connection for credential count queries (raw SQL)
        $credentialConnection = $this->createMock(Connection::class);

        // Users with passkeys (global count)
        $usersWithPasskeysResult = $this->createMock(Result::class);
        $usersWithPasskeysResult->method('fetchOne')->willReturn($usersWithPasskeys);

        $perGroupResults = [];
        foreach ($groups as $group) {
            $groupUid = (int) $group['uid'];
            $count = $usersWithPasskeysPerGroup[$groupUid] ?? 0;
            $result = $this->createMock(Result::class);
            $result->method('fetchOne')->willReturn($count);
            $perGroupResults[] = $result;
        }

        $sqlCallCount = 0;
        $allSqlResults = [$usersWithPasskeysResult, ...$perGroupResults];
        $credentialConnection->method('executeQuery')
            ->willReturnCallback(
                static function () use (&$allSqlResults, &$sqlCallCount): Result {
                    return $allSqlResults[$sqlCallCount++] ?? throw new RuntimeException('No more SQL results');
                },
            );

        $this->connectionPool->method('getConnectionForTable')
            ->willReturn($credentialConnection);
    }

    private function createQueryBuilderMock(?Result $result = null): QueryBuilder&MockObject
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');
        $expressionBuilder->method('inSet')->willReturn('');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn('?');

        if ($result !== null) {
            $queryBuilder->method('executeQuery')->willReturn($result);
        }

        return $queryBuilder;
    }
}
