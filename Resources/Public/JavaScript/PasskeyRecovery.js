/**
 * Passkey Recovery - Recovery code input handling for TYPO3 Frontend
 *
 * Auto-initializes on [data-nr-passkeys-fe="recovery"].
 *
 * Features:
 * - Auto-formats recovery codes (XXXX-XXXX) as the user types
 * - Submits to eID recoveryVerify action
 * - Handles error display and loading state
 *
 * Depends on: PasskeyUtils.js (NrPasskeysFe namespace)
 */
(function () {
  'use strict';

  var U = window.NrPasskeysFe;

  function init() {
    var containers = document.querySelectorAll('[data-nr-passkeys-fe="recovery"]');
    for (var i = 0; i < containers.length; i++) {
      initContainer(containers[i]);
    }
  }

  function initContainer(container) {
    var eidUrl = container.dataset.eidUrl;

    var codeInput = container.querySelector('[data-action="recovery-format"]');
    var form = container.querySelector('[data-action="recovery-verify"]');
    var submitBtn = container.querySelector('[data-action="recovery-submit"]');
    var btnText = submitBtn ? submitBtn.querySelector('.nr-passkeys-fe-btn__text') : null;
    var btnLoading = submitBtn ? submitBtn.querySelector('.nr-passkeys-fe-btn__loading') : null;
    var statusEl = container.querySelector('.nr-passkeys-fe-recovery__status');
    var errorEl = container.querySelector('.nr-passkeys-fe-recovery__error');

    // Auto-format recovery code input (XXXX-XXXX)
    if (codeInput) {
      codeInput.addEventListener('input', function () {
        formatRecoveryCode(codeInput);
      });

      codeInput.addEventListener('keydown', function (e) {
        // Allow backspace to remove the dash too
        if (e.key === 'Backspace') {
          var val = codeInput.value;
          if (val.length === 5 && val[4] === '-') {
            e.preventDefault();
            codeInput.value = val.slice(0, 4);
          }
        }
      });
    }

    // Handle form submission via eID (AJAX) if eidUrl is set
    // Otherwise fall through to native form POST
    if (form && eidUrl) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleRecoveryVerify(eidUrl, codeInput, submitBtn, btnText, btnLoading, statusEl, errorEl, form);
      });
    }
  }

  function formatRecoveryCode(input) {
    // Strip all non-alphanumeric characters
    var raw = input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();

    // Limit to 8 characters (4+4)
    if (raw.length > 8) {
      raw = raw.slice(0, 8);
    }

    // Insert dash after position 4
    if (raw.length > 4) {
      input.value = raw.slice(0, 4) + '-' + raw.slice(4);
    } else {
      input.value = raw;
    }
  }

  async function handleRecoveryVerify(eidUrl, codeInput, submitBtn, btnText, btnLoading, statusEl, errorEl, form) {
    U.hideError(errorEl);

    var code = codeInput ? codeInput.value.trim() : '';
    if (!code || code.length !== 9) {
      U.showError(errorEl, 'Please enter a valid recovery code (format: XXXX-XXXX).');
      if (codeInput) {
        codeInput.focus();
      }
      return;
    }

    // Extract username from the form
    var usernameInput = form ? form.querySelector('[name="recovery_username"]') : null;
    var username = usernameInput ? usernameInput.value.trim() : '';
    if (!username) {
      U.showError(errorEl, 'Please enter your username.');
      if (usernameInput) {
        usernameInput.focus();
      }
      return;
    }

    U.setLoading(true, submitBtn, btnText, btnLoading);

    try {
      var verifyUrl = U.buildEidUrl(eidUrl, {action: 'recoveryVerify'});
      var response = await fetch(verifyUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username, code: code }),
        credentials: 'same-origin',
      });

      var data = await response.json().catch(function () { return {}; });

      if (response.ok && data.status === 'ok' && data.loginToken) {
        // Submit token via felogin form to establish FE session
        var feloginForm = document.querySelector('#nr-passkeys-fe-panel-password form[action]');
        if (feloginForm) {
          var passField = feloginForm.querySelector('input[name="pass"]');
          if (!passField) {
            passField = document.createElement('input');
            passField.type = 'hidden';
            passField.name = 'pass';
            feloginForm.appendChild(passField);
          }
          passField.value = JSON.stringify({ _type: 'passkey_token', token: data.loginToken });
          var userField = feloginForm.querySelector('input[name="user"]');
          if (userField) { userField.value = '__passkey__'; }
          var logintypeField = feloginForm.querySelector('input[name="logintype"]');
          if (logintypeField) { logintypeField.value = 'login'; }
          HTMLFormElement.prototype.submit.call(feloginForm);
          return;
        }
        // Fallback: reload
        window.location.reload();
        return;
      }

      if (response.status === 429) {
        U.showError(errorEl, 'Too many attempts. Please try again later.');
      } else {
        U.showError(errorEl, data.error || 'Invalid recovery code. Please check and try again.');
        if (codeInput) {
          codeInput.value = '';
          codeInput.focus();
        }
      }
    } catch (e) {
      U.showError(errorEl, 'Network error. Please check your connection and try again.');
      console.error('[nr_passkeys_fe] RecoveryVerify error:', e);
    }

    U.setLoading(false, submitBtn, btnText, btnLoading);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
