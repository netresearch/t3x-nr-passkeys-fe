import { test, expect } from '@playwright/test';
import { eidUrl, FE_USER, loginWithPassword, logOut } from './fixtures';

/**
 * Recovery: the path a user takes when the passkey is gone.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

test.describe('Passkey recovery', () => {
    for (const [layout, route] of [['standalone', '/login-plugin'], ['felogin', '/login']] as const) {
        test(`recovery completes a frontend login without WebAuthn in the ${layout} form`, async ({ page }, testInfo) => {
            const username = `e2e_recovery_${layout}_${testInfo.retry}`;
            await loginWithPassword(page, username);
            const generated = await page.request.post(eidUrl('recoveryGenerate'), {
                headers: { Origin: new URL(page.url()).origin },
            });
            expect(generated.status()).toBe(200);
            const { codes } = await generated.json();
            expect(codes.length).toBeGreaterThan(0);
            await logOut(page);

            await page.addInitScript(() => { Object.defineProperty(window, 'PublicKeyCredential', { value: undefined }); });
            await page.goto(route);
            await page.locator('[data-action="show-recovery"]').click();
            const recovery = page.locator('[data-nr-passkeys-fe="recovery"]');
            await recovery.locator('[name="recovery_username"]').fill(username);
            await recovery.locator('[data-action="recovery-format"]').fill(codes[0]);
            await recovery.locator('[data-action="recovery-submit"]').click();
            await expect.poll(async () => (await page.request.get(eidUrl('manageList'))).status()).toBe(200);
        });
    }

    test('a wrong recovery code is refused', async ({ page }) => {
        await page.goto('/login-plugin');

        const response = await page.request.post(eidUrl('recoveryVerify'), {
            headers: { 'Origin': new URL(page.url()).origin, 'Content-Type': 'application/json' },
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
            headers: { 'Origin': new URL(page.url()).origin, 'Content-Type': 'application/json' },
            data: { username: FE_USER, code: 'AAAA-BBBB-CCCC' },
        });
        const unknown = await page.request.post(eidUrl('recoveryVerify'), {
            headers: { 'Origin': new URL(page.url()).origin, 'Content-Type': 'application/json' },
            data: { username: 'nobody_e2e_xyz', code: 'AAAA-BBBB-CCCC' },
        });

        expect(unknown.status()).toBe(known.status());
    });
});
