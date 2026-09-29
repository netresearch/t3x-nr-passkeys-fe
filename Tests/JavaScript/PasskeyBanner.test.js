/**
 * @vitest-environment-options {"url": "https://example.test/"}
 *
 * Tests for the SHIPPED Resources/Public/JavaScript/PasskeyBanner.js.
 *
 * The module initialises on load, so every test builds the banner and then
 * imports the module afresh. The page is served over https because the
 * module sets its dismiss cookie with the Secure flag, which a browser (and
 * jsdom) drops on a plain-http page.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { loadModules, clearBody } from './support/modules.js';

const DISMISS_COOKIE = 'nr_passkeys_fe_banner_dismissed';

function createBanner(enforcement) {
    const banner = document.createElement('div');
    banner.setAttribute('data-nr-passkeys-fe', 'banner');
    if (enforcement !== undefined) {
        banner.dataset.enforcement = enforcement;
    }
    banner.style.display = 'none';
    banner.setAttribute('hidden', 'true');

    const dismissBtn = document.createElement('button');
    dismissBtn.setAttribute('data-action', 'dismiss-banner');
    dismissBtn.textContent = 'Dismiss';
    banner.appendChild(dismissBtn);

    document.body.appendChild(banner);
    return { banner, dismissBtn };
}

function dismissCookie() {
    const entry = document.cookie.split(';').map((c) => c.trim()).find((c) => c.startsWith(DISMISS_COOKIE + '='));
    return entry ? entry.slice(DISMISS_COOKIE.length + 1) : null;
}

function clearDismissCookie() {
    document.cookie = DISMISS_COOKIE + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; Secure';
}

beforeEach(() => {
    clearBody();
    clearDismissCookie();
});

afterEach(() => {
    clearBody();
    clearDismissCookie();
});

describe('PasskeyBanner — encourage level', () => {
    it('shows a banner that has not been dismissed', async () => {
        const { banner, dismissBtn } = createBanner('encourage');

        await loadModules('PasskeyBanner.js');

        expect(banner.style.display).toBe('');
        expect(banner.hasAttribute('hidden')).toBe(false);
        expect(dismissBtn.style.display).toBe('');
    });

    it('treats a banner without a level as encourage', async () => {
        const { banner, dismissBtn } = createBanner(undefined);

        await loadModules('PasskeyBanner.js');
        dismissBtn.click();

        expect(banner.hasAttribute('hidden')).toBe(true);
        expect(dismissCookie()).toBe('1');
    });

    it('dismissing hides the banner and remembers it in a cookie', async () => {
        const { banner, dismissBtn } = createBanner('encourage');

        await loadModules('PasskeyBanner.js');
        dismissBtn.click();

        expect(banner.style.display).toBe('none');
        expect(banner.getAttribute('hidden')).toBe('true');
        expect(dismissCookie()).toBe('1');
    });

    it('remembers the dismissal for 30 days, site-wide and only over https', async () => {
        // document.cookie does not report the attributes back, so the string
        // the module writes is read at the setter.
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-01-01T00:00:00Z'));
        const written = [];
        const setter = vi.spyOn(Document.prototype, 'cookie', 'set');
        setter.mockImplementation(function (value) {
            written.push(value);
        });
        const { dismissBtn } = createBanner('encourage');

        await loadModules('PasskeyBanner.js');
        dismissBtn.click();
        setter.mockRestore();
        vi.useRealTimers();

        const cookie = written.find((c) => c.startsWith(DISMISS_COOKIE + '=1'));
        expect(cookie).toBeDefined();
        expect(cookie).toContain('; expires=' + new Date('2026-01-31T00:00:00Z').toUTCString());
        expect(cookie).toContain('; path=/');
        expect(cookie).toContain('; SameSite=Lax');
        expect(cookie).toContain('; Secure');
    });

    it('keeps a dismissed banner hidden on the next page', async () => {
        document.cookie = DISMISS_COOKIE + '=1; path=/; Secure';
        const { banner } = createBanner('encourage');

        await loadModules('PasskeyBanner.js');

        expect(banner.style.display).toBe('none');
        expect(banner.hasAttribute('hidden')).toBe(true);
    });
});

describe('PasskeyBanner — required and enforced levels', () => {
    it.each(['required', 'enforced'])('shows the banner at %s even after an earlier dismissal', async (level) => {
        document.cookie = DISMISS_COOKIE + '=1; path=/; Secure';
        const { banner } = createBanner(level);

        await loadModules('PasskeyBanner.js');

        expect(banner.style.display).toBe('');
        expect(banner.hasAttribute('hidden')).toBe(false);
    });

    it.each(['required', 'enforced'])('hides the dismiss button at %s, also from assistive technology', async (level) => {
        const { dismissBtn } = createBanner(level);

        await loadModules('PasskeyBanner.js');

        expect(dismissBtn.style.display).toBe('none');
        expect(dismissBtn.getAttribute('aria-hidden')).toBe('true');
    });

    it('does not dismiss a required banner when the (hidden) button is clicked', async () => {
        const { banner, dismissBtn } = createBanner('required');

        await loadModules('PasskeyBanner.js');
        dismissBtn.click();

        expect(banner.hasAttribute('hidden')).toBe(false);
        expect(dismissCookie()).toBeNull();
    });
});
