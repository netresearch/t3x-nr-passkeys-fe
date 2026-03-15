<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * eID dispatcher for the nr_passkeys_fe JSON API.
 *
 * Registered in ext_localconf.php as the eID handler for 'nr_passkeys_fe'.
 * Routes incoming requests to the appropriate FE controller action based on
 * the 'action' query parameter. Enforces FE authentication for non-public
 * actions before delegating to the controller.
 */
final class EidDispatcher
{
    /**
     * Maps action name → [ControllerClass, method].
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    private const ACTION_MAP = [
        'loginOptions'          => [LoginController::class, 'optionsAction'],
        'loginVerify'           => [LoginController::class, 'verifyAction'],
        'registrationOptions'   => [ManagementController::class, 'registrationOptionsAction'],
        'registrationVerify'    => [ManagementController::class, 'registrationVerifyAction'],
        'manageList'            => [ManagementController::class, 'listAction'],
        'manageRename'          => [ManagementController::class, 'renameAction'],
        'manageRemove'          => [ManagementController::class, 'removeAction'],
        'recoveryGenerate'      => [RecoveryController::class, 'generateAction'],
        'recoveryVerify'        => [RecoveryController::class, 'verifyAction'],
        'enrollmentStatus'      => [EnrollmentController::class, 'statusAction'],
        'enrollmentSkip'        => [EnrollmentController::class, 'skipAction'],
    ];

    /**
     * Actions that do not require an authenticated FE user.
     *
     * @var list<string>
     */
    public const PUBLIC_ACTIONS = ['loginOptions', 'loginVerify', 'recoveryVerify'];

    public function processRequest(ServerRequestInterface $request): ResponseInterface
    {
        $action = $request->getQueryParams()['action'] ?? '';

        if (!\is_string($action) || !isset(self::ACTION_MAP[$action])) {
            return new JsonResponse(['error' => 'Unknown action'], 404);
        }

        // Enforce FE authentication for non-public actions
        if (!\in_array($action, self::PUBLIC_ACTIONS, true)) {
            $feUser = $request->getAttribute('frontend.user');
            if (!$feUser instanceof \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication) {
                return new JsonResponse(['error' => 'Authentication required'], 401);
            }

            $userRow = $feUser->user;
            $feUserUid = \is_array($userRow) && \is_numeric($userRow['uid'] ?? null)
                ? (int) $userRow['uid']
                : 0;

            if ($feUserUid === 0) {
                return new JsonResponse(['error' => 'Authentication required'], 401);
            }
        }

        [$controllerClass, $method] = self::ACTION_MAP[$action];

        /** @var LoginController|ManagementController|RecoveryController|EnrollmentController $controller */
        $controller = GeneralUtility::makeInstance($controllerClass);

        try {
            /** @phpstan-ignore method.notFound (dynamic dispatch based on ACTION_MAP) */
            return $controller->$method($request);
        } catch (Throwable) {
            return new JsonResponse(['error' => 'Internal error'], 500);
        }
    }
}
