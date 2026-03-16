<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Netresearch\NrPasskeysFe\Service\FrontendAdoptionStatsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Backend module controller for the FE passkey management admin module.
 *
 * Provides the dashboard and help views under Web > FE Passkey Management.
 */
final class AdminModuleController
{
    /** @var array<string, string> */
    private const ENFORCEMENT_LEVELS = [
        'off' => 'Off',
        'encourage' => 'Encourage',
        'required' => 'Required',
        'enforced' => 'Enforced',
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly FrontendAdoptionStatsService $adoptionStatsService,
        private readonly PageRenderer $pageRenderer,
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * Render the FE passkey adoption dashboard.
     */
    public function dashboardAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle($this->translate('module.title', 'FE Passkey Management'));
        $this->buildDocHeaderMenu($moduleTemplate, 'dashboard');

        $stats = $this->adoptionStatsService->getStats();

        $groupData = [];
        foreach ($stats->perGroupStats as $groupUid => $group) {
            $groupData[] = [
                'uid' => (int) $groupUid,
                'title' => $group['groupName'],
                'enforcement' => $group['enforcement'],
                'totalUsers' => $group['userCount'],
                'usersWithPasskeys' => $group['withPasskeys'],
                'adoptionPercentage' => $group['userCount'] > 0
                    ? \round(($group['withPasskeys'] / $group['userCount']) * 100, 1)
                    : 0.0,
            ];
        }

        $moduleTemplate->assignMultiple([
            'totalUsers' => $stats->totalUsers,
            'usersWithPasskeys' => $stats->usersWithPasskeys,
            'adoptionPercentage' => $stats->adoptionPercentage,
            'groups' => $groupData,
            'enforcementLevels' => $this->getEnforcementLevelOptions(),
        ]);

        $this->pageRenderer->loadJavaScriptModule(
            '@netresearch/nr-passkeys-fe/PasskeyFeAdmin.js',
        );
        $this->pageRenderer->addInlineLanguageLabelFile(
            'EXT:nr_passkeys_fe/Resources/Private/Language/locallang.xlf',
            'js.',
        );

        return $moduleTemplate->renderResponse('AdminModule/Dashboard');
    }

    /**
     * Render the help/documentation view.
     */
    public function helpAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle(
            $this->translate('module.title', 'FE Passkey Management')
            . ' – '
            . $this->translate('module.help', 'Help'),
        );
        $this->buildDocHeaderMenu($moduleTemplate, 'help');

        $moduleTemplate->assignMultiple([
            'dashboardUrl' => (string) $this->uriBuilder->buildUriFromRoute('nr_passkeys_fe'),
        ]);

        return $moduleTemplate->renderResponse('AdminModule/Help');
    }

    /**
     * Set up the docheader tab menu for Dashboard/Help navigation.
     */
    private function buildDocHeaderMenu(ModuleTemplate $moduleTemplate, string $activeTab): void
    {
        $menuRegistry = $moduleTemplate->getDocHeaderComponent()->getMenuRegistry();
        $menu = $menuRegistry->makeMenu();
        $menu->setIdentifier('NrPasskeysFeMenu');

        $dashboardItem = $menu->makeMenuItem()
            ->setTitle($this->translate('module.dashboard', 'Dashboard'))
            ->setHref((string) $this->uriBuilder->buildUriFromRoute('nr_passkeys_fe'));
        if ($activeTab === 'dashboard') {
            $dashboardItem->setActive(true);
        }
        $menu->addMenuItem($dashboardItem);

        $helpItem = $menu->makeMenuItem()
            ->setTitle($this->translate('module.help', 'Help'))
            ->setHref((string) $this->uriBuilder->buildUriFromRoute('nr_passkeys_fe.help'));
        if ($activeTab === 'help') {
            $helpItem->setActive(true);
        }
        $menu->addMenuItem($helpItem);

        $menuRegistry->addMenu($menu);
    }

    /**
     * Build an associative array of enforcement-level values to display labels.
     *
     * @return array<string, string>
     */
    private function getEnforcementLevelOptions(): array
    {
        $options = [];
        foreach (self::ENFORCEMENT_LEVELS as $value => $fallback) {
            $options[$value] = $this->translate('enforcement.level.' . $value, $fallback);
        }

        return $options;
    }

    /**
     * Translate a key from the extension's locallang file with a fallback.
     */
    private function translate(string $key, string $fallback): string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang instanceof LanguageService) {
            $translated = $lang->sL('LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang.xlf:' . $key);
            if ($translated !== '') {
                return $translated;
            }
        }

        return $fallback;
    }
}
