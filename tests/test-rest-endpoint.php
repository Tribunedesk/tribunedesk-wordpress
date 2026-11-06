<?php
declare(strict_types=1);

use TribuneDesk\WordPress\RestEndpoint;
use TribuneDesk\WordPress\PostWriter;
use TribuneDesk\WordPress\SeoMeta;
use TribuneDesk\WordPress\Activator;

class Test_RestEndpoint extends WP_UnitTestCase
{
    private int $author_id;

    public function setUp(): void
    {
        parent::setUp();
        $this->author_id = self::factory()->user->create(['role' => 'editor']);
        update_option(RestEndpoint::OPTION_SECRET, 'test-secret');
        update_option(RestEndpoint::OPTION_DEFAULT_AUTHOR, $this->author_id);
        update_option(Activator::OPTION_INSTALL_USER, $this->author_id);
        // Activator runs DB migration normally on activate; do it manually for tests.
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

    private function placeholder_link(string $uuid): array
    {
        return [
            'article_id'  => $uuid,
            'href'        => 'https://headlinesforge.com/app/a/' . $uuid,
            'anchor_text' => 'kotwica',
            'kind'        => 'placeholder',
            'url'         => 'https://headlinesforge.com/app/a/' . $uuid,
            'headline'    => null,
        ];
    }

    private function send(array $body, ?string $secret = 'test-secret'): WP_REST_Response
    {
        return $this->send_to(RestEndpoint::NAMESPACE, $body, $secret);
    }

    private function send_to(
        string $namespace,
        array $body,
        ?string $secret = 'test-secret'
    ): WP_REST_Response {
        $req = new WP_REST_Request('POST', '/' . $namespace . RestEndpoint::ROUTE);
        if ($secret !== null) {
            $req->set_header('x_webhook_secret', $secret);
        }
        $req->set_body(json_encode($body));
        $req->set_header('Content-Type', 'application/json');
        return rest_do_request($req);
    }

    private const SIGNING_SECRET = 'whsec_C2FVsBQIhrscChlQIMV+b5sSYspob7oD';

    /**
     * Signed delivery (spec D4). The body goes byte for byte as Tribune Desk sends it:
     * compact JSON with non-ASCII text and an unescaped slash.
     */
    private function send_signed(string $body, string $secret, ?string $old_secret = null, int $ts = 0): WP_REST_Response
    {
        $ts  = $ts > 0 ? $ts : time();
        $key = base64_decode(substr($secret, 6));
        $sig = 'v1,' . base64_encode(hash_hmac('sha256', "msg_1.$ts." . $body, $key, true));
        $req = new WP_REST_Request('POST', '/' . RestEndpoint::NAMESPACE_BRAND . RestEndpoint::ROUTE);
        $req->set_header('webhook-id', 'msg_1');
        $req->set_header('webhook-timestamp', (string) $ts);
        $req->set_header('webhook-signature', $sig);
        if ($old_secret !== null) {
            $req->set_header('x_webhook_secret', $old_secret);
        }
        $req->set_body($body);
        $req->set_header('Content-Type', 'application/json');
        return rest_do_request($req);
    }

    private function signed_body(string $uuid = '0b8f1c2a-3d4e-4f5a-8b6c-7d8e9f0a1b2c'): string
    {
        return '{"article_id":"' . $uuid . '","topic":"Zażółć gęślą jaźń",'
            . '"html":"<p>Łódź / Kraków</p>","title":"Zażółć"}';
    }

    public function test_signed_delivery_is_accepted_when_signing_secret_set(): void
    {
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, self::SIGNING_SECRET);
        $res = $this->send_signed($this->signed_body(), self::SIGNING_SECRET);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
    }

