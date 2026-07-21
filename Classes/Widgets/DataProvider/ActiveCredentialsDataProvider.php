<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Widgets\DataProvider;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;

/**
 * Number data provider: total active (not revoked) frontend passkey credentials.
 *
 * Single aggregate query; the default QueryBuilder restrictions take care of
 * deleted credential rows.
 */
final readonly class ActiveCredentialsDataProvider implements NumberWithIconDataProviderInterface
{
    private const TABLE = 'tx_nrpasskeysfe_credential';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getNumber(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $result = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('revoked_at', 0),
            )
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }
}
