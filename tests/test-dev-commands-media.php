<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsDeletions;
use TribuneDesk\WordPress\CmsSync;
use TribuneDesk\WordPress\DevCommands;
use TribuneDesk\WordPress\MediaHandler;
use TribuneDesk\WordPress\MediaMetaSync;
use TribuneDesk\WordPress\PublishedUrlsClient;

/**
 * DevCommands, level 4: `wp td media …`, `wp td sync` and `wp td reset` for `tdkit check`.
 * The media commands act as an editor would; sync runs one whole cycle without waiting for cron.
 */
class Test_Dev_Commands_Media extends WP_UnitTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @var list<string> URLs of the requests the plugin made */
    private array $urls = [];

    /** @var list<array<string, mixed>> answers of GET /changes, in order */
    private array $changes = [];

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_dev');
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        wp_set_current_user((int) self::factory()->user->create(['role' => 'editor']));
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        parent::tear_down();
    }

    public function fake_http($pre, array $args, string $url): array
    {
        $this->urls[] = $url;
        $body = ['results' => []];
        if (str_contains($url, '/v3/cms/status')) {
            $body = ['state' => 'active', 'scopes' => ['media:read', 'media:write'], 'image_hosts' => ['127.0.0.1']];
        } elseif (str_contains($url, '/v3/cms/changes')) {
            $body = array_shift($this->changes) ?? ['changes' => [], 'next_cursor' => 'c-end', 'has_more' => false];
        }

        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => (string) wp_json_encode($body), 'headers' => [], 'cookies' => [], 'filename' => null];
    }

    private function png(): string
    {
        // tdkit writes a PNG with its extension: WordPress checks the type by the name too.
        $file = wp_tempnam('td') . '.png';
        file_put_contents($file, (string) base64_decode(self::PNG_1X1));

        return $file;
    }

    public function test_media_add_returns_attachment_id(): void
    {
        $id = DevCommands::mediaAdd($this->png());

        $this->assertSame('attachment', get_post_type($id));
    }

    public function test_media_inspect_reports_fields_and_sha256(): void
    {
        $id = DevCommands::mediaAdd($this->png());
        update_post_meta($id, MediaHandler::META_ASSET_ID, 'asset-1');
        update_post_meta($id, MediaMetaSync::META_ALT, 'An alt');

        $file = DevCommands::mediaInspect($id);

        $this->assertSame((string) $id, $file['cms_media_id']);
        $this->assertSame('asset-1', $file['asset_id']);
        $this->assertSame(hash_file('sha256', (string) get_attached_file($id)), $file['sha256']);
        $this->assertSame('An alt', $file['fields']['alt']);
        $this->assertSame(['alt', 'caption', 'title', 'description'], array_keys($file['fields']));
        $this->assertFalse($file['deleted_in_td']);
    }

    public function test_media_inspect_unknown_is_null(): void
    {
        $this->assertNull(DevCommands::mediaInspect(999999));
    }

    public function test_media_edit_marks_field_dirty(): void
    {
        $id = DevCommands::mediaAdd($this->png());
        MediaMetaSync::clear($id);

        DevCommands::mediaEdit($id, 'caption', 'New caption');

        $this->assertSame('New caption', get_post($id)->post_excerpt);
        $this->assertContains('caption', MediaMetaSync::dirty_fields($id));
    }

    public function test_media_delete_queues_deletion(): void
    {
        $id = DevCommands::mediaAdd($this->png());
        update_post_meta($id, MediaHandler::META_ASSET_ID, 'asset-2');

        DevCommands::mediaDelete($id);

        $this->assertNull(get_post($id));
        $this->assertNotSame([], CmsDeletions::queue());
    }

    public function test_reset_clears_cursor(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c-old');

        DevCommands::reset();

        $this->assertFalse(get_option(CmsSync::OPTION_CURSOR));
    }

    public function test_sync_polls_until_no_more(): void
    {
        update_option(CmsSync::OPTION_CURSOR, 'c-0');
        $this->changes = [
            ['changes' => [], 'next_cursor' => 'c-1', 'has_more' => true],
            ['changes' => [], 'next_cursor' => 'c-2', 'has_more' => false],
        ];

        DevCommands::sync(false);

        $polls = array_filter($this->urls, static fn (string $u): bool => str_contains($u, '/v3/cms/changes'));
        $this->assertCount(2, $polls);
        $this->assertSame('c-2', get_option(CmsSync::OPTION_CURSOR));
    }
}
