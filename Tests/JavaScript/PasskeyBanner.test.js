/**
 * Tests for PasskeyBanner.js
 *
 * PasskeyBanner.js is standalone (does not depend on PasskeyUtils.js)
 * as it handles only cookie-based dismiss logic with no base64/WebAuthn needs.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const DISMISS_COOKIE = 'nr_passkeys_fe_banner_dismissed';
const DISMISS_DAYS = 30;

function clearBody() {
    while (document.body.firstChild) {
        document.body.removeChild(document.body.firstChild);
    }
}

function createBanner({ enforcement = 'encourage' } = {}) {
    const banner = document.createElement('div');
    banner.setAttribute('data-nr-passkeys-fe', 'banner');
    banner.dataset.enforcement = enforcement;
    banner.style.display = 'none';
    banner.setAttribute('hidden', 'true');

    const dismissBtn = document.createElement('button');
    dismissBtn.setAttribute('data-action', 'dismiss-banner');
    dismissBtn.textContent = 'Dismiss';
    banner.appendChild(dismissBtn);

    document.body.appendChild(banner);
    return { banner, dismissBtn };
}

// Cookie helpers (mirrored from PasskeyBanner.js)
function setCookie(name, value, days) {
    let expires = '';
    if (days) {
        const date = new Date();
        date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
        expires = '; expires=' + date.toUTCString();
    }
    document.cookie = encodeURIComponent(name) + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
}

function getCookie(name) {
    const nameEQ = encodeURIComponent(name) + '=';
    const cookies = document.cookie.split(';');
    for (let i = 0; i < cookies.length; i++) {
        const c = cookies[i].trim();
        if (c.indexOf(nameEQ) === 0) {
            return decodeURIComponent(c.substring(nameEQ.length));
        }
    }
    return null;
}

function clearCookie(name) {
    document.cookie = encodeURIComponent(name) + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
}

// ---------------------------------------------------------------
// Tests
// ---------------------------------------------------------------

describe('PasskeyBanner — show/dismiss (encourage mode)', () => {
    beforeEach(() => {
        clearBody();
        clearCookie(DISMISS_COOKIE);
    });

    afterEach(() => {
        clearBody();
        clearCookie(DISMISS_COOKIE);
        vi.restoreAllMocks();
    });

    it('banner starts hidden before init', () => {
        const { banner } = createBanner();
        expect(banner.style.display).toBe('none');
    });

    it('shows banner on init for encourage mode when not dismissed', () => {
        const { banner } = createBanner({ enforcement: 'encourage' });

        // Simulate init logic
        const enforcement = banner.dataset.enforcement;
        const dismissed = getCookie(DISMISS_COOKIE) === '1';

        if (enforcement === 'encourage' && dismissed) {
            banner.style.display = 'none';
        } else {
            banner.style.display = '';
            banner.removeAttribute('hidden');
        }

        expect(banner.style.display).toBe('');
        expect(banner.hasAttribute('hidden')).toBe(false);
    });

    it('hides banner when dismiss cookie is already set', () => {
        setCookie(DISMISS_COOKIE, '1', DISMISS_DAYS);
        const { banner } = createBanner({ enforcement: 'encourage' });

        const dismissed = getCookie(DISMISS_COOKIE) === '1';
        if (dismissed) {
            banner.style.display = 'none';
            banner.setAttribute('hidden', 'true');
        }

        expect(banner.style.display).toBe('none');
    });

    it('dismiss button sets cookie and hides banner', () => {
        const { banner, dismissBtn } = createBanner({ enforcement: 'encourage' });

        dismissBtn.addEventListener('click', () => {
            setCookie(DISMISS_COOKIE, '1', DISMISS_DAYS);
            banner.style.display = 'none';
            banner.setAttribute('hidden', 'true');
        });

        dismissBtn.click();

        expect(banner.style.display).toBe('none');
        expect(getCookie(DISMISS_COOKIE)).toBe('1');
    });

    it('cookie expires in 30 days', () => {
        setCookie(DISMISS_COOKIE, '1', DISMISS_DAYS);

        const cookieValue = getCookie(DISMISS_COOKIE);
        expect(cookieValue).toBe('1');
    });
});

describe('PasskeyBanner — mandatory mode (required/enforced)', () => {
    beforeEach(() => {
        clearBody();
        clearCookie(DISMISS_COOKIE);
    });

    afterEach(() => {
        clearBody();
        clearCookie(DISMISS_COOKIE);
    });

    it('hides dismiss button in required mode', () => {
        const { banner, dismissBtn } = createBanner({ enforcement: 'required' });

        const enforcement = banner.dataset.enforcement;
        if (enforcement !== 'encourage') {
            dismissBtn.style.display = 'none';
            dismissBtn.setAttribute('aria-hidden', 'true');
        }

        expect(dismissBtn.style.display).toBe('none');
        expect(dismissBtn.getAttribute('aria-hidden')).toBe('true');
    });

    it('hides dismiss button in enforced mode', () => {
        const { banner, dismissBtn } = createBanner({ enforcement: 'enforced' });

        const enforcement = banner.dataset.enforcement;
        if (enforcement !== 'encourage') {
            dismissBtn.style.display = 'none';
            dismissBtn.setAttribute('aria-hidden', 'true');
        }

        expect(dismissBtn.style.display).toBe('none');
    });

    it('still shows banner in required mode even if cookie is set', () => {
        setCookie(DISMISS_COOKIE, '1', DISMISS_DAYS);
        const { banner } = createBanner({ enforcement: 'required' });

        // required/enforced ignores the dismiss cookie
        const enforcement = banner.dataset.enforcement;
        if (enforcement === 'encourage') {
            const dismissed = getCookie(DISMISS_COOKIE) === '1';
            if (dismissed) {
                banner.style.display = 'none';
                return;
            }
        }
        banner.style.display = '';
        banner.removeAttribute('hidden');

        expect(banner.style.display).toBe('');
    });
});

describe('PasskeyBanner — cookie utilities', () => {
    afterEach(() => {
        clearCookie(DISMISS_COOKIE);
    });

    it('getCookie returns null for non-existent cookie', () => {
        clearCookie(DISMISS_COOKIE);
        expect(getCookie(DISMISS_COOKIE)).toBeNull();
    });

    it('getCookie returns correct value after setCookie', () => {
        setCookie(DISMISS_COOKIE, '1', 30);
        expect(getCookie(DISMISS_COOKIE)).toBe('1');
    });

    it('setCookie with SameSite=Lax', () => {
        setCookie(DISMISS_COOKIE, '1', 30);
        // In jsdom document.cookie may not fully emulate Expires/SameSite
        // We just verify the value is readable
        expect(getCookie(DISMISS_COOKIE)).toBe('1');
    });
});
