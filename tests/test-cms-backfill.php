<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsBackfill;
use TribuneDesk\WordPress\CmsSync;
use TribuneDesk\WordPress\MediaHandler;
use TribuneDesk\WordPress\MediaPush;
use TribuneDesk\WordPress\PublishedUrlsClient;
use TribuneDesk\WordPress\Settings;

class Test_Cms_Backfill extends WP_UnitTestCase
{
    private const JOB   = '6f1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
    private const PAGES = '/v3/cms/backfill/requests/' . self::JOB . '/pages';
    private const LIST  = '/v3/cms/backfill/requests';

    /** @var list<array{path: string, body: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<string, array{0: int, 1: array|string}|\WP_Error> */
    private array $routes = [];

    public function set_up(): void
    {
        parent::set_up();
        // Ekran z test_date_form_gone: funkcje z wp-admin i tytul strony, jak w test-settings-api.
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $GLOBALS['title'] = 'Tribune Desk Receiver';
        Activator::activate();
        update_option(CmsApiClient::OPTION_TOKEN, 'tok-testowy');
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        update_option(MediaPush::OPTION_FLOOR, PHP_INT_MAX);
        delete_transient(CmsSync::TRANSIENT_PAUSE);
        delete_transient(CmsSync::TRANSIENT_ACTIVE);
        delete_option(CmsBackfill::OPTION_LOCK);
        $this->requests = [];
        $this->routes   = [
            'GET /v3/cms/status' => [200, ['state' => 'active', 'recent_errors' => []]],
            'POST ' . self::PAGES => [200, ['accepted' => 0, 'results' => []]],
        ];
        $this->job();
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        remove_all_filters('headlinesforge_cms_backfill_budget');
        parent::tear_down();
    }

    /** @return array<string, mixed>|\WP_Error */
    public function fake_http($pre, array $args, string $url)
    {
        $path             = (string) wp_parse_url($url, PHP_URL_PATH);
        $this->requests[] = ['path' => $path, 'body' => json_decode((string) ($args['body'] ?? '[]'), true) ?: []];
        $reply            = $this->routes[$args['method'] . ' ' . $path] ?? [404, ['code' => 'not_found', 'message' => 'x']];
        if ($reply instanceof \WP_Error) {
            return $reply;
        }
        [$code, $body] = $reply;

        return ['response' => ['code' => $code, 'message' => ''], 'body' => is_string($body) ? $body : wp_json_encode($body), 'headers' => [], 'cookies' => []];
    }

    /** @param array<string, mixed> $filters */
    private function job(array $filters = []): void
    {
        $this->routes['GET ' . self::LIST] = [200, ['items' => [
            ['id' => self::JOB, 'status' => 'collecting', 'filters' => $filters, 'created_at' => '2026-11-01T10:00:00Z'],
        ]]];
    }

    private function photo(string $date, int $width = 1200, array $over = []): int
    {
        $id = (int) self::factory()->attachment->create(array_merge(
            ['post_mime_type' => 'image/jpeg', 'post_title' => 'T', 'post_date' => $date, 'post_date_gmt' => $date],
            $over
        ));
        update_post_meta($id, '_wp_attached_file', "2026/10/img-$id.jpg");
        wp_update_attachment_metadata($id, ['width' => $width, 'height' => 800, 'image_meta' => ['credit' => 'PAP']]);

        return $id;
    }

    /** @return list<array<string, mixed>> strony wyslane do zadania */
    private function pages(): array
    {
        return array_values(array_map(
            static fn ($r) => $r['body'],
            array_filter($this->requests, static fn ($r) => $r['path'] === self::PAGES)
        ));
    }

    /** @return list<int> */
    private function sent_ids(): array
    {
        $ids = [];
        foreach ($this->pages() as $page) {
            foreach ($page['items'] as $item) {
                $ids[] = (int) $item['cms_media_id'];
            }
        }

        return $ids;
    }

    private function position(): mixed
    {
        return get_option(CmsBackfill::OPTION_POSITION_PREFIX . self::JOB, false);
    }

    private function no_budget(): void
    {
        add_filter('headlinesforge_cms_backfill_budget', static fn () => 0);
    }

    public function test_no_token_no_requests(): void
    {
        delete_option(CmsApiClient::OPTION_TOKEN);
        $this->photo('2026-10-01 10:00:00');

        CmsBackfill::run();

        $this->assertSame([], $this->requests);
    }

