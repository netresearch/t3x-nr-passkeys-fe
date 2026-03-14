<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Form\Element;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Form\Element\PasskeyFeInfoElement;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

#[CoversClass(PasskeyFeInfoElement::class)]
final class PasskeyFeInfoElementTest extends TestCase
{
    private FrontendCredentialRepository&MockObject $credentialRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // TYPO3 constant used by AbstractFormElement::wrapWithFieldsetAndLegend()
        if (!\defined('LF')) {
            \define('LF', "\n");
        }

        $this->credentialRepository = $this->createMock(FrontendCredentialRepository::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function renderReturnsEmptyHtmlForNonFeUsersTable(): void
    {
        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 1],
        ]);

        $this->credentialRepository->expects(self::never())->method('findAllByFeUser');

        $result = $subject->render();

        self::assertEmpty($result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsEmptyHtmlForZeroUid(): void
    {
        $subject = $this->createSubject([
            'tableName' => 'fe_users',
            'databaseRow' => ['uid' => 0],
        ]);

        $this->credentialRepository->expects(self::never())->method('findAllByFeUser');

        $result = $subject->render();

        self::assertEmpty($result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsEmptyHtmlForMissingUid(): void
    {
        $subject = $this->createSubject([
            'tableName' => 'fe_users',
            'databaseRow' => [],
        ]);

        $this->credentialRepository->expects(self::never())->method('findAllByFeUser');

        $result = $subject->render();

        self::assertEmpty($result['html'] ?? '');
    }

    #[Test]
    public function renderShowsNoBadgeForUserWithNoCredentials(): void
    {
        $this->setUpLanguageService();

        $subject = $this->createSubject([
            'tableName' => 'fe_users',
            'databaseRow' => ['uid' => 42],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $this->credentialRepository->method('findAllByFeUser')->with(42)->willReturn([]);

        $result = $subject->render();

        self::assertStringContainsString('badge-danger', $result['html'] ?? '');
    }

    #[Test]
    public function renderShowsSuccessBadgeForUserWithPasskeys(): void
    {
        $this->setUpLanguageService();

        $subject = $this->createSubject([
            'tableName' => 'fe_users',
            'databaseRow' => ['uid' => 42],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $credential = FrontendCredential::fromArray([
            'uid' => 1,
            'fe_user' => 42,
            'label' => 'My iPhone',
            'credential_id' => 'abc123',
            'created_at' => 1700000000,
            'last_used_at' => 1700001000,
            'revoked_at' => 0,
            'aaguid' => 'aaguid-value-here',
            'site_identifier' => 'main',
        ]);

        $this->credentialRepository->method('findAllByFeUser')->with(42)->willReturn([$credential]);

        $result = $subject->render();

        $html = $result['html'] ?? '';
        self::assertStringContainsString('badge-success', $html);
        self::assertStringContainsString('My iPhone', $html);
    }

    #[Test]
    public function renderShowsRevokedBadgeForRevokedCredential(): void
    {
        $this->setUpLanguageService();

        $subject = $this->createSubject([
            'tableName' => 'fe_users',
            'databaseRow' => ['uid' => 42],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $credential = FrontendCredential::fromArray([
            'uid' => 1,
            'fe_user' => 42,
            'label' => 'Old key',
            'credential_id' => 'xyz789',
            'created_at' => 1700000000,
            'last_used_at' => 0,
            'revoked_at' => 1700005000,
            'site_identifier' => 'main',
        ]);

        $this->credentialRepository->method('findAllByFeUser')->with(42)->willReturn([$credential]);

        $result = $subject->render();

        $html = $result['html'] ?? '';
        // Overall badge shows danger because active count is 0
        self::assertStringContainsString('badge-danger', $html);
        // Revoked badge on the item
        self::assertStringContainsString('Revoked', $html);
    }

    #[Test]
    public function renderShowsAaguidWhenPresent(): void
    {
        $this->setUpLanguageService();

        $subject = $this->createSubject([
            'tableName' => 'fe_users',
            'databaseRow' => ['uid' => 42],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $credential = FrontendCredential::fromArray([
            'uid' => 1,
            'fe_user' => 42,
            'label' => 'YubiKey',
            'credential_id' => 'yk001',
            'created_at' => 1700000000,
            'last_used_at' => 0,
            'revoked_at' => 0,
            'aaguid' => '2fc0579f-8113-47ea-b116-bb5a8db9202a',
            'site_identifier' => 'main',
        ]);

        $this->credentialRepository->method('findAllByFeUser')->with(42)->willReturn([$credential]);

        $result = $subject->render();

        self::assertStringContainsString('2fc0579f-8113-47ea-b116-bb5a8db9202a', $result['html'] ?? '');
    }

    #[Test]
    public function renderShowsSiteIdentifier(): void
    {
        $this->setUpLanguageService();

        $subject = $this->createSubject([
            'tableName' => 'fe_users',
            'databaseRow' => ['uid' => 42],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $credential = FrontendCredential::fromArray([
            'uid' => 1,
            'fe_user' => 42,
            'label' => 'Test Key',
            'credential_id' => 'test001',
            'created_at' => 1700000000,
            'revoked_at' => 0,
            'site_identifier' => 'my-site',
        ]);

        $this->credentialRepository->method('findAllByFeUser')->with(42)->willReturn([$credential]);

        $result = $subject->render();

        self::assertStringContainsString('my-site', $result['html'] ?? '');
    }

    #[Test]
    public function setDataSetsDataProperty(): void
    {
        $this->setUpLanguageService();

        $subject = $this->createSubject([
            'tableName' => 'fe_users',
            'databaseRow' => ['uid' => 42],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $this->credentialRepository->method('findAllByFeUser')->willReturn([]);

        $result = $subject->render();

        self::assertIsArray($result);
        self::assertArrayHasKey('html', $result);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function createSubject(array $data): PasskeyFeInfoElement
    {
        $subject = new PasskeyFeInfoElement($this->credentialRepository);
        $subject->setData($data);
        return $subject;
    }

    private function setUpBackendUser(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('shallDisplayDebugInformation')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function setUpLanguageService(): void
    {
        $this->setUpBackendUser();

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static function (string $key): string {
                $map = [
                    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:admin.fe_passkeys.enabled' => 'passkey(s) registered',
                    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:admin.fe_passkeys.none' => 'No passkeys',
                    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:admin.fe_passkeys.status.active' => 'Active',
                    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:admin.fe_passkeys.status.revoked' => 'Revoked',
                    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:admin.fe_passkeys.created' => 'Created',
                    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:admin.fe_passkeys.lastUsed' => 'Last used',
                    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:admin.fe_passkeys.never' => 'Never',
                    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:admin.fe_passkeys.site' => 'Site',
                    'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:admin.fe_passkeys.aaguid' => 'AAGUID',
                ];
                return $map[$key] ?? '';
            },
        );
        $GLOBALS['LANG'] = $languageService;
    }
}
