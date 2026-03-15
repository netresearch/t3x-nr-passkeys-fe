<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Domain\Dto;

use DateTimeImmutable;
use Error;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrontendEnforcementStatus::class)]
final class FrontendEnforcementStatusTest extends TestCase
{
    #[Test]
    public function constructionWithAllFieldsSetsReadonlyProperties(): void
    {
        $deadline = new DateTimeImmutable('2026-06-01');

        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'required',
            siteLevel: 'encourage',
            groupLevel: 'required',
            passkeyCount: 2,
            inGracePeriod: true,
            graceDeadline: $deadline,
            recoveryCodesRemaining: 8,
        );

        self::assertSame('required', $status->effectiveLevel);
        self::assertSame('encourage', $status->siteLevel);
        self::assertSame('required', $status->groupLevel);
        self::assertSame(2, $status->passkeyCount);
        self::assertTrue($status->inGracePeriod);
        self::assertSame($deadline, $status->graceDeadline);
        self::assertSame(8, $status->recoveryCodesRemaining);
    }

    #[Test]
    public function nullGraceDeadlineIsAccepted(): void
    {
        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'off',
            siteLevel: 'off',
            groupLevel: 'off',
            passkeyCount: 0,
            inGracePeriod: false,
            graceDeadline: null,
            recoveryCodesRemaining: 0,
        );

        self::assertNull($status->graceDeadline);
    }

    #[Test]
    public function zeroValuesAreAccepted(): void
    {
        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'off',
            siteLevel: 'off',
            groupLevel: 'off',
            passkeyCount: 0,
            inGracePeriod: false,
            graceDeadline: null,
            recoveryCodesRemaining: 0,
        );

        self::assertSame(0, $status->passkeyCount);
        self::assertSame(0, $status->recoveryCodesRemaining);
        self::assertFalse($status->inGracePeriod);
    }

    #[Test]
    public function readonlyPropertiesCannotBeWritten(): void
    {
        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'enforced',
            siteLevel: 'enforced',
            groupLevel: 'enforced',
            passkeyCount: 1,
            inGracePeriod: false,
            graceDeadline: null,
            recoveryCodesRemaining: 5,
        );

        $this->expectException(Error::class);

        // @phpstan-ignore-next-line
        $status->effectiveLevel = 'off';
    }

    #[Test]
    public function differentEnforcementLevelStringsArePreserved(): void
    {
        foreach (['off', 'encourage', 'required', 'enforced'] as $level) {
            $status = new FrontendEnforcementStatus(
                effectiveLevel: $level,
                siteLevel: $level,
                groupLevel: $level,
                passkeyCount: 0,
                inGracePeriod: false,
                graceDeadline: null,
                recoveryCodesRemaining: 0,
            );

            self::assertSame($level, $status->effectiveLevel);
        }
    }
}
