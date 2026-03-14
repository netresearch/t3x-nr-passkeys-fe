<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Event\AfterPasskeyEnrollmentEvent;
use Netresearch\NrPasskeysFe\Event\BeforePasskeyEnrollmentEvent;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\PasskeyEnrollmentService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

#[CoversClass(PasskeyEnrollmentService::class)]
final class PasskeyEnrollmentServiceTest extends TestCase
{
    private FrontendWebAuthnService&MockObject $webAuthnService;
    private FrontendCredentialRepository&MockObject $credentialRepository;
    private SiteConfigurationService&MockObject $siteConfigService;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private SiteInterface&MockObject $site;
    private PasskeyEnrollmentService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webAuthnService = $this->createMock(FrontendWebAuthnService::class);
        $this->credentialRepository = $this->createMock(FrontendCredentialRepository::class);
        $this->siteConfigService = $this->createMock(SiteConfigurationService::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->site = $this->createMock(SiteInterface::class);

        $configuration = new FrontendConfiguration(maxPasskeysPerUser: 10);

        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $this->subject = new PasskeyEnrollmentService(
            $this->webAuthnService,
            $this->credentialRepository,
            $configuration,
            $this->siteConfigService,
            $this->eventDispatcher,
        );
    }

    // ---------------------------------------------------------------
    // startEnrollment()
    // ---------------------------------------------------------------

    #[Test]
    public function startEnrollmentReturnsRegistrationOptions(): void
    {
        $this->credentialRepository->method('countByFeUser')->willReturn(0);

        $expected = ['options' => 'dummy', 'optionsJson' => '{}'];
        $this->webAuthnService->method('createRegistrationOptions')
            ->with(1, 'testuser', self::isType('string'), $this->site)
            ->willReturn($expected);

        $challenge = \random_bytes(32);
        $result = $this->subject->startEnrollment(1, 'testuser', $challenge, $this->site);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function startEnrollmentThrowsWhenMaxPasskeysReached(): void
    {
        $this->credentialRepository->method('countByFeUser')->willReturn(10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700300001);

        $this->subject->startEnrollment(1, 'user', \random_bytes(32), $this->site);
    }

    // ---------------------------------------------------------------
    // completeEnrollment()
    // ---------------------------------------------------------------

    #[Test]
    public function completeEnrollmentVerifiesAndSavesCredential(): void
    {
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $credential = new FrontendCredential(feUser: 1, credentialId: 'new-cred');

        $this->webAuthnService->expects(self::once())
            ->method('verifyRegistrationResponse')
            ->willReturn($credential);

        $this->credentialRepository->expects(self::once())
            ->method('save')
            ->with($credential);

        $challenge = \random_bytes(32);
        $result = $this->subject->completeEnrollment(
            1,
            '{"attestation":"json"}',
            $challenge,
            'My New Key',
            $this->site,
        );

        self::assertSame($credential, $result);
        self::assertSame('My New Key', $result->getLabel());
    }

    #[Test]
    public function completeEnrollmentThrowsWhenMaxPasskeysReached(): void
    {
        $this->credentialRepository->method('countByFeUser')->willReturn(10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700300001);

        $this->subject->completeEnrollment(
            1,
            '{}',
            \random_bytes(32),
            'Key',
            $this->site,
        );
    }

    #[Test]
    public function completeEnrollmentDispatchesBeforeEvent(): void
    {
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $credential = new FrontendCredential(feUser: 1);
        $this->webAuthnService->method('verifyRegistrationResponse')->willReturn($credential);

        $dispatchedEvents = [];
        $this->eventDispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $this->subject->completeEnrollment(1, '{}', \random_bytes(32), 'Key', $this->site);

        self::assertCount(2, $dispatchedEvents);
        self::assertInstanceOf(BeforePasskeyEnrollmentEvent::class, $dispatchedEvents[0]);
        self::assertSame(1, $dispatchedEvents[0]->feUserUid);
        self::assertSame('main', $dispatchedEvents[0]->siteIdentifier);
    }

    #[Test]
    public function completeEnrollmentDispatchesAfterEvent(): void
    {
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $credential = new FrontendCredential(feUser: 1);
        $this->webAuthnService->method('verifyRegistrationResponse')->willReturn($credential);

        $dispatchedEvents = [];
        $this->eventDispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $this->subject->completeEnrollment(1, '{}', \random_bytes(32), 'Key', $this->site);

        self::assertCount(2, $dispatchedEvents);
        self::assertInstanceOf(AfterPasskeyEnrollmentEvent::class, $dispatchedEvents[1]);
        self::assertSame(1, $dispatchedEvents[1]->feUserUid);
        self::assertSame($credential, $dispatchedEvents[1]->credential);
    }

    #[Test]
    public function completeEnrollmentSetsLabelOnCredential(): void
    {
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $credential = new FrontendCredential(feUser: 1);
        $this->webAuthnService->method('verifyRegistrationResponse')->willReturn($credential);

        $result = $this->subject->completeEnrollment(
            1,
            '{}',
            \random_bytes(32),
            'Work Laptop Key',
            $this->site,
        );

        self::assertSame('Work Laptop Key', $result->getLabel());
    }

    #[Test]
    public function completeEnrollmentAllowsUpToMaxMinusOnePasskeys(): void
    {
        // 9 existing passkeys, max is 10 — should succeed
        $this->credentialRepository->method('countByFeUser')->willReturn(9);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $credential = new FrontendCredential(feUser: 1);
        $this->webAuthnService->method('verifyRegistrationResponse')->willReturn($credential);

        $result = $this->subject->completeEnrollment(
            1,
            '{}',
            \random_bytes(32),
            'Key',
            $this->site,
        );

        self::assertSame($credential, $result);
    }

    #[Test]
    public function completeEnrollmentPropagatesVerificationFailure(): void
    {
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $this->webAuthnService->method('verifyRegistrationResponse')
            ->willThrowException(new RuntimeException('Verification failed', 1700200022));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700200022);

        $this->subject->completeEnrollment(1, '{}', \random_bytes(32), 'Key', $this->site);
    }
}
