import { test, expect } from '@playwright/test';
import { eidUrl, FE_USER } from './fixtures';

/**
 * Recovery: the path a user takes when the passkey is gone.
 *
 * Generating a code needs a session and delivers out of band, so what is
 * asserted here is the half a browser can reach — the form the login page
 * offers, and that a wrong code is refused without saying whether the user
 * exists.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

test.describe('Passkey recovery', () => {
    test('the login page offers the recovery form', async ({ page }) => {
        await page.goto('/login-plugin');
        await page.waitForLoadState('networkidle');

        await page.locator('[data-action="show-recovery"]').first().click();

        await expect(page.locator('#nr-passkeys-fe-recovery-username')).toBeVisible();
        await expect(page.locator('#nr-passkeys-fe-recovery-code')).toBeVisible();
    });

    test('a wrong recovery code is refused', async ({ page }) => {
        await page.goto('/login-plugin');

        const response = await page.request.post(eidUrl('recoveryVerify'), {
            headers: { 'Content-Type': 'application/json' },
            data: { username: FE_USER, code: 'AAAA-BBBB-CCCC' },
        });

        expect(response.status()).toBeGreaterThanOrEqual(400);
        const body = await response.text();
        // The refusal must not disclose whether the account exists.
        expect(body.toLowerCase()).not.toContain('unknown user');
        expect(body.toLowerCase()).not.toContain('no such user');
    });

    test('a wrong code for an unknown user is refused the same way', async ({ page }) => {
        await page.goto('/login-plugin');

        const known = await page.request.post(eidUrl('recoveryVerify'), {
            headers: { 'Content-Type': 'application/json' },
            data: { username: FE_USER, code: 'AAAA-BBBB-CCCC' },
        });
        const unknown = await page.request.post(eidUrl('recoveryVerify'), {
            headers: { 'Content-Type': 'application/json' },
            data: { username: 'nobody_e2e_xyz', code: 'AAAA-BBBB-CCCC' },
        });

        expect(unknown.status()).toBe(known.status());
    });
});
