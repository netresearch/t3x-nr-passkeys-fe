<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller;

use InvalidArgumentException;
use Netresearch\NrPasskeysFe\Controller\EidDispatcher;
use Netresearch\NrPasskeysFe\Controller\EnrollmentController;
use Netresearch\NrPasskeysFe\Controller\LoginController;
use Netresearch\NrPasskeysFe\Controller\ManagementController;
use Netresearch\NrPasskeysFe\Controller\RecoveryController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

#[CoversClass(EidDispatcher::class)]
final class EidDispatcherTest extends TestCase
{
    private EidDispatcher $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new EidDispatcher();

        // Register a Context singleton so bootstrapFrontendUser() can set aspects
        GeneralUtility::setSingletonInstance(Context::class, new Context());
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Unknown action
    // ---------------------------------------------------------------

    #[Test]
    public function unknownActionReturns404(): void
    {
        $request = $this->buildRequest(['action' => 'nonExistentAction']);

        $response = $this->subject->processRequest($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertJsonBodyEquals(['error' => 'Unknown action'], $response);
    }

    #[Test]
    public function missingActionReturns404(): void
    {
        $request = $this->buildRequest([]);

        $response = $this->subject->processRequest($request);

        self::assertSame(404, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Public actions pass through without FE auth
    // ---------------------------------------------------------------

    /**
     * @return list<array{string}>
     */
    public static function publicActionsProvider(): array
    {
        return [
            ['loginOptions'],
            ['loginVerify'],
            ['recoveryVerify'],
        ];
    }

    #[Test]
    #[DataProvider('publicActionsProvider')]
    public function publicActionWithoutFeUserPassesThrough(string $action): void
    {
        $expectedResponse = new JsonResponse(['status' => 'ok']);
        $controllerStub = $this->createPublicActionControllerStub($action, $expectedResponse);
        $this->registerControllerStub($action, $controllerStub);

        // Register a stub FrontendUserAuthentication for bootstrapFrontendUser()
        $feUserStub = $this->createStub(FrontendUserAuthentication::class);
        $feUserStub->user = null;
        $feUserStub->method('createUserAspect')->willReturn(new UserAspect());
        GeneralUtility::addInstance(FrontendUserAuthentication::class, $feUserStub);

        $request = $this->buildRequest(['action' => $action]); // No frontend.user attribute

        $response = $this->subject->processRequest($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Non-public actions require authentication
    // ---------------------------------------------------------------

    /**
     * @return list<array{string}>
     */
    public static function protectedActionsProvider(): array
    {
        return [
            ['registrationOptions'],
            ['registrationVerify'],
            ['manageList'],
            ['manageRename'],
            ['manageRemove'],
            ['recoveryGenerate'],
            ['enrollmentStatus'],
            ['enrollmentSkip'],
        ];
    }

    #[Test]
    #[DataProvider('protectedActionsProvider')]
    public function protectedActionWithoutFeUserReturns401(string $action): void
    {
        // Register a mock FrontendUserAuthentication that has no logged-in user.
        // bootstrapFrontendUser() will pick this up via GeneralUtility::makeInstance().
        $feUserMock = $this->createMock(FrontendUserAuthentication::class);
        $feUserMock->user = null;
        $feUserMock->expects(self::once())->method('start');
        $feUserMock->expects(self::once())->method('fetchGroupData');
        $feUserMock->method('createUserAspect')->willReturn(new UserAspect());
        GeneralUtility::addInstance(FrontendUserAuthentication::class, $feUserMock);

        $request = $this->buildRequest(['action' => $action]);
        // No frontend.user attribute → bootstrapFrontendUser() resolves session

        $response = $this->subject->processRequest($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertJsonBodyEquals(['error' => 'Authentication required'], $response);
    }

    #[Test]
    #[DataProvider('protectedActionsProvider')]
    public function protectedActionWithZeroUidFeUserReturns401(string $action): void
    {
        $feUserStub = $this->createStub(FrontendUserAuthentication::class);
        $feUserStub->user = ['uid' => 0];

        // Already has frontend.user → bootstrapFrontendUser() skips
        $request = $this->buildRequest(['action' => $action])
            ->withAttribute('frontend.user', $feUserStub);

        $response = $this->subject->processRequest($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('protectedActionsProvider')]
    public function protectedActionWithAuthenticatedFeUserDispatches(string $action): void
    {
        $expectedResponse = new JsonResponse(['status' => 'dispatched']);
        $controllerStub = $this->createProtectedActionControllerStub($action, $expectedResponse);
        $this->registerControllerStub($action, $controllerStub);

        $feUserStub = $this->createStub(FrontendUserAuthentication::class);
        $feUserStub->user = ['uid' => 42];

        // Already has frontend.user → bootstrapFrontendUser() skips
        $request = $this->buildRequest(['action' => $action])
            ->withAttribute('frontend.user', $feUserStub);

        $response = $this->subject->processRequest($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Controller exceptions produce 500
    // ---------------------------------------------------------------

    #[Test]
    public function controllerExceptionReturns500(): void
    {
        $loginStub = $this->createStub(LoginController::class);
        $loginStub->method('optionsAction')->willThrowException(new RuntimeException('Boom'));
        GeneralUtility::addInstance(LoginController::class, $loginStub);

        // Register a stub FrontendUserAuthentication for bootstrapFrontendUser()
        $feUserStub = $this->createStub(FrontendUserAuthentication::class);
        $feUserStub->user = null;
        $feUserStub->method('createUserAspect')->willReturn(new UserAspect());
        GeneralUtility::addInstance(FrontendUserAuthentication::class, $feUserStub);

        $request = $this->buildRequest(['action' => 'loginOptions']);
        $response = $this->subject->processRequest($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertJsonBodyEquals(['error' => 'Internal error'], $response);
    }

    // ---------------------------------------------------------------
    // Bootstrap FE user session for eID requests
    // ---------------------------------------------------------------

    #[Test]
    public function protectedActionBootstrapsFrontendUserWhenNotOnRequest(): void
    {
        $expectedResponse = new JsonResponse(['status' => 'dispatched']);
        $managementStub = $this->createStub(ManagementController::class);
        $managementStub->method('listAction')->willReturn($expectedResponse);
        GeneralUtility::addInstance(ManagementController::class, $managementStub);

        // Register a mock FE user that has a valid session (uid=42)
        // Uses createMock (not stub) because we assert expectations on start/fetchGroupData
        $feUserMock = $this->createMock(FrontendUserAuthentication::class);
        $feUserMock->user = ['uid' => 42];
        $feUserMock->expects(self::once())->method('start');
        $feUserMock->expects(self::once())->method('fetchGroupData');
        $feUserMock->method('createUserAspect')->willReturn(new UserAspect());
        GeneralUtility::addInstance(FrontendUserAuthentication::class, $feUserMock);

        // Request WITHOUT frontend.user attribute — simulates eID request
        $request = $this->buildRequest(['action' => 'manageList']);

        $response = $this->subject->processRequest($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function bootstrapSkipsWhenFrontendUserAlreadyPresent(): void
    {
        $expectedResponse = new JsonResponse(['status' => 'dispatched']);
        $managementStub = $this->createStub(ManagementController::class);
        $managementStub->method('listAction')->willReturn($expectedResponse);
        GeneralUtility::addInstance(ManagementController::class, $managementStub);

        // Pre-set frontend.user on request (e.g. if TYPO3 fixes middleware order)
        // Uses createMock because we assert expectations on start/fetchGroupData
        $feUserMock = $this->createMock(FrontendUserAuthentication::class);
        $feUserMock->user = ['uid' => 42];
        // start() and fetchGroupData() should NOT be called
        $feUserMock->expects(self::never())->method('start');
        $feUserMock->expects(self::never())->method('fetchGroupData');

        $request = $this->buildRequest(['action' => 'manageList'])
            ->withAttribute('frontend.user', $feUserMock);

        $response = $this->subject->processRequest($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // PUBLIC_ACTIONS constant
    // ---------------------------------------------------------------

    #[Test]
    public function publicActionsConstantContainsExpectedValues(): void
    {
        self::assertContains('loginOptions', EidDispatcher::PUBLIC_ACTIONS);
        self::assertContains('loginVerify', EidDispatcher::PUBLIC_ACTIONS);
        self::assertContains('recoveryVerify', EidDispatcher::PUBLIC_ACTIONS);
        self::assertCount(3, EidDispatcher::PUBLIC_ACTIONS);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function buildRequest(array $queryParams): ServerRequest
    {
        return (new ServerRequest('https://example.com/?eID=nr_passkeys_fe', 'GET'))
            ->withQueryParams($queryParams);
    }

    /**
     * Create a mock for the controller that handles the given public action.
     */
    private function createPublicActionControllerStub(string $action, ResponseInterface $response): object
    {
        return match ($action) {
            'loginOptions' => $this->createLoginStub('optionsAction', $response),
            'loginVerify' => $this->createLoginStub('verifyAction', $response),
            'recoveryVerify' => $this->createRecoveryStub('verifyAction', $response),
            default => throw new InvalidArgumentException("Unknown public action: $action"),
        };
    }

    /**
     * Create a stub for the controller that handles the given protected action.
     */
    private function createProtectedActionControllerStub(string $action, ResponseInterface $response): object
    {
        return match ($action) {
            'registrationOptions' => $this->createManagementStub('registrationOptionsAction', $response),
            'registrationVerify' => $this->createManagementStub('registrationVerifyAction', $response),
            'manageList' => $this->createManagementStub('listAction', $response),
            'manageRename' => $this->createManagementStub('renameAction', $response),
            'manageRemove' => $this->createManagementStub('removeAction', $response),
            'recoveryGenerate' => $this->createRecoveryStub('generateAction', $response),
            'enrollmentStatus' => $this->createEnrollmentStub('statusAction', $response),
            'enrollmentSkip' => $this->createEnrollmentStub('skipAction', $response),
            default => throw new InvalidArgumentException("Unknown protected action: $action"),
        };
    }

    private function createLoginStub(string $method, ResponseInterface $response): LoginController
    {
        $stub = $this->createStub(LoginController::class);
        $stub->method($method)->willReturn($response);
        return $stub;
    }

    private function createManagementStub(string $method, ResponseInterface $response): ManagementController
    {
        $stub = $this->createStub(ManagementController::class);
        $stub->method($method)->willReturn($response);
        return $stub;
    }

    private function createRecoveryStub(string $method, ResponseInterface $response): RecoveryController
    {
        $stub = $this->createStub(RecoveryController::class);
        $stub->method($method)->willReturn($response);
        return $stub;
    }

    private function createEnrollmentStub(string $method, ResponseInterface $response): EnrollmentController
    {
        $stub = $this->createStub(EnrollmentController::class);
        $stub->method($method)->willReturn($response);
        return $stub;
    }

    /**
     * Register a controller stub in GeneralUtility so makeInstance returns it.
     */
    private function registerControllerStub(string $action, object $stub): void
    {
        $controllerClass = match (true) {
            \in_array($action, ['loginOptions', 'loginVerify'], true) => LoginController::class,
            \in_array($action, ['registrationOptions', 'registrationVerify', 'manageList', 'manageRename', 'manageRemove'], true) => ManagementController::class,
            \in_array($action, ['recoveryGenerate', 'recoveryVerify'], true) => RecoveryController::class,
            \in_array($action, ['enrollmentStatus', 'enrollmentSkip'], true) => EnrollmentController::class,
            default => throw new InvalidArgumentException("Cannot determine controller for action: $action"),
        };

        GeneralUtility::addInstance($controllerClass, $stub);
    }

    /**
     * Assert that the response body decodes to the expected array.
     */
    private static function assertJsonBodyEquals(array $expected, ResponseInterface $response): void
    {
        $body = (string) $response->getBody();
        $decoded = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, $decoded);
    }
}
