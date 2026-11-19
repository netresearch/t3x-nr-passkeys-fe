<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

use function array_map;
use function is_numeric;
use function time;

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
        $row          = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'credential_id',
                    // credential_id is varbinary; bind as BINARY so the value matches
                    // regardless of DB engine. On SQLite a string-bound parameter is a
                    // TEXT storage class and never equals the BLOB the value is stored as.
                    $queryBuilder->createNamedParameter($credentialId, ParameterType::BINARY),
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
        $row          = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'credential_id',
                    // credential_id is varbinary; bind as BINARY so the value matches
                    // regardless of DB engine. On SQLite a string-bound parameter is a
                    // TEXT storage class and never equals the BLOB the value is stored as.
                    $queryBuilder->createNamedParameter($credentialId, ParameterType::BINARY),
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
        $rows         = $queryBuilder
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
                $this->matchesFolder($queryBuilder, $this->ownerFolder($feUserUid)),
            )
            ->orderBy('created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            FrontendCredential::fromArray(...),
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
        $rows         = $queryBuilder
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

        return array_map(
            FrontendCredential::fromArray(...),
            $rows,
        );
    }

    /**
     * Count active (non-revoked) credentials for a frontend user (across all sites).
     */
    public function countByFeUser(int $feUserUid, ?string $siteIdentifier = null): int
    {
        $folder = $siteIdentifier === null ? null : $this->ownerFolder($feUserUid);

        return $this->countActive($feUserUid, $siteIdentifier, $folder);
    }

    private function countActive(int $feUserUid, ?string $siteIdentifier, ?int $folder): int
    {
        $queryBuilder = $this->getQueryBuilder();

        $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'fe_user',
                    $queryBuilder->createNamedParameter($feUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('revoked_at', 0),
            )
        ;
        if ($siteIdentifier !== null) {
            $queryBuilder->andWhere($this->matchesFolder($queryBuilder, $folder));
            $queryBuilder->andWhere($queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)));
        }

        $result = $queryBuilder
            ->executeQuery()
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Insert a new credential record.
     */
    public function save(FrontendCredential $credential): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now        = time();

        $data = $credential->toArray();
        unset($data['uid']);
        $data['tstamp']     = $now;
        $data['crdate']     = $now;
        $data['created_at'] = $now;

        // Bind the binary columns by their schema type so the stored storage class
        // matches what findByCredentialId() queries with (cross-DB; see the note there).
        $connection->insert(
            self::TABLE,
            $data,
            [
                'credential_id'   => ParameterType::BINARY,
                'public_key_cose' => ParameterType::LARGE_OBJECT,
                'user_handle'     => ParameterType::BINARY,
            ],
        );

        $credential->setUid((int) $connection->lastInsertId());
    }

    /**
     * Update the last_used_at timestamp for a credential.
     */
    public function updateLastUsed(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now        = time();
        $connection->update(
            self::TABLE,
            [
                'last_used_at' => $now,
                'tstamp'       => $now,
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
        $now        = time();
        $connection->update(
            self::TABLE,
            [
                'revoked_at' => $now,
                'revoked_by' => $revokedBy,
                'tstamp'     => $now,
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
        $now          = time();

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
        $row          = $queryBuilder
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
                'label'  => $label,
                'tstamp' => time(),
            ],
            ['uid' => $uid],
        );
    }

    /**
     * Revoke an active credential owned by this account on this site.
     */
    public function removeOwned(int $uid, int $ownerUid, bool $protectLast, string $siteIdentifier): bool
    {
        $db = $this->connectionPool->getConnectionForTable(self::TABLE);

        return $db->transactional(function () use ($db, $uid, $ownerUid, $protectLast, $siteIdentifier): bool {
            // Serialize simultaneous removals before deciding which active key remains.
            $lock = $db->createQueryBuilder();
            $lock->update(self::TABLE)->set('revoked_at', $db->quoteIdentifier('revoked_at'), false)
                ->where($lock->expr()->eq('fe_user', $lock->createNamedParameter($ownerUid, ParameterType::INTEGER)))->executeStatement();
            $target = ['uid' => $uid, 'fe_user' => $ownerUid, 'site_identifier' => $siteIdentifier, 'revoked_at' => 0];
            if ($protectLast) {
                $storagePid = $db->select(['storage_pid'], self::TABLE, $target)->fetchOne();
                if (!is_numeric($storagePid)) {
                    return false;
                }

                $folder = $this->ownerFolder($ownerUid);
                if (((int) $storagePid === 0 || (int) $storagePid === $folder)
                    && $this->countActive($ownerUid, $siteIdentifier, $folder) <= 1) {
                    return false;
                }
            }

            $now = time();

            return $db->update(self::TABLE, ['revoked_at' => $now, 'revoked_by' => $ownerUid, 'tstamp' => $now], $target) === 1;
        });
    }

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
        $now        = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'sign_count'   => $signCount,
                'last_used_at' => $now,
                'tstamp'       => $now,
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
                'tstamp'     => time(),
            ],
            ['uid' => $uid],
        );
    }

    private function ownerFolder(int $uid): ?int
    {
        $connection = $this->connectionPool->getConnectionForTable('fe_users');
        $pid        = $connection->select(['pid'], 'fe_users', ['uid' => $uid])->fetchOne();

        return is_numeric($pid) ? (int) $pid : null;
    }

    private function matchesFolder(QueryBuilder $query, ?int $pid): string
    {
        // Legacy keys have storage_pid 0; an absent owner must never make them usable.
        if ($pid === null) {
            return $query->expr()->isNull('fe_user');
        }

        return $query->expr()->in('storage_pid', $query->createNamedParameter([0, $pid], ArrayParameterType::INTEGER));
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE);
    }
}
