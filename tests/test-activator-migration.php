<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\Backlinks;

/**
 * Hak aktywacji nie odpala sie przy aktualizacji aktywnej wtyczki (auto-update,
 * "Zamien obecna na przeslana"), wiec kolumna `kind` musi dojsc z maybe_upgrade().
 *
 * DDL w transakcji testu: harness WP przepisuje CREATE TABLE na CREATE TEMPORARY TABLE
 * i DROP TABLE na DROP TEMPORARY TABLE, wiec tabela tymczasowa przeslania prawdziwa
 * (te z aktywacji przez wp-env) tylko na czas polaczenia i nie rusza jej danych.
 */
class Test_Activator_Migration extends WP_UnitTestCase
{
    private string $table = '';

    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        $this->table = $wpdb->prefix . Activator::TABLE;
        $wpdb->query("DROP TABLE IF EXISTS {$this->table}");
        $wpdb->query('DROP TABLE IF EXISTS ' . Backlinks::table());
        delete_option(Activator::OPTION_DB_VERSION);
    }

    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$this->table}");
        $wpdb->query('DROP TABLE IF EXISTS ' . Backlinks::table());
        delete_option(Activator::OPTION_DB_VERSION);
        parent::tear_down();
    }

    private function create_v1_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE {$this->table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            received_at DATETIME NOT NULL,
            article_id VARCHAR(64) NOT NULL,
            post_id BIGINT UNSIGNED NULL,
            status VARCHAR(32) NOT NULL,
            error_msg TEXT NULL,
            PRIMARY KEY (id)
        ) $charset_collate");
    }

    private function has_kind_column(): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$this->table} LIKE %s", 'kind'));
    }

    public function test_tabela_v1_dostaje_kolumne_kind_przy_maybe_upgrade(): void
    {
        $this->create_v1_table();
        $this->assertFalse($this->has_kind_column(), 'Warunek wstepny: schemat v1 bez kolumny kind.');

        Activator::maybe_upgrade();

        $this->assertTrue($this->has_kind_column());
        $this->assertSame(Activator::DB_VERSION, (int) get_option(Activator::OPTION_DB_VERSION));
    }

    public function test_stary_wiersz_przezywa_migracje_z_domyslnym_kind(): void
    {
        global $wpdb;
        $this->create_v1_table();
        $wpdb->insert($this->table, [
            'received_at' => '2026-09-01 10:00:00',
            'article_id'  => 'art-przed-migracja',
            'post_id'     => 7,
            'status'      => 'created',
            'error_msg'   => null,
        ], ['%s', '%s', '%d', '%s', '%s']);

        Activator::maybe_upgrade();

        $kind = $wpdb->get_var("SELECT kind FROM {$this->table} WHERE article_id = 'art-przed-migracja'");
        $this->assertSame('receive', $kind);
    }

    public function test_maybe_upgrade_z_v2_zaklada_tabele_backlinks(): void
    {
        global $wpdb;
        $this->create_v1_table();
        update_option(Activator::OPTION_DB_VERSION, 2);

        Activator::maybe_upgrade();

        $this->assertSame(3, (int) get_option(Activator::OPTION_DB_VERSION));
        $cols = $wpdb->get_results('SHOW COLUMNS FROM ' . Backlinks::table(), ARRAY_A);
        $this->assertSame(['source_post_id', 'target_post_id', 'target_kind', 'created_at'], array_column($cols, 'Field'));
        $this->assertSame("enum('evergreen','news')", $cols[2]['Type']);
        $keys = $wpdb->get_results('SHOW INDEX FROM ' . Backlinks::table(), ARRAY_A);
        $primary = array_filter($keys, static fn($k) => $k['Key_name'] === 'PRIMARY');
        $this->assertSame(['source_post_id', 'target_post_id'], array_column($primary, 'Column_name'));
        $this->assertContains('target_post_id', array_column($keys, 'Key_name'));
    }

    public function test_wiersz_dostawy_przezywa_upgrade_do_v3(): void
    {
        global $wpdb;
        $this->create_v1_table();
        update_option(Activator::OPTION_DB_VERSION, 2);
        $wpdb->insert($this->table, [
            'received_at' => '2026-09-01 10:00:00',
            'article_id'  => 'art-przed-v3',
            'post_id'     => 9,
            'status'      => 'created',
            'error_msg'   => null,
        ], ['%s', '%s', '%d', '%s', '%s']);

        Activator::maybe_upgrade();

        $this->assertSame('9', $wpdb->get_var("SELECT post_id FROM {$this->table} WHERE article_id = 'art-przed-v3'"));
    }

    public function test_drugi_przebieg_nie_wykonuje_zadnego_zapytania(): void
    {
        global $wpdb;
        $this->create_v1_table();
        Activator::maybe_upgrade();

        $before = $wpdb->num_queries;
        Activator::maybe_upgrade();

        $this->assertSame($before, $wpdb->num_queries, 'Przy aktualnej wersji schematu maybe_upgrade() ma byc jednym odczytem opcji z cache.');
    }

    public function test_activate_tworzy_tabele_od_zera_w_schemacie_v2(): void
    {
        Activator::activate();

        $this->assertTrue($this->has_kind_column());
        $this->assertSame(Activator::DB_VERSION, (int) get_option(Activator::OPTION_DB_VERSION));
    }

    public function test_hak_plugins_loaded_jest_zarejestrowany(): void
    {
        $this->assertNotFalse(has_action('plugins_loaded', ['TribuneDesk\WordPress\Activator', 'maybe_upgrade']));
        $this->assertNotFalse(has_action('plugins_loaded', ['TribuneDesk\WordPress\Activator', 'clear_removed_crons']));
    }

    public function test_aktualizacja_raz_czysci_usuniete_crony(): void
    {
        delete_option(Activator::OPTION_REMOVED_CRONS_CLEARED);
        wp_schedule_event(time() + 300, 'hourly', 'headlinesforge_media_meta_push');
        wp_schedule_event(time() + 300, 'hourly', 'headlinesforge_media_push');

        Activator::clear_removed_crons();

        $this->assertFalse(wp_next_scheduled('headlinesforge_media_meta_push'));
        $this->assertIsInt(wp_next_scheduled('headlinesforge_media_push'));
        $this->assertSame('1', get_option(Activator::OPTION_REMOVED_CRONS_CLEARED));

        // Drugi przebieg nic nie robi: znacznik juz jest.
        wp_schedule_event(time() + 300, 'hourly', 'headlinesforge_media_meta_push');
        Activator::clear_removed_crons();
        $this->assertIsInt(wp_next_scheduled('headlinesforge_media_meta_push'));

        wp_clear_scheduled_hook('headlinesforge_media_meta_push');
        wp_clear_scheduled_hook('headlinesforge_media_push');
    }
}
