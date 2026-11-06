<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\WebhookSignature;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    public const PAGE_SLUG = 'headlinesforge-receiver';

    // Option name constants live on RestEndpoint (the actual consumer). Aliased
    // here for backwards compatibility — Settings::OPTION_SECRET still resolves.
    public const OPTION_SECRET = RestEndpoint::OPTION_SECRET;

    /** Checkbox name (not an option): removes the stored signing secret on save. */
    public const FIELD_REMOVE_SIGNING_SECRET = 'headlinesforge_remove_signing_secret';
    public const OPTION_DEFAULT_AUTHOR = RestEndpoint::OPTION_DEFAULT_AUTHOR;
    public const OPTION_PUBLISH = RestEndpoint::OPTION_PUBLISH;
    public const OPTION_AUTO_UPDATE = 'headlinesforge_auto_update';
    public const OPTION_API_BASE = PublishedUrlsClient::OPTION_API_BASE;
    public const OPTION_AUTHOR_MAP = AuthorResolver::OPTION_MAP;

    public static function register_menu(): void
    {
        add_options_page(
            __('Tribune Desk', 'tribune-desk'),
            'Tribune Desk',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting(self::PAGE_SLUG, self::OPTION_SECRET, [
            'type'              => 'string',
            'sanitize_callback' => self::sanitize_secret(self::OPTION_SECRET),
            'default'           => '',
        ]);

        register_setting(self::PAGE_SLUG, self::OPTION_DEFAULT_AUTHOR, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);

        register_setting(self::PAGE_SLUG, self::OPTION_AUTHOR_MAP, [
            'type'              => 'array',
            'sanitize_callback' => [AuthorResolver::class, 'sanitize_map'],
            'default'           => [],
        ]);

        register_setting(self::PAGE_SLUG, self::OPTION_PUBLISH, [
            'type'              => 'string',
            'sanitize_callback' => static fn($v) => $v ? '1' : '',
            'default'           => '',
        ]);

        register_setting(self::PAGE_SLUG, self::OPTION_AUTO_UPDATE, [
            'type'              => 'string',
            'sanitize_callback' => [self::class, 'sanitize_auto_update'],
            'default'           => '',
        ]);

        register_setting(self::PAGE_SLUG, self::OPTION_API_BASE, [
            'type'              => 'string',
            'sanitize_callback' => [self::class, 'sanitize_api_base'],
            'default'           => PublishedUrlsClient::DEFAULT_API_BASE,
        ]);

        register_setting(self::PAGE_SLUG, CmsApiClient::OPTION_TOKEN, [
            'type'              => 'string',
            'sanitize_callback' => self::sanitize_secret(CmsApiClient::OPTION_TOKEN),
            'default'           => '',
        ]);

        register_setting(self::PAGE_SLUG, RestEndpoint::OPTION_SIGNING_SECRET, [
            'type'              => 'string',
            'sanitize_callback' => self::sanitize_secret(RestEndpoint::OPTION_SIGNING_SECRET),
            'default'           => '',
        ]);

        register_setting(self::PAGE_SLUG, PostWriter::OPTION_EXCERPT_FROM_TEASER, [
            'type'              => 'string',
            'sanitize_callback' => static fn($v) => $v ? '1' : '',
            'default'           => '',
        ]);

        foreach ([SeoMeta::OPTION_DESC_KEY, SeoMeta::OPTION_FB_TITLE_KEY, SocialQueueFlag::OPTION] as $option) {
            register_setting(self::PAGE_SLUG, $option, [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitize_meta_key'],
                'default'           => '',
            ]);
        }

        add_settings_section(
            'headlinesforge_main',
            __('Webhook settings', 'tribune-desk'),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            self::OPTION_SECRET,
            __('Webhook secret', 'tribune-desk'),
            [self::class, 'render_secret_field'],
            self::PAGE_SLUG,
            'headlinesforge_main'
        );

        add_settings_field(
            RestEndpoint::OPTION_SIGNING_SECRET,
            __('Signing secret (whsec_…)', 'tribune-desk'),
            [self::class, 'render_signing_secret_field'],
            self::PAGE_SLUG,
            'headlinesforge_main'
        );

        add_settings_field(
            self::OPTION_DEFAULT_AUTHOR,
            __('Default author', 'tribune-desk'),
            [self::class, 'render_author_field'],
            self::PAGE_SLUG,
            'headlinesforge_main'
        );

        add_settings_field(
            self::OPTION_PUBLISH,
            __('Publish immediately', 'tribune-desk'),
            [self::class, 'render_publish_field'],
            self::PAGE_SLUG,
            'headlinesforge_main'
        );

        add_settings_section(
            'headlinesforge_authors',
            __('Authors from Tribune Desk', 'tribune-desk'),
            [self::class, 'render_authors_section'],
            self::PAGE_SLUG
        );

        add_settings_section(
            'headlinesforge_updates',
            __('Updates', 'tribune-desk'),
            [self::class, 'render_updates_intro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            self::OPTION_AUTO_UPDATE,
            __('Automatic updates', 'tribune-desk'),
            [self::class, 'render_auto_update_field'],
            self::PAGE_SLUG,
            'headlinesforge_updates'
        );

        add_settings_section(
            'headlinesforge_api',
            __('Tribune Desk API', 'tribune-desk'),
            [self::class, 'render_api_intro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            self::OPTION_API_BASE,
            __('Tribune Desk API address', 'tribune-desk'),
            [self::class, 'render_api_base_field'],
            self::PAGE_SLUG,
            'headlinesforge_api'
        );

        add_settings_field(
            CmsApiClient::OPTION_TOKEN,
            __('CMS API token', 'tribune-desk'),
            [self::class, 'render_cms_token_field'],
            self::PAGE_SLUG,
            'headlinesforge_api'
        );

        add_settings_section(
            'headlinesforge_theme_fields',
            __('Theme fields', 'tribune-desk'),
            [self::class, 'render_theme_fields_intro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            SeoMeta::OPTION_DESC_KEY,
            __('Meta description field', 'tribune-desk'),
            [self::class, 'render_desc_key_field'],
            self::PAGE_SLUG,
            'headlinesforge_theme_fields'
        );

        add_settings_field(
            SeoMeta::OPTION_FB_TITLE_KEY,
            __('Facebook title field', 'tribune-desk'),
            [self::class, 'render_fb_title_key_field'],
            self::PAGE_SLUG,
            'headlinesforge_theme_fields'
        );

        add_settings_field(
            PostWriter::OPTION_EXCERPT_FROM_TEASER,
            __('Excerpt from Facebook teaser', 'tribune-desk'),
            [self::class, 'render_excerpt_from_teaser_field'],
            self::PAGE_SLUG,
            'headlinesforge_theme_fields'
        );

        add_settings_field(
            SocialQueueFlag::OPTION,
            __('CMS field: do not publish on Facebook', 'tribune-desk'),
            [self::class, 'render_social_exclude_key_field'],
            self::PAGE_SLUG,
            'headlinesforge_theme_fields'
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php $result = self::render_backfill_notice(); ?>
            <?php CmsSettingsScreen::render_notice(); ?>
            <?php
            // Addresses are built FROM CONSTANTS, not typed as a string. Before, this held
            // 'headlinesforge/v1/receive' literally, next to constants describing the same thing —
            // a second definition of the same thing that can silently drift apart.
            ?>
            <p><?php esc_html_e('Endpoint URL:', 'tribune-desk'); ?>
                <code><?php echo esc_url(
                    rest_url(RestEndpoint::NAMESPACE_BRAND . RestEndpoint::ROUTE)
                ); ?></code>
            </p>
            <p class="description">
                <?php esc_html_e('The previous address still works and needs no change:', 'tribune-desk'); ?>
                <code><?php echo esc_url(
                    rest_url(RestEndpoint::NAMESPACE . RestEndpoint::ROUTE)
                ); ?></code>
            </p>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::PAGE_SLUG);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
            <?php self::render_backfill_block($result['remaining'] ?? null); ?>
            <?php CmsSettingsScreen::render_block(); ?>
            <?php self::render_recent_log(); ?>
        </div>
        <?php
    }

    /**
     * Sanitize for a secret field: an empty submit keeps the stored value, so the empty password
     * input never wipes a working configuration. The signing secret must also be a valid
     * whsec_ secret; otherwise the old value stays and the page shows an error.
     */
    public static function sanitize_secret(string $option): callable
    {
        return static function ($value) use ($option): string {
            // The signing secret alone can be removed from the page: pasted before Tribune Desk
            // signs, it would refuse every delivery, and most site admins have no WP-CLI.
            if ($option === RestEndpoint::OPTION_SIGNING_SECRET && !empty($_POST[self::FIELD_REMOVE_SIGNING_SECRET])) {
                return '';
            }
            $value = trim(sanitize_text_field((string) $value));
            $old   = (string) get_option($option, '');
            if ($value === '') {
                return $old;
            }
            if ($option === RestEndpoint::OPTION_SIGNING_SECRET && !WebhookSignature::is_valid_secret($value)) {
                add_settings_error(
                    $option,
                    'invalid_signing_secret',
                    __('The signing secret must start with whsec_ and be copied in full. The previous value was kept.', 'tribune-desk')
                );

                return $old;
            }

            return $value;
        };
    }

    /**
     * A password input that never prints the stored value; below it, the last characters of the
     * saved value so the editor can tell which one is set.
     */
    private static function render_password_input(string $option): void
    {
        $stored = (string) get_option($option, '');
        echo '<input type="password" class="regular-text" name="' . esc_attr($option) . '" value="" autocomplete="new-password">';
        if ($stored !== '') {
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %s: last characters of the saved secret */
                __('Saved (…%s). Leave the field empty to keep it unchanged.', 'tribune-desk'),
                mb_substr($stored, -4)
            )) . '</p>';
        }
    }

    public static function render_secret_field(): void
    {
        self::render_password_input(self::OPTION_SECRET);
    }

    public static function render_signing_secret_field(): void
    {
        self::render_password_input(RestEndpoint::OPTION_SIGNING_SECRET);
        if ((string) get_option(RestEndpoint::OPTION_SIGNING_SECRET, '') !== '') {
            echo '<p><label><input type="checkbox" name="' . esc_attr(self::FIELD_REMOVE_SIGNING_SECRET) . '" value="1"> '
                . esc_html__('Remove the saved signing secret (deliveries are checked with the webhook secret again)', 'tribune-desk')
                . '</label></p>';
        }
        echo '<p class="description">' . esc_html__('Signing secret of deliveries from Tribune Desk. When set, the plugin accepts only deliveries with a valid signature, and the webhook secret is no longer checked.', 'tribune-desk') . '</p>';
    }

    public static function render_author_field(): void
    {
        $value = (int) get_option(self::OPTION_DEFAULT_AUTHOR, 0);
        $users = get_users(['role__in' => ['administrator', 'editor']]);
        echo '<select name="' . esc_attr(self::OPTION_DEFAULT_AUTHOR) . '">';
        echo '<option value="0">' . esc_html__('— use plugin installer as fallback —', 'tribune-desk') . '</option>';
        foreach ($users as $u) {
            $sel = selected($value, (int) $u->ID, false);
            echo '<option value="' . (int) $u->ID . '" ' . $sel . '>' . esc_html($u->display_name . ' (' . $u->user_login . ')') . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Author of posts that cannot be matched to a person from Tribune Desk (see the “Authors from Tribune Desk” section).', 'tribune-desk') . '</p>';
    }

    /**
     * Table of people from Tribune Desk and WP accounts (spec HF 2026-10-20 post author, D5). The
     * table also holds people with a manual assignment who dropped off the seen list — otherwise
     * saving the form (options.php saves the whole option) would silently delete their assignment.
     */
    public static function render_authors_section(): void
    {
        echo '<p>' . esc_html__(
            'The post author is the person who clicked “Send to CMS” in Tribune Desk or, if that person cannot be matched, the article author in Tribune Desk. The plugin matches a WordPress account with the same email address by itself; here you can assign an account manually. When nobody can be matched, the author is the “Default author”. The author of published posts does not change.',
            'tribune-desk'
        ) . '</p>';

        $seen = AuthorResolver::seen();
        $map = AuthorResolver::map();
        foreach (array_keys($map) as $hf_id) {
            $seen[$hf_id] ??= ['name' => '', 'email' => '', 'last_seen' => 0];
        }

        if ($seen === []) {
            echo '<p class="description">' . esc_html__(
                'The list appears after the first delivery from Tribune Desk that carries an author.',
                'tribune-desk'
            ) . '</p>';
            return;
        }

        $users = AuthorResolver::eligible_users();
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
        foreach ([__('Person in Tribune Desk', 'tribune-desk'), __('Email', 'tribune-desk'), __('WordPress account', 'tribune-desk'), __('Author now set to', 'tribune-desk')] as $th) {
            echo '<th>' . esc_html($th) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($seen as $hf_id => $person) {
            $hf_id = (string) $hf_id;
            $name = (string) ($person['name'] ?? '');
            $email = (string) ($person['email'] ?? '');
            $current = $map[$hf_id] ?? 0;
            echo '<tr><td>' . esc_html($name !== '' ? $name : $hf_id) . '</td>';
            echo '<td>' . esc_html($email) . '</td><td>';
            echo '<select name="' . esc_attr(self::OPTION_AUTHOR_MAP . '[' . $hf_id . ']') . '">';
            echo '<option value="0">' . esc_html__('— automatic (by email) —', 'tribune-desk') . '</option>';
            foreach ($users as $u) {
                echo '<option value="' . (int) $u->ID . '" ' . selected($current, (int) $u->ID, false) . '>'
                    . esc_html($u->display_name . ' (' . $u->user_login . ')') . '</option>';
            }
            echo '</select></td><td>';
            $found = AuthorResolver::match_with_reason(['id' => $hf_id, 'name' => $name, 'email' => $email]);
            if ($found === null) {
                echo esc_html__('Default author (no match)', 'tribune-desk');
            } else {
                $user = get_user_by('id', $found['user_id']);
                $label = $user !== false ? $user->display_name : '#' . $found['user_id'];
                $via = $found['via'] === 'map'
                    ? __('assigned manually', 'tribune-desk')
                    : __('same email', 'tribune-desk');
                echo esc_html($label . ' — ' . $via);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function render_publish_field(): void
    {
        $checked = get_option(self::OPTION_PUBLISH, '') === '1';
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_PUBLISH) . '" value="1" ' . checked($checked, true, false) . '> ';
        echo esc_html__('Publish received articles immediately instead of saving them as drafts.', 'tribune-desk');
        echo '</label>';
    }

    public static function render_updates_intro(): void
    {
        printf(
            '<p>%s <code>%s</code></p>',
            esc_html__('Installed version:', 'tribune-desk'),
            esc_html(HEADLINESFORGE_VERSION)
        );
    }

    /**
     * The form carries the value, but the STATE is only the core option
     * 'auto_update_plugins'. We never read a copy of our own — so this screen
     * and the plugins list cannot show two different things.
     *
     * @param mixed $value
     */
    public static function sanitize_auto_update($value): string
    {
        $on = ($value === '1' || $value === 1 || $value === true);

        Updater::set_auto_update($on);

        return $on ? '1' : '';
    }

    public static function render_auto_update_field(): void
    {
        $checked = Updater::is_auto_update_enabled();

        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_AUTO_UPDATE) . '" value="1" '
            . checked($checked, true, false) . '> ';
        echo esc_html__(
            'Install new plugin versions automatically.',
            'tribune-desk'
        );
        echo '</label>';
        echo '<p class="description">' . esc_html__(
            'The same setting as "Enable auto-updates" for this plugin on the Plugins list. A change in one place shows in the other.',
            'tribune-desk'
        ) . '</p>';
    }

    /**
     * https-only, because the webhook secret travels in a header to this address. A bad address is not
     * saved — the previous (or default) one stays and a settings error appears.
     *
     * @param mixed $value
     */
    public static function sanitize_api_base($value): string
    {
        $previous = (string) get_option(self::OPTION_API_BASE, PublishedUrlsClient::DEFAULT_API_BASE);
        $fallback = $previous !== '' ? $previous : PublishedUrlsClient::DEFAULT_API_BASE;

        $url   = rtrim(esc_url_raw(trim((string) $value)), '/');
        $parts = $url !== '' ? wp_parse_url($url) : false;

        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            if (function_exists('add_settings_error')) {
                add_settings_error(
                    self::OPTION_API_BASE,
                    'headlinesforge_api_base_invalid',
                    __('The Tribune Desk API address must start with https:// and include a host name — the previous value was kept.', 'tribune-desk')
                );
            }

            return $fallback;
        }

        return $url;
    }

    public static function render_api_intro(): void
    {
        echo '<p>' . esc_html__(
            'The address the plugin sends published post URLs back to (return contract). Change it only when Tribune Desk asks you to.',
            'tribune-desk'
        ) . '</p>';
    }

    public static function render_api_base_field(): void
    {
        $value = (string) get_option(self::OPTION_API_BASE, PublishedUrlsClient::DEFAULT_API_BASE);
        if ($value === '') {
            $value = PublishedUrlsClient::DEFAULT_API_BASE;
        }
        echo '<input type="url" class="regular-text code" name="' . esc_attr(self::OPTION_API_BASE) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr(PublishedUrlsClient::DEFAULT_API_BASE) . '">';
        echo '<p class="description">' . esc_html__('https:// required. Default:', 'tribune-desk') . ' <code>' . esc_html(PublishedUrlsClient::DEFAULT_API_BASE) . '</code></p>';
    }

    public static function render_cms_token_field(): void
    {
        self::render_password_input(CmsApiClient::OPTION_TOKEN);
        echo '<p class="description">' . esc_html__('Token from Tribune Desk for the link back and the media sync. Required permissions: articles:write, settings:read, settings:write, media:read, media:write, backfill:write. Without a token the existing sync keeps working.', 'tribune-desk') . '</p>';
    }

    /**
     * Meta key of a theme field: letters, digits, "_" and "-", up to 191 characters (the length of the
     * meta_key column). Anything else = empty, meaning the field is switched off.
     *
     * @param mixed $value
     */
    public static function sanitize_meta_key($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $key = trim($value);

        return preg_match('/^[A-Za-z0-9_-]{1,191}$/', $key) === 1 ? $key : '';
    }

    public static function render_theme_fields_intro(): void
    {
        echo '<p>' . esc_html__(
            'Optional: meta keys of theme fields the plugin copies the meta description and Facebook title from Tribune Desk into (next to the Yoast fields). An empty field means no copying. Only an empty post field is filled, so manual edits stay.',
            'tribune-desk'
        ) . '</p>';
    }

    public static function render_desc_key_field(): void
    {
        self::render_meta_key_input(SeoMeta::OPTION_DESC_KEY);
    }

    public static function render_fb_title_key_field(): void
    {
        self::render_meta_key_input(SeoMeta::OPTION_FB_TITLE_KEY);
    }

    public static function render_excerpt_from_teaser_field(): void
    {
        $checked = get_option(PostWriter::OPTION_EXCERPT_FROM_TEASER, '') === '1';
        echo '<label><input type="checkbox" name="' . esc_attr(PostWriter::OPTION_EXCERPT_FROM_TEASER) . '" value="1" ' . checked($checked, true, false) . '> ';
        echo esc_html__('Write the Facebook teaser from Tribune Desk into an empty post excerpt.', 'tribune-desk');
        echo '</label>';
    }

    public static function render_social_exclude_key_field(): void
    {
        self::render_meta_key_input(SocialQueueFlag::OPTION);
        echo '<p class="description">' . esc_html__(
            'Meta key of the post field that says "do not publish on Facebook". When set, every report of a published post tells Tribune Desk whether to keep the article out of its Facebook queue: 1, true, yes or on mean yes, any other value or no value means no. A change of the field on a published post is reported again. Empty: nothing is sent.',
            'tribune-desk'
        ) . '</p>';
    }

    private static function render_meta_key_input(string $option): void
    {
        $value = self::sanitize_meta_key(get_option($option, ''));
        echo '<input type="text" class="regular-text code" name="' . esc_attr($option) . '" value="' . esc_attr($value) . '">';
    }

    /** A separate form to admin-post.php — outside the options.php form, because it is an action, not a setting. */
    private static function render_backfill_block(?int $known_remaining = null): void
    {
        $remaining = $known_remaining ?? Backfill::remaining();
        $cursor    = Backfill::cursor();

        echo '<h2>' . esc_html__('Published post URLs', 'tribune-desk') . '</h2>';
        echo '<p>' . esc_html__(
            'Sends Tribune Desk the URLs of posts published before this version was installed (and of all whose URL did not arrive). In batches of 100 — click again if there are more.',
            'tribune-desk'
        ) . '</p>';
        /* translators: %d: number of posts */
        echo '<p>' . esc_html(sprintf(__('Posts to send: %d', 'tribune-desk'), $remaining)) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(Backfill::ACTION) . '">';
        wp_nonce_field(Backfill::ACTION);
        $label = $cursor > 0
            ? __('Send the next 100', 'tribune-desk')
            : __('Send published post URLs to Tribune Desk', 'tribune-desk');
        submit_button($label, 'secondary', 'submit', false, $remaining === 0 ? ['disabled' => 'disabled'] : []);
        echo '</form>';
    }

    /** @return array<string, mixed>|null result of the run, if there was one — passed on so the counter under the form does not contradict the message */
    private static function render_backfill_notice(): ?array
    {
        $result = Backfill::take_result();
        if ($result === null) {
            return null;
        }
        $status    = (string) ($result['status'] ?? '');
        $sent      = (int) ($result['sent'] ?? 0);
        $remaining = (int) ($result['remaining'] ?? 0);
        $detail    = (string) ($result['detail'] ?? '');

        if ($status === PublishedUrlsClient::STATUS_SENT) {
            $class = 'notice-success';
            /* translators: 1: number sent, 2: counts per status, 3: number remaining */
            $text = sprintf(__('Sent %1$d URLs (%2$s). %3$d remaining.', 'tribune-desk'), $sent, $detail, $remaining);
            if ($remaining > 0) {
                $text .= ' ' . __('Click again to send the next batch.', 'tribune-desk');
            }
        } elseif ($status === PublishedUrlsClient::STATUS_NOTHING_TO_SEND) {
            $class = 'notice-info';
            $text  = $detail;
        } else {
            $class = 'notice-error';
            /* translators: 1: status, 2: details */
            $text = sprintf(__('Sending failed (%1$s): %2$s', 'tribune-desk'), $status, $detail);
        }

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';

        return $result;
    }

    private static function kind_label(string $kind): string
    {
        return match ($kind) {
            DeliveriesLog::KIND_RECEIVE  => esc_html__('received', 'tribune-desk'),
            DeliveriesLog::KIND_NOTIFY   => esc_html__('URL sent', 'tribune-desk'),
            DeliveriesLog::KIND_BACKFILL => esc_html__('backfill', 'tribune-desk'),
            DeliveriesLog::KIND_BACKLINKS => esc_html__('backlinks', 'tribune-desk'),
            DeliveriesLog::KIND_MEDIA    => esc_html__('Images to media library', 'tribune-desk'),
            default                      => esc_html($kind),
        };
    }

    private static function render_recent_log(): void
    {
        $rows = DeliveriesLog::recent();
        if ($rows === []) {
            return;
        }
        echo '<h2>' . esc_html__('Recent deliveries', 'tribune-desk') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Time', 'tribune-desk') . '</th>';
        echo '<th>' . esc_html__('Type', 'tribune-desk') . '</th>';
        echo '<th>' . esc_html__('Article ID', 'tribune-desk') . '</th>';
        echo '<th>' . esc_html__('Post ID', 'tribune-desk') . '</th>';
        echo '<th>' . esc_html__('Status', 'tribune-desk') . '</th>';
        echo '<th>' . esc_html__('Details', 'tribune-desk') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $kind = (string) ($row['kind'] ?? DeliveriesLog::KIND_RECEIVE);
            echo '<tr>';
            echo '<td>' . esc_html($row['received_at']) . '</td>';
            echo '<td>' . self::kind_label($kind) . '</td>';
            echo '<td>' . esc_html($row['article_id']) . '</td>';
            if ($row['post_id']) {
                $edit = get_edit_post_link((int) $row['post_id'], 'raw');
                echo '<td><a href="' . esc_url((string) $edit) . '">' . (int) $row['post_id'] . '</a></td>';
            } else {
                echo '<td>—</td>';
            }
            echo '<td>' . esc_html($row['status']) . '</td>';
            echo '<td>' . esc_html((string) $row['error_msg']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}