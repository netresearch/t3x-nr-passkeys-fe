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

        // No FlexForm settings: discoverable login, no username field, and no
        // password link because no page to link to is configured.
        await expect(plugin).toHaveAttribute('data-discoverable', '1');
        await expect(plugin.locator('[name="nr_passkeys_username"]')).toHaveCount(0);
        await expect(plugin.getByRole('link', { name: 'Use password instead' })).toHaveCount(0);
    });

    test('with discoverable login off the plugin asks for the username first', async ({ page }) => {
        // 'load' runs after DOMContentLoaded, where PasskeyLogin.js binds the button.
        await page.goto('/login-plugin-username', { waitUntil: 'load' });

        const plugin = page.locator('[data-nr-passkeys-fe="login"]');
        await expect(plugin).toHaveAttribute('data-discoverable', '0');

        // A role query skips the hidden recovery form's own username field.
        const username = plugin.getByRole('textbox', { name: 'Username' });
        await expect(username).toBeVisible();
        await expect(username).toHaveAttribute('name', 'nr_passkeys_username');

        // PasskeyLogin.js refuses the username-first ceremony without a name.
        await page.locator('#nr-passkeys-fe-login-btn').click();
        await expect(plugin.locator('.nr-passkeys-fe-login__error')).toHaveText('Please enter your username.');
        await expect(username).toBeFocused();
    });

    test('the default CSS follows its constant', async ({ page }) => {
        const stylesheet = 'link[rel="stylesheet"][href*="passkey-fe.css"]';

        await page.goto('/login-plugin');
        await expect(page.locator(stylesheet)).toHaveCount(1);

        // css.includeDefault = 0 for this page (runTests.conf, ts-constants).
        await page.goto('/login-plugin-username');
        await expect(page.locator('[data-nr-passkeys-fe="login"]')).toBeAttached();
        await expect(page.locator(stylesheet)).toHaveCount(0);
    });

    test('the password fallback links to the configured page when it is switched on', async ({ page }) => {
        await page.goto('/login-plugin-username');
        const link = page.locator('[data-nr-passkeys-fe="login"]').getByRole('link', { name: 'Use password instead' });
        await expect(link).toHaveAttribute('href', /\/login$/);

        // The redirecting plugin has the same page configured but the switch off.
        await page.goto('/login-plugin-redirect');
        await expect(
            page.locator('[data-nr-passkeys-fe="login"]').getByRole('link', { name: 'Use password instead' }),
        ).toHaveCount(0);
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

        // PasskeyLogin.js posts the login token through the plugin's hidden
        // form, which targets the current page when no redirect is configured.
        // What proves the login is not markup but access: manageList answers
        // 200 for an authenticated frontend user and refuses an anonymous one,
        // which the neighbouring management spec pins separately.
        await expect.poll(
            async () => (await page.request.get(eidUrl('manageList'))).status(),
            {
                message: 'the passkey ceremony has to leave an authenticated frontend session',
                timeout: 30_000,
            },
        ).toBe(200);
        await expect(page).toHaveURL(/\/login-plugin$/);

        // No password login here: the ceremony left the session the poll above
        // just proved, and felogin answers a logged-in visitor with the logout
        // view, which carries no username field.
        await removeAllCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    test('a passkey login on a plugin with a redirect page lands there', async ({ page }) => {
        test.setTimeout(90_000);

        const { cdp, authenticatorId } = await addVirtualAuthenticator(page);

        await loginWithPassword(page);
        const registered = await registerPasskey(page, 'E2E redirect key');
        expect(registered.success, `Registration failed: ${registered.error}`).toBe(true);

        await setAutomaticPresence(cdp, authenticatorId, false);
        await logOut(page);

        await page.goto('/login-plugin-redirect', { waitUntil: 'load' });
        // The server resolved the page to a link on this site; the browser
        // never sees the page uid.
        await expect(page.locator('#nr-passkeys-fe-token-form')).toHaveAttribute('action', /\/member$/);

        const button = page.locator('#nr-passkeys-fe-login-btn');
        await expect(button).toBeVisible({ timeout: 5000 });
        await setAutomaticPresence(cdp, authenticatorId, true);
        await button.click();

        await page.waitForURL(/\/member$/, { timeout: 30_000 });
        expect((await page.request.get(eidUrl('manageList'))).status()).toBe(200);

        await removeAllCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    // Pages whose link would lead elsewhere, and pages that are no standard
    // page (runTests.conf): the login token must never be posted there, and
    // no password link may point there. The plugin falls back to its own page
    // and to no link.
    const refusedTargets: Array<[string, string]> = [
        ['/login-plugin-external', 'an external-URL page'],
        ['/login-plugin-shortcut', 'a shortcut into another site'],
        ['/login-plugin-refused-200', 'an external-URL page with a tab before "//"'],
        ['/login-plugin-refused-201', 'an external-URL page with a line feed before "//"'],
        ['/login-plugin-refused-202', 'an external-URL page with a carriage return before "//"'],
        ['/login-plugin-refused-203', 'an external-URL page with a tab and a backslash'],
        ['/login-plugin-refused-204', 'an external-URL page with a backslash before the user info'],
        ['/login-plugin-refused-205', 'an external-URL page on another port'],
        ['/login-plugin-refused-206', 'a link page to a page of this site'],
        ['/login-plugin-refused-207', 'a folder'],
        ['/login-plugin-refused-208', 'a shortcut to an external-URL page'],
        ['/login-plugin-group-tree', 'a page below a parent for a group'],
        ['/login-plugin-shortcut-random', 'a "random subpage" shortcut'],
        ['/login-plugin-chain-random', 'a shortcut to a "random subpage" shortcut'],
        ['/login-plugin-hidden-tree', 'a page below a hidden parent that extends to its subpages'],
        ['/login-plugin-future-tree', 'a page below a parent that extends a future start time'],
    ];
    for (const [path, what] of refusedTargets) {
        test(`${what} is neither the login target nor the password link`, async ({ page }) => {
            await page.goto(path, { waitUntil: 'load' });

            await expect(page.locator('#nr-passkeys-fe-token-form')).toHaveAttribute('action', new RegExp(`${path}$`));
            await expect(
                page.locator('[data-nr-passkeys-fe="login"]').getByRole('link', { name: 'Use password instead' }),
            ).toHaveCount(0);
        });
    }

    test('links under config.forceAbsoluteUrls are kept', async ({ page }) => {
        // config.forceAbsoluteUrls is set for this page only (runTests.conf).
        // With a host in the site base TYPO3 builds absolute links on it;
        // with the base '/' it builds paths.
        const prefix = process.env.E2E_SITE_BASE_HOST === '1' ? `http://${process.env.E2E_SECURE_ALIAS_HOST}` : '';
        await page.goto('/login-plugin-absolute', { waitUntil: 'load' });

        await expect(page.locator('#nr-passkeys-fe-token-form')).toHaveAttribute('action', `${prefix}/member`);
        await expect(
            page.locator('[data-nr-passkeys-fe="login"]').getByRole('link', { name: 'Use password instead' }),
        ).toHaveAttribute('href', `${prefix}/login`);
    });

    // Where a real passkey login lands, and what the page answers there. The
    // member (group 7) is used because core grants "any logged-in user" (-2)
    // only to a user with at least one group.
    for (const [path, landing, what] of [
        ['/login-plugin-members', '/members-only', 'a page for any logged-in user is where the login lands'],
        ['/login-plugin-group', '/login-plugin-group', 'a page for a group is no login target; the login stays on the plugin page'],
        ['/login-plugin-hide', '/login-plugin-hide', 'a page hidden at login is no login target; the login stays on the plugin page'],
        ['/login-plugin-members-tree', '/members-tree/child', 'a page below a parent for any logged-in user is where the login lands'],
        ['/login-plugin-shortcut-members', '/members-only', 'a shortcut to a page for any logged-in user is where the login lands'],
        ['/login-plugin-group-tree', '/login-plugin-group-tree', 'a page below a parent for a group is no login target; the login stays on the plugin page'],
    ]) {
        test(what, async ({ page }) => {
            test.setTimeout(90_000);

            const { cdp, authenticatorId } = await addVirtualAuthenticator(page);

            await loginWithPassword(page, 'e2e_member');
            const registered = await registerPasskey(page, `E2E landing ${landing}`);
            expect(registered.success, `Registration failed: ${registered.error}`).toBe(true);

            await setAutomaticPresence(cdp, authenticatorId, false);
            await logOut(page);

            await page.goto(path, { waitUntil: 'load' });
            await expect(page.locator('#nr-passkeys-fe-token-form')).toHaveAttribute('action', new RegExp(`${landing}$`));

            const button = page.locator('#nr-passkeys-fe-login-btn');
            await expect(button).toBeVisible({ timeout: 5000 });
            await setAutomaticPresence(cdp, authenticatorId, true);
            const landed = page.waitForResponse(
                (response) => response.request().isNavigationRequest()
                    && response.request().method() === 'POST'
                    && new URL(response.url()).pathname === landing,
                { timeout: 30_000 },
            );
            await button.click();

            expect((await landed).status(), `the login has to land on ${landing} with a page, not an error`).toBe(200);
            await expect(page).toHaveURL(new RegExp(`${landing}$`));
            expect((await page.request.get(eidUrl('manageList'))).status()).toBe(200);

            await removeAllCredentials(page);
            await removeVirtualAuthenticator(cdp, authenticatorId);
        });
    }

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

test.describe('Passkey tab in the felogin form', () => {
    const stylesheet = 'link[rel="stylesheet"][href*="passkey-fe.css"]';

    test('it logs in discoverably by default, with the default CSS', async ({ page }) => {
        await page.goto('/login', { waitUntil: 'load' });

        const panel = page.locator('#nr-passkeys-fe-panel-passkey');
        await expect(panel).toHaveAttribute('data-discoverable', '1');
        await expect(panel.locator('[name="nr_passkeys_username"]')).toHaveCount(0);
        // The eID controllers take the site from the request; the attribute
        // that carried the RP ID under this name is gone.
        await expect(panel).not.toHaveAttribute('data-site-identifier');
        await expect(page.locator(stylesheet)).toHaveCount(1);
    });

    test('it follows the constants: username first and no default CSS', async ({ page }) => {
        // Both constants are 0 for this page (runTests.conf, ts-constants).
        await page.goto('/login-username', { waitUntil: 'load' });

        const panel = page.locator('#nr-passkeys-fe-panel-passkey');
        await expect(panel).toHaveAttribute('data-discoverable', '0');
        await expect(page.locator(stylesheet)).toHaveCount(0);

        const username = panel.getByRole('textbox', { name: 'Username' });
        await expect(username).toBeVisible();
        await expect(username).toHaveAttribute('name', 'nr_passkeys_username');

        await page.locator('#nr-passkeys-fe-felogin-login-btn').click();
        await expect(panel.locator('.nr-passkeys-fe-login__error')).toHaveText('Please enter your username.');
        await expect(username).toBeFocused();
    });
});
