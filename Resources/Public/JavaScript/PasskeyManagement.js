/**
 * Passkey Management - Frontend passkey CRUD for TYPO3 Frontend
 *
 * Auto-initializes on [data-nr-passkeys-fe="management"].
 * Handles: list rendering, rename (inline), remove (confirmation), recovery codes.
 *
 * Listens for 'nr-passkeys-fe:registered' events from PasskeyEnrollment.js
 * to refresh the list after a new passkey is registered.
 */
(function () {
  'use strict';

  function init() {
    var containers = document.querySelectorAll('[data-nr-passkeys-fe="management"]');
    for (var i = 0; i < containers.length; i++) {
      initContainer(containers[i]);
    }
  }

  function initContainer(container) {
    var eidUrl = container.dataset.eidUrl;
    var listUrl = container.dataset.listUrl || (eidUrl + '?eID=nr_passkeys_fe&action=manageList');
    var renameUrl = container.dataset.renameUrl || (eidUrl + '?eID=nr_passkeys_fe&action=manageRename');
    var removeUrl = container.dataset.removeUrl || (eidUrl + '?eID=nr_passkeys_fe&action=manageRemove');

    var listBody = container.querySelector('#nr-passkeys-fe-credential-body');
    var emptyEl = container.querySelector('.nr-passkeys-fe-management__empty');
    var errorEl = container.querySelector('.nr-passkeys-fe-management__error');
    var statusEl = container.querySelector('.nr-passkeys-fe-management__status');

    // Feature detection — management still works without WebAuthn, just can't add new keys
    if (!window.PublicKeyCredential) {
      var registerBtn = container.querySelector('[data-action="register-passkey"]');
      if (registerBtn) {
        registerBtn.disabled = true;
        registerBtn.title = 'Your browser does not support Passkeys (WebAuthn).';
      }
    }

    // Delegate click events on credential actions
    container.addEventListener('click', function (e) {
      var action = e.target.dataset && e.target.dataset.action;
      if (!action) {
        return;
      }

      if (action === 'rename-credential') {
        var uid = e.target.dataset.uid;
        var labelSpan = container.querySelector('.nr-passkeys-fe-management__label[data-uid="' + uid + '"]');
        if (labelSpan) {
          startRename(labelSpan, uid, renameUrl, errorEl, statusEl);
        }
      }

      if (action === 'remove-credential') {
        var removeUid = e.target.dataset.uid;
        var removeLabel = e.target.dataset.label || 'this passkey';
        handleRemove(removeUid, removeLabel, removeUrl, listBody, emptyEl, errorEl, statusEl, container, listUrl);
      }
    });

    // Listen for registration events from PasskeyEnrollment.js
    container.addEventListener('nr-passkeys-fe:registered', function () {
      refreshList(listUrl, listBody, emptyEl, errorEl, container);
    });

    // Also listen on document for cross-module events
    document.addEventListener('nr-passkeys-fe:registered', function () {
      refreshList(listUrl, listBody, emptyEl, errorEl, container);
    });

    // Initial dynamic list load (optional — server renders the list too)
    // Only refresh if the list body exists and we have a list URL
    if (listBody && listUrl && listBody.children.length === 0 && !emptyEl) {
      refreshList(listUrl, listBody, emptyEl, errorEl, container);
    }
  }

  async function refreshList(listUrl, listBody, emptyEl, errorEl, container) {
    try {
      var response = await fetch(listUrl, {
        method: 'GET',
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        return;
      }

      var data = await response.json();
      renderList(data.credentials || [], listBody, emptyEl, container);
    } catch (e) {
      console.error('[nr_passkeys_fe] PasskeyManagement list refresh failed:', e);
    }
  }

  function renderList(credentials, listBody, emptyEl, container) {
    if (!listBody) {
      return;
    }

    // Clear existing rows
    while (listBody.firstChild) {
      listBody.removeChild(listBody.firstChild);
    }

    if (credentials.length === 0) {
      if (emptyEl) {
        emptyEl.style.display = '';
      }
      return;
    }

    if (emptyEl) {
      emptyEl.style.display = 'none';
    }

    // Show/hide single key warning
    var warningEl = container.querySelector('.nr-passkeys-fe-management__warning');
    if (warningEl) {
      warningEl.style.display = credentials.length === 1 ? '' : 'none';
    }

    credentials.forEach(function (cred) {
      var row = document.createElement('tr');
      row.className = 'nr-passkeys-fe-management__row';
      row.dataset.uid = cred.uid;

      // Label cell
      var labelCell = document.createElement('td');
      labelCell.className = 'nr-passkeys-fe-management__td';
      var labelSpan = document.createElement('span');
      labelSpan.className = 'nr-passkeys-fe-management__label';
      labelSpan.dataset.uid = cred.uid;
      labelSpan.textContent = cred.label || 'Unnamed';
      labelCell.appendChild(labelSpan);
      row.appendChild(labelCell);

      // Created cell
      var createdCell = document.createElement('td');
      createdCell.className = 'nr-passkeys-fe-management__td';
      createdCell.textContent = cred.createdAt ? formatTimestamp(cred.createdAt) : '—';
      row.appendChild(createdCell);

      // Last used cell
      var lastUsedCell = document.createElement('td');
      lastUsedCell.className = 'nr-passkeys-fe-management__td';
      lastUsedCell.textContent = cred.lastUsedAt ? formatTimestamp(cred.lastUsedAt) : 'Never';
      row.appendChild(lastUsedCell);

      // Type/AAGUID cell
      var typeCell = document.createElement('td');
      typeCell.className = 'nr-passkeys-fe-management__td';
      typeCell.textContent = cred.aaguid || '—';
      row.appendChild(typeCell);

      // Actions cell
      var actionsCell = document.createElement('td');
      actionsCell.className = 'nr-passkeys-fe-management__td nr-passkeys-fe-management__td--actions';

      var renameBtn = document.createElement('button');
      renameBtn.type = 'button';
      renameBtn.className = 'nr-passkeys-fe-btn nr-passkeys-fe-btn--secondary nr-passkeys-fe-btn--sm';
      renameBtn.dataset.action = 'rename-credential';
      renameBtn.dataset.uid = cred.uid;
      renameBtn.textContent = 'Rename';
      actionsCell.appendChild(renameBtn);

      actionsCell.appendChild(document.createTextNode('\u00a0'));

      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'nr-passkeys-fe-btn nr-passkeys-fe-btn--danger nr-passkeys-fe-btn--sm';
      removeBtn.dataset.action = 'remove-credential';
      removeBtn.dataset.uid = cred.uid;
      removeBtn.dataset.label = escapeAttr(cred.label || 'Unnamed');
      removeBtn.textContent = 'Remove';
      actionsCell.appendChild(removeBtn);

      row.appendChild(actionsCell);
      listBody.appendChild(row);
    });
  }

  function startRename(labelSpan, uid, renameUrl, errorEl, statusEl) {
    var currentLabel = labelSpan.textContent;

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'nr-passkeys-fe-management__rename-input';
    input.value = currentLabel;
    input.maxLength = 128;
    input.setAttribute('aria-label', 'New label for passkey');

    labelSpan.textContent = '';
    labelSpan.appendChild(input);
    input.focus();
    input.select();

    var committed = false;

    var commitRename = async function () {
      if (committed) {
        return;
      }
      committed = true;

      var newLabel = input.value.trim();
      if (!newLabel || newLabel === currentLabel) {
        labelSpan.textContent = currentLabel;
        return;
      }

      try {
        var response = await fetch(renameUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ uid: uid, label: newLabel }),
          credentials: 'same-origin',
        });
        var data = await response.json().catch(function () { return {}; });

        if (response.ok && data.status === 'ok') {
          labelSpan.textContent = newLabel;
          showStatus(statusEl, 'Passkey renamed successfully.');
          setTimeout(function () { hideStatus(statusEl); }, 3000);
        } else {
          labelSpan.textContent = currentLabel;
          showError(errorEl, data.error || 'Failed to rename passkey.');
        }
      } catch (e) {
        labelSpan.textContent = currentLabel;
        showError(errorEl, 'Failed to rename passkey.');
        console.error('[nr_passkeys_fe] Rename error:', e);
      }
    };

    input.addEventListener('blur', commitRename);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        input.blur();
      }
      if (e.key === 'Escape') {
        committed = true; // prevent blur from committing
        labelSpan.textContent = currentLabel;
      }
    });
  }

  function handleRemove(uid, label, removeUrl, listBody, emptyEl, errorEl, statusEl, container, listUrl) {
    var safeLabel = escapeText(label);
    var confirmed = window.confirm('Remove passkey "' + safeLabel + '"? This cannot be undone.');
    if (!confirmed) {
      return;
    }

    fetch(removeUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ uid: uid }),
      credentials: 'same-origin',
    }).then(function (response) {
      return response.json().then(function (data) {
        return { ok: response.ok, data: data };
      });
    }).then(function (result) {
      if (result.ok && result.data.status === 'ok') {
        // Remove row from DOM
        var row = container.querySelector('.nr-passkeys-fe-management__row[data-uid="' + uid + '"]');
        if (row && row.parentNode) {
          row.parentNode.removeChild(row);
        }
        showStatus(statusEl, 'Passkey removed successfully.');
        setTimeout(function () { hideStatus(statusEl); }, 3000);

        // Refresh list to update single-key warning
        refreshList(listUrl, listBody, emptyEl, errorEl, container);
      } else {
        showError(errorEl, (result.data && result.data.error) || 'Failed to remove passkey.');
      }
    }).catch(function (e) {
      showError(errorEl, 'Failed to remove passkey.');
      console.error('[nr_passkeys_fe] Remove error:', e);
    });
  }

  function formatTimestamp(ts) {
    if (!ts) {
      return '—';
    }
    var d = new Date(ts * 1000);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString();
  }

  function escapeText(text) {
    var el = document.createElement('span');
    el.textContent = text;
    return el.textContent;
  }

  function escapeAttr(text) {
    return text.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
  }

  function showError(errorEl, message) {
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.style.display = '';
    }
  }

  function showStatus(statusEl, message) {
    if (statusEl) {
      statusEl.textContent = message;
      statusEl.style.display = '';
    }
  }

  function hideStatus(statusEl) {
    if (statusEl) {
      statusEl.textContent = '';
      statusEl.style.display = 'none';
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
