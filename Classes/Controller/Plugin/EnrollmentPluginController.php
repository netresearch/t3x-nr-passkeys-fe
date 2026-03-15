<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller\Plugin;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Extbase controller for the PasskeyEnrollment frontend plugin.
 * Assigns template variables then renders Fluid; enrollment logic runs via eID/JS.
 */
final class EnrollmentPluginController extends ActionController
{
    public function indexAction(): ResponseInterface
    {
        $site = $this->request->getAttribute('site');
        $baseUrl = \rtrim((string) ($site?->getBase() ?? ''), '/');
        $eidUrl = $baseUrl . '/?eID=nr_passkeys_fe';

        $this->view->assignMultiple([
            'eidUrl' => $eidUrl,
            'siteIdentifier' => $site?->getIdentifier() ?? '',
            'registerOptionsUrl' => $eidUrl . '&action=registrationOptions',
            'registerVerifyUrl' => $eidUrl . '&action=registrationVerify',
            'enforcementLevel' => 'off',
            'skipUrl' => $eidUrl . '&action=enrollmentSkip',
        ]);

        return $this->htmlResponse();
    }
}
