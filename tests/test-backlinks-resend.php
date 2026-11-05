<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\Backlinks;
use TribuneDesk\WordPress\PostWriter;
use TribuneDesk\WordPress\RestEndpoint;

class Test_Backlinks_Resend extends WP_UnitTestCase
{
    private const DATE = '2026-01-02 03:04:05';
    private const UUID_T = '0b6f3a52-7c1e-4d2a-9f41-3e8a5b7c9d10';
    private const UUID_S1 = 'c4f1e0aa-2b3d-4e5f-8a6b-7c8d9e0f1a2b';
    private const UUID_S2 = 'af36be4b-1961-4f66-9723-705862656927';

    public function setUp(): void
    {
        parent::setUp();
        $author_id = self::factory()->user->create(['role' => 'editor']);
        update_option(RestEndpoint::OPTION_SECRET, 'test-secret');
        update_option(RestEndpoint::OPTION_DEFAULT_AUTHOR, $author_id);
        update_option(Activator::OPTION_INSTALL_USER, $author_id);
        Activator::activate();
        do_action('rest_api_init');
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        parent::tearDown();
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

    /** @param array<string, mixed> $extra */
    private function send(array $extra): array
    {
        $req = new WP_REST_Request('POST', '/' . RestEndpoint::NAMESPACE . RestEndpoint::ROUTE);
        $req->set_header('x_webhook_secret', 'test-secret');
        $req->set_body(json_encode(array_merge([
            'article_id' => self::UUID_T,
            'topic'      => 't',
            'title'      => 'Tytul',
            'html'       => '<p>1</p>',
        ], $extra)));
        $req->set_header('Content-Type', 'application/json');
        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status());

        return $res->get_data();
    }

    /** Pierwsza wysylka: szkic z ustalona data, zeby created_at wierszy dalo sie porownac. */
    private function first_send(array $extra): int
    {
        $id = $this->send($extra)['post_id'];
        wp_update_post(['ID' => $id, 'post_date' => self::DATE, 'post_date_gmt' => self::DATE, 'edit_date' => true]);

        return $id;
    }

    /** @param array<int, string> $uuids */
    private function sources(array $uuids): array
    {
        return array_map(static fn(string $u): array => ['article_id' => $u, 'distance' => 0.1], $uuids);
    }

    private function published_source(string $uuid): int
    {
        return (int) self::factory()->post->create([
            'post_status' => 'publish',
            'meta_input'  => [PostWriter::META_ARTICLE_ID => $uuid],
        ]);
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

    public function test_ponowna_wysylka_opublikowanego_aktualizuje_meta_i_wiersze_bez_zmiany_tresci(): void
    {
        $s1 = $this->published_source(self::UUID_S1);
        $s2 = $this->published_source(self::UUID_S2);

        $id = $this->first_send(['backlink_sources' => $this->sources([self::UUID_S1]), 'evergreen' => false]);
        wp_publish_post($id);
        $this->assertSame([[$s1, 'news', self::DATE]], $this->rows_of($id));

        $data = $this->send([
            'html'             => '<p>2</p>',
            'backlink_sources' => $this->sources([self::UUID_S2]),
            'evergreen'        => true,
        ]);

        $this->assertSame('skipped_already_published', $data['status']);
        $this->assertStringContainsString('<p>1</p>', get_post($id)->post_content);
        $this->assertSame('1', get_post_meta($id, Backlinks::META_EVERGREEN, true));
        $this->assertSame([[$s2, 'evergreen', self::DATE]], $this->rows_of($id));
    }

    public function test_ponowna_wysylka_bez_pol_nie_rusza_meta_ani_wierszy(): void
    {
        $s1 = $this->published_source(self::UUID_S1);

        $id = $this->first_send(['backlink_sources' => $this->sources([self::UUID_S1]), 'evergreen' => true]);
        wp_publish_post($id);
        $meta_before = get_post_meta($id, Backlinks::META_SOURCES, true);

        $data = $this->send(['html' => '<p>2</p>']);

        $this->assertSame('skipped_already_published', $data['status']);
        $this->assertSame($meta_before, get_post_meta($id, Backlinks::META_SOURCES, true));
        $this->assertSame('1', get_post_meta($id, Backlinks::META_EVERGREEN, true));
        $this->assertSame([[$s1, 'evergreen', self::DATE]], $this->rows_of($id));
    }

    public function test_ponowna_wysylka_z_pusta_lista_kasuje_wiersze(): void
    {
        $this->published_source(self::UUID_S1);

        $id = $this->first_send(['backlink_sources' => $this->sources([self::UUID_S1])]);
        wp_publish_post($id);
        $this->assertCount(1, $this->rows_of($id));

        $this->send(['backlink_sources' => []]);

        $this->assertSame([], $this->rows_of($id));
        $this->assertSame('[]', get_post_meta($id, Backlinks::META_SOURCES, true));
    }

    public function test_ponowna_wysylka_do_zaplanowanego_tylko_meta(): void
    {
        $s1 = $this->published_source(self::UUID_S1);

        $id = $this->first_send([]);
        $tomorrow = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        wp_update_post([
            'ID'            => $id,
            'post_status'   => 'future',
            'edit_date'     => true,
            'post_date'     => $tomorrow,
            'post_date_gmt' => $tomorrow,
        ]);
        $this->assertSame('future', get_post($id)->post_status);

        $data = $this->send(['backlink_sources' => $this->sources([self::UUID_S1])]);

        $this->assertSame('skipped_already_published', $data['status']);
        $this->assertNotNull(Backlinks::sources_of($id));
        $this->assertSame([], $this->rows_of($id));

        wp_publish_post($id);
        $this->assertCount(1, $this->rows_of($id));
        $this->assertSame($s1, $this->rows_of($id)[0][0]);
    }
}
