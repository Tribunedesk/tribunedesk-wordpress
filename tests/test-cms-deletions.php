<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsDeletions;
use TribuneDesk\WordPress\CmsSync;
use TribuneDesk\WordPress\MediaMetaSync;
use TribuneDesk\WordPress\MediaPush;
use TribuneDesk\WordPress\PublishedUrlsClient;

class Test_Cms_Deletions extends WP_UnitTestCase
{
    private const SEND_PATH = '/v3/cms/media/deletions';

    /** @var list<array{method: string, path: string, body: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<string, array{0: int, 1: array|string}|\WP_Error> */
    private array $routes = [];

    /** @var list<array<string, mixed>> */
    private array $feed = [];

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        update_option(CmsApiClient::OPTION_TOKEN, 'tok-testowy');
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        update_option(MediaPush::OPTION_FLOOR, PHP_INT_MAX);
        update_option(CmsSync::OPTION_CURSOR, 'c0');
        delete_option(CmsDeletions::OPTION_QUEUE);
        delete_option(CmsSync::OPTION_LOCK);
        delete_option(CmsSync::OPTION_LAST_RUN);
        delete_transient(CmsDeletions::TRANSIENT_OVERFLOW);
        delete_transient(CmsSync::TRANSIENT_PAUSE);
        delete_transient(CmsSync::TRANSIENT_ACTIVE);
        wp_clear_scheduled_hook(CmsSync::HOOK_SEND);
        $this->requests = [];
        $this->feed     = [];
        $this->routes   = ['GET /v3/cms/status' => [200, ['state' => 'active', 'recent_errors' => []]]];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        unset($_GET[CmsDeletions::QUERY_VAR], $_REQUEST['query'], $GLOBALS['current_screen']);
        $GLOBALS['wp_scripts'] = null;
        parent::tear_down();
    }

    /** @return array<string, mixed>|\WP_Error */
    public function fake_http($pre, array $args, string $url)
    {
        $path             = (string) wp_parse_url($url, PHP_URL_PATH);
        $body             = json_decode((string) ($args['body'] ?? '[]'), true) ?: [];
        $this->requests[] = ['method' => $args['method'], 'path' => $path, 'body' => $body];
        $reply            = $this->routes[$args['method'] . ' ' . $path] ?? null;
        if ($reply instanceof \WP_Error) {
            return $reply;
        }
        if ($reply === null) {
            $reply = match (true) {
                $path === self::SEND_PATH  => [200, ['results' => array_map(static fn ($i) => ['id' => $i['cms_media_id'], 'status' => 'ok'], $body['items'])]],
                str_ends_with($path, '/changes') => [200, ['changes' => $this->feed, 'next_cursor' => 'c1', 'has_more' => false]],
                default                    => [404, ['code' => 'not_found', 'message' => 'x']],
            };
        }
        [$code, $content] = $reply;

        return ['response' => ['code' => $code, 'message' => ''], 'body' => is_string($content) ? $content : wp_json_encode($content), 'headers' => [], 'cookies' => []];
    }

    private function photo(string $file = '', array $over = []): int
    {
        $id = (int) self::factory()->attachment->create(array_merge(['post_mime_type' => 'image/jpeg', 'post_title' => 'T'], $over));
        update_post_meta($id, '_wp_attached_file', $file !== '' ? $file : "2026/10/foto-$id.jpg");

        return $id;
    }

    /** @return list<array{method: string, path: string, body: array<string, mixed>}> */
    private function sends(): array
    {
        return array_values(array_filter($this->requests, static fn ($r) => $r['path'] === self::SEND_PATH));
    }

    /** @param int $rows numery od 1 w kolejnosci usuniecia */
    private function seed(int $rows): void
    {
        update_option(CmsDeletions::OPTION_QUEUE, array_fill_keys(range(1, $rows), 1_790_000_000), false);
    }

    private function poll(string $kind, int $id): void
    {
        $this->feed = [['cursor' => 'x', 'kind' => $kind, 'cms_media_id' => (string) $id, 'changed_at' => '2026-11-02T10:00:00Z']];
        CmsSync::run_poll();
    }

    private function marked(int $id): bool
    {
        return metadata_exists('post', $id, CmsDeletions::META_DELETED);
    }

    public function test_delete_hook_queues_without_http(): void
    {
        $photo = $this->photo();
        $doc   = (int) self::factory()->attachment->create(['post_mime_type' => 'application/pdf']);

        wp_delete_attachment($photo, true);
        wp_delete_attachment($doc, true);

        $queue = CmsDeletions::queue();
        $this->assertSame([$photo], array_keys($queue));
        $this->assertEqualsWithDelta(time(), $queue[$photo], 5);
        $this->assertSame([], $this->requests);
        $this->assertNotFalse(wp_next_scheduled(CmsSync::HOOK_SEND));

        global $wpdb;
        $this->assertContains(
            $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", CmsDeletions::OPTION_QUEUE)),
            ['no', 'off']
        );
    }

    public function test_delete_hook_without_token_queues_nothing(): void
    {
        delete_option(CmsApiClient::OPTION_TOKEN);
        wp_delete_attachment($this->photo(), true);

        $this->assertSame([], CmsDeletions::queue());
        $this->assertFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
    }

    public function test_send_batches_and_keeps_on_error(): void
    {
        $this->seed(250);

        // 5xx: kolejka zostaje, ponowienie za 5 minut.
        $this->routes['POST ' . self::SEND_PATH] = [500, ['code' => 'internal_error', 'message' => 'x']];
        CmsSync::run_send();
        $this->assertCount(250, CmsDeletions::queue());
        $this->assertNotFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
        $this->assertNotSame('', CmsSync::last_runs()['send']['error']);
        wp_clear_scheduled_hook(CmsSync::HOOK_SEND);

        // Transport: tak samo.
        $this->routes['POST ' . self::SEND_PATH] = new \WP_Error('http_request_failed', 'x');
        CmsSync::run_send();
        $this->assertCount(250, CmsDeletions::queue());
        $this->assertNotFalse(wp_next_scheduled(CmsSync::HOOK_SEND));

        // Odpowiedz bez wynikow nie zdejmuje niczego.
        $this->routes['POST ' . self::SEND_PATH] = [200, ['results' => []]];
        CmsSync::run_send();
        $this->assertCount(250, CmsDeletions::queue());

        // `unknown` i `reason` tez koncza wiersz; wiersz bez wyniku zostaje.
        $this->routes['POST ' . self::SEND_PATH] = [200, ['results' => [
            ['id' => '1', 'status' => 'ok'],
            ['id' => '2', 'status' => 'unknown'],
            ['id' => '3', 'status' => 'ok', 'reason' => 'threshold'],
        ]]];
        $this->requests = [];
        CmsSync::run_send();
        $left = CmsDeletions::queue();
        $this->assertArrayNotHasKey(1, $left);
        $this->assertArrayNotHasKey(2, $left);
        $this->assertArrayNotHasKey(3, $left);
        $this->assertArrayHasKey(4, $left);

        // Porcja: najstarsze, najwyzej 100, numer tekstem i data z czasem.
        $items = $this->sends()[0]['body']['items'];
        $this->assertCount(100, $items);
        $this->assertSame('1', $items[0]['cms_media_id']);
        $this->assertSame(gmdate('c', 1_790_000_000), $items[0]['deleted_at']);
    }

    public function test_large_queue_survives(): void
    {
        $this->seed(1000);

        CmsSync::run_send();
        $this->assertCount(3, $this->sends());
        $this->assertCount(700, CmsDeletions::queue());
        $this->assertNotFalse(wp_next_scheduled(CmsSync::HOOK_SEND));

        for ($run = 0; $run < 3; $run++) {
            wp_clear_scheduled_hook(CmsSync::HOOK_SEND);
            CmsSync::run_send();
        }
        $this->assertSame([], CmsDeletions::queue());
        $sent = [];
        foreach ($this->sends() as $request) {
            $sent = array_merge($sent, array_column($request['body']['items'], 'cms_media_id'));
        }
        $this->assertSame(array_map('strval', range(1, 1000)), array_values(array_unique($sent)));
    }

    public function test_queue_cap_keeps_oldest_and_warns(): void
    {
        $this->assertSame('', CmsDeletions::overflow_message());
        // Numery powyzej nowego zalacznika: inaczej wiersz juz bylby w kolejce.
        $photo  = $this->photo();
        $oldest = $photo + 1;
        update_option(CmsDeletions::OPTION_QUEUE, array_fill_keys(range($oldest, $photo + CmsDeletions::MAX_QUEUE), 1_790_000_000), false);
        wp_delete_attachment($photo, true);

        $queue = CmsDeletions::queue();
        $this->assertCount(CmsDeletions::MAX_QUEUE, $queue);
        $this->assertSame($oldest, array_key_first($queue));
        $this->assertArrayNotHasKey($photo, $queue);
        $this->assertStringContainsString('5000', CmsDeletions::overflow_message());
    }

    public function test_deleted_change_marks_unused(): void
    {
        $id = $this->photo();
        $this->poll('deleted', $id);

        $this->assertTrue($this->marked($id));
        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
        $this->assertSame('', CmsSync::last_runs()['poll']['error']);

        // Wiersz o zalaczniku, ktorego tu nie ma, nie zatrzymuje kanalu.
        update_option(CmsSync::OPTION_CURSOR, 'c0');
        $this->poll('deleted', 999999);
        $this->assertSame('c1', get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_used_in_published_not_marked(): void
    {
        $published = (int) self::factory()->post->create(['post_status' => 'publish']);
        $draft     = (int) self::factory()->post->create(['post_status' => 'draft']);

        $child = $this->photo('', ['post_parent' => $published]);
        $this->poll('deleted', $child);
        $this->assertFalse($this->marked($child), 'wpis nadrzedny');

        $thumb = $this->photo();
        update_post_meta($published, '_thumbnail_id', $thumb);
        $this->poll('deleted', $thumb);
        $this->assertFalse($this->marked($thumb), 'obraz wyrozniajacy');

        // Miniatura zdjecia: w tresci inny plik niz w zalaczniku, a `-scaled` jej nie dotyczy.
        $in_body = $this->photo('2026/10/w-tresci-scaled.jpg');
        self::factory()->post->create(['post_status' => 'publish', 'post_content' => '<img src="https://x.test/wp-content/uploads/2026/10/w-tresci-300x200.jpg">']);
        $this->poll('deleted', $in_body);
        $this->assertFalse($this->marked($in_body), 'tresc');

        // Szkic niczego nie chroni, a slowo w tresci to nie adres.
        $free = $this->photo('2026/10/wolny.jpg', ['post_parent' => $draft]);
        update_post_meta($draft, '_thumbnail_id', $free);
        self::factory()->post->create(['post_status' => 'draft', 'post_content' => '<img src="https://x.test/2026/10/wolny.jpg">']);
        self::factory()->post->create(['post_status' => 'publish', 'post_content' => 'zupelnie wolny tekst']);
        $this->poll('deleted', $free);
        $this->assertTrue($this->marked($free), 'szkic');
    }

    public function test_restored_clears(): void
    {
        $id = $this->photo();
        update_post_meta($id, CmsDeletions::META_DELETED, 1);

        $this->poll('restored', $id);

        $this->assertFalse($this->marked($id));
        $this->assertSame('', CmsSync::last_runs()['poll']['error']);
    }

    public function test_filter_list_and_grid(): void
    {
        $plain  = $this->photo();
        $marked = $this->photo();

        // Bez oznaczonych plikow filtra nie widac ani na liscie, ani w siatce.
        ob_start();
        do_action('restrict_manage_posts', 'attachment', 'bar');
        $this->assertStringNotContainsString(CmsDeletions::QUERY_VAR, (string) ob_get_clean());
        CmsDeletions::enqueue_grid_filter('upload.php');
        $this->assertStringNotContainsString(CmsDeletions::QUERY_VAR, implode('', (array) wp_scripts()->get_data('media-views', 'after')));

        update_post_meta($marked, CmsDeletions::META_DELETED, 1);

        ob_start();
        do_action('restrict_manage_posts', 'post', 'top');
        $this->assertStringNotContainsString(CmsDeletions::QUERY_VAR, (string) ob_get_clean());
        ob_start();
        do_action('restrict_manage_posts', 'attachment', 'bar');
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="' . CmsDeletions::QUERY_VAR . '"', $html);
        $this->assertStringContainsString('Usunięte w TD', $html);
        CmsDeletions::enqueue_grid_filter('edit.php');
        $this->assertStringNotContainsString(CmsDeletions::QUERY_VAR, implode('', (array) wp_scripts()->get_data('media-views', 'after')));
        CmsDeletions::enqueue_grid_filter('upload.php');
        $this->assertStringContainsString(CmsDeletions::QUERY_VAR, implode('', (array) wp_scripts()->get_data('media-views', 'after')));

        // Lista: glowne zapytanie upload.php.
        global $pagenow;
        $saved_page = $pagenow;
        $pagenow    = 'upload.php';
        // is_admin() pyta ekran o in_admin(); zaslepka nie wymaga plikow z wp-admin.
        $GLOBALS['current_screen'] = new class {
            public function in_admin(): bool
            {
                return true;
            }
        };
        $_GET[CmsDeletions::QUERY_VAR] = '1';
        $query                         = new WP_Query();
        $GLOBALS['wp_the_query']       = $query;
        $ids = $query->query(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids']);
        $pagenow = $saved_page;
        $this->assertSame([$marked], array_map('intval', $ids));

        // Siatka: bez parametru zapytanie bez zmian, z parametrem tylko oznaczone.
        $args = ['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids'];
        $this->assertSame($args, apply_filters('ajax_query_attachments_args', $args));
        $_REQUEST['query'] = [CmsDeletions::QUERY_VAR => '1'];
        $grid = new WP_Query(apply_filters('ajax_query_attachments_args', $args));
        $this->assertSame([$marked], array_map('intval', $grid->posts));
        $this->assertNotContains($plain, array_map('intval', $grid->posts));
    }

    public function test_no_file_deleted(): void
    {
        $uploads = wp_upload_dir();
        $path    = $uploads['basedir'] . '/hf-zostaje.jpg';
        file_put_contents($path, 'x');
        $id = $this->photo('hf-zostaje.jpg');

        $touched = 0;
        $count   = static function () use (&$touched): void {
            $touched++;
        };
        add_action('delete_attachment', $count);
        add_action('wp_trash_post', $count);
        try {
            $this->poll('deleted', $id);
            $this->assertTrue($this->marked($id));
            $this->poll('restored', $id);
        } finally {
            remove_action('delete_attachment', $count);
            remove_action('wp_trash_post', $count);
        }

        $this->assertSame(0, $touched);
        $this->assertFileExists($path);
        $this->assertSame('inherit', get_post($id)->post_status);
        unlink($path);

        $source = (string) file_get_contents(dirname(__DIR__) . '/src/WordPress/CmsDeletions.php');
        $this->assertStringNotContainsString('wp_delete_attachment', $source);
        $this->assertStringNotContainsString('wp_trash_post', $source);
    }

    public function test_old_backend_501_keeps_queue(): void
    {
        $this->seed(5);
        $this->routes['POST ' . self::SEND_PATH] = [501, ['code' => 'not_implemented', 'message' => 'x']];

        CmsSync::run_send();

        $this->assertCount(5, CmsDeletions::queue());
        $this->assertCount(1, $this->sends());
        $this->assertSame('', CmsSync::last_runs()['send']['error']);
        // Bez ponowienia co 5 minut: stara trasa nie zniknie sama, a kolejke rusza godzinny przebieg.
        $this->assertFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
    }

    public function test_old_backend_501_does_not_stop_field_sending(): void
    {
        $this->seed(5);
        $this->routes['POST ' . self::SEND_PATH]     = [501, ['code' => 'not_implemented', 'message' => 'x']];
        $this->routes['POST /v3/cms/media/fields'] = [200, ['results' => []]];
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        update_post_meta($this->photo(), MediaMetaSync::META_ALT, 'Alt');

        CmsSync::run_send();

        $this->assertNotEmpty(array_filter($this->requests, static fn ($r) => $r['path'] === '/v3/cms/media/fields'));
        $this->assertCount(5, CmsDeletions::queue());
    }

    public function test_settings_screen_shows_overflow(): void
    {
        require_once ABSPATH . 'wp-admin/includes/template.php';
        set_transient(CmsDeletions::TRANSIENT_OVERFLOW, 3, WEEK_IN_SECONDS);

        ob_start();
        \TribuneDesk\WordPress\CmsSettingsScreen::render_block();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Kolejka zgłoszeń usunięć', $html);
    }
}
