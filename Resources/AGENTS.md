<!-- FOR AI AGENTS - Scoped to Resources/ -->
<!-- Last updated: 2026-03-15 -->

# Resources/ AGENTS.md

**Scope:** Templates, JavaScript modules, XLIFF, and public assets.

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
```

## Template Conventions

- All Fluid templates use `{namespace f=TYPO3\CMS\Fluid\ViewHelpers}`
- Layout: `<f:layout name="Default"/>`
- Sections: `<f:section name="Main">...</f:section>`
- Translations via `<f:translate key="..." extensionName="NrPasskeysFe"/>`
- Data attributes for JavaScript hooks: `data-passkey-*`
- CSS classes follow BEM: `nr-passkeys-*` prefix
- No inline `<style>` blocks in templates (use included CSS or parent theme)

## JavaScript Module Patterns

All JavaScript modules follow these rules:

- **Vanilla JS only** -- zero npm runtime dependencies
- **ES module syntax** -- `export default {}` or top-level `addEventListener`
- Registered in `Configuration/JavaScriptModules.php` as
  `@netresearch/nr-passkeys-fe/passkey-*.js`
- Loaded by TYPO3's `AssetCollector` or `PageRenderer::loadJavaScriptModule()`
- All WebAuthn calls wrapped in try/catch with user-friendly error display
- No global variable pollution (IIFEs or ES modules only)

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

## Boundaries
- Do NOT add npm packages to the JavaScript modules (zero runtime deps)
- Do NOT use jQuery or other frameworks in templates
- Template paths registered in TypoScript can be overridden by integrators
- JavaScript modules must work with TYPO3 ES module import map
