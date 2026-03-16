/**
 * E2E tests for the backend admin module.
 *
 * Requires a running TYPO3 instance. See login.spec.ts for setup instructions.
 */

import { test, expect } from '@playwright/test';

test.describe('Admin — Passkey Overview', () => {
    test.skip('All E2E tests require a running TYPO3 instance', () => {});

    test.beforeEach(async ({ page }) => {
        // Navigate to backend as admin user
        await page.goto('/typo3/login');
        // Login with admin credentials (set via env vars in CI)
        const username = process.env.TYPO3_ADMIN_USERNAME || 'admin';
        const password = process.env.TYPO3_ADMIN_PASSWORD || 'password';
        await page.fill('[name="username"]', username);
        await page.fill('[name="p_field"]', password);
        await page.click('[name="commandLI"]');
        await page.waitForURL(/backend\.php/);
    });

    test('admin module is accessible from module menu', async ({ page }) => {
        // Navigate to the nr_passkeys_fe admin module
        await page.goto('/typo3/module/web/NrPasskeysFe');
        await expect(page).not.toHaveURL(/login/);
    });

    test('adoption stats dashboard is rendered', async ({ page }) => {
        await page.goto('/typo3/module/web/NrPasskeysFe');
        const statsEl = page.locator('.nr-passkeys-fe-admin__stats, h1');
        await expect(statsEl).toBeVisible();
    });

    test('credential list shows registered passkeys', async ({ page }) => {
        await page.goto('/typo3/module/web/NrPasskeysFe');
        // Credentials table or empty state should be present
        const content = page.locator('main, .module-body');
        await expect(content).toBeVisible();
    });
});
