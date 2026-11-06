<?php
declare(strict_types=1);

use TribuneDesk\WordPress\UpdateManifest;

class Test_Update_Manifest extends WP_UnitTestCase
{
    /** @var array<string, mixed>|null */
    private $next_response = null;
    private int $http_calls = 0;

    public function set_up(): void
    {
        parent::set_up();
        UpdateManifest::flush();
        $this->http_calls = 0;
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        UpdateManifest::flush();
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_http($preempt, array $args, string $url)
    {
        if ($url !== UpdateManifest::MANIFEST_URL) {
            return $preempt;
        }
        $this->http_calls++;
        return $this->next_response;
    }

    private function serve(array $manifest, int $code = 200): void
    {
        $this->next_response = [
            'headers'  => [],
            'body'     => wp_json_encode($manifest),
            'response' => ['code' => $code, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function valid_manifest(array $overrides = []): array
    {
        return array_merge([
            'name'         => 'Tribune Desk Receiver',
            'slug'         => 'headlinesforge-receiver',
            'version'      => '0.9.9',
            'package'      => 'https://updates.headlinesforge.com/wp-plugin/headlinesforge-receiver-0.9.9.zip',
            'sha256'       => str_repeat('a', 64),
            'requires'     => '4.7',
            'tested'       => '7.1',
            'requires_php' => '8.3',
            'last_updated' => '2026-09-05 09:12:00',
            'sections'     => ['description' => 'opis', 'changelog' => '<h4>0.9.9</h4>'],
        ], $overrides);
    }

    /**
     * 2.2.1: a good manifest is cached for 1 h, so with WordPress's 12-hourly check a new version
     * reaches an install within ~13 h, not ~24 h.
     */
    public function test_good_manifest_is_cached_for_one_hour(): void
    {
        $this->serve($this->valid_manifest());
        $before = time();
        UpdateManifest::get();

        $this->assertSame(HOUR_IN_SECONDS, UpdateManifest::TTL_OK);
        $expires = (int) get_option('_site_transient_timeout_' . UpdateManifest::TRANSIENT);
        $this->assertGreaterThanOrEqual($before + HOUR_IN_SECONDS, $expires);
        $this->assertLessThanOrEqual(time() + HOUR_IN_SECONDS, $expires);
    }

    public function test_poprawny_manifest_wraca_jako_tablica(): void
    {
        $this->serve($this->valid_manifest());
        $got = UpdateManifest::get();
        $this->assertIsArray($got);
        $this->assertSame('0.9.9', $got['version']);
    }

    public function test_drugie_wywolanie_idzie_z_cache(): void
    {
        $this->serve($this->valid_manifest());
        UpdateManifest::get();
        UpdateManifest::get();
        $this->assertSame(1, $this->http_calls, 'Drugie wywolanie nie powinno robic requestu.');
    }

    public function test_force_omija_cache(): void
    {
        $this->serve($this->valid_manifest());
        UpdateManifest::get();
        UpdateManifest::get(true);
        $this->assertSame(2, $this->http_calls);
    }

    public function test_brak_sha256_odrzucony(): void
    {
        $m = $this->valid_manifest();
        unset($m['sha256']);
        $this->serve($m);
        $this->assertNull(UpdateManifest::get());
    }

    public function test_sha256_o_zlej_dlugosci_odrzucony(): void
    {
        $this->serve($this->valid_manifest(['sha256' => 'abc123']));
        $this->assertNull(UpdateManifest::get());
    }

    public function test_pakiet_po_http_odrzucony(): void
    {
        $this->serve($this->valid_manifest([
            'package' => 'http://updates.headlinesforge.com/wp-plugin/x.zip',
        ]));
        $this->assertNull(UpdateManifest::get());
    }

    public function test_pakiet_z_obcego_hosta_odrzucony(): void
    {
        $this->serve($this->valid_manifest([
            'package' => 'https://evil.example.com/wp-plugin/x.zip',
        ]));
        $this->assertNull(UpdateManifest::get());
    }

    public function test_http_500_daje_null(): void
    {
        $this->serve($this->valid_manifest(), 500);
        $this->assertNull(UpdateManifest::get());
    }

    public function test_smiec_zamiast_json_daje_null(): void
    {
        $this->next_response = [
            'headers'  => [],
            'body'     => '<html>404</html>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
        $this->assertNull(UpdateManifest::get());
    }

    public function test_blad_jest_cachowany_wiec_nie_ma_drugiego_requestu(): void
    {
        $this->serve($this->valid_manifest(), 500);
        UpdateManifest::get();
        UpdateManifest::get();
        $this->assertSame(1, $this->http_calls, 'Pustka po bledzie ma byc cachowana.');
    }

    /**
     * Nazwa marki stoi w DWOCH plikach i nic ich nie porownywalo.
     *
     * `bin/build-manifest.php` bierze nazwe z tytulu readme.txt, a WordPress pokazuje na
     * liscie wtyczek `Plugin Name` z pliku glownego. Rozjazd miedzy nimi jest niewidoczny
     * w kodzie i widoczny WYLACZNIE u klienta: co innego na liscie wtyczek, co innego
     * w powiadomieniu o aktualizacji.
     *
     * Do 1.0.0 builder mial nazwe WPISANA NA SZTYWNO, wiec przezylby zmiane obu
     * pozostalych miejsc — manifest rozsylalby stara marke po rebrandingu. Builder
     * biegnie wylacznie w release.yml i nie ma zadnego testu, wiec regresja w nim
     * trafia prosto do klientow.
     */
    public function test_nazwa_w_readme_i_w_naglowku_wtyczki_sie_zgadzaja(): void
    {
        $root   = dirname(__DIR__);
        $nl     = static fn (string $s): string => str_replace("\r\n", "\n", $s);
        $readme = $nl((string) file_get_contents($root . '/readme.txt'));
        $main   = $nl((string) file_get_contents($root . '/headlinesforge-receiver.php'));

        $this->assertSame(
            1,
            preg_match('/^===\s*(.+?)\s*===$/m', $readme, $r),
            'readme.txt musi miec tytul "=== Nazwa ===" — z niego builder bierze `name`.'
        );
        $this->assertSame(
            1,
            preg_match('/^\s*\*\s*Plugin Name:\s*(.+?)\s*$/m', $main, $h),
            'Plik glowny musi miec naglowek "Plugin Name:".'
        );

        $this->assertSame(
            trim($h[1]),
            trim($r[1]),
            'Nazwa w naglowku wtyczki i w tytule readme.txt musi byc ta sama.'
        );
    }
}
