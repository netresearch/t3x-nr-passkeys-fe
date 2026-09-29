/**
 * Shared helpers for tests that drive the SHIPPED modules in
 * Resources/Public/JavaScript.
 *
 * The frontend modules are IIFEs that initialise on load, so a test builds
 * the DOM first and then imports the module afresh: vi.resetModules() drops
 * the cached instance and the next import runs the IIFE again against the
 * current document. Nothing here re-implements module logic; only what jsdom
 * cannot run (WebAuthn, the network) is stubbed.
 */
import { vi } from 'vitest';

const MODULE_DIR = '../../../Resources/Public/JavaScript/';

/**
 * Import the given modules, in order, as fresh instances.
 *
 * @param {...string} names file names below Resources/Public/JavaScript
 */
export async function loadModules(...names) {
    vi.resetModules();
    for (const name of names) {
        await import(/* @vite-ignore */ MODULE_DIR + name);
    }
}

/**
 * Let pending promise callbacks (fetch/then chains in the module) run.
 */
export async function settle(rounds = 30) {
    for (let i = 0; i < rounds; i++) {
        await Promise.resolve();
    }
}

/**
 * A minimal fetch Response the modules can read.
 */
export function jsonResponse(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    };
}

export function clearBody() {
    document.body.replaceChildren();
}
