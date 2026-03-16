# nr_passkeys_fe — Passkey-First Frontend Authentication for TYPO3

**Date:** 2026-03-14
**Status:** Draft
**Authors:** Sebastian Mendel

## Executive Summary

`nr_passkeys_fe` is a TYPO3 extension that provides native, passkey-first authentication
for frontend users (fe_users). It enables passwordless login via WebAuthn/FIDO2 passkeys
as the primary authentication mechanism, with password, recovery codes, and magic links
as fallback/recovery options.

The extension builds on `netresearch/nr-passkeys-be` as a composer dependency, reusing
its proven WebAuthn, challenge token, and rate limiting infrastructure while adding
frontend-specific authentication services, Extbase plugins, and multi-site support.

## Goals

1. **Passkey-first FE login** — Not MFA-after-password, but passkeys as the primary
   credential for fe_users
2. **Gradual adoption** — Enforcement levels (Off → Encourage → Required → Enforced)
   allow phased rollout per site and per user group
3. **Recovery-first design** — Password fallback, recovery codes, and magic links prevent
   user lockout from day one
4. **Multi-site aware** — Per-site RP ID configuration with storage PID credential
   isolation for multi-domain TYPO3 installations
5. **Integrator-friendly** — Works with felogin (via PSR-14 events) and standalone, with
   PSR-14 hooks for third-party registration extensions
6. **Admin control** — Backend module for adoption stats, per-user credential management,
   enforcement configuration, and bulk operations

## Non-Goals

- FE user registration (account creation) — out of scope, provide hooks instead
- External IdP integration (OIDC, SAML) — passkeys belong in the IdP if one is used
- Mobile app authentication — browser-only WebAuthn
- BE user passkeys — handled by nr-passkeys-be

## Target Environment

- **PHP:** ^8.2 (tested on 8.2, 8.3, 8.4, 8.5)
- **TYPO3:** ^13.4 || ^14.1
- **Dependency:** `netresearch/nr-passkeys-be` ^0.6
- **License:** GPL-2.0-or-later

---

## Architecture

### Extension Identity

| Property | Value |
|----------|-------|
| Extension key | `nr_passkeys_fe` |
| Composer package | `netresearch/nr-passkeys-fe` |
| PHP namespace | `Netresearch\NrPasskeysFe` |
| Category | fe (frontend) |

### Dependency on nr-passkeys-be

The extension requires `netresearch/nr-passkeys-be` and reuses these services:

- **ChallengeService** — HMAC-SHA256 signed challenge tokens with single-use nonce replay protection
- **RateLimiterService** — Per-IP/endpoint rate limiting and account lockout

The FE extension does NOT reuse:
- **WebAuthnService** — The BE service is hardcoded to `be_users` and `CredentialRepository` (BE table). The FE extension has its own `FrontendWebAuthnService` wrapping `web-auth/webauthn-lib` directly with per-site RP ID/origin and FE-specific credential resolution.
- BE authentication service (FE has its own auth chain)
- BE controllers/routes (FE has its own endpoints)
- BE middleware (FE middleware chain is separate)
- BE TCA overrides (FE extends fe_users/fe_groups, not be_users/be_groups)
- BE credential repository (FE has its own table with fe_user FK and site scoping)
- ExtensionConfigurationService (FE has `SiteConfigurationService` for per-site config)

This dependency approach means:
- **Pro:** No code duplication of security-critical primitives
- **Pro:** Shared security fixes propagate automatically
- **Pro:** Single `web-auth/webauthn-lib` version
- **Con:** BE extension installed even for FE-only sites
- **Mitigation:** Later extraction to `nr-passkeys-core` shared library (ADR-001)

### Component Overview

