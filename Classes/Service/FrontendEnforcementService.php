<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Netresearch\NrPasskeysFe\Domain\Dto\FrontendEnforcementStatus;
use Netresearch\NrPasskeysFe\Event\EnforcementLevelResolvedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the effective passkey enforcement status for a frontend user.
 *
 * Enforcement follows a "strictest wins" policy:
 * - The site-level enforcement and the strictest group-level enforcement
 *   are compared; whichever has higher severity wins.
 * - Severity: off=0, encourage=1, required=2, enforced=3
 * - Grace period logic: if enforcement is 'required', the user may have a
 *   grace period. If 'enforced', there is no grace period.
 * - An event (EnforcementLevelResolvedEvent) is dispatched to allow
 *   custom overrides.
 */
final class FrontendEnforcementService
{
    private const SEVERITY_MAP = [
        'off' => 0,
        'encourage' => 1,
        'required' => 2,
        'enforced' => 3,
    ];

    /** @var array<string, FrontendEnforcementStatus> */
    private array $statusCache = [];

    public function __construct(
        private readonly SiteConfigurationService $siteConfigurationService,
        private readonly FrontendCredentialRepository $credentialRepository,
        private readonly RecoveryCodeService $recoveryCodeService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Determine the effective enforcement status for a frontend user.
     */
    public function getStatus(
        int $feUserUid,
        string $siteIdentifier,
        SiteInterface $site,
    ): FrontendEnforcementStatus {
        $cacheKey = $feUserUid . '|' . $siteIdentifier;
        if (\array_key_exists($cacheKey, $this->statusCache)) {
            return $this->statusCache[$cacheKey];
        }

        $passkeyCount = $this->credentialRepository->countByFeUser($feUserUid);
        $recoveryCodesRemaining = $this->recoveryCodeService->countRemaining($feUserUid);

        $siteLevel = $this->siteConfigurationService->getEnforcementLevel($site);
        $siteSeverity = $this->levelSeverity($siteLevel);

        // Resolve group-level enforcement
        $userRow = $this->fetchFeUser($feUserUid);
        $usergroupValue = $userRow['usergroup'] ?? '';
        $groupUids = GeneralUtility::intExplode(
            ',',
            \is_string($usergroupValue) ? $usergroupValue : '',
            true,
        );

        $groupLevel = 'off';
        $effectiveGraceDays = 0;

        if ($groupUids !== []) {
            $groups = $this->fetchGroups(\array_values($groupUids));
            [$groupLevel, $effectiveGraceDays] = $this->resolveStrictestGroup($groups);
        }

        $groupSeverity = $this->levelSeverity($groupLevel);

        // Effective = max(site, group)
        $effectiveLevel = $siteSeverity >= $groupSeverity ? $siteLevel : $groupLevel;

        // If the effective level is 'enforced', no grace period
        if ($effectiveLevel === 'enforced') {
            $effectiveGraceDays = 0;
        }

        // If site severity is higher than group, take site level (no group grace days)
        if ($siteSeverity > $groupSeverity) {
            $effectiveGraceDays = 0;
        }

        // Dispatch event to allow custom overrides
        $event = new EnforcementLevelResolvedEvent($feUserUid, $effectiveLevel);
        $this->eventDispatcher->dispatch($event);
        $effectiveLevel = $event->getEffectiveLevel();

        // Calculate grace period
        $graceValue = $userRow['passkey_grace_period_start'] ?? 0;
        $gracePeriodStart = \is_numeric($graceValue) ? (int) $graceValue : 0;

        $inGracePeriod = false;
        $graceDeadline = null;

        if ($effectiveGraceDays > 0 && $gracePeriodStart > 0) {
            $deadline = (new DateTimeImmutable())
                ->setTimestamp($gracePeriodStart)
                ->modify('+' . $effectiveGraceDays . ' days');

            if ($deadline !== false && $deadline > new DateTimeImmutable()) {
                $inGracePeriod = true;
                $graceDeadline = $deadline;
            }
        }

        $status = new FrontendEnforcementStatus(
            effectiveLevel: $effectiveLevel,
            siteLevel: $siteLevel,
            groupLevel: $groupLevel,
            passkeyCount: $passkeyCount,
            inGracePeriod: $inGracePeriod,
            graceDeadline: $graceDeadline,
            recoveryCodesRemaining: $recoveryCodesRemaining,
            graceDays: $effectiveGraceDays,
        );

        $this->statusCache[$cacheKey] = $status;

        return $status;
    }

    /**
     * Start the grace period for a frontend user by recording the current timestamp.
     */
    public function startGracePeriod(int $feUserUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable('fe_users');
        $connection->update(
            'fe_users',
            ['passkey_grace_period_start' => \time()],
            ['uid' => $feUserUid],
        );

        // Invalidate cached status for this user across all sites
        foreach (\array_keys($this->statusCache) as $key) {
            if (\str_starts_with($key, $feUserUid . '|')) {
                unset($this->statusCache[$key]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFeUser(int $feUserUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');
        $row = $queryBuilder
            ->select('uid', 'usergroup', 'passkey_grace_period_start')
            ->from('fe_users')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($feUserUid, \Doctrine\DBAL\ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        return \is_array($row) ? $row : [];
    }

    /**
     * @param list<int> $groupUids
     *
     * @return list<array<string, mixed>>
     */
    private function fetchGroups(array $groupUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_groups');

        return $queryBuilder
            ->select('uid', 'passkey_enforcement', 'passkey_grace_period_days')
            ->from('fe_groups')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($groupUids, ArrayParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Find the strictest enforcement level across groups.
     *
     * @param list<array<string, mixed>> $groups
     *
     * @return array{string, int} [level, graceDays]
     */
    private function resolveStrictestGroup(array $groups): array
    {
        $effectiveLevel = 'off';
        $effectiveGraceDays = 0;

        foreach ($groups as $group) {
            $enforcementValue = $group['passkey_enforcement'] ?? 'off';
            $level = \is_string($enforcementValue) ? $enforcementValue : 'off';
            $levelSeverity = $this->levelSeverity($level);

            $graceDaysValue = $group['passkey_grace_period_days'] ?? 0;
            $graceDays = \is_numeric($graceDaysValue) ? (int) $graceDaysValue : 0;

            $currentSeverity = $this->levelSeverity($effectiveLevel);

            if ($levelSeverity > $currentSeverity) {
                $effectiveLevel = $level;
                $effectiveGraceDays = $graceDays;
            } elseif (
                $levelSeverity === $currentSeverity
                && $levelSeverity > 0
                && ($effectiveGraceDays === 0 || $graceDays < $effectiveGraceDays)
            ) {
                // At same severity, pick the shortest grace period
                $effectiveGraceDays = $graceDays;
            }
        }

        return [$effectiveLevel, $effectiveGraceDays];
    }

    private function levelSeverity(string $level): int
    {
        return self::SEVERITY_MAP[$level] ?? 0;
    }
}
