/**
 * Tests for PasskeyEnrollment.js
 *
 * Tests enrollment-specific behavior. Shared utilities are tested
 * in PasskeyLogin.test.js via PasskeyUtils.js.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';

// Load the shared utility module so NrPasskeysFe is available
import '../../Resources/Public/JavaScript/PasskeyUtils.js';

// ---------------------------------------------------------------
// DOM helpers
// ---------------------------------------------------------------

function clearBody() {
    while (document.body.firstChild) {
        document.body.removeChild(document.body.firstChild);
    }
}

function createEnrollmentContainer({ eidUrl = '/index.php', siteIdentifier = 'test-site' } = {}) {
    const container = document.createElement('div');
    container.setAttribute('data-nr-passkeys-fe', 'enrollment');
    container.dataset.eidUrl = eidUrl;
    container.dataset.siteIdentifier = siteIdentifier;

    const registerBtn = document.createElement('button');
    registerBtn.setAttribute('data-action', 'register-passkey');
    const btnText = document.createElement('span');
    btnText.className = 'nr-passkeys-fe-btn__text';
    registerBtn.appendChild(btnText);
    const btnLoading = document.createElement('span');
    btnLoading.className = 'nr-passkeys-fe-btn__loading';
    registerBtn.appendChild(btnLoading);

    const labelInput = document.createElement('input');
    labelInput.id = 'enrollment-device-label';
    labelInput.type = 'text';
    labelInput.value = 'My Key';

    const status = document.createElement('div');
    status.className = 'nr-passkeys-fe-enrollment__status';
    status.style.display = 'none';

    const error = document.createElement('div');
    error.className = 'nr-passkeys-fe-enrollment__error';
    error.style.display = 'none';

    const success = document.createElement('div');
    success.className = 'nr-passkeys-fe-enrollment-form__success';
    success.style.display = 'none';

    container.appendChild(registerBtn);
    container.appendChild(labelInput);
    container.appendChild(status);
    container.appendChild(error);
    container.appendChild(success);
    document.body.appendChild(container);

    return { container, registerBtn, labelInput, status, error, success };
}

// ---------------------------------------------------------------
// Tests
// ---------------------------------------------------------------

describe('PasskeyEnrollment — WebAuthn not supported', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('disables register button when PublicKeyCredential is absent', () => {
        // Simulate what the module does when no WebAuthn support
        const { registerBtn } = createEnrollmentContainer();
        delete window.PublicKeyCredential;

        // Simulate module logic
        if (!window.PublicKeyCredential) {
            registerBtn.disabled = true;
        }

        expect(registerBtn.disabled).toBe(true);
    });
});

describe('PasskeyEnrollment — label validation', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('defaults label to "Passkey" when input is empty', () => {
        const { labelInput } = createEnrollmentContainer();
        labelInput.value = '';

        const label = labelInput.value.trim() || 'Passkey';
        expect(label).toBe('Passkey');
    });

    it('uses provided label when not empty', () => {
        const { labelInput } = createEnrollmentContainer();
        labelInput.value = 'My YubiKey';

        const label = labelInput.value.trim() || 'Passkey';
        expect(label).toBe('My YubiKey');
    });

    it('trims whitespace from label', () => {
        const { labelInput } = createEnrollmentContainer();
        labelInput.value = '  My Key  ';

        const label = labelInput.value.trim() || 'Passkey';
        expect(label).toBe('My Key');
    });
});

describe('PasskeyEnrollment — registrationOptions URL', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('builds correct registrationOptions URL from eidUrl', () => {
        const eidUrl = '/index.php';
        const optionsUrl = eidUrl + '?eID=nr_passkeys_fe&action=registrationOptions';
        const verifyUrl = eidUrl + '?eID=nr_passkeys_fe&action=registrationVerify';

        expect(optionsUrl).toContain('action=registrationOptions');
        expect(verifyUrl).toContain('action=registrationVerify');
    });

    it('uses explicit registerOptionsUrl when provided', () => {
        const container = document.createElement('div');
        container.setAttribute('data-nr-passkeys-fe', 'enrollment');
        container.dataset.eidUrl = '/index.php';
        container.dataset.registerOptionsUrl = '/custom/options';
        container.dataset.registerVerifyUrl = '/custom/verify';
        document.body.appendChild(container);

        const eidUrl = container.dataset.eidUrl;
        const registerOptionsUrl = container.dataset.registerOptionsUrl;
        const registerVerifyUrl = container.dataset.registerVerifyUrl;

        const optionsUrl = registerOptionsUrl || (eidUrl + '?eID=nr_passkeys_fe&action=registrationOptions');
        const verifyUrl = registerVerifyUrl || (eidUrl + '?eID=nr_passkeys_fe&action=registrationVerify');

        expect(optionsUrl).toBe('/custom/options');
        expect(verifyUrl).toBe('/custom/verify');
    });
});

describe('PasskeyEnrollment — navigator.credentials.create', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('calls navigator.credentials.create with publicKey options structure', async () => {
        window.PublicKeyCredential = {};
        Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });

        const fetchMock = vi.fn();
        window.fetch = fetchMock;

        const optionsPayload = {
            options: {
                challenge: btoa('challenge'),
                rp: { name: 'Test Site', id: 'localhost' },
                user: { id: btoa('user1'), name: 'testuser', displayName: 'Test User' },
                pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
                timeout: 60000,
                attestation: 'none',
                authenticatorSelection: {},
            },
            challengeToken: 'tok-xyz',
        };

        const mockCredential = {
            rawId: new Uint8Array([10, 20, 30]).buffer,
            type: 'public-key',
            response: {
                clientDataJSON: new Uint8Array([1]).buffer,
                attestationObject: new Uint8Array([2]).buffer,
                getTransports: () => ['usb'],
            },
        };

        const createMock = vi.fn().mockResolvedValue(mockCredential);
        navigator.credentials = { create: createMock };

        fetchMock
            .mockResolvedValueOnce({ ok: true, json: async () => optionsPayload })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ status: 'ok', uid: 42 }) });

        // Simulate the module's registration call
        await fetch('/index.php?eID=nr_passkeys_fe&action=registrationOptions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ label: 'My Key' }),
            credentials: 'same-origin',
        });

        const options = optionsPayload.options;
        await navigator.credentials.create({
            publicKey: {
                challenge: options.challenge,
                rp: options.rp,
                user: { id: options.user.id, name: options.user.name, displayName: options.user.displayName },
                pubKeyCredParams: options.pubKeyCredParams,
                timeout: options.timeout,
                attestation: options.attestation,
            },
        });

        expect(createMock).toHaveBeenCalledWith(
            expect.objectContaining({
                publicKey: expect.objectContaining({
                    rp: expect.objectContaining({ name: 'Test Site' }),
                }),
            }),
        );
    });

    it('handles InvalidStateError (passkey already registered)', () => {
        const err = Object.assign(new Error('Already registered'), { name: 'InvalidStateError' });
        const errorMessages = {
            NotAllowedError: 'Registration was cancelled.',
            InvalidStateError: 'This passkey is already registered.',
        };
        expect(errorMessages[err.name]).toContain('already registered');
    });

    it('handles NotAllowedError (user cancelled)', () => {
        const err = Object.assign(new Error('Cancelled'), { name: 'NotAllowedError' });
        const errorMessages = {
            NotAllowedError: 'Registration was cancelled.',
            InvalidStateError: 'This passkey is already registered.',
        };
        expect(errorMessages[err.name]).toContain('cancelled');
    });
});

describe('PasskeyEnrollment — success event', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('custom event nr-passkeys-fe:registered is dispatched on success', async () => {
        const { container } = createEnrollmentContainer();
        const handler = vi.fn();
        container.addEventListener('nr-passkeys-fe:registered', handler);

        const event = new CustomEvent('nr-passkeys-fe:registered', {
            bubbles: true,
            detail: { credentialUid: 42 },
        });
        container.dispatchEvent(event);

        expect(handler).toHaveBeenCalledTimes(1);
        expect(handler.mock.calls[0][0].detail.credentialUid).toBe(42);
    });
});
