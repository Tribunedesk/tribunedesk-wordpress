<?php
declare(strict_types=1);

use TribuneDesk\WordPress\DeliveriesLog;
use TribuneDesk\WordPress\Language;

/**
 * 1.8.0: jezyk wpisu przez Polylang. Polylang nie jest zainstalowany w srodowisku testow,
 * wiec sciezke "Polylang aktywny" sprawdzamy atrapami funkcji przekazanymi jawnie.
 */
class Test_Language extends WP_UnitTestCase
{
    private int $post_id;

    /** @var array<int, array{0:int,1:string}> */
    private array $calls = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->post_id = self::factory()->post->create(['post_status' => 'draft']);
        $this->calls = [];
    }

    private function set(): callable
    {
        return function (int $post_id, string $lang): void {
            $this->calls[] = [$post_id, $lang];
        };
    }

    private static function languages(): callable
    {
        return static fn (): array => ['en', 'pl', 'de', 'es'];
    }

    public function test_without_polylang_the_field_is_ignored(): void
    {
        // U klientow bez Polylang: brak funkcji pll_* — nic sie nie dzieje, nic nie pada.
        $this->assertFalse(function_exists('pll_set_post_language'));
        $this->assertNull(Language::apply($this->post_id, ['language' => 'pl']));
    }

    public function test_sets_a_configured_language(): void
    {
        $out = Language::apply($this->post_id, ['language' => 'PL'], $this->set(), self::languages());

        $this->assertSame('pl', $out);
        $this->assertSame([[$this->post_id, 'pl']], $this->calls);
    }

    public function test_no_language_in_payload_changes_nothing(): void
    {
        $this->assertNull(Language::apply($this->post_id, [], $this->set(), self::languages()));
        $this->assertSame([], $this->calls);
    }

    public function test_unknown_language_is_logged_and_not_set(): void
    {
        $out = Language::apply(
            $this->post_id,
            ['language' => 'fr', 'article_id' => 'a-1'],
            $this->set(),
            self::languages()
        );

        $this->assertNull($out);
        $this->assertSame([], $this->calls);
        $last = DeliveriesLog::recent()[0];
        $this->assertSame('error', $last['status']);
        $this->assertStringContainsString('"fr"', (string) $last['error_msg']);
    }
}
