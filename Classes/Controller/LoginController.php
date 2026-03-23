<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Event\BeforePasskeyAuthenticationEvent;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendUserLookupService;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handles the WebAuthn login ceremony for frontend users.
 *
 * Two endpoints:
 * - optionsAction: Generate assertion options (username-first or discoverable)
 * - verifyAction: Verify assertion response and perform FE login via session
 *
 * Both endpoints are PUBLIC (no FE session required). Authentication state
 * is established by verifyAction on success.
 */
final readonly class LoginController
{
    use JsonBodyTrait;

    public function __construct(
        private FrontendWebAuthnService $webAuthnService,
        private SiteConfigurationService $siteConfigurationService,
        private FrontendCredentialRepository $credentialRepository,
        private FrontendUserLookupService $userLookupService,
        private RateLimiterService $rateLimiterService,
        private ChallengeService $challengeService,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * Generate assertion options for passkey login.
     *
     * POST/GET ?eID=nr_passkeys_fe&action=loginOptions
     * Body: { "username": "..." }  (optional — omit for discoverable login)
     */
    public function optionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getJsonBody($request);
        $username = isset($body['username']) && \is_scalar($body['username'])
            ? (string) $body['username']
            : '';

        $remoteAddr = GeneralUtility::getIndpEnv('REMOTE_ADDR');
        $ip = \is_string($remoteAddr) ? $remoteAddr : '';

        try {
            $this->rateLimiterService->checkRateLimit('fe_login_options', $ip);
        } catch (RuntimeException) {
            return new JsonResponse(['error' => 'Too many requests'], 429, ['Retry-After' => '60']);
        }

        $this->rateLimiterService->recordAttempt('fe_login_options', $ip);

        try {
            $site = $this->siteConfigurationService->getCurrentSite($request);
        } catch (Throwable $e) {
            $this->logger->error('FE login options: no site context', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Internal error'], 500);
        }

        $rawChallenge = $this->challengeService->generateChallenge();
        $challengeToken = $this->challengeService->createChallengeToken($rawChallenge);
        $challenge = \bin2hex($rawChallenge);

        // Discoverable (usernameless) login — no username in body
        if ($username === '') {
            try {
                $result = $this->webAuthnService->createDiscoverableAssertionOptions($challenge, $site);

                return new JsonResponse([
                    'options' => \json_decode($result['optionsJson'], true, 512, JSON_THROW_ON_ERROR),
                    'challengeToken' => $challengeToken,
                ]);
            } catch (Throwable $e) {
                $this->logger->error('FE discoverable assertion options failed', [
                    'error' => $e->getMessage(),
                ]);
                return new JsonResponse(['error' => 'Internal error'], 500);
            }
        }

        // Username-first: look up the fe_user
        $feUserUid = $this->findFeUserUid($username);
        if ($feUserUid === null) {
            // Prevent user enumeration: return generic error with timing delay
            \usleep(\random_int(50000, 150000));
            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        try {
            $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);
            $credentials = $this->credentialRepository->findByFeUser($feUserUid, $siteIdentifier);

            if ($credentials === []) {
                // User has no passkeys — prevent enumeration via timing
                \usleep(\random_int(50000, 150000));
                return new JsonResponse(['error' => 'Authentication failed'], 401);
            }

            $result = $this->webAuthnService->createAssertionOptions($feUserUid, $challenge, $site);

            return new JsonResponse([
                'options' => \json_decode($result['optionsJson'], true, 512, JSON_THROW_ON_ERROR),
                'challengeToken' => $challengeToken,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('FE assertion options failed', [
                'username_hash' => \hash('sha256', $username),
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal error'], 500);
        }
    }

    /**
     * Verify an assertion response and establish an FE session.
     *
     * POST ?eID=nr_passkeys_fe&action=loginVerify
     * Body: { "assertion": {...}, "challengeToken": "..." }
     */
    public function verifyAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getJsonBody($request);

        $assertion = isset($body['assertion']) && \is_array($body['assertion'])
            ? \json_encode($body['assertion'], JSON_THROW_ON_ERROR)
            : '';
        $challengeToken = isset($body['challengeToken']) && \is_string($body['challengeToken'])
            ? $body['challengeToken']
            : '';

        if ($assertion === '' || $challengeToken === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $remoteAddr = GeneralUtility::getIndpEnv('REMOTE_ADDR');
        $ip = \is_string($remoteAddr) ? $remoteAddr : '';

        try {
            $this->rateLimiterService->checkRateLimit('fe_login_verify', $ip);
        } catch (RuntimeException) {
            return new JsonResponse(['error' => 'Too many requests'], 429, ['Retry-After' => '60']);
        }

        $this->rateLimiterService->recordAttempt('fe_login_verify', $ip);

        try {
            $challenge = $this->challengeService->verifyChallengeToken($challengeToken);
        } catch (RuntimeException $e) {
            $this->logger->warning('FE login verify: invalid challenge token', [
                'error' => $e->getMessage(),
                'ip' => $ip,
            ]);
            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        try {
            $site = $this->siteConfigurationService->getCurrentSite($request);
        } catch (Throwable $e) {
            $this->logger->error('FE login verify: no site context', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Internal error'], 500);
        }

        $this->eventDispatcher->dispatch(new BeforePasskeyAuthenticationEvent(null, $assertion));

        try {
            $result = $this->webAuthnService->verifyAssertionResponse(
                assertionJson: $assertion,
                challenge: \bin2hex($challenge),
                site: $site,
            );

            $this->rateLimiterService->recordSuccess('', $ip);

            $feUserUid = $result['feUserUid'];

            // Store verified result in a short-lived cache token.
            // The JS will submit this token via the felogin form so the auth
            // service can authenticate without needing site context.
            $token = \bin2hex(\random_bytes(32));
            $cache = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)
                ->getCache('nr_passkeys_fe_nonce');
            $cache->set(
                'passkey_login_' . $token,
                (string) $feUserUid,
                [],
                120, // 2 minutes TTL
            );

            $this->logger->info('FE passkey login successful via eID endpoint', [
                'fe_user_uid' => $feUserUid,
            ]);

            return new JsonResponse([
                'status' => 'ok',
                'feUserUid' => $feUserUid,
                'loginToken' => $token,
            ]);
        } catch (RuntimeException $e) {
            $this->rateLimiterService->recordFailure('', $ip);

            $this->logger->warning('FE passkey assertion verification failed', [
                'ip' => $ip,
                'error_code' => $e->getCode(),
            ]);

            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }
    }

    /**
     * Establish a real frontend login session for the given fe_user UID.
     *
     * Uses FrontendUserAuthentication::createUserSession() to promote the
     * anonymous session to an authenticated user session. This makes the
     * login persist across page requests without needing the auth service chain.
     */
    private function triggerFeLogin(ServerRequestInterface $request, int $feUserUid): void
    {
        $feUserAuth = $request->getAttribute('frontend.user');
        if (!$feUserAuth instanceof \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication) {
            $this->logger->warning('FE passkey login: frontend.user not available in request attributes');
            return;
        }

        // Fetch the full fe_users record (required by createUserSession)
        $userRecord = $this->userLookupService->findFeUserByUid($feUserUid);
        if ($userRecord === null) {
            $this->logger->warning('FE passkey login: fe_user not found', ['uid' => $feUserUid]);
            return;
        }

        // Build a full user record array as TYPO3 expects
        $connection = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Database\ConnectionPool::class,
        )->getConnectionForTable('fe_users');
        $fullRecord = $connection->select(['*'], 'fe_users', ['uid' => $feUserUid])->fetchAssociative();

        if (!\is_array($fullRecord)) {
            $this->logger->warning('FE passkey login: could not fetch full fe_user record', ['uid' => $feUserUid]);
            return;
        }

        // Establish the session
        $feUserAuth->createUserSession($fullRecord);
        $feUserAuth->user = $fullRecord;

        // Set passkey-authenticated flag on the session
        $feUserAuth->setKey('ses', 'nr_passkeys_fe_passkey_authenticated', true);
        $feUserAuth->storeSessionData();

        // Update the Context aspect so downstream code sees the logged-in user
        $context = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Context\Context::class);
        $context->setAspect('frontend.user', $feUserAuth->createUserAspect());
    }

    private function findFeUserUid(string $username): ?int
    {
        return $this->userLookupService->findFeUserUidByUsername($username);
    }
}
