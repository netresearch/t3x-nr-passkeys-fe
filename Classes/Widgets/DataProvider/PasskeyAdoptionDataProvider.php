<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Widgets\DataProvider;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Dashboard\WidgetApi;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Chart.js doughnut data provider: frontend users with vs. without passkeys.
 *
 * Two single aggregate queries; the default QueryBuilder restrictions take
 * care of deleted/disabled fe_users and deleted credential rows. The DISTINCT
 * user count is expressed via selectLiteral() with a quoted identifier —
 * QueryBuilder::count('DISTINCT x') would quote the whole expression as one
 * identifier and produce broken SQL.
 */
final readonly class PasskeyAdoptionDataProvider implements ChartDataProviderInterface
{
    private const LANG_PREFIX = 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_dashboard.xlf:';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{
     *     labels: list<string>,
     *     datasets: list<array{backgroundColor: list<string>, data: list<int>}>
     * }
     */
    public function getChartData(): array
    {
        $totalUsers = $this->countActiveFrontendUsers();
        $usersWithPasskeys = \min($this->countUsersWithActivePasskey(), $totalUsers);

        $chartColors = WidgetApi::getDefaultChartColors();

        return [
            'labels' => [
                $this->translate('widget.adoption.label.with_passkeys', 'With passkeys'),
                $this->translate('widget.adoption.label.without_passkeys', 'Without passkeys'),
            ],
            'datasets' => [
                [
                    'backgroundColor' => [$chartColors[0], $chartColors[1]],
                    'data' => [$usersWithPasskeys, $totalUsers - $usersWithPasskeys],
                ],
            ],
        ];
    }

    /**
     * Count all active (not deleted, not disabled) frontend users.
     */
    private function countActiveFrontendUsers(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');

        $result = $queryBuilder
            ->count('uid')
            ->from('fe_users')
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Count active frontend users owning at least one active (not revoked) passkey.
     */
    private function countUsersWithActivePasskey(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');

        $result = $queryBuilder
            ->selectLiteral('COUNT(DISTINCT ' . $queryBuilder->quoteIdentifier('u.uid') . ')')
            ->from('fe_users', 'u')
            ->join(
                'u',
                'tx_nrpasskeysfe_credential',
                'c',
                (string) $queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('c.fe_user', $queryBuilder->quoteIdentifier('u.uid')),
                    $queryBuilder->expr()->eq('c.revoked_at', 0),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Translate a chart label, falling back to English outside a backend context.
     */
    private function translate(string $key, string $fallback): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $fallback;
        }

        $label = $languageService->sL(self::LANG_PREFIX . $key);

        return $label !== '' ? $label : $fallback;
    }
}
