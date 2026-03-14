<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Domain\Model;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrontendCredential::class)]
final class FrontendCredentialTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $credential = new FrontendCredential();

        self::assertSame(0, $credential->getUid());
        self::assertSame(0, $credential->getFeUser());
        self::assertSame('', $credential->getCredentialId());
        self::assertSame('', $credential->getPublicKeyCose());
        self::assertSame(0, $credential->getSignCount());
        self::assertSame('', $credential->getUserHandle());
        self::assertSame('', $credential->getAaguid());
        self::assertSame('[]', $credential->getTransports());
        self::assertSame('', $credential->getLabel());
        self::assertSame('', $credential->getSiteIdentifier());
        self::assertSame(0, $credential->getStoragePid());
        self::assertSame(0, $credential->getCreatedAt());
        self::assertSame(0, $credential->getLastUsedAt());
        self::assertSame(0, $credential->getRevokedAt());
        self::assertSame(0, $credential->getRevokedBy());
    }

    #[Test]
    public function fromArrayCreatesCredentialWithAllFields(): void
    {
        $data = [
            'uid' => 42,
            'fe_user' => 7,
            'credential_id' => 'cred-abc',
            'public_key_cose' => 'cose-data',
            'sign_count' => 5,
            'user_handle' => 'handle-xyz',
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'transports' => '["usb","nfc"]',
            'label' => 'My Passkey',
            'site_identifier' => 'main',
            'storage_pid' => 1,
            'created_at' => 1700000000,
            'last_used_at' => 1700001000,
            'revoked_at' => 0,
            'revoked_by' => 0,
        ];

        $credential = FrontendCredential::fromArray($data);

        self::assertSame(42, $credential->getUid());
        self::assertSame(7, $credential->getFeUser());
        self::assertSame('cred-abc', $credential->getCredentialId());
        self::assertSame('cose-data', $credential->getPublicKeyCose());
        self::assertSame(5, $credential->getSignCount());
        self::assertSame('handle-xyz', $credential->getUserHandle());
        self::assertSame('00000000-0000-0000-0000-000000000000', $credential->getAaguid());
        self::assertSame('["usb","nfc"]', $credential->getTransports());
        self::assertSame('My Passkey', $credential->getLabel());
        self::assertSame('main', $credential->getSiteIdentifier());
        self::assertSame(1, $credential->getStoragePid());
        self::assertSame(1700000000, $credential->getCreatedAt());
        self::assertSame(1700001000, $credential->getLastUsedAt());
        self::assertSame(0, $credential->getRevokedAt());
        self::assertSame(0, $credential->getRevokedBy());
    }

    #[Test]
    public function fromArrayHandlesMissingKeysWithDefaults(): void
    {
        $credential = FrontendCredential::fromArray([]);

        self::assertSame(0, $credential->getUid());
        self::assertSame(0, $credential->getFeUser());
        self::assertSame('', $credential->getCredentialId());
        self::assertSame('[]', $credential->getTransports());
        self::assertSame('', $credential->getSiteIdentifier());
        self::assertSame(0, $credential->getStoragePid());
    }

    #[Test]
    public function toArrayRoundTripsWithFromArray(): void
    {
        $original = new FrontendCredential(
            uid: 5,
            feUser: 10,
            credentialId: 'round-trip-cred',
            publicKeyCose: 'round-trip-cose',
            signCount: 42,
            userHandle: 'round-trip-handle',
            aaguid: '33333333-3333-3333-3333-333333333333',
            transports: '["usb","ble"]',
            siteIdentifier: 'my-site',
            storagePid: 5,
            createdAt: 1700050000,
            lastUsedAt: 1700060000,
            revokedAt: 0,
            revokedBy: 0,
        );
        $original->setLabel('Round Trip Key');

        $restored = FrontendCredential::fromArray($original->toArray());

        self::assertSame($original->toArray(), $restored->toArray());
    }

    #[Test]
    public function setLabelTrimsToMax128Chars(): void
    {
        $credential = new FrontendCredential();
        $longLabel = \str_repeat('a', 200);
        $credential->setLabel($longLabel);

        self::assertSame(128, \mb_strlen($credential->getLabel()));
        self::assertSame(\str_repeat('a', 128), $credential->getLabel());
    }

    #[Test]
    public function setLabelAcceptsLabelUpTo128Chars(): void
    {
        $credential = new FrontendCredential();
        $label128 = \str_repeat('b', 128);
        $credential->setLabel($label128);

        self::assertSame($label128, $credential->getLabel());
    }

    #[Test]
    public function fromArrayTrimsLabelOver128Chars(): void
    {
        $credential = FrontendCredential::fromArray([
            'label' => \str_repeat('x', 200),
        ]);

        self::assertSame(128, \mb_strlen($credential->getLabel()));
    }

    #[Test]
    public function isRevokedReturnsFalseWhenRevokedAtIsZero(): void
    {
        $credential = new FrontendCredential(revokedAt: 0);
        self::assertFalse($credential->isRevoked());
    }

    #[Test]
    public function isRevokedReturnsTrueWhenRevokedAtIsPositive(): void
    {
        $credential = new FrontendCredential(revokedAt: 1700000000);
        self::assertTrue($credential->isRevoked());
    }

    #[Test]
    public function toArrayIncludesSiteSpecificFields(): void
    {
        $credential = new FrontendCredential(
            siteIdentifier: 'shop-de',
            storagePid: 99,
        );

        $array = $credential->toArray();

        self::assertArrayHasKey('site_identifier', $array);
        self::assertArrayHasKey('storage_pid', $array);
        self::assertSame('shop-de', $array['site_identifier']);
        self::assertSame(99, $array['storage_pid']);
    }

    #[Test]
    public function toArrayContainsAllExpectedKeys(): void
    {
        $credential = new FrontendCredential();
        $array = $credential->toArray();

        $expectedKeys = [
            'uid', 'fe_user', 'credential_id', 'public_key_cose', 'sign_count',
            'user_handle', 'aaguid', 'transports', 'label', 'site_identifier',
            'storage_pid', 'created_at', 'last_used_at', 'revoked_at', 'revoked_by',
        ];

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $array, "Expected key '{$key}' missing from toArray()");
        }
        self::assertCount(\count($expectedKeys), $array);
    }

    #[Test]
    public function settersUpdateValues(): void
    {
        $credential = new FrontendCredential();

        $credential->setUid(1);
        $credential->setFeUser(2);
        $credential->setCredentialId('cred');
        $credential->setPublicKeyCose('cose');
        $credential->setSignCount(10);
        $credential->setUserHandle('handle');
        $credential->setAaguid('aaguid');
        $credential->setTransports('["usb"]');
        $credential->setLabel('label');
        $credential->setSiteIdentifier('site');
        $credential->setStoragePid(3);
        $credential->setCreatedAt(100);
        $credential->setLastUsedAt(200);
        $credential->setRevokedAt(300);
        $credential->setRevokedBy(4);

        self::assertSame(1, $credential->getUid());
        self::assertSame(2, $credential->getFeUser());
        self::assertSame('cred', $credential->getCredentialId());
        self::assertSame('cose', $credential->getPublicKeyCose());
        self::assertSame(10, $credential->getSignCount());
        self::assertSame('handle', $credential->getUserHandle());
        self::assertSame('aaguid', $credential->getAaguid());
        self::assertSame('["usb"]', $credential->getTransports());
        self::assertSame('label', $credential->getLabel());
        self::assertSame('site', $credential->getSiteIdentifier());
        self::assertSame(3, $credential->getStoragePid());
        self::assertSame(100, $credential->getCreatedAt());
        self::assertSame(200, $credential->getLastUsedAt());
        self::assertSame(300, $credential->getRevokedAt());
        self::assertSame(4, $credential->getRevokedBy());
    }
}
