<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\ViewHelpers;

use Netresearch\NrPasskeysFe\ViewHelpers\LoginInstanceIdViewHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoginInstanceIdViewHelper::class)]
final class LoginInstanceIdViewHelperTest extends TestCase
{
    #[Test]
    public function eachWidgetRenderGetsADistinctHtmlIdSuffix(): void
    {
        $helper = new LoginInstanceIdViewHelper();
        $first  = $helper->render();
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $first);
        self::assertNotSame($first, $helper->render());
    }
}
