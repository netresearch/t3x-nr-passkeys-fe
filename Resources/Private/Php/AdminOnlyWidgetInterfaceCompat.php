<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace TYPO3\CMS\Dashboard\Widgets;

/*
 * Compatibility shim for TYPO3 v13.
 *
 * AdminOnlyWidgetInterface only exists since typo3/cms-dashboard v14; the
 * extension's widget classes in Classes/Widgets/ implement it so the v14
 * DashboardWidgetPass marks them adminOnly. On v13 this file (loaded via
 * composer autoload "files") declares an empty stand-in so those classes
 * stay loadable. v13 has no adminOnly widget concept — there, widget
 * visibility falls back to the be_groups "Available widgets" permission,
 * which non-admin users do not have by default.
 *
 * The interface_exists() check autoloads the real interface first when it
 * is available, so on v14 this declaration is skipped entirely. Loaded via
 * autoload "files" (not PSR-4) because the declared name is outside the
 * extension's namespace; kept outside Classes/ so PHPStan and the DI
 * container never scan it as extension code.
 */
if (!\interface_exists(AdminOnlyWidgetInterface::class)) {
    interface AdminOnlyWidgetInterface {}
}
