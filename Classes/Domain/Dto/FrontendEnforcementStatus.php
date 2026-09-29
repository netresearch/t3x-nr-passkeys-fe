<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Domain\Dto;

use DateTimeImmutable;

/**
 * Read-only snapshot of a frontend user's passkey enforcement status.
 *
 * Enforcement levels are stored as strings rather than the
 * Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel enum, because
 * the nr-passkeys-be package is not available at autoload time during
 * testing (no vendor directory). Once composer dependencies are resolved,
 * these fields can be refactored to use the enum directly.
 */
final readonly class FrontendEnforcementStatus
{
    public function __construct(
        /** Effective enforcement level (strictest across site and group levels) */
        public string $effectiveLevel,
        /** Enforcement level configured at site level */
        public string $siteLevel,
        /** Strictest enforcement level across all user groups */
        public string $groupLevel,
        /** Number of passkeys currently registered by the user */
        public int $passkeyCount,
        /** Whether the user is within a grace period for passkey enrollment */
        public bool $inGracePeriod,
        /** Deadline by which the grace period ends, null if no grace period */
        public ?DateTimeImmutable $graceDeadline,
        /** Number of unused recovery codes remaining */
        public int $recoveryCodesRemaining,
        /** Configured grace period in days (0 means no grace period configured) */
        public int $graceDays = 0,
    ) {}

    /**
     * Calendar days left in a running grace period, a started day counting
     * as one; 0 outside a grace period.
     *
     * Counted with DateInterval rather than seconds / 86400, so a period
     * that crosses a daylight-saving change is not a day longer or shorter
     * than the calendar says. The banner and the enrollment page both use it.
     */
    public function graceDaysRemaining(DateTimeImmutable $now): int
    {
        if (!$this->inGracePeriod
            || !$this->graceDeadline instanceof DateTimeImmutable
            || $this->graceDeadline <= $now
        ) {
            return 0;
        }

        $left = $now->diff($this->graceDeadline);
        $days = (int) $left->days;
        $startedDay = $left->h > 0 || $left->i > 0 || $left->s > 0 || $left->f > 0;

        return $startedDay ? $days + 1 : $days;
    }
}
