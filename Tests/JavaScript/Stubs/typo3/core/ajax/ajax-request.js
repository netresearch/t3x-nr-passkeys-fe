/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Stand-in for TYPO3's @typo3/core/ajax/ajax-request.js, which the backend
 * import map provides at runtime (see vitest.config.js). Tests that send a
 * request replace it with vi.mock().
 */
export default class AjaxRequest {
    constructor(url) {
        this.url = url;
    }
}
