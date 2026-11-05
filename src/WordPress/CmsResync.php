<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\Resync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Field reconciliation after the feed cursor expires (410): the plugin sends the state of attachments in batches of
 * 100 every 30 seconds in the background, then waits for approval. Both go to POST /resync: the server tells
 * them apart by the body.
 * The position lives in an option, so an error does not lose it. Giving up deletes the position, the flag and the cursor:
 * the feed starts from "now". Sending ordinary changes does not wait for the reconciliation.
 */
class CmsResync
{
    public const ACTION_START   = 'headlinesforge_cms_resync_start';
    public const ACTION_CONFIRM = 'headlinesforge_cms_resync_confirm';
    public const ACTION_ABANDON = 'headlinesforge_cms_resync_abandon';
    public const ACTION_RESUME  = 'headlinesforge_cms_resync_resume';
    public const HOOK_PAGE      = 'headlinesforge_cms_resync_page';
    public const OPTION_STATE   = 'headlinesforge_cms_resync';

    private const DIRECTIONS = Resync::DIRECTIONS;
    private const FIELDS     = Resync::FIELDS;
    private const DELAY      = 30;
    /** Approval applies the differences at once, and a repeat after a dropped connection returns the same cursor. */
    private const CONFIRM_TIMEOUT = 60;

    // --- form handlers ----------------------------------------------------------------------

    public static function handle_start(): void
    {
        self::guard(self::ACTION_START);

        $direction = isset($_POST['hf_cms_direction']) ? sanitize_key(wp_unslash((string) $_POST['hf_cms_direction'])) : '';
        if (!in_array($direction, self::DIRECTIONS, true)) {
            CmsSettingsScreen::flash('notice-error', __('Choose the sync direction.', 'tribune-desk'));
        } elseif (self::state() !== null) {
            CmsSettingsScreen::flash('notice-error', __('A sync is already running.', 'tribune-desk'));
        } else {
            update_option(self::OPTION_STATE, ['direction' => $direction, 'status' => 'collecting', 'last_id' => 0, 'error' => ''], false);
            self::schedule(0);
            CmsSettingsScreen::flash('notice-success', __('Sync started: fields are sent to Tribune Desk in batches in the background.', 'tribune-desk'));
        }
        self::back();
    }

    public static function handle_resume(): void
    {
        self::guard(self::ACTION_RESUME);

        $state = self::state();
        if ($state !== null && $state['status'] === 'collecting') {
            $state['error'] = '';
            update_option(self::OPTION_STATE, $state, false);
            self::schedule(0);
        }
        self::back();
    }

    public static function handle_confirm(): void
    {
        self::guard(self::ACTION_CONFIRM);

        $state = self::state();
        if ($state === null || $state['status'] !== 'ready' || $state['resync_id'] === '') {
            CmsSettingsScreen::flash('notice-error', __('There is no preview ready to approve.', 'tribune-desk'));
            self::back();
        }
        if ($state['expires_at'] > 0 && $state['expires_at'] < time()) {
            CmsSettingsScreen::flash('notice-error', __('The preview expired (valid for 24 hours). Cancel and start over.', 'tribune-desk'));
            self::back();
        }

        $result = CmsApiClient::post('/resync', ['resync_id' => $state['resync_id'], 'confirm' => true], self::CONFIRM_TIMEOUT);
        $cursor = is_array($result) ? ($result['cursor'] ?? null) : null;
        if (is_wp_error($result)) {
            $state['error'] = $result->get_error_message();
            update_option(self::OPTION_STATE, $state, false);
            CmsSettingsScreen::flash('notice-error', $state['error']);
            self::back();
        }
        if (!is_string($cursor) || $cursor === '' || !in_array($result['status'] ?? '', ['applied', 'held'], true)) {
            $state['error'] = __('Tribune Desk returned an unexpected response.', 'tribune-desk');
            update_option(self::OPTION_STATE, $state, false);
            CmsSettingsScreen::flash('notice-error', $state['error']);
            self::back();
        }

        update_option(CmsSync::OPTION_CURSOR, $cursor, false);
        delete_option(CmsSync::OPTION_NEEDS_RESYNC);
        delete_option(self::OPTION_STATE);
        wp_clear_scheduled_hook(self::HOOK_PAGE);

        $batch = is_string($result['held_batch_id'] ?? null) ? $result['held_batch_id'] : '';
        if ($result['status'] === 'held') {
            /* translators: %s: batch identifier */
            CmsSettingsScreen::flash('notice-warning', sprintf(__('There are more changes than the bulk-change threshold, so Tribune Desk held batch %s. Decide on it in the “Held change batches” section.', 'tribune-desk'), $batch));
        } else {
            CmsSettingsScreen::flash('notice-success', __('Sync approved. The changes feed keeps running.', 'tribune-desk'));
        }
        self::back();
    }

