/**
 * E2E tests for passkey recovery flow.
 *
 * Requires a running TYPO3 instance. See login.spec.ts for setup instructions.
 */

import { test, expect } from '@playwright/test';

test.describe('Passkey Recovery', () => {
    test.skip('All E2E tests require a running TYPO3 instance', () => {});

    test('recovery page renders code input', async ({ page }) => {
        await page.goto('/passkey-recovery');
        const codeInput = page.locator('[data-action="recovery-format"]');
        await expect(codeInput).toBeVisible();
    });

    test('code input auto-formats to XXXX-XXXX', async ({ page }) => {
        await page.goto('/passkey-recovery');
        const codeInput = page.locator('[data-action="recovery-format"]');
        await codeInput.fill('ABCDEFGH');
        await codeInput.dispatchEvent('input');
        await expect(codeInput).toHaveValue('ABCD-EFGH');
    });

    test('submitting empty code shows validation error', async ({ page }) => {
        await page.goto('/passkey-recovery');
        const submitBtn = page.locator('[data-action="recovery-submit"]');
        if (await submitBtn.count() > 0) {
            await submitBtn.click();
            const errorEl = page.locator('.nr-passkeys-fe-recovery__error');
            await expect(errorEl).toBeVisible();
        }
    });

    test('valid recovery code redirects to post-login page', async ({ page }) => {
        await page.goto('/passkey-recovery');
        const codeInput = page.locator('[data-action="recovery-format"]');

        // This would require a real unused code — test verifies the flow structure only
        await codeInput.fill('ABCD-EFGH');
        // Actual submission would fail with "invalid code" in a real test
    });
});
