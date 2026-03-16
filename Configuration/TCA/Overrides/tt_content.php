<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

\defined('TYPO3') or die();

// Register Passkey Login plugin
\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
    'nr_passkeys_fe',
    'PasskeyLogin',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_login',
    'EXT:nr_passkeys_fe/Resources/Public/Icons/plugin-login.svg',
    'forms',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_login.description',
);

// Register Passkey Management plugin
\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
    'nr_passkeys_fe',
    'PasskeyManagement',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_management',
    'EXT:nr_passkeys_fe/Resources/Public/Icons/plugin-management.svg',
    'forms',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_management.description',
);

// Register Passkey Enrollment plugin
\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
    'nr_passkeys_fe',
    'PasskeyEnrollment',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_enrollment',
    'EXT:nr_passkeys_fe/Resources/Public/Icons/plugin-enrollment.svg',
    'forms',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_enrollment.description',
);
