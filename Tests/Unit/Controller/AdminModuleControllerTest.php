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
use PHPUnit\Framework\MockObject\MockObject;
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
    private ModuleTemplateFactory&MockObject $moduleTemplateFactory;
    private FrontendAdoptionStatsService&MockObject $adoptionStatsService;
    private PageRenderer&MockObject $pageRenderer;
    private UriBuilder&MockObject $uriBuilder;
    private ModuleTemplate&MockObject $moduleTemplate;
    private AdminModuleController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleTemplateFactory = $this->createMock(ModuleTemplateFactory::class);
        $this->adoptionStatsService = $this->createMock(FrontendAdoptionStatsService::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->moduleTemplate = $this->createMock(ModuleTemplate::class);

        $this->moduleTemplateFactory
            ->method('create')
            ->willReturn($this->moduleTemplate);

        $this->uriBuilder
            ->method('buildUriFromRoute')
            ->willReturn(new \TYPO3\CMS\Core\Http\Uri('/typo3/module/nr-passkeys-fe'));

        // renderResponse returns a minimal response
        $this->moduleTemplate
            ->method('renderResponse')
            ->willReturn($this->createMock(ResponseInterface::class));

        $this->moduleTemplate
            ->method('getDocHeaderComponent')
            ->willReturn($this->createStub(\TYPO3\CMS\Backend\Template\Components\DocHeaderComponent::class));

        $this->subject = new AdminModuleController(
            $this->moduleTemplateFactory,
            $this->adoptionStatsService,
            $this->pageRenderer,
            $this->uriBuilder,
        );
    }

    // ---------------------------------------------------------------
    // dashboardAction
    // ---------------------------------------------------------------

    #[Test]
    public function dashboardActionReturnsResponse(): void
    {
        $stats = new FrontendAdoptionStats(
            totalUsers: 10,
            usersWithPasskeys: 7,
            adoptionPercentage: 70.0,
            perGroupStats: [],
        );
        $this->adoptionStatsService->method('getStats')->willReturn($stats);

        $request = new ServerRequest('/typo3/module/nr-passkeys-fe', 'GET');
        $response = $this->subject->dashboardAction($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function dashboardActionAssignsStatsToTemplate(): void
    {
        $stats = new FrontendAdoptionStats(
            totalUsers: 20,
            usersWithPasskeys: 5,
            adoptionPercentage: 25.0,
            perGroupStats: [
                '3' => [
                    'groupName' => 'Editors',
                    'userCount' => 10,
                    'withPasskeys' => 2,
                    'enforcement' => 'encourage',
                ],
            ],
        );
        $this->adoptionStatsService->method('getStats')->willReturn($stats);

        $this->moduleTemplate
            ->expects($this->once())
            ->method('assignMultiple')
            ->with(self::callback(static function (array $vars): bool {
                return isset($vars['totalUsers'])
                    && $vars['totalUsers'] === 20
                    && isset($vars['usersWithPasskeys'])
                    && $vars['usersWithPasskeys'] === 5
                    && isset($vars['groups'])
                    && \count($vars['groups']) === 1
                    && $vars['groups'][0]['title'] === 'Editors';
            }));

        $request = new ServerRequest('/typo3/module/nr-passkeys-fe', 'GET');
        $this->subject->dashboardAction($request);
    }

    #[Test]
    public function dashboardActionLoadsJavaScriptModule(): void
    {
        $stats = new FrontendAdoptionStats(0, 0, 0.0, []);
        $this->adoptionStatsService->method('getStats')->willReturn($stats);

        $this->pageRenderer
            ->expects($this->once())
            ->method('loadJavaScriptModule')
            ->with('@netresearch/nr-passkeys-fe/PasskeyFeAdmin.js');

        $request = new ServerRequest('/typo3/module/nr-passkeys-fe', 'GET');
        $this->subject->dashboardAction($request);
    }

    #[Test]
    public function dashboardActionCalculatesGroupAdoptionPercentage(): void
    {
        $stats = new FrontendAdoptionStats(
            totalUsers: 10,
            usersWithPasskeys: 5,
            adoptionPercentage: 50.0,
            perGroupStats: [
                '7' => [
                    'groupName' => 'Members',
                    'userCount' => 8,
                    'withPasskeys' => 4,
                    'enforcement' => 'required',
                ],
            ],
        );
        $this->adoptionStatsService->method('getStats')->willReturn($stats);

        $this->moduleTemplate
            ->expects($this->once())
            ->method('assignMultiple')
            ->with(self::callback(static function (array $vars): bool {
                $groups = $vars['groups'] ?? [];
                return \count($groups) === 1
                    && $groups[0]['adoptionPercentage'] === 50.0;
            }));

        $request = new ServerRequest('/typo3/module/nr-passkeys-fe', 'GET');
        $this->subject->dashboardAction($request);
    }

    // ---------------------------------------------------------------
    // helpAction
    // ---------------------------------------------------------------

    #[Test]
    public function helpActionReturnsResponse(): void
    {
        $request = new ServerRequest('/typo3/module/nr-passkeys-fe/help', 'GET');
        $response = $this->subject->helpAction($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function helpActionRendersHelpTemplate(): void
    {
        $this->moduleTemplate
            ->expects($this->once())
            ->method('renderResponse')
            ->with('AdminModule/Help')
            ->willReturn($this->createMock(ResponseInterface::class));

        $request = new ServerRequest('/typo3/module/nr-passkeys-fe/help', 'GET');
        $this->subject->helpAction($request);
    }

    #[Test]
    public function helpActionAssignsDashboardUrl(): void
    {
        $this->moduleTemplate
            ->expects($this->once())
            ->method('assignMultiple')
            ->with(self::callback(static fn(array $vars): bool => isset($vars['dashboardUrl'])));

        $request = new ServerRequest('/typo3/module/nr-passkeys-fe/help', 'GET');
        $this->subject->helpAction($request);
    }
}
