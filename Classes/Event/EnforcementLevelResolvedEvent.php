<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Event;

/**
 * Dispatched when the effective enforcement level has been resolved for a frontend user.
 *
 * This is a MUTABLE event: listeners may call setEffectiveLevel() to override
 * the resolved level. This allows site-specific extensions to implement custom
 * enforcement logic (e.g., exempting certain user roles from enforcement).
 *
 * The enforcement level is stored as a string (not the EnforcementLevel enum)
 * to avoid a hard dependency on the nr-passkeys-be package at the class level.
 */
final class EnforcementLevelResolvedEvent
{
    public function __construct(
        public readonly int $feUserUid,
        private string $effectiveLevel,
    ) {}

    public function getEffectiveLevel(): string
    {
        return $this->effectiveLevel;
    }

    /**
     * Override the effective enforcement level.
     *
     * Listeners use this to apply custom business rules that supersede
     * the default group/site resolution logic.
     */
    public function setEffectiveLevel(string $level): void
    {
        $this->effectiveLevel = $level;
    }
}
