/**
 * Passkey Enrollment - WebAuthn registration for TYPO3 Frontend
 *
 * Auto-initializes on DOM elements with [data-nr-passkeys-fe="enrollment"]
 * or [data-nr-passkeys-fe="enrollment-form"].
 *
 * Flow:
 * 1. User clicks register button
 * 2. Fetch registration options from eID registrationOptions action
 * 3. Call navigator.credentials.create()
 * 4. Verify with eID registrationVerify action
 * 5. Show success or error
 *
 * Depends on: PasskeyUtils.js (NrPasskeysFe namespace)
 */
(function () {
  'use strict';

  var U = window.NrPasskeysFe;

  function init() {
    var containers = document.querySelectorAll(
      '[data-nr-passkeys-fe="enrollment"], [data-nr-passkeys-fe="enrollment-form"]'
    );
    for (var i = 0; i < containers.length; i++) {
      initContainer(containers[i]);
    }
  }

  function initContainer(container) {
    var eidUrl = container.dataset.eidUrl;
    var registerOptionsUrl = container.dataset.registerOptionsUrl;
    var registerVerifyUrl = container.dataset.registerVerifyUrl;

    if (!eidUrl && !registerOptionsUrl) {
      return;
    }

    var optionsUrl = registerOptionsUrl || (eidUrl + '?eID=nr_passkeys_fe&action=registrationOptions');
    var verifyUrl = registerVerifyUrl || (eidUrl + '?eID=nr_passkeys_fe&action=registrationVerify');

    var registerBtn = container.querySelector('[data-action="register-passkey"]');
    var labelInput = container.querySelector('[id*="enrollment"][id*="label"], [id*="enrollment-device-label"], [id*="new-label"]');
    var btnText = registerBtn ? registerBtn.querySelector('.nr-passkeys-fe-btn__text') : null;
    var btnLoading = registerBtn ? registerBtn.querySelector('.nr-passkeys-fe-btn__loading') : null;
    var statusEl = container.querySelector('.nr-passkeys-fe-enrollment__status, .nr-passkeys-fe-enrollment-form__status');
    var errorEl = container.querySelector('.nr-passkeys-fe-enrollment__error, .nr-passkeys-fe-enrollment-form__error');
    var successEl = container.querySelector('.nr-passkeys-fe-enrollment-form__success');

    // Feature detection
    if (!window.PublicKeyCredential) {
      U.showError(errorEl, 'Your browser does not support Passkeys (WebAuthn). Please use a modern browser.');
      if (registerBtn) {
        registerBtn.disabled = true;
      }
      return;
    }

    if (!window.isSecureContext) {
      U.showError(errorEl, 'Passkeys require a secure connection (HTTPS).');
      if (registerBtn) {
        registerBtn.disabled = true;
      }
      return;
    }

    if (registerBtn) {
      registerBtn.addEventListener('click', function () {
        handleRegistration(
          optionsUrl, verifyUrl,
          labelInput, registerBtn, btnText, btnLoading,
          statusEl, errorEl, successEl, container
        );
      });
    }
  }

  async function handleRegistration(optionsUrl, verifyUrl, labelInput, registerBtn, btnText, btnLoading, statusEl, errorEl, successEl, container) {
    U.hideError(errorEl);
    hideElement(successEl);

    var label = labelInput ? labelInput.value.trim() : 'Passkey';
    if (!label) {
      label = 'Passkey';
    }

    U.setLoading(true, registerBtn, btnText, btnLoading);

    try {
      // Step 1: Get registration options from eID
      var optionsResponse = await fetch(optionsUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ label: label }),
        credentials: 'same-origin',
      });

      if (!optionsResponse.ok) {
        var errData = await optionsResponse.json().catch(function () { return {}; });
        U.showError(errorEl, errData.error || 'Failed to start registration. Please try again.');
        U.setLoading(false, registerBtn, btnText, btnLoading);
        return;
      }

      var optionsData = await optionsResponse.json();
      var options = optionsData.options;
      var challengeToken = optionsData.challengeToken;

      // Step 2: Build PublicKeyCredentialCreationOptions
      var publicKeyOptions = {
        challenge: U.base64urlToBuffer(options.challenge),
        rp: {
          name: options.rp.name,
          id: options.rp.id,
        },
        user: {
          id: U.base64urlToBuffer(options.user.id),
          name: options.user.name,
          displayName: options.user.displayName,
        },
        pubKeyCredParams: options.pubKeyCredParams.map(function (p) {
          return { type: p.type, alg: p.alg };
        }),
        timeout: options.timeout || 60000,
        attestation: options.attestation || 'none',
        authenticatorSelection: options.authenticatorSelection || {},
      };

      if (options.excludeCredentials && options.excludeCredentials.length > 0) {
        publicKeyOptions.excludeCredentials = options.excludeCredentials.map(function (cred) {
          return {
            type: cred.type,
            id: U.base64urlToBuffer(cred.id),
            transports: cred.transports || [],
          };
        });
      }

      // Step 3: Create credential with browser
      var credential = await navigator.credentials.create({ publicKey: publicKeyOptions });

      // Step 4: Encode the credential
      var credentialResponse = {
        id: U.bufferToBase64url(credential.rawId),
        rawId: U.bufferToBase64url(credential.rawId),
        type: credential.type,
        response: {
          clientDataJSON: U.bufferToBase64url(credential.response.clientDataJSON),
          attestationObject: U.bufferToBase64url(credential.response.attestationObject),
        },
      };

      if (credential.response.getTransports) {
        credentialResponse.response.transports = credential.response.getTransports();
      }

      // Step 5: Verify with eID
      U.showStatus(statusEl, 'Registering passkey...');

      var verifyResponse = await fetch(verifyUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          credential: credentialResponse,
          challengeToken: challengeToken,
          label: label,
        }),
        credentials: 'same-origin',
      });

      var verifyData = await verifyResponse.json().catch(function () { return {}; });

      if (verifyResponse.ok && verifyData.status === 'ok') {
        U.hideStatus(statusEl);

        // Check if we should redirect (enrollment flow)
        if (verifyData.redirectUrl && U.isSameOrigin(verifyData.redirectUrl)) {
          window.location.href = verifyData.redirectUrl;
          return;
        }

        // Show inline success (management flow)
        showElement(successEl);
        if (labelInput) {
          labelInput.value = 'Passkey';
        }

        // Fire custom event for list refresh
        container.dispatchEvent(new CustomEvent('nr-passkeys-fe:registered', {
          bubbles: true,
          detail: { credentialUid: verifyData.uid },
        }));
      } else {
        U.showError(errorEl, verifyData.error || 'Registration failed. Please try again.');
        U.hideStatus(statusEl);
      }
    } catch (err) {
      if (err.name === 'NotAllowedError' || err.name === 'AbortError') {
        U.showError(errorEl, 'Registration was cancelled.');
      } else if (err.name === 'InvalidStateError') {
        U.showError(errorEl, 'This passkey is already registered.');
      } else {
        U.showError(errorEl, 'Registration failed: ' + (err.message || 'Please try again.'));
        console.error('[nr_passkeys_fe] PasskeyEnrollment error:', err);
      }
      U.hideStatus(statusEl);
    }

    U.setLoading(false, registerBtn, btnText, btnLoading);
  }

  function showElement(el) {
    if (el) {
      el.style.display = '';
    }
  }

  function hideElement(el) {
    if (el) {
      el.style.display = 'none';
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
