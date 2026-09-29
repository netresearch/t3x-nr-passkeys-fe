<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\FrontendGroupEnforcementService;
use Netresearch\NrPasskeysFe\Service\FrontendUserLookupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Admin API controller for FE passkey management operations.
 *
 * Provides AJAX endpoints for listing, revoking, and unlocking
 * frontend user passkeys, for setting a frontend user group's enforcement
 * level, and for resetting a user's grace period. All endpoints require a
 * backend admin session.
 */
final readonly class AdminController
{
    use JsonBodyTrait;

    public function __construct(
        private FrontendCredentialRepository $credentialRepository,
        private FrontendUserLookupService $userLookupService,
        private RateLimiterService $rateLimiterService,
        private LoggerInterface $logger,
        private FrontendGroupEnforcementService $groupEnforcementService,
        private FrontendEnforcementService $enforcementService,
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
            static fn(FrontendCredential $cred): array => [
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
        if (!$credential instanceof FrontendCredential) {
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

        $revokedCount = $this->credentialRepository->revokeAllByFeUser($feUserUid, $adminUid);

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
        $row = $this->userLookupService->findFeUserByUid($feUserUid);

        if ($row === null || $row['username'] !== $username) {
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
     * Reset the grace period of a frontend user: the next request the
     * enrollment interstitial handles under Required starts a new one.
     *
     * POST /nr-passkeys-fe/admin/reset-grace-period
     * Body: { "feUserUid": 123 }
     */
    public function resetGracePeriodAction(ServerRequestInterface $request): ResponseInterface
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

        // An existing user only, so the audit log names no phantom uid.
        if ($this->userLookupService->findFeUserByUid($feUserUid) === null) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        $this->enforcementService->resetGracePeriod($feUserUid);

        $this->logger->info('Admin reset FE passkey grace period', [
            'admin_uid' => $adminUid,
            'fe_user_uid' => $feUserUid,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Set the passkey enforcement level of a frontend user group.
     *
     * POST /nr-passkeys-fe/admin/update-enforcement
     * Body: { "groupUid": 3, "enforcement": "required" }
     *
     * The route token that TYPO3 adds to every backend AJAX URL is the CSRF
     * protection; the request never reaches this action without it.
     */
    public function updateEnforcementAction(ServerRequestInterface $request): ResponseInterface
    {
        $adminUid = $this->requireAdminUid();
        if ($adminUid === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $body = $this->getJsonBody($request);
        $rawGroupUid = $body['groupUid'] ?? null;
        // Only a plain integer: is_numeric() would let "1.5", "1e2" and " 1"
        // through as group 1, 100 and 1, and canBeInterpretedAsInteger()
        // accepts the JSON values true and 1.0 as 1.
        $groupUid = !\is_bool($rawGroupUid) && !\is_float($rawGroupUid)
            && MathUtility::canBeInterpretedAsInteger($rawGroupUid) ? (int) $rawGroupUid : 0;
        $rawLevel = $body['enforcement'] ?? null;
        $level = \is_string($rawLevel) ? $rawLevel : '';

        if ($groupUid <= 0) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        if (!FrontendGroupEnforcementService::isValidLevel($level)) {
            return new JsonResponse(['error' => 'Invalid enforcement level'], 400);
        }

        if (!$this->groupEnforcementService->groupExists($groupUid)) {
            return new JsonResponse(['error' => 'Frontend user group not found'], 404);
        }

        // fe_groups has no versioning, so in a workspace DataHandler refuses the
        // write; the frontend only ever reads the live record anyway.
        $backendUser = $GLOBALS['BE_USER'];
        if ($backendUser instanceof BackendUserAuthentication
            && !$backendUser->workspaceAllowsLiveEditingInTable('fe_groups')
        ) {
            return new JsonResponse([
                'error' => $this->translate(
                    'admin.enforcement.error.liveWorkspaceRequired',
                    'Frontend user groups are not versioned in workspaces. Switch to the Live workspace to change the enforcement level.',
                ),
            ], 409);
        }

        try {
            $stored = $this->groupEnforcementService->setLevel($groupUid, $level);
        } catch (RuntimeException $exception) {
            $this->logger->error('Updating the FE group enforcement failed', [
                'admin_uid' => $adminUid,
                'fe_group_uid' => $groupUid,
                'enforcement' => $level,
                'reason' => $exception->getMessage(),
            ]);

            return new JsonResponse(['error' => 'The enforcement level could not be saved'], 500);
        }

        $this->logger->info('Admin changed FE group enforcement', [
            'admin_uid' => $adminUid,
            'fe_group_uid' => $groupUid,
            'enforcement' => $stored,
        ]);

        return new JsonResponse(['status' => 'ok', 'groupUid' => $groupUid, 'enforcement' => $stored]);
    }

    private function translate(string $key, string $fallback): string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang instanceof LanguageService) {
            $translated = $lang->sL('LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang.xlf:' . $key);
            if ($translated !== '') {
                return $translated;
            }
        }

        return $fallback;
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
