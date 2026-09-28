<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Controller\Fixtures;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * A DataHandler hook that silently drops fe_groups.passkey_enforcement, the
 * way a third-party hook or a missing field permission can: DataHandler then
 * writes nothing and logs nothing. Registered only by the test that needs it.
 */
final class DropEnforcementFieldHook
{
    /**
     * @param array<string, mixed> $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(array &$incomingFieldArray, string $table, int|string $id, DataHandler $dataHandler): void
    {
        if ($table === 'fe_groups') {
            unset($incomingFieldArray['passkey_enforcement']);
        }
    }
}
