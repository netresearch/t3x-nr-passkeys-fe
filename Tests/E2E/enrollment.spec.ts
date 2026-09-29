import { test, expect } from '@playwright/test';
import {
    addVirtualAuthenticator,
    eidUrl,
    loginWithPassword,
    removeAllCredentials,
    registerPasskey,
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

    test('a user in a grace period sees how many days are left', async ({ page }) => {
        // e2e_grace is four days into a fourteen-day grace period (runTests.conf).
        await loginWithPassword(page, 'e2e_grace');
        await page.goto('/enrollment', { waitUntil: 'load' });

        const plugin = page.locator('[data-nr-passkeys-fe="enrollment"]');
        await expect(plugin.getByText('You have 10 days remaining to set up your passkey.')).toBeVisible();
        await expect(plugin.getByText('Passkey enrollment is required to continue accessing your account.')).toHaveCount(0);
    });

    test('a user whose group enforces passkeys is told enrollment is required', async ({ page }) => {
        await loginWithPassword(page, 'e2e_enforced');
        await page.goto('/enrollment', { waitUntil: 'load' });

        const plugin = page.locator('[data-nr-passkeys-fe="enrollment"]');
        await expect(plugin.getByText('Passkey enrollment is required to continue accessing your account.')).toBeVisible();
        await expect(plugin.getByText(/days remaining to set up your passkey/)).toHaveCount(0);
    });

    test('a user who has registered a passkey is not told to enroll any more', async ({ page }) => {
        const { cdp, authenticatorId } = await addVirtualAuthenticator(page);
        await loginWithPassword(page, 'e2e_enforced');
        await page.goto('/enrollment', { waitUntil: 'load' });

        const plugin = page.locator('[data-nr-passkeys-fe="enrollment"]');
        const required = plugin.getByText('Passkey enrollment is required to continue accessing your account.');
        await expect(required).toBeVisible();

        const registered = await registerPasskey(page, 'E2E enforced key');
        expect(registered.success, `Registration failed: ${registered.error}`).toBe(true);

        await page.reload({ waitUntil: 'load' });
        await expect(plugin).toBeVisible();
        await expect(required).toHaveCount(0);
        await expect(plugin.getByText(/days remaining to set up your passkey/)).toHaveCount(0);

        // An enforced user holding a passkey may no longer log in with the
        // password, so the credential goes before any later spec needs that.
        await removeAllCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    test('a user with nothing enforced sees neither notice', async ({ page }) => {
        await loginWithPassword(page);
        await page.goto('/enrollment', { waitUntil: 'load' });

        const plugin = page.locator('[data-nr-passkeys-fe="enrollment"]');
        await expect(plugin).toBeVisible();
        await expect(plugin.getByText(/required to continue|days remaining/)).toHaveCount(0);
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
