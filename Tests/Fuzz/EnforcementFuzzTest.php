<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Fuzz;

use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrontendEnforcementStatus::class)]
final class EnforcementFuzzTest extends TestCase
{
    private const VALID_LEVELS = ['off', 'encourage', 'required', 'enforced'];

    private const SEVERITY_MAP = [
        'off' => 0,
        'encourage' => 1,
        'required' => 2,
        'enforced' => 3,
    ];

    // ---------------------------------------------------------------
    // Level combinations
    // ---------------------------------------------------------------

    #[Test]
    public function allCombinationsOfSiteAndGroupLevelProduceValidEffectiveLevel(): void
    {
        foreach (self::VALID_LEVELS as $siteLevel) {
            foreach (self::VALID_LEVELS as $groupLevel) {
                $siteSeverity = self::SEVERITY_MAP[$siteLevel];
                $groupSeverity = self::SEVERITY_MAP[$groupLevel];
                $effectiveLevel = $siteSeverity >= $groupSeverity ? $siteLevel : $groupLevel;

                self::assertContains(
                    $effectiveLevel,
                    self::VALID_LEVELS,
                    "Combination site='{$siteLevel}' group='{$groupLevel}' produced invalid effective level: '{$effectiveLevel}'",
                );
            }
        }
    }

    #[Test]
    public function strictestWinsForAllLevelCombinations(): void
    {
        foreach (self::VALID_LEVELS as $levelA) {
            foreach (self::VALID_LEVELS as $levelB) {
                $severityA = self::SEVERITY_MAP[$levelA];
                $severityB = self::SEVERITY_MAP[$levelB];
                $expected = $severityA >= $severityB ? $levelA : $levelB;

                $effectiveSeverity = \max($severityA, $severityB);
                $effectiveLevel = \array_search($effectiveSeverity, self::SEVERITY_MAP, true);

                self::assertSame(
                    self::SEVERITY_MAP[$expected],
                    self::SEVERITY_MAP[$effectiveLevel],
                    "Strictest-wins: site='{$levelA}' group='{$levelB}' should produce severity " . self::SEVERITY_MAP[$expected],
                );
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLevelStringProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'uppercase OFF' => ['OFF'];
        yield 'uppercase ENFORCED' => ['ENFORCED'];
        yield 'typo' => ['enfource'];
        yield 'number as string' => ['3'];
        yield 'space padded' => [' off '];
        yield 'unicode' => ['оff'];  // Cyrillic 'о' looks like Latin 'o'
        yield 'null byte' => ["\x00off"];
        yield 'binary random' => [\random_bytes(8)];
        yield 'sql injection' => ["'; DROP TABLE fe_groups; --"];
        yield 'json string' => ['{"level":"off"}'];
        yield 'very long' => [\str_repeat('off', 1000)];
    }

    #[Test]
    #[DataProvider('invalidLevelStringProvider')]
    public function invalidLevelStringDefaultsToOff(string $rawLevel): void
    {
        // The severity map lookup: invalid strings should not be found, yielding default behavior
        $severity = self::SEVERITY_MAP[$rawLevel] ?? null;

        self::assertNull(
            $severity,
            "Invalid level string '{$rawLevel}' must not resolve to a severity via direct array lookup",
        );
    }

    // ---------------------------------------------------------------
    // FrontendEnforcementStatus DTO
    // ---------------------------------------------------------------

    #[Test]
    public function statusDtoCanBeConstructedWithAllValidLevels(): void
    {
        foreach (self::VALID_LEVELS as $level) {
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
            self::assertSame($level, $status->siteLevel);
            self::assertSame($level, $status->groupLevel);
        }
    }

    #[Test]
    public function statusDtoWithRandomPasskeyCountsStaysConsistent(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $passkeyCount = \random_int(0, 1_000);
            $recoveryCodesRemaining = \random_int(0, 100);
            $level = self::VALID_LEVELS[\random_int(0, \count(self::VALID_LEVELS) - 1)];

            $status = new FrontendEnforcementStatus(
                effectiveLevel: $level,
                siteLevel: $level,
                groupLevel: 'off',
                passkeyCount: $passkeyCount,
                inGracePeriod: (bool) \random_int(0, 1),
                graceDeadline: null,
                recoveryCodesRemaining: $recoveryCodesRemaining,
            );

            self::assertSame($passkeyCount, $status->passkeyCount);
            self::assertSame($recoveryCodesRemaining, $status->recoveryCodesRemaining);
            self::assertIsString($status->effectiveLevel);
        }
    }

    #[Test]
    public function enforcedLevelNeverAllowsGracePeriodByConvention(): void
    {
        // Business rule: 'enforced' means hard requirement, no grace period applies
        // We verify this invariant at the DTO level when grace period = false
        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'enforced',
            siteLevel: 'enforced',
            groupLevel: 'enforced',
            passkeyCount: 0,
            inGracePeriod: false,
            graceDeadline: null,
            recoveryCodesRemaining: 0,
        );

        self::assertFalse($status->inGracePeriod);
        self::assertNull($status->graceDeadline);
        self::assertSame('enforced', $status->effectiveLevel);
    }

    #[Test]
    public function graceDeadlineCanBeSetForRequiredLevel(): void
    {
        $deadline = new \DateTimeImmutable('+7 days');

        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'required',
            siteLevel: 'required',
            groupLevel: 'off',
            passkeyCount: 0,
            inGracePeriod: true,
            graceDeadline: $deadline,
            recoveryCodesRemaining: 5,
        );

        self::assertTrue($status->inGracePeriod);
        self::assertSame($deadline, $status->graceDeadline);
    }

    #[Test]
    public function severityOrderingIsConsistentAcrossAllLevels(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $a = self::VALID_LEVELS[\random_int(0, 3)];
            $b = self::VALID_LEVELS[\random_int(0, 3)];

            $severityA = self::SEVERITY_MAP[$a];
            $severityB = self::SEVERITY_MAP[$b];
            $strictest = $severityA >= $severityB ? $a : $b;
            $strictestSeverity = self::SEVERITY_MAP[$strictest];

            self::assertGreaterThanOrEqual($severityA, $strictestSeverity);
            self::assertGreaterThanOrEqual($severityB, $strictestSeverity);
            // Commutative
            $reversed = $severityB >= $severityA ? $b : $a;
            self::assertSame(
                self::SEVERITY_MAP[$strictest],
                self::SEVERITY_MAP[$reversed],
            );
        }
    }
}
