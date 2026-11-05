<?php
declare(strict_types=1);

use TribuneDesk\WordPress\ProvenanceMeta;

class Test_ProvenanceMeta extends WP_UnitTestCase
{
    private const BLOCK = '<script type="application/ld+json" data-hf-provenance>'
        . '{"@context":"https:\/\/schema.org","@type":"NewsArticle","identifier":"abc-123",'
        . '"dateCreated":"2026-08-20T21:36:42+00:00",'
        . '"digitalSourceType":"http:\/\/cv.iptc.org\/newscodes\/digitalsourcetype\/trainedAlgorithmicMedia",'
        . '"hfProvenanceJws":"eyJhbGciOiJFZERTQSJ9.eyJhIjoxfQ.c2ln"}'
        . '</script>';

    private int $post_id;

    public function setUp(): void
    {
        parent::setUp();
        $this->post_id = self::factory()->post->create(['post_status' => 'draft']);
        // Flaga scalenia z Yoastem zyje przez cale zadanie HTTP; miedzy testami ja zerujemy.
        (new ReflectionProperty(ProvenanceMeta::class, 'merged_into_yoast'))->setValue(null, false);
    }

    public function test_yoast_article_filter_is_hooked(): void
    {
        $this->assertNotFalse(
            has_filter('wpseo_schema_article', [ProvenanceMeta::class, 'yoast_article'])
        );
    }

