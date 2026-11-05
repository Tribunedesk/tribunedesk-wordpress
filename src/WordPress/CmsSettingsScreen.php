<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The "Media sync" block on the Tribune Desk page (spec HF media sync D4).
 * Tribune Desk is the source of truth for the settings: the plugin only shows and sends them back, with no local
 * copy, so that the form does not overwrite a change made elsewhere. Locks are enforced by the server.
 * A Tribune Desk error ends in a message in this block; the rest of the page keeps working.
 */
class CmsSettingsScreen
{
    public const ACTION_SAVE      = 'headlinesforge_cms_settings_save';
    public const ACTION_HELD      = 'headlinesforge_cms_held_decision';
    public const ACTION_PUSH      = 'headlinesforge_cms_push_existing';
    public const TRANSIENT_NOTICE = 'headlinesforge_cms_settings_notice';
    /** The "older files" run: day, after. A new date starts from the beginning. */
    public const OPTION_PUSH      = 'headlinesforge_cms_push_existing';

    private const VARIANTS = ['full', 'cms_to_td_only', 'td_to_cms_only', 'custom'];
    private const FIELDS   = ['title', 'alt', 'caption', 'description'];
    private const BOOLS    = [
        'new_files_cms_to_td', 'new_files_td_to_cms', 'td_to_cms_editor_files', 'td_to_cms_graphics',
        'keep_nonempty_cms_to_td', 'keep_nonempty_td_to_cms', 'cms_deletion_trashes_in_td',
        'td_deletion_marks_in_cms', 'keep_copies_in_td',
    ];
    private const CONFLICT_RULES = ['last_change', 'cms', 'td'];
    private const MODEL_TEXTS    = ['never', 'empty_only'];

    public static function render_notice(): void
    {
        $key    = self::notice_key();
        $notice = get_transient($key);
        if (!is_array($notice)) {
            return;
        }
        delete_transient($key);
        echo '<div class="notice ' . esc_attr((string) ($notice['class'] ?? 'notice-info')) . ' is-dismissible"><p>'
            . esc_html((string) ($notice['text'] ?? '')) . '</p></div>';
    }

    public static function render_block(): void
    {
        echo '<h2>' . esc_html__('Media sync with Tribune Desk', 'tribune-desk') . '</h2>';

        if (!CmsApiClient::has_token()) {
            self::message('notice-warning', __('Media sync requires a CMS API token', 'tribune-desk'));
            return;
        }

        // Before /status: a Tribune Desk failure must not take away the information about runs.
        self::render_last_runs();

        $status = CmsApiClient::get('/status');
        if (is_wp_error($status)) {
            self::message('notice-error', $status->get_error_message());
            return;
        }
        self::render_status($status);
        if (($status['state'] ?? '') === 'disabled') {
            return;
        }
        CmsResync::render();

        $settings = self::fetch_settings();
        if (is_wp_error($settings)) {
            self::message('notice-error', $settings->get_error_message());
        } else {
            self::render_form($settings);
        }
        self::render_push_existing();

        $held = CmsApiClient::get('/held');
        if (is_wp_error($held)) {
            self::message('notice-error', $held->get_error_message());
        } else {
            self::render_held($held);
        }
    }

    public static function handle_save(): void
    {
        self::guard(self::ACTION_SAVE);

        $variant = isset($_POST['hf_cms_variant']) ? sanitize_key(wp_unslash((string) $_POST['hf_cms_variant'])) : '';
        $body    = self::build_body($variant);
        if (is_wp_error($body)) {
            self::notice('notice-error', $body->get_error_message());
            self::back();
        }

        $result = CmsApiClient::put('/settings', $body);
        if (is_wp_error($result)) {
            self::notice('notice-error', $result->get_error_message());
        } else {
            self::notice('notice-success', __('Settings saved in Tribune Desk.', 'tribune-desk'));
        }
        self::back();
    }

