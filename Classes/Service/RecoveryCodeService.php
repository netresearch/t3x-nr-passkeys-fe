<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

use function count;
use function is_numeric;
use function is_string;
use function password_hash;
use function password_verify;
use function random_int;
use function str_replace;
use function strlen;
use function strtoupper;
use function substr;
use function time;
use function trim;

/**
 * Generates, stores, and verifies single-use recovery codes for fe_users.
 *
 * Codes use a 30-character alphabet that excludes ambiguous glyphs
 * (0/O, 1/I/L) for human readability. Stored as bcrypt hashes.
 */
final readonly class RecoveryCodeService
{
    private const TABLE = 'tx_nrpasskeysfe_recovery_code';

    /**
     * 30-character alphabet excluding 0/O, 1/I/L.
     */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const CODE_LENGTH = 8;

    private const BCRYPT_COST = 12;

    private const MAX_CODES = 10;

    /**
     * Pre-computed bcrypt hash used for constant-time padding. When no matching
     * code exists for a user, we still run `password_verify()` against this
     * dummy hash so the verification path takes the same wall-clock time as a
     * real lookup — defeating timing-based user/code enumeration.
     *
     * The plaintext that produced this hash is irrelevant and unknown; the
     * value is not associated with any user account or password and exposes
     * no credential. SAST tooling flagging this as "password hash disclosure"
     * is a false positive on the constant-time-padding pattern.
     */
    // nosemgrep: secrets:S8215, generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash
    private const DUMMY_HASH = '$2y$12$000000000000000000000uGBbLJHBfROXxjMI.RxFKYbIpkYl/6Gy';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Generate a set of recovery codes for a frontend user.
     *
     * Deletes any existing codes first, then creates new ones.
     * Returns the plaintext codes (XXXX-XXXX format) — these are
     * shown to the user exactly once and never stored in plaintext.
     *
     * @return list<string> Plaintext codes in XXXX-XXXX format
     */
    public function generate(int $feUserUid, int $count = 10): array
    {
        if ($count === 0) { return []; }
        if ($feUserUid <= 0 || $count < 0) {
            throw new InvalidArgumentException('A valid owner and at least one code are required');
        }
        $now            = time();
        $plaintextCodes = [];
        $rows           = [];
        for ($i = 0; $i < $count; ++$i) {
            $rawCode = $this->generateRandomCode();
            $rows[]  = [
                'fe_user'    => $feUserUid,
                'code_hash'  => password_hash($rawCode, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
                'used_at'    => 0,
                'created_at' => $now,
            ];
            $plaintextCodes[] = $this->formatCode($rawCode);
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        if ($connection !== $this->connectionPool->getConnectionForTable('fe_users')) {
            throw new RuntimeException('Recovery codes and their owner must use the same database connection.');
        }
        $connection->beginTransaction();
        try {
            // A stable owner row serializes rotations even when the old set is empty.
            $connection->update('fe_users', ['uid' => $feUserUid], ['uid' => $feUserUid]);
            if ($connection->select(['uid'], 'fe_users', ['uid' => $feUserUid, 'deleted' => 0])->fetchOne() === false) {
                throw new RuntimeException('Recovery code owner is unavailable');
            }
            $connection->delete(self::TABLE, ['fe_user' => $feUserUid]);
            foreach ($rows as $row) {
                $connection->insert(self::TABLE, $row);
            }
            $connection->commit();
        } catch (Throwable $error) {
            $connection->rollBack();
            throw $error;
        }

        return $plaintextCodes;
    }

    /**
     * Verify a recovery code for a frontend user.
     *
     * Iterates all unused codes and uses password_verify for comparison.
     * If a match is found, the code is marked as used.
     *
     * The input code is normalised: dashes are stripped and it is upper-cased.
     *
     * To prevent timing oracles that leak whether a user has codes,
     * dummy password_verify() calls pad the total to MAX_CODES.
     */
    public function verify(int $feUserUid, string $code): bool
    {
        $normalised = strtoupper(str_replace('-', '', trim($code)));

        if (strlen($normalised) !== self::CODE_LENGTH) {
            return false;
        }

        $queryBuilder = $this->getQueryBuilder();
        $rows         = $queryBuilder
            ->select('uid', 'code_hash')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'fe_user',
                    $queryBuilder->createNamedParameter($feUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('used_at', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $matched    = false;
        $matchedUid = 0;
        $realCount  = count($rows);

        foreach ($rows as $row) {
            $hash = is_string($row['code_hash'] ?? null) ? $row['code_hash'] : '';

            if (password_verify($normalised, $hash) && !$matched) {
                $matched    = true;
                $matchedUid = (int) ($row['uid'] ?? 0);
            }
        }

        // Pad with dummy bcrypt ops so total is always MAX_CODES
        for ($i = $realCount; $i < self::MAX_CODES; ++$i) {
            password_verify($normalised, self::DUMMY_HASH);
        }

        if ($matched && $feUserUid > 0) {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

            return $connection->update(
                self::TABLE,
                ['used_at' => time()],
                ['uid' => $matchedUid, 'fe_user' => $feUserUid, 'used_at' => 0],
            ) === 1;
        }

        return false;
    }

    /**
     * Count remaining (unused) recovery codes for a frontend user.
     */
    public function countRemaining(int $feUserUid): int
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
                $queryBuilder->expr()->eq('used_at', 0),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Generate a random code using the restricted alphabet.
     */
    private function generateRandomCode(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $code           = '';

        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }

    /**
     * Format a raw 8-character code as XXXX-XXXX.
     */
    private function formatCode(string $raw): string
    {
        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE);
    }
}
