<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Queue of attachment meta tag changes and the write of values from Tribune Desk (spec HF meta tags D8).
 * Human changes only: hooks record which field changed, and CmsSync sends them via /v3.
 * Switching it on scans nothing, so old values are not sent. A write coming from Tribune
 * Desk runs under a flag, so it does not return to the sender.
 */
class MediaMetaSync
{
    public const META_DIRTY      = '_hf_meta_dirty';
    public const META_DIRTY_AT   = '_hf_meta_dirty_at';
    public const META_ALT        = '_wp_attachment_image_alt';
    /** Limit of attachments in the queue when nobody empties it: without a token or with the integration off. */
    public const MAX_PENDING     = 500;

    private const POST_FIELDS = [
        'title'       => 'post_title',
        'caption'     => 'post_excerpt',
        'description' => 'post_content',
    ];

    private static bool $applying = false;

    /** Performs a plugin write so that the hooks do not treat it as a human change. */
    public static function without_tracking(callable $write): void
    {
        $previous       = self::$applying;
        self::$applying = true;
        try {
            $write();
        } finally {
            self::$applying = $previous;
        }
    }

    /**
     * Whether the current write counts as an editor's change (spec HF media sync D6): the admin
     * panel or REST of a logged-in user. WP-CLI (also with --user), cron, an anonymous write and the
     * plugin's own writes do not count.
     */
    public static function is_human_write(): bool
    {
        if (self::$applying || (defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
            return false;
        }

        return is_user_logged_in();
    }

    /** @param array<string, mixed> $fields */
    public static function apply(int $id, array $fields): string
    {
        if (get_post_type($id) !== 'attachment') {
            return 'missing';
        }

        // wp_update_post and update_post_meta strip slashes, so values are passed to them slashed.
        $update = ['ID' => $id];
        foreach (self::POST_FIELDS as $key => $column) {
            if (isset($fields[$key]) && is_string($fields[$key])) {
                $update[$column] = wp_slash($key === 'description'
                    ? sanitize_textarea_field($fields[$key])
                    : sanitize_text_field($fields[$key]));
            }
        }

        $ok = true;
        self::without_tracking(function () use ($id, $fields, $update, &$ok): void {
            if (count($update) > 1) {
                $ok = !is_wp_error(wp_update_post($update, true));
            }
            if (isset($fields['alt']) && is_string($fields['alt'])) {
                update_post_meta($id, self::META_ALT, wp_slash(sanitize_text_field($fields['alt'])));
            }
        });

        return $ok ? 'ok' : 'error';
    }

    public static function on_post_updated(int $id, \WP_Post $after, \WP_Post $before): void
    {
        if ($after->post_type !== 'attachment' || !self::is_human_write()) {
            return;
        }
        foreach (self::POST_FIELDS as $key => $column) {
            if ($after->$column !== $before->$column) {
                self::mark($id, $key);
            }
        }
    }

    /** @param mixed $value */
    public static function on_update_meta(int $meta_id, int $object_id, string $key, $value): void
    {
        if ($key !== self::META_ALT || !self::is_human_write()) {
            return;
        }
        if ((string) get_post_meta($object_id, self::META_ALT, true) !== (string) $value) {
            self::mark($object_id, 'alt');
        }
    }

    /** @param mixed $value */
    public static function on_add_meta(int $meta_id, int $object_id, string $key, $value): void
    {
        if ($key === self::META_ALT && (string) $value !== '' && self::is_human_write()) {
            self::mark($object_id, 'alt');
        }
    }

    private static function mark(int $id, string $field): void
    {
        $dirty = get_post_meta($id, self::META_DIRTY, true);
        $dirty = is_array($dirty) ? $dirty : [];
        // A new attachment over the limit is dropped; one already waiting gets further fields, since it takes no new slot.
        if ($dirty === [] && self::queue_is_parked() && self::pending_count() >= self::MAX_PENDING) {
            return;
        }
        if (!in_array($field, $dirty, true)) {
            $dirty[] = $field;
            update_post_meta($id, self::META_DIRTY, $dirty);
        }
        // The latest edit, because it is the one that counts in the "last change wins" rule.
        update_post_meta($id, self::META_DIRTY_AT, array_merge(self::dirty_at($id), [$field => time()]));
        CmsSync::schedule_send();
    }

    /** No HTTP, because it runs on a save in the admin panel: the mode is the last one known from cron. */
    private static function queue_is_parked(): bool
    {
        return !CmsApiClient::has_token() || get_option(CmsSync::OPTION_LAST_MODE, '') === 'disabled';
    }

    /** @return list<string> */
    public static function dirty_fields(int $id): array
    {
        $dirty = get_post_meta($id, self::META_DIRTY, true);

        return is_array($dirty) ? array_values($dirty) : [];
    }

    /** @return array<string, int> */
    public static function dirty_at(int $id): array
    {
        $marked = get_post_meta($id, self::META_DIRTY_AT, true);

        return is_array($marked) ? $marked : [];
    }

    /** Removes fields without comparing values: we have just written a value from Tribune Desk. @param list<string> $fields */
    public static function drop_fields(int $id, array $fields): void
    {
        self::keep_only($id, array_values(array_diff(self::dirty_fields($id), $fields)));
    }

    /** @param list<string> $left */
    private static function keep_only(int $id, array $left): void
    {
        if ($left === []) {
            self::clear($id);
        } else {
            update_post_meta($id, self::META_DIRTY, $left);
            update_post_meta($id, self::META_DIRTY_AT, array_intersect_key(self::dirty_at($id), array_flip($left)));
        }
    }

    public static function clear(int $id): void
    {
        delete_post_meta($id, self::META_DIRTY);
        delete_post_meta($id, self::META_DIRTY_AT);
    }

    /** @return int[] */
    public static function pending_ids(int $limit): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id ASC LIMIT %d",
            self::META_DIRTY,
            $limit
        )));
    }

    private static function pending_count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::META_DIRTY
        ));
    }

    /** @return array<string, string> */
    public static function values(int $id, array $dirty): array
    {
        $post   = get_post($id);
        $fields = [];
        foreach ($dirty as $field) {
            if ($field === 'alt') {
                $fields['alt'] = (string) get_post_meta($id, self::META_ALT, true);
            } elseif (isset(self::POST_FIELDS[$field]) && $post) {
                $fields[$field] = (string) $post->{self::POST_FIELDS[$field]};
            }
        }

        return $fields;
    }

    /** A field changed during the send stays for the next run. @param array<string, string> $sent */
    public static function finish(int $id, array $sent): void
    {
        $dirty = self::dirty_fields($id);
        $now   = self::values($id, $dirty);
        $left  = array_values(array_filter(
            $dirty,
            static fn ($field) => !isset($sent[$field]) || ($now[$field] ?? '') !== $sent[$field]
        ));
        self::keep_only($id, $left);
    }
}