```
┌─────────────────────────────────────────────────────────┐
│                    TYPO3 Frontend                        │
│                                                         │
│  ┌──────────────┐  ┌───────────────┐  ┌──────────────┐ │
│  │   felogin    │  │  Standalone   │  │  Management  │ │
│  │  Integration │  │ Login Plugin  │  │   Plugin     │ │
│  │  (PSR-14)    │  │  (Extbase)    │  │  (Extbase)   │ │
│  └──────┬───────┘  └───────┬───────┘  └──────┬───────┘ │
│         │                  │                  │         │
│  ┌──────▼──────────────────▼──────────────────▼───────┐ │
│  │              FE Controllers (JSON API)              │ │
│  │  LoginCtrl │ ManagementCtrl │ RecoveryCtrl │ ...    │ │
│  └──────────────────────┬─────────────────────────────┘ │
│                         │                               │
│  ┌──────────────────────▼─────────────────────────────┐ │
│  │                   FE Services                       │ │
│  │  FrontendWebAuthnService                             │ │
│  │  FrontendCredentialRepository                       │ │
│  │  FrontendEnforcementService                         │ │
│  │  RecoveryCodeService                                │ │
│  │  MagicLinkService (v0.2)                            │ │
│  │  SiteConfigurationService                           │ │
│  └──────────────────────┬─────────────────────────────┘ │
│                         │                               │
│  ┌──────────────────────▼─────────────────────────────┐ │
│  │          nr-passkeys-be (shared services)           │ │
│  │  ChallengeService │ RateLimiterService              │ │
│  └────────────────────────────────────────────────────┘ │
│                                                         │
│  ┌────────────────────────────────────────────────────┐ │
│  │  PasskeyFrontendAuthenticationService (priority 80) │ │
│  │  → TYPO3 FE Auth Service Chain                      │ │
│  └────────────────────────────────────────────────────┘ │
│                                                         │
│  ┌────────────────────────────────────────────────────┐ │
│  │  Middleware: Enrollment Interstitial + Route Resolver│ │
│  └────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│                    TYPO3 Backend                         │
│                                                         │
│  ┌────────────────────────────────────────────────────┐ │
│  │  Admin Module: FE Passkey Dashboard                 │ │
│  │  Adoption stats │ User mgmt │ Enforcement │ Bulk    │ │
│  └────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘
```

---

## Class Structure

### Authentication

#### PasskeyFrontendAuthenticationService

Extends `AbstractAuthenticationService`, registered at priority 80 (above
`SaltedPasswordService` at 50, below typical LDAP/SSO providers at 90+).
See ADR-012 for priority rationale. Intercepts FE login and checks for passkey payload.

**Responsibilities:**
- Extract passkey payload from `loginData['uident']` (JSON with `_type: "passkey"`)
- Discoverable login: resolve fe_user from credential ID (no username needed)
- Username-first login: find credential by fe_user UID
- Delegate assertion verification to `WebAuthnService` (from nr-passkeys-be)
- Check lockout via `RateLimiterService`
- Tag session for enrollment interstitial middleware
- Check enforcement level, block password login when `Enforced`

**Integration with TYPO3 auth chain:**
```
FE Login Request
  → PasskeyFrontendAuthenticationService (priority 80)
    → if passkey payload: verify assertion → 200 (authenticated)
    → if no passkey payload: return 100 (continue chain)
  → SaltedPasswordService (priority 50)
    → if enforcement == Enforced && user has passkey: return 0 (deny)
    → normal password check
```

### Controllers

All FE controllers return JSON responses. Routing via TYPO3 eID mechanism for
API endpoints (lightweight, no full page rendering), with Extbase plugins for
user-facing pages. The eID approach is chosen because:
- API endpoints need minimal overhead (no TypoScript, no page rendering)
- Compatible with both TYPO3 v13 and v14
- Proven pattern from nr-passkeys-be (uses backend AJAX routes, the FE equivalent is eID)
- No dependency on Extbase routing configuration

Route paths shown below are logical; actual URLs use eID parameters:
`?eID=nr_passkeys_fe&action=loginOptions` etc.

#### LoginController
Public, unauthenticated endpoints for passkey login ceremony:
- `POST /passkeys-fe/login/options` — Generate assertion challenge
- `POST /passkeys-fe/login/verify` — Verify assertion response

#### ManagementController
Authenticated FE user endpoints for self-service:
- `POST /passkeys-fe/manage/registration/options` — Start passkey enrollment
- `POST /passkeys-fe/manage/registration/verify` — Complete enrollment
- `GET /passkeys-fe/manage/list` — List own passkeys
- `POST /passkeys-fe/manage/rename` — Rename a passkey
- `POST /passkeys-fe/manage/remove` — Remove a passkey

#### RecoveryController
Recovery mechanism endpoints:
- `POST /passkeys-fe/recovery/codes/generate` — Generate new recovery codes
- `POST /passkeys-fe/recovery/codes/verify` — Verify a recovery code for login
- `POST /passkeys-fe/recovery/magic-link/request` — Request magic link email (v0.2)
- `GET /passkeys-fe/recovery/magic-link/verify` — Verify magic link token (v0.2)

#### EnrollmentController
Post-login enrollment flow:
- `GET /passkeys-fe/enrollment/status` — Current enforcement status
- `POST /passkeys-fe/enrollment/skip` — Skip enrollment prompt (with nonce)

#### AdminModuleController
Backend module for admin management of FE passkeys:
- Dashboard rendering, adoption stats, enforcement UI
- Per-user credential management (view, revoke, unlock)

### Services

