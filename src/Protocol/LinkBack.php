<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Builds and reads the link back: telling Tribune Desk where delivered articles were published.
 *
 * Level: 2 · Routes: POST /v3/cms/articles/published (POST /v2/cms/published-urls, deprecated) · Guide: guide/2-link-back.md
 *
 * One batch holds 1 to 100 items {article_id, url, published_at?}. Tribune Desk validates
 * article_id as a UUID and rejects the whole batch (422) for one bad id, so items without a UUID
 * or a URL are dropped before sending. The answer has one result per item; a bad item does not
 * fail the others. The deprecated route takes the old body (article_id and url only).
 */
final class LinkBack
{
    public const PATH       = '/v3/cms/articles/published';
    public const PATH_OLD   = '/v2/cms/published-urls';
    public const MAX_ITEMS  = 100;
    public const STATUSES   = ['ok', 'not_found', 'forbidden', 'invalid_url'];

    /**
     * HTTP codes of the /v3 route after which the same batch goes the old way: a token without
     * articles:write (401/403, media-only installs) or a backend without the route (404).
     */
    public const FALLBACK_CODES = [401, 403, 404];

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function is_uuid(string $value): bool
    {
        return preg_match(self::UUID_RE, $value) === 1;
    }

    /**
     * Items ready to send (lower-case UUID, non-empty url, published_at when given) and the
     * number of items dropped.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{0: array<int, array{article_id: string, url: string, published_at?: string}>, 1: int}
     */
    public static function prepare(array $items): array
    {
        $ready   = [];
        $dropped = 0;
        foreach ($items as $item) {
            $id  = strtolower(trim((string) ($item['article_id'] ?? '')));
            $url = (string) ($item['url'] ?? '');
            if (!self::is_uuid($id) || $url === '') {
                $dropped++;
                continue;
            }
            $article      = ['article_id' => $id, 'url' => $url];
            $published_at = (string) ($item['published_at'] ?? '');
            if ($published_at !== '') {
                $article['published_at'] = $published_at;
            }
            $ready[] = $article;
        }

        return [$ready, $dropped];
    }

    /** @param array<int, array<string, string>> $items */
    public static function body(array $items): array
    {
        return ['items' => $items];
    }

    /** @param array<int, array<string, string>> $items */
    public static function body_old(array $items): array
    {
        return [
            'articles' => array_map(
                static fn(array $a): array => ['article_id' => $a['article_id'], 'url' => $a['url']],
                $items
            ),
        ];
    }

    public static function falls_back(?int $http): bool
    {
        return in_array($http, self::FALLBACK_CODES, true);
    }

    /**
     * Per-item results of a 2xx answer (article_id => status), or null when the answer has no
     * `results` list. Unknown statuses and rows without an id are skipped.
     *
     * @return array<string, string>|null
     */
    public static function results(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !is_array($decoded['results'] ?? null)) {
            return null;
        }
        $results = [];
        foreach ($decoded['results'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            $id     = strtolower((string) ($row['article_id'] ?? ''));
            if ($id === '' || !in_array($status, self::STATUSES, true)) {
                continue;
            }
            $results[$id] = $status;
        }

        return $results;
    }

    /** "Y-m-d H:i:s" in UTC as ISO 8601, or null for an unset date (0000-00-00). */
    public static function iso_date(string $gmt): ?string
    {
        if ($gmt === '' || str_starts_with($gmt, '0000-00-00')) {
            return null;
        }
        $ts = strtotime($gmt . ' UTC');

        return $ts === false ? null : gmdate('Y-m-d\TH:i:s+00:00', $ts);
    }
}
