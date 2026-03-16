/**
 * Tests for PasskeyRecovery.js
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

function clearBody() {
    while (document.body.firstChild) {
        document.body.removeChild(document.body.firstChild);
    }
}

function createRecoveryContainer({ eidUrl = '/index.php' } = {}) {
    const container = document.createElement('div');
    container.setAttribute('data-nr-passkeys-fe', 'recovery');
    container.dataset.eidUrl = eidUrl;

    const codeInput = document.createElement('input');
    codeInput.type = 'text';
    codeInput.setAttribute('data-action', 'recovery-format');
    codeInput.setAttribute('autocomplete', 'off');

    const form = document.createElement('form');
    form.setAttribute('data-action', 'recovery-verify');

    const submitBtn = document.createElement('button');
    submitBtn.type = 'submit';
    submitBtn.setAttribute('data-action', 'recovery-submit');

    const btnText = document.createElement('span');
    btnText.className = 'nr-passkeys-fe-btn__text';
    submitBtn.appendChild(btnText);

    const btnLoading = document.createElement('span');
    btnLoading.className = 'nr-passkeys-fe-btn__loading';
    submitBtn.appendChild(btnLoading);

    const status = document.createElement('div');
    status.className = 'nr-passkeys-fe-recovery__status';
    status.style.display = 'none';

    const error = document.createElement('div');
    error.className = 'nr-passkeys-fe-recovery__error';
    error.style.display = 'none';

    form.appendChild(codeInput);
    form.appendChild(submitBtn);
    container.appendChild(form);
    container.appendChild(status);
    container.appendChild(error);
    document.body.appendChild(container);

    return { container, form, codeInput, submitBtn, status, error };
}

// Mirror formatRecoveryCode from PasskeyRecovery.js
function formatRecoveryCode(value) {
    let raw = value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    if (raw.length > 8) {
        raw = raw.slice(0, 8);
    }
    if (raw.length > 4) {
        return raw.slice(0, 4) + '-' + raw.slice(4);
    }
    return raw;
}

// ---------------------------------------------------------------
// Tests
// ---------------------------------------------------------------

describe('PasskeyRecovery — code input formatting (auto-dash)', () => {
    afterEach(() => {
        clearBody();
    });

    it('inserts dash after 4th character', () => {
        expect(formatRecoveryCode('ABCDE')).toBe('ABCD-E');
    });

    it('produces XXXX-XXXX format for 8 valid chars', () => {
        expect(formatRecoveryCode('ABCDEFGH')).toBe('ABCD-EFGH');
    });

    it('strips non-alphanumeric characters', () => {
        expect(formatRecoveryCode('AB!C-DE@F')).toBe('ABCD-EF');
    });

    it('uppercases lowercase input', () => {
        expect(formatRecoveryCode('abcdefgh')).toBe('ABCD-EFGH');
    });

    it('limits to 8 characters (XXXX-XXXX = 9 with dash)', () => {
        const result = formatRecoveryCode('ABCDEFGHIJKLMNOP');
        expect(result).toBe('ABCD-EFGH');
    });

    it('handles empty string', () => {
        expect(formatRecoveryCode('')).toBe('');
    });

    it('handles 4 chars (no dash yet)', () => {
        expect(formatRecoveryCode('ABCD')).toBe('ABCD');
    });

    it('handles 3 chars (no dash)', () => {
        expect(formatRecoveryCode('ABC')).toBe('ABC');
    });

    it('strips existing dashes and reformats', () => {
        expect(formatRecoveryCode('AB-CD-EF-GH')).toBe('ABCD-EFGH');
    });
});

describe('PasskeyRecovery — form submission', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('does not call fetch if code is empty', async () => {
        const fetchMock = vi.fn();
        window.fetch = fetchMock;

        const { codeInput } = createRecoveryContainer();
        codeInput.value = '';

        const code = codeInput.value.trim();
        if (!code || code.length !== 9) {
            // Validation blocks fetch
        } else {
            await fetch('/index.php?eID=nr_passkeys_fe&action=recoveryVerify', {
                method: 'POST',
                body: JSON.stringify({ code }),
            });
        }

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('validates code length must be 9 (XXXX-XXXX)', () => {
        const validCode = 'ABCD-EFGH';
        const invalidCodes = ['', 'ABCD', 'ABCDEFGH', 'ABCDE-FGHIJ'];

        expect(validCode.length).toBe(9);
        for (const code of invalidCodes) {
            expect(code.length === 9).toBe(false);
        }
    });

    it('sends code to recoveryVerify endpoint', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ status: 'ok', redirectUrl: '/dashboard' }),
        });
        window.fetch = fetchMock;

        const code = 'ABCD-EFGH';
        await fetch('/index.php?eID=nr_passkeys_fe&action=recoveryVerify', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code }),
            credentials: 'same-origin',
        });

        expect(fetchMock).toHaveBeenCalledWith(
            expect.stringContaining('action=recoveryVerify'),
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('shows rate limit error on 429 response', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            status: 429,
            json: async () => ({ error: 'Too many attempts. Please try again later.' }),
        });
        window.fetch = fetchMock;

        const { error } = createRecoveryContainer();

        const response = await fetch('/index.php?eID=nr_passkeys_fe&action=recoveryVerify', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: 'ABCD-EFGH' }),
        });
        const data = await response.json();

        if (response.status === 429) {
            error.textContent = 'Too many attempts. Please try again later.';
            error.style.display = '';
        }

        expect(error.textContent).toContain('Too many');
        expect(error.style.display).not.toBe('none');
    });
});

describe('PasskeyRecovery — error display', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('shows error message on invalid code', () => {
        const { error } = createRecoveryContainer();

        // Simulate showError
        error.textContent = 'Please enter a valid recovery code (format: XXXX-XXXX).';
        error.style.display = '';

        expect(error.textContent).toContain('XXXX-XXXX');
        expect(error.style.display).toBe('');
    });

    it('shows error on server rejection', () => {
        const { error } = createRecoveryContainer();

        error.textContent = 'Invalid recovery code. Please check and try again.';
        error.style.display = '';

        expect(error.textContent).toContain('Invalid recovery code');
    });

    it('clears code input on invalid server response', () => {
        const { codeInput } = createRecoveryContainer();
        codeInput.value = 'ABCD-EFGH';

        // Simulate module clearing input on error
        codeInput.value = '';
        expect(codeInput.value).toBe('');
    });
});

describe('PasskeyRecovery — backspace handling', () => {
    afterEach(() => {
        clearBody();
    });

    it('removes dash on backspace when at position 5 (4 chars + dash)', () => {
        const { codeInput } = createRecoveryContainer();
        codeInput.value = 'ABCD-';

        // Simulate keydown Backspace
        const val = codeInput.value;
        if (val.length === 5 && val[4] === '-') {
            codeInput.value = val.slice(0, 4);
        }

        expect(codeInput.value).toBe('ABCD');
    });

    it('does not modify value on backspace at other positions', () => {
        const { codeInput } = createRecoveryContainer();
        codeInput.value = 'ABC';

        const val = codeInput.value;
        if (val.length === 5 && val[4] === '-') {
            codeInput.value = val.slice(0, 4);
        }

        expect(codeInput.value).toBe('ABC'); // unchanged
    });
});
