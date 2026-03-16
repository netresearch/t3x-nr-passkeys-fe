<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Configuration;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrontendConfiguration::class)]
final class FrontendConfigurationTest extends TestCase
{
    #[Test]
    public function constructorWithDefaultsReturnsExpectedValues(): void
    {
        $config = new FrontendConfiguration();

        self::assertTrue($config->isEnableFePasskeys());
        self::assertSame('off', $config->getDefaultEnforcementLevel());
        self::assertSame(10, $config->getMaxPasskeysPerUser());
        self::assertTrue($config->isRecoveryCodesEnabled());
        self::assertSame(10, $config->getRecoveryCodeCount());
        self::assertFalse($config->isMagicLinkEnabled());
        self::assertTrue($config->isEnrollmentBannerEnabled());
        self::assertTrue($config->isPostLoginEnrollmentEnabled());
    }

    #[Test]
    public function fromArrayWithEmptyArrayReturnsDefaults(): void
    {
        $config = FrontendConfiguration::fromArray([]);

        self::assertTrue($config->isEnableFePasskeys());
        self::assertSame('off', $config->getDefaultEnforcementLevel());
        self::assertSame(10, $config->getMaxPasskeysPerUser());
        self::assertTrue($config->isRecoveryCodesEnabled());
        self::assertSame(10, $config->getRecoveryCodeCount());
        self::assertFalse($config->isMagicLinkEnabled());
        self::assertTrue($config->isEnrollmentBannerEnabled());
        self::assertTrue($config->isPostLoginEnrollmentEnabled());
    }

    #[Test]
    public function fromArrayWithAllOverridesReturnsCustomValues(): void
    {
        $config = FrontendConfiguration::fromArray([
            'enableFePasskeys' => '0',
            'defaultEnforcementLevel' => 'required',
            'maxPasskeysPerUser' => '5',
            'recoveryCodesEnabled' => '0',
            'recoveryCodeCount' => '8',
            'magicLinkEnabled' => '1',
            'enrollmentBannerEnabled' => '0',
            'postLoginEnrollmentEnabled' => '0',
        ]);

        self::assertFalse($config->isEnableFePasskeys());
        self::assertSame('required', $config->getDefaultEnforcementLevel());
        self::assertSame(5, $config->getMaxPasskeysPerUser());
        self::assertFalse($config->isRecoveryCodesEnabled());
        self::assertSame(8, $config->getRecoveryCodeCount());
        self::assertTrue($config->isMagicLinkEnabled());
        self::assertFalse($config->isEnrollmentBannerEnabled());
        self::assertFalse($config->isPostLoginEnrollmentEnabled());
    }

    #[Test]
    public function booleanStringZeroConvertsToFalse(): void
    {
        $config = FrontendConfiguration::fromArray([
            'enableFePasskeys' => '0',
            'recoveryCodesEnabled' => '0',
            'magicLinkEnabled' => '0',
            'enrollmentBannerEnabled' => '0',
            'postLoginEnrollmentEnabled' => '0',
        ]);

        self::assertFalse($config->isEnableFePasskeys());
        self::assertFalse($config->isRecoveryCodesEnabled());
        self::assertFalse($config->isMagicLinkEnabled());
        self::assertFalse($config->isEnrollmentBannerEnabled());
        self::assertFalse($config->isPostLoginEnrollmentEnabled());
    }

    #[Test]
    public function booleanStringOneConvertsToTrue(): void
    {
        $config = FrontendConfiguration::fromArray([
            'enableFePasskeys' => '1',
            'recoveryCodesEnabled' => '1',
            'magicLinkEnabled' => '1',
            'enrollmentBannerEnabled' => '1',
            'postLoginEnrollmentEnabled' => '1',
        ]);

        self::assertTrue($config->isEnableFePasskeys());
        self::assertTrue($config->isRecoveryCodesEnabled());
        self::assertTrue($config->isMagicLinkEnabled());
        self::assertTrue($config->isEnrollmentBannerEnabled());
        self::assertTrue($config->isPostLoginEnrollmentEnabled());
    }

    #[Test]
    public function integerStringConvertsToInt(): void
    {
        $config = FrontendConfiguration::fromArray([
            'maxPasskeysPerUser' => '25',
            'recoveryCodeCount' => '16',
        ]);

        self::assertSame(25, $config->getMaxPasskeysPerUser());
        self::assertSame(16, $config->getRecoveryCodeCount());
    }

    #[Test]
    public function nativeBooleanTrueIsAccepted(): void
    {
        $config = FrontendConfiguration::fromArray([
            'enableFePasskeys' => true,
            'magicLinkEnabled' => true,
        ]);

        self::assertTrue($config->isEnableFePasskeys());
        self::assertTrue($config->isMagicLinkEnabled());
    }

    #[Test]
    public function nativeBooleanFalseIsAccepted(): void
    {
        $config = FrontendConfiguration::fromArray([
            'enableFePasskeys' => false,
            'magicLinkEnabled' => false,
        ]);

        self::assertFalse($config->isEnableFePasskeys());
        self::assertFalse($config->isMagicLinkEnabled());
    }

    #[Test]
    public function nativeIntegerIsAccepted(): void
    {
        $config = FrontendConfiguration::fromArray([
            'maxPasskeysPerUser' => 20,
            'recoveryCodeCount' => 6,
        ]);

        self::assertSame(20, $config->getMaxPasskeysPerUser());
        self::assertSame(6, $config->getRecoveryCodeCount());
    }

    #[Test]
    public function constructorWithExplicitValuesOverridesDefaults(): void
    {
        $config = new FrontendConfiguration(
            enableFePasskeys: false,
            defaultEnforcementLevel: 'enforced',
            maxPasskeysPerUser: 3,
            recoveryCodesEnabled: false,
            recoveryCodeCount: 5,
            magicLinkEnabled: true,
            enrollmentBannerEnabled: false,
            postLoginEnrollmentEnabled: false,
        );

        self::assertFalse($config->isEnableFePasskeys());
        self::assertSame('enforced', $config->getDefaultEnforcementLevel());
        self::assertSame(3, $config->getMaxPasskeysPerUser());
        self::assertFalse($config->isRecoveryCodesEnabled());
        self::assertSame(5, $config->getRecoveryCodeCount());
        self::assertTrue($config->isMagicLinkEnabled());
        self::assertFalse($config->isEnrollmentBannerEnabled());
        self::assertFalse($config->isPostLoginEnrollmentEnabled());
    }
}