#### FrontendCredentialRepository
Database operations for `tx_nrpasskeysfe_credential`:
- `findByCredentialId(string $credentialId): ?FrontendCredential` — Global lookup (credential IDs are globally unique per WebAuthn spec). Used for discoverable login where storage PID is unknown.
- `findByCredentialIdScoped(string $credentialId, int $storagePid, string $siteIdentifier): ?FrontendCredential` — Scoped lookup for username-first login where context is known.
- `findByFeUser(int $feUserUid, string $siteIdentifier): array` — List user's credentials for a site.
- `countByFeUser(int $feUserUid): int`
- `save(FrontendCredential $credential): void`
- `updateLastUsed(int $uid): void`
- `revoke(int $uid, int $revokedBy): void`
- `revokeAllByFeUser(int $feUserUid, int $revokedBy): void`

**Discoverable login resolution:** When no username is provided, `findByCredentialId()`
performs a global lookup (credential IDs are unique). The returned credential contains
`fe_user`, `storage_pid`, and `site_identifier`, which are then validated against the
current request's site context. This solves the chicken-and-egg problem: we find the
credential first, then verify it belongs to the current site.

#### PasskeyEnrollmentService
Orchestrates the full passkey enrollment lifecycle (challenge → verification → storage → events):
- `startEnrollment(int $feUserUid, string $siteIdentifier): RegistrationOptions` — Generate registration challenge.
- `completeEnrollment(int $feUserUid, string $attestationJson, string $challengeToken): FrontendCredential` — Verify attestation, store credential, fire events.
- `enroll(int $feUserUid, string $siteIdentifier, ...): FrontendCredential` — Convenience method for third-party registration extensions to trigger enrollment programmatically.

This is the public API for integration with registration extensions (femanager, sf_register).
See ADR-004.

#### FrontendEnforcementService
Computes effective enforcement for a FE user:
- `getStatus(int $feUserUid, string $siteIdentifier): FrontendEnforcementStatus`
- Resolution: `max(siteEnforcement, strictestGroupEnforcement)`
- Grace period tracking via `fe_users.passkey_grace_period_start`
- `startGracePeriod(int $feUserUid): void`

#### RecoveryCodeService
Generate and verify one-time recovery codes:
- `generate(int $feUserUid, int $count = 10): array` — Returns plaintext codes, stores hashed. **Invalidates all previously generated codes for that user** (deletes existing rows before inserting new ones).
- `verify(int $feUserUid, string $code): bool` — Verify and consume (mark used_at)
- `countRemaining(int $feUserUid): int`
- Hashing: bcrypt (cost 12)
- Codes: 8 alphanumeric characters, grouped as XXXX-XXXX

#### MagicLinkService (v0.2)
Email-based one-time login:
- `requestLink(string $email, string $siteIdentifier): void`
- `verifyToken(string $token): ?int` — Returns fe_user UID or null
- Token: 64-byte random, stored in cache with 15-min TTL
- Fires `MagicLinkRequestedEvent` for custom email rendering

#### SiteConfigurationService
Resolves per-site WebAuthn configuration:
- `getRpId(SiteInterface $site): string` — From site settings or base URL domain
- `getOrigin(SiteInterface $site): string` — From site settings or base URL
- `getEnforcementLevel(SiteInterface $site): EnforcementLevel`
- `getSiteIdentifier(SiteInterface $site): string` — Returns site identifier from site object
- `getCurrentSite(ServerRequestInterface $request): SiteInterface` — Resolves site from request

#### FrontendAdoptionStatsService
Metrics for admin dashboard:
- `getStats(string $siteIdentifier = ''): FrontendAdoptionStats`
- Total FE users, users with passkeys, per-group breakdown
- Enrollment rate, most popular authenticator types (by AAGUID)

### Domain Objects

#### FrontendCredential (Model)
```php
final class FrontendCredential
{
    // Properties matching tx_nrpasskeysfe_credential columns
    // fromArray() / toArray() for DB mapping
    // No Extbase, plain PHP
}
```

#### RecoveryCode (Model)
```php
final class RecoveryCode
{
    // fe_user, code_hash, used_at, created_at
}
```

#### FrontendEnforcementStatus (DTO)
```php
final readonly class FrontendEnforcementStatus
{
    public function __construct(
        public EnforcementLevel $effectiveLevel,
        public EnforcementLevel $siteLevel,
        public EnforcementLevel $groupLevel,
        public int $passkeyCount,
        public bool $inGracePeriod,
        public ?DateTimeImmutable $graceDeadline,
        public int $recoveryCodesRemaining,
    ) {}
}
```

#### RecoveryMethod (Enum)
```php
enum RecoveryMethod: string
{
    case Password = 'password';
    case RecoveryCode = 'recovery_code';
    case MagicLink = 'magic_link';
}
```

