<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Authentication;

use Doctrine\DBAL\ParameterType;
use JsonException;
use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Authentication service for passwordless frontend login via Passkeys (WebAuthn).
 *
 * Priority: 80 (higher than SaltedPasswordService at 50)
 * - If passkey assertion data is present in uident, verify and authenticate
 * - If no passkey data, pass through to next service (password)
 *
 * The passkey assertion and challenge token are packed into the standard
 * userident form field as JSON with _type="passkey". This is necessary
 * because $GLOBALS['TYPO3_REQUEST'] is not reliably available during the
 * auth service chain, so custom POST fields may be inaccessible. The uident
 * field is the standard TYPO3 mechanism for passing auth credentials.
 */
final class PasskeyFrontendAuthenticationService extends AbstractAuthenticationService
{
    private ?FrontendWebAuthnService $webAuthnService = null;

    private ?RateLimiterService $rateLimiterService = null;

    private ?FrontendEnforcementService $enforcementService = null;

    private ?SiteConfigurationService $siteConfigService = null;

    private ?ChallengeService $challengeService = null;

    /**
     * Decoded passkey payload from uident, cached per request.
     *
     * @var array{assertion: string, challengeToken: string}|array{_token_authenticated: true}|null|false false = not yet parsed
     */
    private array|false|null $passkeyPayload = false;

    public function getUser(): array|false
    {
        $loginData = $this->login;
        $rawUsername = $loginData['uname'] ?? '';
        $username = \is_string($rawUsername) ? $rawUsername : '';

        // Check for token-based passkey login (pre-verified by eID endpoint)
        $tokenUid = $this->resolvePasskeyToken();
        if ($tokenUid > 0) {
            $this->getLogger()->info('FE passkey token login', ['fe_user_uid' => $tokenUid]);
            $user = $this->fetchUserByUid($tokenUid);
            if (\is_array($user)) {
                // Mark as token-authenticated so authUser() knows to return 200
                $this->passkeyPayload = ['_token_authenticated' => true];
                return $user;
            }
            return false;
        }

        $payload = $this->getPasskeyPayload();
        if ($payload === null) {
            // Not a passkey login - let other services handle it
            return false;
        }

        $this->getLogger()->info('FE passkey login attempt', [
            'username' => $username,
            'assertion_length' => \strlen($payload['assertion'] ?? ''),
        ]);

        if ($username === '' || $username === '__passkey__') {
            // Discoverable login: resolve user from credential ID in the assertion
            $feUserUid = $this->getWebAuthnService()->findFeUserUidFromAssertion($payload['assertion'] ?? '');
            if ($feUserUid === null) {
                $this->getLogger()->info('Discoverable login: could not resolve user from assertion');
                return false;
            }

            $user = $this->fetchUserByUid($feUserUid);
            if (!\is_array($user)) {
                $this->getLogger()->info('Discoverable login: user not found for resolved UID', [
                    'fe_user_uid' => $feUserUid,
                ]);
                return false;
            }

            return $user;
        }

        // Username-first: look up the user via standard TYPO3 mechanism
        $user = $this->fetchUserRecord($username);
        if (!\is_array($user)) {
            // Don't reveal whether user exists
            $this->getLogger()->info('FE passkey login attempt for unknown user', [
                'username_hash' => \hash('sha256', $username),
            ]);
            return false;
        }

        // Check lockout before returning user for authUser
        $rawIp = GeneralUtility::getIndpEnv('REMOTE_ADDR');
        $ip = \is_string($rawIp) ? $rawIp : '';

        try {
            $this->getRateLimiterService()->checkLockout($username, $ip);
        } catch (Throwable) {
            $this->getLogger()->warning('FE passkey login blocked: account locked out', [
                'username_hash' => \hash('sha256', $username),
            ]);
            return false;
        }

        return $user;
    }

    public function authUser(array $user): int
    {
        // Check for token-based passkey login first (pre-verified by eID).
        // NOTE: getUser() and authUser() run on DIFFERENT service instances
        // (TYPO3 creates new instances via makeInstanceService), so we cannot
        // rely on instance properties set in getUser().
        $tokenUid = $this->resolvePasskeyToken();
        if ($tokenUid > 0 && $tokenUid === (\is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0)) {
            $this->getLogger()->info('FE passkey token auth accepted', [
                'fe_user_uid' => $tokenUid,
            ]);

            // Now consume the token (one-time use)
            try {
                $rawUident = $this->login['uident'] ?? '';
                $data = \json_decode(\is_string($rawUident) ? $rawUident : '', true);
                $token = \is_array($data) ? ($data['token'] ?? '') : '';
                if (\is_string($token) && $token !== '') {
                    GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)
                        ->getCache('nr_passkeys_fe_nonce')
                        ->remove('passkey_login_' . $token);
                }
            } catch (Throwable) {
                // Token cleanup failure is non-critical
            }

            return 200;
        }

