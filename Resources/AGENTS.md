<!-- FOR AI AGENTS - Scoped to Resources/ -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 -->

# Resources/ AGENTS.md

## Overview

**Scope:** Fluid templates, vanilla-JS WebAuthn modules, XLIFF translations,
and public assets (SVG icons). The JS modules have zero npm runtime
dependencies and are loaded via TYPO3's ES module import map.

## Setup

- No build step: JS ships as-is (no bundler, no transpiler).
- For JS tests: `npm install` at the repo root (Vitest is a dev dependency).

## Tests

- JS unit tests: `npm run test:js` (Vitest, specs in `Tests/JavaScript/`).
- Coverage: `npm run test:js:coverage`.
- Template rendering is covered by functional tests; full flows by Playwright E2E (`npm run test:e2e`).

## Structure

```
Resources/
  Private/
    Language/
      locallang.xlf            -> Main XLIFF file (all labels, en)
      locallang_db.xlf         -> TCA/database field labels
      locallang_mod.xlf        -> Backend module labels
    Layouts/
      Default.html             -> Base Fluid layout (FE plugins)
    Partials/                  -> Reusable Fluid partials
    Templates/
      AdminModule/             -> Backend admin module templates
        Dashboard.html         -> Dashboard + Enforcement tabs
        Help.html              -> Help page
      Enrollment/
        Index.html             -> Enrollment form (WebAuthn ceremony)
        Success.html           -> Post-enrollment success page
      Login/
        Index.html             -> Passkey login form
        Recovery.html          -> Recovery code login form
      Management/
        Index.html             -> Self-service credential management
        RecoveryCodes.html     -> Recovery code generation/display
  Public/
    Icons/                     -> SVG icons (extension, passkey, security key)
    JavaScript/
      PasskeyBanner.js         -> Encourage-stage onboarding banner
      PasskeyEnrollment.js     -> Enrollment ceremony (WebAuthn)
      PasskeyFeAdmin.js        -> Backend admin passkey info panel
      PasskeyLogin.js          -> Login form passkey flow (WebAuthn)
      PasskeyManagement.js     -> Self-service management panel
      PasskeyRecovery.js       -> Recovery code login form
      PasskeyRecoveryCodes.js  -> Recovery code generation display
      PasskeyUtils.js          -> Shared utilities (base64url, DOM helpers, buildEidUrl)
```

## Conventions (Fluid templates)

- All Fluid templates use `{namespace f=TYPO3\CMS\Fluid\ViewHelpers}`
- Layout: `<f:layout name="Default"/>`
- Sections: `<f:section name="Main">...</f:section>`
- Translations via `<f:translate key="..." extensionName="NrPasskeysFe"/>`
- Data attributes for JavaScript hooks: `data-passkey-*`
- CSS classes follow BEM: `nr-passkeys-*` prefix
- No inline `<style>` blocks in templates (use included CSS or parent theme)

## Patterns to Follow (JavaScript modules)

All JavaScript modules follow these rules:

- **Vanilla JS only** -- zero npm runtime dependencies
- **ES module syntax** -- `export default {}` or top-level `addEventListener`
- Registered in `Configuration/JavaScriptModules.php` as
  `@netresearch/nr-passkeys-fe/passkey-*.js`
- Loaded by TYPO3's `AssetCollector` or `PageRenderer::loadJavaScriptModule()`
- All WebAuthn calls wrapped in try/catch with user-friendly error display
- No global variable pollution (IIFEs or ES modules only)

### URL construction
All eID URL construction uses the shared `buildEidUrl()` helper from `PasskeyUtils.js`:
```js
var url = NrPasskeysFe.buildEidUrl(eidUrl, {action: 'options'});
```

### WebAuthn API usage pattern
```js
// Always check support first
if (!window.PublicKeyCredential) {
    showError('passkey_not_supported');
    return;
}

// Use ArrayBuffer conversion helpers
const credential = await navigator.credentials.get({
    publicKey: {
        challenge: base64UrlDecode(options.challenge),
        // ...
    }
});
```

## XLIFF Conventions

- Keys use dot notation: `login.button.passkey`, `error.challenge_expired`
- All keys defined in `locallang.xlf` (English source)
- Database field labels go in `locallang_db.xlf`
- Backend module labels go in `locallang_mod.xlf`
- Format: XLIFF 1.2 (`version="1.2"`)

## Security

- All WebAuthn responses are base64url-encoded before POSTing — never send raw ArrayBuffers or roll custom encoders (use the helpers in PasskeyUtils).
- No inline event handlers or `eval` — keep modules CSP-compatible.
- Error messages shown to users come from XLIFF keys; never render server error details verbatim.

## PR Checklist
- [ ] `npm run test:js` passes
- [ ] New labels added to the XLIFF files (English source)
- [ ] No new npm runtime dependencies
- [ ] Data attributes (`data-passkey-*`) used for JS hooks, no id/class coupling

## When stuck
- WebAuthn API behavior: check existing modules first (PasskeyLogin, PasskeyEnrollment).
- Import-map issues: the module must be registered in Configuration/JavaScriptModules.php.
- Fluid questions: templates follow the conventions above; layout is Default.

## Boundaries
- Do NOT add npm packages to the JavaScript modules (zero runtime deps)
- Do NOT use jQuery or other frameworks in templates
- Template paths registered in TypoScript can be overridden by integrators
- JavaScript modules must work with TYPO3 ES module import map
