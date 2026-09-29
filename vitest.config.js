import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    resolve: {
        // Backend modules import TYPO3's own ES modules through the backend
        // import map; each @typo3/<path> resolves to a stand-in of the same
        // path under Tests/JavaScript/Stubs/typo3/, which a test can replace
        // with vi.mock().
        alias: [
            {
                find: /^@typo3\/(.*)$/,
                replacement: fileURLToPath(new URL('./Tests/JavaScript/Stubs/typo3/', import.meta.url)) + '$1',
            },
        ],
    },
    test: {
        environment: 'jsdom',
        include: ['Tests/JavaScript/**/*.test.js'],
    },
});
