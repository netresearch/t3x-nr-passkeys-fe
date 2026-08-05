<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

\defined('TYPO3') || die();

// Register Passkey Login plugin
ExtensionUtility::registerPlugin(
    'nr_passkeys_fe',
    'PasskeyLogin',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_login',
    'nr-passkeys-fe-plugin-login',
    'forms',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_login.description',
);

ExtensionManagementUtility::addPiFlexFormValue(
    '*',
    'FILE:EXT:nr_passkeys_fe/Configuration/FlexForms/LoginPlugin.xml',
    'nrpasskeysfe_passkeylogin',
);

// Register Passkey Management plugin
ExtensionUtility::registerPlugin(
    'nr_passkeys_fe',
    'PasskeyManagement',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_management',
    'nr-passkeys-fe-plugin-management',
    'forms',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_management.description',
);

ExtensionManagementUtility::addPiFlexFormValue(
    '*',
    'FILE:EXT:nr_passkeys_fe/Configuration/FlexForms/ManagementPlugin.xml',
    'nrpasskeysfe_passkeymanagement',
);

// Register Passkey Enrollment plugin
ExtensionUtility::registerPlugin(
    'nr_passkeys_fe',
    'PasskeyEnrollment',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_enrollment',
    'nr-passkeys-fe-plugin-enrollment',
    'forms',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_enrollment.description',
);

ExtensionManagementUtility::addPiFlexFormValue(
    '*',
    'FILE:EXT:nr_passkeys_fe/Configuration/FlexForms/EnrollmentPlugin.xml',
    'nrpasskeysfe_passkeyenrollment',
);
