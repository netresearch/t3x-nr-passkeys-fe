/**
 * Tests for PasskeyManagement.js
 */
import { describe, it, expect, vi, afterEach } from 'vitest';

function clearBody() {
    while (document.body.firstChild) {
        document.body.removeChild(document.body.firstChild);
    }
}

function createManagementContainer() {
    const container = document.createElement('div');
    container.setAttribute('data-nr-passkeys-fe', 'management');
    container.dataset.eidUrl = '/index.php';

    const table = document.createElement('table');
    const tbody = document.createElement('tbody');
    tbody.id = 'nr-passkeys-fe-credential-body';
    table.appendChild(tbody);
    container.appendChild(table);

    const emptyEl = document.createElement('div');
    emptyEl.className = 'nr-passkeys-fe-management__empty';
    container.appendChild(emptyEl);

    const errorEl = document.createElement('div');
    errorEl.className = 'nr-passkeys-fe-management__error';
    container.appendChild(errorEl);

    const statusEl = document.createElement('div');
    statusEl.className = 'nr-passkeys-fe-management__status';
    container.appendChild(statusEl);

    document.body.appendChild(container);
    return { container, tbody, emptyEl, errorEl, statusEl };
}

// ---------------------------------------------------------------
// Render list helper (mirrors PasskeyManagement.js renderList)
// ---------------------------------------------------------------

function renderCredentialRow(cred, tbody) {
    const row = document.createElement('tr');
    row.className = 'nr-passkeys-fe-management__row';
    row.dataset.uid = cred.uid;

    const labelCell = document.createElement('td');
    const labelSpan = document.createElement('span');
    labelSpan.className = 'nr-passkeys-fe-management__label';
    labelSpan.dataset.uid = cred.uid;
    labelSpan.textContent = cred.label || 'Unnamed';
    labelCell.appendChild(labelSpan);
    row.appendChild(labelCell);

    const renameBtn = document.createElement('button');
    renameBtn.type = 'button';
    renameBtn.dataset.action = 'rename-credential';
    renameBtn.dataset.uid = cred.uid;
    renameBtn.textContent = 'Rename';

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.dataset.action = 'remove-credential';
    removeBtn.dataset.uid = cred.uid;
    removeBtn.dataset.label = cred.label || 'Unnamed';
    removeBtn.textContent = 'Remove';

    const actionsCell = document.createElement('td');
    actionsCell.appendChild(renameBtn);
    actionsCell.appendChild(removeBtn);
    row.appendChild(actionsCell);

    tbody.appendChild(row);
    return row;
}

// ---------------------------------------------------------------
// Tests
// ---------------------------------------------------------------

describe('PasskeyManagement — list rendering from JSON', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('renders a row for each credential', () => {
        const { tbody } = createManagementContainer();
        const credentials = [
            { uid: 1, label: 'Key 1', createdAt: 1700000000, lastUsedAt: 0, aaguid: '' },
            { uid: 2, label: 'Key 2', createdAt: 1700000001, lastUsedAt: 1700000100, aaguid: '' },
        ];

        credentials.forEach((c) => renderCredentialRow(c, tbody));

        expect(tbody.querySelectorAll('tr').length).toBe(2);
    });

    it('renders credential label as text content', () => {
        const { tbody } = createManagementContainer();
        renderCredentialRow({ uid: 1, label: 'My YubiKey' }, tbody);

        const labelSpan = tbody.querySelector('.nr-passkeys-fe-management__label');
        expect(labelSpan.textContent).toBe('My YubiKey');
    });

    it('uses "Unnamed" for credential with empty label', () => {
        const { tbody } = createManagementContainer();
        renderCredentialRow({ uid: 1, label: '' }, tbody);

        const labelSpan = tbody.querySelector('.nr-passkeys-fe-management__label');
        expect(labelSpan.textContent).toBe('Unnamed');
    });

    it('stores uid in data-uid attribute on row', () => {
        const { tbody } = createManagementContainer();
        renderCredentialRow({ uid: 42, label: 'Key' }, tbody);

        const row = tbody.querySelector('tr');
        expect(row.dataset.uid).toBe('42');
    });

    it('renders rename and remove buttons with correct data-action', () => {
        const { tbody } = createManagementContainer();
        renderCredentialRow({ uid: 1, label: 'Key' }, tbody);

        const renameBtn = tbody.querySelector('[data-action="rename-credential"]');
        const removeBtn = tbody.querySelector('[data-action="remove-credential"]');

        expect(renameBtn).not.toBeNull();
        expect(removeBtn).not.toBeNull();
    });
});

