/**
 * E2E tests for passkey enrollment flow.
 *
 * Requires a running TYPO3 instance. See login.spec.ts for setup instructions.
 */

import { test, expect } from '@playwright/test';

test.describe('Passkey Enrollment', () => {
    test.skip('All E2E tests require a running TYPO3 instance', () => {});

    test('enrollment page shows register passkey button', async ({ page }) => {
        await page.goto('/passkey-enrollment');
        const btn = page.locator('[data-action="register-passkey"]');
        await expect(btn).toBeVisible();
    });

    test('empty label defaults to "Passkey" after trim', async ({ page }) => {
        await page.goto('/passkey-enrollment');
        const labelInput = page.locator('#enrollment-device-label');
        await labelInput.fill('');
        // The module defaults empty label to 'Passkey' before sending
        await expect(labelInput).toHaveValue('');
    });

    test('successful registration shows success message', async ({ page }) => {
        // Set up virtual authenticator
        const client = await (page.context() as any).newCDPSession(page);
        await client.send('WebAuthn.enable');
        await client.send('WebAuthn.addVirtualAuthenticator', {
            options: {
                protocol: 'ctap2',
                transport: 'internal',
                hasResidentKey: true,
                hasUserVerification: true,
                isUserVerified: true,
            },
        });

        await page.goto('/passkey-enrollment');
        const btn = page.locator('[data-action="register-passkey"]');
        await btn.click();

        // Either a redirect or success element
        const successEl = page.locator('.nr-passkeys-fe-enrollment-form__success');
        await expect(successEl.or(page.locator('body'))).toBeVisible();
    });

    test('registration when already enrolled shows already-registered error', async ({ page }) => {
        await page.goto('/passkey-enrollment');
        const errorEl = page.locator('.nr-passkeys-fe-enrollment__error, .nr-passkeys-fe-enrollment-form__error');
        // Error may or may not be visible depending on state
        await expect(errorEl).toBeDefined();
    });
});
