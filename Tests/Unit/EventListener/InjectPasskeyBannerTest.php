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
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
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

    /**
     * Whether the event class exposes getContent()/setContent() (TYPO3 v14+).
     */
    private bool $eventHasContentAccessors;

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

        $this->eventHasContentAccessors = \method_exists(
            AfterCacheableContentIsGeneratedEvent::class,
            'getContent',
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

        $event = $this->buildEvent(
            new ServerRequest('https://example.com/', 'GET'),
            '<html><body></body></html>',
        );

        $subject->__invoke($event);

        // Banner disabled → content unchanged
        self::assertSame('<html><body></body></html>', $this->getEventContent($event));
    }

    #[Test]
    public function skipsWhenNoAuthenticatedUser(): void
    {
        $request = new ServerRequest('https://example.com/', 'GET');
        // No frontend.user attribute

        $event = $this->buildEvent($request, '<html><body></body></html>');

        $this->subject->__invoke($event);

        self::assertSame('<html><body></body></html>', $this->getEventContent($event));
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

        $event = $this->buildEvent($request, '<html><body></body></html>');

        $this->subject->__invoke($event);

        // Enforcement off → content unchanged
        self::assertSame('<html><body></body></html>', $this->getEventContent($event));
    }

    #[Test]
    public function injectsBannerWhenEnforcementIsEncourage(): void
    {
        // Set up localization stubs needed by LocalizationUtility::translate().
        GeneralUtility::purgeInstances();

        // Build a functional in-memory cache for v13's CacheManager->getCache('runtime').
        $cacheStore = [];
        $runtimeCache = $this->createStub(FrontendInterface::class);
        $runtimeCache->method('get')->willReturnCallback(
            static function (string $key) use (&$cacheStore): mixed {
                return $cacheStore[$key] ?? false;
            },
        );
        $runtimeCache->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$cacheStore): void {
                $cacheStore[$key] = $value;
            },
        );

        $cacheManager = $this->createStub(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($runtimeCache);
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);

        $locales = new Locales();
        $localizationFactory = $this->createStub(LocalizationFactory::class);
        $localizationFactory->method('getParsedData')->willReturn(['default' => []]);
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

        $event = $this->buildEvent($request, '<html><body></body></html>');

        $this->subject->__invoke($event);

        $content = $this->getEventContent($event);
        self::assertStringContainsString('nr-passkeys-banner', $content);
        self::assertStringContainsString('data-enforcement="encourage"', $content);
        self::assertStringContainsString('dismiss-banner', $content);
        self::assertFalse($event->isCachingEnabled());
    }

    #[Test]
    public function skipsWhenUserAlreadyHasPasskeys(): void
    {
        $feUser = $this->createStub(FrontendUserAuthentication::class);
        $feUser->user = ['uid' => 42];

        $request = new ServerRequest('https://example.com/', 'GET');
        $request = $request->withAttribute('frontend.user', $feUser);

        $this->credentialRepository->method('countByFeUser')->willReturn(2);

        $event = $this->buildEvent($request, '<html><body></body></html>');

        $this->subject->__invoke($event);

        // User already has passkeys → content unchanged
        self::assertSame('<html><body></body></html>', $this->getEventContent($event));
    }

    /**
     * Build a real AfterCacheableContentIsGeneratedEvent, compatible with both v13 and v14.
     *
     * In v13 the constructor takes (Request, TypoScriptFrontendController, cacheId, bool)
     * and content lives on the TSFE controller's public $content property.
     * In v14 the constructor takes (Request, content, cacheId, bool) and the TSFE is gone.
     */
    private function buildEvent(
        ServerRequestInterface $request,
        string $content,
    ): AfterCacheableContentIsGeneratedEvent {
        if ($this->eventHasContentAccessors) {
            // v14+: constructor accepts content string directly
            return new AfterCacheableContentIsGeneratedEvent($request, $content, 'test-cache-id', true);
        }

        // v13: constructor requires TypoScriptFrontendController as 2nd argument.
        // Use a stub to avoid TSFE constructor side-effects ($EXEC_TIME, PageRepository).
        // The class only exists in v13, so we reference it by FQCN string.
        $tsfeClass = 'TYPO3\\CMS\\Frontend\\Controller\\TypoScriptFrontendController';
        $controller = $this->createStub($tsfeClass);
        $controller->content = $content;

        return new AfterCacheableContentIsGeneratedEvent($request, $controller, 'test-cache-id', true);
    }

    /**
     * Read content from the event in a version-agnostic way.
     */
    private function getEventContent(AfterCacheableContentIsGeneratedEvent $event): string
    {
        if ($this->eventHasContentAccessors) {
            return $event->getContent();
        }

        return $event->getController()->content;
    }
}
