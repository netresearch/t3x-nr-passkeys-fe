<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(RecoveryCodeService::class)]
final class RecoveryCodeServiceTest extends TestCase
{
    private ConnectionPool&MockObject $connectionPool;
    private Connection&MockObject $connection;
    private RecoveryCodeService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->connection = $this->createMock(Connection::class);

        $this->connectionPool->method('getConnectionForTable')
            ->with('tx_nrpasskeysfe_recovery_code')
            ->willReturn($this->connection);

        $this->subject = new RecoveryCodeService($this->connectionPool);
    }

    // ---------------------------------------------------------------
    // generate()
    // ---------------------------------------------------------------

    #[Test]
    public function generateReturnsRequestedNumberOfCodes(): void
    {
        $this->connection->method('delete')->willReturn(0);
        $this->connection->method('insert')->willReturn(1);

        $codes = $this->subject->generate(1, 10);

        self::assertCount(10, $codes);
    }

    #[Test]
    public function generateReturnsCodesInXxxxXxxxFormat(): void
    {
        $this->connection->method('delete')->willReturn(0);
        $this->connection->method('insert')->willReturn(1);

        $codes = $this->subject->generate(1, 5);

        foreach ($codes as $code) {
            self::assertMatchesRegularExpression(
                '/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/',
                $code,
                'Code must match XXXX-XXXX format with valid alphabet: ' . $code,
            );
        }
    }

    #[Test]
    public function generateUsesOnlyValidAlphabetCharacters(): void
    {
        $this->connection->method('delete')->willReturn(0);
        $this->connection->method('insert')->willReturn(1);

        // Generate many codes to increase confidence
        $codes = $this->subject->generate(1, 50);

        $validChars = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $invalidChars = '01OILZ'; // Z is valid; 0, 1, O, I, L are excluded
        $invalidCharsActual = '01OIL';

        foreach ($codes as $code) {
            $rawCode = \str_replace('-', '', $code);
            for ($i = 0; $i < \strlen($rawCode); $i++) {
                self::assertStringContainsString(
                    $rawCode[$i],
                    $validChars,
                    "Character '{$rawCode[$i]}' is not in the valid alphabet",
                );
            }
        }
    }

    #[Test]
    public function generateDeletesExistingCodesFirst(): void
    {
        $this->connection->expects(self::once())
            ->method('delete')
            ->with('tx_nrpasskeysfe_recovery_code', ['fe_user' => 42]);

        $this->connection->method('insert')->willReturn(1);

        $this->subject->generate(42, 3);
    }

    #[Test]
    public function generateInsertsHashedCodes(): void
    {
        $this->connection->method('delete')->willReturn(0);

        $insertedData = [];
        $this->connection->method('insert')
            ->willReturnCallback(static function (string $table, array $data) use (&$insertedData): int {
                $insertedData[] = $data;

                return 1;
            });

        $this->subject->generate(1, 3);

        self::assertCount(3, $insertedData);

        foreach ($insertedData as $data) {
            self::assertSame(1, $data['fe_user']);
            self::assertSame(0, $data['used_at']);
            self::assertGreaterThan(0, $data['created_at']);

            // Verify it's a bcrypt hash (starts with $2y$)
            self::assertStringStartsWith('$2y$', $data['code_hash']);
        }
    }

    #[Test]
    public function generateWithCustomCount(): void
    {
        $this->connection->method('delete')->willReturn(0);
        $this->connection->method('insert')->willReturn(1);

        $codes = $this->subject->generate(1, 5);
        self::assertCount(5, $codes);

        $codes = $this->subject->generate(1, 20);
        self::assertCount(20, $codes);
    }

    // ---------------------------------------------------------------
    // verify()
    // ---------------------------------------------------------------

    #[Test]
    public function verifyReturnsFalseForWrongLengthCode(): void
    {
        // No DB call should be made for clearly invalid codes
        $this->connectionPool->expects(self::never())
            ->method('getQueryBuilderForTable');

        self::assertFalse($this->subject->verify(1, 'ABC'));
    }

    #[Test]
    public function verifyReturnsFalseWhenNoUnusedCodesExist(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createQueryBuilderMock($result);
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        self::assertFalse($this->subject->verify(1, 'ABCD-EFGH'));
    }

    #[Test]
    public function verifyReturnsTrueAndMarksCodeAsUsed(): void
    {
        // Pre-hash a known code for testing
        $rawCode = 'ABCD1234';
        $rawCodeValid = 'ABCDEFGH'; // Must be in valid alphabet
        $hash = \password_hash($rawCodeValid, PASSWORD_BCRYPT, ['cost' => 4]);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['uid' => 99, 'code_hash' => $hash],
        ]);

        $queryBuilder = $this->createQueryBuilderMock($result);
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        // Expect the code to be marked as used
        $this->connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_nrpasskeysfe_recovery_code',
                self::callback(static function (array $data): bool {
                    return isset($data['used_at']) && $data['used_at'] > 0;
                }),
                ['uid' => 99],
            );

        // Verify with dash-formatted code
        self::assertTrue($this->subject->verify(1, 'ABCD-EFGH'));
    }

    #[Test]
    public function verifyNormalisesInputByStrippingDashes(): void
    {
        $rawCode = 'TESTCODE';
        $hash = \password_hash($rawCode, PASSWORD_BCRYPT, ['cost' => 4]);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['uid' => 1, 'code_hash' => $hash],
        ]);

        $queryBuilder = $this->createQueryBuilderMock($result);
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        $this->connection->method('update')->willReturn(1);

        // Both formats should work
        self::assertTrue($this->subject->verify(1, 'TEST-CODE'));
    }

    #[Test]
    public function verifyNormalisesInputToUpperCase(): void
    {
        $rawCode = 'TESTCODE';
        $hash = \password_hash($rawCode, PASSWORD_BCRYPT, ['cost' => 4]);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['uid' => 1, 'code_hash' => $hash],
        ]);

        $queryBuilder = $this->createQueryBuilderMock($result);
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        $this->connection->method('update')->willReturn(1);

        self::assertTrue($this->subject->verify(1, 'test-code'));
    }

    #[Test]
    public function verifyReturnsFalseForWrongCode(): void
    {
        $hash = \password_hash('REALCODE', PASSWORD_BCRYPT, ['cost' => 4]);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['uid' => 1, 'code_hash' => $hash],
        ]);

        $queryBuilder = $this->createQueryBuilderMock($result);
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        self::assertFalse($this->subject->verify(1, 'WRON-GCOD'));
    }

    // ---------------------------------------------------------------
    // countRemaining()
    // ---------------------------------------------------------------

    #[Test]
    public function countRemainingReturnsCorrectCount(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(7);

        $queryBuilder = $this->createQueryBuilderMock($result);
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        self::assertSame(7, $this->subject->countRemaining(1));
    }

    #[Test]
    public function countRemainingReturnsZeroWhenNoneLeft(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(0);

        $queryBuilder = $this->createQueryBuilderMock($result);
        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        self::assertSame(0, $this->subject->countRemaining(1));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createQueryBuilderMock(?Result $result = null): QueryBuilder&MockObject
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');

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
