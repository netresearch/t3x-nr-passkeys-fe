<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Cose\Algorithm\Manager as AlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES384;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\RSA\RS256;
use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * WebAuthn ceremony handler for frontend (fe_users).
 *
 * Mirrors the BE WebAuthnService but:
 * - Uses FrontendCredentialRepository instead of BE CredentialRepository
 * - Reads RP ID / origin from SiteConfigurationService (per-site)
 * - Operates on fe_user UIDs, not be_user UIDs
 * - Challenge management is external (controllers use ChallengeService directly)
 */
final class FrontendWebAuthnService
{
    private const ALGORITHM_MAP = [
        'ES256' => -7,
        'ES384' => -35,
        'ES512' => -36,
        'RS256' => -257,
    ];

    private const ALLOWED_ALGORITHMS = ['ES256', 'ES384', 'ES512', 'RS256'];

    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly FrontendCredentialRepository $credentialRepository,
        private readonly SiteConfigurationService $siteConfigurationService,
        private readonly FrontendConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create registration options for a frontend user.
     *
     * @return array{options: PublicKeyCredentialCreationOptions, optionsJson: string}
     */
    public function createRegistrationOptions(
        int $feUserUid,
        string $username,
        string $challenge,
        SiteInterface $site,
    ): array {
        $rpId = $this->siteConfigurationService->getRpId($site);
        $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);

        $rp = PublicKeyCredentialRpEntity::create(
            name: $rpId,
            id: $rpId,
        );

        $userHandle = $this->createUserHandle($feUserUid);

        $user = PublicKeyCredentialUserEntity::create(
            name: $username,
            id: $userHandle,
            displayName: $username,
        );

