<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\AuthorResolver;
use TribuneDesk\WordPress\RestEndpoint;
use TribuneDesk\WordPress\Settings;

/** Autor wpisu z osob przyslanych przez Tribune Desk (spec HF 2026-10-20 autor wpisu). */
class Test_AuthorResolver extends WP_UnitTestCase
{
    private int $default_id;

    public function setUp(): void
    {
        parent::setUp();
        $this->default_id = self::factory()->user->create(['role' => 'editor']);
        update_option(RestEndpoint::OPTION_SECRET, 'test-secret');
        update_option(RestEndpoint::OPTION_DEFAULT_AUTHOR, $this->default_id);
        update_option(Activator::OPTION_INSTALL_USER, $this->default_id);
        delete_option(AuthorResolver::OPTION_MAP);
        delete_option(AuthorResolver::OPTION_SEEN);
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

    private function person(string $id, string $email = '', string $name = ''): array
    {
        return ['id' => $id, 'name' => $name, 'email' => $email];
    }

    private function send(array $extra, string $article_id = 'a-autor'): int
    {
        $req = new WP_REST_Request('POST', '/' . RestEndpoint::NAMESPACE . RestEndpoint::ROUTE);
        $req->set_header('x_webhook_secret', 'test-secret');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body((string) json_encode(array_merge(
            ['article_id' => $article_id, 'topic' => 't', 'html' => '<p>x</p>'],
            $extra
        )));
        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status());

        return (int) get_post((int) $res->get_data()['post_id'])->post_author;
    }

    public function test_bez_osob_w_payloadzie_autor_domyslny_jak_w_1_0_0(): void
    {
        $this->assertSame($this->default_id, $this->send([]));
        $this->assertSame([], AuthorResolver::seen());
    }

    public function test_wysylajacy_po_emailu_ma_pierwszenstwo_przed_autorem(): void
    {
        $sender = self::factory()->user->create(['role' => 'author', 'user_email' => 'anna@example.com']);
        self::factory()->user->create(['role' => 'author', 'user_email' => 'jan@example.com']);

        $this->assertSame($sender, $this->send([
            'sent_by' => $this->person('kp_anna', 'anna@example.com', 'Anna'),
            'author'  => $this->person('kp_jan', 'jan@example.com', 'Jan'),
        ]));
    }

    public function test_nieprzypisany_wysylajacy_spada_na_autora_artykulu(): void
    {
        $writer = self::factory()->user->create(['role' => 'author', 'user_email' => 'jan@example.com']);

        $this->assertSame($writer, $this->send([
            'sent_by' => $this->person('kp_obcy', 'nieznany@example.com'),
            'author'  => $this->person('kp_jan', 'jan@example.com'),
        ]));
    }

    public function test_reczne_przypisanie_wygrywa_z_emailem(): void
    {
        self::factory()->user->create(['role' => 'author', 'user_email' => 'anna@example.com']);
        $mapped = self::factory()->user->create(['role' => 'editor']);
        update_option(AuthorResolver::OPTION_MAP, ['kp_anna' => $mapped]);

        $this->assertSame($mapped, $this->send(['sent_by' => $this->person('kp_anna', 'anna@example.com')]));
    }

    public function test_konto_bez_edit_posts_nie_zostaje_autorem(): void
    {
        $sub = self::factory()->user->create(['role' => 'subscriber', 'user_email' => 'anna@example.com']);
        update_option(AuthorResolver::OPTION_MAP, ['kp_jan' => $sub]);

        $this->assertSame($this->default_id, $this->send([
            'sent_by' => $this->person('kp_anna', 'anna@example.com'),
            'author'  => $this->person('kp_jan'),
        ]));
    }

    public function test_przypisanie_do_usunietego_konta_spada_na_email(): void
    {
        $by_email = self::factory()->user->create(['role' => 'author', 'user_email' => 'anna@example.com']);
        update_option(AuthorResolver::OPTION_MAP, ['kp_anna' => 999999]);

        $this->assertSame($by_email, $this->send(['sent_by' => $this->person('kp_anna', 'anna@example.com')]));
    }

