<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Doctrine\DBAL\ParameterType;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes the passkey enforcement level of a frontend user group.
 *
 * The write goes through DataHandler as the current backend user, so it is
 * checked against the TCA, recorded in the history and clears the caches the
 * same way an edit in the record form does.
 */
final readonly class FrontendGroupEnforcementService
{
    /**
     * The values of fe_groups.passkey_enforcement, as the TCA select offers them
     * (Configuration/TCA/Overrides/fe_groups.php).
     */
    public const LEVELS = ['off', 'encourage', 'required', 'enforced'];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public static function isValidLevel(string $level): bool
    {
        return \in_array($level, self::LEVELS, true);
    }

    /**
     * Whether a frontend user group with this uid exists (deleted ones do not).
     */
    public function groupExists(int $groupUid): bool
    {
        return $this->findLevel($groupUid) !== null;
    }

    /**
     * Set the enforcement level and return the value now stored.
     *
     * @throws RuntimeException when DataHandler reports an error or the stored
     *                          value is not the requested one
     */
    public function setLevel(int $groupUid, string $level): string
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            ['fe_groups' => [$groupUid => ['passkey_enforcement' => $level]]],
            [],
        );
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            throw new RuntimeException(\implode(' ', $dataHandler->errorLog), 1790000001);
        }

        // An empty error log does not prove the write: DataHandler drops a
        // field it may not write without logging it. The stored value does.
        $stored = $this->findLevel($groupUid);
        if ($stored !== $level) {
            throw new RuntimeException('The enforcement level was not stored.', 1790000002);
        }

        return $stored;
    }

    private function findLevel(int $groupUid): ?string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_groups');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $value = $queryBuilder
            ->select('passkey_enforcement')
            ->from('fe_groups')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($groupUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();

        return \is_string($value) ? $value : null;
    }
}
