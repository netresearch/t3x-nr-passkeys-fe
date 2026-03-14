<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Event;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;

/**
 * Dispatched after a frontend user has been successfully authenticated via passkey.
 *
 * Listeners may log the successful authentication, update security
 * dashboards, or trigger post-login workflows.
 */
final readonly class AfterPasskeyAuthenticationEvent
{
    public function __construct(
        public int $feUserUid,
        public FrontendCredential $credential,
    ) {}
}
