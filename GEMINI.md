<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-03-15 | Last verified: 2026-03-15 -->

# AGENTS.md

**Precedence:** The **closest AGENTS.md** to changed files wins. Root holds global defaults only.

## Project Overview

**nr_passkeys_fe** -- TYPO3 extension for passwordless **frontend** authentication via WebAuthn/FIDO2
Passkeys. Supports TouchID, FaceID, YubiKey, Windows Hello for frontend users (``fe_users``).
Includes felogin integration, self-service management, recovery codes, per-site + per-group
enforcement (Off → Encourage → Required → Enforced), post-login enrollment interstitial,
backend admin module, and 8 PSR-14 events.

Requires ``netresearch/nr-passkeys-be`` ^0.6 as a Composer dependency (reuses WebAuthn
ceremonies, challenge service, rate limiter). See ADR-001.

| Key | Value |
|-----|-------|
| Vendor | Netresearch DTT GmbH |
| Composer | `netresearch/nr-passkeys-fe` |
| Extension key | `nr_passkeys_fe` |
| Namespace | `Netresearch\NrPasskeysFe` |
| TYPO3 | ^13.4 \|\| ^14.1 |
| PHP | ^8.2 |
| Depends on | `netresearch/nr-passkeys-be` ^0.6 |

## Global Rules
- Conventional Commits: `type(scope): subject`
- `declare(strict_types=1)` in all PHP files
- PER-CS3.0 code style via php-cs-fixer
- PHPStan level 10 (do not lower)
- Do NOT commit `composer.lock` (library, not application)

## Commands (verified)
> Source: `composer.json` scripts, `Makefile`

| Task | Command | ~Time |
|------|---------|-------|
| Install | `composer install` | 30s |
| CGL (check) | `composer ci:test:php:cgl` | 5s |
| CGL (fix) | `composer ci:cgl` | 5s |
| Static analysis | `composer ci:test:php:phpstan` | 10s |
| Unit tests | `composer ci:test:php:unit` | 5s |
| Fuzz tests | `composer ci:test:php:fuzz` | 5s |
| Functional tests | `composer ci:test:php:functional` | 30s |
| Unit + functional | `composer ci:test:php:all` | 35s |
| JS tests | `npx vitest run` | 2s |
| E2E tests | `npx playwright test` | 30s |
| Mutation testing | `composer ci:mutation` | 60s |
| Local CI (no DB) | `make ci` | 25s |
| DDEV full setup | `make up` | 5m |

## File Map
```
Classes/                       -> PHP source (PSR-4: Netresearch\NrPasskeysFe\)
  Authentication/               -> PasskeyFrontendAuthenticationService (auth chain)
  Configuration/                -> Site + extension configuration value objects
  Controller/                   -> EidDispatcher, Login, Enrollment, Management,
                                   Recovery, Admin, AdminModule controllers
  Domain/Dto/                   -> Typed DTOs
  Domain/Enum/                  -> RecoveryMethod enum
  Domain/Model/                 -> FrontendCredential, RecoveryCode (plain PHP)
  Event/                        -> 8 PSR-14 event classes
  EventListener/                -> felogin integration, encourage banner
  Form/Element/                 -> PasskeyFeInfoElement (TCA read-only)
  Middleware/                   -> PasskeyPublicRouteResolver, PasskeyEnrollmentInterstitial
  Service/                      -> FrontendWebAuthnService, SiteConfigurationService,
                                   FrontendCredentialRepository, FrontendEnforcementService,
                                   RecoveryCodeService, PasskeyEnrollmentService,
                                   FrontendAdoptionStatsService
Build/                         -> Tooling configuration (NOT .Build/ which is composer output)
Configuration/                 -> TYPO3 config (TCA, FlexForms, Services.yaml, TypoScript,
                                   RequestMiddlewares, JavaScriptModules)
Documentation/                 -> TYPO3 RST documentation (docs.typo3.org format)
  Adr/                          -> 12 Architecture Decision Records
Resources/Private/             -> Fluid templates (Login, Enrollment, Management, AdminModule)
Resources/Public/JavaScript/   -> 7 JS modules (Login, Enrollment, Management, Recovery,
                                   RecoveryCodes, Banner, FeAdmin)
Tests/Unit/                    -> Unit tests (PHPUnit)
Tests/Functional/              -> Functional tests (require MySQL, CI only)
Tests/Fuzz/                    -> Fuzz tests (property-based)
Tests/Architecture/            -> PHPat architecture tests
Tests/JavaScript/              -> JS unit tests (Vitest)
Tests/E2E/                     -> E2E tests (Playwright, targets DDEV v13)
Makefile                       -> make up, make ci, make help
.github/workflows/             -> CI, TER Publish, PR Quality Gates, CodeQL, OpenSSF Scorecard
```

## Golden Samples
| For | Reference | Key patterns |
|-----|-----------|-------------|
| Service | `Classes/Service/SiteConfigurationService.php` | DI, site-aware config |
| Controller | `Classes/Controller/LoginController.php` | eID, JSON responses |
| Auth service | `Classes/Authentication/PasskeyFrontendAuthenticationService.php` | GeneralUtility::makeInstance() |
| Event | `Classes/Event/AfterPasskeyAuthenticationEvent.php` | `final readonly`, docblock |
| Mutable event | `Classes/Event/EnforcementLevelResolvedEvent.php` | Setter + mutable pattern |
| Middleware | `Classes/Middleware/PasskeyEnrollmentInterstitial.php` | PSR-15, enforcement |
| Domain model | `Classes/Domain/Model/FrontendCredential.php` | Plain PHP, fromArray/toArray |
| TCA | `Configuration/TCA/tx_nrpasskeysfe_credential.php` | TYPO3 TCA patterns |
| Unit test | `Tests/Unit/Service/` | PHPUnit, bypass-finals |
| JS module | `Resources/Public/JavaScript/PasskeyLogin.js` | Vanilla JS, WebAuthn API |

## Heuristics
| When | Do |
|------|----|
| Adding a service | Use constructor DI via Services.yaml |
| Auth service deps | Use `GeneralUtility::makeInstance()` (no DI in auth chain) |
| eID controller returns JSON | Use `JsonBodyTrait`, PSR-7 JsonResponse |
| Database access | Use QueryBuilder, never raw SQL |
| Testing final classes | Use `dg/bypass-finals` + PHPUnit test doubles |
| Functional test needs DB | Only run in CI (MySQL required) |
| Enforcement logic | Read site config first, then group overrides, dispatch event |
| Recovery code verification | Always use constant-time comparison (hash_equals) |
| Releasing a version | Bump `ext_emconf.php` + `guides.xml` version together |
| Adding admin API endpoint | Add to eID dispatcher routing, document in DeveloperGuide/Api.rst |
| New PSR-14 event | Add to `Classes/Event/`, dispatch in relevant service/controller |

## Boundaries

### Always Do
- Run `composer ci:test:php:cgl` and `composer ci:test:php:phpstan` before committing
- Add tests for new code paths (unit preferred, functional for DB)
- Use conventional commit format
- Validate all user inputs in controllers and eID dispatcher
- Show test output as evidence before claiming work is complete
- Dispatch the appropriate PSR-14 event after state-changing operations

### Never Do
- Lower PHPStan level below 10
- Add runtime npm dependencies to the JavaScript modules
- Skip nonce/HMAC verification in challenge handling
- Share a single resource instance across multiple tests

### Ask First
- Adding new Composer dependencies
- Changing the database schema
- Modifying CI/CD configuration
- Changing enforcement model semantics
