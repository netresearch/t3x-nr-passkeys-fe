..  include:: ../Includes.rst.txt

..  _changelog:

=========
Changelog
=========

Version 1.0.1
=============

*Login fixes, and the end-to-end suite in CI*

Security
--------

- **The login options endpoint no longer tells a caller which usernames
  exist.** A username-first request for a known account was answered with
  assertion options and status 200, one for an unknown account with 401 and
  an error body, so the frontend users of a site could be enumerated one
  request at a time. Unknown accounts and accounts without a passkey on the
  site are now answered with decoy options: same status, same keys, and
  credential descriptors derived from the username with HMAC-SHA256 under
  the encryption key, so they are stable per username and cannot be told
  apart from real ones. The ceremony then fails in the browser the way a
  cancelled one does.

- **All username-first answers take the same time.** The random delay that
  used to sit in the unknown-account branch ran on that side only, which made
  the response time a second way to tell the answers apart. Every
  username-first answer now leaves after a shared floor of 150 ms. Work that
  runs past that floor still shows its own duration; such a request logs a
  warning with the overrun.

Bugfixes
--------

- **A passkey login on a page without felogin now establishes a session.**
  After a successful ceremony the script posted the login token through a
  form it assembled itself, and TYPO3 refuses a frontend login whose
  ``__RequestToken`` is missing or carries the wrong scope — silently, with
  a 200 and no session cookie. The login plugin template now renders a
  hidden form whose token has the scope ``core/user-auth/fe``, and the script
  only submits forms TYPO3 rendered.

- **The backend module renders on TYPO3 13.** The dashboard and help
  templates passed the ``state`` of ``f:be.infobox`` as a string; TYPO3 13
  types that argument ``int`` and answered every module page with 503.

Tests
-----

- The end-to-end suite runs. 15 Playwright tests drive registration,
  discoverable login through to an authenticated session, credential
  management, enrollment, recovery and the backend module against a TYPO3
  instance the shared test runner provisions, and
  ``.github/workflows/e2e.yml`` runs them on TYPO3 13 and 14 for every pull
  request. Before this release every specification was skipped and no
  workflow ran them.

Version 1.0.0
=============

*Stable, and paired with nr_passkeys_be 1.0*

Breaking / Important
--------------------

- **The extension state changes from ``beta`` to ``stable``.** From this
  release on the public API follows semantic versioning: a removal or an
  incompatible change to a public class, method or configuration setting
  needs a new major version.

- **``nr_passkeys_be`` 1.0.0 or newer is required.** That release removed
  ``RateLimiterService::checkRateLimit()`` and ``::recordAttempt()``, which
  this extension called in ``LoginController`` and ``RecoveryController``.
  Both call sites now use ``consumeRateLimit()``, which performs the check
  and the increment inside one critical section — the separate check-then-
  record pair left a window in which concurrent requests could all pass the
  check before any of them incremented. No installation could reach the
  broken combination: the dependency constraint refused ``nr_passkeys_be``
  1.0.0 until this release.

Bugfixes
--------

- **Passkey login works on SQLite-backed installations.**
  ``tx_nrpasskeysfe_credential.credential_id`` is a ``varbinary`` column, but
  the lookups bound it as a plain string and ``save()`` declared no column
  types. MySQL compares that regardless; SQLite stores a string-bound
  parameter as a TEXT storage class, which never equals the BLOB the value
  was written as, so the credential could not be found and the login failed.
  The lookups now bind with ``ParameterType::BINARY`` and the insert declares
  ``credential_id``, ``user_handle`` and ``public_key_cose`` by type.

Tests
-----

- The functional suite passes on SQLite as well as MySQL: 113 tests on both,
  where SQLite previously produced twelve failures. The end-to-end
  specifications under ``Tests/E2E/`` remain drafts and are not part of any
  suite that runs.

Version 0.6.0
=============

*Conditional UI and CType plugin registration*

This release shipped without a changelog entry. Its contents:

Features
--------

- **WebAuthn Conditional UI on the login plugin** -- the browser offers a
  stored passkey directly in the username field's autofill menu, so a
  returning user never has to press the passkey button. The plugin's
  discoverable switch governs it.

- **Plugins register as CType on both supported TYPO3 versions**, so the
  content elements appear in the element wizard on v13 and v14 alike.

- **Rector runs with the shared organisation configuration**, including the
  TYPO3 v13 level set.

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
