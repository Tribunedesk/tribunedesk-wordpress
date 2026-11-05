<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\DeliveriesLog;
use TribuneDesk\WordPress\MediaHandler;
use TribuneDesk\WordPress\MediaPush;
use TribuneDesk\WordPress\PublishedUrlsClient;
use TribuneDesk\WordPress\RestEndpoint;

class Test_Media_Push extends WP_UnitTestCase
{
    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . Activator::TABLE);

        update_option(RestEndpoint::OPTION_SECRET, 'sekret-testowy');
        delete_option(PublishedUrlsClient::OPTION_API_BASE);
        update_option(MediaPush::OPTION_FLOOR, 0);
        $this->requests = [];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        delete_option(MediaPush::OPTION_FLOOR);
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_http($preempt, array $args, string $url)
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        return new WP_Error('http_test', 'bez sieci w testach');
    }

    /** @param array<string, mixed> $over */
    private function attachment(array $over = [], int $age_seconds = 3600): int
    {
        $gmt = gmdate('Y-m-d H:i:s', time() - $age_seconds);
        $id  = self::factory()->attachment->create(array_merge([
            'post_mime_type' => 'image/jpeg',
            'post_date'      => $gmt,
            'post_date_gmt'  => $gmt,
        ], $over));
        update_post_meta($id, '_wp_attached_file', "2026/10/img-$id.jpg");
        return $id;
    }

    private function published_parent(): int
    {
        return self::factory()->post->create(['post_status' => 'publish', 'post_title' => 'Tytul wpisu']);
    }

    public function test_obraz_z_wydanym_rodzicem_jest_kandydatem(): void
    {
        $id = $this->attachment(['post_parent' => $this->published_parent()]);
        $this->assertContains($id, MediaPush::candidates(50));
    }

    public function test_bez_rodzica_1h_nie_25h_tak(): void
    {
        $young = $this->attachment([], 3600);
        $old   = $this->attachment([], 25 * 3600);
        $c     = MediaPush::candidates(50);
        $this->assertNotContains($young, $c);
        $this->assertContains($old, $c);
    }

    public function test_rodzic_w_koszu_25h_jest_1h_nie_ma(): void
    {
        $parent = self::factory()->post->create(['post_status' => 'trash']);
        $old    = $this->attachment(['post_parent' => $parent], 25 * 3600);
        $young  = $this->attachment(['post_parent' => $parent], 3600);
        $c      = MediaPush::candidates(50);
        $this->assertContains($old, $c);
        $this->assertNotContains($young, $c);
    }

    public function test_pominiecia_z_td_asset_source_url_pdf_i_floor(): void
    {
        $parent = $this->published_parent();
        $td     = $this->attachment(['post_parent' => $parent]);
        update_post_meta($td, MediaHandler::META_FROM_TD, 1);
        $asset = $this->attachment(['post_parent' => $parent]);
        update_post_meta($asset, MediaHandler::META_ASSET_ID, 'a1');
        $src = $this->attachment(['post_parent' => $parent]);
        update_post_meta($src, '_source_url', 'https://x/y.jpg');
        $pdf  = $this->attachment(['post_parent' => $parent, 'post_mime_type' => 'application/pdf']);
        $low  = $this->attachment(['post_parent' => $parent]);
        $high = $this->attachment(['post_parent' => $parent]);
        update_option(MediaPush::OPTION_FLOOR, $low);

        $c = MediaPush::candidates(50);
        foreach ([$td, $asset, $src, $pdf, $low] as $excluded) {
            $this->assertNotContains($excluded, $c);
        }
        $this->assertContains($high, $c);
    }

    public function test_brak_opcji_floor_to_brak_kandydatow(): void
    {
        $this->attachment(['post_parent' => $this->published_parent()]);
        delete_option(MediaPush::OPTION_FLOOR);
        $this->assertSame([], MediaPush::candidates(50));
    }

    public function test_wyslane_pomijane_a_rejected_host_wraca_po_7_dniach(): void
    {
        $parent   = $this->published_parent();
        $accepted = $this->attachment(['post_parent' => $parent]);
        update_post_meta($accepted, MediaPush::META_PUSHED, 'accepted@' . (time() - 30 * DAY_IN_SECONDS));
        $host_old = $this->attachment(['post_parent' => $parent]);
        update_post_meta($host_old, MediaPush::META_PUSHED, 'rejected:host@' . (time() - 8 * DAY_IN_SECONDS));
        $host_new = $this->attachment(['post_parent' => $parent]);
        update_post_meta($host_new, MediaPush::META_PUSHED, 'rejected:host@' . (time() - DAY_IN_SECONDS));
        $small = $this->attachment(['post_parent' => $parent]);
        update_post_meta($small, MediaPush::META_PUSHED, 'rejected:too_small@' . (time() - 30 * DAY_IN_SECONDS));

        $c = MediaPush::candidates(50);
        $this->assertContains($host_old, $c);
        $this->assertNotContains($accepted, $c);
        $this->assertNotContains($host_new, $c);
        $this->assertNotContains($small, $c);
    }

    public function test_limit_i_kolejnosc_rosnaco_po_id(): void
    {
        $parent = $this->published_parent();
        $a = $this->attachment(['post_parent' => $parent]);
        $b = $this->attachment(['post_parent' => $parent]);
        $this->attachment(['post_parent' => $parent]);
        $this->assertSame([$a, $b], MediaPush::candidates(2));
    }

    public function test_item_pola_alt_iptc_i_wpis(): void
    {
        $parent = $this->published_parent();
        $id     = $this->attachment([
            'post_parent'  => $parent,
            'post_title'   => 'Tytul zdjecia',
            'post_excerpt' => 'Podpis',
            'post_content' => 'Opis',
        ]);
        update_post_meta($id, '_wp_attachment_image_alt', 'Tekst alt');
        wp_update_attachment_metadata($id, [
            'width'      => 1200,
            'height'     => 800,
            'image_meta' => ['caption' => 'IPTC podpis', 'title' => 'IPTC tytul', 'credit' => 'Foto Jan'],
        ]);

        $item = MediaPush::item($id);
        $this->assertSame($id, $item['attachment_id']);
        $this->assertSame(1200, $item['width']);
        $this->assertSame(800, $item['height']);
        $this->assertSame('image/jpeg', $item['mime']);
        $this->assertSame('Tekst alt', $item['alt']);
        $this->assertSame('Tytul zdjecia', $item['title']);
        $this->assertSame('Podpis', $item['caption']);
        $this->assertSame('Opis', $item['description']);
        $this->assertSame('IPTC podpis', $item['iptc_caption']);
        $this->assertSame('IPTC tytul', $item['iptc_title']);
        $this->assertSame('Foto Jan', $item['iptc_credit']);
        $this->assertStringContainsString("img-$id.jpg", $item['url']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $item['uploaded_at']);
        $this->assertSame('Tytul wpisu', $item['post']['title']);
        $this->assertSame(get_permalink($parent), $item['post']['url']);
        $this->assertMatchesRegularExpression('/\+00:00$/', $item['post']['published_at']);
    }

    public function test_item_bez_wydanego_rodzica_ma_post_null(): void
    {
        $id = $this->attachment([], 25 * 3600);
        $this->assertNull(MediaPush::item($id)['post']);
    }

    public function test_run_bez_tokenu_nic_nie_wysyla_mimo_sekretu(): void
    {
        $id = $this->attachment(['post_parent' => $this->published_parent()]);
        $this->assertContains($id, MediaPush::candidates(50));

        MediaPush::run();

        $this->assertCount(0, $this->requests);
        $this->assertCount(0, DeliveriesLog::recent());
        $this->assertSame('', get_post_meta($id, MediaPush::META_PUSHED, true));
    }

    public function test_ensure_scheduled_ustawia_prog_na_max_id_i_planuje_raz(): void
    {
        $id = $this->attachment();
        delete_option(MediaPush::OPTION_FLOOR);
        wp_clear_scheduled_hook(MediaPush::HOOK);

        MediaPush::ensure_scheduled();
        $first = wp_next_scheduled(MediaPush::HOOK);
        MediaPush::ensure_scheduled();

        $this->assertSame($id, (int) get_option(MediaPush::OPTION_FLOOR));
        $this->assertIsInt($first);
        $this->assertSame($first, wp_next_scheduled(MediaPush::HOOK));
        $this->assertCount(1, array_filter(
            _get_cron_array(),
            static fn($events) => isset($events[MediaPush::HOOK])
        ));
        $this->assertSame('hourly', wp_get_schedule(MediaPush::HOOK));
    }

    public function test_ensure_scheduled_nie_nadpisuje_istniejacego_progu(): void
    {
        $this->attachment();
        update_option(MediaPush::OPTION_FLOOR, 3);
        MediaPush::ensure_scheduled();
        $this->assertSame(3, (int) get_option(MediaPush::OPTION_FLOOR));
    }

    public function test_hooki_sa_podpiete(): void
    {
        $this->assertNotFalse(has_action(MediaPush::HOOK, [MediaPush::class, 'run']));
        $this->assertNotFalse(has_action('init', [MediaPush::class, 'ensure_scheduled']));
    }

    public function test_dezaktywacja_czysci_zdarzenie(): void
    {
        wp_schedule_event(time() + 300, 'hourly', MediaPush::HOOK);
        do_action('deactivate_' . plugin_basename(HEADLINESFORGE_PLUGIN_FILE));
        $this->assertFalse(wp_next_scheduled(MediaPush::HOOK));
    }

    public function test_zalacznik_w_koszu_nie_jest_kandydatem(): void
    {
        $id = $this->attachment(['post_status' => 'trash'], 25 * 3600);

        $this->assertNotContains($id, MediaPush::candidates(10));
    }

    public function test_item_dekoduje_encje_w_tytule_wpisu(): void
    {
        $parent = self::factory()->post->create(['post_status' => 'publish', 'post_title' => 'Rok 2026 – wynik']);
        $id     = $this->attachment(['post_parent' => $parent]);

        $this->assertSame('Rok 2026 – wynik', MediaPush::item($id)['post']['title']);
    }

    public function test_item_zerowa_data_gmt_daje_poprawna_date_iso(): void
    {
        $id = $this->attachment();
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_date_gmt' => '0000-00-00 00:00:00', 'post_date' => '2026-10-05 12:00:00'], ['ID' => $id]);
        clean_post_cache($id);

        $this->assertMatchesRegularExpression('/^2026-10-05T\d\d:\d\d:\d\d\+00:00$/', MediaPush::item($id)['uploaded_at']);
    }
}
