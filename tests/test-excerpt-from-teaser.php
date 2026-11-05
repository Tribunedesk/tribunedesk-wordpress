<?php
declare(strict_types=1);

use TribuneDesk\WordPress\PostWriter;
use TribuneDesk\WordPress\Settings;

/**
 * 1.3.0: teaser na Facebooka (`facebook_teaser`) do pustej zajawki wpisu — tylko z wlaczona opcja.
 * 2.0.1: bez opcji zajawka = `excerpt` z dostawy; z opcja teaser ma pierwszenstwo, `excerpt` jest zapasem.
 */
class Test_Excerpt_From_Teaser extends WP_UnitTestCase
{
    private const UUID = '77777777-7777-4777-8777-777777777777';

    private int $author;

    public function set_up(): void
    {
        parent::set_up();
        $this->author = self::factory()->user->create(['role' => 'editor']);
    }

    public function tear_down(): void
    {
        delete_option(PostWriter::OPTION_EXCERPT_FROM_TEASER);
        parent::tear_down();
    }

    private function payload(string $html = '<p>x</p>', ?string $teaser = 'Miała dla kogo żyć. Ogromna tragedia.'): array
    {
        return ['article_id' => self::UUID, 'title' => 'T', 'html' => $html, 'facebook_teaser' => $teaser];
    }

    public function test_bez_opcji_zajawka_zostaje_pusta(): void
    {
        $r = PostWriter::write($this->payload(), $this->author);

        $this->assertSame('', get_post($r['post_id'])->post_excerpt);
    }

    public function test_z_opcja_teaser_trafia_do_zajawki(): void
    {
        update_option(PostWriter::OPTION_EXCERPT_FROM_TEASER, '1');

        $r = PostWriter::write($this->payload(), $this->author);

        $this->assertSame('Miała dla kogo żyć. Ogromna tragedia.', get_post($r['post_id'])->post_excerpt);
    }

    public function test_ponowna_wysylka_nie_nadpisuje_zajawki_redaktora(): void
    {
        update_option(PostWriter::OPTION_EXCERPT_FROM_TEASER, '1');
        $r = PostWriter::write($this->payload(), $this->author);
        wp_update_post(['ID' => $r['post_id'], 'post_excerpt' => 'Ręczna zajawka']);

        PostWriter::write($this->payload('<p>nowa</p>', 'Inny teaser'), $this->author);

        $this->assertSame('Ręczna zajawka', get_post($r['post_id'])->post_excerpt);
        $this->assertSame('<p>nowa</p>', get_post($r['post_id'])->post_content);
    }

    public function test_ponowna_wysylka_wypelnia_pusta_zajawke(): void
    {
        $r = PostWriter::write($this->payload(), $this->author);
        update_option(PostWriter::OPTION_EXCERPT_FROM_TEASER, '1');

        PostWriter::write($this->payload('<p>nowa</p>'), $this->author);

        $this->assertSame('Miała dla kogo żyć. Ogromna tragedia.', get_post($r['post_id'])->post_excerpt);
    }

    public function test_brak_teasera_i_html_w_teaserze(): void
    {
        update_option(PostWriter::OPTION_EXCERPT_FROM_TEASER, '1');

        $r = PostWriter::write($this->payload('<p>x</p>', null), $this->author);
        $this->assertSame('', get_post($r['post_id'])->post_excerpt);

        $other = PostWriter::write(
            ['article_id' => '88888888-8888-4888-8888-888888888888', 'title' => 'T', 'html' => '<p>y</p>', 'facebook_teaser' => 'Szok <script>alert(1)</script>!'],
            $this->author
        );
        $this->assertStringNotContainsString('<script>', get_post($other['post_id'])->post_excerpt);
    }

    public function test_bez_opcji_zajawka_z_excerpt(): void
    {
        $payload = $this->payload() + ['excerpt' => 'Rada miasta przyjęła budżet.'];

        $r = PostWriter::write($payload, $this->author);

        $this->assertSame('Rada miasta przyjęła budżet.', get_post($r['post_id'])->post_excerpt);
    }

    public function test_z_opcja_teaser_wygrywa_z_excerpt(): void
    {
        update_option(PostWriter::OPTION_EXCERPT_FROM_TEASER, '1');
        $payload = $this->payload() + ['excerpt' => 'Rada miasta przyjęła budżet.'];

        $r = PostWriter::write($payload, $this->author);

        $this->assertSame('Miała dla kogo żyć. Ogromna tragedia.', get_post($r['post_id'])->post_excerpt);
    }

    public function test_z_opcja_bez_teasera_zajawka_z_excerpt(): void
    {
        update_option(PostWriter::OPTION_EXCERPT_FROM_TEASER, '1');

        foreach ([null, '', '   '] as $i => $teaser) {
            $payload = ['article_id' => sprintf('99999999-9999-4999-8999-99999999999%d', $i), 'title' => 'T', 'html' => '<p>x</p>', 'facebook_teaser' => $teaser, 'excerpt' => 'Opis meta.'];
            $r       = PostWriter::write($payload, $this->author);
            $this->assertSame('Opis meta.', get_post($r['post_id'])->post_excerpt, var_export($teaser, true));
        }
    }

    public function test_bez_opcji_pusty_excerpt_nie_ustawia_zajawki(): void
    {
        $r = PostWriter::write($this->payload() + ['excerpt' => null], $this->author);

        $this->assertSame('', get_post($r['post_id'])->post_excerpt);
    }

    public function test_pole_ustawien_jest_zarejestrowane(): void
    {
        global $wp_settings_fields;

        Settings::register_settings();

        $this->assertArrayHasKey(PostWriter::OPTION_EXCERPT_FROM_TEASER, $wp_settings_fields[Settings::PAGE_SLUG]['headlinesforge_theme_fields']);
        $this->assertSame('', get_registered_settings()[PostWriter::OPTION_EXCERPT_FROM_TEASER]['default']);
    }
}
