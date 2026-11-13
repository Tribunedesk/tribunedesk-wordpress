<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\ImageHostRule;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Images come only from the hosts Tribune Desk lists in `image_hosts` of GET /v3/cms/status
 * (guide/1-article-delivery.md, "Where images come from"; spec D10 "Security").
 *
 * `allows()` is the portable rule: no list, nothing allowed. `current()` is the WordPress side:
 * the list is kept in an option and refreshed once an hour when a tdcms_ token exists (the status
 * route takes articles:read or media:read). Without a token (an install on level 1 only), or
 * before the first successful read, the guide's list for a CMS without a token is used:
 * the Tribune Desk storage hosts and this site's own host (the domain of the organization and the
 * host of the webhook). Never "any https host".
 */
final class ImageHosts
{
    public const OPTION  = 'headlinesforge_image_hosts';
    public const MAX_AGE = 3600;

    /**
     * Tribune Desk file storage. storage.headlinesforge.com still serves images in articles sent
     * before the 2026-09 rebranding.
     */
    public const STORAGE_HOSTS = ['storage.tribunedesk.com', 'storage.headlinesforge.com'];

    /** @param array<int, string>|null $hosts null or empty = nothing is allowed */
    public static function allows(string $url, ?array $hosts): bool
    {
        return ImageHostRule::allows($url, $hosts);
    }

    /**
     * The hosts allowed without a list from Tribune Desk: its storage and this site.
     *
     * @return array<int, string>
     */
    public static function fallback(): array
    {
        $hosts = self::STORAGE_HOSTS;
        foreach ([home_url(), site_url()] as $url) {
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /** @return array<int, string> never empty */
    public static function current(): array
    {
        if (!CmsApiClient::has_token()) {
            return self::fallback();
        }
        $stored  = get_option(self::OPTION, []);
        $hosts   = is_array($stored) && is_array($stored['hosts'] ?? null) && $stored['hosts'] !== [] ? $stored['hosts'] : null;
        $fetched = (int) (is_array($stored) ? ($stored['fetched_at'] ?? 0) : 0);
        // Also after a failed read: a token without articles:read or media:read fails every
        // time, so one try an hour, not one per image.
        if ($fetched > 0 && time() - $fetched < self::MAX_AGE) {
            return $hosts ?? self::fallback();
        }

        $status = CmsApiClient::get('/status');
        if (is_wp_error($status) || !is_array($status['image_hosts'] ?? null)) {
            update_option(self::OPTION, ['hosts' => $hosts, 'fetched_at' => time()], false);
            if ($hosts === null) {
                DeliveriesLog::record(
                    'image_hosts',
                    null,
                    'error',
                    __('could not fetch image_hosts from Tribune Desk — images only from Tribune Desk storage and this site', 'tribune-desk'),
                    DeliveriesLog::KIND_MEDIA
                );
            }

            return $hosts ?? self::fallback();
        }

        return self::remember($status) ?? self::fallback();
    }

    /**
     * Stores the hosts of a GET /v3/cms/status or /settings answer (the settings screen also
     * calls it).
     *
     * @param array<string, mixed> $settings
     * @return array<int, string>|null
     */
    public static function remember(array $settings): ?array
    {
        if (!is_array($settings['image_hosts'] ?? null)) {
            return null;
        }
        // Tribune Desk never sends an empty list; an empty one is stored as unknown, and the
        // fallback list applies.
        $hosts = ImageHostRule::hosts_from_settings($settings);
        update_option(self::OPTION, ['hosts' => $hosts, 'fetched_at' => time()], false);

        return $hosts;
    }
}
