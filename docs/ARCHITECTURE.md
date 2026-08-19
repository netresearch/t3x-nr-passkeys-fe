# Architecture

Agent-facing component map for `nr_passkeys_fe`. Facts below are verified against
`Classes/`, `Configuration/`, and `Tests/Architecture/ArchitectureTest.php`; keep
this file in sync when those change. User-facing architecture documentation lives
in `Documentation/DeveloperGuide/`.

## System overview

The extension adds passwordless WebAuthn/FIDO2 authentication for TYPO3 frontend
users. Browser-side vanilla-JS modules run the WebAuthn ceremonies against eID
endpoints; a TYPO3 authentication service turns a verified ceremony into a felogin
session via a short-lived cache token. WebAuthn primitives (ceremonies, challenge
service, rate limiter) come from the `netresearch/nr-passkeys-be` Composer
dependency and are wrapped, not reimplemented.

## Components

| Component | Path | Role |
|-----------|------|------|
| Auth service | `Classes/Authentication/PasskeyFrontendAuthenticationService.php` | TYPO3 auth chain (priority 80); consumes cache tokens |
| eID dispatcher | `Classes/Controller/EidDispatcher.php` | Routes eID requests to sub-controllers |
| Controllers | `Classes/Controller/` | Login, Enrollment, Management, Recovery, Admin, AdminModule |
| Services | `Classes/Service/` | WebAuthn wrapper, site config, credential repository, enforcement, recovery codes, enrollment, adoption stats, user lookup |
| Domain | `Classes/Domain/` | DTOs, enums, plain-PHP models (FrontendCredential, RecoveryCode) |
| Events | `Classes/Event/` | 7 PSR-14 event classes (pure data carriers) |
| Event listeners | `Classes/EventListener/` | felogin integration, encourage banner |
| Middleware | `Classes/Middleware/` | PasskeyPublicRouteResolver, PasskeyEnrollmentInterstitial |
| Form element | `Classes/Form/Element/PasskeyFeInfoElement.php` | Read-only TCA display in fe_users |
| Adoption provider | `Classes/Adoption/FrontendPasskeyAdoptionStatsProvider.php` | Stats provider for the BE extension's dashboard |
| Upgrade wizards | `Classes/Updates/PluginListTypeToCTypeUpdateWizard.php` | list_type -> CType plugin migration |
| DI / registration | `Configuration/Services.yaml`, `Configuration/Services.php` | Autowiring; public services for the auth chain |
| Browser modules | `Resources/Public/JavaScript/` | 8 vanilla-JS ES modules (WebAuthn ceremonies, UI) |

## Dependency rules (enforced)

Enforced by PHPat via PHPStan — `Tests/Architecture/ArchitectureTest.php`.
Layering (inner to outer): Domain (Model + Dto) -> Service -> Controller /
Authentication / Middleware / EventListener.

- Domain must not depend on Controller, Middleware, Authentication, EventListener, Form, or Service.
- Services must not depend on Controller, Middleware, EventListener, or Form.
- Controllers must not use `ConnectionPool`/`QueryBuilder` directly (repositories only).
- EventListeners must not depend on Controller, Middleware, Authentication, or Form.
- Authentication must not depend on Controller, Middleware, EventListener, or Form.
- Events are pure data carriers: no dependency on Service, Controller, Middleware, Authentication, EventListener, or Form.
- Form elements must not depend on Controller, Middleware, Authentication, or EventListener.
- Services, controllers, DTOs, domain models, event listeners, the auth service, and middleware are all `final`.

## Data flow: passkey login

1. `PasskeyLogin.js` fetches assertion options from the eID endpoint (`EidDispatcher` -> `LoginController`).
2. The browser runs the WebAuthn ceremony; the JS posts the assertion back to the eID endpoint.
3. `LoginController` verifies via `FrontendWebAuthnService` (wrapping the BE extension) and stores a short-lived token in the TYPO3 cache.
4. The JS submits the token through the standard felogin form.
5. `PasskeyFrontendAuthenticationService` reads the token from the cache in `getUser()`/`authUser()` (separate instances — no shared state) and authenticates the fe_user.

Recovery-code login follows the same token pattern through `RecoveryController`.
Enforcement (Off -> Encourage -> Required -> Enforced) is resolved by
`FrontendEnforcementService` from site settings plus fe_groups overrides and
applied post-login by `PasskeyEnrollmentInterstitial`.

## Key decisions

Recorded as ADRs in `Documentation/Adr/` (ADR-001 … ADR-012), rendered to
docs.typo3.org. Highlights: depend on nr-passkeys-be (001), felogin + standalone
plugin (002), enrollment-only, no self-registration (004), site-configurable
RP ID (005), dual enforcement model (006), vanilla-JS frontend (009), bcrypt-hashed
recovery codes (010), auth service priority 80 (012). Do not duplicate ADR content
here — link to it.
