<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Controller\Plugin;

use Netresearch\NrPasskeysFe\Controller\Plugin\EnrollmentPluginController;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * The enrollment plugin shows the logged-in user's real enforcement state,
 * resolved by the same FrontendEnforcementService the interstitial and the
 * banner use, against real fe_users and fe_groups rows.
 */
#[CoversClass(EnrollmentPluginController::class)]
final class EnrollmentPluginControllerTest extends AbstractPasskeyFunctionalTestCase
{
    private const DAY = 86400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/enforcement.csv');

        // Grace periods run from a start time relative to now: user 1 started
        // four days into a fourteen-day period, user 4 twenty days ago.
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('fe_users');
        $connection->update('fe_users', ['passkey_grace_period_start' => \time() - 4 * self::DAY], ['uid' => 1]);
        $connection->update('fe_users', ['passkey_grace_period_start' => \time() - 20 * self::DAY], ['uid' => 4]);
    }

    /**
     * @return iterable<string, array{int, bool, int}>
     */
    public static function enforcementStates(): iterable
    {
        yield 'required, in its grace period' => [1, false, 10];
        yield 'enforced' => [2, true, 0];
        // The page starts the grace period, as the interstitial would.
        yield 'required, grace period not started' => [3, false, 14];
        yield 'required, grace period over' => [4, true, 0];
        yield 'not enforced' => [5, false, 0];
        // A passkey settles it, as for the interstitial and the banner.
        yield 'required, already holds a passkey' => [6, false, 0];
        yield 'enforced, already holds a passkey' => [7, false, 0];
    }

    #[Test]
    #[DataProvider('enforcementStates')]
    public function theTemplateGetsTheUsersEnforcementState(int $feUserUid, bool $required, int $graceDays): void
    {
        $vars = $this->renderForFrontendUser($feUserUid);

        self::assertSame($required, $vars['enrollmentRequired']);
        self::assertSame($graceDays, $vars['gracePeriodDaysRemaining']);
    }

    #[Test]
    public function theEnrollmentPageStartsAGracePeriodThatHasNotStarted(): void
    {
        $before = \time();
        $this->renderForFrontendUser(3);

        self::assertGreaterThanOrEqual($before, $this->graceStartOf(3));
    }

    #[Test]
    public function aGracePeriodThatIsOverIsNotStartedAgain(): void
    {
        $start = $this->graceStartOf(4);
        $vars = $this->renderForFrontendUser(4);

        self::assertSame($start, $this->graceStartOf(4));
        self::assertTrue($vars['enrollmentRequired']);
    }

    #[Test]
    public function aPasskeyHolderGetsNoGracePeriodStarted(): void
    {
        $this->renderForFrontendUser(6);

        self::assertSame(0, $this->graceStartOf(6));
    }

    #[Test]
    public function aStartedLastDayOfGraceCountsAsOneDay(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_users')
            ->update('fe_users', ['passkey_grace_period_start' => \time() - 13 * self::DAY - 12 * 3600], ['uid' => 1]);

        self::assertSame(1, $this->renderForFrontendUser(1)['gracePeriodDaysRemaining']);
    }

    #[Test]
    public function theSiteLevelAppliesWhenTheGroupsEnforceNothing(): void
    {
        $vars = $this->renderForFrontendUser(5, siteLevel: 'enforced');

        self::assertTrue($vars['enrollmentRequired']);
    }

    private function graceStartOf(int $feUserUid): int
    {
        $value = $this->get(ConnectionPool::class)->getConnectionForTable('fe_users')
            ->select(['passkey_grace_period_start'], 'fe_users', ['uid' => $feUserUid])
            ->fetchOne();

        return \is_numeric($value) ? (int) $value : -1;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderForFrontendUser(int $feUserUid, string $siteLevel = 'off'): array
    {
        $subject = new EnrollmentPluginController($this->get(FrontendEnforcementService::class));
        $subject->injectResponseFactory(new ResponseFactory());
        $subject->injectStreamFactory(new StreamFactory());

        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'settings' => ['nr_passkeys_fe' => ['enforcementLevel' => $siteLevel]],
        ]);
        $feUser = new FrontendUserAuthentication();
        $feUser->user = ['uid' => $feUserUid];

        $request = (new ServerRequest('https://example.com/enrollment', 'GET'))
            ->withAttribute('site', $site)
            ->withAttribute('frontend.user', $feUser)
            ->withAttribute('extbase', new ExtbaseRequestParameters(EnrollmentPluginController::class));

        $assigned = [];
        $view = $this->createStub(ViewInterface::class);
        $view->method('assignMultiple')->willReturnCallback(
            static function (array $vars) use (&$assigned, $view): ViewInterface {
                $assigned = $vars;
                return $view;
            },
        );

        $reflection = new ReflectionClass($subject);
        $reflection->getProperty('request')->setValue($subject, new Request($request));
        $reflection->getProperty('view')->setValue($subject, $view);

        $subject->indexAction();

        return $assigned;
    }
}
