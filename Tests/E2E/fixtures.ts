import { Page, CDPSession, expect } from '@playwright/test';

/**
 * Helpers the end-to-end specs share.
 *
 * The suite drives a TYPO3 frontend that Build/Scripts/runTests.conf
 * provisions: a login page at /login, a member page at /member and an
 * enrollment page at /enrollment, each carrying one plugin content element,
 * plus the frontend user below in a storage folder.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

export const FE_USER = process.env.E2E_FE_USER || 'e2e_user';
export const FE_PASSWORD = process.env.E2E_FE_PASSWORD || 'Passkey!E2E!2026';

/** The eID entry point every frontend endpoint of the extension goes through. */
export function eidUrl(action: string): string {
    return `/index.php?eID=nr_passkeys_fe&action=${action}`;
}

/**
 * Add a CDP virtual authenticator.
 *
 * `hasResidentKey` is on because the frontend login is discoverable: the
 * ceremony sends no credential id and the authenticator has to offer one.
 */
export async function addVirtualAuthenticator(
    page: Page,
): Promise<{ cdp: CDPSession; authenticatorId: string }> {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
        },
    });
    return { cdp, authenticatorId };
}

export async function removeVirtualAuthenticator(cdp: CDPSession, authenticatorId: string): Promise<void> {
    try {
        await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
        await cdp.send('WebAuthn.disable');
    } catch {
        // Cleanup runs after the assertions; a failure here says nothing about
        // the subject and must not turn a passing test red.
    }
}

/**
 * Turn the authenticator's automatic user-presence simulation on or off.
 *
 * With it on, the virtual authenticator approves every ceremony the moment it
 * is asked. A page that arms a discoverable ceremony on load therefore
 * authenticates before a test can assert anything about that page, so the
 * specs switch it off while they need the page to stay put.
 */
export async function setAutomaticPresence(
    cdp: CDPSession,
    authenticatorId: string,
    enabled: boolean,
): Promise<void> {
    await cdp.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled });
}

/**
 * Log in with the seeded password credentials through felogin.
 *
 * The extension overrides felogin's template with two tabs, passkey first, and
 * the password panel is hidden until its tab is chosen — which is the point of
 * a passkey-first login. A spec that fills the fields without switching tabs
 * fills hidden ones.
 */
export async function loginWithPassword(page: Page): Promise<void> {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    const passwordTab = page.locator('[data-action="switch-tab"][data-tab="password"]');
    if (await passwordTab.isVisible({ timeout: 5000 }).catch(() => false)) {
        await passwordTab.click();
    }

    const username = page.locator('#tx-felogin-input-username');
    await expect(username, 'the felogin password panel has to be reachable on the login page').toBeVisible({ timeout: 5000 });
    await username.fill(FE_USER);
    await page.locator('#tx-felogin-input-password').fill(FE_PASSWORD);
    await page.locator('#tx-felogin-input-password').press('Enter');
    await page.waitForLoadState('networkidle');
}

export async function logOut(page: Page): Promise<void> {
    await page.context().clearCookies();
}

/**
 * Register a passkey for the user the browser is logged in as.
 *
 * Mirrors what PasskeyManagement.js does: ask the management endpoint for
 * registration options, run navigator.credentials.create() against the virtual
 * authenticator, and post the attestation back. Returns the reason on failure
 * rather than a bare false, so a broken registration names itself instead of
 * turning into a skipped test.
 */
export async function registerPasskey(
    page: Page,
    label = 'E2E key',
): Promise<{ success: boolean; error?: string }> {
    const optionsResponse = await page.request.post(eidUrl('registrationOptions'), {
        headers: { 'Content-Type': 'application/json' },
        data: {},
    });
    if (!optionsResponse.ok()) {
        return {
            success: false,
            error: `registrationOptions ${optionsResponse.status()}: ${(await optionsResponse.text()).substring(0, 200)}`,
        };
    }

    const optionsData = await optionsResponse.json();
    if (!optionsData.options || !optionsData.challengeToken) {
        return { success: false, error: 'registrationOptions answered without options or challengeToken' };
    }

    const credential = await page.evaluate(async (opts: any) => {
        const toBuffer = (b64url: string): ArrayBuffer => {
            const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
            const binary = atob(b64 + '='.repeat((4 - (b64.length % 4)) % 4));
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
            return bytes.buffer;
        };
        const fromBuffer = (buffer: ArrayBuffer): string => {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
            return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
        };

        const created = await navigator.credentials.create({
            publicKey: {
                challenge: toBuffer(opts.challenge),
                rp: { name: opts.rp.name, id: opts.rp.id },
                user: {
                    id: toBuffer(opts.user.id),
                    name: opts.user.name,
                    displayName: opts.user.displayName,
                },
                pubKeyCredParams: (opts.pubKeyCredParams || []).map((p: any) => ({ type: p.type, alg: p.alg })),
                timeout: opts.timeout || 60000,
                attestation: opts.attestation || 'none',
                authenticatorSelection: opts.authenticatorSelection || {},
                excludeCredentials: (opts.excludeCredentials || []).map((c: any) => ({
                    type: c.type,
                    id: toBuffer(c.id),
                    transports: c.transports || [],
                })),
            },
        }) as PublicKeyCredential | null;

        if (!created) return null;

        const attestation = created.response as AuthenticatorAttestationResponse;
        return {
            id: fromBuffer(created.rawId),
            rawId: fromBuffer(created.rawId),
            type: created.type,
            response: {
                clientDataJSON: fromBuffer(attestation.clientDataJSON),
                attestationObject: fromBuffer(attestation.attestationObject),
            },
        };
    }, optionsData.options);

    if (!credential) {
        return { success: false, error: 'navigator.credentials.create() returned null' };
    }

    const verifyResponse = await page.request.post(eidUrl('registrationVerify'), {
        headers: { 'Content-Type': 'application/json' },
        data: {
            credential,
            challengeToken: optionsData.challengeToken,
            label,
        },
    });
    if (!verifyResponse.ok()) {
        return {
            success: false,
            error: `registrationVerify ${verifyResponse.status()}: ${(await verifyResponse.text()).substring(0, 200)}`,
        };
    }

    return { success: true };
}

/** Remove every credential the seeded user holds, so specs do not inherit each other's. */
export async function removeAllCredentials(page: Page): Promise<void> {
    const listResponse = await page.request.get(eidUrl('manageList'));
    if (!listResponse.ok()) return;

    const data = await listResponse.json();
    for (const credential of data.credentials || []) {
        await page.request.post(eidUrl('manageRemove'), {
            headers: { 'Content-Type': 'application/json' },
            data: { uid: credential.uid },
        });
    }
}
