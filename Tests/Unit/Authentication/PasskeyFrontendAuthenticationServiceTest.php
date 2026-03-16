<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Authentication;

use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Authentication\PasskeyFrontendAuthenticationService;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(PasskeyFrontendAuthenticationService::class)]
final class PasskeyFrontendAuthenticationServiceTest extends TestCase
{
    private PasskeyFrontendAuthenticationService $subject;

    private FrontendWebAuthnService&MockObject $webAuthnService;

    private RateLimiterService&MockObject $rateLimiterService;

    private FrontendEnforcementService&MockObject $enforcementService;

    private SiteConfigurationService&MockObject $siteConfigService;

    private ChallengeService&MockObject $challengeService;

    private SiteInterface&MockObject $site;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webAuthnService = $this->createMock(FrontendWebAuthnService::class);
        $this->rateLimiterService = $this->createMock(RateLimiterService::class);
        $this->enforcementService = $this->createMock(FrontendEnforcementService::class);
        $this->siteConfigService = $this->createMock(SiteConfigurationService::class);
        $this->challengeService = $this->createMock(ChallengeService::class);
        $this->site = $this->createMock(SiteInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Default enforcement: off, no passkeys
        $this->enforcementService
            ->method('getStatus')
            ->willReturn(new FrontendEnforcementStatus(
                effectiveLevel: 'off',
                siteLevel: 'off',
                groupLevel: 'off',
                passkeyCount: 0,
                inGracePeriod: false,
                graceDeadline: null,
                recoveryCodesRemaining: 0,
            ));

        $this->siteConfigService
            ->method('getCurrentSite')
            ->willReturn($this->site);

        $this->siteConfigService
            ->method('getSiteIdentifier')
            ->willReturn('default');

        // Default: verifyChallengeToken returns the token as raw challenge
        $this->challengeService
            ->method('verifyChallengeToken')
            ->willReturnArgument(0);

        // Set up TYPO3_REQUEST so resolveSite() works
        $request = $this->createMock(ServerRequestInterface::class);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        GeneralUtility::addInstance(FrontendWebAuthnService::class, $this->webAuthnService);
        GeneralUtility::addInstance(RateLimiterService::class, $this->rateLimiterService);
        GeneralUtility::addInstance(FrontendEnforcementService::class, $this->enforcementService);
        GeneralUtility::addInstance(SiteConfigurationService::class, $this->siteConfigService);
        GeneralUtility::addInstance(ChallengeService::class, $this->challengeService);

        $this->subject = new PasskeyFrontendAuthenticationService();
        $this->injectLogger($this->subject, $this->logger);
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['TSFE']);
        parent::tearDown();
    }

    // --- Passkey payload encoding helper ---

    /**
     * Build a passkey payload JSON string as the JS would put into userident.
     *
     * @param array<string, mixed> $assertion
     */
    private static function buildPasskeyUident(array $assertion, string $challengeToken = 'challenge-token-123'): string
    {
        return \json_encode([
            '_type' => 'passkey',
            'assertion' => $assertion,
            'challengeToken' => $challengeToken,
        ], JSON_THROW_ON_ERROR);
    }

    // --- getUser tests ---

    #[Test]
    public function getUserReturnsFalseWhenNoPasskeyPayload(): void
    {
        $this->subject->login = [
            'uname' => 'testuser',
            'uident' => 'regularPassword123',
        ];

        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function getUserWithUsernameFindsUserViaFetchUserRecord(): void
    {
        $service = $this->getMockBuilder(PasskeyFrontendAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'frontend_user',
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];
        $this->injectLogger($service, $this->logger);

        $expectedUser = ['uid' => 42, 'username' => 'frontend_user'];

        $service
            ->expects(self::once())
            ->method('fetchUserRecord')
            ->with('frontend_user')
            ->willReturn($expectedUser);

        $result = $service->getUser();

        self::assertSame($expectedUser, $result);
    }

    #[Test]
    public function getUserDiscoverableResolvesFromCredential(): void
    {
        $this->subject->login = [
            'uname' => '',
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];

        $this->webAuthnService
            ->expects(self::once())
            ->method('findFeUserUidFromAssertion')
            ->with('{"assertion":"data"}')
            ->willReturn(42);

        $this->setUpFetchUserByUid(42, ['uid' => 42, 'username' => 'discovered_user', 'disable' => 0, 'deleted' => 0]);

        $result = $this->subject->getUser();

        self::assertIsArray($result);
        self::assertSame(42, $result['uid']);
        self::assertSame('discovered_user', $result['username']);
    }

    #[Test]
    public function getUserDiscoverableReturnsFalseWhenCredentialNotFound(): void
    {
        $this->subject->login = [
            'uname' => '',
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];

        $this->webAuthnService
            ->expects(self::once())
            ->method('findFeUserUidFromAssertion')
            ->with('{"assertion":"data"}')
            ->willReturn(null);

        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function getUserReturnsFalseWhenLockedOut(): void
    {
        $service = $this->getMockBuilder(PasskeyFrontendAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'locked_user',
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];
        $this->injectLogger($service, $this->logger);

        $expectedUser = ['uid' => 7, 'username' => 'locked_user'];

        $service
            ->expects(self::once())
            ->method('fetchUserRecord')
            ->with('locked_user')
            ->willReturn($expectedUser);

        // Provide fresh instances for this service instance
        GeneralUtility::addInstance(RateLimiterService::class, $this->rateLimiterService);

        $this->rateLimiterService
            ->expects(self::once())
            ->method('checkLockout')
            ->willThrowException(new RuntimeException('Account locked', 1700000011));

        $result = $service->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function getUserReturnsFalseForUnknownUser(): void
    {
        $service = $this->getMockBuilder(PasskeyFrontendAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'nonexistent',
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];
        $this->injectLogger($service, $this->logger);

        $service
            ->expects(self::once())
            ->method('fetchUserRecord')
            ->with('nonexistent')
            ->willReturn(false);

        $infoMessages = [];
        $this->logger
            ->method('info')
            ->willReturnCallback(function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $result = $service->getUser();

        self::assertFalse($result);
        self::assertContains('FE passkey login attempt for unknown user', $infoMessages);
    }

    #[Test]
    public function getUserWithMissingUnameKeyAttemptsDiscoverableLogin(): void
    {
        $this->subject->login = [
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];

        $this->webAuthnService
            ->expects(self::once())
            ->method('findFeUserUidFromAssertion')
            ->with('{"assertion":"data"}')
            ->willReturn(null);

        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    // --- authUser tests ---

    #[Test]
    public function authUserReturns100WhenNoPasskeyPayload(): void
    {
        $this->subject->login = [
            'uname' => 'testuser',
            'uident' => 'regularPassword123',
        ];

        $user = ['uid' => 42, 'username' => 'testuser'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserReturns200OnValidAssertion(): void
    {
        $credential = new FrontendCredential(uid: 10, feUser: 42, label: 'Test Key');

        $this->subject->login = [
            'uname' => 'frontend_user',
            'uident' => self::buildPasskeyUident(['valid' => 'assertion']),
        ];

        $this->rateLimiterService
            ->expects(self::once())
            ->method('checkLockout');

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->with(
                assertionJson: '{"valid":"assertion"}',
                challenge: 'challenge-token-123',
                site: $this->site,
            )
            ->willReturn([
                'feUserUid' => 42,
                'credential' => $credential,
            ]);

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordSuccess')
            ->with('frontend_user', self::anything());

        $user = ['uid' => 42, 'username' => 'frontend_user'];

        $result = $this->subject->authUser($user);

        self::assertSame(200, $result);
    }

    #[Test]
    public function authUserReturns0OnInvalidAssertion(): void
    {
        $this->subject->login = [
            'uname' => 'frontend_user',
            'uident' => self::buildPasskeyUident(['bad' => 'data']),
        ];

        $this->rateLimiterService
            ->expects(self::once())
            ->method('checkLockout');

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->willThrowException(new RuntimeException('Assertion failed', 1700200035));

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordFailure')
            ->with('frontend_user', self::anything());

        $user = ['uid' => 42, 'username' => 'frontend_user'];

        $result = $this->subject->authUser($user);

        self::assertSame(0, $result);
    }

    #[Test]
    public function authUserRecordsFailureAndLogsWarningOnError(): void
    {
        $this->subject->login = [
            'uname' => 'testuser',
            'uident' => self::buildPasskeyUident(['bad' => 'data']),
        ];

        $this->webAuthnService
            ->method('verifyAssertionResponse')
            ->willThrowException(new RuntimeException('Verification failed', 1700200035));

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordFailure')
            ->with('testuser', self::anything());

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('FE passkey authentication failed', self::anything());

        $user = ['uid' => 5, 'username' => 'testuser'];

        $result = $this->subject->authUser($user);

        self::assertSame(0, $result);
    }

    #[Test]
    public function authUserRespectsLockout(): void
    {
        $this->subject->login = [
            'uname' => 'locked_user',
            'uident' => self::buildPasskeyUident(['ok' => 'assertion'], 'token-abc'),
        ];

        $this->rateLimiterService
            ->expects(self::once())
            ->method('checkLockout')
            ->willThrowException(new RuntimeException('Account locked', 1700000011));

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordFailure')
            ->with('locked_user', self::anything());

        $this->webAuthnService
            ->expects(self::never())
            ->method('verifyAssertionResponse');

        $user = ['uid' => 7, 'username' => 'locked_user'];

        $result = $this->subject->authUser($user);

        self::assertSame(0, $result);
    }

    #[Test]
    public function authUserClearsLockoutOnSuccess(): void
    {
        $credential = new FrontendCredential(uid: 10, feUser: 42, label: 'Test Key');

        $this->subject->login = [
            'uname' => 'frontend_user',
            'uident' => self::buildPasskeyUident(['ok' => 'assertion'], 'token-abc'),
        ];

        $this->webAuthnService
            ->method('verifyAssertionResponse')
            ->willReturn([
                'feUserUid' => 42,
                'credential' => $credential,
            ]);

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordSuccess')
            ->with('frontend_user', self::anything());

        $user = ['uid' => 42, 'username' => 'frontend_user'];

        $result = $this->subject->authUser($user);

        self::assertSame(200, $result);
    }

    // --- Password login + enforcement tests ---

    #[Test]
    public function authUserBlocksPasswordWhenEnforcedAndUserHasPasskeys(): void
    {
        GeneralUtility::purgeInstances();

        $enforcementService = $this->createMock(FrontendEnforcementService::class);
        $enforcementService
            ->expects(self::once())
            ->method('getStatus')
            ->willReturn(new FrontendEnforcementStatus(
                effectiveLevel: 'enforced',
                siteLevel: 'enforced',
                groupLevel: 'off',
                passkeyCount: 2,
                inGracePeriod: false,
                graceDeadline: null,
                recoveryCodesRemaining: 0,
            ));
        GeneralUtility::addInstance(FrontendEnforcementService::class, $enforcementService);

        $siteConfigService = $this->createMock(SiteConfigurationService::class);
        $siteConfigService->method('getCurrentSite')->willReturn($this->site);
        $siteConfigService->method('getSiteIdentifier')->willReturn('default');
        GeneralUtility::addInstance(SiteConfigurationService::class, $siteConfigService);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with('FE password login blocked by enforcement', self::anything());

        $subject = new PasskeyFrontendAuthenticationService();
        $this->injectLogger($subject, $logger);

        $subject->login = [
            'uname' => 'enforced_user',
            'uident' => 'regularPassword123',
        ];

        $user = ['uid' => 42, 'username' => 'enforced_user'];

        $result = $subject->authUser($user);

        self::assertSame(0, $result);
    }

    #[Test]
    public function authUserAllowsPasswordWhenNoEnforcement(): void
    {
        $this->subject->login = [
            'uname' => 'normal_user',
            'uident' => 'regularPassword123',
        ];

        $user = ['uid' => 42, 'username' => 'normal_user'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserAllowsPasswordWhenEnforcedButNoPasskeys(): void
    {
        GeneralUtility::purgeInstances();

        $enforcementService = $this->createMock(FrontendEnforcementService::class);
        $enforcementService
            ->expects(self::once())
            ->method('getStatus')
            ->willReturn(new FrontendEnforcementStatus(
                effectiveLevel: 'enforced',
                siteLevel: 'enforced',
                groupLevel: 'off',
                passkeyCount: 0,
                inGracePeriod: false,
                graceDeadline: null,
                recoveryCodesRemaining: 0,
            ));
        GeneralUtility::addInstance(FrontendEnforcementService::class, $enforcementService);

        $siteConfigService = $this->createMock(SiteConfigurationService::class);
        $siteConfigService->method('getCurrentSite')->willReturn($this->site);
        $siteConfigService->method('getSiteIdentifier')->willReturn('default');
        GeneralUtility::addInstance(SiteConfigurationService::class, $siteConfigService);

        $subject = new PasskeyFrontendAuthenticationService();
        $this->injectLogger($subject, $this->logger);

        $subject->login = [
            'uname' => 'no_passkeys_user',
            'uident' => 'regularPassword123',
        ];

        $user = ['uid' => 70, 'username' => 'no_passkeys_user'];

        $result = $subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserAllowsPasswordWhenEncourageLevel(): void
    {
        GeneralUtility::purgeInstances();

        $enforcementService = $this->createMock(FrontendEnforcementService::class);
        $enforcementService
            ->expects(self::once())
            ->method('getStatus')
            ->willReturn(new FrontendEnforcementStatus(
                effectiveLevel: 'encourage',
                siteLevel: 'encourage',
                groupLevel: 'off',
                passkeyCount: 3,
                inGracePeriod: false,
                graceDeadline: null,
                recoveryCodesRemaining: 0,
            ));
        GeneralUtility::addInstance(FrontendEnforcementService::class, $enforcementService);

        $siteConfigService = $this->createMock(SiteConfigurationService::class);
        $siteConfigService->method('getCurrentSite')->willReturn($this->site);
        $siteConfigService->method('getSiteIdentifier')->willReturn('default');
        GeneralUtility::addInstance(SiteConfigurationService::class, $siteConfigService);

        $subject = new PasskeyFrontendAuthenticationService();
        $this->injectLogger($subject, $this->logger);

        $subject->login = [
            'uname' => 'encouraged_user',
            'uident' => 'regularPassword123',
        ];

        $user = ['uid' => 60, 'username' => 'encouraged_user'];

        $result = $subject->authUser($user);

        self::assertSame(100, $result);
    }

    // --- Payload parsing edge cases ---

    #[Test]
    public function authUserWithEmptyUidentReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithInvalidJsonUidentReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{not valid json',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithJsonMissingTypeReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{"assertion":{"test":true},"challengeToken":"token"}',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithWrongTypeReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{"_type":"password","assertion":{"test":true},"challengeToken":"token"}',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithMissingAssertionReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{"_type":"passkey","challengeToken":"token"}',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithEmptyChallengeTokenReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{"_type":"passkey","assertion":{"test":true},"challengeToken":""}',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithNonObjectAssertionReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{"_type":"passkey","assertion":"not-an-object","challengeToken":"token"}',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function payloadIsCachedAcrossGetUserAndAuthUser(): void
    {
        $credential = new FrontendCredential(uid: 10, feUser: 42, label: 'Test Key');

        $service = $this->getMockBuilder(PasskeyFrontendAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'frontend_user',
            'uident' => self::buildPasskeyUident(['cached' => 'test'], 'cached-token'),
        ];
        $this->injectLogger($service, $this->logger);

        GeneralUtility::addInstance(FrontendWebAuthnService::class, $this->webAuthnService);
        GeneralUtility::addInstance(RateLimiterService::class, $this->rateLimiterService);
        GeneralUtility::addInstance(SiteConfigurationService::class, $this->siteConfigService);
        GeneralUtility::addInstance(ChallengeService::class, $this->challengeService);

        $expectedUser = ['uid' => 42, 'username' => 'frontend_user'];
        $service->expects(self::once())->method('fetchUserRecord')->willReturn($expectedUser);

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->with(
                assertionJson: '{"cached":"test"}',
                challenge: 'cached-token',
                site: $this->site,
            )
            ->willReturn([
                'feUserUid' => 42,
                'credential' => $credential,
            ]);

        // Both getUser and authUser should use the same decoded payload
        $user = $service->getUser();
        self::assertIsArray($user);

        $result = $service->authUser($user);
        self::assertSame(200, $result);
    }

    #[Test]
    public function authUserSkipsEnforcementWhenUserUidIsZero(): void
    {
        $this->subject->login = [
            'uname' => 'ghost',
            'uident' => 'regularPassword123',
        ];

        $user = ['uid' => 0, 'username' => 'ghost'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserSkipsEnforcementWhenUserUidIsMissing(): void
    {
        $this->subject->login = [
            'uname' => 'nouid',
            'uident' => 'regularPassword123',
        ];

        $user = ['username' => 'nouid'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserReturns0WhenSiteUnavailableForPasskeyAuth(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        // Need fresh instances without TYPO3_REQUEST
        GeneralUtility::purgeInstances();
        GeneralUtility::addInstance(RateLimiterService::class, $this->rateLimiterService);
        GeneralUtility::addInstance(SiteConfigurationService::class, $this->siteConfigService);

        $subject = new PasskeyFrontendAuthenticationService();
        $this->injectLogger($subject, $this->logger);

        $subject->login = [
            'uname' => 'user',
            'uident' => self::buildPasskeyUident(['valid' => 'assertion']),
        ];

        $user = ['uid' => 42, 'username' => 'user'];

        $result = $subject->authUser($user);

        self::assertSame(0, $result);
    }

    // --- Helper methods ---

    /**
     * Set up ConnectionPool mock for fetchUserByUid.
     *
     * @param array<string, mixed>|null $userRow
     */
    private function setUpFetchUserByUid(int $uid, ?array $userRow): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturn($userRow ?? false);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn((string) $uid);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool
            ->method('getQueryBuilderForTable')
            ->with('fe_users')
            ->willReturn($queryBuilder);

        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool);
    }

    private function injectLogger(object $service, LoggerInterface $logger): void
    {
        $reflection = new ReflectionClass($service);
        $parent = $reflection;
        while ($parent !== false) {
            if ($parent->hasProperty('logger')) {
                $prop = $parent->getProperty('logger');
                $prop->setValue($service, $logger);
                return;
            }
            $parent = $parent->getParentClass();
        }
    }
}
