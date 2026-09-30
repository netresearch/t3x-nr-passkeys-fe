/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the enforcement select in PasskeyFeAdmin.js (backend module):
 * a change posts the group and the level to its route and reports the
 * outcome; a refused change puts the previous value back.
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

const ROUTE = '/typo3/ajax/nr-passkeys-fe/admin/update-enforcement?token=abc123';
let admin;

beforeAll(async () => {
    globalThis.TYPO3 = { lang: {}, settings: { ajaxUrls: { nr_passkeys_fe_admin_update_enforcement: ROUTE } } };
    admin = (await import('../../Resources/Public/JavaScript/PasskeyFeAdmin.js')).default;
});

function mountSelect() {
    const select = document.createElement('select');
    select.className = 'passkey-fe-enforcement-select';
    select.dataset.groupUid = '3';
    select.dataset.originalValue = 'off';
    [['off', 'Aus'], ['required', 'Erforderlich']].forEach(([value, label]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        select.appendChild(option);
    });
    document.body.appendChild(select);
    select.value = 'required';
    return select;
}

beforeEach(() => {
    posts.length = 0;
    notifications.length = 0;
});

afterEach(() => {
    document.body.replaceChildren();
});

describe('handleEnforcementChange', () => {
    it('posts the group and the level to the enforcement route and confirms with the option label', async () => {
        answer = async () => ({ resolve: async () => ({ status: 'ok', groupUid: 3, enforcement: 'required' }) });
        const select = mountSelect();

        await admin.handleEnforcementChange({ target: select });

        expect(posts).toEqual([{ url: ROUTE, body: { groupUid: 3, enforcement: 'required' } }]);
        expect(select.dataset.originalValue).toBe('required');
        expect(select.disabled).toBe(false);
        expect(notifications).toHaveLength(1);
        expect(notifications[0].type).toBe('success');
        expect(notifications[0].message).toContain('Erforderlich');
    });

    it('puts the previous value back and shows the server message when the save is refused', async () => {
        answer = async () => {
            const error = new Error('HTTP 403');
            error.resolve = async () => ({ error: 'Unauthorized' });
            error.response = { status: 403 };
            throw error;
        };
        const select = mountSelect();

        await admin.handleEnforcementChange({ target: select });

        expect(select.value).toBe('off');
        expect(select.dataset.originalValue).toBe('off');
        expect(notifications).toEqual([expect.objectContaining({ type: 'error', message: 'Unauthorized' })]);
    });
});
