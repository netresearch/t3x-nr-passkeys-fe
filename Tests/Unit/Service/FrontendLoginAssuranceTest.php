<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Event\AfterPasskeyAuthenticationEvent;
use Netresearch\NrPasskeysFe\Service\FrontendLoginAssurance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\Event\AfterUserLoggedInEvent;
use TYPO3\CMS\Core\Authentication\Event\BeforeRequestTokenProcessedEvent;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Session\UserSession;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

#[CoversClass(FrontendLoginAssurance::class)]
final class FrontendLoginAssuranceTest extends TestCase
{
    #[Test]
    public function onlyAnObservedLoginCanAuthorizeFirstEnrollment(): void
    {
        $service = new FrontendLoginAssurance($this->createStub(EventDispatcherInterface::class), new NullLogger());
        $user    = $this->createMock(FrontendUserAuthentication::class);
        $user->method('getUserId')->willReturn(7);
        $session = UserSession::createFromRecord('session', ['ses_userid' => 0]);
        $user->method('getSession')->willReturnCallback(static function () use (&$session): UserSession {
            return $session;
        });
        $site = $this->createStub(Site::class);
        $site->method('getIdentifier')->willReturn('shop');
        $request = (new ServerRequest('https://example.com/login', 'POST'))->withAttribute('site', $site);
        $proof   = null;
        $user->expects(self::once())->method('setKey')->willReturnCallback(static function (string $type, string $key, mixed $value) use (&$proof): void {
            self::assertSame('ses', $type);
            self::assertSame('nr_passkeys_fe_assurance', $key);
            $proof = $value;
        });
        $user->method('getKey')->willReturnCallback(static function () use (&$proof): mixed {
            return $proof;
        });
        $service->completeForRequest($user, $request);
        self::assertFalse(FrontendLoginAssurance::allowsManagement($user, 7, 'shop', false));

        $service->start(new BeforeRequestTokenProcessedEvent($user, $request, null));
        $session = UserSession::createFromRecord('session', ['ses_userid' => 7]);
        $service->completeForRequest($user, $request);
        $service->completeForRequest($user, $request);

        self::assertTrue(FrontendLoginAssurance::allowsManagement($user, 7, 'shop', false));
        self::assertFalse(FrontendLoginAssurance::allowsManagement($user, 7, 'shop', true));
        self::assertFalse(FrontendLoginAssurance::allowsManagement($user, 7, 'other', false));
        self::assertFalse(FrontendLoginAssurance::allowsManagement($user, 8, 'shop', false));
    }

    #[Test]
    public function verifiedCredentialIsCreditedOnlyToItsSessionOwnerAndSite(): void
    {
        $credential = new FrontendCredential(feUser: 7, siteIdentifier: 'shop');
        $events     = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (AfterPasskeyAuthenticationEvent $event): bool => $event->feUserUid === 7 && $event->credential === $credential,
        ))->willReturnArgument(0);
        $service = new FrontendLoginAssurance($events, new NullLogger());
        $user    = $this->createMock(FrontendUserAuthentication::class);
        $user->method('getUserId')->willReturn(7);
        $user->method('getSession')->willReturn(UserSession::createFromRecord('session', ['ses_userid' => 7]));
        $user->expects(self::once())->method('setKey')->with('ses', 'nr_passkeys_fe_assurance', self::callback(
            static fn (array $proof): bool => $proof['uid'] === 7 && $proof['site'] === 'shop' && $proof['method'] === 'passkey',
        ));
        $site    = new Site('shop', 1, ['base' => 'https://shop.example/']);
        $request = (new ServerRequest('https://shop.example/login', 'POST'))->withAttribute('site', $site);
        $event   = new AfterUserLoggedInEvent($user, $request);
        $service->verified($user, 8, $credential);
        $service->complete($event);
        $service->verified($user, 7, new FrontendCredential(feUser: 7, siteIdentifier: 'other'));
        $service->complete($event);
        $service->verified($user, 7, $credential);
        $service->complete($event);
        $service->complete($event);
    }
}
