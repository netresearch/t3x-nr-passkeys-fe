<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Controller;

use Netresearch\NrPasskeysFe\Controller\AdminController;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * "Reset grace period" against a real fe_users row: the start goes back to
 * 0, so the interstitial starts a new grace period on the next request.
 */
#[CoversClass(AdminController::class)]
final class AdminControllerTest extends AbstractPasskeyFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/grace_reset.csv');
    }

    #[Test]
    public function anAdminResetsTheGracePeriodOfAUser(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->get(AdminController::class)->resetGracePeriodAction($this->resetRequest(1));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->graceStartOf(1));
    }

    #[Test]
    public function anEditorCannotResetAGracePeriod(): void
    {
        $this->setUpBackendUser(2);

        $response = $this->get(AdminController::class)->resetGracePeriodAction($this->resetRequest(1));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(1700000000, $this->graceStartOf(1));
    }

    #[Test]
    public function anUnknownUserIsNotFound(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->get(AdminController::class)->resetGracePeriodAction($this->resetRequest(99));

        self::assertSame(404, $response->getStatusCode());
    }

    private function resetRequest(int $feUserUid): ServerRequest
    {
        return (new ServerRequest('https://example.com/typo3/ajax/nr-passkeys-fe/admin/reset-grace-period', 'POST'))
            ->withParsedBody(['feUserUid' => $feUserUid]);
    }

    private function graceStartOf(int $feUserUid): int
    {
        $value = $this->get(ConnectionPool::class)->getConnectionForTable('fe_users')
            ->select(['passkey_grace_period_start'], 'fe_users', ['uid' => $feUserUid])
            ->fetchOne();

        return \is_numeric($value) ? (int) $value : -1;
    }
}
