<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller;

use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\FrontendLoginAssurance;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;

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
        'loginOptions'        => [LoginController::class, 'optionsAction'],
        'loginVerify'         => [LoginController::class, 'verifyAction'],
        'registrationOptions' => [ManagementController::class, 'registrationOptionsAction'],
        'registrationVerify'  => [ManagementController::class, 'registrationVerifyAction'],
        'manageList'          => [ManagementController::class, 'listAction'],
        'manageRename'        => [ManagementController::class, 'renameAction'],
        'manageRemove'        => [ManagementController::class, 'removeAction'],
        'recoveryGenerate'    => [RecoveryController::class, 'generateAction'],
        'recoveryVerify'      => [RecoveryController::class, 'verifyAction'],
        'enrollmentStatus'    => [EnrollmentController::class, 'statusAction'],
        'enrollmentSkip'      => [EnrollmentController::class, 'skipAction'],
    ];

    /**
     * Actions that do not require an authenticated FE user.
     *
     * @var list<string>
     */
    public const PUBLIC_ACTIONS = ['loginOptions', 'loginVerify', 'recoveryVerify'];

    public function processRequest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->dispatch($request)->withHeader('Cache-Control', 'private, no-store');
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $action = $request->getQueryParams()['action'] ?? '';

        if (!is_string($action) || !isset(self::ACTION_MAP[$action])) {
            return new JsonResponse(['error' => 'Unknown action'], 404);
        }

        $allowed = match ($action) {
            'manageList', 'enrollmentStatus' => ['GET'],
            'loginOptions'                   => ['GET', 'POST'],
            default                          => ['POST'],
        };
        if (!in_array($request->getMethod(), $allowed, true)) {
            return new JsonResponse(['error' => 'Method not allowed'], 405, ['Allow' => implode(', ', $allowed)]);
        }

        // A browser supplies Origin on POST. Require an exact origin match before
        // any cookie-authorized mutation, including requests routed through eID.
        if ($request->getMethod() === 'POST' && !in_array($action, self::PUBLIC_ACTIONS, true)) {
            $uri    = $request->getUri();
            $origin = $uri->getScheme() . '://' . $uri->getAuthority();
            if ($uri->getHost() === '' || $request->getHeaderLine('Origin') !== $origin) {
                return new JsonResponse(['error' => 'Same-origin request required'], 403);
            }
        }

        if (in_array($action, ['recoveryGenerate', 'recoveryVerify'], true)) {
            $site = $request->getAttribute('site');
            if (!$site instanceof Site || !SiteConfigurationService::allowsRecoveryMethod($site, 'recovery_code')) {
                return new JsonResponse(['error' => 'Recovery codes are disabled for this site'], 403);
            }
        }

        [$controllerClass, $method] = self::ACTION_MAP[$action];

        // Public actions (login, recovery verify) do not require an FE session
        if (in_array($action, self::PUBLIC_ACTIONS, true)) {
            // loginVerify and recoveryVerify need a FE session to write session flags
            if ($action === 'loginVerify' || $action === 'recoveryVerify') {
                $request = $this->bootstrapFrontendUser($request);
            }

            /** @var LoginController|ManagementController|RecoveryController|EnrollmentController $controller */
            $controller = GeneralUtility::makeInstance($controllerClass);

            try {
                return $controller->$method($request);
            } catch (Throwable $e) {
                GeneralUtility::makeInstance(LogManager::class)
                    ->getLogger(self::class)
                    ->error('eID action failed', ['action' => $action, 'error' => $e->getMessage()]);

                return new JsonResponse(['error' => 'Internal error'], 500);
            }
        }

        // Bootstrap FE user session for authenticated eID requests.
        // The TYPO3 FrontendUserAuthenticator middleware runs AFTER the EidHandler
        // middleware in the middleware stack, so 'frontend.user' is never set for
        // eID requests. We must manually resolve the session here.
        $request = $this->bootstrapFrontendUser($request);

        // Enforce FE authentication for non-public actions
        $feUser = $request->getAttribute('frontend.user');
        if (!$feUser instanceof FrontendUserAuthentication) {
            return new JsonResponse(['error' => 'Authentication required'], 401);
        }

        $userRow   = $feUser->user;
        $feUserUid = is_array($userRow) && is_numeric($userRow['uid'] ?? null)
            ? (int) $userRow['uid']
            : 0;

        if ($feUserUid === 0) {
            return new JsonResponse(['error' => 'Authentication required'], 401);
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site || $site->getIdentifier() === '') {
            return new JsonResponse(['error' => 'A configured site is required'], 403);
        }

        $siteIdentifier = $site->getIdentifier();
        $status         = GeneralUtility::makeInstance(FrontendEnforcementService::class)->getStatus($feUserUid, $siteIdentifier, $site);
        $proof          = FrontendLoginAssurance::siteProof($feUser, $feUserUid, $siteIdentifier);
        if (!FrontendLoginAssurance::allowsSiteAccess($proof['method'] ?? null, $site, $status)) {
            return new JsonResponse(['error' => 'Please sign in again on this site.'], 403);
        }

        if (in_array($action, ['registrationOptions', 'registrationVerify', 'recoveryGenerate', 'manageRemove'], true)) {
            if (!FrontendLoginAssurance::allowsManagement($feUser, $feUserUid, $siteIdentifier, $status->passkeyCount > 0)) {
                return new JsonResponse(['error' => 'Sign out and sign in again to manage passkeys. Use an existing passkey or recovery code if you have one.'], 403);
            }
        }

        /** @var LoginController|ManagementController|RecoveryController|EnrollmentController $controller */
        $controller = GeneralUtility::makeInstance($controllerClass);

        try {
            // Dynamic dispatch: ACTION_MAP guarantees method exists (see phpstan-baseline.neon)
            return $controller->$method($request);
        } catch (Throwable $e) {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(self::class)
                ->error('eID action failed', ['action' => $action, 'error' => $e->getMessage()]);

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
