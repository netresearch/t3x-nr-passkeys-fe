<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Event;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;

/**
 * Dispatched after a passkey has been successfully enrolled for a frontend user.
 *
 * Listeners may send confirmation emails, update audit logs, or
 * trigger post-enrollment workflows.
 */
final readonly class AfterPasskeyEnrollmentEvent
{
    public function __construct(
        public int $feUserUid,
        public FrontendCredential $credential,
        public string $siteIdentifier,
    ) {}
}
