<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Categories and tags sent to Tribune Desk (`POST /v3/cms/taxonomy/terms`, guide 3 "Categories and
 * tags"), so the editor can pick from them and the writer can choose among them.
 *
 * Two paths. A full run once a day: pages of up to 1000 terms in order of term_id, one `run_id` on
 * every page and `is_last` on the last one, after which Tribune Desk removes the terms the run did
 * not send. The position advances only after the server accepts a page, so an interrupted run
 * continues where it stopped. Single changes from the term hooks: a queue sent by CmsSync::run_send,
 * because the admin cannot wait for HTTP; a row leaves only after the server answers for it.
 */
class CmsTaxonomy
{
    public const HOOK_FULL    = 'headlinesforge_cms_taxonomy_full';
    public const OPTION_RUN   = 'headlinesforge_cms_taxonomy_run';
    public const OPTION_QUEUE = 'headlinesforge_cms_taxonomy_queue';
    public const OPTION_LOCK  = 'headlinesforge_cms_taxonomy_lock';
    public const PAGE         = 1000;
    public const MAX_QUEUE    = 5000;

    /** Seconds of one cron run; the lock lasts 5 minutes. */
    public const BUDGET = 120;
    /** A finished run younger than this is fresh; the daily cron gets an hour of slack. */
    public const FRESH = 23 * HOUR_IN_SECONDS;

    private const ROUTE      = '/taxonomy/terms';
    private const TAXONOMIES = ['category' => 'category', 'post_tag' => 'tag'];
    private const LOCK_WAIT  = 5;

    private const DONE   = 'done';
    private const MORE   = 'more';
    private const FAILED = 'failed';

    /**
     * Daily full run. Before the first one (no OPTION_RUN), it runs now: also when the daily event
     * was planned before the token existed.
     */
    public static function ensure_scheduled(): void
    {
        $first = !is_array(get_option(self::OPTION_RUN));
        $next  = wp_next_scheduled(self::HOOK_FULL);
        if ($next === false) {
            wp_schedule_event(time() + ($first ? 0 : DAY_IN_SECONDS), 'daily', self::HOOK_FULL);
        } elseif ($first && CmsApiClient::has_token() && $next > time() + MINUTE_IN_SECONDS) {
            wp_schedule_single_event(time(), self::HOOK_FULL);
        }
    }

    // --- full run ---------------------------------------------------------------------------

    /** Cron: continues the open run or starts one when the last finished more than FRESH ago. */
    public static function run_full(): void
    {
        if (!CmsApiClient::has_token() || get_transient(CmsSync::TRANSIENT_PAUSE)) {
            return;
        }
        if (!CmsSync::acquire_lock(self::OPTION_LOCK)) {
            return;
        }
        $outcome = self::DONE;
        try {
            $run = self::current_run();
            if ($run !== null) {
                $deadline  = microtime(true) + (int) Hooks::filter('cms_taxonomy_budget', self::BUDGET);
                [$outcome] = self::send_run($run, $deadline);
            }
        } catch (\Throwable $e) {
            $outcome = self::FAILED;
        } finally {
            CmsSync::release_lock(self::OPTION_LOCK);
        }
        if ($outcome === self::MORE) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK_FULL);
        }
    }

    /**
     * One whole full run now, with a new `run_id`, no time budget and no freshness rule
     * (`wp td taxonomy-sync` for tdkit). Abandons the run cron left open.
     *
     * @return string|null error or empty; null when another run holds the lock
     */
    public static function sync_now(): ?string
    {
        if (!CmsApiClient::has_token()) {
            return CmsApiClient::message(CmsApiClient::ERROR_NO_TOKEN);
        }
        if (!CmsSync::acquire_lock(self::OPTION_LOCK)) {
            return null;
        }
        try {
            [$outcome, $error] = self::send_run(self::new_run(), null);
        } finally {
            CmsSync::release_lock(self::OPTION_LOCK);
        }

        return $outcome === self::DONE ? '' : ($error !== '' ? $error : CmsSync::invalid_response());
    }

    /**
     * The run to continue, a new one, or null when the last one is fresh. An open run older than
     * FRESH starts over: Tribune Desk refuses the last page of a run open for 24 hours.
     *
     * @return array{run_id: string, last_term_id: int, started_at: int, finished_at: int}|null
     */
    private static function current_run(): ?array
    {
        $run = self::stored_run();
        if ($run !== null && $run['finished_at'] > 0 && time() - $run['finished_at'] < self::FRESH) {
            return null;
        }
        if ($run !== null && $run['finished_at'] === 0 && time() - $run['started_at'] < self::FRESH) {
            return $run;
        }

        return self::new_run();
    }

    /** @return array{run_id: string, last_term_id: int, started_at: int, finished_at: int}|null */
    private static function stored_run(): ?array
    {
        wp_cache_delete(self::OPTION_RUN, 'options');
        $run = get_option(self::OPTION_RUN);
        if (!is_array($run) || !is_string($run['run_id'] ?? null) || $run['run_id'] === '') {
            return null;
        }

        return [
            'run_id'       => $run['run_id'],
            'last_term_id' => (int) ($run['last_term_id'] ?? 0),
            'started_at'   => (int) ($run['started_at'] ?? 0),
            'finished_at'  => (int) ($run['finished_at'] ?? 0),
        ];
    }

    /** @return array{run_id: string, last_term_id: int, started_at: int, finished_at: int} */
    private static function new_run(): array
    {
        $run = ['run_id' => wp_generate_uuid4(), 'last_term_id' => 0, 'started_at' => time(), 'finished_at' => 0];
        update_option(self::OPTION_RUN, $run, false);

        return $run;
    }

    /**
     * Pages from the stored position. The first page always goes, so a run with no time left
     * still moves.
     *
     * @param array{run_id: string, last_term_id: int, started_at: int, finished_at: int} $run
     * @return array{0: string, 1: string} outcome; error or empty
     */
    private static function send_run(array $run, ?float $deadline): array
    {
        $pages = 0;
        while (true) {
            if ($deadline !== null && $pages > 0 && microtime(true) >= $deadline) {
                return [self::MORE, ''];
            }
            $rows    = self::next_terms($run['last_term_id']);
            $is_last = count($rows) <= self::PAGE;
            $rows    = array_slice($rows, 0, self::PAGE);
            $items   = array_values(array_filter(array_map(static fn (object $row): ?array => self::item($row), $rows)));

            // Only nameless terms: the server takes an empty page only as the last one.
            if ($items !== [] || $is_last) {
                $pages++;
                $response = CmsApiClient::post(
                    self::ROUTE,
                    ['run_id' => $run['run_id'], 'items' => $items, 'is_last' => $is_last],
                    CmsSync::WRITE_TIMEOUT
                );
                if (is_wp_error($response)) {
                    return [self::FAILED, self::run_failure($run, $response)];
                }
                if (!is_array($response['results'] ?? null)) {
                    return [self::FAILED, CmsSync::invalid_response()];
                }
            }

            if ($rows !== []) {
                $run['last_term_id'] = (int) end($rows)->term_id;
            }
            if ($is_last) {
                $run['finished_at'] = time();
                update_option(self::OPTION_RUN, $run, false);

                return [self::DONE, ''];
            }
            update_option(self::OPTION_RUN, $run, false);
        }
    }

    /**
     * 409: the run was open too long (or the organization is frozen). The next run starts from
     * zero; the stored run stays, so the first-run rule does not plan it again at once.
     *
     * @param array{run_id: string, last_term_id: int, started_at: int, finished_at: int} $run
     */
    private static function run_failure(array $run, \WP_Error $error): string
    {
        $data = $error->get_error_data();
        if (is_array($data) && (int) ($data['status'] ?? 0) === 409) {
            update_option(self::OPTION_RUN, array_merge($run, ['last_term_id' => 0, 'started_at' => 0]), false);
        }

        return CmsSync::failure($error);
    }

    /**
     * Up to PAGE + 1 terms after `$after`, so the page knows whether it is the last.
     *
     * @return list<object>
     */
    private static function next_terms(int $after): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent, tt.count
               FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
              WHERE tt.taxonomy IN ('category', 'post_tag') AND t.term_id > %d
              ORDER BY t.term_id LIMIT %d",
            $after,
            self::PAGE + 1
        ));

        return array_values(is_array($rows) ? $rows : []);
    }

    /**
     * A row of the page query or a WP_Term. WordPress keeps names HTML-escaped; the contract wants
     * plain text, and an empty name fails the whole page.
     *
     * @return array<string, mixed>|null
     */
    private static function item(object $term): ?array
    {
        $name = html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8');
        if (trim($name) === '' || !isset(self::TAXONOMIES[(string) $term->taxonomy])) {
            return null;
        }
        $item = [
            'taxonomy'    => self::TAXONOMIES[(string) $term->taxonomy],
            'cms_term_id' => (string) $term->term_id,
            'name'        => $name,
            'slug'        => (string) $term->slug,
            'post_count'  => max(0, (int) $term->count),
        ];
        if ($term->taxonomy === 'category') {
            $item['parent_id'] = (int) $term->parent > 0 ? (string) $term->parent : null;
        }

        return $item;
    }

    // --- single changes ---------------------------------------------------------------------

    /** `created_term` and `edited_term`: only a local save and scheduling the send. */
    public static function on_term_change(int $term_id, int $tt_id, string $taxonomy): void
    {
        self::enqueue($taxonomy, $term_id, ['deleted' => false]);
    }

    /**
     * `delete_term`: the term is gone by the time the queue is sent, so its name goes into the queue.
     *
     * @param mixed $deleted_term
     */
    public static function on_term_delete(int $term_id, int $tt_id, string $taxonomy, $deleted_term = null): void
    {
        $name = $deleted_term instanceof \WP_Term ? html_entity_decode($deleted_term->name, ENT_QUOTES, 'UTF-8') : '';
        self::enqueue($taxonomy, $term_id, ['deleted' => true, 'name' => trim($name) !== '' ? $name : (string) $term_id]);
    }

    /** @param array{deleted: bool, name?: string} $row */
    private static function enqueue(string $taxonomy, int $term_id, array $row): void
    {
        if (!isset(self::TAXONOMIES[$taxonomy]) || !CmsApiClient::has_token()) {
            return;
        }
        $key = $taxonomy . ':' . $term_id;
        self::locked(static function () use ($key, $row): void {
            $queue = self::queue();
            // A full queue drops the change: the next full run carries it anyway.
            if (!isset($queue[$key]) && count($queue) >= self::MAX_QUEUE) {
                return;
            }
            $queue[$key] = $row;
            update_option(self::OPTION_QUEUE, $queue, false);
        });
        CmsSync::schedule_send();
    }

    /**
     * Oldest first: "taxonomy:term_id" => row.
     *
     * @return array<string, array{deleted: bool, name?: string}>
     */
    public static function queue(): array
    {
        wp_cache_delete(self::OPTION_QUEUE, 'options');
        wp_cache_delete('notoptions', 'options');
        $queue = get_option(self::OPTION_QUEUE, []);

        return is_array($queue) ? $queue : [];
    }

    /** Term hooks may fire in parallel requests; reading and writing an option is not atomic. */
    private static function locked(callable $change): void
    {
        global $wpdb;
        $name = 'hf_cms_taxonomy_' . md5($wpdb->options);
        $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT));
        try {
            $change();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    /**
     * Called in the send run, under the exchange lock: pages without `run_id`. 501 (a backend
     * without the route) and 403 (a token without articles:write) are not exchange errors; the
     * queue waits and the full run carries the terms anyway.
     *
     * @return string error or empty
     */
    public static function send_queue(): string
    {
        for ($i = 0; $i < CmsSync::MAX_PAGES; $i++) {
            $rows = array_slice(self::queue(), 0, self::PAGE, true);
            if ($rows === []) {
                return '';
            }
            [$items, $keys, $gone] = self::queue_items($rows);
            if ($gone !== []) {
                self::forget($gone);
            }
            if ($items === []) {
                continue;
            }

            $response = CmsApiClient::post(self::ROUTE, ['items' => $items], CmsSync::WRITE_TIMEOUT);
            if (is_wp_error($response)) {
                $data   = $response->get_error_data();
                $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;

                return in_array($status, [403, 501], true) ? '' : CmsSync::send_failure($response);
            }
            if (!is_array($response['results'] ?? null)) {
                return CmsSync::invalid_response();
            }
            $done = [];
            foreach ($response['results'] as $result) {
                $id = is_array($result) ? (string) ($result['id'] ?? '') : '';
                if (isset($keys[$id]) && is_string($result['status'] ?? null)) {
                    $done[] = $keys[$id];
                }
            }
            if ($done === []) {
                return CmsSync::invalid_response();
            }
            self::forget($done);
        }
        if (self::queue() !== []) {
            CmsSync::schedule_send();
        }

        return '';
    }

    /**
     * A changed term is read now, so the newest name goes. One that no longer exists leaves the
     * queue: its deletion row took its place.
     *
     * @param array<string, array{deleted: bool, name?: string}> $rows
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>, 2: list<string>} items; cms_term_id => key; keys to drop
     */
    private static function queue_items(array $rows): array
    {
        $items = [];
        $keys  = [];
        $gone  = [];
        foreach ($rows as $key => $row) {
            [$taxonomy, $id] = array_pad(explode(':', (string) $key, 2), 2, '');
            if (!isset(self::TAXONOMIES[$taxonomy]) || !ctype_digit($id)) {
                $gone[] = (string) $key;
                continue;
            }
            if (!empty($row['deleted'])) {
                $item = [
                    'taxonomy'    => self::TAXONOMIES[$taxonomy],
                    'cms_term_id' => $id,
                    'name'        => (string) ($row['name'] ?? $id),
                    'deleted'     => true,
                ];
            } else {
                $term = get_term((int) $id, $taxonomy);
                $item = $term instanceof \WP_Term ? self::item($term) : null;
            }
            if ($item === null) {
                $gone[] = (string) $key;
                continue;
            }
            $items[]   = $item;
            $keys[$id] = (string) $key;
        }

        return [$items, $keys, $gone];
    }

    /** @param list<string> $keys */
    private static function forget(array $keys): void
    {
        self::locked(static function () use ($keys): void {
            $left = array_diff_key(self::queue(), array_flip($keys));
            if ($left === []) {
                delete_option(self::OPTION_QUEUE);
            } else {
                update_option(self::OPTION_QUEUE, $left, false);
            }
        });
    }
}
