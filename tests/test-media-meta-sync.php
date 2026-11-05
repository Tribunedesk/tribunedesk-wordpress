<?php
declare(strict_types=1);

use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsSync;
use TribuneDesk\WordPress\MediaHandler;
use TribuneDesk\WordPress\MediaMetaSync;

class Test_Media_Meta_Sync extends WP_UnitTestCase
{
    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->requests = [];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        do_action('rest_api_init');
        // D6: sledzone sa zmiany zalogowanego uzytkownika, wiec testy edycji dzialaja jako redaktor.
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        parent::tear_down();
    }

    public function fake_http($pre, array $args, string $url): WP_Error
    {
        $this->requests[] = ['url' => $url, 'args' => $args];

        return new WP_Error('http_test', 'bez sieci w testach');
    }

    private function attachment(): int
    {
        return (int) self::factory()->attachment->create(['post_title' => 'T', 'post_excerpt' => 'AKPA', 'post_content' => 'Opis']);
    }

    private function dirty(int $id): array
    {
        $dirty = get_post_meta($id, MediaMetaSync::META_DIRTY, true);

        return is_array($dirty) ? $dirty : [];
    }

    public function test_new_attachment_is_not_dirty(): void
    {
        $this->assertSame([], $this->dirty($this->attachment()));
    }

    public function test_edit_marks_only_changed_post_field(): void
    {
        $id = $this->attachment();
        wp_update_post(['ID' => $id, 'post_excerpt' => 'East']);
        $this->assertSame(['caption'], $this->dirty($id));
    }

    public function test_save_without_change_marks_nothing(): void
    {
        $id = $this->attachment();
        wp_update_post(['ID' => $id, 'post_excerpt' => 'AKPA']);
        $this->assertSame([], $this->dirty($id));
    }

    public function test_alt_edit_marks_alt(): void
    {
        $id = $this->attachment();
        update_post_meta($id, MediaMetaSync::META_ALT, 'Pierwszy');
        $this->assertSame(['alt'], $this->dirty($id));
        delete_post_meta($id, MediaMetaSync::META_DIRTY);
        update_post_meta($id, MediaMetaSync::META_ALT, 'Pierwszy');
        $this->assertSame([], $this->dirty($id));
        update_post_meta($id, MediaMetaSync::META_ALT, 'Drugi');
        $this->assertSame(['alt'], $this->dirty($id));
    }

    public function test_cli_cron_and_anonymous_do_not_mark(): void
    {
        $id = $this->attachment();

        // Cron, nawet z ustawionym uzytkownikiem.
        add_filter('wp_doing_cron', '__return_true');
        $this->assertFalse(MediaMetaSync::is_human_write());
        wp_update_post(['ID' => $id, 'post_excerpt' => 'Z crona']);
        update_post_meta($id, MediaMetaSync::META_ALT, 'Z crona');
        remove_filter('wp_doing_cron', '__return_true');
        $this->assertSame([], $this->dirty($id));

        // Brak zalogowanego uzytkownika.
        wp_set_current_user(0);
        $this->assertFalse(MediaMetaSync::is_human_write());
        wp_update_post(['ID' => $id, 'post_excerpt' => 'Anonim']);
        update_post_meta($id, MediaMetaSync::META_ALT, 'Anonim');
        $this->assertSame([], $this->dirty($id));
    }

    /**
     * Stalej WP_CLI nie da sie cofnac, wiec osobny proces.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_wp_cli_does_not_mark_even_with_user(): void
    {
        define('WP_CLI', true);
        $id = $this->attachment();

        $this->assertTrue(is_user_logged_in());
        $this->assertFalse(MediaMetaSync::is_human_write());
        wp_update_post(['ID' => $id, 'post_excerpt' => 'Z CLI']);
        update_post_meta($id, MediaMetaSync::META_ALT, 'Z CLI');
        $this->assertSame([], $this->dirty($id));
    }

    public function test_logged_in_rest_marks(): void
    {
        $id = $this->attachment();
        $this->assertTrue(MediaMetaSync::is_human_write());

        $request = new WP_REST_Request('POST', '/wp/v2/media/' . $id);
        $request->set_param('caption', 'Z REST');
        $request->set_param('alt_text', 'Alt z REST');
        $this->assertSame(200, rest_do_request($request)->get_status());

        $this->assertEqualsCanonicalizing(['caption', 'alt'], $this->dirty($id));
    }

    public function test_applying_blocks_marking_for_logged_in_user(): void
    {
        MediaMetaSync::without_tracking(function (): void {
            $this->assertFalse(MediaMetaSync::is_human_write());
        });
        $this->assertTrue(MediaMetaSync::is_human_write());
    }

    public function test_old_route_is_gone_and_receive_stays(): void
    {
        $routes = rest_get_server()->get_routes();
        foreach (['tribunedesk/v1', 'headlinesforge/v1'] as $namespace) {
            $this->assertArrayNotHasKey("/$namespace/media-meta", $routes);
            $this->assertArrayHasKey("/$namespace/receive", $routes);
        }
    }

    public function test_metadata_from_delivery_is_not_a_human_change(): void
    {
        $id = $this->attachment();
        MediaHandler::apply_metadata_to_attachment($id, ['caption' => 'AKPA nowe', 'alt' => 'X', 'title' => 'Tytul']);
        $this->assertSame([], $this->dirty($id));
        $this->assertSame('Tytul', get_post($id)->post_title);
    }

    public function test_title_wins_over_filename_but_slug_follows_filename(): void
    {
        $id = $this->attachment();
        MediaHandler::apply_metadata_to_attachment($id, ['title' => 'Tytul grafiki', 'filename' => 'moj-plik.jpg']);
        $this->assertSame('Tytul grafiki', get_post($id)->post_title);
        $this->assertSame('moj-plik', get_post($id)->post_name);
    }

    private function fill_queue_to_limit(): void
    {
        global $wpdb;
        $filler = $this->attachment();
        for ($i = 0; $i < MediaMetaSync::MAX_PENDING; $i++) {
            $wpdb->insert($wpdb->postmeta, ['post_id' => $filler, 'meta_key' => MediaMetaSync::META_DIRTY, 'meta_value' => 'a:0:{}']);
        }
    }

    public function test_without_token_edit_waits_in_queue_without_http(): void
    {
        $id = $this->attachment();
        wp_update_post(['ID' => $id, 'post_excerpt' => 'East']);

        $this->assertSame(['caption'], $this->dirty($id));
        $this->assertSame([$id], MediaMetaSync::pending_ids(10));
        $this->assertSame([], $this->requests);
    }

    public function test_without_token_limit_stops_new_attachments_only(): void
    {
        $queued = $this->attachment();
        wp_update_post(['ID' => $queued, 'post_excerpt' => 'East']);
        $this->fill_queue_to_limit();
        $other = $this->attachment();

        wp_update_post(['ID' => $other, 'post_excerpt' => 'Ponad limit']);
        update_post_meta($queued, MediaMetaSync::META_ALT, 'Drugie pole');

        $this->assertSame([], $this->dirty($other));
        $this->assertSame('', get_post_meta($other, MediaMetaSync::META_DIRTY_AT, true));
        $this->assertEqualsCanonicalizing(['caption', 'alt'], $this->dirty($queued));
    }

    public function test_disabled_integration_is_limited_like_no_token(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, 'token-testowy');
        update_option(CmsSync::OPTION_LAST_MODE, 'disabled');
        $this->fill_queue_to_limit();
        $id = $this->attachment();

        wp_update_post(['ID' => $id, 'post_excerpt' => 'Ponad limit']);

        $this->assertSame([], $this->dirty($id));
    }

    public function test_active_integration_has_no_limit(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, 'token-testowy');
        update_option(CmsSync::OPTION_LAST_MODE, 'active');
        $this->fill_queue_to_limit();
        $id = $this->attachment();

        wp_update_post(['ID' => $id, 'post_excerpt' => 'Dalej']);

        $this->assertSame(['caption'], $this->dirty($id));
    }
}
