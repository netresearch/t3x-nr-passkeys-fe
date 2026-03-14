<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\EventListener;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

/**
 * Injects passkey enrollment banner into FE page responses.
 *
 * After cacheable content is generated, checks whether the authenticated
 * frontend user should see a passkey enrollment nudge and injects an HTML
 * banner snippet before the closing </body> tag. The banner is:
 * - Shown for `encourage` or `required` (in grace period) enforcement levels
 * - Dismissible for `encourage`, non-dismissible for `required`/`enforced`
 * - Skipped for `off`, when user already has passkeys, or when the
 *   enrollment banner feature is disabled in configuration
 *
 * The banner disables the page cache for the response to avoid serving
 * user-specific HTML to other visitors.
 */
#[AsEventListener(identifier: 'nr-passkeys-fe/inject-passkey-banner')]
final readonly class InjectPasskeyBanner
{
    public function __construct(
        private FrontendEnforcementService $enforcementService,
        private FrontendCredentialRepository $credentialRepository,
        private SiteConfigurationService $siteConfigurationService,
        private FrontendConfiguration $frontendConfiguration,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        // Feature disabled → skip
        if (!$this->frontendConfiguration->isEnrollmentBannerEnabled()) {
            return;
        }

        $request = $event->getRequest();

        // User not authenticated → skip
        $feUser = $request->getAttribute('frontend.user');
        if (!$feUser instanceof FrontendUserAuthentication) {
            return;
        }

        $userRow = $feUser->user;
        if (!\is_array($userRow) || empty($userRow['uid'])) {
            return;
        }

        $feUserUid = (int) $userRow['uid'];

        // User already has passkeys → skip
        if ($this->credentialRepository->countByFeUser($feUserUid) > 0) {
            return;
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof SiteInterface) {
            return;
        }

        $siteIdentifier = $this->siteConfigurationService->getSiteIdentifier($site);
        $status = $this->enforcementService->getStatus($feUserUid, $siteIdentifier, $site);
        $effectiveLevel = $status->effectiveLevel;

        // Only show banner for encourage, required (in grace), or enforced
        if ($effectiveLevel === 'off') {
            return;
        }

        $isDismissible = $effectiveLevel === 'encourage';
        $enrollmentUrl = $this->resolveEnrollmentUrl($site);

        $banner = $this->renderBanner($effectiveLevel, $isDismissible, $enrollmentUrl, $status->graceDeadline);

        if ($banner === '') {
            return;
        }

        // Inject banner before </body> and disable caching (user-specific content)
        $content = $event->getContent();
        $content = \str_ireplace('</body>', $banner . '</body>', $content);
        $event->setContent($content);
        $event->disableCaching();
    }

    private function resolveEnrollmentUrl(SiteInterface $site): string
    {
        return $this->siteConfigurationService->getEnrollmentPageUrl($site);
    }

    /**
     * Render the banner HTML snippet.
     */
    private function renderBanner(
        string $level,
        bool $isDismissible,
        string $enrollmentUrl,
        ?\DateTimeImmutable $graceDeadline,
    ): string {
        $enrollmentUrlEscaped = \htmlspecialchars($enrollmentUrl, ENT_QUOTES, 'UTF-8');

        $title = 'Secure your account with a passkey';
        $description = 'Passkeys let you sign in faster and more securely using your device\'s biometrics or security key.';

        if ($level === 'required' && $graceDeadline !== null) {
            $remainingDays = (int) $graceDeadline->diff(new \DateTimeImmutable())->days;
            $description = \sprintf(
                'You have %d day(s) left to register a passkey. Your account access will be restricted after the grace period ends.',
                \max(0, $remainingDays),
            );
        } elseif ($level === 'enforced' || $level === 'required') {
            $description = 'Passkey enrollment is required to continue accessing your account.';
        }

        $titleEscaped = \htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $descriptionEscaped = \htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

        $dismissButton = '';
        if ($isDismissible) {
            $dismissButton = '<button type="button" class="nr-passkeys-banner__dismiss" aria-label="Dismiss"'
                . ' onclick="this.closest(\'.nr-passkeys-banner\').remove();">'
                . '&times;</button>';
        }

        $enrollLink = '';
        if ($enrollmentUrl !== '') {
            $enrollLink = '<a href="' . $enrollmentUrlEscaped . '" class="nr-passkeys-banner__cta">'
                . 'Set up passkey</a>';
        }

        return <<<HTML
<div class="nr-passkeys-banner" role="alert" data-level="{$level}" style="position:fixed;bottom:0;left:0;right:0;background:#1a73e8;color:#fff;padding:12px 24px;z-index:9999;display:flex;align-items:center;gap:16px;font-family:sans-serif;font-size:14px;">
  <span class="nr-passkeys-banner__icon" aria-hidden="true">&#128274;</span>
  <div class="nr-passkeys-banner__content" style="flex:1;">
    <strong>{$titleEscaped}</strong>
    <span style="margin-left:8px;">{$descriptionEscaped}</span>
  </div>
  {$enrollLink}
  {$dismissButton}
</div>
HTML;
    }
}