### PSR-14 Events

| Event | When | Payload |
|-------|------|---------|
| `BeforePasskeyEnrollmentEvent` | Before credential is stored | feUserUid, siteIdentifier, attestation |
| `AfterPasskeyEnrollmentEvent` | After successful enrollment | feUserUid, credential, siteIdentifier |
| `BeforePasskeyAuthenticationEvent` | Before assertion verification | feUserUid (if known), assertion |
| `AfterPasskeyAuthenticationEvent` | After successful passkey login | feUserUid, credential |
| `PasskeyRemovedEvent` | When credential is revoked | credential, revokedBy |
| `RecoveryCodesGeneratedEvent` | New codes generated | feUserUid, codeCount |
| `MagicLinkRequestedEvent` | Magic link email requested | feUserUid, email (NO token — security) |
| `EnforcementLevelResolvedEvent` | Enforcement computed | feUserUid, effectiveLevel (mutable) |

All events allow listeners to modify behavior (e.g., `EnforcementLevelResolvedEvent`
allows overriding the computed level for custom business logic).

### Event Listeners

#### InjectPasskeyLoginFields
- **Listens to:** `ModifyLoginFormViewEvent` (from `ext:felogin`)
- **Action:** Injects passkey login button and JavaScript configuration into felogin form
- **Provides:** `window.NrPasskeysFeConfig` with rpId, origin, loginOptionsUrl, etc.

#### InjectPasskeyBanner
- **Listens to:** Appropriate FE rendering event (configurable via TypoScript)
- **Action:** Shows enrollment prompt banner when enforcement >= Encourage
- **Behavior:** Dismissible for Encourage/Required (within grace period),
  non-dismissible for Enforced

### Middleware (PSR-15)

#### PasskeyPublicRouteResolver
- **Position:** After `typo3/cms-frontend/site`, before `typo3/cms-frontend/authentication`
- **Action:** Marks passkey API routes as public (bypass FE auth requirement)
- **Routes:** `/passkeys-fe/login/*`, `/passkeys-fe/recovery/magic-link/verify`

#### PasskeyEnrollmentInterstitial
- **Position:** After `typo3/cms-frontend/authentication`
- **Action:** For authenticated users without passkeys, redirect to enrollment page
  based on enforcement level
- **Behavior:**
  - Off: nothing
  - Encourage: skip (handled by banner only)
  - Required: redirect to enrollment, skip allowed (with session nonce)
  - Enforced: redirect to enrollment, no skip
- **Exemptions:** passkey API routes, logout, password reset

---

## Database Schema

### tx_nrpasskeysfe_credential

Stores WebAuthn credentials for FE users. Mirrors the BE credential table structure
with additions for site/storage scoping.

```sql
CREATE TABLE tx_nrpasskeysfe_credential (
    fe_user int(11) DEFAULT 0 NOT NULL,
    credential_id varbinary(1024) DEFAULT '' NOT NULL,
    public_key_cose blob,
    sign_count int(11) DEFAULT 0 NOT NULL,
    user_handle varbinary(64) DEFAULT '' NOT NULL,
    aaguid char(36) DEFAULT '' NOT NULL,
    transports text,
    label varchar(128) DEFAULT '' NOT NULL,
    site_identifier varchar(255) DEFAULT '' NOT NULL,
    storage_pid int(11) DEFAULT 0 NOT NULL,
    created_at int(11) DEFAULT 0 NOT NULL,
    last_used_at int(11) DEFAULT 0 NOT NULL,
    revoked_at int(11) DEFAULT 0 NOT NULL,
    revoked_by int(11) DEFAULT 0 NOT NULL,

    UNIQUE KEY credential_id (credential_id),
    KEY fe_user (fe_user),
    KEY site_storage (site_identifier, storage_pid)
);
```

### tx_nrpasskeysfe_recovery_code

```sql
CREATE TABLE tx_nrpasskeysfe_recovery_code (
    fe_user int(11) DEFAULT 0 NOT NULL,
    code_hash varchar(255) DEFAULT '' NOT NULL,
    used_at int(11) DEFAULT 0 NOT NULL,
    created_at int(11) DEFAULT 0 NOT NULL,

    KEY fe_user (fe_user)
);
```

### fe_groups Extension

```sql
ALTER TABLE fe_groups
    ADD passkey_enforcement varchar(10) DEFAULT 'off' NOT NULL,
    ADD passkey_grace_period_days int(11) DEFAULT 14 NOT NULL;
```

### fe_users Extension

```sql
ALTER TABLE fe_users
    ADD passkey_grace_period_start int(11) DEFAULT 0 NOT NULL,
    ADD passkey_nudge_until int(11) DEFAULT 0 NOT NULL;
```

