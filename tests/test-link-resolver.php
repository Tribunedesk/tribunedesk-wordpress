<?php
declare(strict_types=1);

use TribuneDesk\WordPress\LinkResolver;
use TribuneDesk\WordPress\PostWriter;

class Test_Link_Resolver extends WP_UnitTestCase
{
    private const UUID_B = 'af36be4b-1961-4f66-9723-705862656927';
    private const PLACEHOLDER_B = 'https://headlinesforge.com/app/a/af36be4b-1961-4f66-9723-705862656927';

    public function set_up(): void
    {
        parent::set_up();
        // Jak na produkcji: /%postname%/ — permalink konczy sie slashem, wiec test
        // "placeholder z koncowym /" sprawdza, ze nie powstaje "//".
        $this->set_permalink_structure('/%postname%/');
        // Publikacja wpisu z UUID odpala (od Tasku 6) callback do HF — zaslepka obowiazkowa.
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
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

    private function make_target(string $uuid, string $status = 'publish'): int
    {
        return (int) self::factory()->post->create([
            'post_status' => $status,
            'post_title'  => 'Cel ' . substr($uuid, 0, 8),
            'post_name'   => 'cel-' . substr($uuid, 0, 8),
            'meta_input'  => [PostWriter::META_ARTICLE_ID => $uuid],
        ]);
    }

    private function link(string $uuid, string $kind = 'placeholder', ?string $href = null): array
    {
        return [
            'article_id'  => $uuid,
            'href'        => $href ?? 'https://headlinesforge.com/app/a/' . $uuid,
            'anchor_text' => 'tekst kotwicy',
            'kind'        => $kind,
            'url'         => $href ?? 'https://headlinesforge.com/app/a/' . $uuid,
            'headline'    => null,
        ];
    }

    // --- 1.17.0: placeholder format from link_placeholder_prefix (spec D6) ---------------

    public function test_marker_follows_the_prefix_from_the_payload(): void
    {
        delete_option(LinkResolver::OPTION_PLACEHOLDER_PATH);
        LinkResolver::remember_prefix(['link_placeholder_prefix' => 'https://tribunedesk.com/td/x/']);
        $this->assertSame('/td/x/', LinkResolver::marker());

        $target = $this->make_target(self::UUID_B);
        $href   = 'https://tribunedesk.com/td/x/' . self::UUID_B;
        $out    = LinkResolver::resolve('<p><a href="' . $href . '">tekst kotwicy</a></p>', [$this->link(self::UUID_B, 'placeholder', $href)]);

        $this->assertStringContainsString('href="' . get_permalink($target) . '"', $out);
    }

    public function test_old_domain_placeholder_still_resolves_with_new_prefix(): void
    {
        LinkResolver::remember_prefix(['link_placeholder_prefix' => 'https://tribunedesk.com/app/a/']);
        $target = $this->make_target(self::UUID_B);
        $old    = 'https://app.headlinesforge.com/app/a/' . self::UUID_B;

        $out = LinkResolver::resolve('<p><a href="' . $old . '">tekst kotwicy</a></p>', [$this->link(self::UUID_B, 'placeholder', $old)]);

        $this->assertStringContainsString('href="' . get_permalink($target) . '"', $out);
    }

    public function test_bad_or_missing_prefix_keeps_the_default_marker(): void
    {
        delete_option(LinkResolver::OPTION_PLACEHOLDER_PATH);
        foreach (['ftp://tribunedesk.com/app/b/', 'https://tribunedesk.com', 'https://tribunedesk.com/app/b', 42, ''] as $bad) {
            LinkResolver::remember_prefix(['link_placeholder_prefix' => $bad]);
            $this->assertSame('/app/a/', LinkResolver::marker(), var_export($bad, true));
        }
        LinkResolver::remember_prefix([]);
        $this->assertSame('/app/a/', LinkResolver::marker());
    }

    public function test_delivery_stores_the_prefix_path(): void
    {
        delete_option(LinkResolver::OPTION_PLACEHOLDER_PATH);
        PostWriter::write([
            'article_id'              => self::UUID_B,
            'topic'                   => 't',
            'title'                   => 'T',
            'html'                    => '<p>x</p>',
            'link_placeholder_prefix' => 'https://tribunedesk.com/td/x/',
        ], 1, false);
        $this->assertSame('/td/x/', get_option(LinkResolver::OPTION_PLACEHOLDER_PATH));
    }

    public function test_podmienia_placeholder_celu_opublikowanego(): void
    {
        $target = $this->make_target(self::UUID_B);
        $html   = '<p>Zobacz <a href="' . self::PLACEHOLDER_B . '">tekst kotwicy</a>.</p>';

        $out = LinkResolver::resolve($html, [$this->link(self::UUID_B)]);

        $this->assertStringContainsString('href="' . get_permalink($target) . '"', $out);
        $this->assertStringNotContainsString(LinkResolver::PLACEHOLDER_MARKER, $out);
        $this->assertStringContainsString('>tekst kotwicy</a>', $out); // tekst kotwicy nietkniety
    }

    public function test_nie_dotyka_celu_w_szkicu(): void
    {
        $this->make_target(self::UUID_B, 'draft');
        $html = '<p><a href="' . self::PLACEHOLDER_B . '">x</a></p>';

        $this->assertSame($html, LinkResolver::resolve($html, [$this->link(self::UUID_B)]));
    }

    public function test_nie_dotyka_nieznanego_celu(): void
    {
        $html = '<p><a href="' . self::PLACEHOLDER_B . '">x</a></p>';

        $this->assertSame($html, LinkResolver::resolve($html, [$this->link(self::UUID_B)]));
    }

    public function test_pozycja_kind_cms_jest_ignorowana(): void
    {
        // Dla kind=cms HF podmienil href juz u siebie; gdyby jednak w tresci zostal
        // placeholder, resolver go NIE rusza — decyduje meta, nie wzorzec hosta.
        $this->make_target(self::UUID_B);
        $html = '<p><a href="' . self::PLACEHOLDER_B . '">x</a></p>';

        $out = LinkResolver::resolve($html, [$this->link(self::UUID_B, 'cms', 'https://politicsonline.example/cel/')]);

        $this->assertSame($html, $out);
    }

    public function test_tresc_ze_slashami_jak_w_wp_insert_post_data(): void
    {
        $target = $this->make_target(self::UUID_B);
        $html   = '<p><a href=\"' . self::PLACEHOLDER_B . '\">x</a></p>';

        $out = LinkResolver::resolve($html, [$this->link(self::UUID_B)]);

        $this->assertSame('<p><a href=\"' . get_permalink($target) . '\">x</a></p>', $out);
    }

    public function test_koncowy_slash_i_dwa_wystapienia(): void
    {
        $target    = $this->make_target(self::UUID_B);
        $permalink = get_permalink($target);
        $html      = '<a href="' . self::PLACEHOLDER_B . '/">a</a> i <a href=\'' . self::PLACEHOLDER_B . '\'>b</a>';

        $out = LinkResolver::resolve($html, [$this->link(self::UUID_B)]);

        $this->assertSame(2, substr_count($out, $permalink));
        $this->assertStringNotContainsString($permalink . '/', $out); // brak podwojnego slasha
        $this->assertStringNotContainsString(LinkResolver::PLACEHOLDER_MARKER, $out);
    }

    public function test_nie_rusza_dluzszego_url_z_tym_samym_prefiksem(): void
    {
        $this->make_target(self::UUID_B);
        $html = '<a href="' . self::PLACEHOLDER_B . '-wersja-2">x</a>';

        $this->assertSame($html, LinkResolver::resolve($html, [$this->link(self::UUID_B)]));
    }

    public function test_idempotentne(): void
    {
        $this->make_target(self::UUID_B);
        $html = '<p><a href="' . self::PLACEHOLDER_B . '">x</a></p>';

        $once  = LinkResolver::resolve($html, [$this->link(self::UUID_B)]);
        $twice = LinkResolver::resolve($once, [$this->link(self::UUID_B)]);

        $this->assertSame($once, $twice);
    }

    public function test_bez_markera_zero_zapytan(): void
    {
        global $wpdb;
        $links  = [$this->link(self::UUID_B)];
        $before = $wpdb->num_queries;

        $out = LinkResolver::resolve('<p>zwykla tresc bez linkow</p>', $links);

        $this->assertSame('<p>zwykla tresc bez linkow</p>', $out);
        $this->assertSame($before, $wpdb->num_queries);
    }

    public function test_store_links_zapisuje_i_kasuje_meta(): void
    {
        $post_id = (int) self::factory()->post->create(['post_status' => 'draft']);
        $links   = [$this->link(self::UUID_B)];
        $links[0]['anchor_text'] = 'cytat z "cudzyslowem" i \\ backslashem';

        LinkResolver::store_links($post_id, $links);
        $this->assertSame($links, LinkResolver::links_of($post_id));

        LinkResolver::store_links($post_id, []);
        $this->assertSame([], LinkResolver::links_of($post_id));
        $this->assertSame('', get_post_meta($post_id, LinkResolver::META_LINKS, true));
    }

    public function test_article_id_of_zwraca_tylko_uuid_dla_post(): void
    {
        $ok   = get_post($this->make_target(self::UUID_B, 'draft'));
        $bad  = get_post((int) self::factory()->post->create(['meta_input' => [PostWriter::META_ARTICLE_ID => 'art-100']]));
        $none = get_post((int) self::factory()->post->create());
        $page = get_post((int) self::factory()->post->create(['post_type' => 'page', 'meta_input' => [PostWriter::META_ARTICLE_ID => self::UUID_B]]));

        $this->assertSame(self::UUID_B, PostWriter::article_id_of($ok));
        $this->assertNull(PostWriter::article_id_of($bad));
        $this->assertNull(PostWriter::article_id_of($none));
        $this->assertNull(PostWriter::article_id_of($page));
    }
}
