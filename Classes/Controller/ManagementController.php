<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\PasskeyEnrollmentService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Handles passkey self-service management for authenticated frontend users.
 *
 * All methods require an authenticated FE user (enforced by EidDispatcher).
 * Provides registration ceremony, listing, renaming, and removal of passkeys.
 */
final class ManagementController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly PasskeyEnrollmentService $enrollmentService,
        private readonly FrontendCredentialRepository $credentialRepository,
        private readonly FrontendWebAuthnService $webAuthnService,
        private readonly SiteConfigurationService $siteConfigurationService,
        private readonly ChallengeService $challengeService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Start passkey enrollment: generate registration options.
     *
     * GET/POST ?eID=nr_passkeys_fe&action=registrationOptions
     * Requires authenticated FE user.
     */
    public function registrationOptionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $feUser = $request->getAttribute('frontend.user');
        \assert($feUser instanceof \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication);
        /** @var array<string, mixed> $userRow */
        $userRow = $feUser->user;
        $feUserUid = \is_numeric($userRow['uid'] ?? null) ? (int) $userRow['uid'] : 0;
        $username = \is_string($userRow['username'] ?? null) ? (string) $userRow['username'] : '';

        try {
            $site = $this->siteConfigurationService->getCurrentSite($request);
        } catch (Throwable $e) {
            $this->logger->error('Management: no site context', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Internal error'], 500);
        }

        $rawChallenge = $this->challengeService->generateChallenge();
        $challengeToken = $this->challengeService->createChallengeToken($rawChallenge);
        $challenge = \bin2hex($rawChallenge);

        try {
            $result = $this->enrollmentService->startEnrollment(
                feUserUid: $feUserUid,
                username: $username,
                challenge: $challenge,
                site: $site,
            );

            return new JsonResponse([
                'options' => \json_decode(
                    $this->webAuthnService->serializeCreationOptions($result['options']),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
                'challengeToken' => $challengeToken,
            ]);
        } catch (RuntimeException $e) {
            $this->logger->error('FE registration options failed', [
                'fe_user_uid' => $feUserUid,
                'error' => $e->getMessage(),
            ]);

            if ($e->getCode() === 1700300001) {
                return new JsonResponse(['error' => 'Maximum number of passkeys reached'], 409);
            }

            return new JsonResponse(['error' => 'Failed to generate registration options'], 500);
        }
    }

    /**
     * Complete passkey enrollment: verify attestation and store credential.
     *
     * POST ?eID=nr_passkeys_fe&action=registrationVerify
     * Body: { "credential": {...}, "challengeToken": "...", "label": "..." }
     * Requires authenticated FE user.
     */
    public function registrationVerifyAction(ServerRequestInterface $request): ResponseInterface
    {
        $feUser = $request->getAttribute('frontend.user');
        \assert($feUser instanceof \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication);
        /** @var array<string, mixed> $userRow */
        $userRow = $feUser->user;
        $feUserUid = \is_numeric($userRow['uid'] ?? null) ? (int) $userRow['uid'] : 0;

        $body = $this->getJsonBody($request);

        $credentialData = $body['credential'] ?? null;
        $credentialJson = \is_array($credentialData)
            ? \json_encode($credentialData, JSON_THROW_ON_ERROR)
            : '';

        $challengeToken = isset($body['challengeToken']) && \is_string($body['challengeToken'])
            ? $body['challengeToken']
            : '';

        $rawLabel = $body['label'] ?? 'Passkey';
        $label = \is_string($rawLabel) ? $rawLabel : 'Passkey';

        if ($credentialJson === '' || $challengeToken === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $label = \mb_substr(\trim($label), 0, 128);
        if ($label === '') {
            $label = 'Passkey';
        }

        try {
            $challenge = $this->challengeService->verifyChallengeToken($challengeToken);
        } catch (RuntimeException $e) {
            $this->logger->warning('FE registration verify: invalid challenge token', [
                'fe_user_uid' => $feUserUid,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Invalid or expired challenge'], 400);
        }

        try {
            $site = $this->siteConfigurationService->getCurrentSite($request);
        } catch (Throwable $e) {
            $this->logger->error('Management: no site context', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Internal error'], 500);
        }

        try {
            $credential = $this->enrollmentService->completeEnrollment(
                feUserUid: $feUserUid,
                attestationJson: $credentialJson,
                challenge: \bin2hex($challenge),
                label: $label,
                site: $site,
            );

            $this->logger->info('FE passkey enrolled', [
                'fe_user_uid' => $feUserUid,
                'credential_uid' => $credential->getUid(),
                'label' => $label,
            ]);

            return new JsonResponse([
                'status' => 'ok',
                'credential' => [
                    'uid' => $credential->getUid(),
                    'label' => $credential->getLabel(),
                    'aaguid' => $credential->getAaguid(),
                    'createdAt' => $credential->getCreatedAt(),
                    'lastUsedAt' => $credential->getLastUsedAt(),
                ],
            ]);
        } catch (RuntimeException $e) {
            $this->logger->error('FE passkey registration failed', [
                'fe_user_uid' => $feUserUid,
                'error' => $e->getMessage(),
            ]);

            if ($e->getCode() === 1700300001) {
                return new JsonResponse(['error' => 'Maximum number of passkeys reached'], 409);
            }

            return new JsonResponse(['error' => 'Registration failed'], 400);
        }
    }

    /**
     * List all passkeys for the authenticated frontend user.
     *
     * GET ?eID=nr_passkeys_fe&action=manageList
     * Requires authenticated FE user.
     */
    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $feUser = $request->getAttribute('frontend.user');
        \assert($feUser instanceof \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication);
        /** @var array<string, mixed> $userRow */
        $userRow = $feUser->user;
        $feUserUid = \is_numeric($userRow['uid'] ?? null) ? (int) $userRow['uid'] : 0;

        try {
            $site = $this->siteConfigurationService->getCurrentSite($request);
            $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);
        } catch (Throwable $e) {
            $this->logger->error('Management list: no site context', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Internal error'], 500);
        }

        $credentials = $this->credentialRepository->findByFeUser($feUserUid, $siteIdentifier);

        $list = \array_map(
            static fn(FrontendCredential $cred): array => [
                'uid' => $cred->getUid(),
                'label' => $cred->getLabel(),
                'aaguid' => $cred->getAaguid(),
                'createdAt' => $cred->getCreatedAt(),
                'lastUsedAt' => $cred->getLastUsedAt(),
            ],
            $credentials,
        );

        return new JsonResponse([
            'credentials' => $list,
            'count' => \count($list),
        ]);
    }

    /**
     * Rename a passkey.
     *
     * POST ?eID=nr_passkeys_fe&action=manageRename
     * Body: { "uid": 123, "label": "New Name" }
     * Requires authenticated FE user.
     */
    public function renameAction(ServerRequestInterface $request): ResponseInterface
    {
        $feUser = $request->getAttribute('frontend.user');
        \assert($feUser instanceof \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication);
        /** @var array<string, mixed> $userRow */
        $userRow = $feUser->user;
        $feUserUid = \is_numeric($userRow['uid'] ?? null) ? (int) $userRow['uid'] : 0;

        $body = $this->getJsonBody($request);
        $credentialUid = self::intFrom($body['uid'] ?? null);
        $rawLabel = $body['label'] ?? null;
        $label = \is_string($rawLabel) ? $rawLabel : '';

        if ($credentialUid === 0 || $label === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $label = \mb_substr(\trim($label), 0, 128);
        if ($label === '') {
            return new JsonResponse(['error' => 'Label must not be empty'], 400);
        }

        $credential = $this->credentialRepository->findByUidAndFeUser($credentialUid, $feUserUid);
        if ($credential === null) {
            return new JsonResponse(['error' => 'Credential not found'], 404);
        }

        $this->credentialRepository->updateLabel($credentialUid, $label);

        $this->logger->info('FE passkey renamed', [
            'fe_user_uid' => $feUserUid,
            'credential_uid' => $credentialUid,
            'new_label' => $label,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Remove a passkey (soft delete via revoke).
     *
     * POST ?eID=nr_passkeys_fe&action=manageRemove
     * Body: { "uid": 123 }
     * Requires authenticated FE user.
     */
    public function removeAction(ServerRequestInterface $request): ResponseInterface
    {
        $feUser = $request->getAttribute('frontend.user');
        \assert($feUser instanceof \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication);
        /** @var array<string, mixed> $userRow */
        $userRow = $feUser->user;
        $feUserUid = \is_numeric($userRow['uid'] ?? null) ? (int) $userRow['uid'] : 0;

        $body = $this->getJsonBody($request);
        $credentialUid = self::intFrom($body['uid'] ?? null);

        if ($credentialUid === 0) {
            return new JsonResponse(['error' => 'Missing credential uid'], 400);
        }

        $credential = $this->credentialRepository->findByUidAndFeUser($credentialUid, $feUserUid);
        if ($credential === null) {
            return new JsonResponse(['error' => 'Credential not found'], 404);
        }

        // Soft delete: revoke with the user as revoker
        $this->credentialRepository->revoke($credentialUid, $feUserUid);

        $this->logger->info('FE passkey removed', [
            'fe_user_uid' => $feUserUid,
            'credential_uid' => $credentialUid,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    private static function intFrom(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }
}
