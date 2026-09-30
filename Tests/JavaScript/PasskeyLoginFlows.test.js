/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the parts of the SHIPPED Resources/Public/JavaScript/PasskeyLogin.js
 * that PasskeyLogin.test.js and PasskeyLoginConditional.test.js leave out:
 * the session hand-over after a verified assertion, the error paths of the
 * options request, the credential list of the username-first flow, the
 * autofill ceremony's failure paths, and the tab and recovery-form controls
 * of the login templates. Only fetch, the WebAuthn API and form submission
 * are stubbed.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { loadModules, settle, jsonResponse, clearBody } from './support/modules.js';

const EID_URL = 'https://example.test/?eID=nr_passkeys_fe';

/**
 * Parse fixed test markup into nodes.
 */
function fragment(markup) {
    return document.createRange().createContextualFragment(markup);
}

function buildLogin({ eidUrl = EID_URL, discoverable = '0', username = 'jdoe' } = {}) {
    const container = document.createElement('div');
    container.setAttribute('data-nr-passkeys-fe', 'login');
    if (eidUrl !== null) {
        container.dataset.eidUrl = eidUrl;
    }
    container.dataset.siteIdentifier = 'main';
    container.dataset.discoverable = discoverable;
    container.appendChild(fragment(`
        <div class="nr-passkeys-fe-passkey-content">
            <button data-action="passkey-login">
                <span class="nr-passkeys-fe-btn__text">Sign in</span>
                <span class="nr-passkeys-fe-btn__loading" style="display:none"></span>
            </button>
            <a href="#" data-action="show-recovery">Use a recovery code</a>
        </div>
        <div class="nr-passkeys-fe-login__status" style="display:none"></div>
        <div class="nr-passkeys-fe-login__error" style="display:none"></div>
        <input name="nr_passkeys_username" />
        <div id="nr-passkeys-fe-recovery" data-nr-passkeys-fe="recovery" style="display:none">
            <a href="#" data-action="hide-recovery">Back</a>
        </div>
    `));
    container.querySelector('[name="nr_passkeys_username"]').value = username;
    document.body.appendChild(container);
    return {
        container,
        btn: container.querySelector('[data-action="passkey-login"]'),
        status: container.querySelector('.nr-passkeys-fe-login__status'),
        error: container.querySelector('.nr-passkeys-fe-login__error'),
        passkeyContent: container.querySelector('.nr-passkeys-fe-passkey-content'),
        recovery: container.querySelector('#nr-passkeys-fe-recovery'),
    };
}

/**
 * The hidden token form the standalone plugin renders; submitLoginToken()
 * fills and submits it.
 */
function appendTokenForm() {
    const form = document.createElement('form');
    form.id = 'nr-passkeys-fe-token-form';
    form.setAttribute('action', '/login');
    document.body.appendChild(form);
    return form;
}

function loginOptions(extra = {}) {
    return {
        options: Object.assign({ challenge: 'YWJjZGVm', rpId: 'example.test', userVerification: 'required' }, extra),
        challengeToken: 'challenge-token-1',
        challengeTtlSeconds: 120,
    };
}

function fakeAssertion() {
    const buf = new Uint8Array([1, 2, 3, 4]).buffer;
    return {
        rawId: buf,
        type: 'public-key',
        response: { clientDataJSON: buf, authenticatorData: buf, signature: buf, userHandle: buf },
    };
}

function installWebAuthn(getImpl, conditional = null) {
    window.PublicKeyCredential = function () {};
    if (conditional !== null) {
        window.PublicKeyCredential.isConditionalMediationAvailable = conditional;
    }
    const get = vi.fn(getImpl);
    Object.defineProperty(navigator, 'credentials', { value: { get }, configurable: true, writable: true });
    return get;
}

/**
 * fetch answering loginOptions with the given options and loginVerify with
 * the given status and body.
 */
function eid(verifyStatus, verifyBody, options = loginOptions()) {
    return vi.fn(async (url) => (String(url).indexOf('loginOptions') !== -1
        ? jsonResponse(200, options)
        : jsonResponse(verifyStatus, verifyBody)));
}

let submitSpy;

beforeEach(() => {
    clearBody();
    sessionStorage.clear();
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
});

afterEach(() => {
    clearBody();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    delete window.PublicKeyCredential;
});

