/**
 * Stand-in for TYPO3 backend ES modules (@typo3/*), which the backend import
 * map provides at runtime and which do not exist in node_modules. Every
 * @typo3/* specifier resolves here (vitest.config.js), so this one default
 * export has to satisfy all of them: DocumentService.ready() never resolves,
 * which keeps a module from binding to a DOM the test has not built.
 */
export default {
    ready: () => new Promise(() => {}),
};
