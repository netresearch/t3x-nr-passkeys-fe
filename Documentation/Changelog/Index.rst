.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: ../Includes.rst.txt

..  _changelog:

=========
Changelog
=========

Version 2.0.0
=============

*Plugin settings that take effect, Required enforcement that binds, and a
working admin module*

Breaking / Important
--------------------

- **Required enforcement becomes binding once the grace period is over.**
  Until now the enrollment interstitial wrote a new grace period start on
  every request of a Required user whose grace period had ended, so the
  user could skip enrollment forever. The start is now written only where
  none is stored: a Required user without a passkey whose grace period has
  expired is sent to the enrollment page on every request, with no way to
  skip, as the enforcement documentation already described. *Upgrade:* a
  site that relied on Required never becoming binding resets the affected
  users' grace periods with the new "Reset grace period" action in the
  backend module, or sets the group to Encourage.

- **A grace period lasts exactly its days times 24 hours.** It used to end
  after N local calendar days, an hour earlier or later across a daylight
  saving change. The banner and the enrollment page count started 24-hour
  periods, so a 14-day grace period shows 14 at its start and 1 during its
  last day; the banner no longer says "0 day(s)" on the last day. A grace
  period now starts on the user's first request, before the banner and the
  enrollment page render.

- **TYPO3 13.4 LTS and 14.3 LTS only.** The constraints are
  ``^13.4 || ^14.3`` in ``composer.json`` and ``13.4.20-14.3.99`` in
  ``ext_emconf.php``. Installations on TYPO3 14.1 or 14.2 upgrade TYPO3 to
  14.3 first.

- **Three TypoScript constants removed:**
  ``plugin.tx_nrpasskeysfe.settings.loginPageUid``, ``managementPageUid``
  and ``enrollmentPageUid``. Nothing read them: the extension has no logout
  redirect and no "back to login" link, and the post-login interstitial
  redirects to the site setting ``nr_passkeys_fe.enrollmentPageUrl``.
  *Upgrade:* delete them from the site's TypoScript constants. A site that
  still sets them keeps working.

- **Templates and a partial that were never rendered are removed:**
  ``Enrollment/Success.html``, ``Management/RecoveryCodes.html``,
  ``Management/Enrollment.html``, ``Login/Recovery.html`` and
  ``Partials/Login/PasskeyButton.html``, together with their 17 labels (en,
  de, fr) and the CSS rules only they used. Only the ``index`` actions exist,
  so an override of these files never took effect. *Upgrade:* a site that
  overrides ``Login/Index.html``, ``Enrollment/Index.html`` or the felogin
  templates compares its copy with the shipped one: the login templates now
  render ``Partials/NrPasskeysFe/LoginAssets.html`` for their CSS, scripts
  and JavaScript translations, and felogin finds it through
  ``plugin.tx_felogin_login.view.partialRootPaths.1700000000``.

- **The login plugin's "Discoverable login" field is a select.** *Use the
  site setting* (the new default), *On* or *Off*. Values stored by the old
  checkbox keep their meaning as *On* and *Off*; such an element follows the
  new ``discoverableEnabled`` constant only once it is set to *Use the site
  setting*.

- **Removed from the frontend output:** ``window.NrPasskeysFeConfig``, the
  ``data-site-identifier`` attribute of the felogin passkey panel and the
  recovery form, the felogin view variables ``passkeyRpId`` and
  ``passkeyLoginEnabled``, and the "My device doesn't support passkeys"
  link, whose target was never set. The enrollment script no longer
  follows a ``redirectUrl`` in the verify response; no server code sent one.
  Custom scripts or template overrides that read any of these drop them.

Features
--------

- **The plugin settings are editable and take effect.** The Login,
  Management and Enrollment plugins show their FlexForm on a "Plugin" tab on
  TYPO3 13.4 and 14.3. Discoverable login off shows a username field, the
  password fallback links to the new "Password login page" field, and a
  redirect page receives the visitor after a passkey login. Both page fields
  accept only a standard page on the plugin's site, reached directly or
  through shortcuts, that the visitor may access as TYPO3 decides for a page
  request; any other target falls back to the plugin's own page.

- **New TypoScript constants.** ``discoverableEnabled`` sets the default of
  the login plugin and makes the felogin passkey tab ask for a username when
  it is off. ``css.includeDefault = 0`` now switches the default CSS off for
  the plugins and the felogin integration; before, nothing read it.

- **The enrollment page shows the user's enforcement state.** It shows the
  grace days left, or that enrollment is required, and a success message
  after a registration.

- **Reset grace period in the backend module.** The credential lookup gets
  the action the user management documentation already described. The next
  request under Required starts a new grace period.

Bugfixes
--------

- **Saving the enforcement level in the admin dashboard works.** The select
  posted to a route that did not exist, so every change ended in "Update
  failed". Changes are recorded in the history like an edit in the record
  form.

- **The credential lookup in the admin module lists the user's passkeys.**
  The request failed on every supported TYPO3 version.

- **The admin module follows the backend's light and dark scheme,** and the
  enforcement select and the adoption bar have accessible names.

- **The removal question in the passkey management no longer shows HTML
  entities** for a label with ``&`` or quotes.

- **No deprecated core API.** The client address for the rate limiter and
  the lockout comes from the request instead of ``getIndpEnv()``, and the
  plugin FlexForms are registered without ``addPiFlexFormValue()``; TYPO3
  14.3 deprecates both.

Tests
-----

- CI runs the JavaScript unit tests, and those tests drive the shipped
  modules instead of copies of their logic. The end-to-end suite covers the
  new settings on TYPO3 13.4 and 14.3, with a second variant whose site base
  carries a host.

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
