/**
 * Tests for NrPasskeysFe.submitLoginToken().
 *
 * The token that the verify endpoint hands back is what establishes the
 * frontend session, and it travels through a form TYPO3 rendered — only such a
 * form carries the `__RequestToken` a frontend login is accepted with. Which
 * form depends on the page: felogin's where the login plugin sits beside it,
 * the plugin's own hidden one where it stands alone.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

import '../../Resources/Public/JavaScript/PasskeyUtils.js';

function clearBody() {
    while (document.body.firstChild) {
        document.body.removeChild(document.body.firstChild);
    }
}

function appendForm({ wrapperId = null, formId = null } = {}) {
    const form = document.createElement('form');
    form.setAttribute('action', '/login');
    if (formId) {
        form.id = formId;
    }
    for (const name of ['user', 'pass', 'logintype']) {
        const input = document.createElement('input');
        input.name = name;
        form.appendChild(input);
    }

    if (wrapperId) {
        const wrapper = document.createElement('div');
        wrapper.id = wrapperId;
        wrapper.appendChild(form);
        document.body.appendChild(wrapper);
    } else {
        document.body.appendChild(form);
    }

    return form;
}

describe('submitLoginToken', () => {
    let submitSpy;

    beforeEach(() => {
        clearBody();
        submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    });

    afterEach(() => {
        submitSpy.mockRestore();
        clearBody();
    });

    it('fills felogin form when the password panel is present', () => {
        const form = appendForm({ wrapperId: 'nr-passkeys-fe-panel-password' });

        expect(window.NrPasskeysFe.submitLoginToken('token-123')).toBe(true);

        expect(form.querySelector('input[name="user"]').value).toBe('__passkey__');
        expect(form.querySelector('input[name="logintype"]').value).toBe('login');
        expect(JSON.parse(form.querySelector('input[name="pass"]').value)).toEqual({
            _type: 'passkey_token',
            token: 'token-123',
        });
        expect(submitSpy).toHaveBeenCalledTimes(1);
    });

    it('uses the plugin form when no felogin form exists', () => {
        // The login plugin can be placed on a page of its own. Before the
        // template rendered this form the ceremony verified the assertion,
        // reloaded the page and left the visitor anonymous.
        const form = appendForm({ formId: 'nr-passkeys-fe-token-form' });

        expect(window.NrPasskeysFe.submitLoginToken('token-456')).toBe(true);

        expect(form.querySelector('input[name="user"]').value).toBe('__passkey__');
        expect(JSON.parse(form.querySelector('input[name="pass"]').value)).toEqual({
            _type: 'passkey_token',
            token: 'token-456',
        });
        expect(submitSpy).toHaveBeenCalledTimes(1);
    });

    it('refuses when the page carries no form at all', () => {
        // Assembling one here would be rejected by TYPO3 without a word: a
        // frontend login needs the request token only a rendered form has.
        expect(window.NrPasskeysFe.submitLoginToken('token-789')).toBe(false);
        expect(submitSpy).not.toHaveBeenCalled();
    });
});
