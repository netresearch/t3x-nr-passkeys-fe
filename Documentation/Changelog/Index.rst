..  include:: ../Includes.rst.txt

..  _changelog:

=========
Changelog
=========

Version 0.5.0
=============

*Unified passkey dashboard widgets*

Breaking / Important
--------------------

- **Standalone dashboard widgets removed** -- ``nr_passkeys_fe`` no
  longer registers its own ``Passkey adoption`` and
  ``Active passkey credentials`` dashboard widgets. When both
  extensions are installed the dashboard previously showed four
  near-identical widgets; it now shows the single unified widget set
  owned by ``nr_passkeys_be``.

- **Frontend adoption is now a segment of the unified widgets** --
  Frontend statistics appear as the "Frontend users" segment (a second
  doughnut ring and a summed credential count) of the
  ``nr_passkeys_be`` widgets, contributed via the new
  ``nr_passkeys_be.adoption_stats_provider`` service tag
  (``FrontendPasskeyAdoptionStatsProvider``). Backend and frontend user
  populations are shown separately, never summed.

- **Requires** ``netresearch/nr-passkeys-be`` **^0.12** -- the
  extension point (``PasskeyAdoptionStatsProviderInterface`` and the
  ``PasskeyAudienceStats`` DTO) is provided from ``nr_passkeys_be``
  0.12.0. The Composer constraint and the ``ext_emconf.php`` dependency
  were tightened accordingly.

- **TYPO3 v13 compatibility shim removed** -- ``nr_passkeys_fe`` no
  longer references any ``typo3/cms-dashboard`` symbol, so the
  ``AdminOnlyWidgetInterface`` compat shim (and its ``autoload.files``
  entry) has been dropped.

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

- **PSR-14 events** -- Seven events for extensibility: before/after
  authentication, before/after enrollment, enforcement level resolved,
  passkey removed, recovery codes generated.

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

- Magic link login is deferred to v0.2 (ADR-011). The event class
  and service will be added in v0.2.
- No admin-initiated passkey registration on behalf of users.
