<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Adoption;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use Netresearch\NrPasskeysBe\Widgets\Adoption\PasskeyAdoptionStatsProviderInterface;
use Netresearch\NrPasskeysFe\Service\FrontendAdoptionStatsService;

/**
 * Frontend-user (fe_users / tx_nrpasskeysfe_credential) segment provider.
 *
 * Contributes the frontend segment to the unified passkey-adoption dashboard
 * widgets owned by nr_passkeys_be, WITHOUT nr_passkeys_be depending on
 * nr_passkeys_fe. Collected via the DI tag
 * 'nr_passkeys_be.adoption_stats_provider'.
 *
 * References only plain PHP from nr_passkeys_be (the interface and the DTO
 * carry no typo3/cms-dashboard symbol), so this class needs no dashboard
 * guard and no compat shim.
 */
final readonly class FrontendPasskeyAdoptionStatsProvider implements PasskeyAdoptionStatsProviderInterface
{
    public function __construct(
        private FrontendAdoptionStatsService $statsService,
    ) {}

    public function getAudienceStats(): PasskeyAudienceStats
    {
        return new PasskeyAudienceStats(
            audienceKey: 'frontend',
            totalActiveUsers: $this->statsService->countTotalActiveUsers(),
            usersWithPasskeys: $this->statsService->countUsersWithActivePasskey(),
            activeCredentials: $this->statsService->countActiveCredentials(),
        );
    }
}
