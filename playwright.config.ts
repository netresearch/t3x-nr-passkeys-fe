import { defineConfig, devices } from '@playwright/test';

import { browserBaseUrl, hostResolverRules } from './Tests/E2E/instance-address';

/**
 * Playwright configuration for the nr_passkeys_fe end-to-end suite.
 *
 * The suite runs against a TYPO3 instance, it does not start one:
 *   - `./Build/Scripts/runTests.sh -s e2e` installs one in containers and
 *     passes its address in TYPO3_BASE_URL
 *   - set TYPO3_BASE_URL yourself to use an instance that already runs
 *
 * Which address the browser gets, and why it differs from the one the runner
 * published, is explained in Tests/E2E/instance-address.ts. Tests/E2E/global-setup.ts
 * holds the first test back until the instance answers.
 */

export default defineConfig({
    testDir: './Tests/E2E',
    globalSetup: './Tests/E2E/global-setup.ts',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    // Serial execution: the specs share one frontend user and one rate-limit
    // budget, so a parallel run would have them refusing each other.
    workers: 1,
    reporter: process.env.CI ? 'github' : 'list',
    timeout: 30_000,

    use: {
        baseURL: browserBaseUrl,
        launchOptions: {
            args: hostResolverRules,
        },
        ignoreHTTPSErrors: true,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
