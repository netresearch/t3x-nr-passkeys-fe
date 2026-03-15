<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\EventListener;

use DateTimeImmutable;
use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use Netresearch\NrPasskeysFe\EventListener\InjectPasskeyBanner;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings; // Used for createFromSettingsTree()
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

#[CoversClass(InjectPasskeyBanner::class)]
final class InjectPasskeyBannerTest extends TestCase
{
    private FrontendEnforcementService&MockObject $enforcementService;
    private FrontendCredentialRepository&MockObject $credentialRepository;
    private SiteConfigurationService&MockObject $siteConfigurationService;
    private FrontendConfiguration $frontendConfiguration;
    private InjectPasskeyBanner $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforcementService = $this->createMock(FrontendEnforcementService::class);
        $this->credentialRepository = $this->createMock(FrontendCredentialRepository::class);
        $this->siteConfigurationService = $this->createMock(SiteConfigurationService::class);
        $this->frontendConfiguration = new FrontendConfiguration(
            enrollmentBannerEnabled: true,
        );
        $this->subject = new InjectPasskeyBanner(
            $this->enforcementService,
            $this->credentialRepository,
            $this->siteConfigurationService,
            $this->frontendConfiguration,
        );
    }

    // ---------------------------------------------------------------
    // Skip cases
    // ---------------------------------------------------------------

    #[Test]
    public function skipsWhenBannerFeatureDisabled(): void
    {
        $this->subject = new InjectPasskeyBanner(
            $this->enforcementService,
            $this->credentialRepository,
            $this->siteConfigurationService,
            new FrontendConfiguration(enrollmentBannerEnabled: false),
        );

        $event = $this->buildEvent('<html><body>content</body></html>');

        $this->credentialRepository->expects(self::never())->method('countByFeUser');

        ($this->subject)($event);

        self::assertSame('<html><body>content</body></html>', $event->getContent());
    }

    #[Test]
    public function skipsWhenNoFrontendUser(): void
    {
        $request = new ServerRequest('https://example.com/', 'GET');
        $event = $this->createMock(\TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent::class);
        $event->method('getRequest')->willReturn($request);
        $event->method('getContent')->willReturn('<html><body>content</body></html>');

        $this->credentialRepository->expects(self::never())->method('countByFeUser');

        ($this->subject)($event);

        self::assertSame('<html><body>content</body></html>', $event->getContent());
    }

    #[Test]
    public function skipsWhenUserHasPasskeys(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $event = $this->buildEvent('<html><body>content</body></html>', $feUser);

        $this->credentialRepository->method('countByFeUser')->with(42)->willReturn(3);
        $this->enforcementService->expects(self::never())->method('getStatus');

        ($this->subject)($event);

        self::assertSame('<html><body>content</body></html>', $event->getContent());
    }

    #[Test]
    public function skipsWhenEnforcementIsOff(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com');
        $event = $this->buildEvent('<html><body>content</body></html>', $feUser, $site);

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('off'),
        );

        ($this->subject)($event);

        self::assertSame('<html><body>content</body></html>', $event->getContent());
    }

    // ---------------------------------------------------------------
    // Banner injection cases
    // ---------------------------------------------------------------

    #[Test]
    public function injectsBannerForEncourageLevel(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com');
        $event = $this->buildEvent('<html><body>page content</body></html>', $feUser, $site);

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('encourage'),
        );

        ($this->subject)($event);

        $content = $event->getContent();
        self::assertStringContainsString('nr-passkeys-banner', $content);
        self::assertStringContainsString('</body>', $content);
        // Banner is before </body>
        self::assertGreaterThan(
            \strpos($content, 'nr-passkeys-banner'),
            \strpos($content, '</body>'),
        );
    }

    #[Test]
    public function bannerIsDismissibleForEncourageLevel(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com');
        $event = $this->buildEvent('<html><body>content</body></html>', $feUser, $site);

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('encourage'),
        );

        ($this->subject)($event);

        self::assertStringContainsString('nr-passkeys-banner__dismiss', $event->getContent());
    }

    #[Test]
    public function bannerIsNotDismissibleForEnforcedLevel(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com');
        $event = $this->buildEvent('<html><body>content</body></html>', $feUser, $site);

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('enforced'),
        );

        ($this->subject)($event);

        self::assertStringNotContainsString('nr-passkeys-banner__dismiss', $event->getContent());
    }

    #[Test]
    public function bannerIsNotDismissibleForRequiredLevel(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com');
        $event = $this->buildEvent('<html><body>content</body></html>', $feUser, $site);

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('required', inGracePeriod: true, graceDeadline: new DateTimeImmutable('+7 days')),
        );

        ($this->subject)($event);

        self::assertStringNotContainsString('nr-passkeys-banner__dismiss', $event->getContent());
    }

    #[Test]
    public function bannerDisablesCaching(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com');
        $event = $this->buildEvent('<html><body>content</body></html>', $feUser, $site);

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('encourage'),
        );

        self::assertTrue($event->isCachingEnabled());

        ($this->subject)($event);

        self::assertFalse($event->isCachingEnabled());
    }

    #[Test]
    public function bannerIncludesEnrollmentLink(): void
    {
        $feUser = $this->createAuthenticatedFeUser(42);
        $site = $this->createSite('main', 'https://example.com', 'https://example.com/enroll');
        $event = $this->buildEvent('<html><body>content</body></html>', $feUser, $site);

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigurationService->method('getSiteIdentifier')->willReturn('main');
        $this->enforcementService->method('getStatus')->willReturn(
            $this->makeStatus('encourage'),
        );

        ($this->subject)($event);

        self::assertStringContainsString('href="https://example.com/enroll"', $event->getContent());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createAuthenticatedFeUser(int $uid): FrontendUserAuthentication&MockObject
    {
        $feUser = $this->createMock(FrontendUserAuthentication::class);
        $feUser->user = ['uid' => $uid, 'username' => 'testuser'];
        return $feUser;
    }

    private function buildEvent(
        string $content,
        ?FrontendUserAuthentication $feUser = null,
        ?Site $site = null,
    ): AfterCacheableContentIsGeneratedEvent {
        $request = new ServerRequest('https://example.com/page', 'GET');

        if ($feUser !== null) {
            $request = $request->withAttribute('frontend.user', $feUser);
        }

        if ($site !== null) {
            $request = $request->withAttribute('site', $site);
        }

        $event = $this->createMock(\TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent::class);
        $event->method('getRequest')->willReturn($request);
        // Track content mutations via setContent/getContent
        $contentRef = $content;
        $cachingDisabled = false;
        $event->method('getContent')->willReturnCallback(function () use (&$contentRef) {
            return $contentRef;
        });
        $event->method('setContent')->willReturnCallback(function (string $c) use (&$contentRef) {
            $contentRef = $c;
        });
        $event->method('disableCaching')->willReturnCallback(function () use (&$cachingDisabled) {
            $cachingDisabled = true;
        });
        $event->method('isCachingEnabled')->willReturnCallback(function () use (&$cachingDisabled) {
            return !$cachingDisabled;
        });
        return $event;
    }

    private function createSite(string $identifier, string $base, string $enrollmentUrl = ''): Site
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
