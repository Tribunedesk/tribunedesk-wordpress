<?php
declare(strict_types=1);

use TribuneDesk\WordPress\SeoMeta;
use TribuneDesk\WordPress\Settings;

/**
 * Pola motywu (1.1.0): opis meta i tytul na Facebooka kopiowane DODATKOWO do kluczy meta
 * wskazanych w ustawieniach. Puste ustawienie = zachowanie sprzed 1.1.0 (tylko Yoast).
 */
class Test_Theme_Meta_Fields extends WP_UnitTestCase
{
    private int $post_id;

    public function set_up(): void
    {
        parent::set_up();
        $this->post_id = self::factory()->post->create(['post_status' => 'draft']);
    }

    public function tear_down(): void
    {
        delete_option(SeoMeta::OPTION_DESC_KEY);
        delete_option(SeoMeta::OPTION_FB_TITLE_KEY);
        parent::tear_down();
    }

    public function test_bez_ustawien_nie_pisze_nic_poza_yoastem(): void
    {
        SeoMeta::sync($this->post_id, [
            'meta_description' => 'Opis z HF',
            'facebook_title'   => 'Tytul FB',
        ]);

        $this->assertSame('Opis z HF', get_post_meta($this->post_id, SeoMeta::META_DESC, true));
        $this->assertSame([], get_post_meta($this->post_id, 'post_meta_desc'));
        $this->assertSame([], get_post_meta($this->post_id, 'facebook_title'));
    }

    public function test_z_ustawieniami_pisze_do_pol_motywu_i_do_yoasta(): void
    {
        update_option(SeoMeta::OPTION_DESC_KEY, 'post_meta_desc');
        update_option(SeoMeta::OPTION_FB_TITLE_KEY, 'facebook_title');

        SeoMeta::sync($this->post_id, [
            'meta_description' => 'Opis z HF',
            'facebook_title'   => 'Pola "w dzungli" & reszta',
        ]);

        $this->assertSame('Opis z HF', get_post_meta($this->post_id, 'post_meta_desc', true));
        $this->assertSame('Opis z HF', get_post_meta($this->post_id, SeoMeta::META_DESC, true));
        // Surowy tekst: kodowanie to sprawa miejsca, ktore go wypisuje.
        $this->assertSame('Pola "w dzungli" & reszta', get_post_meta($this->post_id, 'facebook_title', true));
    }

    public function test_pole_motywu_wypelnione_przez_redaktora_zostaje(): void
    {
        update_option(SeoMeta::OPTION_FB_TITLE_KEY, 'facebook_title');
        update_post_meta($this->post_id, 'facebook_title', 'Reczny tytul FB');

        SeoMeta::sync($this->post_id, ['facebook_title' => 'Tytul z HF']);

        $this->assertSame('Reczny tytul FB', get_post_meta($this->post_id, 'facebook_title', true));
    }

    public function test_puste_pole_motywu_zalozone_przez_motyw_jest_wypelniane(): void
    {
        // Motyw klienta zaklada pusty rekord w save_post, zanim wtyczka dojdzie do glosu.
        update_option(SeoMeta::OPTION_FB_TITLE_KEY, 'facebook_title');
        update_post_meta($this->post_id, 'facebook_title', '');

        SeoMeta::sync($this->post_id, ['facebook_title' => 'Tytul z HF']);

        $this->assertSame('Tytul z HF', get_post_meta($this->post_id, 'facebook_title', true));
    }

    public function test_pusty_tytul_fb_nie_pisze_nic(): void
    {
        update_option(SeoMeta::OPTION_FB_TITLE_KEY, 'facebook_title');

        SeoMeta::sync($this->post_id, ['facebook_title' => null]);

        $this->assertSame([], get_post_meta($this->post_id, 'facebook_title'));
    }

    public function test_klucz_wskazujacy_na_pole_yoasta_nie_dubluje_zapisu(): void
    {
        update_option(SeoMeta::OPTION_DESC_KEY, SeoMeta::META_DESC);

        SeoMeta::sync($this->post_id, ['meta_description' => 'Opis']);

        $this->assertSame(['Opis'], get_post_meta($this->post_id, SeoMeta::META_DESC));
    }

    public function test_sanitize_klucza_meta(): void
    {
        $this->assertSame('post_meta_desc', Settings::sanitize_meta_key(' post_meta_desc '));
        $this->assertSame('facebook-title_2', Settings::sanitize_meta_key('facebook-title_2'));
        $this->assertSame('', Settings::sanitize_meta_key('zly klucz'));
        $this->assertSame('', Settings::sanitize_meta_key('<script>'));
        $this->assertSame('', Settings::sanitize_meta_key(''));
        $this->assertSame('', Settings::sanitize_meta_key(['tablica']));
    }

    public function test_pola_sa_zarejestrowane_w_ustawieniach(): void
    {
        global $wp_settings_fields;

        Settings::register_settings();

        $fields = $wp_settings_fields[Settings::PAGE_SLUG]['headlinesforge_theme_fields'];
        $this->assertArrayHasKey(SeoMeta::OPTION_DESC_KEY, $fields);
        $this->assertArrayHasKey(SeoMeta::OPTION_FB_TITLE_KEY, $fields);
        $registered = get_registered_settings();
        $this->assertSame('', $registered[SeoMeta::OPTION_DESC_KEY]['default']);
        $this->assertSame('', $registered[SeoMeta::OPTION_FB_TITLE_KEY]['default']);
    }

    public function test_pole_ustawien_pokazuje_zapisana_wartosc(): void
    {
        update_option(SeoMeta::OPTION_FB_TITLE_KEY, 'facebook_title');

        ob_start();
        Settings::render_fb_title_key_field();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('name="' . SeoMeta::OPTION_FB_TITLE_KEY . '"', $html);
        $this->assertStringContainsString('value="facebook_title"', $html);
    }
}
