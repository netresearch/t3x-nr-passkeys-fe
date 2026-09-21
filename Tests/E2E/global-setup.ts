import { request } from '@playwright/test';

import { target } from './instance-address';

/**
 * Wait until the instance actually answers before the first test runs.
 *
 * The shared runner checks one page before handing over: after the containers
 * are up it requires `/` to answer 200. It never asks the backend. In the
 * first CI run of this suite the first backend request, made after that check
 * had passed, met Apache's "DNS lookup failure for: phpfpm" twice and a TYPO3
 * 503 once; every request after that succeeded. What made the `phpfpm` alias
 * unresolvable for that moment is not established. The failure landed on the
 * first spec and read like a defect in the module it happened to open.
 *
 * So the suite asks for the two pages it actually needs — the backend login
 * for the module spec, the frontend login page for everything else — and does
 * not start until both answer 200. That covers a fault still present when the
 * suite begins; one that starts later, mid-run, it cannot see.
 *
 * The address is the one the runner published, not the alias the browser is
 * pointed at: this runs in Node, where the browser's host-resolver rule does
 * not apply and the alias resolves elsewhere or not at all.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

const PATHS = ['/typo3/login', '/login'];
const TIMEOUT_MS = 120_000;
const INTERVAL_MS = 1_000;

async function waitForPath(path: string, deadline: number): Promise<void> {
    const context = await request.newContext({ baseURL: target, ignoreHTTPSErrors: true });
    let lastSeen = 'no response at all';

    try {
        while (Date.now() < deadline) {
            try {
                const response = await context.get(path, { timeout: 10_000 });
                if (response.status() === 200) {
                    return;
                }

                lastSeen = `HTTP ${response.status()}`;
            } catch (error) {
                lastSeen = error instanceof Error ? error.message.split('\n')[0] : String(error);
            }

            await new Promise((resolve) => setTimeout(resolve, INTERVAL_MS));
        }
    } finally {
        await context.dispose();
    }

    throw new Error(
        `${target}${path} did not answer 200 within ${TIMEOUT_MS / 1000}s — last seen: ${lastSeen}. `
        + 'The suite would otherwise run against a half-started stack and report its state as a defect.',
    );
}

export default async function globalSetup(): Promise<void> {
    const deadline = Date.now() + TIMEOUT_MS;

    for (const path of PATHS) {
        await waitForPath(path, deadline);
    }
}
