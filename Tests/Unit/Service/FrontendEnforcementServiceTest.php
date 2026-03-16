<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysFe\Event\EnforcementLevelResolvedEvent;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

#[CoversClass(FrontendEnforcementService::class)]
final class FrontendEnforcementServiceTest extends TestCase
{
    private SiteConfigurationService&MockObject $siteConfigService;
    private FrontendCredentialRepository&MockObject $credentialRepository;
    private RecoveryCodeService&MockObject $recoveryCodeService;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private ConnectionPool&MockObject $connectionPool;
    private SiteInterface&MockObject $site;
    private FrontendEnforcementService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteConfigService = $this->createMock(SiteConfigurationService::class);
        $this->credentialRepository = $this->createMock(FrontendCredentialRepository::class);
        $this->recoveryCodeService = $this->createMock(RecoveryCodeService::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->site = $this->createMock(SiteInterface::class);

        // Default: event dispatcher just passes through
        $this->eventDispatcher->method('dispatch')
            ->willReturnArgument(0);

        $this->subject = new FrontendEnforcementService(
            $this->siteConfigService,
            $this->credentialRepository,
            $this->recoveryCodeService,
            $this->eventDispatcher,
            $this->connectionPool,
        );
    }

    // ---------------------------------------------------------------
    // getStatus() — basic enforcement resolution
    // ---------------------------------------------------------------

