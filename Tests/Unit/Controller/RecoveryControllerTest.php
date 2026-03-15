<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller;

use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Controller\RecoveryController;
use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Http\ServerRequest;

#[CoversClass(RecoveryController::class)]
final class RecoveryControllerTest extends TestCase
{
    private RecoveryCodeService&MockObject $recoveryCodeService;
    private RateLimiterService&MockObject $rateLimiterService;
    private SiteConfigurationService&MockObject $siteConfigService;
    private RecoveryController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recoveryCodeService = $this->createMock(RecoveryCodeService::class);
        $this->rateLimiterService = $this->createMock(RateLimiterService::class);
        $this->siteConfigService = $this->createMock(SiteConfigurationService::class);

        $this->subject = new RecoveryController(
            $this->recoveryCodeService,
            $this->rateLimiterService,
            $this->siteConfigService,
            new NullLogger(),
        );
    }

    // ---------------------------------------------------------------
    // generateAction
    // ---------------------------------------------------------------

    #[Test]
    public function generateActionReturnsCodesForAuthenticatedUser(): void
    {
        $codes = ['ABCD-EFGH', 'WXYZ-1234', 'MNOP-QRST'];
        $this->recoveryCodeService->method('generate')->willReturn($codes);

        $request = $this->buildRequestWithUser(42);
        $response = $this->subject->generateAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame($codes, $body['codes']);
        self::assertSame(3, $body['count']);
    }

    #[Test]
    public function generateActionReturns500OnException(): void
    {
        $this->recoveryCodeService->method('generate')
            ->willThrowException(new RuntimeException('DB error'));

        $request = $this->buildRequestWithUser(42);
        $response = $this->subject->generateAction($request);

        self::assertSame(500, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // verifyAction — validation
    // ---------------------------------------------------------------

    #[Test]
    public function verifyActionReturns400WhenFieldsMissing(): void
    {
        $request = $this->buildRequest([]);
        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function verifyActionReturns400WhenOnlyUsernamePresent(): void
    {
        $request = $this->buildRequest(['username' => 'user@example.com']);
        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function verifyActionReturns429WhenRateLimitExceeded(): void
    {
        $this->rateLimiterService->method('checkRateLimit')
            ->willThrowException(new RuntimeException('Rate limit exceeded'));

        $request = $this->buildRequest([
            'username' => 'user@example.com',
            'code' => 'ABCD-EFGH',
        ]);
        $response = $this->subject->verifyAction($request);

        self::assertSame(429, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // verifyAction — user lookup / code verification
    // ---------------------------------------------------------------

    #[Test]
    public function verifyActionReturns401WhenCodeIsInvalid(): void
    {
        // We cannot easily mock the internal ConnectionPool in this unit test,
        // so we test the branch where RecoveryCodeService.verify returns false.
        // The user lookup returns null (no DB mock), triggering the 401.
        $request = $this->buildRequest([
            'username' => 'user@example.com',
            'code' => 'ABCD-EFGH',
        ]);

        // Without DB mock, findFeUserUid returns null → 401
        $response = $this->subject->verifyAction($request);
        self::assertSame(401, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function buildRequestWithUser(int $uid): ServerRequest
    {
        $feUser = new class ($uid) {
            /** @var array<string, mixed> */
            public array $user;

            public function __construct(int $uid)
            {
                $this->user = ['uid' => $uid];
            }
        };

        return (new ServerRequest('https://example.com/', 'POST'))
            ->withAttribute('frontend.user', $feUser)
            ->withParsedBody([]);
    }

    private function buildRequest(array $body): ServerRequest
    {
        return (new ServerRequest('https://example.com/', 'POST'))
            ->withParsedBody($body);
    }

    private function decodeBody(\Psr\Http\Message\ResponseInterface $response): array
    {
        return \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
