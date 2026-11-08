<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Builds and reads the link back: telling Tribune Desk where delivered articles were published.
 *
 * Level: 2 · Routes: POST /v3/cms/articles/published · Guide: guide/2-link-back.md
 *
 * One batch holds 1 to 100 items {article_id, url, published_at?, social_queue_exclude?}. Tribune
 * Desk validates article_id as a UUID and rejects the whole batch (422) for one bad id, so items
 * without a UUID or a URL are dropped before sending. The answer has one result per item; a bad
 * item does not fail the others.
 *
 * social_queue_exclude: true keeps the article out of the social (Facebook) queue, false lets it
 * in; a missing field leaves the stored flag unchanged. A CMS that maps the flag to a field sends
 * it on every report, false included, or clearing the field would never reach Tribune Desk.
 */
final class LinkBack
{
    public const PATH       = '/v3/cms/articles/published';
    public const MAX_ITEMS  = 100;
    // 'forbidden' is no longer returned since contract 3.4.0; kept so older answers parse.
    public const STATUSES   = ['ok', 'not_found', 'forbidden', 'invalid_url'];

    /** CMS field values (any case, trimmed) that mean "true"; anything else is false. */
    public const TRUTHY = ['1', 'true', 'yes', 'on'];

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function is_uuid(string $value): bool
    {
        return preg_match(self::UUID_RE, $value) === 1;
    }

    /**
     * A CMS field value as a boolean flag: 1/true/yes/on (any case) are true; anything else,
     * an empty or missing value included, is false.
     *
     * @param mixed $value
     */
    public static function is_truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_scalar($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), self::TRUTHY, true);
    }

    /**
     * Items ready to send (lower-case UUID, non-empty url, published_at when given,
     * social_queue_exclude when given as a boolean) and the number of items dropped.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{0: array<int, array{article_id: string, url: string, published_at?: string, social_queue_exclude?: bool}>, 1: int}
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
            if (is_bool($item['social_queue_exclude'] ?? null)) {
                $article['social_queue_exclude'] = $item['social_queue_exclude'];
            }
            $ready[] = $article;
        }

        return [$ready, $dropped];
    }

    /** @param array<int, array<string, mixed>> $items */
    public static function body(array $items): array
    {
        return ['items' => $items];
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