describe('PasskeyLogin — session hand-over', () => {
    it('submits the login token through the token form after a verified assertion', async () => {
        installWebAuthn(async () => fakeAssertion());
        const fetchMock = eid(200, { status: 'ok', loginToken: 'login-token-1' });
        vi.stubGlobal('fetch', fetchMock);
        const form = appendTokenForm();
        const { btn } = buildLogin();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        btn.click();
        await settle(60);

        expect(submitSpy).toHaveBeenCalledTimes(1);
        expect(JSON.parse(form.querySelector('input[name="pass"]').value))
            .toEqual({ _type: 'passkey_token', token: 'login-token-1' });
        const verify = fetchMock.mock.calls.find((c) => String(c[0]).indexOf('loginVerify') !== -1);
        const body = JSON.parse(verify[1].body);
        expect(body).toMatchObject({ challengeToken: 'challenge-token-1', siteIdentifier: 'main' });
        expect(body.assertion).toMatchObject({ id: 'AQIDBA', rawId: 'AQIDBA', type: 'public-key' });
        expect(body.assertion.response.userHandle).toBe('AQIDBA');
        expect(sessionStorage.getItem('nr_passkeys_fe_attempt')).toBeNull();
    });

    it('does not accept a success status without a login token', async () => {
        installWebAuthn(async () => fakeAssertion());
        vi.stubGlobal('fetch', eid(200, { status: 'ok' }));
        appendTokenForm();
        const { btn, error } = buildLogin();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        btn.click();
        await settle(60);

        expect(submitSpy).not.toHaveBeenCalled();
        expect(error.textContent).toBe('Authentication failed. Please try again.');
        expect(btn.disabled).toBe(false);
    });

    it('reports a failed earlier attempt left in the session', async () => {
        installWebAuthn(async () => fakeAssertion());
        sessionStorage.setItem('nr_passkeys_fe_attempt', '1');
        const { error } = buildLogin();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');

        expect(error.textContent).toContain('Passkey authentication failed');
        expect(sessionStorage.getItem('nr_passkeys_fe_attempt')).toBeNull();
    });
});

describe('PasskeyLogin — options request', () => {
    // Two places resolve the URL: PasskeyLogin.js prefixes the origin and
    // NrPasskeysFe.buildEidUrl() passes it as the base of new URL(). This
    // fails only when neither does.
    it('resolves a root-relative eID URL against the page origin', async () => {
        installWebAuthn(() => new Promise(() => {}));
        const fetchMock = eid(200, {});
        vi.stubGlobal('fetch', fetchMock);
        const { btn } = buildLogin({ eidUrl: '/?eID=nr_passkeys_fe' });

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        btn.click();
        await settle(60);

        expect(String(fetchMock.mock.calls[0][0]).startsWith(window.location.origin + '/')).toBe(true);
    });

    it('leaves a container without an eID URL alone', async () => {
        installWebAuthn(async () => fakeAssertion());
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const { btn } = buildLogin({ eidUrl: null });

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        btn.click();
        await settle(60);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('shows the generic error when the request cannot be sent', async () => {
        const get = installWebAuthn(async () => fakeAssertion());
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new TypeError('network down');
        }));
        const { btn, error } = buildLogin();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        btn.click();
        await settle(60);

        expect(error.textContent).toBe('Authentication failed. Please try again.');
        expect(get).not.toHaveBeenCalled();
        expect(btn.disabled).toBe(false);
    });

    it('names the rate limit on HTTP 429', async () => {
        installWebAuthn(async () => fakeAssertion());
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(429, { error: 'ignored' })));
        const { btn, error } = buildLogin();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        btn.click();
        await settle(60);

        expect(error.textContent).toBe('Too many attempts. Please try again later.');
    });

    it('shows the error the server returned for other refusals', async () => {
        installWebAuthn(async () => fakeAssertion());
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(400, { error: 'Unknown site' })));
        const { btn, error } = buildLogin();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        btn.click();
        await settle(60);

        expect(error.textContent).toBe('Unknown site');
    });

    it('passes the allowed credentials of the username-first flow to the authenticator', async () => {
        const get = installWebAuthn(() => new Promise(() => {}));
        vi.stubGlobal('fetch', eid(200, {}, loginOptions({
            allowCredentials: [
                { type: 'public-key', id: 'AQIDBA', transports: ['usb', 'nfc'] },
                { type: 'public-key', id: 'BQYHCA' },
            ],
            timeout: 30000,
        })));
        const { btn } = buildLogin();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        btn.click();
        await settle(60);

        const publicKey = get.mock.calls[0][0].publicKey;
        expect(publicKey.timeout).toBe(30000);
        expect(publicKey.allowCredentials).toHaveLength(2);
        expect(Array.from(new Uint8Array(publicKey.allowCredentials[0].id))).toEqual([1, 2, 3, 4]);
        expect(publicKey.allowCredentials[0].transports).toEqual(['usb', 'nfc']);
        expect(publicKey.allowCredentials[1].transports).toEqual([]);
    });

    it('shows the message of an unexpected error and logs it', async () => {
        const logged = vi.spyOn(console, 'error').mockImplementation(() => {});
        installWebAuthn(async () => {
            throw new TypeError('authenticator exploded');
        });
        vi.stubGlobal('fetch', eid(200, {}));
        const { btn, error } = buildLogin();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        btn.click();
        await settle(60);

        expect(error.textContent).toBe('authenticator exploded');
        expect(logged).toHaveBeenCalledTimes(1);
    });
});

