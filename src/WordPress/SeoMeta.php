<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps HeadlinesForge payload fields onto Yoast SEO post meta.
 *
 * This is the ONLY place in the plugin that knows any Yoast key, so supporting
 * another SEO plugin later means editing this map — not PostWriter. Values go in
 * through update_post_meta(), so no Yoast code needs to be loaded: with Yoast
 * inactive the values simply wait in the database until it is switched on.
 *
 * 1.1.0: the meta description and the Facebook title may go ADDITIONALLY to meta keys named
 * in settings (e.g. a theme that reads post_meta_desc, facebook_title). An empty setting
 * = Yoast only, as before 1.1.0 — which is why no theme name appears in the code.
 */
class SeoMeta
{
    public const META_TITLE = '_yoast_wpseo_title';
    public const META_DESC  = '_yoast_wpseo_metadesc';
    public const META_FOCUS = '_yoast_wpseo_focuskw';

    public const OPTION_DESC_KEY     = 'headlinesforge_meta_desc_key';
    public const OPTION_FB_TITLE_KEY = 'headlinesforge_fb_title_key';

    /** @var array<string, string> payload key => option holding the meta key of a theme field */
    private const THEME_OPTIONS = [
        'meta_description' => self::OPTION_DESC_KEY,
        'facebook_title'   => self::OPTION_FB_TITLE_KEY,
    ];

    /** @var array<string, string> payload key => Yoast post meta key */
    private const MAP = [
        'seo_title'        => self::META_TITLE,
        'meta_description' => self::META_DESC,
        'focus_keyphrase'  => self::META_FOCUS,
    ];

    /** @param array<string, mixed> $payload */
    public static function sync(int $post_id, array $payload): void
    {
        foreach (self::targets() as [$payload_key, $meta_key]) {
            $raw = $payload[$payload_key] ?? null;
            if (!is_string($raw)) {
                continue;
            }

            // sanitize_text_field() collapses whitespace and trims the edges, so
            // an all-blank value arrives here as ''. No extra trim() needed.
            $value = sanitize_text_field($raw);
            if ($value === '') {
                continue;
            }

            // Never clobber an editor's manual edit in Yoast: a re-send only
            // fills fields that are still empty. Yoast's own meta boxes write
            // strings, so a non-string here means another plugin owns the key —
            // leave that alone too.
            $existing = get_post_meta($post_id, $meta_key, true);
            if ($existing !== '') {
                continue;
            }

            update_post_meta($post_id, $meta_key, $value);
        }
    }

    /**
     * Pairs [payload key, meta key]: Yoast first, then the theme fields from settings.
     * The same protection for both: the plugin fills only an empty field, so an editor's
     * manual fix survives a re-send. The key from the option is read through sanitize_meta_key,
     * because options can also be saved outside the settings screen (WP-CLI, update_option).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function targets(): array
    {
        $targets = [];
        foreach (self::MAP as $payload_key => $meta_key) {
            $targets[] = [$payload_key, $meta_key];
        }
        foreach (self::THEME_OPTIONS as $payload_key => $option) {
            $meta_key = Settings::sanitize_meta_key(get_option($option, ''));
            if ($meta_key !== '') {
                $targets[] = [$payload_key, $meta_key];
            }
        }

        return $targets;
    }
}
