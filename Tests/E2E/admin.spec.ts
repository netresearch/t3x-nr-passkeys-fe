import { test, expect, Page } from '@playwright/test';

/**
 * The backend module the extension adds for administrators.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

const BE_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const BE_PASSWORD = process.env.TYPO3_BACKEND_PASSWORD || process.env.TYPO3_ADMIN_PASS || 'Joh316!!';

// TYPO3 derives a module's route from its identifier, not from its parent:
// ModuleFactory turns `nr_passkeys_fe` into /module/nr/passkeys/fe unless the
// registration sets an explicit `path`, and Configuration/Backend/Modules.php
// sets none. A wrong URL does not fail loudly — TYPO3 redirects to the user's
// start module, and every assertion then runs against the Dashboard.
const MODULE_URL = '/typo3/module/nr/passkeys/fe';

async function loginToBackend(page: Page): Promise<boolean> {
    await page.goto('/typo3/login');
    await page.waitForLoadState('networkidle');

    const username = page.locator('input[name="username"]');
    if (!await username.isVisible({ timeout: 5000 }).catch(() => false)) {
        return false;
    }

    await username.fill(BE_USER);
    await page.locator('input[name="p_field"]').fill(BE_PASSWORD);
    await page.locator('#t3-login-submit').click();
    await page.waitForLoadState('networkidle');

    return !page.url().includes('/login');
}

test.describe('Backend module', () => {
    test('an administrator reaches the module and it renders its own content', async ({ page }) => {
        expect(await loginToBackend(page), 'the backend login has to succeed').toBe(true);

        await page.goto(MODULE_URL);
        await page.waitForLoadState('networkidle');

        // The module URL must resolve rather than redirect to the start module.
        expect(page.url()).toContain('/module/nr/passkeys/fe');

        const frame = page.frame('list_frame') ?? page;
        const body = await frame.locator('body').textContent();
        expect((body || '').toLowerCase()).toContain('passkey');
    });

    test('the module is not reachable without a backend session', async ({ page }) => {
        await page.context().clearCookies();

        await page.goto(MODULE_URL);
        await page.waitForLoadState('networkidle');

        // TYPO3 sends an unauthenticated request to the login screen.
        await expect(page.locator('input[name="username"]')).toBeVisible({ timeout: 10_000 });
    });
});