        $payload = $this->getPasskeyPayload();
        if ($payload === null) {
            // Not a passkey login attempt - check enforcement for password login
            return $this->handlePasswordLogin($user);
        }

        $rawUname = $this->login['uname'] ?? '';
        $username = \is_string($rawUname) ? $rawUname : '';
        $rawIp = GeneralUtility::getIndpEnv('REMOTE_ADDR');
        $ip = \is_string($rawIp) ? $rawIp : '';

        try {
            // Check lockout (may have changed between getUser and authUser)
            $this->getRateLimiterService()->checkLockout($username, $ip);

            // Resolve site for WebAuthn verification
            $site = $this->resolveSite();
            if ($site === null) {
                $this->getLogger()->warning('FE passkey auth failed: no site context available');
                return 0;
            }

            // Verify the challenge token HMAC/nonce/expiry and extract raw challenge
            try {
                $rawChallenge = $this->getChallengeService()->verifyChallengeToken($payload['challengeToken']);
            } catch (RuntimeException $e) {
                $this->getLogger()->warning('FE passkey auth: invalid challenge token', [
                    'error' => $e->getMessage(),
                ]);
                return 0;
            }

            // Verify the assertion — challenge must be hex-encoded to match the
            // format used when creating assertion options in the eID endpoint.
            $result = $this->getWebAuthnService()->verifyAssertionResponse(
                assertionJson: $payload['assertion'],
                challenge: \bin2hex($rawChallenge),
                site: $site,
            );

            // Clear lockout on success
            $this->getRateLimiterService()->recordSuccess($username, $ip);

            $this->getLogger()->info('FE passkey authentication successful', [
                'fe_user_uid' => $user['uid'] ?? 0,
                'username' => $username,
                'credential_uid' => $result['credential']->getUid(),
            ]);

            // Mark session as passkey-authenticated so middleware knows to skip enrollment
            $this->setFeUserSessionKey('nr_passkeys_fe_passkey_authenticated', true);

            // Return 200 = authenticated, stop further auth processing
            return 200;
        } catch (Throwable $e) {
            $this->getRateLimiterService()->recordFailure($username, $ip);

            $this->getLogger()->warning('FE passkey authentication failed', [
                'fe_user_uid' => $user['uid'] ?? 0,
                'username' => $username,
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'ip' => $ip,
            ]);

            // Return 0 = authentication failed
            return 0;
        }
    }

    /**
     * Handle password login: check enforcement rules and tag for enrollment.
     *
     * @param array<string, mixed> $user
     */
    private function handlePasswordLogin(array $user): int
    {
        $uid = \is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0;

        // Check enforcement: block password login when enforced and user has passkeys
        if ($uid > 0) {
            $site = $this->resolveSite();
            if ($site !== null) {
                $siteIdentifier = $this->getSiteConfigService()->getSiteIdentifier($site);
                $status = $this->getEnforcementService()->getStatus($uid, $siteIdentifier, $site);

                if ($status->passkeyCount > 0 && $status->effectiveLevel === 'enforced') {
                    $this->getLogger()->warning('FE password login blocked by enforcement', [
                        'fe_user_uid' => $uid,
                        'username' => $user['username'] ?? '',
                    ]);

                    return 0;
                }

                // Tag session for enrollment interstitial on password login
                if ($status->effectiveLevel !== 'off') {
                    $this->setFeUserSessionKey('nr_passkeys_fe_needs_enrollment', true);
                }
            }
        }

        // Return 100 = continue chain, let password service handle it
        return 100;
    }

    /**
     * Check if the uident contains a passkey login token (pre-verified by eID).
     *
     * Returns the fe_user UID if a valid token is found, null otherwise.
     * The token is consumed (deleted) on use to prevent replay.
     */
    /**
     * @return int 0 = no valid token found
     */
    private function resolvePasskeyToken(): int
    {
        $rawUident = $this->login['uident'] ?? '';
        $uident = \is_string($rawUident) ? $rawUident : '';
        if ($uident === '' || $uident[0] !== '{') {
            return 0;
        }

        try {
            $data = \json_decode($uident, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 0;
        }

        if (!\is_array($data) || ($data['_type'] ?? '') !== 'passkey_token') {
            return 0;
        }

        $token = $data['token'] ?? '';
        if (!\is_string($token) || $token === '') {
            return 0;
        }

        try {
            $cache = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)
                ->getCache('nr_passkeys_fe_nonce');
            $cacheKey = 'passkey_login_' . $token;

            $feUserUid = $cache->get($cacheKey);
            if ($feUserUid === false) {
                $this->getLogger()->warning('FE passkey token not found or expired');
                return 0;
            }

            // Do NOT remove the token here — authUser() runs on a DIFFERENT
            // service instance and needs to read the same token. The 120s TTL
            // ensures automatic expiry. The token is consumed after authUser().

            $uid = \is_numeric($feUserUid) ? (int) $feUserUid : 0;
            return $uid;
        } catch (Throwable $e) {
            $this->getLogger()->warning('FE passkey token resolution failed', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Extract and validate the passkey payload from the uident login field.
     *
     * The JS packs assertion + challengeToken into userident as JSON:
     * {"_type":"passkey","assertion":{...},"challengeToken":"..."}
     *
     * @return array{assertion: string, challengeToken: string}|null
     */
    private function getPasskeyPayload(): ?array
    {
        if ($this->passkeyPayload !== false) {
            // Token-authenticated payloads are handled by resolvePasskeyToken()
            if (\is_array($this->passkeyPayload) && \array_key_exists('_token_authenticated', $this->passkeyPayload)) {
                return null;
            }

            /** @var array{assertion: string, challengeToken: string}|null */
            $cached = $this->passkeyPayload;

            return $cached;
        }

        $this->passkeyPayload = null;

        $rawUident = $this->login['uident'] ?? '';
        $uident = \is_string($rawUident) ? $rawUident : '';
        if ($uident === '' || $uident[0] !== '{') {
            return null;
        }

        try {
            $data = \json_decode($uident, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($data) || ($data['_type'] ?? '') !== 'passkey') {
            return null;
        }

        $assertion = $data['assertion'] ?? null;
        $challengeToken = $data['challengeToken'] ?? null;

        if (!\is_array($assertion) || !\is_string($challengeToken) || $challengeToken === '') {
            $this->getLogger()->warning('FE passkey payload has invalid structure');
            return null;
        }

        $this->passkeyPayload = [
            'assertion' => \json_encode($assertion, JSON_THROW_ON_ERROR),
            'challengeToken' => $challengeToken,
        ];

        return $this->passkeyPayload;
    }

    /**
     * Fetch an fe_users record by UID for discoverable login.
     *
     * @return array<string, mixed>|false
     */
    private function fetchUserByUid(int $uid): array|false
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('fe_users');

        $row = $queryBuilder
            ->select('*')
            ->from('fe_users')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('disable', 0),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : false;
    }

    /**
     * Resolve the current site from TYPO3 request context.
     *
     * During frontend authentication, the site is available via $GLOBALS['TYPO3_REQUEST'].
     * Falls back to SiteFinder when the site attribute is not yet set (auth runs
     * before SiteResolver middleware in the middleware stack).
     */
    private function resolveSite(): ?SiteInterface
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        // Try request attribute first (set by SiteResolver middleware)
        $siteAttr = $request->getAttribute('site');
        if ($siteAttr instanceof SiteInterface) {
            return $siteAttr;
        }

        // Fallback: resolve site by matching request host against configured sites
        try {
            $siteFinder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Site\SiteFinder::class);
            $host = $request->getUri()->getHost();

            foreach ($siteFinder->getAllSites() as $site) {
                $siteHost = \parse_url((string) $site->getBase(), PHP_URL_HOST);
                if ($siteHost === $host) {
                    return $site;
                }
            }
        } catch (Throwable) {
            // SiteFinder not available in unit tests or early bootstrap
        }

        return null;
    }

    /**
     * Set a session key on the frontend user session.
     *
     * Uses the frontend.user request attribute when available.
     */
    private function setFeUserSessionKey(string $key, mixed $value): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return;
        }

        $feUserAuth = $request->getAttribute('frontend.user');
        if ($feUserAuth instanceof \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication) {
            $feUserAuth->setKey('ses', $key, $value);
        }
    }

    private function getWebAuthnService(): FrontendWebAuthnService
    {
        if ($this->webAuthnService === null) {
            $this->webAuthnService = GeneralUtility::makeInstance(FrontendWebAuthnService::class);
        }

        return $this->webAuthnService;
    }

    private function getRateLimiterService(): RateLimiterService
    {
        if ($this->rateLimiterService === null) {
            $this->rateLimiterService = GeneralUtility::makeInstance(RateLimiterService::class);
        }

        return $this->rateLimiterService;
    }

    private function getEnforcementService(): FrontendEnforcementService
    {
        if ($this->enforcementService === null) {
            $this->enforcementService = GeneralUtility::makeInstance(FrontendEnforcementService::class);
        }

        return $this->enforcementService;
    }

    private function getSiteConfigService(): SiteConfigurationService
    {
        if ($this->siteConfigService === null) {
            $this->siteConfigService = GeneralUtility::makeInstance(SiteConfigurationService::class);
        }

        return $this->siteConfigService;
    }

    private function getChallengeService(): ChallengeService
    {
        if ($this->challengeService === null) {
            $this->challengeService = GeneralUtility::makeInstance(ChallengeService::class);
        }

        return $this->challengeService;
    }

    private function getLogger(): \Psr\Log\LoggerInterface
    {
        if ($this->logger === null) {
            try {
                $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(static::class));
            } catch (Throwable) {
                $this->setLogger(new NullLogger());
            }
        }

        \assert($this->logger instanceof \Psr\Log\LoggerInterface);

        return $this->logger;
    }
}
