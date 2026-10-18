<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

\defined('TYPO3') || exit;

ExtensionManagementUtility::addStaticFile(
    'nr_passkeys_fe',
    'Configuration/TypoScript',
    'Passkeys Frontend Authentication',
);
