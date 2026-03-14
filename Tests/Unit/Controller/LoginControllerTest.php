<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller;

use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Controller\LoginController;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

#[CoversClass(LoginController::class)]
final class LoginControllerTest extends TestCase
{
    private FrontendWebAuthnService&MockObject $webAuthnService;
    private SiteConfigurationService&MockObject $siteConfigService;
    private FrontendCredentialRepository&MockObject $credentialRepository;
    private RateLimiterService&MockObject $rateLimiterService;
    private ChallengeService&MockObject $challengeService;
    private ConnectionPool&MockObject $connectionPool;
    private SiteInterface&MockObject $site;
    private LoginController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webAuthnService = $this->createMock(FrontendWebAuthnService::class);
        $this->siteConfigService = $this->createMock(SiteConfigurationService::class);
        $this->credentialRepository = $this->createMock(FrontendCredentialRepository::class);
        $this->rateLimiterService = $this->createMock(RateLimiterService::class);
        $this->challengeService = $this->createMock(ChallengeService::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->site = $this->createMock(SiteInterface::class);

        $this->siteConfigService->method('getCurrentSite')->willReturn($this->site);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $this->challengeService->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeService->method('createChallengeToken')->willReturn('test-challenge-token');

        $this->subject = new LoginController(
            $this->webAuthnService,
            $this->siteConfigService,
            $this->credentialRepository,
            $this->rateLimiterService,
            $this->challengeService,
            $this->connectionPool,
            new NullLogger(),
        );
    }

    // ---------------------------------------------------------------
    // optionsAction — rate limiting
    // ---------------------------------------------------------------

    #[Test]
    public function optionsActionReturns429WhenRateLimitExceeded(): void
    {
        $this->rateLimiterService->method('checkRateLimit')
            ->willThrowException(new \RuntimeException('Rate limit exceeded'));

        $request = $this->buildJsonRequest('POST', []);
        $response = $this->subject->optionsAction($request);

        self::assertSame(429, $response->getStatusCode());
        self::assertJsonKey('error', $response);
    }

    // ---------------------------------------------------------------
    // optionsAction — discoverable login
    // ---------------------------------------------------------------

    #[Test]
    public function optionsActionReturnsDiscoverableOptionsWhenNoUsername(): void
    {
        $optionsData = ['challenge' => 'abc123', 'rpId' => 'example.com'];
        $this->webAuthnService->method('createDiscoverableAssertionOptions')->willReturn([
            'options' => null,
            'optionsJson' => \json_encode($optionsData),
        ]);

        $request = $this->buildJsonRequest('POST', []);
        $response = $this->subject->optionsAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertArrayHasKey('options', $body);
        self::assertArrayHasKey('challengeToken', $body);
        self::assertSame('test-challenge-token', $body['challengeToken']);
    }

    // ---------------------------------------------------------------
    // optionsAction — username-first login
    // ---------------------------------------------------------------

