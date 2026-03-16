<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Domain\Enum;

use Netresearch\NrPasskeysFe\Domain\Enum\RecoveryMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

#[CoversClass(RecoveryMethod::class)]
final class RecoveryMethodTest extends TestCase
{
    #[Test]
    public function passwordHasCorrectBackingValue(): void
    {
        self::assertSame('password', RecoveryMethod::Password->value);
    }

    #[Test]
    public function recoveryCodeHasCorrectBackingValue(): void
    {
        self::assertSame('recovery_code', RecoveryMethod::RecoveryCode->value);
    }

    #[Test]
    public function magicLinkHasCorrectBackingValue(): void
    {
        self::assertSame('magic_link', RecoveryMethod::MagicLink->value);
    }

    #[Test]
    public function allThreeCasesExist(): void
    {
        self::assertCount(3, RecoveryMethod::cases());
    }

    #[Test]
    public function fromWithValidStringReturnsCorrectCase(): void
    {
        self::assertSame(RecoveryMethod::Password, RecoveryMethod::from('password'));
        self::assertSame(RecoveryMethod::RecoveryCode, RecoveryMethod::from('recovery_code'));
        self::assertSame(RecoveryMethod::MagicLink, RecoveryMethod::from('magic_link'));
    }

    #[Test]
    public function fromWithInvalidStringThrowsValueError(): void
    {
        $this->expectException(ValueError::class);
        RecoveryMethod::from('invalid_method');
    }

    #[Test]
    public function tryFromWithInvalidStringReturnsNull(): void
    {
        self::assertNull(RecoveryMethod::tryFrom('not_a_method'));
    }

    #[Test]
    public function tryFromWithValidStringReturnsCase(): void
    {
        self::assertSame(RecoveryMethod::MagicLink, RecoveryMethod::tryFrom('magic_link'));
    }
}