    public function test_osoba_bez_id_jest_pomijana(): void
    {
        self::factory()->user->create(['role' => 'author', 'user_email' => 'anna@example.com']);

        $this->assertSame(
            $this->default_id,
            $this->send(['sent_by' => ['email' => 'anna@example.com'], 'author' => null])
        );
    }

    public function test_ponowna_wysylka_do_szkicu_zmienia_autora(): void
    {
        $anna = self::factory()->user->create(['role' => 'author', 'user_email' => 'anna@example.com']);
        $jan = self::factory()->user->create(['role' => 'author', 'user_email' => 'jan@example.com']);

        $this->assertSame($anna, $this->send(['sent_by' => $this->person('kp_anna', 'anna@example.com')]));
        $this->assertSame($jan, $this->send(['sent_by' => $this->person('kp_jan', 'jan@example.com')]));
    }

    public function test_opublikowany_wpis_zachowuje_autora(): void
    {
        $anna = self::factory()->user->create(['role' => 'author', 'user_email' => 'anna@example.com']);
        self::factory()->user->create(['role' => 'author', 'user_email' => 'jan@example.com']);
        update_option(RestEndpoint::OPTION_PUBLISH, '1');

        $this->assertSame($anna, $this->send(['sent_by' => $this->person('kp_anna', 'anna@example.com')]));
        $this->assertSame($anna, $this->send(['sent_by' => $this->person('kp_jan', 'jan@example.com')]));
    }

    public function test_zapamietuje_osoby_i_nie_gubi_danych_przy_samym_id(): void
    {
        $this->send([
            'sent_by' => $this->person('kp_anna', 'anna@example.com', 'Anna Nowak'),
            'author'  => $this->person('kp_jan', 'jan@example.com', 'Jan Kowalski'),
        ]);
        // Stary wiersz dostawy w Tribune Desku: samo id wysylajacego.
        $this->send(['sent_by' => ['id' => 'kp_anna', 'name' => null, 'email' => null]], 'a-autor-2');

        $seen = AuthorResolver::seen();
        $this->assertSame(['kp_anna', 'kp_jan'], array_keys($seen));
        $this->assertSame('Anna Nowak', $seen['kp_anna']['name']);
        $this->assertSame('anna@example.com', $seen['kp_anna']['email']);
        $this->assertArrayNotHasKey(AuthorResolver::OPTION_SEEN, wp_load_alloptions(), 'Lista bez autoloadu.');
    }

    public function test_lista_widzianych_ma_limit(): void
    {
        $seen = [];
        for ($i = 0; $i < AuthorResolver::SEEN_LIMIT; $i++) {
            $seen['kp_' . $i] = ['name' => 'X', 'email' => '', 'last_seen' => 1000 + $i];
        }
        update_option(AuthorResolver::OPTION_SEEN, $seen, false);

        $this->send(['sent_by' => $this->person('kp_nowy')]);

        $after = AuthorResolver::seen();
        $this->assertCount(AuthorResolver::SEEN_LIMIT, $after);
        $this->assertArrayHasKey('kp_nowy', $after);
        $this->assertArrayNotHasKey('kp_0', $after, 'Wypada najdawniej widziany.');
    }

    public function test_sanitizacja_mapy_usuwa_zera_i_smieci(): void
    {
        $this->assertSame(
            ['kp_a' => 5],
            AuthorResolver::sanitize_map(['kp_a' => '5', 'kp_b' => '0', '' => 3, 'kp_c' => 'abc'])
        );
        $this->assertSame([], AuthorResolver::sanitize_map('nie-tablica'));
    }

    public function test_ekran_pokazuje_osoby_takze_przypisane_spoza_listy_widzianych(): void
    {
        $mapped = self::factory()->user->create(['role' => 'editor', 'display_name' => 'Redaktor Mapowany']);
        update_option(AuthorResolver::OPTION_MAP, ['kp_stary' => $mapped]);
        $this->send(['sent_by' => $this->person('kp_anna', '', 'Anna Nowak')]);

        ob_start();
        Settings::render_authors_section();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Anna Nowak', $html);
        $this->assertStringContainsString('name="headlinesforge_author_map[kp_stary]"', $html);
        $this->assertStringContainsString('Redaktor Mapowany — przypisane ręcznie', $html);
        $this->assertStringContainsString('Default author (brak dopasowania)', $html);
    }
}
