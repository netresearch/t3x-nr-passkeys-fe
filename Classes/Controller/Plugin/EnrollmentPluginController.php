<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller\Plugin;

use DateTimeImmutable;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Extbase controller for the PasskeyEnrollment frontend plugin.
 * Assigns template variables then renders Fluid; enrollment logic runs via eID/JS.
 */
final class EnrollmentPluginController extends ActionController
{
    public function __construct(
        private readonly FrontendEnforcementService $enforcementService,
    ) {}

    public function indexAction(): ResponseInterface
    {
        /** @var SiteInterface|null $site */
        $site = $this->request->getAttribute('site');
        $baseUrl = \rtrim((string) ($site?->getBase() ?? ''), '/');
        $eidUrl = $baseUrl . '/?eID=nr_passkeys_fe';

        // The same status the post-login interstitial and the banner act on.
        // A user who holds a passkey has nothing left to do here.
        $status = $this->resolveStatus($site);
        $enrollmentRequired = false;
        $graceDaysRemaining = 0;
        if ($status instanceof FrontendEnforcementStatus && $status->passkeyCount === 0) {
            // Enrollment cannot be put off: enforced, or required with no
            // grace period left to run.
            $enrollmentRequired = $status->effectiveLevel === 'enforced'
                || ($status->effectiveLevel === 'required' && !$status->inGracePeriod);
            $graceDaysRemaining = $status->graceDaysRemaining(new DateTimeImmutable());
        }

        $this->view->assignMultiple([
            'eidUrl' => $eidUrl,
            'siteIdentifier' => $site?->getIdentifier() ?? '',
            'registerOptionsUrl' => $eidUrl . '&action=registrationOptions',
            'registerVerifyUrl' => $eidUrl . '&action=registrationVerify',
            'enrollmentRequired' => $enrollmentRequired,
            'gracePeriodDaysRemaining' => $graceDaysRemaining,
        ]);

        return $this->htmlResponse();
    }

    private function resolveStatus(?SiteInterface $site): ?FrontendEnforcementStatus
    {
        $feUser = $this->request->getAttribute('frontend.user');
        if (!$site instanceof SiteInterface || !$feUser instanceof FrontendUserAuthentication) {
            return null;
        }

        $userRow = $feUser->user;
        $feUserUid = \is_array($userRow) && \is_numeric($userRow['uid'] ?? null) ? (int) $userRow['uid'] : 0;
        if ($feUserUid <= 0) {
            return null;
        }

        $status = $this->enforcementService->getStatus($feUserUid, $site->getIdentifier(), $site);

        // The post-login interstitial starts the grace period on its first
        // redirect, but passes the enrollment page itself through before it
        // gets there. A user who arrives here first gets it started here, by
        // the same service call, so the page shows the days that now run.
        if ($status->effectiveLevel === 'required'
            && $status->passkeyCount === 0
            && !$status->inGracePeriod
            && $status->graceDays > 0
            && $this->enforcementService->startGracePeriod($feUserUid)) {
            return $this->enforcementService->getStatus($feUserUid, $site->getIdentifier(), $site);
        }

        return $status;
    }
}
