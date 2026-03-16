<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

#[CoversClass(FrontendWebAuthnService::class)]
final class FrontendWebAuthnServiceTest extends TestCase
{
    private FrontendCredentialRepository&MockObject $credentialRepository;
    private SiteConfigurationService&MockObject $siteConfigService;
    private FrontendConfiguration $configuration;
    private FrontendWebAuthnService $subject;
    private SiteInterface&MockObject $site;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up TYPO3 encryption key for user handle derivation
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = \str_repeat('a', 64);

        $this->credentialRepository = $this->createMock(FrontendCredentialRepository::class);
        $this->siteConfigService = $this->createMock(SiteConfigurationService::class);
        $this->configuration = new FrontendConfiguration();

        $this->subject = new FrontendWebAuthnService(
            $this->credentialRepository,
            $this->siteConfigService,
            $this->configuration,
            new NullLogger(),
        );

        $this->site = $this->createMock(SiteInterface::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Constructor & dependency wiring
    // ---------------------------------------------------------------

    #[Test]
    public function canBeInstantiated(): void
    {
        self::assertInstanceOf(FrontendWebAuthnService::class, $this->subject);
    }

    // ---------------------------------------------------------------
    // createRegistrationOptions()
    // ---------------------------------------------------------------

    #[Test]
    public function createRegistrationOptionsUsesRpIdFromSiteConfigService(): void
    {
        $this->siteConfigService->method('getRpId')->with($this->site)->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->with($this->site)->willReturn('main');
        $this->credentialRepository->method('findByFeUser')->willReturn([]);

        $challenge = \random_bytes(32);
        $result = $this->subject->createRegistrationOptions(1, 'testuser', $challenge, $this->site);

        self::assertArrayHasKey('options', $result);
        self::assertArrayHasKey('optionsJson', $result);

        $options = $result['options'];
        self::assertSame('example.com', $options->rp->id);
    }

    #[Test]
    public function createRegistrationOptionsExcludesExistingCredentials(): void
    {
        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $existingCred = new FrontendCredential(
            credentialId: 'existing-cred-id',
            transports: '["usb"]',
        );

        $this->credentialRepository->method('findByFeUser')
            ->with(1, 'main')
            ->willReturn([$existingCred]);

        $challenge = \random_bytes(32);
        $result = $this->subject->createRegistrationOptions(1, 'testuser', $challenge, $this->site);

        $options = $result['options'];
        self::assertCount(1, $options->excludeCredentials);
    }

    #[Test]
    public function createRegistrationOptionsPassesChallengeThrough(): void
    {
        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');
        $this->credentialRepository->method('findByFeUser')->willReturn([]);

        $challenge = \random_bytes(32);
        $result = $this->subject->createRegistrationOptions(1, 'testuser', $challenge, $this->site);

        self::assertSame($challenge, $result['options']->challenge);
    }

    // ---------------------------------------------------------------
    // createAssertionOptions()
    // ---------------------------------------------------------------

    #[Test]
    public function createAssertionOptionsUsesRpIdFromSiteConfig(): void
    {
        $this->siteConfigService->method('getRpId')->with($this->site)->willReturn('login.example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');
        $this->credentialRepository->method('findByFeUser')->willReturn([]);

        $challenge = \random_bytes(32);
        $result = $this->subject->createAssertionOptions(1, $challenge, $this->site);

        self::assertArrayHasKey('options', $result);
        self::assertArrayHasKey('optionsJson', $result);
        self::assertSame('login.example.com', $result['options']->rpId);
    }

    #[Test]
    public function createAssertionOptionsIncludesUserCredentials(): void
    {
        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $cred1 = new FrontendCredential(credentialId: 'cred-1', transports: '["usb"]');
        $cred2 = new FrontendCredential(credentialId: 'cred-2', transports: '["ble"]');

        $this->credentialRepository->method('findByFeUser')
            ->with(7, 'main')
            ->willReturn([$cred1, $cred2]);

        $challenge = \random_bytes(32);
        $result = $this->subject->createAssertionOptions(7, $challenge, $this->site);

        self::assertCount(2, $result['options']->allowCredentials);
    }

    // ---------------------------------------------------------------
    // createDiscoverableAssertionOptions()
    // ---------------------------------------------------------------

    #[Test]
    public function createDiscoverableAssertionOptionsHasEmptyAllowCredentials(): void
    {
        $this->siteConfigService->method('getRpId')->with($this->site)->willReturn('example.com');

        $challenge = \random_bytes(32);
        $result = $this->subject->createDiscoverableAssertionOptions($challenge, $this->site);

        self::assertSame([], $result['options']->allowCredentials);
        self::assertSame('example.com', $result['options']->rpId);
    }

    // ---------------------------------------------------------------
    // findFeUserUidFromAssertion() — tests that don't need real WebAuthn lib
    // ---------------------------------------------------------------

    #[Test]
    public function findFeUserUidFromAssertionReturnsNullOnInvalidJson(): void
    {
        // This will throw during deserialization, which is caught
        self::assertNull($this->subject->findFeUserUidFromAssertion('not-json'));
    }

    #[Test]
    public function findFeUserUidFromAssertionReturnsNullOnEmptyJson(): void
    {
        self::assertNull($this->subject->findFeUserUidFromAssertion('{}'));
    }

    // ---------------------------------------------------------------
    // User handle derivation
    // ---------------------------------------------------------------

    #[Test]
    public function userHandleIsDeterministicForSameUser(): void
    {
        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');
        $this->credentialRepository->method('findByFeUser')->willReturn([]);

        $challenge = \random_bytes(32);

        $result1 = $this->subject->createRegistrationOptions(42, 'user', $challenge, $this->site);
        $result2 = $this->subject->createRegistrationOptions(42, 'user', $challenge, $this->site);

        self::assertSame($result1['options']->user->id, $result2['options']->user->id);
    }

    #[Test]
    public function userHandleDiffersForDifferentUsers(): void
    {
        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');
        $this->credentialRepository->method('findByFeUser')->willReturn([]);

        $challenge = \random_bytes(32);

        $result1 = $this->subject->createRegistrationOptions(1, 'user1', $challenge, $this->site);
        $result2 = $this->subject->createRegistrationOptions(2, 'user2', $challenge, $this->site);

        self::assertNotSame($result1['options']->user->id, $result2['options']->user->id);
    }

    // ---------------------------------------------------------------
    // Encryption key validation
    // ---------------------------------------------------------------

    #[Test]
    public function throwsWhenEncryptionKeyTooShort(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'short';

        $service = new FrontendWebAuthnService(
            $this->credentialRepository,
            $this->siteConfigService,
            $this->configuration,
            new NullLogger(),
        );

        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');
        $this->credentialRepository->method('findByFeUser')->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700200040);

        $service->createRegistrationOptions(1, 'user', \random_bytes(32), $this->site);
    }

    #[Test]
    public function throwsWhenEncryptionKeyMissing(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);

        $service = new FrontendWebAuthnService(
            $this->credentialRepository,
            $this->siteConfigService,
            $this->configuration,
            new NullLogger(),
        );

        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');
        $this->credentialRepository->method('findByFeUser')->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700200040);

        $service->createRegistrationOptions(1, 'user', \random_bytes(32), $this->site);
    }
}
