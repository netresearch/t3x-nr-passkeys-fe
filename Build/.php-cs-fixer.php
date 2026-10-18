<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

$createConfig = require __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/php-cs-fixer/config.php';

// Shared org code style (@PER-CS3x0 + @Symfony and the shared rules) over the
// repository root, with the header that 119 of the 125 PHP files carry. The
// extra rules are the repository's own that are stricter than the shared set.
return $createConfig(
    "Copyright (c) 2025-2026 Netresearch DTT GmbH\nSPDX-License-Identifier: GPL-2.0-or-later",
    __DIR__ . '/..',
    [
        // The shared set has no risky PER-CS rules:
        // no_trailing_whitespace_in_string, no_unreachable_default_argument_value.
        '@PER-CS3x0:risky' => true,
        'strict_param'     => true,
        // Keep class, function and const imports in separate groups; the
        // shared value sorts them as one alphabetical list.
        'ordered_imports' => [
            'imports_order'  => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
    ],
);
