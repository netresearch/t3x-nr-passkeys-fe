<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Widgets\DataProvider;

use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysFe\Widgets\DataProvider\PasskeyAdoptionDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

#[CoversClass(PasskeyAdoptionDataProvider::class)]
final class PasskeyAdoptionDataProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function implementsChartDataProviderInterface(): void
    {
        $subject = new PasskeyAdoptionDataProvider($this->createStub(ConnectionPool::class));

        self::assertInstanceOf(ChartDataProviderInterface::class, $subject);
    }

    #[Test]
    public function getChartDataSplitsUsersWithAndWithoutPasskeys(): void
    {
        $subject = new PasskeyAdoptionDataProvider(
            $this->createConnectionPool(totalUsers: 100, usersWithPasskeys: 25),
        );

        $chartData = $subject->getChartData();

        self::assertSame([25, 75], $chartData['datasets'][0]['data']);
    }

    #[Test]
    public function getChartDataReturnsZeroesWhenNoUsersExist(): void
    {
        $subject = new PasskeyAdoptionDataProvider(
            $this->createConnectionPool(totalUsers: 0, usersWithPasskeys: 0),
        );

        $chartData = $subject->getChartData();

        self::assertSame([0, 0], $chartData['datasets'][0]['data']);
    }

    #[Test]
    public function getChartDataClampsPasskeyUsersToTotalUsers(): void
    {
        // Defensive: a count drift between the two aggregate queries must
        // never produce a negative "without passkeys" slice.
        $subject = new PasskeyAdoptionDataProvider(
            $this->createConnectionPool(totalUsers: 5, usersWithPasskeys: 10),
        );

        $chartData = $subject->getChartData();

        self::assertSame([5, 0], $chartData['datasets'][0]['data']);
    }

    #[Test]
    public function getChartDataTreatsNonNumericResultsAsZero(): void
    {
        $subject = new PasskeyAdoptionDataProvider(
            $this->createConnectionPool(totalUsers: false, usersWithPasskeys: false),
        );

        $chartData = $subject->getChartData();

        self::assertSame([0, 0], $chartData['datasets'][0]['data']);
    }

    #[Test]
    public function getChartDataUsesEnglishFallbackLabelsWithoutLanguageService(): void
    {
        $subject = new PasskeyAdoptionDataProvider(
            $this->createConnectionPool(totalUsers: 10, usersWithPasskeys: 4),
        );

        $chartData = $subject->getChartData();

        self::assertSame(['With passkeys', 'Without passkeys'], $chartData['labels']);
    }

    #[Test]
    public function getChartDataTranslatesLabelsThroughLanguageService(): void
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => 'translated:' . $key,
        );
        $GLOBALS['LANG'] = $languageService;

        $subject = new PasskeyAdoptionDataProvider(
            $this->createConnectionPool(totalUsers: 10, usersWithPasskeys: 4),
        );

        $chartData = $subject->getChartData();

        self::assertSame(
            [
                'translated:LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_dashboard.xlf:widget.adoption.label.with_passkeys',
                'translated:LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_dashboard.xlf:widget.adoption.label.without_passkeys',
            ],
            $chartData['labels'],
        );
    }

    #[Test]
    public function getChartDataContainsOneBackgroundColorPerSlice(): void
    {
        $subject = new PasskeyAdoptionDataProvider(
            $this->createConnectionPool(totalUsers: 10, usersWithPasskeys: 4),
        );

        $chartData = $subject->getChartData();

        self::assertCount(2, $chartData['datasets'][0]['backgroundColor']);
    }

    /**
     * Query order: 1. total fe_users count, 2. joined users-with-passkey count.
     */
    private function createConnectionPool(mixed $totalUsers, mixed $usersWithPasskeys): ConnectionPool&Stub
    {
        $queryBuilders = [
            $this->createQueryBuilder($totalUsers),
            $this->createQueryBuilder($usersWithPasskeys),
        ];

        $callIndex = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturnCallback(
            static function () use (&$queryBuilders, &$callIndex): QueryBuilder {
                return $queryBuilders[$callIndex++] ?? throw new RuntimeException('No more QueryBuilder stubs');
            },
        );

        return $connectionPool;
    }

    private function createQueryBuilder(mixed $fetchOneResult): QueryBuilder&Stub
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn($fetchOneResult);

        $compositeExpression = $this->createStub(CompositeExpression::class);
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');
        $expressionBuilder->method('and')->willReturn($compositeExpression);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('selectLiteral')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('join')->willReturnSelf();
        $queryBuilder->method('quoteIdentifier')->willReturn('`u`.`uid`');
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }
}
