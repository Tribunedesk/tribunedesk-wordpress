<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\ChangesFeed;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exchange with Tribune Desk via /v3/cms (stage 4): change feed every 5 minutes, field and new-file push
 * a minute after a change, hourly cron as a safety net. Everything runs in cron: saving in the
 * admin only schedules an event. The cursor advances only after a whole page is applied, and the
 * queue clears only after the server replies, so a failure loses nothing.
 * Field values and the token never reach the logs or the stored error.
 */
class CmsSync
{
    public const HOOK_POLL = 'headlinesforge_cms_poll';
    public const HOOK_SEND = 'headlinesforge_cms_send';
    public const SCHEDULE  = 'headlinesforge_five_minutes';

    public const OPTION_CURSOR       = 'headlinesforge_cms_cursor';
    public const OPTION_NEEDS_RESYNC = 'headlinesforge_cms_needs_resync';
    public const OPTION_LAST_RUN     = 'headlinesforge_cms_last_run';
    public const OPTION_LAST_MODE    = 'headlinesforge_cms_last_mode';
    public const OPTION_LOCK         = 'headlinesforge_cms_lock';
    public const OPTION_ATTEMPTS     = 'headlinesforge_cms_row_attempts';
    public const OPTION_SKIPPED      = 'headlinesforge_cms_skipped';
    public const OPTION_DECIDED      = 'headlinesforge_cms_held_decided';

    public const TRANSIENT_ACTIVE   = 'headlinesforge_cms_active';
    public const TRANSIENT_SETTINGS = 'headlinesforge_cms_keep_nonempty';
    public const TRANSIENT_PAUSE    = 'headlinesforge_cms_pause';
    /** Last file from TD that was left without a pair. A run without an error must not drop it from the screen. */
    public const TRANSIENT_FILE_PROBLEM = 'headlinesforge_cms_file_problem';

    public const META_VERSIONS   = '_hf_field_versions';
    /** TD change skipped because the field was waiting to be sent: field => value, version, batch. */
    public const META_PENDING_TD = '_hf_pending_td';

    public const BATCH     = 100;
    public const MAX_PAGES = 3;
    /** Saves from cron; the screen keeps the short client timeout so the admin does not slow down. */
    public const WRITE_TIMEOUT = 15;
    /** The lock lasts 5 minutes and up to 100 may be fetched: after that time the run ends after the current row. */
    public const POLL_BUDGET = 120;

    private const CACHE_TTL     = 5 * MINUTE_IN_SECONDS;
    private const LOCK_TTL      = 5 * MINUTE_IN_SECONDS;

    /** @var array<string, string> option name => value of the lock this request holds */
    private static array $held_locks = [];
    private const SEND_DELAY    = MINUTE_IN_SECONDS;
    private const RETRY_DELAY   = 5 * MINUTE_IN_SECONDS;
    private const MAX_ATTEMPTS  = 5;
    private const MAX_SKIPPED   = 50;
    private const CRON_OVERDUE  = 30 * MINUTE_IN_SECONDS;
    private const DEFAULT_PAUSE = MINUTE_IN_SECONDS;
    private const MAX_FIELD     = 5000;
    private const FIELDS        = ['title', 'alt', 'caption', 'description'];

    /** @param array<string, array<string, mixed>> $schedules @return array<string, array<string, mixed>> */
    public static function add_schedule(array $schedules): array
    {
        $schedules[self::SCHEDULE] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Every 5 minutes (Tribune Desk)', 'tribune-desk'),
        ];

