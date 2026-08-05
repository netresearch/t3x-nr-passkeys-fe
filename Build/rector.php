<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

$configure = require_once __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: code-quality sets, rule skips, standard extension
    // paths, and the package's ergebnis-free phpstan-rector.neon.
    $configure($rectorConfig, __DIR__ . '/..');

    // paths() replaces the shared list rather than extending it, so the shared
    // entries are repeated here alongside Tests/, which the CGL finder also
    // covers and which must therefore be rewritten by the same rules.
    $rectorConfig->paths([
        __DIR__ . '/../Classes',
        __DIR__ . '/../Configuration',
        __DIR__ . '/../Resources',
        __DIR__ . '/../Tests',
        __DIR__ . '/../ext_localconf.php',
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

    // TYPO3 migration level: v13, the lowest still-supported major
    // (typo3-ci-workflows#155); raise when v13 support is dropped.
    $rectorConfig->sets([
        Typo3LevelSetList::UP_TO_TYPO3_13,
    ]);
};
