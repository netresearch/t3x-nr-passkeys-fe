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
 */
(function () {
  'use strict';

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
    var btnText = container.querySelector('#nr-passkeys-fe-btn-text');
    var btnLoading = container.querySelector('#nr-passkeys-fe-btn-loading');
    var statusEl = container.querySelector('.nr-passkeys-fe-login__status');
    var errorEl = container.querySelector('.nr-passkeys-fe-login__error');
    var usernameInput = container.querySelector('[name="nr_passkeys_username"]');

    // Feature detection
    if (!window.PublicKeyCredential) {
      showError(errorEl, 'Your browser does not support Passkeys (WebAuthn). Please use a modern browser.');
      if (btnEl) {
        btnEl.disabled = true;
      }
      return;
    }

    if (!window.isSecureContext) {
      showError(errorEl, 'Passkeys require a secure connection (HTTPS).');
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
    var recoverySection = container.querySelector('#nr-passkeys-fe-recovery')
      || document.getElementById('nr-passkeys-fe-recovery');
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
    for (var i = 0; i < tabButtons.length; i++) {
      tabButtons[i].addEventListener('click', function () {
        var tabName = this.dataset.tab;
        var tabContainer = this.closest('.nr-passkeys-fe-card');
        if (!tabContainer) return;

        // Deactivate all tabs
        var allTabs = tabContainer.querySelectorAll('.nr-passkeys-fe-tab');
        for (var j = 0; j < allTabs.length; j++) {
          allTabs[j].classList.remove('nr-passkeys-fe-tab--active');
          allTabs[j].setAttribute('aria-selected', 'false');
        }

        // Hide all panels
        var allPanels = tabContainer.querySelectorAll('.nr-passkeys-fe-tabpanel');
        for (var j = 0; j < allPanels.length; j++) {
          allPanels[j].style.display = 'none';
        }

        // Activate clicked tab
        this.classList.add('nr-passkeys-fe-tab--active');
        this.setAttribute('aria-selected', 'true');

        // Show corresponding panel
        var panelId = 'nr-passkeys-fe-panel-' + tabName;
        var panel = document.getElementById(panelId);
        if (panel) {
          panel.style.display = '';
        }
      });
    }
  }

  function checkForFailedLogin(errorEl) {
    try {
      if (sessionStorage.getItem('nr_passkeys_fe_attempt')) {
        sessionStorage.removeItem('nr_passkeys_fe_attempt');
        showError(errorEl, 'Passkey authentication failed. Please try again or use a recovery code.');
      }
    } catch (e) {
      // sessionStorage may be unavailable
    }
  }

  async function handlePasskeyLogin(eidUrl, siteIdentifier, discoverable, usernameInput, btnEl, btnText, btnLoading, statusEl, errorEl) {
    hideError(errorEl);
    var username = usernameInput ? usernameInput.value.trim() : '';

    // Only require username for non-discoverable (username-first) flow
    if (!discoverable && !username) {
      showError(errorEl, 'Please enter your username.');
      if (usernameInput) {
        usernameInput.focus();
      }
      return;
    }

    setLoading(true, btnEl, btnText, btnLoading);

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
          showError(errorEl, 'Too many attempts. Please try again later.');
        } else {
          showError(errorEl, errData.error || 'Authentication failed. Please try again.');
        }
        setLoading(false, btnEl, btnText, btnLoading);
        return;
      }

      var optionsData = await optionsResponse.json();
      var options = optionsData.options;
      var challengeToken = optionsData.challengeToken;

      // Step 2: Build PublicKeyCredentialRequestOptions
      var publicKeyOptions = {
        challenge: base64urlToBuffer(options.challenge),
        rpId: options.rpId,
        timeout: options.timeout || 60000,
        userVerification: options.userVerification || 'required',
      };

      if (options.allowCredentials && options.allowCredentials.length > 0) {
        publicKeyOptions.allowCredentials = options.allowCredentials.map(function (cred) {
          return {
            type: cred.type,
            id: base64urlToBuffer(cred.id),
            transports: cred.transports || [],
          };
        });
      }

      // Step 3: Call WebAuthn API
      var assertion = await navigator.credentials.get({ publicKey: publicKeyOptions });

      // Step 4: Encode the response
      var credentialResponse = {
        id: bufferToBase64url(assertion.rawId),
        rawId: bufferToBase64(assertion.rawId),
        type: assertion.type,
        response: {
          clientDataJSON: bufferToBase64url(assertion.response.clientDataJSON),
          authenticatorData: bufferToBase64url(assertion.response.authenticatorData),
          signature: bufferToBase64url(assertion.response.signature),
          userHandle: assertion.response.userHandle
            ? bufferToBase64url(assertion.response.userHandle)
            : null,
        },
      };

      // Step 5: Submit via felogin form so TYPO3's auth chain establishes the session.
      // Pack the assertion + challengeToken into the `pass` (uident) field as JSON.
      // PasskeyFrontendAuthenticationService picks this up and verifies server-side.
      showStatus(statusEl, 'Verifying...');
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
        // Clear the username — discoverable login resolves from assertion
        var userField = feloginForm.querySelector('input[name="user"]');
        if (userField) {
          userField.value = '';
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
        showStatus(statusEl, 'Authenticated! Redirecting...');
        var redirect = verifyData.redirectUrl;
        if (redirect && isSameOrigin(redirect)) {
          window.location.href = redirect;
        } else {
          window.location.reload();
        }
        return;
      }

      try { sessionStorage.removeItem('nr_passkeys_fe_attempt'); } catch (e) { /* ignore */ }
      showError(errorEl, verifyData.error || 'Authentication failed. Please try again.');
      hideStatus(statusEl);
    } catch (err) {
      if (err.name === 'NotAllowedError') {
        showError(errorEl, 'Authentication was cancelled or no passkey found for this site.');
      } else if (err.name === 'SecurityError') {
        showError(errorEl, 'Security error. Please check your connection and try again.');
      } else if (err.name === 'AbortError') {
        showError(errorEl, 'Authentication was cancelled.');
      } else {
        showError(errorEl, 'Authentication failed: ' + (err.message || 'Please try again.'));
        console.error('[nr_passkeys_fe] PasskeyLogin error:', err);
      }
    }

    setLoading(false, btnEl, btnText, btnLoading);
    hideStatus(statusEl);
  }

  function setLoading(loading, btnEl, btnText, btnLoading) {
    if (btnEl) {
      btnEl.disabled = loading;
    }
    if (btnText) {
      btnText.style.display = loading ? 'none' : '';
    }
    if (btnLoading) {
      btnLoading.style.display = loading ? '' : 'none';
      if (loading) {
        btnLoading.removeAttribute('aria-hidden');
      } else {
        btnLoading.setAttribute('aria-hidden', 'true');
      }
    }
  }

  function showError(errorEl, message) {
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.style.display = '';
    }
  }

  function hideError(errorEl) {
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.style.display = 'none';
    }
  }

  function showStatus(statusEl, message) {
    if (statusEl) {
      statusEl.textContent = message;
      statusEl.style.display = '';
    }
  }

  function hideStatus(statusEl) {
    if (statusEl) {
      statusEl.textContent = '';
      statusEl.style.display = 'none';
    }
  }

  function isSameOrigin(url) {
    try { return new URL(url, window.location.origin).origin === window.location.origin; }
    catch (e) { return false; }
  }

  // Base64URL encoding/decoding utilities
  function base64urlToBuffer(base64url) {
    var base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    var padLen = (4 - (base64.length % 4)) % 4;
    var padded = base64 + '='.repeat(padLen);
    var binary = atob(padded);
    var buffer = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) {
      buffer[i] = binary.charCodeAt(i);
    }
    return buffer.buffer;
  }

  function bufferToBase64url(buffer) {
    var bytes = new Uint8Array(buffer);
    var binary = '';
    for (var i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  }

  function bufferToBase64(buffer) {
    var bytes = new Uint8Array(buffer);
    var binary = '';
    for (var i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
