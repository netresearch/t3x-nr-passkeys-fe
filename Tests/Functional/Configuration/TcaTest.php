<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Configuration;

use Netresearch\NrPasskeysFe\Tests\FunctionalTestExtensionsTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for TCA configuration registered by nr_passkeys_fe.
 *
 * These tests run in a real TYPO3 bootstrap so $GLOBALS['TCA'] is fully loaded.
 */
final class TcaTest extends FunctionalTestCase
{
    use FunctionalTestExtensionsTrait;

    // ---------------------------------------------------------------
    // fe_groups TCA
    // ---------------------------------------------------------------

    #[Test]
    public function feGroupsHasPasskeyEnforcementField(): void
    {
        self::assertArrayHasKey(
            'passkey_enforcement',
            $GLOBALS['TCA']['fe_groups']['columns'],
            'fe_groups must have passkey_enforcement column in TCA',
        );
    }

    #[Test]
    public function passkeyEnforcementFieldIsSelectType(): void
    {
        $config = $GLOBALS['TCA']['fe_groups']['columns']['passkey_enforcement']['config'] ?? [];

        self::assertSame('select', $config['type'] ?? null);
        self::assertSame('selectSingle', $config['renderType'] ?? null);
    }

    #[Test]
    public function passkeyEnforcementFieldHasExpectedItems(): void
    {
        $items = $GLOBALS['TCA']['fe_groups']['columns']['passkey_enforcement']['config']['items'] ?? [];

        $values = \array_column($items, 'value');
        self::assertContains('off', $values, 'passkey_enforcement must have "off" item');
        self::assertContains('encourage', $values, 'passkey_enforcement must have "encourage" item');
        self::assertContains('required', $values, 'passkey_enforcement must have "required" item');
        self::assertContains('enforced', $values, 'passkey_enforcement must have "enforced" item');
    }

    #[Test]
    public function passkeyEnforcementDefaultIsOff(): void
    {
        $default = $GLOBALS['TCA']['fe_groups']['columns']['passkey_enforcement']['config']['default'] ?? null;

        self::assertSame('off', $default, 'passkey_enforcement default must be "off"');
    }

    #[Test]
    public function feGroupsHasGracePeriodDaysField(): void
    {
        self::assertArrayHasKey(
            'passkey_grace_period_days',
            $GLOBALS['TCA']['fe_groups']['columns'],
            'fe_groups must have passkey_grace_period_days column in TCA',
        );
    }

    // ---------------------------------------------------------------
    // fe_users TCA
    // ---------------------------------------------------------------

    #[Test]
    public function feUsersHasPasskeyGracePeriodStartField(): void
    {
        self::assertArrayHasKey(
            'passkey_grace_period_start',
            $GLOBALS['TCA']['fe_users']['columns'],
            'fe_users must have passkey_grace_period_start column in TCA',
        );
    }

    #[Test]
    public function feUsersHasPasskeyNudgeUntilField(): void
    {
        self::assertArrayHasKey(
            'passkey_nudge_until',
            $GLOBALS['TCA']['fe_users']['columns'],
            'fe_users must have passkey_nudge_until column in TCA',
        );
    }

    // ---------------------------------------------------------------
    // tt_content plugins
    // ---------------------------------------------------------------

    #[Test]
    public function passkeyLoginPluginIsRegisteredInTtContent(): void
    {
        self::assertContains(
            'nrpasskeysfe_passkeylogin',
            $this->getRegisteredPluginSignatures(),
            'PasskeyLogin plugin must be selectable in tt_content',
        );
    }

    #[Test]
    public function passkeyManagementPluginIsRegisteredInTtContent(): void
    {
        self::assertContains(
            'nrpasskeysfe_passkeymanagement',
            $this->getRegisteredPluginSignatures(),
            'PasskeyManagement plugin must be selectable in tt_content',
        );
    }

    #[Test]
    public function passkeyEnrollmentPluginIsRegisteredInTtContent(): void
    {
        self::assertContains(
            'nrpasskeysfe_passkeyenrollment',
            $this->getRegisteredPluginSignatures(),
            'PasskeyEnrollment plugin must be selectable in tt_content',
        );
    }

    // ---------------------------------------------------------------
    // tx_nrpasskeysfe_credential TCA
    // ---------------------------------------------------------------

    #[Test]
    public function credentialTableHasTcaDefinition(): void
    {
        self::assertArrayHasKey(
            'tx_nrpasskeysfe_credential',
            $GLOBALS['TCA'],
            'tx_nrpasskeysfe_credential must have a TCA definition',
        );
    }

    #[Test]
    public function credentialTableHasRequiredColumns(): void
    {
        $columns = \array_keys($GLOBALS['TCA']['tx_nrpasskeysfe_credential']['columns'] ?? []);

        foreach (['fe_user', 'credential_id', 'label', 'site_identifier', 'storage_pid'] as $required) {
            self::assertContains($required, $columns, "tx_nrpasskeysfe_credential must have column '{$required}'");
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Plugin signatures selectable in tt_content, from whichever column the
     * running core registers them in.
     *
     * Which column that is depends on the TYPO3 version, because
     * ExtensionUtility::configurePlugin() is called here without an explicit
     * plugin type: v13.4 then defaults to PLUGIN_TYPE_PLUGIN ('list_type'),
     * v14 dropped that constant and always uses 'CType'. Asserting either
     * column alone therefore passes on one leg of the matrix and fails on the
     * other. Moving the registration to 'CType' on both is a content-record
     * migration (existing elements are stored as CType=list plus
     * list_type=<signature>) and belongs in its own change.
     *
     * @return list<string>
     */
    private function getRegisteredPluginSignatures(): array
    {
        $items = [
            ...($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] ?? []),
            ...($GLOBALS['TCA']['tt_content']['columns']['list_type']['config']['items'] ?? []),
        ];

        return \array_values(\array_map('strval', \array_column($items, 'value')));
    }
}
