<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller;

use Netresearch\NrPasskeysFe\Controller\AdminModuleController;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendAdoptionStats;
use Netresearch\NrPasskeysFe\Service\FrontendAdoptionStatsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Page\PageRenderer;

#[CoversClass(AdminModuleController::class)]
final class AdminModuleControllerTest extends TestCase
{
    private ModuleTemplateFactory&Stub $moduleTemplateFactory;
    private FrontendAdoptionStatsService&Stub $adoptionStatsService;
    private PageRenderer&Stub $pageRenderer;
    private UriBuilder&Stub $uriBuilder;
    private AdminModuleController $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleTemplateFactory = $this->createStub(ModuleTemplateFactory::class);
        $this->adoptionStatsService = $this->createStub(FrontendAdoptionStatsService::class);
        $this->pageRenderer = $this->createStub(PageRenderer::class);
        $this->uriBuilder = $this->createStub(UriBuilder::class);

        $this->subject = new AdminModuleController(
            $this->moduleTemplateFactory,
            $this->adoptionStatsService,
            $this->pageRenderer,
            $this->uriBuilder,
        );
    }

    #[Test]
    public function isInstantiable(): void
    {
        self::assertInstanceOf(AdminModuleController::class, $this->subject);
    }

    #[Test]
    public function dashboardActionReturnsResponse(): void
    {
        $moduleTemplate = $this->buildModuleTemplateStub();
        $this->moduleTemplateFactory->method('create')->willReturn($moduleTemplate);

        $stats = new FrontendAdoptionStats(
            totalUsers: 100,
            usersWithPasskeys: 25,
            adoptionPercentage: 25.0,
            perGroupStats: [],
        );
        $this->adoptionStatsService->method('getStats')->willReturn($stats);

        $request = new ServerRequest('https://example.com/typo3/module', 'GET');
        $response = $this->subject->dashboardAction($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function helpActionReturnsResponse(): void
    {
        $moduleTemplate = $this->buildModuleTemplateStub();
        $this->moduleTemplateFactory->method('create')->willReturn($moduleTemplate);
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(
            new \TYPO3\CMS\Core\Http\Uri('https://example.com/typo3/module'),
        );

        $request = new ServerRequest('https://example.com/typo3/module/help', 'GET');
        $response = $this->subject->helpAction($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    private function buildModuleTemplateStub(): ModuleTemplate&Stub
    {
        $menuItem = $this->createStub(\TYPO3\CMS\Backend\Template\Components\Menu\MenuItem::class);
        $menuItem->method('setTitle')->willReturnSelf();
        $menuItem->method('setHref')->willReturnSelf();
        $menuItem->method('setActive')->willReturnSelf();

        $menu = $this->createStub(\TYPO3\CMS\Backend\Template\Components\Menu\Menu::class);
        $menu->method('makeMenuItem')->willReturn($menuItem);

        $menuRegistry = $this->createStub(\TYPO3\CMS\Backend\Template\Components\MenuRegistry::class);
        $menuRegistry->method('makeMenu')->willReturn($menu);

        $docHeaderComponent = $this->createStub(\TYPO3\CMS\Backend\Template\Components\DocHeaderComponent::class);
        $docHeaderComponent->method('getMenuRegistry')->willReturn($menuRegistry);

        $responseStub = $this->createStub(ResponseInterface::class);

        $moduleTemplate = $this->createStub(ModuleTemplate::class);
        $moduleTemplate->method('getDocHeaderComponent')->willReturn($docHeaderComponent);
        $moduleTemplate->method('renderResponse')->willReturn($responseStub);

        return $moduleTemplate;
    }
}
