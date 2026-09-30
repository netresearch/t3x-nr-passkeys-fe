<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ties the support table in SECURITY.md to the version in ext_emconf.php:
 * the major line the extension is on is the one marked supported, and every
 * older line is marked unsupported.
 */
#[CoversNothing]
final class SecurityPolicyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    #[Test]
    public function theCurrentMajorLineIsTheSupportedOne(): void
    {
        $major = $this->currentMajor();

        self::assertMatchesRegularExpression(
            '/^\| ' . $major . '\.x +\| :white_check_mark: +\| :white_check_mark: +\|$/m',
            $this->securityPolicy(),
            'SECURITY.md must mark ' . $major . '.x as receiving bug and security fixes.',
        );
    }

    #[Test]
    public function olderLinesAreMarkedUnsupported(): void
    {
        $major = $this->currentMajor();

        self::assertMatchesRegularExpression(
            '/^\| < ' . $major . '\.0 +\| :x: +\| :x: +\|$/m',
            $this->securityPolicy(),
            'SECURITY.md must mark every line below ' . $major . '.0 as unsupported.',
        );
    }

    #[Test]
    public function noOtherLineIsMarkedSupported(): void
    {
        $major = $this->currentMajor();

        \preg_match_all('/^\| ([^|]+?) +\|[^\n]*:white_check_mark:/m', $this->securityPolicy(), $matches);

        self::assertSame([$major . '.x'], $matches[1]);
    }

    private function currentMajor(): string
    {
        $emconf = \file_get_contents(self::ROOT . 'ext_emconf.php');
        self::assertIsString($emconf);
        self::assertSame(1, \preg_match("/'version' => '(\\d+)\\.\\d+\\.\\d+'/", $emconf, $match));

        return $match[1];
    }

    private function securityPolicy(): string
    {
        $policy = \file_get_contents(self::ROOT . 'SECURITY.md');
        self::assertIsString($policy);

        return $policy;
    }
}
