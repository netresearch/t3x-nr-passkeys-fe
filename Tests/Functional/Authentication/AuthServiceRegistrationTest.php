<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Authentication;

use Netresearch\NrPasskeysFe\Authentication\PasskeyFrontendAuthenticationService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies that PasskeyFrontendAuthenticationService is properly registered
 * in the TYPO3 service chain with the expected priority and subtypes.
 */
final class AuthServiceRegistrationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-passkeys-be',
        'netresearch/nr-passkeys-fe',
    ];

    #[Test]
    public function passkeyAuthServiceIsRegisteredInT3Services(): void
    {
        $services = $GLOBALS['T3_SERVICES']['auth'] ?? [];

        self::assertArrayHasKey(
            PasskeyFrontendAuthenticationService::class,
            $services,
            'PasskeyFrontendAuthenticationService must be registered in T3_SERVICES[auth]',
        );
    }

    #[Test]
    public function passkeyAuthServiceHasPriority80(): void
    {
        $service = $GLOBALS['T3_SERVICES']['auth'][PasskeyFrontendAuthenticationService::class] ?? [];

        self::assertSame(
            80,
            $service['priority'] ?? null,
            'PasskeyFrontendAuthenticationService must have priority 80',
        );
    }

    #[Test]
    public function passkeyAuthServiceHasAuthUserFESubtype(): void
    {
        $service = $GLOBALS['T3_SERVICES']['auth'][PasskeyFrontendAuthenticationService::class] ?? [];
        $subtype = $service['subtype'] ?? '';

        self::assertStringContainsString(
            'authUserFE',
            $subtype,
            'PasskeyFrontendAuthenticationService subtype must include authUserFE',
        );
    }

    #[Test]
    public function passkeyAuthServiceHasGetUserFESubtype(): void
    {
        $service = $GLOBALS['T3_SERVICES']['auth'][PasskeyFrontendAuthenticationService::class] ?? [];
        $subtype = $service['subtype'] ?? '';

        self::assertStringContainsString(
            'getUserFE',
            $subtype,
            'PasskeyFrontendAuthenticationService subtype must include getUserFE',
        );
    }

    #[Test]
    public function passkeyAuthServiceIsAvailable(): void
    {
        $service = $GLOBALS['T3_SERVICES']['auth'][PasskeyFrontendAuthenticationService::class] ?? [];

        self::assertTrue(
            $service['available'] ?? false,
            'PasskeyFrontendAuthenticationService must be marked available',
        );
    }

    #[Test]
    public function passkeyAuthServiceClassMatchesRegistration(): void
    {
        $service = $GLOBALS['T3_SERVICES']['auth'][PasskeyFrontendAuthenticationService::class] ?? [];

        self::assertSame(
            PasskeyFrontendAuthenticationService::class,
            $service['className'] ?? null,
            'Registered className must match PasskeyFrontendAuthenticationService',
        );
    }
}
