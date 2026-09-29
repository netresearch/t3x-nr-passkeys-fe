<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Imaging;

use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;

/**
 * Record and plugin icons must reach the backend as <svg><use>, which
 * inherits currentColor, so their glyph follows the backend colour scheme.
 * As <img> the SVG cannot see currentColor and paints its glyph black,
 * which disappears on the dark scheme.
 */
#[CoversNothing]
final class BackendIconRegistrationTest extends AbstractPasskeyFunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function recordTableProvider(): iterable
    {
        yield 'credential' => ['tx_nrpasskeysfe_credential', 'credential.svg#nr-passkeys-fe-credential'];
        yield 'recovery code' => ['tx_nrpasskeysfe_recovery_code', 'recovery-code.svg#nr-passkeys-fe-recovery-code'];
    }

    #[Test]
    #[DataProvider('recordTableProvider')]
    public function recordIconsRenderAsSpriteUse(string $table, string $sprite): void
    {
        $iconFactory = $this->get(IconFactory::class);
        $markup = $iconFactory->getIconForRecord($table, ['uid' => 1], IconSize::SMALL)->render();

        self::assertStringContainsString('<use xlink:href="', $markup);
        [$file, $fragment] = \explode('#', $sprite, 2);
        // Core appends a cache-busting query between the file and the fragment.
        self::assertMatchesRegularExpression(
            '~Resources/Public/Icons/' . \preg_quote($file, '~') . '(\?\d+)?#' . \preg_quote($fragment, '~') . '"~',
            $markup,
        );
        self::assertStringNotContainsString('<img', $markup);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pluginIconProvider(): iterable
    {
        foreach (['login', 'management', 'enrollment'] as $plugin) {
            yield $plugin => ['nr-passkeys-fe-plugin-' . $plugin];
        }
    }

    #[Test]
    #[DataProvider('pluginIconProvider')]
    public function pluginIconsRenderAsSpriteUse(string $identifier): void
    {
        $iconFactory = $this->get(IconFactory::class);
        $markup = $iconFactory->getIcon($identifier, IconSize::SMALL)->render();

        self::assertStringContainsString('#' . $identifier . '"', $markup);
        self::assertStringContainsString('<use xlink:href="', $markup);
        self::assertStringNotContainsString('<img', $markup);
    }
}
