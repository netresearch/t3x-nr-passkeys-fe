/**
 * Stand-in for TYPO3's @typo3/core/document-service.js (see vitest.config.js).
 * ready() never resolves, so a module does not bind to a DOM the test has not
 * built yet; a test calls the module's methods itself.
 */
export default {
    ready: () => new Promise(() => {}),
};
