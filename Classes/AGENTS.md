<!-- FOR AI AGENTS - Scoped to Classes/ -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 -->

# Classes/ AGENTS.md

## Overview

**Scope:** PHP source code for `nr_passkeys_fe`. All classes live under
`Netresearch\NrPasskeysFe\` (PSR-4 from `Classes/`). Layering (inner to outer):
Domain (Model + Dto) -> Service -> Controller / Authentication / Middleware /
EventListener — enforced by PHPat (see `Tests/Architecture/` and `docs/ARCHITECTURE.md`).

## Setup

- `composer install` from the repo root (installs into `.Build/`).
- The BE dependency `netresearch/nr-passkeys-be` provides WebAuthn ceremonies,
  challenge service, and rate limiter — do not reimplement those here.

## Tests & checks

- After every change here: `composer ci:test:php:cgl` + `composer ci:test:php:phpstan` (level 10).
- Unit tests: `composer ci:test:php:unit` (includes the PHPat architecture rules via PHPStan).
- DB-touching code (repositories, upgrade wizards) needs functional tests: `composer ci:test:php:functional` (MySQL, CI/DDEV only).

## Namespace Structure (verified)

All classes are under `Netresearch\NrPasskeysFe\` (PSR-4 from `Classes/`).

```
Authentication/
  PasskeyFrontendAuthenticationService   -> TYPO3 auth service (priority 80 via ext_localconf)
Configuration/
  FrontendConfiguration                  -> Value object for extension + site config
Controller/
  EidDispatcher                          -> Routes eID requests to sub-controllers
  LoginController                        -> Passkey auth (options, verify, recovery)
  EnrollmentController                   -> Passkey enrollment (options, verify)
  ManagementController                   -> Self-service (list, rename, remove, recovery codes)
  RecoveryController                     -> Recovery code login
  AdminController                        -> Admin-only user management
  AdminModuleController                  -> Backend module renderer (Fluid)
  JsonBodyTrait                          -> Shared JSON request parsing
Domain/
  Dto/                                   -> Typed request/response DTOs
  Enum/RecoveryMethod                    -> Recovery method enum
  Model/FrontendCredential               -> Credential entity (plain PHP)
  Model/RecoveryCode                     -> Recovery code entity (plain PHP)
Event/                                   -> 7 PSR-14 event classes (see Events.rst)
EventListener/                           -> felogin integration, encourage banner
Form/Element/PasskeyFeInfoElement        -> TCA read-only display in fe_users records
Middleware/
  PasskeyPublicRouteResolver             -> Allows unauthenticated eID access
  PasskeyEnrollmentInterstitial          -> Redirects non-passkey users after login
Service/
  FrontendWebAuthnService                -> WebAuthn ceremonies (wraps BE's WebAuthnService)
  SiteConfigurationService               -> Reads passkeys.* from site config.yaml
  FrontendCredentialRepository           -> DB access for tx_nrpasskeysfe_credential
  FrontendEnforcementService             -> Resolves effective enforcement level
  RecoveryCodeService                    -> Recovery code generation + verification
  PasskeyEnrollmentService               -> Orchestrates enrollment ceremony + saves credential
  FrontendAdoptionStatsService           -> Statistics for backend admin dashboard
  FrontendUserLookupService              -> fe_users table lookup (extracted from credential repo)
```

## Code style (PHP conventions)

- `declare(strict_types=1)` in every file
- `final` on service classes and event classes (allow extension via events, not inheritance)
- `readonly` properties on DTOs and immutable value objects
- `GeneralUtility::makeInstance()` for classes used in the auth service chain
- Constructor DI everywhere else (autowired via Services.yaml)
- Return types always declared
- Never suppress PHPStan errors -- fix the root cause

## Examples (key patterns)

### Auth service (no DI)
```php
// In PasskeyFrontendAuthenticationService
$service = GeneralUtility::makeInstance(FrontendWebAuthnService::class);
```

### eID controller response
```php
// Use PSR-7 JsonResponse via JsonBodyTrait
return new JsonResponse(['success' => true]);
```

### PSR-14 event dispatch
```php
$event = $this->eventDispatcher->dispatch(
    new AfterPasskeyEnrollmentEvent($feUserUid, $credential, $siteIdentifier)
);
```

### Token-based auth flow (eID → felogin)
The eID endpoint (LoginController/RecoveryController) verifies passkey/recovery credentials
and stores a short-lived token in the TYPO3 cache. JavaScript submits this token via the
standard felogin form as `_token_authenticated` payload. The auth service reads the token
from cache during `getUser()` / `authUser()`.

**Critical:** `getUser()` and `authUser()` run on **DIFFERENT** service instances.
Do not rely on instance properties set in `getUser()` during `authUser()`. Both methods
must independently parse the passkey payload from `uident`.

### Recovery code verification (constant-time)
```php
if (!hash_equals($storedHash, password_hash($submitted, PASSWORD_BCRYPT))) {
    // reject
}
// Correct pattern: password_verify()
if (!password_verify($submitted, $storedHash)) {
    // reject
}
```

## Security

- Recovery codes: hash with bcrypt, verify with `password_verify()` — never string comparison.
- Never skip nonce/HMAC verification in challenge handling; challenges are single-use.
- Validate and type-check every eID request payload (`JsonBodyTrait`) before use.
- Credential IDs are binary — always base64url-encode at boundaries, never log raw payloads.

## PR Checklist
- [ ] `composer ci:test:php:cgl` and `composer ci:test:php:phpstan` pass
- [ ] New code paths covered by unit tests (functional for DB access)
- [ ] State-changing operations dispatch their PSR-14 event
- [ ] No new PHPStan suppressions

## When stuck
- Check the Golden Samples table in the root AGENTS.md for a reference implementation.
- Component map + dependency rules: `docs/ARCHITECTURE.md`.
- WebAuthn ceremony internals live in the BE extension (`netresearch/nr-passkeys-be`), not here.
- eID endpoint reference: Documentation/DeveloperGuide/Api.rst.

## Boundaries
- Do NOT use Extbase repositories or QuerySettings
- Do NOT access `$GLOBALS['TYPO3_REQUEST']` in the auth service (it's null)
- Domain models use `fromArray()` / `toArray()` for DB serialization
- Events in `Event/` are dispatched, not subscribed (listeners live in `EventListener/`)
