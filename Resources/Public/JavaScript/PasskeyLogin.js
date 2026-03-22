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

  // Material Symbols "passkey" icon (Apache 2.0, google/material-design-icons)
  // Kept as constant to avoid innerHTML with dynamic content
  var PASSKEY_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 -960 960 960" width="20" fill="currentColor" aria-hidden="true">' +
    '<path d="M120-160v-112q0-34 17.5-62.5T184-378q62-31 126-46.5T440-440q20 0 40 1.5t40 4.5q-4 58 21 109.5t73 84.5v80H120Z' +
    'M760-40l-60-60v-186q-44-13-72-49.5T600-420q0-58 41-99t99-41q58 0 99 41t41 99q0 45-25.5 80T790-290l50 50-60 60 60 60-80 80Z' +
    'M440-480q-66 0-113-47t-47-113q0-66 47-113t113-47q66 0 113 47t47 113q0 66-47 113t-113 47Z' +
    'mm300 80q17 0 28.5-11.5T780-440q0-17-11.5-28.5T740-480q-17 0-28.5 11.5T700-440q0 17 11.5 28.5T740-400Z"/>' +
    '</svg>';

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
      U.showError(errorEl, 'Your browser does not support Passkeys (WebAuthn). Please use a modern browser.');
      if (btnEl) {
        btnEl.disabled = true;
      }
      return;
    }

    if (!window.isSecureContext) {
      U.showError(errorEl, 'Passkeys require a secure connection (HTTPS).');
      if (btnEl) {
        btnEl.disabled = true;
      }
      return;
    }

    // Check for conditional mediation (autofill UI)
    if (window.PublicKeyCredential.isConditionalMediationAvailable) {
      window.PublicKeyCredential.isConditionalMediationAvailable().then(function (available) {
        if (available && usernameInput) {
          usernameInput.setAttribute('autocomplete', 'username webauthn');
        }
      });
    }

    // Detect failed passkey login from previous attempt
    checkForFailedLogin(errorEl);

    if (btnEl) {
      btnEl.addEventListener('click', function () {
        handlePasskeyLogin(
          eidUrl, siteIdentifier, discoverable,
          usernameInput, btnEl, btnText, btnLoading, statusEl, errorEl
        );
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
        U.showError(errorEl, 'Passkey authentication failed. Please try again or use a recovery code.');
      }
    } catch (e) {
      // sessionStorage may be unavailable
    }
  }

  async function handlePasskeyLogin(eidUrl, siteIdentifier, discoverable, usernameInput, btnEl, btnText, btnLoading, statusEl, errorEl) {
    U.hideError(errorEl);
    var username = usernameInput ? usernameInput.value.trim() : '';

    // Only require username for non-discoverable (username-first) flow
    if (!discoverable && !username) {
      U.showError(errorEl, 'Please enter your username.');
      if (usernameInput) {
        usernameInput.focus();
      }
      return;
    }

    U.setLoading(true, btnEl, btnText, btnLoading);

    try {
      // Step 1: Fetch assertion options from eID
      var optionsUrl = eidUrl + '&action=loginOptions';
      var optionsResponse = await fetch(optionsUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username, siteIdentifier: siteIdentifier }),
        credentials: 'same-origin',
      });

      if (!optionsResponse.ok) {
        var errData = await optionsResponse.json().catch(function () { return {}; });
        if (optionsResponse.status === 429) {
          U.showError(errorEl, 'Too many attempts. Please try again later.');
        } else {
          U.showError(errorEl, errData.error || 'Authentication failed. Please try again.');
        }
        U.setLoading(false, btnEl, btnText, btnLoading);
        return;
      }

      var optionsData = await optionsResponse.json();
      var options = optionsData.options;
      var challengeToken = optionsData.challengeToken;

      // Step 2: Build PublicKeyCredentialRequestOptions
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

      // Step 3: Call WebAuthn API
      var assertion = await navigator.credentials.get({ publicKey: publicKeyOptions });

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

      // Step 5: Submit via felogin form so TYPO3's auth chain establishes the session.
      // Pack the assertion + challengeToken into the `pass` (uident) field as JSON.
      // PasskeyFrontendAuthenticationService picks this up and verifies server-side.
      U.showStatus(statusEl, 'Verifying...');
      try { sessionStorage.setItem('nr_passkeys_fe_attempt', '1'); } catch (e) { /* ignore */ }

      var feloginForm = document.querySelector('#nr-passkeys-fe-panel-password form[action]');
      if (feloginForm) {
        var passkeyPayload = JSON.stringify({
          _type: 'passkey',
          assertion: credentialResponse,
          challengeToken: challengeToken,
        });
        // Set the pass (uident) field to the passkey payload
        var passField = feloginForm.querySelector('input[name="pass"]');
        if (!passField) {
          passField = document.createElement('input');
          passField.type = 'hidden';
          passField.name = 'pass';
          feloginForm.appendChild(passField);
        }
        passField.value = passkeyPayload;
        // Set logintype
        var logintypeField = feloginForm.querySelector('input[name="logintype"]');
        if (logintypeField) {
          logintypeField.value = 'login';
        }
        // Set a placeholder username — TYPO3 requires non-empty uname to enter
        // the auth chain. The auth service resolves the actual user from the assertion.
        var userField = feloginForm.querySelector('input[name="user"]');
        if (userField) {
          userField.value = '__passkey__';
        }
        // Use HTMLFormElement.prototype.submit to avoid shadowing by elements named "submit"
        HTMLFormElement.prototype.submit.call(feloginForm);
        return;
      }

      // Fallback: no felogin form available — use eID verify + reload
      var verifyUrl = eidUrl + '&action=loginVerify';
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

      if (verifyResponse.ok && verifyData.status === 'ok') {
        try { sessionStorage.removeItem('nr_passkeys_fe_attempt'); } catch (e) { /* ignore */ }
        U.showStatus(statusEl, 'Authenticated! Redirecting...');
        var redirect = verifyData.redirectUrl;
        if (redirect && U.isSameOrigin(redirect)) {
          window.location.href = redirect;
        } else {
          window.location.reload();
        }
        return;
      }

      try { sessionStorage.removeItem('nr_passkeys_fe_attempt'); } catch (e) { /* ignore */ }
      U.showError(errorEl, verifyData.error || 'Authentication failed. Please try again.');
      U.hideStatus(statusEl);
    } catch (err) {
      if (err.name === 'NotAllowedError') {
        U.showError(errorEl, 'Authentication was cancelled or no passkey found for this site.');
      } else if (err.name === 'SecurityError') {
        U.showError(errorEl, 'Security error. Please check your connection and try again.');
      } else if (err.name === 'AbortError') {
        U.showError(errorEl, 'Authentication was cancelled.');
      } else {
        U.showError(errorEl, 'Authentication failed: ' + (err.message || 'Please try again.'));
        console.error('[nr_passkeys_fe] PasskeyLogin error:', err);
      }
    }

    U.setLoading(false, btnEl, btnText, btnLoading);
    U.hideStatus(statusEl);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
