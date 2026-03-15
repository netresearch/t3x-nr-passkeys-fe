<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller;

use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysFe\Controller\ManagementController;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\FrontendWebAuthnService;
use Netresearch\NrPasskeysFe\Service\PasskeyEnrollmentService;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use Webauthn\PublicKeyCredentialCreationOptions;

#[CoversClass(ManagementController::class)]
final class ManagementControllerTest extends TestCase
{
    private PasskeyEnrollmentService&MockObject $enrollmentService;
    private FrontendCredentialRepository&MockObject $credentialRepository;
    private FrontendWebAuthnService&MockObject $webAuthnService;
    private SiteConfigurationService&MockObject $siteConfigService;
    private ChallengeService&MockObject $challengeService;
    private SiteInterface&MockObject $site;
    private ManagementController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enrollmentService = $this->createMock(PasskeyEnrollmentService::class);
        $this->credentialRepository = $this->createMock(FrontendCredentialRepository::class);
        $this->webAuthnService = $this->createMock(FrontendWebAuthnService::class);
        $this->siteConfigService = $this->createMock(SiteConfigurationService::class);
        $this->challengeService = $this->createMock(ChallengeService::class);
        $this->site = $this->createMock(SiteInterface::class);

        $this->siteConfigService->method('getCurrentSite')->willReturn($this->site);
        $this->siteConfigService->method('getSiteIdentifier')->willReturn('main');

        $this->challengeService->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeService->method('createChallengeToken')->willReturn('challenge-token-123');

