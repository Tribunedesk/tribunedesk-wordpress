<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Reads `backlink_sources` and `evergreen` of a delivery: which older articles should link to
 * this one, and whether it stays current.
 *
 * Level: 3 · Routes: webhook article.delivered (backlink_sources, evergreen) · Guide: guide/3-full-content.md#the-read-more-box
 *
 * The rules: a missing key means "leave the existing links alone" (null); an empty list means
 * "no older article should link here"; a list replaces the previous one. Entries without a
 * valid UUID are dropped, duplicates keep their first occurrence.
 */
final class BacklinkSources
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{article_id: string, distance: float|null}>|null null = key missing
     */
    public static function from_payload(array $payload): ?array
    {
        $raw = $payload['backlink_sources'] ?? null;
        if (!is_array($raw)) {
            return null;
        }
        $sources = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['article_id'] ?? null;
            if (!is_string($id) || !LinkBack::is_uuid($id)) {
                continue;
            }
            $id = strtolower($id);
            if (isset($sources[$id])) {
                continue;
            }
            $distance     = $item['distance'] ?? null;
            $sources[$id] = [
                'article_id' => $id,
                'distance'   => is_numeric($distance) ? (float) $distance : null,
            ];
        }

        return array_values($sources);
    }

    /**
     * `evergreen` as a boolean, or null when it is missing or not a boolean-like value
     * (an explicit null is "no value", not false).
     *
     * @param array<string, mixed> $payload
     */
    public static function evergreen(array $payload): ?bool
    {
        if (!isset($payload['evergreen'])) {
            return null;
        }

        return filter_var($payload['evergreen'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