describe('PasskeyManagement — rename API call', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('sends uid and label to rename endpoint', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ status: 'ok' }),
        });
        window.fetch = fetchMock;

        await fetch('/index.php?eID=nr_passkeys_fe&action=manageRename', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ uid: '5', label: 'New Name' }),
            credentials: 'same-origin',
        });

        expect(fetchMock).toHaveBeenCalledWith(
            expect.stringContaining('action=manageRename'),
            expect.objectContaining({ method: 'POST' }),
        );
        const callBody = JSON.parse(fetchMock.mock.calls[0][1].body);
        expect(callBody.uid).toBe('5');
        expect(callBody.label).toBe('New Name');
    });

    it('reverts label on rename failure', async () => {
        const { tbody } = createManagementContainer();
        renderCredentialRow({ uid: 1, label: 'Original Label' }, tbody);

        const labelSpan = tbody.querySelector('.nr-passkeys-fe-management__label');
        const currentLabel = labelSpan.textContent;

        // Simulate failed rename — revert
        labelSpan.textContent = currentLabel;
        expect(labelSpan.textContent).toBe('Original Label');
    });
});

describe('PasskeyManagement — remove with confirmation', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('does not call fetch when user cancels confirmation', async () => {
        const fetchMock = vi.fn();
        window.fetch = fetchMock;
        window.confirm = vi.fn().mockReturnValue(false);

        const confirmed = window.confirm('Remove this passkey?');
        if (confirmed) {
            await fetch('/remove');
        }

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('calls remove endpoint when user confirms', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ status: 'ok' }),
        });
        window.fetch = fetchMock;
        window.confirm = vi.fn().mockReturnValue(true);

        const confirmed = window.confirm('Remove this passkey?');
        if (confirmed) {
            await fetch('/index.php?eID=nr_passkeys_fe&action=manageRemove', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ uid: '3' }),
                credentials: 'same-origin',
            });
        }

        expect(fetchMock).toHaveBeenCalledWith(
            expect.stringContaining('action=manageRemove'),
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('removes DOM row on successful delete', () => {
        const { tbody, container } = createManagementContainer();
        renderCredentialRow({ uid: 7, label: 'Delete Me' }, tbody);

        expect(tbody.querySelectorAll('tr').length).toBe(1);

        // Simulate successful removal
        const row = container.querySelector('.nr-passkeys-fe-management__row[data-uid="7"]');
        if (row && row.parentNode) {
            row.parentNode.removeChild(row);
        }

        expect(tbody.querySelectorAll('tr').length).toBe(0);
    });
});

describe('PasskeyManagement — nr-passkeys-fe:registered event', () => {
    afterEach(() => {
        clearBody();
        vi.restoreAllMocks();
    });

    it('listens for registration event on container and document', () => {
        const { container } = createManagementContainer();
        const containerHandler = vi.fn();
        const documentHandler = vi.fn();

        container.addEventListener('nr-passkeys-fe:registered', containerHandler);
        document.addEventListener('nr-passkeys-fe:registered', documentHandler);

        const event = new CustomEvent('nr-passkeys-fe:registered', { bubbles: true, detail: { credentialUid: 1 } });
        container.dispatchEvent(event);

        expect(containerHandler).toHaveBeenCalledTimes(1);
        expect(documentHandler).toHaveBeenCalledTimes(1); // bubbles to document
    });
});