    public function test_bad_signature_gives_401_even_with_old_secret(): void
    {
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, self::SIGNING_SECRET);
        $other = 'whsec_' . base64_encode(str_repeat('x', 32));
        $res   = $this->send_signed($this->signed_body(), $other, 'test-secret');
        $this->assertSame(401, $res->get_status());
    }

    public function test_signature_outside_tolerance_gives_401(): void
    {
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, self::SIGNING_SECRET);
        $res = $this->send_signed($this->signed_body(), self::SIGNING_SECRET, null, time() - 600);
        $this->assertSame(401, $res->get_status());
    }

    public function test_old_secret_still_works_without_signing_secret(): void
    {
        delete_option(RestEndpoint::OPTION_SIGNING_SECRET);
        $res = $this->send(['article_id' => '0b8f1c2a-3d4e-4f5a-8b6c-7d8e9f0a1b2d', 'topic' => 't', 'html' => '<p>x</p>']);
        $this->assertSame(200, $res->get_status());
    }

    public function test_response_has_cms_post_id_next_to_post_id(): void
    {
        $res  = $this->send(['article_id' => '0b8f1c2a-3d4e-4f5a-8b6c-7d8e9f0a1b2e', 'topic' => 't', 'html' => '<p>x</p>']);
        $data = $res->get_data();
        $this->assertSame(200, $res->get_status());
        $this->assertIsInt($data['post_id']);
        $this->assertSame((string) $data['post_id'], $data['cms_post_id']);
    }

    public function test_error_500_does_not_leak_the_exception_text(): void
    {
        // wp_insert_post returns WP_Error -> PostWriter throws "wp_insert_post failed: ...".
        add_filter('wp_insert_post_empty_content', '__return_true');
        $res = $this->send(['article_id' => '0b8f1c2a-3d4e-4f5a-8b6c-7d8e9f0a1b2f', 'topic' => 't', 'html' => '<p>x</p>']);
        remove_filter('wp_insert_post_empty_content', '__return_true');

        $this->assertSame(500, $res->get_status());
        $this->assertSame(['error' => 'internal_error'], $res->get_data());
        $row = \TribuneDesk\WordPress\DeliveriesLog::recent()[0];
        $this->assertSame('error', $row['status']);
        $this->assertStringContainsString('wp_insert_post failed', (string) $row['error_msg']);
    }

    public function test_rejects_missing_secret(): void
    {
        $res = $this->send(['article_id' => 'a1', 'topic' => 't', 'html' => '<p>x</p>'], null);
        $this->assertSame(401, $res->get_status());
    }

    public function test_rejects_wrong_secret(): void
    {
        $res = $this->send(['article_id' => 'a1', 'topic' => 't', 'html' => '<p>x</p>'], 'wrong');
        $this->assertSame(401, $res->get_status());
    }

    /**
     * Oba namespace'y musza byc zarejestrowane. Stary ZOSTAJE NA ZAWSZE — to on jest
     * zapisany w `webhook_url` po naszej stronie dla kazdej istniejacej instalacji,
     * a klient moze nigdy nie zaktualizowac wtyczki.
     */
    public function test_both_namespaces_are_registered(): void
    {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/' . RestEndpoint::NAMESPACE . RestEndpoint::ROUTE, $routes);
        $this->assertArrayHasKey(
            '/' . RestEndpoint::NAMESPACE_BRAND . RestEndpoint::ROUTE,
            $routes
        );
    }

    /**
     * Nowy adres ma robic DOKLADNIE to samo, nie „cos podobnego". Gdyby ktos kiedys
     * rozdzielil to na dwa callbacki, ten test zlapie pierwsza rozbieznosc.
     */
    public function test_brand_namespace_creates_the_same_draft(): void
    {
        $res = $this->send_to(RestEndpoint::NAMESPACE_BRAND, [
            'article_id' => 'art-brand-1',
            'topic' => 'Test topic',
            'title' => 'Brand title',
            'html' => '<p>Body</p>',
        ]);
        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('created', $data['status']);

        $post = get_post($data['post_id']);
        $this->assertSame('draft', $post->post_status);
        $this->assertSame('Brand title', $post->post_title);
    }

    /**
     * Uwierzytelnianie nie moze byc slabsze na nowym adresie — inaczej dodanie
     * namespace'u byloby cicha dziura, a nie zmiana marki.
     */
    public function test_brand_namespace_rejects_wrong_secret(): void
    {
        $res = $this->send_to(
            RestEndpoint::NAMESPACE_BRAND,
            ['article_id' => 'a1', 'topic' => 't', 'html' => '<p>x</p>'],
            'wrong'
        );
        $this->assertSame(401, $res->get_status());
    }

    public function test_rejects_missing_fields(): void
    {
        $res = $this->send(['article_id' => 'a1']);
        $this->assertSame(400, $res->get_status());
    }

    public function test_creates_draft_post(): void
    {
        $res = $this->send([
            'article_id' => 'art-100',
            'topic' => 'Test topic',
            'title' => 'Test title',
            'html' => '<p>Body</p>',
            'related_topics' => [['title' => 'TagA'], ['title' => 'TagB']],
        ]);
        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('created', $data['status']);

        $post = get_post($data['post_id']);
        $this->assertSame('draft', $post->post_status);
        $this->assertSame('Test title', $post->post_title);
        $this->assertSame($this->author_id, (int) $post->post_author);
        $this->assertSame('art-100', get_post_meta($post->ID, PostWriter::META_ARTICLE_ID, true));

        // `related_topics` to PROPOZYCJE KOLEJNYCH ARTYKULOW, nie opis tego wpisu.
        // Mapowanie ich na tagi produkowalo taksonomie ze zdan: 96 tagow o sredniej
        // dlugosci 90 znakow na jednej stronie klienta, kazdy uzyty raz (2026-08-23).
        $tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
        $this->assertSame([], $tags);
    }

    public function test_dedupes_and_updates_existing_draft(): void
    {
        $first = $this->send(['article_id' => 'art-200', 'topic' => 't', 'html' => '<p>v1</p>'])->get_data();
        $second = $this->send(['article_id' => 'art-200', 'topic' => 't', 'title' => 'v2', 'html' => '<p>v2</p>'])->get_data();

        $this->assertSame('created', $first['status']);
        $this->assertSame('updated', $second['status']);
        $this->assertSame($first['post_id'], $second['post_id']);

        $post = get_post($second['post_id']);
        $this->assertStringContainsString('v2', $post->post_content);
    }

    public function test_skips_already_published(): void
    {
        $first = $this->send(['article_id' => 'art-300', 'topic' => 't', 'html' => '<p>v1</p>'])->get_data();
        wp_publish_post($first['post_id']);

        $second = $this->send(['article_id' => 'art-300', 'topic' => 't', 'html' => '<p>v2</p>'])->get_data();
        $this->assertSame('skipped_already_published', $second['status']);
        $this->assertSame($first['post_id'], $second['post_id']);

        $post = get_post($first['post_id']);
        $this->assertStringContainsString('v1', $post->post_content); // not overwritten
    }

    public function test_published_post_still_syncs_tags_without_overwriting_content(): void
    {
        // Re-send of an already-published article must NOT overwrite its content
        // but SHOULD still sync late-arriving metadata (tags, featured image).
        $first = $this->send(['article_id' => 'art-301', 'topic' => 't', 'html' => '<p>v1</p>'])->get_data();
        wp_publish_post($first['post_id']);

        $second = $this->send([
            'article_id' => 'art-301',
            'topic' => 't',
            'html' => '<p>v2</p>',
            'related_topics' => [['title' => 'LateTag']],
        ])->get_data();
        $this->assertSame('skipped_already_published', $second['status']);

        $post = get_post($first['post_id']);
        $this->assertStringContainsString('v1', $post->post_content); // content preserved
        $tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
        $this->assertSame([], $tags); // tematy na przyszlosc nie sa tagami tego wpisu
    }

    public function test_creates_draft_when_publish_option_off(): void
    {
        // domyślnie opcja publish-immediately jest wyłączona
        $res = $this->send(['article_id' => 'art-400', 'topic' => 't', 'html' => '<p>x</p>']);
        $this->assertSame(200, $res->get_status());
        $post = get_post($res->get_data()['post_id']);
        $this->assertSame('draft', $post->post_status);
    }

    public function test_creates_published_when_publish_option_on(): void
    {
        update_option(RestEndpoint::OPTION_PUBLISH, '1');
        $res = $this->send(['article_id' => 'art-401', 'topic' => 't', 'html' => '<p>x</p>']);
        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('created', $data['status']);
        $post = get_post($data['post_id']);
        $this->assertSame('publish', $post->post_status);
    }

    public function test_seo_fields_land_on_a_created_post(): void
    {
        $res = $this->send([
            'article_id'       => 'art-500',
            'topic'            => 't',
            'html'             => '<p>x</p>',
            'seo_title'        => 'Tytul SEO',
            'meta_description' => 'Opis meta',
            'focus_keyphrase'  => 'fraza glowna',
        ]);
        $this->assertSame(200, $res->get_status());

        $post_id = $res->get_data()['post_id'];
        $this->assertSame('Tytul SEO', get_post_meta($post_id, SeoMeta::META_TITLE, true));
        $this->assertSame('Opis meta', get_post_meta($post_id, SeoMeta::META_DESC, true));
        $this->assertSame('fraza glowna', get_post_meta($post_id, SeoMeta::META_FOCUS, true));
    }

    public function test_late_seo_fields_reach_an_already_published_post(): void
    {
        // Content agent bywa uruchamiany PO pierwszej wysylce, wiec meta
        // description potrafi dojsc dopiero za drugim razem — juz na
        // opublikowanym poscie. Tresc zostaje nietknieta, meta ma dojsc.
        $first = $this->send(['article_id' => 'art-501', 'topic' => 't', 'html' => '<p>v1</p>'])->get_data();
        wp_publish_post($first['post_id']);

        $second = $this->send([
            'article_id'       => 'art-501',
            'topic'            => 't',
            'html'             => '<p>v2</p>',
            'meta_description' => 'Pozny opis meta',
        ])->get_data();

        $this->assertSame('skipped_already_published', $second['status']);
        $post = get_post($first['post_id']);
        $this->assertStringContainsString('v1', $post->post_content); // tresc nietknieta
        $this->assertSame(
            'Pozny opis meta',
            get_post_meta($first['post_id'], SeoMeta::META_DESC, true)
        );
    }

    public function test_resend_to_a_draft_does_not_clobber_editor_seo_edit(): void
    {
        $first = $this->send([
            'article_id'       => 'art-502',
            'topic'            => 't',
            'html'             => '<p>v1</p>',
            'meta_description' => 'Opis pierwotny',
        ])->get_data();

        // Redaktor poprawia opis w Yoascie...
        update_post_meta($first['post_id'], SeoMeta::META_DESC, 'Opis redaktora');

        // ...a artykul przychodzi ponownie z inna wartoscia.
        $this->send([
            'article_id'       => 'art-502',
            'topic'            => 't',
            'html'             => '<p>v2</p>',
            'meta_description' => 'Opis nowy z HF',
        ]);

        $this->assertSame(
            'Opis redaktora',
            get_post_meta($first['post_id'], SeoMeta::META_DESC, true)
        );
    }

    public function test_internal_links_trafiaja_do_meta_przy_created(): void
    {
        $uuid_a = '11111111-1111-4111-8111-111111111111';
        $uuid_b = '22222222-2222-4222-8222-222222222222';
        $link   = $this->placeholder_link($uuid_b);

        $res = $this->send(['article_id' => $uuid_a, 'topic' => 't', 'html' => '<p>x</p>', 'internal_links' => [$link]]);

        $meta = get_post_meta($res->get_data()['post_id'], \TribuneDesk\WordPress\LinkResolver::META_LINKS, true);
        $this->assertSame([$link], $meta);
    }

    public function test_placeholder_podmieniony_przy_odbiorze_gdy_cel_opublikowany(): void
    {
        $uuid_a = '11111111-1111-4111-8111-111111111111';
        $uuid_b = '22222222-2222-4222-8222-222222222222';
        $target = (int) self::factory()->post->create([
            'post_status' => 'publish',
            'meta_input'  => [PostWriter::META_ARTICLE_ID => $uuid_b],
        ]);

        $res = $this->send([
            'article_id'     => $uuid_a,
            'topic'          => 't',
            'html'           => '<p><a href="https://headlinesforge.com/app/a/' . $uuid_b . '">kotwica</a></p>',
            'internal_links' => [$this->placeholder_link($uuid_b)],
        ]);

        $content = get_post($res->get_data()['post_id'])->post_content;
        $this->assertStringContainsString(get_permalink($target), $content);
        $this->assertStringNotContainsString('/app/a/', $content);
    }

    public function test_ponowna_wysylka_szkicu_odswieza_meta_a_pusta_lista_je_kasuje(): void
    {
        $uuid_a = '11111111-1111-4111-8111-111111111111';
        $l1     = $this->placeholder_link('22222222-2222-4222-8222-222222222222');
        $l2     = $this->placeholder_link('33333333-3333-4333-8333-333333333333');

        $post_id = $this->send(['article_id' => $uuid_a, 'topic' => 't', 'html' => '<p>1</p>', 'internal_links' => [$l1]])->get_data()['post_id'];
        $this->send(['article_id' => $uuid_a, 'topic' => 't', 'html' => '<p>2</p>', 'internal_links' => [$l2]]);
        $this->assertSame([$l2], get_post_meta($post_id, \TribuneDesk\WordPress\LinkResolver::META_LINKS, true));

        $this->send(['article_id' => $uuid_a, 'topic' => 't', 'html' => '<p>3</p>']);
        $this->assertSame('', get_post_meta($post_id, \TribuneDesk\WordPress\LinkResolver::META_LINKS, true));
    }

    public function test_meta_nietkniete_przy_skipped_already_published(): void
    {
        $uuid_a = '11111111-1111-4111-8111-111111111111';
        $l1     = $this->placeholder_link('22222222-2222-4222-8222-222222222222');
        $l2     = $this->placeholder_link('33333333-3333-4333-8333-333333333333');

        $post_id = $this->send(['article_id' => $uuid_a, 'topic' => 't', 'html' => '<p>1</p>', 'internal_links' => [$l1]])->get_data()['post_id'];
        wp_publish_post($post_id);

        $second = $this->send(['article_id' => $uuid_a, 'topic' => 't', 'html' => '<p>2</p>', 'internal_links' => [$l2]])->get_data();

        $this->assertSame('skipped_already_published', $second['status']);
        $this->assertSame([$l1], get_post_meta($post_id, \TribuneDesk\WordPress\LinkResolver::META_LINKS, true));
    }

    public function test_permalink_w_odpowiedzi_przy_publish_immediately(): void
    {
        update_option(RestEndpoint::OPTION_PUBLISH, '1');

        $data = $this->send(['article_id' => '44444444-4444-4444-8444-444444444444', 'topic' => 't', 'html' => '<p>x</p>'])->get_data();

        $this->assertSame(get_permalink($data['post_id']), $data['permalink']);
        $this->assertNotSame($data['edit_url'], $data['permalink']);
        $this->assertStringNotContainsString('wp-admin', $data['permalink']);
    }

    public function test_edit_url_bez_zalogowanego_uzytkownika(): void
    {
        // Delivery is authenticated by the signature header, never by a WordPress login:
        // the request runs as user 0, who may not edit posts. The edit link must still come back.
        wp_set_current_user(0);

        $data = $this->send(['article_id' => '66666666-6666-4666-8666-666666666666', 'topic' => 't', 'html' => '<p>x</p>'])->get_data();

        $this->assertSame(admin_url('post.php?post=' . $data['post_id'] . '&action=edit'), $data['edit_url']);
    }

    public function test_permalink_przy_skipped_already_published(): void
    {
        $first = $this->send(['article_id' => '55555555-5555-4555-8555-555555555555', 'topic' => 't', 'html' => '<p>x</p>'])->get_data();
        $this->assertArrayNotHasKey('permalink', $first); // szkic nie ma publicznego adresu
        wp_publish_post($first['post_id']);

        $second = $this->send(['article_id' => '55555555-5555-4555-8555-555555555555', 'topic' => 't', 'html' => '<p>y</p>'])->get_data();

        $this->assertSame('skipped_already_published', $second['status']);
        $this->assertSame(get_permalink($first['post_id']), $second['permalink']);
    }

    public function test_szkic_nie_ma_klucza_permalink(): void
    {
        $data = $this->send(['article_id' => '66666666-6666-4666-8666-666666666666', 'topic' => 't', 'html' => '<p>x</p>'])->get_data();

        $this->assertArrayNotHasKey('permalink', $data);
        $this->assertSame('draft', get_post_status($data['post_id']));
    }

    private const ASSET = '77777777-7777-4777-8777-777777777777';

    /** Plik PNG w uploads + zalacznik ze znakiem _hf_asset_id (jak po poprzedniej dostawie). */
    private function library_attachment(): int
    {
        $up = wp_upload_dir();
        $rel = 'hf-test/featured-contract.png';
        wp_mkdir_p($up['basedir'] . '/hf-test');
        file_put_contents($up['basedir'] . '/' . $rel, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $id = (int) wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title'     => 'Biblioteka',
            'post_status'    => 'inherit',
        ], $up['basedir'] . '/' . $rel);
        update_post_meta($id, '_wp_attached_file', $rel);
        update_post_meta($id, \TribuneDesk\WordPress\MediaHandler::META_ASSET_ID, self::ASSET);

        return $id;
    }

    private function payload_with_image(string $uuid, int $attachment_id): array
    {
        return [
            'article_id'       => $uuid,
            'topic'            => 't',
            'html'             => '<p>x</p>',
            'generated_images' => [[
                'url'               => 'https://example.test/result.jpg',
                'asset_id'          => self::ASSET,
                'cms_attachment_id' => $attachment_id,
            ]],
        ];
    }

    public function test_response_has_featured_image_contract(): void
    {
        $att = $this->library_attachment();

        $data = $this->send($this->payload_with_image('88888888-8888-4888-8888-888888888881', $att))->get_data();

        $this->assertArrayHasKey('featured_image', $data);
        $fi = $data['featured_image'];
        $this->assertSame(self::ASSET, $fi['asset_id']);
        $this->assertSame($att, $fi['attachment_id']);
        $this->assertIsInt($fi['attachment_id']);
        $this->assertSame(wp_get_original_image_url($att) ?: wp_get_attachment_url($att), $fi['url']);
        $this->assertStringNotContainsString('-scaled', $fi['url']);
    }

    public function test_second_delivery_still_reports_featured_image(): void
    {
        $att = $this->library_attachment();
        $payload = $this->payload_with_image('88888888-8888-4888-8888-888888888882', $att);
        $first = $this->send($payload)->get_data();
        wp_publish_post($first['post_id']);

        $second = $this->send($payload)->get_data();

        $this->assertSame('skipped_already_published', $second['status']);
        $this->assertSame($att, $second['featured_image']['attachment_id']);
        $this->assertSame(self::ASSET, $second['featured_image']['asset_id']);
    }

    public function test_no_asset_id_no_featured_image_key(): void
    {
        $data = $this->send(['article_id' => '88888888-8888-4888-8888-888888888883', 'topic' => 't', 'html' => '<p>x</p>'])->get_data();
        $this->assertArrayNotHasKey('featured_image', $data);

        $att = $this->library_attachment();
        delete_post_meta($att, \TribuneDesk\WordPress\MediaHandler::META_ASSET_ID);
        $payload = $this->payload_with_image('88888888-8888-4888-8888-888888888884', $att);
        unset($payload['generated_images'][0]['asset_id']);
        $data = $this->send($payload)->get_data();

        $this->assertNotSame(0, (int) get_post_thumbnail_id($data['post_id']));
        $this->assertArrayNotHasKey('featured_image', $data);
    }
}
