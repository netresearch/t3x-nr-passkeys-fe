<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Domain\Model;

use Netresearch\NrPasskeysFe\Domain\Model\RecoveryCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecoveryCode::class)]
final class RecoveryCodeTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $code = new RecoveryCode();

        self::assertSame(0, $code->getUid());
        self::assertSame(0, $code->getFeUser());
        self::assertSame('', $code->getCodeHash());
        self::assertSame(0, $code->getUsedAt());
        self::assertSame(0, $code->getCreatedAt());
    }

    #[Test]
    public function fromArrayCreatesCodeWithAllFields(): void
    {
        $data = [
            'uid' => 10,
            'fe_user' => 5,
            'code_hash' => '$argon2id$hash',
            'used_at' => 0,
            'created_at' => 1700000000,
        ];

        $code = RecoveryCode::fromArray($data);

        self::assertSame(10, $code->getUid());
        self::assertSame(5, $code->getFeUser());
        self::assertSame('$argon2id$hash', $code->getCodeHash());
        self::assertSame(0, $code->getUsedAt());
        self::assertSame(1700000000, $code->getCreatedAt());
    }

    #[Test]
    public function fromArrayHandlesMissingKeysWithDefaults(): void
    {
        $code = RecoveryCode::fromArray([]);

        self::assertSame(0, $code->getUid());
        self::assertSame(0, $code->getFeUser());
        self::assertSame('', $code->getCodeHash());
        self::assertSame(0, $code->getUsedAt());
        self::assertSame(0, $code->getCreatedAt());
    }

    #[Test]
    public function isUsedReturnsFalseWhenUsedAtIsZero(): void
    {
        $code = new RecoveryCode(usedAt: 0);
        self::assertFalse($code->isUsed());
    }

    #[Test]
    public function isUsedReturnsTrueWhenUsedAtIsPositive(): void
    {
        $code = new RecoveryCode(usedAt: 1700000000);
        self::assertTrue($code->isUsed());
    }

    #[Test]
    public function toArrayRoundTripsWithFromArray(): void
    {
        $original = new RecoveryCode(
            uid: 3,
            feUser: 7,
            codeHash: '$hash-value',
            usedAt: 0,
            createdAt: 1700005000,
        );

        $restored = RecoveryCode::fromArray($original->toArray());

        self::assertSame($original->toArray(), $restored->toArray());
    }

    #[Test]
    public function toArrayContainsAllExpectedKeys(): void
    {
        $code = new RecoveryCode();
        $array = $code->toArray();

        self::assertArrayHasKey('uid', $array);
        self::assertArrayHasKey('fe_user', $array);
        self::assertArrayHasKey('code_hash', $array);
        self::assertArrayHasKey('used_at', $array);
        self::assertArrayHasKey('created_at', $array);
        self::assertCount(5, $array);
    }

    #[Test]
    public function settersUpdateValues(): void
    {
        $code = new RecoveryCode();
        $code->setUid(99);
        $code->setFeUser(10);
        $code->setCodeHash('$new-hash');
        $code->setUsedAt(1700009000);
        $code->setCreatedAt(1700001000);

        self::assertSame(99, $code->getUid());
        self::assertSame(10, $code->getFeUser());
        self::assertSame('$new-hash', $code->getCodeHash());
        self::assertSame(1700009000, $code->getUsedAt());
        self::assertSame(1700001000, $code->getCreatedAt());
    }
}
