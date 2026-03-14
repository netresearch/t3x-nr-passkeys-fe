<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Middleware;

use Netresearch\NrPasskeysFe\Middleware\PasskeyPublicRouteResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\ServerRequest;

#[CoversClass(PasskeyPublicRouteResolver::class)]
final class PasskeyPublicRouteResolverTest extends TestCase
{
    private PasskeyPublicRouteResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new PasskeyPublicRouteResolver();
    }

    // ---------------------------------------------------------------
    // Public action detection
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function publicActionsProvider(): array
    {
        return [
            'loginOptions' => ['loginOptions'],
            'loginVerify' => ['loginVerify'],
            'recoveryVerify' => ['recoveryVerify'],
        ];
    }

    #[Test]
    #[DataProvider('publicActionsProvider')]
    public function publicActionSetsPublicRouteAttribute(string $action): void
    {
        $capturedRequest = null;
        $handler = $this->createHandlerCapturingRequest($capturedRequest);

        $request = $this->buildGetRequest('https://example.com/', ['eID' => 'nr_passkeys_fe', 'action' => $action]);

        $this->subject->process($request, $handler);

        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertTrue($capturedRequest->getAttribute('nr_passkeys_fe.public_route'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function privateActionsProvider(): array
    {
        return [
            'enrollOptions' => ['enrollOptions'],
            'enrollVerify' => ['enrollVerify'],
            'revokeCredential' => ['revokeCredential'],
            'listCredentials' => ['listCredentials'],
        ];
    }

    #[Test]
    #[DataProvider('privateActionsProvider')]
    public function privateActionDoesNotSetPublicRouteAttribute(string $action): void
    {
        $capturedRequest = null;
        $handler = $this->createHandlerCapturingRequest($capturedRequest);

        $request = $this->buildGetRequest('https://example.com/', ['eID' => 'nr_passkeys_fe', 'action' => $action]);

        $this->subject->process($request, $handler);

        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertNull($capturedRequest->getAttribute('nr_passkeys_fe.public_route'));
    }

    #[Test]
    public function differentEidDoesNotSetPublicRouteAttribute(): void
    {
        $capturedRequest = null;
        $handler = $this->createHandlerCapturingRequest($capturedRequest);

        $request = $this->buildGetRequest('https://example.com/', ['eID' => 'other_extension', 'action' => 'loginOptions']);

        $this->subject->process($request, $handler);

        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertNull($capturedRequest->getAttribute('nr_passkeys_fe.public_route'));
    }

    #[Test]
    public function noEidDoesNotSetPublicRouteAttribute(): void
    {
        $capturedRequest = null;
        $handler = $this->createHandlerCapturingRequest($capturedRequest);

        $request = $this->buildGetRequest('https://example.com/some-page', []);

        $this->subject->process($request, $handler);

        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertNull($capturedRequest->getAttribute('nr_passkeys_fe.public_route'));
    }

    #[Test]
    public function requestAlwaysPassesToNextHandler(): void
    {
        $handlerCalled = false;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(function () use (&$handlerCalled, $response) {
                $handlerCalled = true;
                return $response;
            });

        $request = $this->buildGetRequest('https://example.com/', ['eID' => 'nr_passkeys_fe', 'action' => 'loginOptions']);

        $result = $this->subject->process($request, $handler);

        self::assertTrue($handlerCalled);
        self::assertSame($response, $result);
    }

    #[Test]
    public function noActionParameterDoesNotSetPublicRouteAttribute(): void
    {
        $capturedRequest = null;
        $handler = $this->createHandlerCapturingRequest($capturedRequest);

        $request = $this->buildGetRequest('https://example.com/', ['eID' => 'nr_passkeys_fe']);

        $this->subject->process($request, $handler);

        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertNull($capturedRequest->getAttribute('nr_passkeys_fe.public_route'));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Build a GET request with explicit query params (not relying on URL parsing).
     *
     * @param array<string, string> $queryParams
     */
    private function buildGetRequest(string $uri, array $queryParams): ServerRequest
    {
        $request = new ServerRequest($uri, 'GET');
        return $request->withQueryParams($queryParams);
    }

    /**
     * Creates a handler stub that captures the request it receives.
     */
    private function createHandlerCapturingRequest(?ServerRequestInterface &$capturedRequest): RequestHandlerInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedRequest, $response): ResponseInterface {
                $capturedRequest = $req;
                return $response;
            }
        );
        return $handler;
    }
}
