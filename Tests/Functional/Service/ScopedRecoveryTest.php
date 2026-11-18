<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Service;

use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Entity\Site;

#[CoversClass(RecoveryCodeService::class)]
final class ScopedRecoveryTest extends AbstractPasskeyFunctionalTestCase
{
    #[Test]
    public function rotatingOneSitePreservesTheOtherSitesCodes(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $codes = $this->get(RecoveryCodeService::class);
        $a = new Site('a', 1, ['base' => 'https://a.example/']);
        $b = new Site('b', 2, ['base' => 'https://b.example/']);
        [$first] = $codes->generate(1, 1, $a);
        [$second] = $codes->generate(1, 1, $b);
        self::assertFalse($codes->verify(1, $first, $b));
        self::assertSame(1, $codes->countRemaining(1, $a));
        self::assertTrue($codes->verify(1, $second, $b));
        self::assertFalse($codes->verify(1, $second, $b));
        self::assertTrue($codes->verify(1, $first, $a));
    }
}
