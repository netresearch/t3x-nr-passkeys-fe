<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Fuzz;

use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Controller\EidDispatcher;
use Netresearch\NrPasskeysFe\Controller\LoginController;
use Netresearch\NrPasskeysFe\Controller\RecoveryController;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendUserLookupService;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

#[CoversClass(EidDispatcher::class)]
final class RequestPayloadFuzzTest extends TestCase
{
    /**
     * Data sets of malformedJsonProvider() whose body carries a non-empty
     * scalar username; every other body is read as carrying none.
     */
    private const BODIES_WITH_A_USERNAME = [
        'huge string value',
        'unicode username',
        'integer username',
        'sql injection username',
        'xss username',
        'extra proto fields',
        'path traversal',
    ];

    private EidDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new EidDispatcher();

        // Register a Context singleton so bootstrapFrontendUser() can set aspects
        GeneralUtility::setSingletonInstance(Context::class, new Context());
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Unknown action fuzz
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function fuzzedActionProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'not an action' => ['doSomethingBad'];
        yield 'sql injection' => ["'; DROP TABLE fe_users; --"];
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'null byte' => ["\x00loginOptions"];
        yield 'unicode action' => ['loginÖptions'];
        yield 'very long action' => [\str_repeat('loginOptions', 1000)];
        yield 'binary bytes' => [\random_bytes(20)];
        yield 'xss attempt' => ['<script>alert(1)</script>'];
        yield 'newline injection' => ["loginOptions\nContent-Type: text/html"];
        yield 'admin action' => ['adminDelete'];
        yield 'php code' => ['<?php phpinfo(); ?>'];
    }

    #[Test]
    #[DataProvider('fuzzedActionProvider')]
    public function unknownActionReturns404(string $action): void
    {
        $request = $this->createRequestWithAction($action, '');
        $response = $this->dispatcher->processRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(404, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Public actions with malformed JSON body
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedJsonProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'not json' => ['not json at all'];
        yield 'partial json' => ['{"username":'];
        yield 'array instead of object' => ['[1,2,3]'];
        yield 'null' => ['null'];
        yield 'number' => ['42'];
        yield 'string' => ['"just a string"'];
        yield 'boolean' => ['true'];
        yield 'deeply nested' => [\str_repeat('{"a":', 50) . '1' . \str_repeat('}', 50)];
        yield 'huge string value' => ['{"username":"' . \str_repeat('A', 50000) . '"}'];
        yield 'unicode username' => ['{"username":"ünïcödé_üser_🔑"}'];
        yield 'null username' => ['{"username":null}'];
        yield 'integer username' => ['{"username":42}'];
        yield 'array username' => ['{"username":["admin"]}'];
        yield 'sql injection username' => ['{"username":"\'; DROP TABLE fe_users; --"}'];
        yield 'xss username' => ['{"username":"<script>alert(1)</script>"}'];
        yield 'null bytes' => ["\x00\x01\x02\x03"];
        yield 'extra proto fields' => ['{"username":"admin","__proto__":{"polluted":true}}'];
        yield 'path traversal' => ['{"username":"../../etc/passwd"}'];
    }

    #[Test]
    #[DataProvider('malformedJsonProvider')]
    public function loginOptionsHandlesMalformedJson(string $body): void
    {
        // The real LoginController behind the real dispatcher: a malformed body
        // is read as "no usable username" (discoverable options) or as a
        // username (decoy options for an unknown account), never as an error.
        GeneralUtility::addInstance(LoginController::class, $this->realLoginController());

        $request = $this->createRequestWithAction('loginOptions', $body);
        $response = $this->dispatcher->processRequest($request);

        self::assertSame(200, $response->getStatusCode());
        $data = \json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('test-challenge-token', $data['challengeToken'] ?? null);
        self::assertIsArray($data['options'] ?? null);
        // Only a non-empty scalar username leads to the username-first branch.
        $expected = \in_array($this->dataName(), self::BODIES_WITH_A_USERNAME, true) ? 'decoy' : 'discoverable';
        self::assertSame($expected, $data['options']['challenge'] ?? null);
    }

    #[Test]
    #[DataProvider('malformedJsonProvider')]
    public function recoveryVerifyHandlesMalformedJson(string $body): void
    {
        // The real RecoveryController: none of these bodies carries a code, so
        // every one is refused as incomplete before any lookup.
        GeneralUtility::addInstance(RecoveryController::class, $this->realRecoveryController());

        $request = $this->createRequestWithAction('recoveryVerify', $body);
        $response = $this->dispatcher->processRequest($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'Missing required fields'], \json_decode((string) $response->getBody(), true));
    }

    // ---------------------------------------------------------------
    // Protected actions (require FE auth)
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedActionProvider(): iterable
    {
        yield 'registrationOptions' => ['registrationOptions'];
        yield 'registrationVerify' => ['registrationVerify'];
        yield 'manageList' => ['manageList'];
        yield 'manageRename' => ['manageRename'];
        yield 'manageRemove' => ['manageRemove'];
        yield 'recoveryGenerate' => ['recoveryGenerate'];
        yield 'enrollmentStatus' => ['enrollmentStatus'];
        yield 'enrollmentSkip' => ['enrollmentSkip'];
    }

    #[Test]
    #[DataProvider('protectedActionProvider')]
    public function unauthenticatedRequestToProtectedActionReturns401(string $action): void
    {
        $request = $this->createRequestWithAction($action, '{}', authenticated: false);
        $response = $this->dispatcher->processRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(401, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Random bytes — must never crash
    // ---------------------------------------------------------------

    #[Test]
    public function randomBytesAsActionNeverThrows(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $randomAction = \random_bytes(\random_int(1, 64));
            $request = $this->createRequestWithAction($randomAction, '');

            try {
                $response = $this->dispatcher->processRequest($request);
                self::assertInstanceOf(ResponseInterface::class, $response);
            } catch (Throwable $e) {
                self::fail('EidDispatcher threw an unhandled exception for random action bytes: ' . $e->getMessage());
            }
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * A LoginController whose collaborators are stubbed, so only its own request
     * handling runs: every username is unknown, so a named request gets decoy
     * options and an unnamed one discoverable options.
     */
    private function realLoginController(): LoginController
    {
        $site = $this->createStub(SiteInterface::class);
        $siteConfiguration = $this->createStub(SiteConfigurationService::class);
        $siteConfiguration->method('getCurrentSite')->willReturn($site);
        $siteConfiguration->method('getSiteIdentifier')->willReturn('main');

        $challengeService = $this->createStub(ChallengeService::class);
        $challengeService->method('generateChallenge')->willReturn(\str_repeat("\x01", 32));
        $challengeService->method('createChallengeToken')->willReturn('test-challenge-token');

        $webAuthn = $this->createStub(FrontendWebAuthnService::class);
        $webAuthn->method('createDiscoverableAssertionOptions')->willReturn(['options' => null, 'optionsJson' => '{"challenge":"discoverable"}']);
        $webAuthn->method('createDecoyAssertionOptions')->willReturn(['options' => null, 'optionsJson' => '{"challenge":"decoy"}']);

        $configuration = $this->createStub(ExtensionConfigurationService::class);
        $configuration->method('getConfiguration')->willReturn(new ExtensionConfiguration());

        return new LoginController(
            $webAuthn,
            $siteConfiguration,
            $this->createStub(FrontendCredentialRepository::class),
            $this->createStub(FrontendUserLookupService::class),
            $this->createStub(RateLimiterService::class),
            $challengeService,
            $configuration,
            $this->createStub(EventDispatcherInterface::class),
            new NullLogger(),
        );
    }

    private function realRecoveryController(): RecoveryController
    {
        return new RecoveryController(
            $this->createStub(RecoveryCodeService::class),
            $this->createStub(RateLimiterService::class),
            $this->createStub(FrontendUserLookupService::class),
            $this->createStub(EventDispatcherInterface::class),
            new NullLogger(),
        );
    }

    /**
     * Register a mock FrontendUserAuthentication for bootstrapFrontendUser().
     *
     * Called before each test that creates requests without frontend.user already set.
     */
    private function registerUnauthenticatedFeUserMock(): void
    {
        $feUserMock = $this->createStub(FrontendUserAuthentication::class);
        $feUserMock->user = null;
        $feUserMock->method('createUserAspect')->willReturn(new UserAspect());
        GeneralUtility::addInstance(FrontendUserAuthentication::class, $feUserMock);
    }

    private function createRequestWithAction(
        string $action,
        string $body,
        bool $authenticated = false,
    ): ServerRequestInterface {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $feUser = null;
        if ($authenticated) {
            $feUser = $this->createStub(FrontendUserAuthentication::class);
            $feUser->user = ['uid' => 1];
        } else {
            // Register mock for bootstrapFrontendUser() to pick up
            $this->registerUnauthenticatedFeUserMock();
        }

        // Track attributes so withAttribute()/getAttribute() work correctly
        $attributes = [];
        if ($feUser instanceof Stub) {
            $attributes['frontend.user'] = $feUser;
        }

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['eID' => 'nr_passkeys_fe', 'action' => $action]);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getBody')->willReturn($stream);
        $request->method('getAttribute')->willReturnCallback(
            static function (string $attr) use (&$attributes): mixed {
                return $attributes[$attr] ?? null;
            },
        );
        $request->method('withAttribute')->willReturnCallback(
            function (string $attr, mixed $value) use ($request, &$attributes): ServerRequestInterface {
                $attributes[$attr] = $value;
                return $request;
            },
        );

        return $request;
    }
}
