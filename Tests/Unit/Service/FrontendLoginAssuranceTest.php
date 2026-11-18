<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Netresearch\NrPasskeysFe\Service\FrontendLoginAssurance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
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
        $user = $this->createMock(FrontendUserAuthentication::class);
        $user->method('getUserId')->willReturn(7);
        $session = UserSession::createFromRecord('session', ['ses_userid' => 0]);
        $user->method('getSession')->willReturnCallback(static function () use (&$session): UserSession {
            return $session;
        });
        $site = $this->createStub(Site::class);
        $site->method('getIdentifier')->willReturn('shop');
        $request = (new ServerRequest('https://example.com/login', 'POST'))->withAttribute('site', $site);
        $proof = null;
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
}