        $existingCredentials = $this->credentialRepository->findByFeUser($feUserUid, $siteIdentifier);
        $excludeCredentials = \array_map(
            static fn(FrontendCredential $cred): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                id: $cred->getCredentialId(),
                transports: $cred->getTransportsArray(),
            ),
            $existingCredentials,
        );

        $authenticatorSelection = AuthenticatorSelectionCriteria::create(
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
        );

        $options = PublicKeyCredentialCreationOptions::create(
            rp: $rp,
            user: $user,
            challenge: $challenge,
            pubKeyCredParams: $this->getPublicKeyCredentialParameters(),
            authenticatorSelection: $authenticatorSelection,
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $excludeCredentials,
            timeout: 60000,
        );

        return [
            'options' => $options,
            'optionsJson' => $this->serializeCreationOptions($options),
        ];
    }

    /**
     * Verify a registration response from the browser.
     *
     * @throws RuntimeException on verification failure
     */
    public function verifyRegistrationResponse(
        string $attestationJson,
        string $challenge,
        int $feUserUid,
        SiteInterface $site,
    ): FrontendCredential {
        $rpId = $this->siteConfigurationService->getRpId($site);
        $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);
        $userHandle = $this->createUserHandle($feUserUid);

        $rp = PublicKeyCredentialRpEntity::create(name: $rpId, id: $rpId);
        $user = PublicKeyCredentialUserEntity::create(
            name: '',
            id: $userHandle,
            displayName: '',
        );

        $creationOptions = PublicKeyCredentialCreationOptions::create(
            rp: $rp,
            user: $user,
            challenge: $challenge,
            pubKeyCredParams: $this->getPublicKeyCredentialParameters(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
        );

        $publicKeyCredential = $this->getSerializer()->deserialize(
            $attestationJson,
            PublicKeyCredential::class,
            'json',
        );

        if (!$publicKeyCredential instanceof PublicKeyCredential) {
            throw new RuntimeException('Failed to deserialize credential response', 1700200020);
        }

        $response = $publicKeyCredential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new RuntimeException('Expected attestation response', 1700200021);
        }

        $factory = $this->createCeremonyFactory($site);
        $ceremonyManager = $factory->creationCeremony();
        $validator = AuthenticatorAttestationResponseValidator::create($ceremonyManager);

        try {
            $source = $validator->check(
                authenticatorAttestationResponse: $response,
                publicKeyCredentialCreationOptions: $creationOptions,
                host: $rpId,
            );
        } catch (Throwable $e) {
            $this->logger->error('Frontend passkey registration verification failed', [
                'fe_user_uid' => $feUserUid,
                'site' => $siteIdentifier,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                'Registration verification failed: ' . $e->getMessage(),
                1700200022,
                $e,
            );
        }

        $this->logger->info('Frontend passkey registered successfully', [
            'fe_user_uid' => $feUserUid,
            'site' => $siteIdentifier,
        ]);

        return $this->sourceToCredential($source, $feUserUid, $siteIdentifier);
    }

    /**
     * Create assertion options for username-first login.
     *
     * @return array{options: PublicKeyCredentialRequestOptions, optionsJson: string}
     */
    public function createAssertionOptions(
        int $feUserUid,
        string $challenge,
        SiteInterface $site,
    ): array {
        $rpId = $this->siteConfigurationService->getRpId($site);
        $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);

        $credentials = $this->credentialRepository->findByFeUser($feUserUid, $siteIdentifier);
        $allowCredentials = \array_map(
            static fn(FrontendCredential $cred): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                id: $cred->getCredentialId(),
                transports: $cred->getTransportsArray(),
            ),
            $credentials,
        );

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: $allowCredentials,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: 60000,
        );

        return [
            'options' => $options,
            'optionsJson' => $this->serializeRequestOptions($options),
        ];
    }

    /**
     * Create assertion options for discoverable (identifierless) login.
     *
     * @return array{options: PublicKeyCredentialRequestOptions, optionsJson: string}
     */
    public function createDiscoverableAssertionOptions(
        string $challenge,
        SiteInterface $site,
    ): array {
        $rpId = $this->siteConfigurationService->getRpId($site);

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: [],
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: 60000,
        );

        return [
            'options' => $options,
            'optionsJson' => $this->serializeRequestOptions($options),
        ];
    }

    /**
     * Verify an assertion response for login.
     *
     * @return array{feUserUid: int, credential: FrontendCredential}
     *
     * @throws RuntimeException on verification failure
     */
    public function verifyAssertionResponse(
        string $assertionJson,
        string $challenge,
        SiteInterface $site,
    ): array {
        $rpId = $this->siteConfigurationService->getRpId($site);
        $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);

        $publicKeyCredential = $this->getSerializer()->deserialize(
            $assertionJson,
            PublicKeyCredential::class,
            'json',
        );

        if (!$publicKeyCredential instanceof PublicKeyCredential) {
            throw new RuntimeException('Failed to deserialize assertion response', 1700200030);
        }

        $response = $publicKeyCredential->response;
        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw new RuntimeException('Expected assertion response', 1700200031);
        }

        // Find the credential by its ID
        $credentialId = $publicKeyCredential->rawId;
        $credential = $this->credentialRepository->findByCredentialId($credentialId);

        if ($credential === null) {
            $this->logger->warning('FE assertion with unknown credential ID', [
                'site' => $siteIdentifier,
            ]);
            throw new RuntimeException('Unknown credential', 1700200032);
        }

        if ($credential->isRevoked()) {
            throw new RuntimeException('Credential has been revoked', 1700200033);
        }

        $storedSource = $this->credentialToSource($credential);

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );

        $factory = $this->createCeremonyFactory($site);
        $ceremonyManager = $factory->requestCeremony();
        $validator = AuthenticatorAssertionResponseValidator::create($ceremonyManager);

        try {
            $updatedSource = $validator->check(
                publicKeyCredentialSource: $storedSource,
                authenticatorAssertionResponse: $response,
                publicKeyCredentialRequestOptions: $requestOptions,
                host: $rpId,
                userHandle: $credential->getUserHandle() !== '' ? $credential->getUserHandle() : null,
            );
        } catch (Throwable $e) {
            $this->logger->error('Frontend passkey assertion verification failed', [
                'fe_user_uid' => $credential->getFeUser(),
                'site' => $siteIdentifier,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                'Assertion verification failed: ' . $e->getMessage(),
                1700200035,
                $e,
            );
        }

        $this->credentialRepository->updateSignCount($credential->getUid(), $updatedSource->counter);
        $this->credentialRepository->updateLastUsed($credential->getUid());

        $this->logger->info('Frontend passkey login successful', [
            'fe_user_uid' => $credential->getFeUser(),
            'credential_uid' => $credential->getUid(),
            'site' => $siteIdentifier,
        ]);

        return [
            'feUserUid' => $credential->getFeUser(),
            'credential' => $credential,
        ];
    }

    /**
     * Resolve the fe_user UID from a passkey assertion response.
     *
     * Used for discoverable (usernameless) login where the credential ID
     * in the assertion identifies the user without requiring a username.
     */
    public function findFeUserUidFromAssertion(string $assertionJson): ?int
    {
        try {
            $publicKeyCredential = $this->getSerializer()->deserialize(
                $assertionJson,
                PublicKeyCredential::class,
                'json',
            );

            if (!$publicKeyCredential instanceof PublicKeyCredential) {
                return null;
            }

            $credential = $this->credentialRepository->findByCredentialId($publicKeyCredential->rawId);
            if ($credential === null || $credential->isRevoked()) {
                return null;
            }

            return $credential->getFeUser();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Serialize PublicKeyCredentialCreationOptions to JSON for the browser.
     */
    public function serializeCreationOptions(PublicKeyCredentialCreationOptions $options): string
    {
        return $this->getSerializer()->serialize($options, 'json');
    }

    /**
     * Serialize PublicKeyCredentialRequestOptions to JSON for the browser.
     */
    public function serializeRequestOptions(PublicKeyCredentialRequestOptions $options): string
    {
        return $this->getSerializer()->serialize($options, 'json');
    }

    private function getSerializer(): SerializerInterface
    {
        if ($this->serializer === null) {
            $attestationManager = $this->createAttestationStatementSupportManager();
            $factory = new WebauthnSerializerFactory($attestationManager);
            $this->serializer = $factory->create();
        }

        return $this->serializer;
    }

    private function createAttestationStatementSupportManager(): AttestationStatementSupportManager
    {
        $manager = new AttestationStatementSupportManager();
        $manager->add(new NoneAttestationStatementSupport());

        return $manager;
    }

    private function createCeremonyFactory(SiteInterface $site): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();

        $origin = $this->siteConfigurationService->getOrigin($site);
        $factory->setAllowedOrigins([$origin]);

        $algorithmManager = $this->createAlgorithmManager();
        $factory->setAlgorithmManager($algorithmManager);
        $factory->setAttestationStatementSupportManager($this->createAttestationStatementSupportManager());

        return $factory;
    }

    private function createAlgorithmManager(): AlgorithmManager
    {
        $manager = AlgorithmManager::create();
        $manager->add(ES256::create());
        $manager->add(ES384::create());
        $manager->add(ES512::create());
        $manager->add(RS256::create());

        return $manager;
    }

    /**
     * @return list<PublicKeyCredentialParameters>
     */
    private function getPublicKeyCredentialParameters(): array
    {
        $params = [];
        foreach (self::ALLOWED_ALGORITHMS as $algo) {
            $algoId = self::ALGORITHM_MAP[$algo] ?? null;
            if ($algoId !== null) {
                $params[] = PublicKeyCredentialParameters::createPk($algoId);
            }
        }

        return $params;
    }

    private function createUserHandle(int $feUserUid): string
    {
        $key = $this->getEncryptionKey();
        $derivedKey = \hash_hkdf('sha256', $key, 32, 'nr_passkeys_fe_user_handle');

        return \hash_hmac('sha256', (string) $feUserUid, $derivedKey, true);
    }

    private function getEncryptionKey(): string
    {
        $typo3Conf = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConf = \is_array($typo3Conf) ? ($typo3Conf['SYS'] ?? null) : null;
        $key = \is_array($sysConf) && \is_string($sysConf['encryptionKey'] ?? null)
            ? $sysConf['encryptionKey']
            : '';

        if (\strlen($key) < 32) {
            throw new RuntimeException(
                'TYPO3 encryptionKey is missing or too short (min 32 chars). '
                . 'Configure it in Settings > Configure Installation-Wide Options.',
                1700200040,
            );
        }

        return $key;
    }

    private function credentialToSource(FrontendCredential $credential): PublicKeyCredentialSource
    {
        $aaguid = $credential->getAaguid() !== ''
            ? \Symfony\Component\Uid\Uuid::fromString($credential->getAaguid())
            : \Symfony\Component\Uid\Uuid::v4();

        return PublicKeyCredentialSource::create(
            publicKeyCredentialId: $credential->getCredentialId(),
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: $credential->getTransportsArray(),
            attestationType: 'none',
            trustPath: new EmptyTrustPath(),
            aaguid: $aaguid,
            credentialPublicKey: $credential->getPublicKeyCose(),
            userHandle: $credential->getUserHandle(),
            counter: $credential->getSignCount(),
        );
    }

    private function sourceToCredential(
        PublicKeyCredentialSource $source,
        int $feUserUid,
        string $siteIdentifier,
    ): FrontendCredential {
        return new FrontendCredential(
            feUser: $feUserUid,
            credentialId: $source->publicKeyCredentialId,
            publicKeyCose: $source->credentialPublicKey,
            signCount: $source->counter,
            userHandle: $source->userHandle,
            aaguid: $source->aaguid->toString(),
            transports: \json_encode($source->transports, JSON_THROW_ON_ERROR),
            siteIdentifier: $siteIdentifier,
        );
    }
}