    public function test_disabled_integration_sends_no_pages(): void
    {
        $this->routes['GET /v3/cms/status'] = [200, ['state' => 'disabled', 'recent_errors' => []]];
        $this->photo('2026-10-01 10:00:00');

        CmsBackfill::run();

        $this->assertSame([], $this->pages());
    }

    public function test_held_lock_blocks_the_run(): void
    {
        $this->assertTrue(CmsSync::acquire_lock(CmsBackfill::OPTION_LOCK));

        CmsBackfill::run();

        $this->assertSame([], $this->pages());
        // Blokada importu nie zajmuje blokady zwyklej wymiany.
        $this->assertTrue(CmsSync::acquire_lock());
    }

    public function test_pages_newest_first_with_filters(): void
    {
        $this->job([
            'date_from' => '2026-05-01T00:00:00Z', 'date_to' => '2026-10-01T00:00:00Z', 'min_width' => 1000,
            'mime_types' => ['image/jpeg'], 'only_attached_to_published' => true, 'categories' => ['Sport'],
        ]);
        $category = (int) self::factory()->category->create(['name' => 'Sport', 'slug' => 'sport-pl']);
        $other    = (int) self::factory()->category->create(['name' => 'Inne']);
        $live     = (int) self::factory()->post->create(['post_status' => 'publish', 'post_category' => [$category]]);
        $off      = (int) self::factory()->post->create(['post_status' => 'publish', 'post_category' => [$other]]);
        $draft    = (int) self::factory()->post->create(['post_status' => 'draft', 'post_category' => [$category]]);
        $in       = ['post_parent' => $live];

        $older = $this->photo('2026-06-01 10:00:00', 1200, $in);
        $newer = $this->photo('2026-09-01 10:00:00', 1200, $in);
        $this->photo('2026-04-01 10:00:00', 1200, $in);                                   // przed data
        $this->photo('2026-10-02 10:00:00', 1200, $in);                                   // po dacie
        $this->photo('2026-07-01 10:00:00', 800, $in);                                    // za waska
        $this->photo('2026-07-01 10:00:00', 1200, $in + ['post_mime_type' => 'image/png']); // typ
        $this->photo('2026-07-01 10:00:00', 1200, ['post_parent' => $off]);               // kategoria
        $this->photo('2026-07-01 10:00:00', 1200, ['post_parent' => $draft]);             // szkic
        $this->photo('2026-07-01 10:00:00', 1200);                                        // bez wpisu
        $td = $this->photo('2026-07-01 10:00:00', 1200, $in);
        update_post_meta($td, MediaHandler::META_FROM_TD, 1);
        $asset = $this->photo('2026-07-01 10:00:00', 1200, $in);
        update_post_meta($asset, MediaHandler::META_ASSET_ID, 'a');

        CmsBackfill::run();

        $this->assertSame([$newer, $older], $this->sent_ids());
        $page = $this->pages()[0];
        $this->assertTrue($page['is_last']);
        $item = $page['items'][0];
        // Dokladnie pola BackfillCandidate (additionalProperties: false) i bez sha.
        $this->assertEqualsCanonicalizing(['cms_media_id', 'file_url', 'mime_type', 'width', 'height', 'created_at', 'credit', 'caption', 'post_title', 'parent_title'], array_keys($item));
        $this->assertSame('PAP', $item['credit']);
        $this->assertSame('T', $item['post_title']);
        $this->assertSame(1200, $item['width']);
    }

    public function test_same_date_orders_by_id_and_slug_matches_category(): void
    {
        $this->job(['categories' => ['sport-pl']]);
        $category = (int) self::factory()->category->create(['name' => 'Sport', 'slug' => 'sport-pl']);
        $post     = (int) self::factory()->post->create(['post_status' => 'draft', 'post_category' => [$category]]);
        $first    = $this->photo('2026-06-01 10:00:00', 1200, ['post_parent' => $post]);
        $second   = $this->photo('2026-06-01 10:00:00', 1200, ['post_parent' => $post]);

        CmsBackfill::run();

        $this->assertSame([$second, $first], $this->sent_ids());
    }

