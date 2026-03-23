<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller\Plugin;

use Netresearch\NrPasskeysFe\Controller\Plugin\EnrollmentPluginController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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

#[CoversClass(EnrollmentPluginController::class)]
final class EnrollmentPluginControllerTest extends TestCase
{
    #[Test]
    public function isInstantiable(): void
    {
        $subject = new EnrollmentPluginController();
        self::assertInstanceOf(EnrollmentPluginController::class, $subject);
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
    public function indexActionAssignsEnrollmentUrls(): void
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

        $this->injectExtbaseProperties(
            $subject,
            $this->buildExtbaseRequest('test-site', 'https://test.example.com'),
            $view,
        );

        $subject->indexAction();

        self::assertSame('test-site', $assignedVars['siteIdentifier']);
        self::assertStringContainsString('action=registrationOptions', $assignedVars['registerOptionsUrl']);
        self::assertStringContainsString('action=registrationVerify', $assignedVars['registerVerifyUrl']);
        self::assertSame('off', $assignedVars['enforcementLevel']);
    }

    private function buildController(): EnrollmentPluginController
    {
        $subject = new EnrollmentPluginController();
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
            new ExtbaseRequestParameters(EnrollmentPluginController::class),
        );
        return new Request($serverRequest);
    }

    private function injectExtbaseProperties(
        EnrollmentPluginController $subject,
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
