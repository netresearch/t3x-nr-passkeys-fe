<?php

declare(strict_types=1);

use Netresearch\NrPasskeysFe\Controller\Plugin\LoginPluginController;
use Netresearch\NrPasskeysFe\Controller\Plugin\ManagementPluginController;
use Netresearch\NrPasskeysFe\Controller\Plugin\EnrollmentPluginController;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use Netresearch\NrPasskeysFe\Form\Element\PasskeyFeInfoElement;
use Netresearch\NrPasskeysFe\Controller\EidDispatcher;
use Netresearch\NrPasskeysFe\Authentication\PasskeyFrontendAuthenticationService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') || die();

// Configure Extbase plugins (required for rendering)
// These Extbase controllers render Fluid templates; actual WebAuthn logic runs via eID/JavaScript.
ExtensionUtility::configurePlugin(
    'NrPasskeysFe',
    'PasskeyLogin',
    [LoginPluginController::class => 'index'],
    [LoginPluginController::class => 'index'],
);

ExtensionUtility::configurePlugin(
    'NrPasskeysFe',
    'PasskeyManagement',
    [ManagementPluginController::class => 'index'],
    [ManagementPluginController::class => 'index'],
);

ExtensionUtility::configurePlugin(
    'NrPasskeysFe',
    'PasskeyEnrollment',
    [EnrollmentPluginController::class => 'index'],
    [EnrollmentPluginController::class => 'index'],
);

// Register FE passkey authentication service at priority 80
// Above SaltedPasswordService (50), below typical LDAP/SSO (90+)
// See ADR-012 for priority rationale
ExtensionManagementUtility::addService(
    'nr_passkeys_fe',
    'auth',
    PasskeyFrontendAuthenticationService::class,
    [
        'title' => 'Passkey Frontend Authentication',
        'description' => 'Authenticates frontend users via WebAuthn/Passkey assertions',
        'subtype' => 'authUserFE,getUserFE',
        'available' => true,
        'priority' => 80,
        'quality' => 80,
        'os' => '',
        'exec' => '',
        'className' => PasskeyFrontendAuthenticationService::class,
    ]
);

// Security audit logging for FE passkey authentication events
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Netresearch']['NrPasskeysFe']['writerConfiguration'][LogLevel::WARNING] ??= [
    FileWriter::class => [
        'logFile' => 'typo3temp/var/log/passkey_fe_auth.log',
    ],
];

// Register cache for FE challenge nonces (used by FrontendWebAuthnService)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_fe_nonce'] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_fe_nonce']['options'] ??= [
    'defaultLifetime' => 300,
];

// Register custom FormEngine element for passkey info display in fe_users records
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1742000000] = [
    'nodeName' => 'passkeyFeInfo',
    'priority' => 40,
    'class' => PasskeyFeInfoElement::class,
];

// Register eID for passkey FE API endpoints
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['nr_passkeys_fe'] =
    EidDispatcher::class . '::processRequest';
