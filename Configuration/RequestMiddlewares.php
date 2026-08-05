<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrPasskeysFe\Middleware\PasskeyEnrollmentInterstitial;
use Netresearch\NrPasskeysFe\Middleware\PasskeyPublicRouteResolver;

return [
    'frontend' => [
        'nr-passkeys-fe/public-route-resolver' => [
            'target' => PasskeyPublicRouteResolver::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => ['typo3/cms-frontend/authentication'],
        ],
        'nr-passkeys-fe/enrollment-interstitial' => [
            'target' => PasskeyEnrollmentInterstitial::class,
            'after' => ['typo3/cms-frontend/authentication'],
        ],
    ],
];
