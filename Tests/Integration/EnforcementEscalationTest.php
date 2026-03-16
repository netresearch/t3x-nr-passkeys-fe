<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Integration;

use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Integration tests for enforcement escalation with real database.
 *
 * Verifies "strictest wins" policy across groups, and site+group combinations.
 */
#[CoversNothing]
final class EnforcementEscalationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-passkeys-be',
        'netresearch/nr-passkeys-fe',
    ];

    private FrontendEnforcementService $enforcementService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');
        $this->enforcementService = $this->get(FrontendEnforcementService::class);
    }

    // ---------------------------------------------------------------
    // Single group escalation
    // ---------------------------------------------------------------

    #[Test]
    public function statusIsOffWhenGroupEnforcementIsOff(): void
    {
        $this->createGroup(10, 'off', 0);
        $this->assignGroupToUser(1, [10]);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        self::assertSame('off', $status->effectiveLevel);
        self::assertSame('off', $status->groupLevel);
    }

    #[Test]
    public function statusIsEncourageWhenGroupEnforcementIsEncourage(): void
    {
        $this->createGroup(20, 'encourage', 0);
        $this->assignGroupToUser(1, [20]);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        self::assertSame('encourage', $status->effectiveLevel);
        self::assertSame('encourage', $status->groupLevel);
    }

    #[Test]
    public function statusIsRequiredWhenGroupEnforcementIsRequired(): void
    {
        $this->createGroup(30, 'required', 14);
        $this->assignGroupToUser(1, [30]);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        self::assertSame('required', $status->effectiveLevel);
        self::assertSame('required', $status->groupLevel);
    }

    #[Test]
    public function statusIsEnforcedWhenGroupEnforcementIsEnforced(): void
    {
        $this->createGroup(40, 'enforced', 0);
        $this->assignGroupToUser(1, [40]);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        self::assertSame('enforced', $status->effectiveLevel);
        self::assertSame('enforced', $status->groupLevel);
    }

    // ---------------------------------------------------------------
    // Grace period calculation
    // ---------------------------------------------------------------

    #[Test]
    public function gracePeriodIsActiveWhenRequiredAndGracePeriodStartIsRecent(): void
    {
        $this->createGroup(50, 'required', 14);
        $this->assignGroupToUser(1, [50]);

        // Set grace period start to now (1 day ago still within 14 days)
        $gracePeriodStart = \time() - (86400 * 1); // 1 day ago
        $this->setGracePeriodStart(1, $gracePeriodStart);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        self::assertSame('required', $status->effectiveLevel);
        self::assertTrue($status->inGracePeriod, 'Grace period must be active when within configured days');
        self::assertNotNull($status->graceDeadline, 'Grace deadline must be set when in grace period');
    }

    #[Test]
    public function gracePeriodIsExpiredWhenStartedLongAgo(): void
    {
        $this->createGroup(51, 'required', 14);
        $this->assignGroupToUser(1, [51]);

        // Set grace period start to 30 days ago (beyond 14-day grace)
        $gracePeriodStart = \time() - (86400 * 30);
        $this->setGracePeriodStart(1, $gracePeriodStart);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        self::assertFalse($status->inGracePeriod, 'Grace period must be expired when beyond configured days');
    }

    #[Test]
    public function gracePeriodIsNotActiveWhenEnforcementIsEnforced(): void
    {
        $this->createGroup(52, 'enforced', 14);
        $this->assignGroupToUser(1, [52]);

        $this->setGracePeriodStart(1, \time() - 86400);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        self::assertSame('enforced', $status->effectiveLevel);
        self::assertFalse($status->inGracePeriod, 'Grace period must not apply when enforcement is "enforced"');
    }

    // ---------------------------------------------------------------
    // Multiple groups: strictest wins
    // ---------------------------------------------------------------

    #[Test]
    public function strictestGroupWinsWhenTwoGroupsHaveDifferentLevels(): void
    {
        $this->createGroup(60, 'encourage', 0);
        $this->createGroup(61, 'required', 14);
        $this->assignGroupToUser(1, [60, 61]);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        self::assertSame('required', $status->effectiveLevel, 'Strictest group level must win');
    }

    #[Test]
    public function strictestGroupWinsAcrossThreeGroups(): void
    {
        $this->createGroup(70, 'off', 0);
        $this->createGroup(71, 'encourage', 0);
        $this->createGroup(72, 'enforced', 0);
        $this->assignGroupToUser(1, [70, 71, 72]);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        self::assertSame('enforced', $status->effectiveLevel, '"enforced" must win over all other levels');
    }

    #[Test]
    public function shortestGracePeriodWinsWhenGroupsHaveSameLevel(): void
    {
        // Two groups both at 'required' but different grace days
        $this->createGroup(80, 'required', 30);
        $this->createGroup(81, 'required', 7);
        $this->assignGroupToUser(1, [80, 81]);

        $this->setGracePeriodStart(1, \time() - (86400 * 1));

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        // With 7-day grace and 1-day elapsed, should be in grace period still
        self::assertSame('required', $status->effectiveLevel);
        self::assertTrue(
            $status->inGracePeriod,
            'Grace period must be active since 1 day elapsed out of 7-day minimum grace',
        );
    }

    // ---------------------------------------------------------------
    // Site + group combination: strictest wins
    // ---------------------------------------------------------------

    #[Test]
    public function siteLevelBeatsGroupLevelWhenSiteIsStricter(): void
    {
        $this->createGroup(90, 'encourage', 0);
        $this->assignGroupToUser(1, [90]);

        // Site configured to 'enforced', group to 'encourage' → site wins
        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('enforced'));

        self::assertSame('enforced', $status->effectiveLevel, 'Site level must win when stricter than group');
        self::assertSame('enforced', $status->siteLevel);
        self::assertSame('encourage', $status->groupLevel);
    }

    #[Test]
    public function groupLevelBeatsSiteLevelWhenGroupIsStricter(): void
    {
        $this->createGroup(91, 'enforced', 0);
        $this->assignGroupToUser(1, [91]);

        // Site configured to 'encourage', group to 'enforced' → group wins
        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('encourage'));

        self::assertSame('enforced', $status->effectiveLevel, 'Group level must win when stricter than site');
        self::assertSame('encourage', $status->siteLevel);
        self::assertSame('enforced', $status->groupLevel);
    }

    #[Test]
    public function siteLevelAloneWithNoGroupsIsRespected(): void
    {
        // User with no group assignments
        $usersConnection = $this->getConnectionPool()->getConnectionForTable('fe_users');
        $usersConnection->update('fe_users', ['usergroup' => ''], ['uid' => 1]);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('required'));

        self::assertSame('required', $status->effectiveLevel, 'Site level must apply when user is in no groups');
        self::assertSame('required', $status->siteLevel);
        self::assertSame('off', $status->groupLevel, 'Group level must default to off when no groups');
    }

    #[Test]
    public function noGroupsAndSiteOffResultsInOff(): void
    {
        $usersConnection = $this->getConnectionPool()->getConnectionForTable('fe_users');
        $usersConnection->update('fe_users', ['usergroup' => ''], ['uid' => 1]);

        $status = $this->enforcementService->getStatus(1, 'site-a', $this->makeSiteWithLevel('off'));

        self::assertSame('off', $status->effectiveLevel);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createGroup(int $uid, string $enforcement, int $graceDays): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('fe_groups');
        $connection->insert('fe_groups', [
            'uid' => $uid,
            'pid' => 1,
            'title' => 'Test Group ' . $uid,
            'passkey_enforcement' => $enforcement,
            'passkey_grace_period_days' => $graceDays,
            'hidden' => 0,
            'deleted' => 0,
        ]);
    }

    /**
     * @param list<int> $groupUids
     */
    private function assignGroupToUser(int $feUserUid, array $groupUids): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('fe_users');
        $connection->update(
            'fe_users',
            ['usergroup' => \implode(',', $groupUids)],
            ['uid' => $feUserUid],
        );
    }

    private function setGracePeriodStart(int $feUserUid, int $timestamp): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('fe_users');
        $connection->update(
            'fe_users',
            ['passkey_grace_period_start' => $timestamp],
            ['uid' => $feUserUid],
        );
    }

    private function makeSiteWithLevel(string $enforcementLevel): SiteInterface
    {
        $site = $this->createMock(SiteInterface::class);
        $site->method('getIdentifier')->willReturn('site-a');
        $site->method('getSettings')->willReturn(SiteSettings::createFromSettingsTree([
            'nr_passkeys_fe' => [
                'enforcementLevel' => $enforcementLevel,
            ],
        ]));
        $site->method('getBase')->willReturn(new Uri('https://example.com'));

        return $site;
    }
}
