<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * The sibling backend extension (nr_passkeys_be) registers the same group
 * key with the same title on purpose: dashboard widget groups merge by key,
 * so both extensions' widgets land in one shared "Passkeys" group whichever
 * of them is installed.
 */
return [
    'nrpasskeys' => [
        'title' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_dashboard.xlf:widget_group.nrpasskeys',
    ],
];
