/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the credential actions of the backend module in the SHIPPED
 * PasskeyFeAdmin.js: revoking one passkey, revoking all of a user's
 * passkeys, resetting a user's login lock, the confirmation dialog they
 * share, and how a failed request is reported. The TYPO3 AJAX, modal and
 * notification modules are replaced by recording doubles.
 */
import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest';

const requests = [];
let answer = null;

vi.mock('@typo3/core/ajax/ajax-request.js', () => ({
    default: class AjaxRequest {
        constructor(url) {
            this.url = url;
            this.query = null;
        }

        withQueryArguments(query) {
            this.query = query;
            return this;
        }

        async get() {
            requests.push({ method: 'GET', url: this.url, query: this.query });
            return { resolve: async () => ({ credentials: [] }) };
        }

        async post(body) {
            requests.push({ method: 'POST', url: this.url, body });
            return answer();
        }
    },
}));

const notifications = [];
vi.mock('@typo3/backend/notification.js', () => ({
    default: {
        success: (title, message) => notifications.push({ type: 'success', title, message }),
        error: (title, message) => notifications.push({ type: 'error', title, message }),
        warning: (title, message) => notifications.push({ type: 'warning', title, message }),
    },
}));

const dialog = { handlers: {}, dismissed: 0 };
vi.mock('@typo3/backend/modal.js', () => {
    const chain = {
        on(name, handler) {
            dialog.handlers[name] = handler;
            return chain;
        },
    };
    return {
        default: {
            confirm: (title, message) => {
                dialog.title = title;
                dialog.message = message;
                return chain;
            },
            currentModal: {
                trigger: (name) => {
                    if (name === 'modal-dismiss') {
                        dialog.dismissed++;
                    }
                },
            },
        },
    };
});

const ROUTES = {
    nr_passkeys_fe_admin_list: '/typo3/ajax/nr-passkeys-fe/admin/list?token=l',
    nr_passkeys_fe_admin_remove: '/typo3/ajax/nr-passkeys-fe/admin/remove?token=r',
    nr_passkeys_fe_admin_revoke_all: '/typo3/ajax/nr-passkeys-fe/admin/revoke-all?token=a',
    nr_passkeys_fe_admin_unlock: '/typo3/ajax/nr-passkeys-fe/admin/unlock?token=u',
};
let admin;

beforeAll(async () => {
    globalThis.TYPO3 = { lang: {}, settings: { ajaxUrls: ROUTES } };
    admin = (await import('../../Resources/Public/JavaScript/PasskeyFeAdmin.js')).default;
});

function mountButton(id) {
    const button = document.createElement('button');
    button.id = id;
    button.textContent = 'Unlock';
    document.body.appendChild(button);
    return button;
}

/**
 * The user lookup the revoke actions reload after a success.
 */
function mountLookup(uid) {
    const input = document.createElement('input');
    input.id = 'passkey-fe-user-uid-input';
    input.value = String(uid);
    document.body.appendChild(input);
}

function revokeEvent(credentialUid) {
    const button = document.createElement('button');
    button.dataset.credentialUid = String(credentialUid);
    document.body.appendChild(button);
    return { currentTarget: button };
}

function ok() {
    return async () => ({ resolve: async () => ({ status: 'ok' }) });
}

function refused(message) {
    return async () => ({ resolve: async () => ({ status: 'error', error: message }) });
}

beforeEach(() => {
    requests.length = 0;
    notifications.length = 0;
    dialog.handlers = {};
    dialog.dismissed = 0;
    admin.currentFeUserUid = 42;
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.replaceChildren();
});

describe('confirm', () => {
    it.each([
        ['confirm.button.ok', true],
        ['confirm.button.cancel', false],
    ])('resolves the choice of %s and closes the dialog', async (button, expected) => {
        const choice = admin.confirm('Revoke passkey', 'Sure?');
        dialog.handlers[button]();

        await expect(choice).resolves.toBe(expected);
        expect(dialog.title).toBe('Revoke passkey');
        expect(dialog.message).toBe('Sure?');
        expect(dialog.dismissed).toBe(1);
    });
});

describe('handleRevokeSingle', () => {
    it('revokes the credential of the button, reports it and reloads the list', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        answer = ok();
        mountLookup(42);

        await admin.handleRevokeSingle(revokeEvent(7));

        expect(requests[0]).toEqual({
            method: 'POST', url: ROUTES.nr_passkeys_fe_admin_remove, body: { feUserUid: 42, credentialUid: 7 },
        });
        expect(requests[1]).toMatchObject({ method: 'GET', url: ROUTES.nr_passkeys_fe_admin_list, query: { feUserUid: 42 } });
        expect(notifications).toEqual([expect.objectContaining({ type: 'success', title: 'Passkey revoked' })]);
    });

    it('does nothing when the confirmation is cancelled', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(false);
        const event = revokeEvent(7);

        await admin.handleRevokeSingle(event);

        expect(requests).toEqual([]);
        expect(event.currentTarget.disabled).toBe(false);
    });

    it('re-enables the button and shows the refusal', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        answer = refused('Credential not found');
        const event = revokeEvent(7);

        await admin.handleRevokeSingle(event);

        expect(notifications).toEqual([expect.objectContaining({ type: 'error', message: 'Credential not found' })]);
        expect(event.currentTarget.disabled).toBe(false);
    });

    it('names the HTTP status when the error body cannot be read', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        answer = async () => {
            const error = new Error('HTTP 500');
            error.resolve = async () => {
                throw new SyntaxError('not JSON');
            };
            error.response = { status: 500 };
            throw error;
        };
        const event = revokeEvent(7);

        await admin.handleRevokeSingle(event);

        expect(notifications).toEqual([expect.objectContaining({
            type: 'error',
            message: 'Server returned an error (status 500). Please try again.',
        })]);
        expect(event.currentTarget.disabled).toBe(false);
    });
});

