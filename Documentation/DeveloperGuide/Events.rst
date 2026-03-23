..  include:: ../Includes.rst.txt

..  _events-reference:

PSR-14 Events
=============

The extension dispatches seven PSR-14 events that third-party
extensions can listen to. All events are in the
``Netresearch\NrPasskeysFe\Event`` namespace.

Register a listener in ``Configuration/Services.yaml``:

..  code-block:: yaml

    MyVendor\MyExt\EventListener\MyListener:
      tags:
        - name: event.listener
          identifier: 'my-ext/passkey-auth'
          event: Netresearch\NrPasskeysFe\Event\AfterPasskeyAuthenticationEvent

----

BeforePasskeyAuthenticationEvent
---------------------------------

Dispatched before a passkey assertion is verified. The ``feUserUid``
is ``null`` for discoverable-credential (usernameless) logins.

..  code-block:: php

    final readonly class BeforePasskeyAuthenticationEvent
    {
        public function __construct(
            public readonly ?int $feUserUid,
            public readonly string $assertionJson,
        ) {}
    }

Use cases: audit logging, custom rate limiting, anomaly detection.

AfterPasskeyAuthenticationEvent
---------------------------------

Dispatched after a successful passkey authentication.

..  code-block:: php

    final readonly class AfterPasskeyAuthenticationEvent
    {
        public function __construct(
            public readonly int $feUserUid,
            public readonly FrontendCredential $credential,
        ) {}
    }

Use cases: security dashboards, post-login workflows, notifications.

BeforePasskeyEnrollmentEvent
-----------------------------

Dispatched before a passkey enrollment ceremony begins. Throw an
exception in the listener to abort enrollment.

..  code-block:: php

    final readonly class BeforePasskeyEnrollmentEvent
    {
        public function __construct(
            public readonly int $feUserUid,
            public readonly string $siteIdentifier,
            public readonly string $attestationJson,
        ) {}
    }

Use cases: enrollment rate limiting, allowed-device policies.

AfterPasskeyEnrollmentEvent
----------------------------

Dispatched after a passkey is successfully enrolled.

..  code-block:: php

    final readonly class AfterPasskeyEnrollmentEvent
    {
        public function __construct(
            public readonly int $feUserUid,
            public readonly FrontendCredential $credential,
            public readonly string $siteIdentifier,
        ) {}
    }

Use cases: confirmation emails, audit logs, enforcement re-evaluation.

PasskeyRemovedEvent
--------------------

Dispatched after a passkey credential is revoked (by the user or an
admin). ``revokedBy`` is the UID of the actor (user UID for
self-service, admin UID for admin-initiated removal).

..  code-block:: php

    final readonly class PasskeyRemovedEvent
    {
        public function __construct(
            public readonly FrontendCredential $credential,
            public readonly int $revokedBy,
        ) {}
    }

Use cases: security alerts, audit logging.

RecoveryCodesGeneratedEvent
----------------------------

Dispatched when a new set of recovery codes is generated. The actual
code values are **never** included for security reasons.

..  code-block:: php

    final readonly class RecoveryCodesGeneratedEvent
    {
        public function __construct(
            public readonly int $feUserUid,
            public readonly int $codeCount,
        ) {}
    }

Use cases: email notification, audit logging.

EnforcementLevelResolvedEvent
------------------------------

**Mutable event.** Dispatched when the effective enforcement level has
been computed for a user. Listeners can call ``setEffectiveLevel()``
to override the resolved level.

..  code-block:: php

    final class EnforcementLevelResolvedEvent
    {
        public function __construct(
            public readonly int $feUserUid,
            private string $effectiveLevel,
        ) {}

        public function getEffectiveLevel(): string { ... }
        public function setEffectiveLevel(string $level): void { ... }
    }

The level is a string (``off``, ``encourage``, ``required``,
``enforced``) to avoid a hard compile-time dependency on the
``EnforcementLevel`` enum from ``nr-passkeys-be``.

Use cases: custom enforcement overrides (e.g. exempting staff users,
IP-based enforcement).

..  note::

    Magic link login (including ``MagicLinkRequestedEvent``) is deferred
    to v0.2. See :ref:`ADR-011 <adr-011>`.