        return $schedules;
    }

    public static function ensure_scheduled(): void
    {
        if (!wp_next_scheduled(self::HOOK_POLL)) {
            wp_schedule_event(time() + self::CACHE_TTL, self::SCHEDULE, self::HOOK_POLL);
        }
    }

    /** Only schedules the send, no HTTP: so it does not ask about the mode, cron checks it later. */
    public static function schedule_send(int $delay = self::SEND_DELAY): void
    {
        if (CmsApiClient::has_token() && !wp_next_scheduled(self::HOOK_SEND)) {
            wp_schedule_single_event(time() + $delay, self::HOOK_SEND);
        }
    }

    public static function on_add_attachment(): void
    {
        self::schedule_send();
    }

    /** Publishing a post makes its attachments send candidates right away, not a day later. */
    public static function on_transition(string $new, string $old, \WP_Post $post): void
    {
        if ($new !== 'publish' || $old === 'publish' || $post->post_type === 'attachment' || !CmsApiClient::has_token()) {
            return;
        }
        global $wpdb;
        $has_child = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'attachment' LIMIT 1",
            $post->ID
        ));
        if ($has_child) {
            self::schedule_send();
        }
    }

    /**
     * Atomic: INSERT IGNORE, because add_option has ON DUPLICATE KEY UPDATE and also reports
     * success when the row is taken. An expired lock goes through a conditional UPDATE, so one wins.
     */
    public static function acquire_lock(string $option = self::OPTION_LOCK): bool
    {
        global $wpdb;
        // "<expiry>:<owner>": the takeover reads the expiry before the colon, and the owner part lets
        // release_lock() remove only the lock this request took (2.2.2). A run that outlived its
        // 5 minutes no longer deletes the lock a newer run took over.
        $value = (time() + self::LOCK_TTL) . ':' . wp_generate_password(12, false);
        $taken = (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $option,
            $value
        )) === 1
            || (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d",
                $value,
                $option,
                time()
            )) === 1;
        if ($taken) {
            self::$held_locks[$option] = $value;
        }

        return $taken;
    }

    public static function release_lock(string $option = self::OPTION_LOCK): void
    {
        global $wpdb;
        $value = self::$held_locks[$option] ?? null;
        if ($value === null) {
            return;
        }
        unset(self::$held_locks[$option]);
        $wpdb->delete($wpdb->options, ['option_name' => $option, 'option_value' => $value]);
    }

    /**
     * There is a token and /status does not say "disabled". An error does not switch the mode:
     * the last known one stays, and the 5-minute cache is not refreshed, so the next run asks again.
     */
    public static function is_active(): bool
    {
        if (!CmsApiClient::has_token()) {
            return false;
        }
        $cached = get_transient(self::TRANSIENT_ACTIVE);
        if ($cached !== false) {
            return $cached === 'active';
        }

        $status = CmsApiClient::get('/status');
        $state  = is_array($status) ? ($status['state'] ?? null) : null;
        if (!is_string($state)) {
            return get_option(self::OPTION_LAST_MODE, '') === 'active';
        }

        $mode = $state === 'disabled' ? 'disabled' : 'active';
        set_transient(self::TRANSIENT_ACTIVE, $mode, self::CACHE_TTL);
        update_option(self::OPTION_LAST_MODE, $mode, false);

        return $mode === 'active';
    }

    public static function run_poll(): void
    {
        if (
            get_option(self::OPTION_NEEDS_RESYNC) || get_transient(self::TRANSIENT_PAUSE)
            || !self::is_active() || !self::acquire_lock()
        ) {
            return;
        }
        try {
            $error = self::settle_decided();
            $page  = self::poll_pages();
            $error = $page !== '' ? $page : ($error !== '' ? $error : (string) get_transient(self::TRANSIENT_FILE_PROBLEM));
        } catch (\Throwable $e) {
            $error = self::crash_message();
        } finally {
            self::release_lock();
        }
        self::record('poll', $error);
    }

    public static function run_send(): void
    {
        if (get_transient(self::TRANSIENT_PAUSE) || !self::is_active()) {
            return;
        }
        if (!self::acquire_lock()) {
            self::schedule_send();
            return;
        }
        try {
            $error = self::send_fields();
            if ($error === '' || !get_transient(self::TRANSIENT_PAUSE)) {
                $files_error = self::send_new_files();
                $error       = $error !== '' ? $error : $files_error;
            }
            if (!get_transient(self::TRANSIENT_PAUSE)) {
                $deletions_error = CmsDeletions::send();
                $error           = $error !== '' ? $error : $deletions_error;
            }
        } catch (\Throwable $e) {
            $error = self::crash_message();
        } finally {
            self::release_lock();
        }
        self::record('send', $error);
    }

    /** Fixed text: the exception text may carry field values. */
    private static function crash_message(): string
    {
        return __('An unexpected plugin error interrupted the exchange run.', 'tribune-desk');
    }

    /**
     * Empty when jobs run on time: DISABLE_WP_CRON with a working server cron breaks nothing.
     * Parameter: the DISABLE_WP_CRON state, by default read from the constant.
     */
    public static function cron_warning(?bool $disabled = null): string
    {
        $next = wp_next_scheduled(self::HOOK_POLL);
        if ($next === false || time() - $next <= self::CRON_OVERDUE) {
            return '';
        }
        if ($disabled ?? (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
            return __('Fetching changes is more than 30 minutes late, and WP-Cron is disabled (DISABLE_WP_CRON). The exchange only works when the server runs wp-cron.php itself.', 'tribune-desk');
        }

        return __('Fetching changes is more than 30 minutes late: WP-Cron is not running tasks on time.', 'tribune-desk');
    }

    /** @return int[] attachments whose TD changes could not be saved despite retries */
    public static function skipped_ids(): array
    {
        $ids = get_option(self::OPTION_SKIPPED, []);

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /** The decision about a batch is made on the screen; cron moves the rows, because the admin has no time for it. */
    public static function note_held_decision(string $batch, string $decision): void
    {
        $decided         = get_option(self::OPTION_DECIDED, []);
        $decided         = is_array($decided) ? $decided : [];
        $decided[$batch] = $decision;
        update_option(self::OPTION_DECIDED, $decided, false);
    }

    /** @return array<string, array{at: int, error: string}> keys: poll, send */
    public static function last_runs(): array
    {
        $runs = get_option(self::OPTION_LAST_RUN, []);

        return is_array($runs) ? $runs : [];
    }

    private static function record(string $kind, string $error): void
    {
        $runs        = self::last_runs();
        $runs[$kind] = ['at' => time(), 'error' => $error];
        update_option(self::OPTION_LAST_RUN, $runs, false);
    }

    /** Save failure: without a server pause, transport errors and 5xx are retried in 5 minutes, not in an hour. */
    public static function send_failure(\WP_Error $error): string
    {
        $message = self::failure($error);
        $data    = $error->get_error_data();
        $status  = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        if (($error->get_error_code() === CmsApiClient::ERROR_TRANSPORT || $status >= 500) && !get_transient(self::TRANSIENT_PAUSE)) {
            self::schedule_send(self::RETRY_DELAY);
        }

        return $message;
    }

    /** @return string reliable text: fixed client messages, no response content */
    public static function failure(\WP_Error $error): string
    {
        $code = $error->get_error_code();
        $data = $error->get_error_data();
        $wait = is_array($data) ? ($data['retry_after'] ?? null) : null;
        if (is_int($wait) && $wait > 0) {
            set_transient(self::TRANSIENT_PAUSE, 1, $wait);
        } elseif ($code === 'rate_limited') {
            set_transient(self::TRANSIENT_PAUSE, 1, self::DEFAULT_PAUSE);
        }
        if ($code === 'state_conflict') {
            // Integrations disabled: the next run must ask about the mode, not wait 5 minutes.
            delete_transient(self::TRANSIENT_ACTIVE);
        }

        return $error->get_error_message();
    }

    public static function invalid_response(): string
    {
        return __('Tribune Desk returned an unexpected response.', 'tribune-desk');
    }

    // --- feed -------------------------------------------------------------------------------

    /** @return string error or empty */
    private static function poll_pages(): string
    {
        $deadline = microtime(true) + (int) Hooks::filter('cms_poll_budget', self::POLL_BUDGET);
        $cursor   = (string) get_option(self::OPTION_CURSOR, '');
        if ($cursor === '') {
            $page = CmsApiClient::get('/changes');
            if (is_wp_error($page)) {
                return self::failure($page);
            }
            $next = $page['next_cursor'] ?? null;
            if (!is_string($next) || $next === '') {
                return self::invalid_response();
            }
            update_option(self::OPTION_CURSOR, $next, false);

            return '';
        }

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $page = CmsApiClient::get('/changes?cursor=' . rawurlencode($cursor) . '&limit=' . self::BATCH);
            if (is_wp_error($page)) {
                if ($page->get_error_code() === 'cursor_expired') {
                    update_option(self::OPTION_NEEDS_RESYNC, 1, false);
                }

                return self::failure($page);
            }
            $next = $page['next_cursor'] ?? null;
            if (!is_string($next) || $next === '' || !is_array($page['changes'] ?? null)) {
                return self::invalid_response();
            }
            foreach ($page['changes'] as $change) {
                $error = is_array($change) ? self::process_change($change) : '';
                if ($error !== '') {
                    return $error;
                }
                // A downloaded file must not be downloaded again, and `deleted` searches post content:
                // for both rows the cursor moves on right away, and the budget may end the run.
                if (is_array($change) && in_array($change['kind'] ?? '', ['new_asset', 'deleted'], true) && is_string($change['cursor'] ?? null) && $change['cursor'] !== '') {
                    $cursor = $change['cursor'];
                    update_option(self::OPTION_CURSOR, $cursor, false);
                    if (microtime(true) >= $deadline) {
                        return '';
                    }
                }
            }
            update_option(self::OPTION_CURSOR, $next, false);
            $cursor = $next;
            if (empty($page['has_more'])) {
                return '';
            }
        }

        return '';
    }

    /**
     * A TD failure holds the cursor without limit, because it passes on its own. A row this page can't save
     * steps aside after 5 attempts: one attachment must not stop the whole organization.
     *
     * @param array<string, mixed> $change
     * @return string error or empty
     */
    private static function process_change(array $change): string
    {
        try {
            $result = self::apply_change($change);
        } catch (\Throwable $e) {
            $result = false;
        }
        if ($result instanceof \WP_Error) {
            return self::failure($result);
        }
        // A new_asset row has no CMS ID yet, so attempts are counted by the TD file.
        $id  = (string) ($change['cms_media_id'] ?? $change['asset_id'] ?? '');
        $key = $id . ':' . self::row_version($change);
        if ($result === true) {
            self::forget_row($id, $key);

            return '';
        }

        $attempts       = get_option(self::OPTION_ATTEMPTS, []);
        $attempts       = is_array($attempts) ? $attempts : [];
        $attempts[$key] = (int) ($attempts[$key] ?? 0) + 1;
        if ($attempts[$key] < self::MAX_ATTEMPTS) {
            update_option(self::OPTION_ATTEMPTS, $attempts, false);

            return __('Could not save a change from Tribune Desk, so the feed will retry this page.', 'tribune-desk');
        }
        unset($attempts[$key]);
        update_option(self::OPTION_ATTEMPTS, $attempts, false);
        if (ctype_digit($id)) {
            $skipped = array_unique(array_merge(self::skipped_ids(), [(int) $id]));
            update_option(self::OPTION_SKIPPED, array_slice($skipped, -self::MAX_SKIPPED), false);
        } else {
            self::note_file_problem(__('A file from Tribune Desk could not be downloaded after 5 attempts, so the feed moved on.', 'tribune-desk'));
        }

        return '';
    }

    /** @param array<string, mixed> $change */
    private static function row_version(array $change): int
    {
        return ChangesFeed::row_version($change);
    }

    private static function forget_row(string $id, string $key): void
    {
        $attempts = get_option(self::OPTION_ATTEMPTS, []);
        if (is_array($attempts) && isset($attempts[$key])) {
            unset($attempts[$key]);
            update_option(self::OPTION_ATTEMPTS, $attempts, false);
        }
        $skipped = self::skipped_ids();
        if (ctype_digit($id) && in_array((int) $id, $skipped, true)) {
            update_option(self::OPTION_SKIPPED, array_values(array_diff($skipped, [(int) $id])), false);
        }
    }

    /**
     * @param array<string, mixed> $change
     * @return true|false|\WP_Error true: handled; false: we cannot save it; WP_Error: TD failure
     */
    private static function apply_change(array $change)
    {
        $kind = $change['kind'] ?? '';
        if ($kind === 'settings') {
            delete_transient(self::TRANSIENT_ACTIVE);
            delete_transient(self::TRANSIENT_SETTINGS);
            self::return_disabled_files();
            return true;
        }
        if ($kind === 'new_asset') {
            return self::apply_new_asset($change);
        }
        if ($kind === 'deleted' || $kind === 'restored') {
            return CmsDeletions::apply($change);
        }
        if ($kind !== 'fields' || !is_array($change['fields'] ?? null)) {
            return true;
        }

        $raw_id = $change['cms_media_id'] ?? '';
        if (!is_string($raw_id) || !ctype_digit($raw_id) || get_post_type((int) $raw_id) !== 'attachment') {
            return true;
        }
        $id       = (int) $raw_id;
        $versions = self::versions($id);
        $dirty    = MediaMetaSync::dirty_fields($id);
        $rows     = [];
        foreach ($change['fields'] as $row) {
            $field   = is_array($row) ? ($row['field'] ?? null) : null;
            $value   = is_array($row) ? ($row['value'] ?? null) : null;
            $version = is_array($row) ? ($row['version'] ?? null) : null;
            if (!in_array($field, self::FIELDS, true) || !is_string($value) || !is_int($version)) {
                continue;
            }
            // Our sent value is newer. A resync or switch row has no `base_version`
            // and carries the current field version, so at equal versions its value applies anyway.
            $stored = (int) ($versions[$field] ?? 0);
            if ($version < $stored || ($version === $stored && isset($row['base_version']))) {
                continue;
            }
            // The field is waiting to be sent: the server will resolve it by rule, so the version stays old.
            // We set the row aside, because on `rejected` or a rejected batch it must win.
            if (in_array($field, $dirty, true)) {
                self::keep_row($id, $field, $value, $version);
                continue;
            }
            $rows[$field] = ['value' => $value, 'version' => $version];
        }
        if ($rows === []) {
            return true;
        }

        $result = self::apply_rows($id, $rows);
        if ($result === true) {
            self::drop_kept($id, array_map(static fn ($r) => $r['version'], $rows));
        }

        return $result;
    }

    /**
     * File from TD: an attachment with a matching asset_id is not downloaded a second time, only reported via
     * /link, so a repeated row and an earlier article delivery do not create copies. The new_files option
     * is checked by the server. A row without an address is a file that no longer exists.
     *
     * @param array<string, mixed> $change
     * @return true|false|\WP_Error true: handled; false: failed download; WP_Error: TD failure
     */
    public static function apply_new_asset(array $change)
    {
        $asset_id = is_string($change['asset_id'] ?? null) ? trim($change['asset_id']) : '';
        $url      = is_string($change['file_url'] ?? null) ? $change['file_url'] : '';
        if ($asset_id === '' || $url === '') {
            return true;
        }
        $sha = is_string($change['sha256'] ?? null) ? strtolower($change['sha256']) : '';

        $id = MediaHandler::find_existing('', ['asset_id' => $asset_id]);
        if ($id === null) {
            $id = self::download_asset($change, $asset_id, $url, $sha);
            if ($id === null || !self::store_asset_texts($id, $change)) {
                return false;
            }
        }

        return self::link_asset($asset_id, $id, $sha);
    }

    /**
     * The address is signed: it does not go into the `_source_url` meta or the log. We save the asset_id right away,
     * so an interrupted run does not download the file a second time.
     *
     * @param array<string, mixed> $change
     */
    private static function download_asset(array $change, string $asset_id, string $url, string $sha): ?int
    {
        if (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }
        $filename = is_string($change['filename'] ?? null) ? $change['filename'] : '';
        // Markers in add_attachment, i.e. before thumbnails: a fatal or timeout during
        // generation will not leave an attachment without meta. The hook marks only the first new attachment
        // without a parent from this call and is always detached.
        $marked = false;
        $mark   = static function ($attachment_id) use (&$marked, $asset_id): void {
            if ($marked || get_post_type($attachment_id) !== 'attachment' || (int) wp_get_post_parent_id($attachment_id) !== 0) {
                return;
            }
            $marked = true;
            update_post_meta($attachment_id, MediaHandler::META_FROM_TD, 1);
            update_post_meta($attachment_id, MediaHandler::META_ASSET_ID, $asset_id);
        };
        add_action('add_attachment', $mark, 1);
        try {
            $id = MediaHandler::sideload_from_url(
                $url,
                MediaHandler::file_name($filename, '', $url),
                0,
                null,
                static fn (string $tmp): bool => self::is_expected_download($tmp, $sha)
            );
        } finally {
            remove_action('add_attachment', $mark, 1);
        }
        if ($id !== null) {
            update_post_meta($id, MediaHandler::META_FROM_TD, 1);
            update_post_meta($id, MediaHandler::META_ASSET_ID, $asset_id);
        }

        return $id;
    }

    /** An R2 address returns the original, so a different sha means a broken download, not a re-encode. */
    private static function is_expected_download(string $tmp, string $sha): bool
    {
        if ((int) filesize($tmp) === 0 || wp_get_image_mime($tmp) === false) {
            return false;
        }

        return $sha === '' || hash_file('sha256', $tmp) === $sha;
    }

    /**
     * A new attachment has only a title from the file name, so keep_nonempty does not apply to it. Saved outside the
     * metadata queue; versions as in apply_rows, only after a successful save.
     *
     * @param array<string, mixed> $change
     */
    private static function store_asset_texts(int $id, array $change): bool
    {
        $writes   = [];
        $versions = [];
        foreach (is_array($change['fields'] ?? null) ? $change['fields'] : [] as $row) {
            $field   = is_array($row) ? ($row['field'] ?? null) : null;
            $value   = is_array($row) ? ($row['value'] ?? null) : null;
            $version = is_array($row) ? ($row['version'] ?? null) : null;
            if (in_array($field, self::FIELDS, true) && is_string($value) && is_int($version)) {
                $writes[$field]   = $value;
                $versions[$field] = $version;
            }
        }
        if ($writes === []) {
            return true;
        }
        if (MediaMetaSync::apply($id, $writes) !== 'ok') {
            return false;
        }
        self::store_versions($id, $versions);

        return true;
    }

    /**
     * The sha of a file already saved in the library decides `reencoded`. Refusals that a retry will not
     * fix do not block the feed: the row is handled and the message stays on the screen.
     *
     * @return true|false|\WP_Error
     */
    private static function link_asset(string $asset_id, int $id, string $sha)
    {
        $url = wp_get_attachment_url($id);
        if (!is_string($url) || $url === '') {
            return false;
        }
        $path   = get_attached_file($id);
        $stored = is_string($path) && is_file($path) ? hash_file('sha256', $path) : false;

        $result = CmsApiClient::post('/media/' . rawurlencode($asset_id) . '/link', [
            'cms_media_id' => (string) $id,
            'file_url'     => $url,
            'reencoded'    => $sha !== '' && is_string($stored) && $stored !== $sha,
        ], self::WRITE_TIMEOUT);
        if (!is_wp_error($result)) {
            if (!in_array($result['status'] ?? null, ['ok', 'known'], true)) {
                return new \WP_Error(CmsApiClient::ERROR_INVALID_RESPONSE, self::invalid_response());
            }
            delete_transient(self::TRANSIENT_FILE_PROBLEM);

            return true;
        }

        $data   = $result->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        if ($status === 404) {
            self::note_file_problem(__('Tribune Desk no longer knows the file the plugin downloaded: it is deleted or in the trash. The attachment stays in the library.', 'tribune-desk'));

            return true;
        }
        if ($status === 422) {
            self::note_file_problem(__('Tribune Desk rejected the file address from this site: add the host in the Tribune Desk panel. The attachment stays in the library.', 'tribune-desk'));

            return true;
        }
        if ($result->get_error_code() !== 'state_conflict') {
            return $result;
        }

        return self::settle_link_conflict($result);
    }

    /**
     * 409 state_conflict carries the same code for a disabled integration and for a taken ID or
     * a changed address. /status decides, not the response body: a disabled integration holds the cursor.
     *
     * @return true|\WP_Error
     */
    private static function settle_link_conflict(\WP_Error $conflict)
    {
        $status = CmsApiClient::get('/status');
        if (is_wp_error($status)) {
            return $status;
        }
        $state = $status['state'] ?? null;
        if (!is_string($state) || $state === 'disabled') {
            return $conflict;
        }
        self::note_file_problem(__('Tribune Desk did not link the file to the attachment: the ID or address already belongs to another file. The attachment stays in the library.', 'tribune-desk'));

        return true;
    }

    private static function note_file_problem(string $message): void
    {
        set_transient(self::TRANSIENT_FILE_PROBLEM, $message, WEEK_IN_SECONDS);
    }

    /**
     * Shared save of TD rows under the keep_nonempty_td_to_cms rule; the version only after a successful save.
     *
     * @param array<string, array{value: string, version: int}> $rows
     * @return true|false|\WP_Error
     */
    private static function apply_rows(int $id, array $rows)
    {
        $writes   = [];
        $versions = [];
        foreach ($rows as $field => $row) {
            $current = MediaMetaSync::values($id, [$field])[$field] ?? '';
            if (ChangesFeed::overwrites_nonempty($current, $row['value'])) {
                $keep = self::keep_nonempty();
                if ($keep instanceof \WP_Error) {
                    return $keep;
                }
                if ($keep) {
                    $versions[$field] = $row['version'];
                    continue;
                }
            }
            $versions[$field] = $row['version'];
            if ($current !== $row['value']) {
                $writes[$field] = $row['value'];
            }
        }
        if ($writes !== [] && MediaMetaSync::apply($id, $writes) !== 'ok') {
            return false;
        }
        if ($versions !== []) {
            self::store_versions($id, $versions);
        }

        return true;
    }

    /** The plugin applies the keep_nonempty_td_to_cms rule. Error: unknown, must not overwrite. @return bool|\WP_Error */
    private static function keep_nonempty()
    {
        $cached = get_transient(self::TRANSIENT_SETTINGS);
        if ($cached !== false) {
            return $cached === 'keep';
        }
        $settings = CmsApiClient::get('/settings');
        if (is_wp_error($settings)) {
            return $settings;
        }
        $keep = is_array($settings['options'] ?? null) ? ($settings['options']['keep_nonempty_td_to_cms'] ?? null) : null;
        if (!is_bool($keep)) {
            return new \WP_Error(CmsApiClient::ERROR_INVALID_RESPONSE, self::invalid_response());
        }
        set_transient(self::TRANSIENT_SETTINGS, $keep ? 'keep' : 'overwrite', self::CACHE_TTL);

        return $keep;
    }

    /** @return array<string, array{value: string, version: int, batch?: string}> */
    private static function kept(int $id): array
    {
        $kept = get_post_meta($id, self::META_PENDING_TD, true);

        return is_array($kept) ? $kept : [];
    }

    /** @param array<string, array<string, mixed>> $kept */
    private static function save_kept(int $id, array $kept): void
    {
        if ($kept === []) {
            delete_post_meta($id, self::META_PENDING_TD);
        } else {
            // update_post_meta strips slashes, and TD values may contain them.
            update_post_meta($id, self::META_PENDING_TD, wp_slash($kept));
        }
    }

    private static function keep_row(int $id, string $field, string $value, int $version): void
    {
        $kept = self::kept($id);
        if (ChangesFeed::replaces_kept((int) ($kept[$field]['version'] ?? 0), $version)) {
            $kept[$field] = ['value' => $value, 'version' => $version];
            self::save_kept($id, $kept);
        }
    }

    /** Removes deferred rows not newer than the given versions. @param array<string, int> $versions */
    private static function drop_kept(int $id, array $versions): void
    {
        $kept = self::kept($id);
        if ($kept === []) {
            return;
        }
        foreach ($versions as $field => $version) {
            if (isset($kept[$field]) && ChangesFeed::drops_kept((int) $kept[$field]['version'], $version)) {
                unset($kept[$field]);
            }
        }
        self::save_kept($id, $kept);
    }

    /** Files rejected while the option was off come back when settings change; other reasons do not. */
    private static function return_disabled_files(): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
            MediaPush::META_PUSHED,
            $wpdb->esc_like('rejected:new_files_disabled@') . '%'
        ));
        foreach ($ids as $id) {
            delete_post_meta((int) $id, MediaPush::META_PUSHED);
        }
        if ($ids !== []) {
            self::schedule_send();
        }
    }

    /**
     * Settles decisions about held batches. `send`: the CMS value wins, the row is dropped.
     * `discard`: the TD row is saved. An error leaves the decision for the next run.
     *
     * @return string error or empty
     */
    private static function settle_decided(): string
    {
        $decided = get_option(self::OPTION_DECIDED, []);
        $decided = is_array($decided) ? $decided : [];
        $error   = self::read_server_decisions($decided);
        if ($decided === []) {
            return $error;
        }

        global $wpdb;
        foreach ($decided as $batch => $decision) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
                self::META_PENDING_TD,
                '%' . $wpdb->esc_like((string) $batch) . '%'
            ));
            $done = true;
            foreach ($ids as $raw) {
                $failure = self::settle_batch_rows((int) $raw, (string) $batch, (string) $decision);
                if ($failure !== '') {
                    $error = $failure;
                    $done  = false;
                }
            }
            if ($done) {
                unset($decided[$batch]);
            }
        }
        update_option(self::OPTION_DECIDED, $decided, false);

        return $error;
    }

    /**
     * The server (auto-rejection) or another API client may have resolved the batch, so the screen doesn't
     * know. It asks only when some row is waiting for a batch. Batch gone from the list (a day after
     * the decision): the row keeps waiting until the next feed change replaces it.
     *
     * @param array<string, string> $decided
     * @return string error or empty
     */
    private static function read_server_decisions(array &$decided): string
    {
        global $wpdb;
        $waiting = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1",
            self::META_PENDING_TD,
            '%' . $wpdb->esc_like('"batch"') . '%'
        ));
        if (!$waiting) {
            return '';
        }

        $held = CmsApiClient::get('/held');
        if (is_wp_error($held)) {
            return self::failure($held);
        }
        foreach (is_array($held['items'] ?? null) ? $held['items'] : [] as $batch) {
            $id       = is_array($batch) ? ($batch['id'] ?? null) : null;
            $state    = is_array($batch) ? ($batch['state'] ?? null) : null;
            $decision = ['sent' => 'send', 'discarded' => 'discard'][$state] ?? null;
            if (is_string($id) && $decision !== null) {
                $decided[$id] ??= $decision;
            }
        }

        return '';
    }

    /** @return string error or empty */
    private static function settle_batch_rows(int $id, string $batch, string $decision): string
    {
        $kept  = self::kept($id);
        $dirty = MediaMetaSync::dirty_fields($id);
        $rows  = [];
        foreach ($kept as $field => $row) {
            if (($row['batch'] ?? '') !== $batch) {
                continue;
            }
            if ($decision === 'discard' && !in_array($field, $dirty, true)) {
                $rows[$field] = ['value' => (string) $row['value'], 'version' => (int) $row['version']];
            }
            if ($decision === 'discard' && in_array($field, $dirty, true)) {
                unset($kept[$field]['batch']); // new edit: the row waits for the result of its send
            } else {
                unset($kept[$field]);
            }
        }
        if ($rows !== []) {
            $result = self::apply_rows($id, $rows);
            if ($result !== true) {
                return $result instanceof \WP_Error ? self::failure($result) : __('Could not save a change from Tribune Desk after the batch decision.', 'tribune-desk');
            }
        }
        self::save_kept($id, $kept);

        return '';
    }

    /** @return array<string, int> */
    private static function versions(int $id): array
    {
        $versions = get_post_meta($id, self::META_VERSIONS, true);

        return is_array($versions) ? $versions : [];
    }

    /** @param array<string, int> $new */
    private static function store_versions(int $id, array $new): void
    {
        update_post_meta($id, self::META_VERSIONS, array_merge(self::versions($id), $new));
    }

    // --- sending fields ---------------------------------------------------------------------

    /** @return string error or empty */
    private static function send_fields(): string
    {
        $note = '';
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $ids = MediaMetaSync::pending_ids(self::BATCH);
            if ($ids === []) {
                return $note;
            }

            $items = [];
            $sent  = [];
            foreach ($ids as $id) {
                $values = MediaMetaSync::values($id, MediaMetaSync::dirty_fields($id));
                $too_long = array_filter($values, static fn ($v) => mb_strlen($v) > self::MAX_FIELD || str_contains($v, "\0"));
                if ($too_long !== []) {
                    // 422 would block the whole queue; such a field can never pass.
                    MediaMetaSync::finish($id, $too_long);
                    $note = __('Skipped a field longer than 5000 characters or containing a NUL character: Tribune Desk will not accept it.', 'tribune-desk');
                }
                $values = array_diff_key($values, $too_long);
                if ($values === []) {
                    MediaMetaSync::clear($id);
                    continue;
                }
                $items[]   = ['cms_media_id' => (string) $id, 'fields' => self::field_writes($id, $values)];
                $sent[$id] = $values;
            }
            if ($items === []) {
                continue;
            }

            $response = CmsApiClient::post('/media/fields', ['items' => $items], self::WRITE_TIMEOUT);
            if (is_wp_error($response)) {
                return self::send_failure($response);
            }
            if (!is_array($response['results'] ?? null)) {
                return self::invalid_response();
            }
            $batch  = is_string($response['held_batch_id'] ?? null) ? $response['held_batch_id'] : '';
            $failed  = '';
            $waiting = false;
            foreach ($response['results'] as $result) {
                $id = is_array($result) ? (int) ($result['id'] ?? 0) : 0;
                if (isset($sent[$id])) {
                    $error   = self::finish_item($id, $sent[$id], $result, $batch);
                    $failed  = $error !== '' ? $error : $failed;
                    $waiting = $waiting || self::waits_for_import($id, $result);
                }
            }
            // The field stayed in the queue after an error: a retry in the same run would give the same result.
            if ($failed !== '') {
                return $failed;
            }
            // Import in progress: a retry right now and every minute would give the same result.
            if ($waiting) {
                self::schedule_send(self::RETRY_DELAY);
                return $note;
            }
        }
        if (MediaMetaSync::pending_ids(1) !== []) {
            self::schedule_send();
        }

        return $note;
    }

    /**
     * @param array<string, string> $values
     * @return list<array<string, mixed>>
     */
    private static function field_writes(int $id, array $values): array
    {
        $versions = self::versions($id);
        $marked   = MediaMetaSync::dirty_at($id);
        $writes   = [];
        foreach ($values as $field => $value) {
            $writes[] = ChangesFeed::field_write(
                (string) $field,
                (string) $value,
                isset($versions[$field]) ? (int) $versions[$field] : null,
                isset($marked[$field]) ? (int) $marked[$field] : null,
                time()
            );
        }

        return $writes;
    }

    /**
     * The server result finishes the field in the queue, unless the editor changed it during the send. The version
     * is saved only for `ok`, `unchanged` and `conflict`: with `rejected` and `held` the TD change stays
     * unread, and a saved version would make the feed skip it.
     *
     * @param array<string, string> $sent
     * @param array<string, mixed>  $result
     * @return string error or empty
     */
    private static function finish_item(int $id, array $sent, array $result, string $batch): string
    {
        $fields = is_array($result['fields'] ?? null) ? $result['fields'] : [];
        if ($fields === []) {
            if (!self::waits_for_import($id, $result) && in_array($result['status'] ?? '', ['unknown', 'rejected', 'held'], true)) {
                MediaMetaSync::finish($id, $sent);
            }
            return '';
        }

        $now      = MediaMetaSync::values($id, array_keys($sent));
        $kept     = self::kept($id);
        $done     = [];
        $versions = [];
        $winners  = [];
        $settle   = [];
        $held     = [];
        foreach ($fields as $row) {
            $field = is_array($row) ? ($row['field'] ?? null) : null;
            if (!is_string($field) || !isset($sent[$field])) {
                continue;
            }
            $status = $row['status'] ?? '';
            $same   = ($now[$field] ?? null) === $sent[$field];
            $done[$field] = $sent[$field];
            if (ChangesFeed::stores_versions($status) && is_int($row['version'] ?? null)) {
                $versions[$field] = $row['version'];
            }
            if ($status === 'conflict' && $same && is_string($row['winning_value'] ?? null)) {
                $winners[$field] = $row['winning_value'];
                unset($done[$field]);
            } elseif ($status === 'rejected' && $same && isset($kept[$field])) {
                $settle[$field] = ['value' => (string) $kept[$field]['value'], 'version' => (int) $kept[$field]['version']];
                unset($done[$field]);
            } elseif ($status === 'held' && isset($kept[$field])) {
                $held[] = $field;
            }
        }

        $error = '';
        if ($winners !== []) {
            if (MediaMetaSync::apply($id, $winners) === 'ok') {
                MediaMetaSync::drop_fields($id, array_keys($winners));
            } else {
                // Queue and version stay: the next send will get a conflict with the winner again.
                $versions = array_diff_key($versions, $winners);
                $error    = __('Could not save the winning value from Tribune Desk.', 'tribune-desk');
            }
        }
        if ($settle !== []) {
            $applied = self::apply_rows($id, $settle);
            if ($applied === true) {
                MediaMetaSync::drop_fields($id, array_keys($settle));
                self::drop_kept($id, array_map(static fn ($r) => $r['version'], $settle));
            } else {
                // Field in the queue: the next send will get `rejected` again and retry this save.
                $error = $applied instanceof \WP_Error
                    ? self::failure($applied)
                    : __('Could not save a change from Tribune Desk.', 'tribune-desk');
            }
        }
        MediaMetaSync::finish($id, $done);
        if ($versions !== []) {
            self::store_versions($id, $versions);
            self::drop_kept($id, $versions);
        }
        if ($held !== [] && $batch !== '') {
            $kept = self::kept($id);
            foreach ($held as $field) {
                if (isset($kept[$field])) {
                    $kept[$field]['batch'] = $batch;
                }
            }
            self::save_kept($id, $kept);
        }

        return $error;
    }

    /**
     * `unknown` for a recently accepted file means "import still in progress": TD creates the item only after
     * downloading the file, and an edit made before then must get through. After a day it is an ordinary `unknown`.
     *
     * @param array<string, mixed> $result
     */
    private static function waits_for_import(int $id, array $result): bool
    {
        if (($result['status'] ?? '') !== 'unknown') {
            return false;
        }
        $pushed = (string) get_post_meta($id, MediaPush::META_PUSHED, true);

        return str_starts_with($pushed, 'accepted@')
            && time() - (int) substr($pushed, strlen('accepted@')) < DAY_IN_SECONDS;
    }

    // --- new files --------------------------------------------------------------------------

    /** @return string error or empty */
    private static function send_new_files(): string
    {
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $ids = MediaPush::candidates(self::BATCH);
            if ($ids === []) {
                return '';
            }

            $items = [];
            foreach ($ids as $id) {
                $item = MediaPush::cms_item($id);
                if ($item['file_url'] === '') {
                    update_post_meta($id, MediaPush::META_PUSHED, 'rejected:no_url@' . time());
                    continue;
                }
                $items[] = $item;
            }
            if ($items !== []) {
                $response = CmsApiClient::post('/media', ['items' => $items], self::WRITE_TIMEOUT);
                if (is_wp_error($response)) {
                    DeliveriesLog::record('', null, $response->get_error_code(), $response->get_error_message(), DeliveriesLog::KIND_MEDIA);

                    return self::send_failure($response);
                }
                if (!is_array($response['results'] ?? null)) {
                    return self::invalid_response();
                }
                self::finish_files($ids, $response['results']);
            }
            if (count($ids) < self::BATCH) {
                return '';
            }
        }

        return '';
    }

    /**
     * @param int[]        $ids
     * @param array<mixed> $results
     */
    private static function finish_files(array $ids, array $results): void
    {
        $counts = [];
        foreach ($results as $result) {
            $id = is_array($result) ? (int) ($result['id'] ?? 0) : 0;
            if (!in_array($id, $ids, true)) {
                continue;
            }
            $outcome = match ($result['status'] ?? '') {
                'ok'    => 'accepted',
                'known' => 'known',
                default => 'rejected:' . sanitize_key((string) ($result['reason'] ?? 'unknown')),
            };
            update_post_meta($id, MediaPush::META_PUSHED, $outcome . '@' . time());
            $key          = str_starts_with($outcome, 'rejected') ? 'rejected' : $outcome;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $message = implode(' ', array_map(static fn ($k, $v) => "$k=$v", array_keys($counts), $counts));
        DeliveriesLog::record('', null, 'sent', $message, DeliveriesLog::KIND_MEDIA);
    }
}
