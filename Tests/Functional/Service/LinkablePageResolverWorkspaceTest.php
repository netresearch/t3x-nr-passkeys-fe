<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Service;

use Netresearch\NrPasskeysFe\Service\LinkablePageResolver;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;

/**
 * An ancestor is judged in the workspace the page is rendered in: in a
 * workspace preview, the workspace version of a parent counts.
 */
#[CoversClass(LinkablePageResolver::class)]
final class LinkablePageResolverWorkspaceTest extends AbstractPasskeyFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/linkable_pages_workspace.csv');
    }

    #[Test]
    public function liveTheChildOfAnOpenParentIsLinked(): void
    {
        self::assertSame(101, $this->get(LinkablePageResolver::class)->resolve(101, true));
    }

    #[Test]
    public function inTheWorkspaceTheParentVersionExtendingAGroupRefusesTheChild(): void
    {
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));

        self::assertNull($this->get(LinkablePageResolver::class)->resolve(101, true));
    }
}
