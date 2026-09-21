import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for the nr_passkeys_fe end-to-end suite.
 *
 * The suite runs against a TYPO3 instance, it does not start one:
 *   - `./Build/Scripts/runTests.sh -s e2e` installs one in containers and
 *     passes its address in TYPO3_BASE_URL
 *   - set TYPO3_BASE_URL yourself to use an instance that already runs
 *
 * WebAuthn exists only in a secure context. The runner serves TYPO3 from a
 * container the browser reaches by name over plain http, which Chromium does
 * not trust: window.isSecureContext is false, navigator.credentials is
 * undefined, and every ceremony spec then fails on the environment instead of
 * on the code.
 *
 * Chromium does trust anything under .localhost, so the browser is pointed at
 * http://typo3.localhost and --host-resolver-rules sends that name to the
 * container. Build/Scripts/runTests.conf writes the same host into the site
 * settings as rpId and origin, and sets E2E_SECURE_ALIAS_HOST; only that
 * variable turns the rewrite on, so a run pointed at a foreign instance
 * through TYPO3_BASE_URL keeps its own host.
 */

const target = process.env.TYPO3_BASE_URL || 'http://localhost:8080';
const targetUrl = new URL(target);

const SECURE_ALIAS_HOST = process.env.E2E_SECURE_ALIAS_HOST;
const isTrustedOrigin = targetUrl.protocol === 'https:'
    || ['localhost', '127.0.0.1', '[::1]'].includes(targetUrl.hostname)
    || targetUrl.hostname.endsWith('.localhost');
const useSecureAlias = !!SECURE_ALIAS_HOST && !isTrustedOrigin;

export default defineConfig({
    testDir: './Tests/E2E',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    // Serial execution: the specs share one frontend user and one rate-limit
    // budget, so a parallel run would have them refusing each other.
    workers: 1,
    reporter: process.env.CI ? 'github' : 'list',
    timeout: 30_000,

    use: {
        baseURL: useSecureAlias ? `http://${SECURE_ALIAS_HOST}` : target,
        launchOptions: {
            args: useSecureAlias
                ? [`--host-resolver-rules=MAP ${SECURE_ALIAS_HOST} ${targetUrl.host}`]
                : [],
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
