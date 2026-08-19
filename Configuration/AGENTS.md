<!-- FOR AI AGENTS - Scoped to Configuration/ -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 -->

# Configuration/ AGENTS.md

## Overview

**Scope:** TYPO3 configuration files for `nr_passkeys_fe`: TCA, FlexForms,
DI wiring (Services.yaml + Services.php), TypoScript, backend module and
middleware registration. All registrations live here — this extension
deliberately ships no legacy ext_tables PHP file.

## Setup

No extra setup beyond `composer install`. Changes here are picked up after a
TYPO3 cache flush in the consuming installation.

## Tests

- TCA is covered by `Tests/Functional/Configuration/TcaTest.php` — run `composer ci:test:php:functional` (MySQL, CI/DDEV only).
- DI wiring errors surface in unit + functional bootstraps: `composer ci:test:php:all`.

## Structure

```
Configuration/
  Backend/                     -> Backend module registration (Modules.php)
  FlexForms/
    EnrollmentPlugin.xml       -> FlexForm for NrPasskeysFe:Enrollment plugin
    LoginPlugin.xml            -> FlexForm for NrPasskeysFe:Login plugin
    ManagementPlugin.xml       -> FlexForm for NrPasskeysFe:Management plugin
  Icons.php                    -> Icon registry (SVG icons)
  JavaScriptModules.php        -> ES module import map (8 modules)
  RequestMiddlewares.php       -> PSR-15 middleware registration
  Services.yaml                -> Symfony DI wiring
  TCA/
    tx_nrpasskeysfe_credential.php   -> Credential table TCA
    tx_nrpasskeysfe_recovery_code.php -> Recovery code table TCA
    Overrides/                 -> TCA overrides for fe_users and fe_groups
  TypoScript/
    constants.typoscript       -> Constants (page UIDs)
    setup.typoscript           -> Plugin view paths + settings
```

## Examples (TCA patterns)

- All TCA arrays use `'type' => 'passthrough'` for binary credential fields
- The `passkey_fe_info` field uses the custom `passkey_fe_info` renderType
  (registered via `PasskeyFeInfoElement`)
- Override files in `TCA/Overrides/` use `\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns()`
- ShowItem strings use comma-separated field names with `--div--;Tab Name` separators

## Conventions (Services.yaml)

- `_defaults.autowire: true` and `_defaults.autoconfigure: true` are set globally
- Services that need `GeneralUtility::makeInstance()` access are declared `public: true`
- Domain model classes are excluded from autowiring (they are plain PHP value objects)
- Event listeners are autoconfigured via `#[AsEventListener]` attribute (preferred)

## RequestMiddlewares.php

Two PSR-15 middlewares are registered:

| Middleware | After | Purpose |
|-----------|-------|---------|
| `PasskeyPublicRouteResolver` | `typo3/cms-frontend/authentication` | Allow unauthenticated eID requests |
| `PasskeyEnrollmentInterstitial` | `typo3/cms-frontend/base-redirect-resolver` | Post-login enforcement interstitial |

## TypoScript

Constants are prefixed `plugin.tx_nrpasskeysfe.settings.*`. The three
key constants are `loginPageUid`, `managementPageUid`, `enrollmentPageUid`.
These must be set in the site's TypoScript constants.

## Site Configuration Schema

The extension reads `settings.nr_passkeys_fe.*` keys from the site's `config.yaml`:

```yaml
settings:
  nr_passkeys_fe:
    rpId: 'example.com'           # WebAuthn RP ID (domain only)
    rpName: 'Site Name'           # Human-readable RP name
    origin: 'https://example.com' # Full origin URL
    enforcementLevel: 'off'       # off|encourage|required|enforced
    gracePeriodDays: 14           # Days before required becomes enforced
```

These are read by `SiteConfigurationService` and returned as
`FrontendConfiguration` value objects.

## Security

- `settings.nr_passkeys_fe.rpId` and `origin` in site config define WebAuthn trust — never default them to wildcard or derive them from request headers.
- Credential/binary TCA columns stay `type => passthrough` — no backend editing of raw credential data.
- Services needed by the auth chain are `public: true`; keep everything else private.

## PR Checklist
- [ ] Functional TCA test still green (`composer ci:test:php:functional` in CI)
- [ ] New settings documented in Documentation/Configuration/
- [ ] Middleware order unchanged unless the change is deliberate and documented

## When stuck
- Site config schema and enforcement semantics: Documentation/Configuration/SiteConfiguration.rst.
- Middleware ordering issues: compare with the table above and TYPO3 middleware docs.
- DI questions: check how existing services in Services.yaml are wired.

## Boundaries
- Do NOT register anything via a legacy ext_tables PHP file (this extension has none — use Configuration/ files)
- TCA Overrides go in `TCA/Overrides/`, never in a root-level PHP registration file
- FlexForms reference `EXT:nr_passkeys_fe/Configuration/FlexForms/*.xml`
- JavaScriptModules.php maps short names to `EXT:` paths