    public static function handle_abandon(): void
    {
        self::guard(self::ACTION_ABANDON);

        delete_option(self::OPTION_STATE);
        delete_option(CmsSync::OPTION_NEEDS_RESYNC);
        delete_option(CmsSync::OPTION_CURSOR);
        wp_clear_scheduled_hook(self::HOOK_PAGE);
        CmsSettingsScreen::flash('notice-warning', __('Sync cancelled. The feed will start from now, and changes from the gap will not arrive.', 'tribune-desk'));
        self::back();
    }

    // --- background batches -----------------------------------------------------------------

    public static function run_page(): void
    {
        $state = self::state();
        if ($state === null || $state['status'] !== 'collecting' || !CmsApiClient::has_token()) {
            return;
        }

        $ids     = self::next_ids($state['last_id']);
        $is_last = count($ids) <= CmsSync::BATCH;
        $ids     = array_slice($ids, 0, CmsSync::BATCH);

        $values = [];
        foreach ($ids as $id) {
            $values[$id] = MediaMetaSync::values($id, self::FIELDS);
        }
        $body   = Resync::page($state['direction'], $values, $is_last, $state['resync_id']);
        $result = CmsApiClient::post('/resync', $body, CmsSync::WRITE_TIMEOUT);
        if (is_wp_error($result)) {
            self::fail_state($state, $result->get_error_message());
            return;
        }
        $rid = $result['resync_id'] ?? null;
        if (!Resync::answer_is_valid($result, $is_last)) {
            self::fail_state($state, __('Tribune Desk returned an unexpected response.', 'tribune-desk'));
            return;
        }

        $expires = is_string($result['expires_at'] ?? null) ? strtotime($result['expires_at']) : false;
        $state   = array_merge($state, [
            'resync_id'   => $rid,
            'status'      => $is_last ? 'ready' : 'collecting',
            'last_id'     => $ids === [] ? $state['last_id'] : max($ids),
            'differences' => is_int($result['differences'] ?? null) ? $result['differences'] : 0,
            'expires_at'  => $expires !== false ? $expires : 0,
            'error'       => '',
        ]);
        update_option(self::OPTION_STATE, $state, false);
        if (!$is_last) {
            self::schedule(self::DELAY);
        }
    }

