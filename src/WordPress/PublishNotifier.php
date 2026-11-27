<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Callback to HeadlinesForge when a post from HF is published (spec 0.8.0 D5/D6, spec HF D12).
 *
 * A synchronous send of one item (5 s timeout) in the wp_after_insert_post hook, one row in the
 * deliveries log, and on a network error / 429 / 5xx ONE deferred retry through WP-Cron (+5 min).
 * The retry handler schedules nothing further: the second mechanism is the backfill from
 * settings. An error never blocks publishing — every HTTP result is the client's return value,
 * not an exception. On bulk publishing (Bulk edit) the send is deferred to cron, so that N
 * selected posts do not fire N blocking requests in one request.
 *
 * 2.1.0: wp_after_insert_post (WordPress 5.6), not transition_post_status, because the second one
 * fires before save_post, where themes store their meta boxes — the "do not publish on Facebook"
 * field (SocialQueueFlag) would not be saved yet. The first publication is told by $post_before.
 * A change of that field on a published post is reported once more, once per post and request:
 * here when the change came with a post save, otherwise at shutdown (REST, WP-CLI, other code).
 * A published post whose address changes in a save (new slug or date) is reported again too.
 */
class PublishNotifier
{
    public const RETRY_HOOK  = 'headlinesforge_notify_retry';
    /** 2.7.1: the deferred send of a bulk publication: a first attempt, so it may get its retry. */
    public const BULK_HOOK   = 'tribunedesk_notify_bulk';
    public const RETRY_DELAY = 5 * MINUTE_IN_SECONDS;
    public const TIMEOUT     = 5;
    public const BULK_DELAY  = 30;

