<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller\Fixtures;

use RuntimeException;

/**
 * What a rate-limiter double throws to end a request at the limit.
 *
 * RateLimiterService signals an exceeded limit with a RuntimeException
 * (code 1700000010) and the controllers catch RuntimeException, so the double
 * throws a subclass of it.
 */
final class RateLimitExceeded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Rate limit exceeded', 1700000010);
    }
}
