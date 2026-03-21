<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Service for looking up frontend users (fe_users table).
 *
 * Extracted from FrontendCredentialRepository to separate concerns:
 * credential storage vs. user lookup.
 */
final readonly class FrontendUserLookupService
{
    private const TABLE = 'fe_users';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Find an active frontend user UID by username.
     *
     * Queries the fe_users table for a non-disabled, non-deleted user.
     */
    public function findFeUserUidByUsername(string $username): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'username',
                    $queryBuilder->createNamedParameter($username),
                ),
                $queryBuilder->expr()->eq('disable', 0),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $rawUid = $row['uid'] ?? null;

        return \is_numeric($rawUid) ? (int) $rawUid : null;
    }

    /**
     * Look up a frontend user's username by UID.
     *
     * Returns null if no matching active user is found.
     * Checks disable/deleted flags for consistency with findFeUserUidByUsername.
     *
     * @return array{uid: int, username: string}|null
     */
    public function findFeUserByUid(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('uid', 'username')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('disable', 0),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'uid' => \is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0,
            'username' => \is_string($row['username'] ?? null) ? (string) $row['username'] : '',
        ];
    }
}
