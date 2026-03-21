<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(FrontendCredentialRepository::class)]
final class FrontendCredentialRepositoryTest extends TestCase
{
    private ConnectionPool&Stub $connectionPool;
    private FrontendCredentialRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = $this->createStub(ConnectionPool::class);
        $this->subject = new FrontendCredentialRepository($this->connectionPool);
    }

    // ---------------------------------------------------------------
    // findByCredentialId()
    // ---------------------------------------------------------------

    #[Test]
    public function findByCredentialIdReturnsCredentialWhenFound(): void
    {
        $row = $this->buildCredentialRow(uid: 1, feUser: 7, credentialId: 'cred-abc');

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $queryBuilder = $this->createQueryBuilderStub($result);

        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        $credential = $this->subject->findByCredentialId('cred-abc');

        self::assertInstanceOf(FrontendCredential::class, $credential);
        self::assertSame(1, $credential->getUid());
        self::assertSame(7, $credential->getFeUser());
        self::assertSame('cred-abc', $credential->getCredentialId());
    }

    #[Test]
    public function findByCredentialIdReturnsNullWhenNotFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createQueryBuilderStub($result);

        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        self::assertNull($this->subject->findByCredentialId('nonexistent'));
    }

    // ---------------------------------------------------------------
    // findByCredentialIdScoped()
    // ---------------------------------------------------------------

    #[Test]
    public function findByCredentialIdScopedReturnsCredentialWhenMatched(): void
    {
        $row = $this->buildCredentialRow(
            uid: 2,
            feUser: 10,
            credentialId: 'scoped-cred',
            siteIdentifier: 'main',
            storagePid: 5,
        );

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $queryBuilder = $this->createQueryBuilderStub($result);

        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        $credential = $this->subject->findByCredentialIdScoped('scoped-cred', 5, 'main');

        self::assertInstanceOf(FrontendCredential::class, $credential);
        self::assertSame('main', $credential->getSiteIdentifier());
        self::assertSame(5, $credential->getStoragePid());
    }

    #[Test]
    public function findByCredentialIdScopedReturnsNullWhenNotFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createQueryBuilderStub($result);

        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        self::assertNull($this->subject->findByCredentialIdScoped('no-match', 1, 'other'));
    }

    // ---------------------------------------------------------------
    // findByFeUser()
    // ---------------------------------------------------------------

    #[Test]
    public function findByFeUserReturnsListOfCredentials(): void
    {
        $rows = [
            $this->buildCredentialRow(uid: 1, feUser: 7, siteIdentifier: 'main'),
            $this->buildCredentialRow(uid: 2, feUser: 7, siteIdentifier: 'main'),
        ];

        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $queryBuilder = $this->createQueryBuilderStub($result);
        $queryBuilder->method('orderBy')->willReturnSelf();

        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        $credentials = $this->subject->findByFeUser(7, 'main');

        self::assertCount(2, $credentials);
        self::assertContainsOnlyInstancesOf(FrontendCredential::class, $credentials);
    }

    #[Test]
    public function findByFeUserReturnsEmptyArrayWhenNoneFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createQueryBuilderStub($result);
        $queryBuilder->method('orderBy')->willReturnSelf();

        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        self::assertSame([], $this->subject->findByFeUser(999, 'main'));
    }

    // ---------------------------------------------------------------
    // countByFeUser()
    // ---------------------------------------------------------------

    #[Test]
    public function countByFeUserReturnsCount(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(3);

        $queryBuilder = $this->createQueryBuilderStub($result);

        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        self::assertSame(3, $this->subject->countByFeUser(7));
    }

    #[Test]
    public function countByFeUserReturnsZeroWhenNoResults(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(false);

        $queryBuilder = $this->createQueryBuilderStub($result);

        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        self::assertSame(0, $this->subject->countByFeUser(999));
    }

    // ---------------------------------------------------------------
    // save()
    // ---------------------------------------------------------------

    #[Test]
    public function saveSetsUidFromLastInsertId(): void
    {
        $credential = new FrontendCredential(
            feUser: 7,
            credentialId: 'new-cred',
            publicKeyCose: 'cose-data',
            siteIdentifier: 'main',
            storagePid: 1,
        );

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with('tx_nrpasskeysfe_credential', self::isArray());
        $connection->method('lastInsertId')->willReturn('42');

        $this->connectionPool->method('getConnectionForTable')
            ->willReturn($connection);

        $this->subject->save($credential);

        self::assertSame(42, $credential->getUid());
    }

    // ---------------------------------------------------------------
    // updateLastUsed()
    // ---------------------------------------------------------------

    #[Test]
    public function updateLastUsedCallsConnectionUpdate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_nrpasskeysfe_credential',
                self::callback(static function (array $data): bool {
                    return isset($data['last_used_at'], $data['tstamp'])
                        && $data['last_used_at'] > 0
                        && $data['tstamp'] > 0;
                }),
                ['uid' => 42],
            );

        $this->connectionPool->method('getConnectionForTable')
            ->willReturn($connection);

        $this->subject->updateLastUsed(42);
    }

    // ---------------------------------------------------------------
    // revoke()
    // ---------------------------------------------------------------

    #[Test]
    public function revokeSetsRevokedAtAndRevokedBy(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_nrpasskeysfe_credential',
                self::callback(static function (array $data): bool {
                    return isset($data['revoked_at'], $data['revoked_by'], $data['tstamp'])
                        && $data['revoked_at'] > 0
                        && $data['revoked_by'] === 99;
                }),
                ['uid' => 5],
            );

        $this->connectionPool->method('getConnectionForTable')
            ->willReturn($connection);

        $this->subject->revoke(5, 99);
    }

    // ---------------------------------------------------------------
    // revokeAllByFeUser()
    // ---------------------------------------------------------------

    #[Test]
    public function revokeAllByFeUserExecutesUpdateStatement(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();  // needs expects()
        $queryBuilder->method('update')->willReturnSelf();
        $queryBuilder->method('set')->willReturnSelf();
        $queryBuilder->expects(self::once())
            ->method('executeStatement');

        $this->connectionPool->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        $this->subject->revokeAllByFeUser(7, 99);
    }

    // ---------------------------------------------------------------
    // updateSignCount()
    // ---------------------------------------------------------------

    #[Test]
    public function updateSignCountCallsConnectionUpdate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_nrpasskeysfe_credential',
                self::callback(static function (array $data): bool {
                    return ($data['sign_count'] ?? null) === 42
                        && isset($data['tstamp']);
                }),
                ['uid' => 5],
            );

        $this->connectionPool->method('getConnectionForTable')
            ->willReturn($connection);

        $this->subject->updateSignCount(5, 42);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildCredentialRow(
        int $uid = 0,
        int $feUser = 0,
        string $credentialId = '',
        string $siteIdentifier = '',
        int $storagePid = 0,
    ): array {
        return [
            'uid' => $uid,
            'fe_user' => $feUser,
            'credential_id' => $credentialId,
            'public_key_cose' => 'cose-data',
            'sign_count' => 0,
            'user_handle' => 'handle',
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'transports' => '["usb"]',
            'label' => 'Test Key',
            'site_identifier' => $siteIdentifier,
            'storage_pid' => $storagePid,
            'created_at' => 1700000000,
            'last_used_at' => 0,
            'revoked_at' => 0,
            'revoked_by' => 0,
        ];
    }

    private function createQueryBuilderStub(?Result $result = null): QueryBuilder&Stub
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');
        $expressionBuilder->method('in')->willReturn('');

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn('?');

        if ($result !== null) {
            $queryBuilder->method('executeQuery')->willReturn($result);
        }

        return $queryBuilder;
    }

    private function createQueryBuilderMock(?Result $result = null): QueryBuilder&MockObject
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('');
        $expressionBuilder->method('in')->willReturn('');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn('?');

        if ($result !== null) {
            $queryBuilder->method('executeQuery')->willReturn($result);
        }

        return $queryBuilder;
    }
}
