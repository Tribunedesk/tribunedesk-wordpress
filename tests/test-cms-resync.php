<?php
declare(strict_types=1);

use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsResync;
use TribuneDesk\WordPress\CmsSync;
use TribuneDesk\WordPress\PublishedUrlsClient;

class Test_Cms_Resync extends WP_UnitTestCase
{
    private const TOKEN = 'tok-sekretny-123';
    private const RID   = '6f1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array<string, array{0: int, 1: array|string}> */
    private array $routes = [];

    public function set_up(): void
    {
        parent::set_up();
        require_once ABSPATH . 'wp-admin/includes/template.php';
        update_option(CmsApiClient::OPTION_TOKEN, self::TOKEN);
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        delete_option(CmsResync::OPTION_STATE);
        delete_option(CmsSync::OPTION_NEEDS_RESYNC);
        delete_option(CmsSync::OPTION_CURSOR);
        wp_clear_scheduled_hook(CmsResync::HOOK_PAGE);
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->requests = [];
        $this->routes   = [
            'POST /v3/cms/resync preview' => [200, $this->preview('collecting')],
            'POST /v3/cms/resync confirm' => [200, ['status' => 'applied', 'cursor' => 'c-new']],
        ];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        $_POST    = [];
        $_REQUEST = [];
        parent::tear_down();
    }

    /** @return array<string, mixed> */
    public function fake_http($pre, array $args, string $url): array
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        $route = $args['method'] . ' ' . wp_parse_url($url, PHP_URL_PATH);
        if ($route === 'POST /v3/cms/resync') {
            $route .= ' ' . $this->form($args);
        }
        [$code, $body] = $this->routes[$route] ?? [404, ['code' => 'not_found', 'message' => 'x']];

