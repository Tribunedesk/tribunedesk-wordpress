<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\BacklinkSources;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Read more" links between posts: the hf_backlinks table and _hf_* meta (contract
 * `backlink_sources`, guide/3-full-content.md). Read by the sample ReadMore box or the site's
 * theme (a theme may draw its own box from the same table).
 */
final class Backlinks
{
    public const TABLE = 'hf_backlinks';
    public const META_SOURCES = '_hf_backlink_sources';
    public const META_EVERGREEN = '_hf_evergreen';
    public const KIND_EVERGREEN = 'evergreen';
    public const KIND_NEWS = 'news';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Payload fields -> post meta. A key exists only for a field that is present and of the right type:
     * no key = "leave the meta alone", a present empty list = "overwrite with empty".
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public static function meta_from_payload(array $payload): array
    {
        $meta = [];

        $sources = BacklinkSources::from_payload($payload);
        if ($sources !== null) {
            $meta[self::META_SOURCES] = (string) wp_json_encode($sources);
        }

        $flag = BacklinkSources::evergreen($payload);
        if ($flag !== null) {
            $meta[self::META_EVERGREEN] = $flag ? '1' : '0';
        }

        return $meta;
    }

    /** @param array<string, mixed> $payload */
    public static function store_meta(int $post_id, array $payload): void
    {
        foreach (self::meta_from_payload($payload) as $key => $value) {
            update_post_meta($post_id, $key, wp_slash($value));
        }
    }

    /**
     * Link sources of a post: null when the meta does not exist, otherwise a list ([] when empty/corrupt).
     *
     * @return array<int, array{article_id: string, distance: float|null}>|null
     */
    public static function sources_of(int $post_id): ?array
    {
        if (!metadata_exists('post', $post_id, self::META_SOURCES)) {
            return null;
        }
        $decoded = json_decode((string) get_post_meta($post_id, self::META_SOURCES, true), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Publish hook of the target. SQL only, so it also works on bulk publishing;
     * a fresh object, because post_date_gmt in the passed one may not be set yet.
     */
    public static function on_transition(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        $fresh = get_post($post->ID);
        if ($fresh instanceof \WP_Post) {
            self::sync_rows($fresh);
        }
    }

    /**
     * The target's hf_backlinks rows: published sources from the meta. No meta = we do nothing,
     * meta present (also []) = we delete the target's rows and insert the current ones. Never throws:
     * an error goes to DeliveriesLog and publishing carries on.
     */
    public static function sync_rows(\WP_Post $target): void
    {
        $article_id = PostWriter::article_id_of($target);
        if ($article_id === null) {
            return;
        }

        try {
            $listed = self::sources_of($target->ID);
            if ($listed === null) {
                return;
            }

            $source_ids = [];
            foreach ($listed as $item) {
                $uuid = is_array($item) ? ($item['article_id'] ?? null) : null;
                if (!is_string($uuid) || !PublishedUrlsClient::is_uuid($uuid)) {
                    continue;
                }
                $source = PostWriter::find_by_article_id($uuid);
                if ($source === null || $source->post_status !== 'publish' || $source->ID === $target->ID) {
                    continue;
                }
                $source_ids[$source->ID] = $source->ID;
            }

            global $wpdb;
            $table = self::table();
            $kind  = get_post_meta($target->ID, self::META_EVERGREEN, true) === '1'
                ? self::KIND_EVERGREEN
                : self::KIND_NEWS;
            $created = $target->post_date_gmt;
            if ($created === '0000-00-00 00:00:00' || $created === '') {
                $created = current_time('mysql', true);
            }

            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE target_post_id = %d", $target->ID));
            if ($deleted === false) {
                throw new \RuntimeException($wpdb->last_error !== '' ? $wpdb->last_error : 'DELETE failed');
            }
            if ($source_ids === []) {
                return;
            }

            $values = [];
            $args   = [];
            foreach ($source_ids as $source_id) {
                $values[] = '(%d, %d, %s, %s)';
                array_push($args, $source_id, $target->ID, $kind, $created);
            }
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (source_post_id, target_post_id, target_kind, created_at) VALUES "
                . implode(', ', $values),
                ...$args
            ));
            if ($inserted === false) {
                throw new \RuntimeException($wpdb->last_error !== '' ? $wpdb->last_error : 'INSERT failed');
            }
        } catch (\Throwable $e) {
            DeliveriesLog::record($article_id, $target->ID, 'error', $e->getMessage(), DeliveriesLog::KIND_BACKLINKS);
        }
    }
}
