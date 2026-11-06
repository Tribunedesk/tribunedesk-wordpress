<?php
declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'headlinesforge_deliveries';
$wpdb->query("DROP TABLE IF EXISTS $table");
$backlinks_table = $wpdb->prefix . 'hf_backlinks';
$wpdb->query("DROP TABLE IF EXISTS $backlinks_table");

delete_option('headlinesforge_webhook_secret');
delete_option('headlinesforge_default_author_id');
// This option was skipped from the start: the seven others were deleted,
// this one stayed in the customer's database after uninstall.
delete_option('headlinesforge_publish_immediately');
delete_option('_headlinesforge_install_user_id');
delete_option('headlinesforge_autoupdate_bootstrapped');
delete_option('headlinesforge_db_version');
delete_option('headlinesforge_backfill_cursor');
delete_option('headlinesforge_api_base');
// 1.17.0: signing secret, placeholder path from the payload, image hosts from /v3/cms/settings.
delete_option('headlinesforge_signing_secret');
delete_option('headlinesforge_placeholder_path');
delete_option('headlinesforge_image_hosts');
delete_option('headlinesforge_meta_desc_key');
delete_option('headlinesforge_fb_title_key');
delete_option('headlinesforge_excerpt_from_fb_teaser');
delete_option('headlinesforge_social_exclude_key');
delete_option('headlinesforge_author_map');
delete_option('headlinesforge_seen_authors');
delete_option('headlinesforge_media_push_floor');
delete_option('headlinesforge_removed_crons_cleared');
delete_option('headlinesforge_cms_token');
delete_option('headlinesforge_cms_cursor');
delete_option('headlinesforge_cms_needs_resync');
delete_option('headlinesforge_cms_last_run');
delete_option('headlinesforge_cms_last_mode');
delete_transient('headlinesforge_cms_active');
delete_transient('headlinesforge_cms_keep_nonempty');
delete_option('headlinesforge_cms_lock');
delete_option('headlinesforge_cms_row_attempts');
delete_option('headlinesforge_cms_skipped');
delete_option('headlinesforge_cms_held_decided');
delete_option('headlinesforge_cms_push_existing');
delete_option('headlinesforge_cms_deletions');
delete_transient('headlinesforge_cms_deletions_overflow');
delete_post_meta_by_key('_hf_deleted_in_td');
delete_transient('headlinesforge_cms_file_problem');
delete_post_meta_by_key('_hf_pending_td');
delete_transient('headlinesforge_cms_pause');
wp_clear_scheduled_hook('headlinesforge_cms_poll');
wp_clear_scheduled_hook('headlinesforge_cms_send');
wp_clear_scheduled_hook('headlinesforge_cms_backfill');
delete_option('headlinesforge_cms_backfill_lock');
delete_option('headlinesforge_cms_backfill_error');
// Backfill positions: one option per task.
$backfill_positions = $wpdb->get_col($wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('headlinesforge_cms_backfill_pos_') . '%'
));
foreach ($backfill_positions as $backfill_position) {
    delete_option($backfill_position);
}
delete_post_meta_by_key('_hf_field_versions');
delete_post_meta_by_key('_hf_meta_dirty');
delete_post_meta_by_key('_hf_meta_dirty_at');
delete_transient('headlinesforge_media_meta_pause');
wp_clear_scheduled_hook('headlinesforge_media_meta_push');
delete_transient('headlinesforge_media_push_pause');
delete_transient('headlinesforge_media_push_lock');
wp_clear_scheduled_hook('headlinesforge_media_push');
delete_post_meta_by_key('_hf_media_pushed');
delete_post_meta_by_key('_hf_from_td');

// Scheduled callback retries (0.8.0). wp_unschedule_hook exists since WP 4.9;
// on an older core wp_clear_scheduled_hook without arguments clears events without arguments.
if (function_exists('wp_unschedule_hook')) {
    wp_unschedule_hook('headlinesforge_notify_retry');
} else {
    wp_clear_scheduled_hook('headlinesforge_notify_retry');
}
