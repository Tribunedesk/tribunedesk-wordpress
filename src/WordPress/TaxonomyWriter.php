<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\Taxonomy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The categories and tags of a delivery on the post (contract 3.8, guide 3 "Categories and tags").
 *
 * Taxonomy::requested() decides what to set and Taxonomy::resolve() finds the terms; this class
 * only looks terms up in WordPress and sets them. It never creates a term (owner, 2026-11-25): a
 * term WordPress does not have goes to `ignored`. A taxonomy the delivery leaves out stays as it
 * is, and so does one whose non-empty list found nothing (only a genuine `[]` clears).
 *
 * The terms go on the post before it is saved (fields of wp_insert_post for a new post, a write
 * before wp_update_post for an existing one): the publish hooks inside the save — the permalink
 * other posts link to, the address reported to Tribune Desk — must see the delivered category,
 * because a theme may build the permalink from it.
 */
class TaxonomyWriter
{
    /** Delivery key => WordPress taxonomy. */
    private const TAXONOMIES = [
        'categories' => 'category',
        'tags'       => 'post_tag',
    ];

    /**
     * Finds the terms of `$requested` (Taxonomy::requested()) without writing anything.
     *
     * @param array{categories: list<array{cms_term_id: ?string, name: string}>|null, tags: list<array{cms_term_id: ?string, name: string}>|null} $requested
     * @return array{set: array{categories?: list<int>, tags?: list<int>}, ignored: list<array{name: string, reason: string}>}
     *         `set` has a key only for a taxonomy to replace
     */
    public static function lookup(array $requested): array
    {
        $set = [];
        $ignored = [];
        foreach (self::TAXONOMIES as $key => $taxonomy) {
            $refs = $requested[$key] ?? null;
            if ($refs === null) {
                continue;
            }
            $other = $taxonomy === 'category' ? 'post_tag' : 'category';
            $found = Taxonomy::resolve(
                $refs,
                static fn (string $id): ?int => self::by_id($id, $taxonomy, $other),
                static fn (string $name): ?int => self::by_name($name, $taxonomy, $other)
            );
            $ignored = array_merge($ignored, $found['ignored']);
            // A non-empty list of which nothing was found leaves the taxonomy alone.
            if ($refs === [] || $found['ids'] !== []) {
                $set[$key] = $found['ids'];
            }
        }

        return ['set' => $set, 'ignored' => $ignored];
    }

    /**
     * Fields of wp_insert_post() for a new post: core sets them before the status transition.
     * No `tax_input`, which core drops for a user without assign_terms (a delivery runs as user 0).
     *
     * @param array{categories?: list<int>, tags?: list<int>} $set from lookup()
     * @return array<string, list<int>>
     */
    public static function insert_fields(array $set): array
    {
        $fields = [];
        // An empty category list: no field, and core gives the post the default category.
        if (($set['categories'] ?? []) !== []) {
            $fields['post_category'] = $set['categories'];
        }
        if (($set['tags'] ?? []) !== []) {
            $fields['tags_input'] = $set['tags'];
        }

        return $fields;
    }

    /**
     * Sets the terms of lookup() on an existing post, before wp_update_post().
     *
     * @param array{categories?: list<int>, tags?: list<int>} $set
     */
    public static function write(int $post_id, array $set): void
    {
        if (isset($set['categories'])) {
            // An empty list falls back to the site's default category (guide 3).
            wp_set_post_categories($post_id, $set['categories'], false);
        }
        if (isset($set['tags'])) {
            wp_set_post_terms($post_id, $set['tags'], 'post_tag', false);
        }
    }

    /** The term with this id in `$taxonomy`, OTHER_TAXONOMY when it is in `$other`, else null. */
    private static function by_id(string $id, string $taxonomy, string $other): ?int
    {
        if (!ctype_digit($id) || (int) $id < 1) {
            return null;
        }
        $term = get_term((int) $id);
        if (!$term instanceof \WP_Term) {
            return null;
        }
        if ($term->taxonomy === $taxonomy) {
            return (int) $term->term_id;
        }

        return $term->taxonomy === $other ? Taxonomy::OTHER_TAXONOMY : null;
    }

    /**
     * The term with this name in `$taxonomy`, OTHER_TAXONOMY when only `$other` has it, else null.
     * get_term_by() compares in the database: with the usual *_ci collation that ignores letter
     * case and accents, and it returns one row when several terms match.
     */
    private static function by_name(string $name, string $taxonomy, string $other): ?int
    {
        $term = get_term_by('name', $name, $taxonomy);
        if ($term instanceof \WP_Term) {
            return (int) $term->term_id;
        }

        return get_term_by('name', $name, $other) instanceof \WP_Term ? Taxonomy::OTHER_TAXONOMY : null;
    }

    /**
     * The terms now on the post, as the answer lists them. Names without HTML entities (2.7.1):
     * WordPress stores `&` as `&amp;`, like CmsTaxonomy, which decodes them before sending.
     *
     * @return list<array{cms_term_id: string, name: string}>
     */
    public static function terms_of(int $post_id, string $key): array
    {
        $terms = wp_get_object_terms($post_id, self::TAXONOMIES[$key]);
        if (!is_array($terms)) {
            return [];
        }

        return array_values(array_map(
            static fn (\WP_Term $t): array => ['cms_term_id' => (string) $t->term_id, 'name' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8')],
            array_filter($terms, static fn ($t): bool => $t instanceof \WP_Term)
        ));
    }
}
