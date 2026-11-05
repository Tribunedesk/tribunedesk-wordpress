<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

class Activator
{
    public const TABLE = 'headlinesforge_deliveries';
    public const OPTION_INSTALL_USER = '_headlinesforge_install_user_id';

    /**
     * Version of the deliveries table schema. Raise it on every CREATE TABLE change below.
     * 1 = 0.1.0-0.7.2 (no kind column), 2 = 0.8.0 (kind column), 3 = 1.7.0 (hf_backlinks table).
     */
    public const DB_VERSION = 3;
    public const OPTION_DB_VERSION = 'headlinesforge_db_version';

    /** Crons of removed features. An event without a WP handler would be scheduled forever. */
    public const REMOVED_CRONS = ['headlinesforge_media_meta_push'];
    public const OPTION_REMOVED_CRONS_CLEARED = 'headlinesforge_removed_crons_cleared';

    public static function activate(): void
    {
        self::create_or_upgrade_table();

        if (!get_option(self::OPTION_INSTALL_USER)) {
            update_option(self::OPTION_INSTALL_USER, get_current_user_id());
        }
    }

    /**
     * The activation hook does NOT fire when an active plugin is updated (auto-update from the manifest,
     * "Replace current with uploaded"). 0.8.0 spreads via auto-update, so without this
     * DeliveriesLog::record() would fail on the nonexistent `kind` column on every install.
     * Cost in a normal job: one option read from cache.
     */
    public static function maybe_upgrade(): void
    {
        if ((int) get_option(self::OPTION_DB_VERSION, 0) >= self::DB_VERSION) {
            return;
        }
        self::create_or_upgrade_table();
    }

    /** The activation hook does not run on update, so cleanup runs once, like maybe_upgrade. */
    public static function clear_removed_crons(): void
    {
        if (get_option(self::OPTION_REMOVED_CRONS_CLEARED)) {
            return;
        }
        foreach (self::REMOVED_CRONS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        update_option(self::OPTION_REMOVED_CRONS_CLEARED, '1');
    }

    private static function create_or_upgrade_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        // dbDelta compares columns with DESCRIBE and adds the missing ones — that is the whole v1 -> v2 migration.
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            received_at DATETIME NOT NULL,
            article_id VARCHAR(64) NOT NULL,
            post_id BIGINT UNSIGNED NULL,
            kind VARCHAR(16) NOT NULL DEFAULT 'receive',
            status VARCHAR(32) NOT NULL,
            error_msg TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_article_id (article_id),
            KEY idx_received_at (received_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $backlinks = Backlinks::table();
        $sql = "CREATE TABLE $backlinks (
            source_post_id BIGINT UNSIGNED NOT NULL,
            target_post_id BIGINT UNSIGNED NOT NULL,
            target_kind ENUM('evergreen','news') NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (source_post_id, target_post_id),
            KEY target_post_id (target_post_id)
        ) $charset_collate;";
        dbDelta($sql);

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }
}
