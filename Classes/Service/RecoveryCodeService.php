<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

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
     * Pre-computed bcrypt hash used for constant-time padding.
     * The actual plaintext is irrelevant; it just needs to be a valid bcrypt hash.
     */
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
        // Delete all existing codes for this user
        $this->deleteAllForUser($feUserUid);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = \time();
        $plaintextCodes = [];

        for ($i = 0; $i < $count; $i++) {
            $rawCode = $this->generateRandomCode();
            $formattedCode = $this->formatCode($rawCode);
            $hash = \password_hash($rawCode, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

            $connection->insert(self::TABLE, [
                'fe_user' => $feUserUid,
                'code_hash' => $hash,
                'used_at' => 0,
                'created_at' => $now,
            ]);

            $plaintextCodes[] = $formattedCode;
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
        $normalised = \strtoupper(\str_replace('-', '', \trim($code)));

        if (\strlen($normalised) !== self::CODE_LENGTH) {
            // Perform dummy bcrypt ops to prevent length-based timing leak
            for ($i = 0; $i < self::MAX_CODES; $i++) {
                \password_verify($normalised, self::DUMMY_HASH);
            }

            return false;
        }

        $queryBuilder = $this->getQueryBuilder();
        $rows = $queryBuilder
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

        $matched = false;
        $matchedUid = 0;
        $realCount = \count($rows);

        foreach ($rows as $row) {
            $hash = \is_string($row['code_hash'] ?? null) ? $row['code_hash'] : '';

            if (!$matched && \password_verify($normalised, $hash)) {
                $matched = true;
                $matchedUid = (int) ($row['uid'] ?? 0);
            }
        }

        // Pad with dummy bcrypt ops so total is always MAX_CODES
        for ($i = $realCount; $i < self::MAX_CODES; $i++) {
            \password_verify($normalised, self::DUMMY_HASH);
        }

        if ($matched) {
            $this->markUsed($matchedUid);

            return true;
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

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Generate a random code using the restricted alphabet.
     */
    private function generateRandomCode(): string
    {
        $alphabetLength = \strlen(self::ALPHABET);
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[\random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }

    /**
     * Format a raw 8-character code as XXXX-XXXX.
     */
    private function formatCode(string $raw): string
    {
        return \substr($raw, 0, 4) . '-' . \substr($raw, 4, 4);
    }

    private function deleteAllForUser(int $feUserUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->delete(self::TABLE, ['fe_user' => $feUserUid]);
    }

    private function markUsed(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            ['used_at' => \time()],
            ['uid' => $uid],
        );
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE);
    }
}
