<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Doctrine\DBAL\ParameterType;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendAdoptionStats;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Computes passkey adoption statistics for frontend users.
 *
 * Used by the admin dashboard to show how many fe_users have
 * enrolled passkeys, broken down by fe_group.
 */
final readonly class FrontendAdoptionStatsService
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Get adoption statistics, optionally scoped to a site identifier.
     */
    public function getStats(string $siteIdentifier = ''): FrontendAdoptionStats
    {
        $totalUsers = $this->countTotalFeUsers($siteIdentifier);
        $usersWithPasskeys = $this->countUsersWithPasskeys($siteIdentifier);

        $adoptionPercentage = $totalUsers > 0
            ? \round(($usersWithPasskeys / $totalUsers) * 100, 1)
            : 0.0;

        $perGroupStats = $this->getPerGroupStats($siteIdentifier);

        return new FrontendAdoptionStats(
            totalUsers: $totalUsers,
            usersWithPasskeys: $usersWithPasskeys,
            adoptionPercentage: $adoptionPercentage,
            perGroupStats: $perGroupStats,
        );
    }

    /**
     * Count total active fe_users, optionally scoped to a site identifier.
     *
     * When a site identifier is given, only users that have at least one
     * credential on that site are counted. Without a site scope the global
     * count of all active fe_users is returned.
     */
    private function countTotalFeUsers(string $siteIdentifier = ''): int
    {
        if ($siteIdentifier !== '') {
            $connection = $this->connectionPool->getConnectionForTable('fe_users');

            $sql = <<<'SQL'
                SELECT COUNT(DISTINCT u.uid)
                FROM fe_users u
                INNER JOIN tx_nrpasskeysfe_credential c ON c.fe_user = u.uid
                WHERE u.deleted = 0 AND u.disable = 0
                  AND c.site_identifier = ?
                SQL;

            $result = $connection->executeQuery($sql, [$siteIdentifier], [ParameterType::STRING])->fetchOne();

            return \is_numeric($result) ? (int) $result : 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');

        $result = $queryBuilder
            ->count('uid')
            ->from('fe_users')
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Count fe_users that have at least one active (non-revoked) passkey credential.
     */
    private function countUsersWithPasskeys(string $siteIdentifier): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_nrpasskeysfe_credential');

        $sql = 'SELECT COUNT(DISTINCT fe_user) FROM tx_nrpasskeysfe_credential WHERE revoked_at = 0';
        $params = [];
        $types = [];

        if ($siteIdentifier !== '') {
            $sql .= ' AND site_identifier = ?';
            $params[] = $siteIdentifier;
            $types[] = ParameterType::STRING;
        }

        $result = $connection->executeQuery($sql, $params, $types)->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Get per-group breakdown of passkey adoption.
     *
     * Uses two aggregate queries (GROUP BY) instead of per-group queries
     * to avoid the N+1 query pattern.
     *
     * @return array<string, array{groupName: string, userCount: int, withPasskeys: int, enforcement: string}>
     */
    private function getPerGroupStats(string $siteIdentifier): array
    {
        // Fetch all active fe_groups
        $groupQb = $this->connectionPool->getQueryBuilderForTable('fe_groups');
        $groups = $groupQb
            ->select('uid', 'title', 'passkey_enforcement')
            ->from('fe_groups')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($groups === []) {
            return [];
        }

        // Build group metadata map
        $stats = [];
        foreach ($groups as $group) {
            $groupUid = (string) (int) ($group['uid'] ?? 0);
            $groupTitle = \is_string($group['title'] ?? null) ? $group['title'] : '';
            $enforcement = \is_string($group['passkey_enforcement'] ?? null) ? $group['passkey_enforcement'] : 'off';

            $stats[$groupUid] = [
                'groupName' => $groupTitle,
                'userCount' => 0,
                'withPasskeys' => 0,
                'enforcement' => $enforcement,
            ];
        }

        // Count users per group (portable, uses QueryBuilder::inSet)
        $usersPerGroup = $this->countUsersPerGroup($groups);
        foreach ($usersPerGroup as $groupUid => $count) {
            $key = (string) $groupUid;
            if (isset($stats[$key])) {
                $stats[$key]['userCount'] = $count;
            }
        }

        // Count users with passkeys per group (portable, uses QueryBuilder::inSet)
        $passkeysPerGroup = $this->countUsersWithPasskeysPerGroup($groups, $siteIdentifier);
        foreach ($passkeysPerGroup as $groupUid => $count) {
            $key = (string) $groupUid;
            if (isset($stats[$key])) {
                $stats[$key]['withPasskeys'] = $count;
            }
        }

        return $stats;
    }

    /**
     * Count users per group using portable QueryBuilder queries.
     *
     * Uses per-group queries with ExpressionBuilder::inSet() for cross-database
     * compatibility (MySQL, PostgreSQL, SQLite). The N+1 pattern is acceptable
     * here because this is an admin dashboard endpoint, not a hot path.
     *
     * @param list<array<string, mixed>> $groups
     * @return array<int, int> Map of group UID to user count
     */
    private function countUsersPerGroup(array $groups): array
    {
        $result = [];

        foreach ($groups as $group) {
            $groupUid = (int) ($group['uid'] ?? 0);
            if ($groupUid === 0) {
                continue;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');
            $count = $queryBuilder
                ->count('uid')
                ->from('fe_users')
                ->where(
                    $queryBuilder->expr()->inSet(
                        'usergroup',
                        $queryBuilder->createNamedParameter((string) $groupUid),
                    ),
                )
                ->executeQuery()
                ->fetchOne();

            $result[$groupUid] = \is_numeric($count) ? (int) $count : 0;
        }

        return $result;
    }

    /**
     * Count users with active passkeys per group using portable QueryBuilder queries.
     *
     * Uses per-group queries with ExpressionBuilder::inSet() for cross-database
     * compatibility. See {@see countUsersPerGroup()} for rationale on N+1 pattern.
     *
     * @param list<array<string, mixed>> $groups
     * @return array<int, int> Map of group UID to count of users with passkeys
     */
    private function countUsersWithPasskeysPerGroup(array $groups, string $siteIdentifier): array
    {
        $result = [];

        foreach ($groups as $group) {
            $groupUid = (int) ($group['uid'] ?? 0);
            if ($groupUid === 0) {
                continue;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');
            $queryBuilder
                ->count('DISTINCT u.uid')
                ->from('fe_users', 'u')
                ->join(
                    'u',
                    'tx_nrpasskeysfe_credential',
                    'c',
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('c.fe_user', $queryBuilder->quoteIdentifier('u.uid')),
                        $queryBuilder->expr()->eq('c.revoked_at', 0),
                    ),
                )
                ->where(
                    $queryBuilder->expr()->inSet(
                        'u.usergroup',
                        $queryBuilder->createNamedParameter((string) $groupUid),
                    ),
                );

            if ($siteIdentifier !== '') {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq(
                        'c.site_identifier',
                        $queryBuilder->createNamedParameter($siteIdentifier),
                    ),
                );
            }

            $count = $queryBuilder->executeQuery()->fetchOne();
            $result[$groupUid] = \is_numeric($count) ? (int) $count : 0;
        }

        return $result;
    }
}
