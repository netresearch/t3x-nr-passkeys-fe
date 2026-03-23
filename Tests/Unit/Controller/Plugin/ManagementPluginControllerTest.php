<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller\Plugin;

use Netresearch\NrPasskeysFe\Controller\Plugin\ManagementPluginController;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;

#[CoversClass(ManagementPluginController::class)]
final class ManagementPluginControllerTest extends TestCase
{
    private FrontendCredentialRepository&Stub $credentialRepository;
    private RecoveryCodeService&Stub $recoveryCodeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->credentialRepository = $this->createStub(FrontendCredentialRepository::class);
        $this->recoveryCodeService = $this->createStub(RecoveryCodeService::class);
    }

    #[Test]
    public function isInstantiable(): void
    {
        $subject = $this->buildController();
        self::assertInstanceOf(ManagementPluginController::class, $subject);
    }

    #[Test]
    public function indexActionReturnsResponseInterface(): void
    {
        $subject = $this->buildController();

        $view = $this->createStub(ViewInterface::class);
        $this->injectExtbaseProperties($subject, $this->buildExtbaseRequest('main', 'https://example.com'), $view);

        $response = $subject->indexAction();
        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function indexActionAssignsCredentialsAndRecoveryCount(): void
    {
        $subject = $this->buildController();

        $assignedVars = [];
        $view = $this->createStub(ViewInterface::class);
        $view->method('assignMultiple')->willReturnCallback(
            static function (array $vars) use (&$assignedVars, $view): ViewInterface {
                $assignedVars = $vars;
                return $view;
            },
        );

        // No authenticated FE user → credentials empty, recovery count 0
        $this->injectExtbaseProperties(
            $subject,
            $this->buildExtbaseRequest('main', 'https://example.com'),
            $view,
        );

        $subject->indexAction();

        self::assertSame([], $assignedVars['credentials']);
        self::assertSame(0, $assignedVars['recoveryCodesRemaining']);
        self::assertStringContainsString('eID=nr_passkeys_fe', $assignedVars['eidUrl']);
    }

    private function buildController(): ManagementPluginController
    {
        $subject = new ManagementPluginController(
            $this->credentialRepository,
            $this->recoveryCodeService,
        );
        $subject->injectResponseFactory(new ResponseFactory());
        $subject->injectStreamFactory(new StreamFactory());
        return $subject;
    }

    private function buildExtbaseRequest(string $siteIdentifier, string $baseUrl): Request
    {
        $site = $this->createStub(SiteInterface::class);
        $site->method('getIdentifier')->willReturn($siteIdentifier);
        $site->method('getBase')->willReturn(new \GuzzleHttp\Psr7\Uri($baseUrl));

        $serverRequest = new ServerRequest($baseUrl . '/page', 'GET');
        $serverRequest = $serverRequest->withAttribute('site', $site);
        $serverRequest = $serverRequest->withAttribute(
            'extbase',
            new ExtbaseRequestParameters(ManagementPluginController::class),
        );
        return new Request($serverRequest);
    }

    private function injectExtbaseProperties(
        ManagementPluginController $subject,
        Request $request,
        ViewInterface $view,
    ): void {
        $reflection = new ReflectionClass($subject);

        $requestProp = $reflection->getProperty('request');

        $requestProp->setValue($subject, $request);

        $viewProp = $reflection->getProperty('view');

        $viewProp->setValue($subject, $view);
    }
}
