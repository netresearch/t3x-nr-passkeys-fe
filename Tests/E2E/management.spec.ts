import { test, expect } from '@playwright/test';
import {
    addVirtualAuthenticator,
    eidUrl,
    loginWithPassword,
    registerPasskey,
    removeAllCredentials,
    removeVirtualAuthenticator,
} from './fixtures';

/**
 * The management plugin: what a logged-in frontend user can do with their own
 * credentials.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

test.describe('Passkey management plugin', () => {
    test('the management page renders the plugin for a logged-in user', async ({ page }) => {
        await loginWithPassword(page);

        await page.goto('/member');
        await page.waitForLoadState('networkidle');

        const plugin = page.locator('[data-nr-passkeys-fe="management"]');
        await expect(plugin).toBeVisible();
        await expect(plugin).toHaveAttribute('data-list-url', /eID=nr_passkeys_fe/);
    });

    test('a registered credential appears in the list, can be renamed and removed', async ({ page }) => {
        const { cdp, authenticatorId } = await addVirtualAuthenticator(page);
        await loginWithPassword(page);
        await removeAllCredentials(page);

        const registered = await registerPasskey(page, 'E2E management key');
        expect(registered.success, `Registration failed: ${registered.error}`).toBe(true);

        const listed = await page.request.get(eidUrl('manageList'));
        expect(listed.status()).toBe(200);
        const credentials = (await listed.json()).credentials;
        expect(Array.isArray(credentials)).toBe(true);
        expect(credentials).toHaveLength(1);
        expect(credentials[0].label).toBe('E2E management key');

        const renamed = await page.request.post(eidUrl('manageRename'), {
            headers: { 'Content-Type': 'application/json' },
            data: { uid: credentials[0].uid, label: 'Renamed by e2e' },
        });
        expect(renamed.status(), await renamed.text()).toBe(200);
        const afterRename = (await (await page.request.get(eidUrl('manageList'))).json()).credentials;
        expect(afterRename[0].label).toBe('Renamed by e2e');

        const removed = await page.request.post(eidUrl('manageRemove'), {
            headers: { 'Content-Type': 'application/json' },
            data: { uid: credentials[0].uid },
        });
        expect(removed.status(), await removed.text()).toBe(200);
        const afterRemove = (await (await page.request.get(eidUrl('manageList'))).json()).credentials;
        expect(afterRemove).toHaveLength(0);

        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    test('the management endpoints refuse an anonymous caller', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto('/member');

        const listed = await page.request.get(eidUrl('manageList'));
        expect(listed.status()).toBeGreaterThanOrEqual(400);

        const renamed = await page.request.post(eidUrl('manageRename'), {
            headers: { 'Content-Type': 'application/json' },
            data: { uid: 1, label: 'anonymous rename' },
        });
        expect(renamed.status()).toBeGreaterThanOrEqual(400);
    });
});