**Field purposes:**
- `passkey_grace_period_start`: Unix timestamp when the enforcement grace period began
  for this user. Set by `FrontendEnforcementService.startGracePeriod()` on first login
  after enforcement level changes to Required/Enforced.
- `passkey_nudge_until`: Unix timestamp until which the user receives enrollment nudge
  banners. Used by `InjectPasskeyBanner` listener. Set by admin "send reminder" action.
  After this timestamp, nudging stops (user either enrolled or admin sends another reminder).

---

## Security Model

### Inherited from nr-passkeys-be

- **Challenge tokens:** HMAC-SHA256 signed with single-use nonce; atomic invalidation
  via file-lock prevents TOCTOU replay attacks
- **Rate limiting:** Per-IP/endpoint with configurable window (default 10 req / 5 min)
- **Account lockout:** Per-username+IP after N failures (default 5, 15-min lockout)
- **User enumeration prevention:** Generic error responses, timing normalization
  (50-150ms random delay)
- **Signature counter:** CTAP cloning detection via sign_count verification

### FE-Specific Additions

- **Storage PID scoping:** All credential queries include storage PID. Prevents
  credential leakage between separate FE user pools on the same TYPO3 instance.
- **Credential-ID-to-UID resolution:** Resolves credentials by fe_user.uid, never by
  username. Avoids ambiguity across storage folders (ref: TYPO3-SA-2024-006).
- **CSRF protection:** TYPO3 `FormProtection` on all state-changing **authenticated** FE
  endpoints. For unauthenticated login endpoints (`/login/options`, `/login/verify`),
  CSRF protection is provided by the WebAuthn challenge-response mechanism itself: the
  challenge token is bound to the server-side session/nonce and is single-use, making
  cross-origin replay impossible without the challenge.
- **Recovery code hashing:** bcrypt with cost factor 12. Codes are never stored in
  plaintext.
- **Magic link tokens:** 64-byte random, stored in TYPO3 cache with 15-min TTL,
  single-use (deleted after verification).
- **Site-bound credentials:** `site_identifier` stored with each credential, enforced
  on verification. Credentials from site A cannot be used on site B even with same RP ID.

### Threat Model

| Threat | Mitigation |
|--------|------------|
| Credential theft | WebAuthn: private key never leaves authenticator |
| Replay attack | Single-use nonce with atomic invalidation |
| Brute force | Rate limiting + account lockout |
| User enumeration | Generic errors + timing normalization |
| CSRF (authenticated) | TYPO3 FormProtection tokens |
| CSRF (unauthenticated) | WebAuthn challenge-response binding |
| Cross-site credential use | Storage PID + site_identifier scoping |
| Recovery code brute force | bcrypt hashing + rate limiting |
| Magic link interception | Short TTL (15 min) + single-use |
| Authenticator cloning | Signature counter verification |
| Phishing | WebAuthn origin binding (built into spec) |

---

## Frontend JavaScript

### Architecture

Vanilla ES6 modules, no framework dependencies. Progressive enhancement: forms work
without JavaScript (submit to server-side handler), JavaScript enhances with WebAuthn API.

### Modules

#### PasskeyLogin.js
- Login ceremony via `navigator.credentials.get()`
- Discoverable login (no username) and username-first flows
- Handles: API calls, base64url encoding, error display
- Conditional UI: checks `PublicKeyCredential.isConditionalMediationAvailable()`

#### PasskeyEnrollment.js
- Registration ceremony via `navigator.credentials.create()`
- Label input for naming the passkey
- Handles: API calls, attestation encoding, success/error states

#### PasskeyManagement.js
- List credentials with metadata (label, created, last used, AAGUID)
- Rename, remove with confirmation
- Recovery codes: generate, display, download

#### PasskeyRecovery.js
- Recovery code input form (XXXX-XXXX format)
- Magic link request form (email input)

#### PasskeyBanner.js
- Enrollment prompt banner (dismissible or mandatory)
- Cookie-based dismissal tracking (for Encourage level)
- Configurable position and styling via data attributes

### Browser Compatibility

- All browsers supporting WebAuthn Level 2 (Chrome 67+, Firefox 60+, Safari 14+, Edge 79+)
- Feature detection before showing passkey UI
- Graceful degradation: if WebAuthn unavailable, passkey UI hidden, password login shown

### Platform Authenticator Detection

Before showing enrollment UI, check `PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()`.
If no platform authenticator is available (older desktops without Windows Hello/TPM/Touch ID):
- Login plugin: still shows passkey button (user may have a security key)
- Enrollment: shows informational message explaining hardware requirements
- Enforcement: grace period continues; user is not blocked if they cannot physically
  register a passkey. The enrollment interstitial shows a "My device doesn't support this"
  option that extends the grace period.

