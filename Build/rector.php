<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;

$configure = require_once __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths (Classes/, Configuration/, Resources/,
    // Tests/ and ext_*.php), code-quality sets, rule skips, the package's
    // ergebnis-free phpstan-rector.neon, and the PHP and TYPO3 level sets for
    // the lowest supported versions (composer.json: php ^8.2,
    // typo3/cms-core ^13.4 || ^14.3). Raise 'typo3' when v13 support is
    // dropped (typo3-ci-workflows#155).
    $configure($rectorConfig, __DIR__ . '/..', [
        'tests' => true,
        'php'   => '8.2',
        'typo3' => 13,
    ]);

    $rectorConfig->skip([
        // Several public signatures here are called by the framework, not by
        // this codebase, so "unused" is not a safe inference:
        //
        // - InjectPasskeyBanner carries #[AsEventListener] without an event:
        //   argument, so TYPO3 v13/v14 derives the event from the __invoke()
        //   parameter type. Dropping that parameter deregisters the listener
        //   silently — the banner simply stops being injected.
        // - AdminController::listAction/removeAction/revokeAllAction/
        //   unlockAction are AJAX route targets (Configuration/Backend/
        //   AjaxRoutes.php) and receive their ServerRequestInterface from the
        //   route dispatcher.
        //
        // Every one of those parameters is currently read, so the rule does not
        // fire today; this keeps a later edit that stops reading one from
        // quietly breaking the wiring. Neither the test suite nor PHPStan
        // detects that class of breakage. The shared config already skips the
        // private-method sibling of this rule.
        RemoveUnusedPublicMethodParameterRector::class,
    ]);
};
