<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\ImageHostRule;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Images come only from the hosts Tribune Desk lists in `image_hosts` of GET /v3/cms/settings
 * (guide/1-article-delivery.md, "Where images come from"; spec D10 "Security").
 *
 * `allows()` is the portable rule. `current()` is the WordPress side: the list is kept in an
 * option and refreshed once an hour when a tdcms_ token exists. Without a token (an install on
 * level 1 only) or before the first successful read there is no list, and only https is checked.
 */
final class ImageHosts
{
    public const OPTION  = 'headlinesforge_image_hosts';
    public const MAX_AGE = 3600;

    /** @param array<int, string>|null $hosts null = list unknown: https only */
    public static function allows(string $url, ?array $hosts): bool
    {
        return ImageHostRule::allows($url, $hosts);
    }

    /** @return array<int, string>|null */
    public static function current(): ?array
    {
        if (!CmsApiClient::has_token()) {
            return null;
        }
        $stored  = get_option(self::OPTION, []);
        $hosts   = is_array($stored) && is_array($stored['hosts'] ?? null) && $stored['hosts'] !== [] ? $stored['hosts'] : null;
        $fetched = (int) (is_array($stored) ? ($stored['fetched_at'] ?? 0) : 0);
        // Also after a failed read: a token without settings:read fails every time, so one try
        // an hour, not one per image.
        if ($fetched > 0 && time() - $fetched < self::MAX_AGE) {
            return $hosts;
        }

        $settings = CmsApiClient::get('/settings');
        if (is_wp_error($settings) || !is_array($settings['image_hosts'] ?? null)) {
            update_option(self::OPTION, ['hosts' => $hosts, 'fetched_at' => time()], false);
            if ($hosts === null) {
                DeliveriesLog::record(
                    'image_hosts',
                    null,
                    'error',
                    __('could not fetch image_hosts from Tribune Desk — images are checked for https only', 'tribune-desk'),
                    DeliveriesLog::KIND_MEDIA
                );
            }

            return $hosts;
        }

        return self::remember($settings);
    }

    /**
     * Stores the hosts of a GET /v3/cms/settings answer (also called by the settings screen).
     *
     * @param array<string, mixed> $settings
     * @return array<int, string>|null
     */
    public static function remember(array $settings): ?array
    {
        if (!is_array($settings['image_hosts'] ?? null)) {
            return null;
        }
        // An empty list means "unknown", not "nothing allowed": it would drop every image.
        $hosts = ImageHostRule::hosts_from_settings($settings);
        update_option(self::OPTION, ['hosts' => $hosts, 'fetched_at' => time()], false);

        return $hosts;
    }
}
