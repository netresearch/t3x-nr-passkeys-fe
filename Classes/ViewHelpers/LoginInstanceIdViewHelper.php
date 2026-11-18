<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\ViewHelpers;

use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/** Distinguishes the controls of each login widget, including repeated content elements. */
final class LoginInstanceIdViewHelper extends AbstractViewHelper
{
    public function render(): string
    {
        return StringUtility::getUniqueId();
    }
}
