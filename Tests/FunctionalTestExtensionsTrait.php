<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests;

/**
 * The extensions every functional and integration test instance has to load.
 *
 * nr_passkeys_fe requires nr_passkeys_be, whose ext_emconf declares backend
 * and setup as dependencies, and the testing framework refuses to build a
 * package graph in which a declared dependency is installed but not loaded.
 *
 * setup is listed unconditionally although it only exists up to TYPO3 v13:
 * v14 merged EXT:setup into EXT:backend (typo3/cms-backend replaces
 * typo3/cms-setup), and an extension key that resolves to no installed
 * package is skipped rather than demanded. Naming it here therefore satisfies
 * the v13 leg of the matrix without breaking the v14 one.
 *
 * A test that needs more than this appends to $coreExtensionsToLoad in its own
 * setUp() before calling parent::setUp() — redeclaring the property alongside
 * the trait is a fatal error unless the value is identical.
 */
trait FunctionalTestExtensionsTrait
{
    protected array $coreExtensionsToLoad = [
        'frontend',
        'backend',
        'setup',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-passkeys-be',
        'netresearch/nr-passkeys-fe',
    ];
}
