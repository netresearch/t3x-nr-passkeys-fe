<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handles recovery code management for frontend users.
 *
 * - generateAction: Authenticated — generates new recovery codes, returns plaintext
 * - verifyAction:   Public — verifies a recovery code and establishes FE session
 */
final class RecoveryController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly RecoveryCodeService $recoveryCodeService,
        private readonly RateLimiterService $rateLimiterService,
        private readonly FrontendCredentialRepository $credentialRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generate new recovery codes for the authenticated frontend user.
     *
     * POST ?eID=nr_passkeys_fe&action=recoveryGenerate
     * Requires authenticated FE user (enforced by EidDispatcher).
     * Returns the plaintext codes — these are shown exactly once.
     */
    public function generateAction(ServerRequestInterface $request): ResponseInterface
    {
        $feUser = $request->getAttribute('frontend.user');
        \assert($feUser instanceof \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication);
        /** @var array<string, mixed> $userRow */
        $userRow = $feUser->user;
        $feUserUid = \is_numeric($userRow['uid'] ?? null) ? (int) $userRow['uid'] : 0;

        try {
            $codes = $this->recoveryCodeService->generate($feUserUid);
        } catch (Throwable $e) {
            $this->logger->error('FE recovery code generation failed', [
                'fe_user_uid' => $feUserUid,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Failed to generate recovery codes'], 500);
        }

        $this->logger->info('FE recovery codes generated', [
            'fe_user_uid' => $feUserUid,
            'count' => \count($codes),
        ]);

        return new JsonResponse([
            'status' => 'ok',
            'codes' => $codes,
            'count' => \count($codes),
        ]);
    }

    /**
     * Verify a recovery code and establish an FE session on success.
     *
     * POST ?eID=nr_passkeys_fe&action=recoveryVerify
     * Body: { "username": "...", "code": "XXXX-XXXX" }
     * Public endpoint (no FE auth required).
     */
    public function verifyAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getJsonBody($request);

        $username = isset($body['username']) && \is_scalar($body['username'])
            ? (string) $body['username']
            : '';
        $code = isset($body['code']) && \is_scalar($body['code'])
            ? (string) $body['code']
            : '';

        if ($username === '' || $code === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $remoteAddr = GeneralUtility::getIndpEnv('REMOTE_ADDR');
        $ip = \is_string($remoteAddr) ? $remoteAddr : '';

        try {
            $this->rateLimiterService->checkRateLimit('fe_recovery_verify', $ip);
            $this->rateLimiterService->checkLockout($username, $ip);
        } catch (RuntimeException) {
            return new JsonResponse(['error' => 'Too many requests'], 429, ['Retry-After' => '60']);
        }

        $this->rateLimiterService->recordAttempt('fe_recovery_verify', $ip);

        // Look up the fe_user to get their UID
        $feUserUid = $this->findFeUserUid($username);
        if ($feUserUid === null) {
            // Prevent user enumeration
            \usleep(\random_int(50000, 150000));
            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        $valid = $this->recoveryCodeService->verify($feUserUid, $code);

        if (!$valid) {
            $this->rateLimiterService->recordFailure($username, $ip);

            $this->logger->warning('FE recovery code verification failed', [
                'username_hash' => \hash('sha256', $username),
                'ip' => $ip,
            ]);

            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        $this->rateLimiterService->recordSuccess($username, $ip);

        $this->logger->info('FE recovery code accepted', [
            'fe_user_uid' => $feUserUid,
        ]);

        // Set session flag so the JS can redirect to post-login page
        $this->setFeUserSessionKey($request, 'nr_passkeys_fe_recovery_authenticated', true);
        $this->setFeUserSessionKey($request, 'nr_passkeys_fe_pending_uid', $feUserUid);

        return new JsonResponse([
            'status' => 'ok',
            'feUserUid' => $feUserUid,
        ]);
    }

    /**
     * Set a session key on the frontend user session.
     */
    private function setFeUserSessionKey(ServerRequestInterface $request, string $key, mixed $value): void
    {
        $feUserAuth = $request->getAttribute('frontend.user');
        if ($feUserAuth instanceof \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication) {
            $feUserAuth->setKey('ses', $key, $value);
            $feUserAuth->storeSessionData();
        } else {
            $this->logger->warning('FE recovery: frontend.user not available in request attributes');
        }
    }

    private function findFeUserUid(string $username): ?int
    {
        return $this->credentialRepository->findFeUserUidByUsername($username);
    }
}
