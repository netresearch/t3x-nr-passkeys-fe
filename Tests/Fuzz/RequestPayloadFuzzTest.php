<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Fuzz;

use Netresearch\NrPasskeysFe\Controller\EidDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

#[CoversClass(EidDispatcher::class)]
final class RequestPayloadFuzzTest extends TestCase
{
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
        // loginOptions is a public action but requires site context — the controller
        // will fail with 500 (no site) before any body parsing security issue arises.
        // The important property: no unhandled exceptions propagate out.
        $request = $this->createRequestWithAction('loginOptions', $body);
        $response = $this->dispatcher->processRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
        // Must be a structured JSON error response (any 4xx or 5xx), not an exception
        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertLessThan(600, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('malformedJsonProvider')]
    public function recoveryVerifyHandlesMalformedJson(string $body): void
    {
        $request = $this->createRequestWithAction('recoveryVerify', $body);
        $response = $this->dispatcher->processRequest($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertLessThan(600, $response->getStatusCode());
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
        if ($feUser !== null) {
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