    public static function handle_held(): void
    {
        $id = isset($_POST['hf_cms_held_id']) ? sanitize_key(wp_unslash((string) $_POST['hf_cms_held_id'])) : '';
        self::guard(self::ACTION_HELD . '_' . $id);

        $decision = isset($_POST['hf_cms_decision']) ? sanitize_key(wp_unslash((string) $_POST['hf_cms_decision'])) : '';
        if (!self::is_uuid($id) || !in_array($decision, ['send', 'discard'], true)) {
            self::notice('notice-error', __('Invalid decision.', 'tribune-desk'));
            self::back();
        }

        $result = CmsApiClient::post('/held/' . $id . '/decision', ['decision' => $decision]);
        if (is_wp_error($result)) {
            self::notice('notice-error', $result->get_error_message());
        } else {
            CmsSync::note_held_decision($id, $decision);
            if ($decision === 'send') {
                self::notice('notice-success', __('Batch sent: Tribune Desk applies the changes in portions in the background.', 'tribune-desk'));
            } else {
                self::notice('notice-success', __('Batch discarded: these changes will not be applied.', 'tribune-desk'));
            }
        }
        self::back();
    }

    /**
     * One click is one portion. A run ends with `next_after = null`, not `queued = 0`.
     * Files arrive through the channel from the cursor, so without the first fetch there is nothing to send.
     */
    public static function handle_push_existing(): void
    {
        self::guard(self::ACTION_PUSH);

        if ((string) get_option(CmsSync::OPTION_CURSOR, '') === '') {
            self::notice('notice-warning', self::push_needs_poll());
            self::back();
        }
        $day   = isset($_POST['hf_cms_push_since']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['hf_cms_push_since']))) : '';
        $since = self::since_from_post($day, '');
        if ($since === null) {
            self::notice('notice-error', __('Invalid date - expected format YYYY-MM-DD.', 'tribune-desk'));
            self::back();
        }

        $body  = ['since' => $since !== '' ? $since : null];
        $saved = self::push_state();
        if ($saved !== null && $saved['day'] === $day) {
            $body['after'] = $saved['after'];
        }
        $result = CmsApiClient::post('/media/push-existing', $body, CmsSync::WRITE_TIMEOUT);
        if (is_wp_error($result)) {
            $conflict = $result->get_error_code() === 'state_conflict';
            self::notice($conflict ? 'notice-warning' : 'notice-error', $conflict ? self::push_needs_poll() : $result->get_error_message());
            self::back();
        }

        $next = $result['next_after'] ?? null;
        if (!is_int($result['queued'] ?? null) || !array_key_exists('next_after', $result) || !((is_string($next) && $next !== '') || $next === null)) {
            self::notice('notice-error', __('Tribune Desk returned an unexpected response.', 'tribune-desk'));
            self::back();
        }
        if ($next === null) {
            delete_option(self::OPTION_PUSH);
            /* translators: %d: number of files */
            self::notice('notice-success', sprintf(__('All older files have been sent. Queued in this click: %d.', 'tribune-desk'), $result['queued']));
        } else {
            update_option(self::OPTION_PUSH, ['day' => $day, 'after' => $next], false);
            /* translators: %d: number of files */
            self::notice('notice-success', sprintf(__('Queued: %d. Click again to send the next portion.', 'tribune-desk'), $result['queued']));
        }
        self::back();
    }

    private static function push_needs_poll(): string
    {
        return __('The changes feed has not been fetched yet. Wait for the first fetch (up to 5 minutes) and click again.', 'tribune-desk');
    }

    /** @return array{day: string, after: string}|null */
    private static function push_state(): ?array
    {
        $saved = get_option(self::OPTION_PUSH, null);

        return is_array($saved) && is_string($saved['day'] ?? null) && is_string($saved['after'] ?? null) && $saved['after'] !== ''
            ? ['day' => $saved['day'], 'after' => $saved['after']]
            : null;
    }

    /** The form only, no HTTP: the route is called only by a click. */
    private static function render_push_existing(): void
    {
        $saved = self::push_state();
        echo '<h3>' . esc_html__('Older files from Tribune Desk', 'tribune-desk') . '</h3>';
        if ($saved !== null) {
            echo '<p>' . esc_html__('Sending is in progress: click again to send the next portion. A different date starts from the beginning.', 'tribune-desk') . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_PUSH) . '">';
        wp_nonce_field(self::ACTION_PUSH);
        echo '<p><label>' . esc_html__('From day (empty: all)', 'tribune-desk')
            . ' <input type="date" name="hf_cms_push_since" value="' . esc_attr($saved['day'] ?? '') . '"></label></p>';
        submit_button(__('Send older files from Tribune Desk', 'tribune-desk'), 'secondary', 'submit', false);
        echo '</form>';
    }

    /** Capability first, then nonce; both end in wp_die. */
    private static function guard(string $nonce_action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'tribune-desk'), 403);
        }
        check_admin_referer($nonce_action);
    }

    private static function back(): void
    {
        wp_safe_redirect(add_query_arg(['page' => Settings::PAGE_SLUG], admin_url('options-general.php')));
        exit;
    }

    private static function notice_key(): string
    {
        return self::TRANSIENT_NOTICE . '_' . get_current_user_id();
    }

    /** The message after the redirect, for the other handlers on this screen. */
    public static function flash(string $class, string $text): void
    {
        self::notice($class, $text);
    }

    private static function notice(string $class, string $text): void
    {
        set_transient(self::notice_key(), ['class' => $class, 'text' => $text], MINUTE_IN_SECONDS);
    }

    private static function message(string $class, string $text): void
    {
        echo '<div class="notice inline ' . esc_attr($class) . '"><p>' . esc_html($text) . '</p></div>';
    }

    private static function is_uuid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1;
    }

    /** @param mixed $value */
    private static function text($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return array<string, mixed>|\WP_Error */
    private static function fetch_settings()
    {
        $settings = CmsApiClient::get('/settings');
        if (is_wp_error($settings)) {
            return $settings;
        }
        // The same answer carries the image hosts: keep them fresh while we have it.
        ImageHosts::remember($settings);
        if (
            !in_array($settings['variant'] ?? null, self::VARIANTS, true)
            || !is_array($settings['options'] ?? null)
            || !is_array($settings['options']['fields_cms_to_td'] ?? null)
            || !is_array($settings['options']['fields_td_to_cms'] ?? null)
            || !is_array($settings['locked_options'] ?? null)
        ) {
            return new \WP_Error(CmsApiClient::ERROR_INVALID_RESPONSE, __('Tribune Desk returned an unexpected response.', 'tribune-desk'));
        }

        return $settings;
    }

    /**
     * The PUT body. Only "custom" carries options and it must give all of them: what the form showed
     * as locked (the browser does not send it) we take from the current read. The rest
     * goes as it came, so an option locked in the meantime is rejected by the server with a 409.
     *
     * @return array<string, mixed>|\WP_Error
     */
    private static function build_body(string $variant)
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return new \WP_Error('invalid', __('Invalid sync variant.', 'tribune-desk'));
        }
        if ($variant !== 'custom') {
            return ['variant' => $variant];
        }

        $current = self::fetch_settings();
        if (is_wp_error($current)) {
            return $current;
        }
        $posted = isset($_POST['hf_cms']) && is_array($_POST['hf_cms']) ? wp_unslash($_POST['hf_cms']) : [];
        $kept   = isset($_POST['hf_cms_kept']) && is_array($_POST['hf_cms_kept'])
            ? array_map('strval', wp_unslash($_POST['hf_cms_kept']))
            : [];

        $options = self::options_from_post($posted, $current['options'], $kept);

        return is_wp_error($options) ? $options : ['variant' => 'custom', 'options' => $options];
    }

    /**
     * @param array<string, mixed> $posted
     * @param array<string, mixed> $current
     * @param list<string>         $kept
     * @return array<string, mixed>|\WP_Error
     */
    private static function options_from_post(array $posted, array $current, array $kept)
    {
        $options = [];
        foreach (self::BOOLS as $name) {
            $options[$name] = in_array($name, $kept, true) ? (bool) ($current[$name] ?? false) : !empty($posted[$name]);
        }
        foreach (['fields_cms_to_td', 'fields_td_to_cms'] as $group) {
            foreach (self::FIELDS as $field) {
                $options[$group][$field] = in_array($group . '.' . $field, $kept, true)
                    ? (bool) ($current[$group][$field] ?? false)
                    : !empty($posted[$group][$field]);
            }
        }

        foreach (['conflict_rule' => self::CONFLICT_RULES, 'model_texts_to_cms' => self::MODEL_TEXTS] as $name => $allowed) {
            $value = in_array($name, $kept, true) ? ($current[$name] ?? null) : self::text($posted[$name] ?? '');
            if (!in_array($value, $allowed, true)) {
                return new \WP_Error('invalid', __('Invalid option value.', 'tribune-desk'));
            }
            $options[$name] = $value;
        }

        $threshold = in_array('mass_change_threshold', $kept, true)
            ? ($current['mass_change_threshold'] ?? null)
            : self::positive_int($posted['mass_change_threshold'] ?? '');
        if (!is_int($threshold)) {
            return new \WP_Error('invalid', __('The mass-change threshold must be a whole number from 1.', 'tribune-desk'));
        }
        $options['mass_change_threshold'] = $threshold;

        if (in_array('new_files_cms_to_td_min_width', $kept, true)) {
            $options['new_files_cms_to_td_min_width'] = $current['new_files_cms_to_td_min_width'] ?? null;
        } else {
            $width = self::text($posted['new_files_cms_to_td_min_width'] ?? '');
            $min   = self::positive_int($width);
            if (trim($width) !== '' && $min === null) {
                return new \WP_Error('invalid', __('The minimum width must be a whole number from 1 or empty.', 'tribune-desk'));
            }
            $options['new_files_cms_to_td_min_width'] = $min;
        }

        $since = self::text($current['new_files_cms_to_td_since'] ?? '');
        if (!in_array('new_files_cms_to_td_since', $kept, true)) {
            $since = self::since_from_post(self::text($posted['new_files_cms_to_td_since'] ?? ''), $since);
            if ($since === null) {
                return new \WP_Error('invalid', __('Invalid date - expected format YYYY-MM-DD.', 'tribune-desk'));
            }
        }
        $options['new_files_cms_to_td_since'] = $since !== '' ? $since : null;

        return $options;
    }

    /** @param mixed $value */
    private static function positive_int($value): ?int
    {
        $text = trim(self::text($value));

        return preg_match('/^[1-9][0-9]{0,9}$/', $text) === 1 ? (int) $text : null;
    }

    /** An empty day means no threshold; an unchanged day keeps the exact time from Tribune Desk. Null = invalid date. */
    private static function since_from_post(string $day, string $current): ?string
    {
        $day = trim($day);
        if ($day === '') {
            return '';
        }
        if ($day === self::since_day($current)) {
            return $current;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, wp_timezone());
        if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
            return null;
        }

        return $parsed->format(DATE_ATOM);
    }

    /** The day in the site's time zone for a date from the API; empty when there is no date or it is unreadable. */
    private static function since_day(string $value): string
    {
        $timestamp = $value !== '' ? strtotime($value) : false;

        return $timestamp !== false ? (string) wp_date('Y-m-d', $timestamp) : '';
    }

    /** Time from the API in the site's time zone; an unreadable one stays as it came. @param mixed $value */
    private static function local_time($value): string
    {
        $text      = self::text($value);
        $timestamp = $text !== '' ? strtotime($text) : false;

        return $timestamp !== false ? (string) wp_date('Y-m-d H:i', $timestamp) : $text;
    }

    /** @param array<string, mixed> $status */
    private static function render_status(array $status): void
    {
        $state  = self::text($status['state'] ?? '');
        $labels = [
            'active'   => __('running', 'tribune-desk'),
            'stalled'  => __('stopped: the CMS has not fetched changes for over 24 hours', 'tribune-desk'),
            'disabled' => __('not enabled: a Tribune Desk administrator turns it on', 'tribune-desk'),
        ];
        /* translators: %s: integration state */
        echo '<p>' . esc_html(sprintf(__('Integration status: %s', 'tribune-desk'), $labels[$state] ?? $state)) . '</p>';

        if (isset($status['pending_changes'])) {
            /* translators: %d: number of changes */
            echo '<p>' . esc_html(sprintf(__('Changes waiting to be fetched: %d', 'tribune-desk'), (int) $status['pending_changes'])) . '</p>';
        }
        if (!empty($status['last_changes_poll_at'])) {
            /* translators: %s: date */
            echo '<p>' . esc_html(sprintf(__('Last changes fetch: %s', 'tribune-desk'), self::local_time($status['last_changes_poll_at']))) . '</p>';
        }

        $errors = is_array($status['recent_errors'] ?? null) ? array_slice($status['recent_errors'], 0, 5) : [];
        if ($errors !== []) {
            echo '<p>' . esc_html__('Recent errors:', 'tribune-desk') . '</p><ul>';
            foreach ($errors as $error) {
                if (is_array($error)) {
                    echo '<li>' . esc_html(self::local_time($error['at'] ?? '') . ' ' . self::text($error['code'] ?? '') . ': ' . self::text($error['message'] ?? '')) . '</li>';
                }
            }
            echo '</ul>';
        }
    }

    /** The plugin's latest runs: exchange failures show here, because cron has no other place. */
    private static function render_last_runs(): void
    {
        $labels = [
            'poll' => __('Last changes fetch by the plugin', 'tribune-desk'),
            'send' => __('Last send by the plugin', 'tribune-desk'),
        ];
        $runs = CmsSync::last_runs();
        foreach ($labels as $kind => $label) {
            $run = $runs[$kind] ?? null;
            if (!is_array($run)) {
                continue;
            }
            echo '<p>' . esc_html($label . ': ' . wp_date('Y-m-d H:i', (int) ($run['at'] ?? 0))) . '</p>';
            $error = self::text($run['error'] ?? '');
            if ($error !== '') {
                self::message('notice-error', $error);
            }
        }

        // Backfill import export: fixed content written by CmsBackfill, read-only.
        $export = self::text(get_option(CmsBackfill::OPTION_ERROR, ''));
        if ($export !== '') {
            /* translators: %s: error message */
            self::message('notice-error', sprintf(__('Backfill import: %s', 'tribune-desk'), $export));
        }

        $skipped = CmsSync::skipped_ids();
        if ($skipped !== []) {
            /* translators: %s: attachment IDs */
            self::message('notice-warning', sprintf(__('Attachments skipped after 5 failed saves of changes from Tribune Desk: %s', 'tribune-desk'), implode(', ', $skipped)));
        }
        $overflow = CmsDeletions::overflow_message();
        if ($overflow !== '') {
            self::message('notice-warning', $overflow);
        }
        $warning = CmsSync::cron_warning();
        if ($warning !== '') {
            self::message('notice-warning', $warning);
        }
    }

    /** @param array<string, mixed> $settings */
    private static function render_form(array $settings): void
    {
        $variants = [
            'full'           => __('Full', 'tribune-desk'),
            'cms_to_td_only' => __('CMS → TD only', 'tribune-desk'),
            'td_to_cms_only' => __('TD → CMS only', 'tribune-desk'),
            'custom'         => __('Custom', 'tribune-desk'),
        ];

        self::message('notice-info', __('Fields, new files and deletions go through the new exchange: the feed every 5 minutes, sending one minute after a change.', 'tribune-desk'));

        // The options are shown by CSS alone ("Custom" selected), because the screen has no script.
        echo '<style>.hf-cms-options{display:none}#hf-cms-variant-custom:checked~.hf-cms-options{display:block}</style>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE) . '">';
        wp_nonce_field(self::ACTION_SAVE);
        echo '<p>' . esc_html__('Sync variant:', 'tribune-desk') . '</p>';
        foreach ($variants as $value => $label) {
            $id = 'hf-cms-variant-' . str_replace('_', '-', $value);
            echo '<input type="radio" name="hf_cms_variant" id="' . esc_attr($id) . '" value="' . esc_attr($value) . '" ' . checked($settings['variant'], $value, false) . '> ';
            echo '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><br>';
        }

        echo '<div class="hf-cms-options">';
        self::render_options($settings['options'], array_map([self::class, 'text'], $settings['locked_options']));
        echo '</div>';
        submit_button(__('Save in Tribune Desk', 'tribune-desk'), 'secondary', 'submit', false);
        echo '</form>';
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $locked
     */
    private static function render_options(array $options, array $locked): void
    {
        echo '<h3>' . esc_html__('New files', 'tribune-desk') . '</h3>';
        self::checkbox('new_files_cms_to_td', __('New files CMS → TD', 'tribune-desk'), $options, $locked);
        self::input('new_files_cms_to_td_since', 'date', __('From day (empty: no threshold)', 'tribune-desk'), self::since_day(self::text($options['new_files_cms_to_td_since'] ?? '')), $locked);
        self::input('new_files_cms_to_td_min_width', 'number', __('Minimum width in pixels (empty: no threshold)', 'tribune-desk'), self::text($options['new_files_cms_to_td_min_width'] ?? ''), $locked);
        self::checkbox('new_files_td_to_cms', __('New files TD → CMS', 'tribune-desk'), $options, $locked);
        self::checkbox('td_to_cms_editor_files', __('TD → CMS: editor files', 'tribune-desk'), $options, $locked);
        self::checkbox('td_to_cms_graphics', __('TD → CMS: graphics', 'tribune-desk'), $options, $locked);

        $labels = [
            'title'       => __('title', 'tribune-desk'),
            'alt'         => __('alt text', 'tribune-desk'),
            'caption'     => __('caption', 'tribune-desk'),
            'description' => __('description', 'tribune-desk'),
        ];
        echo '<h3>' . esc_html__('Fields', 'tribune-desk') . '</h3>';
        foreach (['fields_cms_to_td' => 'CMS → TD', 'fields_td_to_cms' => 'TD → CMS'] as $group => $direction) {
            foreach (self::FIELDS as $field) {
                self::render_field_toggle($group, $field, $direction . ': ' . $labels[$field], $options, $locked);
            }
        }

        echo '<h3>' . esc_html__('Rules', 'tribune-desk') . '</h3>';
        self::select('conflict_rule', __('Conflict', 'tribune-desk'), [
            'last_change' => __('last change', 'tribune-desk'),
            'cms'         => __('CMS wins', 'tribune-desk'),
            'td'          => __('TD wins', 'tribune-desk'),
        ], $options, $locked);
        self::checkbox('keep_nonempty_cms_to_td', __('Do not overwrite non-empty fields: CMS → TD', 'tribune-desk'), $options, $locked);
        self::checkbox('keep_nonempty_td_to_cms', __('Do not overwrite non-empty fields: TD → CMS', 'tribune-desk'), $options, $locked);
        self::select('model_texts_to_cms', __('Model texts to the CMS', 'tribune-desk'), [
            'never'      => __('never', 'tribune-desk'),
            'empty_only' => __('empty fields only', 'tribune-desk'),
        ], $options, $locked);
        self::input('mass_change_threshold', 'number', __('Mass-change threshold per run', 'tribune-desk'), self::text($options['mass_change_threshold'] ?? ''), $locked);
        self::checkbox('cms_deletion_trashes_in_td', __('Deleting in the CMS moves to the trash in TD', 'tribune-desk'), $options, $locked);
        self::checkbox('td_deletion_marks_in_cms', __('Deleting in TD marks the file in the CMS (except files used in published content)', 'tribune-desk'), $options, $locked);
        self::checkbox('keep_copies_in_td', __('Copies of new files in TD', 'tribune-desk'), $options, $locked);
    }

    /** @param list<string> $locked */
    private static function locked_attr(string $dotted, array $locked): string
    {
        return self::is_locked($dotted, $locked) ? ' disabled' : '';
    }

    /** @param list<string> $locked */
    private static function is_locked(string $dotted, array $locked): bool
    {
        return in_array($dotted, $locked, true) || in_array(strtok($dotted, '.'), $locked, true);
    }

    /**
     * Row suffixes. The browser does not send a disabled field, so a hidden marker tells the save
     * to keep the current value from Tribune Desk.
     *
     * @param list<string> $locked
     */
    private static function row_marks(string $dotted, array $locked): void
    {
        if (self::is_locked($dotted, $locked)) {
            echo '<input type="hidden" name="hf_cms_kept[]" value="' . esc_attr($dotted) . '"> ';
            echo '<em>' . esc_html__('locked by a Tribune Desk administrator', 'tribune-desk') . '</em>';
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $locked
     */
    private static function checkbox(string $name, string $label, array $options, array $locked): void
    {
        echo '<p><label><input type="checkbox" name="' . esc_attr('hf_cms[' . $name . ']') . '" value="1" '
            . checked(!empty($options[$name]), true, false) . self::locked_attr($name, $locked) . '> ' . esc_html($label) . '</label>';
        self::row_marks($name, $locked);
        echo '</p>';
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $locked
     */
    private static function render_field_toggle(string $group, string $field, string $label, array $options, array $locked): void
    {
        $dotted = $group . '.' . $field;
        echo '<p><label><input type="checkbox" name="' . esc_attr('hf_cms[' . $group . '][' . $field . ']') . '" value="1" '
            . checked(!empty($options[$group][$field]), true, false) . self::locked_attr($dotted, $locked) . '> ' . esc_html($label) . '</label>';
        self::row_marks($dotted, $locked);
        echo '</p>';
    }

    /** @param list<string> $locked */
    private static function input(string $name, string $type, string $label, string $value, array $locked): void
    {
        $min = $type === 'number' ? ' min="1"' : '';
        echo '<p><label>' . esc_html($label) . ' <input type="' . esc_attr($type) . '" name="' . esc_attr('hf_cms[' . $name . ']') . '" value="' . esc_attr($value) . '"'
            . $min . self::locked_attr($name, $locked) . '></label>';
        self::row_marks($name, $locked);
        echo '</p>';
    }

    /**
     * @param array<string, string> $choices
     * @param array<string, mixed>  $options
     * @param list<string>          $locked
     */
    private static function select(string $name, string $label, array $choices, array $options, array $locked): void
    {
        echo '<p><label>' . esc_html($label) . ' <select name="' . esc_attr('hf_cms[' . $name . ']') . '"' . self::locked_attr($name, $locked) . '>';
        foreach ($choices as $value => $text) {
            echo '<option value="' . esc_attr($value) . '" ' . selected(self::text($options[$name] ?? ''), $value, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select></label>';
        self::row_marks($name, $locked);
        echo '</p>';
    }

    /** @param array<string, mixed> $held */
    private static function render_held(array $held): void
    {
        echo '<h3>' . esc_html__('Held change batches', 'tribune-desk') . '</h3>';
        $items = is_array($held['items'] ?? null) ? $held['items'] : [];
        if ($items === []) {
            echo '<p>' . esc_html__('No held batches.', 'tribune-desk') . '</p>';
            return;
        }

        $states = [
            'pending'   => __('awaiting decision', 'tribune-desk'),
            'sent'      => __('sent, being applied in the background', 'tribune-desk'),
            'discarded' => __('discarded', 'tribune-desk'),
        ];
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
        foreach ([__('Created', 'tribune-desk'), __('Direction', 'tribune-desk'), __('Kind', 'tribune-desk'), __('Number of changes', 'tribune-desk'), __('Status', 'tribune-desk'), __('Decision', 'tribune-desk')] as $th) {
            echo '<th>' . esc_html($th) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $batch) {
            $id = is_array($batch) ? strtolower(self::text($batch['id'] ?? '')) : '';
            if (!self::is_uuid($id)) {
                continue;
            }
            $state     = self::text($batch['state'] ?? '');
            $direction = self::text($batch['direction'] ?? '');
            $kind      = self::text($batch['kind'] ?? '');
            echo '<tr><td>' . esc_html(self::local_time($batch['created_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html(['cms_to_td' => 'CMS → TD', 'td_to_cms' => 'TD → CMS'][$direction] ?? $direction) . '</td>';
            echo '<td>' . esc_html(['fields' => __('fields', 'tribune-desk'), 'files' => __('files', 'tribune-desk')][$kind] ?? $kind) . '</td>';
            echo '<td>' . (int) ($batch['count'] ?? 0) . '</td>';
            echo '<td>' . esc_html($states[$state] ?? $state) . '</td><td>';
            if ($state === 'pending') {
                self::render_held_form($id);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_held_form(string $id): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_HELD) . '">';
        echo '<input type="hidden" name="hf_cms_held_id" value="' . esc_attr($id) . '">';
        wp_nonce_field(self::ACTION_HELD . '_' . $id);
        echo '<button type="submit" class="button button-secondary" name="hf_cms_decision" value="send">' . esc_html__('Send', 'tribune-desk') . '</button> ';
        echo '<button type="submit" class="button" name="hf_cms_decision" value="discard">' . esc_html__('Discard', 'tribune-desk') . '</button>';
        echo '</form>';
    }
}
