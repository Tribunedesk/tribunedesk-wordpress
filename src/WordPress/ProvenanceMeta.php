<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Moves the AI labelling block (art. 50(2)) from the post content into post meta.
 *
 * Why not in the content: the block is a <script type="application/ld+json"> element,
 * and the Sanitizer strips scripts together with their insides. Letting it through wp_kses
 * would require adding `script` to the allowed tags, i.e. opening the way for any
 * script from the payload — the one thing a sanitizer must never do.
 *
 * This class is a CARRIER, not the author of the block: it takes out the ready JSON built by the
 * backend (provenance/embed.py) and reproduces it unchanged. The mapping to schema.org
 * stays on one side, so a format change needs no change here.
 */
class ProvenanceMeta
{
    public const META_KEY = '_hf_ai_provenance';

    private const BLOCK_RE = '#<script\b[^>]*\bdata-hf-provenance\b[^>]*>(.*?)</script>#is';

    /**
     * Takes the block out of the payload's raw HTML and stores it in the post meta.
     *
     * 1.4.0: no block (the publisher switched labelling off in Tribune Desk) or a block
     * without a signature / with broken JSON DELETES the meta. Sync runs only for a new
     * post and for a draft (PostWriter does not touch published ones), and a draft is just
     * getting new content — the old signature would not match it and would look like
     * tampering. A bad block is treated like no block: we have no signature that matches
     * this content anyway.
     */
    public static function sync(int $post_id, string $html): void
    {
        if (preg_match(self::BLOCK_RE, $html, $matches) !== 1) {
            delete_post_meta($post_id, self::META_KEY);
            return;
        }

        $data = json_decode($matches[1], true);
        if (!is_array($data) || !isset($data['hfProvenanceJws'])) {
            delete_post_meta($post_id, self::META_KEY);
            return;
        }

        update_post_meta($post_id, self::META_KEY, $data);
    }

    /** Labelling fields added to the Yoast article (the rest of the block duplicates what Yoast already describes). */
    private const YOAST_FIELDS = ['digitalSourceType', 'hfProvenanceJws', 'identifier'];

    /** Whether the labelling already went into the Yoast graph in this request (then render() does not print a second object). */
    private static bool $merged_into_yoast = false;

    /**
     * 1.5.0: adds the labelling to the article object in the Yoast SEO graph instead of
     * a separate NewsArticle — one description of the text with a human author and the AI mark.
     * Hooked to the `wpseo_schema_article` filter. Yoast prints the graph on wp_head
     * with priority 1, i.e. before render() (10), so the flag is set in time.
     *
     * @param mixed $data Article object from Yoast
     * @return mixed
     */
    public static function yoast_article($data)
    {
        if (!is_array($data) || !is_singular()) {
            return $data;
        }

        $provenance = self::current();
        if ($provenance === null) {
            return $data;
        }

        foreach (self::YOAST_FIELDS as $field) {
            // Yoast fields win: we never overwrite anything it already described.
            if (isset($provenance[$field]) && !isset($data[$field])) {
                $data[$field] = $provenance[$field];
            }
        }
        self::$merged_into_yoast = true;

        return $data;
    }

    /** Prints the block in the <head> of a single post. Hooked to wp_head. */
    public static function render(): void
    {
        if (!is_singular() || self::$merged_into_yoast) {
            return;
        }

        $data = self::current();
        if ($data === null) {
            return;
        }

        // We encode BACK from the array instead of printing the stored text.
        // This way the output is always valid JSON and slashes stay
        // escaped — so "</script>" inside a value cannot close the
        // element and break out of it into the document.
        $json = wp_json_encode($data);
        if (!is_string($json)) {
            return;
        }

        echo '<script type="application/ld+json" data-hf-provenance>' . $json . '</script>' . "\n";
    }

    /** Labelling of the displayed post, or null when there is none. */
    private static function current(): ?array
    {
        $data = get_post_meta((int) get_queried_object_id(), self::META_KEY, true);

        return is_array($data) && $data !== [] ? $data : null;
    }
}
