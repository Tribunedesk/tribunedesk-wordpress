<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backfill import: a cron every 5 minutes reads orders from /backfill/requests and hands over candidates in pages,
 * newest first. Only a superadmin in Tribune Desk orders it, so there is nothing here on the publisher screen.
 * The position advances only after the server's answer, and the server accepts a repeated page without
 * duplicates, so an interruption midway loses nothing. No sha: computing it would require reading files.
 */
class CmsBackfill
{
    public const HOOK = 'headlinesforge_cms_backfill';

    public const OPTION_LOCK            = 'headlinesforge_cms_backfill_lock';
    public const OPTION_ERROR           = 'headlinesforge_cms_backfill_error';
    public const OPTION_POSITION_PREFIX = 'headlinesforge_cms_backfill_pos_';

    /** Counter of consecutive rejected pages (422) and task suspension: both keyed by task id. */
    public const REJECT_PREFIX = 'headlinesforge_cms_backfill_rej_';
    public const HOLD_PREFIX   = 'headlinesforge_cms_backfill_hold_';
    public const MAX_REJECTS   = 5;
    /** A position without movement for this long is abandoned. */
    public const POSITION_TTL = 30 * DAY_IN_SECONDS;
    /** Longest text value of a candidate (server limit). */
    public const MAX_TEXT = 5000;

    public const PAGE_SIZE = 500;
    public const MAX_PAGES = 3;
    /** The lock lasts 5 minutes, so a run does not start a new row after that time. */
    public const BUDGET = 120;