describe('handleRevokeAll', () => {
    it('revokes all passkeys of the looked-up user and reloads the list', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        answer = ok();
        mountLookup(42);
        const button = mountButton('passkey-fe-revoke-all');

        await admin.handleRevokeAll();

        expect(requests[0]).toEqual({ method: 'POST', url: ROUTES.nr_passkeys_fe_admin_revoke_all, body: { feUserUid: 42 } });
        expect(requests[1]).toMatchObject({ method: 'GET', query: { feUserUid: 42 } });
        expect(notifications).toEqual([expect.objectContaining({ type: 'success', title: 'All passkeys revoked' })]);
        expect(button.disabled).toBe(false);
    });

    it('asks nothing while no user is looked up', async () => {
        const confirm = vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        admin.currentFeUserUid = null;

        await admin.handleRevokeAll();

        expect(confirm).not.toHaveBeenCalled();
        expect(requests).toEqual([]);
    });

    it('posts nothing when the confirmation is cancelled', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(false);

        await admin.handleRevokeAll();

        expect(requests).toEqual([]);
    });

    it('shows the refusal and re-enables the button', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        answer = refused('User not found');
        const button = mountButton('passkey-fe-revoke-all');

        await admin.handleRevokeAll();

        expect(notifications).toEqual([expect.objectContaining({ type: 'error', message: 'User not found' })]);
        expect(button.disabled).toBe(false);
    });

    it('reports a request that never got an answer as a network error', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        answer = async () => {
            throw new TypeError('Failed to fetch');
        };

        await admin.handleRevokeAll();

        expect(notifications).toEqual([expect.objectContaining({
            type: 'error',
            message: 'Network error. Please check your connection.',
        })]);
    });
});

describe('handleUnlockUser', () => {
    it('posts the looked-up user and the entered username', async () => {
        vi.spyOn(window, 'prompt').mockReturnValue('  jdoe ');
        answer = ok();
        const button = mountButton('passkey-fe-unlock-user');

        await admin.handleUnlockUser();

        expect(requests).toEqual([{
            method: 'POST', url: ROUTES.nr_passkeys_fe_admin_unlock, body: { feUserUid: 42, username: 'jdoe' },
        }]);
        expect(notifications).toEqual([expect.objectContaining({
            type: 'success',
            message: 'Failed login attempt counter reset for "jdoe".',
        })]);
        expect(button.textContent).toBe('Reset');
        expect(button.disabled).toBe(true);
    });

    it.each([[null], ['   ']])('posts nothing when the prompt answers %j', async (entered) => {
        vi.spyOn(window, 'prompt').mockReturnValue(entered);

        await admin.handleUnlockUser();

        expect(requests).toEqual([]);
    });

    it('asks nothing while no user is looked up', async () => {
        const prompt = vi.spyOn(window, 'prompt').mockReturnValue('jdoe');
        admin.currentFeUserUid = null;

        await admin.handleUnlockUser();

        expect(prompt).not.toHaveBeenCalled();
    });

    it('restores the button and shows the refusal', async () => {
        vi.spyOn(window, 'prompt').mockReturnValue('jdoe');
        answer = refused('Unknown user');
        const button = mountButton('passkey-fe-unlock-user');

        await admin.handleUnlockUser();

        expect(notifications).toEqual([expect.objectContaining({ type: 'error', message: 'Unknown user' })]);
        expect(button.textContent).toBe('Unlock');
        expect(button.disabled).toBe(false);
    });

    it('restores the button and shows the server message of a failed request', async () => {
        vi.spyOn(window, 'prompt').mockReturnValue('jdoe');
        answer = async () => {
            const error = new Error('HTTP 403');
            error.resolve = async () => ({ error: 'Admin access required' });
            throw error;
        };
        const button = mountButton('passkey-fe-unlock-user');

        await admin.handleUnlockUser();

        expect(notifications).toEqual([expect.objectContaining({ type: 'error', message: 'Admin access required' })]);
        expect(button.textContent).toBe('Unlock');
        expect(button.disabled).toBe(false);
    });
});

describe('button wiring', () => {
    it.each([
        ['passkey-fe-revoke-all', 'bindRevokeAll', 'handleRevokeAll'],
        ['passkey-fe-unlock-user', 'bindUnlockUser', 'handleUnlockUser'],
    ])('wires #%s through %s', (id, bind, handler) => {
        const spy = vi.spyOn(admin, handler).mockResolvedValue(undefined);
        const button = mountButton(id);

        admin[bind]();
        button.click();

        expect(spy).toHaveBeenCalledTimes(1);
    });
});
