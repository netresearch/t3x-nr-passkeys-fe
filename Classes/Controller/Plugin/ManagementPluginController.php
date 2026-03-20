<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller\Plugin;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Extbase controller for the PasskeyManagement frontend plugin.
 * Assigns template variables including credential list and recovery code count,
 * then renders Fluid; management CRUD runs via eID/JS.
 */
final class ManagementPluginController extends ActionController
{
    public function __construct(
        private readonly FrontendCredentialRepository $credentialRepository,
        private readonly RecoveryCodeService $recoveryCodeService,
    ) {}

    public function indexAction(): ResponseInterface
    {
        /** @var SiteInterface|null $site */
        $site = $this->request->getAttribute('site');
        $siteIdentifier = $site?->getIdentifier() ?? '';
        $baseUrl = \rtrim((string) ($site?->getBase() ?? ''), '/');
        $eidUrl = $baseUrl . '/?eID=nr_passkeys_fe';

        // Fetch credential list and recovery code count for the authenticated FE user
        $feUserUid = $this->getFeUserUid();
        $credentials = [];
        $recoveryCodesRemaining = 0;

        if ($feUserUid > 0) {
            $credentials = $this->credentialRepository->findByFeUser($feUserUid, $siteIdentifier);
            $recoveryCodesRemaining = $this->recoveryCodeService->countRemaining($feUserUid);
        }

        $this->view->assignMultiple([
            'eidUrl' => $eidUrl,
            'siteIdentifier' => $siteIdentifier,
            'listUrl' => $eidUrl . '&action=manageList',
            'registerOptionsUrl' => $eidUrl . '&action=registrationOptions',
            'registerVerifyUrl' => $eidUrl . '&action=registrationVerify',
            'renameUrl' => $eidUrl . '&action=manageRename',
            'removeUrl' => $eidUrl . '&action=manageRemove',
            'recoveryGenerateUrl' => $eidUrl . '&action=recoveryGenerate',
            'credentials' => $credentials,
            'recoveryCodesRemaining' => $recoveryCodesRemaining,
        ]);

        return $this->htmlResponse();
    }

    /**
     * Extract the authenticated FE user UID from the request.
     */
    private function getFeUserUid(): int
    {
        $feUser = $this->request->getAttribute('frontend.user');
        if (!$feUser instanceof FrontendUserAuthentication) {
            return 0;
        }

        $userRow = $feUser->user;
        return \is_numeric($userRow['uid'] ?? null) ? (int) $userRow['uid'] : 0;
    }
}
