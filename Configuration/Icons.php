<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgSpriteIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v14 ships a redesigned backend with light/dark mode: use the flat,
// three-color mark (currentColor + teal accent) that adapts via currentColor.
// v13 uses the colored (teal tile) variant that matches the classic module menu.
//
// Record and plugin icons are sprite icons, the way core registers its own:
// their default markup is <svg><use>, which inherits currentColor, so the
// glyph follows the backend colour scheme in the list module, the page module
// and the content element wizard. SvgIconProvider renders <img> as default
// markup, where currentColor cannot reach the SVG and the glyph paints black.
$moduleIcon = (new Typo3Version())->getMajorVersion() >= 14
    ? 'EXT:nr_passkeys_fe/Resources/Public/Icons/ModuleIcon.svg'
    : 'EXT:nr_passkeys_fe/Resources/Public/Icons/ModuleIcon.legacy.svg';

return [
    'nr-passkeys-fe-module' => [
        'provider' => SvgIconProvider::class,
        'source' => $moduleIcon,
    ],
    'nr-passkeys-fe-plugin-login' => [
        'provider' => SvgSpriteIconProvider::class,
        'sprite' => 'EXT:nr_passkeys_fe/Resources/Public/Icons/plugin-login.svg#nr-passkeys-fe-plugin-login',
    ],
    'nr-passkeys-fe-plugin-management' => [
        'provider' => SvgSpriteIconProvider::class,
        'sprite' => 'EXT:nr_passkeys_fe/Resources/Public/Icons/plugin-management.svg#nr-passkeys-fe-plugin-management',
    ],
    'nr-passkeys-fe-plugin-enrollment' => [
        'provider' => SvgSpriteIconProvider::class,
        'sprite' => 'EXT:nr_passkeys_fe/Resources/Public/Icons/plugin-enrollment.svg#nr-passkeys-fe-plugin-enrollment',
    ],
    'nr-passkeys-fe-credential' => [
        'provider' => SvgSpriteIconProvider::class,
        'sprite' => 'EXT:nr_passkeys_fe/Resources/Public/Icons/credential.svg#nr-passkeys-fe-credential',
    ],
    'nr-passkeys-fe-recovery-code' => [
        'provider' => SvgSpriteIconProvider::class,
        'sprite' => 'EXT:nr_passkeys_fe/Resources/Public/Icons/recovery-code.svg#nr-passkeys-fe-recovery-code',
    ],
];