        $this->subject = new ManagementController(
            $this->enrollmentService,
            $this->credentialRepository,
            $this->webAuthnService,
            $this->siteConfigService,
            $this->challengeService,
            new NullLogger(),
        );
    }

    // ---------------------------------------------------------------
    // registrationOptionsAction
    // ---------------------------------------------------------------

    #[Test]
    public function registrationOptionsActionReturnsOptionsAndChallengeToken(): void
    {
        $optionsMock = $this->createMock(PublicKeyCredentialCreationOptions::class);
        $this->enrollmentService->method('startEnrollment')->willReturn([
            'options' => $optionsMock,
            'optionsJson' => '{"challenge":"abc"}',
        ]);
        $this->webAuthnService->method('serializeCreationOptions')->willReturn('{"challenge":"abc"}');

        $request = $this->buildRequestWithUser(42, 'user@example.com');
        $response = $this->subject->registrationOptionsAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertArrayHasKey('options', $body);
        self::assertArrayHasKey('challengeToken', $body);
        self::assertSame('challenge-token-123', $body['challengeToken']);
    }

    #[Test]
    public function registrationOptionsActionReturns409WhenMaxPasskeysReached(): void
    {
        $this->enrollmentService->method('startEnrollment')
            ->willThrowException(new RuntimeException('Max reached', 1700300001));

        $request = $this->buildRequestWithUser(42, 'user@example.com');
        $response = $this->subject->registrationOptionsAction($request);

        self::assertSame(409, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // registrationVerifyAction
    // ---------------------------------------------------------------

    #[Test]
    public function registrationVerifyActionReturns400WhenFieldsMissing(): void
    {
        $request = $this->buildRequestWithUser(42, 'user@example.com', []);
        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function registrationVerifyActionReturns400WhenChallengeTokenInvalid(): void
    {
        $this->challengeService->method('verifyChallengeToken')
            ->willThrowException(new RuntimeException('Invalid'));

        $request = $this->buildRequestWithUser(42, 'user@example.com', [
            'credential' => ['id' => 'abc'],
            'challengeToken' => 'bad',
        ]);
        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function registrationVerifyActionReturns200OnSuccess(): void
    {
        $this->challengeService->method('verifyChallengeToken')->willReturn(\str_repeat('a', 32));

        $credential = new FrontendCredential(
            uid: 5,
            feUser: 42,
            label: 'My Key',
            aaguid: '550e8400-e29b-41d4-a716-446655440000',
            createdAt: 1700000000,
            lastUsedAt: 0,
        );
        $this->enrollmentService->method('completeEnrollment')->willReturn($credential);

        $request = $this->buildRequestWithUser(42, 'user@example.com', [
            'credential' => ['id' => 'abc', 'response' => []],
            'challengeToken' => 'valid-token',
            'label' => 'My Key',
        ]);
        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('ok', $body['status']);
        self::assertArrayHasKey('credential', $body);
        self::assertSame(5, $body['credential']['uid']);
    }

    // ---------------------------------------------------------------
    // listAction
    // ---------------------------------------------------------------

    #[Test]
    public function listActionReturnsEmptyListWhenNoCredentials(): void
    {
        $this->credentialRepository->method('findByFeUser')->willReturn([]);

        $request = $this->buildRequestWithUser(42);
        $response = $this->subject->listAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame([], $body['credentials']);
        self::assertSame(0, $body['count']);
    }

    #[Test]
    public function listActionReturnsCredentialList(): void
    {
        $credential = new FrontendCredential(
            uid: 10,
            feUser: 42,
            label: 'YubiKey',
            aaguid: '550e8400-e29b-41d4-a716-446655440000',
            createdAt: 1700000000,
            lastUsedAt: 1710000000,
        );
        $this->credentialRepository->method('findByFeUser')->willReturn([$credential]);

        $request = $this->buildRequestWithUser(42);
        $response = $this->subject->listAction($request);

        $body = $this->decodeBody($response);
        self::assertSame(1, $body['count']);
        self::assertSame(10, $body['credentials'][0]['uid']);
        self::assertSame('YubiKey', $body['credentials'][0]['label']);
    }

    // ---------------------------------------------------------------
    // renameAction
    // ---------------------------------------------------------------

    #[Test]
    public function renameActionReturns400WhenFieldsMissing(): void
    {
        $request = $this->buildRequestWithUser(42, '', []);
        $response = $this->subject->renameAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function renameActionReturns404WhenCredentialNotFound(): void
    {
        $this->credentialRepository->method('findByUidAndFeUser')->willReturn(null);

        $request = $this->buildRequestWithUser(42, '', ['uid' => 99, 'label' => 'New Name']);
        $response = $this->subject->renameAction($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function renameActionReturns200OnSuccess(): void
    {
        $credential = new FrontendCredential(uid: 10, feUser: 42, label: 'Old Name');
        $this->credentialRepository->method('findByUidAndFeUser')->willReturn($credential);
        $this->credentialRepository->expects(self::once())->method('updateLabel')->with(10, 'New Name');

        $request = $this->buildRequestWithUser(42, '', ['uid' => 10, 'label' => 'New Name']);
        $response = $this->subject->renameAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $this->decodeBody($response)['status']);
    }

    #[Test]
    public function renameActionTruncatesLabelTo128Characters(): void
    {
        $longLabel = \str_repeat('A', 200);
        $expectedLabel = \str_repeat('A', 128);

        $credential = new FrontendCredential(uid: 10, feUser: 42, label: 'Old');
        $this->credentialRepository->method('findByUidAndFeUser')->willReturn($credential);
        $this->credentialRepository->expects(self::once())->method('updateLabel')->with(10, $expectedLabel);

        $request = $this->buildRequestWithUser(42, '', ['uid' => 10, 'label' => $longLabel]);
        $response = $this->subject->renameAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // removeAction
    // ---------------------------------------------------------------

    #[Test]
    public function removeActionReturns400WhenUidMissing(): void
    {
        $request = $this->buildRequestWithUser(42, '', []);
        $response = $this->subject->removeAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function removeActionReturns404WhenCredentialNotFound(): void
    {
        $this->credentialRepository->method('findByUidAndFeUser')->willReturn(null);

        $request = $this->buildRequestWithUser(42, '', ['uid' => 99]);
        $response = $this->subject->removeAction($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function removeActionRevokesCredentialAndReturns200(): void
    {
        $credential = new FrontendCredential(uid: 10, feUser: 42);
        $this->credentialRepository->method('findByUidAndFeUser')->willReturn($credential);
        $this->credentialRepository->expects(self::once())->method('revoke')->with(10, 42);

        $request = $this->buildRequestWithUser(42, '', ['uid' => 10]);
        $response = $this->subject->removeAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $this->decodeBody($response)['status']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function buildRequestWithUser(int $uid, string $username = '', array $body = []): ServerRequest
    {
        $feUser = $this->createMock(FrontendUserAuthentication::class);
        $feUser->user = ['uid' => $uid, 'username' => $username];

        return (new ServerRequest('https://example.com/', 'POST'))
            ->withAttribute('frontend.user', $feUser)
            ->withParsedBody($body);
    }

    private function decodeBody(\Psr\Http\Message\ResponseInterface $response): array
    {
        return \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
