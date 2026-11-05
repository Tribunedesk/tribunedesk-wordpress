<?php
declare(strict_types=1);

use TribuneDesk\WordPress\UpdateManifest;
use TribuneDesk\WordPress\Updater;

class Test_Updater_Transient extends WP_UnitTestCase
{
    /** @var array<string, mixed>|null */
    private $next_response = null;

    public function set_up(): void
    {
        parent::set_up();
        UpdateManifest::flush();
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        UpdateManifest::flush();
        unset($_GET['force-check']);
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_http($preempt, array $args, string $url)
    {
        if ($url !== UpdateManifest::MANIFEST_URL) {
            // do_action('load-update-core.php') odpala tez core'owe wp_update_plugins(),
            // ktore bez tego robiloby prawdziwy request do api.wordpress.org — blokujemy
            // go, zeby test nie zalezal od sieci.
            return new WP_Error('headlinesforge_test_no_network', 'Zablokowane w tescie.');
        }
        return $this->next_response;
    }

    private function serve_version(string $version): void
    {
        $this->next_response = [
            'headers'  => [],
            'body'     => wp_json_encode([
                'name'         => 'Tribune Desk Receiver',
                'slug'         => 'headlinesforge-receiver',
                'version'      => $version,
                'package'      => 'https://updates.headlinesforge.com/wp-plugin/headlinesforge-receiver-' . $version . '.zip',
                'sha256'       => str_repeat('b', 64),
                'requires'     => '4.7',
                'tested'       => '7.1',
                'requires_php' => '8.3',
                'homepage'     => 'https://headlinesforge.com',
            ]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    public function test_nowsza_wersja_laduje_w_response(): void
    {
        $this->serve_version('99.0.0');
        $out = Updater::inject_update(new stdClass());
        $file = Updater::basename();

        // PHPUnit 9.6 nie ma assertObjectHasProperty (to PHPUnit 10.1+).
        $this->assertTrue(property_exists($out, 'response'));
        $this->assertArrayHasKey($file, $out->response);
        $this->assertSame('99.0.0', $out->response[$file]->new_version);
        $this->assertSame(
            'https://updates.headlinesforge.com/wp-plugin/headlinesforge-receiver-99.0.0.zip',
            $out->response[$file]->package
        );
        $this->assertArrayNotHasKey($file, (array) ($out->no_update ?? []));
    }

    public function test_ta_sama_wersja_laduje_w_no_update_a_nie_w_pustce(): void
    {
        $this->serve_version(HEADLINESFORGE_VERSION);
        $out = Updater::inject_update(new stdClass());
        $file = Updater::basename();

        // Bez wpisu w no_update core gasi kolumne "Automatyczne aktualizacje"
        // i link "Zobacz szczegoly" (class-wp-plugins-list-table.php:214-220).
        $this->assertArrayHasKey($file, $out->no_update);
        $this->assertArrayNotHasKey($file, (array) ($out->response ?? []));
        $this->assertSame(Updater::SLUG, $out->no_update[$file]->slug);
        $this->assertSame(HEADLINESFORGE_VERSION, $out->no_update[$file]->version);
    }

    public function test_starsza_wersja_w_manifescie_tez_daje_no_update(): void
    {
        $this->serve_version('0.0.1');
        $out = Updater::inject_update(new stdClass());
        $file = Updater::basename();

        $this->assertArrayHasKey($file, $out->no_update);
        $this->assertArrayNotHasKey($file, (array) ($out->response ?? []));
    }

    public function test_brak_manifestu_nie_rusza_transientu(): void
    {
        $this->next_response = new WP_Error('http_request_failed', 'padlo');
        $in = new stdClass();
        $in->response = [];
        $out = Updater::inject_update($in);

        $this->assertSame([], $out->response);
        $this->assertFalse(property_exists($out, 'no_update'));
    }

    public function test_filtr_update_uri_zwraca_ten_sam_pakiet(): void
    {
        $this->serve_version('99.0.0');
        $got = Updater::update_uri_response(false, [], Updater::basename());

        $this->assertIsArray($got);
        $this->assertSame('99.0.0', $got['new_version']);
        $this->assertSame(UpdateManifest::MANIFEST_URL, $got['id']);
        // Core odrzuca caly payload z update_plugins_{host}, jesli brakuje 'version'
        // (wp-includes/update.php: isset($update->version)) — samo 'new_version' nie wystarczy.
        $this->assertArrayHasKey('version', $got);
        $this->assertSame('99.0.0', $got['version']);
    }

    public function test_filtr_update_uri_ignoruje_obca_wtyczke(): void
    {
        $this->serve_version('99.0.0');
        $this->assertFalse(Updater::update_uri_response(false, [], 'inna/inna.php'));
    }

    public function test_naglowek_update_uri_jest_w_pliku_wtyczki(): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $data = get_plugin_data(HEADLINESFORGE_PLUGIN_FILE, false, false);
        $this->assertSame(UpdateManifest::MANIFEST_URL, $data['UpdateURI']);
    }

    public function test_force_check_flushuje_nasz_cache_manifestu(): void
    {
        // To jest to, co robi README'owe "Sprawdz ponownie": core odswieza SWOJ
        // transient sam, ale bez tego flusha inject_update() nadal czytalby nasz
        // wlasny 12-godzinny cache manifestu.
        $this->serve_version('99.0.0');
        UpdateManifest::get();
        $this->assertIsArray(get_site_transient(UpdateManifest::TRANSIENT));

        $this->neutralize_core_wp_update_plugins();

        $_GET['force-check'] = '1';
        do_action('load-update-core.php');

        $this->assertFalse(get_site_transient(UpdateManifest::TRANSIENT));
    }

    public function test_bez_force_check_cache_manifestu_przezywa(): void
    {
        $this->serve_version('99.0.0');
        UpdateManifest::get();
        $this->assertIsArray(get_site_transient(UpdateManifest::TRANSIENT));

        $this->neutralize_core_wp_update_plugins();

        do_action('load-update-core.php');

        $this->assertIsArray(get_site_transient(UpdateManifest::TRANSIENT));
    }

    /**
     * core'owe wp_update_plugins() tez wisi na load-update-core.php (priorytet
     * domyslny 10) i przy kazdym wywolaniu samo odswieza transient
     * update_plugins — co przez nasz inject_update() posrednio dotyka i naszego
     * cache'u manifestu, niezaleznie od force-check. Testujemy tu WYLACZNIE
     * efekt WLASNEGO haka, wiec usuwamy core'owy na czas testu; WP_UnitTestCase
     * i tak przywraca wszystkie hooki po kazdym tescie (_restore_hooks()).
     */
    private function neutralize_core_wp_update_plugins(): void
    {
        remove_action('load-update-core.php', 'wp_update_plugins');
    }
}
