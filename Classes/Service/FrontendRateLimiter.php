<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Netresearch\NrPasskeysBe\Service\RateLimiterService;

/** Access the frontend cache-backed limiter from legacy TYPO3 auth services. */
final readonly class FrontendRateLimiter
{
    public function __construct(public RateLimiterService $service) {}
}