describe('PasskeyLogin — autofill ceremony failures', () => {
    it('arms nothing when conditional mediation is unavailable', async () => {
        const get = installWebAuthn(() => new Promise(() => {}), vi.fn(async () => false));
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        buildLogin({ discoverable: '1', username: '' });

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await settle(60);

        expect(get).not.toHaveBeenCalled();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('arms nothing when the availability check throws', async () => {
        const get = installWebAuthn(() => new Promise(() => {}), vi.fn(async () => {
            throw new Error('unsupported');
        }));
        vi.stubGlobal('fetch', vi.fn());
        buildLogin({ discoverable: '1', username: '' });

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await settle(60);

        expect(get).not.toHaveBeenCalled();
    });

    it('keeps a refused prefetch off the screen', async () => {
        const get = installWebAuthn(() => new Promise(() => {}), vi.fn(async () => true));
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(429, {})));
        const { error } = buildLogin({ discoverable: '1', username: '' });

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await settle(60);

        expect(get).not.toHaveBeenCalled();
        expect(error.style.display).toBe('none');
        expect(error.textContent).toBe('');
    });

    it('keeps a failed prefetch request off the screen', async () => {
        installWebAuthn(() => new Promise(() => {}), vi.fn(async () => true));
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new TypeError('offline');
        }));
        const { error } = buildLogin({ discoverable: '1', username: '' });

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await settle(60);

        expect(error.textContent).toBe('');
    });

    it('re-arms quietly after the user dismisses the autofill menu', async () => {
        const logged = vi.spyOn(console, 'error').mockImplementation(() => {});
        const get = installWebAuthn(() => new Promise(() => {}), vi.fn(async () => true));
        get.mockImplementationOnce(async () => {
            throw Object.assign(new Error('dismissed'), { name: 'NotAllowedError' });
        });
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, loginOptions())));
        buildLogin({ discoverable: '1', username: '' });

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await settle(60);

        expect(get).toHaveBeenCalledTimes(2);
        expect(get.mock.calls[1][0].mediation).toBe('conditional');
        expect(logged).not.toHaveBeenCalled();
    });

    it('logs any other autofill error and re-arms', async () => {
        const logged = vi.spyOn(console, 'error').mockImplementation(() => {});
        const get = installWebAuthn(() => new Promise(() => {}), vi.fn(async () => true));
        get.mockImplementationOnce(async () => {
            throw Object.assign(new Error('broken'), { name: 'InvalidStateError' });
        });
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, loginOptions())));
        buildLogin({ discoverable: '1', username: '' });

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await settle(60);

        expect(logged).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledTimes(2);
    });

    it('stops after the autofill ceremony established a session', async () => {
        const get = installWebAuthn(() => new Promise(() => {}), vi.fn(async () => true));
        get.mockImplementationOnce(async () => fakeAssertion());
        vi.stubGlobal('fetch', eid(200, { status: 'ok', loginToken: 'login-token-2' }));
        appendTokenForm();
        buildLogin({ discoverable: '1', username: '' });

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await settle(80);

        expect(submitSpy).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledTimes(1);
    });

    it('lets the button flow report its own refusal after taking over', async () => {
        const get = installWebAuthn(() => new Promise(() => {}), vi.fn(async () => true));
        vi.stubGlobal('fetch', vi.fn(async (url) => (String(url).indexOf('loginOptions') !== -1
            && get.mock.calls.length > 0
            ? jsonResponse(429, {})
            : jsonResponse(200, loginOptions()))));
        const { btn, error } = buildLogin({ discoverable: '1', username: '' });

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        await settle(60);
        expect(get).toHaveBeenCalledTimes(1);

        btn.click();
        await settle(60);

        expect(error.textContent).toBe('Too many attempts. Please try again later.');
        expect(get.mock.calls[0][0].signal.aborted).toBe(true);
    });
});

