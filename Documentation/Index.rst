..  include:: Includes.rst.txt

=====================================
Passkeys Frontend Authentication
=====================================

:Extension key:
    nr_passkeys_fe

:Package name:
    netresearch/nr-passkeys-fe

:Version:
    |release|

:Language:
    en

:Author:
    Netresearch DTT GmbH

:License:
    This document is published under the
    `GPL-2.0-or-later <https://www.gnu.org/licenses/gpl-2.0.html>`__
    license.

:Rendered:
    |today|

----

Passwordless TYPO3 frontend authentication for ``fe_users`` via
WebAuthn/FIDO2 Passkeys. Enables login with TouchID, FaceID, YubiKey,
and Windows Hello on your frontend login page -- with optional felogin
integration, self-service management, recovery codes, and per-site
enforcement.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        What the extension does, which authenticators are supported,
        and the full feature list.

    ..  card:: :ref:`Installation <installation>`

        Install via Composer, activate the extension, and run the
        database schema update.

    ..  card:: :ref:`Configuration <configuration>`

        Extension settings, site configuration (RP ID), and
        TypoScript reference.

    ..  card:: :ref:`Quick Start <quick-start>`

        Five-minute setup: install, add plugins, and log in with a
        passkey.

    ..  card:: :ref:`Usage <usage>`

        Login flows, passkey enrollment, recovery mechanisms, and the
        self-service management plugin.

    ..  card:: :ref:`Administration <administration>`

        Backend admin module, enforcement settings, and user
        management.

    ..  card:: :ref:`Developer Guide <developer-guide>`

        PSR-14 events, eID API reference, extension points, and
        architecture notes.

    ..  card:: :ref:`Security <security>`

        WebAuthn compliance, threat model, and security hardening.

    ..  card:: :ref:`Multi-Site <multi-site>`

        Multi-domain RP ID configuration and site-aware authentication.

    ..  card:: :ref:`Troubleshooting <troubleshooting>`

        Common issues and solutions.

    ..  card:: :ref:`Architecture Decision Records <adr>`

        Design decisions and rationale (ADR-001 to ADR-012).

    ..  card:: :ref:`Changelog <changelog>`

        Version history and release notes.

..  toctree::
    :maxdepth: 2
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    QuickStart/Index
    Usage/Index
    Administration/Index
    DeveloperGuide/Index
    Security/Index
    MultiSite/Index
    Troubleshooting/Index
    Adr/Index
    Changelog/Index
