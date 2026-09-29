/**
 * Tests for the SHIPPED Resources/Public/JavaScript/PasskeyRecoveryCodes.js.
 *
 * Each test builds a recovery-codes container, imports PasskeyUtils.js and the
 * module afresh (it initialises on load) and drives it through its generate
 * and download buttons. Only fetch, window.confirm and the object-URL API
 * are stubbed; the anchor's click is intercepted because jsdom cannot
 * download.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { loadModules, settle, jsonResponse, clearBody } from './support/modules.js';

function createRecoveryCodes({ codes = [], filename } = {}) {
    const container = document.createElement('div');
    container.setAttribute('data-nr-passkeys-fe', 'recovery-codes');
    container.dataset.generateUrl = '/generate';

    const grid = document.createElement('div');
    grid.id = 'nr-passkeys-fe-codes-grid';
    grid.style.display = codes.length ? '' : 'none';
    codes.forEach((code) => {
        const div = document.createElement('div');
        const el = document.createElement('code');
        el.textContent = code;
        div.appendChild(el);
        grid.appendChild(div);
    });

    const generateBtn = document.createElement('button');
    generateBtn.setAttribute('data-action', 'generate-codes');
    const downloadBtn = document.createElement('button');
    downloadBtn.setAttribute('data-action', 'download-codes');
    downloadBtn.style.display = codes.length ? '' : 'none';
    if (filename) {
        downloadBtn.dataset.filename = filename;
    }
    const status = document.createElement('div');
    status.className = 'nr-passkeys-fe-recovery-codes__status';
    const error = document.createElement('div');
    error.className = 'nr-passkeys-fe-recovery-codes__error';
    error.style.display = 'none';

    const count = document.createElement('span');
    count.id = 'nr-passkeys-fe-recovery-count';
    count.textContent = '3';

    container.append(grid, generateBtn, downloadBtn, status, error);
    document.body.append(container, count);
    return { grid, generateBtn, downloadBtn, status, error, count };
}

/**
 * Stub the object-URL API and intercept the anchor click; returns what the
 * module handed over for download.
 */
function captureDownload() {
    const captured = { blob: null, anchor: null };
    vi.spyOn(URL, 'createObjectURL').mockImplementation((blob) => {
        captured.blob = blob;
        return 'blob:recovery-codes';
    });
    captured.revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
        captured.anchor = { href: this.getAttribute('href'), download: this.download, attached: document.body.contains(this) };
    });
    return captured;
}

beforeEach(() => {
    clearBody();
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    clearBody();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    vi.useRealTimers();
});

describe('PasskeyRecoveryCodes — generating', () => {
    it('sends nothing when the user cancels the regeneration', async () => {
        const { generateBtn } = createRecoveryCodes();
        await loadModules('PasskeyUtils.js', 'PasskeyRecoveryCodes.js');
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        generateBtn.click();
        await settle();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('renders the new codes as text, shows the download button and updates the count', async () => {
        const { grid, generateBtn, downloadBtn, status, count } = createRecoveryCodes();
        await loadModules('PasskeyUtils.js', 'PasskeyRecoveryCodes.js');
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        fetch.mockResolvedValueOnce(jsonResponse(200, { codes: ['ABCD-1234', '<b>x</b>'], count: 2 }));

        generateBtn.click();
        await settle();

        expect(fetch).toHaveBeenCalledWith('/generate', expect.objectContaining({ method: 'POST' }));
        expect([...grid.querySelectorAll('code')].map((c) => c.textContent)).toEqual(['ABCD-1234', '<b>x</b>']);
        expect(grid.querySelector('b')).toBeNull();
        expect(grid.style.display).toBe('');
        expect(downloadBtn.style.display).toBe('');
        expect(count.textContent).toBe('2');
        expect(status.textContent).toBe('New recovery codes generated. Save them now!');
        expect(generateBtn.disabled).toBe(false);
    });

    it('replaces the codes shown before', async () => {
        const { grid, generateBtn } = createRecoveryCodes({ codes: ['OLD1-OLD1'] });
        await loadModules('PasskeyUtils.js', 'PasskeyRecoveryCodes.js');
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        fetch.mockResolvedValueOnce(jsonResponse(200, { codes: ['NEW1-NEW1'], count: 1 }));

        generateBtn.click();
        await settle();

        expect([...grid.querySelectorAll('code')].map((c) => c.textContent)).toEqual(['NEW1-NEW1']);
    });

    it('shows the server error and keeps the grid hidden on failure', async () => {
        const { grid, generateBtn, error, count } = createRecoveryCodes();
        await loadModules('PasskeyUtils.js', 'PasskeyRecoveryCodes.js');
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        fetch.mockResolvedValueOnce(jsonResponse(403, { error: 'Not logged in' }));

        generateBtn.click();
        await settle();

        expect(error.textContent).toBe('Not logged in');
        expect(grid.style.display).toBe('none');
        expect(count.textContent).toBe('3');
    });

    it('reports a network failure', async () => {
        const { generateBtn, error } = createRecoveryCodes();
        await loadModules('PasskeyUtils.js', 'PasskeyRecoveryCodes.js');
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(console, 'error').mockImplementation(() => {});
        fetch.mockRejectedValueOnce(new TypeError('Failed to fetch'));

        generateBtn.click();
        await settle();

        expect(error.textContent).toBe('Network error. Please check your connection and try again.');
        expect(generateBtn.disabled).toBe(false);
    });
});

describe('PasskeyRecoveryCodes — downloading', () => {
    it('downloads the shown codes as a text file and cleans up afterwards', async () => {
        vi.useFakeTimers();
        const { downloadBtn } = createRecoveryCodes({ codes: ['ABCD-1234', ' EFGH-5678 '] });
        await loadModules('PasskeyUtils.js', 'PasskeyRecoveryCodes.js');
        const captured = captureDownload();

        downloadBtn.click();

        expect(captured.anchor).toEqual({ href: 'blob:recovery-codes', download: 'recovery-codes.txt', attached: true });
        expect(captured.blob.type).toBe('text/plain; charset=utf-8');
        const lines = (await captured.blob.text()).split('\n');
        expect(lines[0]).toBe('Passkey Recovery Codes');
        expect(lines).toContain('ABCD-1234');
        expect(lines).toContain('EFGH-5678');
        expect(lines.at(-1)).toBe('After using a code, generate new codes in your passkey settings.');

        vi.advanceTimersByTime(100);
        expect(captured.revoke).toHaveBeenCalledWith('blob:recovery-codes');
        expect(document.querySelector('a[download]')).toBeNull();
    });

    it('uses the file name the template provides', async () => {
        const { downloadBtn } = createRecoveryCodes({ codes: ['ABCD-1234'], filename: 'my-codes.txt' });
        await loadModules('PasskeyUtils.js', 'PasskeyRecoveryCodes.js');
        const captured = captureDownload();

        downloadBtn.click();

        expect(captured.anchor.download).toBe('my-codes.txt');
    });

    it('downloads nothing while no codes are shown', async () => {
        const { downloadBtn } = createRecoveryCodes();
        await loadModules('PasskeyUtils.js', 'PasskeyRecoveryCodes.js');
        const captured = captureDownload();

        downloadBtn.click();

        expect(captured.anchor).toBeNull();
        expect(captured.blob).toBeNull();
    });
});
