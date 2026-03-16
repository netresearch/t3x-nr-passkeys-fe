<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrPasskeysFe\Controller\AdminController;

return [
    // Admin read
    'nr_passkeys_fe_admin_list' => [
        'path' => '/nr-passkeys-fe/admin/list',
        'target' => AdminController::class . '::listAction',
        'methods' => ['GET'],
    ],

    // Admin write operations (admin-level authentication checked in controller)
    'nr_passkeys_fe_admin_remove' => [
        'path' => '/nr-passkeys-fe/admin/remove',
        'target' => AdminController::class . '::removeAction',
        'methods' => ['POST'],
    ],
    'nr_passkeys_fe_admin_revoke_all' => [
        'path' => '/nr-passkeys-fe/admin/revoke-all',
        'target' => AdminController::class . '::revokeAllAction',
        'methods' => ['POST'],
    ],
    'nr_passkeys_fe_admin_unlock' => [
        'path' => '/nr-passkeys-fe/admin/unlock',
        'target' => AdminController::class . '::unlockAction',
        'methods' => ['POST'],
    ],
];
