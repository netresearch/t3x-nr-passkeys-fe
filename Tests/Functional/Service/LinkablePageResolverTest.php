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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the resolver admits against real pages, judged by core's
 * PageRepository, RootlineUtility and RecordAccessVoter: for the login
 * target (a visitor holding "any login") and for the password page (an
 * anonymous visitor). Fixtures/linkable_pages.csv describes each page.
 */
#[CoversClass(LinkablePageResolver::class)]
final class LinkablePageResolverTest extends AbstractPasskeyFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/linkable_pages.csv');
    }

    /**
     * @return iterable<string, array{int, ?int, ?int}>
     */
    public static function pages(): iterable
    {
        // [page chosen, page linked as login target, page linked as password page]
        yield 'an open page' => [2, 2, 2];
        yield 'an external URL page' => [3, null, null];
        yield 'a folder' => [4, null, null];
        yield 'a spacer' => [5, null, null];
        yield 'a hidden page' => [6, null, null];
        yield 'no page' => [999, null, null];
        yield 'uid 0' => [0, null, null];

        yield 'any login (-2)' => [11, 11, null];
        yield 'hide at login (-1)' => [12, null, 12];
        yield 'a group' => [13, null, null];
        yield 'any login or a group' => [14, 14, null];

        yield 'below a parent extending a group' => [21, null, null];
        yield 'below a parent extending hide at login' => [23, null, 23];
        yield 'below a parent extending any login' => [25, 25, null];
        yield 'below a parent with a group it does not extend' => [27, 27, 27];
        yield 'below a grandparent extending a group' => [30, null, null];
        yield 'below a hidden parent extending to subpages' => [32, null, null];
        yield 'below a parent extending a start time in the future' => [34, null, null];
        yield 'below a parent extending an end time in the past' => [36, null, null];

        yield 'a shortcut to an open page' => [40, 2, 2];
        yield 'a shortcut to an any-login page' => [41, 11, null];
        yield 'a chain of shortcuts to an any-login page' => [42, 11, null];
        yield 'a shortcut to an external URL page' => [44, null, null];
        yield 'a shortcut loop' => [45, null, null];
        yield 'a shortcut to a missing page' => [47, null, null];
        yield 'a shortcut into a tree extending a group' => [48, null, null];
        yield 'a random subpage shortcut' => [50, null, null];
        yield 'a shortcut to a random subpage shortcut' => [52, null, null];
        yield 'a first subpage shortcut whose first subpage is a random one' => [53, null, null];
        // The first subpage the visitor may see, as core picks it.
        yield 'a first subpage shortcut past a subpage for a group' => [60, 62, 62];
        yield 'a parent page shortcut' => [63, 62, 62];
    }

    #[Test]
    #[DataProvider('pages')]
    public function theResolverAdmitsWhatCoreGrantsTheVisitor(int $pageUid, ?int $asLoginTarget, ?int $asPasswordPage): void
    {
        $resolver = $this->get(LinkablePageResolver::class);

        self::assertSame($asLoginTarget, $resolver->resolve($pageUid, true), 'login target');
        self::assertSame($asPasswordPage, $resolver->resolve($pageUid, false), 'password page');
    }
}
