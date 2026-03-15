<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller;

use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysFe\Controller\AdminController;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;

#[CoversClass(AdminController::class)]
final class AdminControllerTest extends TestCase
{
    private FrontendCredentialRepository&MockObject $credentialRepository;
    private RateLimiterService&MockObject $rateLimiterService;
    private AdminController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialRepository = $this->createMock(FrontendCredentialRepository::class);
        $this->rateLimiterService = $this->createMock(RateLimiterService::class);

        $this->subject = new AdminController(
            $this->credentialRepository,
            $this->rateLimiterService,
            new NullLogger(),
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function setAdminBackendUser(int $uid = 1): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => $uid, 'username' => 'admin', 'realName' => 'Admin'];
        $backendUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function unsetBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    protected function tearDown(): void
    {
        $this->unsetBackendUser();
        parent::tearDown();
    }

    private function buildCredential(int $uid, int $feUser, bool $revoked = false): FrontendCredential
    {
        $cred = new FrontendCredential(
            uid: $uid,
            feUser: $feUser,
            credentialId: 'cred-id-' . $uid,
            label: 'Test Key ' . $uid,
            siteIdentifier: 'main',
            createdAt: \time() - 3600,
        );
        if ($revoked) {
            $cred->setRevokedAt(\time() - 60);
            $cred->setRevokedBy(1);
        }
        return $cred;
    }

    // ---------------------------------------------------------------
    // Auth guard
    // ---------------------------------------------------------------

