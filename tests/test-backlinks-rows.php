<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\Backlinks;
use TribuneDesk\WordPress\DeliveriesLog;
use TribuneDesk\WordPress\PostWriter;
use TribuneDesk\WordPress\RestEndpoint;

class Test_Backlinks_Rows extends WP_UnitTestCase
{
    private const UUID_T    = '0b6f3a52-7c1e-4d2a-9f41-3e8a5b7c9d10';
    private const UUID_S1   = 'c4f1e0aa-2b3d-4e5f-8a6b-7c8d9e0f1a2b';
    private const UUID_S2   = 'af36be4b-1961-4f66-9723-705862656927';
    private const UUID_NONE = '99999999-9999-4999-8999-999999999999';

    public function set_up(): void
    {
        parent::set_up();
        $author_id = self::factory()->user->create(['role' => 'editor']);
        update_option(RestEndpoint::OPTION_SECRET, 'test-secret');
        update_option(RestEndpoint::OPTION_DEFAULT_AUTHOR, $author_id);
        update_option(Activator::OPTION_INSTALL_USER, $author_id);
        Activator::activate();
        do_action('rest_api_init');
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        unset($_REQUEST['bulk_edit']);
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_http($preempt, array $args, string $url)
    {
        return [
            'headers'  => [],
            'body'     => wp_json_encode(['results' => []]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /** @param array<string, string> $meta */
    private function hf_post(string $uuid, string $status, array $meta = []): int
    {
        return (int) self::factory()->post->create([
            'post_status'   => $status,
            'post_date'     => '2026-01-02 03:04:05',
            'post_date_gmt' => '2026-01-02 03:04:05',
            'meta_input'  => array_merge([PostWriter::META_ARTICLE_ID => $uuid], $meta),
        ]);
    }

    /** @param array<int, string> $uuids */
    private function sources_json(array $uuids): string
    {
        return wp_slash((string) wp_json_encode(array_map(
            static fn(string $u): array => ['article_id' => $u, 'distance' => 0.1],
            $uuids
        )));
    }

    /** @return array<int, array<int, mixed>> */
    private function rows_of(int $target): array
    {
        global $wpdb;
        $table = Backlinks::table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT source_post_id, target_kind, created_at FROM $table WHERE target_post_id = %d ORDER BY source_post_id",
                $target
            ),
            ARRAY_N
        );

        return array_map(static fn(array $r): array => [(int) $r[0], $r[1], $r[2]], $rows);
    }

    private function insert_row(int $source, int $target): void
    {
        global $wpdb;
        $wpdb->insert(Backlinks::table(), [
            'source_post_id' => $source,
            'target_post_id' => $target,
            'target_kind'    => 'news',
            'created_at'     => '2020-01-01 00:00:00',
        ]);
    }

    public function test_publikacja_celu_zapisuje_tylko_opublikowane_znane_zrodla(): void
    {
        $s1 = $this->hf_post(self::UUID_S1, 'publish');
        $this->hf_post(self::UUID_S2, 'draft');
        $cel = $this->hf_post(self::UUID_T, 'draft', [
            Backlinks::META_SOURCES => $this->sources_json([self::UUID_S1, self::UUID_S2, self::UUID_NONE]),
        ]);
        wp_publish_post($cel);

        $this->assertSame([[$s1, 'news', get_post($cel)->post_date_gmt]], $this->rows_of($cel));
    }

    public function test_cel_poradnik_ma_target_kind_evergreen(): void
    {
        $s1  = $this->hf_post(self::UUID_S1, 'publish');
        $cel = $this->hf_post(self::UUID_T, 'draft', [
            Backlinks::META_SOURCES   => $this->sources_json([self::UUID_S1]),
            Backlinks::META_EVERGREEN => '1',
        ]);
        wp_publish_post($cel);

        $this->assertSame([[$s1, 'evergreen', get_post($cel)->post_date_gmt]], $this->rows_of($cel));
    }

    public function test_brak_evergreen_daje_news(): void
    {
        $this->hf_post(self::UUID_S1, 'publish');
        $cel = $this->hf_post(self::UUID_T, 'draft', [
            Backlinks::META_SOURCES => $this->sources_json([self::UUID_S1]),
        ]);
        wp_publish_post($cel);

        $this->assertSame('news', $this->rows_of($cel)[0][1]);
    }

    public function test_ponowna_publikacja_zastepuje_wiersze(): void
    {
        $this->hf_post(self::UUID_S1, 'publish');
        $s2  = $this->hf_post(self::UUID_S2, 'publish');
        $cel = $this->hf_post(self::UUID_T, 'draft', [
            Backlinks::META_SOURCES => $this->sources_json([self::UUID_S1]),
        ]);
        wp_publish_post($cel);
        wp_update_post(['ID' => $cel, 'post_status' => 'draft']);
        update_post_meta($cel, Backlinks::META_SOURCES, $this->sources_json([self::UUID_S2]));
        wp_publish_post($cel);

        $this->assertSame([$s2], array_column($this->rows_of($cel), 0));
    }

    public function test_pusta_lista_kasuje_wiersze_celu(): void
    {
        $s1  = $this->hf_post(self::UUID_S1, 'publish');
        $cel = $this->hf_post(self::UUID_T, 'draft', [Backlinks::META_SOURCES => '[]']);
        $this->insert_row($s1, $cel);
        wp_publish_post($cel);

        $this->assertSame([], $this->rows_of($cel));
    }

    public function test_brak_meta_zrodel_nie_rusza_wierszy(): void
    {
        $s1  = $this->hf_post(self::UUID_S1, 'publish');
        $cel = $this->hf_post(self::UUID_T, 'draft');
        $this->insert_row($s1, $cel);
        wp_publish_post($cel);

        $this->assertCount(1, $this->rows_of($cel));
    }

    public function test_cel_w_koszu_zostawia_wiersze(): void
    {
        $this->hf_post(self::UUID_S1, 'publish');
        $cel = $this->hf_post(self::UUID_T, 'draft', [
            Backlinks::META_SOURCES => $this->sources_json([self::UUID_S1]),
        ]);
        wp_publish_post($cel);
        $before = $this->rows_of($cel);
        wp_trash_post($cel);

        $this->assertNotSame([], $before);
        $this->assertSame($before, $this->rows_of($cel));
    }

    public function test_zrodlo_rowne_celowi_i_duplikat_pominiete(): void
    {
        $s1  = $this->hf_post(self::UUID_S1, 'publish');
        $cel = $this->hf_post(self::UUID_T, 'draft', [
            Backlinks::META_SOURCES => $this->sources_json([self::UUID_T, self::UUID_S1, strtoupper(self::UUID_S1)]),
        ]);
        wp_publish_post($cel);

        $this->assertSame([$s1], array_column($this->rows_of($cel), 0));
        $this->assertSame([], array_filter(
            DeliveriesLog::recent(),
            static fn(array $r): bool => $r['kind'] === 'backlinks'
        ));
    }

    public function test_publish_immediately_przez_webhook_zapisuje_wiersze(): void
    {
        update_option(RestEndpoint::OPTION_PUBLISH, '1');
        $s1  = $this->hf_post(self::UUID_S1, 'publish');
        $req = new WP_REST_Request('POST', '/' . RestEndpoint::NAMESPACE . RestEndpoint::ROUTE);
        $req->set_header('x_webhook_secret', 'test-secret');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode([
            'article_id'       => self::UUID_T,
            'topic'            => 't',
            'title'            => 'Tytul',
            'html'             => '<p>x</p>',
            'backlink_sources' => [['article_id' => self::UUID_S1, 'distance' => 0.2]],
        ]));
        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status());
        $post_id = (int) $res->get_data()['post_id'];

        $this->assertSame([$s1], array_column($this->rows_of($post_id), 0));
    }

