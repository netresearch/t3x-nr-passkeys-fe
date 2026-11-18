<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_int;
use function is_string;
use function parse_url;
use function trim;

/**
 * Reads WebAuthn configuration from TYPO3 site settings.
 *
 * Each site can define its own rpId, origin, and enforcement level
 * via the site configuration (config/sites/<identifier>/config.yaml).
 * This service centralises that lookup so every other service has
 * a single source of truth for per-site WebAuthn parameters.
 */
final readonly class SiteConfigurationService
{
    /**
     * Get the Relying Party ID for a site.
     *
     * Reads `nr_passkeys_fe.rpId` from site settings.
     * Falls back to the hostname of the site base URL.
     */
    public function getRpId(SiteInterface $site): string
    {
        $rpId = self::setting($site, 'rpId', '');

        if (is_string($rpId) && $rpId !== '') {
            return $rpId;
        }

        // Fall back to the hostname of the site base URL
        $base = (string) $site->getBase();
        $host = parse_url($base, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * Get the expected origin for WebAuthn ceremonies.
     *
     * Reads `nr_passkeys_fe.origin` from site settings.
     * Falls back to scheme://host[:port] derived from the site base URL,
     * including non-standard ports (not 80 or 443).
     */
    public function getOrigin(SiteInterface $site): string
    {
        $origin = self::setting($site, 'origin', '');

        if (is_string($origin) && $origin !== '') {
            return $origin;
        }

        // Build origin from site base URL
        $base   = (string) $site->getBase();
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host   = parse_url($base, PHP_URL_HOST);
        $port   = parse_url($base, PHP_URL_PORT);

        if (!is_string($scheme) || !is_string($host)) {
            return '';
        }

        $result = $scheme . '://' . $host;

        // Include non-standard ports
        if (is_int($port) && !$this->isStandardPort($scheme, $port)) {
            $result .= ':' . $port;
        }

        return $result;
    }

    /**
     * Get the enforcement level configured at site level.
     *
     * Reads `nr_passkeys_fe.enforcementLevel` from site settings.
     * Defaults to 'off' when not configured.
     */
    public function getEnforcementLevel(SiteInterface $site): string
    {
        $level = self::setting($site, 'enforcementLevel', 'off');

        // Older YAML parsers can store an unquoted 'off' as boolean false.
        if ($level === false) {
            return 'off';
        }

        return is_string($level) ? $level : throw new RuntimeException('Invalid site enforcement policy');
    }

    /**
     * Get the site identifier string.
     */
    public function getSiteIdentifier(SiteInterface $site): string
    {
        return $site->getIdentifier();
    }

    /**
     * Resolve the current site from a PSR-7 server request.
     *
     * TYPO3 adds the resolved site to the request attributes via
     * the SiteResolver middleware.
     *
     * @throws RuntimeException when the request has no site attribute
     */
    public function getCurrentSite(ServerRequestInterface $request): SiteInterface
    {
        $site = $request->getAttribute('site');

        if (!$site instanceof SiteInterface) {
            throw new RuntimeException(
                'No site found in request attributes. Ensure the SiteResolver middleware has run.',
                1700100001,
            );
        }

        return $site;
    }

    /**
     * Get the enrollment page URL configured for the site.
     *
     * Reads `nr_passkeys_fe.enrollmentPageUrl` from site settings.
     * Returns an empty string when not configured.
     */
    public function getEnrollmentPageUrl(SiteInterface $site): string
    {
        $url = self::setting($site, 'enrollmentPageUrl', '');

        return is_string($url) ? trim($url) : '';
    }

    public static function allowsRecoveryMethod(SiteInterface $site, string $method): bool
    {
        if (!$site instanceof Site) {
            return false;
        }

        if ($method === 'recovery_code' && !GeneralUtility::makeInstance(FrontendConfiguration::class)->isRecoveryCodesEnabled()) {
            return false;
        }

        $methods = $site->getConfiguration()['nr_passkeys_fe_localRecoveryMethods']
            ?? self::setting($site, 'enabledRecoveryMethods', ['password', 'recovery_code']);

        return is_array($methods) && in_array($method, $methods, true);
    }

    public static function setting(SiteInterface $site, string $key, mixed $default = null): mixed
    {
        if (!$site instanceof Site) {
            throw new RuntimeException('Passkey settings require a configured TYPO3 site.');
        }

        $settings = $site->getSettings();
        $section  = $settings->getAll()['nr_passkeys_fe'] ?? [];
        $fallback = is_array($section) && array_key_exists($key, $section) ? $section[$key] : $default;

        return $settings->get('nr_passkeys_fe.' . $key, $fallback);
    }

    private function isStandardPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);
    }
}
