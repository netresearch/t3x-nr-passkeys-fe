/**
 * Tests for the shared helpers in the SHIPPED PasskeyUtils.js
 * (window.NrPasskeysFe): base64url conversion, DOM helpers, origin check and
 * eID URL building. The login module itself is tested in PasskeyLogin.test.js.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';

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
