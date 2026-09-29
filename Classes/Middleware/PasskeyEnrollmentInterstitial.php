<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Middleware;

use DateTimeImmutable;
use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
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
        if (!$feUser instanceof FrontendUserAuthentication) {
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

        // Off and Encourage → pass through (the banner handles Encourage)
        if ($status->effectiveLevel === 'off' || $status->effectiveLevel === 'encourage') {
            return $handler->handle($request);
        }

        // A due grace period starts on this request, before any pass-through
        // below (no enrollment URL, the enrollment page itself): the banner
        // and the enrollment plugin render later and must both see it.
        $status = $this->withDueGracePeriodStarted($feUserUid, $siteIdentifier, $site, $status);

        // Required or Enforced: check for redirect necessity
        $enrollmentUrl = $this->resolveEnrollmentUrl($request, $site);
        if ($enrollmentUrl === '') {
            // No enrollment URL configured → pass through to avoid hard lock-out
            return $handler->handle($request);
        }

        // Avoid redirect loops: if we're already on the enrollment page, pass through
        $requestPath = $request->getUri()->getPath();
        $enrollmentPath = \parse_url($enrollmentUrl, PHP_URL_PATH);
        $onEnrollmentPage = \is_string($enrollmentPath) && $enrollmentPath !== '' && $requestPath === $enrollmentPath;

        // Required within the grace period may be skipped for the session;
        // an expired grace period and Enforced may not. The skip signal
        // previously appended as ?canSkip=1 is intentionally omitted: no
        // template or JS module consumes it.
        if (
            $onEnrollmentPage
            || ($status->effectiveLevel === 'required' && $status->inGracePeriod && $this->hasSkippedEnrollment($feUser))
        ) {
            return $handler->handle($request);
        }

        return new RedirectResponse($enrollmentUrl, 303);
    }

    /**
     * Start the grace period of a Required user who has none yet, and return
     * the status as it is now. It is read again whether or not this request
     * wrote the start: another request may have written it in between.
     */
    private function withDueGracePeriodStarted(
        int $feUserUid,
        string $siteIdentifier,
        SiteInterface $site,
        FrontendEnforcementStatus $status,
    ): FrontendEnforcementStatus {
        if ($status->effectiveLevel !== 'required' || !$this->hasGracePeriodConfigured($status)) {
            return $status;
        }

        $this->enforcementService->startGracePeriod($feUserUid);

        return $this->enforcementService->getStatus($feUserUid, $siteIdentifier, $site);
    }

    private function hasSkippedEnrollment(FrontendUserAuthentication $feUser): bool
    {
        $sessionData = $feUser->getKey('ses', self::SESSION_KEY);

        return \is_array($sessionData) && ($sessionData['enrollment_skipped'] ?? false) === true;
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

    /**
     * Check if the status indicates a grace period is configured (days > 0) but
     * not yet started (graceDeadline is null and not in grace period).
     */
    private function hasGracePeriodConfigured(FrontendEnforcementStatus $status): bool
    {
        return !$status->inGracePeriod
            && !$status->graceDeadline instanceof DateTimeImmutable
            && $status->graceDays > 0;
    }
}
