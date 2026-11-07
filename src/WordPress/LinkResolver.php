<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\PlaceholderLinks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Replacing HeadlinesForge internal link placeholders with permalinks of targets
 * published in THIS WordPress (spec 0.8.0 D2/D3/D10).
 *
 * A placeholder is the exact string {PUBLIC_APP_URL}/app/a/<uuid> from an `internal_links` item
 * of the payload (kind=placeholder). META decides, not a host pattern: the list of items lives
 * in the post meta `_hf_internal_links`, and the target is looked up by `_aw_article_id`. No
 * HTML parser (re-serialization would change entities and <br>) — replacement by the exact string
 * of the placeholder (preg_replace_callback with an attribute-delimiter assertion), the counterpart of
 * resolve_placeholders on the HF side.
 */
class LinkResolver
{
    /** @var array<int, true> posts resolve_own() is saving right now */
    private static array $resolving = [];

    public const META_LINKS    = '_hf_internal_links';
    public const REVERSE_LIMIT = 50;

    // Common fragment of every placeholder. Serves ONLY as a cheap guard for
    // "is there anything to replace at all" before any database query.
    public const PLACEHOLDER_MARKER = PlaceholderLinks::DEFAULT_PATH;

    /**
     * Path of `link_placeholder_prefix` from the last delivery (spec D6: the CMS must not
     * hard-code `/app/a/`). Only the path, not the whole prefix: older posts carry placeholders
     * with an older domain (app.headlinesforge.com) and the same path, and must still match.
     */
    public const OPTION_PLACEHOLDER_PATH = 'headlinesforge_placeholder_path';

    /** The guard every placeholder contains: the stored path, or `/app/a/` when none is known. */
    public static function marker(): string
    {
        // From the autoloaded options already in memory: the marker is the cheap guard before any
        // query, so reading it must not cost one (a missing option would in get_option()).
        $all  = wp_load_alloptions();
        $path = (string) ($all[self::OPTION_PLACEHOLDER_PATH] ?? '');

        return $path !== '' ? $path : self::PLACEHOLDER_MARKER;
    }

    /**
     * Stores the path of the delivery's `link_placeholder_prefix` when it is an http(s) URL
     * whose path ends with "/". Anything else leaves the stored value as it is.
     *
     * @param array<string, mixed> $payload
     */
    public static function remember_prefix(array $payload): void
    {
        $path = PlaceholderLinks::path_of_prefix($payload['link_placeholder_prefix'] ?? null);
        if ($path !== null && get_option(self::OPTION_PLACEHOLDER_PATH, '') !== $path) {
            update_option(self::OPTION_PLACEHOLDER_PATH, $path, true);
        }
    }

    /**
     * @param array<int, mixed> $links internal_links items from the payload (or from meta)
     */
    public static function resolve(string $html, array $links): string
    {
        return PlaceholderLinks::resolve($html, $links, self::marker(), [self::class, 'published_permalink']);
    }

    /**
     * On publish (1.1.0): a placeholder whose target DOES NOT EXIST in this WordPress
     * in any status will never resolve — the target was published outside the plugin (e.g. via
     * Make) or was never sent here. It would stay live as a link to the Tribune
     * Desk app behind a login, so we strip just the <a> tag and keep the anchor text.
     * A target that is a draft stays: its publication will replace the placeholder (resolve_linking_posts).
     *
     * @param array<int, mixed> $links internal_links items from meta
     */
    public static function unwrap_missing(string $html, array $links): string
    {
        return PlaceholderLinks::unwrap_missing($html, $links, self::marker(), [self::class, 'target_exists']);
    }

