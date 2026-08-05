<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Updates;

use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use Netresearch\NrPasskeysFe\Updates\PluginListTypeToCTypeUpdateWizard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for the list_type => CType plugin migration wizard.
 *
 * The fixture describes an installation whose passkey content elements were
 * created while the plugins were still registered as list_type sub-types
 * (TYPO3 v13 before the explicit PLUGIN_TYPE_CONTENT_ELEMENT registration):
 * three passkey plugin records, one foreign plugin record and one ordinary
 * content element.
 *
 * On the v14 leg of the test matrix a fresh database has no
 * tt_content.list_type column any more (the field is gone from core TCA), so
 * setUp() re-adds it — mirroring a real v13-to-v14 upgrade, where the column
 * still exists until the schema migration drops it and the wizard must run
 * before that.
 */
#[CoversClass(PluginListTypeToCTypeUpdateWizard::class)]
final class PluginListTypeToCTypeUpdateWizardTest extends AbstractPasskeyFunctionalTestCase
{
    private PluginListTypeToCTypeUpdateWizard $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureListTypeColumnExists();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tt_content_list_type_plugins.csv');
        $this->subject = new PluginListTypeToCTypeUpdateWizard($this->getConnectionPool());
    }

    #[Test]
    public function updateNecessaryReturnsTrueForLegacyPluginElements(): void
    {
        self::assertTrue($this->subject->updateNecessary());
    }

    #[Test]
    public function updateNecessaryIsDryRunAndModifiesNoRecords(): void
    {
        $before = $this->fetchContentRows();

        $this->subject->updateNecessary();

        self::assertSame($before, $this->fetchContentRows());
    }

    #[Test]
    public function executeUpdateMigratesAllThreePluginSignatures(): void
    {
        self::assertTrue($this->subject->executeUpdate());

        $rows = $this->fetchContentRows();

        self::assertSame(['CType' => 'nrpasskeysfe_passkeylogin', 'list_type' => ''], $rows[1]);
        self::assertSame(['CType' => 'nrpasskeysfe_passkeymanagement', 'list_type' => ''], $rows[2]);
        self::assertSame(['CType' => 'nrpasskeysfe_passkeyenrollment', 'list_type' => ''], $rows[3]);
    }

    #[Test]
    public function executeUpdateLeavesUnrelatedRecordsUntouched(): void
    {
        $this->subject->executeUpdate();

        $rows = $this->fetchContentRows();

        self::assertSame(['CType' => 'list', 'list_type' => 'someother_plugin'], $rows[4]);
        self::assertSame(['CType' => 'textmedia', 'list_type' => ''], $rows[5]);
    }

    #[Test]
    public function updateNecessaryReturnsFalseOnceMigrated(): void
    {
        $this->subject->executeUpdate();

        self::assertFalse($this->subject->updateNecessary());
    }

    #[Test]
    public function wizardProvidesInstallToolMetadata(): void
    {
        self::assertNotSame('', $this->subject->getTitle());
        self::assertNotSame('', $this->subject->getDescription());
    }

    /**
     * @return array<int, array{CType: string, list_type: string}>
     */
    private function fetchContentRows(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder
            ->select('uid', 'CType', 'list_type')
            ->from('tt_content')
            ->orderBy('uid')
            ->executeQuery();

        $rows = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $rows[(int) $row['uid']] = [
                'CType' => (string) $row['CType'],
                'list_type' => (string) $row['list_type'],
            ];
        }

        return $rows;
    }

    /**
     * Fresh v14 databases have no tt_content.list_type column any more —
     * re-add it to model the upgraded-installation state the wizard targets.
     */
    private function ensureListTypeColumnExists(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $columns = $connection->createSchemaManager()->listTableColumns('tt_content');

        if (!isset($columns['list_type'])) {
            $connection->executeStatement(
                "ALTER TABLE tt_content ADD list_type VARCHAR(255) DEFAULT '' NOT NULL",
            );
        }
    }
}
