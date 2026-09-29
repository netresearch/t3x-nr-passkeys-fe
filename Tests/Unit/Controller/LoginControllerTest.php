<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller;

use ArrayObject;
use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Controller\LoginController;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendUserLookupService;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Netresearch\NrPasskeysFe\Tests\Unit\Controller\Fixtures\RateLimitExceeded;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(LoginController::class)]
final class LoginControllerTest extends TestCase
{
    private FrontendWebAuthnService&Stub $webAuthnService;

    private SiteConfigurationService&Stub $siteConfigService;

    private FrontendCredentialRepository&Stub $credentialRepository;

    private FrontendUserLookupService&Stub $userLookupService;

    private RateLimiterService&Stub $rateLimiterService;

    private ChallengeService&Stub $challengeService;

    private SiteInterface&Stub $site;

    private LoginController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webAuthnService = $this->createStub(FrontendWebAuthnService::class);
        $this->siteConfigService = $this->createStub(SiteConfigurationService::class);
        $this->credentialRepository = $this->createStub(FrontendCredentialRepository::class);
        $this->userLookupService = $this->createStub(FrontendUserLookupService::class);
        $this->rateLimiterService = $this->createStub(RateLimiterService::class);
        $this->challengeService = $this->createStub(ChallengeService::class);
        $this->site = $this->createStub(SiteInterface::class);

        $this->siteConfigService->method('getCurrentSite')->willReturn($this->site);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $this->challengeService->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeService->method('createChallengeToken')->willReturn('test-challenge-token');

