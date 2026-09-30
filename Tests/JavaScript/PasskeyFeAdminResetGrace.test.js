/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the "Reset grace period" button in PasskeyFeAdmin.js (backend
 * module): after a confirmation it posts the looked-up user to its route and
 * reports the outcome; without a confirmation or a user it posts nothing.
 */
import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest';

const posts = [];
let answer = null;

vi.mock('@typo3/core/ajax/ajax-request.js', () => ({
    default: class AjaxRequest {
        constructor(url) {
            this.url = url;
        }

        async post(body) {
            posts.push({ url: this.url, body });
            return answer();
        }
    },
}));

const notifications = [];
vi.mock('@typo3/backend/notification.js', () => ({
    default: {
        success: (title, message) => notifications.push({ type: 'success', title, message }),
        error: (title, message) => notifications.push({ type: 'error', title, message }),
    },
}));

const ROUTE = '/typo3/ajax/nr-passkeys-fe/admin/reset-grace-period?token=abc123';
let admin;

beforeAll(async () => {
    globalThis.TYPO3 = { lang: {}, settings: { ajaxUrls: { nr_passkeys_fe_admin_reset_grace_period: ROUTE } } };
    admin = (await import('../../Resources/Public/JavaScript/PasskeyFeAdmin.js')).default;
});

function mountButton() {
    const button = document.createElement('button');
    button.id = 'passkey-fe-reset-grace';
    document.body.appendChild(button);
    return button;
}

beforeEach(() => {
    posts.length = 0;
    notifications.length = 0;
    admin.currentFeUserUid = 42;
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.replaceChildren();
});

describe('handleResetGrace', () => {
    it('posts the looked-up user to the reset route after a confirmation and reports success', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        answer = async () => ({ resolve: async () => ({ status: 'ok' }) });
        const button = mountButton();

        await admin.handleResetGrace();

        expect(posts).toEqual([{ url: ROUTE, body: { feUserUid: 42 } }]);
        expect(notifications).toEqual([expect.objectContaining({ type: 'success', title: 'Grace period reset' })]);
        expect(button.disabled).toBe(false);
    });

    it('posts nothing when the confirmation is cancelled', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(false);
        mountButton();

        await admin.handleResetGrace();

        expect(posts).toEqual([]);
        expect(notifications).toEqual([]);
    });

    it('posts nothing and asks nothing while no user is looked up', async () => {
        const confirm = vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        admin.currentFeUserUid = null;

        await admin.handleResetGrace();

        expect(confirm).not.toHaveBeenCalled();
        expect(posts).toEqual([]);
    });

    it('shows the server message when the reset is refused', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        answer = async () => {
            const error = new Error('HTTP 404');
            error.resolve = async () => ({ error: 'User not found' });
            throw error;
        };
        const button = mountButton();

        await admin.handleResetGrace();

        expect(notifications).toEqual([expect.objectContaining({ type: 'error', message: 'User not found' })]);
        expect(button.disabled).toBe(false);
    });

    it('reports an answer without status ok as a failure', async () => {
        vi.spyOn(admin, 'confirm').mockResolvedValue(true);
        answer = async () => ({ resolve: async () => ({ error: 'Missing required fields' }) });
        mountButton();

        await admin.handleResetGrace();

        expect(notifications).toEqual([expect.objectContaining({ type: 'error', message: 'Missing required fields' })]);
    });
});

describe('initialize', () => {
    it('wires the reset button when the module starts', () => {
        const handler = vi.spyOn(admin, 'handleResetGrace').mockResolvedValue(undefined);
        const button = mountButton();

        admin.initialize();
        button.click();

        expect(handler).toHaveBeenCalledTimes(1);
    });
});

describe('bindResetGrace', () => {
    it('wires the button to the handler', () => {
        const handler = vi.spyOn(admin, 'handleResetGrace').mockResolvedValue(undefined);
        const button = mountButton();

        admin.bindResetGrace();
        button.click();

        expect(handler).toHaveBeenCalledTimes(1);
    });
});
