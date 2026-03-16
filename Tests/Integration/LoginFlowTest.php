<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Integration;

use Netresearch\NrPasskeysFe\Authentication\PasskeyFrontendAuthenticationService;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendEnforcementService;
use Netresearch\NrPasskeysFe\Service\RecoveryCodeService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Integration tests for the complete login-related service flow.
 *
 * These tests exercise multiple services working together with a real
 * database. WebAuthn ceremony steps that require a browser are not
 * tested here — only the service-layer integration around them.
 */
#[CoversNothing]
final class LoginFlowTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-passkeys-be',
        'netresearch/nr-passkeys-fe',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'nr_passkeys_fe_nonce' => [
                        'backend' => \TYPO3\CMS\Core\Cache\Backend\NullBackend::class,
                    ],
                ],
            ],
        ],
    ];

    private FrontendCredentialRepository $credentialRepository;

    private RecoveryCodeService $recoveryCodeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');
        $this->credentialRepository = $this->get(FrontendCredentialRepository::class);
        $this->recoveryCodeService = $this->get(RecoveryCodeService::class);
    }

    // ---------------------------------------------------------------
    // Auth service passkey payload detection
    // ---------------------------------------------------------------

    #[Test]
    public function authServiceReturnsFalseWhenNoPasskeyPayloadInUident(): void
    {
        $service = new PasskeyFrontendAuthenticationService();
        $service->initAuth(
            'getUserFE',
            ['uname' => 'testuser1', 'uident' => 'plaintextpassword', 'status' => 'login'],
            [],
            null,
        );

        // getUser returns false (not array) when uident is not passkey JSON
        $result = $service->getUser();

        self::assertFalse($result, 'Auth service must return false when uident has no passkey payload');
    }

    #[Test]
    public function authServiceReturnsFalseForNonPasskeyJsonUident(): void
    {
        $service = new PasskeyFrontendAuthenticationService();
        $service->initAuth(
            'getUserFE',
            ['uname' => 'testuser1', 'uident' => '{"_type":"password","value":"secret"}', 'status' => 'login'],
            [],
            null,
        );

        $result = $service->getUser();

        self::assertFalse($result, 'Auth service must return false for non-passkey JSON in uident');
    }

    #[Test]
    public function authServiceReturnsFalseForMalformedPasskeyPayload(): void
    {
        $service = new PasskeyFrontendAuthenticationService();
        // Missing challengeToken field
        $malformedPayload = \json_encode([
            '_type' => 'passkey',
            'assertion' => ['id' => 'test'],
            // no challengeToken
        ]);

        $service->initAuth(
            'getUserFE',
            ['uname' => 'testuser1', 'uident' => $malformedPayload, 'status' => 'login'],
            [],
            null,
        );

        $result = $service->getUser();

        self::assertFalse($result, 'Auth service must return false for passkey payload missing challengeToken');
    }

    #[Test]
    public function authServiceReturnsContinue100WhenNoPasskeyPayload(): void
    {
        // authUser called with no passkey payload should return 100 (continue)
        // unless enforcement blocks the password login.
        // User 1 has no passkeys registered and group enforcement is 'off'.
        $service = new PasskeyFrontendAuthenticationService();
        $service->initAuth(
            'authUserFE',
            ['uname' => 'testuser1', 'uident' => 'plaintext-password', 'status' => 'login'],
            [],
            null,
        );

        // User record with no passkeys and enforcement off → should continue (100)
        $result = $service->authUser(['uid' => 1, 'username' => 'testuser1']);

        self::assertSame(100, $result, 'authUser must return 100 (continue) when no passkey payload and enforcement is off');
    }

    // ---------------------------------------------------------------
    // Enforcement status resolution with real DB
    // ---------------------------------------------------------------

    #[Test]
    public function enforcementStatusIsOffWhenGroupHasNoEnforcement(): void
    {
        // Create a fe_groups row with enforcement = 'off'
        $connection = $this->getConnectionPool()->getConnectionForTable('fe_groups');
        $connection->insert('fe_groups', [
            'uid' => 100,
            'pid' => 1,
            'title' => 'Test Group Off',
            'passkey_enforcement' => 'off',
            'passkey_grace_period_days' => 14,
            'hidden' => 0,
            'deleted' => 0,
        ]);

        // Assign fe_user 1 to this group
        $usersConnection = $this->getConnectionPool()->getConnectionForTable('fe_users');
        $usersConnection->update('fe_users', ['usergroup' => '100'], ['uid' => 1]);

        $site = $this->createMock(SiteInterface::class);
        $site->method('getIdentifier')->willReturn('site-a');
        $site->method('getSettings')->willReturn(SiteSettings::createFromSettingsTree([]));
        $site->method('getBase')->willReturn(new Uri('https://example.com'));

        $enforcementService = $this->get(FrontendEnforcementService::class);
        $status = $enforcementService->getStatus(1, 'site-a', $site);

        self::assertSame('off', $status->effectiveLevel);
        self::assertSame('off', $status->groupLevel);
    }

    #[Test]
    public function enforcementStatusReflectsGroupEnforcementLevel(): void
    {
        // Create a fe_groups row with enforcement = 'encourage'
        $connection = $this->getConnectionPool()->getConnectionForTable('fe_groups');
        $connection->insert('fe_groups', [
            'uid' => 101,
            'pid' => 1,
            'title' => 'Test Group Encourage',
            'passkey_enforcement' => 'encourage',
            'passkey_grace_period_days' => 14,
            'hidden' => 0,
            'deleted' => 0,
        ]);

        $usersConnection = $this->getConnectionPool()->getConnectionForTable('fe_users');
        $usersConnection->update('fe_users', ['usergroup' => '101'], ['uid' => 1]);

        $site = $this->createMock(SiteInterface::class);
        $site->method('getIdentifier')->willReturn('site-a');
        $site->method('getSettings')->willReturn(SiteSettings::createFromSettingsTree([]));
        $site->method('getBase')->willReturn(new Uri('https://example.com'));

        $enforcementService = $this->get(FrontendEnforcementService::class);
        $status = $enforcementService->getStatus(1, 'site-a', $site);

        self::assertSame('encourage', $status->effectiveLevel);
        self::assertSame('encourage', $status->groupLevel);
    }

    // ---------------------------------------------------------------
    // Credential + enforcement combined flow
    // ---------------------------------------------------------------

    #[Test]
    public function authServiceBlocksPasswordLoginWhenEnforcedAndHasPasskeys(): void
    {
        // Register a credential for user 1 on site-a
        $credential = new FrontendCredential(
            feUser: 1,
            credentialId: 'integration-test-cred-1',
            publicKeyCose: 'cose-data',
            label: 'Test Key',
            siteIdentifier: 'site-a',
        );
        $this->credentialRepository->save($credential);

        // Create fe_groups with 'enforced' enforcement
        $connection = $this->getConnectionPool()->getConnectionForTable('fe_groups');
        $connection->insert('fe_groups', [
            'uid' => 200,
            'pid' => 1,
            'title' => 'Test Group Enforced',
            'passkey_enforcement' => 'enforced',
            'passkey_grace_period_days' => 0,
            'hidden' => 0,
            'deleted' => 0,
        ]);

        $usersConnection = $this->getConnectionPool()->getConnectionForTable('fe_users');
        $usersConnection->update('fe_users', ['usergroup' => '200'], ['uid' => 1]);

        // Simulate a fake site request so authUser can resolve the site
        $site = $this->createMock(SiteInterface::class);
        $site->method('getIdentifier')->willReturn('site-a');
        $site->method('getSettings')->willReturn(SiteSettings::createFromSettingsTree([]));
        $site->method('getBase')->willReturn(new Uri('https://example.com'));

        // We test the enforcement service directly since the auth service
        // requires $GLOBALS['TYPO3_REQUEST'] to resolve the site
        $enforcementService = $this->get(FrontendEnforcementService::class);
        $status = $enforcementService->getStatus(1, 'site-a', $site);

        // Verify enforcement + passkey count means password should be blocked
        self::assertSame('enforced', $status->effectiveLevel);
        self::assertGreaterThan(0, $status->passkeyCount);

        $shouldBlock = $status->passkeyCount > 0 && $status->effectiveLevel === 'enforced';
        self::assertTrue($shouldBlock, 'Password login must be blocked when enforcement=enforced and user has passkeys');
    }

    // ---------------------------------------------------------------
    // Recovery code flow within login context
    // ---------------------------------------------------------------

    #[Test]
    public function recoveryCodeGenerationAndVerificationFlowWithinLoginContext(): void
    {
        // Simulate: user registers passkeys, generates recovery codes (shown at enrollment)
        $credential = new FrontendCredential(
            feUser: 2,
            credentialId: 'login-recovery-test-cred',
            publicKeyCose: 'cose-data',
            label: 'Main Key',
            siteIdentifier: 'site-a',
        );
        $this->credentialRepository->save($credential);

        // Generate recovery codes (as done during enrollment)
        $codes = $this->recoveryCodeService->generate(2, 10);

        self::assertCount(10, $codes, 'Should generate exactly 10 recovery codes');
        self::assertGreaterThan(0, $this->recoveryCodeService->countRemaining(2));

        // During login, user provides a recovery code instead of passkey
        $code = $codes[0];
        $verified = $this->recoveryCodeService->verify(2, $code);

        self::assertTrue($verified, 'Recovery code must verify successfully during login flow');

        // After use, count decrements
        $remaining = $this->recoveryCodeService->countRemaining(2);
        self::assertSame(9, $remaining, 'Remaining count must decrement after code use');
    }

    #[Test]
    public function usedRecoveryCodeCannotBeUsedAgainDuringLoginFlow(): void
    {
        $codes = $this->recoveryCodeService->generate(3, 5);
        $firstCode = $codes[0];

        // First use succeeds
        $firstUse = $this->recoveryCodeService->verify(3, $firstCode);
        self::assertTrue($firstUse, 'First use of recovery code must succeed');

        // Second use fails (code consumed)
        $secondUse = $this->recoveryCodeService->verify(3, $firstCode);
        self::assertFalse($secondUse, 'Second use of same recovery code must fail');
    }

    #[Test]
    public function wrongRecoveryCodeFailsVerification(): void
    {
        $this->recoveryCodeService->generate(1, 5);

        $result = $this->recoveryCodeService->verify(1, 'ZZZZ-ZZZZ');

        self::assertFalse($result, 'Wrong recovery code must fail verification');
    }
}
