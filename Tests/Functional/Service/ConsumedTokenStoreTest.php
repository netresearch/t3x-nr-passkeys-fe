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
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ConsumedTokenStoreTest extends AbstractPasskeyFunctionalTestCase
{
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
