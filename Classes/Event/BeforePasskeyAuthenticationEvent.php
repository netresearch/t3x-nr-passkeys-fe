<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Event;

/**
 * Dispatched before a passkey authentication assertion is verified.
 *
 * The feUserUid is null for discoverable-credential logins where the
 * user has not yet been identified from the session.
 */
final readonly class BeforePasskeyAuthenticationEvent
{
    public function __construct(
        public ?int $feUserUid,
        public string $assertionJson,
    ) {}
}
