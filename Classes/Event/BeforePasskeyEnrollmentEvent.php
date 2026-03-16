<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Event;

/**
 * Dispatched before a passkey enrollment ceremony begins.
 *
 * Listeners may inspect or log the enrollment attempt.
 * To abort enrollment, throw an exception in the listener.
 */
final readonly class BeforePasskeyEnrollmentEvent
{
    public function __construct(
        public int $feUserUid,
        public string $siteIdentifier,
        public string $attestationJson,
    ) {}
}
