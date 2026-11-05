<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Callback to HeadlinesForge when a post from HF is published (spec 0.8.0 D5/D6, spec HF D12).
 *
 * A synchronous send of one item (5 s timeout) in the transition_post_status hook,
 * one row in the deliveries log, and on a network error / 429 / 5xx ONE deferred retry
 * through WP-Cron (+5 min). The retry handler schedules nothing further: the second mechanism
 * is the backfill from settings. An error never blocks publishing — every HTTP result is
 * the client's return value, not an exception. On bulk publishing (Bulk edit) the send
 * is deferred to cron, so that N selected posts do not fire N blocking requests in
 * one request.
 */
class PublishNotifier
{
    public const RETRY_HOOK  = 'headlinesforge_notify_retry';
    public const RETRY_DELAY = 5 * MINUTE_IN_SECONDS;
    public const TIMEOUT     = 5;
    public const BULK_DELAY  = 30;

    public static function on_transition(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        $article_id = PostWriter::article_id_of($post);
        if ($article_id === null) {
            return;
        }

        // Bulk publishing (Bulk edit -> Status: Published) goes through this hook ONCE PER POST
        // in a single HTTP request. Fifteen drafts means fifteen consecutive calls of 5 s each, i.e.
        // 75 s with HF unavailable and the request dying on max_execution_time halfway through the batch.
        // That is why on bulk publishing the send is deferred to cron — by the same
        // mechanism that handles retries. The editor does not wait, the addresses arrive within a minute.
        if (isset($_REQUEST['bulk_edit'])) {
            wp_schedule_single_event(time() + self::BULK_DELAY, self::RETRY_HOOK, [$post->ID]);

            return;
        }

        $result = self::notify($post, $article_id, false);

        if ($result['retryable']) {
            // Core deduplicates an identical event (hook + arguments) within a 10-minute window.
            wp_schedule_single_event(time() + self::RETRY_DELAY, self::RETRY_HOOK, [$post->ID]);
        }
    }

    /** @param int|string $post_id */
    public static function retry($post_id): void
    {
        // An argument from the cron array can be a string after migrations/CLI, and strict_types turns that into a fatal.
        $post_id = (int) $post_id;
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return;
        }
        $article_id = PostWriter::article_id_of($post);
        if ($article_id === null) {
            return;
        }
        self::notify($post, $article_id, true);
    }

    /** @return array<string, mixed> result of PublishedUrlsClient::send() */
    private static function notify(\WP_Post $post, string $article_id, bool $is_retry): array
    {
        $url    = (string) get_permalink($post);
        $item = ['article_id' => $article_id, 'url' => $url];
        $published_at = PublishedUrlsClient::published_at($post);
        if ($published_at !== null) {
            $item['published_at'] = $published_at;
        }
        $result = PublishedUrlsClient::send([$item], self::TIMEOUT);

        $status = (string) $result['status'];
        $detail = $result['error'];

        if ($status === PublishedUrlsClient::STATUS_SENT) {
            // One item: the log row gets THIS item's status (ok / forbidden / ...),
            // not an aggregate "sent" — forbidden must be visible as forbidden (spec HF D10).
            $key = strtolower($article_id);
            if (isset($result['results'][$key])) {
                $status = (string) $result['results'][$key];
                $detail = PublishedUrlsClient::describe($status, $url);
            } else {
                $status = PublishedUrlsClient::STATUS_REJECTED;
                $detail = __('HeadlinesForge did not return a status for this item', 'tribune-desk');
            }
        }

        if (!empty($result['notice'])) {
            $detail = trim((string) $detail . ' (' . (string) $result['notice'] . ')');
        }

        if ($is_retry) {
            $retry_label = __('retry', 'tribune-desk');
            $detail = trim($retry_label . ': ' . (string) $detail, ': ');
            $detail = str_starts_with($detail, $retry_label) ? $detail : $retry_label . ': ' . $detail;
        } elseif (!empty($result['retryable'])) {
            $detail = trim((string) $detail . ' — ' . __('retry in 5 min', 'tribune-desk'));
        }

        DeliveriesLog::record(
            $article_id,
            $post->ID,
            $status,
            ($detail === null || $detail === '') ? null : $detail,
            DeliveriesLog::KIND_NOTIFY
        );

        return $result;
    }
}
