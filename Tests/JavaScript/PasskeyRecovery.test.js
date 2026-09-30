/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the SHIPPED Resources/Public/JavaScript/PasskeyRecovery.js.
 *
 * Each test builds a recovery container, imports PasskeyUtils.js and the
 * module afresh (it initialises on load) and drives it through the code field
 * and the form. Only fetch is stubbed; the felogin hand-over is observed
 * through PasskeyUtils' submitLoginToken.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { loadModules, settle, jsonResponse, clearBody } from './support/modules.js';

const EID_URL = 'https://example.test/?eID=nr_passkeys_fe';

function createRecoveryContainer({ eidUrl = EID_URL, username = 'jdoe' } = {}) {
    const container = document.createElement('div');
    container.setAttribute('data-nr-passkeys-fe', 'recovery');
    if (eidUrl) {
        container.dataset.eidUrl = eidUrl;
    }

    const form = document.createElement('form');
    form.setAttribute('data-action', 'recovery-verify');
    const usernameInput = document.createElement('input');
    usernameInput.name = 'recovery_username';
    usernameInput.value = username;
    const codeInput = document.createElement('input');
    codeInput.setAttribute('data-action', 'recovery-format');
    const submitBtn = document.createElement('button');
    submitBtn.type = 'submit';
    submitBtn.setAttribute('data-action', 'recovery-submit');
    const btnText = document.createElement('span');
    btnText.className = 'nr-passkeys-fe-btn__text';
    const btnLoading = document.createElement('span');
    btnLoading.className = 'nr-passkeys-fe-btn__loading';
    submitBtn.append(btnText, btnLoading);
    form.append(usernameInput, codeInput, submitBtn);

    const status = document.createElement('div');
    status.className = 'nr-passkeys-fe-recovery__status';
    const error = document.createElement('div');
    error.className = 'nr-passkeys-fe-recovery__error';
    error.style.display = 'none';

    container.append(form, status, error);
    document.body.appendChild(container);
    return { container, form, usernameInput, codeInput, submitBtn, error };
}

function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input'));
    return input.value;
}

async function submit(form) {
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();
}

beforeEach(() => {
    clearBody();
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    clearBody();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('PasskeyRecovery — code formatting as the user types', () => {
    it.each([
        ['ABCD', 'ABCD'],
        ['ABC', 'ABC'],
        ['ABCDE', 'ABCD-E'],
        ['ABCD1234', 'ABCD-1234'],
        ['abcd1234', 'ABCD-1234'],
        ['AB!C@D#1', 'ABCD-1'],
        ['ABCD-12-34', 'ABCD-1234'],
        ['ABCDEFGHIJ', 'ABCD-EFGH'],
        ['', ''],
    ])('formats %j as %j', async (raw, formatted) => {
        const { codeInput } = createRecoveryContainer();
        await loadModules('PasskeyUtils.js', 'PasskeyRecovery.js');

        expect(type(codeInput, raw)).toBe(formatted);
    });

    it('removes the dash together with the last character before it on Backspace', async () => {
        const { codeInput } = createRecoveryContainer();
        await loadModules('PasskeyUtils.js', 'PasskeyRecovery.js');
        codeInput.value = 'ABCD-';

        const event = new KeyboardEvent('keydown', { key: 'Backspace', cancelable: true });
        codeInput.dispatchEvent(event);

        expect(codeInput.value).toBe('ABCD');
        expect(event.defaultPrevented).toBe(true);
    });

    it('leaves other Backspace presses to the browser', async () => {
        const { codeInput } = createRecoveryContainer();
        await loadModules('PasskeyUtils.js', 'PasskeyRecovery.js');
        codeInput.value = 'ABCD-12';

        const event = new KeyboardEvent('keydown', { key: 'Backspace', cancelable: true });
        codeInput.dispatchEvent(event);

        expect(codeInput.value).toBe('ABCD-12');
        expect(event.defaultPrevented).toBe(false);
    });
});

describe('PasskeyRecovery — submitting', () => {
    it('refuses an incomplete code without a request', async () => {
        const { form, codeInput, error } = createRecoveryContainer();
        await loadModules('PasskeyUtils.js', 'PasskeyRecovery.js');
        type(codeInput, 'ABCD12');

        await submit(form);

        expect(fetch).not.toHaveBeenCalled();
        expect(error.textContent).toContain('XXXX-XXXX');
    });

    it('refuses a missing username without a request', async () => {
        const { form, codeInput, error } = createRecoveryContainer({ username: '  ' });
        await loadModules('PasskeyUtils.js', 'PasskeyRecovery.js');
        type(codeInput, 'ABCD1234');

        await submit(form);

        expect(fetch).not.toHaveBeenCalled();
        expect(error.textContent).toBe('Please enter your username.');
    });

    it('posts username and code to recoveryVerify and hands the token to felogin', async () => {
        const { form, codeInput } = createRecoveryContainer();
        await loadModules('PasskeyUtils.js', 'PasskeyRecovery.js');
        const handOver = vi.spyOn(window.NrPasskeysFe, 'submitLoginToken').mockReturnValue(true);
        fetch.mockResolvedValueOnce(jsonResponse(200, { status: 'ok', loginToken: 'login-token-1' }));
        type(codeInput, 'abcd1234');

        await submit(form);

        const [url, init] = fetch.mock.calls[0];
        expect(url).toContain('action=recoveryVerify');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)).toEqual({ username: 'jdoe', code: 'ABCD-1234' });
        expect(handOver).toHaveBeenCalledWith('login-token-1');
    });

    it('reports the rate limit on 429 and keeps the code', async () => {
        const { form, codeInput, error, submitBtn } = createRecoveryContainer();
        await loadModules('PasskeyUtils.js', 'PasskeyRecovery.js');
        fetch.mockResolvedValueOnce(jsonResponse(429, {}));
        type(codeInput, 'ABCD1234');

        await submit(form);

        expect(error.textContent).toBe('Too many attempts. Please try again later.');
        expect(codeInput.value).toBe('ABCD-1234');
        expect(submitBtn.disabled).toBe(false);
    });

    it('shows the server error and clears the code when it is rejected', async () => {
        const { form, codeInput, error } = createRecoveryContainer();
        await loadModules('PasskeyUtils.js', 'PasskeyRecovery.js');
        fetch.mockResolvedValueOnce(jsonResponse(401, { error: 'Invalid recovery code.' }));
        type(codeInput, 'ABCD1234');

        await submit(form);

        expect(error.textContent).toBe('Invalid recovery code.');
        expect(codeInput.value).toBe('');
    });

    it('reports a network failure', async () => {
        const { form, codeInput, error } = createRecoveryContainer();
        await loadModules('PasskeyUtils.js', 'PasskeyRecovery.js');
        vi.spyOn(console, 'error').mockImplementation(() => {});
        fetch.mockRejectedValueOnce(new TypeError('Failed to fetch'));
        type(codeInput, 'ABCD1234');

        await submit(form);

        expect(error.textContent).toBe('Network error. Please check your connection and try again.');
    });

    it('leaves the form to the browser when no eID endpoint is configured', async () => {
        const { form, codeInput } = createRecoveryContainer({ eidUrl: '' });
        await loadModules('PasskeyUtils.js', 'PasskeyRecovery.js');
        type(codeInput, 'ABCD1234');

        const event = new Event('submit', { cancelable: true });
        form.dispatchEvent(event);
        await settle();

        expect(event.defaultPrevented).toBe(false);
        expect(fetch).not.toHaveBeenCalled();
    });
});
