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
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * eID dispatcher for the nr_passkeys_fe JSON API.
 *
 * Registered in ext_localconf.php as the eID handler for 'nr_passkeys_fe'.
 * Routes incoming requests to the appropriate FE controller action based on
 * the 'action' query parameter. Enforces FE authentication for non-public
 * actions before delegating to the controller.
 *
 * NOTE: eID handlers run before the TYPO3 FrontendUserAuthenticator middleware,
 * so the 'frontend.user' request attribute is not set automatically. This
 * dispatcher manually bootstraps the FE user session for authenticated actions.
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

        // Bootstrap FE user session for eID requests.
        // The TYPO3 FrontendUserAuthenticator middleware runs AFTER the EidHandler
        // middleware in the middleware stack, so 'frontend.user' is never set for
        // eID requests. We must manually resolve the session here.
        $request = $this->bootstrapFrontendUser($request);

        // Enforce FE authentication for non-public actions
        if (!\in_array($action, self::PUBLIC_ACTIONS, true)) {
            $feUser = $request->getAttribute('frontend.user');
            if (!$feUser instanceof FrontendUserAuthentication) {
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
            // Dynamic dispatch: ACTION_MAP guarantees method exists (see phpstan-baseline.neon)
            return $controller->$method($request);
        } catch (Throwable) {
            return new JsonResponse(['error' => 'Internal error'], 500);
        }
    }

    /**
     * Manually bootstrap the FE user session for the current request.
     *
     * Mirrors the essential logic of {@see \TYPO3\CMS\Frontend\Middleware\FrontendUserAuthenticator}:
     * instantiates FrontendUserAuthentication, starts the session (reads cookies),
     * fetches group data, and sets the request attribute + Context aspect so that
     * downstream controllers can use $request->getAttribute('frontend.user').
     */
    private function bootstrapFrontendUser(ServerRequestInterface $request): ServerRequestInterface
    {
        // If the attribute is already set (e.g. future TYPO3 versions fix the
        // middleware order for eID), skip bootstrapping to avoid double init.
        if ($request->getAttribute('frontend.user') instanceof FrontendUserAuthentication) {
            return $request;
        }

        $frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
        $frontendUser->start($request);
        $frontendUser->fetchGroupData($request);

        // Register in Context so that Context-based access also works
        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('frontend.user', $frontendUser->createUserAspect());

        return $request->withAttribute('frontend.user', $frontendUser);
    }
}
