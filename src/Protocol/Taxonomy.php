<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * The categories and tags of a delivery: what to set, how to find each term, what to answer.
 *
 * Level: 3 · Routes: webhook article.delivered, POST /v3/cms/taxonomy/terms · Guide: guide/3-full-content.md#categories-and-tags
 *
 * Contract 3.8: the optional `taxonomy` carries `categories` and `tags`, each a list of
 * `{cms_term_id, name}`. A key that is present means "set exactly these" (an empty list removes
 * them all); a missing key, or a missing `taxonomy`, means "leave that taxonomy alone". A term is
 * found by `cms_term_id`, and when the CMS does not know the id, by `name` (any letter case). The
 * CMS never creates a term: one it cannot find goes to `ignored` with a reason. Only a post that
 * is new or not public yet (DraftRules::UPDATABLE) is changed; a published or private one never.
 * A field that cannot be read is ignored, like any field this library does not know.
 *
 * Sending the CMS's own terms to `POST /v3/cms/taxonomy/terms` is the CMS's HTTP client's job;
 * this class covers the delivery side.
 */
final class Taxonomy
{
    /** `ignored.reason`: no such term (and the CMS must not create one). */
    public const UNKNOWN_TERM = 'unknown_term';

    /** `ignored.reason`: the term exists, but in the other taxonomy. */
    public const WRONG_TAXONOMY = 'wrong_taxonomy';

    /** What a lookup of resolve() returns for a term that exists in the other taxonomy. */
    public const OTHER_TAXONOMY = -1;

    /**
     * The terms this delivery sets, per taxonomy; null for a taxonomy to leave alone.
     *
     * Null as a whole when there is nothing to do: no readable `categories` or `tags`, or a post
     * that is already public. Terms without a name are dropped; ids become text.
     *
     * @param string|null $existing_status status of the post with this article_id, null = none
     * @param mixed $taxonomy the delivery's `taxonomy`, as json_decode(…, true) gives it
     * @return array{categories: list<array{cms_term_id: ?string, name: string}>|null, tags: list<array{cms_term_id: ?string, name: string}>|null}|null
     */
    public static function requested(?string $existing_status, mixed $taxonomy): ?array
    {
        if ($existing_status !== null && !in_array($existing_status, DraftRules::UPDATABLE, true)) {
            return null;
        }
        if (!is_array($taxonomy) || array_is_list($taxonomy)) {
            return null;
        }
        $plan = [
            'categories' => self::terms($taxonomy['categories'] ?? null),
            'tags'       => self::terms($taxonomy['tags'] ?? null),
        ];

        return $plan['categories'] === null && $plan['tags'] === null ? null : $plan;
    }

    /**
     * The CMS ids of the terms, found first by `cms_term_id`, then by `name`; the rest ignored.
     *
     * Each lookup returns the CMS id of a term of this taxonomy, null when there is none, or
     * OTHER_TAXONOMY when the term exists in the other taxonomy. `$by_name` is expected to ignore
     * letter case. Ids come once each, in the order first found.
     *
     * @param list<array{cms_term_id: ?string, name: string}> $refs one taxonomy of requested()
     * @param callable(string): ?int $by_id
     * @param callable(string): ?int $by_name
     * @return array{ids: list<int>, ignored: list<array{name: string, reason: string}>}
     */
    public static function resolve(array $refs, callable $by_id, callable $by_name): array
    {
        $ids = [];
        $ignored = [];
        foreach ($refs as $ref) {
            $other = false;
            $id = null;
            if (($ref['cms_term_id'] ?? null) !== null) {
                $found = $by_id($ref['cms_term_id']);
                $other = $found === self::OTHER_TAXONOMY;
                $id = $found !== null && $found > 0 ? $found : null;
            }
            if ($id === null) {
                $found = $by_name($ref['name']);
                $other = $other || $found === self::OTHER_TAXONOMY;
                $id = $found !== null && $found > 0 ? $found : null;
            }
            if ($id === null) {
                $ignored[] = ['name' => $ref['name'], 'reason' => $other ? self::WRONG_TAXONOMY : self::UNKNOWN_TERM];
            } elseif (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return ['ids' => $ids, 'ignored' => $ignored];
    }

    /**
     * The answer's `taxonomy` (TaxonomyResult): the terms now on the post and the ones not set.
     *
     * @param list<array{cms_term_id: int|string|null, name: string}> $categories
     * @param list<array{cms_term_id: int|string|null, name: string}> $tags
     * @param list<array{name: string, reason: string}> $ignored
     * @return array{categories: list<array{cms_term_id: ?string, name: string}>, tags: list<array{cms_term_id: ?string, name: string}>, ignored: list<array{name: string, reason: string}>}
     * @throws \InvalidArgumentException When a reason is empty (the contract needs at least one character).
     */
    public static function answer(array $categories, array $tags, array $ignored): array
    {
        $result = static fn (array $term): array => [
            'cms_term_id' => isset($term['cms_term_id']) ? (string) $term['cms_term_id'] : null,
            'name'        => (string) $term['name'],
        ];

        return [
            'categories' => array_map($result, array_values($categories)),
            'tags'       => array_map($result, array_values($tags)),
            'ignored'    => array_map(static function (array $item): array {
                $reason = (string) $item['reason'];
                if ($reason === '') {
                    throw new \InvalidArgumentException('ignored.reason must not be empty');
                }
                return ['name' => (string) $item['name'], 'reason' => $reason];
            }, array_values($ignored)),
        ];
    }

    /**
     * One taxonomy of the field: a list of terms, or null when the key is absent or unreadable.
     *
     * Only a genuine `[]` clears the taxonomy: a non-empty list in which no term has a name
     * (blank names included) is unreadable and leaves the post's terms alone.
     *
     * @return list<array{cms_term_id: ?string, name: string}>|null
     */
    private static function terms(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $terms = [];
        foreach ($value as $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : null;
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $id = $item['cms_term_id'] ?? null;
            $terms[] = [
                'cms_term_id' => (is_string($id) && $id !== '') || is_int($id) ? (string) $id : null,
                'name'        => $name,
            ];
        }

        return $terms === [] && $value !== [] ? null : $terms;
    }
}
