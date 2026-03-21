<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller;

use DateTimeImmutable;
use Netresearch\NrPasskeysFe\Controller\EnrollmentController;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

#[CoversClass(EnrollmentController::class)]
final class EnrollmentControllerTest extends TestCase
{
    private FrontendEnforcementService&Stub $enforcementService;
    private SiteConfigurationService&Stub $siteConfigService;
    private SiteInterface&Stub $site;
    private EnrollmentController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforcementService = $this->createStub(FrontendEnforcementService::class);
        $this->siteConfigService = $this->createStub(SiteConfigurationService::class);
        $this->site = $this->createStub(SiteInterface::class);

        $this->siteConfigService->method('getCurrentSite')->willReturn($this->site);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $this->subject = new EnrollmentController(
            $this->enforcementService,
            $this->siteConfigService,
            new NullLogger(),
        );
    }

    // ---------------------------------------------------------------
    // statusAction
    // ---------------------------------------------------------------

    #[Test]
    public function statusActionReturnsEnforcementStatus(): void
    {
        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'required',
            siteLevel: 'required',
            groupLevel: 'off',
            passkeyCount: 0,
            inGracePeriod: true,
            graceDeadline: new DateTimeImmutable('2026-06-01 00:00:00'),
            recoveryCodesRemaining: 5,
        );

        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->buildRequestWithUser(42);
        $response = $this->subject->statusAction($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertSame('required', $body['effectiveLevel']);
        self::assertSame('required', $body['siteLevel']);
        self::assertSame('off', $body['groupLevel']);
        self::assertSame(0, $body['passkeyCount']);
        self::assertTrue($body['inGracePeriod']);
        self::assertNotNull($body['graceDeadline']);
        self::assertSame(5, $body['recoveryCodesRemaining']);
    }

    #[Test]
    public function statusActionReturnsNullGraceDeadlineWhenNotInGracePeriod(): void
    {
        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'off',
            siteLevel: 'off',
            groupLevel: 'off',
            passkeyCount: 2,
            inGracePeriod: false,
            graceDeadline: null,
            recoveryCodesRemaining: 0,
        );

        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->buildRequestWithUser(42);
        $response = $this->subject->statusAction($request);

        $body = $this->decodeBody($response);
        self::assertNull($body['graceDeadline']);
        self::assertFalse($body['inGracePeriod']);
    }

    #[Test]
    public function statusActionReturns500WhenSiteContextMissing(): void
    {
        $this->siteConfigService->method('getCurrentSite')
            ->willThrowException(new RuntimeException('No site'));

        $request = $this->buildRequestWithUser(42);
        $response = $this->subject->statusAction($request);

        self::assertSame(500, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // skipAction
    // ---------------------------------------------------------------

    #[Test]
    public function skipActionReturns400WhenNonceMissing(): void
    {
        $request = $this->buildRequestWithUser(42, []);
        $response = $this->subject->skipAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function skipActionReturns403WhenNonceDoesNotMatchSession(): void
    {
        // No TSFE globals configured → getSessionNonce returns '' → mismatch
        $request = $this->buildRequestWithUser(42, ['nonce' => 'wrong-nonce-value']);
        $response = $this->subject->skipAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function skipActionReturns200WhenNonceMatchesSession(): void
    {
        $nonce = 'valid-enrollment-nonce-abc123';

        // Mock FrontendUserAuthentication with session nonce
        $feUser = $this->createMock(FrontendUserAuthentication::class);
        $feUser->user = ['uid' => 42];
        $feUser->method('getKey')
            ->with('ses', 'nr_passkeys_fe_enrollment_nonce')
            ->willReturn($nonce);
        $feUser->expects($this->exactly(2))
            ->method('setKey')
            ->willReturnCallback(function (string $type, string $key, mixed $value): void {
                static $callCount = 0;
                $callCount++;
                self::assertSame('ses', $type);
                if ($callCount === 1) {
                    self::assertSame('nr_passkeys_fe_skip_enrollment', $key);
                    self::assertTrue($value);
                } else {
                    self::assertSame('nr_passkeys_fe_enrollment_nonce', $key);
                    self::assertSame('', $value);
                }
            });
        $feUser->expects($this->exactly(2))
            ->method('storeSessionData');

        $request = (new ServerRequest('https://example.com/', 'POST'))
            ->withAttribute('frontend.user', $feUser)
            ->withParsedBody(['nonce' => $nonce]);

        $response = $this->subject->skipAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $this->decodeBody($response)['status']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function buildRequestWithUser(int $uid, array $body = []): ServerRequest
    {
        $feUser = $this->createStub(FrontendUserAuthentication::class);
        $feUser->user = ['uid' => $uid];

        return (new ServerRequest('https://example.com/', 'POST'))
            ->withAttribute('frontend.user', $feUser)
            ->withParsedBody($body);
    }

    private function decodeBody(\Psr\Http\Message\ResponseInterface $response): array
    {
        return \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
