<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Doctrine\DBAL\Exception as DatabaseException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** The primary key arbitrates single-use claims across application nodes. */
final readonly class ConsumedTokenStore
{
    private const TABLE = 'tx_nrpasskeysfe_consumed_token';

    public function __construct(
        private ConnectionPool $connectionPool,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function claim(string $key, int $expiresAt): bool
    {
        $now = $this->clock->now()->getTimestamp();
        if ($expiresAt <= $now) {
            return false;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        if (random_int(1, 64) === 1) {
            try {
                $this->pruneExpired();
            } catch (DatabaseException $error) {
                $this->logger->warning('Passkey token cleanup failed.', ['exception' => $error::class]);
            }
        }

        try {
            return $connection->insert(self::TABLE, ['token_hash' => hash('sha256', $key), 'expires_at' => $expiresAt]) === 1 && $this->clock->now()->getTimestamp() < $expiresAt;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** Retain recently expired claims for an hour to tolerate clock differences between nodes. */
    public function pruneExpired(): void
    {
        $query = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $query->delete(self::TABLE)
            ->where($query->expr()->lte('expires_at', $query->createNamedParameter($this->clock->now()->getTimestamp() - 3600, ParameterType::INTEGER)))
            ->executeStatement();
    }
}
