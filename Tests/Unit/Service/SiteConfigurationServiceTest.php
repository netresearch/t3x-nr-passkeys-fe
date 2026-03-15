<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

#[CoversClass(SiteConfigurationService::class)]
final class SiteConfigurationServiceTest extends TestCase
{
    private SiteConfigurationService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SiteConfigurationService();
    }

    // ---------------------------------------------------------------
    // getRpId()
    // ---------------------------------------------------------------

    #[Test]
    public function getRpIdReturnsValueFromSiteSettings(): void
    {
        $site = $this->createSiteWithSettings(
            ['nr_passkeys_fe' => ['rpId' => 'example.com']],
            'https://example.com/',
        );

        self::assertSame('example.com', $this->subject->getRpId($site));
    }

    #[Test]
    public function getRpIdFallsBackToBaseUrlHost(): void
    {
        $site = $this->createSiteWithSettings(
            [],
            'https://shop.example.org:8443/sub/',
        );

        self::assertSame('shop.example.org', $this->subject->getRpId($site));
    }

    #[Test]
    public function getRpIdFallsBackWhenSettingIsEmpty(): void
    {
        $site = $this->createSiteWithSettings(
            ['nr_passkeys_fe' => ['rpId' => '']],
            'https://fallback.test/',
        );

        self::assertSame('fallback.test', $this->subject->getRpId($site));
    }

    // ---------------------------------------------------------------
    // getOrigin()
    // ---------------------------------------------------------------

    #[Test]
    public function getOriginReturnsValueFromSiteSettings(): void
    {
        $site = $this->createSiteWithSettings(
            ['nr_passkeys_fe' => ['origin' => 'https://custom.example.com:9443']],
            'https://example.com/',
        );

        self::assertSame('https://custom.example.com:9443', $this->subject->getOrigin($site));
    }

    #[Test]
    public function getOriginBuildsFromBaseUrlWithoutStandardPort(): void
    {
        $site = $this->createSiteWithSettings(
            [],
            'https://example.com/',
        );

        self::assertSame('https://example.com', $this->subject->getOrigin($site));
    }

    #[Test]
    public function getOriginIncludesNonStandardHttpsPort(): void
    {
        $site = $this->createSiteWithSettings(
            [],
            'https://example.com:8443/path/',
        );

        self::assertSame('https://example.com:8443', $this->subject->getOrigin($site));
    }

    #[Test]
    public function getOriginExcludesStandardHttpsPort443(): void
    {
        $site = $this->createSiteWithSettings(
            [],
            'https://example.com:443/',
        );

        self::assertSame('https://example.com', $this->subject->getOrigin($site));
    }

    #[Test]
    public function getOriginExcludesStandardHttpPort80(): void
    {
        $site = $this->createSiteWithSettings(
            [],
            'http://example.com:80/',
        );

        self::assertSame('http://example.com', $this->subject->getOrigin($site));
    }

    #[Test]
    public function getOriginIncludesNonStandardHttpPort(): void
    {
        $site = $this->createSiteWithSettings(
            [],
            'http://example.com:3000/',
        );

        self::assertSame('http://example.com:3000', $this->subject->getOrigin($site));
    }

    // ---------------------------------------------------------------
    // getEnforcementLevel()
    // ---------------------------------------------------------------

    #[Test]
    public function getEnforcementLevelReturnsConfiguredValue(): void
    {
        $site = $this->createSiteWithSettings(
            ['nr_passkeys_fe' => ['enforcementLevel' => 'required']],
            'https://example.com/',
        );

        self::assertSame('required', $this->subject->getEnforcementLevel($site));
    }

    #[Test]
    public function getEnforcementLevelDefaultsToOff(): void
    {
        $site = $this->createSiteWithSettings(
            [],
            'https://example.com/',
        );

        self::assertSame('off', $this->subject->getEnforcementLevel($site));
    }

    // ---------------------------------------------------------------
    // getSiteIdentifier()
    // ---------------------------------------------------------------

    #[Test]
    public function getSiteIdentifierReturnsSiteIdentifier(): void
    {
        $site = $this->createSiteWithSettings(
            [],
            'https://example.com/',
            'my-site',
        );

        self::assertSame('my-site', $this->subject->getSiteIdentifier($site));
    }

    // ---------------------------------------------------------------
    // getCurrentSite()
    // ---------------------------------------------------------------

    #[Test]
    public function getCurrentSiteReturnsSiteFromRequestAttribute(): void
    {
        $site = $this->createMock(SiteInterface::class);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('site')
            ->willReturn($site);

        self::assertSame($site, $this->subject->getCurrentSite($request));
    }

    #[Test]
    public function getCurrentSiteThrowsWhenNoSiteInRequest(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('site')
            ->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700100001);

        $this->subject->getCurrentSite($request);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $settingsArray
     */
    private function createSiteWithSettings(
        array $settingsArray,
        string $baseUrl,
        string $identifier = 'test-site',
    ): SiteInterface {
        $site = $this->createMock(SiteInterface::class);

        $settings = $this->createSettingsMock($settingsArray);
        $site->method('getSettings')->willReturn($settings);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn($baseUrl);
        $site->method('getBase')->willReturn($uri);

        $site->method('getIdentifier')->willReturn($identifier);

        return $site;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createSettingsMock(array $data): SiteSettings
    {
        $settings = $this->createMock(SiteSettings::class);
        $settings->method('get')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($data): mixed {
                // Support dot-notation: nr_passkeys_fe.rpId
                $parts = \explode('.', $key);
                $value = $data;
                foreach ($parts as $part) {
                    if (!\is_array($value) || !\array_key_exists($part, $value)) {
                        return $default;
                    }
                    $value = $value[$part];
                }

                return $value;
            },
        );

        return $settings;
    }
}
