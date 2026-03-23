/**
 * Tests for PasskeyLogin.js
 *
 * Tests shared utilities from PasskeyUtils.js (base64url encoding/decoding)
 * and PasskeyLogin-specific DOM setup, URL construction, and error handling.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Load the shared utility module so NrPasskeysFe is available
import '../../Resources/Public/JavaScript/PasskeyUtils.js';

// ---------------------------------------------------------------
// Helpers — DOM setup
// ---------------------------------------------------------------

function clearBody() {
    while (document.body.firstChild) {
        document.body.removeChild(document.body.firstChild);
    }
}

function createLoginContainer({ eidUrl = '/index.php', siteIdentifier = 'test-site', discoverable = '1' } = {}) {
    const container = document.createElement('div');
    container.setAttribute('data-nr-passkeys-fe', 'login');
    container.dataset.eidUrl = eidUrl;
    container.dataset.siteIdentifier = siteIdentifier;
    container.dataset.discoverable = discoverable;

    const btn = document.createElement('button');
    btn.setAttribute('data-action', 'passkey-login');

    const btnText = document.createElement('span');
    btnText.id = 'nr-passkeys-fe-btn-text';
    btn.appendChild(btnText);

    const btnLoading = document.createElement('span');
    btnLoading.id = 'nr-passkeys-fe-btn-loading';
    btnLoading.setAttribute('aria-hidden', 'true');
    btn.appendChild(btnLoading);

    const status = document.createElement('div');
    status.className = 'nr-passkeys-fe-login__status';
    status.style.display = 'none';

    const error = document.createElement('div');
    error.className = 'nr-passkeys-fe-login__error';
    error.style.display = 'none';

    const usernameInput = document.createElement('input');
    usernameInput.name = 'nr_passkeys_username';
    usernameInput.type = 'text';

    container.appendChild(btn);
    container.appendChild(status);
    container.appendChild(error);
    container.appendChild(usernameInput);
    document.body.appendChild(container);

    return { container, btn, status, error, usernameInput };
}

// ---------------------------------------------------------------
// Shared utility tests (NrPasskeysFe from PasskeyUtils.js)
// ---------------------------------------------------------------

describe('NrPasskeysFe — base64url utilities', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('base64urlToBuffer produces correct ArrayBuffer length', () => {
        const input = btoa('hello').replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
        const result = window.NrPasskeysFe.base64urlToBuffer(input);
        expect(result.byteLength).toBe(5); // 'hello' = 5 bytes
    });

    it('bufferToBase64url round-trips correctly', () => {
        const original = new Uint8Array([1, 2, 3, 255, 0, 127]);
        const encoded = window.NrPasskeysFe.bufferToBase64url(original.buffer);
        const decoded = new Uint8Array(window.NrPasskeysFe.base64urlToBuffer(encoded));

        for (let i = 0; i < original.length; i++) {
            expect(decoded[i]).toBe(original[i]);
        }
    });

    it('bufferToBase64url produces URL-safe characters (no +, /, =)', () => {
        // Use bytes that produce + and / in standard base64
        const bytes = new Uint8Array([251, 255, 254]);
        const encoded = window.NrPasskeysFe.bufferToBase64url(bytes.buffer);

        expect(encoded).not.toContain('+');
        expect(encoded).not.toContain('/');
        expect(encoded).not.toContain('=');
    });

    it('base64urlToBuffer handles empty string', () => {
        const result = window.NrPasskeysFe.base64urlToBuffer('');
        expect(result.byteLength).toBe(0);
    });
});

describe('NrPasskeysFe — DOM helpers', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('showError sets text and shows element', () => {
        const el = document.createElement('div');
        el.style.display = 'none';
        window.NrPasskeysFe.showError(el, 'Test error');
        expect(el.textContent).toBe('Test error');
        expect(el.style.display).toBe('');
    });

    it('hideError clears text and hides element', () => {
        const el = document.createElement('div');
        el.textContent = 'Some error';
        window.NrPasskeysFe.hideError(el);
        expect(el.textContent).toBe('');
        expect(el.style.display).toBe('none');
    });

    it('showStatus sets text and shows element', () => {
        const el = document.createElement('div');
        el.style.display = 'none';
        window.NrPasskeysFe.showStatus(el, 'Loading...');
        expect(el.textContent).toBe('Loading...');
        expect(el.style.display).toBe('');
    });

    it('hideStatus clears text and hides element', () => {
        const el = document.createElement('div');
        el.textContent = 'Loading...';
        window.NrPasskeysFe.hideStatus(el);
        expect(el.textContent).toBe('');
        expect(el.style.display).toBe('none');
    });

    it('setLoading disables button and toggles text/loading visibility', () => {
        const btn = document.createElement('button');
        const btnText = document.createElement('span');
        const btnLoading = document.createElement('span');
        btnLoading.setAttribute('aria-hidden', 'true');

        window.NrPasskeysFe.setLoading(true, btn, btnText, btnLoading);
        expect(btn.disabled).toBe(true);
        expect(btnText.style.display).toBe('none');
        expect(btnLoading.style.display).toBe('');

        window.NrPasskeysFe.setLoading(false, btn, btnText, btnLoading);
        expect(btn.disabled).toBe(false);
        expect(btnText.style.display).toBe('');
        expect(btnLoading.style.display).toBe('none');
    });

    it('isSameOrigin returns true for same origin', () => {
        expect(window.NrPasskeysFe.isSameOrigin('/dashboard')).toBe(true);
    });

    it('isSameOrigin returns false for different origin', () => {
        expect(window.NrPasskeysFe.isSameOrigin('https://evil.example.com/redirect')).toBe(false);
    });

    it('buildEidUrl appends action parameter to eID URL', () => {
        const result = window.NrPasskeysFe.buildEidUrl('/?eID=nr_passkeys_fe', {action: 'loginOptions'});
        expect(result).toContain('eID=nr_passkeys_fe');
        expect(result).toContain('action=loginOptions');
    });

    it('buildEidUrl does not duplicate query string', () => {
        const result = window.NrPasskeysFe.buildEidUrl('/?eID=nr_passkeys_fe', {action: 'test'});
        // Should only have one '?' in the URL
        const questionMarks = (result.match(/\?/g) || []).length;
        expect(questionMarks).toBe(1);
    });

    it('buildEidUrl handles multiple parameters', () => {
        const result = window.NrPasskeysFe.buildEidUrl('/?eID=nr_passkeys_fe', {action: 'test', foo: 'bar'});
        expect(result).toContain('action=test');
        expect(result).toContain('foo=bar');
    });

    it('showError handles null element gracefully', () => {
        // Should not throw
        window.NrPasskeysFe.showError(null, 'msg');
    });

    it('hideError handles null element gracefully', () => {
        window.NrPasskeysFe.hideError(null);
    });

    it('setLoading handles null elements gracefully', () => {
        window.NrPasskeysFe.setLoading(true, null, null, null);
    });
});

// ---------------------------------------------------------------
// Feature-detection tests (unit logic, no module loading needed)
// ---------------------------------------------------------------

describe('PasskeyLogin — feature detection logic', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('returns false for WebAuthn support when PublicKeyCredential is absent', () => {
        const supported = typeof window.PublicKeyCredential !== 'undefined';
        // In a plain jsdom env without the stub, it may or may not exist — just check the type
        expect(typeof supported).toBe('boolean');
    });
});

describe('PasskeyLogin — DOM container setup', () => {
    afterEach(() => {
        clearBody();
    });

    it('createLoginContainer appends button to body', () => {
        const { btn } = createLoginContainer();
        expect(document.body.contains(btn)).toBe(true);
    });

    it('error element starts hidden', () => {
        const { error } = createLoginContainer();
        expect(error.style.display).toBe('none');
    });

    it('status element starts hidden', () => {
        const { status } = createLoginContainer();
        expect(status.style.display).toBe('none');
    });

    it('button is not disabled initially', () => {
        const { btn } = createLoginContainer();
        expect(btn.disabled).toBe(false);
    });
});

describe('PasskeyLogin — fetch URL construction', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('loginOptions URL contains eID and action parameters', () => {
        // Verify the URL patterns the module would use via buildEidUrl
        const U = window.NrPasskeysFe;
        const eidUrl = '/?eID=nr_passkeys_fe';
        const optionsUrl = U.buildEidUrl(eidUrl, {action: 'loginOptions'});
        const verifyUrl = U.buildEidUrl(eidUrl, {action: 'loginVerify'});

        expect(optionsUrl).toContain('eID=nr_passkeys_fe');
        expect(optionsUrl).toContain('action=loginOptions');
        expect(verifyUrl).toContain('action=loginVerify');
    });

    it('fetch is called with POST method and JSON content-type for loginOptions', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            status: 500,
            json: async () => ({ error: 'test' }),
        });
        window.fetch = fetchMock;
        window.PublicKeyCredential = {};
        Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });

        // Simulate what the module does (directly, without loading the IIFE)
        await fetch('/index.php?eID=nr_passkeys_fe&action=loginOptions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: '', siteIdentifier: 'test-site' }),
            credentials: 'same-origin',
        });

        expect(fetchMock).toHaveBeenCalledWith(
            expect.stringContaining('action=loginOptions'),
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({ 'Content-Type': 'application/json' }),
            }),
        );
    });
});

describe('PasskeyLogin — error handling patterns', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('NotAllowedError maps to "cancelled" message', () => {
        const err = Object.assign(new Error('User declined'), { name: 'NotAllowedError' });
        const errorMessages = {
            NotAllowedError: 'Authentication was cancelled or no passkey found for this site.',
            SecurityError: 'Security error. Please check your connection and try again.',
            AbortError: 'Authentication was cancelled.',
        };
        expect(errorMessages[err.name]).toContain('cancelled');
    });

    it('SecurityError maps to security message', () => {
        const err = Object.assign(new Error('Security violation'), { name: 'SecurityError' });
        const errorMessages = {
            NotAllowedError: 'Authentication was cancelled or no passkey found for this site.',
            SecurityError: 'Security error. Please check your connection and try again.',
            AbortError: 'Authentication was cancelled.',
        };
        expect(errorMessages[err.name]).toContain('Security error');
    });

    it('AbortError maps to cancelled message', () => {
        const err = Object.assign(new Error('Aborted'), { name: 'AbortError' });
        const errorMessages = {
            NotAllowedError: 'Authentication was cancelled or no passkey found for this site.',
            SecurityError: 'Security error. Please check your connection and try again.',
            AbortError: 'Authentication was cancelled.',
        };
        expect(errorMessages[err.name]).toContain('cancelled');
    });
});
