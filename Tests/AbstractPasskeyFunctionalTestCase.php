<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base class for the functional and integration tests, carrying the
 * extensions every test instance in this extension has to load.
 *
 * nr_passkeys_fe requires nr_passkeys_be, whose ext_emconf declares backend
 * and setup as dependencies, and the testing framework refuses to build a
 * package graph in which a declared dependency is installed but not loaded.
 *
 * setup is listed although it only exists up to TYPO3 v13: v14 merged
 * EXT:setup into EXT:backend (typo3/cms-backend replaces typo3/cms-setup), so
 * there the key resolves to no installed package and is skipped rather than
 * demanded. Naming it satisfies the v13 leg of the matrix without breaking the
 * v14 one.
 *
 * These lists live in a base class rather than a trait on purpose: a trait
 * property whose default differs from the one FunctionalTestCase declares is a
 * fatal error on PHP 8.2 through 8.4 ("define the same property"), which PHP
 * 8.5 no longer raises. A subclass overriding an inherited property is legal
 * on every version in the matrix.
 *
 * A test needing more than this appends to $coreExtensionsToLoad in its own
 * setUp() before calling parent::setUp().
 */
abstract class AbstractPasskeyFunctionalTestCase extends FunctionalTestCase
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
