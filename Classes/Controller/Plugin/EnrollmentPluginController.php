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
    private const SECONDS_PER_DAY = 86400;

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
        $status = $this->resolveStatus($site);

        $this->view->assignMultiple([
            'eidUrl' => $eidUrl,
            'siteIdentifier' => $site?->getIdentifier() ?? '',
            'registerOptionsUrl' => $eidUrl . '&action=registrationOptions',
            'registerVerifyUrl' => $eidUrl . '&action=registrationVerify',
            // Enrollment cannot be put off: enforced, or required with no
            // grace period left to run.
            'enrollmentRequired' => $status instanceof FrontendEnforcementStatus
                && ($status->effectiveLevel === 'enforced'
                    || ($status->effectiveLevel === 'required' && !$status->inGracePeriod)),
            'gracePeriodDaysRemaining' => $this->graceDaysRemaining($status),
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

        return $this->enforcementService->getStatus($feUserUid, $site->getIdentifier(), $site);
    }

    /**
     * Whole days left in a running grace period, counting a started day as a
     * day: with twelve hours left the page says one day, not zero.
     */
    private function graceDaysRemaining(?FrontendEnforcementStatus $status): int
    {
        if (!$status instanceof FrontendEnforcementStatus
            || !$status->inGracePeriod
            || !$status->graceDeadline instanceof DateTimeImmutable
        ) {
            return 0;
        }

        $secondsLeft = $status->graceDeadline->getTimestamp() - \time();

        return \max(1, (int) \ceil($secondsLeft / self::SECONDS_PER_DAY));
    }
}
