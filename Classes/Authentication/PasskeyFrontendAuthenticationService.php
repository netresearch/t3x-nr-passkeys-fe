<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Authentication;

use JsonException;
use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

use function array_key_exists;
use function assert;
use function bin2hex;
use function hash;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;

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
     * @var array{assertion: string, challengeToken: string}|array{_token_authenticated: true}|false|null false = not yet parsed
     */
    private array|false|null $passkeyPayload = false;

    public function getUser(): array|false
    {
        $loginData   = $this->login;
        $rawUsername = $loginData['uname'] ?? '';
        $username    = is_string($rawUsername) ? $rawUsername : '';

        // Check for token-based passkey login (pre-verified by eID endpoint)
        $ticket   = $this->resolvePasskeyToken();
        $tokenUid = $ticket['uid'] ?? 0;
        if ($tokenUid > 0) {
            $this->getLogger()->info('FE passkey token login', ['fe_user_uid' => $tokenUid]);
            $user = $this->fetchUserByUid($tokenUid);
            if (is_array($user)) {
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
            'username_hash'    => hash('sha256', $username),
            'assertion_length' => strlen($payload['assertion'] ?? ''),
        ]);

        if ($username === '' || $username === '__passkey__') {
            // Discoverable login: resolve user from credential ID in the assertion
            $site = $this->resolveSite();
            if ($site === null) {
                return false;
            }
            $feUserUid = $this->getWebAuthnService()->findFeUserUidFromAssertion($payload['assertion'] ?? '', $site);
            if ($feUserUid === null) {
                $this->getLogger()->info('Discoverable login: could not resolve user from assertion');

                return false;
            }

            $user = $this->fetchUserByUid($feUserUid);
            if (!is_array($user)) {
                $this->getLogger()->info('Discoverable login: user not found for resolved UID', [
                    'fe_user_uid' => $feUserUid,
                ]);

                return false;
            }

            return $user;
        }

        // Username-first: look up the user via standard TYPO3 mechanism
        $user = $this->fetchUserRecord($username);
        if (!is_array($user)) {
            // Don't reveal whether user exists
            $this->getLogger()->info('FE passkey login attempt for unknown user', [
                'username_hash' => hash('sha256', $username),
            ]);

            return false;
        }

        // Check lockout before returning user for authUser
        $ip = $this->getRemoteAddress();

        try {
            $this->getRateLimiterService()->checkLockout((string) (is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0), $ip);
        } catch (Throwable) {
            $this->getLogger()->warning('FE passkey login blocked: account locked out', [
                'username_hash' => hash('sha256', $username),
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
        $ticket   = $this->resolvePasskeyToken();
        $tokenUid = $ticket['uid'] ?? 0;
        if ($ticket !== null && $tokenUid > 0 && $tokenUid === (is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0)) {
            $site = $this->resolveSite();
            if ($site === null) {
                return 0;
            }
            $pid = GeneralUtility::makeInstance(\Netresearch\NrPasskeysFe\Service\FrontendUserLookupService::class)->ownerStoragePid($tokenUid, $site);
            if ($pid !== $ticket['storagePid'] || $this->fetchUserByUid($tokenUid) === false
                || ($ticket['method'] === 'recovery' && !SiteConfigurationService::allowsRecoveryMethod($site, 'recovery_code'))) {
                return 0;
            }
            try {
                if (!GeneralUtility::makeInstance(\Netresearch\NrPasskeysFe\Service\ConsumedTokenStore::class)->claim('login:' . $ticket['token'], $ticket['expiresAt'])) {
                    return 0;
                }
                $credential = null;
                if ($ticket['method'] === 'passkey') {
                    $credential = GeneralUtility::makeInstance(\Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository::class)->findByUidAndFeUser($ticket['credentialUid'], $tokenUid);
                    if ($credential === null || $credential->isRevoked() || $credential->getSiteIdentifier() !== $site->getIdentifier()
                        || ($credential->getStoragePid() !== 0 && $credential->getStoragePid() !== $pid)) {
                        return 0;
                    }
                }
                if ($this->pObj instanceof FrontendUserAuthentication) {
                    GeneralUtility::makeInstance(\Netresearch\NrPasskeysFe\Service\FrontendLoginAssurance::class)->verified($this->pObj, $tokenUid, $credential);
                }
                GeneralUtility::makeInstance(CacheManager::class)->getCache('nr_passkeys_fe_nonce')->remove('passkey_login_' . $ticket['token']);
            } catch (Throwable $error) {
                $this->getLogger()->error('Passkey token consumption unavailable', ['exception' => $error::class]);

                return 0;
            }

            return 200;
        }

        $payload = $this->getPasskeyPayload();
        if ($payload === null) {
            // Not a passkey login attempt - check enforcement for password login
            return $this->handlePasswordLogin($user);
        }

        $rawUname = $this->login['uname'] ?? '';
        $username = is_string($rawUname) ? $rawUname : '';
        $ip       = $this->getRemoteAddress();

        try {
            // Check lockout (may have changed between getUser and authUser)
            $this->getRateLimiterService()->checkLockout((string) (is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0), $ip);

            // Resolve site for WebAuthn verification
            $site = $this->resolveSite();
            if (!$site instanceof Site) {
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
                challenge: bin2hex($rawChallenge),
                site: $site,
            );

            $candidateUid = is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0;
            if ($candidateUid <= 0 || $result['feUserUid'] !== $candidateUid) {
                $this->getLogger()->warning('FE passkey credential does not belong to the login candidate');

                return 0;
            }

            // Clear lockout on success
            $this->getRateLimiterService()->recordSuccess((string) $candidateUid, $ip);

            $this->getLogger()->info('FE passkey authentication successful', [
                'fe_user_uid'    => $user['uid'] ?? 0,
                'username_hash'  => hash('sha256', $username),
                'credential_uid' => $result['credential']->getUid(),
            ]);

            // Mark session as passkey-authenticated so middleware knows to skip enrollment
            if ($this->pObj instanceof FrontendUserAuthentication) {
                GeneralUtility::makeInstance(\Netresearch\NrPasskeysFe\Service\FrontendLoginAssurance::class)->verified($this->pObj, $candidateUid, $result['credential']);
            }

            // Return 200 = authenticated, stop further auth processing
            return 200;
        } catch (Throwable $e) {
            $this->getRateLimiterService()->recordFailure((string) (is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0), $ip);

            $this->getLogger()->warning('FE passkey authentication failed', [
                'fe_user_uid'   => $user['uid'] ?? 0,
                'username_hash' => hash('sha256', $username),
                'error_code'    => $e->getCode(),
                'error_message' => $e->getMessage(),
                'ip'            => $ip,
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
        $uid = is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0;

        // Check enforcement: block password login when enforced and user has passkeys
        if ($uid > 0) {
            $site = $this->resolveSite();
            if (!$site instanceof Site) {
                $this->getLogger()->warning('FE password login refused because the site policy cannot be resolved');

                return 0;
            }

            if (!SiteConfigurationService::allowsRecoveryMethod($site, 'password')) {
                return 0;
            }
            try {
                $siteIdentifier = $this->getSiteConfigService()->getSiteIdentifier($site);
                $status = $this->getEnforcementService()->getStatus($uid, $siteIdentifier, $site);
            } catch (Throwable $error) {
                $this->getLogger()->error('Frontend login policy unavailable', ['fe_user_uid' => $uid, 'exception' => $error::class]);

                return 0;
            }

            if ($status->passkeyCount > 0 && $status->effectiveLevel === 'enforced') {
                $this->getLogger()->warning('FE password login blocked by enforcement', [
                    'fe_user_uid' => $uid,
                ]);

                return 0;
            }

            // Tag session for enrollment interstitial on password login
            if ($status->effectiveLevel !== 'off') {
                $this->setFeUserSessionKey('nr_passkeys_fe_needs_enrollment', true);
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
     * @return array{uid:int, token:string, expiresAt:int, credentialUid:int, method:string, storagePid:int}|null
     */
    private function resolvePasskeyToken(): ?array
    {
        $raw = $this->login['uident'] ?? null;
        if (!is_string($raw) || !str_starts_with($raw, '{')) {
            return null;
        }
        try {
            $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($input) || ($input['_type'] ?? '') !== 'passkey_token' || !is_string($input['token'] ?? null)) {
                return null;
            }
            $token = $input['token'];
            $site  = $this->resolveSite();
            if ($site === null || $site->getIdentifier() === '') {
                return null;
            }
            $rawTicket = GeneralUtility::makeInstance(CacheManager::class)->getCache('nr_passkeys_fe_nonce')->get('passkey_login_' . $token);
            $ticket    = is_string($rawTicket) ? json_decode($rawTicket, true, 8, JSON_THROW_ON_ERROR) : null;
            if (!is_array($ticket) || ($ticket['site'] ?? null) !== $site->getIdentifier()
                || !is_int($ticket['uid'] ?? null) || $ticket['uid'] <= 0
                || !is_int($ticket['storagePid'] ?? null) || $ticket['storagePid'] <= 0
                || !is_int($ticket['expiresAt'] ?? null) || $ticket['expiresAt'] <= time()
                || !in_array($ticket['method'] ?? null, ['passkey', 'recovery'], true)
                || !is_int($ticket['credentialUid'] ?? null)) {
                return null;
            }

            return ['uid' => $ticket['uid'], 'storagePid' => $ticket['storagePid'], 'token' => $token, 'expiresAt' => $ticket['expiresAt'], 'credentialUid' => $ticket['credentialUid'], 'method' => $ticket['method']];
        } catch (Throwable $error) {
            $this->getLogger()->warning('FE passkey token resolution failed', ['exception' => $error::class]);

            return null;
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
            if (is_array($this->passkeyPayload) && array_key_exists('_token_authenticated', $this->passkeyPayload)) {
                return null;
            }

            /** @var array{assertion: string, challengeToken: string}|null */
            $cached = $this->passkeyPayload;

            return $cached;
        }

        $this->passkeyPayload = null;

        $rawUident = $this->login['uident'] ?? '';
        $uident    = is_string($rawUident) ? $rawUident : '';
        if ($uident === '' || $uident[0] !== '{') {
            return null;
        }

        try {
            $data = json_decode($uident, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || ($data['_type'] ?? '') !== 'passkey') {
            return null;
        }

        $assertion      = $data['assertion'] ?? null;
        $challengeToken = $data['challengeToken'] ?? null;

        if (!is_array($assertion) || !is_string($challengeToken) || $challengeToken === '') {
            $this->getLogger()->warning('FE passkey payload has invalid structure');

            return null;
        }

        $this->passkeyPayload = [
            'assertion'      => json_encode($assertion, JSON_THROW_ON_ERROR),
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
        $setup = $this->db_user;
        if (($setup['table'] ?? '') !== 'fe_users' || ($setup['userid_column'] ?? '') !== 'uid') {
            return false;
        }
        $setup['username_column'] = 'uid';

        return $this->fetchUserRecord((string) $uid, '', $setup);
    }

    private function resolveSite(): ?Site
    {
        $request = $this->authInfo['request'] ?? null;
        $site    = $request instanceof ServerRequestInterface ? $request->getAttribute('site') : null;

        return $site instanceof Site ? $site : null;
    }

    /**
     * Set a session key on the frontend user session.
     *
     * Uses the frontend.user request attribute when available.
     */
    private function setFeUserSessionKey(string $key, mixed $value): void
    {
        $request = $this->authInfo['request'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return;
        }

        $feUserAuth = $request->getAttribute('frontend.user');
        if ($feUserAuth instanceof FrontendUserAuthentication) {
            $feUserAuth->setKey('ses', $key, $value);
        }
    }

    /**
     * The client address core computed for this login (authInfo['REMOTE_ADDR']
     * from AbstractUserAuthentication::getAuthInfoArray(), at 13.4 and 14.3).
     * The auth chain has no request argument, and $GLOBALS['TYPO3_REQUEST'] is
     * not reliable here; core already derived the address from the request.
     */
    private function getRemoteAddress(): string
    {
        $remoteAddress = $this->authInfo['REMOTE_ADDR'] ?? '';

        return is_string($remoteAddress) ? $remoteAddress : '';
    }

    private function getWebAuthnService(): FrontendWebAuthnService
    {
        if (!$this->webAuthnService instanceof FrontendWebAuthnService) {
            $this->webAuthnService = GeneralUtility::makeInstance(FrontendWebAuthnService::class);
        }

        return $this->webAuthnService;
    }

    private function getRateLimiterService(): RateLimiterService
    {
        if (!$this->rateLimiterService instanceof RateLimiterService) {
            $this->rateLimiterService = GeneralUtility::makeInstance(\Netresearch\NrPasskeysFe\Service\FrontendRateLimiter::class)->service;
        }

        return $this->rateLimiterService;
    }

    private function getEnforcementService(): FrontendEnforcementService
    {
        if (!$this->enforcementService instanceof FrontendEnforcementService) {
            $this->enforcementService = GeneralUtility::makeInstance(FrontendEnforcementService::class);
        }

        return $this->enforcementService;
    }

    private function getSiteConfigService(): SiteConfigurationService
    {
        if (!$this->siteConfigService instanceof SiteConfigurationService) {
            $this->siteConfigService = GeneralUtility::makeInstance(SiteConfigurationService::class);
        }

        return $this->siteConfigService;
    }

    private function getChallengeService(): ChallengeService
    {
        if (!$this->challengeService instanceof ChallengeService) {
            $this->challengeService = GeneralUtility::makeInstance(ChallengeService::class);
        }

        return $this->challengeService;
    }

    private function getLogger(): LoggerInterface
    {
        if (!$this->logger instanceof LoggerInterface) {
            try {
                $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class));
            } catch (Throwable) {
                $this->setLogger(new NullLogger());
            }
        }

        assert($this->logger instanceof LoggerInterface);

        return $this->logger;
    }
}
