<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the developer commands `wp td …` (Cli) do, for `tdkit check`, the test of the Tribune
 * Desk CMS API (Tribunedesk/tribunedesk-cms-kit, README "The commands your integration
 * provides"). They read what the plugin stored and act as an editor would; they never report
 * the delivery itself, so a green `tdkit check` means the plugin kept what it was sent.
 */
final class DevCommands
{
    /** Media fields of level 4 and where WordPress keeps them. */
    private const MEDIA_FIELDS = ['alt', 'caption', 'title', 'description'];

    /**
     * The post made from `$article_id`, in the keys of `--inspect-cmd`, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function inspect(string $article_id): ?array
    {
        $post = PostWriter::find_by_article_id($article_id);
        if (!$post instanceof \WP_Post || $post->post_status === 'trash') {
            return null;
        }
        $id = $post->ID;
        $thumbnail = (int) get_post_thumbnail_id($id);
        $provenance = get_post_meta($id, ProvenanceMeta::META_KEY, true);
        $author = get_userdata((int) $post->post_author);
        $read_more = [];
        foreach (ReadMore::targets($id, ReadMore::MAX_LIMIT) as $target) {
            $target_id = PostWriter::article_id_of($target);
            if ($target_id !== null) {
                $read_more[] = $target_id;
            }
        }

        return [
            'cms_post_id' => (string) $id,
            'title' => $post->post_title,
            'html' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'status' => self::contract_status($post),
            'featured_image_url' => $thumbnail > 0 ? (string) wp_get_attachment_url($thumbnail) : null,
            'permalink' => $post->post_status === 'publish' ? (string) get_permalink($post) : null,
            'seo_title' => (string) get_post_meta($id, SeoMeta::META_TITLE, true),
            'meta_description' => (string) get_post_meta($id, SeoMeta::META_DESC, true),
            'language' => function_exists('pll_get_post_language') ? (pll_get_post_language($id) ?: null) : null,
            'author' => $author instanceof \WP_User ? $author->display_name : '',
            'read_more' => $read_more,
            'ai_provenance_jws' => is_array($provenance) && is_string($provenance['hfProvenanceJws'] ?? null) ? $provenance['hfProvenanceJws'] : null,
            'categories' => self::term_names($id, 'category'),
            'tags' => self::term_names($id, 'post_tag'),
        ];
    }

    /** The post status in the words of the contract (`draft`, `scheduled`, `published`); a state it does not name stays WordPress's own. */
    private static function contract_status(\WP_Post $post): string
    {
        return PostWriter::publication_of($post->ID)['status'] ?? $post->post_status;
    }

    /** @return list<string> names as plain text: WordPress keeps them HTML-escaped */
    private static function term_names(int $post_id, string $taxonomy): array
    {
        $names = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
        if (!is_array($names)) {
            return [];
        }

        return array_values(array_map(static fn ($name) => html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'), $names));
    }

    /**
     * Publishes the post made from `$article_id` as an editor would. PublishNotifier sends the
     * link back from the save itself; whatever waits for the end of the request goes now, so
     * the report is there when the command returns.
     */
    public static function publish(string $article_id): void
    {
        $post = PostWriter::find_by_article_id($article_id);
        if (!$post instanceof \WP_Post) {
            throw new \RuntimeException("no post for article $article_id");
        }
        wp_publish_post($post);
        PublishNotifier::flush_pending();
    }

    /**
     * One whole sync cycle of level 4, without waiting for cron: the feed (until has_more is
     * false), then field edits, new files and deletions, import requests with `$backfill`, and
     * the next page of a resync the editor started.
     */
    public static function sync(bool $backfill): void
    {
        CmsSync::run_poll();
        CmsSync::run_send();
        if ($backfill) {
            CmsBackfill::run();
        }
        CmsResync::run_page();
    }

    /**
     * One whole full run of categories and tags (`--taxonomy-sync-cmd`), with a new `run_id`, not
     * split by the cron time budget and regardless of when the last run finished.
     */
    public static function taxonomySync(): void
    {
        $error = CmsTaxonomy::sync_now();
        if ($error === null) {
            throw new \RuntimeException('another taxonomy sync is running');
        }
        if ($error !== '') {
            throw new \RuntimeException($error);
        }
    }

    /** @return array<string, mixed>|null the attachment in the keys of `--media-inspect-cmd` */
    public static function mediaInspect(int $id): ?array
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
            return null;
        }
        $file = (string) get_attached_file($id);
        $asset = (string) get_post_meta($id, MediaHandler::META_ASSET_ID, true);

        return [
            'cms_media_id' => (string) $id,
            'asset_id' => $asset !== '' ? $asset : null,
            'sha256' => is_file($file) ? (string) hash_file('sha256', $file) : null,
            'fields' => [
                'alt' => (string) get_post_meta($id, MediaMetaSync::META_ALT, true),
                'caption' => $post->post_excerpt,
                'title' => $post->post_title,
                'description' => $post->post_content,
            ],
            'deleted_in_td' => (bool) get_post_meta($id, CmsDeletions::META_DELETED, true),
        ];
    }

    /** Adds the image `$file` to the media library as an editor would; the attachment ID. */
    public static function mediaAdd(string $file): int
    {
        if (!is_file($file)) {
            throw new \RuntimeException("no file $file");
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        // media_handle_sideload moves the file: give it a copy, the caller's file stays.
        $copy = wp_tempnam(basename($file));
        copy($file, $copy);
        $id = MediaMetaSync::as_editor(static fn () => media_handle_sideload(['name' => basename($file), 'tmp_name' => $copy], 0));
        if (is_wp_error($id)) {
            throw new \RuntimeException($id->get_error_message());
        }

        return (int) $id;
    }

    /** Sets one level 4 field of the attachment as an editor would. */
    public static function mediaEdit(int $id, string $field, string $value): void
    {
        if (!in_array($field, self::MEDIA_FIELDS, true)) {
            throw new \InvalidArgumentException("unknown field $field: " . implode(', ', self::MEDIA_FIELDS));
        }
        if (get_post_type($id) !== 'attachment') {
            throw new \RuntimeException("no attachment $id");
        }
        MediaMetaSync::as_editor(static function () use ($id, $field, $value): void {
            if ($field === 'alt') {
                update_post_meta($id, MediaMetaSync::META_ALT, wp_slash($value));
                return;
            }
            $column = ['caption' => 'post_excerpt', 'title' => 'post_title', 'description' => 'post_content'][$field];
            wp_update_post(wp_slash(['ID' => $id, $column => $value]));
        });
    }

    /** Deletes the attachment as an editor would. */
    public static function mediaDelete(int $id): void
    {
        if (get_post_type($id) !== 'attachment') {
            throw new \RuntimeException("no attachment $id");
        }
        MediaMetaSync::as_editor(static fn () => wp_delete_attachment($id, true));
    }

    /** Clears the level 4 state: cursor, field versions, queues, pairs, locks and run marks. */
    public static function reset(): void
    {
        foreach ([
            CmsSync::OPTION_CURSOR, CmsSync::OPTION_NEEDS_RESYNC, CmsSync::OPTION_LAST_RUN,
            CmsSync::OPTION_LAST_MODE, CmsSync::OPTION_LOCK, CmsSync::OPTION_ATTEMPTS,
            CmsSync::OPTION_SKIPPED, CmsSync::OPTION_DECIDED, CmsDeletions::OPTION_QUEUE,
            CmsResync::OPTION_STATE, CmsBackfill::OPTION_LOCK, CmsBackfill::OPTION_ERROR,
        ] as $option) {
            delete_option($option);
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(CmsBackfill::OPTION_POSITION_PREFIX) . '%'));
        foreach ([
            CmsSync::META_VERSIONS, MediaMetaSync::META_DIRTY, MediaMetaSync::META_DIRTY_AT,
            MediaPush::META_PUSHED, MediaHandler::META_ASSET_ID, CmsDeletions::META_DELETED,
        ] as $key) {
            delete_metadata('post', 0, $key, '', true);
        }
        foreach ([CmsSync::TRANSIENT_ACTIVE, CmsSync::TRANSIENT_SETTINGS, CmsSync::TRANSIENT_PAUSE, CmsSync::TRANSIENT_FILE_PROBLEM] as $transient) {
            delete_transient($transient);
        }
    }
}
