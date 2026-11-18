<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Integration;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function array_intersect;
use function array_map;

/**
 * Integration tests for multi-site credential isolation.
 *
 * Verifies that credentials scoped to one site/storage-PID are never
 * returned when querying from a different site, and that the global
 * (unscoped) lookup always works regardless of site.
 */
#[CoversNothing]
final class MultiSiteIsolationTest extends AbstractPasskeyFunctionalTestCase
{
    private FrontendCredentialRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');
        $this->repository = $this->get(FrontendCredentialRepository::class);

        $users = $this->getConnectionPool()->getConnectionForTable('fe_users');
        $users->update('fe_users', ['pid' => 42], ['uid' => 1]);
        $users->update('fe_users', ['pid' => 99], ['uid' => 2]);

        // Seed credentials for two distinct sites
        $this->seedSiteACredentials();
        $this->seedSiteBCredentials();
    }

    // ---------------------------------------------------------------
    // findByFeUser: site-scoped
    // ---------------------------------------------------------------

    #[Test]
    public function findByFeUserReturnsSiteACredentialsForSiteA(): void
    {
        $credentials = $this->repository->findByFeUser(1, 'site-a');

        self::assertNotEmpty($credentials, 'findByFeUser must return credentials for site-a');

        foreach ($credentials as $credential) {
            self::assertSame(
                'site-a',
                $credential->getSiteIdentifier(),
                'findByFeUser(site-a) must not return credentials from other sites',
            );
        }
    }

    #[Test]
    public function findByFeUserReturnsSiteBCredentialsForSiteB(): void
    {
        $credentials = $this->repository->findByFeUser(2, 'site-b');

        self::assertNotEmpty($credentials, 'findByFeUser must return credentials for site-b');

        foreach ($credentials as $credential) {
            self::assertSame(
                'site-b',
                $credential->getSiteIdentifier(),
                'findByFeUser(site-b) must not return credentials from other sites',
            );
        }
    }

    #[Test]
    public function findByFeUserDoesNotMixSites(): void
    {
        $siteACredentials = $this->repository->findByFeUser(1, 'site-a');
        $siteBCredentials = $this->repository->findByFeUser(2, 'site-b');

        $siteAIds = array_map(
            static fn (FrontendCredential $c): string => $c->getCredentialId(),
            $siteACredentials,
        );
        $siteBIds = array_map(
            static fn (FrontendCredential $c): string => $c->getCredentialId(),
            $siteBCredentials,
        );

        $overlap = array_intersect($siteAIds, $siteBIds);
        self::assertEmpty($overlap, 'Credentials from site-a and site-b must be completely disjoint');
    }

    #[Test]
    public function findByFeUserRespectsStoragePid(): void
    {
        // Site A uses storage PID 42, site B uses storage PID 99
        $siteACredentials = $this->repository->findByFeUser(1, 'site-a');
        $siteBCredentials = $this->repository->findByFeUser(2, 'site-b');

        self::assertSame([42, 42], array_map(static fn (FrontendCredential $c): int => $c->getStoragePid(), $siteACredentials));
        self::assertSame([99], array_map(static fn (FrontendCredential $c): int => $c->getStoragePid(), $siteBCredentials));
        self::assertSame([], $this->repository->findByFeUser(1, 'site-b'));
        self::assertSame([], $this->repository->findByFeUser(2, 'site-a'));
    }

    // ---------------------------------------------------------------
    // findByCredentialId: global (unscoped)
    // ---------------------------------------------------------------

    #[Test]
    public function findByCredentialIdFindsCredentialFromSiteA(): void
    {
        $credential = $this->repository->findByCredentialId('multi-site-cred-a-1');

        self::assertNotNull($credential, 'findByCredentialId must find site-a credential globally');
        self::assertSame('multi-site-cred-a-1', $credential->getCredentialId());
        self::assertSame('site-a', $credential->getSiteIdentifier());
    }

    #[Test]
    public function findByCredentialIdFindsCredentialFromSiteB(): void
    {
        $credential = $this->repository->findByCredentialId('multi-site-cred-b-1');

        self::assertNotNull($credential, 'findByCredentialId must find site-b credential globally');
        self::assertSame('multi-site-cred-b-1', $credential->getCredentialId());
        self::assertSame('site-b', $credential->getSiteIdentifier());
    }

    #[Test]
    public function findByCredentialIdDoesNotRequireSiteContext(): void
    {
        // Both site-a and site-b credentials are found without site filter
        $credA = $this->repository->findByCredentialId('multi-site-cred-a-1');
        $credB = $this->repository->findByCredentialId('multi-site-cred-b-1');

        self::assertNotNull($credA, 'Global lookup must find site-a credential without site filter');
        self::assertNotNull($credB, 'Global lookup must find site-b credential without site filter');
    }

    // ---------------------------------------------------------------
    // findByCredentialIdScoped: rejects wrong site
    // ---------------------------------------------------------------

    #[Test]
    public function findByCredentialIdScopedReturnsSiteACredentialForSiteA(): void
    {
        $credential = $this->repository->findByCredentialIdScoped('multi-site-cred-a-1', 42, 'site-a');

        self::assertNotNull($credential, 'findByCredentialIdScoped must return credential for correct site/pid');
        self::assertSame('multi-site-cred-a-1', $credential->getCredentialId());
    }

    #[Test]
    public function findByCredentialIdScopedReturnsSiteBCredentialForSiteB(): void
    {
        $credential = $this->repository->findByCredentialIdScoped('multi-site-cred-b-1', 99, 'site-b');

        self::assertNotNull($credential, 'findByCredentialIdScoped must return credential for correct site/pid');
        self::assertSame('multi-site-cred-b-1', $credential->getCredentialId());
    }

    #[Test]
    public function findByCredentialIdScopedReturnsNullForWrongSiteIdentifier(): void
    {
        // Site-a credential queried with site-b identifier → must not be returned
        $credential = $this->repository->findByCredentialIdScoped('multi-site-cred-a-1', 42, 'site-b');

        self::assertNull($credential, 'findByCredentialIdScoped must return null for wrong siteIdentifier');
    }

    #[Test]
    public function findByCredentialIdScopedReturnsNullForWrongStoragePid(): void
    {
        // Site-a credential queried with site-b storage PID → must not be returned
        $credential = $this->repository->findByCredentialIdScoped('multi-site-cred-a-1', 99, 'site-a');

        self::assertNull($credential, 'findByCredentialIdScoped must return null for wrong storagePid');
    }

    #[Test]
    public function findByCredentialIdScopedReturnsNullForCompleteMismatch(): void
    {
        // Site-a credential queried with both wrong site and wrong PID
        $credential = $this->repository->findByCredentialIdScoped('multi-site-cred-a-1', 99, 'site-b');

        self::assertNull($credential, 'findByCredentialIdScoped must return null when both site and pid mismatch');
    }

    // ---------------------------------------------------------------
    // Cross-site credential count isolation
    // ---------------------------------------------------------------

    #[Test]
    public function countByFeUserCountsAcrossAllSites(): void
    {
        // User 1 can use a shared folder on another site without mixing site-scoped keys.
        $this->repository->save(new FrontendCredential(
            feUser: 1,
            credentialId: 'shared-folder-site-b',
            publicKeyCose: 'cose-b',
            label: 'Shared folder key',
            siteIdentifier: 'site-b',
            storagePid: 42,
        ));
        self::assertSame(2, $this->repository->countByFeUser(1, 'site-a'));
        self::assertSame(1, $this->repository->countByFeUser(1, 'site-b'));
        $count = $this->repository->countByFeUser(1);

        self::assertSame(3, $count, 'countByFeUser must count active credentials across all sites');
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function seedSiteACredentials(): void
    {
        // Two credentials for user 1 on site-a, storage PID 42
        foreach (['multi-site-cred-a-1', 'multi-site-cred-a-2'] as $credentialId) {
            $credential = new FrontendCredential(
                feUser: 1,
                credentialId: $credentialId,
                publicKeyCose: 'cose-data-a',
                label: 'Site A Key',
                siteIdentifier: 'site-a',
                storagePid: 42,
            );
            $this->repository->save($credential);
        }
    }

    private function seedSiteBCredentials(): void
    {
        // One credential for user 2 on site-b, storage PID 99
        $credential = new FrontendCredential(
            feUser: 2,
            credentialId: 'multi-site-cred-b-1',
            publicKeyCose: 'cose-data-b',
            label: 'Site B Key',
            siteIdentifier: 'site-b',
            storagePid: 99,
        );
        $this->repository->save($credential);
    }
}
