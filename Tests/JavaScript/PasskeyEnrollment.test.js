/**
 * Tests for the SHIPPED Resources/Public/JavaScript/PasskeyEnrollment.js.
 *
 * Each test builds an enrollment container, imports PasskeyUtils.js and the
 * module afresh (the module initialises on load), and drives it through its
 * register button. Only the network (fetch) and the WebAuthn API
 * (navigator.credentials.create) are stubbed.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { loadModules, settle, jsonResponse, clearBody } from './support/modules.js';

const EID_URL = 'https://example.test/?eID=nr_passkeys_fe';

function createEnrollmentContainer({ dataset = { eidUrl: EID_URL }, label = 'My Key' } = {}) {
    const container = document.createElement('div');
    container.setAttribute('data-nr-passkeys-fe', 'enrollment');
    Object.assign(container.dataset, dataset);

    const registerBtn = document.createElement('button');
    registerBtn.setAttribute('data-action', 'register-passkey');
    const btnText = document.createElement('span');
    btnText.className = 'nr-passkeys-fe-btn__text';
    const btnLoading = document.createElement('span');
    btnLoading.className = 'nr-passkeys-fe-btn__loading';
    registerBtn.append(btnText, btnLoading);

    const labelInput = document.createElement('input');
    labelInput.id = 'enrollment-device-label';
    labelInput.value = label;

    const status = document.createElement('div');
    status.className = 'nr-passkeys-fe-enrollment__status';
    const error = document.createElement('div');
    error.className = 'nr-passkeys-fe-enrollment__error';
    error.style.display = 'none';
    const success = document.createElement('div');
    success.className = 'nr-passkeys-fe-enrollment-form__success';
    success.style.display = 'none';

    container.append(registerBtn, labelInput, status, error, success);
    document.body.appendChild(container);
    return { container, registerBtn, labelInput, error, success };
}

function registrationOptions(overrides = {}) {
    return {
        options: {
            challenge: 'YWJjZGVm',
            rp: { name: 'Test Site', id: 'example.test' },
            user: { id: 'dXNlcjE', name: 'testuser', displayName: 'Test User' },
            pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
            ...overrides,
        },
        challengeToken: 'tok-xyz',
    };
}

function fakeCredential() {
    return {
        rawId: new Uint8Array([10, 20, 30]).buffer,
        type: 'public-key',
        response: {
            clientDataJSON: new Uint8Array([1]).buffer,
            attestationObject: new Uint8Array([2]).buffer,
            getTransports: () => ['usb'],
        },
    };
}

function installWebAuthn(createImpl) {
    window.PublicKeyCredential = function () {};
    const create = vi.fn(createImpl);
    Object.defineProperty(navigator, 'credentials', { value: { create }, configurable: true, writable: true });
    return create;
}

function stubFetch(...responses) {
    const fetchMock = vi.fn();
    responses.forEach((r) => fetchMock.mockResolvedValueOnce(r));
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

async function register(registerBtn) {
    registerBtn.click();
    await settle(40);
}

beforeEach(() => {
    clearBody();
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
});

afterEach(() => {
    clearBody();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    delete window.PublicKeyCredential;
});

describe('PasskeyEnrollment — feature detection', () => {
    it('disables the button and explains when WebAuthn is missing', async () => {
        delete window.PublicKeyCredential;
        const { registerBtn, error } = createEnrollmentContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyEnrollment.js');

        expect(registerBtn.disabled).toBe(true);
        expect(error.textContent).toContain('does not support Passkeys');
    });

    it('disables the button on an insecure page', async () => {
        installWebAuthn(async () => fakeCredential());
        Object.defineProperty(window, 'isSecureContext', { value: false, configurable: true });
        const { registerBtn, error } = createEnrollmentContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyEnrollment.js');

        expect(registerBtn.disabled).toBe(true);
        expect(error.textContent).toContain('secure connection');
    });

    it('does nothing for a container without an endpoint', async () => {
        installWebAuthn(async () => fakeCredential());
        const fetchMock = stubFetch();
        const { registerBtn } = createEnrollmentContainer({ dataset: {} });

        await loadModules('PasskeyUtils.js', 'PasskeyEnrollment.js');
        await register(registerBtn);

        expect(fetchMock).not.toHaveBeenCalled();
    });
});

describe('PasskeyEnrollment — registration', () => {
    it('requests options, creates the credential and verifies it at the eID endpoints', async () => {
        const create = installWebAuthn(async () => fakeCredential());
        const fetchMock = stubFetch(jsonResponse(200, registrationOptions()), jsonResponse(200, { status: 'ok', uid: 42 }));
        const { container, registerBtn, success, labelInput } = createEnrollmentContainer({ label: '  My YubiKey  ' });
        const registered = vi.fn();
        container.addEventListener('nr-passkeys-fe:registered', registered);

        await loadModules('PasskeyUtils.js', 'PasskeyEnrollment.js');
        await register(registerBtn);

        const [optionsUrl, optionsInit] = fetchMock.mock.calls[0];
        expect(optionsUrl).toContain('action=registrationOptions');
        expect(optionsInit.method).toBe('POST');
        expect(JSON.parse(optionsInit.body)).toEqual({ label: 'My YubiKey' });

        const publicKey = create.mock.calls[0][0].publicKey;
        expect(publicKey.rp).toEqual({ name: 'Test Site', id: 'example.test' });
        expect(publicKey.user.name).toBe('testuser');
        expect(publicKey.challenge).toBeInstanceOf(ArrayBuffer);
        expect(publicKey.timeout).toBe(60000);
        expect(publicKey.attestation).toBe('none');

        const [verifyUrl, verifyInit] = fetchMock.mock.calls[1];
        expect(verifyUrl).toContain('action=registrationVerify');
        const verifyBody = JSON.parse(verifyInit.body);
        expect(verifyBody.challengeToken).toBe('tok-xyz');
        expect(verifyBody.label).toBe('My YubiKey');
        expect(verifyBody.credential.id).toBe('ChQe');
        expect(verifyBody.credential.response.transports).toEqual(['usb']);

        expect(success.style.display).toBe('');
        expect(labelInput.value).toBe('Passkey');
        expect(registered).toHaveBeenCalledTimes(1);
        expect(registered.mock.calls[0][0].detail.credentialUid).toBe(42);
    });

    it('falls back to the label "Passkey" when the field is empty', async () => {
        installWebAuthn(async () => fakeCredential());
        const fetchMock = stubFetch(jsonResponse(200, registrationOptions()), jsonResponse(200, { status: 'ok' }));
        const { registerBtn } = createEnrollmentContainer({ label: '   ' });

        await loadModules('PasskeyUtils.js', 'PasskeyEnrollment.js');
        await register(registerBtn);

        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ label: 'Passkey' });
    });

    it('uses explicit option and verify URLs when the container provides them', async () => {
        installWebAuthn(async () => fakeCredential());
        const fetchMock = stubFetch(jsonResponse(200, registrationOptions()), jsonResponse(200, { status: 'ok' }));
        const { registerBtn } = createEnrollmentContainer({
            dataset: { registerOptionsUrl: '/custom/options', registerVerifyUrl: '/custom/verify' },
        });

        await loadModules('PasskeyUtils.js', 'PasskeyEnrollment.js');
        await register(registerBtn);

        expect(fetchMock.mock.calls[0][0]).toBe('/custom/options');
        expect(fetchMock.mock.calls[1][0]).toBe('/custom/verify');
    });

    it('passes excluded credentials on as buffers', async () => {
        const create = installWebAuthn(async () => fakeCredential());
        stubFetch(
            jsonResponse(200, registrationOptions({ excludeCredentials: [{ type: 'public-key', id: 'AQID', transports: ['internal'] }] })),
            jsonResponse(200, { status: 'ok' }),
        );
        const { registerBtn } = createEnrollmentContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyEnrollment.js');
        await register(registerBtn);

        const excluded = create.mock.calls[0][0].publicKey.excludeCredentials;
        expect(excluded).toHaveLength(1);
        expect(new Uint8Array(excluded[0].id)).toEqual(new Uint8Array([1, 2, 3]));
        expect(excluded[0].transports).toEqual(['internal']);
    });
});

describe('PasskeyEnrollment — errors', () => {
    it('shows the server error when the options request fails', async () => {
        const create = installWebAuthn(async () => fakeCredential());
        stubFetch(jsonResponse(403, { error: 'Not logged in' }));
        const { registerBtn, error } = createEnrollmentContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyEnrollment.js');
        await register(registerBtn);

        expect(error.textContent).toBe('Not logged in');
        expect(create).not.toHaveBeenCalled();
        expect(registerBtn.disabled).toBe(false);
    });

    it.each([
        ['NotAllowedError', 'Registration was cancelled.'],
        ['AbortError', 'Registration was cancelled.'],
        ['InvalidStateError', 'This passkey is already registered.'],
    ])('maps %s from the authenticator to its message', async (name, message) => {
        installWebAuthn(async () => {
            throw Object.assign(new Error('from authenticator'), { name });
        });
        stubFetch(jsonResponse(200, registrationOptions()));
        const { registerBtn, error } = createEnrollmentContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyEnrollment.js');
        await register(registerBtn);

        expect(error.textContent).toBe(message);
        expect(registerBtn.disabled).toBe(false);
    });

    it('shows the verify error and fires no event when the server rejects the credential', async () => {
        installWebAuthn(async () => fakeCredential());
        stubFetch(jsonResponse(200, registrationOptions()), jsonResponse(400, { error: 'Attestation invalid' }));
        const { container, registerBtn, error, success } = createEnrollmentContainer();
        const registered = vi.fn();
        container.addEventListener('nr-passkeys-fe:registered', registered);

        await loadModules('PasskeyUtils.js', 'PasskeyEnrollment.js');
        await register(registerBtn);

        expect(error.textContent).toBe('Attestation invalid');
        expect(success.style.display).toBe('none');
        expect(registered).not.toHaveBeenCalled();
    });
});