    /** @return int[] up to 101 image ids greater than $after, ascending */
    private static function next_ids(int $after): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
            'image/%',
            $after,
            CmsSync::BATCH + 1
        ));

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /** @param array<string, mixed> $state */
    private static function fail_state(array $state, string $error): void
    {
        $state['error'] = $error;
        update_option(self::OPTION_STATE, $state, false);
    }

    private static function schedule(int $delay): void
    {
        if (!wp_next_scheduled(self::HOOK_PAGE)) {
            wp_schedule_single_event(time() + $delay, self::HOOK_PAGE);
        }
    }

    // --- screen -----------------------------------------------------------------------------

    public static function is_relevant(): bool
    {
        return (bool) get_option(CmsSync::OPTION_NEEDS_RESYNC) || self::state() !== null;
    }

    public static function render(): void
    {
        $state = self::state();
        if (!self::is_relevant()) {
            return;
        }

        echo '<h3>' . esc_html__('Sync with Tribune Desk', 'tribune-desk') . '</h3>';
        if ($state === null) {
            echo '<p>' . esc_html__('The changes feed expired: Tribune Desk no longer holds changes since the last fetch. The feed is paused until you sync the fields or cancel. Sending your changes continues.', 'tribune-desk') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_START) . '">';
            wp_nonce_field(self::ACTION_START);
            echo '<p><label><input type="radio" name="hf_cms_direction" value="cms_to_td" checked> '
                . esc_html__('WordPress wins: WordPress values will replace differing values in Tribune Desk.', 'tribune-desk') . '</label><br>';
            echo '<label><input type="radio" name="hf_cms_direction" value="td_to_cms"> '
                . esc_html__('Tribune Desk wins: Tribune Desk values will replace differing values in WordPress.', 'tribune-desk') . '</label></p>';
            submit_button(__('Start sync', 'tribune-desk'), 'primary', 'submit', false);
            echo '</form>';
            self::abandon_form();
            return;
        }

        echo '<p>' . esc_html(
            $state['direction'] === 'cms_to_td'
                ? __('WordPress wins: differing values in Tribune Desk will be replaced with WordPress values.', 'tribune-desk')
                : __('Tribune Desk wins: differing values in WordPress will be replaced with Tribune Desk values.', 'tribune-desk')
        ) . '</p>';

        if ($state['error'] !== '') {
            echo '<div class="notice inline notice-error"><p>' . esc_html($state['error']) . '</p></div>';
        }

        if ($state['status'] === 'collecting') {
            /* translators: %d: id of the last file sent */
            echo '<p>' . esc_html(sprintf(__('Sending files in batches of 100 (last sent: %d).', 'tribune-desk'), $state['last_id'])) . '</p>';
            if ($state['error'] !== '') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RESUME) . '">';
                wp_nonce_field(self::ACTION_RESUME);
                submit_button(__('Resume from the saved position', 'tribune-desk'), 'secondary', 'submit', false);
                echo '</form>';
            }
        } else {
            /* translators: %d: number of differences */
            echo '<p>' . esc_html(sprintf(__('Preview ready. Number of differences: %d.', 'tribune-desk'), $state['differences'])) . '</p>';
            if ($state['expires_at'] > 0 && $state['expires_at'] < time()) {
                echo '<p>' . esc_html__('The preview expired (valid for 24 hours): cancel and start over.', 'tribune-desk') . '</p>';
            } else {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_CONFIRM) . '">';
                wp_nonce_field(self::ACTION_CONFIRM);
                submit_button(__('Approve sync', 'tribune-desk'), 'primary', 'submit', false);
                echo '</form>';
            }
        }
        self::abandon_form();
    }

    private static function abandon_form(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_ABANDON) . '">';
        wp_nonce_field(self::ACTION_ABANDON);
        echo '<p>' . esc_html__('Cancelling starts the feed from now: changes from the gap will not arrive.', 'tribune-desk') . '</p>';
        submit_button(__('Cancel and start from now', 'tribune-desk'), 'secondary', 'submit', false);
        echo '</form>';
    }

    // --- shared -----------------------------------------------------------------------------

    /** @return array{direction: string, status: string, last_id: int, resync_id: string, differences: int, expires_at: int, error: string}|null */
    private static function state(): ?array
    {
        $raw = get_option(self::OPTION_STATE, false);
        if (!is_array($raw) || !in_array($raw['direction'] ?? null, self::DIRECTIONS, true)) {
            return null;
        }

        return [
            'direction'   => (string) $raw['direction'],
            'status'      => ($raw['status'] ?? '') === 'ready' ? 'ready' : 'collecting',
            'last_id'     => (int) ($raw['last_id'] ?? 0),
            'resync_id'   => is_string($raw['resync_id'] ?? null) ? $raw['resync_id'] : '',
            'differences' => (int) ($raw['differences'] ?? 0),
            'expires_at'  => (int) ($raw['expires_at'] ?? 0),
            'error'       => is_string($raw['error'] ?? null) ? $raw['error'] : '',
        ];
    }

    /** Capability first, then nonce; both end in wp_die. */
    private static function guard(string $nonce_action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission.', 'tribune-desk'), 403);
        }
        check_admin_referer($nonce_action);
    }

    private static function back(): void
    {
        wp_safe_redirect(add_query_arg(['page' => Settings::PAGE_SLUG], admin_url('options-general.php')));
        exit;
    }
}
