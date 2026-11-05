<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Candidates for sending new photos to the Tribune Desk library: attachments not from TD, newer than
 * the `floor` threshold. CmsSync sends them via /v3, and the per-photo result goes to the _hf_media_pushed meta.
 * The hourly cron is this send's safety net: it moves a photo without a parent after a day
 * and the deletion queue after a 501. The hooks are registered by the main plugin file.
 */
class MediaPush
{
    public const HOOK            = 'headlinesforge_media_push';
    public const READY_AFTER     = DAY_IN_SECONDS;
    public const HOST_RETRY      = 7 * DAY_IN_SECONDS;
    public const OPTION_FLOOR    = 'headlinesforge_media_push_floor';
    public const META_PUSHED     = '_hf_media_pushed';

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * On init: sets the threshold (highest attachment ID - old photos don't go on their own) and schedules
     * the hourly event. Cost in a normal job: an option read and wp_next_scheduled.
     */
    public static function ensure_scheduled(): void
    {
        if (get_option(self::OPTION_FLOOR, false) === false) {
            global $wpdb;
            $max = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
            update_option(self::OPTION_FLOOR, $max, false);
        }
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK);
        }
    }

    /** @return int[] */
    public static function candidates(int $limit): array
    {
        $floor = get_option(self::OPTION_FLOOR, false);
        if ($floor === false || $floor === '' || $limit < 1) {
            return [];
        }

        global $wpdb;
        $now       = time();
        $ready_cut = gmdate('Y-m-d H:i:s', $now - self::READY_AFTER);
        $host_cut  = $now - self::HOST_RETRY;
        $mimes     = implode(',', array_fill(0, count(self::MIMES), '%s'));

        // Parent published (join on publish) or the attachment older than READY_AFTER;
        // a parent in trash or deleted does not enter the join, so it counts as none.
        // Sent earlier: skipped, unless rejected for host or missing dimensions longer ago than HOST_RETRY.
        $sql = "SELECT p.ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent AND par.post_status = 'publish'
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
            WHERE p.post_type = 'attachment'
              AND p.post_status = 'inherit'
              AND p.post_mime_type IN ($mimes)
              AND p.ID > %d
              AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} x WHERE x.post_id = p.ID AND x.meta_key IN (%s, %s, %s))
              AND (pm.meta_id IS NULL
                   OR ((pm.meta_value LIKE %s OR pm.meta_value LIKE %s) AND CAST(SUBSTRING_INDEX(pm.meta_value, '@', -1) AS UNSIGNED) < %d))
              AND (par.ID IS NOT NULL OR p.post_date_gmt < %s)
            GROUP BY p.ID
            ORDER BY p.ID ASC
            LIMIT %d";

        $args = array_merge(
            [self::META_PUSHED],
            self::MIMES,
            [
                (int) $floor,
                MediaHandler::META_ASSET_ID,
                '_source_url',
                MediaHandler::META_FROM_TD,
                $wpdb->esc_like('rejected:host@') . '%',
                $wpdb->esc_like('rejected:missing_metadata@') . '%',
                $host_cut,
                $ready_cut,
                $limit,
            ]
        );

        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, ...$args)));
    }

    /** @return array<string, mixed> */
    public static function item(int $id): array
    {
        $post = get_post($id);
        $meta = wp_get_attachment_metadata($id);
        $meta = is_array($meta) ? $meta : [];
        $iptc = is_array($meta['image_meta'] ?? null) ? $meta['image_meta'] : [];

        $parent_info = null;
        $parent      = $post && $post->post_parent ? get_post((int) $post->post_parent) : null;
        if ($parent && $parent->post_status === 'publish') {
            $parent_info = [
                'title'        => html_entity_decode(get_the_title($parent), ENT_QUOTES, 'UTF-8'),
                'url'          => (string) get_permalink($parent),
                'published_at' => self::iso(self::gmt_of($parent)),
            ];
        }

        return [
            'attachment_id' => $id,
            'url'           => (string) wp_get_attachment_url($id),
            'width'         => (int) ($meta['width'] ?? 0),
            'height'        => (int) ($meta['height'] ?? 0),
            'mime'          => (string) get_post_mime_type($id),
            'uploaded_at'   => $post ? self::iso(self::gmt_of($post)) : '',
            'alt'           => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'title'         => $post ? (string) $post->post_title : '',
            'caption'       => $post ? (string) $post->post_excerpt : '',
            'description'   => $post ? (string) $post->post_content : '',
            'iptc_caption'  => (string) ($iptc['caption'] ?? ''),
            'iptc_title'    => (string) ($iptc['title'] ?? ''),
            'iptc_credit'   => (string) ($iptc['credit'] ?? ''),
            'post'          => $parent_info,
        ];
    }

    /**
     * The same item in the NewMedia shape from /v3/cms. Empty values drop out, and so do too long ones:
     * the server limit is 5000 characters and one such value would reject the whole batch.
     *
     * @return array<string, mixed>
     */
    public static function cms_item(int $id): array
    {
        $item = self::item($id);
        $at   = $item['uploaded_at'] !== '' ? $item['uploaded_at'] : gmdate('c');

        $fields = [];
        foreach (['title', 'alt', 'caption', 'description'] as $field) {
            if (self::fits($item[$field])) {
                $fields[] = ['field' => $field, 'value' => $item[$field], 'changed_at' => $at];
            }
        }

        $out = [
            'cms_media_id' => (string) $id,
            // Empty means rejected:no_url at the caller: such an address would give 422 on the whole batch.
            'file_url'     => strlen($item['url']) <= 2083 && !str_contains($item['url'], "\0") ? $item['url'] : '',
            'mime_type'    => $item['mime'],
            'fields'       => $fields,
        ];
        if ($item['width'] > 0 && $item['height'] > 0) {
            $out['width']  = $item['width'];
            $out['height'] = $item['height'];
        }
        if ($item['uploaded_at'] !== '') {
            $out['created_at'] = $item['uploaded_at'];
        }
        if (self::fits($item['iptc_credit'])) {
            $out['credit'] = $item['iptc_credit'];
        }
        $post_title = (string) ($item['post']['title'] ?? '');
        if (self::fits($post_title)) {
            $out['post_title'] = $post_title;
        }
        foreach (['iptc_caption', 'iptc_title'] as $context) {
            if (self::fits($item[$context])) {
                $out[$context] = $item[$context];
            }
        }

        return $out;
    }

    private static function fits(string $value): bool
    {
        return $value !== '' && mb_strlen($value) <= 5000 && !str_contains($value, "\0");
    }

    /** Without a token or with the integration disabled, run_send ends without sending. */
    public static function run(): void
    {
        CmsSync::run_send();
    }

    /** The post's GMT date; a zero one (0000-00-00, e.g. a draft without a date) replaced by the local date converted to GMT. */
    private static function gmt_of(\WP_Post $post): string
    {
        if ($post->post_date_gmt !== '' && !str_starts_with($post->post_date_gmt, '0000-')) {
            return $post->post_date_gmt;
        }

        return get_gmt_from_date($post->post_date);
    }

    private static function iso(string $gmt): string
    {
        $ts = strtotime($gmt . ' UTC');

        return $ts !== false && $ts > 0 ? gmdate('c', $ts) : '';
    }
}
