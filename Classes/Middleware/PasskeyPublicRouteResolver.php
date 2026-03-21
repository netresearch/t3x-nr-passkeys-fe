<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Middleware;

use Netresearch\NrPasskeysFe\Controller\EidDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Marks eID requests for public passkey actions so downstream middleware
 * (especially authentication) can exempt them.
 *
 * The three passkey actions that must be accessible without an authenticated
 * session are: loginOptions, loginVerify, and recoveryVerify. This middleware
 * detects them and sets the request attribute `nr_passkeys_fe.public_route`
 * to `true`, which PasskeyEnrollmentInterstitial (and any other middleware)
 * can check to avoid intercepting these requests.
 */
final readonly class PasskeyPublicRouteResolver implements MiddlewareInterface
{
    private const EID = 'nr_passkeys_fe';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isPublicAction($request)) {
            $request = $request->withAttribute('nr_passkeys_fe.public_route', true);
        }

        return $handler->handle($request);
    }

    private function isPublicAction(ServerRequestInterface $request): bool
    {
        $queryParams = $request->getQueryParams();

        $eId = $queryParams['eID'] ?? null;
        if ($eId !== self::EID) {
            return false;
        }

        $action = $queryParams['action'] ?? null;
        if (!\is_string($action)) {
            return false;
        }

        return \in_array($action, EidDispatcher::PUBLIC_ACTIONS, true);
    }
}
