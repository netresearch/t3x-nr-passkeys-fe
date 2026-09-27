import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    resolve: {
        // Backend modules import TYPO3's own ES modules through the import map;
        // resolve them to one stub (see Tests/JavaScript/Stubs/typo3-module.js).
        alias: [
            {
                find: /^@typo3\/.*$/,
                replacement: fileURLToPath(new URL('./Tests/JavaScript/Stubs/typo3-module.js', import.meta.url)),
            },
        ],
    },
    test: {
        environment: 'jsdom',
        include: ['Tests/JavaScript/**/*.test.js'],
    },
});
