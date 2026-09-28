/**
 * Tests for the SHIPPED Resources/Public/JavaScript/PasskeyManagement.js.
 *
 * Each test builds a management container, imports PasskeyUtils.js and the
 * module afresh (it initialises on load) and drives it through the DOM:
 * the registered event, the rename and remove buttons. Only fetch and
 * window.confirm are stubbed.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { loadModules, settle, jsonResponse, clearBody } from './support/modules.js';

const LIST_URL = '/list';
const RENAME_URL = '/rename';
const REMOVE_URL = '/remove';

function createManagementContainer() {
    const container = document.createElement('div');
    container.setAttribute('data-nr-passkeys-fe', 'management');
    container.dataset.listUrl = LIST_URL;
    container.dataset.renameUrl = RENAME_URL;
    container.dataset.removeUrl = REMOVE_URL;

    const table = document.createElement('table');
    const tbody = document.createElement('tbody');
    tbody.id = 'nr-passkeys-fe-credential-body';
    table.appendChild(tbody);

    const empty = document.createElement('div');
    empty.className = 'nr-passkeys-fe-management__empty';
    const warning = document.createElement('div');
    warning.className = 'nr-passkeys-fe-management__warning';
    const error = document.createElement('div');
    error.className = 'nr-passkeys-fe-management__error';
    error.style.display = 'none';
    const status = document.createElement('div');
    status.className = 'nr-passkeys-fe-management__status';
    status.style.display = 'none';
    const registerBtn = document.createElement('button');
    registerBtn.setAttribute('data-action', 'register-passkey');

    container.append(table, empty, warning, error, status, registerBtn);
    document.body.appendChild(container);
    return { container, table, tbody, empty, warning, error, status, registerBtn };
}

const CREDENTIALS = [
    { uid: 1, label: 'iPhone', createdAt: 1700000000, lastUsedAt: 0 },
    { uid: 2, label: '', createdAt: 0, lastUsedAt: 1700000500 },
];

/**
 * Load the module and render the list through its own refresh path, which the
 * enrollment module triggers with the nr-passkeys-fe:registered event.
 */
async function renderWith(parts, credentials) {
    await loadModules('PasskeyUtils.js', 'PasskeyManagement.js');
    fetch.mockResolvedValueOnce(jsonResponse(200, { credentials }));
    parts.container.dispatchEvent(new CustomEvent('nr-passkeys-fe:registered'));
    await settle();
}

beforeEach(() => {
    clearBody();
    window.PublicKeyCredential = function () {};
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    clearBody();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    vi.useRealTimers();
    delete window.PublicKeyCredential;
});

describe('PasskeyManagement — list rendering', () => {
    it('renders one row per credential with its label, and "Unnamed" for an empty one', async () => {
        const parts = createManagementContainer();

        await renderWith(parts, CREDENTIALS);

        expect(fetch).toHaveBeenCalledWith(LIST_URL, expect.objectContaining({ method: 'GET' }));
        const rows = parts.tbody.querySelectorAll('tr.nr-passkeys-fe-management__row');
        expect(rows).toHaveLength(2);
        expect(rows[0].dataset.uid).toBe('1');
        expect(rows[0].querySelector('.nr-passkeys-fe-management__label').textContent).toBe('iPhone');
        expect(rows[1].querySelector('.nr-passkeys-fe-management__label').textContent).toBe('Unnamed');
        expect(rows[0].children[2].textContent).toBe('Never');
        expect(rows[1].children[1].textContent).toBe('—');
        expect(parts.empty.style.display).toBe('none');
        expect(parts.warning.style.display).toBe('none');
    });

    it('renders the label as text, never as markup', async () => {
        const parts = createManagementContainer();

        await renderWith(parts, [{ uid: 7, label: '<img src=x onerror=alert(1)>', createdAt: 0, lastUsedAt: 0 }]);

        const label = parts.tbody.querySelector('.nr-passkeys-fe-management__label');
        expect(label.textContent).toBe('<img src=x onerror=alert(1)>');
        expect(label.querySelector('img')).toBeNull();
    });

    it('gives every row a rename and a remove button for its uid', async () => {
        const parts = createManagementContainer();

        await renderWith(parts, CREDENTIALS);

        const rename = parts.tbody.querySelector('[data-action="rename-credential"]');
        const remove = parts.tbody.querySelector('[data-action="remove-credential"]');
        expect(rename.dataset.uid).toBe('1');
        expect(remove.dataset.uid).toBe('1');
        expect(remove.dataset.label).toBe('iPhone');
    });

    it('warns when only one passkey is left', async () => {
        const parts = createManagementContainer();

        await renderWith(parts, [CREDENTIALS[0]]);

        expect(parts.warning.style.display).toBe('');
    });

    it('shows the empty message and hides the table when no passkey is left', async () => {
        const parts = createManagementContainer();

        await renderWith(parts, []);

        expect(parts.empty.style.display).toBe('');
        expect(parts.table.style.display).toBe('none');
    });

    it('keeps the register button usable only where WebAuthn exists', async () => {
        delete window.PublicKeyCredential;
        const parts = createManagementContainer();

        await loadModules('PasskeyUtils.js', 'PasskeyManagement.js');

        expect(parts.registerBtn.disabled).toBe(true);
    });
});

