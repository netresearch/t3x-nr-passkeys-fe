/**
 * Tests for the button flow of the SHIPPED Resources/Public/JavaScript/PasskeyLogin.js.
 *
 * The conditional-UI (autofill) ceremony is covered in
 * PasskeyLoginConditional.test.js. Here the container is built with
 * data-discoverable="0" and no conditional mediation, so only the button
 * ceremony runs: feature detection, the options request, the mapping of
 * WebAuthn errors, and the Signal API call after the server reports an
 * unknown credential. Only fetch and the WebAuthn API are stubbed.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { loadModules, settle, jsonResponse, clearBody } from './support/modules.js';

const EID_URL = 'https://example.test/?eID=nr_passkeys_fe';

function createLoginContainer() {
    const container = document.createElement('div');
    container.setAttribute('data-nr-passkeys-fe', 'login');
    container.dataset.eidUrl = EID_URL;
    container.dataset.siteIdentifier = 'main';
    container.dataset.discoverable = '0';

    const btn = document.createElement('button');
    btn.setAttribute('data-action', 'passkey-login');
    const btnText = document.createElement('span');
    btnText.className = 'nr-passkeys-fe-btn__text';
    const btnLoading = document.createElement('span');
    btnLoading.className = 'nr-passkeys-fe-btn__loading';
    btn.append(btnText, btnLoading);

    const status = document.createElement('div');
    status.className = 'nr-passkeys-fe-login__status';
    const error = document.createElement('div');
    error.className = 'nr-passkeys-fe-login__error';
    error.style.display = 'none';
    const usernameInput = document.createElement('input');
    usernameInput.name = 'nr_passkeys_username';
    usernameInput.value = 'jdoe';

    container.append(btn, status, error, usernameInput);
    document.body.appendChild(container);
    return { container, btn, error, usernameInput };
}

function loginOptions() {
    return {
        options: { challenge: 'YWJjZGVm', rpId: 'example.test', userVerification: 'required' },
        challengeToken: 'challenge-token-1',
    };
}

function fakeAssertion() {
    const buf = new Uint8Array([1, 2, 3, 4]).buffer;
    return {
        rawId: buf,
        type: 'public-key',
        response: { clientDataJSON: buf, authenticatorData: buf, signature: buf, userHandle: null },
    };
}

/**
 * WebAuthn without conditional mediation, so no autofill ceremony is armed.
 */
function installWebAuthn(getImpl, extra = {}) {
    window.PublicKeyCredential = Object.assign(function () {}, extra);
    const get = vi.fn(getImpl);
    Object.defineProperty(navigator, 'credentials', { value: { get }, configurable: true, writable: true });
    return get;
}

async function clickLogin(btn) {
    btn.click();
    await settle(60);
}

beforeEach(() => {
    clearBody();
    sessionStorage.clear();
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
});

afterEach(() => {
    clearBody();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    delete window.PublicKeyCredential;
});

describe('PasskeyLogin — feature detection', () => {
    it('disables the button and explains when WebAuthn is missing', async () => {
        delete window.PublicKeyCredential;
        const { btn, error } = createLoginContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');

        expect(btn.disabled).toBe(true);
        expect(error.textContent).toContain('does not support Passkeys');
    });

    it('disables the button on an insecure page', async () => {
        installWebAuthn(async () => fakeAssertion());
        Object.defineProperty(window, 'isSecureContext', { value: false, configurable: true });
        const { btn, error } = createLoginContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');

        expect(btn.disabled).toBe(true);
        expect(error.textContent).toContain('secure connection');
    });
});

describe('PasskeyLogin — button ceremony', () => {
    it('posts the username and site to loginOptions', async () => {
        installWebAuthn(() => new Promise(() => {}));
        const fetchMock = vi.fn(async () => jsonResponse(200, loginOptions()));
        vi.stubGlobal('fetch', fetchMock);
        const { btn } = createLoginContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await clickLogin(btn);

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toContain('eID=nr_passkeys_fe');
        expect(url).toContain('action=loginOptions');
        expect(init.method).toBe('POST');
        expect(init.headers['Content-Type']).toBe('application/json');
        expect(JSON.parse(init.body)).toMatchObject({ username: 'jdoe', siteIdentifier: 'main' });
    });

    it('asks for the username first in the username-first flow', async () => {
        installWebAuthn(async () => fakeAssertion());
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const { btn, error, usernameInput } = createLoginContainer();
        usernameInput.value = '';

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await clickLogin(btn);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(error.textContent).toBe('Please enter your username.');
    });

    it.each([
        ['NotAllowedError', 'Authentication was cancelled or no passkey found for this site.'],
        ['SecurityError', 'Security error. Please check your connection and try again.'],
        ['AbortError', 'Authentication was cancelled.'],
    ])('maps %s from the authenticator to its message', async (name, message) => {
        installWebAuthn(async () => {
            throw Object.assign(new Error('from authenticator'), { name });
        });
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, loginOptions())));
        const { btn, error } = createLoginContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await clickLogin(btn);

        expect(error.textContent).toBe(message);
        expect(btn.disabled).toBe(false);
    });
});

describe('PasskeyLogin — Signal API after an unknown credential', () => {
    function rejectingVerify(reason) {
        return vi.fn(async (url) => (String(url).indexOf('loginOptions') !== -1
            ? jsonResponse(200, loginOptions())
            : jsonResponse(401, { error: 'Unknown passkey', reason })));
    }

    it('reports the credential to the authenticator when the server does not know it', async () => {
        const signal = vi.fn(async () => {});
        installWebAuthn(async () => fakeAssertion(), { signalUnknownCredential: signal });
        vi.stubGlobal('fetch', rejectingVerify('unknown_credential'));
        const { btn, error } = createLoginContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await clickLogin(btn);

        expect(signal).toHaveBeenCalledWith({ rpId: 'example.test', credentialId: 'AQIDBA' });
        expect(error.textContent).toBe('Unknown passkey');
    });

    it('reports nothing for other rejections', async () => {
        const signal = vi.fn(async () => {});
        installWebAuthn(async () => fakeAssertion(), { signalUnknownCredential: signal });
        vi.stubGlobal('fetch', rejectingVerify('bad_signature'));
        const { btn } = createLoginContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await clickLogin(btn);

        expect(signal).not.toHaveBeenCalled();
    });

    it('carries on when the Signal API is missing or rejects', async () => {
        const signal = vi.fn(() => Promise.reject(new Error('unsupported')));
        installWebAuthn(async () => fakeAssertion(), { signalUnknownCredential: signal });
        vi.stubGlobal('fetch', rejectingVerify('unknown_credential'));
        const { btn, error } = createLoginContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await clickLogin(btn);

        expect(signal).toHaveBeenCalledTimes(1);
        expect(error.textContent).toBe('Unknown passkey');
        expect(btn.disabled).toBe(false);
    });
});
