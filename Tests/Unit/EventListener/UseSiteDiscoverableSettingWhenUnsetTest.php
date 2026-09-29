<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\EventListener;

use Netresearch\NrPasskeysFe\EventListener\UseSiteDiscoverableSettingWhenUnset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Extbase\Event\Configuration\BeforeFlexFormConfigurationOverrideEvent;

#[CoversClass(UseSiteDiscoverableSettingWhenUnset::class)]
final class UseSiteDiscoverableSettingWhenUnsetTest extends TestCase
{
    private const LOGIN_PLUGIN = ['extensionName' => 'NrPasskeysFe', 'pluginName' => 'PasskeyLogin'];

    #[Test]
    public function anEmptyValueLeavesTheSettingToTypoScript(): void
    {
        $flexForm = $this->dispatch(self::LOGIN_PLUGIN, ['discoverableEnabled' => '', 'cssClass' => 'x']);

        self::assertSame(['settings' => ['cssClass' => 'x']], $flexForm);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function chosenValues(): iterable
    {
        yield 'on' => ['1'];
        // Core's ignoreFlexFormSettingsIfEmpty would drop this one too.
        yield 'off' => ['0'];
    }

    #[Test]
    #[DataProvider('chosenValues')]
    public function aValueTheContentElementChoseIsKept(string $value): void
    {
        $flexForm = $this->dispatch(self::LOGIN_PLUGIN, ['discoverableEnabled' => $value]);

        self::assertSame(['settings' => ['discoverableEnabled' => $value]], $flexForm);
    }

    #[Test]
    public function anotherPluginIsLeftAlone(): void
    {
        $flexForm = $this->dispatch(
            ['extensionName' => 'NrPasskeysFe', 'pluginName' => 'PasskeyManagement'],
            ['discoverableEnabled' => ''],
        );

        self::assertSame(['settings' => ['discoverableEnabled' => '']], $flexForm);
    }

    /**
     * @param array<string, string> $framework
     * @param array<string, string> $settings
     *
     * @return array<string, mixed>
     */
    private function dispatch(array $framework, array $settings): array
    {
        $flexForm = ['settings' => $settings];
        $event = new BeforeFlexFormConfigurationOverrideEvent($framework, $flexForm, $flexForm);

        (new UseSiteDiscoverableSettingWhenUnset())($event);

        return $event->getFlexFormConfiguration();
    }
}
