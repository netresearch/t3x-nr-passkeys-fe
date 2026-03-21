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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(FrontendAdoptionStatsService::class)]
final class FrontendAdoptionStatsServiceTest extends TestCase
{
    private ConnectionPool&Stub $connectionPool;
    private FrontendAdoptionStatsService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = $this->createStub(ConnectionPool::class);
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

    #[Test]
    public function getStatsWithSiteIdentifierScopesTotalUsers(): void
    {
        $this->setupMocks(
            totalFeUsers: 42,
            usersWithPasskeys: 10,
            groups: [],
            siteIdentifier: 'my-site',
        );

        $stats = $this->subject->getStats('my-site');

        self::assertSame(42, $stats->totalUsers);
        self::assertSame(10, $stats->usersWithPasskeys);
    }

    #[Test]
    public function getStatsGroupsWithZeroUsersRetainDefaultCounts(): void
    {
        $this->setupMocks(
            totalFeUsers: 50,
            usersWithPasskeys: 5,
            groups: [
                ['uid' => 99, 'title' => 'EmptyGroup', 'passkey_enforcement' => 'off'],
            ],
            usersPerGroup: [],
            usersWithPasskeysPerGroup: [],
        );

        $stats = $this->subject->getStats();

        self::assertArrayHasKey('99', $stats->perGroupStats);
        self::assertSame(0, $stats->perGroupStats['99']['userCount']);
        self::assertSame(0, $stats->perGroupStats['99']['withPasskeys']);
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
        string $siteIdentifier = '',
    ): void {
        // fe_users count query builder (used when no site scope)
        $feUsersCountResult = $this->createStub(Result::class);
        $feUsersCountResult->method('fetchOne')->willReturn($totalFeUsers);

        $feUsersCountQb = $this->createQueryBuilderMock($feUsersCountResult);

        // fe_groups query builder
        $feGroupsResult = $this->createStub(Result::class);
        $feGroupsResult->method('fetchAllAssociative')->willReturn($groups);
        $feGroupsQb = $this->createQueryBuilderMock($feGroupsResult);

        // Build the callback for getQueryBuilderForTable
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(
                static function (string $table) use ($feUsersCountQb, $feGroupsQb): QueryBuilder {
                    if ($table === 'fe_groups') {
                        return $feGroupsQb;
                    }

                    return $feUsersCountQb;
                },
            );

        // Build aggregate query results for Connection::executeQuery
        // The connection is used for:
        //   1. countTotalFeUsers with site scope (returns fetchOne)
        //   2. countUsersWithPasskeys (returns fetchOne)
        //   3. countUsersPerGroup (returns fetchAllAssociative)
        //   4. countUsersWithPasskeysPerGroup (returns fetchAllAssociative)
        $usersPerGroupRows = [];
        foreach ($usersPerGroup as $groupUid => $count) {
            $usersPerGroupRows[] = ['group_uid' => $groupUid, 'user_count' => $count];
        }

        $passkeysPerGroupRows = [];
        foreach ($usersWithPasskeysPerGroup as $groupUid => $count) {
            $passkeysPerGroupRows[] = ['group_uid' => $groupUid, 'with_passkeys' => $count];
        }

        $credentialConnection = $this->createStub(Connection::class);

        // Build all expected Result objects in call order
        $sqlResults = [];

        if ($siteIdentifier !== '') {
            // Site-scoped total user count (fetchOne)
            $siteTotalResult = $this->createStub(Result::class);
            $siteTotalResult->method('fetchOne')->willReturn($totalFeUsers);
            $siteTotalResult->method('fetchAllAssociative')->willReturn([]);
            $sqlResults[] = $siteTotalResult;
        }

        // Users with passkeys count (fetchOne)
        $usersWithPasskeysResult = $this->createStub(Result::class);
        $usersWithPasskeysResult->method('fetchOne')->willReturn($usersWithPasskeys);
        $usersWithPasskeysResult->method('fetchAllAssociative')->willReturn([]);
        $sqlResults[] = $usersWithPasskeysResult;

        if ($groups !== []) {
            // Aggregate: users per group (fetchAllAssociative)
            $usersPerGroupResult = $this->createStub(Result::class);
            $usersPerGroupResult->method('fetchOne')->willReturn(0);
            $usersPerGroupResult->method('fetchAllAssociative')->willReturn($usersPerGroupRows);
            $sqlResults[] = $usersPerGroupResult;

            // Aggregate: users with passkeys per group (fetchAllAssociative)
            $passkeysPerGroupResult = $this->createStub(Result::class);
            $passkeysPerGroupResult->method('fetchOne')->willReturn(0);
            $passkeysPerGroupResult->method('fetchAllAssociative')->willReturn($passkeysPerGroupRows);
            $sqlResults[] = $passkeysPerGroupResult;
        }

        $sqlCallCount = 0;
        $credentialConnection->method('executeQuery')
            ->willReturnCallback(
                static function () use (&$sqlResults, &$sqlCallCount): Result {
                    return $sqlResults[$sqlCallCount++] ?? throw new RuntimeException('No more SQL results');
                },
            );

        $this->connectionPool->method('getConnectionForTable')
            ->willReturn($credentialConnection);
    }

    private function createQueryBuilderMock(?Result $result = null): QueryBuilder&Stub
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');
        $expressionBuilder->method('inSet')->willReturn('');

        $queryBuilder = $this->createStub(QueryBuilder::class);
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
