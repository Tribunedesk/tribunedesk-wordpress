<?php
declare(strict_types=1);

/**
 * uninstall.php biegnie tu NAPRAWDE. Jest to bezpieczne: harness WP przepisuje
 * "DROP TABLE" na "DROP TEMPORARY TABLE" (prawdziwa tabela zostaje), a kasowanie opcji
 * dzieje sie w transakcji testu i jest wycofywane w tear_down.
 */
class Test_Uninstall extends WP_UnitTestCase
{
    public function test_uninstall_removes_the_1_17_options(): void
    {
        update_option('headlinesforge_signing_secret', 'whsec_C2FVsBQIhrscChlQIMV+b5sSYspob7oD');
        update_option('headlinesforge_placeholder_path', '/app/a/');
        update_option('headlinesforge_image_hosts', ['hosts' => ['example.com'], 'fetched_at' => 1]);
        update_option('headlinesforge_social_exclude_key', 'exclude_facebook_rss');

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require dirname(__DIR__) . '/uninstall.php';

        $this->assertFalse(get_option('headlinesforge_signing_secret'));
        $this->assertFalse(get_option('headlinesforge_placeholder_path'));
        $this->assertFalse(get_option('headlinesforge_image_hosts'));
        $this->assertFalse(get_option('headlinesforge_social_exclude_key'));
    }

    public function test_uninstall_kasuje_nowe_opcje_i_zaplanowane_ponowienia(): void
    {
        update_option('headlinesforge_api_base', 'https://staging.example.com');
        update_option('headlinesforge_meta_desc_key', 'post_meta_desc');
        update_option('headlinesforge_fb_title_key', 'facebook_title');
        update_option('headlinesforge_excerpt_from_fb_teaser', '1');
        update_option('headlinesforge_db_version', 2);
        update_option('headlinesforge_backfill_cursor', 15);
        // Ta jedna opcja byla pomijana od poczatku — siedem pozostalych bylo kasowanych,
        // a `publish_immediately` zostawala w bazie klienta po odinstalowaniu wtyczki.
        update_option('headlinesforge_publish_immediately', 1);
        update_option('headlinesforge_author_map', ['kp_1' => 5]);
        update_option('headlinesforge_seen_authors', ['kp_1' => ['name' => 'A', 'email' => '', 'last_seen' => 1]]);
        wp_schedule_single_event(time() + 300, 'headlinesforge_notify_retry', [123]);
        $this->assertIsInt(wp_next_scheduled('headlinesforge_notify_retry', [123]), 'Warunek wstepny: zdarzenie zaplanowane.');

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require dirname(__DIR__) . '/uninstall.php';

        $this->assertFalse(get_option('headlinesforge_api_base'));
        $this->assertFalse(get_option('headlinesforge_meta_desc_key'));
        $this->assertFalse(get_option('headlinesforge_fb_title_key'));
        $this->assertFalse(get_option('headlinesforge_excerpt_from_fb_teaser'));
        $this->assertFalse(get_option('headlinesforge_db_version'));
        $this->assertFalse(get_option('headlinesforge_backfill_cursor'));
        $this->assertFalse(get_option('headlinesforge_webhook_secret'));
        $this->assertFalse(
            get_option('headlinesforge_publish_immediately'),
            'uninstall.php musi kasowac TAKZE publish_immediately — byla jedyna pomijana.'
        );
        $this->assertFalse(get_option('headlinesforge_author_map'));
        $this->assertFalse(get_option('headlinesforge_seen_authors'));
        $this->assertFalse(wp_next_scheduled('headlinesforge_notify_retry', [123]));
    }

    public function test_uninstall_kasuje_token_cms(): void
    {
        update_option('headlinesforge_cms_token', 'tok');

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require dirname(__DIR__) . '/uninstall.php';

        $this->assertFalse(get_option('headlinesforge_cms_token'));
    }

    public function test_uninstall_czysci_usuwanie(): void
    {
        update_option('headlinesforge_cms_deletions', [1 => 1], false);
        set_transient('headlinesforge_cms_deletions_overflow', 1, 60);
        $id = (int) self::factory()->attachment->create();
        update_post_meta($id, '_hf_deleted_in_td', 1);

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require dirname(__DIR__) . '/uninstall.php';

        $this->assertFalse(get_option('headlinesforge_cms_deletions'));
        $this->assertFalse(get_transient('headlinesforge_cms_deletions_overflow'));
        $this->assertSame('', get_post_meta($id, '_hf_deleted_in_td', true));
    }

    public function test_uninstall_czysci_import_wsteczny(): void
    {
        update_option('headlinesforge_cms_backfill_pos_abc', ['date' => '2026-01-01 00:00:00', 'id' => 1], false);
        update_option('headlinesforge_cms_backfill_error', 'x', false);
        wp_schedule_event(time() + 300, 'hourly', 'headlinesforge_cms_backfill');

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require dirname(__DIR__) . '/uninstall.php';

        $this->assertFalse(get_option('headlinesforge_cms_backfill_pos_abc'));
        $this->assertFalse(get_option('headlinesforge_cms_backfill_error'));
        $this->assertFalse(wp_next_scheduled('headlinesforge_cms_backfill'));
    }

    public function test_uninstall_usuwa_tabele_backlinks(): void
    {
        global $wpdb;
        $queries = [];
        $collect = static function ($sql) use (&$queries) {
            $queries[] = $sql;
            return $sql;
        };
        // Priorytet 1: harness WP przepisuje DROP TABLE na DROP TEMPORARY TABLE w filtrze 'query' (10).
        add_filter('query', $collect, 1);

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require dirname(__DIR__) . '/uninstall.php';
        remove_filter('query', $collect, 1);

        $this->assertContains("DROP TABLE IF EXISTS {$wpdb->prefix}hf_backlinks", $queries);
    }

    public function test_uninstall_czysci_wysylke_zdjec(): void
    {
        $att = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        update_option('headlinesforge_media_push_floor', 5);
        set_transient('headlinesforge_media_push_pause', 1, 600);
        set_transient('headlinesforge_media_push_lock', 1, 600);
        update_post_meta($att, '_hf_media_pushed', 'created@1');
        update_post_meta($att, '_hf_from_td', '1');
        wp_schedule_event(time() + 300, 'hourly', 'headlinesforge_media_push');
        wp_schedule_event(time() + 300, 'hourly', 'headlinesforge_media_meta_push');
        update_option('headlinesforge_removed_crons_cleared', '1');
        set_transient('headlinesforge_media_meta_pause', 1, 600);
        update_post_meta($att, '_hf_meta_dirty', ['alt']);

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require dirname(__DIR__) . '/uninstall.php';

        $this->assertFalse(get_option('headlinesforge_media_push_floor'));
        $this->assertFalse(get_transient('headlinesforge_media_push_pause'));
        $this->assertFalse(get_transient('headlinesforge_media_push_lock'));
        $this->assertSame('', get_post_meta($att, '_hf_media_pushed', true));
        $this->assertSame('', get_post_meta($att, '_hf_from_td', true));
        $this->assertFalse(wp_next_scheduled('headlinesforge_media_push'));
        $this->assertFalse(wp_next_scheduled('headlinesforge_media_meta_push'));
        $this->assertFalse(get_option('headlinesforge_removed_crons_cleared'));
        $this->assertFalse(get_transient('headlinesforge_media_meta_pause'));
        $this->assertSame('', get_post_meta($att, '_hf_meta_dirty', true));
    }
}