    public function test_position_survives_error(): void
    {
        $this->no_budget();
        $old = $this->photo('2026-06-01 10:00:00');
        $new = $this->photo('2026-09-01 10:00:00');

        CmsBackfill::run();
        $this->assertSame([$new], $this->sent_ids());
        $after_first = $this->position();
        $this->assertSame($new, $after_first['id']);

        foreach ([[500, ['code' => 'internal_error', 'message' => 'x']], new \WP_Error('http_request_failed', 'x')] as $failure) {
            $this->routes['POST ' . self::PAGES] = $failure;
            CmsBackfill::run();
            $this->assertSame($after_first, $this->position());
            $this->assertNotSame('', (string) get_option(CmsBackfill::OPTION_ERROR, ''));
        }

        // Po awarii wraca od strony nieprzyjetej, bez powtorki przyjetych.
        $this->routes['POST ' . self::PAGES] = [200, ['accepted' => 1, 'results' => []]];
        $this->requests = [];
        CmsBackfill::run();
        $this->assertSame([$old], $this->sent_ids());
        $this->assertFalse(get_option(CmsBackfill::OPTION_ERROR, false));
    }

    public function test_failed_list_keeps_position(): void
    {
        update_option(CmsBackfill::OPTION_POSITION_PREFIX . self::JOB, ['date' => '2026-06-01 10:00:00', 'id' => 5], false);
        $this->routes['GET ' . self::LIST] = [503, ['code' => 'unavailable', 'message' => 'x']];

        CmsBackfill::run();

        $this->assertSame(5, $this->position()['id']);
        $this->assertSame([], $this->pages());
    }

    public function test_rate_limit_pauses_and_keeps_position(): void
    {
        $this->no_budget();
        $this->photo('2026-06-01 10:00:00');
        $new = $this->photo('2026-09-01 10:00:00');
        CmsBackfill::run();

        $this->routes['POST ' . self::PAGES] = [429, ['code' => 'rate_limited', 'message' => 'x', 'retry_after' => 30]];
        CmsBackfill::run();

        $this->assertSame($new, $this->position()['id']);
        $this->assertNotFalse(get_transient(CmsSync::TRANSIENT_PAUSE));
    }

    public function test_is_last_clears_position(): void
    {
        $this->no_budget();
        $this->photo('2026-06-01 10:00:00');
        $this->photo('2026-09-01 10:00:00');

        CmsBackfill::run();
        $this->assertNotFalse($this->position());
        $this->assertFalse($this->pages()[0]['is_last']);

        CmsBackfill::run();

        $last =$this->pages()[array_key_last($this->pages())];
        $this->assertTrue($last['is_last']);
        $this->assertFalse($this->position());
    }

    public function test_empty_library_closes_request(): void
    {
        $this->job(['date_to' => '2000-01-01T00:00:00Z']);
        $this->photo('2026-06-01 10:00:00');

        CmsBackfill::run();

        $this->assertSame([['items' => [], 'is_last' => true]], $this->pages());
    }

    public function test_cancelled_job_stops(): void
    {
        $this->no_budget();
        $this->photo('2026-06-01 10:00:00');
        $this->photo('2026-08-01 10:00:00');
        $this->photo('2026-09-01 10:00:00');
        CmsBackfill::run();
        $this->assertNotFalse($this->position());

        // Zadanie zniknelo z listy, ale serwer przyjmuje probe (wstrzymane): pozycja zostaje.
        $this->routes['GET ' . self::LIST] = [200, ['items' => []]];
        $this->requests = [];
        CmsBackfill::run();
        $this->assertNotFalse($this->position());
        $this->assertSame([['items' => [], 'is_last' => false]], $this->pages());

        // Zamkniete albo nieznane: 409 i 404 na probe kasuja pozycje.
        foreach ([[409, ['code' => 'state_conflict', 'message' => 'x']], [404, ['code' => 'not_found', 'message' => 'x']]] as $closed) {
            update_option(CmsBackfill::OPTION_POSITION_PREFIX . self::JOB, ['date' => '2026-12-01 10:00:00', 'id' => 1, 'at' => time()], false);
            $this->routes['POST ' . self::PAGES] = $closed;
            CmsBackfill::run();
            $this->assertFalse($this->position());
        }
    }

    public function test_closed_between_list_and_page_drops_position(): void
    {
        foreach ([[409, ['code' => 'state_conflict', 'message' => 'x']], [404, ['code' => 'not_found', 'message' => 'x']]] as $closed) {
            $this->photo('2026-06-01 10:00:00');
            update_option(CmsBackfill::OPTION_POSITION_PREFIX . self::JOB, ['date' => '2026-12-01 10:00:00', 'id' => 1], false);
            $this->routes['POST ' . self::PAGES] = $closed;
            $this->requests = [];
            CmsBackfill::run();
            $this->assertCount(1, $this->pages());
            $this->assertFalse($this->position());
        }
    }

