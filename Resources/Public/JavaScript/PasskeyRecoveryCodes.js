/**
 * Passkey Recovery Codes - Display and download recovery codes
 *
 * Auto-initializes on [data-nr-passkeys-fe="recovery-codes"].
 *
 * Features:
 * - Download codes as a plain text file
 * - Generate new codes (replaces existing codes)
 */
(function () {
  'use strict';

  function init() {
    var containers = document.querySelectorAll('[data-nr-passkeys-fe="recovery-codes"]');
    for (var i = 0; i < containers.length; i++) {
      initContainer(containers[i]);
    }
  }

  function initContainer(container) {
    var eidUrl = container.dataset.eidUrl;
    var generateUrl = container.dataset.generateUrl || (eidUrl + '?eID=nr_passkeys_fe&action=recoveryGenerate');

    var downloadBtn = container.querySelector('[data-action="download-codes"]');
    var generateBtn = container.querySelector('[data-action="generate-codes"]');
    var codesGrid = container.querySelector('#nr-passkeys-fe-codes-grid');
    var statusEl = container.querySelector('.nr-passkeys-fe-recovery-codes__status');
    var errorEl = container.querySelector('.nr-passkeys-fe-recovery-codes__error');
    var countEl = document.getElementById('nr-passkeys-fe-recovery-count');

    if (downloadBtn) {
      downloadBtn.addEventListener('click', function () {
        downloadCodes(codesGrid, downloadBtn.dataset.filename || 'recovery-codes.txt');
      });
    }

    if (generateBtn) {
      var btnText = generateBtn.querySelector('.nr-passkeys-fe-btn__text');
      var btnLoading = generateBtn.querySelector('.nr-passkeys-fe-btn__loading');
      generateBtn.addEventListener('click', function () {
        handleGenerateCodes(generateUrl, codesGrid, generateBtn, btnText, btnLoading, statusEl, errorEl, downloadBtn, countEl);
      });
    }
  }

  function downloadCodes(codesGrid, filename) {
    if (!codesGrid) {
      return;
    }

    var codeEls = codesGrid.querySelectorAll('code');
    if (codeEls.length === 0) {
      return;
    }

    var lines = [
      'Passkey Recovery Codes',
      '======================',
      'Keep these codes safe. Each code can only be used once.',
      'Generated: ' + new Date().toISOString(),
      '',
    ];

    for (var i = 0; i < codeEls.length; i++) {
      lines.push(codeEls[i].textContent.trim());
    }

    lines.push('');
    lines.push('After using a code, generate new codes in your passkey settings.');

    var content = lines.join('\n');
    var blob = new Blob([content], { type: 'text/plain; charset=utf-8' });
    var url = URL.createObjectURL(blob);

    var link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();

    // Clean up
    setTimeout(function () {
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    }, 100);
  }

  async function handleGenerateCodes(generateUrl, codesGrid, generateBtn, btnText, btnLoading, statusEl, errorEl, downloadBtn, countEl) {
    var confirmed = window.confirm(
      'Generate new recovery codes? This will invalidate all existing codes.'
    );
    if (!confirmed) {
      return;
    }

    setLoading(true, generateBtn, btnText, btnLoading);
    hideError(errorEl);

    try {
      var response = await fetch(generateUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
        credentials: 'same-origin',
      });

      var data = await response.json().catch(function () { return {}; });

      if (response.ok && data.codes) {
        renderCodes(codesGrid, data.codes);
        showStatus(statusEl, 'New recovery codes generated. Save them now!');
        // Show download button
        if (downloadBtn) {
          downloadBtn.style.display = '';
        }
        // Update remaining count
        if (countEl && data.count !== undefined) {
          countEl.textContent = String(data.count);
        }
      } else {
        showError(errorEl, data.error || 'Failed to generate recovery codes. Please try again.');
      }
    } catch (e) {
      showError(errorEl, 'Network error. Please check your connection and try again.');
      console.error('[nr_passkeys_fe] GenerateCodes error:', e);
    }

    setLoading(false, generateBtn, btnText, btnLoading);
  }

  function renderCodes(codesGrid, codes) {
    if (!codesGrid) {
      return;
    }

    // Clear existing codes
    while (codesGrid.firstChild) {
      codesGrid.removeChild(codesGrid.firstChild);
    }

    codes.forEach(function (code) {
      var div = document.createElement('div');
      div.className = 'nr-passkeys-fe-recovery-codes__code';
      var codeEl = document.createElement('code');
      codeEl.textContent = code;
      div.appendChild(codeEl);
      codesGrid.appendChild(div);
    });

    // Show the grid
    codesGrid.style.display = '';
  }

  function setLoading(loading, btnEl, btnText, btnLoading) {
    if (btnEl) {
      btnEl.disabled = loading;
    }
    if (btnText) {
      btnText.style.display = loading ? 'none' : '';
    }
    if (btnLoading) {
      btnLoading.style.display = loading ? '' : 'none';
    }
  }

  function showError(errorEl, message) {
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.style.display = '';
    }
  }

  function hideError(errorEl) {
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.style.display = 'none';
    }
  }

  function showStatus(statusEl, message) {
    if (statusEl) {
      statusEl.textContent = message;
      statusEl.style.display = '';
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
