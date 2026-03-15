..  include:: ../Includes.rst.txt

..  _changelog:

=========
Changelog
=========

Version 0.1.0
=============

*Initial release*

This is the first public release of Passkeys Frontend Authentication
(``nr_passkeys_fe``). It provides passkey-first login for TYPO3
frontend users with all core features.

Features
--------

- **Passkey-first login** -- Discoverable (usernameless) and
  username-first login flows via the NrPasskeysFe:Login plugin.
  Supports all FIDO2/WebAuthn-compliant authenticators.

- **felogin integration** -- Injects a passkey button into the
  standard felogin plugin via PSR-14 event listener. No login provider
  switching required.

- **Self-service management** -- Frontend users can enroll, rename,
  and revoke their own passkeys via the NrPasskeysFe:Management plugin.

- **Recovery codes** -- Users can generate 10 one-time recovery codes
  (bcrypt hashed) as a fallback when no authenticator device is
  available.

- **Per-site RP ID** -- Each TYPO3 site has an independent WebAuthn
  Relying Party configuration via ``config.yaml``.

- **Per-group enforcement** -- Four enforcement levels (Off, Encourage,
  Required, Enforced) configurable per site and per frontend user
  group with configurable grace periods.

- **Post-login interstitial** -- Users without a passkey are shown an
  enrollment interstitial when enforcement level is Required or
  Enforced.

- **Backend admin module** -- Administrators can view adoption
  statistics, manage credentials, and configure enforcement from
  :guilabel:`Admin Tools > Passkey Management FE`.

- **PSR-14 events** -- Eight events for extensibility: before/after
  authentication, before/after enrollment, enforcement level resolved,
  passkey removed, recovery codes generated, magic link requested.

- **Security hardened** -- HMAC-signed challenges, nonce replay
  protection, per-IP rate limiting, and account lockout (shared with
  ``nr-passkeys-be``).

- **Vanilla JavaScript** -- Zero runtime npm dependencies. The
  frontend JavaScript uses only the native WebAuthn browser API.

Requirements
------------

- TYPO3 13.4 LTS or 14.1+
- PHP 8.2+
- ``netresearch/nr-passkeys-be`` ^0.6
- HTTPS

Known limitations
-----------------

- Magic link login is deferred to v0.2 (ADR-011). The
  ``MagicLinkRequestedEvent`` is emitted but no email is sent
  by default.
- No admin-initiated passkey registration on behalf of users.
