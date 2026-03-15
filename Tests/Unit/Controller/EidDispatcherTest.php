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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
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
        $controllerMock = $this->createPublicActionControllerMock($action, $expectedResponse);
        $this->registerControllerMock($action, $controllerMock);

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
        $request = $this->buildRequest(['action' => $action]);
        // No frontend.user attribute → unauthenticated

        $response = $this->subject->processRequest($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertJsonBodyEquals(['error' => 'Authentication required'], $response);
    }

    #[Test]
    #[DataProvider('protectedActionsProvider')]
    public function protectedActionWithZeroUidFeUserReturns401(string $action): void
    {
        $feUserMock = $this->createMock(FrontendUserAuthentication::class);
        $feUserMock->user = ['uid' => 0];

        $request = $this->buildRequest(['action' => $action])
            ->withAttribute('frontend.user', $feUserMock);

        $response = $this->subject->processRequest($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('protectedActionsProvider')]
    public function protectedActionWithAuthenticatedFeUserDispatches(string $action): void
    {
        $expectedResponse = new JsonResponse(['status' => 'dispatched']);
        $controllerMock = $this->createProtectedActionControllerMock($action, $expectedResponse);
        $this->registerControllerMock($action, $controllerMock);

        $feUserMock = $this->createMock(FrontendUserAuthentication::class);
        $feUserMock->user = ['uid' => 42];

        $request = $this->buildRequest(['action' => $action])
            ->withAttribute('frontend.user', $feUserMock);

        $response = $this->subject->processRequest($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Controller exceptions produce 500
    // ---------------------------------------------------------------

    #[Test]
    public function controllerExceptionReturns500(): void
    {
        $loginMock = $this->createMock(LoginController::class);
        $loginMock->method('optionsAction')->willThrowException(new RuntimeException('Boom'));
        GeneralUtility::addInstance(LoginController::class, $loginMock);

        $request = $this->buildRequest(['action' => 'loginOptions']);
        $response = $this->subject->processRequest($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertJsonBodyEquals(['error' => 'Internal error'], $response);
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
    private function createPublicActionControllerMock(string $action, ResponseInterface $response): object
    {
        return match ($action) {
            'loginOptions' => $this->createLoginMock('optionsAction', $response),
            'loginVerify' => $this->createLoginMock('verifyAction', $response),
            'recoveryVerify' => $this->createRecoveryMock('verifyAction', $response),
            default => throw new InvalidArgumentException("Unknown public action: $action"),
        };
    }

    /**
     * Create a mock for the controller that handles the given protected action.
     */
    private function createProtectedActionControllerMock(string $action, ResponseInterface $response): object
    {
        return match ($action) {
            'registrationOptions' => $this->createManagementMock('registrationOptionsAction', $response),
            'registrationVerify' => $this->createManagementMock('registrationVerifyAction', $response),
            'manageList' => $this->createManagementMock('listAction', $response),
            'manageRename' => $this->createManagementMock('renameAction', $response),
            'manageRemove' => $this->createManagementMock('removeAction', $response),
            'recoveryGenerate' => $this->createRecoveryMock('generateAction', $response),
            'enrollmentStatus' => $this->createEnrollmentMock('statusAction', $response),
            'enrollmentSkip' => $this->createEnrollmentMock('skipAction', $response),
            default => throw new InvalidArgumentException("Unknown protected action: $action"),
        };
    }

    private function createLoginMock(string $method, ResponseInterface $response): LoginController&MockObject
    {
        $mock = $this->createMock(LoginController::class);
        $mock->method($method)->willReturn($response);
        return $mock;
    }

    private function createManagementMock(string $method, ResponseInterface $response): ManagementController&MockObject
    {
        $mock = $this->createMock(ManagementController::class);
        $mock->method($method)->willReturn($response);
        return $mock;
    }

    private function createRecoveryMock(string $method, ResponseInterface $response): RecoveryController&MockObject
    {
        $mock = $this->createMock(RecoveryController::class);
        $mock->method($method)->willReturn($response);
        return $mock;
    }

    private function createEnrollmentMock(string $method, ResponseInterface $response): EnrollmentController&MockObject
    {
        $mock = $this->createMock(EnrollmentController::class);
        $mock->method($method)->willReturn($response);
        return $mock;
    }

    /**
     * Register a controller mock in GeneralUtility so makeInstance returns it.
     */
    private function registerControllerMock(string $action, object $mock): void
    {
        $controllerClass = match (true) {
            \in_array($action, ['loginOptions', 'loginVerify'], true) => LoginController::class,
            \in_array($action, ['registrationOptions', 'registrationVerify', 'manageList', 'manageRename', 'manageRemove'], true) => ManagementController::class,
            \in_array($action, ['recoveryGenerate', 'recoveryVerify'], true) => RecoveryController::class,
            \in_array($action, ['enrollmentStatus', 'enrollmentSkip'], true) => EnrollmentController::class,
            default => throw new InvalidArgumentException("Cannot determine controller for action: $action"),
        };

        GeneralUtility::addInstance($controllerClass, $mock);
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
