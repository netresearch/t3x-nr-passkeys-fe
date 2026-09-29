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
     * Seconds from "now" to the deadline, and the days shown: started
     * 24-hour periods. 2026-10-24 10:00 Europe/Berlin is the day before
     * daylight saving time ends; the count follows the seconds, not the
     * local calendar.
     *
     * @return iterable<string, array{int, int}>
     */
    public static function secondsToTheDeadline(): iterable
    {
        yield 'fourteen full periods, across the end of DST' => [14 * 86400, 14];
        yield 'one second more than a period' => [86400 + 1, 2];
        yield 'exactly one period' => [86400, 1];
        yield 'one second' => [1, 1];
        yield 'deadline reached' => [0, 0];
        yield 'deadline passed' => [-60, 0];
    }

    #[Test]
    #[DataProvider('secondsToTheDeadline')]
    public function graceDaysRemainingCountsStartedPeriodsOf24Hours(int $secondsLeft, int $expected): void
    {
        $now = new DateTimeImmutable('2026-10-24 10:00', new DateTimeZone('Europe/Berlin'));
        $status = $this->statusInGrace($now->setTimestamp($now->getTimestamp() + $secondsLeft));

        self::assertSame($expected, $status->graceDaysRemaining($now));
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
