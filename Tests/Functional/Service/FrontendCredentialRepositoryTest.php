<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Service;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(FrontendCredentialRepository::class)]
final class FrontendCredentialRepositoryTest extends FunctionalTestCase
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

    private FrontendCredentialRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->repository = $this->get(FrontendCredentialRepository::class);
    }

    // ---------------------------------------------------------------
    // findByCredentialId() — global, unscoped
    // ---------------------------------------------------------------

    #[Test]
    public function findByCredentialIdReturnsCredentialWhenFound(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credential = $this->repository->findByCredentialId('credential-id-active-1');

        self::assertInstanceOf(FrontendCredential::class, $credential);
        self::assertSame(1, $credential->getUid());
        self::assertSame('credential-id-active-1', $credential->getCredentialId());
        self::assertSame(1, $credential->getFeUser());
    }

    #[Test]
    public function findByCredentialIdReturnsNullWhenNotFound(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credential = $this->repository->findByCredentialId('does-not-exist');

        self::assertNull($credential);
    }

    #[Test]
    public function findByCredentialIdFindsAcrossStoragePids(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        // credential-id-pid2 belongs to storage_pid=2
        $credential = $this->repository->findByCredentialId('credential-id-pid2');

        self::assertInstanceOf(FrontendCredential::class, $credential);
        self::assertSame(2, $credential->getStoragePid());
    }

    // ---------------------------------------------------------------
    // findByCredentialIdScoped() — site + storage scoped
    // ---------------------------------------------------------------

    #[Test]
    public function findByCredentialIdScopedReturnsCredentialWhenScopeMatches(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credential = $this->repository->findByCredentialIdScoped('credential-id-active-1', 1, 'site-a');

        self::assertInstanceOf(FrontendCredential::class, $credential);
        self::assertSame('credential-id-active-1', $credential->getCredentialId());
    }

    #[Test]
    public function findByCredentialIdScopedReturnsNullWhenSiteDoesNotMatch(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credential = $this->repository->findByCredentialIdScoped('credential-id-active-1', 1, 'site-b');

        self::assertNull($credential);
    }

    #[Test]
    public function findByCredentialIdScopedReturnsNullWhenStoragePidDoesNotMatch(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credential = $this->repository->findByCredentialIdScoped('credential-id-active-1', 99, 'site-a');

        self::assertNull($credential);
    }

    // ---------------------------------------------------------------
    // findByFeUser() — active only, site-scoped
    // ---------------------------------------------------------------

    #[Test]
    public function findByFeUserReturnsOnlyActiveCredentialsForSite(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credentials = $this->repository->findByFeUser(1, 'site-a');

        // User 1 has 2 active + 1 revoked on site-a; findByFeUser excludes revoked
        self::assertCount(2, $credentials);
        foreach ($credentials as $credential) {
            self::assertFalse($credential->isRevoked());
            self::assertSame('site-a', $credential->getSiteIdentifier());
        }
    }

    #[Test]
    public function findByFeUserExcludesCredentialsFromOtherSites(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credentials = $this->repository->findByFeUser(1, 'site-b');

        // User 1 has 1 active credential on site-b (credential-id-pid2)
        self::assertCount(1, $credentials);
        self::assertSame('site-b', $credentials[0]->getSiteIdentifier());
    }

    #[Test]
    public function findByFeUserExcludesRevokedCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credentials = $this->repository->findByFeUser(1, 'site-a');

        foreach ($credentials as $credential) {
            self::assertSame(0, $credential->getRevokedAt(), 'Revoked credential must not appear in findByFeUser');
        }
    }

    #[Test]
    public function findByFeUserReturnsEmptyArrayWhenNoCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credentials = $this->repository->findByFeUser(999, 'site-a');

        self::assertSame([], $credentials);
    }

    // ---------------------------------------------------------------
    // save() + retrieve round-trip
    // ---------------------------------------------------------------

    #[Test]
    public function saveInsertCredentialAndSetUid(): void
    {
        $credential = new FrontendCredential(
            feUser: 1,
            credentialId: 'new-cred-id',
            publicKeyCose: 'cose-data',
            signCount: 0,
            userHandle: 'user-handle',
            aaguid: '00000000-0000-0000-0000-000000000000',
            transports: '["usb"]',
            label: 'New Key',
            siteIdentifier: 'site-a',
            storagePid: 1,
        );

        $this->repository->save($credential);

        self::assertGreaterThan(0, $credential->getUid());
    }

    #[Test]
    public function saveStoresAllFieldsCorrectly(): void
    {
        $credential = new FrontendCredential(
            feUser: 1,
            credentialId: 'round-trip-cred',
            publicKeyCose: 'round-trip-cose',
            signCount: 7,
            userHandle: 'round-trip-handle',
            aaguid: '11111111-1111-1111-1111-111111111111',
            transports: '["internal","hybrid"]',
            label: 'Round Trip Key',
            siteIdentifier: 'site-round',
            storagePid: 42,
        );

        $this->repository->save($credential);

        $found = $this->repository->findByCredentialId('round-trip-cred');
        self::assertNotNull($found);
        self::assertSame(1, $found->getFeUser());
        self::assertSame('round-trip-cred', $found->getCredentialId());
        self::assertSame('round-trip-cose', $found->getPublicKeyCose());
        self::assertSame(7, $found->getSignCount());
        self::assertSame('round-trip-handle', $found->getUserHandle());
        self::assertSame('11111111-1111-1111-1111-111111111111', $found->getAaguid());
        self::assertSame('["internal","hybrid"]', $found->getTransports());
        self::assertSame('Round Trip Key', $found->getLabel());
        self::assertSame('site-round', $found->getSiteIdentifier());
        self::assertSame(42, $found->getStoragePid());
    }

    // ---------------------------------------------------------------
    // revoke() — soft revoke
    // ---------------------------------------------------------------

    #[Test]
    public function revokeSetsRevokedAtTimestamp(): void
    {
        $credential = new FrontendCredential(
            feUser: 1,
            credentialId: 'cred-to-revoke',
            publicKeyCose: 'cose-data',
            label: 'Revoke Me',
            siteIdentifier: 'site-a',
        );

        $this->repository->save($credential);
        $uid = $credential->getUid();
        $beforeRevoke = \time();

        $this->repository->revoke($uid, 99);

        $afterRevoke = \time();
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrpasskeysfe_credential');
        $row = $connection->select(['revoked_at', 'revoked_by'], 'tx_nrpasskeysfe_credential', ['uid' => $uid])->fetchAssociative();

        self::assertNotFalse($row);
        $revokedAt = (int) $row['revoked_at'];
        self::assertGreaterThanOrEqual($beforeRevoke, $revokedAt);
        self::assertLessThanOrEqual($afterRevoke, $revokedAt);
        self::assertSame(99, (int) $row['revoked_by']);
    }

    #[Test]
    public function revokeExcludesCredentialFromFindByFeUser(): void
    {
        $credential = new FrontendCredential(
            feUser: 2,
            credentialId: 'cred-revoke-exclude',
            publicKeyCose: 'cose-data',
            label: 'Revoke Exclude',
            siteIdentifier: 'site-test',
        );

        $this->repository->save($credential);
        $uid = $credential->getUid();

        $countBefore = $this->repository->countByFeUser(2);
        self::assertGreaterThanOrEqual(1, $countBefore);

        $this->repository->revoke($uid, 1);

        $credentials = $this->repository->findByFeUser(2, 'site-test');
        foreach ($credentials as $c) {
            self::assertNotSame($uid, $c->getUid(), 'Revoked credential must not appear in findByFeUser');
        }
    }

    // ---------------------------------------------------------------
    // revokeAllByFeUser()
    // ---------------------------------------------------------------

    #[Test]
    public function revokeAllByFeUserRevokesOnlyActiveCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $countBefore = $this->repository->countByFeUser(1);
        self::assertGreaterThan(0, $countBefore);

        $this->repository->revokeAllByFeUser(1, 99);

        $countAfter = $this->repository->countByFeUser(1);
        self::assertSame(0, $countAfter);
    }

    // ---------------------------------------------------------------
    // countByFeUser() — active only, all sites
    // ---------------------------------------------------------------

    #[Test]
    public function countByFeUserCountsOnlyActiveCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        // User 1 has 2 active on site-a + 1 on site-b, and 1 revoked on site-a
        $count = $this->repository->countByFeUser(1);

        self::assertSame(3, $count, 'countByFeUser should count all active (non-revoked) across all sites');
    }

    #[Test]
    public function countByFeUserReturnsZeroForUnknownUser(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $count = $this->repository->countByFeUser(999);

        self::assertSame(0, $count);
    }

    // ---------------------------------------------------------------
    // storage PID scoping isolation
    // ---------------------------------------------------------------

    #[Test]
    public function storagePidScopingIsolatesCredentialPools(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        // User 1, site-a, storage_pid=1 should NOT see credentials from site-b (storage_pid=2)
        $credsSiteA = $this->repository->findByFeUser(1, 'site-a');
        $credsSiteB = $this->repository->findByFeUser(1, 'site-b');

        foreach ($credsSiteA as $c) {
            self::assertSame('site-a', $c->getSiteIdentifier());
        }
        foreach ($credsSiteB as $c) {
            self::assertSame('site-b', $c->getSiteIdentifier());
        }
    }

    // ---------------------------------------------------------------
    // findByUidAndFeUser()
    // ---------------------------------------------------------------

    #[Test]
    public function findByUidAndFeUserReturnsCredentialForCorrectOwner(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credential = $this->repository->findByUidAndFeUser(1, 1);

        self::assertNotNull($credential);
        self::assertSame(1, $credential->getUid());
        self::assertSame(1, $credential->getFeUser());
    }

    #[Test]
    public function findByUidAndFeUserReturnsNullForWrongOwner(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $credential = $this->repository->findByUidAndFeUser(1, 2);

        self::assertNull($credential);
    }

    // ---------------------------------------------------------------
    // updateLabel()
    // ---------------------------------------------------------------

    #[Test]
    public function updateLabelChangesStoredLabel(): void
    {
        $credential = new FrontendCredential(
            feUser: 1,
            credentialId: 'cred-label-update',
            publicKeyCose: 'cose-data',
            label: 'Original Label',
            siteIdentifier: 'site-a',
        );

        $this->repository->save($credential);
        $uid = $credential->getUid();

        $this->repository->updateLabel($uid, 'Updated Label');

        $found = $this->repository->findByCredentialId('cred-label-update');
        self::assertNotNull($found);
        self::assertSame('Updated Label', $found->getLabel());
    }

    // ---------------------------------------------------------------
    // updateLastUsed()
    // ---------------------------------------------------------------

    #[Test]
    public function updateLastUsedUpdatesTimestamp(): void
    {
        $credential = new FrontendCredential(
            feUser: 1,
            credentialId: 'cred-last-used',
            publicKeyCose: 'cose-data',
            label: 'Last Used',
            siteIdentifier: 'site-a',
        );

        $this->repository->save($credential);
        $uid = $credential->getUid();
        $beforeUpdate = \time();

        $this->repository->updateLastUsed($uid);

        $afterUpdate = \time();
        $found = $this->repository->findByCredentialId('cred-last-used');

        self::assertNotNull($found);
        self::assertGreaterThanOrEqual($beforeUpdate, $found->getLastUsedAt());
        self::assertLessThanOrEqual($afterUpdate, $found->getLastUsedAt());
    }

    // ---------------------------------------------------------------
    // updateSignCount()
    // ---------------------------------------------------------------

    #[Test]
    public function updateSignCountUpdatesCounter(): void
    {
        $credential = new FrontendCredential(
            feUser: 1,
            credentialId: 'cred-sign-count',
            publicKeyCose: 'cose-data',
            signCount: 5,
            label: 'Sign Count',
            siteIdentifier: 'site-a',
        );

        $this->repository->save($credential);
        $uid = $credential->getUid();

        $this->repository->updateSignCount($uid, 42);

        $found = $this->repository->findByCredentialId('cred-sign-count');
        self::assertNotNull($found);
        self::assertSame(42, $found->getSignCount());
    }

    // ---------------------------------------------------------------
    // delete() — hard delete
    // ---------------------------------------------------------------

    #[Test]
    public function deleteRemovesCredentialFromDatabase(): void
    {
        $credential = new FrontendCredential(
            feUser: 1,
            credentialId: 'cred-to-delete',
            publicKeyCose: 'cose-data',
            label: 'Delete Me',
            siteIdentifier: 'site-a',
        );

        $this->repository->save($credential);
        $uid = $credential->getUid();

        $this->repository->delete($uid);

        $found = $this->repository->findByCredentialId('cred-to-delete');
        self::assertNull($found);
    }

    // ---------------------------------------------------------------
    // UNIQUE constraint on credential_id
    // ---------------------------------------------------------------

    #[Test]
    public function saveThrowsOnDuplicateCredentialId(): void
    {
        $credentialA = new FrontendCredential(
            feUser: 1,
            credentialId: 'unique-test-id',
            publicKeyCose: 'cose-a',
            label: 'Key A',
            siteIdentifier: 'site-a',
        );
        $credentialB = new FrontendCredential(
            feUser: 2,
            credentialId: 'unique-test-id',
            publicKeyCose: 'cose-b',
            label: 'Key B',
            siteIdentifier: 'site-a',
        );

        $this->repository->save($credentialA);

        $this->expectException(\Throwable::class);
        $this->repository->save($credentialB);
    }

    // ---------------------------------------------------------------
    // findAllByFeUser() — includes revoked
    // ---------------------------------------------------------------

    #[Test]
    public function findAllByFeUserIncludesRevokedCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysfe_credential.csv');

        $all = $this->repository->findAllByFeUser(1);

        // User 1 has 2 active + 1 revoked + 1 on site-b = 4 total (no deleted in fixture)
        self::assertGreaterThanOrEqual(3, \count($all));

        $hasRevoked = false;
        foreach ($all as $credential) {
            if ($credential->isRevoked()) {
                $hasRevoked = true;
                break;
            }
        }
        self::assertTrue($hasRevoked, 'findAllByFeUser should include revoked credentials');
    }
}
