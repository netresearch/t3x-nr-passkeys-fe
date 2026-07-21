<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Widgets;

use Netresearch\NrPasskeysFe\Widgets\AdminOnlyDoughnutChartWidget;
use Netresearch\NrPasskeysFe\Widgets\AdminOnlyNumberWithIconWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Dashboard\Widgets\AdminOnlyWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\DoughnutChartWidget;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconWidget;

/**
 * The dashboard's DashboardWidgetPass derives the adminOnly flag from the
 * registered widget class via is_a(..., AdminOnlyWidgetInterface::class, true).
 * These assertions pin that contract for both widget wrapper classes.
 */
#[CoversClass(AdminOnlyDoughnutChartWidget::class)]
#[CoversClass(AdminOnlyNumberWithIconWidget::class)]
final class AdminOnlyWidgetsTest extends TestCase
{
    #[Test]
    public function doughnutChartWidgetIsAdminOnly(): void
    {
        self::assertTrue(
            \is_a(AdminOnlyDoughnutChartWidget::class, AdminOnlyWidgetInterface::class, true),
        );
    }

    #[Test]
    public function doughnutChartWidgetExtendsCoreDoughnutChartWidget(): void
    {
        self::assertTrue(
            \is_a(AdminOnlyDoughnutChartWidget::class, DoughnutChartWidget::class, true),
        );
    }

    #[Test]
    public function numberWithIconWidgetIsAdminOnly(): void
    {
        self::assertTrue(
            \is_a(AdminOnlyNumberWithIconWidget::class, AdminOnlyWidgetInterface::class, true),
        );
    }

    #[Test]
    public function numberWithIconWidgetExtendsCoreNumberWithIconWidget(): void
    {
        self::assertTrue(
            \is_a(AdminOnlyNumberWithIconWidget::class, NumberWithIconWidget::class, true),
        );
    }
}
