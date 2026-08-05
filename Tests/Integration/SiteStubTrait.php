<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Integration;

use PHPUnit\Framework\MockObject\Stub;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

/**
 * A site double carrying nr_passkeys_fe settings.
 *
 * Stubs the concrete Site rather than SiteInterface: getSettings() is
 * declared on Site only, so a SiteInterface double cannot configure it
 * ("method ... cannot be configured because it does not exist"). Site is
 * also what the framework hands the services at runtime, so the double
 * matches production. Tests/Unit/Service/SiteConfigurationServiceTest
 * stubs the same class for the same reason.
 */
trait SiteStubTrait
{
    /**
     * @param array<string, mixed> $settingsTree
     */
    private function makeSiteWithSettings(
        array $settingsTree = [],
        string $identifier = 'site-a',
        string $baseUrl = 'https://example.com',
    ): Site&Stub {
        $site = $this->createStub(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getSettings')->willReturn(SiteSettings::createFromSettingsTree($settingsTree));
        $site->method('getBase')->willReturn(new Uri($baseUrl));

        return $site;
    }
}