### Content Security Policy (CSP)

The vanilla JS approach with `data-*` attributes is CSP-friendly:
- No `unsafe-inline` needed for scripts
- No `unsafe-eval` needed
- `connect-src 'self'` sufficient for API calls to same origin
- `script-src` needs the nonce/hash for the module script tag
- TYPO3 v13/v14 CSP support is respected via `AssetCollector`

---

## Configuration

### Extension Settings (Admin Tools → Settings → Extension Configuration)

Inherits nr-passkeys-be settings where applicable. FE-specific additions:

| Setting | Default | Description |
|---------|---------|-------------|
| `enableFePasskeys` | `true` | Master switch for FE passkey authentication |
| `defaultEnforcementLevel` | `off` | Site-wide default enforcement |
| `maxPasskeysPerUser` | `10` | Maximum passkeys a user can register |
| `recoveryCodesEnabled` | `true` | Allow recovery code generation |
| `recoveryCodeCount` | `10` | Number of codes generated per set |
| `magicLinkEnabled` | `false` | Enable magic link recovery (v0.2) |
| `magicLinkTtlSeconds` | `900` | Magic link token TTL |
| `enrollmentBannerEnabled` | `true` | Show enrollment prompt banners |
| `postLoginEnrollmentEnabled` | `true` | Enable post-login enrollment flow |

### Site Configuration (config/sites/*/settings.yaml)

```yaml
nr_passkeys_fe:
  rpId: 'example.com'              # Override RP ID (default: site base domain)
  origin: 'https://example.com'    # Override origin (default: site base URL)
  enforcement: 'off'               # Site-level enforcement
  enabledRecoveryMethods:          # Which recovery methods are active
    - password
    - recovery_code
    # - magic_link                 # v0.2
```

### TypoScript

```typoscript
plugin.tx_nrpasskeysfe {
    settings {
        loginPage = {$plugin.tx_nrpasskeysfe.loginPageUid}
        managementPage = {$plugin.tx_nrpasskeysfe.managementPageUid}
        enrollmentPage = {$plugin.tx_nrpasskeysfe.enrollmentPageUid}
        css {
            includeDefault = 1
        }
    }
}
```

### TCA

#### fe_groups Override
New tab "Passkey Enforcement" with:
- `passkey_enforcement`: Select (Off, Encourage, Required, Enforced)
- `passkey_grace_period_days`: Input (integer, default 14)

#### fe_users Override
New tab "Passkeys" with:
- Read-only display of registered passkeys (via FormEngine element)
- `passkey_grace_period_start`: Hidden/read-only
- `passkey_nudge_until`: Hidden/read-only

---

## Plugins

### PasskeyLoginPlugin

**Extbase plugin** for standalone passkey login. Place on any page.

**FlexForm settings:**
- Enable discoverable login (yes/no)
- Show password fallback link (yes/no)
- Redirect after login (page selection)
- CSS class override

**Templates:**
- `Resources/Private/Templates/Login/Index.html` — Main login form
- `Resources/Private/Templates/Login/Recovery.html` — Recovery code entry
- `Resources/Private/Partials/Login/PasskeyButton.html` — Reusable passkey button

### PasskeyManagementPlugin

**Extbase plugin** for self-service passkey management. Place on "My Account" page.
Requires authenticated FE user.

**Features:**
- List registered passkeys (label, created, last used, device type)
- Register new passkey
- Rename passkey
- Remove passkey (with confirmation)
- Recovery codes: generate new set, view remaining count, download codes

**Templates:**
- `Resources/Private/Templates/Management/Index.html` — Main management view
- `Resources/Private/Templates/Management/Enrollment.html` — New passkey enrollment
- `Resources/Private/Templates/Management/RecoveryCodes.html` — Recovery code management

### PasskeyEnrollmentPlugin

**Extbase plugin** for the enrollment interstitial / post-login enrollment page.

**Templates:**
- `Resources/Private/Templates/Enrollment/Index.html` — Enrollment prompt
- `Resources/Private/Templates/Enrollment/Success.html` — Enrollment complete

---

## Backend Admin Module

TYPO3 backend module under a suitable module group for managing FE user passkeys.

### Dashboard View
- Total FE users, users with passkeys, adoption percentage
- Per-group breakdown with enforcement levels
- Enrollment trend chart (last 30/90 days)
- Quick actions: send bulk reminders, change site enforcement

### User Management View
- Search/filter FE users by passkey status
- Per-user actions: view credentials, revoke, revoke all, unlock lockout
- Grace period management: start, reset, extend

### Enforcement View
- Overview of all fe_groups with current enforcement settings
- Bulk update enforcement levels
- Grace period configuration per group

