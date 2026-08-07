/**
 * Passkey Login - WebAuthn authentication for TYPO3 Frontend
 *
 * Auto-initializes on DOM elements with [data-nr-passkeys-fe="login"].
 * Configuration is read from data-* attributes on the container element.
 *
 * Features:
 * - Discoverable login (no username required)
 * - Username-first login (fetch options with username)
 * - Tab switching (Layout B - Tabbed)
 * - Recovery form show/hide toggle (both layouts)
 *
 * Flow:
 * 1. Check WebAuthn support + secure context
 * 2. Click "Sign in with a passkey"
 * 3. Discoverable: navigator.credentials.get() with empty allowCredentials
 *    Username-first: fetch options from eID with username, then credentials.get()
 * 4. Submit assertion to eID loginVerify action
 * 5. Redirect on success / show error on failure
 *
 * Depends on: PasskeyUtils.js (NrPasskeysFe namespace)
 */
(function () {
  'use strict';

  var U = window.NrPasskeysFe;

  function init() {
    var containers = document.querySelectorAll('[data-nr-passkeys-fe="login"]');
    for (var i = 0; i < containers.length; i++) {
      initContainer(containers[i]);
    }

    // Initialize tab switching (Layout B)
    initTabs();
  }

  function initContainer(container) {
    var eidUrl = container.dataset.eidUrl;
    var siteIdentifier = container.dataset.siteIdentifier;
    var discoverable = container.dataset.discoverable !== '0';

    if (!eidUrl) {
      return;
    }

    // Resolve absolute eID URL when relative
    if (eidUrl.indexOf('://') === -1 && eidUrl.charAt(0) === '/') {
      eidUrl = window.location.origin + eidUrl;
    }

    var btnEl = container.querySelector('[data-action="passkey-login"]');
    var btnText = container.querySelector('.nr-passkeys-fe-btn__text');
    var btnLoading = container.querySelector('.nr-passkeys-fe-btn__loading');
    var statusEl = container.querySelector('.nr-passkeys-fe-login__status');
    var errorEl = container.querySelector('.nr-passkeys-fe-login__error');
    var usernameInput = container.querySelector('[name="nr_passkeys_username"]');

    // Feature detection
    if (!window.PublicKeyCredential) {
      U.showError(errorEl, U.t('js.error.unsupported', 'Your browser does not support Passkeys (WebAuthn). Please use a modern browser.'));
      if (btnEl) {
        btnEl.disabled = true;
      }
      return;
    }

    if (!window.isSecureContext) {
      U.showError(errorEl, U.t('js.error.insecure', 'Passkeys require a secure connection (HTTPS).'));
      if (btnEl) {
        btnEl.disabled = true;
      }
      return;
    }

    // Conditional UI / autofill: the username field advertises WebAuthn and an
    // armed ceremony waits for the user to pick a passkey from the browser's
    // autofill menu. The attribute alone does nothing — without a pending
    // credentials.get({mediation:'conditional'}) the menu has no passkeys to
    // offer. Degrades silently to the button where unsupported.
    var ctx = {
      eidUrl: eidUrl,
      siteIdentifier: siteIdentifier,
      discoverable: discoverable,
      usernameInput: usernameInput,
      btnEl: btnEl,
      btnText: btnText,
      btnLoading: btnLoading,
      statusEl: statusEl,
      errorEl: errorEl,
      conditionalAbort: null,
      conditionalGeneration: 0,
      conditionalRefreshTimer: null,
      navigatingAway: false,
    };

    if (discoverable && usernameInput
        && typeof window.PublicKeyCredential.isConditionalMediationAvailable === 'function') {
      startConditionalLogin(ctx);
    }

    // Detect failed passkey login from previous attempt
    checkForFailedLogin(errorEl);

    if (btnEl) {
      btnEl.addEventListener('click', function () {
        handlePasskeyLogin(ctx);
      });
    }

    // Recovery form show/hide toggle
    initRecoveryToggle(container);
  }

  /**
   * Initialize recovery form show/hide for a login container.
   * Works for both Layout B (tabbed) and Layout C (stacked).
   */
  function initRecoveryToggle(container) {
    var recoveryLink = container.querySelector('[data-action="show-recovery"]');
    // The recovery section can be inside the container or a sibling
    // Support both standalone (#nr-passkeys-fe-recovery) and felogin (#nr-passkeys-fe-felogin-recovery) IDs
    var recoverySection = container.querySelector('[id$="-recovery"][data-nr-passkeys-fe="recovery"]')
      || document.querySelector('[id$="-recovery"][data-nr-passkeys-fe="recovery"]');
    var passkeyContent = container.querySelector('.nr-passkeys-fe-passkey-content');

    if (recoveryLink && recoverySection) {
      recoveryLink.addEventListener('click', function (e) {
        e.preventDefault();
        if (passkeyContent) {
          passkeyContent.style.display = 'none';
        }
        recoverySection.style.display = '';
      });

      var backLink = recoverySection.querySelector('[data-action="hide-recovery"]');
      if (backLink) {
        backLink.addEventListener('click', function (e) {
          e.preventDefault();
          recoverySection.style.display = 'none';
          if (passkeyContent) {
            passkeyContent.style.display = '';
          }
        });
      }
    }
  }

  /**
   * Initialize tab switching for Layout B (Tabbed).
   * Looks for [data-action="switch-tab"] buttons in .nr-passkeys-fe-tabs.
   */
  function initTabs() {
    var tabButtons = document.querySelectorAll('[data-action="switch-tab"]');
    var tabBtns = [];
    for (var i = 0; i < tabButtons.length; i++) {
      tabBtns.push(tabButtons[i]);
    }

    for (var i = 0; i < tabBtns.length; i++) {
      tabBtns[i].addEventListener('click', function () {
        var tabName = this.dataset.tab;
        var tabContainer = this.closest('.nr-passkeys-fe-card');
        if (!tabContainer) return;

        // Deactivate all tabs
        var allTabs = tabContainer.querySelectorAll('.nr-passkeys-fe-tab');
        for (var j = 0; j < allTabs.length; j++) {
          allTabs[j].classList.remove('nr-passkeys-fe-tab--active');
          allTabs[j].setAttribute('aria-selected', 'false');
          allTabs[j].setAttribute('tabindex', '-1');
        }

        // Hide all panels
        var allPanels = tabContainer.querySelectorAll('.nr-passkeys-fe-tabpanel');
        for (var j = 0; j < allPanels.length; j++) {
          allPanels[j].style.display = 'none';
        }

        // Activate clicked tab
        this.classList.add('nr-passkeys-fe-tab--active');
        this.setAttribute('aria-selected', 'true');
        this.setAttribute('tabindex', '0');

        // Show corresponding panel
        var panelId = 'nr-passkeys-fe-panel-' + tabName;
        var panel = document.getElementById(panelId);
        if (panel) {
          panel.style.display = '';
        }
      });
    }

    // Set initial tabindex values
    for (var i = 0; i < tabBtns.length; i++) {
      if (tabBtns[i].classList.contains('nr-passkeys-fe-tab--active')) {
        tabBtns[i].setAttribute('tabindex', '0');
      } else {
        tabBtns[i].setAttribute('tabindex', '-1');
      }
    }

    // Arrow key navigation for tabs (WAI-ARIA tabs pattern)
    tabBtns.forEach(function(btn) {
      btn.addEventListener('keydown', function(e) {
        var index = tabBtns.indexOf(btn);
        var newIndex = -1;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
          newIndex = (index + 1) % tabBtns.length;
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
          newIndex = (index - 1 + tabBtns.length) % tabBtns.length;
        } else if (e.key === 'Home') {
          newIndex = 0;
        } else if (e.key === 'End') {
          newIndex = tabBtns.length - 1;
        }
        if (newIndex >= 0) {
          e.preventDefault();
          tabBtns[newIndex].click();
          tabBtns[newIndex].focus();
        }
      });
    });
  }

  function checkForFailedLogin(errorEl) {
    try {
      if (sessionStorage.getItem('nr_passkeys_fe_attempt')) {
        sessionStorage.removeItem('nr_passkeys_fe_attempt');
        U.showError(errorEl, U.t('js.login.error.failed', 'Passkey authentication failed. Please try again or use a recovery code.'));
      }
    } catch (e) {
      // sessionStorage may be unavailable
    }
  }

  /**
   * Cancel the armed conditional ceremony, if any, and stop its refresh timer.
   * Safe to call when nothing is armed.
   */
  function stopConditionalLogin(ctx) {
    ctx.conditionalGeneration++;
    if (ctx.conditionalRefreshTimer !== null) {
      clearTimeout(ctx.conditionalRefreshTimer);
      ctx.conditionalRefreshTimer = null;
    }
    if (ctx.conditionalAbort) {
      ctx.conditionalAbort.abort();
      ctx.conditionalAbort = null;
    }
  }

  /**
   * Put the autofill ceremony back in place. Skipped once a session has been
   * established, so no ceremony is started into a page that is leaving.
   */
  function rearmConditionalLogin(ctx) {
    if (ctx.navigatingAway) {
      return;
    }
    if (!ctx.discoverable || !ctx.usernameInput
        || typeof window.PublicKeyCredential.isConditionalMediationAvailable !== 'function') {
      return;
    }
    startConditionalLogin(ctx);
  }

  /**
   * Swap the armed challenge for a fresh one before the server would reject it
   * as expired. Aborting closes an open autofill menu, so a refresh waits while
   * the username field has focus and happens on blur instead.
   */
  function scheduleConditionalRefresh(ctx, ttlSeconds) {
    var ttl = Math.max(30, Number(ttlSeconds) || 120);
    if (ctx.conditionalRefreshTimer !== null) {
      clearTimeout(ctx.conditionalRefreshTimer);
    }
    ctx.conditionalRefreshTimer = setTimeout(function () {
      ctx.conditionalRefreshTimer = null;
      if (document.activeElement === ctx.usernameInput) {
        ctx.usernameInput.addEventListener('blur', function onBlur() {
          ctx.usernameInput.removeEventListener('blur', onBlur);
          startConditionalLogin(ctx);
        });
        return;
      }
      startConditionalLogin(ctx);
    }, Math.floor(ttl * 1000 / 2));
  }

  /**
   * Arm a conditional-mediation ceremony so the browser can offer discoverable
   * passkeys in the username field's autofill menu.
   *
   * The ceremony holds its challenge until the user picks a credential, which
   * can be far later than the challenge lives — hence the refresh timer. It is
   * also spent once it settles, so every outcome that is not an abort re-arms:
   * without that the menu would offer no passkeys for the rest of the page.
   */
  async function startConditionalLogin(ctx) {
    var available = false;
    try {
      available = await window.PublicKeyCredential.isConditionalMediationAvailable();
    } catch (e) {
      return;
    }
    if (!available) {
      return;
    }

    stopConditionalLogin(ctx);
    var generation = ctx.conditionalGeneration;

    // Advertise WebAuthn autofill without clobbering an existing token.
    var existing = ctx.usernameInput.getAttribute('autocomplete') || '';
    if (existing.indexOf('webauthn') === -1) {
      ctx.usernameInput.setAttribute('autocomplete', (existing ? existing + ' ' : 'username ') + 'webauthn');
    }

    // Prefetch discoverable options quietly: a rate limit or any other error
    // here must not surface on page load — the explicit button stays.
    var optionsData = await fetchLoginOptions(ctx, '', true);
    if (!optionsData || generation !== ctx.conditionalGeneration) {
      return;
    }

    var abort = new AbortController();
    ctx.conditionalAbort = abort;
    scheduleConditionalRefresh(ctx, optionsData.challengeTtlSeconds);

    try {
      var assertion = await navigator.credentials.get({
        publicKey: buildRequestOptions(optionsData.options),
        mediation: 'conditional',
        signal: abort.signal,
      });
      if (generation !== ctx.conditionalGeneration) {
        return;
      }
      ctx.conditionalAbort = null;
      await completeAssertion(ctx, assertion, optionsData.options, optionsData.challengeToken);
      if (generation === ctx.conditionalGeneration) {
        rearmConditionalLogin(ctx);
      }
    } catch (err) {
      if (generation !== ctx.conditionalGeneration) {
        return;
      }
      ctx.conditionalAbort = null;
      // An abort means another flow deliberately took over and decides what
      // happens next; a dismissed menu (NotAllowedError) is normal here.
      if (err.name !== 'AbortError' && err.name !== 'NotAllowedError') {
        console.error('[nr_passkeys_fe] conditional login error:', err);
      }
      if (err.name !== 'AbortError') {
        rearmConditionalLogin(ctx);
      }
    }
  }

  /**
   * Fetch assertion options from the eID endpoint. When silent is true the
   * caller is the autofill prefetch and errors stay off the screen.
   */
  async function fetchLoginOptions(ctx, username, silent) {
    var optionsUrl = U.buildEidUrl(ctx.eidUrl, {action: 'loginOptions'});
    var response;
    try {
      response = await fetch(optionsUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username, siteIdentifier: ctx.siteIdentifier }),
        credentials: 'same-origin',
      });
    } catch (e) {
      if (!silent) {
        U.showError(ctx.errorEl, U.t('js.login.error.generic', 'Authentication failed. Please try again.'));
      }
      return null;
    }
    if (response.ok) {
      return response.json();
    }
    if (silent) {
      return null;
    }
    var errData = await response.json().catch(function () { return {}; });
    if (response.status === 429) {
      U.showError(ctx.errorEl, U.t('js.login.error.rateLimit', 'Too many attempts. Please try again later.'));
    } else {
      U.showError(ctx.errorEl, errData.error || U.t('js.login.error.generic', 'Authentication failed. Please try again.'));
    }
    return null;
  }

  /**
   * Map the server's assertion options onto PublicKeyCredentialRequestOptions.
   */
  function buildRequestOptions(options) {
    var publicKeyOptions = {
      challenge: U.base64urlToBuffer(options.challenge),
      rpId: options.rpId,
      timeout: options.timeout || 60000,
      userVerification: options.userVerification || 'required',
    };
    if (options.allowCredentials && options.allowCredentials.length > 0) {
      publicKeyOptions.allowCredentials = options.allowCredentials.map(function (cred) {
        return {
          type: cred.type,
          id: U.base64urlToBuffer(cred.id),
          transports: cred.transports || [],
        };
      });
    }
    return publicKeyOptions;
  }

  /**
   * Encode the assertion, verify it at the eID endpoint and establish the
   * session. Shared by the button flow and the autofill ceremony so both
   * behave identically once an assertion exists.
   *
   * @returns {Promise<boolean>} true when a session was established and the
   *   page is navigating away.
   */
  async function completeAssertion(ctx, assertion, options, challengeToken) {
    var siteIdentifier = ctx.siteIdentifier;
    var eidUrl = ctx.eidUrl;
    var statusEl = ctx.statusEl;
    var errorEl = ctx.errorEl;
    // Step 4: Encode the response
    var credentialResponse = {
      id: U.bufferToBase64url(assertion.rawId),
      rawId: U.bufferToBase64url(assertion.rawId),
      type: assertion.type,
      response: {
        clientDataJSON: U.bufferToBase64url(assertion.response.clientDataJSON),
        authenticatorData: U.bufferToBase64url(assertion.response.authenticatorData),
        signature: U.bufferToBase64url(assertion.response.signature),
        userHandle: assertion.response.userHandle
          ? U.bufferToBase64url(assertion.response.userHandle)
          : null,
      },
    };

    // Step 5: Verify the assertion via eID endpoint (server-side WebAuthn verification).
    // The eID has full site context and establishes the FE session.
    // On success, redirect to the post-login page or reload.
    U.showStatus(statusEl, U.t('js.login.status.verifying', 'Verifying...'));
    try { sessionStorage.setItem('nr_passkeys_fe_attempt', '1'); } catch (e) { /* ignore */ }
    var verifyUrl = U.buildEidUrl(eidUrl, {action: 'loginVerify'});
    var verifyResponse = await fetch(verifyUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        assertion: credentialResponse,
        challengeToken: challengeToken,
        siteIdentifier: siteIdentifier,
      }),
      credentials: 'same-origin',
    });

    var verifyData = await verifyResponse.json().catch(function () { return {}; });

    if (verifyResponse.ok && verifyData.status === 'ok' && verifyData.loginToken) {
      try { sessionStorage.removeItem('nr_passkeys_fe_attempt'); } catch (e) { /* ignore */ }

      // Submit the login token via felogin form to establish a real FE session.
      // The eID verified the assertion; the token proves it to the auth service.
      if (U.submitLoginToken(verifyData.loginToken)) {
        ctx.navigatingAway = true;
        stopConditionalLogin(ctx);
        return true;
      }

      // No felogin form (standalone plugin) — just reload
      U.showStatus(statusEl, U.t('js.login.status.authenticated', 'Authenticated! Redirecting...'));
      ctx.navigatingAway = true;
      stopConditionalLogin(ctx);
      window.location.reload();
      return true;
    }

    try { sessionStorage.removeItem('nr_passkeys_fe_attempt'); } catch (e) { /* ignore */ }
    // The server confirmed this credential ID is not (or no longer) registered.
    // Tell the platform authenticator / password manager so it can prune the
    // orphaned passkey instead of offering it again (WebAuthn Signal API).
    if (verifyData.reason === 'unknown_credential') {
      signalUnknownCredential(options.rpId, credentialResponse.id);
    }
    U.showError(errorEl, verifyData.error || U.t('js.login.error.generic', 'Authentication failed. Please try again.'));
    U.hideStatus(statusEl);
    return false;
  }

  async function handlePasskeyLogin(ctx) {
    var discoverable = ctx.discoverable;
    var usernameInput = ctx.usernameInput;
    var btnEl = ctx.btnEl;
    var btnText = ctx.btnText;
    var btnLoading = ctx.btnLoading;
    var statusEl = ctx.statusEl;
    var errorEl = ctx.errorEl;

    U.hideError(errorEl);
    // Only one credentials.get() ceremony may be in flight: the armed
    // conditional one has to go before the modal one can start.
    stopConditionalLogin(ctx);
    var username = usernameInput ? usernameInput.value.trim() : '';

    // Only require username for non-discoverable (username-first) flow
    if (!discoverable && !username) {
      U.showError(errorEl, U.t('js.login.error.noUsername', 'Please enter your username.'));
      if (usernameInput) {
        usernameInput.focus();
      }
      rearmConditionalLogin(ctx);
      return;
    }

    U.setLoading(true, btnEl, btnText, btnLoading);

    try {
      // Step 1: Fetch assertion options from eID
      var optionsData = await fetchLoginOptions(ctx, username, false);
      if (!optionsData) {
        U.setLoading(false, btnEl, btnText, btnLoading);
        rearmConditionalLogin(ctx);
        return;
      }
      var options = optionsData.options;
      var challengeToken = optionsData.challengeToken;

      // Step 2: Build PublicKeyCredentialRequestOptions
      var publicKeyOptions = buildRequestOptions(options);

      // Step 3: Call WebAuthn API
      var assertion = await navigator.credentials.get({ publicKey: publicKeyOptions });

      if (await completeAssertion(ctx, assertion, options, challengeToken)) {
        return;
      }
    } catch (err) {
      if (err.name === 'NotAllowedError') {
        U.showError(errorEl, U.t('js.login.error.notAllowed', 'Authentication was cancelled or no passkey found for this site.'));
      } else if (err.name === 'SecurityError') {
        U.showError(errorEl, U.t('js.login.error.security', 'Security error. Please check your connection and try again.'));
      } else if (err.name === 'AbortError') {
        U.showError(errorEl, U.t('js.login.error.cancelled', 'Authentication was cancelled.'));
      } else {
        U.showError(errorEl, (err.message || U.t('js.login.error.generic', 'Authentication failed. Please try again.')));
        console.error('[nr_passkeys_fe] PasskeyLogin error:', err);
      }
    }

    U.setLoading(false, btnEl, btnText, btnLoading);
    U.hideStatus(statusEl);
    // Reaching here means the flow ended without establishing a session; the
    // autofill needs a working ceremony again.
    rearmConditionalLogin(ctx);
  }

  /**
   * Best-effort WebAuthn Signal API call: report a credential ID the server no
   * longer recognises so supporting authenticators/password managers can remove
   * the orphaned passkey. Feature-detected and error-swallowing — the API is
   * new and support varies; a failure here must never affect the login flow.
   *
   * @param {string} rpId - Relying Party ID the ceremony ran against
   * @param {string} credentialId - base64url credential ID from the assertion
   */
  function signalUnknownCredential(rpId, credentialId) {
    try {
      if (window.PublicKeyCredential
          && typeof window.PublicKeyCredential.signalUnknownCredential === 'function'
          && rpId && credentialId) {
        var result = window.PublicKeyCredential.signalUnknownCredential({
          rpId: rpId,
          credentialId: credentialId,
        });
        if (result && typeof result.catch === 'function') {
          result.catch(function () { /* best-effort */ });
        }
      }
    } catch (e) {
      /* best-effort */
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
