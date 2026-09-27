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

    test('the help page renders its infoboxes with their severities', async ({ page }) => {
        // The help page carries three of the module's four infoboxes, and on
        // TYPO3 13 a wrongly typed `state` takes the whole page down with a
        // 503. The dashboard's own infobox only renders once every user has a
        // passkey, so this page is where the argument is actually exercised.
        // Asserting the callout classes pins the state-to-severity mapping too.
        expect(await loginToBackend(page), 'the backend login has to succeed').toBe(true);

        const response = await page.goto(`${MODULE_URL}/help`);
        await page.waitForLoadState('networkidle');
        expect(response?.status(), 'the help route has to render, not redirect or fail').toBe(200);

        const frame = page.frame('list_frame') ?? page;
        for (const severity of ['info', 'warning', 'notice']) {
            await expect(
                frame.locator(`.callout.callout-${severity}`),
                `an infobox with severity "${severity}" has to render`,
            ).toHaveCount(1);
        }
    });

    test('the passkey lookup lists the stored credentials of a frontend user', async ({ page }) => {
        // Seeded by e2e_provision_seed: fe_user 2 with one credential,
        // "E2E lookup key". The route URL TYPO3 issues already carries its
        // ?token=; the uid has to arrive as a query argument of its own, or
        // the backend refuses the request (401 on 14.3, a login redirect on
        // 13.4) and the table never fills.
        expect(await loginToBackend(page), 'the backend login has to succeed').toBe(true);

        await page.goto(MODULE_URL);
        await page.waitForLoadState('networkidle');
        const frame = page.frame('list_frame') ?? page;

        await frame.locator('#passkey-fe-user-uid-input').fill('2');
        const [response] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/nr-passkeys-fe/admin/list')),
            frame.locator('#passkey-fe-load-user').click(),
        ]);

        expect(response.status(), 'the list request has to succeed').toBe(200);
        const url = new URL(response.url());
        expect(url.searchParams.get('feUserUid')).toBe('2');
        expect(url.searchParams.get('token') ?? '', 'the token must not swallow the uid').not.toContain('?');

        const data = await response.json();
        expect(data.feUserUid).toBe(2);
        expect(data.count).toBe(1);
        expect(data.credentials[0].label).toBe('E2E lookup key');

        await expect(frame.locator('#passkey-fe-credentials-body')).toContainText('E2E lookup key');
    });

    test('the module is not reachable without a backend session', async ({ page }) => {
        await page.context().clearCookies();

        await page.goto(MODULE_URL);
        await page.waitForLoadState('networkidle');

        // TYPO3 sends an unauthenticated request to the login screen.
        await expect(page.locator('input[name="username"]')).toBeVisible({ timeout: 10_000 });
    });
});
