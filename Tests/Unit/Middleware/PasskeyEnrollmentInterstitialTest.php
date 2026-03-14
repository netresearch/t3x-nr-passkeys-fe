<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Middleware;

use DateTimeImmutable;
use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use Netresearch\NrPasskeysFe\Middleware\PasskeyEnrollmentInterstitial;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings; // Used for createFromSettingsTree()
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

#[CoversClass(PasskeyEnrollmentInterstitial::class)]
final class PasskeyEnrollmentInterstitialTest extends TestCase
{
    private FrontendEnforcementService&MockObject $enforcementService;
    private FrontendCredentialRepository&MockObject $credentialRepository;
    private SiteConfigurationService&MockObject $siteConfigurationService;
    private FrontendConfiguration $frontendConfiguration;
    private PasskeyEnrollmentInterstitial $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforcementService = $this->createMock(FrontendEnforcementService::class);
        $this->credentialRepository = $this->createMock(FrontendCredentialRepository::class);
        $this->siteConfigurationService = $this->createMock(SiteConfigurationService::class);
        $this->frontendConfiguration = new FrontendConfiguration(
            postLoginEnrollmentEnabled: true,
        );
        $this->subject = new PasskeyEnrollmentInterstitial(
            $this->enforcementService,
            $this->credentialRepository,
            $this->siteConfigurationService,
            $this->frontendConfiguration,
        );
    }

    // ---------------------------------------------------------------
    // Pass-through cases
    // ---------------------------------------------------------------

    #[Test]
    public function passesThroughWhenNoFrontendUser(): void
    {
        $request = new ServerRequest('https://example.com/page', 'GET');
        $handler = $this->createPassThroughHandler();

        $this->enforcementService->expects(self::never())->method('getStatus');

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenUserHasPasskeys(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $request = $this->buildRequest($feUser);
        $handler = $this->createPassThroughHandler();

        $this->credentialRepository->method('countByFeUser')->with(42)->willReturn(2);
        $this->enforcementService->expects(self::never())->method('getStatus');

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenPostLoginEnrollmentDisabled(): void
    {
        $this->subject = new PasskeyEnrollmentInterstitial(
            $this->enforcementService,
            $this->credentialRepository,
            $this->siteConfigurationService,
            new FrontendConfiguration(postLoginEnrollmentEnabled: false),
        );

        $feUser = $this->createAuthenticatedFeUser(42);
        $request = $this->buildRequest($feUser);
        $handler = $this->createPassThroughHandler();

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->enforcementService->expects(self::never())->method('getStatus');

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenEidRequestForOurExtension(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $request = $this->buildRequest($feUser, ['eID' => 'nr_passkeys_fe', 'action' => 'enrollOptions']);
        $handler = $this->createPassThroughHandler();

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->enforcementService->expects(self::never())->method('getStatus');

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenPublicRouteAttributeIsSet(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $request = $this->buildRequest($feUser, ['eID' => 'nr_passkeys_fe', 'action' => 'loginVerify']);
        $request = $request->withAttribute('nr_passkeys_fe.public_route', true);
        $handler = $this->createPassThroughHandler();

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->enforcementService->expects(self::never())->method('getStatus');

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenEnforcementLevelIsOff(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com', '');
        $request = $this->buildRequest($feUser, [], $site);
        $handler = $this->createPassThroughHandler();

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('off')
        );

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenEnforcementLevelIsEncourage(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com', '');
        $request = $this->buildRequest($feUser, [], $site);
        $handler = $this->createPassThroughHandler();

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('encourage')
        );

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenNoEnrollmentUrlConfigured(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com', '');
        $request = $this->buildRequest($feUser, [], $site);
        $handler = $this->createPassThroughHandler();

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('required')
        );

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenRequiredAndInGracePeriodWithSessionSkip(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42, ['enrollment_skipped' => true]);
        $site = $this->createSite('main', 'https://example.com', 'https://example.com/enroll');
        $request = $this->buildRequest($feUser, [], $site);
        $handler = $this->createPassThroughHandler();

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('required', inGracePeriod: true, graceDeadline: new DateTimeImmutable('+7 days'))
        );

        $this->subject->process($request, $handler);
    }

    // ---------------------------------------------------------------
    // Redirect cases
    // ---------------------------------------------------------------

    #[Test]
    public function redirectsWhenRequiredAndInGracePeriodNoSessionSkip(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com', 'https://example.com/enroll');
        $request = $this->buildRequest($feUser, [], $site);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('required', inGracePeriod: true, graceDeadline: new DateTimeImmutable('+7 days'))
        );

        $response = $this->subject->process($request, $handler);

        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('/enroll', $location);
        self::assertStringContainsString('canSkip=1', $location);
    }

    #[Test]
    public function redirectsWhenRequiredAndGraceExpired(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com', 'https://example.com/enroll');
        $request = $this->buildRequest($feUser, [], $site);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('required', inGracePeriod: false, graceDeadline: null)
        );

        $response = $this->subject->process($request, $handler);

        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('/enroll', $location);
        self::assertStringNotContainsString('canSkip', $location);
    }

    #[Test]
    public function redirectsWhenEnforcedNoSkip(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com', 'https://example.com/enroll');
        $request = $this->buildRequest($feUser, [], $site);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('enforced')
        );

        $response = $this->subject->process($request, $handler);

        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('/enroll', $location);
        self::assertStringNotContainsString('canSkip', $location);
    }

    #[Test]
    public function passesThroughWhenAlreadyOnEnrollmentPage(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com', 'https://example.com/enroll');
        $request = $this->buildRequest($feUser, [], $site, '/enroll');
        $handler = $this->createPassThroughHandler();

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('enforced')
        );

        $this->subject->process($request, $handler);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createPassThroughHandler(): RequestHandlerInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($response);
        return $handler;
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    private function createAuthenticatedFeUser(int $uid, array $sessionData = []): FrontendUserAuthentication&MockObject
    {
        $feUser = $this->createMock(FrontendUserAuthentication::class);
        $feUser->user = ['uid' => $uid, 'username' => 'testuser'];
        $feUser->method('getKey')->with('ses', 'tx_nrpasskeysfe')->willReturn($sessionData ?: null);
        return $feUser;
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function buildRequest(
        FrontendUserAuthentication $feUser,
        array $queryParams = [],
        ?Site $site = null,
        string $path = '/page',
    ): ServerRequest {
        $uri = 'https://example.com' . $path;
        if ($queryParams !== []) {
            $uri .= '?' . \http_build_query($queryParams);
        }
        $request = new ServerRequest($uri, 'GET');
        $request = $request->withAttribute('frontend.user', $feUser);
        $request = $request->withQueryParams($queryParams);

        if ($site !== null) {
            $request = $request->withAttribute('site', $site);
        }

        return $request;
    }

    private function createSite(string $identifier, string $base, string $enrollmentUrl): Site
    {
        $siteSettings = SiteSettings::createFromSettingsTree([]);
        $site = new Site($identifier, 1, ['base' => $base], $siteSettings);

        // Teach the mock to return the enrollment URL for this site
        $this->siteConfigurationService
            ->method('getEnrollmentPageUrl')
            ->willReturn($enrollmentUrl);

        return $site;
    }

    private function makeStatus(
        string $effectiveLevel,
        bool $inGracePeriod = false,
        ?DateTimeImmutable $graceDeadline = null,
    ): FrontendEnforcementStatus {
        return new FrontendEnforcementStatus(
            effectiveLevel: $effectiveLevel,
            siteLevel: $effectiveLevel,
            groupLevel: 'off',
            passkeyCount: 0,
            inGracePeriod: $inGracePeriod,
            graceDeadline: $graceDeadline,
            recoveryCodesRemaining: 0,
        );
    }
}
