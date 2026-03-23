<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller;

use Netresearch\NrPasskeysFe\Controller\JsonBodyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Concrete class using the trait for testing purposes.
 */
final class JsonBodyTraitTestSubject
{
    use JsonBodyTrait;

    /**
     * Expose the private trait method for testing.
     *
     * @return array<string, mixed>
     */
    public function callGetJsonBody(ServerRequestInterface $request): array
    {
        return $this->getJsonBody($request);
    }
}

#[CoversClass(JsonBodyTrait::class)]
final class JsonBodyTraitTest extends TestCase
{
    private JsonBodyTraitTestSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new JsonBodyTraitTestSubject();
    }

    #[Test]
    public function returnsParsedBodyWhenItIsArray(): void
    {
        $request = new ServerRequest('https://example.com/', 'POST');
        $request = $request->withParsedBody(['foo' => 'bar']);

        $result = $this->subject->callGetJsonBody($request);
        self::assertSame(['foo' => 'bar'], $result);
    }

    #[Test]
    public function decodesJsonFromBodyContent(): void
    {
        $body = new \GuzzleHttp\Psr7\Stream(\fopen('php://temp', 'r+'));
        $body->write('{"key":"value"}');
        $body->rewind();

        $request = new ServerRequest('https://example.com/', 'POST');
        $request = $request->withHeader('Content-Type', 'application/json')
            ->withBody($body);

        $result = $this->subject->callGetJsonBody($request);
        self::assertSame(['key' => 'value'], $result);
    }

    #[Test]
    public function returnsEmptyArrayForInvalidJson(): void
    {
        $body = new \GuzzleHttp\Psr7\Stream(\fopen('php://temp', 'r+'));
        $body->write('{invalid json');
        $body->rewind();

        $request = new ServerRequest('https://example.com/', 'POST');
        $request = $request->withHeader('Content-Type', 'application/json')
            ->withBody($body);

        $result = $this->subject->callGetJsonBody($request);
        self::assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyArrayForEmptyBody(): void
    {
        $request = new ServerRequest('https://example.com/', 'POST');
        $request = $request->withHeader('Content-Type', 'application/json');

        $result = $this->subject->callGetJsonBody($request);
        self::assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyArrayForNonJsonContentType(): void
    {
        $body = new \GuzzleHttp\Psr7\Stream(\fopen('php://temp', 'r+'));
        $body->write('{"key":"value"}');
        $body->rewind();

        $request = new ServerRequest('https://example.com/', 'POST');
        $request = $request->withHeader('Content-Type', 'text/html')
            ->withBody($body);

        $result = $this->subject->callGetJsonBody($request);
        self::assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyArrayWhenJsonDecodesToNonArray(): void
    {
        $body = new \GuzzleHttp\Psr7\Stream(\fopen('php://temp', 'r+'));
        $body->write('"just a string"');
        $body->rewind();

        $request = new ServerRequest('https://example.com/', 'POST');
        $request = $request->withHeader('Content-Type', 'application/json')
            ->withBody($body);

        $result = $this->subject->callGetJsonBody($request);
        self::assertSame([], $result);
    }
}
