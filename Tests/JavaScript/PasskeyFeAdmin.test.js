/**
 * Tests for PasskeyFeAdmin.js (backend module): the credential table must
 * paint status with core badge classes and mute revoked rows on the leaf
 * cells, so it follows TYPO3's light and dark scheme on 13.4 and 14.3.
 */
import { describe, it, expect, beforeAll, afterEach } from 'vitest';

// The @typo3/* imports resolve to the stand-ins under Tests/JavaScript/Stubs/
// (vitest.config.js); rendering the table needs none of them.

let admin;

beforeAll(async () => {
    globalThis.TYPO3 = { lang: {} };
    admin = (await import('../../Resources/Public/JavaScript/PasskeyFeAdmin.js')).default;
});

function mountTable() {
    const table = document.createElement('table');
    const tbody = document.createElement('tbody');
    tbody.id = 'passkey-fe-credentials-body';
    table.appendChild(tbody);
    document.body.appendChild(table);
    return tbody;
}

const credential = (overrides) => ({
    uid: 1,
    label: 'Phone',
    siteIdentifier: 'main',
    createdAt: 1700000000,
    lastUsedAt: 0,
    isRevoked: false,
    ...overrides,
});

afterEach(() => {
    document.body.replaceChildren();
});

describe('renderCredentialTable', () => {
    it('paints an active credential with the core success badge', () => {
        const tbody = mountTable();
        admin.renderCredentialTable([credential()]);

        const badge = tbody.querySelector('.badge');
        expect(badge.className).toBe('badge badge-success');
        expect(tbody.querySelector('.text-variant')).toBeNull();
    });

    it('paints a revoked credential with the core default badge and mutes only its text cells', () => {
        const tbody = mountTable();
        admin.renderCredentialTable([credential({ isRevoked: true })]);

        const row = tbody.rows[0];
        expect(row.querySelector('.badge').className).toBe('badge badge-default');
        // text-variant mixes with currentColor on TYPO3 14: never on the row
        // and on a cell inside it at the same time.
        expect(row.className).toBe('');
        const muted = [...row.cells].filter((cell) => cell.classList.contains('text-variant'));
        expect(muted).toHaveLength(4);
        expect(row.querySelectorAll('.text-variant .text-variant')).toHaveLength(0);
    });

    it('mutes the empty-state message with core text-variant', () => {
        const tbody = mountTable();
        admin.renderCredentialTable([]);

        expect(tbody.rows[0].cells[0].className).toBe('text-variant text-center');
    });

    it('never uses Bootstrap classes that core backend CSS does not define', () => {
        const tbody = mountTable();
        admin.renderCredentialTable([credential(), credential({ uid: 2, isRevoked: true })]);

        expect(tbody.innerHTML).not.toMatch(/text-bg-|text-body-secondary/);
    });
});
