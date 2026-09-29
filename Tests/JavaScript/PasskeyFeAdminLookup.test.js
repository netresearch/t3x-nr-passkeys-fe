/**
 * Tests for the credential lookup in PasskeyFeAdmin.js (backend module).
 *
 * The route URL TYPO3 hands to the script already carries its ?token=, so the
 * user uid has to go through AjaxRequest's query arguments; a second '?'
 * glued onto the URL becomes part of the token and the backend refuses it.
 */
import { describe, it, expect, vi, beforeAll, afterEach } from 'vitest';

const requests = [];

vi.mock('@typo3/core/ajax/ajax-request.js', () => ({
    default: class AjaxRequest {
        constructor(url) {
            this.url = url;
            this.query = null;
            requests.push(this);
        }

        withQueryArguments(query) {
            this.query = query;
            return this;
        }

        async get() {
            this.method = 'GET';
            return { resolve: async () => ({ feUserUid: 7, credentials: [], count: 0 }) };
        }
    },
}));

const ROUTE = '/typo3/ajax/nr-passkeys-fe/admin/list?token=abc123';
let admin;

beforeAll(async () => {
    globalThis.TYPO3 = { lang: {}, settings: { ajaxUrls: { nr_passkeys_fe_admin_list: ROUTE } } };
    admin = (await import('../../Resources/Public/JavaScript/PasskeyFeAdmin.js')).default;
});

function mountLookup(uid) {
    const input = document.createElement('input');
    input.id = 'passkey-fe-user-uid-input';
    input.value = uid;
    const button = document.createElement('button');
    button.id = 'passkey-fe-load-user';
    const container = document.createElement('div');
    container.id = 'passkey-fe-user-credentials';
    container.className = 'd-none';
    const table = document.createElement('table');
    const tbody = document.createElement('tbody');
    tbody.id = 'passkey-fe-credentials-body';
    table.appendChild(tbody);
    container.appendChild(table);
    document.body.append(input, button, container);
}

afterEach(() => {
    requests.length = 0;
    document.body.replaceChildren();
});

describe('handleLoadUser', () => {
    it('keeps the route URL as TYPO3 issued it and sends the uid as a query argument', async () => {
        mountLookup('7');
        await admin.handleLoadUser();

        expect(requests).toHaveLength(1);
        expect(requests[0].url).toBe(ROUTE);
        expect(requests[0].query).toEqual({ feUserUid: 7 });
        expect(requests[0].method).toBe('GET');
    });

    it('shows the result table once the list has loaded', async () => {
        mountLookup('7');
        await admin.handleLoadUser();

        expect(document.getElementById('passkey-fe-user-credentials').classList.contains('d-none')).toBe(false);
    });
});
