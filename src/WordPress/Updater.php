<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hooking the plugin into the WordPress update API.
 *
 * Two paths at once, and this is deliberate:
 *  - pre_set_site_transient_update_plugins is the load-bearing path (works since WP 2.8) and it
 *    actually protects us from a slug takeover on wordpress.org — it fires last
 *    and overwrites response[$file] with its own entry, so any .org response for our
 *    slug gets covered over anyway;
 *  - update_plugins_{host} + the "Update URI" header is the documented extension point since WP 5.8.
 *    Protection against impersonation comes from the wordpress.org side here, not from core code: the .org API honors this
 *    header and does not serve updates for a plugin that declares a foreign Update URI. Core itself
 *    still sends our plugin in the request to .org (wp-includes/update.php builds the list
 *    from ALL installed plugins, before it even reaches the loop over Update URI).
 */
class Updater
{
    public const SLUG = 'headlinesforge-receiver';
    public const BOOTSTRAP_FLAG = 'headlinesforge_autoupdate_bootstrapped';

    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_update']);
        add_filter('update_plugins_' . self::manifest_host(), [self::class, 'update_uri_response'], 10, 3);
        add_filter('plugins_api', [self::class, 'plugin_information'], 10, 3);
        add_filter('upgrader_pre_download', [self::class, 'verify_package'], 10, 3);
        add_filter('auto_update_plugin', [self::class, 'auto_update_default'], 10, 2);
        add_action('admin_init', [self::class, 'bootstrap_auto_update']);
        // Priority 1: core's wp_update_plugins() hangs on the same hook with
        // priority 10 and builds the transient ANEW, but does not touch our manifest
        // cache (1 h) — we must flush BEFORE it, so that inject_update() (which
        // fires from inside it) gets to fetch a fresh manifest, not a stale one.
        add_action('load-update-core.php', [self::class, 'flush_manifest_on_force_check'], 1);
    }

    /**
     * "Check again" on the Dashboard -> Updates screen is the only place in
     * which the README promises the customer a fresh result "on demand". Core refreshes
     * ITS OWN transient after ?force-check=1, but inject_update() still reads our
     * OWN manifest cache (up to 1 h) — without this flush the button would lie.
     *
     * No nonce is deliberate: flushing the cache is harmless (at most one
     * extra request to R2), and core itself acts on the same parameter without
     * verifying a nonce at this point.
     */
    public static function flush_manifest_on_force_check(): void
    {
        if (!empty($_GET['force-check'])) {
            UpdateManifest::flush();
        }
    }

    public static function basename(): string
    {
        // Computed locally, NOT taken from the manifest: an install in an oddly named
        // directory must work the same way.
        return plugin_basename(HEADLINESFORGE_PLUGIN_FILE);
    }

    private static function manifest_host(): string
    {
        return (string) wp_parse_url(UpdateManifest::MANIFEST_URL, PHP_URL_HOST);
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    public static function inject_update($transient)
    {
        $manifest = UpdateManifest::get();

        if ($manifest === null) {
            return $transient;
        }

        if (!is_object($transient)) {
            $transient = new \stdClass();
        }

        $file    = self::basename();
        $payload = self::payload($manifest);

        if (version_compare((string) $manifest['version'], HEADLINESFORGE_VERSION, '>')) {
            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }
            $transient->response[$file] = (object) $payload;
            unset($transient->no_update[$file]);

            return $transient;
        }

        // There is no newer version — but an entry in no_update MUST exist. Without it core
        // sets update-supported => false and turns off both the auto-update toggle
        // and the "View details" link.
        $payload['version']     = HEADLINESFORGE_VERSION;
        $payload['new_version'] = HEADLINESFORGE_VERSION;
        $payload['package']     = '';

        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }
        $transient->no_update[$file] = (object) $payload;
        unset($transient->response[$file]);

        return $transient;
    }

    /**
     * @param mixed                $update
     * @param array<string, mixed> $plugin_data
     * @return mixed
     */
    public static function update_uri_response($update, array $plugin_data = [], string $plugin_file = '')
    {
        if ($plugin_file !== self::basename()) {
            return $update;
        }

        $manifest = UpdateManifest::get();

        if ($manifest === null) {
            return $update;
        }

        if (!version_compare((string) $manifest['version'], HEADLINESFORGE_VERSION, '>')) {
            return $update;
        }

        return self::payload($manifest);
    }

    /**
     * "View details" screen in the plugin list (plugins_api filter, WP 2.7+).
     *
     * Without this core would ask wordpress.org about our slug, which knows nothing about it —
     * the modal would be empty. We answer ONLY for our own slug and the action
     * plugin_information; for everything else $result is returned untouched, so as
     * not to break the plugin browser for the rest of the install.
     *
     * @param mixed $result
     * @param mixed $args
     * @return mixed
     */
    public static function plugin_information($result, string $action = '', $args = null)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!is_object($args) || !isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }

        // force=true: this is a screen the user reaches DELIBERATELY, wanting to
        // see current data (changelog, versions) — serving them hours-old
        // cache here would be confusing, and clicks are rare, so an
        // extra request costs nothing.
        $manifest = UpdateManifest::get(true);

        if ($manifest === null) {
            return $result;
        }

        $info = new \stdClass();

        $info->name          = (string) ($manifest['name'] ?? 'Tribune Desk');
        $info->slug          = self::SLUG;
        $info->version       = (string) $manifest['version'];
        $info->author        = (string) ($manifest['author'] ?? '');
        $info->homepage      = (string) ($manifest['homepage'] ?? '');
        $info->requires      = (string) ($manifest['requires'] ?? '');
        $info->tested        = (string) ($manifest['tested'] ?? '');
        $info->requires_php  = (string) ($manifest['requires_php'] ?? '');
        $info->last_updated  = (string) ($manifest['last_updated'] ?? '');
        $info->download_link = (string) $manifest['package'];
        $info->trunk         = (string) $manifest['package'];
        $info->sections      = array_map('strval', (array) ($manifest['sections'] ?? []));
        $info->banners       = [];
        $info->icons         = [];

        return $info;
    }

    /**
     * sha256 verification BEFORE unpacking.
     *
     * The hook is 'upgrader_pre_download', not 'upgrader_pre_install', because the latter
     * fires AFTER download and unpacking — there is no ZIP then and nothing to
     * hash. The returned WP_Error aborts the update with a readable message
     * and leaves the installed version untouched.
     *
     * We determine the package owner BY HOST, regardless of whether the manifest can
     * be fetched at the moment — otherwise a brief R2 outage (or the hourly error cache
     * in UpdateManifest) would disable the whole verification gate instead of stopping
     * the install. No host (a manually uploaded ZIP, a local path) is OUR signal
     * "this is not our download" — upgrader_pre_download fires BEFORE the core
     * branch handling local files, so this path must work even when R2
     * is down.
     *
     * @param mixed       $reply
     * @param string|null $package  Core documents this as a string, but
     *                              download_package() calls this filter BEFORE
     *                              its own empty($package) — an entry of another plugin
     *                              with package => null (e.g. plugin-update-checker
     *                              without a license) arrives here as null.
     * @param mixed       $upgrader
     * @return mixed
     */
    public static function verify_package($reply, $package = '', $upgrader = null)
    {
        if ($reply !== false) {
            return $reply;
        }

        // Normalization BEFORE use: without it a non-string $package (see the
        // parameter description above) would blow up on wp_parse_url() or a TypeError before
        // we even got to the host check.
        $package = is_string($package) ? $package : '';

        $package_host = $package !== '' ? (string) wp_parse_url($package, PHP_URL_HOST) : '';

        if ($package_host === '' || strtolower($package_host) !== strtolower(self::manifest_host())) {
            return $reply;
        }

        // We force a fresh manifest (force=true): the error cache in UpdateManifest lives
        // up to an hour, and refusing an update because of a stale error would be a bad
        // decision at the moment someone manually clicks "Install now". One
        // extra request on such a click is a low price.
        $manifest = UpdateManifest::get(true);

        if ($manifest === null) {
            return new \WP_Error(
                'headlinesforge_manifest_unavailable',
                sprintf(
                    /* translators: %s: installed plugin version */
                    __(
                        'Tribune Desk manifest could not be fetched, so the package could not be verified. The update was aborted, version %s remains installed. Try again later.',
                        'tribune-desk'
                    ),
                    HEADLINESFORGE_VERSION
                )
            );
        }

        if ($package !== $manifest['package']) {
            return new \WP_Error(
                'headlinesforge_package_mismatch',
                sprintf(
                    /* translators: %s: installed plugin version */
                    __(
                        'The Tribune Desk manifest points to a different package than the one WordPress is trying to download — the update list is stale. Refresh it (Dashboard -> Updates -> "Check again") and try again. The update was aborted, version %s remains installed.',
                        'tribune-desk'
                    ),
                    HEADLINESFORGE_VERSION
                )
            );
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $file = download_url($package, 300);

        if (is_wp_error($file)) {
            return $file;
        }

        $actual = (string) hash_file('sha256', $file);

        if (hash_equals(strtolower((string) $manifest['sha256']), strtolower($actual))) {
            return $file;
        }

        @unlink($file);

        return new \WP_Error(
            'headlinesforge_checksum_mismatch',
            sprintf(
                /* translators: %s: installed plugin version */
                __(
                    'The checksum of the downloaded Tribune Desk package does not match the manifest. The update was aborted, version %s remains installed.',
                    'tribune-desk'
                ),
                HEADLINESFORGE_VERSION
            )
        );
    }

    /**
     * Single source of truth: the site option 'auto_update_plugins' — exactly the same one
     * that the core plugin list uses. We keep no copy of our own, so there is no
     * way for it to drift apart.
     */
    public static function is_auto_update_enabled(): bool
    {
        return in_array(self::basename(), (array) get_site_option('auto_update_plugins', []), true);
    }

    public static function set_auto_update(bool $on): void
    {
        $enabled = array_values(
            array_diff((array) get_site_option('auto_update_plugins', []), [self::basename()])
        );

        if ($on) {
            $enabled[] = self::basename();
        }

        update_site_option('auto_update_plugins', $enabled);
    }

    /**
     * Enables auto-updates EXACTLY ONCE, on the first admin_init after installation.
     *
     * The flag is set BEFORE changing the option, so that a failed write does not turn this
     * into a loop. The hook is admin_init, not register_activation_hook, because updating
     * an active plugin (and overwriting via upload) does NOT fire the activation hook.
     */
    public static function bootstrap_auto_update(): void
    {
        if (get_option(self::BOOTSTRAP_FLAG)) {
            return;
        }

        update_option(self::BOOTSTRAP_FLAG, '1', false);

        if (!self::is_auto_update_enabled()) {
            self::set_auto_update(true);
        }
    }

    /**
     * @param mixed $update
     * @param mixed $item
     * @return mixed
     */
    public static function auto_update_default($update, $item = null)
    {
        $plugin = is_object($item) && isset($item->plugin) ? (string) $item->plugin : '';

        return self::resolve_auto_update($update, $plugin, (string) get_bloginfo('version'));
    }

    /**
     * Fallback only for cores < 5.5, which do not know the auto_update_plugins option.
     *
     * On 5.5+ we return $update UNCHANGED. Returning anything non-null makes
     * wp_is_auto_update_forced_for_item() treat the value as forced, and core
     * will render a dead label instead of a working toggle.
     *
     * Decided by the core VERSION NUMBER, not by function_exists('wp_is_auto_update_enabled_for_type'):
     * that function lives in wp-admin/includes/update.php, so in a context without
     * wp-admin (and in tests) it does not exist even on WP 7.1 — the marker would then say
     * "old core" on the newest WordPress.
     *
     * @param mixed $update
     * @return mixed
     */
    public static function resolve_auto_update($update, string $plugin, string $wp_version)
    {
        if ($plugin !== self::basename()) {
            return $update;
        }

        if (version_compare($wp_version, '5.5', '>=')) {
            return $update;
        }

        return self::is_auto_update_enabled();
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private static function payload(array $manifest): array
    {
        return [
            'id'           => UpdateManifest::MANIFEST_URL,
            'slug'         => self::SLUG,
            'plugin'       => self::basename(),
            // 'version' is required by core for update_plugins_{host}: without it
            // wp-includes/update.php rejects the whole payload as invalid (isset($update->version)).
            // 'new_version' stays separate, because the classic pre_set_site_transient path
            // reads that field, not 'version'.
            'version'      => (string) $manifest['version'],
            'new_version'  => (string) $manifest['version'],
            'url'          => (string) ($manifest['homepage'] ?? ''),
            'package'      => (string) $manifest['package'],
            'icons'        => [],
            'banners'      => [],
            'banners_rtl'  => [],
            'requires'     => (string) ($manifest['requires'] ?? ''),
            'requires_php' => (string) ($manifest['requires_php'] ?? ''),
            'tested'       => (string) ($manifest['tested'] ?? ''),
        ];
    }
}
