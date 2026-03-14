<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Event;

/**
 * Dispatched after a new set of recovery codes has been generated for a frontend user.
 *
 * Listeners may send email notifications or audit the code generation.
 * The actual code values are never included in the event payload for security reasons.
 */
final readonly class RecoveryCodesGeneratedEvent
{
    public function __construct(
        public int $feUserUid,
        public int $codeCount,
    ) {}
}
