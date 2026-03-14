<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Admin API controller for FE passkey management operations.
 *
 * Provides AJAX endpoints for listing, revoking, and unlocking
 * frontend user passkeys. All endpoints require a backend admin session.
 */
final class AdminController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly FrontendCredentialRepository $credentialRepository,
        private readonly RateLimiterService $rateLimiterService,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * List all passkeys for a specific frontend user.
     *
     * GET /nr-passkeys-fe/admin/list?feUserUid=123
     */
    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $queryParams = $request->getQueryParams();
        $rawUid = $queryParams['feUserUid'] ?? null;
        $feUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;

        if ($feUserUid === 0) {
            return new JsonResponse(['error' => 'Missing feUserUid parameter'], 400);
        }

        $credentials = $this->credentialRepository->findAllByFeUser($feUserUid);
        $list = \array_map(
            static fn($cred) => [
                'uid' => $cred->getUid(),
                'label' => $cred->getLabel(),
                'siteIdentifier' => $cred->getSiteIdentifier(),
                'createdAt' => $cred->getCreatedAt(),
                'lastUsedAt' => $cred->getLastUsedAt(),
                'revokedAt' => $cred->getRevokedAt(),
                'isRevoked' => $cred->isRevoked(),
            ],
            $credentials,
        );

        return new JsonResponse([
            'feUserUid' => $feUserUid,
            'credentials' => $list,
            'count' => \count($list),
        ]);
    }

    /**
     * Remove/revoke a specific passkey for a frontend user.
     *
     * POST /nr-passkeys-fe/admin/remove
     * Body: { "feUserUid": 123, "credentialUid": 456 }
     */
    public function removeAction(ServerRequestInterface $request): ResponseInterface
    {
        $adminUid = $this->requireAdminUid();
        if ($adminUid === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $body = $this->getJsonBody($request);
        $rawUid = $body['feUserUid'] ?? null;
        $feUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;
        $rawCredUid = $body['credentialUid'] ?? null;
        $credentialUid = \is_numeric($rawCredUid) ? (int) $rawCredUid : 0;

        if ($feUserUid === 0 || $credentialUid === 0) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        // Verify the credential belongs to the specified user
        $credential = $this->credentialRepository->findByUidAndFeUser($credentialUid, $feUserUid);
        if ($credential === null) {
            return new JsonResponse(['error' => 'Credential not found for this user'], 404);
        }

        $this->credentialRepository->revoke($credentialUid, $adminUid);

        $this->logger->info('Admin revoked FE passkey', [
            'admin_uid' => $adminUid,
            'fe_user_uid' => $feUserUid,
            'credential_uid' => $credentialUid,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Revoke all active passkeys for a frontend user.
     *
     * POST /nr-passkeys-fe/admin/revoke-all
     * Body: { "feUserUid": 123 }
     */
    public function revokeAllAction(ServerRequestInterface $request): ResponseInterface
    {
        $adminUid = $this->requireAdminUid();
        if ($adminUid === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $body = $this->getJsonBody($request);
        $rawUid = $body['feUserUid'] ?? null;
        $feUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;

        if ($feUserUid === 0) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $credentials = $this->credentialRepository->findAllByFeUser($feUserUid);
        $revokedCount = 0;

        foreach ($credentials as $credential) {
            if (!$credential->isRevoked()) {
                $this->credentialRepository->revoke($credential->getUid(), $adminUid);
                ++$revokedCount;
            }
        }

        $this->logger->info('Admin revoked all FE passkeys', [
            'admin_uid' => $adminUid,
            'fe_user_uid' => $feUserUid,
            'revoked_count' => $revokedCount,
        ]);

        return new JsonResponse(['status' => 'ok', 'revokedCount' => $revokedCount]);
    }

    /**
     * Clear the rate-limiter lockout for a frontend user.
     *
     * POST /nr-passkeys-fe/admin/unlock
     * Body: { "feUserUid": 123, "username": "john" }
     */
    public function unlockAction(ServerRequestInterface $request): ResponseInterface
    {
        $adminUid = $this->requireAdminUid();
        if ($adminUid === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $body = $this->getJsonBody($request);
        $rawUid = $body['feUserUid'] ?? null;
        $feUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;
        $rawUsername = $body['username'] ?? null;
        $username = \is_string($rawUsername) ? \trim($rawUsername) : '';

        if ($feUserUid === 0 || $username === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        // Validate feUserUid matches username to ensure audit-log integrity
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');
        $row = $queryBuilder
            ->select('uid', 'username')
            ->from('fe_users')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($feUserUid, Connection::PARAM_INT),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false || $row['username'] !== $username) {
            return new JsonResponse(['error' => 'User not found or username mismatch'], 404);
        }

        // Clear the rate-limiter lockout state for this FE user
        $this->rateLimiterService->resetLockout($username);

        $this->logger->info('Admin unlocked FE user account', [
            'admin_uid' => $adminUid,
            'fe_user_uid' => $feUserUid,
            'username' => $username,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Check whether the current request is from a backend admin.
     */
    private function isAdmin(): bool
    {
        return $this->requireAdminUid() !== null;
    }

    /**
     * Return the admin BE user UID, or null if the request is not authenticated as admin.
     */
    private function requireAdminUid(): ?int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        $userData = $backendUser->user;
        if (!\is_array($userData)) {
            return null;
        }

        if (!$backendUser->isAdmin()) {
            return null;
        }

        $rawUid = $userData['uid'] ?? null;
        if (!\is_numeric($rawUid)) {
            return null;
        }

        return (int) $rawUid;
    }
}
