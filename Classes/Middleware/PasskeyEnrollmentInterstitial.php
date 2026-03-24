<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Middleware;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Intercepts frontend requests to enforce passkey enrollment.
 *
 * After the TYPO3 authentication middleware has run, checks whether the
 * logged-in frontend user must enroll a passkey based on their enforcement
 * policy. Redirects to the enrollment page when required, with or without
 * a skip option depending on enforcement level and grace period status.
 *
 * Exempt cases (pass through):
 * - No authenticated frontend user
 * - User already has passkeys
 * - postLoginEnrollmentEnabled is false
 * - Request is an eID request for nr_passkeys_fe (prevents redirect loops)
 * - Enforcement level is Off or Encourage
 * - Required + in grace period + session skip flag set
 */
final readonly class PasskeyEnrollmentInterstitial implements MiddlewareInterface
{
    private const SESSION_KEY = 'tx_nrpasskeysfe';
    private const EID = 'nr_passkeys_fe';

    public function __construct(
        private FrontendEnforcementService $enforcementService,
        private FrontendCredentialRepository $credentialRepository,
        private SiteConfigurationService $siteConfigurationService,
        private FrontendConfiguration $frontendConfiguration,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. User not authenticated → pass through
        $feUser = $this->resolveFrontendUser($request);
        if ($feUser === null) {
            return $handler->handle($request);
        }

        $userRow = $feUser->user;
        if (!\is_array($userRow)) {
            return $handler->handle($request);
        }

        $rawUid = $userRow['uid'] ?? null;
        $feUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;
        if ($feUserUid === 0) {
            return $handler->handle($request);
        }

        // 2. User already has passkeys → pass through
        if ($this->credentialRepository->countByFeUser($feUserUid) > 0) {
            return $handler->handle($request);
        }

        // 3. postLoginEnrollmentEnabled is false → pass through
        if (!$this->frontendConfiguration->isPostLoginEnrollmentEnabled()) {
            return $handler->handle($request);
        }

        // 4. eID request for our extension → pass through (exempt, prevent loops)
        if ($this->isOurEidRequest($request)) {
            return $handler->handle($request);
        }

        // Also exempt if the public route attribute was set by PasskeyPublicRouteResolver
        if ($request->getAttribute('nr_passkeys_fe.public_route') === true) {
            return $handler->handle($request);
        }

        // Get the current site
        $site = $request->getAttribute('site');
        if (!$site instanceof SiteInterface) {
            return $handler->handle($request);
        }

        $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);

        // 5-9. Check enforcement status
        $status = $this->enforcementService->getStatus($feUserUid, $siteIdentifier, $site);
        $effectiveLevel = $status->effectiveLevel;

        // Off → pass through
        if ($effectiveLevel === 'off') {
            return $handler->handle($request);
        }

        // Encourage → pass through (banner handles it via InjectPasskeyBanner)
        if ($effectiveLevel === 'encourage') {
            return $handler->handle($request);
        }

        // Required or Enforced: check for redirect necessity
        $enrollmentUrl = $this->resolveEnrollmentUrl($request, $site);
        if ($enrollmentUrl === '') {
            // No enrollment URL configured → pass through to avoid hard lock-out
            return $handler->handle($request);
        }

        // Avoid redirect loops: if we're already on the enrollment page, pass through
        $requestPath = $request->getUri()->getPath();
        $enrollmentPath = \parse_url($enrollmentUrl, PHP_URL_PATH);
        if (\is_string($enrollmentPath) && $enrollmentPath !== '' && $requestPath === $enrollmentPath) {
            return $handler->handle($request);
        }

        // Session data for skip logic
        $sessionData = $feUser->getKey('ses', self::SESSION_KEY);
        $sessionArray = \is_array($sessionData) ? $sessionData : [];

        if ($effectiveLevel === 'required') {
            // If grace period has started and user is still within it, allow skip
            if ($status->inGracePeriod) {
                // Check session skip flag
                if (($sessionArray['enrollment_skipped'] ?? false) === true) {
                    return $handler->handle($request);
                }

                // Redirect with skip option
                return new RedirectResponse(
                    $this->appendQueryParam($enrollmentUrl, 'canSkip', '1'),
                    303,
                );
            }

            // Grace period expired or not started → start grace period if not started
            if ($status->graceDeadline === null && $this->hasGracePeriodConfigured($status)) {
                $this->enforcementService->startGracePeriod($feUserUid);
                // After starting, allow skip this first time
                if (($sessionArray['enrollment_skipped'] ?? false) === true) {
                    return $handler->handle($request);
                }
            }

            // Grace expired → redirect, no skip
            return new RedirectResponse($enrollmentUrl, 303);
        }

        // Enforced → redirect, no skip
        return new RedirectResponse($enrollmentUrl, 303);
    }

    private function resolveFrontendUser(ServerRequestInterface $request): ?FrontendUserAuthentication
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            return null;
        }

        // Check that user is actually logged in
        $userRow = $frontendUser->user;
        if (!\is_array($userRow) || empty($userRow['uid'])) {
            return null;
        }

        return $frontendUser;
    }

    private function isOurEidRequest(ServerRequestInterface $request): bool
    {
        $queryParams = $request->getQueryParams();
        return ($queryParams['eID'] ?? null) === self::EID;
    }

    /**
     * Resolve the enrollment page URL from site settings.
     *
     * Delegates to SiteConfigurationService which reads
     * `nr_passkeys_fe.enrollmentPageUrl` from site settings.
     * Falls back to an empty string when not configured.
     */
    private function resolveEnrollmentUrl(ServerRequestInterface $request, SiteInterface $site): string
    {
        return $this->siteConfigurationService->getEnrollmentPageUrl($site);
    }

    private function appendQueryParam(string $url, string $key, string $value): string
    {
        $separator = \str_contains($url, '?') ? '&' : '?';
        return $url . $separator . \urlencode($key) . '=' . \urlencode($value);
    }

    /**
     * Check if the status indicates a grace period is configured (days > 0) but
     * not yet started (graceDeadline is null and not in grace period).
     */
    private function hasGracePeriodConfigured(\Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus $status): bool
    {
        return !$status->inGracePeriod
            && $status->graceDeadline === null
            && $status->graceDays > 0;
    }
}
