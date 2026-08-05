<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\Stub;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * A QueryBuilder stub for services that only read through the fluent API.
 *
 * The chainable methods return the builder itself and the expression methods
 * return an empty fragment, so a service can build its query without the stub
 * asserting anything about it. Tests that need to assert on the query belong
 * with a mock and its expectations instead, not here.
 *
 * FrontendAdoptionStatsServiceTest keeps its own builder: it asserts that
 * count() receives a plain column identifier, which is a guard this stub
 * deliberately does not carry.
 */
trait QueryBuilderStubTrait
{
    private function createQueryBuilderStub(?Result $result = null): QueryBuilder&Stub
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');
        $expressionBuilder->method('in')->willReturn('');

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn('?');

        if ($result instanceof Result) {
            $queryBuilder->method('executeQuery')->willReturn($result);
        }

        return $queryBuilder;
    }
}
