<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\DraftRules;
use TribuneDesk\Protocol\Publication;
use TribuneDesk\Protocol\Taxonomy;

if (!defined('ABSPATH')) {
    exit;
}


class PostWriter
{
    public const META_ARTICLE_ID = '_aw_article_id';

    /** 1.3.0: '1' = the Facebook teaser from Tribune Desk goes into the post's empty excerpt, ahead of `excerpt` (2.0.1). */
    public const OPTION_EXCERPT_FROM_TEASER = 'headlinesforge_excerpt_from_fb_teaser';

    /**
     * HF article UUID for a post the plugin may report to HeadlinesForge — or null.
     * The shared condition of the publish hook (LinkResolver and PublishNotifier), so the rule
     * "post_type=post with a UUID in _aw_article_id" lives in one place. Old test
     * identifiers like art-100 give null — HF would reject them with 422 anyway.
     */
    public static function article_id_of(\WP_Post $post): ?string
    {
        if ($post->post_type !== 'post') {
            return null;
        }
        $article_id = (string) get_post_meta($post->ID, self::META_ARTICLE_ID, true);

        return PublishedUrlsClient::is_uuid($article_id) ? $article_id : null;
    }

    /** @param array<string, mixed> $payload
     *  @return array<int, array<string, mixed>> */
    private static function links_from_payload(array $payload): array
    {
        $raw = $payload['internal_links'] ?? [];

        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    /**
     * Run a post write with `iframe` allowed in post content. A delivery runs as user 0, so
     * WordPress filters the content again on save with the default `post` list, which has no
     * `iframe`: embeds the Sanitizer let through (hosts on its list) would vanish. The filter
     * is added only for this write and removed afterwards, also when the write throws.
     *
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public static function with_embeds(callable $write)
    {
        $allow = static function ($tags, $context) {
            if ($context === 'post' && is_array($tags)) {
                $tags['iframe'] = Sanitizer::IFRAME_ATTRIBUTES;
            }
            return $tags;
        };
        add_filter('wp_kses_allowed_html', $allow, 10, 2);
        try {
            return $write();
        } finally {
            remove_filter('wp_kses_allowed_html', $allow, 10);
        }
    }

    /** `sent_at` of the last send applied to the post (guide 1, "Order of sends"). */
    public const META_SENT_AT = '_hf_sent_at';

    /** Seconds a delivery waits for another attempt of the same article; Tribune Desk waits 30. */
    public const LOCK_WAIT = 20;

    /** Name of the MySQL lock that serialises the writes of one article. */
    public static function lock_name(string $article_id): string
    {
        return 'td_article_' . md5($article_id);
    }

    /**
     * Writes the delivery under a lock on its article_id: two overlapping attempts of one send
     * (guide 1, "Store under a unique key") are written one after the other, so the second finds
     * the post the first made. A lock not free in time ends with an exception, which the endpoint
     * answers with a 5xx, so Tribune Desk tries again.
     *
     * @param array<string, mixed> $payload
     */
    public static function write(array $payload, int $author_id, bool $publish = false): array
    {
        global $wpdb;
        $name = self::lock_name((string) $payload['article_id']);
        $wait = (int) Hooks::filter('delivery_lock_wait', self::LOCK_WAIT);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $wait)) !== '1') {
            throw new \RuntimeException('another attempt of this article is still being written');
        }
        try {
            return self::write_locked($payload, $author_id, $publish);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    /**
     * Whether the delivery is an attempt of a send older than the one last applied to the post.
     * A missing or unreadable `sent_at` on either side counts as not older.
     *
     * @param array<string, mixed> $payload
     */
    private static function is_older_send(array $payload, int $post_id): bool
    {
        $new = strtotime((string) ($payload['sent_at'] ?? ''));
        $old = strtotime((string) get_post_meta($post_id, self::META_SENT_AT, true));

        return $new !== false && $old !== false && $new < $old;
    }

    /** @param array<string, mixed> $payload */
    private static function remember_sent_at(int $post_id, array $payload): void
    {
        $sent_at = (string) ($payload['sent_at'] ?? '');
        if ($sent_at !== '' && strtotime($sent_at) !== false) {
            update_post_meta($post_id, self::META_SENT_AT, $sent_at);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function write_locked(array $payload, int $author_id, bool $publish): array
    {
        $article_id = (string) $payload['article_id'];
        $title = DraftRules::title($payload);
        // Keep the raw HTML at hand, because the AI labelling block must be taken out of it
        // BEFORE the Sanitizer strips scripts. The reverse order cost us
        // the labelling on 3 posts (2026-08-22).
        $raw_html = (string) ($payload['html'] ?? '');
        $links = self::links_from_payload($payload);
        // Placeholder format of this delivery, before the resolver uses the marker (spec D6).
        LinkResolver::remember_prefix($payload);
        // Sanitizer BEFORE the resolver: the resolver sees exactly the content that goes to the database,
        // i.e. the same content the wp_insert_post_data filter will see later.
        $html = LinkResolver::resolve(Sanitizer::sanitize($raw_html), $links);

        $existing = self::find_by_article_id($article_id);
        // `taxonomy_ignored` is null when the delivery has no `taxonomy` (the answer then has none),
        // [] when it has one but nothing of it was set or ignored here.
        $no_terms = Taxonomy::requested(null, $payload['taxonomy'] ?? null) !== null ? [] : null;
        if ($existing !== null && self::is_older_send($payload, (int) $existing->ID)) {
            // Nothing of an older send is applied, not even the metadata of a published post.
            return ['post_id' => (int) $existing->ID, 'status' => WriteResult::SkippedOlderSend, 'taxonomy_ignored' => $no_terms];
        }
        $action   = DraftRules::action($existing === null ? null : (string) $existing->post_status);

        $excerpt = self::excerpt_for_post($payload);
        // Publication mode (contract 3.7): draft, scheduled for `at`, or published now; without
        // the field a new post follows the site's option and an existing one keeps its status.
        $publication = is_array($payload['publication'] ?? null) ? $payload['publication'] : null;
        $target = Publication::target(
            $existing === null ? null : (string) $existing->post_status,
            $publication,
            $publish,
            time()
        );

        // Terms before the save, so the publish hooks inside it see them (TaxonomyWriter).
        $terms = self::lookup_taxonomy($existing === null ? null : (string) $existing->post_status, $payload);

        if ($existing === null) {
            $body    = BodyImages::convert($html, 0, (float) BodyImages::DELIVERY_BUDGET);
            $html    = $body['html'];
            $post_id = self::with_embeds(static fn () => wp_insert_post([
                'post_author'  => $author_id,
                'post_title'   => $title,
                'post_content' => $html,
                'post_excerpt' => $excerpt,
                'meta_input'   => self::meta_input($article_id, $links, $payload),
            ] + self::publication_fields($target) + TaxonomyWriter::insert_fields($terms['set'] ?? []), true));

            if (is_wp_error($post_id)) {
                throw new \RuntimeException('wp_insert_post failed: ' . $post_id->get_error_message());
            }

            self::adopt_body_images((int) $post_id, $body);
            self::sync_featured_image((int) $post_id, $payload);
            SeoMeta::sync((int) $post_id, $payload);
            ProvenanceMeta::sync((int) $post_id, $raw_html);
            // Language only on creation: an existing post may have been moved by hand.
            Language::apply((int) $post_id, $payload);
            self::remember_sent_at((int) $post_id, $payload);

            return ['post_id' => (int) $post_id, 'status' => WriteResult::Created, 'taxonomy_ignored' => $terms['ignored'] ?? $no_terms];
        }

        if ($action === DraftRules::SKIP) {
            // Don't overwrite a published article's content/title (editors may
            // have tweaked it), but still sync late-arriving metadata: a featured
            // image generated after the first send, and Yoast SEO fields. Both are
            // idempotent (featured image guarded by post meta; SEO fields never
            // overwrite a non-empty value).
            self::sync_featured_image((int) $existing->ID, $payload);
            SeoMeta::sync((int) $existing->ID, $payload);
            // Link meta and rows follow every send, content does not (the newsroom may have changed it).
            // Rows only for a published post: a scheduled post gets them from the publish hook.
            Backlinks::store_meta((int) $existing->ID, $payload);
            if ($existing->post_status === 'publish'
                && isset(Backlinks::meta_from_payload($payload)[Backlinks::META_SOURCES])) {
                Backlinks::sync_rows($existing);
            }
            self::remember_sent_at((int) $existing->ID, $payload);
            // Terms of a published or private post stay as the newsroom left them.
            return ['post_id' => (int) $existing->ID, 'status' => WriteResult::SkippedAlreadyPublished, 'taxonomy_ignored' => $no_terms];
        }

        // Meta BEFORE content: the wp_insert_post_data filter (Task 5) reads the list from meta during
        // wp_update_post, so it must already see the new list, not the one from the previous send.
        LinkResolver::store_links((int) $existing->ID, $links);
        Backlinks::store_meta((int) $existing->ID, $payload);

        $body = BodyImages::convert($html, (int) $existing->ID, (float) BodyImages::DELIVERY_BUDGET);
        $html = $body['html'];

        $postarr = [
            'ID'           => $existing->ID,
            // A draft gets new content anyway, so also the author from the latest send (1.2.0).
            // A published post is not touched above — neither content nor author.
            'post_author'  => $author_id,
            'post_title'   => $title,
            'post_content' => $html,
        ];
        // We only fill the excerpt: one typed by an editor survives a re-send.
        if (DraftRules::fills_excerpt($excerpt, (string) $existing->post_excerpt)) {
            $postarr['post_excerpt'] = $excerpt;
        }
        $postarr += self::publication_fields($target);
        // wp_update_post keeps the categories it finds on the post, so they are set first; an
        // empty `post_category` in $postarr would be replaced by the old ones.
        if ($terms !== null) {
            TaxonomyWriter::write((int) $existing->ID, $terms['set']);
        }
        $update = self::with_embeds(static fn () => wp_update_post($postarr, true));

        if (is_wp_error($update)) {
            throw new \RuntimeException('wp_update_post failed: ' . $update->get_error_message());
        }

        self::adopt_body_images((int) $existing->ID, $body);
        self::sync_featured_image((int) $existing->ID, $payload);
        SeoMeta::sync((int) $existing->ID, $payload);
        // The content was replaced, so the labelling must follow it: it describes
        // the content hash, and an old signature next to new text looks like a forgery.
        // On the SkippedAlreadyPublished path we deliberately do NOT do this — there the content
        // stays untouched, so the old signature is the right one.
        ProvenanceMeta::sync((int) $existing->ID, $raw_html);
        self::remember_sent_at((int) $existing->ID, $payload);

        return ['post_id' => (int) $existing->ID, 'status' => WriteResult::Updated, 'taxonomy_ignored' => $terms['ignored'] ?? $no_terms];
    }

    /**
     * Post fields for the status Publication::target() chose. `future`: the GMT moment and the
     * same moment in the site's time zone, with edit_date so WordPress keeps them. `publish`: now
     * (a post moving from `future` would otherwise keep its later date and stay scheduled).
     * `draft`: no date of its own, like a new draft. `keep`: nothing.
     *
     * @param array{status: string, date_gmt: int|null} $target
     * @return array<string, mixed>
     */
    private static function publication_fields(array $target): array
    {
        if ($target['status'] === 'future' && $target['date_gmt'] !== null) {
            $gmt = gmdate('Y-m-d H:i:s', $target['date_gmt']);

            return [
                'post_status'   => 'future',
                'post_date_gmt' => $gmt,
                'post_date'     => get_date_from_gmt($gmt),
                'edit_date'     => true,
            ];
        }
        if ($target['status'] === 'publish') {
            return [
                'post_status'   => 'publish',
                'post_date'     => current_time('mysql'),
                'post_date_gmt' => current_time('mysql', true),
                'edit_date'     => true,
            ];
        }
        if ($target['status'] === 'draft') {
            return ['post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00'];
        }

        return [];
    }

    /**
     * The delivery's categories and tags for a new or not yet public post (contract 3.8), looked
     * up but not written; null when there is nothing to set (no field, or a public post).
     *
     * @param string|null $status the post's status before this delivery, null = new post
     * @param array<string, mixed> $payload
     * @return array{set: array{categories?: list<int>, tags?: list<int>}, ignored: list<array{name: string, reason: string}>}|null
     */
    private static function lookup_taxonomy(?string $status, array $payload): ?array
    {
        $requested = Taxonomy::requested($status, $payload['taxonomy'] ?? null);

        return $requested === null ? null : TaxonomyWriter::lookup($requested);
    }

    /**
     * The answer's `taxonomy`: the categories and tags on the post after this delivery, also for a
     * skipped send, and the terms of the delivery not set.
     *
     * @param list<array{name: string, reason: string}> $ignored
     * @return array<string, mixed>|null
     */
    public static function taxonomy_of(int $post_id, array $ignored): ?array
    {
        if (!get_post($post_id) instanceof \WP_Post) {
            return null;
        }

        return Taxonomy::answer(
            TaxonomyWriter::terms_of($post_id, 'categories'),
            TaxonomyWriter::terms_of($post_id, 'tags'),
            $ignored
        );
    }

    /**
     * The answer's `publication`: the state of the post after this delivery.
     *
     * @return array{status: string, at: string|null}|null
     */
    public static function publication_of(int $post_id): ?array
    {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return null;
        }
        $gmt = $post->post_date_gmt === '0000-00-00 00:00:00' ? false : strtotime($post->post_date_gmt . ' UTC');

        return Publication::answer($post->post_status, $gmt === false ? null : $gmt);
    }

    /**
     * The post excerpt: `excerpt` from the delivery (level 1 of the contract). With the teaser option
     * on, the Facebook teaser goes first and `excerpt` covers a delivery without one (for a theme
     * that uses the excerpt as og:description and the RSS description). Neither → ''.
     *
     * @param array<string, mixed> $payload
     */
    private static function excerpt_for_post(array $payload): string
    {
        $fields = get_option(self::OPTION_EXCERPT_FROM_TEASER, '') === '1'
            ? ['facebook_teaser', 'excerpt']
            : ['excerpt'];
        foreach ($fields as $field) {
            $raw  = $payload[$field] ?? null;
            $text = is_string($raw) ? sanitize_text_field($raw) : '';
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /** @param array<int, array<string, mixed>> $links
     *  @param array<string, mixed> $payload
     *  @return array<string, mixed> */
    private static function meta_input(string $article_id, array $links, array $payload): array
    {
        $meta = [self::META_ARTICLE_ID => $article_id];
        if ($links !== []) {
            // meta_input goes through update_post_meta, i.e. through wp_unslash — hence wp_slash.
            $meta[LinkResolver::META_LINKS] = wp_slash($links);
        }
        foreach (Backlinks::meta_from_payload($payload) as $key => $value) {
            $meta[$key] = wp_slash($value);
        }

        return $meta;
    }

    public static function find_by_article_id(string $article_id): ?\WP_Post
    {
        $posts = get_posts([
            'meta_key'       => self::META_ARTICLE_ID,
            'meta_value'     => $article_id,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        return $posts[0] ?? null;
    }

    /** @param array<string, mixed> $payload */
    /**
     * The featured image of a delivery, downloaded with the delivery's short limit. When the
     * download fails the post is saved without it and one retry is scheduled a minute later,
     * with the background limit; the retry does not schedule another.
     */
    private static function sync_featured_image(int $post_id, array $payload, bool $in_delivery = true): void
    {
        $images = $payload['generated_images'] ?? [];
        if (!is_array($images) || $images === []) {
            return;
        }
        
        $first = $images[0];
        if (!is_array($first)) {
            return;
        }
        
        $url = (string) ($first['url'] ?? '');
        if ($url === '') {
            return;
        }

        // Extract metadata from the first image (caption, description, alt, filename)
        $metadata = [];
        foreach (['title', 'caption', 'description', 'alt', 'filename'] as $key) {
            if (isset($first[$key]) && is_string($first[$key]) && $first[$key] !== '') {
                $metadata[$key] = $first[$key];
            }
        }

        // Optional hints (older payloads lack them): used to look up an existing attachment.
        $hints = [];
        if (isset($first['asset_id']) && is_string($first['asset_id']) && $first['asset_id'] !== '') {
            $hints['asset_id'] = $first['asset_id'];
        }
        if (isset($first['cms_attachment_id']) && is_numeric($first['cms_attachment_id'])) {
            $hints['cms_attachment_id'] = (int) $first['cms_attachment_id'];
        }

        $title = (string) ($payload['title'] ?? '');
        $timeout = $in_delivery ? MediaHandler::DELIVERY_TIMEOUT : MediaHandler::BACKGROUND_TIMEOUT;
        $attached = MediaHandler::set_featured_from_url($post_id, $url, $title, $metadata, $hints, $timeout);
        if ($attached === null && $in_delivery && !get_post_meta($post_id, MediaHandler::META_THUMBNAIL_SET, true)) {
            $args = [$post_id, $first, $title];
            if (wp_next_scheduled(MediaHandler::RETRY_HOOK, $args) === false) {
                wp_schedule_single_event(time() + 60, MediaHandler::RETRY_HOOK, $args);
            }
        }
    }

    /**
     * `MediaHandler::RETRY_HOOK`: the featured image a delivery could not download in time.
     *
     * @param array<string, mixed> $image the first entry of `generated_images`
     */
    public static function retry_featured_image(int $post_id, array $image, string $title): void
    {
        if (get_post($post_id) === null) {
            return;
        }
        self::sync_featured_image($post_id, ['generated_images' => [$image], 'title' => $title], false);
    }

    /**
     * Body photos downloaded before the post existed get it as their parent; a figure left
     * unconverted (time budget, failed download) gets one retry a minute later.
     *
     * @param array{html: string, pending: bool, created: int[]} $body
     */
    private static function adopt_body_images(int $post_id, array $body): void
    {
        foreach ($body['created'] as $attachment_id) {
            if ((int) get_post_field('post_parent', $attachment_id) !== $post_id) {
                wp_update_post(['ID' => $attachment_id, 'post_parent' => $post_id]);
            }
        }
        if ($body['pending'] && wp_next_scheduled(BodyImages::RETRY_HOOK, [$post_id]) === false) {
            wp_schedule_single_event(time() + 60, BodyImages::RETRY_HOOK, [$post_id]);
        }
    }

    /**
     * `BodyImages::RETRY_HOOK`: converts the figures with `data-td-asset-id` a delivery could not
     * download in time. Works on the content as it is now (the editor may have changed it) and
     * does not schedule another retry.
     */
    public static function retry_body_images(int $post_id): void
    {
        global $wpdb;
        $post = get_post($post_id);
        if ($post === null) {
            return;
        }
        // The same lock as a delivery: the retry downloads for up to a minute and must not
        // overwrite a newer send. Not free in time: skip, the next send converts the figures anyway.
        $article_id = (string) get_post_meta($post_id, self::META_ARTICLE_ID, true);
        $name       = $article_id !== '' ? self::lock_name($article_id) : '';
        if ($name !== '') {
            $wait = (int) Hooks::filter('delivery_lock_wait', self::LOCK_WAIT);
            if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $wait)) !== '1') {
                return;
            }
        }
        try {
            $snapshot = (string) $post->post_content;
            $body     = BodyImages::convert($snapshot, $post_id, (float) MediaHandler::BACKGROUND_TIMEOUT, true);
            // Re-read: the editor or a delivery may have saved while the images were downloading.
            clean_post_cache($post_id);
            $fresh = get_post($post_id);
            if ($fresh === null) {
                return;
            }
            $html = $body['html'];
            if ((string) $fresh->post_content !== $snapshot) {
                $html = BodyImages::convert((string) $fresh->post_content, $post_id, 1.0, true)['html'];
            }
            if ($html === (string) $fresh->post_content) {
                return;
            }
            self::save_retry_content($fresh, $html, $article_id);
        } finally {
            if ($name !== '') {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }

    /** One write of the retry: the content comes from the database, so kses must not filter it again. */
    private static function save_retry_content(\WP_Post $post, string $html, string $article_id): void
    {
        $kses_active = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;
        if ($kses_active) {
            kses_remove_filters();
        }
        try {
            $result = self::with_embeds(static fn () => wp_update_post([
                'ID'            => $post->ID,
                'post_content'  => wp_slash($html),
                // Without an explicit date core recomputes the status from post_date (see LinkResolver::resolve_own).
                'post_date'     => $post->post_date,
                'post_date_gmt' => $post->post_date_gmt,
            ], true));
        } finally {
            if ($kses_active) {
                kses_init_filters();
            }
        }
        if (is_wp_error($result)) {
            DeliveriesLog::record($article_id, (int) $post->ID, 'error', $result->get_error_code() . ': ' . $result->get_error_message(), DeliveriesLog::KIND_MEDIA);
        }
    }
}
