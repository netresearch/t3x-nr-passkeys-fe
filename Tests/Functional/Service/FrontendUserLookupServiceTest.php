<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Service;

use Netresearch\NrPasskeysFe\Service\FrontendUserLookupService;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

#[CoversClass(FrontendUserLookupService::class)]
final class FrontendUserLookupServiceTest extends AbstractPasskeyFunctionalTestCase
{
    #[Test]
    public function onlyOneActiveAccountInAnAllowedFolderCanBeSelected(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $db = $this->getConnectionPool()->getConnectionForTable('fe_users');
        $db->update('fe_users', ['username' => 'testuser1'], ['uid' => 3]);

        $lookup = new FrontendUserLookupService($this->getConnectionPool());
        $origin = ['base' => 'https://portal.example/'];
        $first  = new Site('first', 100, $origin, SiteSettings::createFromSettingsTree(['nr_passkeys_fe' => ['userStoragePids' => '1']]));
        $second = new Site('second', 200, $origin, SiteSettings::createFromSettingsTree(['nr_passkeys_fe' => ['userStoragePids' => [2]]]));
        $shared = new Site('shared', 300, $origin, SiteSettings::createFromSettingsTree(['nr_passkeys_fe' => ['userStoragePids' => '1, 2']]));
        self::assertSame(1, $lookup->findFeUserUidByUsername('testuser1', $first));
        self::assertSame(3, $lookup->findFeUserUidByUsername('testuser1', $second));
        self::assertNull($lookup->findFeUserUidByUsername('testuser1', $shared));
        self::assertSame(1, $lookup->ownerStoragePid(1, $first));
        self::assertNull($lookup->ownerStoragePid(1, $second));
        self::assertNull($lookup->ownerStoragePid(20, $first));
        self::assertNull($lookup->findFeUserUidByUsername('disableduser', $first));
        $db->update('fe_users', ['disable' => 1], ['uid' => 1]);
        self::assertSame(3, $lookup->findFeUserUidByUsername('testuser1', $shared));
    }
}
