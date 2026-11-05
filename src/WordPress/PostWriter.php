<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\DraftRules;

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

    /** @param array<string, mixed> $payload */
    public static function write(array $payload, int $author_id, bool $publish = false): array
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
        $action   = DraftRules::action($existing === null ? null : (string) $existing->post_status);

        $excerpt = self::excerpt_for_post($payload);

        if ($existing === null) {
            $post_id = wp_insert_post([
                'post_status'  => $publish ? 'publish' : 'draft',
                'post_author'  => $author_id,
                'post_title'   => $title,
                'post_content' => $html,
                'post_excerpt' => $excerpt,
                'meta_input'   => self::meta_input($article_id, $links, $payload),
            ], true);

            if (is_wp_error($post_id)) {
                throw new \RuntimeException('wp_insert_post failed: ' . $post_id->get_error_message());
            }

            self::sync_featured_image((int) $post_id, $payload);
            SeoMeta::sync((int) $post_id, $payload);
            ProvenanceMeta::sync((int) $post_id, $raw_html);
            // Language only on creation: an existing post may have been moved by hand.
            Language::apply((int) $post_id, $payload);

            return ['post_id' => (int) $post_id, 'status' => WriteResult::Created];
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
            return ['post_id' => (int) $existing->ID, 'status' => WriteResult::SkippedAlreadyPublished];
        }

        // Meta BEFORE content: the wp_insert_post_data filter (Task 5) reads the list from meta during
        // wp_update_post, so it must already see the new list, not the one from the previous send.
        LinkResolver::store_links((int) $existing->ID, $links);
        Backlinks::store_meta((int) $existing->ID, $payload);

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
        $update = wp_update_post($postarr, true);

        if (is_wp_error($update)) {
            throw new \RuntimeException('wp_update_post failed: ' . $update->get_error_message());
        }

        self::sync_featured_image((int) $existing->ID, $payload);
        SeoMeta::sync((int) $existing->ID, $payload);
        // The content was replaced, so the labelling must follow it: it describes
        // the content hash, and an old signature next to new text looks like a forgery.
        // On the SkippedAlreadyPublished path we deliberately do NOT do this — there the content
        // stays untouched, so the old signature is the right one.
        ProvenanceMeta::sync((int) $existing->ID, $raw_html);

        return ['post_id' => (int) $existing->ID, 'status' => WriteResult::Updated];
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
    private static function sync_featured_image(int $post_id, array $payload): void
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

        MediaHandler::set_featured_from_url($post_id, $url, (string) ($payload['title'] ?? ''), $metadata, $hints);
    }
}
