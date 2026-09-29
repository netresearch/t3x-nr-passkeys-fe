import { test, expect } from '@playwright/test';
import {
    addVirtualAuthenticator,
    eidUrl,
    loginWithPassword,
    removeAllCredentials,
    removeVirtualAuthenticator,
} from './fixtures';

/**
 * The enrollment plugin, which asks a logged-in user without a passkey to
 * create one.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

test.describe('Passkey enrollment plugin', () => {
    test('the enrollment page renders the plugin for a logged-in user', async ({ page }) => {
        await loginWithPassword(page);

        await page.goto('/enrollment');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('[data-nr-passkeys-fe="enrollment"]')).toBeVisible();
        // css.includeDefault = 0 for this page (runTests.conf, ts-constants).
        await expect(page.locator('link[rel="stylesheet"][href*="passkey-fe.css"]')).toHaveCount(0);
        // No link without a target: the page used to render one with an empty href.
        await expect(page.locator('[data-nr-passkeys-fe="enrollment"] a[href=""]')).toHaveCount(0);
    });

    test('a registration on the enrollment page shows its success message', async ({ page }) => {
        const { cdp, authenticatorId } = await addVirtualAuthenticator(page);
        await loginWithPassword(page);

        await page.goto('/enrollment', { waitUntil: 'load' });
        const plugin = page.locator('[data-nr-passkeys-fe="enrollment"]');
        const success = plugin.getByRole('status').filter({ hasText: 'You can now sign in with your passkey.' });
        await expect(success).toBeHidden();

        await page.locator('#nr-passkeys-fe-enroll-btn').click();

        // The page stays where it is and says the passkey exists; before, it
        // hid the status line and showed nothing at all.
        await expect(success).toBeVisible({ timeout: 15_000 });
        await expect(plugin.getByRole('heading', { name: 'Passkey set up successfully!' })).toBeVisible();
        await expect(page).toHaveURL(/\/enrollment$/);

        await removeAllCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    test('the status endpoint answers for a logged-in user', async ({ page }) => {
        await loginWithPassword(page);

        const response = await page.request.get(eidUrl('enrollmentStatus'));
        expect(response.status(), await response.text()).toBe(200);

        const data = await response.json();
        // The shape is what the plugin's JavaScript reads to decide whether to
        // prompt: how many passkeys the user holds, which enforcement level
        // applies, and whether a grace period is running.
        expect(data).toHaveProperty('passkeyCount');
        expect(typeof data.passkeyCount).toBe('number');
        expect(data).toHaveProperty('effectiveLevel');
        expect(['off', 'encourage', 'required', 'enforced']).toContain(data.effectiveLevel);
        expect(data).toHaveProperty('inGracePeriod');
        expect(typeof data.inGracePeriod).toBe('boolean');
    });

    test('the status endpoint refuses an anonymous caller', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto('/enrollment');

        const response = await page.request.get(eidUrl('enrollmentStatus'));
        expect(response.status()).toBeGreaterThanOrEqual(400);
    });
});
