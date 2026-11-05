<?php
declare(strict_types=1);

use TribuneDesk\WordPress\LinkResolver;
use TribuneDesk\WordPress\PostWriter;

class Test_Link_Resolver_Hooks extends WP_UnitTestCase
{
    private const UUID_A = '11111111-1111-4111-8111-111111111111';
    private const UUID_B = '22222222-2222-4222-8222-222222222222';
    private const PLACEHOLDER_B = 'https://headlinesforge.com/app/a/22222222-2222-4222-8222-222222222222';

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
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

    private function link_to_b(): array
    {
        return [
            'article_id'  => self::UUID_B,
            'href'        => self::PLACEHOLDER_B,
            'anchor_text' => 'kotwica',
            'kind'        => 'placeholder',
            'url'         => self::PLACEHOLDER_B,
            'headline'    => null,
        ];
    }

    /**
     * Wpis z placeholderem W TRESCI zapisany przez fabryke: filtr wp_insert_post_data
     * pomija nowe wpisy (brak ID w $postarr), wiec placeholder zostaje — tak wyglada
     * wpis A opublikowany ZANIM cel B istnial.
     */
    private function make_post(string $uuid, string $status, string $content, bool $with_links = true, string $type = 'post'): int
    {
        $meta = [PostWriter::META_ARTICLE_ID => $uuid];
        if ($with_links) {
            $meta[LinkResolver::META_LINKS] = wp_slash([$this->link_to_b()]);
        }

        return (int) self::factory()->post->create([
            'post_type'    => $type,
            'post_status'  => $status,
            'post_name'    => 'wpis-' . substr($uuid, 0, 4) . '-' . wp_rand(),
            'post_content' => $content,
            'meta_input'   => $meta,
        ]);
    }

    private function content_with_placeholder(): string
    {
        return '<p>Jak <a href="' . self::PLACEHOLDER_B . '">pisaliśmy</a>.</p>';
    }

    public function test_filtr_podmienia_przy_wp_update_post(): void
    {
        $b = $this->make_post(self::UUID_B, 'publish', '<p>B</p>', false);
        $a = $this->make_post(self::UUID_A, 'draft', $this->content_with_placeholder());

        wp_update_post(['ID' => $a, 'post_title' => 'edycja redaktora']);

        $content = get_post($a)->post_content;
        $this->assertStringContainsString(get_permalink($b), $content);
        $this->assertStringNotContainsString('/app/a/', $content);
    }

    public function test_filtr_nie_dotyka_wpisu_bez_meta(): void
    {
        $this->make_post(self::UUID_B, 'publish', '<p>B</p>', false);
        $a = $this->make_post(self::UUID_A, 'draft', $this->content_with_placeholder(), false);

        wp_update_post(['ID' => $a, 'post_title' => 'edycja']);

        $this->assertStringContainsString(self::PLACEHOLDER_B, get_post($a)->post_content);
    }

    public function test_filtr_nie_dotyka_innego_post_type(): void
    {
        $this->make_post(self::UUID_B, 'publish', '<p>B</p>', false);
        $page = $this->make_post(self::UUID_A, 'draft', $this->content_with_placeholder(), true, 'page');

        wp_update_post(['ID' => $page, 'post_title' => 'edycja']);

        $this->assertStringContainsString(self::PLACEHOLDER_B, get_post($page)->post_content);
    }

    public function test_filtr_bez_markera_nie_wykonuje_zapytan(): void
    {
        global $wpdb;
        $a      = $this->make_post(self::UUID_A, 'draft', '<p>bez linkow</p>');
        $data   = ['post_type' => 'post', 'post_content' => '<p>bez linkow</p>'];
        $before = $wpdb->num_queries;

        $out = LinkResolver::filter_post_data($data, ['ID' => $a]);

        $this->assertSame($data, $out);
        $this->assertSame($before, $wpdb->num_queries);
    }

    public function test_filtr_dziala_na_danych_ze_slashami(): void
    {
        $b = $this->make_post(self::UUID_B, 'publish', '<p>B</p>', false);
        $a = $this->make_post(self::UUID_A, 'draft', '<p>stara</p>');
        $data = ['post_type' => 'post', 'post_content' => '<p><a href=\"' . self::PLACEHOLDER_B . '\">x</a></p>'];

        $out = LinkResolver::filter_post_data($data, ['ID' => $a]);

        $this->assertSame('<p><a href=\"' . get_permalink($b) . '\">x</a></p>', $out['post_content']);
    }

