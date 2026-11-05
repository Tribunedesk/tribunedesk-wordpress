<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A sample "Read more" box: shortcode [tribune_desk_read_more] and block tribune-desk/read-more.
 *
 * Level 3 of the Tribune Desk CMS API, guide/3-full-content.md ("The read more box"). Tribune
 * Desk sends `backlink_sources` (older articles that should link to this one); Backlinks turns
 * them into rows of {prefix}hf_backlinks (source_post_id = the older post, target_post_id = the
 * newer one). On the page of a post this box lists the published targets of its rows, newest
 * first. How the box looks is up to the site: the filter `tribune_desk_read_more_html`
 * replaces the HTML (the 1.x name `headlinesforge_read_more_html` still works). The plugin never adds the box by itself (a theme may already draw one).
 */
final class ReadMore
{
    public const SHORTCODE     = 'tribune_desk_read_more';
    public const BLOCK         = 'tribune-desk/read-more';
    public const SCRIPT        = 'tribune-desk-read-more-block';
    public const DEFAULT_LIMIT = 5;
    public const MAX_LIMIT     = 20;

    public static function register(): void
    {
        add_shortcode(self::SHORTCODE, [self::class, 'shortcode']);

        // Plain script on WordPress globals (wp.blocks, wp.serverSideRender): no build step.
        // Registered on every call (a second registration is a no-op), so the script and its
        // translations exist even when the block was registered earlier.
        wp_register_script(
            self::SCRIPT,
            plugins_url('assets/read-more-block.js', HEADLINESFORGE_PLUGIN_DIR . 'headlinesforge-receiver.php'),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'],
            HEADLINESFORGE_VERSION,
            true
        );
        wp_set_script_translations(self::SCRIPT, 'tribune-desk', HEADLINESFORGE_PLUGIN_DIR . 'languages');

        if (\WP_Block_Type_Registry::get_instance()->is_registered(self::BLOCK)) {
            return;
        }
        register_block_type(self::BLOCK, [
            'api_version'     => 2,
            'editor_script'   => self::SCRIPT,
            'render_callback' => static fn(array $attributes): string => self::render((int) get_the_ID(), $attributes),
            'attributes'      => [
                'limit' => ['type' => 'integer', 'default' => self::DEFAULT_LIMIT],
                'title' => ['type' => 'string', 'default' => ''],
            ],
        ]);
    }

    /** @param array<string, mixed>|string $atts */
    public static function shortcode($atts): string
    {
        $atts = shortcode_atts(['limit' => self::DEFAULT_LIMIT, 'title' => ''], is_array($atts) ? $atts : []);

        return self::render((int) get_the_ID(), $atts);
    }

    /**
     * Published targets of the post's rows, newest first.
     *
     * @return array<int, \WP_Post>
     */
    public static function targets(int $post_id, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($post_id <= 0) {
            return [];
        }
        global $wpdb;
        $table = Backlinks::table();
        $ids   = $wpdb->get_col($wpdb->prepare(
            "SELECT target_post_id FROM $table WHERE source_post_id = %d ORDER BY created_at DESC, target_post_id DESC LIMIT %d",
            $post_id,
            self::MAX_LIMIT * 5
        ));

        $posts = [];
        foreach ($ids ?: [] as $id) {
            $post = get_post((int) $id);
            if ($post instanceof \WP_Post && $post->post_status === 'publish' && $post->ID !== $post_id) {
                $posts[] = $post;
            }
            if (count($posts) >= $limit) {
                break;
            }
        }

        return $posts;
    }

    /** @param array<string, mixed> $atts limit (1-20), title */
    public static function render(int $post_id, array $atts = []): string
    {
        $limit = max(1, min(self::MAX_LIMIT, (int) ($atts['limit'] ?? self::DEFAULT_LIMIT)));
        $title = trim((string) ($atts['title'] ?? ''));
        if ($title === '') {
            $title = __('Read more', 'tribune-desk');
        }

        $posts = self::targets($post_id, $limit);
        if ($posts === []) {
            return '';
        }

        $items = '';
        foreach ($posts as $post) {
            $items .= '<li><a href="' . esc_url((string) get_permalink($post)) . '">'
                . esc_html(get_the_title($post)) . '</a></li>';
        }
        $html = '<aside class="td-read-more"><h2>' . esc_html($title) . '</h2><ul>' . $items . '</ul></aside>';

        return (string) Hooks::filter('read_more_html', $html, $posts, $post_id);
    }
}
