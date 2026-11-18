<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Middleware;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\FrontendLoginAssurance;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

use function is_array;
use function is_numeric;

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
    private const EID = 'nr_passkeys_fe';

    public function __construct(
        private FrontendEnforcementService $enforcementService,
        private FrontendCredentialRepository $credentialRepository,
        private SiteConfigurationService $siteConfigurationService,
        private FrontendConfiguration $frontendConfiguration,
        private ?FrontendLoginAssurance $loginAssurance = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. User not authenticated → pass through
        $feUser = $this->resolveFrontendUser($request);
        if (!$feUser instanceof FrontendUserAuthentication) {
            return $handler->handle($request);
        }

        // Core may omit AfterUserLoggedInEvent for accounts without frontend groups.
        // Only an active login observed on this request has a pending proof.
        $this->loginAssurance?->completeForRequest($feUser, $request);
        $userRow = $feUser->user;
        if (!is_array($userRow)) {
            return $handler->handle($request);
        }

        $rawUid    = $userRow['uid'] ?? null;
        $feUserUid = is_numeric($rawUid) ? (int) $rawUid : 0;
        if ($feUserUid === 0) {
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
        if (!$site instanceof Site) {
            return new HtmlResponse('Login policy is unavailable. Please contact support.', 503, ['Cache-Control' => 'private, no-store']);
        }

        $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);

        if ($this->credentialRepository->countByFeUser($feUserUid, $siteIdentifier) > 0) {
            return $handler->handle($request);
        }

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
        $optional      = $status->effectiveLevel === 'required' && $status->inGracePeriod;
        if ($optional && !$this->frontendConfiguration->isPostLoginEnrollmentEnabled()) {
            return $handler->handle($request);
        }

        $uri        = $request->getUri();
        $target     = new Uri($enrollmentUrl);
        $absolute   = $target->getScheme() !== '' || $target->getAuthority() !== '';
        $sameOrigin = $target->getScheme() === $uri->getScheme() && $target->getAuthority() === $uri->getAuthority();
        if ($enrollmentUrl === '' || !str_starts_with($target->getPath(), '/') || ($absolute && !$sameOrigin)) {
            if ($optional) {
                return $handler->handle($request);
            }

            return new HtmlResponse('Passkey enrollment is unavailable. Please contact support.', 503, ['Cache-Control' => 'private, no-store']);
        }

        $onEnrollmentPage = $target->getPath() === $uri->getPath() && $target->getQuery() === $uri->getQuery();

        // Required within the grace period may be skipped for the session;
        // an expired grace period and Enforced may not. The skip signal
        // previously appended as ?canSkip=1 is intentionally omitted: no
        // template or JS module consumes it.
        if (
            $onEnrollmentPage
            || ($status->effectiveLevel === 'required' && $status->inGracePeriod && $this->hasSkippedEnrollment($feUser, $siteIdentifier))
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
        if ($status->effectiveLevel !== 'required' || !$this->isGracePeriodDue($status)) {
            return $status;
        }

        $this->enforcementService->startGracePeriod($feUserUid);

        return $this->enforcementService->getStatus($feUserUid, $siteIdentifier, $site);
    }

    private function hasSkippedEnrollment(FrontendUserAuthentication $feUser, string $siteIdentifier): bool
    {
        $sessionData = $feUser->getKey('ses', 'nr_passkeys_fe_skip_enrollment');

        return $sessionData === $siteIdentifier;
    }

    private function resolveFrontendUser(ServerRequestInterface $request): ?FrontendUserAuthentication
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            return null;
        }

        // Check that user is actually logged in
        $userRow = $frontendUser->user;
        if (!is_array($userRow) || empty($userRow['uid'])) {
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
     * A grace period is configured (days > 0) and no start is stored. A
     * grace period that is over has a start and is not started again, so
     * such a user costs neither a write nor a second status read.
     */
    private function isGracePeriodDue(FrontendEnforcementStatus $status): bool
    {
        return $status->graceDays > 0 && !$status->gracePeriodStarted;
    }
}
