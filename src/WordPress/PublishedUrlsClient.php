<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\LinkBack;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Client of the Tribune Desk link back: POST {api_base}/v3/cms/articles/published with the
 * tdcms_ API token (level 2, guide/2-link-back.md). The only place in the plugin that knows the shape of the request
 * and response. It knows nothing of posts, hooks or the log: it takes a list of article_id/url pairs,
 * and returns the result as an array with fixed keys. The timeout is an argument, because the
 * publish hook (5 s, the editor is waiting) and the backfill (10 s, batch of 100) have different budgets.
 */
class PublishedUrlsClient
{
    public const OPTION_API_BASE = 'headlinesforge_api_base';

    // api., not apeks: apeks is Cloudflare Pages and answers 404 or HTML
    // (lesson split-domain-callbacks).
    public const DEFAULT_API_BASE = 'https://api.headlinesforge.com';
    public const MAX_ITEMS        = 100;

    public const STATUS_SENT            = 'sent';
    public const STATUS_NOTHING_TO_SEND = 'nothing_to_send';
    public const STATUS_UNAUTHORIZED    = 'unauthorized';
    public const STATUS_RATE_LIMITED    = 'rate_limited';
    public const STATUS_ERROR           = 'error';
    public const STATUS_REJECTED        = 'rejected';

    private const BODY_EXCERPT  = 200;

    public static function is_uuid(string $value): bool
    {
        return LinkBack::is_uuid($value);
    }

    public static function api_base(): string
    {
        $configured = rtrim((string) get_option(self::OPTION_API_BASE, ''), '/');

        return $configured !== '' ? $configured : self::DEFAULT_API_BASE;
    }

    /**
     * @param array<int, array{article_id: string, url: string}> $items
     * @return array{status: string, http: ?int, error: ?string, counts: array<string, int>, results: array<string, string>, retryable: bool, retry_after: ?int, items_sent: int}
     */
    public static function send(array $items, int $timeout): array
    {
        $counts = ['ok' => 0, 'not_found' => 0, 'forbidden' => 0, 'invalid_url' => 0, 'skipped_non_uuid' => 0];
        // HF validates article_id as a UUID in Pydantic: one bad item (e.g. the test
        // art-100 from before 0.8.0) is a 422 for the WHOLE batch, not a per-item status.
        [$articles, $counts['skipped_non_uuid']] = LinkBack::prepare($items);

        $n = count($articles);
        if ($n === 0) {
            return self::result(self::STATUS_NOTHING_TO_SEND, null, __('no posts with a UUID to send', 'tribune-desk'), $counts, [], false, null, 0);
        }
        if ($n > self::MAX_ITEMS) {
            return self::result(self::STATUS_REJECTED, null, sprintf(
                /* translators: %d: maximum number of items in one batch */
                __('batch larger than %d items', 'tribune-desk'),
                self::MAX_ITEMS
            ), $counts, [], false, null, 0);
        }

        // 2.2.0: the link back goes only through /v3 with the token. Tribune Desk removed the old
        // /v2 route and its shared secret (E7, 2026-11-06), so without a token there is nowhere to send.
        if (!CmsApiClient::has_token()) {
            // Separate text from a refusal by Tribune Desk: here the plugin's configuration is at fault.
            return self::result(self::STATUS_UNAUTHORIZED, null, __('no Tribune Desk API token: the link back needs a token with the articles:write permission', 'tribune-desk'), $counts, [], false, null, 0);
        }

        return self::post($articles, $timeout, $counts, $n);
    }

    /**
     * POST /v3/cms/articles/published with the tdcms_ token (level 2, guide/2-link-back.md).
     *
     * @param array<int, array{article_id: string, url: string, published_at?: string}> $articles
     * @param array<string, int> $counts
     * @return array<string, mixed>
     */
    private static function post(array $articles, int $timeout, array $counts, int $n): array
    {
        $response = wp_remote_post(self::api_base() . LinkBack::PATH, [
            'timeout'     => $timeout,
            // No redirects: the token must not reach another host.
            'redirection' => 0,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . trim((string) get_option(CmsApiClient::OPTION_TOKEN, '')),
            ],
            'body'        => wp_json_encode(LinkBack::body($articles), JSON_UNESCAPED_SLASHES),
        ]);