    public static function ensure_scheduled(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, CmsSync::SCHEDULE, self::HOOK);
        }
    }

    public static function run(): void
    {
        if (
            get_transient(CmsSync::TRANSIENT_PAUSE) || !CmsSync::is_active()
            || !CmsSync::acquire_lock(self::OPTION_LOCK)
        ) {
            return;
        }
        try {
            $error = self::export_requests();
        } catch (\Throwable $e) {
            // Fixed text: an exception's text may carry data from the library.
            $error = __('An unexpected plugin error interrupted the import candidates export.', 'tribune-desk');
        } finally {
            CmsSync::release_lock(self::OPTION_LOCK);
        }
        if ($error === '') {
            delete_option(self::OPTION_ERROR);
        } else {
            update_option(self::OPTION_ERROR, $error, false);
        }
    }

    /** @return string error or empty */
    private static function export_requests(): string
    {
        $list = CmsApiClient::get('/backfill/requests');
        if (is_wp_error($list)) {
            return self::failure_text($list);
        }
        if (!is_array($list['items'] ?? null)) {
            return CmsSync::invalid_response();
        }

        $requests = [];
        foreach ($list['items'] as $request) {
            $id = is_array($request) ? ($request['id'] ?? null) : null;
            if (is_string($id) && preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1) {
                $requests[$id] = is_array($request['filters'] ?? null) ? $request['filters'] : [];
            }
        }
        self::reconcile_positions(array_keys($requests));

        $deadline = microtime(true) + (int) Hooks::filter('cms_backfill_budget', self::BUDGET);
        $pages    = 0;
        $error    = '';
        foreach ($requests as $id => $raw) {
            $filters = self::filters($raw);
            if ($filters === null) {
                $error = CmsSync::invalid_response();
                continue;
            }
            [$failure, $stop] = self::export_request($id, $filters, $deadline, $pages);
            $error = $failure !== '' ? $failure : $error;
            if ($stop) {
                break;
            }
        }

        return $error;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: bool} error or empty; whether to abort the whole run
     */
    private static function export_request(string $id, array $filters, float $deadline, int &$pages): array
    {
        if (get_transient(self::HOLD_PREFIX . $id) !== false) {
            return [self::held_message(), false];
        }
        $position = self::position($id);
        while (true) {
            if ($pages >= self::MAX_PAGES || ($pages > 0 && microtime(true) >= $deadline)) {
                return ['', true];
            }
            $page = self::collect($filters, $position, $deadline);
            if ($page['items'] === [] && !$page['last']) {
                // Only ones rejected by filters through the end of the budget: no page, but progress stays.
                self::store_position($id, $page['position']);

                return ['', true];
            }

            $pages++;
            $response = CmsApiClient::post(
                '/backfill/requests/' . rawurlencode($id) . '/pages',
                ['items' => $page['items'], 'is_last' => $page['last']],
                CmsSync::WRITE_TIMEOUT
            );
            if (is_wp_error($response)) {
                return self::page_failure($id, $response);
            }
            if (!is_array($response['results'] ?? null)) {
                return [CmsSync::invalid_response(), true];
            }
            delete_transient(self::REJECT_PREFIX . $id);
            if ($page['last']) {
                delete_option(self::OPTION_POSITION_PREFIX . $id);

                return ['', false];
            }
            $position = $page['position'];
            self::store_position($id, $position);
        }
    }

    /**
     * Task closed or unknown: nothing to return to. A page refusal (422) is kept with the task
     * and after MAX_REJECTS tries in a row suspends the task's export for an hour; the position stays.
     * Other errors pass on their own, so the position stays too.
     *
     * @return array{0: string, 1: bool}
     */
    private static function page_failure(string $id, \WP_Error $error): array
    {
        $data   = $error->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        if ($status === 404 || $status === 409) {
            CmsSync::failure($error);
            delete_option(self::OPTION_POSITION_PREFIX . $id);

            return ['', false];
        }
        if ($status === 422) {
            $count = (int) get_transient(self::REJECT_PREFIX . $id) + 1;
            if ($count >= self::MAX_REJECTS) {
                delete_transient(self::REJECT_PREFIX . $id);
                set_transient(self::HOLD_PREFIX . $id, 1, HOUR_IN_SECONDS);

                return [self::held_message(), false];
            }
            set_transient(self::REJECT_PREFIX . $id, $count, DAY_IN_SECONDS);

            return [__('Tribune Desk rejected the import candidates page, so the plugin will retry it on the next run.', 'tribune-desk'), false];
        }

        return [self::failure_text($error), true];
    }

    private static function held_message(): string
    {
        return __('Tribune Desk rejected the import candidates page 5 times in a row, so the plugin paused this task\'s export for an hour. Check the task in Tribune Desk and the plugin version.', 'tribune-desk');
    }

    /** @return string trusted text: fixed messages, no response content */
    private static function failure_text(\WP_Error $error): string
    {
        $text = CmsSync::failure($error);
        if ($error->get_error_code() === 'forbidden') {
            return __('The CMS API token lacks the backfill:write permission, so the plugin cannot hand over backfill import candidates. The rest of the exchange works unchanged.', 'tribune-desk');
        }

        return $text;
    }

    /**
     * Scans in chunks, so that a filter rejecting almost everything does not block the DB with one query
     * and so that the time budget can be checked between rows. The first row always passes,
     * otherwise a run with no time left would make no progress.
     *
     * @param array<string, mixed>                  $filters
     * @param array{date: string, id: int}|null     $position
     * @return array{items: list<array<string, mixed>>, position: array{date: string, id: int}|null, last: bool}
     */
    private static function collect(array $filters, ?array $position, float $deadline): array
    {
        $items = [];
        $moved = false;
        while (true) {
            $rows = self::scan($filters, $position);
            $keep = self::matching($rows, $filters);
            self::prime($rows, $keep);
            foreach ($rows as $row) {
                if (count($items) >= self::PAGE_SIZE || ($moved && microtime(true) >= $deadline)) {
                    return ['items' => $items, 'position' => $position, 'last' => false];
                }
                $position = ['date' => (string) $row->post_date, 'id' => (int) $row->ID];
                $moved    = true;
                if (isset($keep[(int) $row->ID])) {
                    $item = self::candidate((int) $row->ID);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
            }
            if (count($rows) < self::PAGE_SIZE) {
                return ['items' => $items, 'position' => $position, 'last' => true];
            }
            if (count($items) >= self::PAGE_SIZE) {
                return ['items' => $items, 'position' => $position, 'last' => false];
            }
        }
    }

    /**
     * Order and position by (post_date, ID): WordPress's type_status_date index. Files from Tribune
     * Desk are not candidates.
     *
     * @param array<string, mixed>              $filters
     * @param array{date: string, id: int}|null $position
     * @return list<object>
     */
    private static function scan(array $filters, ?array $position): array
    {
        global $wpdb;
        $sql = "SELECT p.ID, p.post_date, p.post_parent FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
              AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} x WHERE x.post_id = p.ID AND x.meta_key IN (%s, %s))";
        $args = [MediaHandler::META_FROM_TD, MediaHandler::META_ASSET_ID];

        if ($filters['mimes'] !== []) {
            $sql .= ' AND p.post_mime_type IN (' . implode(',', array_fill(0, count($filters['mimes']), '%s')) . ')';
            $args = array_merge($args, $filters['mimes']);
        } else {
            $sql   .= ' AND p.post_mime_type LIKE %s';
            $args[] = $wpdb->esc_like('image/') . '%';
        }
        if ($filters['from'] !== null) {
            $sql   .= ' AND p.post_date >= %s';
            $args[] = $filters['from'];
        }
        if ($filters['to'] !== null) {
            $sql   .= ' AND p.post_date <= %s';
            $args[] = $filters['to'];
        }
        if ($position !== null) {
            $sql .= ' AND (p.post_date < %s OR (p.post_date = %s AND p.ID < %d))';
            array_push($args, $position['date'], $position['date'], $position['id']);
        }
        $sql   .= ' ORDER BY p.post_date DESC, p.ID DESC LIMIT %d';
        $args[] = self::PAGE_SIZE;

        return array_values((array) $wpdb->get_results($wpdb->prepare($sql, ...$args)));
    }

    /**
     * Filters requiring other tables, one query per chunk.
     *
     * @param list<object>         $rows
     * @param array<string, mixed> $filters
     * @return array<int, true> attachments passing the filters
     */
    private static function matching(array $rows, array $filters): array
    {
        $keep = [];
        foreach ($rows as $row) {
            $keep[(int) $row->ID] = true;
        }
        if ($keep === []) {
            return [];
        }

        if ($filters['min_width'] > 0) {
            $wide = self::wide_enough(array_keys($keep), $filters['min_width']);
            $keep = array_intersect_key($keep, $wide);
        }
        $parents = self::parents($rows);
        if ($filters['published']) {
            $allowed = self::published_parents($parents);
            $keep    = array_filter($keep, static fn ($_, $id) => isset($allowed[$parents[$id] ?? 0]), ARRAY_FILTER_USE_BOTH);
        }
        if ($filters['categories'] !== []) {
            $allowed = self::parents_in_categories($parents, $filters['categories']);
            $keep    = array_filter($keep, static fn ($_, $id) => isset($allowed[$parents[$id] ?? 0]), ARRAY_FILTER_USE_BOTH);
        }

        return $keep;
    }

    /**
     * @param list<object> $rows
     * @return array<int, int> attachment => parent post
     */
    private static function parents(array $rows): array
    {
        $parents = [];
        foreach ($rows as $row) {
            if ((int) $row->post_parent > 0) {
                $parents[(int) $row->ID] = (int) $row->post_parent;
            }
        }

        return $parents;
    }

    /**
     * @param int[] $ids
     * @return array<int, true> an attachment without metadata does not pass: width unknown
     */
    private static function wide_enough(array $ids, int $min_width): array
    {
        global $wpdb;
        $list = implode(',', array_map('intval', $ids));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($list)",
            '_wp_attachment_metadata'
        ));
        $wide = [];
        foreach ((array) $rows as $row) {
            $meta = maybe_unserialize($row->meta_value);
            if (is_array($meta) && (int) ($meta['width'] ?? 0) >= $min_width) {
                $wide[(int) $row->post_id] = true;
            }
        }

        return $wide;
    }

    /**
     * @param array<int, int> $parents
     * @return array<int, true>
     */
    private static function published_parents(array $parents): array
    {
        if ($parents === []) {
            return [];
        }
        global $wpdb;
        $list = implode(',', array_map('intval', array_unique($parents)));

        return array_fill_keys(
            array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID IN ($list)")),
            true
        );
    }

    /**
     * The order may give a category name or slug.
     *
     * @param array<int, int> $parents
     * @param list<string>    $categories
     * @return array<int, true>
     */
    private static function parents_in_categories(array $parents, array $categories): array
    {
        if ($parents === []) {
            return [];
        }
        global $wpdb;
        $list   = implode(',', array_map('intval', array_unique($parents)));
        $places = implode(',', array_fill(0, count($categories), '%s'));
        $ids    = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                WHERE tt.taxonomy = 'category' AND tr.object_id IN ($list)
                  AND (t.slug IN ($places) OR t.name IN ($places))",
            ...array_merge($categories, $categories)
        ));

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * MediaPush::cms_item reads the post, meta and parent post: without this, 500 rows are thousands of queries.
     * Only what passed the filters, so that browsing a large library does not bloat memory.
     *
     * @param list<object>     $rows
     * @param array<int, true> $keep
     */
    private static function prime(array $rows, array $keep): void
    {
        if ($keep === []) {
            return;
        }
        _prime_post_caches(array_keys($keep), false, true);
        $parents = array_unique(array_values(array_intersect_key(self::parents($rows), $keep)));
        if ($parents !== []) {
            // Post terms: the permalink may contain categories.
            _prime_post_caches($parents, true, false);
        }
    }

    /**
     * The contract has additionalProperties: false, so only BackfillCandidate fields. The server
     * checks the host itself; a wrong schema would give 422 on the whole page.
     *
     * @return array<string, mixed>|null
     */
    private static function candidate(int $id): ?array
    {
        $item   = MediaPush::cms_item($id);
        $scheme = strtolower((string) wp_parse_url($item['file_url'], PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || (string) wp_parse_url($item['file_url'], PHP_URL_HOST) === '') {
            return null;
        }

        $out = array_intersect_key($item, array_flip(['cms_media_id', 'file_url', 'mime_type', 'width', 'height', 'created_at']));

        // Contexts for the server: empty ones are dropped, long ones are trimmed to the limit.
        $raw    = MediaPush::item($id);
        $post   = get_post($id);
        $parent = $post && $post->post_parent ? get_post((int) $post->post_parent) : null;
        $extra  = [
            'credit'       => $raw['iptc_credit'],
            'caption'      => $raw['caption'],
            'post_title'   => $raw['title'],
            'iptc_caption' => $raw['iptc_caption'],
            'parent_title' => $parent ? html_entity_decode(get_the_title($parent), ENT_QUOTES, 'UTF-8') : '',
        ];
        foreach ($extra as $key => $value) {
            $value = mb_substr(trim(str_replace("\0", '', (string) $value)), 0, self::MAX_TEXT);
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Dates from the server are in UTC, while post_date is in site time: only that column has an index.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null null: bad date, and without it the task would cover too much
     */
    private static function filters(array $raw): ?array
    {
        $from = self::site_date($raw['date_from'] ?? null);
        $to   = self::site_date($raw['date_to'] ?? null);
        if ($from === false || $to === false) {
            return null;
        }

        return [
            'from'       => $from,
            'to'         => $to,
            'min_width'  => is_int($raw['min_width'] ?? null) ? $raw['min_width'] : 0,
            'mimes'      => self::strings($raw['mime_types'] ?? null),
            'published'  => ($raw['only_attached_to_published'] ?? false) === true,
            'categories' => self::strings($raw['categories'] ?? null),
        ];
    }

    /**
     * @param mixed $value
     * @return string|null|false null: no filter; false: unreadable
     */
    private static function site_date($value)
    {
        if ($value === null) {
            return null;
        }
        $time = is_string($value) ? strtotime($value) : false;

        return $time === false ? false : get_date_from_gmt(gmdate('Y-m-d H:i:s', $time));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function strings($value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn ($v) => is_string($v) && $v !== '')) : [];
    }

    /** @return array{date: string, id: int}|null */
    private static function position(string $id): ?array
    {
        $position = get_option(self::OPTION_POSITION_PREFIX . $id, null);
        if (!is_array($position) || !is_string($position['date'] ?? null) || !is_int($position['id'] ?? null)) {
            return null;
        }

        return ['date' => $position['date'], 'id' => $position['id']];
    }

    /** @param array{date: string, id: int}|null $position */
    private static function store_position(string $id, ?array $position): void
    {
        if ($position !== null) {
            update_option(self::OPTION_POSITION_PREFIX . $id, $position + ['at' => time()], false);
        }
    }

    /**
     * A position older than POSITION_TTL disappears. A task missing from the list may only be suspended, so
     * its position disappears only when the server answers a probe empty page with 404 or 409.
     *
     * @param list<string> $open
     */
    private static function reconcile_positions(array $open): void
    {
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(self::OPTION_POSITION_PREFIX) . '%'
        ));
        foreach ($names as $name) {
            $id     = substr($name, strlen(self::OPTION_POSITION_PREFIX));
            $stored = get_option($name);
            $stamp  = is_array($stored) && is_int($stored['at'] ?? null) ? $stored['at'] : null;
            if ($stamp !== null && time() - $stamp > self::POSITION_TTL) {
                delete_option($name);
                continue;
            }
            if (in_array($id, $open, true) || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
                continue;
            }
            if (get_transient(CmsSync::TRANSIENT_PAUSE)) {
                break;
            }
            $probe = CmsApiClient::post(
                '/backfill/requests/' . rawurlencode($id) . '/pages',
                ['items' => [], 'is_last' => false],
                CmsSync::WRITE_TIMEOUT
            );
            if (!is_wp_error($probe)) {
                continue;
            }
            $data   = $probe->get_error_data();
            $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
            if ($status === 404 || $status === 409) {
                delete_option($name);
            } else {
                CmsSync::failure($probe);
            }
        }
    }
}
