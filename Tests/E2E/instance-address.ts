/**
 * Where the instance under test lives, and under which name the browser sees it.
 *
 * Two addresses, and they are not interchangeable:
 *
 *   `target` is the address the runner published, and the only one a plain
 *   Node client can reach. The readiness check in global-setup.ts uses it.
 *
 *   `browserBaseUrl` is what the browser is pointed at. WebAuthn exists only
 *   in a secure context, and Chromium does not trust a container name over
 *   plain http: window.isSecureContext is false, navigator.credentials is
 *   undefined, and every ceremony spec fails on the environment instead of on
 *   the code. Chromium does trust anything under .localhost, so the browser
 *   gets http://<alias> and `hostResolverRules` sends that name to the
 *   container. Build/Scripts/runTests.conf writes the same host into the site
 *   settings as rpId and origin and sets E2E_SECURE_ALIAS_HOST; only that
 *   variable turns the rewrite on, so a run pointed at a foreign instance
 *   through TYPO3_BASE_URL keeps its own host.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

export const target = process.env.TYPO3_BASE_URL || 'http://localhost:8080';

const targetUrl = new URL(target);
const secureAliasHost = process.env.E2E_SECURE_ALIAS_HOST;
const isTrustedOrigin = targetUrl.protocol === 'https:'
    || ['localhost', '127.0.0.1', '[::1]'].includes(targetUrl.hostname)
    || targetUrl.hostname.endsWith('.localhost');

export const useSecureAlias = !!secureAliasHost && !isTrustedOrigin;

export const browserBaseUrl = useSecureAlias ? `http://${secureAliasHost}` : target;

export const hostResolverRules = useSecureAlias
    ? [`--host-resolver-rules=MAP ${secureAliasHost} ${targetUrl.host}`]
    : [];
