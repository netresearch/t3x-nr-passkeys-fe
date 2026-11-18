<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Event\AfterPasskeyAuthenticationEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\Event\AfterUserLoggedInEvent;
use TYPO3\CMS\Core\Authentication\Event\BeforeRequestTokenProcessedEvent;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use WeakMap;

final class FrontendLoginAssurance
{
    /** @var WeakMap<FrontendUserAuthentication, array{uid:int,method:string,credential:?FrontendCredential}> */
    private WeakMap $pending;

    public function __construct(private readonly EventDispatcherInterface $events, private readonly LoggerInterface $logger)
    {
        $this->pending = new WeakMap();
    }

    #[AsEventListener(identifier: 'nr-passkeys-fe/start-login')]
    public function start(BeforeRequestTokenProcessedEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof FrontendUserAuthentication && $user->getSession()->isAnonymous()) {
            $this->pending[$user] = ['uid' => 0, 'method' => 'primary', 'credential' => null];
        }
    }

    public function verified(FrontendUserAuthentication $user, int $uid, ?FrontendCredential $credential): void
    {
        $this->pending[$user] = ['uid' => $uid, 'method' => $credential instanceof FrontendCredential ? 'passkey' : 'recovery', 'credential' => $credential];
    }

    #[AsEventListener(identifier: 'nr-passkeys-fe/complete-login')]
    public function complete(AfterUserLoggedInEvent $event): void
    {
        $user    = $event->getUser();
        $request = $event->getRequest();
        if ($user instanceof FrontendUserAuthentication && $request instanceof ServerRequestInterface) {
            $this->completeForRequest($user, $request);
        }
    }

    public function completeForRequest(FrontendUserAuthentication $user, ServerRequestInterface $request): void
    {
        if (!isset($this->pending[$user])) {
            return;
        }

        $proof = $this->pending[$user];
        unset($this->pending[$user]);
        $site = $request->getAttribute('site');
        $uid  = $user->getUserId() ?? 0;
        if (!$site instanceof SiteInterface || $uid <= 0 || $user->getSession()->getUserId() !== $uid || ($proof['uid'] !== 0 && $proof['uid'] !== $uid)) {
            return;
        }

        $credential = $proof['credential'];
        if ($credential !== null && ($credential->getFeUser() !== $uid || $credential->getSiteIdentifier() !== $site->getIdentifier())) {
            return;
        }

        $user->setKey('ses', 'nr_passkeys_fe_assurance', ['uid' => $uid, 'site' => $site->getIdentifier(), 'method' => $proof['method'], 'at' => time()]);
        if ($credential !== null) {
            $this->events->dispatch(new AfterPasskeyAuthenticationEvent($uid, $credential));
        }

        $this->logger->info('Frontend login completed', ['uid' => $uid, 'site' => $site->getIdentifier(), 'method' => $proof['method']]);
    }

    /** @return array{method: string, at: int}|null */
    public static function siteProof(FrontendUserAuthentication $user, int $uid, string $site): ?array
    {
        $proof = $user->getKey('ses', 'nr_passkeys_fe_assurance');
        if (!is_array($proof) || ($proof['uid'] ?? null) !== $uid || ($proof['site'] ?? null) !== $site
            || !is_int($proof['at'] ?? null) || $proof['at'] <= 0 || $proof['at'] > time()
            || !in_array($proof['method'] ?? null, ['passkey', 'recovery', 'primary'], true)) {
            return null;
        }

        return ['method' => $proof['method'], 'at' => $proof['at']];
    }

    public static function allowsSiteAccess(?string $method, SiteInterface $site, FrontendEnforcementStatus $status): bool
    {
        if ($status->effectiveLevel !== 'enforced') {
            return true;
        }

        return match ($method) {
            'passkey'  => true,
            'recovery' => SiteConfigurationService::allowsRecoveryMethod($site, 'recovery_code'),
            'primary'  => $status->passkeyCount === 0 && SiteConfigurationService::allowsRecoveryMethod($site, 'password'),
            default    => false,
        };
    }

    public static function allowsManagement(FrontendUserAuthentication $user, int $uid, string $site, bool $hasPasskeys): bool
    {
        $proof = self::siteProof($user, $uid, $site);

        return $proof !== null && $proof['at'] > time() - 300 && (!$hasPasskeys || $proof['method'] !== 'primary');
    }
}
