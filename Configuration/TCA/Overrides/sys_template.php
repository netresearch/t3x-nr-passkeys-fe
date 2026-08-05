<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

\defined('TYPO3') || die();

ExtensionManagementUtility::addStaticFile(
    'nr_passkeys_fe',
    'Configuration/TypoScript',
    'Passkeys Frontend Authentication',
);
