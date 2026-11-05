<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\UpdateManifest;

/**
 * Dowod, ze Plugin_Upgrader::upgrade() podmienia katalog wtyczki, ale NIE uruchamia
 * uninstall.php i nie rusza opcji ani tabel.
 *
 * Test jedzie na ATRAPIE wtyczki, nie na nas samych: uruchomienie upgradera na
 * wlasnym kodzie w srodku przebiegu podmienialoby kod pod kolejnymi testami.
 */
class Test_Upgrade_Preserves_Data extends WP_UnitTestCase
{
    private string $slug     = 'hf-upgrade-fixture';
    private string $file     = 'hf-upgrade-fixture/hf-upgrade-fixture.php';
    private string $dir      = '';
    private string $zip_path = '';

    /**
     * @var callable|null Atrapa upgrader_pre_download — usuwana w tear_down(),
     *                     niezaleznie od tego, gdzie w tescie sie zatrzymamy.
     */
    private $pre_download_stub = null;

    public function set_up(): void
    {
        parent::set_up();

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('Brak rozszerzenia zip w PHP.');
        }

        // Bez tego Plugin_Upgrader nie ma jak zapisac na dysk (WP_Filesystem_Direct
        // nie inicjalizuje sie sam) — upgrade() padalby na etapie kopiowania plikow.
        global $wp_filesystem;
        WP_Filesystem();

        // Tabela dostaw normalnie powstaje przy aktywacji wtyczki (register_activation_hook),
        // ktora w srodowisku testowym sie nie odpala. Bez tego insert() w tescie padnie.
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            Activator::activate();
        }

        // UpdateManifest::flush() usuwa cache, ale set_site_transient('update_plugins', ...)
        // w tescie odpala pre_set_site_transient_update_plugins -> Updater::inject_update()
        // -> UpdateManifest::get(), ktore bez stuba posz-loby po prawdziwy HTTP do
        // updates.headlinesforge.com. WP test suite blokuje tylko grupe external-http,
        // wiec bez tego filtra test robilby realny request. Manifest i tak nas nie
        // interesuje (upgrader dostaje swoj wpis w transiencie recznie nizej), wiec
        // stub po prostu odmawia — WP_Error, ktory Updater::inject_update() ignoruje.
        add_filter('pre_http_request', [$this, 'block_manifest_http'], 10, 3);
        UpdateManifest::flush();

        $this->dir = WP_PLUGIN_DIR . '/' . $this->slug;
        $this->write_fixture('1.0.0');
        $this->zip_path = $this->build_zip('1.1.0');
    }

    public function tear_down(): void
    {
        if ($this->pre_download_stub !== null) {
            remove_filter('upgrader_pre_download', $this->pre_download_stub, 5);
        }

        $this->rmdir_recursive($this->dir);

        if ($this->zip_path !== '' && file_exists($this->zip_path)) {
            @unlink($this->zip_path);
        }

        $copy = get_temp_dir() . 'hf-upgrade-fixture-download.zip';
        if (file_exists($copy)) {
            @unlink($copy);
        }

        delete_option('hf_fixture_uninstalled');
        delete_site_transient('update_plugins');

        UpdateManifest::flush();
        remove_filter('pre_http_request', [$this, 'block_manifest_http'], 10);

        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function block_manifest_http($preempt, array $args, string $url)
    {
        if ($url !== UpdateManifest::MANIFEST_URL) {
            return $preempt;
        }

        return new WP_Error('headlinesforge_test_no_network', 'Manifest zablokowany w tescie upgradu.');
    }

    private function write_fixture(string $version): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }

        file_put_contents(
            $this->dir . '/hf-upgrade-fixture.php',
            "<?php\n/**\n * Plugin Name: HF Upgrade Fixture\n * Version: {$version}\n */\n"
        );

        // Gdyby WordPress kiedykolwiek uruchomil uninstall.php przy AKTUALIZACJI,
        // ta opcja by powstala. Jej brak po tescie jest wlasnie dowodem.
        file_put_contents(
            $this->dir . '/uninstall.php',
            "<?php\nupdate_option('hf_fixture_uninstalled', '1');\n"
        );
    }

    private function build_zip(string $version): string
    {
        $path = get_temp_dir() . 'hf-upgrade-fixture-' . $version . '.zip';

        if (file_exists($path)) {
            @unlink($path);
        }

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString(
            $this->slug . '/hf-upgrade-fixture.php',
            "<?php\n/**\n * Plugin Name: HF Upgrade Fixture\n * Version: {$version}\n */\n"
        );
        $zip->addFromString($this->slug . '/NOWY-PLIK.txt', "wersja {$version}\n");
        $zip->close();

        return $path;
    }

    private function rmdir_recursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir_recursive($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    public function test_aktualizacja_zachowuje_sekret_tabele_i_nie_odpala_uninstall(): void
    {
        global $wpdb;

        // Stan, ktory MUSI przezyc.
        update_option('headlinesforge_webhook_secret', 'sekret-testowy-123');
        $table = $wpdb->prefix . Activator::TABLE;
        $wpdb->insert(
            $table,
            [
                'received_at' => current_time('mysql'),
                'article_id'  => 'art-przed-aktualizacja',
                'post_id'     => null,
                'status'      => 'ok',
                'error_msg'   => null,
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );

        // Upgrader wymaga wpisu w transiencie aktualizacji.
        $transient = new stdClass();
        $transient->response = [
            $this->file => (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->file,
                'new_version' => '1.1.0',
                'package'     => 'https://example.com/wp-plugin/hf-upgrade-fixture-1.1.0.zip',
            ],
        ];
        set_site_transient('update_plugins', $transient);

        // Zamiast pobierania z sieci podstawiamy KOPIE lokalnego ZIP-a: upgrader
        // kasuje plik, ktory dostanie, wiec oryginal musi zostac na miejscu.
        $copy = get_temp_dir() . 'hf-upgrade-fixture-download.zip';
        copy($this->zip_path, $copy);
        $this->pre_download_stub = static fn() => $copy;
        // Priorytet 5: produkcyjny Updater::verify_package() jest zarejestrowany na
        // upgrader_pre_download z priorytetem 10 i musi przegrac, zeby w ogole zdazyc
        // odpowiedziec na ten sam filtr — pakiet atrapy jest na obcym hoscie
        // (example.com), wiec verify_package() przepusciloby go bez zmian i tak,
        // ale wynik ma zwrocic NASZA kopia ZIP-a, nie prawdziwe pobranie.
        add_filter('upgrader_pre_download', $this->pre_download_stub, 5);

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result   = $upgrader->upgrade($this->file);

        $this->assertTrue($result === true || is_array($result), 'Aktualizacja atrapy powinna sie udac.');

        // 1. Kod faktycznie sie podmienil.
        $this->assertFileExists($this->dir . '/NOWY-PLIK.txt');
        $this->assertStringContainsString(
            '1.1.0',
            (string) file_get_contents($this->dir . '/hf-upgrade-fixture.php')
        );

        // 2. uninstall.php NIE zostal uruchomiony.
        $this->assertFalse(
            get_option('hf_fixture_uninstalled', false),
            'Aktualizacja nie moze uruchamiac uninstall.php.'
        );

        // 3. Sekret webhooka przezyl.
        $this->assertSame('sekret-testowy-123', get_option('headlinesforge_webhook_secret'));

        // 4. Tabela dostaw przezyla wraz z wierszem.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE article_id = %s", 'art-przed-aktualizacja')
        );
        $this->assertSame(1, $count);
    }
}