    #[Test]
    public function getStatusReturnsOffWhenSiteAndGroupAreOff(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('off');
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->recoveryCodeService->method('countRemaining')->willReturn(0);

        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '', 'passkey_grace_period_start' => 0],
        );

        $status = $this->subject->getStatus(1, 'main', $this->site);

        self::assertSame('off', $status->effectiveLevel);
        self::assertSame('off', $status->siteLevel);
        self::assertSame('off', $status->groupLevel);
        self::assertSame(0, $status->passkeyCount);
    }

    #[Test]
    public function getStatusReturnsSiteLevelWhenStricterThanGroup(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('required');
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->recoveryCodeService->method('countRemaining')->willReturn(0);

        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '5', 'passkey_grace_period_start' => 0],
            [['uid' => 5, 'passkey_enforcement' => 'encourage', 'passkey_grace_period_days' => 14]],
        );

        $status = $this->subject->getStatus(1, 'main', $this->site);

        self::assertSame('required', $status->effectiveLevel);
        self::assertSame('required', $status->siteLevel);
        self::assertSame('encourage', $status->groupLevel);
    }

    #[Test]
    public function getStatusReturnsGroupLevelWhenStricterThanSite(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('off');
        $this->credentialRepository->method('countByFeUser')->willReturn(2);
        $this->recoveryCodeService->method('countRemaining')->willReturn(5);

        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '3', 'passkey_grace_period_start' => 0],
            [['uid' => 3, 'passkey_enforcement' => 'enforced', 'passkey_grace_period_days' => 0]],
        );

        $status = $this->subject->getStatus(1, 'main', $this->site);

        self::assertSame('enforced', $status->effectiveLevel);
        self::assertSame('off', $status->siteLevel);
        self::assertSame('enforced', $status->groupLevel);
        self::assertSame(2, $status->passkeyCount);
        self::assertSame(5, $status->recoveryCodesRemaining);
    }

    #[Test]
    public function getStatusPicksStrictestGroupWhenMultiple(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('off');
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->recoveryCodeService->method('countRemaining')->willReturn(0);

        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '1,2,3', 'passkey_grace_period_start' => 0],
            [
                ['uid' => 1, 'passkey_enforcement' => 'off', 'passkey_grace_period_days' => 0],
                ['uid' => 2, 'passkey_enforcement' => 'encourage', 'passkey_grace_period_days' => 30],
                ['uid' => 3, 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 7],
            ],
        );

        $status = $this->subject->getStatus(1, 'main', $this->site);

        self::assertSame('required', $status->effectiveLevel);
        self::assertSame('required', $status->groupLevel);
    }

    #[Test]
    public function getStatusEnforcedLevelHasNoGracePeriod(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('off');
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->recoveryCodeService->method('countRemaining')->willReturn(0);

        $gracePeriodStart = \time() - 3600; // Started 1 hour ago
        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '3', 'passkey_grace_period_start' => $gracePeriodStart],
            [['uid' => 3, 'passkey_enforcement' => 'enforced', 'passkey_grace_period_days' => 14]],
        );

        $status = $this->subject->getStatus(1, 'main', $this->site);

        self::assertSame('enforced', $status->effectiveLevel);
        self::assertFalse($status->inGracePeriod);
        self::assertNull($status->graceDeadline);
    }

    // ---------------------------------------------------------------
    // getStatus() — grace period calculation
    // ---------------------------------------------------------------

    #[Test]
    public function getStatusDetectsActiveGracePeriod(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('off');
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->recoveryCodeService->method('countRemaining')->willReturn(0);

        $gracePeriodStart = \time() - 3600; // Started 1 hour ago
        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '3', 'passkey_grace_period_start' => $gracePeriodStart],
            [['uid' => 3, 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 14]],
        );

        $status = $this->subject->getStatus(1, 'main', $this->site);

        self::assertTrue($status->inGracePeriod);
        self::assertNotNull($status->graceDeadline);
    }

    #[Test]
    public function getStatusDetectsExpiredGracePeriod(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('off');
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->recoveryCodeService->method('countRemaining')->willReturn(0);

        $gracePeriodStart = \time() - (30 * 86400); // Started 30 days ago
        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '3', 'passkey_grace_period_start' => $gracePeriodStart],
            [['uid' => 3, 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 14]],
        );

        $status = $this->subject->getStatus(1, 'main', $this->site);

        self::assertFalse($status->inGracePeriod);
        self::assertNull($status->graceDeadline);
    }

    #[Test]
    public function getStatusNoGracePeriodWhenNotStarted(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('off');
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->recoveryCodeService->method('countRemaining')->willReturn(0);

        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '3', 'passkey_grace_period_start' => 0],
            [['uid' => 3, 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 14]],
        );

        $status = $this->subject->getStatus(1, 'main', $this->site);

        self::assertFalse($status->inGracePeriod);
        self::assertNull($status->graceDeadline);
    }

    // ---------------------------------------------------------------
    // getStatus() — event dispatch
    // ---------------------------------------------------------------

    #[Test]
    public function getStatusDispatchesEnforcementLevelResolvedEvent(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('required');
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->recoveryCodeService->method('countRemaining')->willReturn(0);

        $this->setupDbQueries(
            ['uid' => 42, 'usergroup' => '', 'passkey_grace_period_start' => 0],
        );

        $this->eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (EnforcementLevelResolvedEvent $event): bool {
                return $event->feUserUid === 42
                    && $event->getEffectiveLevel() === 'required';
            }))
            ->willReturnArgument(0);

        $this->subject->getStatus(42, 'main', $this->site);
    }

    #[Test]
    public function getStatusUsesOverriddenLevelFromEvent(): void
    {
        // Create a separate service instance with a custom event dispatcher
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')
            ->willReturnCallback(static function (EnforcementLevelResolvedEvent $event): EnforcementLevelResolvedEvent {
                $event->setEffectiveLevel('off');

                return $event;
            });

        $subject = new FrontendEnforcementService(
            $this->siteConfigService,
            $this->credentialRepository,
            $this->recoveryCodeService,
            $eventDispatcher,
            $this->connectionPool,
        );

        $this->siteConfigService->method('getEnforcementLevel')->willReturn('required');
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->recoveryCodeService->method('countRemaining')->willReturn(0);

        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '', 'passkey_grace_period_start' => 0],
        );

        $status = $subject->getStatus(1, 'main', $this->site);

        self::assertSame('off', $status->effectiveLevel);
    }

    // ---------------------------------------------------------------
    // getStatus() — user with no groups
    // ---------------------------------------------------------------

    #[Test]
    public function getStatusHandlesUserWithNoGroups(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('encourage');
        $this->credentialRepository->method('countByFeUser')->willReturn(1);
        $this->recoveryCodeService->method('countRemaining')->willReturn(10);

        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '', 'passkey_grace_period_start' => 0],
        );

        $status = $this->subject->getStatus(1, 'main', $this->site);

        self::assertSame('encourage', $status->effectiveLevel);
        self::assertSame('off', $status->groupLevel);
        self::assertSame(1, $status->passkeyCount);
        self::assertSame(10, $status->recoveryCodesRemaining);
    }

    // ---------------------------------------------------------------
    // startGracePeriod()
    // ---------------------------------------------------------------

    #[Test]
    public function startGracePeriodUpdatesFeUsersTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'fe_users',
                self::callback(static function (array $data): bool {
                    return isset($data['passkey_grace_period_start'])
                        && $data['passkey_grace_period_start'] > 0;
                }),
                ['uid' => 42],
            );

        $this->connectionPool->method('getConnectionForTable')
            ->with('fe_users')
            ->willReturn($connection);

        $this->subject->startGracePeriod(42);
    }

    // ---------------------------------------------------------------
    // Same severity picks shortest grace days
    // ---------------------------------------------------------------

    #[Test]
    public function getStatusPicksShortestGraceDaysAtSameSeverity(): void
    {
        $this->siteConfigService->method('getEnforcementLevel')->willReturn('off');
        $this->credentialRepository->method('countByFeUser')->willReturn(0);
        $this->recoveryCodeService->method('countRemaining')->willReturn(0);

        $gracePeriodStart = \time() - 3600; // 1 hour ago
        $this->setupDbQueries(
            ['uid' => 1, 'usergroup' => '1,2', 'passkey_grace_period_start' => $gracePeriodStart],
            [
                ['uid' => 1, 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 30],
                ['uid' => 2, 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 7],
            ],
        );

        $status = $this->subject->getStatus(1, 'main', $this->site);

        // Both groups are 'required' — should pick the shorter grace period (7 days)
        self::assertSame('required', $status->effectiveLevel);
        // The grace period should still be active (started 1h ago, deadline in 7 days)
        self::assertTrue($status->inGracePeriod);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Set up database queries for fe_users and optionally fe_groups.
     *
     * @param array<string, mixed>            $userRow fe_users record
     * @param list<array<string, mixed>>|null $groups  fe_groups records (null = no groups query expected)
     */
    private function setupDbQueries(array $userRow, ?array $groups = null): void
    {
        $feUserResult = $this->createMock(Result::class);
        $feUserResult->method('fetchAssociative')->willReturn($userRow);
        $feUserQb = $this->createQueryBuilderMock($feUserResult);

        if ($groups !== null) {
            $groupResult = $this->createMock(Result::class);
            $groupResult->method('fetchAllAssociative')->willReturn($groups);
            $groupQb = $this->createQueryBuilderMock($groupResult);

            $this->connectionPool->method('getQueryBuilderForTable')
                ->willReturnCallback(
                    static function (string $table) use ($feUserQb, $groupQb): QueryBuilder {
                        return match ($table) {
                            'fe_users' => $feUserQb,
                            'fe_groups' => $groupQb,
                            default => throw new RuntimeException('Unexpected table: ' . $table),
                        };
                    },
                );
        } else {
            $this->connectionPool->method('getQueryBuilderForTable')
                ->willReturn($feUserQb);
        }
    }

    private function createQueryBuilderMock(?Result $result = null): QueryBuilder&MockObject
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');
        $expressionBuilder->method('in')->willReturn('');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn('?');

        if ($result !== null) {
            $queryBuilder->method('executeQuery')->willReturn($result);
        }

        return $queryBuilder;
    }
}