    #[Test]
    public function listActionReturns403WhenNotAuthenticated(): void
    {
        $this->unsetBackendUser();
        $request = new ServerRequest('GET', '/nr-passkeys-fe/admin/list');
        $response = $this->subject->listAction($request);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function removeActionReturns403WhenNotAuthenticated(): void
    {
        $this->unsetBackendUser();
        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/remove'))
            ->withParsedBody(['feUserUid' => 1, 'credentialUid' => 2]);
        $response = $this->subject->removeAction($request);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function revokeAllActionReturns403WhenNotAuthenticated(): void
    {
        $this->unsetBackendUser();
        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/revoke-all'))
            ->withParsedBody(['feUserUid' => 1]);
        $response = $this->subject->revokeAllAction($request);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function unlockActionReturns403WhenNotAuthenticated(): void
    {
        $this->unsetBackendUser();
        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/unlock'))
            ->withParsedBody(['feUserUid' => 1, 'username' => 'johndoe']);
        $response = $this->subject->unlockAction($request);
        self::assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // listAction
    // ---------------------------------------------------------------

    #[Test]
    public function listActionReturns400WhenFeUserUidMissing(): void
    {
        $this->setAdminBackendUser();
        $request = new ServerRequest('GET', '/nr-passkeys-fe/admin/list');
        $response = $this->subject->listAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function listActionReturnsCredentialList(): void
    {
        $this->setAdminBackendUser();

        $credentials = [
            $this->buildCredential(10, 42),
            $this->buildCredential(11, 42),
        ];
        $this->credentialRepository
            ->method('findAllByFeUser')
            ->with(42)
            ->willReturn($credentials);

        $request = (new ServerRequest('GET', '/nr-passkeys-fe/admin/list'))
            ->withQueryParams(['feUserUid' => '42']);

        $response = $this->subject->listAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = \json_decode((string) $response->getBody(), true);
        self::assertSame(42, $body['feUserUid']);
        self::assertCount(2, $body['credentials']);
        self::assertSame(2, $body['count']);
    }

    #[Test]
    public function listActionIncludesRevokedCredentials(): void
    {
        $this->setAdminBackendUser();

        $credentials = [
            $this->buildCredential(10, 42),
            $this->buildCredential(11, 42, revoked: true),
        ];
        $this->credentialRepository
            ->method('findAllByFeUser')
            ->willReturn($credentials);

        $request = (new ServerRequest('GET', '/nr-passkeys-fe/admin/list'))
            ->withQueryParams(['feUserUid' => '42']);

        $response = $this->subject->listAction($request);
        $body = \json_decode((string) $response->getBody(), true);

        self::assertSame(2, $body['count']);
        $revokedItems = \array_filter($body['credentials'], static fn($c) => $c['isRevoked'] === true);
        self::assertCount(1, $revokedItems);
    }

    // ---------------------------------------------------------------
    // removeAction
    // ---------------------------------------------------------------

    #[Test]
    public function removeActionReturns400WhenBodyMissingFields(): void
    {
        $this->setAdminBackendUser();
        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/remove'))
            ->withParsedBody(['feUserUid' => 42]);
        $response = $this->subject->removeAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function removeActionReturns404WhenCredentialNotFound(): void
    {
        $this->setAdminBackendUser();
        $this->credentialRepository
            ->method('findByUidAndFeUser')
            ->willReturn(null);

        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/remove'))
            ->withParsedBody(['feUserUid' => 42, 'credentialUid' => 99]);
        $response = $this->subject->removeAction($request);
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function removeActionRevokesCredentialAndReturnsOk(): void
    {
        $this->setAdminBackendUser(adminUid: 1);
        $credential = $this->buildCredential(10, 42);
        $this->credentialRepository
            ->method('findByUidAndFeUser')
            ->with(10, 42)
            ->willReturn($credential);

        $this->credentialRepository
            ->expects($this->once())
            ->method('revoke')
            ->with(10, 1);

        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/remove'))
            ->withParsedBody(['feUserUid' => 42, 'credentialUid' => 10]);
        $response = $this->subject->removeAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = \json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $body['status']);
    }

    // ---------------------------------------------------------------
    // revokeAllAction
    // ---------------------------------------------------------------

    #[Test]
    public function revokeAllActionReturns400WhenBodyMissingFields(): void
    {
        $this->setAdminBackendUser();
        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/revoke-all'))
            ->withParsedBody([]);
        $response = $this->subject->revokeAllAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function revokeAllActionRevokesOnlyActiveCredentials(): void
    {
        $this->setAdminBackendUser(adminUid: 1);

        $active = $this->buildCredential(10, 42);
        $alreadyRevoked = $this->buildCredential(11, 42, revoked: true);

        $this->credentialRepository
            ->method('findAllByFeUser')
            ->with(42)
            ->willReturn([$active, $alreadyRevoked]);

        $this->credentialRepository
            ->expects($this->once())
            ->method('revoke')
            ->with(10, 1);

        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/revoke-all'))
            ->withParsedBody(['feUserUid' => 42]);
        $response = $this->subject->revokeAllAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = \json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $body['status']);
        self::assertSame(1, $body['revokedCount']);
    }

    #[Test]
    public function revokeAllActionReturnsZeroWhenNoActiveCredentials(): void
    {
        $this->setAdminBackendUser();
        $this->credentialRepository->method('findAllByFeUser')->willReturn([]);

        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/revoke-all'))
            ->withParsedBody(['feUserUid' => 42]);
        $response = $this->subject->revokeAllAction($request);
        $body = \json_decode((string) $response->getBody(), true);

        self::assertSame('ok', $body['status']);
        self::assertSame(0, $body['revokedCount']);
    }

    // ---------------------------------------------------------------
    // unlockAction
    // ---------------------------------------------------------------

    #[Test]
    public function unlockActionReturns400WhenBodyMissingFields(): void
    {
        $this->setAdminBackendUser();
        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/unlock'))
            ->withParsedBody(['feUserUid' => 42]);
        $response = $this->subject->unlockAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function unlockActionReturns404WhenUserNotFound(): void
    {
        $this->setAdminBackendUser();

        $this->credentialRepository
            ->method('findFeUserByUid')
            ->with(42)
            ->willReturn(null);

        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/unlock'))
            ->withParsedBody(['feUserUid' => 42, 'username' => 'ghost']);
        $response = $this->subject->unlockAction($request);
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function unlockActionResetsRateLimiterAndReturnsOk(): void
    {
        $this->setAdminBackendUser();

        $this->credentialRepository
            ->method('findFeUserByUid')
            ->with(42)
            ->willReturn(['uid' => 42, 'username' => 'johndoe']);

        $this->rateLimiterService
            ->expects($this->once())
            ->method('resetLockout')
            ->with('johndoe');

        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/unlock'))
            ->withParsedBody(['feUserUid' => 42, 'username' => 'johndoe']);
        $response = $this->subject->unlockAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = \json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function unlockActionReturns404WhenUsernameMismatch(): void
    {
        $this->setAdminBackendUser();

        $this->credentialRepository
            ->method('findFeUserByUid')
            ->with(42)
            ->willReturn(['uid' => 42, 'username' => 'differentuser']);

        $request = (new ServerRequest('POST', '/nr-passkeys-fe/admin/unlock'))
            ->withParsedBody(['feUserUid' => 42, 'username' => 'johndoe']);
        $response = $this->subject->unlockAction($request);
        self::assertSame(404, $response->getStatusCode());
    }
}
