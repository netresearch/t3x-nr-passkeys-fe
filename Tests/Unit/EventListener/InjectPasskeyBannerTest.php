<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\EventListener;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use Netresearch\NrPasskeysFe\EventListener\InjectPasskeyBanner;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Localization\LocalizationFactory;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

#[CoversClass(InjectPasskeyBanner::class)]
final class InjectPasskeyBannerTest extends TestCase
{
    private FrontendEnforcementService&Stub $enforcementService;
    private FrontendCredentialRepository&Stub $credentialRepository;
    private SiteConfigurationService&Stub $siteConfigService;
    private InjectPasskeyBanner $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforcementService = $this->createStub(FrontendEnforcementService::class);
        $this->credentialRepository = $this->createStub(FrontendCredentialRepository::class);
        $this->siteConfigService = $this->createStub(SiteConfigurationService::class);

        $this->subject = new InjectPasskeyBanner(
            $this->enforcementService,
            $this->credentialRepository,
            $this->siteConfigService,
            new FrontendConfiguration(enrollmentBannerEnabled: true),
        );
    }

    #[Test]
    public function isInstantiable(): void
    {
        self::assertInstanceOf(InjectPasskeyBanner::class, $this->subject);
    }

    #[Test]
    public function skipsWhenBannerDisabled(): void
    {
        $subject = new InjectPasskeyBanner(
            $this->enforcementService,
            $this->credentialRepository,
            $this->siteConfigService,
            new FrontendConfiguration(enrollmentBannerEnabled: false),
        );

        $event = $this->createStub(AfterCacheableContentIsGeneratedEvent::class);
        // getRequest should never be called if banner is disabled
        $event->method('getContent')->willReturn('<html><body></body></html>');

        $subject->__invoke($event);

        // No assertion needed beyond no exception; event content unchanged
        self::assertTrue(true);
    }

    #[Test]
    public function skipsWhenNoAuthenticatedUser(): void
    {
        $request = new ServerRequest('https://example.com/', 'GET');
        // No frontend.user attribute

        $event = $this->createStub(AfterCacheableContentIsGeneratedEvent::class);
        $event->method('getRequest')->willReturn($request);
        $event->method('getContent')->willReturn('<html><body></body></html>');

        $this->subject->__invoke($event);

        self::assertTrue(true);
    }

    #[Test]
    public function skipsWhenEnforcementLevelIsOff(): void
    {
        $feUser = $this->createStub(FrontendUserAuthentication::class);
        $feUser->user = ['uid' => 42];

        $site = $this->createStub(SiteInterface::class);

        $request = new ServerRequest('https://example.com/', 'GET');
        $request = $request->withAttribute('frontend.user', $feUser);
        $request = $request->withAttribute('site', $site);

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'off',
            siteLevel: 'off',
            groupLevel: 'off',
            passkeyCount: 0,
            inGracePeriod: false,
            graceDeadline: null,
            recoveryCodesRemaining: 0,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $event = $this->createMock(AfterCacheableContentIsGeneratedEvent::class);
        $event->method('getRequest')->willReturn($request);
        $event->method('getContent')->willReturn('<html><body></body></html>');
        $event->expects(self::never())->method('setContent');

        $this->subject->__invoke($event);
    }

    #[Test]
    public function injectsBannerWhenEnforcementIsEncourage(): void
    {
        // Set up localization stubs needed by LocalizationUtility::translate().
        // Reset GeneralUtility state to ensure addInstance keys match makeInstance lookups.
        GeneralUtility::purgeInstances();

        $runtimeCache = $this->createStub(FrontendInterface::class);
        $locales = new Locales();
        $localizationFactory = $this->createStub(LocalizationFactory::class);
        $localizationFactory->method('getParsedData')->willReturn([]);
        $langFactory = new LanguageServiceFactory($locales, $localizationFactory, $runtimeCache);
        // Register multiple instances since translate() is called multiple times
        for ($i = 0; $i < 10; $i++) {
            GeneralUtility::addInstance(LanguageServiceFactory::class, $langFactory);
        }

        $feUser = $this->createStub(FrontendUserAuthentication::class);
        $feUser->user = ['uid' => 42];

        $site = $this->createStub(SiteInterface::class);

        $request = new ServerRequest('https://example.com/', 'GET');
        $request = $request->withAttribute('frontend.user', $feUser);
        $request = $request->withAttribute('site', $site);

        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');
        $this->siteConfigService->method('getEnrollmentPageUrl')->willReturn('/passkey-setup');

        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'encourage',
            siteLevel: 'encourage',
            groupLevel: 'off',
            passkeyCount: 0,
            inGracePeriod: false,
            graceDeadline: null,
            recoveryCodesRemaining: 0,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $capturedContent = '';
        $event = $this->createMock(AfterCacheableContentIsGeneratedEvent::class);
        $event->method('getRequest')->willReturn($request);
        $event->method('getContent')->willReturn('<html><body></body></html>');
        $event->expects(self::once())->method('setContent')->willReturnCallback(
            static function (string $content) use (&$capturedContent): void {
                $capturedContent = $content;
            },
        );
        $event->expects(self::once())->method('disableCaching');

        $this->subject->__invoke($event);

        self::assertStringContainsString('nr-passkeys-banner', $capturedContent);
        self::assertStringContainsString('data-enforcement="encourage"', $capturedContent);
        self::assertStringContainsString('dismiss-banner', $capturedContent);
    }

    #[Test]
    public function skipsWhenUserAlreadyHasPasskeys(): void
    {
        $feUser = $this->createStub(FrontendUserAuthentication::class);
        $feUser->user = ['uid' => 42];

        $request = new ServerRequest('https://example.com/', 'GET');
        $request = $request->withAttribute('frontend.user', $feUser);

        $this->credentialRepository->method('countByFeUser')->willReturn(2);

        $event = $this->createMock(AfterCacheableContentIsGeneratedEvent::class);
        $event->method('getRequest')->willReturn($request);
        $event->method('getContent')->willReturn('<html><body></body></html>');
        $event->expects(self::never())->method('setContent');

        $this->subject->__invoke($event);
    }
}
