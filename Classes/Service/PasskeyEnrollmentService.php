<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Event\AfterPasskeyEnrollmentEvent;
use Netresearch\NrPasskeysFe\Event\BeforePasskeyEnrollmentEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

/**
 * Orchestrates the passkey enrollment flow for frontend users.
 *
 * Coordinates the FrontendWebAuthnService (cryptographic verification),
 * FrontendCredentialRepository (persistence), and FrontendConfiguration
 * (policy enforcement such as max passkeys per user). Dispatches
 * Before/After enrollment events for extension points.
 */
final readonly class PasskeyEnrollmentService
{
    public function __construct(
        private FrontendWebAuthnService $webAuthnService,
        private FrontendCredentialRepository $credentialRepository,
        private FrontendConfiguration $configuration,
        private SiteConfigurationService $siteConfigurationService,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Start a passkey enrollment by generating registration options.
     *
     * @return array{options: \Webauthn\PublicKeyCredentialCreationOptions, optionsJson: string}
     */
    public function startEnrollment(
        int $feUserUid,
        string $username,
        string $challenge,
        SiteInterface $site,
    ): array {
        $this->assertMaxPasskeysNotReached($feUserUid);

        return $this->webAuthnService->createRegistrationOptions(
            $feUserUid,
            $username,
            $challenge,
            $site,
        );
    }

    /**
     * Complete a passkey enrollment by verifying the attestation and storing the credential.
     *
     * @throws RuntimeException when the maximum number of passkeys is reached
     * @throws RuntimeException when verification fails
     */
    public function completeEnrollment(
        int $feUserUid,
        string $attestationJson,
        string $challenge,
        string $label,
        SiteInterface $site,
    ): FrontendCredential {
        $this->assertMaxPasskeysNotReached($feUserUid);

        $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);

        // Dispatch before event — listeners may abort by throwing
        $this->eventDispatcher->dispatch(
            new BeforePasskeyEnrollmentEvent($feUserUid, $siteIdentifier, $attestationJson),
        );

        $credential = $this->webAuthnService->verifyRegistrationResponse(
            $attestationJson,
            $challenge,
            $feUserUid,
            $site,
        );

        $credential->setLabel($label);
        $this->credentialRepository->save($credential);

        // Dispatch after event
        $this->eventDispatcher->dispatch(
            new AfterPasskeyEnrollmentEvent($feUserUid, $credential, $siteIdentifier),
        );

        return $credential;
    }

    /**
     * @throws RuntimeException when the maximum number of passkeys is reached
     */
    private function assertMaxPasskeysNotReached(int $feUserUid): void
    {
        $maxPasskeys = $this->configuration->getMaxPasskeysPerUser();
        $currentCount = $this->credentialRepository->countByFeUser($feUserUid);

        if ($currentCount >= $maxPasskeys) {
            throw new RuntimeException(
                \sprintf(
                    'Maximum number of passkeys (%d) reached for user %d',
                    $maxPasskeys,
                    $feUserUid,
                ),
                1700300001,
            );
        }
    }
}
