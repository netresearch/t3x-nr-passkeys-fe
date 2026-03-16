<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Event;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;

/**
 * Dispatched after a passkey credential has been revoked (removed).
 *
 * Listeners may send security alerts, update audit logs, or check
 * whether the user still has remaining passkeys.
 */
final readonly class PasskeyRemovedEvent
{
    public function __construct(
        public FrontendCredential $credential,
        public int $revokedBy,
    ) {}
}
