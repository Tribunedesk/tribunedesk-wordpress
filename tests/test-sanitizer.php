<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Sanitizer;

class Test_Sanitizer extends WP_UnitTestCase
{
    public function test_strips_script_tags(): void
    {
        $html = '<p>Hi</p><script>alert(1)</script>';
        $out = Sanitizer::sanitize($html);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('<p>Hi</p>', $out);
    }

    public function test_removes_script_content_not_only_the_tag(): void
    {
        // wp_kses usuwa NIEDOZWOLONY ZNACZNIK, ale zostawia jego zawartosc jako
        // goly tekst. Dokladnie tak blok znakowania AI (JSON-LD) wyladowal na
        // opublikowanej stronie jako akapit base64 — 2 wpisy na politicsonline,
        // 1 na chronicle (2026-08-22).
        //
        // test_strips_script_tags wyzej tego nie lapal: sprawdzal tylko brak
        // "<script", a tresc skryptu przechodzila dalej.
        $html = '<p>Hi</p><script>alert(1)</script>';
        $out = Sanitizer::sanitize($html);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('<p>Hi</p>', $out);
    }

    public function test_keeps_whitelisted_youtube_iframe(): void
    {
        $html = '<iframe src="https://www.youtube.com/embed/abc"></iframe>';
        $out = Sanitizer::sanitize($html);
        $this->assertStringContainsString('youtube.com/embed/abc', $out);
    }

    public function test_drops_non_whitelisted_iframe(): void
    {
        $html = '<iframe src="https://evil.com/x"></iframe>';
        $out = Sanitizer::sanitize($html);
        $this->assertStringNotContainsString('iframe', $out);
    }

    /** 2.7.1 (audit 27.11): an iframe without `</iframe>` did not match the pattern and stayed. */
    public function test_drops_unclosed_foreign_iframe(): void
    {
        $out = Sanitizer::sanitize('<p>a</p><iframe src="https://evil.example/x"><p>b</p>');
        $this->assertStringNotContainsString('evil.example', $out);
        $this->assertStringNotContainsString('<iframe', $out);
        $this->assertStringContainsString('<p>a</p>', $out);
    }

    /** An unclosed allowed embed is kept and closed; what follows it stays visible. */
    public function test_closes_unclosed_allowed_iframe(): void
    {
        $out = Sanitizer::sanitize('<iframe src="https://www.youtube.com/embed/abc"><p>after</p>');
        $this->assertStringContainsString('<iframe src="https://www.youtube.com/embed/abc"></iframe>', $out);
        $this->assertStringContainsString('<p>after</p>', $out);
    }

    /** The pattern took the LAST `src=` of the tag, here the one inside `title`. */
    public function test_src_inside_another_attribute_does_not_count(): void
    {
        foreach ([
            '<iframe src="https://evil.example/x" title=\'src="https://www.youtube.com/embed/abc"\'></iframe>',
            '<iframe src="https://evil.example/x" title="src=\'https://www.youtube.com/embed/abc\'"></iframe>',
        ] as $html) {
            $out = Sanitizer::sanitize($html);
            $this->assertStringNotContainsString('evil.example', $out, $html);
            $this->assertStringNotContainsString('<iframe', $out, $html);
        }
    }

    /** `data-src` is not `src`. */
    public function test_data_src_does_not_count(): void
    {
        $out = Sanitizer::sanitize('<iframe src="https://evil.example/x" data-src="https://www.youtube.com/embed/abc"></iframe>');
        $this->assertStringNotContainsString('evil.example', $out);
    }

    /** An iframe hidden inside an allowed one disappears with the allowed one's content. */
    public function test_nested_iframe_is_dropped(): void
    {
        $out = Sanitizer::sanitize('<iframe src="https://www.youtube.com/embed/abc"><iframe src="https://evil.example/x"></iframe></iframe>');
        $this->assertStringNotContainsString('evil.example', $out);
        $this->assertSame(1, substr_count($out, '<iframe'));
    }

    /**
     * The host is compared after parsing the address: a longer host, user info, a backslash or
     * another scheme never passes.
     *
     * @dataProvider foreign_srcs
     */
    public function test_host_checked_after_parsing(string $src): void
    {
        $out = Sanitizer::sanitize('<iframe src="' . $src . '"></iframe>');
        $this->assertStringNotContainsString('<iframe', $out, $src);
    }

    /** @return array<string, array{string}> */
    public function foreign_srcs(): array
    {
        return [
            'longer host'   => ['https://www.youtube.com.evil.example/embed/x'],
            'user info'     => ['https://www.youtube.com:x@evil.example/embed/x'],
            'backslash'     => ['https://evil.example\\@www.youtube.com/embed/x'],
            'wrong path'    => ['https://www.youtube.com/watch?v=x'],
            'relative'      => ['//www.youtube.com/embed/x'],
            'port'          => ['https://www.youtube.com:8443/embed/x'],
        ];
    }

    /** @dataProvider allowed_srcs */
    public function test_allowed_embeds_stay(string $src): void
    {
        $out = Sanitizer::sanitize('<iframe src="' . $src . '" width="560"></iframe>');
        $this->assertStringContainsString('<iframe', $out, $src);
    }

    /** @return array<string, array{string}> */
    public function allowed_srcs(): array
    {
        return [
            'youtube'      => ['https://www.youtube.com/embed/abc?start=10&amp;rel=0'],
            'youtube bare' => ['https://youtube.com/embed/abc'],
            'upper host'   => ['https://WWW.YOUTUBE.COM/embed/abc'],
            'vimeo'        => ['https://player.vimeo.com/video/1'],
            'facebook'     => ['https://www.facebook.com/plugins/post.php?href=x'],
        ];
    }

    public function test_strips_event_handlers(): void
    {
        $html = '<a href="https://x.com" onclick="alert(1)">link</a>';
        $out = Sanitizer::sanitize($html);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('href="https://x.com"', $out);
    }
}
