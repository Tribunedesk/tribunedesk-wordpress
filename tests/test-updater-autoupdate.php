<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Updater;

class Test_Updater_Autoupdate extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        delete_option(Updater::BOOTSTRAP_FLAG);
        delete_site_option('auto_update_plugins');
    }

    public function tear_down(): void
    {
        delete_option(Updater::BOOTSTRAP_FLAG);
        delete_site_option('auto_update_plugins');
        parent::tear_down();
    }

    public function test_pierwszy_bootstrap_wlacza_auto_update(): void
    {
        Updater::bootstrap_auto_update();

        $this->assertContains(Updater::basename(), (array) get_site_option('auto_update_plugins', []));
        $this->assertTrue(Updater::is_auto_update_enabled());
    }

    public function test_bootstrap_nie_dubluje_wpisu(): void
    {
        Updater::bootstrap_auto_update();
        Updater::bootstrap_auto_update();

        $enabled = (array) get_site_option('auto_update_plugins', []);
        $this->assertSame(1, count(array_keys($enabled, Updater::basename(), true)));
    }

    public function test_bootstrap_nie_przywraca_tego_co_klient_wylaczyl(): void
    {
        Updater::bootstrap_auto_update();
        Updater::set_auto_update(false);
        $this->assertFalse(Updater::is_auto_update_enabled());

        // Kolejna aktualizacja wtyczki => kolejny admin_init. Nie wolno cofnac decyzji klienta.
        Updater::bootstrap_auto_update();

        $this->assertFalse(Updater::is_auto_update_enabled());
    }

    public function test_bootstrap_nie_rusza_cudzych_wtyczek(): void
    {
        update_site_option('auto_update_plugins', ['akismet/akismet.php']);

        Updater::bootstrap_auto_update();

        $enabled = (array) get_site_option('auto_update_plugins', []);
        $this->assertContains('akismet/akismet.php', $enabled);
        $this->assertContains(Updater::basename(), $enabled);
    }

    public function test_set_auto_update_wlacza_i_wylacza(): void
    {
        Updater::set_auto_update(true);
        $this->assertTrue(Updater::is_auto_update_enabled());

        Updater::set_auto_update(false);
        $this->assertFalse(Updater::is_auto_update_enabled());
        $this->assertNotContains(Updater::basename(), (array) get_site_option('auto_update_plugins', []));
    }

    public function test_na_wp_55_i_nowszym_filtr_przepuszcza_wartosc_corea(): void
    {
        // WP >= 5.5 sam wyprowadza domyslna wartosc z auto_update_plugins.
        // Zwrocenie czegokolwiek nie-null sprawia, ze wp_is_auto_update_forced_for_item()
        // (wp-admin/includes/update.php:1085) uzna wartosc za wymuszona i core
        // wyrenderuje martwa etykiete zamiast dzialajacego przelacznika.
        $me = Updater::basename();

        $this->assertNull(Updater::resolve_auto_update(null, $me, '7.1'));
        $this->assertTrue(Updater::resolve_auto_update(true, $me, '7.1'));
        $this->assertFalse(Updater::resolve_auto_update(false, $me, '5.5'));
    }

    public function test_na_starym_corze_filtr_czyta_ta_sama_opcje(): void
    {
        $me = Updater::basename();

        Updater::set_auto_update(true);
        $this->assertTrue(Updater::resolve_auto_update(false, $me, '4.9'));

        Updater::set_auto_update(false);
        $this->assertFalse(Updater::resolve_auto_update(true, $me, '4.9'));
    }

    public function test_filtr_ignoruje_cudza_wtyczke(): void
    {
        Updater::set_auto_update(true);

        $this->assertFalse(Updater::resolve_auto_update(false, 'akismet/akismet.php', '4.9'));

        $item = (object) ['plugin' => 'akismet/akismet.php'];
        $this->assertFalse(Updater::auto_update_default(false, $item));
    }

    public function test_filtr_bez_obiektu_item_nic_nie_zmienia(): void
    {
        $this->assertFalse(Updater::auto_update_default(false, null));
    }

    public function test_haki_sa_zarejestrowane(): void
    {
        $this->assertNotFalse(has_filter('auto_update_plugin', ['TribuneDesk\WordPress\Updater', 'auto_update_default']));
        $this->assertNotFalse(has_action('admin_init', ['TribuneDesk\WordPress\Updater', 'bootstrap_auto_update']));
    }
}
