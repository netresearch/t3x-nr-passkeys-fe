<!-- FOR AI AGENTS - Scoped to Configuration/ -->
<!-- Last updated: 2026-03-15 -->

# Configuration/ AGENTS.md

**Scope:** TYPO3 configuration files for `nr_passkeys_fe`.

## Structure

```
Configuration/
  Backend/                     -> Backend module registration (Modules.php)
  FlexForms/
    EnrollmentPlugin.xml       -> FlexForm for NrPasskeysFe:Enrollment plugin
    LoginPlugin.xml            -> FlexForm for NrPasskeysFe:Login plugin
    ManagementPlugin.xml       -> FlexForm for NrPasskeysFe:Management plugin
  Icons.php                    -> Icon registry (SVG icons)
  JavaScriptModules.php        -> ES module import map (7 modules)
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

## TCA Patterns

- All TCA arrays use `'type' => 'passthrough'` for binary credential fields
- The `passkey_fe_info` field uses the custom `passkey_fe_info` renderType
  (registered via `PasskeyFeInfoElement`)
- Override files in `TCA/Overrides/` use `\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns()`
- ShowItem strings use comma-separated field names with `--div--;Tab Name` separators

## Services.yaml Conventions

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

The extension reads `passkeys.*` keys from the site's `config.yaml`:

```yaml
passkeys:
  rpId: 'example.com'           # WebAuthn RP ID (domain only)
  rpName: 'Site Name'           # Human-readable RP name
  origin: 'https://example.com' # Full origin URL
  enforcementLevel: 'off'       # off|encourage|required|enforced
  gracePeriodDays: 14           # Days before required becomes enforced
```

These are read by `SiteConfigurationService` and returned as
`SitePasskeyConfig` value objects.

## Boundaries
- Do NOT use `ext_tables.php` for registrations (use Configuration/ files)
- TCA Overrides go in `TCA/Overrides/`, not inline in ext_tables.php
- FlexForms reference `EXT:nr_passkeys_fe/Configuration/FlexForms/*.xml`
- JavaScriptModules.php maps short names to `EXT:` paths
