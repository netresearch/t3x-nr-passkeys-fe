<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\EventListener;

use Closure;
use Netresearch\NrPasskeysFe\Event\AfterPasskeyEnrollmentEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** @internal */
final readonly class SendEnrollmentNotification
{
    /** @param Closure(): MailerInterface $mailer */
    public function __construct(
        private ConnectionPool $connections,
        private LanguageServiceFactory $languages,
        private SiteFinder $sites,
        private Closure $mailer,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(AfterPasskeyEnrollmentEvent $event): void
    {
        $uid = $event->feUserUid;
        try {
            $row = $this->connections
                ->getConnectionForTable('fe_users')
                ->select(
                    ['email'],
                    'fe_users',
                    [
                        'uid'     => $uid,
                        'pid'     => $event->credential->getStoragePid(),
                        'deleted' => 0,
                        'disable' => 0,
                    ],
                )
                ->fetchAssociative();
            $address = $row['email'] ?? null;
            if (!is_string($address) || !GeneralUtility::validEmail($address)) {
                $this->logger->warning(
                    'Passkey enrollment notification has no valid recipient',
                    ['fe_user_uid' => $uid],
                );

                return;
            }

            $site     = $this->sites->getSiteByIdentifier($event->siteIdentifier);
            $language = $this->languages->createFromSiteLanguage(
                $site->getDefaultLanguage(),
            );
            $siteName = $site->getIdentifier() . ' (' . $site->getBase()->withUserInfo('')->withQuery('')->withFragment('') . ')';
            $prefix   = 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang.xlf:enrollment.email.';
            $message  = (new Email())
                ->to($address)
                ->subject($language->sL($prefix . 'subject'))
                ->text(sprintf($language->sL($prefix . 'body'), $siteName));
            ($this->mailer)()->send($message);
        } catch (Throwable $error) {
            $this->logger->warning(
                'Passkey enrollment notification could not be sent',
                ['fe_user_uid' => $uid, 'exceptionType' => $error::class],
            );
        }
    }
}
