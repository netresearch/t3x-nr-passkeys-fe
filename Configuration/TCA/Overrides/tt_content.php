<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

\defined('TYPO3') || die();

/**
 * Give a plugin content type its FlexForm and show it on a "Plugin" tab.
 *
 * TYPO3 14 deprecates ExtensionManagementUtility::addPiFlexFormValue(): the
 * data structure belongs to the content type's columnsOverrides, which is
 * what ExtensionManagementUtility::addPlugin() writes for its $flexForm
 * argument. TYPO3 13.4 still reads plugin data structures from the
 * pi_flexform "ds" array (keyed "<list_type>,<CType>") and must not get them
 * through columnsOverrides (see FlexFormTools there). Both entries are
 * written directly, as each version's core does, so no deprecated API is
 * called on either version. Neither registerPlugin() nor 13.4's addPlugin()
 * put pi_flexform into the type's form, hence the tab.
 */
$addPluginFlexForm = static function (string $cType, string $flexForm): void {
    if ((new Typo3Version())->getMajorVersion() >= 14) {
        $GLOBALS['TCA']['tt_content']['types'][$cType]['columnsOverrides']['pi_flexform']['config']['ds'] = $flexForm;
    } else {
        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']['*,' . $cType] = $flexForm;
    }

    ExtensionManagementUtility::addToAllTCAtypes(
        'tt_content',
        '--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:plugin, pi_flexform',
        $cType,
        'after:palette:headers',
    );
};

// Register Passkey Login plugin
ExtensionUtility::registerPlugin(
    'nr_passkeys_fe',
    'PasskeyLogin',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_login',
    'nr-passkeys-fe-plugin-login',
    'forms',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_login.description',
);

$addPluginFlexForm('nrpasskeysfe_passkeylogin', 'FILE:EXT:nr_passkeys_fe/Configuration/FlexForms/LoginPlugin.xml');

// Register Passkey Management plugin
ExtensionUtility::registerPlugin(
    'nr_passkeys_fe',
    'PasskeyManagement',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_management',
    'nr-passkeys-fe-plugin-management',
    'forms',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_management.description',
);

$addPluginFlexForm('nrpasskeysfe_passkeymanagement', 'FILE:EXT:nr_passkeys_fe/Configuration/FlexForms/ManagementPlugin.xml');

// Register Passkey Enrollment plugin
ExtensionUtility::registerPlugin(
    'nr_passkeys_fe',
    'PasskeyEnrollment',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_enrollment',
    'nr-passkeys-fe-plugin-enrollment',
    'forms',
    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tt_content.list_type.passkey_enrollment.description',
);

$addPluginFlexForm('nrpasskeysfe_passkeyenrollment', 'FILE:EXT:nr_passkeys_fe/Configuration/FlexForms/EnrollmentPlugin.xml');
