<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'nr-passkeys-fe-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_passkeys_fe/Resources/Public/Icons/Extension.svg',
    ],
];
