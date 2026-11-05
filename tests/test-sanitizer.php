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

    public function test_strips_event_handlers(): void
    {
        $html = '<a href="https://x.com" onclick="alert(1)">link</a>';
        $out = Sanitizer::sanitize($html);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('href="https://x.com"', $out);
    }
}
