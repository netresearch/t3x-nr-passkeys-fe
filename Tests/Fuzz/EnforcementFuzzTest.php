<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Fuzz;

use DateTimeImmutable;
use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Netresearch\NrPasskeysFe\Tests\Unit\Service\QueryBuilderStubTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

#[CoversClass(FrontendEnforcementService::class)]
#[CoversClass(FrontendEnforcementStatus::class)]
final class EnforcementFuzzTest extends TestCase
{
    use QueryBuilderStubTrait;

    private const VALID_LEVELS = ['off', 'encourage', 'required', 'enforced'];

    /**
     * The specification of "strictest wins", written out rather than computed:
     * [site level][group level] => effective level.
     */
    private const EXPECTED_EFFECTIVE = [
        'off' => ['off' => 'off', 'encourage' => 'encourage', 'required' => 'required', 'enforced' => 'enforced'],
        'encourage' => ['off' => 'encourage', 'encourage' => 'encourage', 'required' => 'required', 'enforced' => 'enforced'],
        'required' => ['off' => 'required', 'encourage' => 'required', 'required' => 'required', 'enforced' => 'enforced'],
        'enforced' => ['off' => 'enforced', 'encourage' => 'enforced', 'required' => 'enforced', 'enforced' => 'enforced'],
    ];

    // ---------------------------------------------------------------
    // Level combinations, resolved by FrontendEnforcementService
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function levelCombinationProvider(): iterable
    {
        foreach (self::VALID_LEVELS as $siteLevel) {
            foreach (self::VALID_LEVELS as $groupLevel) {
                yield "site {$siteLevel}, group {$groupLevel}" => [$siteLevel, $groupLevel];
            }
        }
    }

    #[Test]
    #[DataProvider('levelCombinationProvider')]
    public function theServiceResolvesTheStrictestOfSiteAndGroupLevel(string $siteLevel, string $groupLevel): void
    {
        $status = $this->statusFor($siteLevel, [$groupLevel]);

        self::assertSame(self::EXPECTED_EFFECTIVE[$siteLevel][$groupLevel], $status->effectiveLevel);
        self::assertSame($siteLevel, $status->siteLevel);
        self::assertSame($groupLevel, $status->groupLevel);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLevelStringProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'uppercase OFF' => ['OFF'];
        yield 'uppercase ENFORCED' => ['ENFORCED'];
        yield 'typo' => ['enfource'];
        yield 'number as string' => ['3'];
        yield 'space padded' => [' enforced '];
        yield 'unicode' => ['еnforced'];  // Cyrillic 'е' looks like Latin 'e'
        yield 'null byte' => ["\x00enforced"];
        yield 'binary random' => [\random_bytes(8)];
        yield 'sql injection' => ["'; DROP TABLE fe_groups; --"];
        yield 'json string' => ['{"level":"enforced"}'];
        yield 'very long' => [\str_repeat('enforced', 1000)];
    }

    #[Test]
    #[DataProvider('invalidLevelStringProvider')]
    public function anInvalidGroupLevelNeverRaisesEnforcement(string $rawLevel): void
    {
        // A group value the TCA does not offer counts as off: it neither
        // escalates the site level nor becomes the group level.
        $status = $this->statusFor('encourage', [$rawLevel]);

        self::assertSame('encourage', $status->effectiveLevel);
        self::assertSame('off', $status->groupLevel);
    }

