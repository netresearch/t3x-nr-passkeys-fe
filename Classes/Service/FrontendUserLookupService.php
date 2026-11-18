<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_numeric;
use function is_string;

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
    public function findFeUserUidByUsername(string $username, ?SiteInterface $site = null): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->select('uid', 'pid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'username',
                    $queryBuilder->createNamedParameter($username),
                ),
                $queryBuilder->expr()->eq('disable', 0),
                $queryBuilder->expr()->eq('deleted', 0),
            )
        ;
        if (!$site instanceof SiteInterface) {
            $row = $queryBuilder->executeQuery()->fetchAssociative();

            return is_array($row) && is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : null;
        }

        $uid = null;
        foreach ($queryBuilder->executeQuery()->iterateAssociative() as $candidate) {
            if (is_numeric($candidate['pid'] ?? null) && $this->allowsStoragePid((int) $candidate['pid'], $site) && is_numeric($candidate['uid'] ?? null)) {
                if ($uid !== null) {
                    return null;
                }

                $uid = (int) $candidate['uid'];
            }
        }

        return $uid;
    }

    public function ownerStoragePid(int $uid, SiteInterface $site): ?int
    {
        $db  = $this->connectionPool->getConnectionForTable(self::TABLE);
        $pid = $db->select(['pid'], self::TABLE, ['uid' => $uid, 'disable' => 0, 'deleted' => 0])->fetchOne();

        return is_numeric($pid) && $this->allowsStoragePid((int) $pid, $site) ? (int) $pid : null;
    }

    private function allowsStoragePid(int $pid, SiteInterface $site): bool
    {
        if ($pid <= 0 || !$site instanceof Site) {
            return false;
        }

        $folders = SiteConfigurationService::setting($site, 'userStoragePids');
        if ($folders !== null && $folders !== '' && $folders !== []) {
            $values = is_string($folders) ? explode(',', $folders) : $folders;
            if (!is_array($values)) {
                return false;
            }

            foreach ($values as $value) {
                if ((is_int($value) || is_string($value)) && trim((string) $value) === (string) $pid) {
                    return true;
                }
            }

            return false;
        }

        try {
            return GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pid)->getIdentifier() === $site->getIdentifier();
        } catch (SiteNotFoundException) {
            return false;
        }
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
        $row          = $queryBuilder
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
            'uid'      => is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0,
            'username' => is_string($row['username'] ?? null) ? $row['username'] : '',
        ];
    }
}