---

## Versioning & Roadmap

### v0.1.0 — MVP
- Passkey login (discoverable + username-first)
- felogin integration (PSR-14)
- Standalone login plugin
- Self-service management plugin
- Recovery codes
- Password fallback
- Per-group + per-site enforcement (Off, Encourage, Required)
- **Note:** "Enforced" level is available but documented as requiring v0.2 magic link
  for complete lockout prevention. Admin unlock is the emergency escape in v0.1.
  See ADR-011 for details.
- Post-login enrollment interstitial
- Backend admin module (basic: user management, credential revocation, admin unlock)
- Full test suite
- Documentation

**Admin unlock in v0.1:** The admin module provides per-user actions:
- "Unlock account" — clears rate limit lockout
- "Revoke all passkeys" — removes all credentials, forces password login
- "Reset grace period" — restarts enforcement grace period
These are the emergency escape hatches before magic link is available.

### v0.2.0 — Magic Link + Admin Polish
- Magic link recovery via email
- Admin module: adoption dashboard, enrollment trend charts
- Bulk reminder emails
- Conditional UI (autocomplete="webauthn")

### v0.3.0 — Advanced Features
- Attestation metadata service (FIDO MDS) for authenticator identification
- Per-credential trust level (platform vs. cross-platform)
- Audit log export
- GDPR data export/deletion hooks

### v1.0.0 — Stable Release
- API stability guarantee
- TER publication
- Full RST documentation on docs.typo3.org

---

## Testing Strategy

### Test Pyramid

```
         ╱╲
        ╱ E2E ╲           Playwright: full browser flows
       ╱────────╲          with virtual authenticator
      ╱ Integr.  ╲        TYPO3 bootstrap, full login flows
     ╱────────────╲
    ╱  Functional   ╲     TYPO3 Testing Framework, DB
   ╱──────────────────╲
  ╱    Unit Tests       ╲  PHPUnit, isolated, fast
 ╱────────────────────────╲
╱  Fuzz │ Arch │ Mutation   ╲  Eris, PHPat, Infection
╱────────────────────────────╲
```

### Unit Tests (~60% of test count)
Target: every public method of every service, DTO, enum, model.

**Coverage areas:**
- `PasskeyFrontendAuthenticationService`: all auth paths (passkey, discoverable,
  password-blocked, lockout, enforcement)
- `FrontendCredentialRepository`: query building, storage PID scoping
- `FrontendEnforcementService`: level resolution, grace period logic, site+group merge
- `RecoveryCodeService`: generation, hashing, verification, consumption
- `MagicLinkService`: token generation, TTL, single-use
- `SiteConfigurationService`: RP ID derivation, override, multi-domain
- All DTOs: construction, immutability
- All Enums: values, `Valid()` equivalent, severity ordering
- Event classes: construction, payload access
- ViewHelpers: rendering output

### Functional Tests (~20%)
TYPO3 Testing Framework with MySQL backend.

**Coverage areas:**
- Credential CRUD operations against real database
- TCA configuration (fe_groups/fe_users field rendering)
- Plugin rendering (FlexForm, TypoScript)
- Middleware registration and ordering
- Event listener registration
- Auth service registration and priority

### Integration Tests (~10%)
Full TYPO3 bootstrap, multi-step flows.

**Coverage areas:**
- Complete login flow: password → enrollment prompt → passkey registration → passkey login
- Recovery flow: passkey fails → recovery code → login
- Enforcement escalation: Off → Encourage → Required → Enforced
- Multi-site: credentials isolated between sites
- Storage PID: credentials isolated between user pools

### Fuzz Tests
Property-based testing via Eris for security-sensitive inputs.

- Challenge token fuzzing (malformed tokens, truncated, wrong HMAC)
- Credential ID fuzzing (oversized, empty, special characters)
- Recovery code input fuzzing (wrong format, unicode, injection)
- Request payload fuzzing (malformed JSON, missing fields, extra fields)

### Architecture Tests (PHPat)
Enforce dependency rules:

- Controllers → Services (allowed)
- Controllers → Repository (forbidden, go through services)
- Services → Domain (allowed)
- Domain → nothing (no framework dependencies)
- Events → nothing (immutable, no dependencies)
- No circular dependencies

### E2E Tests (Playwright)
Browser automation with virtual WebAuthn authenticator.

- Full passkey login flow
- Discoverable login (no username)
- Passkey enrollment after password login
- Self-service: register, rename, remove passkey
- Recovery code generation and use
- Enrollment interstitial behavior per enforcement level
- felogin integration (passkey button appears)
- Admin module: view users, revoke credentials

