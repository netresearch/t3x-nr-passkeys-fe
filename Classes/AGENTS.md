<!-- FOR AI AGENTS - Scoped to Classes/ -->
<!-- Last updated: 2026-03-15 -->

# Classes/ AGENTS.md

**Scope:** PHP source code for `nr_passkeys_fe`.

## Namespace Structure

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
Event/                                   -> 8 PSR-14 event classes (see Events.rst)
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
```

## PHP Conventions

- `declare(strict_types=1)` in every file
- `final` on service classes and event classes (allow extension via events, not inheritance)
- `readonly` properties on DTOs and immutable value objects
- `GeneralUtility::makeInstance()` for classes used in the auth service chain
- Constructor DI everywhere else (autowired via Services.yaml)
- Return types always declared
- Never suppress PHPStan errors -- fix the root cause

## Key Patterns

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

## Boundaries
- Do NOT use Extbase repositories or QuerySettings
- Do NOT access `$GLOBALS['TYPO3_REQUEST']` in the auth service (it's null)
- Domain models use `fromArray()` / `toArray()` for DB serialization
- Events in `Event/` are dispatched, not subscribed (listeners live in `EventListener/`)
