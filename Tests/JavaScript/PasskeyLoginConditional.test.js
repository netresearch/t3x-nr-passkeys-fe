/**
 * Tests for the conditional-UI (autofill) ceremony in the SHIPPED login module.
 *
 * These import Resources/Public/JavaScript/PasskeyLogin.js itself and drive it
 * through a jsdom login container with a stubbed WebAuthn API, so the
 * assertions cover the real control flow rather than a copy of it. The module
 * is an IIFE that initialises on load, which is why each test resets the module
 * registry and rebuilds the DOM first.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const EID_URL = 'https://example.test/?eID=nr_passkeys_fe';
const CHALLENGE_B64 = 'YWJjZGVmZ2hpamtsbW5vcA';

function buildLoginDom() {
    document.body.innerHTML = `
        <div data-nr-passkeys-fe="login"
             data-eid-url="${EID_URL}"
             data-site-identifier="main"
             data-discoverable="1">
            <div class="nr-passkeys-fe-login__error" style="display:none"></div>
            <div class="nr-passkeys-fe-login__status" style="display:none"></div>
            <button data-action="passkey-login">
                <span class="nr-passkeys-fe-btn__text">Sign in</span>
                <span class="nr-passkeys-fe-btn__loading" style="display:none"></span>
            </button>
            <input name="nr_passkeys_username" />
        </div>
    `;
}

function optionsPayload(ttlSeconds = 120) {
    return {
        options: {
            challenge: CHALLENGE_B64,
            rpId: 'example.test',
            userVerification: 'required',
        },
        challengeToken: 'challenge-token-1',
        challengeTtlSeconds: ttlSeconds,
    };
}

function fakeAssertion() {
    const buf = new Uint8Array([1, 2, 3, 4]).buffer;
    return {
        rawId: buf,
        type: 'public-key',
        response: {
            clientDataJSON: buf,
            authenticatorData: buf,
            signature: buf,
            userHandle: null,
        },
    };
}

function installWebAuthnStub(getImpl) {
    window.PublicKeyCredential = function () {};
    window.PublicKeyCredential.isConditionalMediationAvailable = vi.fn(async () => true);
    const get = vi.fn(getImpl);
    Object.defineProperty(navigator, 'credentials', {
        value: { get },
        configurable: true,
        writable: true,
    });
    return get;
}

async function loadModule() {
    await import('../../Resources/Public/JavaScript/PasskeyUtils.js');
    await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
}

async function settle(rounds = 30) {
    for (let i = 0; i < rounds; i++) {
        await Promise.resolve();
    }
}

beforeEach(() => {
    vi.resetModules();
    vi.useRealTimers();
    sessionStorage.clear();
    buildLoginDom();
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('conditional UI ceremony (frontend)', () => {
    // The username field used to carry autocomplete="username webauthn" while
    // no ceremony was ever armed. The attribute only advertises autofill; with
    // no pending credentials.get({mediation:'conditional'}) the browser has no
    // passkey to put in the menu, so the documented flow could not happen.
    it('arms a conditional ceremony on load, not just the autocomplete hint', async () => {
        const get = installWebAuthnStub(() => new Promise(() => {}));
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => optionsPayload(),
        })));

        await loadModule();
        await settle();

        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][0].mediation).toBe('conditional');
        expect(document.querySelector('[name="nr_passkeys_username"]').getAttribute('autocomplete'))
            .toContain('webauthn');
    });

    it('re-arms after the server rejects the picked passkey', async () => {
        const get = installWebAuthnStub(async () => fakeAssertion());
        const fetchMock = vi.fn(async (url) => {
            if (String(url).indexOf('loginOptions') !== -1) {
                return { ok: true, status: 200, json: async () => optionsPayload() };
            }
            return { ok: false, status: 401, json: async () => ({ error: 'nope' }) };
        });
        vi.stubGlobal('fetch', fetchMock);

        await loadModule();
        await settle(60);

        expect(get.mock.calls.length).toBeGreaterThanOrEqual(2);
        expect(get.mock.calls[1][0].mediation).toBe('conditional');
        const optionsCalls = fetchMock.mock.calls.filter((c) => String(c[0]).indexOf('loginOptions') !== -1);
        expect(optionsCalls.length).toBeGreaterThanOrEqual(2);
    });

    it('refreshes the challenge before it expires while the field is unfocused', async () => {
        vi.useFakeTimers();
        const get = installWebAuthnStub(() => new Promise(() => {}));
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => optionsPayload(120),
        })));

        await loadModule();
        await vi.advanceTimersByTimeAsync(0);
        expect(get).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(60000);
        await vi.advanceTimersByTimeAsync(0);

        expect(get.mock.calls.length).toBeGreaterThanOrEqual(2);
        expect(get.mock.calls[0][0].signal.aborted).toBe(true);
    });

    it('does not close the autofill menu while the username field has focus', async () => {
        vi.useFakeTimers();
        const get = installWebAuthnStub(() => new Promise(() => {}));
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => optionsPayload(120),
        })));

        await loadModule();
        await vi.advanceTimersByTimeAsync(0);

        const username = document.querySelector('[name="nr_passkeys_username"]');
        username.focus();
        await vi.advanceTimersByTimeAsync(60000);
        await vi.advanceTimersByTimeAsync(0);

        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][0].signal.aborted).toBe(false);

        username.blur();
        await vi.advanceTimersByTimeAsync(0);
        expect(get.mock.calls.length).toBeGreaterThanOrEqual(2);
    });

    it('lets the button flow abort the armed ceremony instead of racing it', async () => {
        // Two credentials.get() ceremonies may not be in flight at once: the
        // browser rejects the second. The button has to cancel the armed one.
        const get = installWebAuthnStub(() => new Promise(() => {}));
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => optionsPayload(),
        })));

        await loadModule();
        await settle();
        expect(get).toHaveBeenCalledTimes(1);

        document.querySelector('[data-action="passkey-login"]').click();
        await settle();

        expect(get.mock.calls[0][0].signal.aborted).toBe(true);
        // The modal ceremony carries no mediation option.
        expect(get.mock.calls[1][0].mediation).toBeUndefined();
    });
});
