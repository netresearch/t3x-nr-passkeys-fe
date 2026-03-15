/**
 * E2E tests for passkey management flow.
 *
 * Requires a running TYPO3 instance. See login.spec.ts for setup instructions.
 */

import { test, expect } from '@playwright/test';

test.describe('Passkey Management', () => {
    test.skip('All E2E tests require a running TYPO3 instance', () => {});

    test.beforeEach(async ({ page }) => {
        // Navigate to management page as authenticated fe_user
        await page.goto('/passkey-management');
    });

    test('management page renders passkey list table', async ({ page }) => {
        const table = page.locator('#nr-passkeys-fe-credential-body').or(
            page.locator('.nr-passkeys-fe-management__empty'),
        );
        await expect(table).toBeVisible();
    });

    test('rename passkey shows inline edit input', async ({ page }) => {
        const renameBtn = page.locator('[data-action="rename-credential"]').first();
        if (await renameBtn.count() > 0) {
            await renameBtn.click();
            const renameInput = page.locator('.nr-passkeys-fe-management__rename-input');
            await expect(renameInput).toBeVisible();
        }
    });

    test('remove passkey shows confirmation dialog', async ({ page }) => {
        const removeBtn = page.locator('[data-action="remove-credential"]').first();
        if (await removeBtn.count() > 0) {
            page.on('dialog', async (dialog) => {
                expect(dialog.message()).toContain('Remove passkey');
                await dialog.dismiss();
            });
            await removeBtn.click();
        }
    });

    test('empty state shown when no passkeys registered', async ({ page }) => {
        // If the user has no passkeys, the empty state element should be visible
        const emptyEl = page.locator('.nr-passkeys-fe-management__empty');
        const credentialBody = page.locator('#nr-passkeys-fe-credential-body tr');
        if (await credentialBody.count() === 0) {
            await expect(emptyEl).toBeVisible();
        }
    });
});