        // Register a cache stub for the login token
        $cacheStub = $this->createStub(FrontendInterface::class);
        $cacheManagerStub = $this->createStub(CacheManager::class);
        $cacheManagerStub->method('getCache')->willReturn($cacheStub);
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManagerStub);

        // The options endpoint reports the challenge TTL so the client can
        // re-arm its conditional-UI ceremony before the challenge expires.
        $configurationService = $this->createStub(ExtensionConfigurationService::class);
        $configurationService->method('getConfiguration')->willReturn(new ExtensionConfiguration());

        $this->subject = new LoginController(
            $this->webAuthnService,
            $this->siteConfigService,
            $this->credentialRepository,
            $this->userLookupService,
            $this->rateLimiterService,
            $this->challengeService,
            $configurationService,
            $this->createStub(EventDispatcherInterface::class),
            new NullLogger(),
        );
    }

    // ---------------------------------------------------------------
    // optionsAction — rate limiting
    // ---------------------------------------------------------------

    #[Test]
    public function optionsActionReturns429WhenRateLimitExceeded(): void
    {
        $this->rateLimiterService->method('consumeRateLimit')
            ->willThrowException(new RuntimeException('Rate limit exceeded'));

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
        // Without the TTL the client cannot know when to re-arm its autofill
        // ceremony, and a passkey picked after the challenge expired fails.
        self::assertArrayHasKey('challengeTtlSeconds', $body);
        self::assertGreaterThan(0, $body['challengeTtlSeconds']);
    }

    // ---------------------------------------------------------------
    // optionsAction — username-first login
    // ---------------------------------------------------------------

    #[Test]
    public function optionsActionAnswersAnUnknownUsernameWithDecoyOptions(): void
    {
        // A 401 here would name the account as non-existent. The endpoint has
        // to answer as it does for a user who has a passkey — same status, same
        // shape — or a caller can enumerate frontend users one request at a
        // time.
        $this->setupDbUserNotFound();
        $this->webAuthnService
            ->method('createDecoyAssertionOptions')
            ->willReturn(['options' => null, 'optionsJson' => '{"challenge":"decoy"}']);

        $request = $this->buildJsonRequest('POST', ['username' => 'unknown@example.com']);
        $response = $this->subject->optionsAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('options', $body);
        self::assertArrayHasKey('challengeToken', $body);
    }

    #[Test]
    public function optionsActionAnswersAUserWithoutPasskeysWithDecoyOptions(): void
    {
        // Same for an account that exists but has enrolled nothing on this
        // site: the answer must not separate it from one that has.
        $this->setupDbUserFound(42);
        $this->credentialRepository->method('findByFeUser')->willReturn([]);
        $this->webAuthnService
            ->method('createDecoyAssertionOptions')
            ->willReturn(['options' => null, 'optionsJson' => '{"challenge":"decoy"}']);

        $request = $this->buildJsonRequest('POST', ['username' => 'user@example.com']);
        $response = $this->subject->optionsAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('options', $body);
        self::assertArrayHasKey('challengeToken', $body);
    }

    #[Test]
    public function optionsActionReturnsAssertionOptionsForKnownUser(): void
    {
        $this->setupDbUserFound(42);

        $credentialMock = $this->createStub(FrontendCredential::class);
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
        $this->rateLimiterService->method('consumeRateLimit')
            ->willThrowException(new RuntimeException('Rate limit exceeded'));

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
            ->willThrowException(new RuntimeException('Invalid token'));

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
            ->willThrowException(new RuntimeException('Verification failed'));

        $request = $this->buildJsonRequest('POST', [
            'assertion' => ['id' => 'abc', 'response' => []],
            'challengeToken' => 'valid-token',
        ]);
        $response = $this->subject->verifyAction($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function verifyActionAddsUnknownCredentialReasonWhenCredentialUnknown(): void
    {
        $this->challengeService->method('verifyChallengeToken')->willReturn(\str_repeat('a', 32));
        $this->webAuthnService->method('verifyAssertionResponse')
            ->willThrowException(new RuntimeException('Unknown credential', FrontendWebAuthnService::CODE_UNKNOWN_CREDENTIAL));

        $request = $this->buildJsonRequest('POST', [
            'assertion' => ['id' => 'abc', 'response' => []],
            'challengeToken' => 'valid-token',
        ]);
        $response = $this->subject->verifyAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('unknown_credential', $body['reason']);
    }

    #[Test]
    public function verifyActionOmitsReasonForGenericVerificationFailure(): void
    {
        $this->challengeService->method('verifyChallengeToken')->willReturn(\str_repeat('a', 32));
        $this->webAuthnService->method('verifyAssertionResponse')
            ->willThrowException(new RuntimeException('Signature invalid', 1700200099));

        $request = $this->buildJsonRequest('POST', [
            'assertion' => ['id' => 'abc', 'response' => []],
            'challengeToken' => 'valid-token',
        ]);
        $response = $this->subject->verifyAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertArrayNotHasKey('reason', $body);
    }

    #[Test]
    public function verifyActionReturns200OnSuccess(): void
    {
        $credentialMock = $this->createStub(FrontendCredential::class);
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

    // ---------------------------------------------------------------
    // optionsAction() — constant-time budget
    // ---------------------------------------------------------------
    //
    // The three username-first branches do different work: a decoy derives
    // its descriptors, a real answer queries the credentials and serializes
    // them. A caller who averages enough requests per username reads that
    // difference as an answer about the account, which is the channel the
    // decoys exist to close, so each branch is held to one floor. The floor
    // is asserted as a lower bound, which a sleep guarantees — an upper bound
    // would measure the machine.

    private const BUDGET_MS = 150.0;

    #[Test]
    public function anUnknownUsernameIsAnsweredAtTheBudget(): void
    {
        $this->setupDbUserNotFound();
        $this->webAuthnService
            ->method('createDecoyAssertionOptions')
            ->willReturn(['options' => null, 'optionsJson' => '{"challenge":"decoy"}']);

        self::assertGreaterThanOrEqual(self::BUDGET_MS, $this->timeOptionsAction('unknown@example.com'));
    }

    #[Test]
    public function aUserWithoutPasskeysIsAnsweredAtTheBudget(): void
    {
        $this->setupDbUserFound(42);
        $this->credentialRepository->method('findByFeUser')->willReturn([]);
        $this->webAuthnService
            ->method('createDecoyAssertionOptions')
            ->willReturn(['options' => null, 'optionsJson' => '{"challenge":"decoy"}']);

        self::assertGreaterThanOrEqual(self::BUDGET_MS, $this->timeOptionsAction('user@example.com'));
    }

    #[Test]
    public function aUserWithAPasskeyIsAnsweredAtTheBudget(): void
    {
        // The real branch too: a floor on the decoys alone is the same signal
        // with the sign flipped.
        $this->setupDbUserFound(42);
        $this->credentialRepository->method('findByFeUser')->willReturn([$this->createStub(FrontendCredential::class)]);
        $this->webAuthnService->method('createAssertionOptions')->willReturn([
            'options' => null,
            'optionsJson' => '{"challenge":"abc123"}',
        ]);

        self::assertGreaterThanOrEqual(self::BUDGET_MS, $this->timeOptionsAction('user@example.com'));
    }

    private function timeOptionsAction(string $username): float
    {
        $request = $this->buildJsonRequest('POST', ['username' => $username]);

        $start = \hrtime(true);
        $this->subject->optionsAction($request);

        return (\hrtime(true) - $start) / 1_000_000;
    }

    private function setupDbUserFound(int $uid): void
    {
        $this->userLookupService->method('findFeUserUidByUsername')
            ->willReturn($uid);
    }

    private function setupDbUserNotFound(): void
    {
        $this->userLookupService->method('findFeUserUidByUsername')
            ->willReturn(null);
    }

    private function assertJsonKey(string $key, ResponseInterface $response): void
    {
        $body = $this->decodeBody($response);
        self::assertArrayHasKey($key, $body);
    }

    private function decodeBody(ResponseInterface $response): array
    {
        return \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------
    // Client address: the request's normalizedParams, not getIndpEnv()
    // ---------------------------------------------------------------

    /**
     * A request as it arrives without core's normalized-params-attribute
     * middleware; tests that model the middleware add the attribute.
     *
     * @param array<string, string> $serverParams
     */
    private function requestFrom(array $serverParams): ServerRequest
    {
        return (new ServerRequest('https://example.com/?eID=nr_passkeys_fe', 'POST', 'php://input', [], $serverParams))
            ->withParsedBody([]);
    }

    /**
     * Let the rate limiter record which address each endpoint was charged for,
     * then stop the request as an exceeded limit would.
     *
     * @return ArrayObject<int, string> "endpoint@address" per call
     */
    private function recordRateLimitedAddresses(): ArrayObject
    {
        $addresses = new ArrayObject();
        $this->rateLimiterService->method('consumeRateLimit')->willReturnCallback(
            static function (string $endpoint, string $ip) use ($addresses): never {
                $addresses[] = $endpoint . '@' . $ip;
                throw new RateLimitExceeded();
            },
        );

        return $addresses;
    }

    #[Test]
    public function optionsActionRateLimitsTheAddressFromTheNormalizedParams(): void
    {
        $addresses = $this->recordRateLimitedAddresses();

        // The server param differs on purpose: only the attribute may count.
        $request = $this->requestFrom(['REMOTE_ADDR' => '192.0.2.1'])
            ->withAttribute('normalizedParams', new NormalizedParams(['REMOTE_ADDR' => '203.0.113.8'], [], '', ''));
        $this->subject->optionsAction($request);

        self::assertSame(['fe_login_options@203.0.113.8'], $addresses->getArrayCopy());
    }

    #[Test]
    public function verifyActionRateLimitsTheAddressFromTheNormalizedParams(): void
    {
        $addresses = $this->recordRateLimitedAddresses();

        // The server param differs on purpose: only the attribute may count.
        $request = $this->requestFrom(['REMOTE_ADDR' => '192.0.2.1'])
            ->withAttribute('normalizedParams', new NormalizedParams(['REMOTE_ADDR' => '203.0.113.9'], [], '', ''))
            ->withParsedBody(['assertion' => ['id' => 'x'], 'challengeToken' => 'token']);
        $response = $this->subject->verifyAction($request);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(['fe_login_verify@203.0.113.9'], $addresses->getArrayCopy());
    }

    #[Test]
    public function withoutTheAttributeTheAddressHonoursTheReverseProxyConfiguration(): void
    {
        // A request that did not pass the middleware: the address is computed
        // from the server params with the SYS configuration, as core does.
        $addresses = $this->recordRateLimitedAddresses();
        $backup = $GLOBALS['TYPO3_CONF_VARS']['SYS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP'] = '10.0.0.1';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyHeaderMultiValue'] = 'first';

        try {
            $this->subject->optionsAction($this->requestFrom(
                ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4'],
            ));
        } finally {
            if ($backup === null) {
                unset($GLOBALS['TYPO3_CONF_VARS']['SYS']);
            } else {
                $GLOBALS['TYPO3_CONF_VARS']['SYS'] = $backup;
            }
        }

        self::assertSame(['fe_login_options@198.51.100.4'], $addresses->getArrayCopy());
    }

    #[Test]
    public function aForwardedForHeaderFromAnUntrustedClientIsIgnored(): void
    {
        // No reverseProxyIP configured: X-Forwarded-For is whatever the client
        // sent, so the limiter must charge the connecting address.
        $addresses = $this->recordRateLimitedAddresses();
        $serverParams = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'];

        $request = $this->requestFrom($serverParams)
            ->withAttribute('normalizedParams', new NormalizedParams($serverParams, [], '', ''));
        $this->subject->optionsAction($request);

        self::assertSame(['fe_login_options@198.51.100.7'], $addresses->getArrayCopy());
    }

    #[Test]
    public function withoutTheAttributeAForwardedForHeaderFromAnUntrustedClientIsIgnored(): void
    {
        // The fallback path, again without a trusted proxy.
        $addresses = $this->recordRateLimitedAddresses();
        $backup = $GLOBALS['TYPO3_CONF_VARS']['SYS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS'] = [];

        try {
            $this->subject->optionsAction($this->requestFrom(
                ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'],
            ));
        } finally {
            if ($backup === null) {
                unset($GLOBALS['TYPO3_CONF_VARS']['SYS']);
            } else {
                $GLOBALS['TYPO3_CONF_VARS']['SYS'] = $backup;
            }
        }

        self::assertSame(['fe_login_options@198.51.100.7'], $addresses->getArrayCopy());
    }
}
