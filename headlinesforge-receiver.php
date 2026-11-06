<?php
/**
 * Plugin Name: Tribune Desk
 * Plugin URI:  https://github.com/Tribunedesk/tribunedesk-wordpress
 * Update URI: https://updates.headlinesforge.com/wp-plugin/manifest.json
 * Description: Receives finished articles from Tribune Desk (ArticleWriter) and creates them as drafts or publishes them immediately.
 * Version:           2.1.0
 * Requires at least: 5.6
 * Requires PHP: 8.3
 * Author:      Dominik Czarnota
 * License:     MIT
 * Text Domain: tribune-desk
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('HEADLINESFORGE_VERSION', '2.1.0');
define('HEADLINESFORGE_PLUGIN_FILE', __FILE__);
define('HEADLINESFORGE_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Classes load on demand from src/ (TribuneDesk\<Dir>\<Class>); 1.x names are aliases.
require_once HEADLINESFORGE_PLUGIN_DIR . 'src/autoload.php';

/** Translations: English strings in code, other languages in languages/ (Polish today). */
function tribune_desk_load_textdomain(): void
{
    load_plugin_textdomain('tribune-desk', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'tribune_desk_load_textdomain');

register_activation_hook(__FILE__, [\TribuneDesk\WordPress\Activator::class, 'activate']);
// Priority 1: the schema migration must run before anything can write a delivery row.
add_action('plugins_loaded', [\TribuneDesk\WordPress\Activator::class, 'maybe_upgrade'], 1);
add_action('plugins_loaded', [\TribuneDesk\WordPress\Activator::class, 'clear_removed_crons'], 1);

add_action('rest_api_init', [\TribuneDesk\WordPress\RestEndpoint::class, 'register_routes']);
add_action('init', [\TribuneDesk\WordPress\ReadMore::class, 'register']);
add_action('wp_head', [\TribuneDesk\WordPress\ProvenanceMeta::class, 'render']);
add_filter('wpseo_schema_article', [\TribuneDesk\WordPress\ProvenanceMeta::class, 'yoast_article']);
add_action('admin_menu', [\TribuneDesk\WordPress\Settings::class, 'register_menu']);
add_action('admin_init', [\TribuneDesk\WordPress\Settings::class, 'register_settings']);

add_filter('wp_insert_post_data', [\TribuneDesk\WordPress\LinkResolver::class, 'filter_post_data'], 10, 2);
// Content first, then the callback to HF (PublishNotifier, wp_after_insert_post).
add_action('transition_post_status', [\TribuneDesk\WordPress\LinkResolver::class, 'on_transition'], 10, 3);
// Local write of link rows before the 5-second HTTP callback from PublishNotifier.
add_action('transition_post_status', [\TribuneDesk\WordPress\Backlinks::class, 'on_transition'], 15, 3);
// 2.1.0: the callback goes after the save (wp_after_insert_post), when meta from save_post is stored.
add_action('wp_after_insert_post', [\TribuneDesk\WordPress\PublishNotifier::class, 'on_after_insert'], 20, 4);
// "Do not publish on Facebook" field: meta hooks only note a change; the report goes once per post.
add_action('add_post_meta', [\TribuneDesk\WordPress\SocialQueueFlag::class, 'before_add'], 10, 2);
add_action('update_post_meta', [\TribuneDesk\WordPress\SocialQueueFlag::class, 'before_change'], 10, 3);
add_action('delete_post_meta', [\TribuneDesk\WordPress\SocialQueueFlag::class, 'before_change'], 10, 3);
add_action('added_post_meta', [\TribuneDesk\WordPress\SocialQueueFlag::class, 'after_change'], 10, 3);
add_action('updated_post_meta', [\TribuneDesk\WordPress\SocialQueueFlag::class, 'after_change'], 10, 3);
add_action('deleted_post_meta', [\TribuneDesk\WordPress\SocialQueueFlag::class, 'after_change'], 10, 3);
add_action('shutdown', [\TribuneDesk\WordPress\PublishNotifier::class, 'flush_pending']);
add_action(\TribuneDesk\WordPress\PublishNotifier::RETRY_HOOK, [\TribuneDesk\WordPress\PublishNotifier::class, 'retry']);
add_action('admin_post_' . \TribuneDesk\WordPress\Backfill::ACTION, [\TribuneDesk\WordPress\Backfill::class, 'handle_admin_post']);

// Hourly safety net for sending via /v3 and the threshold of new images.
add_action('init', [\TribuneDesk\WordPress\MediaPush::class, 'ensure_scheduled']);
add_action('attachment_updated', [\TribuneDesk\WordPress\MediaMetaSync::class, 'on_post_updated'], 10, 3);
add_action('update_postmeta', [\TribuneDesk\WordPress\MediaMetaSync::class, 'on_update_meta'], 10, 4);
add_action('added_post_meta', [\TribuneDesk\WordPress\MediaMetaSync::class, 'on_add_meta'], 10, 4);
add_action(\TribuneDesk\WordPress\MediaPush::HOOK, [\TribuneDesk\WordPress\MediaPush::class, 'run']);
// Exchange via /v3/cms: channel every 5 minutes, sending a minute after a change. Only cron checks the mode.
add_filter('cron_schedules', [\TribuneDesk\WordPress\CmsSync::class, 'add_schedule']);
add_action('init', [\TribuneDesk\WordPress\CmsSync::class, 'ensure_scheduled']);
add_action(\TribuneDesk\WordPress\CmsSync::HOOK_POLL, [\TribuneDesk\WordPress\CmsSync::class, 'run_poll']);
add_action(\TribuneDesk\WordPress\CmsSync::HOOK_SEND, [\TribuneDesk\WordPress\CmsSync::class, 'run_send']);
add_action('add_attachment', [\TribuneDesk\WordPress\CmsSync::class, 'on_add_attachment'], 10, 0);
add_action('transition_post_status', [\TribuneDesk\WordPress\CmsSync::class, 'on_transition'], 30, 3);
add_action('admin_post_' . \TribuneDesk\WordPress\CmsSettingsScreen::ACTION_SAVE, [\TribuneDesk\WordPress\CmsSettingsScreen::class, 'handle_save']);
add_action('admin_post_' . \TribuneDesk\WordPress\CmsSettingsScreen::ACTION_HELD, [\TribuneDesk\WordPress\CmsSettingsScreen::class, 'handle_held']);
add_action('admin_post_' . \TribuneDesk\WordPress\CmsSettingsScreen::ACTION_PUSH, [\TribuneDesk\WordPress\CmsSettingsScreen::class, 'handle_push_existing']);
// Removal: reported from the hook before meta is deleted; marker and filter in the library.
add_action('delete_attachment', [\TribuneDesk\WordPress\CmsDeletions::class, 'on_delete_attachment'], 10, 1);
add_action('restrict_manage_posts', [\TribuneDesk\WordPress\CmsDeletions::class, 'render_list_filter'], 10, 1);
add_action('pre_get_posts', [\TribuneDesk\WordPress\CmsDeletions::class, 'filter_list_query']);
add_filter('ajax_query_attachments_args', [\TribuneDesk\WordPress\CmsDeletions::class, 'filter_grid_query']);
add_action('admin_enqueue_scripts', [\TribuneDesk\WordPress\CmsDeletions::class, 'enqueue_grid_filter']);
// Backfill: candidates for a superadmin order, no control on the publisher screen.
add_action('init', [\TribuneDesk\WordPress\CmsBackfill::class, 'ensure_scheduled']);
add_action(\TribuneDesk\WordPress\CmsBackfill::HOOK, [\TribuneDesk\WordPress\CmsBackfill::class, 'run']);
add_action(\TribuneDesk\WordPress\CmsResync::HOOK_PAGE, [\TribuneDesk\WordPress\CmsResync::class, 'run_page']);
add_action('admin_post_' . \TribuneDesk\WordPress\CmsResync::ACTION_START, [\TribuneDesk\WordPress\CmsResync::class, 'handle_start']);
add_action('admin_post_' . \TribuneDesk\WordPress\CmsResync::ACTION_RESUME, [\TribuneDesk\WordPress\CmsResync::class, 'handle_resume']);
add_action('admin_post_' . \TribuneDesk\WordPress\CmsResync::ACTION_CONFIRM, [\TribuneDesk\WordPress\CmsResync::class, 'handle_confirm']);
add_action('admin_post_' . \TribuneDesk\WordPress\CmsResync::ACTION_ABANDON, [\TribuneDesk\WordPress\CmsResync::class, 'handle_abandon']);
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(\TribuneDesk\WordPress\MediaPush::HOOK);
    wp_clear_scheduled_hook(\TribuneDesk\WordPress\CmsSync::HOOK_POLL);
    wp_clear_scheduled_hook(\TribuneDesk\WordPress\CmsSync::HOOK_SEND);
    wp_clear_scheduled_hook(\TribuneDesk\WordPress\CmsResync::HOOK_PAGE);
    wp_clear_scheduled_hook(\TribuneDesk\WordPress\CmsBackfill::HOOK);
});

\TribuneDesk\WordPress\Updater::init();