describe('PasskeyLogin — tabs', () => {
    function buildTabs() {
        document.body.appendChild(fragment(`
            <div class="nr-passkeys-fe-card">
                <div class="nr-passkeys-fe-tabs" role="tablist">
                    <button class="nr-passkeys-fe-tab nr-passkeys-fe-tab--active" data-action="switch-tab" data-tab="passkey" role="tab">Passkey</button>
                    <button class="nr-passkeys-fe-tab" data-action="switch-tab" data-tab="password" role="tab">Password</button>
                    <button class="nr-passkeys-fe-tab" data-action="switch-tab" data-tab="other" role="tab">Other</button>
                </div>
                <div class="nr-passkeys-fe-tabpanel" id="nr-passkeys-fe-panel-passkey"></div>
                <div class="nr-passkeys-fe-tabpanel" id="nr-passkeys-fe-panel-password" style="display:none"></div>
                <div class="nr-passkeys-fe-tabpanel" id="nr-passkeys-fe-panel-other" style="display:none"></div>
            </div>
        `));
        return Array.from(document.querySelectorAll('[data-action="switch-tab"]'));
    }

    function panel(name) {
        return document.getElementById('nr-passkeys-fe-panel-' + name);
    }

    it('makes only the active tab focusable on load', async () => {
        const tabs = buildTabs();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');

        expect(tabs.map((t) => t.getAttribute('tabindex'))).toEqual(['0', '-1', '-1']);
    });

    it('shows the panel of the clicked tab and hides the others', async () => {
        const tabs = buildTabs();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        tabs[1].click();

        expect(tabs[1].classList.contains('nr-passkeys-fe-tab--active')).toBe(true);
        expect(tabs[1].getAttribute('aria-selected')).toBe('true');
        expect(tabs[0].classList.contains('nr-passkeys-fe-tab--active')).toBe(false);
        expect(tabs[0].getAttribute('aria-selected')).toBe('false');
        expect(tabs.map((t) => t.getAttribute('tabindex'))).toEqual(['-1', '0', '-1']);
        expect(panel('password').style.display).toBe('');
        expect(panel('passkey').style.display).toBe('none');
        expect(panel('other').style.display).toBe('none');
    });

    it.each([
        ['ArrowRight', 0, 1],
        ['ArrowDown', 2, 0],
        ['ArrowLeft', 0, 2],
        ['ArrowUp', 1, 0],
        ['Home', 2, 0],
        ['End', 0, 2],
    ])('moves with %s from tab %i to tab %i', async (key, from, to) => {
        const tabs = buildTabs();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        const event = new KeyboardEvent('keydown', { key, cancelable: true });
        tabs[from].dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(tabs[to].getAttribute('aria-selected')).toBe('true');
        expect(document.activeElement).toBe(tabs[to]);
    });

    it('ignores other keys', async () => {
        const tabs = buildTabs();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        const event = new KeyboardEvent('keydown', { key: 'a', cancelable: true });
        tabs[0].dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(tabs[0].getAttribute('tabindex')).toBe('0');
    });

    it('ignores a tab outside a login card', async () => {
        const stray = document.createElement('button');
        stray.className = 'nr-passkeys-fe-tab';
        stray.dataset.action = 'switch-tab';
        stray.dataset.tab = 'password';
        document.body.appendChild(stray);

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');
        stray.click();

        expect(stray.getAttribute('aria-selected')).toBeNull();
    });
});

describe('PasskeyLogin — recovery form toggle', () => {
    it('swaps the passkey content for the recovery form and back', async () => {
        installWebAuthn(async () => fakeAssertion());
        const { container, passkeyContent, recovery } = buildLogin();

        await loadModules('PasskeyUtils.js', 'PasskeyLogin.js');

        const show = new MouseEvent('click', { cancelable: true, bubbles: true });
        container.querySelector('[data-action="show-recovery"]').dispatchEvent(show);
        expect(show.defaultPrevented).toBe(true);
        expect(passkeyContent.style.display).toBe('none');
        expect(recovery.style.display).toBe('');

        const hide = new MouseEvent('click', { cancelable: true, bubbles: true });
        recovery.querySelector('[data-action="hide-recovery"]').dispatchEvent(hide);
        expect(hide.defaultPrevented).toBe(true);
        expect(recovery.style.display).toBe('none');
        expect(passkeyContent.style.display).toBe('');
    });
});
