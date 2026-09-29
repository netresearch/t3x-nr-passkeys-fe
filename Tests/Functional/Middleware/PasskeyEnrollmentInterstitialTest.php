<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Middleware;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Middleware\PasskeyEnrollmentInterstitial;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * The interstitial starts a due grace period against real fe_users and
 * fe_groups rows, on the enrollment page too, before that page is rendered:
 * the banner is rendered before the enrollment plugin and has to see it.
 */
#[CoversClass(PasskeyEnrollmentInterstitial::class)]
final class PasskeyEnrollmentInterstitialTest extends AbstractPasskeyFunctionalTestCase
{
    private const DAY = 86400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Controller/Plugin/Fixtures/enforcement.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_users')
            ->update('fe_users', ['passkey_grace_period_start' => \time() - 20 * self::DAY], ['uid' => 4]);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function pagesAndOutcomes(): iterable
    {
        yield 'the enrollment page, passed through' => ['/enrollment', 'https://example.com/enrollment', 200];
        yield 'any other page, redirected to the enrollment page' => ['/news', 'https://example.com/enrollment', 303];
        yield 'no enrollment page configured, passed through' => ['/news', '', 200];
    }

    #[Test]
    #[DataProvider('pagesAndOutcomes')]
    public function aDueGracePeriodIsStartedOnTheFirstRequest(string $path, string $enrollmentUrl, int $status): void
    {
        $before = \time();

        self::assertSame($status, $this->process(3, $path, $enrollmentUrl)->getStatusCode());
        self::assertGreaterThanOrEqual($before, $this->graceStartOf(3));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function usersWithNoGracePeriodToStart(): iterable
    {
        yield 'enforced' => [2];
        yield 'not enforced' => [5];
        yield 'required, already holds a passkey' => [6];
    }

    #[Test]
    #[DataProvider('usersWithNoGracePeriodToStart')]
    public function noGracePeriodIsStartedWhereNoneIsDue(int $feUserUid): void
    {
        $this->process($feUserUid, '/enrollment');

        self::assertSame(0, $this->graceStartOf($feUserUid));
    }

    #[Test]
    public function aGracePeriodThatIsOverIsNotStartedAgain(): void
    {
        $start = $this->graceStartOf(4);

        self::assertSame(303, $this->process(4, '/news')->getStatusCode());
        self::assertSame($start, $this->graceStartOf(4));
    }

    private function process(int $feUserUid, string $path, string $enrollmentUrl = 'https://example.com/enrollment'): ResponseInterface
    {
        $subject = new PasskeyEnrollmentInterstitial(
            $this->get(FrontendEnforcementService::class),
            $this->get(FrontendCredentialRepository::class),
            $this->get(SiteConfigurationService::class),
            new FrontendConfiguration(postLoginEnrollmentEnabled: true),
        );

        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'settings' => ['nr_passkeys_fe' => ['enrollmentPageUrl' => $enrollmentUrl]],
        ]);
        $feUser = $this->createStub(FrontendUserAuthentication::class);
        $feUser->user = ['uid' => $feUserUid];
        $feUser->method('getKey')->willReturn(null);

        $request = (new ServerRequest('https://example.com' . $path, 'GET'))
            ->withAttribute('site', $site)
            ->withAttribute('frontend.user', $feUser);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        return $subject->process($request, $handler);
    }

    private function graceStartOf(int $feUserUid): int
    {
        $value = $this->get(ConnectionPool::class)->getConnectionForTable('fe_users')
            ->select(['passkey_grace_period_start'], 'fe_users', ['uid' => $feUserUid])
            ->fetchOne();

        return \is_numeric($value) ? (int) $value : -1;
    }
}
