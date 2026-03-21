<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture rules enforced via PHPStan + phpat.
 *
 * Layering (inner -> outer):
 *   Domain (Model + Dto) -> Service -> Controller / Auth / Middleware / EventListener
 *
 * Invariants:
 *   - Domain layer must not depend on extension infrastructure namespaces
 *   - Services must not depend on the HTTP/controller layer
 *   - Controllers must not depend on each other
 *   - Controllers must not access database directly (use repositories)
 *   - Events are independent data-carriers (no service/controller deps)
 *   - All leaf classes must be final
 */
final class ArchitectureTest
{
    private const NS = 'Netresearch\\NrPasskeysFe\\';

    // --- Layer isolation ---------------------------------------------------------

    public function test_domain_does_not_depend_on_infrastructure(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Domain'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'Authentication'),
                Selector::inNamespace(self::NS . 'EventListener'),
                Selector::inNamespace(self::NS . 'Form'),
                Selector::inNamespace(self::NS . 'Service'),
            )
            ->because('Domain layer (Model + Dto) must have zero outward dependencies within the extension');
    }

    public function test_services_do_not_depend_on_http_layer(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Service'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'EventListener'),
                Selector::inNamespace(self::NS . 'Form'),
            )
            ->because('Services must not depend on HTTP handlers or UI components');
    }

    public function test_controllers_do_not_access_database_directly(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Controller'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::classname('TYPO3\\CMS\\Core\\Database\\ConnectionPool'),
                Selector::classname('TYPO3\\CMS\\Core\\Database\\Query\\QueryBuilder'),
            )
            ->because('Controllers must use repository services, not access the database directly');
    }

    public function test_event_listeners_do_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'EventListener'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'Authentication'),
                Selector::inNamespace(self::NS . 'Form'),
            )
            ->because('Event listeners inject view data and must not depend on controller or auth layer');
    }

    public function test_authentication_does_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Authentication'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'EventListener'),
                Selector::inNamespace(self::NS . 'Form'),
            )
            ->because('Authentication service is infrastructure, not an HTTP consumer');
    }

    public function test_events_are_independent_data_carriers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Event'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Service'),
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'Authentication'),
                Selector::inNamespace(self::NS . 'EventListener'),
                Selector::inNamespace(self::NS . 'Form'),
            )
            ->because('Event classes are pure data carriers and must have no behavioral dependencies');
    }

    public function test_form_elements_do_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Form'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'Authentication'),
                Selector::inNamespace(self::NS . 'EventListener'),
            )
            ->because('FormEngine elements render data, they must not depend on controllers');
    }

    // --- Finality enforcement ----------------------------------------------------

    public function test_all_services_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Service'))
            ->should()->beFinal()
            ->because('Services are leaf classes — composition over inheritance');
    }

    public function test_all_controllers_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Controller'))
            ->excluding(Selector::isInterface())
            ->should()->beFinal()
            ->because('Controllers are leaf classes — composition over inheritance');
    }

    public function test_all_dtos_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Domain\\Dto'))
            ->should()->beFinal()
            ->because('DTOs are immutable value objects that must not be extended');
    }

    public function test_domain_models_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Domain\\Model'))
            ->should()->beFinal()
            ->because('Domain models are entities that must not be extended');
    }

    public function test_event_listeners_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'EventListener'))
            ->should()->beFinal()
            ->because('Event listeners are leaf classes — composition over inheritance');
    }

    public function test_authentication_service_is_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Authentication'))
            ->should()->beFinal()
            ->because('Authentication service is a leaf class — composition over inheritance');
    }

    public function test_middleware_is_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Middleware'))
            ->should()->beFinal()
            ->because('Middleware is a leaf class — composition over inheritance');
    }
}