    public function test_stale_position_is_dropped_after_30_days(): void
    {
        $this->routes['GET ' . self::LIST] = [200, ['items' => []]];
        update_option(CmsBackfill::OPTION_POSITION_PREFIX . self::JOB, ['date' => '2026-01-01 10:00:00', 'id' => 1, 'at' => time() - 31 * DAY_IN_SECONDS], false);

        CmsBackfill::run();

        $this->assertFalse($this->position());
        $this->assertSame([], $this->pages());
    }

    public function test_export_error_is_shown_read_only_and_cleared(): void
    {
        $this->photo('2026-06-01 10:00:00');
        $this->routes['POST ' . self::PAGES] = [403, ['code' => 'forbidden', 'message' => 'x']];
        CmsBackfill::run();

        $html = $this->screen();
        $this->assertStringContainsString('backfill:write', $html);
        // Tylko do odczytu: brak akcji i pol zwiazanych z importem wstecznym.
        $this->assertStringNotContainsString('backfill_', str_replace(CmsBackfill::OPTION_ERROR, '', $html));

        $this->routes['POST ' . self::PAGES] = [200, ['accepted' => 1, 'results' => []]];
        CmsBackfill::run();
        $this->assertFalse(get_option(CmsBackfill::OPTION_ERROR, false));
        $this->assertStringNotContainsString('backfill:write', $this->screen());
    }

    private function screen(): string
    {
        ob_start();
        \TribuneDesk\WordPress\CmsSettingsScreen::render_block();

        return (string) ob_get_clean();
    }

    public function test_five_rejections_hold_the_job_for_an_hour_keeping_position(): void
    {
        $this->no_budget();
        $this->photo('2026-06-01 10:00:00');
        $new = $this->photo('2026-09-01 10:00:00');
        CmsBackfill::run();
        $this->routes['POST ' . self::PAGES] = [422, ['code' => 'validation_error', 'message' => 'x']];

        for ($i = 1; $i <= 4; $i++) {
            CmsBackfill::run();
            $this->assertFalse(get_transient(CmsBackfill::HOLD_PREFIX . self::JOB));
        }
        CmsBackfill::run();
        $this->assertNotFalse(get_transient(CmsBackfill::HOLD_PREFIX . self::JOB));
        $this->assertNotSame('', (string) get_option(CmsBackfill::OPTION_ERROR, ''));

        // Wstrzymane: kolejny przebieg nie pyta serwera o strone, a pozycja zostaje.
        $this->requests = [];
        CmsBackfill::run();
        $this->assertSame([], $this->pages());
        $this->assertNotSame('', (string) get_option(CmsBackfill::OPTION_ERROR, ''));
        $this->assertSame($new, $this->position()['id']);
    }

    public function test_accepted_page_resets_rejection_count(): void
    {
        $this->no_budget();
        $this->photo('2026-06-01 10:00:00');
        $this->photo('2026-09-01 10:00:00');
        $this->photo('2026-10-01 10:00:00');
        $good = $this->routes['POST ' . self::PAGES];
        for ($i = 0; $i < 4; $i++) {
            $this->routes['POST ' . self::PAGES] = [422, ['code' => 'validation_error', 'message' => 'x']];
            CmsBackfill::run();
        }
        $this->routes['POST ' . self::PAGES] = $good;
        CmsBackfill::run();
        $this->assertFalse(get_transient(CmsBackfill::REJECT_PREFIX . self::JOB));
    }

    public function test_candidate_carries_texts_clipped(): void
    {
        $parent = (int) self::factory()->post->create(['post_status' => 'publish', 'post_title' => 'Wpis nadrzedny']);
        $id     = $this->photo('2026-06-01 10:00:00', 1200, [
            'post_parent' => $parent, 'post_title' => 'Tytul', 'post_excerpt' => str_repeat('a', 6000),
        ]);
        wp_update_attachment_metadata($id, ['width' => 1200, 'height' => 800, 'image_meta' => ['credit' => 'PAP', 'caption' => 'Opis IPTC']]);

        CmsBackfill::run();

        $item = $this->pages()[0]['items'][0];
        $this->assertSame('PAP', $item['credit']);
        $this->assertSame('Tytul', $item['post_title']);
        $this->assertSame('Wpis nadrzedny', $item['parent_title']);
        $this->assertSame('Opis IPTC', $item['iptc_caption']);
        $this->assertSame(5000, mb_strlen($item['caption']));
    }

