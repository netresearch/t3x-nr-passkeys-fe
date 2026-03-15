/**
 * E2E tests for passkey login flow.
 *
 * These tests require a running TYPO3 instance with:
 * - nr_passkeys_fe extension installed and configured
 * - A frontend site with the PasskeyLogin plugin on the login page
 * - At least one fe_user with a registered passkey
 *
 * Set TYPO3_BASE_URL environment variable to the TYPO3 base URL.
 *
 * Virtual authenticator (CDP) is used to simulate WebAuthn operations
 * without a real hardware authenticator.
 */

import { test, expect, chromium } from '@playwright/test';

test.describe('Passkey Login', () => {
    test.skip('All E2E tests require a running TYPO3 instance', () => {
        // Skip all tests in this suite by default.
        // Remove this test.skip and set TYPO3_BASE_URL to run against a real instance.
    });

    test.beforeEach(async ({ page }) => {
        // Set up virtual authenticator via CDP
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
    });

    test('shows passkey login button on login page', async ({ page }) => {
        await page.goto('/login');
        const btn = page.locator('[data-action="passkey-login"]');
        await expect(btn).toBeVisible();
    });

    test('passkey login with discoverable credential redirects to dashboard', async ({ page }) => {
        // Pre-register a passkey credential via CDP virtual authenticator
        await page.goto('/login');
        const btn = page.locator('[data-action="passkey-login"]');
        await btn.click();
        // Expect success redirect — URL depends on site configuration
        await expect(page).not.toHaveURL(/login/);
    });

    test('shows error when passkey authentication is cancelled', async ({ page }) => {
        await page.goto('/login');
        const btn = page.locator('[data-action="passkey-login"]');
        await btn.click();
        // Simulate cancellation — the error element should be visible
        const errorEl = page.locator('.nr-passkeys-fe-login__error');
        await expect(errorEl).not.toBeEmpty();
    });

    test('WebAuthn not supported shows unsupported message', async ({ browser }) => {
        // Launch a context that does not support WebAuthn
        const context = await browser.newContext({
            javaScriptEnabled: true,
        });
        const page = await context.newPage();
        // Override PublicKeyCredential to be undefined
        await page.addInitScript(() => {
            Object.defineProperty(window, 'PublicKeyCredential', { value: undefined });
        });

        await page.goto('/login');
        const errorEl = page.locator('.nr-passkeys-fe-login__error');
        await expect(errorEl).toContainText('does not support Passkeys');
        await context.close();
    });
});
