<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Updates;

use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\AbstractListTypeToCTypeUpdate;

/**
 * Migrates the three passkey plugins from CType=list + list_type=<signature>
 * to CType=<signature>.
 *
 * Since the plugins are registered with an explicit
 * ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT (see ext_localconf.php),
 * content elements created under the former list_type registration on
 * TYPO3 v13 would no longer render or be editable. This wizard rewrites those
 * tt_content records (and be_groups explicit_allowdeny entries, handled by
 * the core base class) to the CType-based registration.
 *
 * updateNecessary() only counts affected records and never writes, so the
 * wizard is safe to probe as a dry run — via the install tool or
 * `typo3 upgrade:list`.
 *
 * The TYPO3\CMS\Install\* class names are the v13.4 ones; on v14 they are
 * deprecated aliases of the TYPO3\CMS\Core\* implementations and slated for
 * removal in v15. They stay here until v13 support is dropped, because the
 * v14 names do not exist on v13.
 *
 * @internal Not part of the @api surface; may change without notice.
 */
#[UpgradeWizard('nrPasskeysFe_pluginListTypeToCType')]
final class PluginListTypeToCTypeUpdateWizard extends AbstractListTypeToCTypeUpdate
{
    public function getTitle(): string
    {
        return 'Migrate nr_passkeys_fe plugins to content elements (CType)';
    }

    public function getDescription(): string
    {
        return 'The passkey login, management and enrollment plugins are now registered as '
            . 'content elements (CType) instead of the deprecated list_type sub-types. This '
            . 'wizard migrates existing tt_content records from CType=list plus '
            . 'list_type=<plugin signature> to CType=<plugin signature> and updates '
            . 'backend group permissions accordingly.';
    }

    /**
     * @return array<string, string>
     */
    protected function getListTypeToCTypeMapping(): array
    {
        return [
            'nrpasskeysfe_passkeylogin' => 'nrpasskeysfe_passkeylogin',
            'nrpasskeysfe_passkeymanagement' => 'nrpasskeysfe_passkeymanagement',
            'nrpasskeysfe_passkeyenrollment' => 'nrpasskeysfe_passkeyenrollment',
        ];
    }
}
