/**
 * PasskeyFeAdmin - Interactive admin dashboard for FE passkey management.
 *
 * Handles:
 *  - Enforcement-level dropdown changes (AJAX POST to update fe_groups)
 *  - FE user passkey lookup (AJAX GET to list credentials)
 *  - Single-credential revocation
 *  - Revoke-all for a user
 *  - Unlock (reset rate-limiter) for a user
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import DocumentService from '@typo3/core/document-service.js';

class PasskeyFeAdmin {
  constructor() {
    /** @type {number|null} */
    this.currentFeUserUid = null;

    DocumentService.ready().then(() => this.initialize());
  }

  initialize() {
    this.bindEnforcementSelects();
    this.bindUserLookup();
    this.bindRevokeAll();
    this.bindUnlockUser();
  }

  // ---------------------------------------------------------------------------
  // i18n helpers
  // ---------------------------------------------------------------------------

  /**
   * @param {string} key
   * @param {string} fallback
   * @returns {string}
   */
  translate(key, fallback) {
    return (TYPO3.lang && TYPO3.lang[key]) || fallback;
  }

  /**
   * @param {*} error
   * @returns {Promise<string>}
   */
  async extractErrorMessage(error) {
    try {
      if (error && typeof error.resolve === 'function') {
        const data = await error.resolve();
        if (data && data.error) {
          return data.error;
        }
      }
    } catch {
      // Cannot parse response body; fall through
    }

    if (error && error.response) {
      return this.translate(
        'js.error.server',
        'Server returned an error (status ' + error.response.status + '). Please try again.',
      );
    }

    return this.translate('js.error.network', 'Network error. Please check your connection.');
  }

  // ---------------------------------------------------------------------------
  // Enforcement-level selects
  // ---------------------------------------------------------------------------

  bindEnforcementSelects() {
    const selects = document.querySelectorAll('.passkey-fe-enforcement-select');
    selects.forEach((select) => {
      select.addEventListener('change', (event) => this.handleEnforcementChange(event));
    });
  }

  async handleEnforcementChange(event) {
    const select = event.target;
    const groupUid = parseInt(select.dataset.groupUid, 10);
    const enforcement = select.value;
    const originalValue = select.dataset.originalValue;

    select.disabled = true;

    try {
      const url = TYPO3.settings.ajaxUrls.nr_passkeys_fe_admin_update_enforcement;
      if (!url) {
        throw new Error('AJAX route nr_passkeys_fe_admin_update_enforcement not registered.');
      }
      const response = await new AjaxRequest(url).post({ groupUid, enforcement });
      const data = await response.resolve();

      if (data.status === 'ok') {
        select.dataset.originalValue = enforcement;
        Notification.success(
          this.translate('js.enforcement.updated', 'Enforcement updated'),
          this.translate('js.enforcement.setTo', 'Group enforcement set to "%s".').replace('%s', enforcement),
        );
      } else {
        select.value = originalValue;
        Notification.error(
          this.translate('js.enforcement.failed', 'Update failed'),
          data.error || this.translate('js.error.unknown', 'Unknown error.'),
        );
      }
    } catch (error) {
      select.value = originalValue;
      const message = await this.extractErrorMessage(error);
      Notification.error(this.translate('js.enforcement.failed', 'Update failed'), message);
    } finally {
      select.disabled = false;
    }
  }

  // ---------------------------------------------------------------------------
  // FE user passkey lookup
  // ---------------------------------------------------------------------------

  bindUserLookup() {
    const loadBtn = document.getElementById('passkey-fe-load-user');
    if (loadBtn) {
      loadBtn.addEventListener('click', () => this.handleLoadUser());
    }
  }

  async handleLoadUser() {
    const input = document.getElementById('passkey-fe-user-uid-input');
    if (!input) {
      return;
    }

    const feUserUid = parseInt(input.value, 10);
    if (!feUserUid || feUserUid <= 0) {
      Notification.warning(
        this.translate('js.admin.lookup.invalidUid', 'Invalid UID'),
        this.translate('js.admin.lookup.invalidUid', 'Please enter a valid frontend user UID.'),
      );
      return;
    }

    this.currentFeUserUid = feUserUid;

    const loadBtn = document.getElementById('passkey-fe-load-user');
    if (loadBtn) {
      loadBtn.disabled = true;
    }

    try {
      const url = TYPO3.settings.ajaxUrls.nr_passkeys_fe_admin_list + '?feUserUid=' + feUserUid;
      const response = await new AjaxRequest(url).get();
      const data = await response.resolve();

      this.renderCredentialTable(data.credentials || []);

      const container = document.getElementById('passkey-fe-user-credentials');
      if (container) {
        container.classList.remove('d-none');
      }
    } catch (error) {
      const message = await this.extractErrorMessage(error);
      Notification.error(this.translate('js.admin.lookup.loadFailed', 'Load failed'), message);
    } finally {
      if (loadBtn) {
        loadBtn.disabled = false;
      }
    }
  }

  /**
   * @param {Array<{uid: number, label: string, siteIdentifier: string, createdAt: number, lastUsedAt: number, isRevoked: boolean}>} credentials
   */
  renderCredentialTable(credentials) {
    const tbody = document.getElementById('passkey-fe-credentials-body');
    if (!tbody) {
      return;
    }

    // Clear existing rows safely
    while (tbody.firstChild) {
      tbody.removeChild(tbody.firstChild);
    }

    if (credentials.length === 0) {
      const row = tbody.insertRow();
      const cell = row.insertCell();
      cell.colSpan = 6;
      cell.className = 'text-body-secondary text-center';
      cell.textContent = this.translate('js.admin.lookup.noCredentials', 'No credentials found.');
      return;
    }

    credentials.forEach((cred) => {
      const row = tbody.insertRow();
      if (cred.isRevoked) {
        row.classList.add('text-body-secondary');
      }

      const formatDate = (ts) => ts > 0
        ? new Date(ts * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
        : '—';

      // Label
      row.insertCell().textContent = cred.label || this.translate('js.admin.lookup.unlabelled', '(unlabelled)');

      // Site
      row.insertCell().textContent = cred.siteIdentifier;

      // Created
      row.insertCell().textContent = formatDate(cred.createdAt);

      // Last used
      row.insertCell().textContent = formatDate(cred.lastUsedAt);

      // Status badge
      const statusCell = row.insertCell();
      const badge = document.createElement('span');
      badge.className = cred.isRevoked ? 'badge text-bg-secondary' : 'badge text-bg-success';
      badge.textContent = cred.isRevoked
        ? this.translate('js.admin.lookup.status.revoked', 'Revoked')
        : this.translate('js.admin.lookup.status.active', 'Active');
      statusCell.appendChild(badge);

      // Actions
      const actionsCell = row.insertCell();
      actionsCell.className = 'col-control nowrap';

      if (!cred.isRevoked) {
        const revokeBtn = document.createElement('button');
        revokeBtn.type = 'button';
        revokeBtn.className = 'btn btn-default btn-sm passkey-fe-revoke-single';
        revokeBtn.dataset.credentialUid = String(cred.uid);
        revokeBtn.title = this.translate('js.admin.lookup.action.revokeTitle', 'Revoke this passkey');
        revokeBtn.textContent = this.translate('js.admin.lookup.action.revoke', 'Revoke');
        revokeBtn.addEventListener('click', (event) => this.handleRevokeSingle(event));
        actionsCell.appendChild(revokeBtn);
      }
    });
  }

  // ---------------------------------------------------------------------------
  // Revoke single credential
  // ---------------------------------------------------------------------------

  async handleRevokeSingle(event) {
    const button = event.currentTarget;
    const credentialUid = parseInt(button.dataset.credentialUid, 10);

    const confirmed = await this.confirm(
      this.translate('js.admin.revoke.title', 'Revoke passkey'),
      this.translate('js.revoke.confirm', 'Revoke this passkey? This cannot be undone.'),
    );

    if (!confirmed) {
      return;
    }

    button.disabled = true;

    try {
      const response = await new AjaxRequest(
        TYPO3.settings.ajaxUrls.nr_passkeys_fe_admin_remove,
      ).post({
        feUserUid: this.currentFeUserUid,
        credentialUid,
      });
      const data = await response.resolve();

      if (data.status === 'ok') {
        Notification.success(
          this.translate('js.revoke.success', 'Passkey revoked'),
          this.translate('js.revoke.message', 'The passkey has been revoked successfully.'),
        );
        // Reload the credential list
        await this.handleLoadUser();
      } else {
        button.disabled = false;
        Notification.error(
          this.translate('js.revoke.failed', 'Revoke failed'),
          data.error || this.translate('js.error.unknown', 'Unknown error.'),
        );
      }
    } catch (error) {
      button.disabled = false;
      const message = await this.extractErrorMessage(error);
      Notification.error(this.translate('js.revoke.failed', 'Revoke failed'), message);
    }
  }

  // ---------------------------------------------------------------------------
  // Revoke all
  // ---------------------------------------------------------------------------

  bindRevokeAll() {
    const btn = document.getElementById('passkey-fe-revoke-all');
    if (btn) {
      btn.addEventListener('click', () => this.handleRevokeAll());
    }
  }

  async handleRevokeAll() {
    if (!this.currentFeUserUid) {
      return;
    }

    const confirmed = await this.confirm(
      this.translate('js.admin.revokeAll.title', 'Revoke all passkeys'),
      this.translate(
        'js.revokeAll.confirm',
        'Revoke ALL passkeys for this user? This cannot be undone.',
      ),
    );

    if (!confirmed) {
      return;
    }

    const btn = document.getElementById('passkey-fe-revoke-all');
    if (btn) {
      btn.disabled = true;
    }

    try {
      const response = await new AjaxRequest(
        TYPO3.settings.ajaxUrls.nr_passkeys_fe_admin_revoke_all,
      ).post({ feUserUid: this.currentFeUserUid });
      const data = await response.resolve();

      if (data.status === 'ok') {
        Notification.success(
          this.translate('js.revokeAll.success', 'All passkeys revoked'),
          this.translate('js.revokeAll.message', 'All passkeys have been revoked for this user.'),
        );
        await this.handleLoadUser();
      } else {
        Notification.error(
          this.translate('js.revokeAll.failed', 'Revoke all failed'),
          data.error || this.translate('js.error.unknown', 'Unknown error.'),
        );
      }
    } catch (error) {
      const message = await this.extractErrorMessage(error);
      Notification.error(this.translate('js.revokeAll.failed', 'Revoke all failed'), message);
    } finally {
      if (btn) {
        btn.disabled = false;
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Unlock user (reset rate-limiter)
  // ---------------------------------------------------------------------------

  bindUnlockUser() {
    const btn = document.getElementById('passkey-fe-unlock-user');
    if (btn) {
      btn.addEventListener('click', () => this.handleUnlockUser());
    }
  }

  async handleUnlockUser() {
    if (!this.currentFeUserUid) {
      return;
    }

    // Prompt for username (needed for audit log and rate-limiter key)
    const username = window.prompt(
      this.translate(
        'js.unlock.confirm',
        'Enter the FE username to reset the login lock:',
      ),
    );
    if (!username || username.trim() === '') {
      return;
    }

    const btn = document.getElementById('passkey-fe-unlock-user');
    const originalText = btn ? btn.textContent : '';
    if (btn) {
      btn.disabled = true;
      btn.textContent = this.translate('js.unlock.progress', 'Resetting...');
    }

    try {
      const response = await new AjaxRequest(
        TYPO3.settings.ajaxUrls.nr_passkeys_fe_admin_unlock,
      ).post({
        feUserUid: this.currentFeUserUid,
        username: username.trim(),
      });
      const data = await response.resolve();

      if (data.status === 'ok') {
        if (btn) {
          btn.textContent = this.translate('js.unlock.done', 'Reset');
        }
        Notification.success(
          this.translate('js.unlock.success', 'Login lock reset'),
          this.translate('js.unlock.message', 'Failed login attempt counter reset for "%s".').replace('%s', username.trim()),
        );
      } else {
        if (btn) {
          btn.textContent = originalText;
          btn.disabled = false;
        }
        Notification.error(
          this.translate('js.unlock.failed', 'Reset failed'),
          data.error || this.translate('js.error.unknown', 'Unknown error.'),
        );
      }
    } catch (error) {
      if (btn) {
        btn.textContent = originalText;
        btn.disabled = false;
      }
      const message = await this.extractErrorMessage(error);
      Notification.error(this.translate('js.unlock.failed', 'Reset failed'), message);
    }
  }

  // ---------------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------------

  /**
   * Show a TYPO3 Modal confirmation dialog.
   *
   * @param {string} title
   * @param {string} message
   * @returns {Promise<boolean>}
   */
  confirm(title, message) {
    return new Promise((resolve) => {
      Modal.confirm(title, message)
        .on('confirm.button.ok', () => {
          Modal.currentModal.trigger('modal-dismiss');
          resolve(true);
        })
        .on('confirm.button.cancel', () => {
          Modal.currentModal.trigger('modal-dismiss');
          resolve(false);
        });
    });
  }
}

export default new PasskeyFeAdmin();
