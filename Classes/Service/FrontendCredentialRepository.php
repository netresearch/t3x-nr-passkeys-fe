<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Doctrine\DBAL\ParameterType;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Repository for frontend passkey credentials.
 *
 * Wraps TYPO3 ConnectionPool/QueryBuilder to provide typed access
 * to the tx_nrpasskeysfe_credential table. Mirrors the BE
 * CredentialRepository but operates on fe_users and includes
 * site/storage scoping.
 */
final readonly class FrontendCredentialRepository
{
    private const TABLE = 'tx_nrpasskeysfe_credential';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Find a credential by its WebAuthn credential ID (global, unscoped).
     *
     * Used for discoverable login where the credential ID alone
     * identifies the user.
     */
    public function findByCredentialId(string $credentialId): ?FrontendCredential
    {
        $queryBuilder = $this->getQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'credential_id',
                    $queryBuilder->createNamedParameter($credentialId),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return FrontendCredential::fromArray($row);
    }

    /**
     * Find a credential by its WebAuthn credential ID, scoped to a storage PID and site.
     *
     * Used when the login context (site, storage folder) is known.
     */
    public function findByCredentialIdScoped(
        string $credentialId,
        int $storagePid,
        string $siteIdentifier,
    ): ?FrontendCredential {
        $queryBuilder = $this->getQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'credential_id',
                    $queryBuilder->createNamedParameter($credentialId),
                ),
                $queryBuilder->expr()->eq(
                    'storage_pid',
                    $queryBuilder->createNamedParameter($storagePid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return FrontendCredential::fromArray($row);
    }

    /**
     * Find all active (non-revoked) credentials for a frontend user on a given site.
     *
     * @return list<FrontendCredential>
     */
    public function findByFeUser(int $feUserUid, string $siteIdentifier): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'fe_user',
                    $queryBuilder->createNamedParameter($feUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier),
                ),
                $queryBuilder->expr()->eq('revoked_at', 0),
            )
            ->orderBy('created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return \array_map(
            static fn(array $row): FrontendCredential => FrontendCredential::fromArray($row),
            $rows,
        );
    }

    /**
     * Find all credentials (including revoked) for a frontend user, across all sites.
     *
     * Used by backend FormEngine elements that need a complete credential history.
     *
     * @return list<FrontendCredential>
     */
    public function findAllByFeUser(int $feUserUid): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'fe_user',
                    $queryBuilder->createNamedParameter($feUserUid, ParameterType::INTEGER),
                ),
            )
            ->orderBy('created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return \array_map(
            static fn(array $row): FrontendCredential => FrontendCredential::fromArray($row),
            $rows,
        );
    }

    /**
     * Count active (non-revoked) credentials for a frontend user (across all sites).
     */
    public function countByFeUser(int $feUserUid): int
    {
        $queryBuilder = $this->getQueryBuilder();

        $result = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'fe_user',
                    $queryBuilder->createNamedParameter($feUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('revoked_at', 0),
            )
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Insert a new credential record.
     */
    public function save(FrontendCredential $credential): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = \time();

        $data = $credential->toArray();
        unset($data['uid']);
        $data['tstamp'] = $now;
        $data['crdate'] = $now;
        $data['created_at'] = $now;

        $connection->insert(self::TABLE, $data);

        $credential->setUid((int) $connection->lastInsertId());
    }

    /**
     * Update the last_used_at timestamp for a credential.
     */
    public function updateLastUsed(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = \time();
        $connection->update(
            self::TABLE,
            [
                'last_used_at' => $now,
                'tstamp' => $now,
            ],
            ['uid' => $uid],
        );
    }

    /**
     * Revoke a single credential.
     */
    public function revoke(int $uid, int $revokedBy): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = \time();
        $connection->update(
            self::TABLE,
            [
                'revoked_at' => $now,
                'revoked_by' => $revokedBy,
                'tstamp' => $now,
            ],
            ['uid' => $uid],
        );
    }

    /**
     * Revoke all credentials for a frontend user.
     *
     * @return int Number of affected (revoked) rows
     */
    public function revokeAllByFeUser(int $feUserUid, int $revokedBy): int
    {
        $queryBuilder = $this->getQueryBuilder();
        $now = \time();

        return $queryBuilder
            ->update(self::TABLE)
            ->set('revoked_at', $now)
            ->set('revoked_by', $revokedBy)
            ->set('tstamp', $now)
            ->where(
                $queryBuilder->expr()->eq(
                    'fe_user',
                    $queryBuilder->createNamedParameter($feUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('revoked_at', 0),
            )
            ->executeStatement();
    }

    /**
     * Find a single credential by UID, verifying it belongs to the given fe_user.
     */
    public function findByUidAndFeUser(int $uid, int $feUserUid): ?FrontendCredential
    {
        $queryBuilder = $this->getQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'fe_user',
                    $queryBuilder->createNamedParameter($feUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('revoked_at', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return FrontendCredential::fromArray($row);
    }

    /**
     * Update the label of a credential.
     */
    public function updateLabel(int $uid, string $label): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'label' => $label,
                'tstamp' => \time(),
            ],
            ['uid' => $uid],
        );
    }

    /**
     * Hard-delete a credential record by UID.
     */
    public function delete(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->delete(self::TABLE, ['uid' => $uid]);
    }

    /**
     * Update sign count and last-used timestamp after a successful assertion.
     *
     * Combines the formerly separate updateSignCount + updateLastUsed into a
     * single DB round-trip.
     */
    public function updateAfterAssertion(int $uid, int $signCount): void
    {
        $now = \time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'sign_count' => $signCount,
                'last_used_at' => $now,
                'tstamp' => $now,
            ],
            ['uid' => $uid],
        );
    }

    /**
     * Update the sign count after a successful assertion.
     */
    public function updateSignCount(int $uid, int $newCount): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'sign_count' => $newCount,
                'tstamp' => \time(),
            ],
            ['uid' => $uid],
        );
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE);
    }
}
