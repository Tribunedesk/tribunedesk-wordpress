<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\Backfill;
use TribuneDesk\WordPress\DeliveriesLog;
use TribuneDesk\WordPress\PostWriter;
use TribuneDesk\WordPress\PublishedUrlsClient;
use TribuneDesk\WordPress\Settings;

class Test_Settings_Api extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        // add_settings_error / submit_button / get_admin_page_title mieszkaja w wp-admin —
        // w tescie trzeba je zaladowac. Globalny $title: bez niego get_admin_page_title()
        // iteruje po $submenu, ktore poza wp-admin jest null (TypeError na PHP 8).
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $GLOBALS['title'] = 'Tribune Desk Receiver';
        Activator::activate();
        delete_option(PublishedUrlsClient::OPTION_API_BASE);
        delete_option(Backfill::OPTION_CURSOR);
        global $wp_settings_errors;
        $wp_settings_errors = [];
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        delete_option(PublishedUrlsClient::OPTION_API_BASE);
        delete_option(Backfill::OPTION_CURSOR);
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_http($preempt, array $args, string $url)
    {
        return [
            'headers'  => [],
            'body'     => wp_json_encode(['results' => []]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function render_page(): string
    {
        ob_start();
        Settings::render_page();

        return (string) ob_get_clean();
    }

    private function make_published_hf_post(): int
    {
        $id = (int) self::factory()->post->create(['post_status' => 'publish']);
        add_post_meta($id, PostWriter::META_ARTICLE_ID, sprintf('%08x-0000-4000-8000-%012x', $id, $id));

        return $id;
    }

    public function test_sanitize_akceptuje_https_i_zdejmuje_koncowy_slash(): void
    {
        $this->assertSame('https://staging.example.com', Settings::sanitize_api_base('https://staging.example.com/'));
        $this->assertSame('https://api.headlinesforge.com', Settings::sanitize_api_base(' https://api.headlinesforge.com '));
    }

    public function test_sanitize_odrzuca_http_i_zostawia_poprzednia_wartosc(): void
    {
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://prev.example.com');

        $out = Settings::sanitize_api_base('http://insecure.example.com');

        $this->assertSame('https://prev.example.com', $out);
        $this->assertNotEmpty(get_settings_errors(PublishedUrlsClient::OPTION_API_BASE));
    }

    public function test_sanitize_pusta_lub_bez_hosta_wraca_do_domyslnej(): void
    {
        $this->assertSame(PublishedUrlsClient::DEFAULT_API_BASE, Settings::sanitize_api_base(''));
        $this->assertSame(PublishedUrlsClient::DEFAULT_API_BASE, Settings::sanitize_api_base('https://'));
        $this->assertSame(PublishedUrlsClient::DEFAULT_API_BASE, Settings::sanitize_api_base('nie-url'));
    }

    public function test_sekcja_i_pole_api_sa_zarejestrowane(): void
    {
        global $wp_settings_sections, $wp_settings_fields;

        Settings::register_settings();

        $this->assertArrayHasKey('headlinesforge_api', $wp_settings_sections[Settings::PAGE_SLUG]);
        $this->assertArrayHasKey(PublishedUrlsClient::OPTION_API_BASE, $wp_settings_fields[Settings::PAGE_SLUG]['headlinesforge_api']);
        $registered = get_registered_settings();
        $this->assertSame(PublishedUrlsClient::DEFAULT_API_BASE, $registered[PublishedUrlsClient::OPTION_API_BASE]['default']);
    }

    public function test_pole_api_pokazuje_domyslna_wartosc(): void
    {
        ob_start();
        Settings::render_api_base_field();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('value="https://api.headlinesforge.com"', $html);
        $this->assertStringContainsString('name="headlinesforge_api_base"', $html);
    }

    public function test_strona_pokazuje_przycisk_backfillu_z_licznikiem(): void
    {
        $this->make_published_hf_post();
        $this->make_published_hf_post();

        $html = $this->render_page();

        $this->assertStringContainsString('Liczba wpisów do wysłania: 2', $html);
        $this->assertStringContainsString('admin-post.php', $html);
        $this->assertStringContainsString('value="' . Backfill::ACTION . '"', $html);
        $this->assertStringContainsString('Wyślij adresy opublikowanych wpisów do Tribune Desk', $html);
        $this->assertStringContainsString('_wpnonce', $html);
    }

    public function test_etykieta_kolejne_100_gdy_kursor_w_srodku(): void
    {
        $first = $this->make_published_hf_post();
        $this->make_published_hf_post();
        update_option(Backfill::OPTION_CURSOR, $first);

        $html = $this->render_page();

        $this->assertStringContainsString('Wyślij kolejne 100', $html);
        $this->assertStringContainsString('Liczba wpisów do wysłania: 1', $html);
    }

    public function test_komunikat_wyniku_pokazuje_sie_raz(): void
    {
        set_transient(
            Backfill::TRANSIENT_RESULT . '_' . get_current_user_id(),
            ['status' => 'sent', 'sent' => 3, 'remaining' => 0, 'detail' => 'ok=3 not_found=0 forbidden=0 invalid_url=0 skipped_non_uuid=0', 'counts' => []],
            MINUTE_IN_SECONDS
        );

        $first  = $this->render_page();
        $second = $this->render_page();

        $this->assertStringContainsString('notice-success', $first);
        $this->assertStringContainsString('Wysłano 3', $first);
        $this->assertStringNotContainsString('Wysłano 3', $second);
    }

    public function test_komunikat_bledu_dla_unauthorized(): void
    {
        set_transient(
            Backfill::TRANSIENT_RESULT . '_' . get_current_user_id(),
            ['status' => 'unauthorized', 'sent' => 0, 'remaining' => 5, 'detail' => 'sekret nie pasuje do żadnej redakcji w HeadlinesForge', 'counts' => []],
            MINUTE_IN_SECONDS
        );

        $html = $this->render_page();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('sekret nie pasuje', $html);
    }

    public function test_komunikat_info_dla_nothing_to_send(): void
    {
        set_transient(
            Backfill::TRANSIENT_RESULT . '_' . get_current_user_id(),
            ['status' => PublishedUrlsClient::STATUS_NOTHING_TO_SEND, 'sent' => 0, 'remaining' => 0, 'detail' => 'brak opublikowanych wpisów z HeadlinesForge', 'counts' => []],
            MINUTE_IN_SECONDS
        );

        $html = $this->render_page();

        $this->assertStringContainsString('notice-info', $html);
        $this->assertStringContainsString('brak opublikowanych wpisów z HeadlinesForge', $html);
        $this->assertStringNotContainsString('notice-error', $html);
        $this->assertStringNotContainsString('notice-success', $html);
    }

    public function test_tabela_logu_ma_kolumne_rodzaj_i_szczegoly(): void
    {
        DeliveriesLog::record('11111111-1111-4111-8111-111111111111', 1, 'ok', null, DeliveriesLog::KIND_NOTIFY);

        $html = $this->render_page();

        $this->assertStringContainsString('<th>Rodzaj</th>', $html);
        $this->assertStringContainsString('<th>Szczegóły</th>', $html);
        $this->assertStringContainsString('adres wysłany', $html);
    }

    public function test_po_zakonczonym_przebiegu_licznik_pokazuje_zero(): void
    {
        $this->make_published_hf_post();
        $this->make_published_hf_post();
        set_transient(
            Backfill::TRANSIENT_RESULT . '_' . get_current_user_id(),
            ['status' => PublishedUrlsClient::STATUS_SENT, 'sent' => 2, 'remaining' => 0, 'detail' => 'ok=2', 'counts' => []],
            MINUTE_IN_SECONDS
        );

        $html = $this->render_page();

        $this->assertStringContainsString('Pozostało 0', $html);
        $this->assertStringContainsString('Liczba wpisów do wysłania: 0', $html);
        $this->assertStringNotContainsString('Liczba wpisów do wysłania: 2', $html);
    }

    public function test_strona_ma_etykiete_rodzaju_media(): void
    {
        DeliveriesLog::record('', null, 'sent', 'created=2', DeliveriesLog::KIND_MEDIA);

        $this->assertStringContainsString('Zdjęcia do biblioteki', $this->render_page());
    }

    /**
     * 2.2.0: without a signing secret every delivery is refused (the shared secret is gone, E7),
     * and the page says so above the form.
     */
    public function test_without_signing_secret_the_page_warns_that_deliveries_are_refused(): void
    {
        delete_option(\TribuneDesk\WordPress\RestEndpoint::OPTION_SIGNING_SECRET);
        $html = $this->render_page();
        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('każda dostawa z Tribune Desk jest odrzucana', $html);

        update_option(\TribuneDesk\WordPress\RestEndpoint::OPTION_SIGNING_SECRET, TRIBUNE_DESK_TESTS_SIGNING_SECRET);
        $this->assertStringNotContainsString('każda dostawa z Tribune Desk jest odrzucana', $this->render_page());
    }

    /**
     * Update from 2.1.0 with the old shared secret saved: the field is gone from the page, the
     * setting is no longer registered, and the stored value stays untouched (going back to 2.1.0
     * still works; uninstall removes it).
     */
    public function test_saved_shared_secret_from_2_1_0_stays_but_is_not_on_the_page(): void
    {
        update_option('headlinesforge_webhook_secret', 'old-shared-secret');
        Settings::register_settings();

        $html = $this->render_page();
        $this->assertStringNotContainsString('name="headlinesforge_webhook_secret"', $html);
        $this->assertArrayNotHasKey('headlinesforge_webhook_secret', get_registered_settings());
        $this->assertSame('old-shared-secret', get_option('headlinesforge_webhook_secret'));
    }
}
