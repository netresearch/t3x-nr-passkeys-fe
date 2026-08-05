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
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\FrontendLogin\Event\ModifyLoginFormViewEvent;

#[CoversClass(InjectPasskeyLoginFields::class)]
final class InjectPasskeyLoginFieldsTest extends TestCase
{
    private SiteConfigurationService&Stub $siteConfigService;

    private FrontendConfiguration $frontendConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteConfigService = $this->createStub(SiteConfigurationService::class);
        $this->frontendConfiguration = new FrontendConfiguration(enableFePasskeys: true);
    }

    private function buildEvent(ViewInterface $view, ?SiteInterface $site): ModifyLoginFormViewEvent
    {
        $request = new ServerRequest('https://example.com/', 'GET');
        if ($site instanceof SiteInterface) {
            $request = $request->withAttribute('site', $site);
        }

        return new ModifyLoginFormViewEvent($view, $request);
    }

    #[Test]
    public function doesNothingForForeignEventObjects(): void
    {
        $assetCollector = $this->createMock(AssetCollector::class);
        $subject = new InjectPasskeyLoginFields(
            $this->siteConfigService,
            $this->frontendConfiguration,
            $assetCollector,
        );

        $assetCollector->expects(self::never())->method('addInlineJavaScript');
        $assetCollector->expects(self::never())->method('addJavaScript');

        // The listener parameter type is object; anything that is not the
        // felogin event must be ignored.
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

        $subject->__invoke($this->buildEvent($this->createStub(ViewInterface::class), null));
    }

    #[Test]
    public function injectsConfigAndModuleForSiteWithRpId(): void
    {
        $site = $this->createStub(SiteInterface::class);
        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getOrigin')->willReturn('https://example.com');

        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector->expects(self::once())
            ->method('addInlineJavaScript')
            ->with(
                'nr-passkeys-fe-config',
                self::logicalAnd(
                    self::stringContains('"rpId":"example.com"'),
                    self::stringContains('"origin":"https:\/\/example.com"'),
                ),
                self::anything(),
                self::anything(),
            );
        $assetCollector->expects(self::once())
            ->method('addJavaScript')
            ->with('nr-passkeys-fe-login', self::anything(), self::anything(), self::anything());

        $subject = new InjectPasskeyLoginFields(
            $this->siteConfigService,
            $this->frontendConfiguration,
            $assetCollector,
        );

        $view = $this->createMock(ViewInterface::class);
        $view->expects(self::exactly(3))->method('assign');

        $subject->__invoke($this->buildEvent($view, $site));
    }

    #[Test]
    public function injectsEmptyRpIdWhenRequestHasNoSite(): void
    {
        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector->expects(self::once())
            ->method('addInlineJavaScript')
            ->with(
                'nr-passkeys-fe-config',
                self::stringContains('"rpId":""'),
                self::anything(),
                self::anything(),
            );

        $subject = new InjectPasskeyLoginFields(
            $this->siteConfigService,
            $this->frontendConfiguration,
            $assetCollector,
        );

        $subject->__invoke($this->buildEvent($this->createStub(ViewInterface::class), null));
    }
}
