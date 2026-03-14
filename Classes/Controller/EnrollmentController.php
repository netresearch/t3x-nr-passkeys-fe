<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Handles the post-login enrollment interstitial for frontend users.
 *
 * Both methods require an authenticated FE user (enforced by EidDispatcher).
 *
 * - statusAction: Returns the effective enforcement status for the current user
 * - skipAction:   Sets a session flag to skip the enrollment prompt for this session
 */
final class EnrollmentController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly FrontendEnforcementService $enforcementService,
        private readonly SiteConfigurationService $siteConfigurationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Return the enforcement status for the authenticated frontend user.
     *
     * GET ?eID=nr_passkeys_fe&action=enrollmentStatus
     * Requires authenticated FE user (enforced by EidDispatcher).
     */
    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        $feUser = $request->getAttribute('frontend.user');
        $feUserUid = (int) ($feUser->user['uid'] ?? 0);

        try {
            $site = $this->siteConfigurationService->getCurrentSite($request);
            $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);
        } catch (Throwable $e) {
            $this->logger->error('Enrollment status: no site context', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Internal error'], 500);
        }

        try {
            $status = $this->enforcementService->getStatus($feUserUid, $siteIdentifier, $site);
        } catch (Throwable $e) {
            $this->logger->error('Enrollment status: failed to resolve enforcement', [
                'fe_user_uid' => $feUserUid,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal error'], 500);
        }

        $graceDeadlineTs = $status->graceDeadline !== null
            ? $status->graceDeadline->getTimestamp()
            : null;

        return new JsonResponse([
            'effectiveLevel' => $status->effectiveLevel,
            'siteLevel' => $status->siteLevel,
            'groupLevel' => $status->groupLevel,
            'passkeyCount' => $status->passkeyCount,
            'inGracePeriod' => $status->inGracePeriod,
            'graceDeadline' => $graceDeadlineTs,
            'recoveryCodesRemaining' => $status->recoveryCodesRemaining,
        ]);
    }

    /**
     * Skip the enrollment prompt for this session.
     *
     * POST ?eID=nr_passkeys_fe&action=enrollmentSkip
     * Body: { "nonce": "..." }
     * Requires authenticated FE user (enforced by EidDispatcher).
     *
     * Sets a session flag so the enrollment interstitial middleware won't
     * redirect the user again during this session. Validates a one-time nonce
     * from the body to prevent CSRF-style skip requests.
     */
    public function skipAction(ServerRequestInterface $request): ResponseInterface
    {
        $feUser = $request->getAttribute('frontend.user');
        $feUserUid = (int) ($feUser->user['uid'] ?? 0);

        $body = $this->getJsonBody($request);
        $nonce = isset($body['nonce']) && \is_string($body['nonce']) ? $body['nonce'] : '';

        if ($nonce === '') {
            return new JsonResponse(['error' => 'Missing nonce'], 400);
        }

        // Validate the nonce against what the middleware stored in the session
        $expectedNonce = $this->getSessionNonce($request);
        if ($expectedNonce === '' || !\hash_equals($expectedNonce, $nonce)) {
            $this->logger->warning('Enrollment skip: invalid nonce', [
                'fe_user_uid' => $feUserUid,
            ]);
            return new JsonResponse(['error' => 'Invalid nonce'], 403);
        }

        // Set the skip flag for this session
        $this->setFeUserSessionKey('nr_passkeys_fe_skip_enrollment', true);

        $this->logger->info('FE enrollment prompt skipped', [
            'fe_user_uid' => $feUserUid,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Retrieve the enrollment nonce stored in the current FE session.
     */
    private function getSessionNonce(ServerRequestInterface $request): string
    {
        try {
            $tsfe = $GLOBALS['TSFE'] ?? null;
            if (\is_object($tsfe) && \property_exists($tsfe, 'fe_user') && \is_object($tsfe->fe_user)) {
                $value = $tsfe->fe_user->getKey('ses', 'nr_passkeys_fe_enrollment_nonce');
                return \is_string($value) ? $value : '';
            }
        } catch (Throwable) {
            // Fall through
        }

        return '';
    }

    /**
     * Set a session key on the frontend user session.
     */
    private function setFeUserSessionKey(string $key, mixed $value): void
    {
        try {
            $tsfe = $GLOBALS['TSFE'] ?? null;
            if (\is_object($tsfe) && \property_exists($tsfe, 'fe_user') && \is_object($tsfe->fe_user)) {
                $tsfe->fe_user->setKey('ses', $key, $value);
            }
        } catch (Throwable) {
            // Non-critical
        }
    }
}