    public function test_rejected_page_stores_fixed_message(): void
    {
        $this->no_budget();
        $this->photo('2026-06-01 10:00:00');
        $new = $this->photo('2026-09-01 10:00:00');
        CmsBackfill::run();

        $this->routes['POST ' . self::PAGES] = [422, ['code' => 'validation_error', 'message' => 'tresc-odpowiedzi-serwera']];
        CmsBackfill::run();

        $error = (string) get_option(CmsBackfill::OPTION_ERROR, '');
        $this->assertNotSame('', $error);
        $this->assertStringNotContainsString('tresc-odpowiedzi-serwera', $error);
        $this->assertSame($new, $this->position()['id']);
    }

    public function test_date_form_gone(): void
    {
        ob_start();
        Settings::render_page();
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('hf_media_since', $html);
        $this->assertStringNotContainsString('headlinesforge_media_push_since', $html);
        $this->assertStringNotContainsString('wgrane od dnia', $html);
        $this->assertFalse(has_action('admin_post_headlinesforge_media_push_since'));
        $this->assertFalse(method_exists(MediaPush::class, 'set_floor_since'));
        // Prog zwyklej wysylki zostaje.
        $this->assertNotFalse(has_action('init', [MediaPush::class, 'ensure_scheduled']));
    }

    public function test_cron_is_scheduled_every_five_minutes(): void
    {
        wp_clear_scheduled_hook(CmsBackfill::HOOK);

        CmsBackfill::ensure_scheduled();

        $this->assertSame(CmsSync::SCHEDULE, wp_get_schedule(CmsBackfill::HOOK));
        $this->assertNotFalse(has_action(CmsBackfill::HOOK, [CmsBackfill::class, 'run']));
    }

    public function test_large_page_within_time_budget(): void
    {
        global $wpdb;
        $values = [];
        for ($i = 0; $i < 1600; $i++) {
            $date     = gmdate('Y-m-d H:i:s', 1760000000 + $i);
            $values[] = $wpdb->prepare(
                "(%s, %s, '', '', '', '', '', '', 'inherit', 'attachment', 'image/jpeg', %s, %s)",
                $date,
                $date,
                'bulk-' . $i,
                'https://pliki.example.test/bulk-' . $i . '.jpg'
            );
        }
        $wpdb->query(
            "INSERT INTO {$wpdb->posts} (post_date, post_date_gmt, post_content, post_title, post_excerpt, to_ping, pinged, post_content_filtered, post_status, post_type, post_mime_type, post_name, guid) VALUES "
            . implode(',', $values)
        );

        $before = $wpdb->num_queries;
        $start  = microtime(true);
        CmsBackfill::run();
        $queries = $wpdb->num_queries - $before;

        // Trzy strony po 500 i ani jednej wiecej, od najnowszego.
        $pages = $this->pages();
        $this->assertCount(CmsBackfill::MAX_PAGES, $pages);
        foreach ($pages as $page) {
            $this->assertCount(CmsBackfill::PAGE_SIZE, $page['items']);
            $this->assertFalse($page['is_last']);
        }
        $this->assertStringContainsString('bulk-1599', $pages[0]['items'][0]['file_url']);
        $this->assertSame(1500, count(array_unique($this->sent_ids())));
        $this->assertLessThan(CmsBackfill::BUDGET, microtime(true) - $start);
        // Odczyt porcjami: zapytania nie rosna z liczba wierszy.
        $this->assertLessThan(200, $queries);

        // Reszta w nastepnym przebiegu, bez powtorek, i zamkniecie.
        CmsBackfill::run();
        $this->assertSame(1600, count(array_unique($this->sent_ids())));
        $this->assertCount(1600, $this->sent_ids());
        $this->assertTrue($this->pages()[array_key_last($this->pages())]['is_last']);
    }

    public function test_budget_cuts_the_run(): void
    {
        $this->no_budget();
        $this->photo('2026-06-01 10:00:00');
        $this->photo('2026-07-01 10:00:00');
        $newest = $this->photo('2026-08-01 10:00:00');

        CmsBackfill::run();

        $this->assertSame([$newest], $this->sent_ids());
    }

    public function test_filtered_rows_advance_without_page(): void
    {
        $this->no_budget();
        $this->job(['min_width' => 5000]);
        $narrow = $this->photo('2026-08-01 10:00:00', 800);
        $this->photo('2026-07-01 10:00:00', 800);

        CmsBackfill::run();

        $this->assertSame([], $this->pages());
        $this->assertSame($narrow, $this->position()['id']);
    }
}
