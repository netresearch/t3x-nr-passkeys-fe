<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Fuzz;

use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(RecoveryCodeService::class)]
final class RecoveryCodeFuzzTest extends TestCase
{
    /**
     * 30-character alphabet used by RecoveryCodeService.
     * Excludes ambiguous glyphs: 0/O, 1/I/L.
     */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const CODE_PATTERN = '/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/';

    private RecoveryCodeService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal stubs so generate() can be called without a real database
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $exprBuilder = $this->createStub(ExpressionBuilder::class);
        $exprBuilder->method('eq')->willReturn('1=1');
        $queryBuilder->method('expr')->willReturn($exprBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();

        // Fake DB result: no existing codes (empty)
        $resultStub = $this->createStub(\Doctrine\DBAL\Result::class);
        $resultStub->method('fetchAllAssociative')->willReturn([]);
        $resultStub->method('fetchOne')->willReturn(0);
        $queryBuilder->method('executeQuery')->willReturn($resultStub);

        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willReturn(1);
        $connection->method('delete')->willReturn(1);
        $connection->method('lastInsertId')->willReturn('1');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->subject = new RecoveryCodeService($connectionPool);
    }

    // ---------------------------------------------------------------
    // Generated code format
    // ---------------------------------------------------------------

    #[Test]
    public function generatedCodesAlwaysMatchXxxxXxxxFormat(): void
    {
        $codes = $this->subject->generate(feUserUid: 1, count: 50);

        self::assertCount(50, $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression(
                self::CODE_PATTERN,
                $code,
                "Generated code '{$code}' does not match XXXX-XXXX format",
            );
        }
    }

    #[Test]
    public function generatedCodesOnlyContainValidAlphabetCharacters(): void
    {
        $codes = $this->subject->generate(feUserUid: 1, count: 100);

        foreach ($codes as $code) {
            $stripped = \str_replace('-', '', $code);
            for ($i = 0; $i < \strlen($stripped); $i++) {
                self::assertStringContainsString(
                    $stripped[$i],
                    self::ALPHABET,
                    "Code '{$code}' contains character '{$stripped[$i]}' not in alphabet",
                );
            }
        }
    }

    #[Test]
    public function generatedCodesHaveDashAtPositionFive(): void
    {
        $codes = $this->subject->generate(feUserUid: 1, count: 20);

        foreach ($codes as $code) {
            self::assertSame(9, \strlen($code), "Code must be 9 characters (4-4 + dash): got '{$code}'");
            self::assertSame('-', $code[4], "Code must have dash at position 4: got '{$code}'");
        }
    }

    // ---------------------------------------------------------------
    // verify() — never throws on fuzzed input
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function fuzzedCodeProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'single char' => ['X'];
        yield 'too short' => ['ABCD'];
        yield 'too long' => ['ABCD-EFGH-IJKL'];
        yield 'no dash' => ['ABCDEFGH'];
        yield 'multiple dashes' => ['AB-CD-EF'];
        yield 'lowercase' => ['abcd-efgh'];
        yield 'null byte' => ["\x00\x00\x00\x00-\x00\x00\x00\x00"];
        yield 'unicode' => ['🔑KEY-ABCD'];
        yield 'sql injection' => ["'; DROP TABLE tx_nrpasskeysfe_recovery_code; --"];
        yield 'html injection' => ['<SCRIP-ALERT'];
        yield 'binary' => [\random_bytes(9)];
        yield 'all zeros' => ['0000-0000'];
        yield 'ambiguous chars O and I' => ['OOOO-IIII'];
        yield 'whitespace only' => ['    -    '];
        yield 'tabs and newlines' => ["\t\t\t\t-\n\n\n\n"];
        yield 'very long string' => [\str_repeat('ABCD-', 1000)];
        yield 'valid format wrong chars' => ['0000-0000'];
        yield 'dash at start' => ['-ABCDEFGH'];
        yield 'dash at end' => ['ABCDEFGH-'];
    }

    #[Test]
    #[DataProvider('fuzzedCodeProvider')]
    public function verifyNeverThrowsOnFuzzedInput(string $code): void
    {
        // Should return false (invalid), never throw an exception
        $result = $this->subject->verify(feUserUid: 1, code: $code);

        self::assertIsBool($result);
        self::assertFalse($result, 'Fuzzed invalid input must return false from verify()');
    }

    #[Test]
    public function verifyReturnsFalseForAllRandomInputs(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $randomCode = \random_bytes(\random_int(1, 50));
            $result = $this->subject->verify(feUserUid: 1, code: $randomCode);

            self::assertIsBool($result);
            // Should not throw; result can be true or false depending on content
        }
    }

    // ---------------------------------------------------------------
    // generate() with edge-case counts
    // ---------------------------------------------------------------

    #[Test]
    public function generateWithZeroCountReturnsEmptyArray(): void
    {
        $codes = $this->subject->generate(feUserUid: 1, count: 0);

        self::assertSame([], $codes);
    }

    #[Test]
    public function generateWithLargeCountProducesCorrectNumber(): void
    {
        $codes = $this->subject->generate(feUserUid: 1, count: 200);

        self::assertCount(200, $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression(self::CODE_PATTERN, $code);
        }
    }
}
