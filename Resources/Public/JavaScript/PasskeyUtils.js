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
   * Check whether a URL shares the same origin as the current page.
   *
   * @param {string} url
   * @returns {boolean}
   */
  window.NrPasskeysFe.isSameOrigin = function (url) {
    try { return new URL(url, window.location.origin).origin === window.location.origin; }
    catch (e) { return false; }
  };
})();
