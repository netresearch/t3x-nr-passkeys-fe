<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Adoption;

use Netresearch\NrPasskeysBe\Widgets\DataProvider\PasskeyAdoptionChartDataProvider;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\PasskeyCredentialsCountDataProvider;
use Netresearch\NrPasskeysFe\Adoption\FrontendPasskeyAdoptionStatsProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * DI smoke test: with both extensions loaded, the frontend provider is part
 * of the nr_passkeys_be.adoption_stats_provider tagged collection that the
 * unified dashboard widgets consume.
 *
 * The backend fixtures are intentionally empty, so the backend segment
 * contributes zero — any non-zero credential total or a second chart dataset
 * can only come from the frontend provider being present in the collection.
 */
#[CoversNothing]
final class FrontendPasskeyAdoptionStatsProviderCollectionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'frontend',
        'dashboard',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-passkeys-be',
        'netresearch/nr-passkeys-fe',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'nr_passkeys_fe_nonce' => [
                        'backend' => \TYPO3\CMS\Core\Cache\Backend\NullBackend::class,
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Service/Fixtures/tx_nrpasskeysfe_credential.csv');
    }

    #[Test]
    public function frontendProviderIsResolvableAndTagged(): void
    {
        $provider = $this->get(FrontendPasskeyAdoptionStatsProvider::class);

        self::assertInstanceOf(FrontendPasskeyAdoptionStatsProvider::class, $provider);
        self::assertSame('frontend', $provider->getAudienceStats()->audienceKey);
    }

    #[Test]
    public function credentialsCountProviderSumsTheFrontendSegment(): void
    {
        // Backend fixtures empty -> backend contributes 0 active credentials;
        // the frontend fixtures hold 4 active credentials (one revoked).
        // A total of 4 proves the frontend provider is in the collection.
        $dataProvider = $this->get(PasskeyCredentialsCountDataProvider::class);

        self::assertSame(4, $dataProvider->getNumber());
    }

    #[Test]
    public function adoptionChartHasOneDatasetPerRegisteredAudience(): void
    {
        $dataProvider = $this->get(PasskeyAdoptionChartDataProvider::class);

        $chartData = $dataProvider->getChartData();

        // Backend (always) + frontend (present) = two audience datasets,
        // sorted by audienceKey (backend before frontend).
        self::assertCount(2, $chartData['datasets']);
        // Frontend segment: 2 users with passkeys, 5 - 2 = 3 without.
        self::assertSame([2, 3], $chartData['datasets'][1]['data']);
    }
}
