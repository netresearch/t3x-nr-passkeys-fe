<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Fuzz;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrontendCredential::class)]
final class CredentialIdFuzzTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function fuzzedCredentialDataProvider(): iterable
    {
        yield 'empty array' => [[]];
        yield 'null values' => [['uid' => null, 'fe_user' => null, 'credential_id' => null]];
        yield 'negative uid' => [['uid' => -1, 'fe_user' => -1]];
        yield 'max int uid' => [['uid' => PHP_INT_MAX, 'fe_user' => PHP_INT_MAX]];
        yield 'string uid' => [['uid' => 'not_a_number']];
        yield 'float uid' => [['uid' => 3.14]];
        yield 'binary credential_id' => [['credential_id' => \random_bytes(255)]];
        yield 'empty credential_id' => [['credential_id' => '']];
        yield 'huge credential_id' => [['credential_id' => \str_repeat('X', 10000)]];
        yield 'null byte credential_id' => [['credential_id' => "\x00\x00\x00"]];
        yield 'unicode label' => [['label' => '🔑 Mëîn Schlüssel 日本語']];
        yield 'long label' => [['label' => \str_repeat('A', 500)]];
        yield 'html label' => [['label' => '<b>Bold</b><script>alert(1)</script>']];
        yield 'malformed transports json' => [['transports' => 'not json']];
        yield 'transports with objects' => [['transports' => '{"key": "value"}']];
        yield 'transports with nested arrays' => [['transports' => '[[["deep"]]]']];
        yield 'empty transports' => [['transports' => '']];
        yield 'null transports' => [['transports' => null]];
        yield 'negative timestamps' => [['created_at' => -1, 'last_used_at' => -1]];
        yield 'future timestamps' => [['created_at' => PHP_INT_MAX, 'last_used_at' => PHP_INT_MAX]];
        yield 'invalid aaguid' => [['aaguid' => 'not-a-uuid']];
        yield 'empty aaguid' => [['aaguid' => '']];
        yield 'oversized public_key_cose' => [['public_key_cose' => \random_bytes(65536)]];
        yield 'all zeros' => [['uid' => 0, 'fe_user' => 0, 'sign_count' => 0, 'created_at' => 0]];
        yield 'site_identifier with special chars' => [['site_identifier' => '../../../etc/passwd']];
        yield 'storage_pid as string' => [['storage_pid' => 'not_an_int']];
        yield 'storage_pid negative' => [['storage_pid' => -100]];
        yield 'revoked_at max' => [['revoked_at' => PHP_INT_MAX, 'revoked_by' => PHP_INT_MAX]];
    }

    #[Test]
    #[DataProvider('fuzzedCredentialDataProvider')]
    public function fromArrayHandlesFuzzedInputWithoutException(array $data): void
    {
        $credential = FrontendCredential::fromArray($data);

        self::assertInstanceOf(FrontendCredential::class, $credential);
        self::assertIsInt($credential->getUid());
        self::assertIsInt($credential->getFeUser());
        self::assertIsString($credential->getCredentialId());
        self::assertIsString($credential->getLabel());
        self::assertIsString($credential->getSiteIdentifier());
        self::assertIsInt($credential->getStoragePid());
    }

    #[Test]
    #[DataProvider('fuzzedCredentialDataProvider')]
    public function toArrayAndBackRoundTrips(array $data): void
    {
        $credential = FrontendCredential::fromArray($data);
        $array = $credential->toArray();

        self::assertIsArray($array);
        self::assertArrayHasKey('uid', $array);
        self::assertArrayHasKey('fe_user', $array);
        self::assertArrayHasKey('credential_id', $array);
        self::assertArrayHasKey('site_identifier', $array);
        self::assertArrayHasKey('storage_pid', $array);

        // Round-trip: re-hydrating must yield identical scalar values
        $credential2 = FrontendCredential::fromArray($array);
        self::assertSame($credential->getUid(), $credential2->getUid());
        self::assertSame($credential->getCredentialId(), $credential2->getCredentialId());
        self::assertSame($credential->getSiteIdentifier(), $credential2->getSiteIdentifier());
    }

    #[Test]
    public function oversizedCredentialIdDoesNotThrow(): void
    {
        $credential = new FrontendCredential(
            credentialId: \str_repeat('A', 100_000),
        );

        self::assertIsString($credential->getCredentialId());
        self::assertSame(100_000, \strlen($credential->getCredentialId()));
    }

    #[Test]
    public function emptyCredentialIdDoesNotThrow(): void
    {
        $credential = new FrontendCredential(credentialId: '');

        self::assertSame('', $credential->getCredentialId());
    }

    #[Test]
    public function binaryCredentialIdDoesNotThrow(): void
    {
        $binary = \random_bytes(32);
        $credential = new FrontendCredential(credentialId: $binary);

        self::assertSame($binary, $credential->getCredentialId());
    }

    #[Test]
    public function transportsArrayHandlesMalformedJson(): void
    {
        $testCases = [
            'not json',
            '{broken',
            '42',
            'true',
            'null',
            '',
            '{"object": true}',
            '[[["deep"]]]',
        ];

        foreach ($testCases as $transport) {
            $credential = new FrontendCredential(transports: $transport);
            $result = $credential->getTransportsArray();

            // Must return an array (possibly empty) without throwing
            self::assertIsArray($result);
        }
    }

    #[Test]
    public function labelTrimmedTo128Characters(): void
    {
        $longLabel = \str_repeat('A', 500);
        $credential = new FrontendCredential();
        $credential->setLabel($longLabel);

        self::assertSame(128, \mb_strlen($credential->getLabel()));
    }

    #[Test]
    public function isRevokedReturnsTrueWhenRevokedAtIsPositive(): void
    {
        $credential = new FrontendCredential(revokedAt: \time());

        self::assertTrue($credential->isRevoked());
    }

    #[Test]
    public function isRevokedReturnsFalseWhenRevokedAtIsZero(): void
    {
        $credential = new FrontendCredential(revokedAt: 0);

        self::assertFalse($credential->isRevoked());
    }

    #[Test]
    public function fromArrayHandlesRandomBinaryData(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $data = [
                'uid' => \random_int(-PHP_INT_MAX, PHP_INT_MAX),
                'fe_user' => \random_int(-100, 100),
                'credential_id' => \random_bytes(\random_int(0, 255)),
                'public_key_cose' => \random_bytes(\random_int(0, 1024)),
                'label' => \random_bytes(\random_int(0, 200)),
                'site_identifier' => \random_bytes(\random_int(0, 50)),
                'storage_pid' => \random_int(-100, 1000),
                'transports' => \random_bytes(\random_int(0, 100)),
                'sign_count' => \random_int(-100, PHP_INT_MAX),
                'created_at' => \random_int(-PHP_INT_MAX, PHP_INT_MAX),
            ];

            $credential = FrontendCredential::fromArray($data);
            self::assertInstanceOf(FrontendCredential::class, $credential);
        }
    }
}
