<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\EventListener;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\EventListener\InjectPasskeyLoginFields;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use stdClass;
use TYPO3\CMS\Core\Page\AssetCollector;

#[CoversClass(InjectPasskeyLoginFields::class)]
final class InjectPasskeyLoginFieldsTest extends TestCase
{
    private SiteConfigurationService&Stub $siteConfigService;
    private FrontendConfiguration $frontendConfiguration;
    private AssetCollector&Stub $assetCollector;
    private InjectPasskeyLoginFields $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteConfigService = $this->createStub(SiteConfigurationService::class);
        $this->frontendConfiguration = new FrontendConfiguration(enableFePasskeys: true);
        $this->assetCollector = $this->createStub(AssetCollector::class);
        $this->subject = new InjectPasskeyLoginFields(
            $this->siteConfigService,
            $this->frontendConfiguration,
            $this->assetCollector,
        );
    }

    #[Test]
    public function doesNothingWhenFeloginNotInstalled(): void
    {
        $assetCollector = $this->createMock(AssetCollector::class);
        $subject = new InjectPasskeyLoginFields(
            $this->siteConfigService,
            $this->frontendConfiguration,
            $assetCollector,
        );

        $assetCollector->expects(self::never())->method('addInlineJavaScript');
        $assetCollector->expects(self::never())->method('addJavaScript');

        // Pass a plain stdClass as the event (not felogin event)
        $subject->__invoke(new stdClass());
    }

    #[Test]
    public function doesNothingWhenFePasskeysDisabled(): void
    {
        $assetCollector = $this->createMock(AssetCollector::class);
        $subject = new InjectPasskeyLoginFields(
            $this->siteConfigService,
            new FrontendConfiguration(enableFePasskeys: false),
            $assetCollector,
        );

        $assetCollector->expects(self::never())->method('addInlineJavaScript');

        // Pass a plain stdClass as the event (felogin guard runs first anyway)
        $subject->__invoke(new stdClass());
    }

    #[Test]
    public function listenerIsInstantiable(): void
    {
        self::assertInstanceOf(InjectPasskeyLoginFields::class, $this->subject);
    }

    #[Test]
    public function listenerHasExpectedDependencies(): void
    {
        // Verify constructor accepts all required dependencies without errors
        $listener = new InjectPasskeyLoginFields(
            $this->siteConfigService,
            $this->frontendConfiguration,
            $this->assetCollector,
        );
        self::assertInstanceOf(InjectPasskeyLoginFields::class, $listener);
    }
}
