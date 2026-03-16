import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: '.',
    timeout: 30000,
    use: {
        baseURL: process.env.TYPO3_BASE_URL || 'https://localhost',
        screenshot: 'only-on-failure',
    },
});