    public function test_publikacja_szkicu_przez_edytor_rozwiazuje_wlasna_tresc(): void
    {
        $b = $this->make_post(self::UUID_B, 'publish', '<p>B</p>', false);
        $a = $this->make_post(self::UUID_A, 'draft', $this->content_with_placeholder());

        wp_update_post(['ID' => $a, 'post_status' => 'publish']);

        $this->assertStringContainsString(get_permalink($b), get_post($a)->post_content);
    }

    public function test_wp_publish_post_rozwiazuje_wlasna_tresc_przez_hak(): void
    {
        // wp_publish_post (cron dla zaplanowanych wpisow) omija wp_insert_post_data —
        // zabezpieczenie w haku transition_post_status musi to zlapac.
        $b = $this->make_post(self::UUID_B, 'publish', '<p>B</p>', false);
        $a = $this->make_post(self::UUID_A, 'draft', $this->content_with_placeholder());

        wp_publish_post($a);

        $this->assertSame('publish', get_post_status($a));
        $this->assertStringContainsString(get_permalink($b), get_post($a)->post_content);
    }

    public function test_publikacja_b_naprawia_opublikowane_a(): void
    {
        // B jest juz szkicem w WP, gdy A sie publikuje — dlatego placeholder w A zostaje
        // (1.1.0: link do celu, ktorego w WP nie ma wcale, publikacja zdejmuje).
        $b = $this->make_post(self::UUID_B, 'draft', '<p>B</p>', false);
        $a = $this->make_post(self::UUID_A, 'publish', $this->content_with_placeholder());
        $this->assertStringContainsString(self::PLACEHOLDER_B, get_post($a)->post_content, 'Warunek wstepny: A opublikowane z placeholderem.');

        wp_publish_post($b);

        $content = get_post($a)->post_content;
        $this->assertStringContainsString(get_permalink($b), $content);
        $this->assertStringNotContainsString('/app/a/', $content);
        $this->assertSame([$this->link_to_b()], LinkResolver::links_of($a)); // meta zostaje
    }

    public function test_publikacja_b_nie_rusza_szkicu_a(): void
    {
        $a = $this->make_post(self::UUID_A, 'draft', $this->content_with_placeholder());
        $b = $this->make_post(self::UUID_B, 'draft', '<p>B</p>', false);

        wp_publish_post($b);

        // Szkic samonaprawi sie przy wlasnym zapisie/publikacji (filtr) — tu ma zostac nietkniety.
        $this->assertStringContainsString(self::PLACEHOLDER_B, get_post($a)->post_content);
    }

    public function test_publikacja_b_nie_rusza_wpisu_bez_meta(): void
    {
        $c = $this->make_post(self::UUID_A, 'publish', $this->content_with_placeholder(), false);
        $b = $this->make_post(self::UUID_B, 'draft', '<p>B</p>', false);

        wp_publish_post($b);

        $this->assertStringContainsString(self::PLACEHOLDER_B, get_post($c)->post_content);
    }

