<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsSettingsScreen;
use TribuneDesk\WordPress\CmsSync;
use TribuneDesk\WordPress\MediaHandler;
use TribuneDesk\WordPress\MediaMetaSync;
use TribuneDesk\WordPress\MediaPush;
use TribuneDesk\WordPress\PublishedUrlsClient;

/** Etap 5: plik z Tribune Desk przychodzi kanalem, trafia do biblioteki i wraca jako /link. */
class Test_Cms_New_Asset extends WP_UnitTestCase
{
    private const PNG_1X1   = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private const ASSET     = '11111111-2222-4333-8444-555555555555';
    private const ASSET_2   = '11111111-2222-4333-8444-666666666666';
    private const FILE_URL  = 'https://pliki.example.test/o/zdjecie.png?X-Amz-Signature=tajny';
    private const LINK      = '/v3/cms/media/' . self::ASSET . '/link';
    private const PUSH      = '/v3/cms/media/push-existing';

    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array<string, array{0: int, 1: array|string}|\WP_Error> */
    private array $routes = [];

    private int $downloads = 0;

    /** Tresc pobieranego pliku albo kod bledu serwera plikow. */
    private string $file_body = '';
    private int $file_code    = 200;

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        require_once ABSPATH . 'wp-admin/includes/template.php';
        update_option(CmsApiClient::OPTION_TOKEN, 'tok-sekretny-123');
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        update_option(MediaPush::OPTION_FLOOR, 0);
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        delete_transient(CmsSync::TRANSIENT_ACTIVE);
        delete_transient(CmsSync::TRANSIENT_PAUSE);
        delete_transient(CmsSync::TRANSIENT_FILE_PROBLEM);
        $this->file_body = (string) base64_decode(self::PNG_1X1);
        $this->file_code = 200;
        $this->downloads = 0;
        $this->requests  = [];
        $this->routes    = [
            'GET /v3/cms/status'   => [200, ['state' => 'active', 'recent_errors' => [], 'image_hosts' => ['pliki.example.test']]],
            'GET /v3/cms/settings' => [200, ['variant' => 'custom', 'options' => ['keep_nonempty_td_to_cms' => true], 'locked_options' => []]],
            'GET /v3/cms/held'     => [200, ['items' => []]],
            'GET /v3/cms/changes'  => [200, ['changes' => [], 'next_cursor' => 'c2', 'has_more' => false]],
            'POST ' . self::LINK   => [200, ['id' => '1', 'status' => 'ok']],
        ];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        remove_all_filters('headlinesforge_cms_poll_budget');
        $_POST    = [];
        $_REQUEST = [];
        parent::tear_down();
    }

    /** @return array<string, mixed>|\WP_Error */
    public function fake_http($pre, array $args, string $url)
    {
        if (str_starts_with($url, 'https://pliki.example.test/')) {
            $this->downloads++;
            // download_url() strumieniuje do pliku tymczasowego: tresc trzeba tam zapisac samemu.
            file_put_contents((string) $args['filename'], $this->file_body);

            return ['response' => ['code' => $this->file_code, 'message' => ''], 'body' => '', 'headers' => [], 'cookies' => [], 'filename' => $args['filename']];
        }
        $this->requests[] = ['url' => $url, 'args' => $args];
        $route = ($args['method'] ?? 'POST') . ' ' . wp_parse_url($url, PHP_URL_PATH);
        $reply = $this->routes[$route] ?? [404, ['code' => 'not_found', 'message' => 'x']];
        if ($reply instanceof \WP_Error) {
            return $reply;
        }
        [$code, $body] = $reply;

        return ['response' => ['code' => $code, 'message' => ''], 'body' => is_string($body) ? $body : wp_json_encode($body), 'headers' => [], 'cookies' => []];
    }

    private function sha(): string
    {
        return hash('sha256', (string) base64_decode(self::PNG_1X1));
    }

    /** @param array<string, mixed> $over */
    private function asset_row(array $over = []): array
    {
        return array_merge([
            'cursor' => 'row-1', 'kind' => 'new_asset', 'asset_id' => self::ASSET, 'changed_at' => '2026-11-01T10:00:00Z',
            'file_url' => self::FILE_URL, 'file_url_expires_at' => '2026-11-02T10:00:00Z', 'mime_type' => 'image/png',
            'filename' => 'Zdjecie redakcji.png', 'sha256' => $this->sha(), 'width' => 1, 'height' => 1,
            'fields' => [
                ['field' => 'title', 'value' => 'Tytul z TD', 'version' => 4, 'changed_at' => '2026-11-01T10:00:00Z', 'changed_by' => 'editor'],
                ['field' => 'alt', 'value' => 'Alt z TD', 'version' => 5, 'changed_at' => '2026-11-01T10:00:00Z', 'changed_by' => 'editor'],
                ['field' => 'caption', 'value' => 'Podpis z TD', 'version' => 6, 'changed_at' => '2026-11-01T10:00:00Z', 'changed_by' => 'editor'],
            ],
        ], $over);
    }

    private function feed(array $changes, string $next = 'c2'): void
    {
        $this->routes['GET /v3/cms/changes'] = [200, ['changes' => $changes, 'next_cursor' => $next, 'has_more' => false]];
    }

    /** @return int[] */
    private function attachments_of(string $asset): array
    {
        return array_map('intval', get_posts([
            'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1,
            'meta_query' => [['key' => MediaHandler::META_ASSET_ID, 'value' => $asset]],
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function bodies(string $path): array
    {
        $found = [];
        foreach ($this->requests as $request) {
            if (wp_parse_url($request['url'], PHP_URL_PATH) === $path) {
                $found[] = json_decode((string) ($request['args']['body'] ?? '{}'), true);
            }
        }

        return $found;
    }

    private function last_error(): string
    {
        return (string) (CmsSync::last_runs()['poll']['error'] ?? '');
    }

    public function test_new_asset_creates_attachment_with_texts_no_parent(): void
    {
        $this->feed([$this->asset_row()]);

        CmsSync::run_poll();

        $ids = $this->attachments_of(self::ASSET);
        $this->assertCount(1, $ids);
        $post = get_post($ids[0]);
        $this->assertSame(0, (int) $post->post_parent);
        $this->assertSame('Tytul z TD', $post->post_title);
        $this->assertSame('Podpis z TD', $post->post_excerpt);
        $this->assertSame('Alt z TD', get_post_meta($ids[0], '_wp_attachment_image_alt', true));
        $this->assertSame(['title' => 4, 'alt' => 5, 'caption' => 6], get_post_meta($ids[0], CmsSync::META_VERSIONS, true));
        $this->assertSame([], MediaMetaSync::dirty_fields($ids[0]));
        $this->assertStringStartsWith('zdjecie-redakcji', wp_basename((string) get_attached_file($ids[0])));

        $link = $this->bodies(self::LINK);
        $this->assertCount(1, $link);
        $this->assertSame((string) $ids[0], $link[0]['cms_media_id']);
        $this->assertSame(wp_get_attachment_url($ids[0]), $link[0]['file_url']);
        $this->assertFalse($link[0]['reencoded']);
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
        $this->assertSame('', $this->last_error());
    }

    public function test_signed_url_is_not_stored(): void
    {
        $this->feed([$this->asset_row()]);

        CmsSync::run_poll();

        $id = $this->attachments_of(self::ASSET)[0];
        $this->assertSame('', get_post_meta($id, '_source_url', true));
        $this->assertStringNotContainsString('tajny', (string) wp_json_encode(get_post_meta($id)));
    }

    public function test_existing_by_asset_id_not_downloaded_and_linked(): void
    {
        $id = (int) self::factory()->attachment->create(['post_title' => 'Z dostawy', 'post_mime_type' => 'image/png']);
        update_post_meta($id, MediaHandler::META_ASSET_ID, self::ASSET);
        update_post_meta($id, '_wp_attached_file', '2026/10/z-dostawy.png');
        $this->feed([$this->asset_row()]);

        CmsSync::run_poll();

        $this->assertSame(0, $this->downloads);
        $this->assertSame([$id], $this->attachments_of(self::ASSET));
        $this->assertSame('Z dostawy', get_post($id)->post_title);
        $link = $this->bodies(self::LINK);
        $this->assertSame((string) $id, $link[0]['cms_media_id']);
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_repeated_row_does_not_create_second_attachment(): void
    {
        $this->routes['POST ' . self::LINK] = [503, ['code' => 'unavailable', 'message' => 'x']];
        $this->feed([$this->asset_row()]);
        CmsSync::run_poll();
        $this->routes['POST ' . self::LINK] = [200, ['id' => '1', 'status' => 'known']];

        CmsSync::run_poll();

        $this->assertSame(1, $this->downloads);
        $this->assertCount(1, $this->attachments_of(self::ASSET));
        $this->assertCount(2, $this->bodies(self::LINK));
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_meta_set_before_thumbnails_so_interrupted_sideload_is_found(): void
    {
        $boom = static function () {
            throw new \RuntimeException('przerwane w miniaturach');
        };
        add_filter('wp_generate_attachment_metadata', $boom);
        $this->feed([$this->asset_row()]);
        try {
            CmsSync::run_poll();
        } catch (\RuntimeException $e) {
            // przerwanie jak fatal w trakcie generowania miniatur
        }
        remove_filter('wp_generate_attachment_metadata', $boom);

        $ids = $this->attachments_of(self::ASSET);
        $this->assertCount(1, $ids);
        $this->assertSame('1', (string) get_post_meta($ids[0], MediaHandler::META_FROM_TD, true));

        $this->assertNotNull(MediaHandler::find_existing('', ['asset_id' => self::ASSET]));
        $before = $this->downloads;
        CmsSync::run_poll();
        $this->assertSame($before, $this->downloads);
        $this->assertCount(1, $this->attachments_of(self::ASSET));
    }

    public function test_marks_from_td_so_not_pushed_back(): void
    {
        global $wpdb;
        $this->feed([$this->asset_row()]);
        CmsSync::run_poll();
        $id      = $this->attachments_of(self::ASSET)[0];
        $control = (int) self::factory()->attachment->create(['post_mime_type' => 'image/png', 'post_status' => 'inherit']);
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_date_gmt = %s WHERE ID IN (%d, %d)", '2020-01-01 00:00:00', $id, $control));

        $this->assertSame('1', get_post_meta($id, MediaHandler::META_FROM_TD, true));
        $candidates = MediaPush::candidates(100);
        $this->assertContains($control, $candidates);
        $this->assertNotContains($id, $candidates);
    }

    public function test_sha_differs_reports_reencoded_true(): void
    {
        $change = static function (array $upload): array {
            file_put_contents($upload['file'], "\n", FILE_APPEND);

            return $upload;
        };
        add_filter('wp_handle_upload', $change);
        $this->feed([$this->asset_row()]);

        CmsSync::run_poll();
        remove_filter('wp_handle_upload', $change);

        $this->assertTrue($this->bodies(self::LINK)[0]['reencoded']);
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_row_without_sha_is_not_reencoded(): void
    {
        $this->feed([$this->asset_row(['sha256' => null])]);

        CmsSync::run_poll();

        $this->assertFalse($this->bodies(self::LINK)[0]['reencoded']);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public function bad_downloads(): array
    {
        return [
            'pusty'       => ['', 200],
            'nie obraz'   => ['<html>blad</html>', 200],
            'blad pliku'  => ['x', 500],
            'inny sha'    => ['PNG', 200],
        ];
    }

    /** @dataProvider bad_downloads */
    public function test_empty_or_not_image_download_fails_row(string $body, int $code): void
    {
        $this->file_code = $code;
        $this->file_body = $body === 'PNG' ? (string) base64_decode(self::PNG_1X1) . "\n" : $body;
        $this->feed([$this->asset_row()]);

        for ($i = 1; $i <= 4; $i++) {
            CmsSync::run_poll();
            $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR), "przebieg $i");
            $this->assertNotSame('', $this->last_error());
        }
        CmsSync::run_poll();

        $this->assertSame([], $this->attachments_of(self::ASSET));
        $this->assertSame([], $this->bodies(self::LINK));
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
        $this->assertSame([], CmsSync::skipped_ids());
        $this->assertStringContainsString('po 5 próbach', $this->last_error());
    }

    public function test_http_url_is_not_downloaded(): void
    {
        $this->feed([$this->asset_row(['file_url' => 'http://pliki.example.test/o/a.png'])]);

        CmsSync::run_poll();

        $this->assertSame(0, $this->downloads);
        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
    }

    /** @return array<string, array{0: array|\WP_Error}> */
    public function link_outages(): array
    {
        return [
            'transport' => [new \WP_Error('http_request_failed', 'tajny')],
            '503'       => [[503, ['code' => 'unavailable', 'message' => 'x']]],
            '500'       => [[500, ['code' => 'internal_error', 'message' => 'x']]],
        ];
    }

    /**
     * @dataProvider link_outages
     * @param array|\WP_Error $reply
     */
    public function test_link_error_keeps_cursor_on_row($reply): void
    {
        $this->routes['POST ' . self::LINK] = $reply;
        $this->feed([$this->asset_row()]);

        // Wiecej niz limit 5 prob: awaria TD nie ma limitu.
        for ($i = 0; $i < 7; $i++) {
            CmsSync::run_poll();
            delete_transient(CmsSync::TRANSIENT_PAUSE);
        }

        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
        $this->assertSame(1, $this->downloads);
        $this->assertCount(7, $this->bodies(self::LINK));
        $this->assertNotSame('', $this->last_error());
    }

    public function test_link_conflict_when_integration_disabled_keeps_cursor(): void
    {
        $this->routes['POST ' . self::LINK] = [409, ['code' => 'state_conflict', 'message' => 'x']];
        $this->feed([$this->asset_row()]);
        $this->assertTrue(CmsSync::is_active());
        $this->routes['GET /v3/cms/status'] = [200, ['state' => 'disabled', 'recent_errors' => [], 'image_hosts' => ['pliki.example.test']]];

        CmsSync::run_poll();

        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
        $this->assertFalse(get_transient(CmsSync::TRANSIENT_ACTIVE));
    }

    public function test_link_conflict_with_status_outage_keeps_cursor(): void
    {
        $this->routes['POST ' . self::LINK] = [409, ['code' => 'state_conflict', 'message' => 'x']];
        $this->feed([$this->asset_row()]);
        $this->assertTrue(CmsSync::is_active());
        $this->routes['GET /v3/cms/status'] = [503, ['code' => 'unavailable', 'message' => 'x']];

        CmsSync::run_poll();

        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
    }

    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public function link_refusals(): array
    {
        return [
            'zajety numer' => [409, 'state_conflict', 'należy już do innego pliku'],
            'brak pliku'   => [404, 'not_found', 'nie zna już pliku'],
            'host'         => [422, 'validation_error', 'dodaj host w panelu Tribune Desk'],
        ];
    }

    /** @dataProvider link_refusals */
    public function test_link_refusal_settles_row_with_fixed_message(int $code, string $api_code, string $message): void
    {
        $this->routes['POST ' . self::LINK] = [$code, ['code' => $api_code, 'message' => 'tresc z serwera']];
        $this->feed([$this->asset_row()]);

        CmsSync::run_poll();

        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
        $this->assertCount(1, $this->attachments_of(self::ASSET));
        $this->assertStringContainsString($message, $this->last_error());
        $this->assertStringNotContainsString('tresc z serwera', $this->last_error());

        // Komunikat nie znika z ekranu po nastepnym pustym przebiegu, a znika po udanym /link.
        $this->feed([]);
        CmsSync::run_poll();
        $this->assertStringContainsString($message, $this->last_error());
        $this->assertCount(1, $this->bodies(self::LINK));

        $this->routes['POST ' . self::LINK] = [200, ['id' => '1', 'status' => 'known']];
        $this->feed([$this->asset_row()], 'c3');
        CmsSync::run_poll();
        $this->assertSame('', $this->last_error());
    }

    public function test_row_without_file_url_settled_without_download(): void
    {
        $this->feed([$this->asset_row(['file_url' => null])]);

        CmsSync::run_poll();

        $this->assertSame(0, $this->downloads);
        $this->assertSame([], $this->bodies(self::LINK));
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_time_budget_stops_after_row_and_keeps_its_cursor(): void
    {
        add_filter('headlinesforge_cms_poll_budget', static fn () => 0);
        $this->feed([$this->asset_row(), $this->asset_row(['cursor' => 'row-2', 'asset_id' => self::ASSET_2])]);

        CmsSync::run_poll();

        $this->assertSame('row-1', get_option(CmsSync::OPTION_CURSOR));
        $this->assertCount(1, $this->attachments_of(self::ASSET));
        $this->assertSame([], $this->attachments_of(self::ASSET_2));
        $this->assertSame(1, $this->downloads);
    }

    public function test_cursor_saved_after_row_when_next_row_fails(): void
    {
        $this->routes['POST /v3/cms/media/' . self::ASSET_2 . '/link'] = [503, ['code' => 'unavailable', 'message' => 'x']];
        $this->feed([$this->asset_row(), $this->asset_row(['cursor' => 'row-2', 'asset_id' => self::ASSET_2])]);

        CmsSync::run_poll();

        $this->assertSame('row-1', get_option(CmsSync::OPTION_CURSOR));
    }

    // --- przycisk ---------------------------------------------------------------------------

    /** Akcja admin_post konczy sie na przekierowaniu, zamiast na exit. Zwraca komunikat. */
    private function click(string $day = ''): string
    {
        $_POST                = ['hf_cms_push_since' => $day];
        $_REQUEST['_wpnonce'] = wp_create_nonce(CmsSettingsScreen::ACTION_PUSH);
        $stop = static function () {
            throw new RuntimeException('redirect');
        };
        add_filter('wp_redirect', $stop);
        try {
            CmsSettingsScreen::handle_push_existing();
            $this->fail('Oczekiwano przekierowania.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $stop);
        }
        ob_start();
        CmsSettingsScreen::render_notice();

        return (string) ob_get_clean();
    }

    public function test_push_existing_button_shows_count(): void
    {
        $this->routes['POST ' . self::PUSH] = [200, ['queued' => 1000, 'held_batch_id' => null, 'next_after' => 'k1']];

        $notice = $this->click();

        $this->assertStringContainsString('1000', $notice);
        $this->assertStringContainsString('Kliknij ponownie', $notice);
        $this->assertSame(['since' => null], $this->bodies(self::PUSH)[0]);
        $this->assertSame('k1', get_option(CmsSettingsScreen::OPTION_PUSH)['after']);
    }

    public function test_push_existing_sends_after_until_null_then_clears(): void
    {
        $this->routes['POST ' . self::PUSH] = [200, ['queued' => 0, 'held_batch_id' => null, 'next_after' => 'k1']];
        // queued = 0 nie konczy przebiegu: konczy go dopiero null.
        $this->assertStringContainsString('Kliknij ponownie', $this->click());

        $this->routes['POST ' . self::PUSH] = [200, ['queued' => 7, 'held_batch_id' => null, 'next_after' => null]];
        $notice = $this->click();

        $this->assertSame('k1', $this->bodies(self::PUSH)[1]['after']);
        $this->assertStringContainsString('Wszystkie starsze pliki są wysłane', $notice);
        $this->assertStringContainsString('7', $notice);
        $this->assertFalse(get_option(CmsSettingsScreen::OPTION_PUSH));

        $this->click();
        $this->assertArrayNotHasKey('after', $this->bodies(self::PUSH)[2]);
    }

    public function test_push_existing_new_date_starts_from_beginning(): void
    {
        $this->routes['POST ' . self::PUSH] = [200, ['queued' => 5, 'held_batch_id' => null, 'next_after' => 'k1']];
        $this->click('2026-01-01');
        $this->click('2026-01-01');
        $this->click('2026-02-01');

        $bodies = $this->bodies(self::PUSH);
        $this->assertArrayNotHasKey('after', $bodies[0]);
        $this->assertSame('k1', $bodies[1]['after']);
        $this->assertArrayNotHasKey('after', $bodies[2]);
        $this->assertStringStartsWith('2026-02-01', $bodies[2]['since']);
    }

    public function test_push_existing_without_cursor_does_not_call_route(): void
    {
        delete_option(CmsSync::OPTION_CURSOR);

        $notice = $this->click();

        $this->assertStringContainsString('Kanał zmian nie był jeszcze pobrany', $notice);
        $this->assertSame([], $this->requests);
    }

    public function test_push_existing_conflict_shows_fixed_message_and_keeps_progress(): void
    {
        update_option(CmsSettingsScreen::OPTION_PUSH, ['day' => '', 'after' => 'k1'], false);
        $this->routes['POST ' . self::PUSH] = [409, ['code' => 'state_conflict', 'message' => 'tresc z serwera']];

        $notice = $this->click();

        $this->assertStringContainsString('Kanał zmian nie był jeszcze pobrany', $notice);
        $this->assertStringNotContainsString('tresc z serwera', $notice);
        $this->assertSame('k1', get_option(CmsSettingsScreen::OPTION_PUSH)['after']);
    }

    public function test_push_existing_response_without_next_after_is_rejected(): void
    {
        $this->routes['POST ' . self::PUSH] = [200, ['queued' => 3]];

        $notice = $this->click();

        $this->assertStringContainsString('nieoczekiwaną odpowiedź', $notice);
        $this->assertStringNotContainsString('Wszystkie starsze pliki', $notice);
    }

    public function test_push_existing_requires_manage_options(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'editor']));
        $_REQUEST['_wpnonce'] = wp_create_nonce(CmsSettingsScreen::ACTION_PUSH);

        $this->expectException(WPDieException::class);
        CmsSettingsScreen::handle_push_existing();
    }

    public function test_screen_renders_button_without_calling_route(): void
    {
        ob_start();
        CmsSettingsScreen::render_block();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Wyślij starsze pliki z Tribune Desk', $html);
        $this->assertStringContainsString(CmsSettingsScreen::ACTION_PUSH, $html);
        $this->assertSame([], $this->bodies(self::PUSH));
    }
}
