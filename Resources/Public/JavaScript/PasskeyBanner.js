/**
 * Passkey Banner - Enrollment nudge banner for TYPO3 Frontend
 *
 * Auto-initializes on [data-nr-passkeys-fe="banner"].
 *
 * Enforcement levels:
 * - encourage: Show dismiss button, set cookie on dismiss
 * - required:  No dismiss button; only "Set up now" link available
 * - enforced:  No dismiss button; hard requirement
 *
 * Cookie: nr_passkeys_fe_banner_dismissed=1 (30 days)
 */
(function () {
  'use strict';

  var DISMISS_COOKIE = 'nr_passkeys_fe_banner_dismissed';
  var DISMISS_DAYS = 30;

  function init() {
    var banners = document.querySelectorAll('[data-nr-passkeys-fe="banner"]');
    for (var i = 0; i < banners.length; i++) {
      initBanner(banners[i]);
    }
  }

  function initBanner(banner) {
    var enforcement = banner.dataset.enforcement || 'encourage';
    var dismissBtn = banner.querySelector('[data-action="dismiss-banner"]');

    // For "encourage" level: check if banner was already dismissed
    if (enforcement === 'encourage' && isBannerDismissed()) {
      hideBanner(banner);
      return;
    }

    // Show the banner
    banner.style.display = '';
    banner.removeAttribute('hidden');

    // For "encourage" level: wire up dismiss button
    if (enforcement === 'encourage' && dismissBtn) {
      dismissBtn.addEventListener('click', function () {
        dismissBanner(banner);
      });
    } else if (dismissBtn) {
      // For required/enforced: hide dismiss button
      dismissBtn.style.display = 'none';
      dismissBtn.setAttribute('aria-hidden', 'true');
    }
  }

  function dismissBanner(banner) {
    // Set cookie to remember dismissal
    setCookie(DISMISS_COOKIE, '1', DISMISS_DAYS);
    hideBanner(banner);
  }

  function hideBanner(banner) {
    banner.style.display = 'none';
    banner.setAttribute('hidden', 'true');
  }

  function isBannerDismissed() {
    return getCookie(DISMISS_COOKIE) === '1';
  }

  function setCookie(name, value, days) {
    var expires = '';
    if (days) {
      var date = new Date();
      date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
      expires = '; expires=' + date.toUTCString();
    }
    // SameSite=Lax is safe for this non-sensitive preference cookie
    document.cookie = encodeURIComponent(name) + '=' + encodeURIComponent(value) +
      expires + '; path=/; SameSite=Lax; Secure';
  }

  function getCookie(name) {
    var nameEQ = encodeURIComponent(name) + '=';
    var cookies = document.cookie.split(';');
    for (var i = 0; i < cookies.length; i++) {
      var c = cookies[i].trim();
      if (c.indexOf(nameEQ) === 0) {
        return decodeURIComponent(c.substring(nameEQ.length));
      }
    }
    return null;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
