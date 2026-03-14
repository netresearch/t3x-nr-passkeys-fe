<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrPasskeysFe\Controller\AdminModuleController;

return [
    'nr_passkeys_fe' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'admin',
        'iconIdentifier' => 'nr-passkeys-fe-module',
        'labels' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => AdminModuleController::class . '::dashboardAction',
            ],
            'help' => [
                'target' => AdminModuleController::class . '::helpAction',
            ],
        ],
    ],
];
