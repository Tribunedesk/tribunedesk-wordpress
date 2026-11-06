<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetching and validating the update manifest.
 *
 * The class does NOT know the WordPress update API (transients, plugins_api, plugin_basename).
 * Its only job: fetch the JSON, validate it and remember it for a while.
 */
class UpdateManifest
{
    public const MANIFEST_URL = 'https://updates.headlinesforge.com/wp-plugin/manifest.json';
    public const TRANSIENT    = 'headlinesforge_manifest';

    // 2.2.1: 1 h, not 12 h. WordPress checks for updates every 12 h; with a 12 h cache on top a
    // new version reached installs up to ~24 h late (Chronicle and politicsonline, 2026-11-06).
    public const TTL_OK     = HOUR_IN_SECONDS;
    private const TTL_ERROR = HOUR_IN_SECONDS;

    /**
     * @return array<string, mixed>|null Validated manifest or null.
     */
    public static function get(bool $force = false): ?array
    {
        if (!$force) {
            $cached = get_site_transient(self::TRANSIENT);
            if (is_array($cached)) {
                // The error entry is cached deliberately: a downed R2 must not mean a
                // request on every wp-admin load.
                return isset($cached['__error']) ? null : $cached;
            }
        }

        $manifest = self::fetch();

        if ($manifest === null) {
            set_site_transient(self::TRANSIENT, ['__error' => true], self::TTL_ERROR);
            return null;
        }

        set_site_transient(self::TRANSIENT, $manifest, self::TTL_OK);

        return $manifest;
    }

    public static function flush(): void
    {
        delete_site_transient(self::TRANSIENT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetch(): ?array
    {
        $response = wp_remote_get(self::MANIFEST_URL, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($decoded) || !self::is_valid($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function is_valid(array $manifest): bool
    {
        foreach (['version', 'package', 'sha256'] as $key) {
            if (!isset($manifest[$key]) || !is_string($manifest[$key]) || $manifest[$key] === '') {
                return false;
            }
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $manifest['sha256']) !== 1) {
            return false;
        }

        $package  = wp_parse_url($manifest['package']);
        $expected = wp_parse_url(self::MANIFEST_URL);

        if (!is_array($package) || !is_array($expected)) {
            return false;
        }

        // The package MUST go over HTTPS and from the same host as the manifest. Without this
        // a swapped manifest could point to any address on the internet.
        if (($package['scheme'] ?? '') !== 'https') {
            return false;
        }

        return strtolower((string) ($package['host'] ?? '')) === strtolower((string) ($expected['host'] ?? ''));
    }
}