    public function test_publikacja_zbiorcza_zapisuje_wiersze(): void
    {
        $_REQUEST['bulk_edit'] = 'Update';
        $s1  = $this->hf_post(self::UUID_S1, 'publish');
        $cel = $this->hf_post(self::UUID_T, 'draft', [
            Backlinks::META_SOURCES => $this->sources_json([self::UUID_S1]),
        ]);
        wp_update_post(['ID' => $cel, 'post_status' => 'publish']);

        $this->assertSame([$s1], array_column($this->rows_of($cel), 0));
    }

    public function test_blad_insertu_nie_blokuje_publikacji_i_trafia_do_logu(): void
    {
        global $wpdb;
        $this->hf_post(self::UUID_S1, 'publish');
        $cel = $this->hf_post(self::UUID_T, 'draft', [
            Backlinks::META_SOURCES => $this->sources_json([self::UUID_S1]),
        ]);
        $table = Backlinks::table();
        $swap  = static fn(string $q): string => str_starts_with($q, "INSERT INTO $table")
            ? 'INSERT INTO hf_nie_ma_takiej VALUES (1)'
            : $q;
        add_filter('query', $swap);
        $wpdb->suppress_errors(true);

        wp_publish_post($cel);

        remove_filter('query', $swap);
        $wpdb->suppress_errors(false);

        $this->assertSame('publish', get_post_status($cel));
        $log = array_values(array_filter(
            DeliveriesLog::recent(),
            static fn(array $r): bool => $r['kind'] === 'backlinks'
        ));
        $this->assertCount(1, $log);
        $this->assertSame('error', $log[0]['status']);
        $this->assertSame($cel, (int) $log[0]['post_id']);
        $this->assertNotSame('', (string) $log[0]['error_msg']);
    }

    public function test_cel_bez_daty_gmt_dostaje_biezaca(): void
    {
        $this->hf_post(self::UUID_S1, 'publish');
        $cel = $this->hf_post(self::UUID_T, 'draft', [
            Backlinks::META_SOURCES => $this->sources_json([self::UUID_S1]),
        ]);
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_date_gmt' => '0000-00-00 00:00:00'], ['ID' => $cel]);
        clean_post_cache($cel);
        wp_publish_post($cel);

        $this->assertNotSame('0000-00-00 00:00:00', $this->rows_of($cel)[0][2]);
    }

    public function test_hak_zarejestrowany_z_priorytetem_15(): void
    {
        $this->assertSame(15, has_action('transition_post_status', [Backlinks::class, 'on_transition']));
    }

    public function test_wpis_bez_uuid_nie_jest_celem(): void
    {
        $this->hf_post(self::UUID_S1, 'publish');
        $cel = $this->hf_post('art-100', 'draft', [
            Backlinks::META_SOURCES => $this->sources_json([self::UUID_S1]),
        ]);
        wp_publish_post($cel);

        $this->assertSame([], $this->rows_of($cel));
    }
}
