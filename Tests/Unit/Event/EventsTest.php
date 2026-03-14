<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Event;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Event\AfterPasskeyAuthenticationEvent;
use Netresearch\NrPasskeysFe\Event\AfterPasskeyEnrollmentEvent;
use Netresearch\NrPasskeysFe\Event\BeforePasskeyAuthenticationEvent;
use Netresearch\NrPasskeysFe\Event\BeforePasskeyEnrollmentEvent;
use Netresearch\NrPasskeysFe\Event\EnforcementLevelResolvedEvent;
use Netresearch\NrPasskeysFe\Event\MagicLinkRequestedEvent;
use Netresearch\NrPasskeysFe\Event\PasskeyRemovedEvent;
use Netresearch\NrPasskeysFe\Event\RecoveryCodesGeneratedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BeforePasskeyEnrollmentEvent::class)]
#[CoversClass(AfterPasskeyEnrollmentEvent::class)]
#[CoversClass(BeforePasskeyAuthenticationEvent::class)]
#[CoversClass(AfterPasskeyAuthenticationEvent::class)]
#[CoversClass(PasskeyRemovedEvent::class)]
#[CoversClass(RecoveryCodesGeneratedEvent::class)]
#[CoversClass(MagicLinkRequestedEvent::class)]
#[CoversClass(EnforcementLevelResolvedEvent::class)]
final class EventsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // BeforePasskeyEnrollmentEvent
    // -------------------------------------------------------------------------

    #[Test]
    public function beforePasskeyEnrollmentEventExposesAllProperties(): void
    {
        $event = new BeforePasskeyEnrollmentEvent(
            feUserUid: 42,
            siteIdentifier: 'main',
            attestationJson: '{"type":"webauthn.create"}',
        );

        self::assertSame(42, $event->feUserUid);
        self::assertSame('main', $event->siteIdentifier);
        self::assertSame('{"type":"webauthn.create"}', $event->attestationJson);
    }

    #[Test]
    public function beforePasskeyEnrollmentEventIsReadonly(): void
    {
        $event = new BeforePasskeyEnrollmentEvent(1, 'site', 'json');

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $event->feUserUid = 99;
    }

    // -------------------------------------------------------------------------
    // AfterPasskeyEnrollmentEvent
    // -------------------------------------------------------------------------

    #[Test]
    public function afterPasskeyEnrollmentEventExposesAllProperties(): void
    {
        $credential = new FrontendCredential(uid: 10, feUser: 42);

        $event = new AfterPasskeyEnrollmentEvent(
            feUserUid: 42,
            credential: $credential,
            siteIdentifier: 'shop',
        );

        self::assertSame(42, $event->feUserUid);
        self::assertSame($credential, $event->credential);
        self::assertSame('shop', $event->siteIdentifier);
    }

    #[Test]
    public function afterPasskeyEnrollmentEventIsReadonly(): void
    {
        $credential = new FrontendCredential();
        $event = new AfterPasskeyEnrollmentEvent(1, $credential, 'site');

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $event->feUserUid = 99;
    }

    // -------------------------------------------------------------------------
    // BeforePasskeyAuthenticationEvent
    // -------------------------------------------------------------------------

    #[Test]
    public function beforePasskeyAuthenticationEventExposesAllProperties(): void
    {
        $event = new BeforePasskeyAuthenticationEvent(
            feUserUid: 7,
            assertionJson: '{"type":"webauthn.get"}',
        );

        self::assertSame(7, $event->feUserUid);
        self::assertSame('{"type":"webauthn.get"}', $event->assertionJson);
    }

    #[Test]
    public function beforePasskeyAuthenticationEventAcceptsNullFeUserUid(): void
    {
        $event = new BeforePasskeyAuthenticationEvent(
            feUserUid: null,
            assertionJson: '{}',
        );

        self::assertNull($event->feUserUid);
    }

    #[Test]
    public function beforePasskeyAuthenticationEventIsReadonly(): void
    {
        $event = new BeforePasskeyAuthenticationEvent(null, '{}');

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $event->assertionJson = 'other';
    }

    // -------------------------------------------------------------------------
    // AfterPasskeyAuthenticationEvent
    // -------------------------------------------------------------------------

    #[Test]
    public function afterPasskeyAuthenticationEventExposesAllProperties(): void
    {
        $credential = new FrontendCredential(uid: 5, feUser: 7);

        $event = new AfterPasskeyAuthenticationEvent(
            feUserUid: 7,
            credential: $credential,
        );

        self::assertSame(7, $event->feUserUid);
        self::assertSame($credential, $event->credential);
    }

    #[Test]
    public function afterPasskeyAuthenticationEventIsReadonly(): void
    {
        $credential = new FrontendCredential();
        $event = new AfterPasskeyAuthenticationEvent(1, $credential);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $event->feUserUid = 99;
    }

    // -------------------------------------------------------------------------
    // PasskeyRemovedEvent
    // -------------------------------------------------------------------------

    #[Test]
    public function passkeyRemovedEventExposesAllProperties(): void
    {
        $credential = new FrontendCredential(uid: 3, feUser: 10);

        $event = new PasskeyRemovedEvent(
            credential: $credential,
            revokedBy: 99,
        );

        self::assertSame($credential, $event->credential);
        self::assertSame(99, $event->revokedBy);
    }

    #[Test]
    public function passkeyRemovedEventIsReadonly(): void
    {
        $credential = new FrontendCredential();
        $event = new PasskeyRemovedEvent($credential, 1);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $event->revokedBy = 99;
    }

    // -------------------------------------------------------------------------
    // RecoveryCodesGeneratedEvent
    // -------------------------------------------------------------------------

    #[Test]
    public function recoveryCodesGeneratedEventExposesAllProperties(): void
    {
        $event = new RecoveryCodesGeneratedEvent(
            feUserUid: 15,
            codeCount: 10,
        );

        self::assertSame(15, $event->feUserUid);
        self::assertSame(10, $event->codeCount);
    }

    #[Test]
    public function recoveryCodesGeneratedEventIsReadonly(): void
    {
        $event = new RecoveryCodesGeneratedEvent(1, 10);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $event->codeCount = 99;
    }

    // -------------------------------------------------------------------------
    // MagicLinkRequestedEvent
    // -------------------------------------------------------------------------

    #[Test]
    public function magicLinkRequestedEventExposesAllProperties(): void
    {
        $event = new MagicLinkRequestedEvent(
            feUserUid: 20,
            email: 'user@example.com',
        );

        self::assertSame(20, $event->feUserUid);
        self::assertSame('user@example.com', $event->email);
    }

    #[Test]
    public function magicLinkRequestedEventHasNoTokenProperty(): void
    {
        $event = new MagicLinkRequestedEvent(1, 'user@example.com');

        $reflection = new \ReflectionClass($event);
        $properties = $reflection->getProperties();
        $propertyNames = \array_map(static fn (\ReflectionProperty $p): string => $p->getName(), $properties);

        self::assertNotContains('token', $propertyNames, 'MagicLinkRequestedEvent must not expose the token for security reasons');
        self::assertNotContains('magicLinkToken', $propertyNames, 'MagicLinkRequestedEvent must not expose the token for security reasons');
    }

    #[Test]
    public function magicLinkRequestedEventIsReadonly(): void
    {
        $event = new MagicLinkRequestedEvent(1, 'user@example.com');

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $event->email = 'attacker@example.com';
    }

    // -------------------------------------------------------------------------
    // EnforcementLevelResolvedEvent (mutable)
    // -------------------------------------------------------------------------

    #[Test]
    public function enforcementLevelResolvedEventExposesFeUserUidAndLevel(): void
    {
        $event = new EnforcementLevelResolvedEvent(
            feUserUid: 30,
            effectiveLevel: 'required',
        );

        self::assertSame(30, $event->feUserUid);
        self::assertSame('required', $event->getEffectiveLevel());
    }

    #[Test]
    public function enforcementLevelResolvedEventSetEffectiveLevelChangesValue(): void
    {
        $event = new EnforcementLevelResolvedEvent(
            feUserUid: 30,
            effectiveLevel: 'encourage',
        );

        self::assertSame('encourage', $event->getEffectiveLevel());

        $event->setEffectiveLevel('enforced');

        self::assertSame('enforced', $event->getEffectiveLevel());
    }

    #[Test]
    public function enforcementLevelResolvedEventFeUserUidIsReadonly(): void
    {
        $event = new EnforcementLevelResolvedEvent(30, 'off');

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $event->feUserUid = 99;
    }

    #[Test]
    public function enforcementLevelResolvedEventLevelCanBeOverriddenMultipleTimes(): void
    {
        $event = new EnforcementLevelResolvedEvent(1, 'off');

        $event->setEffectiveLevel('encourage');
        self::assertSame('encourage', $event->getEffectiveLevel());

        $event->setEffectiveLevel('required');
        self::assertSame('required', $event->getEffectiveLevel());

        $event->setEffectiveLevel('off');
        self::assertSame('off', $event->getEffectiveLevel());
    }
}
