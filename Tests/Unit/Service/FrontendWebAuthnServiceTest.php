<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use Webauthn\CredentialRecord;

#[CoversClass(FrontendWebAuthnService::class)]
final class FrontendWebAuthnServiceTest extends TestCase
{
    private FrontendCredentialRepository&Stub $credentialRepository;

    private SiteConfigurationService&Stub $siteConfigService;

    private FrontendWebAuthnService $subject;

    private SiteInterface&Stub $site;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up TYPO3 encryption key for user handle derivation
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = \str_repeat('a', 64);

        $this->credentialRepository = $this->createStub(FrontendCredentialRepository::class);
        $this->siteConfigService = $this->createStub(SiteConfigurationService::class);

        $this->subject = new FrontendWebAuthnService(
            $this->credentialRepository,
            $this->siteConfigService,
            new NullLogger(),
        );

        $this->site = $this->createStub(SiteInterface::class);
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
        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');
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
        $this->siteConfigService->method('getRpId')->willReturn('login.example.com');
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
        $this->siteConfigService->method('getRpId')->willReturn('example.com');

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
            new NullLogger(),
        );

        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');
        $this->credentialRepository->method('findByFeUser')->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700200040);

        $service->createRegistrationOptions(1, 'user', \random_bytes(32), $this->site);
    }

    // ---------------------------------------------------------------
    // Assertion handling with a syntactically valid credential payload
    // ---------------------------------------------------------------

    /**
     * A minimal assertion payload the webauthn serializer accepts: all
     * binary fields are base64url, authenticatorData is rpIdHash(32) +
     * flags(1) + signCount(4).
     */
    private function buildAssertionJson(string $credentialId = 'credential-id-123'): string
    {
        $b64 = static fn(string $bin): string => \rtrim(\strtr(\base64_encode($bin), '+/', '-_'), '=');

        $clientData = \json_encode([
            'type' => 'webauthn.get',
            'challenge' => $b64(\random_bytes(32)),
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        return \json_encode([
            'id' => $b64($credentialId),
            'rawId' => $b64($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $b64($clientData),
                'authenticatorData' => $b64(\str_repeat("\x00", 37)),
                'signature' => $b64('sig'),
                'userHandle' => null,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function verifyAssertionResponseRejectsUnknownCredential(): void
    {
        $this->siteConfigService->method('getRpId')->willReturn('example.com');
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');
        // Repository stub returns null by default: credential ID is unknown

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(FrontendWebAuthnService::CODE_UNKNOWN_CREDENTIAL);

        $this->subject->verifyAssertionResponse($this->buildAssertionJson(), 'challenge', $this->site);
    }

    #[Test]
    public function findFeUserUidFromAssertionReturnsNullForUnknownCredential(): void
    {
        // Repository stub returns null by default
        self::assertNull($this->subject->findFeUserUidFromAssertion($this->buildAssertionJson()));
    }

    #[Test]
    public function findFeUserUidFromAssertionReturnsNullForRevokedCredential(): void
    {
        $revoked = new FrontendCredential(feUser: 42, credentialId: 'credential-id-123', revokedAt: 1700000000);
        $this->credentialRepository->method('findByCredentialId')->willReturn($revoked);

        self::assertNull($this->subject->findFeUserUidFromAssertion($this->buildAssertionJson()));
    }

    #[Test]
    public function findFeUserUidFromAssertionReturnsOwnerForActiveCredential(): void
    {
        $credential = new FrontendCredential(feUser: 42, credentialId: 'credential-id-123');
        $this->credentialRepository->method('findByCredentialId')->willReturn($credential);

        self::assertSame(42, $this->subject->findFeUserUidFromAssertion($this->buildAssertionJson()));
    }

    // ---------------------------------------------------------------
    // credentialToSource() AAGUID handling
    // ---------------------------------------------------------------

    #[Test]
    public function credentialToSourceKeepsStoredAaguid(): void
    {
        $credential = new FrontendCredential(
            feUser: 42,
            credentialId: 'cid',
            publicKeyCose: 'cose-key',
            signCount: 7,
            userHandle: 'uh',
            aaguid: '01234567-89ab-cdef-0123-456789abcdef',
        );

        $record = $this->callCredentialToSource($credential);

        self::assertSame('01234567-89ab-cdef-0123-456789abcdef', $record->aaguid->toRfc4122());
        self::assertSame('cid', $record->publicKeyCredentialId);
        self::assertSame(7, $record->counter);
    }

    #[Test]
    public function credentialToSourceGeneratesRandomAaguidWhenEmpty(): void
    {
        $credential = new FrontendCredential(feUser: 42, credentialId: 'cid', aaguid: '');

        $record = $this->callCredentialToSource($credential);

        self::assertInstanceOf(Uuid::class, $record->aaguid);
        self::assertNotSame('00000000-0000-0000-0000-000000000000', $record->aaguid->toRfc4122());
    }

    private function callCredentialToSource(FrontendCredential $credential): CredentialRecord
    {
        $method = (new ReflectionClass($this->subject))->getMethod('credentialToSource');
        $record = $method->invoke($this->subject, $credential);
        \assert($record instanceof CredentialRecord);

        return $record;
    }
}
