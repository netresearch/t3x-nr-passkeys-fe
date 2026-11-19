/**
 * PasskeyUtils - Shared utilities for passkey frontend modules
 *
 * Provides base64url encoding/decoding, DOM helpers for error/status/loading
 * state, and URL validation. Loaded before module-specific scripts via
 * f:asset.script in Fluid templates.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
(function () {
  'use strict';

  window.NrPasskeysFe = window.NrPasskeysFe || {};

  // Auto-load translations from <script data-nr-passkeys-fe-translations> JSON block
  (function () {
    var el = document.querySelector('script[data-nr-passkeys-fe-translations]');
    if (el) {
      try {
        window.NrPasskeysFe.lang = JSON.parse(el.textContent);
      } catch (e) {
        // Invalid JSON — ignore, fallbacks will be used
      }
    }
  })();

  /**
   * Look up a translated string by key, returning the fallback if not found.
   *
   * Translations are loaded from NrPasskeysFe.lang (populated by the
   * data-nr-passkeys-fe-translations JSON script block in Fluid templates).
   *
   * @param {string} key
   * @param {string} fallback
   * @returns {string}
   */
  window.NrPasskeysFe.t = function (key, fallback) {
    return (window.NrPasskeysFe.lang && window.NrPasskeysFe.lang[key]) || fallback;
  };

  function isLoginTokenForm(form) {
    if (!(form instanceof HTMLFormElement) || !form.isConnected || form.method.toLowerCase() !== 'post') {
      return false;
    }
    var requestToken = form.querySelector('input[name="__RequestToken"]:enabled');
    if (!requestToken || !requestToken.value
        || form.querySelector('input[name="pass"]:disabled')
        || !form.querySelector('input[name="user"]:enabled')
        || !form.querySelector('input[name="logintype"]:enabled')) {
      return false;
    }
    try {
      var action = new URL(form.action, document.baseURI);
      return action.origin === window.location.origin && ['https:', 'http:'].includes(action.protocol);
    } catch (e) {
      return false;
    }
  }

  window.NrPasskeysFe.getLoginTokenForm = function (source) {
    var card = source && source.closest('.nr-passkeys-fe-card');
    var scopes = card ? [card, document] : [document];
    // Keep felogin's configured redirect when the standalone plugin shares a page with it.
    var selectors = [
      'form[data-nr-passkeys-fe-token-form="felogin"], #nr-passkeys-fe-panel-password form[action], form#nr-passkeys-fe-felogin-token-form',
      'form[data-nr-passkeys-fe-token-form="standalone"], form#nr-passkeys-fe-token-form',
    ];
    for (var selector of selectors) {
      for (var scope of scopes) {
        var form = Array.from(scope.querySelectorAll(selector)).find(isLoginTokenForm);
        if (form) {
          return form;
        }
      }
    }
    return null;
  };

  /**
   * Submit a passkey login token via the felogin form to establish an FE session.
   *
   * Finds the felogin form in the password panel, sets the login fields, and
   * submits it. Returns true if the form was found and submitted, false otherwise.
   *
   * @param {string} token - The login token returned by the eID verify endpoint
   * @param {HTMLFormElement|null} [form] - Previously selected form, rechecked before submission
   * @returns {boolean} Whether the form was found and submitted
   */

  window.NrPasskeysFe.submitLoginToken = function (token, form) {
    if (form === undefined) {
      form = window.NrPasskeysFe.getLoginTokenForm();
    }
    if (!isLoginTokenForm(form)) {
      return false;
    }

    var passField = form.querySelector('input[name="pass"]');
    if (!passField) {
      passField = document.createElement('input');
      passField.type = 'hidden';
      passField.name = 'pass';
      form.appendChild(passField);
    }
    passField.value = JSON.stringify({ _type: 'passkey_token', token: token });

    form.querySelector('input[name="user"]:enabled').value = '__passkey__';

    form.querySelector('input[name="logintype"]:enabled').value = 'login';

    HTMLFormElement.prototype.submit.call(form);
    return true;
  };

  /**
   * Decode a base64url-encoded string to an ArrayBuffer.
   *
   * @param {string} base64url
   * @returns {ArrayBuffer}
   */
  window.NrPasskeysFe.base64urlToBuffer = function (base64url) {
    var base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    var padLen = (4 - (base64.length % 4)) % 4;
    var padded = base64 + '='.repeat(padLen);
    var binary = atob(padded);
    var buffer = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) {
      buffer[i] = binary.charCodeAt(i);
    }
    return buffer.buffer;
  };

  /**
   * Encode an ArrayBuffer to a base64url string (no padding).
   *
   * @param {ArrayBuffer} buffer
   * @returns {string}
   */
  window.NrPasskeysFe.bufferToBase64url = function (buffer) {
    var bytes = new Uint8Array(buffer);
    var binary = '';
    for (var i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  };

  /**
   * Show an error message in the given element.
   *
   * @param {HTMLElement|null} el
   * @param {string} msg
   */
  window.NrPasskeysFe.showError = function (el, msg) {
    if (el) {
      el.textContent = msg;
      el.style.display = '';
    }
  };

  /**
   * Hide the error element and clear its text.
   *
   * @param {HTMLElement|null} el
   */
  window.NrPasskeysFe.hideError = function (el) {
    if (el) {
      el.textContent = '';
      el.style.display = 'none';
    }
  };

  /**
   * Show a status message in the given element.
   *
   * @param {HTMLElement|null} el
   * @param {string} msg
   */
  window.NrPasskeysFe.showStatus = function (el, msg) {
    if (el) {
      el.textContent = msg;
      el.style.display = '';
    }
  };

  /**
   * Hide the status element and clear its text.
   *
   * @param {HTMLElement|null} el
   */
  window.NrPasskeysFe.hideStatus = function (el) {
    if (el) {
      el.textContent = '';
      el.style.display = 'none';
    }
  };

  /**
   * Toggle loading state on a button with text/loading child elements.
   *
   * @param {boolean} loading
   * @param {HTMLButtonElement|null} btnEl
   * @param {HTMLElement|null} btnText
   * @param {HTMLElement|null} btnLoading
   */
  window.NrPasskeysFe.setLoading = function (loading, btnEl, btnText, btnLoading) {
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
  };

  /**
   * Build an eID URL with additional query parameters.
   * @param {string} eidUrl - Base eID URL (e.g. "/?eID=nr_passkeys_fe")
   * @param {Object} params - Additional parameters (e.g. {action: 'loginOptions'})
   * @returns {string}
   */
  window.NrPasskeysFe.buildEidUrl = function(eidUrl, params) {
    var url = new URL(eidUrl, window.location.origin);
    Object.keys(params).forEach(function(key) {
      url.searchParams.set(key, params[key]);
    });
    return url.toString();
  };
})();