### JavaScript Tests (Vitest)
- WebAuthn API interaction (mocked navigator.credentials)
- Base64url encoding/decoding
- API call handling (success, error, timeout)
- Form state management
- Progressive enhancement detection
- Banner dismiss behavior

### Mutation Testing (Infection)
- Target: MSI >= 80%
- Focus: security-critical paths (auth service, challenge verification,
  recovery code verification, enforcement resolution)
- Configuration: `Build/infection.json5`

---

## Documentation Plan

### RST Documentation (Documentation/)

```
Documentation/
├── Index.rst                    # Landing page
├── guides.xml                   # Navigation structure
├── Introduction/
│   └── Index.rst                # Features, authenticator support, screenshots
├── Installation/
│   └── Index.rst                # Composer, TYPO3 setup, first passkey
├── Configuration/
│   ├── Index.rst                # Overview
│   ├── ExtensionSettings.rst    # Admin Tools settings
│   ├── SiteConfiguration.rst    # Per-site YAML settings
│   └── TypoScript.rst           # Plugin configuration
├── QuickStart/
│   └── Index.rst                # 5-minute setup guide
├── Usage/
│   ├── Login.rst                # Passkey login for end users
│   ├── Enrollment.rst           # Setting up passkeys
│   ├── Recovery.rst             # Recovery mechanisms
│   └── Management.rst           # Self-service management
├── Administration/
│   ├── Index.rst                # Admin overview
│   ├── Dashboard.rst            # Adoption stats
│   ├── Enforcement.rst          # Per-group/site enforcement
│   ├── UserManagement.rst       # Per-user actions
│   └── BulkOperations.rst       # Reminders, mass actions
├── DeveloperGuide/
│   ├── Index.rst                # Developer overview
│   ├── Events.rst               # PSR-14 events reference
│   ├── ExtensionPoints.rst      # Hooks for registration extensions
│   ├── Api.rst                  # REST API reference
│   └── CustomTemplates.rst      # Fluid template override
├── Security/
│   ├── Index.rst                # Security overview
│   ├── WebAuthnCompliance.rst   # Spec compliance details
│   └── ThreatModel.rst         # Threat model + mitigations
├── MultiSite/
│   └── Index.rst                # Multi-domain configuration guide
├── Troubleshooting/
│   └── Index.rst                # Common issues + solutions
├── Adr/
│   ├── Index.rst                # ADR index
│   ├── Adr001DependOnNrPasskeysBe.rst
│   ├── Adr002FeloginAndStandalonePlugin.rst
│   ├── Adr003TripleRecoveryMechanisms.rst
│   ├── Adr004EnrollmentOnlyNoRegistration.rst
│   ├── Adr005SiteConfigurableRpId.rst
│   ├── Adr006DualEnforcementModel.rst
│   ├── Adr007PostLoginEnrollmentInterstitial.rst
│   ├── Adr008CredentialIdToUidResolution.rst
│   ├── Adr009VanillaJavaScriptFrontend.rst
│   ├── Adr010RecoveryCodesBcryptHashed.rst
│   └── Adr011MagicLinkDeferredToV02.rst
└── Changelog/
    └── Index.rst                # Version history
```

### In-Extension Help

- Backend module: contextual help panels on each view
- FE plugins: configurable help text via TypoScript constants
- FlexForm fields: descriptions and CSH (context-sensitive help)
- TCA fields: descriptions for fe_groups/fe_users passkey fields

---

## CI/CD

Delegates to `netresearch/typo3-ci-workflows`:

```yaml
# .github/workflows/ci.yml
name: CI
on: [push, pull_request, merge_group]
jobs:
  ci:
    uses: netresearch/typo3-ci-workflows/.github/workflows/ci.yml@main
    with:
      php-versions: '["8.2","8.3","8.4","8.5"]'
      typo3-versions: '["^13.4","^14.1"]'
      upload-coverage: true
```

Additional workflows:
- `ter-publish.yml` — TER publishing on tag
- `pr-quality.yml` — Auto-approve + Copilot review
- `codeql.yml` — Code scanning
- `scorecard.yml` — OpenSSF assessment
- `e2e.yml` — Playwright E2E tests
- `release.yml` — Tag-triggered release (SBOM, Cosign, attestation)
- `fuzz.yml` — Fuzz + mutation testing

---

## Open Questions

1. **felogin PSR-14 event availability in v14:** Verify `ModifyLoginFormViewEvent` exists
   and has sufficient payload in TYPO3 v14.
2. **nr-passkeys-be minimum version:** Which version of nr-passkeys-be exposes the
   services needed? May need to define interface contracts.
3. **Magic link email template:** Use TYPO3 FluidEmail or custom? Depends on v0.2 scope.
