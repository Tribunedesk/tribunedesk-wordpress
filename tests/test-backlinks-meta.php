<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\Backlinks;
use TribuneDesk\WordPress\RestEndpoint;

class Test_Backlinks_Meta extends WP_UnitTestCase
{
    private const UUID_T = '0b6f3a52-7c1e-4d2a-9f41-3e8a5b7c9d10';
    private const UUID_A = 'c4f1e0aa-2b3d-4e5f-8a6b-7c8d9e0f1a2b';
    private const UUID_B = 'af36be4b-1961-4f66-9723-705862656927';

    public function setUp(): void
    {
        parent::setUp();
        $author_id = self::factory()->user->create(['role' => 'editor']);
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, TRIBUNE_DESK_TESTS_SIGNING_SECRET);
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
        $req->set_body(json_encode(array_merge([
            'article_id' => self::UUID_T,
            'topic'      => 't',
            'title'      => 'Tytul',
            'html'       => '<p>x</p>',
        ], $extra)));
        $req->set_header('Content-Type', 'application/json');
        tribune_desk_tests_sign($req);
        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status());

        return $res->get_data();
    }

    public function test_zrodla_i_evergreen_trafiaja_do_meta_przy_created(): void
    {
        $data = $this->send([
            'backlink_sources' => [
                ['article_id' => self::UUID_A, 'distance' => 0.21],
                ['article_id' => 'art-1', 'distance' => 0.3],
                ['distance' => 0.4],
                'x',
                ['article_id' => strtoupper(self::UUID_A), 'distance' => 0.5],
            ],
            'evergreen' => true,
        ]);
        $id = $data['post_id'];

        $this->assertSame(
            [['article_id' => self::UUID_A, 'distance' => 0.21]],
            json_decode((string) get_post_meta($id, Backlinks::META_SOURCES, true), true)
        );
        $this->assertSame('1', get_post_meta($id, Backlinks::META_EVERGREEN, true));
    }

    public function test_brak_pol_nie_tworzy_meta(): void
    {
        $id = $this->send([])['post_id'];

        $this->assertFalse(metadata_exists('post', $id, Backlinks::META_SOURCES));
        $this->assertFalse(metadata_exists('post', $id, Backlinks::META_EVERGREEN));
    }

    public function test_zle_typy_nie_psuja_odbioru(): void
    {
        $data = $this->send(['backlink_sources' => 'abc', 'evergreen' => 'tak']);

        $this->assertSame('created', $data['status']);
        $this->assertFalse(metadata_exists('post', $data['post_id'], Backlinks::META_SOURCES));
        $this->assertFalse(metadata_exists('post', $data['post_id'], Backlinks::META_EVERGREEN));
    }

    public function test_ponowna_wysylka_szkicu_nadpisuje_meta(): void
    {
        $id = $this->send([
            'backlink_sources' => [['article_id' => self::UUID_A, 'distance' => 0.2]],
            'evergreen' => true,
        ])['post_id'];

        $second = $this->send(['backlink_sources' => [], 'evergreen' => false]);

        $this->assertSame('updated', $second['status']);
        $this->assertSame('[]', get_post_meta($id, Backlinks::META_SOURCES, true));
        $this->assertSame('0', get_post_meta($id, Backlinks::META_EVERGREEN, true));
    }

    public function test_ponowna_wysylka_szkicu_bez_pol_zostawia_meta(): void
    {
        $id = $this->send([
            'backlink_sources' => [['article_id' => self::UUID_B, 'distance' => null]],
            'evergreen' => true,
        ])['post_id'];

        $second = $this->send([]);

        $this->assertSame('updated', $second['status']);
        $this->assertSame(
            [['article_id' => self::UUID_B, 'distance' => null]],
            json_decode((string) get_post_meta($id, Backlinks::META_SOURCES, true), true)
        );
        $this->assertSame('1', get_post_meta($id, Backlinks::META_EVERGREEN, true));
    }

    public function test_evergreen_null_zostawia_meta(): void
    {
        $id = $this->send(['evergreen' => true])['post_id'];

        $second = $this->send(['evergreen' => null]);

        $this->assertSame('updated', $second['status']);
        $this->assertSame('1', get_post_meta($id, Backlinks::META_EVERGREEN, true));
    }

    public function test_sources_of_rozroznia_brak_od_pustej_listy(): void
    {
        $id = $this->send([])['post_id'];
        $this->assertNull(Backlinks::sources_of($id));

        $this->send(['backlink_sources' => []]);
        $this->assertSame([], Backlinks::sources_of($id));
    }
}
