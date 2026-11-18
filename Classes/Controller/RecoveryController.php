<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Event\RecoveryCodesGeneratedEvent;
use Netresearch\NrPasskeysFe\Service\FrontendUserLookupService;
use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

use function assert;
use function bin2hex;
use function count;
use function hash;
use function is_numeric;
use function is_scalar;
use function random_bytes;

/**
 * Handles recovery code management for frontend users.
 *
 * - generateAction: Authenticated — generates new recovery codes, returns plaintext
 * - verifyAction:   Public — verifies a recovery code and establishes FE session
 */
final readonly class RecoveryController
{
    use JsonBodyTrait;
    use RemoteAddressTrait;

    public function __construct(
        private RecoveryCodeService $recoveryCodeService,
        private RateLimiterService $rateLimiterService,
        private FrontendUserLookupService $userLookupService,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
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
        assert($feUser instanceof FrontendUserAuthentication);
        /** @var array<string, mixed> $userRow */
        $userRow   = $feUser->user;
        $feUserUid = is_numeric($userRow['uid'] ?? null) ? (int) $userRow['uid'] : 0;

        try {
            $site = $request->getAttribute('site');
            if (!$site instanceof SiteInterface || $site->getIdentifier() === '') {
                return new JsonResponse(['error' => 'A configured site is required'], 403);
            }

            $codes = $this->recoveryCodeService->generate($feUserUid, site: $site);
        } catch (Throwable $e) {
            $this->logger->error('FE recovery code generation failed', [
                'fe_user_uid' => $feUserUid,
                'error'       => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Failed to generate recovery codes'], 500);
        }

        $this->eventDispatcher->dispatch(new RecoveryCodesGeneratedEvent($feUserUid, count($codes)));

        $this->logger->info('FE recovery codes generated', [
            'fe_user_uid' => $feUserUid,
            'count'       => count($codes),
        ]);

        return new JsonResponse([
            'status' => 'ok',
            'codes'  => $codes,
            'count'  => count($codes),
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

        $username = isset($body['username']) && is_scalar($body['username'])
            ? (string) $body['username']
            : '';
        $code = isset($body['code']) && is_scalar($body['code'])
            ? (string) $body['code']
            : '';

        if ($username === '' || $code === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $ip = $this->getRemoteAddress($request);

        try {
            $this->rateLimiterService->consumeRateLimit('fe_recovery_verify', $ip);
        } catch (RuntimeException) {
            return new JsonResponse(['error' => 'Too many requests'], 429, ['Retry-After' => '60']);
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof SiteInterface || $site->getIdentifier() === '') {
            return new JsonResponse(['error' => 'Invalid site'], 400);
        }

        // Look up the fe_user to get their UID
        $feUserUid = $this->userLookupService->findFeUserUidByUsername($username, $site);
        $account   = $feUserUid === null ? 'unknown:' . hash('sha256', $site->getIdentifier() . '|' . $username) : (string) $feUserUid;
        try {
            $this->rateLimiterService->checkLockout($account, $ip);
        } catch (RuntimeException) {
            return new JsonResponse(['error' => 'Too many requests'], 429, ['Retry-After' => '60']);
        }

        if (!RecoveryCodeService::hasValidCodeLength($code)) {
            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        if (!$this->recoveryCodeService->reserveVerificationCapacity()) {
            return new JsonResponse(['error' => 'Recovery temporarily busy'], 429, ['Retry-After' => '60']);
        }

        $valid = $this->recoveryCodeService->verify($feUserUid ?? 0, $code, $site);
        if ($feUserUid === null || !$valid) {
            $this->rateLimiterService->recordFailure($account, $ip, false);
            $this->logger->warning('FE recovery code verification failed', [
                'username_hash' => hash('sha256', $username),
                'ip'            => $ip,
            ]);

            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        $this->rateLimiterService->recordSuccess($account, $ip);

        $this->logger->info('FE recovery code accepted', [
            'fe_user_uid' => $feUserUid,
        ]);

        // Create a one-time login token (same mechanism as passkey login)
        $storagePid = $this->userLookupService->ownerStoragePid($feUserUid, $site);
        if ($storagePid === null) {
            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        $token = bin2hex(random_bytes(32));
        $cache = GeneralUtility::makeInstance(CacheManager::class)
            ->getCache('nr_passkeys_fe_nonce');
        $cache->set(
            'passkey_login_' . $token,
            json_encode(['uid' => $feUserUid, 'site' => $site->getIdentifier(), 'storagePid' => $storagePid, 'expiresAt' => time() + 120, 'method' => 'recovery', 'credentialUid' => 0], JSON_THROW_ON_ERROR),
            [],
            120,
        );

        return new JsonResponse([
            'status'     => 'ok',
            'feUserUid'  => $feUserUid,
            'loginToken' => $token,
        ]);
    }
}
