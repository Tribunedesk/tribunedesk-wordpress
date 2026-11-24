<?php
declare(strict_types=1);

use TribuneDesk\WordPress\BodyImages;
use TribuneDesk\WordPress\PostWriter;
use TribuneDesk\WordPress\WriteResult;

/**
 * 2.5.0: zdjecia z biblioteki w tresci dostawy staja sie natywne przy zapisie szkicu;
 * to, czego nie udalo sie pobrac w budzecie, wraca jednym ponowieniem po minucie.
 */
class Test_Body_Images_Delivery extends WP_UnitTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private const ASSET   = '22222222-2222-2222-2222-222222222222';
    private const SRC     = 'https://storage.tribunedesk.com/abc/body.png';
    private const UUID    = '88888888-8888-4888-8888-888888888888';

    private int $author;
    private bool $http_ok = true;

    public function set_up(): void
    {
        parent::set_up();
        $this->author  = self::factory()->user->create(['role' => 'editor']);
        $this->http_ok = true;
        add_filter('pre_http_request', [$this, 'fake_image'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_image'], 10);
        wp_unschedule_hook(BodyImages::RETRY_HOOK);
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_image($preempt, array $args, string $url)
    {
        if (!$this->http_ok) {
            return new WP_Error('http_request_failed', 'down');
        }
        if (!empty($args['filename'])) {
            file_put_contents($args['filename'], base64_decode(self::PNG_1X1));
        }

        return [
            'headers'  => ['content-type' => 'image/png'],
            'body'     => '',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => $args['filename'] ?? null,
        ];
    }

    private function figure(): string
    {
        return '<figure><img src="' . self::SRC . '" alt="Ksiądz" data-td-asset-id="' . self::ASSET . '"><figcaption>podpis/Fot. X</figcaption></figure>';
    }

    private function payload(string $html = ''): array
    {
        return ['article_id' => self::UUID, 'title' => 'T', 'html' => $html !== '' ? $html : '<p>a</p>' . $this->figure()];
    }

    private function attachments_count(): int
    {
        return count(get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']));
    }

    public function test_new_draft_gets_native_caption(): void
    {
        $r = PostWriter::write($this->payload(), $this->author);

        $content = get_post($r['post_id'])->post_content;
        $this->assertStringContainsString('[caption id="attachment_', $content);
        $this->assertStringContainsString('podpis/Fot. X[/caption]', $content);
        $this->assertStringNotContainsString('<figure', $content);
    }

    public function test_resend_draft_same_content_no_new_attachments(): void
    {
        $r1      = PostWriter::write($this->payload(), $this->author);
        $content = get_post($r1['post_id'])->post_content;
        $count   = $this->attachments_count();

        $r2 = PostWriter::write($this->payload(), $this->author);

        $this->assertSame($r1['post_id'], $r2['post_id']);
        $this->assertSame($content, get_post($r2['post_id'])->post_content);
        $this->assertSame($count, $this->attachments_count());
    }

    public function test_direct_publish_converts_figures(): void
    {
        $r = PostWriter::write($this->payload(), $this->author, true);

        $post = get_post($r['post_id']);
        $this->assertSame('publish', $post->post_status);
        $this->assertStringContainsString('[caption id="attachment_', $post->post_content);
        $this->assertStringNotContainsString('<figure', $post->post_content);
    }

    public function test_already_published_post_not_touched(): void
    {
        $r = PostWriter::write($this->payload('<p>a</p>'), $this->author);
        wp_update_post(['ID' => $r['post_id'], 'post_status' => 'publish']);
        $count = $this->attachments_count();

        $r2 = PostWriter::write($this->payload(), $this->author);

        $this->assertSame(WriteResult::SkippedAlreadyPublished, $r2['status']);
        $this->assertSame('<p>a</p>', get_post($r['post_id'])->post_content);
        $this->assertSame($count, $this->attachments_count());
    }

    public function test_sideloaded_attachment_parent_is_new_post(): void
    {
        $r = PostWriter::write($this->payload(), $this->author);

        $this->assertSame(1, preg_match('/attachment_(\d+)/', get_post($r['post_id'])->post_content, $m));
        $this->assertSame($r['post_id'], (int) get_post((int) $m[1])->post_parent);
    }

    public function test_pending_schedules_single_retry(): void
    {
        $this->http_ok = false;

        $r = PostWriter::write($this->payload(), $this->author);
        PostWriter::write($this->payload(), $this->author);

        $this->assertStringContainsString('<figure', get_post($r['post_id'])->post_content);
        $this->assertNotFalse(wp_next_scheduled(BodyImages::RETRY_HOOK, [$r['post_id']]));
        $count = 0;
        foreach (_get_cron_array() as $hooks) {
            $count += isset($hooks[BodyImages::RETRY_HOOK]) ? count($hooks[BodyImages::RETRY_HOOK]) : 0;
        }
        $this->assertSame(1, $count);
    }

    public function test_retry_converts_only_hinted_figures_and_keeps_editor_changes(): void
    {
        $this->make_plain_attachment();
        $plain         = '<figure><img src="' . wp_upload_dir()['baseurl'] . '/2026/10/plain.png" alt="x"></figure>';
        $this->http_ok = false;
        $r    = PostWriter::write($this->payload(), $this->author);
        $post = get_post($r['post_id']);
        // The editor changes a paragraph and pastes a figure of an upload that has an attachment.
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => str_replace('<p>a</p>', '<p>zmiana redaktora</p>', $post->post_content) . $plain], ['ID' => $post->ID]);
        clean_post_cache($post->ID);
        $this->http_ok = true;

        PostWriter::retry_body_images($post->ID);

        $content = get_post($post->ID)->post_content;
        $this->assertStringContainsString('<p>zmiana redaktora</p>', $content);
        $this->assertStringContainsString('[caption id="attachment_', $content);
        $this->assertStringContainsString('plain.png', $content);
        $this->assertSame(1, substr_count($content, '<figure'), 'figura bez wskazowki zostaje');
    }

    /** Attachment for plain.png without TD hints: a hinted_only=false run would convert the plain figure. */
    private function make_plain_attachment(): void
    {
        $rel = '2026/10/plain.png';
        $abs = wp_upload_dir()['basedir'] . '/' . $rel;
        wp_mkdir_p(dirname($abs));
        file_put_contents($abs, base64_decode(self::PNG_1X1));
        $id = (int) wp_insert_attachment(['post_mime_type' => 'image/png', 'post_status' => 'inherit'], $abs);
        update_post_meta($id, '_wp_attached_file', $rel);
        wp_update_attachment_metadata($id, ['width' => 10, 'height' => 10, 'file' => $rel, 'sizes' => []]);
    }

    public function test_retry_editor_save_during_download_survives(): void
    {
        $this->http_ok = false;
        $r = PostWriter::write($this->payload(), $this->author);
        $this->http_ok = true;
        $post_id = $r['post_id'];
        $saved   = false;
        $editor  = function ($pre) use ($post_id, &$saved) {
            if (!$saved) {
                $saved = true;
                global $wpdb;
                $wpdb->update($wpdb->posts, ['post_content' => '<p>edit w trakcie</p>' . $this->figure()], ['ID' => $post_id]);
                clean_post_cache($post_id);
            }
            return $pre;
        };
        add_filter('pre_http_request', $editor, 5);

        PostWriter::retry_body_images($post_id);
        remove_filter('pre_http_request', $editor, 5);

        $content = get_post($post_id)->post_content;
        $this->assertTrue($saved);
        $this->assertStringContainsString('<p>edit w trakcie</p>', $content);
        $this->assertStringContainsString('[caption id="attachment_', $content);
        $this->assertStringNotContainsString('<figure', $content);
    }

    public function test_retry_keeps_published_status_and_dates(): void
    {
        $this->http_ok = false;
        $r = PostWriter::write($this->payload(), $this->author);
        $this->http_ok = true;
        wp_update_post(['ID' => $r['post_id'], 'post_status' => 'publish', 'post_date' => '2026-01-02 03:04:05', 'post_date_gmt' => '2026-01-02 03:04:05']);
        $before = get_post($r['post_id']);

        PostWriter::retry_body_images($r['post_id']);

        $after = get_post($r['post_id']);
        $this->assertStringContainsString('[caption id="attachment_', $after->post_content);
        $this->assertSame('publish', $after->post_status);
        $this->assertSame($before->post_date, $after->post_date);
        $this->assertSame($before->post_date_gmt, $after->post_date_gmt);
    }

    public function test_retry_does_not_filter_content_through_kses(): void
    {
        $this->http_ok = false;
        $r = PostWriter::write($this->payload(), $this->author);
        $this->http_ok = true;
        $extra = '<p style="color:red">x</p><iframe src="https://evil.example/e"></iframe>';
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => $extra . $this->figure()], ['ID' => $r['post_id']]);
        clean_post_cache($r['post_id']);
        wp_set_current_user(0);
        kses_init_filters();

        PostWriter::retry_body_images($r['post_id']);

        $content = get_post($r['post_id'])->post_content;
        $this->assertStringContainsString('<p style="color:red">x</p>', $content);
        $this->assertStringContainsString('<iframe src="https://evil.example/e">', $content);
        $this->assertStringContainsString('[caption id="attachment_', $content);
        $this->assertNotFalse(has_filter('content_save_pre', 'wp_filter_post_kses'), 'kses filters restored');
    }

    public function test_retry_does_not_reschedule(): void
    {
        $this->http_ok = false;
        $r = PostWriter::write($this->payload(), $this->author);
        wp_unschedule_hook(BodyImages::RETRY_HOOK);

        PostWriter::retry_body_images($r['post_id']);

        $this->assertFalse(wp_next_scheduled(BodyImages::RETRY_HOOK, [$r['post_id']]));
    }

    public function test_featured_image_still_attached(): void
    {
        $payload = $this->payload();
        $payload['generated_images'] = [['url' => 'https://storage.tribunedesk.com/abc/cover.png', 'title' => 'Okladka']];

        $r = PostWriter::write($payload, $this->author);

        $this->assertGreaterThan(0, (int) get_post_thumbnail_id($r['post_id']));
        $this->assertStringContainsString('[caption id="attachment_', get_post($r['post_id'])->post_content);
    }
}