    public function test_with_yoast_the_marking_joins_the_article_and_no_second_object_is_rendered(): void
    {
        // 1.5.0: jeden opis tekstu. Yoast wola filtr przed naszym render() (wp_head 1 < 10).
        $published = self::factory()->post->create(['post_status' => 'publish']);
        ProvenanceMeta::sync($published, self::BLOCK);
        $this->go_to(get_permalink($published));

        $article = ProvenanceMeta::yoast_article(['@type' => 'Article', 'headline' => 'Tytul']);

        $this->assertSame('Article', $article['@type']);
        $this->assertSame('Tytul', $article['headline']);
        $this->assertSame(
            'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
            $article['digitalSourceType']
        );
        $this->assertSame('eyJhbGciOiJFZERTQSJ9.eyJhIjoxfQ.c2ln', $article['hfProvenanceJws']);
        $this->assertSame('abc-123', $article['identifier']);
        $this->assertArrayNotHasKey('dateCreated', $article);

        ob_start();
        ProvenanceMeta::render();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function test_yoast_fields_are_not_overwritten(): void
    {
        $published = self::factory()->post->create(['post_status' => 'publish']);
        ProvenanceMeta::sync($published, self::BLOCK);
        $this->go_to(get_permalink($published));

        $article = ProvenanceMeta::yoast_article(['@type' => 'Article', 'identifier' => 'yoast-id']);

        $this->assertSame('yoast-id', $article['identifier']);
    }

    public function test_without_marking_yoast_article_is_unchanged_and_nothing_is_rendered(): void
    {
        $published = self::factory()->post->create(['post_status' => 'publish']);
        $this->go_to(get_permalink($published));

        $input = ['@type' => 'Article', 'headline' => 'Tytul'];
        $this->assertSame($input, ProvenanceMeta::yoast_article($input));

        ob_start();
        ProvenanceMeta::render();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function test_render_is_hooked_into_wp_head(): void
    {
        // Bez tej asercji caly zestaw moze byc zielony przy niepodpietym renderze:
        // pozostale testy wolaja ProvenanceMeta::render() same, wiec nie zauwazyly by,
        // ze na prawdziwej stronie nikt jej nie wywoluje.
        $this->assertNotFalse(
            has_action('wp_head', [ProvenanceMeta::class, 'render'])
        );
    }

    public function test_stores_the_provenance_block_from_the_incoming_html(): void
    {
        ProvenanceMeta::sync($this->post_id, '<p>Tresc artykulu</p>' . self::BLOCK);

        $stored = get_post_meta($this->post_id, ProvenanceMeta::META_KEY, true);

        $this->assertIsArray($stored);
        $this->assertSame('eyJhbGciOiJFZERTQSJ9.eyJhIjoxfQ.c2ln', $stored['hfProvenanceJws']);
        $this->assertSame('abc-123', $stored['identifier']);
    }

    public function test_renders_the_block_on_a_single_post(): void
    {
        // To jest cel calej klasy: znakowanie ma byc na opublikowanej stronie
        // i czytelne maszynowo. Sprawdzamy wyjscie wp_head, nie zawartosc meta —
        // meta bez renderu jest z punktu widzenia art. 50 niczym.
        $published = self::factory()->post->create(['post_status' => 'publish']);
        ProvenanceMeta::sync($published, self::BLOCK);
        $this->go_to(get_permalink($published));

        ob_start();
        ProvenanceMeta::render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('type="application/ld+json"', $out);
        $this->assertStringContainsString('data-hf-provenance', $out);
        $this->assertStringContainsString('eyJhbGciOiJFZERTQSJ9.eyJhIjoxfQ.c2ln', $out);
    }

    public function test_post_writer_moves_provenance_out_of_the_content_into_meta(): void
    {
        // Regresja 2026-08-22: blok zostawal w tresci jako goly tekst, bo
        // PostWriter sanityzowal HTML, zanim ktokolwiek go z niego wyjal.
        // Kolejnosc jest tu cala trescia testu: najpierw wyjmij, potem sanityzuj.
        $author = self::factory()->user->create(['role' => 'administrator']);

        $result = \TribuneDesk\WordPress\PostWriter::write([
            'article_id' => 'art-prov-1',
            'topic'      => 'Temat',
            'title'      => 'Tytul artykulu',
            'html'       => '<p>Tresc artykulu</p>' . self::BLOCK,
        ], $author);

        $content = (string) get_post_field('post_content', $result['post_id']);
        $stored = get_post_meta($result['post_id'], ProvenanceMeta::META_KEY, true);

        $this->assertStringNotContainsString('hfProvenanceJws', $content);
        $this->assertStringContainsString('Tresc artykulu', $content);
        $this->assertSame('eyJhbGciOiJFZERTQSJ9.eyJhIjoxfQ.c2ln', $stored['hfProvenanceJws']);
    }

    public function test_resending_a_draft_refreshes_the_provenance(): void
    {
        // Znakowanie opisuje hash TRESCI, nie sam fakt wygenerowania artykulu.
        // Ponowna wysylka podmienia tresc szkicu, wiec stary podpis przestaje
        // do niej pasowac — musi zostac zastapiony, inaczej weryfikacja
        // zewnetrzna wykaze niezgodnosc i wyjdzie to jak falszerstwo.
        $author = self::factory()->user->create(['role' => 'administrator']);
        $block_v2 = str_replace('.c2ln', '.c2ln2', self::BLOCK);

        $first = \TribuneDesk\WordPress\PostWriter::write([
            'article_id' => 'art-prov-2',
            'title'      => 'Wersja pierwsza',
            'html'       => '<p>Wersja pierwsza</p>' . self::BLOCK,
        ], $author);

        $second = \TribuneDesk\WordPress\PostWriter::write([
            'article_id' => 'art-prov-2',
            'title'      => 'Wersja druga',
            'html'       => '<p>Wersja druga</p>' . $block_v2,
        ], $author);

        $this->assertSame($first['post_id'], $second['post_id']);
        $stored = get_post_meta($second['post_id'], ProvenanceMeta::META_KEY, true);
        $this->assertSame('eyJhbGciOiJFZERTQSJ9.eyJhIjoxfQ.c2ln2', $stored['hfProvenanceJws']);
    }

    public function test_sync_without_a_block_deletes_the_stored_provenance(): void
    {
        // 1.4.0 (D3): wydawca wylaczyl znakowanie, szkic przychodzi z nowa trescia
        // bez bloku. Stary podpis nie pasuje do tej tresci, wiec meta znika.
        ProvenanceMeta::sync($this->post_id, '<p>Tresc</p>' . self::BLOCK);
        $this->assertIsArray(get_post_meta($this->post_id, ProvenanceMeta::META_KEY, true));

        ProvenanceMeta::sync($this->post_id, '<p>Nowa tresc bez znakowania</p>');

        $this->assertFalse(metadata_exists('post', $this->post_id, ProvenanceMeta::META_KEY));
    }

    public function test_sync_with_an_invalid_block_deletes_the_stored_provenance(): void
    {
        // Blok bez hfProvenanceJws albo z zepsutym JSON-em = brak podpisu dla tej
        // tresci, czyli to samo co brak bloku.
        $no_jws = '<script type="application/ld+json" data-hf-provenance>{"@type":"NewsArticle"}</script>';
        $bad_json = '<script type="application/ld+json" data-hf-provenance>{nie-json</script>';

        foreach ([$no_jws, $bad_json] as $invalid) {
            ProvenanceMeta::sync($this->post_id, self::BLOCK);
            ProvenanceMeta::sync($this->post_id, '<p>Tresc</p>' . $invalid);

            $this->assertFalse(metadata_exists('post', $this->post_id, ProvenanceMeta::META_KEY));
        }
    }

    public function test_resending_a_draft_without_a_block_deletes_the_provenance(): void
    {
        // Ta sama sciezka od strony PostWriter: ponowna wysylka szkicu bez bloku.
        $author = self::factory()->user->create(['role' => 'administrator']);

        $first = \TribuneDesk\WordPress\PostWriter::write([
            'article_id' => 'art-prov-3',
            'title'      => 'Wersja pierwsza',
            'html'       => '<p>Wersja pierwsza</p>' . self::BLOCK,
        ], $author);
        $this->assertIsArray(get_post_meta($first['post_id'], ProvenanceMeta::META_KEY, true));

        $second = \TribuneDesk\WordPress\PostWriter::write([
            'article_id' => 'art-prov-3',
            'title'      => 'Wersja druga',
            'html'       => '<p>Wersja druga</p>',
        ], $author);

        $this->assertSame($first['post_id'], $second['post_id']);
        $this->assertFalse(metadata_exists('post', $second['post_id'], ProvenanceMeta::META_KEY));
    }

    public function test_resending_a_published_post_without_a_block_keeps_the_provenance(): void
    {
        // D3: opublikowanych nie ruszamy — podpis pasowal do tresci w chwili publikacji.
        $author = self::factory()->user->create(['role' => 'administrator']);

        $first = \TribuneDesk\WordPress\PostWriter::write([
            'article_id' => 'art-prov-4',
            'title'      => 'Wersja pierwsza',
            'html'       => '<p>Wersja pierwsza</p>' . self::BLOCK,
        ], $author);
        wp_update_post(['ID' => $first['post_id'], 'post_status' => 'publish']);

        \TribuneDesk\WordPress\PostWriter::write([
            'article_id' => 'art-prov-4',
            'title'      => 'Wersja druga',
            'html'       => '<p>Wersja druga</p>',
        ], $author);

        $stored = get_post_meta($first['post_id'], ProvenanceMeta::META_KEY, true);
        $this->assertSame('eyJhbGciOiJFZERTQSJ9.eyJhIjoxfQ.c2ln', $stored['hfProvenanceJws']);
    }
}
