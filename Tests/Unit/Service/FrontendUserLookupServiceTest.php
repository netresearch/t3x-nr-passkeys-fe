<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysFe\Service\FrontendUserLookupService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(FrontendUserLookupService::class)]
final class FrontendUserLookupServiceTest extends TestCase
{
    private ConnectionPool&Stub $connectionPool;
    private FrontendUserLookupService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = $this->createStub(ConnectionPool::class);
        $this->subject = new FrontendUserLookupService($this->connectionPool);
    }

    // ---------------------------------------------------------------
    // findFeUserUidByUsername()
    // ---------------------------------------------------------------

    #[Test]
    public function findFeUserUidByUsernameReturnsUidWhenFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => 42]);

        $queryBuilder = $this->createQueryBuilderStub($result);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        self::assertSame(42, $this->subject->findFeUserUidByUsername('johndoe'));
    }

    #[Test]
    public function findFeUserUidByUsernameReturnsNullWhenNotFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createQueryBuilderStub($result);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        self::assertNull($this->subject->findFeUserUidByUsername('ghost'));
    }

    #[Test]
    public function findFeUserUidByUsernameReturnsNullWhenUidIsNotNumeric(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => null]);

        $queryBuilder = $this->createQueryBuilderStub($result);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        self::assertNull($this->subject->findFeUserUidByUsername('broken'));
    }

    // ---------------------------------------------------------------
    // findFeUserByUid()
    // ---------------------------------------------------------------

    #[Test]
    public function findFeUserByUidReturnsArrayWhenFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => 42, 'username' => 'johndoe']);

        $queryBuilder = $this->createQueryBuilderStub($result);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $row = $this->subject->findFeUserByUid(42);

        self::assertNotNull($row);
        self::assertSame(42, $row['uid']);
        self::assertSame('johndoe', $row['username']);
    }

    #[Test]
    public function findFeUserByUidReturnsNullWhenNotFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createQueryBuilderStub($result);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        self::assertNull($this->subject->findFeUserByUid(999));
    }

    #[Test]
    public function findFeUserByUidHandlesNonNumericUidGracefully(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => null, 'username' => 'broken']);

        $queryBuilder = $this->createQueryBuilderStub($result);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $row = $this->subject->findFeUserByUid(1);

        self::assertNotNull($row);
        self::assertSame(0, $row['uid']);
        self::assertSame('broken', $row['username']);
    }

    #[Test]
    public function findFeUserByUidHandlesNonStringUsernameGracefully(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => 42, 'username' => null]);

        $queryBuilder = $this->createQueryBuilderStub($result);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $row = $this->subject->findFeUserByUid(42);

        self::assertNotNull($row);
        self::assertSame(42, $row['uid']);
        self::assertSame('', $row['username']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createQueryBuilderStub(?Result $result = null): QueryBuilder&Stub
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn('?');

        if ($result !== null) {
            $queryBuilder->method('executeQuery')->willReturn($result);
        }

        return $queryBuilder;
    }
}