    /**
     * wp_after_insert_post: fires after save_post and after the REST API stored meta and terms,
     * also for wp_publish_post() (scheduled posts published by cron).
     *
     * @param int|string $post_id
     * @param mixed      $post
     * @param mixed      $update
     * @param mixed      $post_before null for a new post
     */
    public static function on_after_insert($post_id, $post, $update, $post_before): void
    {
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return;
        }
        // The save LinkResolver makes from the transition hook (placeholders resolved while the
        // post is being published) is not a publication of its own: the outer save reports the
        // post, so this one must not report it a second time (2.2.2).
        if (LinkResolver::is_resolving($post->ID)) {
            return;
        }
        $first = !$post_before instanceof \WP_Post || $post_before->post_status !== 'publish';
        if (!$first && !SocialQueueFlag::changed($post->ID) && !self::moved($post, $post_before)) {
            return;
        }
        $article_id = PostWriter::article_id_of($post);
        if ($article_id === null) {
            return;
        }
        self::dispatch($post, $article_id);
    }

    /**
     * A published post whose address changed in this save (a new slug or date): guide 2 asks
     * for the new address, otherwise Tribune Desk keeps linking to the old one. A save that
     * leaves the address as it was reports nothing.
     */
    private static function moved(\WP_Post $post, \WP_Post $post_before): bool
    {
        return get_permalink($post_before) !== get_permalink($post);
    }

    /**
     * shutdown: a field change that did not come with a post save (REST meta update, WP-CLI,
     * code calling update_post_meta) is reported here, once per post.
     */
    public static function flush_pending(): void
    {
        foreach (SocialQueueFlag::touched() as $post_id) {
            if (!SocialQueueFlag::changed($post_id)) {
                continue;
            }
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
                continue;
            }
            $article_id = PostWriter::article_id_of($post);
            if ($article_id === null) {
                continue;
            }
            self::dispatch($post, $article_id);
        }
    }

    private static function dispatch(\WP_Post $post, string $article_id): void
    {
        // Whatever happens next, this request has reported the post: the value read at sending
        // is the new base for a later change.
        SocialQueueFlag::reported($post->ID);

        // Bulk publishing (Bulk edit -> Status: Published) goes through this hook ONCE PER POST
        // in a single HTTP request. Fifteen drafts means fifteen consecutive calls of 5 s each, i.e.
        // 75 s with HF unavailable and the request dying on max_execution_time halfway through the batch.
        // That is why on bulk publishing the send is deferred to cron — by the same
        // mechanism that handles retries. The editor does not wait, the addresses arrive within a minute.
        // 2.7.1: on its own hook, not the retry's: the deferred send is a first attempt, and a
        // failed one gets its retry like any other (before, a failure here was never retried).
        if (isset($_REQUEST['bulk_edit'])) {
            wp_schedule_single_event(time() + self::BULK_DELAY, self::BULK_HOOK, [$post->ID]);

            return;
        }

        self::first_attempt($post, $article_id);
    }

    /** `BULK_HOOK`: the deferred send of a post published in bulk. @param int|string $post_id */
    public static function send_deferred($post_id): void
    {
        $post = get_post((int) $post_id);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return;
        }
        $article_id = PostWriter::article_id_of($post);
        if ($article_id !== null) {
            self::first_attempt($post, $article_id);
        }
    }

    /**
     * Waits before retry N (N = 1..count): from 5 min growing to 15 h, more than a day in all
     * (2.8.0, guide "complete integration": retry with a growing interval for at least a day,
     * bulk publication included). A `429` waits at least its `retry_after`. Before 2.8.0 one retry.
     */
    public const RETRY_DELAYS = [
        5 * MINUTE_IN_SECONDS,
        15 * MINUTE_IN_SECONDS,
        HOUR_IN_SECONDS,
        3 * HOUR_IN_SECONDS,
        6 * HOUR_IN_SECONDS,
        15 * HOUR_IN_SECONDS,
    ];

    private static function first_attempt(\WP_Post $post, string $article_id): void
    {
        self::attempt($post, $article_id, 0);
    }

    /**
     * Sends the post; a send that may be retried and still has retries left schedules the next
     * one. $attempt: 0 = the first send, N = retry N.
     */
    private static function attempt(\WP_Post $post, string $article_id, int $attempt): void
    {
        $result = self::notify($post, $article_id, $attempt);
        $delay  = self::next_delay($result, $attempt);
        if ($delay !== null) {
            // Core deduplicates an identical event (hook + arguments) within a 10-minute window.
            wp_schedule_single_event(time() + $delay, self::RETRY_HOOK, [$post->ID, $attempt + 1]);
        }
    }

    /**
     * Seconds until the next retry, or null when there is none (not retryable, or all used).
     *
     * @param array<string, mixed> $result
     */
    private static function next_delay(array $result, int $attempt): ?int
    {
        if (empty($result['retryable']) || $attempt >= count(self::RETRY_DELAYS)) {
            return null;
        }
        $asked = $result['retry_after'] ?? null;

        return max(self::RETRY_DELAYS[$attempt], is_int($asked) ? $asked : 0);
    }

    /**
     * `RETRY_HOOK`. Events scheduled before 2.8.0 carry only the post id: retry 1.
     *
     * @param int|string $post_id
     * @param int|string $attempt
     */
    public static function retry($post_id, $attempt = 1): void
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
        self::attempt($post, $article_id, max(1, (int) $attempt));
    }

    /** @return array<string, mixed> result of PublishedUrlsClient::send() */
    private static function notify(\WP_Post $post, string $article_id, int $attempt): array
    {
        $url    = (string) get_permalink($post);
        $item = ['article_id' => $article_id, 'url' => $url];
        $published_at = PublishedUrlsClient::published_at($post);
        if ($published_at !== null) {
            $item['published_at'] = $published_at;
        }
        // Read now, not when the send was planned: a cron retry carries the current value.
        $item   = SocialQueueFlag::add_to($item, $post->ID);
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
                $detail = __('Tribune Desk did not return a status for this item', 'tribune-desk');
            }
        }

        if ($attempt > 0) {
            $retry_label = __('retry', 'tribune-desk');
            $detail = trim($retry_label . ': ' . (string) $detail, ': ');
            $detail = str_starts_with($detail, $retry_label) ? $detail : $retry_label . ': ' . $detail;
        }
        $delay = self::next_delay($result, $attempt);
        if ($delay !== null) {
            $detail = trim((string) $detail . ' — ' . self::describe_delay($delay));
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

    private static function describe_delay(int $delay): string
    {
        if ($delay === self::RETRY_DELAY) {
            return __('retry in 5 min', 'tribune-desk');
        }
        if ($delay % MINUTE_IN_SECONDS === 0) {
            /* translators: %d: minutes until the retry */
            return sprintf(__('retry in %d min', 'tribune-desk'), intdiv($delay, MINUTE_IN_SECONDS));
        }

        /* translators: %d: seconds until the retry */
        return sprintf(__('retry in %d s', 'tribune-desk'), $delay);
    }
}
