import { test, expect } from '@playwright/test';
import {
    addVirtualAuthenticator,
    eidUrl,
    loginWithPassword,
    logOut,
    registerPasskey,
    removeAllCredentials,
    removeVirtualAuthenticator,
    setAutomaticPresence,
    FE_USER,
} from './fixtures';

/**
 * The frontend passkey login, end to end.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

test.describe('Passkey login plugin', () => {
    test('the login page renders the plugin and its button', async ({ page }) => {
        await page.goto('/login-plugin');
        await page.waitForLoadState('networkidle');

        const plugin = page.locator('[data-nr-passkeys-fe="login"]');
        await expect(plugin).toBeVisible();
        await expect(plugin).toHaveAttribute('data-eid-url', /eID=nr_passkeys_fe/);

        const button = page.locator('#nr-passkeys-fe-login-btn');
        await expect(button).toBeVisible();
        await expect(button).toBeEnabled();
    });

    test('the login options endpoint answers a discoverable request', async ({ page }) => {
        await page.goto('/login-plugin');

        const response = await page.request.post(eidUrl('loginOptions'), {
            headers: { 'Content-Type': 'application/json' },
            data: {},
        });

        expect(response.status(), await response.text()).toBe(200);
        const data = await response.json();
        expect(data.options).toBeDefined();
        expect(typeof data.options.challenge).toBe('string');
        expect(data.challengeToken).toBeTruthy();
        // Discoverable login carries no credential list: the authenticator
        // picks the credential, which is the point of the usernameless flow.
        expect(data.options.allowCredentials ?? []).toEqual([]);
    });

    test('a registered passkey logs the user in', async ({ page }) => {
        // The ceremony runs against the server's timeout when the authenticator
        // has nothing to offer, which is longer than the default test budget.
        test.setTimeout(90_000);

        const { cdp, authenticatorId } = await addVirtualAuthenticator(page);

        await loginWithPassword(page);
        const registered = await registerPasskey(page, 'E2E login key');
        expect(registered.success, `Registration failed: ${registered.error}`).toBe(true);

        // A resident credential now exists, so the discoverable ceremony the
        // login page arms would authenticate the browser before the test can
        // press the button itself.
        await setAutomaticPresence(cdp, authenticatorId, false);
        await logOut(page);

        await page.goto('/login-plugin');
        await page.waitForLoadState('networkidle');
        const button = page.locator('#nr-passkeys-fe-login-btn');
        await expect(button).toBeVisible({ timeout: 5000 });

        await setAutomaticPresence(cdp, authenticatorId, true);
        await button.click();

        // The plugin page carries no felogin form, so PasskeyLogin.js reloads it
        // once the eID endpoint has established the session. What proves the
        // login is therefore not markup but access: manageList answers 200 for
        // an authenticated frontend user and refuses an anonymous one, which
        // the neighbouring management spec pins separately.
        await expect.poll(
            async () => (await page.request.get(eidUrl('manageList'))).status(),
            {
                message: 'the passkey ceremony has to leave an authenticated frontend session',
                timeout: 30_000,
            },
        ).toBe(200);

        // No password login here: the ceremony left the session the poll above
        // just proved, and felogin answers a logged-in visitor with the logout
        // view, which carries no username field.
        await removeAllCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    test('a known and an unknown username are answered the same way', async ({ page }) => {
        await page.goto('/login-plugin');

        const known = await page.request.post(eidUrl('loginOptions'), {
            headers: { 'Content-Type': 'application/json' },
            data: { username: FE_USER },
        });
        const unknown = await page.request.post(eidUrl('loginOptions'), {
            headers: { 'Content-Type': 'application/json' },
            data: { username: 'nobody_e2e_xyz' },
        });

        // What the endpoint answers is its business; that it answers the same
        // for a real username as for an invented one is the security property.
        // A difference here lets a caller enumerate frontend users.
        expect(unknown.status()).toBe(known.status());

        const knownBody = await known.text();
        const unknownBody = await unknown.text();
        expect(unknownBody.length > 0).toBe(knownBody.length > 0);
        expect(unknownBody.toLowerCase()).not.toContain('unknown');
        expect(unknownBody.toLowerCase()).not.toContain('not found');
    });
});
