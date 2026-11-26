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
 * term WordPress does not have goes to `ignored`. A taxonomy the delivery leaves out stays as it is.
 */
class TaxonomyWriter
{
    /** Delivery key => WordPress taxonomy. */
    private const TAXONOMIES = [
        'categories' => 'category',
        'tags'       => 'post_tag',
    ];

    /**
     * Sets the terms of `$requested` (Taxonomy::requested()) on the post.
     *
     * @param array{categories: list<array{cms_term_id: ?string, name: string}>|null, tags: list<array{cms_term_id: ?string, name: string}>|null} $requested
     * @return list<array{name: string, reason: string}> the terms not set
     */
    public static function apply(int $post_id, array $requested): array
    {
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
            if ($taxonomy === 'category') {
                // An empty list falls back to the site's default category (guide 3).
                wp_set_post_categories($post_id, $found['ids'], false);
            } else {
                wp_set_post_terms($post_id, $found['ids'], $taxonomy, false);
            }
            $ignored = array_merge($ignored, $found['ignored']);
        }

        return $ignored;
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
     * get_term_by() compares in the database, whose collation ignores letter case.
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
     * The terms now on the post, as the answer lists them.
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
            static fn (\WP_Term $t): array => ['cms_term_id' => (string) $t->term_id, 'name' => $t->name],
            array_filter($terms, static fn ($t): bool => $t instanceof \WP_Term)
        ));
    }
}
