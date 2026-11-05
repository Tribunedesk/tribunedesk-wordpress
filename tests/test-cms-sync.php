<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsSettingsScreen;
use TribuneDesk\WordPress\CmsSync;
use TribuneDesk\WordPress\MediaMetaSync;
use TribuneDesk\WordPress\MediaPush;
use TribuneDesk\WordPress\PublishedUrlsClient;
use TribuneDesk\WordPress\RestEndpoint;

class Test_Cms_Sync extends WP_UnitTestCase
{
    private const TOKEN = 'tok-sekretny-123';

    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array<string, array{0: int, 1: array|string}|\WP_Error> klucz: "METODA /sciezka" */
    private array $routes = [];

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        require_once ABSPATH . 'wp-admin/includes/template.php';
        update_option(CmsApiClient::OPTION_TOKEN, self::TOKEN);
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        update_option(RestEndpoint::OPTION_SECRET, 'sekret-testowy');
        update_option(MediaPush::OPTION_FLOOR, PHP_INT_MAX);
        wp_clear_scheduled_hook(CmsSync::HOOK_SEND);
        $this->requests = [];
        $this->routes   = [
            'GET /v3/cms/status'       => [200, ['state' => 'active', 'recent_errors' => []]],
            'GET /v3/cms/settings'     => [200, $this->settings(false)],
            'GET /v3/cms/changes'      => [200, ['changes' => [], 'next_cursor' => 'c1', 'has_more' => false]],
            'POST /v3/cms/media/fields' => [200, ['results' => []]],
            'POST /v3/cms/media'       => [200, ['results' => []]],
            'GET /v3/cms/held'         => [200, ['items' => []]],
        ];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        // D6: sledzone sa zmiany zalogowanego uzytkownika.
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        wp_clear_scheduled_hook(CmsSync::HOOK_SEND);
        parent::tear_down();
    }

    /** @return array<string, mixed>|\WP_Error */
    public function fake_http($pre, array $args, string $url)
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        $route = ($args['method'] ?? 'POST') . ' ' . wp_parse_url($url, PHP_URL_PATH);
        $reply = $this->routes[$route] ?? [404, ['code' => 'not_found', 'message' => 'x']];
        if ($reply instanceof \WP_Error) {
            return $reply;
        }
        [$code, $body] = $reply;

        return ['response' => ['code' => $code, 'message' => ''], 'body' => is_string($body) ? $body : wp_json_encode($body), 'headers' => [], 'cookies' => []];
    }

    private function settings(bool $keep): array
    {
        return ['variant' => 'custom', 'options' => ['keep_nonempty_td_to_cms' => $keep], 'locked_options' => []];
    }

    private function attachment(array $over = []): int
    {
        return (int) self::factory()->attachment->create(array_merge(
            ['post_title' => 'T', 'post_excerpt' => '', 'post_content' => 'Opis'],
            $over
        ));
    }

    private function row(int $id, string $field, string $value, int $version = 3): array
    {
        return [
            'cursor' => 'x', 'kind' => 'fields', 'cms_media_id' => (string) $id, 'changed_at' => '2026-11-01T10:00:00Z',
            'fields' => [['field' => $field, 'value' => $value, 'version' => $version, 'changed_at' => '2026-11-01T10:00:00Z', 'changed_by' => 'editor']],
        ];
    }

    private function feed(array $changes, string $next = 'c2', bool $more = false): void
    {
        $this->routes['GET /v3/cms/changes'] = [200, ['changes' => $changes, 'next_cursor' => $next, 'has_more' => $more]];
    }

    /** @return list<array{url: string, args: array}> */
    private function calls(string $path, string $method = ''): array
    {
        return array_values(array_filter($this->requests, static function ($r) use ($path, $method) {
            return wp_parse_url($r['url'], PHP_URL_PATH) === $path && ($method === '' || $r['args']['method'] === $method);
        }));
    }

    private function dirty(int $id): array
    {
        $dirty = get_post_meta($id, MediaMetaSync::META_DIRTY, true);

        return is_array($dirty) ? $dirty : [];
    }

    private function versions(int $id): array
    {
        $versions = get_post_meta($id, CmsSync::META_VERSIONS, true);

        return is_array($versions) ? $versions : [];
    }

    private function edited(string $field = 'post_excerpt', string $value = 'Nowy'): int
    {
        $id = $this->attachment();
        wp_update_post(['ID' => $id, $field => $value]);

        return $id;
    }

    private function fields_reply(int $id, array $fields, string $status = 'ok'): void
    {
        $this->routes['POST /v3/cms/media/fields'] = [200, ['results' => [['id' => (string) $id, 'status' => $status, 'fields' => $fields]]]];
    }

    // --- tryb --------------------------------------------------------------------------------

    public function test_inactive_sends_nothing_and_keeps_queue(): void
    {
        $this->routes['GET /v3/cms/status'] = [200, ['state' => 'disabled', 'recent_errors' => []]];
        $id = $this->edited();

        CmsSync::run_poll();
        CmsSync::run_send();
        MediaPush::run();

        $this->assertSame([], $this->calls('/v3/cms/changes'));
        $this->assertSame([], $this->calls('/v3/cms/media/fields'));
        $this->assertSame([], $this->calls('/v2/cms/media/meta'));
        $this->assertSame(['caption'], $this->dirty($id));
    }

    public function test_no_token_is_inactive_without_http(): void
    {
        delete_option(CmsApiClient::OPTION_TOKEN);
        $this->assertFalse(CmsSync::is_active());
        $this->assertSame([], $this->requests);
    }

    public function test_hourly_run_sends_only_through_v3(): void
    {
        $id = $this->edited();
        $this->fields_reply($id, [['field' => 'caption', 'status' => 'ok', 'version' => 2]]);

        MediaPush::run();

        $this->assertSame([], $this->calls('/v2/cms/media/meta'));
        $this->assertSame([], $this->calls('/v2/cms/media'));
        // Godzinny cron jest siatka bezpieczenstwa: wysyla, ale przez /v3.
        $this->assertCount(1, $this->calls('/v3/cms/media/fields'));
    }

    public function test_is_active_is_cached_and_error_does_not_switch_mode(): void
    {
        $this->assertTrue(CmsSync::is_active());
        $this->assertTrue(CmsSync::is_active());
        $this->assertCount(1, $this->calls('/v3/cms/status'));

        // Po wygasnieciu blad zostawia ostatni znany tryb i nie odswieza pamieci.
        delete_transient(CmsSync::TRANSIENT_ACTIVE);
        $this->routes['GET /v3/cms/status'] = [503, ['code' => 'unavailable', 'message' => 'x']];
        $this->assertTrue(CmsSync::is_active());
        $this->assertFalse(get_transient(CmsSync::TRANSIENT_ACTIVE));

        $this->routes['GET /v3/cms/status'] = [200, ['state' => 'disabled', 'recent_errors' => []]];
        $this->assertFalse(CmsSync::is_active());
    }

    public function test_status_error_with_no_known_mode_keeps_old_path(): void
    {
        $this->routes['GET /v3/cms/status'] = [500, ''];
        $this->assertFalse(CmsSync::is_active());
    }

    // --- kanal -------------------------------------------------------------------------------

    public function test_first_poll_has_no_cursor_and_stores_next(): void
    {
        CmsSync::run_poll();

        $calls = $this->calls('/v3/cms/changes');
        $this->assertCount(1, $calls);
        $this->assertStringNotContainsString('cursor=', $calls[0]['url']);
        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_poll_applies_fields_without_marking(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->attachment();
        wp_clear_scheduled_hook(CmsSync::HOOK_SEND); // nowy plik sam planuje wysylke
        $this->feed([$this->row($id, 'caption', 'Podpis "z" \\ TD'), $this->row($id, 'alt', 'Alt z TD', 7)]);

        CmsSync::run_poll();

        $this->assertStringContainsString('cursor=c1', $this->calls('/v3/cms/changes')[0]['url']);
        $this->assertSame('Podpis "z" \\ TD', get_post($id)->post_excerpt);
        $this->assertSame('Alt z TD', get_post_meta($id, MediaMetaSync::META_ALT, true));
        $this->assertSame([], $this->dirty($id));
        $this->assertFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
        $this->assertSame(['caption' => 3, 'alt' => 7], $this->versions($id));
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_poll_keeps_cursor_on_error(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->routes['GET /v3/cms/changes'] = new \WP_Error('http_request_failed', 'boom ' . self::TOKEN);

        CmsSync::run_poll();

        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
        $last = CmsSync::last_runs()['poll'];
        $this->assertNotSame('', $last['error']);
        $this->assertStringNotContainsString(self::TOKEN, $last['error']);
    }

    public function test_poll_keeps_cursor_when_a_row_cannot_be_applied(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->attachment();
        add_filter('wp_insert_post_empty_content', '__return_true');
        $this->feed([$this->row($id, 'caption', 'X')]);

        CmsSync::run_poll();

        remove_filter('wp_insert_post_empty_content', '__return_true');
        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
        $this->assertSame([], $this->versions($id));
    }

    public function test_poll_skips_missing_attachment_and_advances(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->attachment();
        $this->feed([$this->row(999999, 'caption', 'X'), $this->row($id, 'caption', 'Dalej')]);

        CmsSync::run_poll();

        $this->assertSame('Dalej', get_post($id)->post_excerpt);
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_poll_skips_unknown_kinds_and_settings_drops_cache(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->assertTrue(CmsSync::is_active());
        $this->feed([
            ['cursor' => 'a', 'kind' => 'new_asset', 'changed_at' => 'x'],
            ['cursor' => 'b', 'kind' => 'jutrzejszy', 'changed_at' => 'x'],
            ['cursor' => 'c', 'kind' => 'settings', 'changed_at' => 'x'],
        ]);

        CmsSync::run_poll();

        $this->assertFalse(get_transient(CmsSync::TRANSIENT_ACTIVE));
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_keep_nonempty_td_to_cms_skips(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->routes['GET /v3/cms/settings'] = [200, $this->settings(true)];
        $full  = $this->attachment(['post_excerpt' => 'Moje']);
        $empty = $this->attachment();
        $this->feed([$this->row($full, 'caption', 'Z TD'), $this->row($empty, 'caption', 'Z TD')]);

        CmsSync::run_poll();

        $this->assertSame('Moje', get_post($full)->post_excerpt);
        $this->assertSame('Z TD', get_post($empty)->post_excerpt);
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_settings_error_keeps_cursor_when_rule_is_needed(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->routes['GET /v3/cms/settings'] = [503, ['code' => 'unavailable', 'message' => 'x']];
        $full = $this->attachment(['post_excerpt' => 'Moje']);
        $this->feed([$this->row($full, 'caption', 'Z TD')]);

        CmsSync::run_poll();

        $this->assertSame('Moje', get_post($full)->post_excerpt);
        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_410_sets_needs_resync_and_stops(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->routes['GET /v3/cms/changes'] = [410, ['code' => 'cursor_expired', 'message' => 'x']];

        CmsSync::run_poll();
        $this->assertTrue((bool) get_option(CmsSync::OPTION_NEEDS_RESYNC));
        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));

        $this->requests = [];
        CmsSync::run_poll();
        $this->assertSame([], $this->calls('/v3/cms/changes'));
    }

    public function test_poll_reads_at_most_three_pages(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->feed([], 'c9', true);

        CmsSync::run_poll();

        $this->assertCount(3, $this->calls('/v3/cms/changes'));
    }

    public function test_locked_poll_does_nothing_and_lock_expires(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->assertTrue(CmsSync::acquire_lock());
        $this->assertFalse(CmsSync::acquire_lock());
        CmsSync::run_poll();
        $this->assertSame([], $this->calls('/v3/cms/changes'));

        // Blokada po terminie przechodzi na nastepny przebieg, a po przebiegu znika.
        global $wpdb;
        $wpdb->update($wpdb->options, ['option_value' => (string) (time() - 1)], ['option_name' => CmsSync::OPTION_LOCK]);
        CmsSync::run_poll();
        $this->assertCount(1, $this->calls('/v3/cms/changes'));
        $this->assertTrue(CmsSync::acquire_lock());
        CmsSync::release_lock();
    }

    public function test_rate_limit_pauses_both_directions(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->routes['GET /v3/cms/changes'] = [429, ['code' => 'rate_limited', 'message' => 'x', 'retry_after' => 30]];
        CmsSync::run_poll();

        $this->requests = [];
        $this->edited();
        CmsSync::run_poll();
        CmsSync::run_send();
        $this->assertSame([], $this->calls('/v3/cms/changes'));
        $this->assertSame([], $this->calls('/v3/cms/media/fields'));
    }

    // --- obie strony tego samego pola ---------------------------------------------------------

    public function test_poll_leaves_field_dirty_locally_for_the_conflict_rule(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->edited('post_excerpt', 'Redaktor');
        update_post_meta($id, CmsSync::META_VERSIONS, ['caption' => 2]);
        $this->feed([$this->row($id, 'caption', 'Z TD', 3)]);

        CmsSync::run_poll();

        // Bez nadpisania i ze stara wersja: serwer rozstrzygnie regula przy wysylce.
        $this->assertSame('Redaktor', get_post($id)->post_excerpt);
        $this->assertSame(['caption'], $this->dirty($id));
        $this->assertSame(['caption' => 2], $this->versions($id));
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));

        $this->fields_reply($id, [['field' => 'caption', 'status' => 'conflict', 'version' => 3, 'winning_value' => 'Z TD']], 'conflict');
        CmsSync::run_send();
        $sent = json_decode($this->calls('/v3/cms/media/fields')[0]['args']['body'], true);
        $this->assertSame(2, $sent['items'][0]['fields'][0]['base_version']);
        $this->assertSame('Z TD', get_post($id)->post_excerpt);
        $this->assertSame([], $this->dirty($id));
    }

    public function test_poll_skips_row_not_newer_than_stored_version(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->attachment(['post_excerpt' => 'Wyslane z WP']);
        update_post_meta($id, CmsSync::META_VERSIONS, ['caption' => 5]);
        $this->feed([$this->row($id, 'caption', 'Stare z TD', 4)]);

        CmsSync::run_poll();

        $this->assertSame('Wyslane z WP', get_post($id)->post_excerpt);
        $this->assertSame(['caption' => 5], $this->versions($id));
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_poll_skips_own_row_at_equal_version(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->attachment(['post_excerpt' => 'Nowsze w WP']);
        update_post_meta($id, CmsSync::META_VERSIONS, ['caption' => 5]);
        $row = $this->row($id, 'caption', 'Stare z TD', 5);
        $row['fields'][0]['base_version'] = 4;
        $this->feed([$row]);

        CmsSync::run_poll();

        $this->assertSame('Nowsze w WP', get_post($id)->post_excerpt);
    }

    public function test_resync_row_at_equal_version_writes_the_value(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->attachment(['post_excerpt' => 'Z WP']);
        update_post_meta($id, CmsSync::META_VERSIONS, ['caption' => 5]);
        $row = $this->row($id, 'caption', 'Z resync TD', 5);
        $row['fields'][0]['base_version'] = null; // tak serwer wysyla wiersz resync
        $this->feed([$row]);

        CmsSync::run_poll();

        $this->assertSame('Z resync TD', get_post($id)->post_excerpt);
        $this->assertSame(['caption' => 5], $this->versions($id));
        $this->assertSame([], $this->dirty($id));
    }

    // --- wysylka -----------------------------------------------------------------------------

    public function test_send_includes_base_version_and_changed_at(): void
    {
        $id = $this->attachment();
        update_post_meta($id, CmsSync::META_VERSIONS, ['caption' => 4]);
        $before = time();
        wp_update_post(['ID' => $id, 'post_excerpt' => 'Nowy', 'post_title' => 'Tytul']);
        update_post_meta($id, MediaMetaSync::META_DIRTY_AT, ['caption' => 1793000000, 'title' => $before]);
        $this->fields_reply($id, [
            ['field' => 'title', 'status' => 'ok', 'version' => 1],
            ['field' => 'caption', 'status' => 'ok', 'version' => 5],
        ]);

        CmsSync::run_send();

        $calls = $this->calls('/v3/cms/media/fields');
        $this->assertSame('Bearer ' . self::TOKEN, $calls[0]['args']['headers']['Authorization']);
        $item = json_decode($calls[0]['args']['body'], true)['items'][0];
        $this->assertSame((string) $id, $item['cms_media_id']);
        $fields = array_column($item['fields'], null, 'field');
        $this->assertSame('Nowy', $fields['caption']['value']);
        $this->assertSame(4, $fields['caption']['base_version']);
        $this->assertSame(gmdate('c', 1793000000), $fields['caption']['changed_at']);
        $this->assertSame(1, $fields['title']['base_version']);
        $this->assertMatchesRegularExpression('/[+-]\d\d:\d\d$/', $fields['title']['changed_at']);

        $this->assertSame([], $this->dirty($id));
        $this->assertSame('', get_post_meta($id, MediaMetaSync::META_DIRTY_AT, true));
        $this->assertSame(['caption' => 5, 'title' => 1], $this->versions($id));
    }

    public function test_mark_records_when_the_field_changed(): void
    {
        $before = time();
        $id     = $this->edited();
        $at     = get_post_meta($id, MediaMetaSync::META_DIRTY_AT, true);
        $this->assertGreaterThanOrEqual($before, $at['caption']);
    }

    public function test_next_send_after_own_result_uses_returned_version(): void
    {
        $id = $this->edited();
        $this->fields_reply($id, [['field' => 'caption', 'status' => 'ok', 'version' => 9]]);
        CmsSync::run_send();

        wp_update_post(['ID' => $id, 'post_excerpt' => 'Drugi']);
        $this->requests = [];
        CmsSync::run_send();

        $sent = json_decode($this->calls('/v3/cms/media/fields')[0]['args']['body'], true);
        $this->assertSame(9, $sent['items'][0]['fields'][0]['base_version']);
    }

    public function test_conflict_applies_winning_value(): void
    {
        $id = $this->edited();
        $this->fields_reply($id, [['field' => 'caption', 'status' => 'conflict', 'version' => 6, 'winning_value' => 'Wygrany']], 'conflict');

        CmsSync::run_send();

        $this->assertSame('Wygrany', get_post($id)->post_excerpt);
        $this->assertSame([], $this->dirty($id));
        $this->assertSame(['caption' => 6], $this->versions($id));
    }

    public function test_first_edit_under_td_rule_conflicts_when_td_is_ahead(): void
    {
        $id = $this->edited('post_excerpt', 'Redaktor');
        $this->assertSame([], $this->versions($id));
        $this->routes['POST /v3/cms/media/fields'] = [200, ['results' => []]];
        // Serwer z regula `td`: base_version 1 przy wersji 4 to konflikt; bez niej wygralaby data.
        $seen = null;
        $spy  = function ($pre, $args, $url) use ($id, &$seen) {
            if (str_ends_with($url, '/media/fields')) {
                $seen = json_decode($args['body'], true)['items'][0]['fields'][0];
                if (($seen['base_version'] ?? null) === 1) {
                    $this->fields_reply($id, [['field' => 'caption', 'status' => 'conflict', 'version' => 4, 'winning_value' => 'Z TD']], 'ok');
                }
            }

            return $pre;
        };
        add_filter('pre_http_request', $spy, 5, 3);
        CmsSync::run_send();
        remove_filter('pre_http_request', $spy, 5);

        $this->assertSame(1, $seen['base_version']);
        $this->assertSame('Z TD', get_post($id)->post_excerpt);
        $this->assertSame(['caption' => 4], $this->versions($id));
        $this->assertSame([], $this->dirty($id));
    }

    public function test_held_and_rejected_clear_queue(): void
    {
        $id = $this->attachment();
        wp_update_post(['ID' => $id, 'post_excerpt' => 'A', 'post_title' => 'B']);
        $this->fields_reply($id, [
            ['field' => 'title', 'status' => 'held', 'version' => 2],
            ['field' => 'caption', 'status' => 'rejected', 'version' => 1, 'reason' => 'field_disabled'],
        ], 'held');

        CmsSync::run_send();

        $this->assertSame([], $this->dirty($id));
        $this->assertSame('A', get_post($id)->post_excerpt);
    }

    public function test_unknown_item_clears_queue(): void
    {
        $id = $this->edited();
        $this->fields_reply($id, [], 'unknown');
        CmsSync::run_send();
        $this->assertSame([], $this->dirty($id));

        foreach (['accepted@' . (time() - DAY_IN_SECONDS - 60), 'known@' . time(), 'rejected:host@' . time()] as $pushed) {
            $id = $this->edited();
            update_post_meta($id, MediaPush::META_PUSHED, $pushed);
            $this->fields_reply($id, [], 'unknown');
            CmsSync::run_send();
            $this->assertSame([], $this->dirty($id), $pushed);
        }
    }

    public function test_unknown_keeps_queue_while_file_import_is_pending(): void
    {
        $id = $this->edited();
        update_post_meta($id, MediaPush::META_PUSHED, 'accepted@' . (time() - 60));
        $this->fields_reply($id, [], 'unknown');
        wp_clear_scheduled_hook(CmsSync::HOOK_SEND);

        CmsSync::run_send();

        $this->assertSame(['caption'], $this->dirty($id));
        // Raz w przebiegu, nastepna proba za 5 minut, a nie co minute.
        $this->assertCount(1, $this->calls('/v3/cms/media/fields'));
        $this->assertGreaterThanOrEqual(time() + 290, wp_next_scheduled(CmsSync::HOOK_SEND));
        $this->assertSame('', CmsSync::last_runs()['send']['error']);
    }

    public function test_send_failure_keeps_queue(): void
    {
        $id = $this->edited();
        foreach ([[503, ['code' => 'unavailable', 'message' => 'x']], new \WP_Error('http_request_failed', 'x'), [409, ['code' => 'state_conflict', 'message' => 'x']]] as $reply) {
            delete_transient(CmsSync::TRANSIENT_PAUSE);
            $this->routes['POST /v3/cms/media/fields'] = $reply;
            CmsSync::run_send();
            $this->assertSame(['caption'], $this->dirty($id));
            $this->assertNotSame('', CmsSync::last_runs()['send']['error']);
        }
    }

    public function test_state_conflict_drops_mode_cache(): void
    {
        $this->edited();
        $this->assertTrue(CmsSync::is_active());
        $this->routes['POST /v3/cms/media/fields'] = [409, ['code' => 'state_conflict', 'message' => 'x']];
        CmsSync::run_send();
        $this->assertFalse(get_transient(CmsSync::TRANSIENT_ACTIVE));
    }

    public function test_field_changed_during_send_stays_in_queue(): void
    {
        $id = $this->edited();
        $this->routes['POST /v3/cms/media/fields'] = [200, ['results' => [['id' => (string) $id, 'status' => 'ok', 'fields' => [['field' => 'caption', 'status' => 'ok', 'version' => 2]]]]]];
        $edit = static function ($pre, $args, $url) use ($id) {
            if (str_ends_with($url, '/media/fields')) {
                wp_update_post(['ID' => $id, 'post_excerpt' => 'W trakcie']);
            }

            return $pre;
        };
        add_filter('pre_http_request', $edit, 5, 3);
        CmsSync::run_send();
        remove_filter('pre_http_request', $edit, 5);

        // Pole zostalo w kolejce po pierwszej porcji, wiec nastepna wysyla juz nowa wartosc.
        $calls = $this->calls('/v3/cms/media/fields');
        $this->assertCount(2, $calls);
        $this->assertSame('Nowy', json_decode($calls[0]['args']['body'], true)['items'][0]['fields'][0]['value']);
        $this->assertSame('W trakcie', json_decode($calls[1]['args']['body'], true)['items'][0]['fields'][0]['value']);
        $this->assertSame([], $this->dirty($id));
    }

    public function test_too_long_value_does_not_block_the_batch(): void
    {
        $long = $this->edited('post_content', str_repeat('x', 5001));
        $ok   = $this->edited();
        $this->fields_reply($ok, [['field' => 'caption', 'status' => 'ok', 'version' => 2]]);

        CmsSync::run_send();

        $items = json_decode($this->calls('/v3/cms/media/fields')[0]['args']['body'], true)['items'];
        $this->assertSame([(string) $ok], array_column($items, 'cms_media_id'));
        $this->assertSame([], $this->dirty($long));
        $this->assertNotSame('', CmsSync::last_runs()['send']['error']);
    }

    public function test_nul_value_does_not_block_the_batch(): void
    {
        $bad = $this->attachment();
        update_post_meta($bad, MediaMetaSync::META_ALT, "a\0b");
        $ok = $this->edited();
        $this->fields_reply($ok, [['field' => 'caption', 'status' => 'ok', 'version' => 2]]);

        CmsSync::run_send();

        $items = json_decode($this->calls('/v3/cms/media/fields')[0]['args']['body'], true)['items'];
        $this->assertSame([(string) $ok], array_column($items, 'cms_media_id'));
        $this->assertSame([], $this->dirty($bad));
        $this->assertNotSame('', CmsSync::last_runs()['send']['error']);
    }

    public function test_edit_schedules_send_in_60s_once(): void
    {
        $before = time();
        $id     = $this->attachment();
        wp_update_post(['ID' => $id, 'post_excerpt' => 'A']);
        wp_update_post(['ID' => $id, 'post_excerpt' => 'B']);
        update_post_meta($id, MediaMetaSync::META_ALT, 'C');

        $at = wp_next_scheduled(CmsSync::HOOK_SEND);
        $this->assertGreaterThanOrEqual($before + 60, $at);
        $this->assertLessThanOrEqual(time() + 60, $at);

        $count = 0;
        foreach (_get_cron_array() as $hooks) {
            $count += count($hooks[CmsSync::HOOK_SEND] ?? []);
        }
        $this->assertSame(1, $count);
        // Bez wysylki przy zapisie.
        $this->assertSame([], $this->requests);
    }

    public function test_edit_without_token_schedules_nothing(): void
    {
        delete_option(CmsApiClient::OPTION_TOKEN);
        $this->edited();
        $this->assertFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
    }

    public function test_locked_send_tries_again_later(): void
    {
        CmsSync::acquire_lock();
        CmsSync::run_send();
        $this->assertSame([], $this->calls('/v3/cms/media/fields'));
        $this->assertNotFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
        CmsSync::release_lock();
    }

    // --- zmiana z TD czekajaca przy polu oznaczonym ------------------------------------------

    /** Wariant td_to_cms_only: edycja w WP i nieprzeczytana zmiana w TD. Zbiega do TD. */
    public function test_rejected_send_before_poll_converges_to_td(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->edited('post_excerpt', 'Redaktor');
        update_post_meta($id, CmsSync::META_VERSIONS, ['caption' => 2]);
        $this->fields_reply($id, [['field' => 'caption', 'status' => 'rejected', 'version' => 3, 'reason' => 'field_disabled']], 'rejected');

        CmsSync::run_send();
        $this->assertSame(['caption' => 2], $this->versions($id));

        $this->feed([$this->row($id, 'caption', 'Z TD', 3)]);
        CmsSync::run_poll();

        $this->assertSame('Z TD', get_post($id)->post_excerpt);
        $this->assertSame(['caption' => 3], $this->versions($id));
        $this->assertSame([], $this->dirty($id));
    }

    public function test_rejected_send_after_poll_converges_to_td(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->edited('post_excerpt', 'Redaktor');
        update_post_meta($id, CmsSync::META_VERSIONS, ['caption' => 2]);
        $this->feed([$this->row($id, 'caption', 'Z TD', 3)]);
        CmsSync::run_poll();
        $this->assertSame('Redaktor', get_post($id)->post_excerpt);

        $this->fields_reply($id, [['field' => 'caption', 'status' => 'rejected', 'version' => 3, 'reason' => 'field_disabled']], 'rejected');
        CmsSync::run_send();

        $this->assertSame('Z TD', get_post($id)->post_excerpt);
        $this->assertSame(['caption' => 3], $this->versions($id));
        $this->assertSame([], $this->dirty($id));
        $this->assertSame('', get_post_meta($id, CmsSync::META_PENDING_TD, true));
    }

    public function test_rejected_kept_row_obeys_keep_nonempty(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->routes['GET /v3/cms/settings'] = [200, $this->settings(true)];
        $id = $this->edited('post_excerpt', 'Redaktor');
        $this->feed([$this->row($id, 'caption', 'Z TD', 3)]);
        CmsSync::run_poll();

        $this->fields_reply($id, [['field' => 'caption', 'status' => 'rejected', 'version' => 3]], 'rejected');
        CmsSync::run_send();

        $this->assertSame('Redaktor', get_post($id)->post_excerpt);
        $this->assertSame(['caption' => 3], $this->versions($id));
        $this->assertSame([], $this->dirty($id));
    }

    public function test_rejected_with_settings_error_leaves_queue(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->routes['GET /v3/cms/settings'] = [503, ['code' => 'unavailable', 'message' => 'x']];
        $id = $this->edited('post_excerpt', 'Redaktor');
        $this->feed([$this->row($id, 'caption', 'Z TD', 3)]);
        CmsSync::run_poll();

        $this->fields_reply($id, [['field' => 'caption', 'status' => 'rejected', 'version' => 3]], 'rejected');
        CmsSync::run_send();

        $this->assertSame(['caption'], $this->dirty($id));
        $this->assertSame('Redaktor', get_post($id)->post_excerpt);
        $this->assertNotSame('', CmsSync::last_runs()['send']['error']);
    }

    private function held_row(string $batch = 'b1'): int
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->edited('post_excerpt', 'Redaktor');
        $this->feed([$this->row($id, 'caption', 'Z TD', 3)]);
        CmsSync::run_poll();
        $this->routes['POST /v3/cms/media/fields'] = [200, [
            'results' => [['id' => (string) $id, 'status' => 'held', 'fields' => [['field' => 'caption', 'status' => 'held', 'version' => 3]]]],
            'held_batch_id' => $batch,
        ]];
        CmsSync::run_send();
        $this->assertSame([], $this->dirty($id));
        $this->assertSame([], $this->versions($id));
        $this->assertSame('Redaktor', get_post($id)->post_excerpt);
        $this->feed([], 'c3');

        return $id;
    }

    public function test_held_keeps_row_until_next_feed(): void
    {
        $id = $this->held_row();
        CmsSync::run_poll();
        $this->assertSame('Redaktor', get_post($id)->post_excerpt);

        $this->feed([$this->row($id, 'caption', 'Nowsze z TD', 4)], 'c4');
        CmsSync::run_poll();
        $this->assertSame('Nowsze z TD', get_post($id)->post_excerpt);
        $this->assertSame('', get_post_meta($id, CmsSync::META_PENDING_TD, true));
    }

    public function test_held_discard_applies_kept_row(): void
    {
        $id = $this->held_row();
        CmsSync::note_held_decision('b1', 'discard');
        CmsSync::run_poll();

        $this->assertSame('Z TD', get_post($id)->post_excerpt);
        $this->assertSame(['caption' => 3], $this->versions($id));
        $this->assertSame('', get_post_meta($id, CmsSync::META_PENDING_TD, true));
    }

    public function test_auto_discard_on_the_server_settles_kept_row(): void
    {
        $id = $this->held_row();
        $this->routes['GET /v3/cms/held'] = [200, ['items' => [['id' => 'b1', 'state' => 'discarded']]]];

        CmsSync::run_poll();

        $this->assertSame('Z TD', get_post($id)->post_excerpt);
        $this->assertSame(['caption' => 3], $this->versions($id));
        $this->assertSame('', get_post_meta($id, CmsSync::META_PENDING_TD, true));
        $this->assertSame([], get_option(CmsSync::OPTION_DECIDED));
    }

    public function test_api_send_decision_settles_kept_row(): void
    {
        $id = $this->held_row();
        $this->routes['GET /v3/cms/held'] = [200, ['items' => [['id' => 'b1', 'state' => 'sent']]]];

        CmsSync::run_poll();

        $this->assertSame('Redaktor', get_post($id)->post_excerpt);
        $this->assertSame('', get_post_meta($id, CmsSync::META_PENDING_TD, true));
    }

    public function test_pending_or_unlisted_batch_keeps_row_and_held_error_does_not_stop_feed(): void
    {
        $id = $this->held_row();
        $this->routes['GET /v3/cms/held'] = [200, ['items' => [['id' => 'b1', 'state' => 'pending'], ['id' => 'inna', 'state' => 'discarded']]]];
        CmsSync::run_poll();
        $this->assertSame('Redaktor', get_post($id)->post_excerpt);
        $this->assertNotSame('', get_post_meta($id, CmsSync::META_PENDING_TD, true));

        $this->routes['GET /v3/cms/held'] = [503, ['code' => 'unavailable', 'message' => 'x']];
        CmsSync::run_poll();
        $this->assertNotSame('', get_post_meta($id, CmsSync::META_PENDING_TD, true));
        $this->assertSame('c3', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_no_kept_rows_means_no_held_request(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        CmsSync::run_poll();
        $this->assertSame([], $this->calls('/v3/cms/held'));
    }

    public function test_held_send_drops_kept_row(): void
    {
        $id = $this->held_row();
        CmsSync::note_held_decision('b1', 'send');
        CmsSync::run_poll();

        $this->assertSame('Redaktor', get_post($id)->post_excerpt);
        $this->assertSame('', get_post_meta($id, CmsSync::META_PENDING_TD, true));
    }

    public function test_winner_that_cannot_be_applied_leaves_queue_and_version(): void
    {
        $id = $this->edited();
        update_post_meta($id, CmsSync::META_VERSIONS, ['caption' => 2]);
        $this->fields_reply($id, [['field' => 'caption', 'status' => 'conflict', 'version' => 6, 'winning_value' => 'Wygrany']], 'conflict');

        add_filter('wp_insert_post_empty_content', '__return_true');
        CmsSync::run_send();
        remove_filter('wp_insert_post_empty_content', '__return_true');

        $this->assertSame(['caption'], $this->dirty($id));
        $this->assertSame(['caption' => 2], $this->versions($id));
        $this->assertNotSame('', CmsSync::last_runs()['send']['error']);

        CmsSync::run_send();
        $this->assertSame('Wygrany', get_post($id)->post_excerpt);
        $this->assertSame([], $this->dirty($id));
        $this->assertSame(['caption' => 6], $this->versions($id));
    }

    // --- wiersz nie do zastosowania -----------------------------------------------------------

    public function test_row_failing_locally_is_skipped_after_five_attempts(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id = $this->attachment();
        $this->feed([$this->row($id, 'caption', 'X', 3)]);

        add_filter('wp_insert_post_empty_content', '__return_true');
        for ($i = 0; $i < 4; $i++) {
            CmsSync::run_poll();
            $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
            $this->assertNotSame('', CmsSync::last_runs()['poll']['error']);
        }
        CmsSync::run_poll();
        remove_filter('wp_insert_post_empty_content', '__return_true');

        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
        $this->assertSame([], $this->versions($id));
        $this->assertSame([$id], CmsSync::skipped_ids());

        ob_start();
        CmsSettingsScreen::render_block();
        $this->assertStringContainsString((string) $id, (string) ob_get_clean());

        // Nastepna zmiana tego pliku przechodzi i zdejmuje go z listy.
        $this->feed([$this->row($id, 'caption', 'Y', 4)], 'c3');
        CmsSync::run_poll();
        $this->assertSame('Y', get_post($id)->post_excerpt);
        $this->assertSame([], CmsSync::skipped_ids());
    }

    public function test_throwable_in_a_row_is_recorded_and_counted(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $id   = $this->attachment();
        $boom = static function () {
            throw new \RuntimeException('tajna wartosc');
        };
        add_action('attachment_updated', $boom);
        $this->feed([$this->row($id, 'caption', 'X', 3)]);

        for ($i = 0; $i < 5; $i++) {
            CmsSync::run_poll();
        }
        remove_action('attachment_updated', $boom);

        $this->assertStringNotContainsString('tajna', CmsSync::last_runs()['poll']['error']);
        $this->assertSame('c2', get_option(CmsSync::OPTION_CURSOR));
        $this->assertTrue(CmsSync::acquire_lock());
        CmsSync::release_lock();
    }

    public function test_td_failure_never_skips_a_row(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $this->routes['GET /v3/cms/settings'] = [503, ['code' => 'unavailable', 'message' => 'x']];
        $full = $this->attachment(['post_excerpt' => 'Moje']);
        $this->feed([$this->row($full, 'caption', 'Z TD')]);

        for ($i = 0; $i < 7; $i++) {
            CmsSync::run_poll();
        }

        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
        $this->assertSame([], CmsSync::skipped_ids());
    }

    // --- nowe pliki: wyzwalacze i powroty ------------------------------------------------------

    public function test_new_attachment_schedules_send(): void
    {
        $this->attachment();
        $this->assertNotFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
    }

    public function test_parent_publish_schedules_send(): void
    {
        $parent = self::factory()->post->create(['post_status' => 'draft']);
        $this->attachment(['post_parent' => $parent]);
        wp_clear_scheduled_hook(CmsSync::HOOK_SEND);

        wp_update_post(['ID' => $parent, 'post_title' => 'Nadal szkic']);
        $this->assertFalse(wp_next_scheduled(CmsSync::HOOK_SEND));

        wp_update_post(['ID' => $parent, 'post_status' => 'publish']);
        $this->assertNotFalse(wp_next_scheduled(CmsSync::HOOK_SEND));

        // Wpis bez zalacznikow niczego nie planuje.
        wp_clear_scheduled_hook(CmsSync::HOOK_SEND);
        self::factory()->post->create(['post_status' => 'publish']);
        $this->assertFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
    }

    public function test_settings_row_returns_files_rejected_as_disabled(): void
    {
        update_option(MediaPush::OPTION_FLOOR, 0);
        update_option(CmsSync::OPTION_CURSOR, 'c1');
        $disabled = $this->file();
        $narrow   = $this->file();
        update_post_meta($disabled, MediaPush::META_PUSHED, 'rejected:new_files_disabled@1');
        update_post_meta($narrow, MediaPush::META_PUSHED, 'rejected:below_min_width@1');
        $this->feed([['cursor' => 'c', 'kind' => 'settings', 'changed_at' => 'x']]);

        CmsSync::run_poll();

        $candidates = MediaPush::candidates(100);
        $this->assertContains($disabled, $candidates);
        $this->assertNotContains($narrow, $candidates);
    }

    public function test_missing_metadata_is_retried_like_host(): void
    {
        update_option(MediaPush::OPTION_FLOOR, 0);
        $old    = $this->file();
        $recent = $this->file();
        $since  = $this->file();
        update_post_meta($old, MediaPush::META_PUSHED, 'rejected:missing_metadata@1');
        update_post_meta($recent, MediaPush::META_PUSHED, 'rejected:missing_metadata@' . time());
        update_post_meta($since, MediaPush::META_PUSHED, 'rejected:before_since@1');

        $candidates = MediaPush::candidates(100);
        $this->assertContains($old, $candidates);
        $this->assertNotContains($recent, $candidates);
        $this->assertNotContains($since, $candidates);
    }

    public function test_bad_file_url_is_marked_and_not_sent(): void
    {
        update_option(MediaPush::OPTION_FLOOR, 0);
        foreach (['https://x.test/' . str_repeat('a', 2100), "https://x.test/a\0b"] as $url) {
            $id     = $this->file();
            $filter = static fn () => $url;
            add_filter('wp_get_attachment_url', $filter);
            CmsSync::run_send();
            remove_filter('wp_get_attachment_url', $filter);

            $this->assertStringStartsWith('rejected:no_url@', get_post_meta($id, MediaPush::META_PUSHED, true));
        }
        $this->assertSame([], $this->calls('/v3/cms/media', 'POST'));
    }

    // --- czas oczekiwania i ponowienie ---------------------------------------------------------

    public function test_cron_writes_use_longer_timeout_and_screen_keeps_short(): void
    {
        $this->edited();
        CmsSync::run_send();
        $this->assertSame(CmsSync::WRITE_TIMEOUT, $this->calls('/v3/cms/media/fields')[0]['args']['timeout']);
        $this->assertSame(15, CmsSync::WRITE_TIMEOUT);

        $this->requests = [];
        CmsApiClient::post('/held/x/decision', ['decision' => 'send']);
        $this->assertSame(CmsApiClient::TIMEOUT, $this->requests[0]['args']['timeout']);
        $this->assertSame(5, CmsApiClient::TIMEOUT);
    }

    public function test_transport_or_5xx_failure_retries_in_five_minutes(): void
    {
        $this->edited();
        foreach ([new \WP_Error('http_request_failed', 'x'), [500, ['code' => 'internal_error', 'message' => 'x']]] as $reply) {
            wp_clear_scheduled_hook(CmsSync::HOOK_SEND);
            $this->routes['POST /v3/cms/media/fields'] = $reply;
            CmsSync::run_send();
            $at = wp_next_scheduled(CmsSync::HOOK_SEND);
            $this->assertGreaterThanOrEqual(time() + 290, $at);
            $this->assertLessThanOrEqual(time() + 300, $at);
        }
    }

    public function test_no_retry_when_paused_or_on_409(): void
    {
        $this->edited();
        wp_clear_scheduled_hook(CmsSync::HOOK_SEND);
        $this->routes['POST /v3/cms/media/fields'] = [503, ['code' => 'unavailable', 'message' => 'x', 'retry_after' => 30]];
        CmsSync::run_send();
        $this->assertFalse(wp_next_scheduled(CmsSync::HOOK_SEND));

        delete_transient(CmsSync::TRANSIENT_PAUSE);
        $this->routes['POST /v3/cms/media/fields'] = [409, ['code' => 'state_conflict', 'message' => 'x']];
        CmsSync::run_send();
        $this->assertFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
    }

    public function test_cron_warning(): void
    {
        wp_clear_scheduled_hook(CmsSync::HOOK_POLL);
        wp_schedule_event(time() + 60, CmsSync::SCHEDULE, CmsSync::HOOK_POLL);
        $this->assertSame('', CmsSync::cron_warning(false));
        // DISABLE_WP_CRON z cronem serwera: zadania ida na czas, wiec nie ma o czym ostrzegac.
        $this->assertSame('', CmsSync::cron_warning(true));

        wp_clear_scheduled_hook(CmsSync::HOOK_POLL);
        wp_schedule_event(time() - 31 * MINUTE_IN_SECONDS, CmsSync::SCHEDULE, CmsSync::HOOK_POLL);
        $this->assertStringNotContainsString('DISABLE_WP_CRON', CmsSync::cron_warning(false));
        $this->assertNotSame('', CmsSync::cron_warning(false));
        $this->assertStringContainsString('DISABLE_WP_CRON', CmsSync::cron_warning(true));
    }

    public function test_screen_shows_last_run_when_status_fails(): void
    {
        update_option(CmsSync::OPTION_LAST_RUN, ['poll' => ['at' => 1793000000, 'error' => 'awaria-wymiany']], false);
        $this->routes['GET /v3/cms/status'] = [503, ['code' => 'unavailable', 'message' => 'x']];

        ob_start();
        CmsSettingsScreen::render_block();
        $this->assertStringContainsString('awaria-wymiany', (string) ob_get_clean());
    }

    // --- nowe pliki --------------------------------------------------------------------------

    private function file(array $over = []): int
    {
        $parent = self::factory()->post->create(['post_status' => 'publish', 'post_title' => 'Wpis']);
        $id     = (int) self::factory()->attachment->create(array_merge([
            'post_mime_type' => 'image/jpeg', 'post_parent' => $parent, 'post_title' => 'Plik', 'post_excerpt' => 'Podpis', 'post_content' => '',
        ], $over));
        update_post_meta($id, '_wp_attached_file', "2026/10/img-$id.jpg");
        wp_update_attachment_metadata($id, ['width' => 1200, 'height' => 800, 'image_meta' => ['credit' => 'AKPA', 'caption' => 'Podpis IPTC', 'title' => 'Tytul IPTC']]);

        return $id;
    }

    public function test_new_file_goes_to_v3_media_when_active(): void
    {
        update_option(MediaPush::OPTION_FLOOR, 0);
        $ok = $this->file();
        $known = $this->file();
        $rejected = $this->file();
        $this->routes['POST /v3/cms/media'] = [200, ['results' => [
            ['id' => (string) $ok, 'status' => 'ok', 'fields' => []],
            ['id' => (string) $known, 'status' => 'known', 'fields' => []],
            ['id' => (string) $rejected, 'status' => 'rejected', 'reason' => 'host', 'fields' => []],
        ]]];

        MediaPush::run();

        $this->assertSame([], $this->calls('/v2/cms/media'));
        $calls = $this->calls('/v3/cms/media', 'POST');
        $this->assertCount(1, $calls);
        $items = json_decode($calls[0]['args']['body'], true)['items'];
        $item  = array_column($items, null, 'cms_media_id')[(string) $ok];
        $this->assertStringContainsString("img-$ok.jpg", $item['file_url']);
        $this->assertSame('image/jpeg', $item['mime_type']);
        $this->assertSame(1200, $item['width']);
        $this->assertSame(800, $item['height']);
        $this->assertSame('AKPA', $item['credit']);
        $this->assertSame('Wpis', $item['post_title']);
        $this->assertSame('Podpis IPTC', $item['iptc_caption']);
        $this->assertSame('Tytul IPTC', $item['iptc_title']);
        $this->assertMatchesRegularExpression('/[+-]\d\d:\d\d$/', $item['created_at']);
        $fields = array_column($item['fields'], null, 'field');
        $this->assertSame('Podpis', $fields['caption']['value']);
        $this->assertArrayNotHasKey('description', $fields);
        $this->assertMatchesRegularExpression('/[+-]\d\d:\d\d$/', $fields['caption']['changed_at']);

        $this->assertStringStartsWith('accepted@', get_post_meta($ok, MediaPush::META_PUSHED, true));
        $this->assertStringStartsWith('known@', get_post_meta($known, MediaPush::META_PUSHED, true));
        $this->assertStringStartsWith('rejected:host@', get_post_meta($rejected, MediaPush::META_PUSHED, true));
    }

    public function test_media_409_keeps_queue(): void
    {
        update_option(MediaPush::OPTION_FLOOR, 0);
        $id = $this->file();
        $this->routes['POST /v3/cms/media'] = [409, ['code' => 'state_conflict', 'message' => 'x']];

        CmsSync::run_send();

        $this->assertSame('', get_post_meta($id, MediaPush::META_PUSHED, true));
        $this->assertContains($id, MediaPush::candidates(100));
        $this->assertNotSame('', CmsSync::last_runs()['send']['error']);
    }

    // --- harmonogram i ekran -------------------------------------------------------------------

    public function test_five_minute_schedule_registered_and_cleared(): void
    {
        $this->assertSame(300, wp_get_schedules()[CmsSync::SCHEDULE]['interval']);
        wp_clear_scheduled_hook(CmsSync::HOOK_POLL);

        do_action('init');
        $this->assertNotFalse(wp_next_scheduled(CmsSync::HOOK_POLL));
        $this->assertSame(CmsSync::SCHEDULE, wp_get_schedule(CmsSync::HOOK_POLL));
        $this->assertNotFalse(has_action(CmsSync::HOOK_POLL, [CmsSync::class, 'run_poll']));
        $this->assertNotFalse(has_action(CmsSync::HOOK_SEND, [CmsSync::class, 'run_send']));

        wp_schedule_single_event(time() + 60, CmsSync::HOOK_SEND);
        do_action('deactivate_' . plugin_basename(HEADLINESFORGE_PLUGIN_FILE));
        $this->assertFalse(wp_next_scheduled(CmsSync::HOOK_POLL));
        $this->assertFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
    }

    public function test_screen_shows_last_run_and_escaped_error(): void
    {
        update_option(CmsSync::OPTION_LAST_RUN, ['poll' => ['at' => 1793000000, 'error' => '<b>awaria</b>']], false);
        $this->routes['GET /v3/cms/held'] = [200, ['items' => []]];

        ob_start();
        CmsSettingsScreen::render_block();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString((string) wp_date('Y-m-d H:i', 1793000000), $html);
        $this->assertStringContainsString('&lt;b&gt;awaria', $html);
        $this->assertStringNotContainsString('<b>awaria', $html);
    }
}
