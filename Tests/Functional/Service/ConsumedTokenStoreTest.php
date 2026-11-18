<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Service;

use Netresearch\NrPasskeysFe\Service\ConsumedTokenStore;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ConsumedTokenStoreTest extends AbstractPasskeyFunctionalTestCase
{
    #[Test]
    public function claimFinishingAtTheDeadlineIsRejectedAndRemainsConsumed(): void
    {
        $clock      = new MockClock('2026-01-01 10:00:00 UTC');
        $expiresAt  = $clock->now()->getTimestamp() + 1;
        $pool       = $this->get(ConnectionPool::class);
        $database   = $pool->getConnectionForTable('tx_nrpasskeysfe_consumed_token');
        $slowWriter = $this->createMock(Connection::class);
        $slowWriter->expects(self::once())->method('insert')->willReturnCallback(
            static function (string $table, array $values) use ($database, $clock): int {
                $clock->sleep(1);

                return (int) $database->insert($table, $values);
            },
        );
        $worker = $this->createStub(ConnectionPool::class);
        $worker->method('getConnectionForTable')->willReturn($slowWriter);
        $worker->method('getQueryBuilderForTable')->willReturnCallback($pool->getQueryBuilderForTable(...));
        self::assertFalse((new ConsumedTokenStore($worker, $clock))->claim('login:deadline', $expiresAt));
        self::assertSame(1, (int) $database->count('*', 'tx_nrpasskeysfe_consumed_token', ['token_hash' => hash('sha256', 'login:deadline')]));
    }

    #[Test]
    public function pruningRetainsLiveAndRecentlyExpiredClaims(): void
    {
        $pool       = $this->get(ConnectionPool::class);
        $table      = 'tx_nrpasskeysfe_consumed_token';
        $connection = $pool->getConnectionForTable($table);
        $now        = time();
        foreach (['old' => -7200, 'recent' => -60, 'active' => 120] as $key => $offset) {
            $connection->insert($table, ['token_hash' => hash('sha256', $key), 'expires_at' => $now + $offset]);
        }

        $store = new ConsumedTokenStore($pool);
        $store->pruneExpired();
        self::assertSame(0, (int) $connection->count('*', $table, ['token_hash' => hash('sha256', 'old')]));
        self::assertSame(1, (int) $connection->count('*', $table, ['token_hash' => hash('sha256', 'recent')]));
        self::assertFalse($store->claim('active', $now + 120));
    }

    #[Test]
    public function claimsAreSharedAndExpiredTicketsDoNotReserveAKey(): void
    {
        $pool      = $this->get(ConnectionPool::class);
        $first     = new ConsumedTokenStore($pool);
        $second    = new ConsumedTokenStore($pool);
        $expiresAt = time() + 120;
        self::assertFalse($first->claim('expired-ticket', time() - 1));
        self::assertTrue($first->claim('valid-ticket', $expiresAt));
        self::assertFalse($second->claim('valid-ticket', $expiresAt));
        $table = 'tx_nrpasskeysfe_consumed_token';
        $db    = $pool->getConnectionForTable($table);
        self::assertSame(1, (int) $db->count('*', $table, []));
        self::assertSame(hash('sha256', 'valid-ticket'), $db->select(['token_hash'], $table, [])->fetchOne());
    }
}
