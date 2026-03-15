<?php

declare(strict_types=1);

defined('TYPO3') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'nr_passkeys_fe',
    'Configuration/TypoScript',
    'Passkeys Frontend Authentication'
);
