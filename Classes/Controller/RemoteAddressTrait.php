<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * The client address of a request, from the request itself.
 *
 * Replaces GeneralUtility::getIndpEnv('REMOTE_ADDR'), deprecated in TYPO3
 * 14.3. The eID handler runs after core's normalized-params-attribute
 * middleware in the frontend stack (13.4 and 14.3), so the attribute is set
 * for every eID request. A request that did not pass the middleware falls
 * back to the same computation from its server params and the SYS
 * configuration, so reverseProxyIP / reverseProxyHeaderMultiValue apply
 * either way. The fallback passes empty script and site paths: the remote
 * address does not depend on them, and NormalizedParams::createFromRequest()
 * would require an initialised Environment to fill them.
 */
trait RemoteAddressTrait
{
    private function getRemoteAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$normalizedParams instanceof NormalizedParams) {
            $configurationVariables = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
            $systemConfiguration = \is_array($configurationVariables) ? ($configurationVariables['SYS'] ?? []) : [];
            $normalizedParams = new NormalizedParams(
                $request->getServerParams(),
                \is_array($systemConfiguration) ? $systemConfiguration : [],
                '',
                '',
            );
        }

        return $normalizedParams->getRemoteAddress();
    }
}