        return ['response' => ['code' => $code, 'message' => ''], 'body' => is_string($body) ? $body : wp_json_encode($body), 'headers' => [], 'cookies' => []];
    }

    /** @return array<string, mixed> */
    private function preview(string $status, int $diff = 3): array
    {
        return ['resync_id' => self::RID, 'status' => $status, 'differences' => $diff, 'expires_at' => gmdate('c', time() + DAY_IN_SECONDS)];
    }

    /** @return int[] */
    private function images(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = self::factory()->attachment->create_object(['file' => "r$i.jpg", 'post_mime_type' => 'image/jpeg', 'post_title' => "T$i", 'post_excerpt' => "C$i"]);
        }

        return $ids;
    }

    /** Serwer rozroznia podglad i zatwierdzenie po tresci, jak unia w `POST /v3/cms/resync`. */
    private function form(array $args): string
    {
        return array_key_exists('confirm', json_decode($args['body'], true)) ? 'confirm' : 'preview';
    }

    /** @return array<int, array<string, mixed>> */
    private function posts(string $form): array
    {
        $out = [];
        foreach ($this->requests as $r) {
            if ($r['args']['method'] === 'POST' && wp_parse_url($r['url'], PHP_URL_PATH) === '/v3/cms/resync' && $this->form($r['args']) === $form) {
                $out[] = json_decode($r['args']['body'], true);
            }
        }

        return $out;
    }

    private function post_as(string $action, callable $handler, array $post = []): void
    {
        $_POST                = $post;
        $_REQUEST['_wpnonce'] = wp_create_nonce($action);
        $stop = static function () {
            throw new RuntimeException('redirect');
        };
        add_filter('wp_redirect', $stop);
        try {
            $handler();
            $this->fail('Oczekiwano przekierowania.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $stop);
        }
    }

    private function render(): string
    {
        ob_start();
        CmsResync::render();

        return (string) ob_get_clean();
    }

    public function test_start_needs_nonce_and_capability(): void
    {
        $_POST = ['hf_cms_direction' => 'cms_to_td'];
        $_REQUEST['_wpnonce'] = 'zly';
        try {
            CmsResync::handle_start();
            $this->fail('Oczekiwano wp_die.');
        } catch (\WPDieException $e) {
            $this->assertNotFalse($e);
        }
        $this->assertFalse(get_option(CmsResync::OPTION_STATE));

        wp_set_current_user((int) self::factory()->user->create(['role' => 'editor']));
        $_REQUEST['_wpnonce'] = wp_create_nonce(CmsResync::ACTION_START);
        try {
            CmsResync::handle_start();
            $this->fail('Oczekiwano wp_die.');
        } catch (\WPDieException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertFalse(get_option(CmsResync::OPTION_STATE));

        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->post_as(CmsResync::ACTION_START, [CmsResync::class, 'handle_start'], ['hf_cms_direction' => 'cms_to_td']);
        $this->assertSame('cms_to_td', get_option(CmsResync::OPTION_STATE)['direction']);
        $this->assertNotFalse(wp_next_scheduled(CmsResync::HOOK_PAGE));
    }

    public function test_start_rejects_unknown_direction(): void
    {
        $this->post_as(CmsResync::ACTION_START, [CmsResync::class, 'handle_start'], ['hf_cms_direction' => 'x']);
        $this->assertFalse(get_option(CmsResync::OPTION_STATE));
    }

    public function test_pages_100_and_finish_with_is_last(): void
    {
        $ids = $this->images(101);
        self::factory()->post->create(['post_type' => 'post']);
        update_post_meta($ids[0], '_wp_attachment_image_alt', 'Alt0');
        update_option(CmsResync::OPTION_STATE, ['direction' => 'td_to_cms', 'status' => 'collecting']);

        CmsResync::run_page();

        $first = $this->posts('preview')[0];
        $this->assertSame('td_to_cms', $first['direction']);
        $this->assertCount(100, $first['items']);
        $this->assertFalse($first['is_last']);
        $this->assertArrayNotHasKey('resync_id', $first);
        $this->assertSame((string) $ids[0], $first['items'][0]['cms_media_id']);
        $by = array_column($first['items'][0]['fields'], 'value', 'field');
        $this->assertSame(['title' => 'T0', 'alt' => 'Alt0', 'caption' => 'C0', 'description' => ''], $by);
        $state = get_option(CmsResync::OPTION_STATE);
        $this->assertSame(self::RID, $state['resync_id']);
        $this->assertSame($ids[99], $state['last_id']);
        $this->assertGreaterThanOrEqual(time() + 25, (int) wp_next_scheduled(CmsResync::HOOK_PAGE));

        $this->routes['POST /v3/cms/resync preview'] = [200, $this->preview('ready', 5)];
        wp_clear_scheduled_hook(CmsResync::HOOK_PAGE);
        CmsResync::run_page();

        $second = $this->posts('preview')[1];
        $this->assertCount(1, $second['items']);
        $this->assertTrue($second['is_last']);
        $this->assertSame(self::RID, $second['resync_id']);
        $state = get_option(CmsResync::OPTION_STATE);
        $this->assertSame('ready', $state['status']);
        $this->assertSame(5, $state['differences']);
        $this->assertFalse(wp_next_scheduled(CmsResync::HOOK_PAGE));
        $this->assertStringContainsString('5', $this->render());
        $this->assertStringContainsString('Wygrywa Tribune Desk', $this->render());
    }

    public function test_page_leaves_out_nul_and_too_long_values(): void
    {
        $id = $this->images(1)[0];
        update_post_meta($id, '_wp_attachment_image_alt', "a\0b");
        wp_update_post(['ID' => $id, 'post_content' => str_repeat('x', 5001)]);
        update_option(CmsResync::OPTION_STATE, ['direction' => 'cms_to_td', 'status' => 'collecting']);
        $this->routes['POST /v3/cms/resync preview'] = [200, $this->preview('ready')];

        CmsResync::run_page();

        $by = array_column($this->posts('preview')[0]['items'][0]['fields'], 'value', 'field');
        $this->assertSame(['title' => 'T0', 'caption' => 'C0'], $by);
        $this->assertSame('ready', get_option(CmsResync::OPTION_STATE)['status']);
    }

    public function test_confirm_sets_cursor_and_clears_flag(): void
    {
        update_option(CmsSync::OPTION_NEEDS_RESYNC, 1);
        update_option(CmsResync::OPTION_STATE, ['direction' => 'cms_to_td', 'status' => 'ready', 'resync_id' => self::RID, 'differences' => 2, 'expires_at' => time() + 3600]);

        $this->post_as(CmsResync::ACTION_CONFIRM, [CmsResync::class, 'handle_confirm']);

        $this->assertSame(['resync_id' => self::RID, 'confirm' => true], $this->posts('confirm')[0]);
        $this->assertSame(60, $this->requests[0]['args']['timeout']);
        $this->assertSame('c-new', get_option(CmsSync::OPTION_CURSOR));
        $this->assertFalse(get_option(CmsSync::OPTION_NEEDS_RESYNC));
        $this->assertFalse(get_option(CmsResync::OPTION_STATE));
    }

    public function test_confirm_refused_before_ready(): void
    {
        update_option(CmsResync::OPTION_STATE, ['direction' => 'cms_to_td', 'status' => 'collecting', 'resync_id' => self::RID]);
        $this->post_as(CmsResync::ACTION_CONFIRM, [CmsResync::class, 'handle_confirm']);
        $this->assertSame([], $this->posts('confirm'));
    }

    public function test_held_result_shows_batch(): void
    {
        update_option(CmsSync::OPTION_NEEDS_RESYNC, 1);
        update_option(CmsResync::OPTION_STATE, ['direction' => 'cms_to_td', 'status' => 'ready', 'resync_id' => self::RID, 'differences' => 900, 'expires_at' => time() + 3600]);
        $this->routes['POST /v3/cms/resync confirm'] = [200, ['status' => 'held', 'cursor' => 'c-held', 'held_batch_id' => '11111111-2222-4333-8444-555555555555']];

        $this->post_as(CmsResync::ACTION_CONFIRM, [CmsResync::class, 'handle_confirm']);

        $this->assertSame('c-held', get_option(CmsSync::OPTION_CURSOR));
        $this->assertFalse(get_option(CmsSync::OPTION_NEEDS_RESYNC));
        ob_start();
        \TribuneDesk\WordPress\CmsSettingsScreen::render_notice();
        $notice = (string) ob_get_clean();
        $this->assertStringContainsString('11111111-2222-4333-8444-555555555555', $notice);
    }

    public function test_abandon_restarts_from_now(): void
    {
        update_option(CmsSync::OPTION_NEEDS_RESYNC, 1);
        update_option(CmsSync::OPTION_CURSOR, 'stary');
        update_option(CmsResync::OPTION_STATE, ['direction' => 'cms_to_td', 'status' => 'collecting', 'last_id' => 5]);
        wp_schedule_single_event(time() + 30, CmsResync::HOOK_PAGE);

        $this->post_as(CmsResync::ACTION_ABANDON, [CmsResync::class, 'handle_abandon']);

        $this->assertFalse(get_option(CmsResync::OPTION_STATE));
        $this->assertFalse(get_option(CmsSync::OPTION_NEEDS_RESYNC));
        $this->assertFalse(get_option(CmsSync::OPTION_CURSOR));
        $this->assertFalse(wp_next_scheduled(CmsResync::HOOK_PAGE));
    }

    public function test_screen_warns_about_gap_and_explains_direction(): void
    {
        update_option(CmsSync::OPTION_NEEDS_RESYNC, 1);
        $html = $this->render();
        $this->assertStringContainsString('Wygrywa WordPress', $html);
        $this->assertStringContainsString('Wygrywa Tribune Desk', $html);
        $this->assertStringContainsString('nie dotrą', $html);
    }

    public function test_error_keeps_position(): void
    {
        $this->images(2);
        update_option(CmsResync::OPTION_STATE, ['direction' => 'cms_to_td', 'status' => 'collecting', 'resync_id' => self::RID, 'last_id' => 7]);
        $this->routes['POST /v3/cms/resync preview'] = [500, ['code' => 'internal_error', 'message' => 'x']];

        CmsResync::run_page();

        $state = get_option(CmsResync::OPTION_STATE);
        $this->assertSame(7, $state['last_id']);
        $this->assertSame(self::RID, $state['resync_id']);
        $this->assertNotSame('', $state['error']);
        $this->assertFalse(wp_next_scheduled(CmsResync::HOOK_PAGE));
        $this->assertStringContainsString(esc_html((string) $state['error']), $this->render());
        $this->assertStringNotContainsString(self::TOKEN, $this->render());

        $this->post_as(CmsResync::ACTION_RESUME, [CmsResync::class, 'handle_resume']);
        $this->assertSame('', get_option(CmsResync::OPTION_STATE)['error']);
        $this->assertNotFalse(wp_next_scheduled(CmsResync::HOOK_PAGE));
    }
}
