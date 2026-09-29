<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Domain\Dto;

use DateTimeImmutable;
use DateTimeZone;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Europe/Berlin leaves daylight saving time on 2026-10-25 and enters it
     * on 2026-03-29. A period that crosses either change is still counted in
     * calendar days: seconds / 86400 would be a day off on one side.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function graceDeadlines(): iterable
    {
        yield 'ten calendar days, crossing the end of DST' => ['2026-10-24 10:00', '2026-11-03 10:00', 10];
        yield 'ten calendar days, crossing the start of DST' => ['2026-03-24 10:00', '2026-04-03 10:00', 10];
        yield 'a started day counts as one' => ['2026-11-02 22:00', '2026-11-03 10:00', 1];
        yield 'nine days and a started one' => ['2026-10-24 11:00', '2026-11-03 10:00', 10];
        yield 'deadline reached' => ['2026-11-03 10:00', '2026-11-03 10:00', 0];
    }

    #[Test]
    #[DataProvider('graceDeadlines')]
    public function graceDaysRemainingCountsCalendarDays(string $now, string $deadline, int $expected): void
    {
        $berlin = new DateTimeZone('Europe/Berlin');
        $status = $this->statusInGrace(new DateTimeImmutable($deadline, $berlin));

        self::assertSame($expected, $status->graceDaysRemaining(new DateTimeImmutable($now, $berlin)));
    }

    #[Test]
    public function graceDaysRemainingIsZeroOutsideAGracePeriod(): void
    {
        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'required',
            siteLevel: 'off',
            groupLevel: 'required',
            passkeyCount: 0,
            inGracePeriod: false,
            graceDeadline: null,
            recoveryCodesRemaining: 0,
            graceDays: 14,
        );

        self::assertSame(0, $status->graceDaysRemaining(new DateTimeImmutable()));
    }

    private function statusInGrace(DateTimeImmutable $deadline): FrontendEnforcementStatus
    {
        return new FrontendEnforcementStatus(
            effectiveLevel: 'required',
            siteLevel: 'off',
            groupLevel: 'required',
            passkeyCount: 0,
            inGracePeriod: true,
            graceDeadline: $deadline,
            recoveryCodesRemaining: 0,
            graceDays: 14,
        );
    }
}
