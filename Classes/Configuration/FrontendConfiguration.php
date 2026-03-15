<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Configuration;

use Throwable;

/**
 * Frontend extension configuration value object.
 *
 * Reads and provides typed access to nr_passkeys_fe extension settings.
 * Use fromExtensionConfiguration() to create an instance from the TYPO3
 * extension configuration API, or construct directly with an array for testing.
 */
final class FrontendConfiguration
{
    public function __construct(
        private readonly bool $enableFePasskeys = true,
        private readonly string $defaultEnforcementLevel = 'off',
        private readonly int $maxPasskeysPerUser = 10,
        private readonly bool $recoveryCodesEnabled = true,
        private readonly int $recoveryCodeCount = 10,
        private readonly bool $magicLinkEnabled = false,
        private readonly bool $enrollmentBannerEnabled = true,
        private readonly bool $postLoginEnrollmentEnabled = true,
    ) {}

    public function isEnableFePasskeys(): bool
    {
        return $this->enableFePasskeys;
    }

    public function getDefaultEnforcementLevel(): string
    {
        return $this->defaultEnforcementLevel;
    }

    public function getMaxPasskeysPerUser(): int
    {
        return $this->maxPasskeysPerUser;
    }

    public function isRecoveryCodesEnabled(): bool
    {
        return $this->recoveryCodesEnabled;
    }

    public function getRecoveryCodeCount(): int
    {
        return $this->recoveryCodeCount;
    }

    public function isMagicLinkEnabled(): bool
    {
        return $this->magicLinkEnabled;
    }

    public function isEnrollmentBannerEnabled(): bool
    {
        return $this->enrollmentBannerEnabled;
    }

    public function isPostLoginEnrollmentEnabled(): bool
    {
        return $this->postLoginEnrollmentEnabled;
    }

    /**
     * Creates an instance from a raw settings array (e.g. from TypoScript or ext_conf_template).
     *
     * String values '1'/'0' are converted to booleans; numeric strings are cast to int.
     *
     * @param array<string, mixed> $settings
     */
    public static function fromArray(array $settings): self
    {
        return new self(
            enableFePasskeys: self::boolVal($settings['enableFePasskeys'] ?? null, true),
            defaultEnforcementLevel: self::stringVal($settings['defaultEnforcementLevel'] ?? null, 'off'),
            maxPasskeysPerUser: self::intVal($settings['maxPasskeysPerUser'] ?? null, 10),
            recoveryCodesEnabled: self::boolVal($settings['recoveryCodesEnabled'] ?? null, true),
            recoveryCodeCount: self::intVal($settings['recoveryCodeCount'] ?? null, 10),
            magicLinkEnabled: self::boolVal($settings['magicLinkEnabled'] ?? null, false),
            enrollmentBannerEnabled: self::boolVal($settings['enrollmentBannerEnabled'] ?? null, true),
            postLoginEnrollmentEnabled: self::boolVal($settings['postLoginEnrollmentEnabled'] ?? null, true),
        );
    }

    /**
     * Creates an instance from the TYPO3 ExtensionConfiguration API.
     *
     * Falls back to all defaults when the extension configuration key does not exist.
     */
    public static function fromExtensionConfiguration(): self
    {
        try {
            /** @var \TYPO3\CMS\Core\Configuration\ExtensionConfiguration $extConfApi */
            $extConfApi = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
                \TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class,
            );
            $settings = $extConfApi->get('nr_passkeys_fe');
            if (!\is_array($settings)) {
                $settings = [];
            }
        } catch (Throwable) {
            $settings = [];
        }

        return self::fromArray($settings);
    }

    private static function boolVal(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return $value !== '0' && $value !== '';
        }

        return (bool) $value;
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
