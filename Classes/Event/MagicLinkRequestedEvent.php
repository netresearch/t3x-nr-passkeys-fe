<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Event;

/**
 * Dispatched when a frontend user requests a magic-link login email.
 *
 * The event intentionally does NOT include the magic-link token.
 * The token is a secret that must never leave the authentication
 * service. Listeners may audit the request or apply rate limiting,
 * but cannot access the token value.
 */
final readonly class MagicLinkRequestedEvent
{
    public function __construct(
        public int $feUserUid,
        public string $email,
    ) {}
}