    #[Test]
    public function theStrictestOfSeveralRandomGroupsWinsInAnyOrder(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $groups = [];
            for ($g = \random_int(1, 4); $g > 0; $g--) {
                $groups[] = self::VALID_LEVELS[\random_int(0, 3)];
            }

            $siteLevel = self::VALID_LEVELS[\random_int(0, 3)];

            $forward = $this->statusFor($siteLevel, $groups);
            $reversed = $this->statusFor($siteLevel, \array_reverse($groups));

            $expected = $siteLevel;
            foreach ($groups as $groupLevel) {
                $expected = self::EXPECTED_EFFECTIVE[$expected][$groupLevel];
            }

            $message = 'site ' . $siteLevel . ', groups ' . \implode(',', $groups);
            self::assertSame($expected, $forward->effectiveLevel, $message);
            self::assertSame($forward->effectiveLevel, $reversed->effectiveLevel, $message);
        }
    }

    /**
     * Resolve the status of frontend user 1, who belongs to one group per
     * given level (each with $graceDays grace days), on a site at $siteLevel;
     * $graceStart is the user's grace-period start timestamp.
     *
     * @param list<string> $groupLevels
     */
    private function statusFor(string $siteLevel, array $groupLevels, int $graceDays = 0, int $graceStart = 0): FrontendEnforcementStatus
    {
        $siteConfiguration = $this->createStub(SiteConfigurationService::class);
        $siteConfiguration->method('getEnforcementLevel')->willReturn($siteLevel);

        $groups = [];
        foreach (\array_values($groupLevels) as $index => $level) {
            $groups[] = ['uid' => $index + 1, 'passkey_enforcement' => $level, 'passkey_grace_period_days' => $graceDays];
        }

        $userResult = $this->createStub(Result::class);
        $userResult->method('fetchAssociative')->willReturn([
            'uid' => 1,
            'usergroup' => \implode(',', \array_column($groups, 'uid')),
            'passkey_grace_period_start' => $graceStart,
        ]);
        $groupResult = $this->createStub(Result::class);
        $groupResult->method('fetchAllAssociative')->willReturn($groups);
        $userQueryBuilder = $this->createQueryBuilderStub($userResult);
        $groupQueryBuilder = $this->createQueryBuilderStub($groupResult);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturnCallback(
            static fn(string $table): QueryBuilder => $table === 'fe_groups' ? $groupQueryBuilder : $userQueryBuilder,
        );

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $service = new FrontendEnforcementService(
            $siteConfiguration,
            $this->createStub(FrontendCredentialRepository::class),
            $this->createStub(RecoveryCodeService::class),
            $eventDispatcher,
            $connectionPool,
        );

        return $service->getStatus(1, 'main', $this->createStub(SiteInterface::class));
    }

    // ---------------------------------------------------------------
    // FrontendEnforcementStatus DTO
    // ---------------------------------------------------------------

    #[Test]
    public function statusDtoCanBeConstructedWithAllValidLevels(): void
    {
        foreach (self::VALID_LEVELS as $level) {
            $status = new FrontendEnforcementStatus(
                effectiveLevel: $level,
                siteLevel: $level,
                groupLevel: $level,
                passkeyCount: 0,
                inGracePeriod: false,
                graceDeadline: null,
                recoveryCodesRemaining: 0,
            );

            self::assertSame($level, $status->effectiveLevel);
            self::assertSame($level, $status->siteLevel);
            self::assertSame($level, $status->groupLevel);
        }
    }

    #[Test]
    public function statusDtoWithRandomPasskeyCountsStaysConsistent(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $passkeyCount = \random_int(0, 1_000);
            $recoveryCodesRemaining = \random_int(0, 100);
            $level = self::VALID_LEVELS[\random_int(0, \count(self::VALID_LEVELS) - 1)];

            $status = new FrontendEnforcementStatus(
                effectiveLevel: $level,
                siteLevel: $level,
                groupLevel: 'off',
                passkeyCount: $passkeyCount,
                inGracePeriod: (bool) \random_int(0, 1),
                graceDeadline: null,
                recoveryCodesRemaining: $recoveryCodesRemaining,
            );

            self::assertSame($passkeyCount, $status->passkeyCount);
            self::assertSame($recoveryCodesRemaining, $status->recoveryCodesRemaining);
            self::assertIsString($status->effectiveLevel);
        }
    }

    #[Test]
    public function anEnforcedGroupGrantsNoGracePeriodEvenWithGraceDaysConfigured(): void
    {
        // Business rule: 'enforced' is a hard requirement. The group carries
        // 14 grace days and the user started a grace period yesterday; the
        // service must still grant none.
        $status = $this->statusFor('off', ['enforced'], graceDays: 14, graceStart: \time() - 86_400);

        self::assertSame('enforced', $status->effectiveLevel);
        self::assertFalse($status->inGracePeriod);
        self::assertNull($status->graceDeadline);
        self::assertSame(0, $status->graceDays);
    }

    #[Test]
    public function aRequiredGroupWithTheSameGraceDataIsInItsGracePeriod(): void
    {
        // Control for the case above: the same fixture at 'required' does
        // produce a grace period, so the enforced case cannot pass vacuously.
        $status = $this->statusFor('off', ['required'], graceDays: 14, graceStart: \time() - 86_400);

        self::assertSame('required', $status->effectiveLevel);
        self::assertTrue($status->inGracePeriod);
        self::assertSame(14, $status->graceDays);
    }

    #[Test]
    public function graceDeadlineCanBeSetForRequiredLevel(): void
    {
        $deadline = new DateTimeImmutable('+7 days');

        $status = new FrontendEnforcementStatus(
            effectiveLevel: 'required',
            siteLevel: 'required',
            groupLevel: 'off',
            passkeyCount: 0,
            inGracePeriod: true,
            graceDeadline: $deadline,
            recoveryCodesRemaining: 5,
        );

        self::assertTrue($status->inGracePeriod);
        self::assertSame($deadline, $status->graceDeadline);
    }
}
