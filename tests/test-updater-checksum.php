<?php
declare(strict_types=1);

use TribuneDesk\WordPress\UpdateManifest;
use TribuneDesk\WordPress\Updater;

class Test_Updater_Checksum extends WP_UnitTestCase
{
    private string $payload   = "to udaje zawartosc ZIP-a";
    private string $fake_zip  = '';
    private string $sha       = '';
    private string $package   = 'https://updates.headlinesforge.com/wp-plugin/headlinesforge-receiver-99.0.0.zip';
    private string $served_sha = '';
    private string $served_package = '';
    private bool $manifest_unreachable = false;

    public function set_up(): void
    {
        parent::set_up();
        require_once ABSPATH . 'wp-admin/includes/file.php';

        UpdateManifest::flush();
        $this->sha             = hash('sha256', $this->payload);
        $this->served_sha      = $this->sha;
        $this->served_package  = $this->package;
        $this->fake_zip        = '';
        $this->manifest_unreachable = false;

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
        if ($url === UpdateManifest::MANIFEST_URL) {
            if ($this->manifest_unreachable) {
                // Symulacja awarii R2 w momencie kliknietego "Zainstaluj teraz" —
                // bramka weryfikacji MUSI wtedy zablokowac instalacje, a nie przepuscic.
                return new WP_Error('http_request_failed', 'Symulowana awaria R2.');
            }

            return [
                'headers'  => [],
                'body'     => wp_json_encode([
                    'version' => '99.0.0',
                    'package' => $this->served_package,
                    'sha256'  => $this->served_sha,
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }

        if ($url === $this->package) {
            // download_url() woła wp_safe_remote_get z 'stream' => true i oczekuje,
            // że plik znajdzie się pod $args['filename'].
            $this->fake_zip = (string) $args['filename'];
            file_put_contents($this->fake_zip, $this->payload);

            return [
                'headers'  => [],
                'body'     => '',
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => $this->fake_zip,
            ];
        }

        return $preempt;
    }

    public function test_zgodna_suma_zwraca_sciezke_do_pliku(): void
    {
        $got = Updater::verify_package(false, $this->package, null);

        $this->assertIsString($got);
        $this->assertFileExists($got);
        $this->assertSame($this->sha, hash_file('sha256', $got));

        @unlink($got);
    }

    public function test_niezgodna_suma_daje_wp_error(): void
    {
        $this->served_sha = str_repeat('d', 64);

        $got = Updater::verify_package(false, $this->package, null);

        $this->assertInstanceOf(WP_Error::class, $got);
        $this->assertSame('headlinesforge_checksum_mismatch', $got->get_error_code());
        $this->assertStringContainsString(HEADLINESFORGE_VERSION, $got->get_error_message());
    }

    public function test_niezgodna_suma_kasuje_plik_tymczasowy(): void
    {
        $this->served_sha = str_repeat('e', 64);

        Updater::verify_package(false, $this->package, null);

        $this->assertNotSame('', $this->fake_zip);
        $this->assertFileDoesNotExist($this->fake_zip);
    }

    public function test_obcy_pakiet_zostaje_nietkniety(): void
    {
        $this->assertFalse(
            Updater::verify_package(false, 'https://example.com/inna.zip', null)
        );
    }

    public function test_manifest_niedostepny_wstrzymuje_aktualizacje(): void
    {
        // To jest dokladnie scenariusz, ktory wczesniej "failowal open": przy
        // awarii R2 (albo godzinnym cache bledu w UpdateManifest) pakiet z
        // naszego hosta MUSI zostac zablokowany, a nie przepuszczony do core'a
        // bez weryfikacji sumy.
        $this->manifest_unreachable = true;

        $got = Updater::verify_package(false, $this->package, null);

        $this->assertInstanceOf(WP_Error::class, $got);
        $this->assertSame('headlinesforge_manifest_unavailable', $got->get_error_code());
        $this->assertStringContainsString(HEADLINESFORGE_VERSION, $got->get_error_message());
    }

    public function test_manifest_wskazuje_inny_pakiet(): void
    {
        // Manifest jest osiagalny, ale serwuje INNY adres pakietu niz ten, ktory
        // WordPress akurat probuje pobrac — lista aktualizacji jest nieaktualna
        // (np. manifest podmieniony miedzy odswiezeniem transientu a klikniem).
        $this->served_package = 'https://updates.headlinesforge.com/wp-plugin/headlinesforge-receiver-100.0.0.zip';

        $got = Updater::verify_package(false, $this->package, null);

        $this->assertInstanceOf(WP_Error::class, $got);
        $this->assertSame('headlinesforge_package_mismatch', $got->get_error_code());
        $this->assertStringContainsString(HEADLINESFORGE_VERSION, $got->get_error_message());
    }

    public function test_lokalny_plik_pozostaje_nietkniety(): void
    {
        // Reczne wgranie ZIP-a przez admina to udokumentowana sciezka awaryjna —
        // upgrader_pre_download odpala sie PRZED galezia core'a obslugujaca lokalne
        // pliki, wiec musimy przepuscic taki $package nietkniety. Manifest celowo
        // nieosiagalny: to wlasnie scenariusz "R2 lezy", w ktorym reczne wgranie
        // ma nadal dzialac.
        $this->manifest_unreachable = true;

        $this->assertFalse(
            Updater::verify_package(false, '/tmp/reczne-wgranie.zip', null)
        );
    }

    public function test_gdy_ktos_juz_odpowiedzial_nie_wchodzimy_w_droge(): void
    {
        $this->assertSame(
            '/tmp/cudzy.zip',
            Updater::verify_package('/tmp/cudzy.zip', $this->package, null)
        );
    }

    public function test_filtr_jest_zarejestrowany(): void
    {
        $this->assertNotFalse(
            has_filter('upgrader_pre_download', ['TribuneDesk\WordPress\Updater', 'verify_package'])
        );
    }

    public function test_package_null_od_cudzej_wtyczki_nie_wywala_typeerror(): void
    {
        // Core (download_package()) odpala upgrader_pre_download PRZED wlasnym
        // empty($package) — wpis innej wtyczki z package => null (np.
        // plugin-update-checker bez licencji) dociera wiec tutaj jako null.
        $this->assertFalse(Updater::verify_package(false, null, null));
    }
}
