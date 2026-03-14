<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

/**
 * Reads WebAuthn configuration from TYPO3 site settings.
 *
 * Each site can define its own rpId, origin, and enforcement level
 * via the site configuration (config/sites/<identifier>/config.yaml).
 * This service centralises that lookup so every other service has
 * a single source of truth for per-site WebAuthn parameters.
 */
final class SiteConfigurationService
{
    /**
     * Get the Relying Party ID for a site.
     *
     * Reads `nr_passkeys_fe.rpId` from site settings.
     * Falls back to the hostname of the site base URL.
     */
    public function getRpId(SiteInterface $site): string
    {
        $settings = $site->getSettings();
        $rpId = $settings->get('nr_passkeys_fe.rpId', '');

        if (\is_string($rpId) && $rpId !== '') {
            return $rpId;
        }

        // Fall back to the hostname of the site base URL
        $base = (string) $site->getBase();
        $host = \parse_url($base, PHP_URL_HOST);

        return \is_string($host) ? $host : '';
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
        $settings = $site->getSettings();
        $origin = $settings->get('nr_passkeys_fe.origin', '');

        if (\is_string($origin) && $origin !== '') {
            return $origin;
        }

        // Build origin from site base URL
        $base = (string) $site->getBase();
        $scheme = \parse_url($base, PHP_URL_SCHEME);
        $host = \parse_url($base, PHP_URL_HOST);
        $port = \parse_url($base, PHP_URL_PORT);

        if (!\is_string($scheme) || !\is_string($host)) {
            return '';
        }

        $result = $scheme . '://' . $host;

        // Include non-standard ports
        if (\is_int($port) && !$this->isStandardPort($scheme, $port)) {
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
        $settings = $site->getSettings();
        $level = $settings->get('nr_passkeys_fe.enforcementLevel', 'off');

        return \is_string($level) ? $level : 'off';
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
     * @throws \RuntimeException when the request has no site attribute
     */
    public function getCurrentSite(ServerRequestInterface $request): SiteInterface
    {
        $site = $request->getAttribute('site');

        if (!$site instanceof SiteInterface) {
            throw new \RuntimeException(
                'No site found in request attributes. Ensure the SiteResolver middleware has run.',
                1700100001,
            );
        }

        return $site;
    }

    private function isStandardPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);
    }
}
