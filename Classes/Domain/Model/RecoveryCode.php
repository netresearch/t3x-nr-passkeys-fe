<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Domain\Model;

/**
 * Single-use recovery code for a TYPO3 frontend user.
 *
 * A recovery code is used exactly once (usedAt > 0) when the user
 * cannot authenticate via passkey and needs an alternative path.
 */
final class RecoveryCode
{
    public function __construct(
        private int $uid = 0,
        private int $feUser = 0,
        private string $codeHash = '',
        private int $usedAt = 0,
        private int $createdAt = 0,
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

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function setCodeHash(string $codeHash): void
    {
        $this->codeHash = $codeHash;
    }

    public function getUsedAt(): int
    {
        return $this->usedAt;
    }

    public function setUsedAt(int $usedAt): void
    {
        $this->usedAt = $usedAt;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'fe_user' => $this->feUser,
            'code_hash' => $this->codeHash,
            'used_at' => $this->usedAt,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uid: self::intVal($data['uid'] ?? null),
            feUser: self::intVal($data['fe_user'] ?? null),
            codeHash: self::stringVal($data['code_hash'] ?? null),
            usedAt: self::intVal($data['used_at'] ?? null),
            createdAt: self::intVal($data['created_at'] ?? null),
        );
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
