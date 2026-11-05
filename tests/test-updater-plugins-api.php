<?php
declare(strict_types=1);

use TribuneDesk\WordPress\UpdateManifest;
use TribuneDesk\WordPress\Updater;

class Test_Updater_Plugins_Api extends WP_UnitTestCase
{
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

        return [
            'headers'  => [],
            'body'     => wp_json_encode([
                'name'         => 'Tribune Desk Receiver',
                'slug'         => 'headlinesforge-receiver',
                'version'      => '99.0.0',
                'package'      => 'https://updates.headlinesforge.com/wp-plugin/headlinesforge-receiver-99.0.0.zip',
                'sha256'       => str_repeat('c', 64),
                'requires'     => '4.7',
                'tested'       => '7.1',
                'requires_php' => '8.3',
                'last_updated' => '2026-09-05 09:12:00',
                'homepage'     => 'https://headlinesforge.com',
                'author'       => 'Dominik Czarnota',
                'sections'     => [
                    'description' => 'Odbiera gotowe artykuly z HeadlinesForge.',
                    'changelog'   => '<h4>99.0.0</h4><ul><li>Cos tam.</li></ul>',
                ],
            ]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    public function test_zwraca_obiekt_z_changelogiem_dla_naszego_sluga(): void
    {
        $args = (object) ['slug' => Updater::SLUG];
        $info = Updater::plugin_information(false, 'plugin_information', $args);

        $this->assertIsObject($info);
        $this->assertSame('99.0.0', $info->version);
        $this->assertSame(Updater::SLUG, $info->slug);
        $this->assertArrayHasKey('changelog', $info->sections);
        $this->assertStringContainsString('99.0.0', $info->sections['changelog']);
        $this->assertSame(
            'https://updates.headlinesforge.com/wp-plugin/headlinesforge-receiver-99.0.0.zip',
            $info->download_link
        );
    }

    public function test_obcy_slug_zostaje_nietkniety(): void
    {
        $args = (object) ['slug' => 'akismet'];
        $this->assertFalse(Updater::plugin_information(false, 'plugin_information', $args));
    }

    public function test_inna_akcja_zostaje_nietknieta(): void
    {
        $args = (object) ['slug' => Updater::SLUG];
        $this->assertFalse(Updater::plugin_information(false, 'query_plugins', $args));
    }

    public function test_filtr_jest_zarejestrowany(): void
    {
        $this->assertNotFalse(
            has_filter('plugins_api', ['TribuneDesk\WordPress\Updater', 'plugin_information'])
        );
    }

    public function test_plugin_information_wymusza_swiezy_manifest(): void
    {
        // Ekran "Zobacz szczegoly" to miejsce, w ktorym uzytkownik SWIADOMIE chce
        // swiezych danych — plugin_information() musi wiec wolac get(true), a nie
        // czytac z cache'u wypelnionego wczesniejszym get().
        UpdateManifest::get();
        $this->assertSame(1, $this->http_calls);

        $args = (object) ['slug' => Updater::SLUG];
        Updater::plugin_information(false, 'plugin_information', $args);

        $this->assertSame(2, $this->http_calls);
    }
}
