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
final class FrontendAdoptionStatsService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Get adoption statistics, optionally scoped to a site identifier.
     */
    public function getStats(string $siteIdentifier = ''): FrontendAdoptionStats
    {
        $totalUsers = $this->countTotalFeUsers();
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
     * Count total active fe_users.
     */
    private function countTotalFeUsers(): int
    {
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

        $stats = [];

        foreach ($groups as $group) {
            $groupUid = (int) ($group['uid'] ?? 0);
            $groupTitle = \is_string($group['title'] ?? null) ? $group['title'] : '';
            $enforcement = \is_string($group['passkey_enforcement'] ?? null) ? $group['passkey_enforcement'] : 'off';

            $userCount = $this->countUsersInGroup($groupUid);
            $withPasskeys = $this->countUsersWithPasskeysInGroup($groupUid, $siteIdentifier);

            $stats[(string) $groupUid] = [
                'groupName' => $groupTitle,
                'userCount' => $userCount,
                'withPasskeys' => $withPasskeys,
                'enforcement' => $enforcement,
            ];
        }

        return $stats;
    }

    /**
     * Count fe_users that belong to a specific group.
     *
     * TYPO3 stores fe_groups membership as comma-separated UIDs in fe_users.usergroup.
     */
    private function countUsersInGroup(int $groupUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');

        $result = $queryBuilder
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

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Count fe_users in a group that have at least one active passkey.
     */
    private function countUsersWithPasskeysInGroup(int $groupUid, string $siteIdentifier): int
    {
        $connection = $this->connectionPool->getConnectionForTable('fe_users');

        $sql = <<<'SQL'
            SELECT COUNT(DISTINCT u.uid)
            FROM fe_users u
            INNER JOIN tx_nrpasskeysfe_credential c ON c.fe_user = u.uid AND c.revoked_at = 0
            WHERE FIND_IN_SET(?, u.usergroup) > 0
            SQL;

        $params = [(string) $groupUid];
        $types = [ParameterType::STRING];

        if ($siteIdentifier !== '') {
            $sql .= ' AND c.site_identifier = ?';
            $params[] = $siteIdentifier;
            $types[] = ParameterType::STRING;
        }

        $result = $connection->executeQuery($sql, $params, $types)->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }
}
