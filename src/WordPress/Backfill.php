<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backfill of addresses of already published HF posts to HeadlinesForge (spec 0.8.0 D1).
 *
 * One batch (<= 100) per click, cursor = ID of the last sent post in an option,
 * reset on reaching the end. The job time is capped: one wp_remote_post
 * with a 10 s timeout, on 429 a pause and ONE retry of the same batch (worst case
 * 10 + 5 + 10 = 25 s, below the default max_execution_time). The HF contract is idempotent
 * (last write wins), so resending the same addresses is harmless.
 */
class Backfill
{
    public const OPTION_CURSOR    = 'headlinesforge_backfill_cursor';
    public const ACTION           = 'headlinesforge_backfill';
    public const BATCH            = PublishedUrlsClient::MAX_ITEMS;
    public const TIMEOUT          = 10;
    public const PAUSE_SECONDS    = 5;
    public const PAUSE_MAX        = 5;
    public const TRANSIENT_RESULT = 'headlinesforge_backfill_result';

    public static function cursor(): int
    {
        return (int) get_option(self::OPTION_CURSOR, 0);
    }

    /** Number of published posts with _aw_article_id and an ID above the cursor. */
    public static function remaining(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.ID > %d",
            PostWriter::META_ARTICLE_ID,
            self::cursor()
        ));
    }

    /**
     * @return array{status: string, sent: int, remaining: int, detail: ?string, counts: array<string, int>}
     */
    public static function run_batch(): array
    {
        $cursor = self::cursor();
        $rows   = self::rows($cursor);

        if ($rows === []) {
            update_option(self::OPTION_CURSOR, 0);
            $detail = $cursor > 0
                ? __('all posts sent; the next click starts from the beginning', 'tribune-desk')
                : __('no published posts from Tribune Desk', 'tribune-desk');

            // No log row: there was no job, and the message on the page is enough.
            return ['status' => PublishedUrlsClient::STATUS_NOTHING_TO_SEND, 'sent' => 0, 'remaining' => 0, 'detail' => $detail, 'counts' => []];
        }

        // One query instead of a hundred: otherwise get_permalink() per post hits the database every time.
        _prime_post_caches(array_map(static fn(array $row): int => (int) $row['ID'], $rows), false, false);

        $items   = [];
        $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = max($last_id, (int) $row['ID']);
            $item = [
                'article_id' => (string) $row['article_id'],
                'url'        => (string) get_permalink((int) $row['ID']),
            ];
            $post = get_post((int) $row['ID']);
            $published_at = $post instanceof \WP_Post ? PublishedUrlsClient::published_at($post) : null;
            if ($published_at !== null) {
                $item['published_at'] = $published_at;
            }
            $items[] = SocialQueueFlag::add_to($item, (int) $row['ID']);
        }

        $result = PublishedUrlsClient::send($items, self::TIMEOUT);
        if ($result['status'] === PublishedUrlsClient::STATUS_RATE_LIMITED) {
            self::pause($result['retry_after']);
            $result = PublishedUrlsClient::send($items, self::TIMEOUT);
        }

        // nothing_to_send after the UUID filter = only old test identifiers; the cursor
        // skips them, because they will never become sendable.
        $advance = in_array($result['status'], [PublishedUrlsClient::STATUS_SENT, PublishedUrlsClient::STATUS_NOTHING_TO_SEND], true);
        $at_end  = count($rows) < self::BATCH;

        if ($advance) {
            update_option(self::OPTION_CURSOR, $at_end ? 0 : $last_id);
        }

        $detail = match ($result['status']) {
            PublishedUrlsClient::STATUS_SENT            => PublishedUrlsClient::format_counts($result['counts']),
            PublishedUrlsClient::STATUS_NOTHING_TO_SEND => sprintf(
                /* translators: %d: number of posts skipped */
                __('skipped %d posts without a UUID', 'tribune-desk'),
                (int) $result['counts']['skipped_non_uuid']
            ),
            PublishedUrlsClient::STATUS_RATE_LIMITED    => __('Tribune Desk request limit hit again after the retry — try again in a minute', 'tribune-desk'),
            default                                     => $result['error'],
        };
        DeliveriesLog::record(
            'batch:' . (int) $result['items_sent'],
            null,
            (string) $result['status'],
            $detail,
            DeliveriesLog::KIND_BACKFILL
        );

        return [
            'status'    => (string) $result['status'],
            'sent'      => (int) $result['items_sent'],
            'remaining' => ($advance && $at_end) ? 0 : self::remaining(),
            'detail'    => $detail,
            'counts'    => $result['counts'],
        ];
    }

    /** admin-post.php handler: capability, nonce, one batch, result in a transient, redirect. */
    public static function handle_admin_post(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'tribune-desk'), 403);
        }
        check_admin_referer(self::ACTION);

        $result = self::run_batch();
        set_transient(self::TRANSIENT_RESULT . '_' . get_current_user_id(), $result, MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg(['page' => Settings::PAGE_SLUG, 'hf_backfill' => '1'], admin_url('options-general.php')));
        exit;
    }

    /** @return array<string, mixed>|null result of the current user's last run; deleted on read */
    public static function take_result(): ?array
    {
        $key    = self::TRANSIENT_RESULT . '_' . get_current_user_id();
        $result = get_transient($key);
        if (!is_array($result)) {
            return null;
        }
        delete_transient($key);

        return $result;
    }

    /** @return array<int, array{ID: string, article_id: string}> */
    private static function rows(int $cursor): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, m.meta_value AS article_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            PostWriter::META_ARTICLE_ID,
            $cursor,
            self::BATCH
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Tribune Desk answers 429 with `Retry-After` (600 requests per minute per token, contract
     * 3.4.0); we honor it up to PAUSE_MAX seconds. The filter exists for tests (0 s) — sleep() in the suite is 5 s wasted.
     */
    private static function pause(?int $retry_after): void
    {
        $seconds = min($retry_after !== null && $retry_after > 0 ? $retry_after : self::PAUSE_SECONDS, self::PAUSE_MAX);
        $seconds = (int) Hooks::filter('backfill_pause_seconds', $seconds);
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
