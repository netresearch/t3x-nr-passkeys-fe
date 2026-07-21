<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Widgets;

use TYPO3\CMS\Dashboard\Widgets\AdminOnlyWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\DoughnutChartWidget;

/**
 * Admin-only variant of the core doughnut chart widget.
 *
 * The core DashboardWidgetPass derives the adminOnly flag from the widget
 * service's class implementing {@see AdminOnlyWidgetInterface}; the core
 * DoughnutChartWidget does not implement it, so this empty subclass is the
 * supported way to restrict a core-rendered widget to admin users. This
 * matches the extension's backend module, which is registered with
 * 'access' => 'admin'.
 *
 * On TYPO3 v13 the interface does not exist yet; a compat shim
 * (Resources/Private/Php/AdminOnlyWidgetInterfaceCompat.php) keeps this
 * class loadable there, and widget visibility falls back to the be_groups
 * "Available widgets" permission (not granted to non-admins by default).
 */
final class AdminOnlyDoughnutChartWidget extends DoughnutChartWidget implements AdminOnlyWidgetInterface {}