        return self::read_response($response, $counts, $n);
    }

    /**
     * @param array|\WP_Error $response
     * @param array<string, int> $counts
     * @return array<string, mixed>
     */
    private static function read_response($response, array $counts, int $n): array
    {
        if (is_wp_error($response)) {
            return self::result(self::STATUS_ERROR, null, $response->get_error_message(), $counts, [], true, null, $n);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code === 401 || $code === 403) {
            // 401: revoked or invalid token; 403: a token without articles:write (media-only install).
            $why = $code === 401
                ? __('Tribune Desk rejected the token (revoked or invalid)', 'tribune-desk')
                : __('Tribune Desk token lacks the articles:write permission', 'tribune-desk');

            return self::result(self::STATUS_UNAUTHORIZED, $code, $why, $counts, [], false, null, $n);
        }
        if ($code === 404) {
            // A backend without the route (an older version, or api_base pointing at one).
            return self::result(self::STATUS_REJECTED, $code, __('Tribune Desk has no /v3/cms/articles/published route', 'tribune-desk'), $counts, [], false, null, $n);
        }
        if ($code === 429) {
            // HF does not send Retry-After today; if it starts to, the caller gets a number of seconds.
            $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');

            return self::result(self::STATUS_RATE_LIMITED, $code, __('HeadlinesForge request limit', 'tribune-desk'), $counts, [], true, $retry_after > 0 ? $retry_after : null, $n);
        }
        if ($code >= 500) {
            return self::result(self::STATUS_ERROR, $code, "http $code", $counts, [], true, null, $n);
        }
        if ($code < 200 || $code >= 300) {
            return self::result(self::STATUS_REJECTED, $code, "http $code: " . self::excerpt($body), $counts, [], false, null, $n);
        }

        $results = LinkBack::results($body);
        if ($results === null) {
            return self::result(self::STATUS_REJECTED, $code, __('response without a results field', 'tribune-desk'), $counts, [], false, null, $n);
        }
        foreach ($results as $status) {
            $counts[$status]++;
        }

        return self::result(self::STATUS_SENT, $code, null, $counts, $results, false, null, $n);
    }

    /**
     * Text for the log row for the status of a single item. Every status has its own text
     * (lesson komunikaty-diagnostyczne): forbidden = the token belongs to a DIFFERENT newsroom,
     * not_found = HF does not know the ID — two different diagnoses.
     */
    public static function describe(string $item_status, string $url): ?string
    {
        return match ($item_status) {
            'ok'          => null,
            'forbidden'   => __('the article exists, but not in this token\'s newsroom', 'tribune-desk'),
            'not_found'   => __('Tribune Desk does not know this article_id', 'tribune-desk'),
            'invalid_url' => sprintf(
                /* translators: %s: URL */
                __('HeadlinesForge rejected the address: %s', 'tribune-desk'),
                $url
            ),
            default       => sprintf(
                /* translators: %s: item status */
                __('unknown item status: %s', 'tribune-desk'),
                $item_status
            ),
        };
    }

    /** @param array<string, int> $counts */
    public static function format_counts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $key => $value) {
            $parts[] = $key . '=' . (int) $value;
        }

        return implode(' ', $parts);
    }

    private static function excerpt(string $body): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        return mb_substr($flat, 0, self::BODY_EXCERPT);
    }

    /**
     * @param array<string, int>    $counts
     * @param array<string, string> $results
     * @return array<string, mixed>
     */
    private static function result(
        string $status,
        ?int $http,
        ?string $error,
        array $counts,
        array $results,
        bool $retryable,
        ?int $retry_after,
        int $items_sent
    ): array {
        return compact('status', 'http', 'error', 'counts', 'results', 'retryable', 'retry_after', 'items_sent');
    }

    /**
     * Publication date of a post for the link back. A draft published by wp_publish_post() can
     * keep post_date_gmt at 0000-00-00, so the local post_date converted to GMT is the fallback.
     */
    public static function published_at(\WP_Post $post): ?string
    {
        $iso = self::iso_date((string) $post->post_date_gmt);
        if ($iso === null && (string) $post->post_date !== '' && !str_starts_with((string) $post->post_date, '0000-00-00')) {
            $iso = self::iso_date(get_gmt_from_date((string) $post->post_date));
        }

        return $iso;
    }

    /** post_date_gmt ("Y-m-d H:i:s") as ISO 8601 in UTC, or null for an unset date. */
    public static function iso_date(string $gmt): ?string
    {
        return LinkBack::iso_date($gmt);
    }
}