    public static function target_exists(string $article_id): bool
    {
        $ids = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_key'       => PostWriter::META_ARTICLE_ID,
            'meta_value'     => $article_id,
        ]);

        return $ids !== [];
    }

    public static function published_permalink(string $article_id): ?string
    {
        $ids = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_key'       => PostWriter::META_ARTICLE_ID,
            'meta_value'     => $article_id,
        ]);
        if ($ids === []) {
            return null;
        }
        $permalink = get_permalink((int) $ids[0]);

        return is_string($permalink) && $permalink !== '' ? $permalink : null;
    }

    /**
     * Meta follows the content: saved together with it, an empty list = no meta.
     * A PHP array (WordPress serializes it), not JSON — update_post_meta does wp_unslash,
     * so JSON with quotes in anchor_text would need manual slashing.
     *
     * @param array<int, mixed> $links
     */
    public static function store_links(int $post_id, array $links): void
    {
        $clean = array_values(array_filter($links, 'is_array'));
        if ($clean === []) {
            delete_post_meta($post_id, self::META_LINKS);

            return;
        }
        update_post_meta($post_id, self::META_LINKS, wp_slash($clean));
    }

    /** @return array<int, mixed> */
    public static function links_of(int $post_id): array
    {
        $links = get_post_meta($post_id, self::META_LINKS, true);

        return is_array($links) ? array_values($links) : [];
    }

    /**
     * The wp_insert_post_data filter: the post's own content is resolved BEFORE saving, on EVERY
     * save (editor, REST, publication, resend from HF). Closes the editor hole:
     * when B is being published while A is open in the editor, the next save of A would overwrite our
     * replacement with a stale copy containing the placeholder — this very save replaces it again.
     * $data comes with slashes (wp_unslash runs after the filter) — resolve() is robust to that.
     * Cheap for foreign posts: post_type -> ID -> marker in the content, only then meta.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function filter_post_data(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? '') !== 'post') {
            return $data;
        }
        $post_id = (int) ($postarr['ID'] ?? 0);
        if ($post_id <= 0) {
            // New post: the meta does not exist yet. PostWriter handles the receive path explicitly.
            return $data;
        }
        $content = (string) ($data['post_content'] ?? '');
        if (!str_contains($content, self::marker())) {
            return $data;
        }
        $links = self::links_of($post_id);
        if ($links === []) {
            return $data;
        }
        $content = self::resolve($content, $links);
        if (($data['post_status'] ?? '') === 'publish') {
            $content = self::unwrap_missing($content, $links);
        }
        $data['post_content'] = $content;

        return $data;
    }

    /**
     * The transition_post_status hook (priority 10, BEFORE PublishNotifier). On publication of target B:
     *  1. secures B's own content — on the usual path already resolved by the filter, but
     *     wp_publish_post (cron for scheduled posts) bypasses the filter; saved only on a difference;
     *  2. reverse lookup: published posts that link to B with a placeholder.
     * No remove_action: wp_update_post of post A is a publish -> publish transition, to which this
     * hook does not react, and the filter is idempotent.
     */
    public static function on_transition(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        $article_id = PostWriter::article_id_of($post);
        if ($article_id === null) {
            return;
        }
        self::resolve_own($post);
        self::resolve_linking_posts($post->ID, $article_id);
    }

    private static function resolve_own(\WP_Post $post): void
    {
        $content = (string) $post->post_content;
        if (!str_contains($content, self::marker())) {
            return;
        }
        $links = self::links_of($post->ID);
        if ($links === []) {
            return;
        }
        $resolved = self::unwrap_missing(self::resolve($content, $links), $links);
        if ($resolved === $content) {
            return;
        }
        // wp_update_post expects data with slashes.
        self::$resolving[$post->ID] = true;
        try {
            wp_update_post([
                'ID'            => $post->ID,
                'post_content'  => wp_slash($resolved),
                // Without an explicit date core recomputes the status from post_date: a published post with a date
                // in the future (import, wrong time zone) would be silently unpublished.
                'post_date'     => $post->post_date,
                'post_date_gmt' => $post->post_date_gmt,
            ]);
        } finally {
            unset(self::$resolving[$post->ID]);
        }
    }

    /** True while resolve_own() saves this post (PublishNotifier skips that nested save). */
    public static function is_resolving(int $post_id): bool
    {
        return isset(self::$resolving[$post_id]);
    }

    /**
     * Posts A whose meta contains the article_id of B — meta_query LIKE over the serialized array.
     * Published only: drafts self-heal on their own save. The REVERSE_LIMIT limit
     * bounds the work in the publish request; the rest catches up when those posts are saved themselves.
     */
    private static function resolve_linking_posts(int $exclude_id, string $article_id): void
    {
        // The filter exists for tests and for an installation that really hits the limit.
        $limit = (int) Hooks::filter('reverse_limit', self::REVERSE_LIMIT);

        $ids = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'exclude'        => [$exclude_id],
            'meta_query'     => [[
                'key'     => self::META_LINKS,
                'value'   => $article_id,
                'compare' => 'LIKE',
            ]],
        ]);
        foreach ($ids as $id) {
            $linking = get_post((int) $id);
            if ($linking instanceof \WP_Post) {
                self::resolve_own($linking);
            }
        }

        // Limit hit: the remaining linking posts will repair themselves on their own save, but without
        // this row nobody would learn that the limit is sometimes reached (spec: revisit).
        if (count($ids) >= $limit) {
            DeliveriesLog::record(
                $article_id,
                $exclude_id,
                'reverse_limit',
                sprintf(
                    /* translators: %d: limit of posts processed per publication */
                    __('repaired %d linking posts - per-publication limit reached, the rest will repair themselves on their own save', 'tribune-desk'),
                    $limit
                ),
                DeliveriesLog::KIND_NOTIFY
            );
        }
    }
}