    #[Test]
    public function optionsActionReturns401WhenUsernameNotFound(): void
    {
        $this->setupDbUserNotFound();

        $request = $this->buildJsonRequest('POST', ['username' => 'unknown@example.com']);
        $response = $this->subject->optionsAction($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function optionsActionReturns401WhenUserHasNoPasskeys(): void
    {
        $this->setupDbUserFound(42);
        $this->credentialRepository->method('findByFeUser')->willReturn([]);

        $request = $this->buildJsonRequest('POST', ['username' => 'user@example.com']);
        $response = $this->subject->optionsAction($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function optionsActionReturnsAssertionOptionsForKnownUser(): void
    {
        $this->setupDbUserFound(42);

        $credentialMock = $this->createMock(\Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential::class);
        $this->credentialRepository->method('findByFeUser')->willReturn([$credentialMock]);

        $optionsData = ['challenge' => 'abc123', 'rpId' => 'example.com', 'allowCredentials' => []];
        $this->webAuthnService->method('createAssertionOptions')->willReturn([
            'options' => null,
            'optionsJson' => \json_encode($optionsData),
        ]);

        $request = $this->buildJsonRequest('POST', ['username' => 'user@example.com']);
        $response = $this->subject->optionsAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertArrayHasKey('options', $body);
        self::assertArrayHasKey('challengeToken', $body);
    }

    // ---------------------------------------------------------------
    // verifyAction — validation
    // ---------------------------------------------------------------

    #[Test]
    public function verifyActionReturns400WhenFieldsMissing(): void
    {
        $request = $this->buildJsonRequest('POST', []);
        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function verifyActionReturns400WhenOnlyAssertionPresent(): void
    {
        $request = $this->buildJsonRequest('POST', ['assertion' => ['id' => 'abc']]);
        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function verifyActionReturns429WhenRateLimitExceeded(): void
    {
        $this->rateLimiterService->method('checkRateLimit')
            ->willThrowException(new \RuntimeException('Rate limit exceeded'));

        $request = $this->buildJsonRequest('POST', [
            'assertion' => ['id' => 'abc', 'response' => []],
            'challengeToken' => 'token',
        ]);
        $response = $this->subject->verifyAction($request);

        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function verifyActionReturns401WhenChallengeTokenInvalid(): void
    {
        $this->challengeService->method('verifyChallengeToken')
            ->willThrowException(new \RuntimeException('Invalid token'));

        $request = $this->buildJsonRequest('POST', [
            'assertion' => ['id' => 'abc', 'response' => []],
            'challengeToken' => 'bad-token',
        ]);
        $response = $this->subject->verifyAction($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function verifyActionReturns401WhenAssertionVerificationFails(): void
    {
        $this->challengeService->method('verifyChallengeToken')->willReturn(\str_repeat('a', 32));
        $this->webAuthnService->method('verifyAssertionResponse')
            ->willThrowException(new \RuntimeException('Verification failed'));

        $request = $this->buildJsonRequest('POST', [
            'assertion' => ['id' => 'abc', 'response' => []],
            'challengeToken' => 'valid-token',
        ]);
        $response = $this->subject->verifyAction($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function verifyActionReturns200OnSuccess(): void
    {
        $credentialMock = $this->createMock(\Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential::class);
        $credentialMock->method('getUid')->willReturn(7);

        $this->challengeService->method('verifyChallengeToken')->willReturn(\str_repeat('a', 32));
        $this->webAuthnService->method('verifyAssertionResponse')->willReturn([
            'feUserUid' => 42,
            'credential' => $credentialMock,
        ]);

        $request = $this->buildJsonRequest('POST', [
            'assertion' => ['id' => 'abc', 'response' => []],
            'challengeToken' => 'valid-token',
        ]);
        $response = $this->subject->verifyAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('ok', $body['status']);
        self::assertSame(42, $body['feUserUid']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function buildJsonRequest(string $method, array $body): ServerRequest
    {
        $request = new ServerRequest('https://example.com/?eID=nr_passkeys_fe&action=login', $method);
        $request = $request->withHeader('Content-Type', 'application/json');

        return $request->withParsedBody($body);
    }

    private function setupDbUserFound(int $uid): void
    {
        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => $uid]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('expr')->willReturn($expr);
        $queryBuilder->method('createNamedParameter')->willReturnArgument(0);

        $this->connectionPool->method('getQueryBuilderForTable')
            ->with('fe_users')
            ->willReturn($queryBuilder);
    }

    private function setupDbUserNotFound(): void
    {
        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=0');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('expr')->willReturn($expr);
        $queryBuilder->method('createNamedParameter')->willReturnArgument(0);

        $this->connectionPool->method('getQueryBuilderForTable')
            ->with('fe_users')
            ->willReturn($queryBuilder);
    }

    private function assertJsonKey(string $key, \Psr\Http\Message\ResponseInterface $response): void
    {
        $body = $this->decodeBody($response);
        self::assertArrayHasKey($key, $body);
    }

    private function decodeBody(\Psr\Http\Message\ResponseInterface $response): array
    {
        return \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