describe('PasskeyManagement — rename', () => {
    async function startRename(parts) {
        await renderWith(parts, CREDENTIALS);
        parts.tbody.querySelector('[data-action="rename-credential"]').click();
        return parts.tbody.querySelector('.nr-passkeys-fe-management__rename-input');
    }

    it('posts the uid and the trimmed new label and shows it on success', async () => {
        const parts = createManagementContainer();
        const input = await startRename(parts);
        expect(input.value).toBe('iPhone');

        fetch.mockResolvedValueOnce(jsonResponse(200, { status: 'ok' }));
        input.value = '  Work phone  ';
        input.dispatchEvent(new Event('blur'));
        await settle();

        const [url, init] = fetch.mock.calls.at(-1);
        expect(url).toBe(RENAME_URL);
        expect(JSON.parse(init.body)).toEqual({ uid: '1', label: 'Work phone' });
        expect(parts.tbody.querySelector('.nr-passkeys-fe-management__label').textContent).toBe('Work phone');
        expect(parts.status.textContent).toBe('Passkey renamed successfully.');
    });

    it('restores the old label and shows the error when the server refuses', async () => {
        const parts = createManagementContainer();
        const input = await startRename(parts);

        fetch.mockResolvedValueOnce(jsonResponse(400, { error: 'Label too long' }));
        input.value = 'Something else';
        input.dispatchEvent(new Event('blur'));
        await settle();

        expect(parts.tbody.querySelector('.nr-passkeys-fe-management__label').textContent).toBe('iPhone');
        expect(parts.error.textContent).toBe('Label too long');
    });

    it('sends nothing for an unchanged label or after Escape', async () => {
        const parts = createManagementContainer();
        const input = await startRename(parts);
        const callsBefore = fetch.mock.calls.length;

        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        input.dispatchEvent(new Event('blur'));
        await settle();

        expect(fetch.mock.calls.length).toBe(callsBefore);
        expect(parts.tbody.querySelector('.nr-passkeys-fe-management__label').textContent).toBe('iPhone');
    });
});

describe('PasskeyManagement — remove', () => {
    it('asks first and sends nothing when the user cancels', async () => {
        const parts = createManagementContainer();
        await renderWith(parts, CREDENTIALS);
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const callsBefore = fetch.mock.calls.length;

        parts.tbody.querySelector('[data-action="remove-credential"]').click();
        await settle();

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(fetch.mock.calls.length).toBe(callsBefore);
    });

    it('names the passkey in the question exactly as it is labelled', async () => {
        // The label is shown as plain text in a dialog: it must not arrive
        // HTML-escaped ("A &amp; B").
        const parts = createManagementContainer();
        await renderWith(parts, [{ uid: 3, label: 'Work & "home" key', createdAt: 0, lastUsedAt: 0 }]);
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        parts.tbody.querySelector('[data-action="remove-credential"]').click();

        expect(confirmSpy.mock.calls[0][0]).toBe('Remove passkey "Work & "home" key"? This cannot be undone.');
    });

    it('removes the row after the server confirms and reloads the list', async () => {
        const parts = createManagementContainer();
        await renderWith(parts, CREDENTIALS);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        fetch
            .mockResolvedValueOnce(jsonResponse(200, { status: 'ok' }))
            .mockResolvedValueOnce(jsonResponse(200, { credentials: [CREDENTIALS[1]] }));

        parts.tbody.querySelector('[data-action="remove-credential"][data-uid="1"]').click();
        await settle(60);

        const removeCall = fetch.mock.calls.find((c) => c[0] === REMOVE_URL);
        expect(JSON.parse(removeCall[1].body)).toEqual({ uid: '1' });
        expect(parts.tbody.querySelector('tr[data-uid="1"]')).toBeNull();
        expect(parts.tbody.querySelectorAll('tr')).toHaveLength(1);
        expect(parts.warning.style.display).toBe('');
    });

    it('keeps the row and shows the error when the server refuses', async () => {
        const parts = createManagementContainer();
        await renderWith(parts, CREDENTIALS);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        fetch.mockResolvedValueOnce(jsonResponse(409, { error: 'Last passkey' }));

        parts.tbody.querySelector('[data-action="remove-credential"][data-uid="1"]').click();
        await settle(60);

        expect(parts.tbody.querySelector('tr[data-uid="1"]')).not.toBeNull();
        expect(parts.error.textContent).toBe('Last passkey');
    });
});
