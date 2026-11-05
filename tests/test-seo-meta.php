<?php
declare(strict_types=1);

use TribuneDesk\WordPress\SeoMeta;

class Test_SeoMeta extends WP_UnitTestCase
{
    private int $post_id;

    public function setUp(): void
    {
        parent::setUp();
        $this->post_id = self::factory()->post->create(['post_status' => 'draft']);
    }

    public function test_writes_all_three_yoast_meta_fields(): void
    {
        SeoMeta::sync($this->post_id, [
            'seo_title'        => 'Tytul SEO',
            'meta_description' => 'Opis meta',
            'focus_keyphrase'  => 'fraza glowna',
        ]);

        $this->assertSame('Tytul SEO', get_post_meta($this->post_id, SeoMeta::META_TITLE, true));
        $this->assertSame('Opis meta', get_post_meta($this->post_id, SeoMeta::META_DESC, true));
        $this->assertSame('fraza glowna', get_post_meta($this->post_id, SeoMeta::META_FOCUS, true));
    }

    public function test_does_not_overwrite_a_value_the_editor_already_set(): void
    {
        // Redaktor poprawil opis recznie w Yoascie. Ponowna wysylka tego samego
        // artykulu NIE MOZE tego skasowac — to jedyny scenariusz, w ktorym
        // wtyczka moglaby bezpowrotnie zniszczyc cudza prace.
        update_post_meta($this->post_id, SeoMeta::META_DESC, 'Reczny opis redaktora');

        SeoMeta::sync($this->post_id, ['meta_description' => 'Opis z HeadlinesForge']);

        $this->assertSame(
            'Reczny opis redaktora',
            get_post_meta($this->post_id, SeoMeta::META_DESC, true)
        );
    }

    public function test_fills_the_empty_field_and_leaves_the_filled_one_alone(): void
    {
        // Ochrona dziala per POLE, nie per post.
        update_post_meta($this->post_id, SeoMeta::META_TITLE, 'Reczny tytul');

        SeoMeta::sync($this->post_id, [
            'seo_title'        => 'Tytul z HF',
            'meta_description' => 'Opis z HF',
        ]);

        $this->assertSame('Reczny tytul', get_post_meta($this->post_id, SeoMeta::META_TITLE, true));
        $this->assertSame('Opis z HF', get_post_meta($this->post_id, SeoMeta::META_DESC, true));
    }

    public function test_stores_a_long_value_whole_without_truncating(): void
    {
        // Yoast NIE obcina zapisanej wartosci — mierzy ja i zapala pomaranczowa
        // lampke. Przyciecie po naszej stronie po cichu zmienialoby tekst,
        // ktorego redaktor nigdy nie zobaczy w oryginale (decyzja D7).
        $long = str_repeat('a', 300);

        SeoMeta::sync($this->post_id, ['meta_description' => $long]);

        $this->assertSame($long, get_post_meta($this->post_id, SeoMeta::META_DESC, true));
    }

    public function test_missing_null_blank_and_non_string_values_write_nothing(): void
    {
        SeoMeta::sync($this->post_id, [
            'seo_title'        => null,
            'meta_description' => '   ',
            'focus_keyphrase'  => 42, // nie-string nie moze wywrocic synchronizacji
        ]);

        $this->assertSame('', get_post_meta($this->post_id, SeoMeta::META_TITLE, true));
        $this->assertSame('', get_post_meta($this->post_id, SeoMeta::META_FOCUS, true));
        // Pusty string z get_post_meta(..., true) zwraca tez nieistniejacy klucz,
        // wiec sprawdz dodatkowo, ze w bazie NIE MA pustego rekordu.
        $this->assertSame([], get_post_meta($this->post_id, SeoMeta::META_DESC));
    }

    public function test_strips_html_and_script_content(): void
    {
        SeoMeta::sync($this->post_id, [
            'meta_description' => 'Opis <script>alert(1)</script> dalej',
        ]);

        $stored = get_post_meta($this->post_id, SeoMeta::META_DESC, true);
        $this->assertStringNotContainsString('<script>', $stored);
        $this->assertStringNotContainsString('alert(1)', $stored);
        $this->assertStringContainsString('Opis', $stored);
        $this->assertStringContainsString('dalej', $stored);
    }

    public function test_wordpress_sanitizer_strips_percent_hex_sequences(): void
    {
        // Test CHARAKTERYZUJACY, nie zyczenie. sanitize_text_field() usuwa
        // sekwencje pasujace do /%[a-f0-9]{2}/i, wiec zmienna szablonowa Yoasta
        // "%%date%%" zostaje okaleczona ("%da" wypada), a "%%sitename%%" nie
        // ("%si" to nie hex). Nie ma to znaczenia praktycznego — wysylamy
        // literalny tekst z modelu, nie szablony Yoasta. Test istnieje po to,
        // zeby nikt nie wzial tego za blad NASZEGO kodu.
        SeoMeta::sync($this->post_id, ['seo_title' => 'Tytul %%sitename%%']);
        $this->assertSame(
            'Tytul %%sitename%%',
            get_post_meta($this->post_id, SeoMeta::META_TITLE, true)
        );

        $other = self::factory()->post->create(['post_status' => 'draft']);
        SeoMeta::sync($other, ['seo_title' => '%%date%%']);
        $this->assertSame('%te%%', get_post_meta($other, SeoMeta::META_TITLE, true));
    }
}
