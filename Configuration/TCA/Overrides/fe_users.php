<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

\defined('TYPO3') || die();

$tempColumns = [
    'passkeys' => [
        'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:fe_users.passkeys.label',
        'description' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:fe_users.passkeys.description',
        'config' => [
            'type' => 'none',
            'renderType' => 'passkeyFeInfo',
        ],
    ],
    'passkey_grace_period_start' => [
        'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:fe_users.passkey_grace_period_start',
        'config' => [
            'type' => 'passthrough',
        ],
    ],
    'passkey_nudge_until' => [
        'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:fe_users.passkey_nudge_until',
        'config' => [
            'type' => 'passthrough',
        ],
    ],
];

ExtensionManagementUtility::addTCAcolumns('fe_users', $tempColumns);

// Add Passkeys tab to the fe_users form
ExtensionManagementUtility::addToAllTCAtypes(
    'fe_users',
    '--div--;LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:fe_users.tab.passkeys,passkeys,passkey_grace_period_start,passkey_nudge_until',
);
