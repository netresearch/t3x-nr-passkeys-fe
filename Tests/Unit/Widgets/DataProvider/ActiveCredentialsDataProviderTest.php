<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Widgets\DataProvider;

use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysFe\Widgets\DataProvider\ActiveCredentialsDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;

#[CoversClass(ActiveCredentialsDataProvider::class)]
final class ActiveCredentialsDataProviderTest extends TestCase
{
    #[Test]
    public function implementsNumberWithIconDataProviderInterface(): void
    {
        $subject = new ActiveCredentialsDataProvider($this->createStub(ConnectionPool::class));

        self::assertInstanceOf(NumberWithIconDataProviderInterface::class, $subject);
    }

    #[Test]
    public function getNumberReturnsActiveCredentialCount(): void
    {
        $subject = new ActiveCredentialsDataProvider($this->createConnectionPool(42));

        self::assertSame(42, $subject->getNumber());
    }

    #[Test]
    public function getNumberReturnsZeroWhenNoCredentialsExist(): void
    {
        $subject = new ActiveCredentialsDataProvider($this->createConnectionPool(0));

        self::assertSame(0, $subject->getNumber());
    }

    #[Test]
    public function getNumberReturnsZeroForNonNumericResult(): void
    {
        $subject = new ActiveCredentialsDataProvider($this->createConnectionPool(false));

        self::assertSame(0, $subject->getNumber());
    }

    #[Test]
    public function getNumberCastsNumericStringResult(): void
    {
        $subject = new ActiveCredentialsDataProvider($this->createConnectionPool('17'));

        self::assertSame(17, $subject->getNumber());
    }

    private function createConnectionPool(mixed $fetchOneResult): ConnectionPool&Stub
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn($fetchOneResult);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }
}
