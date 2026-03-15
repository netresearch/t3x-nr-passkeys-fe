..  include:: ../Includes.rst.txt

..  _introduction:

============
Introduction
============

What does it do?
================

Passkeys Frontend Authentication provides passwordless login for TYPO3
frontend users (``fe_users``) using the WebAuthn/FIDO2 standard.
Frontend users can authenticate with a single touch or glance using
biometric authenticators such as TouchID, FaceID, Windows Hello, or
hardware security keys like YubiKey -- no password required.

The extension ships two frontend plugins:

- **NrPasskeysFe:Login** -- A passkey-first login form. Can replace
  or supplement the standard felogin plugin.
- **NrPasskeysFe:Management** -- A self-service credential management
  panel for logged-in users (enroll, rename, remove passkeys; generate
  recovery codes).

A third plugin, **NrPasskeysFe:Enrollment**, is used as the target for
the post-login enrollment interstitial when enforcement is active.

Features
========

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :card-height: 100

    ..  card:: Passkey-first login

        Discoverable (usernameless) login and username-first flows.
        Works as a standalone plugin or alongside felogin.

    ..  card:: felogin integration

        Hooks into the standard TYPO3 felogin plugin via PSR-14 events
        to inject a passkey button without replacing the entire login
        form.

    ..  card:: Self-service management

        Frontend users can enroll new passkeys, rename existing ones,
        and revoke credentials they no longer need -- all from the
        frontend.

    ..  card:: Recovery codes

        Users can generate one-time recovery codes (bcrypt hashed) as
        a fallback when no authenticator device is available.

    ..  card:: Per-site enforcement

        Each TYPO3 site can have an independent RP ID and enforcement
        level. Enforcement levels: Off, Encourage, Required, Enforced.

    ..  card:: Per-group enforcement

        Enforcement level can be set per frontend user group with
        configurable grace periods.

    ..  card:: Post-login interstitial

        When enforcement is Required or Enforced, users without a
        passkey are shown an enrollment interstitial after login.

    ..  card:: Backend admin module

        Administrators can view adoption statistics, manage
        credentials, and configure enforcement from the TYPO3 backend.

    ..  card:: PSR-14 events

        Eight events for extensibility: before/after authentication,
        before/after enrollment, enforcement resolved, passkey removed,
        recovery codes generated, magic link requested.

    ..  card:: Security hardened

        Builds on ``nr-passkeys-be`` for HMAC-signed challenges, nonce
        replay protection, per-IP rate limiting, and account lockout.

    ..  card:: Vanilla JavaScript

        Zero npm dependencies at runtime. The frontend JavaScript uses
        only the native WebAuthn browser API and TYPO3 Ajax helpers.

    ..  card:: TYPO3 v13 and v14

        Compatible with TYPO3 13.4 LTS and 14.1+. PHP 8.2, 8.3, 8.4,
        and 8.5 supported.

Supported authenticators
========================

Any FIDO2/WebAuthn-compliant authenticator works, including:

- Apple TouchID and FaceID (macOS, iOS, iPadOS)
- Windows Hello (fingerprint, face, PIN)
- YubiKey 5 series and newer
- Android fingerprint and face unlock
- Any FIDO2-compliant hardware security key

Browser support
===============

WebAuthn is supported by all modern browsers:

=========================  ===========
Browser                    Version
=========================  ===========
Chrome / Edge              67+
Firefox                    60+
Safari                     14+
Chrome for Android         70+
Safari for iOS             14.5+
=========================  ===========

Screenshots
===========

..  note::

    Screenshots will be added once the extension is deployed on a
    staging site. See ``Documentation/Images/`` for the placeholder
    directory.

Relationship to nr-passkeys-be
================================

``nr_passkeys_fe`` requires ``netresearch/nr-passkeys-be`` as a
Composer dependency. It reuses the backend extension's WebAuthn
ceremony implementation, challenge service, and rate limiter. The
backend extension installs its own login module and BE credential
table -- these are present but unused on FE-only sites.

See :ref:`ADR-001 <adr-001>` for the rationale.