    public function test_publish_do_publish_nie_uruchamia_odwrotnego_wyszukania(): void
    {
        global $wpdb;
        // A opublikowane z placeholderem, gdy B bylo jeszcze szkicem: fabryka pomija filtr
        // (brak ID), a hak publikacji A nie znajduje opublikowanego B, wiec placeholder zostaje.
        $b = $this->make_post(self::UUID_B, 'draft', '<p>B</p>', false);
        $a = $this->make_post(self::UUID_A, 'publish', $this->content_with_placeholder());
        // B staje sie opublikowane BEZ przejscia statusu widocznego dla hakow (zapis wprost
        // do tabeli) — jak wpis opublikowany, zanim wtyczka miala te haki.
        $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $b]);
        clean_post_cache($b);
        $this->assertSame('publish', get_post_status($b), 'Warunek wstepny: B opublikowane bez haka.');
        $this->assertStringContainsString(self::PLACEHOLDER_B, get_post($a)->post_content, 'Warunek wstepny: A trzyma placeholder.');

        // Edycja juz opublikowanego B = przejscie publish -> publish: hak nie moze ruszyc A.
        wp_update_post(['ID' => $b, 'post_title' => 'edycja opublikowanego B']);

        $this->assertStringContainsString(self::PLACEHOLDER_B, get_post($a)->post_content);
    }

    public function test_publikacja_zdejmuje_link_do_celu_ktorego_nie_ma_w_wp(): void
    {
        // Cel opublikowany poza wtyczka (np. przez Make) — WP go nie zna, wiec placeholder
        // nigdy by sie nie rozwiazal, a czytelnik dostalby link do aplikacji Tribune Desk.
        $a = $this->make_post(self::UUID_A, 'draft', $this->content_with_placeholder());

        wp_update_post(['ID' => $a, 'post_status' => 'publish']);

        $this->assertSame('<p>Jak pisaliśmy.</p>', get_post($a)->post_content);
    }

    public function test_wp_publish_post_zdejmuje_link_do_celu_ktorego_nie_ma_w_wp(): void
    {
        $a = $this->make_post(self::UUID_A, 'draft', $this->content_with_placeholder());

        wp_publish_post($a);

        $this->assertSame('<p>Jak pisaliśmy.</p>', get_post($a)->post_content);
    }

    public function test_publikacja_zostawia_link_do_celu_ktory_jest_szkicem(): void
    {
        $this->make_post(self::UUID_B, 'draft', '<p>B</p>', false);
        $a = $this->make_post(self::UUID_A, 'draft', $this->content_with_placeholder());

        wp_update_post(['ID' => $a, 'post_status' => 'publish']);

        $this->assertStringContainsString(self::PLACEHOLDER_B, get_post($a)->post_content);
    }

    public function test_zapis_szkicu_nie_zdejmuje_linku(): void
    {
        $a = $this->make_post(self::UUID_A, 'draft', $this->content_with_placeholder());

        wp_update_post(['ID' => $a, 'post_title' => 'edycja redaktora']);

        $this->assertStringContainsString(self::PLACEHOLDER_B, get_post($a)->post_content);
    }

    public function test_zdejmowanie_dziala_na_danych_ze_slashami_i_zostawia_inne_linki(): void
    {
        $a    = $this->make_post(self::UUID_A, 'draft', '<p>stara</p>');
        $data = [
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_content' => '<p><a href=\"' . self::PLACEHOLDER_B . '\" target=\"_blank\" rel=\"noopener\">Mandaryna <b>na weselu</b></a>'
                . ' i <a href=\"https://example.com/newsy/1.x\">inny</a></p>',
        ];

        $out = LinkResolver::filter_post_data($data, ['ID' => $a]);

        $this->assertSame(
            '<p>Mandaryna <b>na weselu</b> i <a href=\"https://example.com/newsy/1.x\">inny</a></p>',
            $out['post_content']
        );
    }

    public function test_haki_sa_zarejestrowane_z_wlasciwym_priorytetem(): void
    {
        $this->assertSame(10, has_filter('wp_insert_post_data', ['TribuneDesk\WordPress\LinkResolver', 'filter_post_data']));
        $this->assertSame(10, has_action('transition_post_status', ['TribuneDesk\WordPress\LinkResolver', 'on_transition']));
    }

    public function test_trafienie_limitu_odwrotnego_wyszukania_zostawia_wiersz_w_logu(): void
    {
        \TribuneDesk\WordPress\Activator::activate();
        add_filter('headlinesforge_reverse_limit', static fn(): int => 1);

        $b  = $this->make_post(self::UUID_B, 'draft', '<p>B</p>', false);
        $a1 = $this->make_post(self::UUID_A, 'publish', $this->content_with_placeholder());
        $a2 = $this->make_post('33333333-3333-4333-8333-333333333333', 'publish', $this->content_with_placeholder());

        wp_publish_post($b);

        remove_all_filters('headlinesforge_reverse_limit');

        $rows = array_values(array_filter(
            \TribuneDesk\WordPress\DeliveriesLog::recent(),
            static fn(array $row): bool => $row['status'] === 'reverse_limit'
        ));
        $this->assertCount(1, $rows, 'Trafienie limitu ma zostawic dokladnie jeden wiersz.');
        $this->assertSame(self::UUID_B, $rows[0]['article_id']);
        $this->assertStringContainsString('limit', (string) $rows[0]['error_msg']);

        // Jeden z dwoch wpisow zostal naprawiony, drugi czeka na wlasny zapis.
        $fixed = 0;
        foreach ([$a1, $a2] as $id) {
            if (!str_contains(get_post($id)->post_content, '/app/a/')) {
                $fixed++;
            }
        }
        $this->assertSame(1, $fixed);
    }
}
