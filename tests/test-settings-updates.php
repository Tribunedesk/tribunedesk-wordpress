<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Settings;
use TribuneDesk\WordPress\Updater;

class Test_Settings_Updates extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        delete_site_option('auto_update_plugins');
        delete_option(Settings::OPTION_AUTO_UPDATE);
    }

    public function tear_down(): void
    {
        delete_site_option('auto_update_plugins');
        delete_option(Settings::OPTION_AUTO_UPDATE);
        parent::tear_down();
    }

    public function test_zapis_wlaczony_dopisuje_do_opcji_corea(): void
    {
        $out = Settings::sanitize_auto_update('1');

        $this->assertSame('1', $out);
        $this->assertTrue(Updater::is_auto_update_enabled());
    }

    public function test_zapis_wylaczony_usuwa_z_opcji_corea(): void
    {
        Updater::set_auto_update(true);

        $out = Settings::sanitize_auto_update('');

        $this->assertSame('', $out);
        $this->assertFalse(Updater::is_auto_update_enabled());
    }

    public function test_zapis_nie_rusza_cudzych_wtyczek(): void
    {
        update_site_option('auto_update_plugins', ['akismet/akismet.php']);

        Settings::sanitize_auto_update('1');

        $enabled = (array) get_site_option('auto_update_plugins', []);
        $this->assertContains('akismet/akismet.php', $enabled);
    }

    public function test_checkbox_czyta_stan_z_opcji_corea_a_nie_z_wlasnej(): void
    {
        // Klient wylaczyl auto-update w "Wtyczki". Nasz ekran musi to pokazac,
        // mimo ze nasza wlasna opcja formularza nadal mowi "1".
        update_option(Settings::OPTION_AUTO_UPDATE, '1');
        Updater::set_auto_update(false);

        ob_start();
        Settings::render_auto_update_field();
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('checked', $html);
    }

    public function test_checkbox_zaznaczony_gdy_core_ma_wlaczone(): void
    {
        Updater::set_auto_update(true);

        ob_start();
        Settings::render_auto_update_field();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('checked', $html);
    }

    public function test_sekcja_aktualizacji_pokazuje_numer_wersji(): void
    {
        ob_start();
        Settings::render_updates_intro();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(HEADLINESFORGE_VERSION, $html);
    }

    public function test_sekcja_i_pole_sa_zarejestrowane(): void
    {
        global $wp_settings_sections, $wp_settings_fields;

        Settings::register_settings();

        $this->assertArrayHasKey('headlinesforge_updates', $wp_settings_sections[Settings::PAGE_SLUG]);
        $this->assertArrayHasKey(
            Settings::OPTION_AUTO_UPDATE,
            $wp_settings_fields[Settings::PAGE_SLUG]['headlinesforge_updates']
        );
    }
}
