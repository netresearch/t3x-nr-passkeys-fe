<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Domain\Model;

/**
 * Passkey credential registered by a TYPO3 frontend user.
 *
 * Plain PHP model (no Extbase) mirroring the BE Credential model,
 * extended with frontend-specific fields siteIdentifier and storagePid.
 */
final class FrontendCredential
{
    public function __construct(
        private int $uid = 0,
        private int $feUser = 0,
        private string $credentialId = '',
        private string $publicKeyCose = '',
        private int $signCount = 0,
        private string $userHandle = '',
        private string $aaguid = '',
        private string $transports = '[]',
        private string $label = '',
        private string $siteIdentifier = '',
        private int $storagePid = 0,
        private int $createdAt = 0,
        private int $lastUsedAt = 0,
        private int $revokedAt = 0,
        private int $revokedBy = 0,
    ) {}

    public function getUid(): int
    {
        return $this->uid;
    }

    public function setUid(int $uid): void
    {
        $this->uid = $uid;
    }

    public function getFeUser(): int
    {
        return $this->feUser;
    }

    public function setFeUser(int $feUser): void
    {
        $this->feUser = $feUser;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function setCredentialId(string $credentialId): void
    {
        $this->credentialId = $credentialId;
    }

    public function getPublicKeyCose(): string
    {
        return $this->publicKeyCose;
    }

    public function setPublicKeyCose(string $publicKeyCose): void
    {
        $this->publicKeyCose = $publicKeyCose;
    }

    public function getSignCount(): int
    {
        return $this->signCount;
    }

    public function setSignCount(int $signCount): void
    {
        $this->signCount = $signCount;
    }

    public function getUserHandle(): string
    {
        return $this->userHandle;
    }

    public function setUserHandle(string $userHandle): void
    {
        $this->userHandle = $userHandle;
    }

    public function getAaguid(): string
    {
        return $this->aaguid;
    }

    public function setAaguid(string $aaguid): void
    {
        $this->aaguid = $aaguid;
    }

    public function getTransports(): string
    {
        return $this->transports;
    }

    public function setTransports(string $transports): void
    {
        $this->transports = $transports;
    }

    /**
     * @return list<string>
     */
    public function getTransportsArray(): array
    {
        $decoded = \json_decode($this->transports, true);
        if (!\is_array($decoded)) {
            return [];
        }

        return \array_values(\array_filter($decoded, '\is_string'));
    }

    /**
     * @param list<string> $transports
     */
    public function setTransportsArray(array $transports): void
    {
        $this->transports = \json_encode(\array_values($transports), JSON_THROW_ON_ERROR);
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Trims label to a maximum of 128 characters to match the database column constraint.
     */
    public function setLabel(string $label): void
    {
        $this->label = \mb_substr($label, 0, 128);
    }

    public function getSiteIdentifier(): string
    {
        return $this->siteIdentifier;
    }

    public function setSiteIdentifier(string $siteIdentifier): void
    {
        $this->siteIdentifier = $siteIdentifier;
    }

    public function getStoragePid(): int
    {
        return $this->storagePid;
    }

    public function setStoragePid(int $storagePid): void
    {
        $this->storagePid = $storagePid;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(int $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
    }

    public function getRevokedAt(): int
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(int $revokedAt): void
    {
        $this->revokedAt = $revokedAt;
    }

    public function getRevokedBy(): int
    {
        return $this->revokedBy;
    }

    public function setRevokedBy(int $revokedBy): void
    {
        $this->revokedBy = $revokedBy;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'fe_user' => $this->feUser,
            'credential_id' => $this->credentialId,
            'public_key_cose' => $this->publicKeyCose,
            'sign_count' => $this->signCount,
            'user_handle' => $this->userHandle,
            'aaguid' => $this->aaguid,
            'transports' => $this->transports,
            'label' => $this->label,
            'site_identifier' => $this->siteIdentifier,
            'storage_pid' => $this->storagePid,
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt,
            'revoked_at' => $this->revokedAt,
            'revoked_by' => $this->revokedBy,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $credential = new self(
            uid: self::intVal($data['uid'] ?? null),
            feUser: self::intVal($data['fe_user'] ?? null),
            credentialId: self::stringVal($data['credential_id'] ?? null),
            publicKeyCose: self::stringVal($data['public_key_cose'] ?? null),
            signCount: self::intVal($data['sign_count'] ?? null),
            userHandle: self::stringVal($data['user_handle'] ?? null),
            aaguid: self::stringVal($data['aaguid'] ?? null),
            transports: self::stringVal($data['transports'] ?? null, '[]'),
            siteIdentifier: self::stringVal($data['site_identifier'] ?? null),
            storagePid: self::intVal($data['storage_pid'] ?? null),
            createdAt: self::intVal($data['created_at'] ?? null),
            lastUsedAt: self::intVal($data['last_used_at'] ?? null),
            revokedAt: self::intVal($data['revoked_at'] ?? null),
            revokedBy: self::intVal($data['revoked_by'] ?? null),
        );
        // Use setLabel so the 128-char trimming is applied on load as well
        $credential->setLabel(self::stringVal($data['label'] ?? null));

        return $credential;
    }

    private static function intVal(mixed $value, int $default = 0): int
    {
        return \is_numeric($value) ? (int) $value : $default;
    }

    private static function stringVal(mixed $value, string $default = ''): string
    {
        return \is_string($value) ? $value : $default;
    }
}
