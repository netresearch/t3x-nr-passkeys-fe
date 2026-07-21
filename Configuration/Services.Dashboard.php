<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrPasskeysFe\Widgets\AdminOnlyDoughnutChartWidget;
use Netresearch\NrPasskeysFe\Widgets\AdminOnlyNumberWithIconWidget;
use Netresearch\NrPasskeysFe\Widgets\DataProvider\ActiveCredentialsDataProvider;
use Netresearch\NrPasskeysFe\Widgets\DataProvider\PasskeyAdoptionDataProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Dashboard widget registration for nr_passkeys_fe.
 *
 * Imported conditionally from Configuration/Services.php only when
 * typo3/cms-dashboard is installed. This is a PHP config file (not YAML) because
 * TYPO3 loads Configuration/Services.php with a standalone Symfony PhpFileLoader
 * that has no YAML loader in its resolver, so a `.yaml` import cannot be
 * resolved from there. The Classes/Widgets/ namespace is excluded from the
 * Services.yaml auto-registration for the same reason.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->set(PasskeyAdoptionDataProvider::class);
    $services->set(ActiveCredentialsDataProvider::class);

    $services->set('dashboard.widget.nrpasskeysfe.adoption', AdminOnlyDoughnutChartWidget::class)
        ->arg('$dataProvider', service(PasskeyAdoptionDataProvider::class))
        ->tag('dashboard.widget', [
            'identifier'     => 'nrpasskeysfe-adoption',
            'groupNames'     => 'nrpasskeys',
            'title'          => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_dashboard.xlf:widget.adoption.title',
            'description'    => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_dashboard.xlf:widget.adoption.description',
            'iconIdentifier' => 'nr-passkeys-fe-module',
            'height'         => 'medium',
            'width'          => 'small',
        ]);

    $services->set('dashboard.widget.nrpasskeysfe.credentials', AdminOnlyNumberWithIconWidget::class)
        ->arg('$dataProvider', service(ActiveCredentialsDataProvider::class))
        ->arg('$options', [
            'icon'     => 'nr-passkeys-fe-module',
            'title'    => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_dashboard.xlf:widget.credentials.title',
            'subtitle' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_dashboard.xlf:widget.credentials.subtitle',
        ])
        ->tag('dashboard.widget', [
            'identifier'     => 'nrpasskeysfe-credentials',
            'groupNames'     => 'nrpasskeys',
            'title'          => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_dashboard.xlf:widget.credentials.title',
            'description'    => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_dashboard.xlf:widget.credentials.description',
            'iconIdentifier' => 'nr-passkeys-fe-module',
            'height'         => 'small',
            'width'          => 'small',
        ]);
};
