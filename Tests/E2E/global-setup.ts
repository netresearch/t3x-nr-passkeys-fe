import { request } from '@playwright/test';

import { target } from './instance-address';

/**
 * Wait until the instance actually answers before the first test runs.
 *
 * The shared runner waits for open TCP ports — MariaDB 3306, PHP-FPM 9000,
 * Apache 80 — and an open port is not readiness. Apache accepts a connection
 * on :80 while its proxy cannot yet resolve the `phpfpm` container, and TYPO3
 * answers 503 while the installation is still finishing. The first spec then
 * fails against a proxy error page, which reads like a defect in the extension
 * and is a race in the environment.
 *
 * Both halves are polled because the suite needs both: the backend login for
 * the module spec, the frontend login page for everything else.
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
