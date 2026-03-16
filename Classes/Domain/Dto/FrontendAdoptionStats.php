<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Domain\Dto;

/**
 * Aggregate passkey adoption statistics for frontend users.
 *
 * Provides site-wide totals and per-group breakdowns used by
 * the admin overview and reporting features.
 */
final readonly class FrontendAdoptionStats
{
    /**
     * @param int   $totalUsers           Total number of active frontend users
     * @param int   $usersWithPasskeys    Number of frontend users with at least one passkey
     * @param float $adoptionPercentage   Percentage of users with passkeys (0.0 when totalUsers = 0)
     * @param array<string, array{groupName: string, userCount: int, withPasskeys: int, enforcement: string}> $perGroupStats Per-group breakdown keyed by group UID
     */
    public function __construct(
        public int $totalUsers,
        public int $usersWithPasskeys,
        public float $adoptionPercentage,
        public array $perGroupStats,
    ) {}
}
