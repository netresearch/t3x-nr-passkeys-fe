<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

$configure = require_once __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: code-quality sets, rule skips, standard extension
    // paths, and the package's ergebnis-free phpstan-rector.neon. No TYPO3 level
    // set yet — that is tracked in netresearch/typo3-ci-workflows#155.
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
};
