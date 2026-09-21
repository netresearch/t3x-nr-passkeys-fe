import { test, expect } from '@playwright/test';
import { eidUrl, loginWithPassword } from './fixtures';

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
