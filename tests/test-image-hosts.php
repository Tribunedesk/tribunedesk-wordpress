<?php
declare(strict_types=1);

use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\DeliveriesLog;
use TribuneDesk\WordPress\ImageHosts;
use TribuneDesk\WordPress\MediaHandler;

/**
 * Images only from the hosts Tribune Desk lists (spec D10 "Security", guide level 1 "Where
 * images come from"). Without a token (level 1 only) the plugin cannot read the list and checks
 * https only (plan E4a, Ruling 6).
 */
class Test_ImageHosts extends WP_UnitTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @var array<int, string> */
    private array $urls = [];

    private int $settings_status = 200;

    public function set_up(): void
    {
        parent::set_up();
        \TribuneDesk\WordPress\Activator::activate();
        delete_option(ImageHosts::OPTION);
        delete_option(CmsApiClient::OPTION_TOKEN);
        $this->urls            = [];
        $this->settings_status = 200;
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_http($preempt, array $args, string $url)
    {
        $this->urls[] = $url;
        if (str_contains($url, '/v3/cms/settings')) {
            return [
                'headers'  => ['content-type' => 'application/json'],
                'body'     => $this->settings_status === 200
                    ? wp_json_encode(['image_hosts' => ['media.example.com', 'CDN.Example.org']])
                    : wp_json_encode(['code' => 'internal_error', 'message' => 'x']),
                'response' => ['code' => $this->settings_status, 'message' => 'x'],
                'cookies'  => [],
                'filename' => null,
            ];
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

    public function test_allows_is_https_only_without_a_list(): void
    {
        $this->assertTrue(ImageHosts::allows('https://anything.example.net/a.png', null));
        $this->assertFalse(ImageHosts::allows('http://anything.example.net/a.png', null));
        $this->assertFalse(ImageHosts::allows('data:image/png;base64,AAAA', null));
        $this->assertFalse(ImageHosts::allows('//media.example.com/a.png', null));
    }

    public function test_allows_checks_the_host_against_the_list_ignoring_case(): void
    {
        $hosts = ['media.example.com', 'cdn.example.org'];
        $this->assertTrue(ImageHosts::allows('https://MEDIA.example.com/a.png', $hosts));
        $this->assertTrue(ImageHosts::allows('https://cdn.example.org/x/y.jpg', $hosts));
        $this->assertFalse(ImageHosts::allows('https://evil.example.com/a.png', $hosts));
        $this->assertFalse(ImageHosts::allows('https://media.example.com.evil.net/a.png', $hosts));
        $this->assertFalse(ImageHosts::allows('http://media.example.com/a.png', $hosts));
    }

    public function test_current_without_token_is_null_and_makes_no_request(): void
    {
        $this->assertNull(ImageHosts::current());
        $this->assertSame([], $this->urls);
    }

    public function test_current_fetches_and_stores_the_list_with_a_token(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        $this->assertSame(['media.example.com', 'cdn.example.org'], ImageHosts::current());
        $this->assertCount(1, $this->urls);
        // Fresh list: no second request.
        $this->assertSame(['media.example.com', 'cdn.example.org'], ImageHosts::current());
        $this->assertCount(1, $this->urls);
    }

    public function test_fetch_error_without_an_old_list_gives_null_and_logs(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        $this->settings_status = 500;
        $this->assertNull(ImageHosts::current());
        $this->assertStringContainsString('image_hosts', (string) DeliveriesLog::recent()[0]['error_msg']);
    }

    public function test_failed_fetch_is_not_repeated_within_the_hour(): void
    {
        // A token without settings:read (articles only) fails every time: one request and one
        // log row per hour, not per image.
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_articlesonly');
        $this->settings_status = 403;
        $this->assertNull(ImageHosts::current());
        $this->assertNull(ImageHosts::current());
        $this->assertCount(1, $this->urls);
        $this->assertCount(1, DeliveriesLog::recent());
    }

    public function test_fetch_error_keeps_the_old_list(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        update_option(ImageHosts::OPTION, ['hosts' => ['old.example.com'], 'fetched_at' => time() - 7200]);
        $this->settings_status = 500;
        $this->assertSame(['old.example.com'], ImageHosts::current());
    }

    public function test_remember_stores_hosts_from_a_settings_answer(): void
    {
        ImageHosts::remember(['image_hosts' => ['A.example.com', 'b.example.com', 7]]);
        $stored = get_option(ImageHosts::OPTION);
        $this->assertSame(['a.example.com', 'b.example.com'], $stored['hosts']);
    }

    public function test_sideload_from_a_host_outside_the_list_downloads_nothing(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        update_option(ImageHosts::OPTION, ['hosts' => ['media.example.com'], 'fetched_at' => time()]);

        $id = MediaHandler::sideload_from_url('https://evil.example.net/a.png', 'a.png');

        $this->assertNull($id);
        $this->assertSame([], $this->urls);
    }

    public function test_sideload_from_a_listed_host_works(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        update_option(ImageHosts::OPTION, ['hosts' => ['media.example.com'], 'fetched_at' => time()]);

        $id = MediaHandler::sideload_from_url('https://media.example.com/a.png', 'a.png');

        $this->assertIsInt($id);
    }

    public function test_refused_host_is_logged(): void
    {
        // Review E4a: a refused image must leave a trace for support.
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        update_option(ImageHosts::OPTION, ['hosts' => ['media.example.com'], 'fetched_at' => time()]);

        MediaHandler::sideload_from_url('https://cdn.client.example.net/a.png', 'a.png');

        $row = DeliveriesLog::recent()[0];
        $this->assertSame(DeliveriesLog::KIND_MEDIA, $row['kind']);
        $this->assertStringContainsString('cdn.client.example.net', (string) $row['error_msg']);
    }

    public function test_empty_list_from_tribune_desk_means_unknown_not_nothing(): void
    {
        $this->assertNull(ImageHosts::remember(['image_hosts' => []]));
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        update_option(ImageHosts::OPTION, ['hosts' => [], 'fetched_at' => time()]);
        $this->assertNull(ImageHosts::current());
        $this->assertIsInt(MediaHandler::sideload_from_url('https://media.example.com/a.png', 'a.png'));
    }

    public function test_sideload_without_token_still_works_over_https(): void
    {
        $this->assertIsInt(MediaHandler::sideload_from_url('https://media.example.com/a.png', 'a.png'));
        $this->assertNull(MediaHandler::sideload_from_url('http://media.example.com/a.png', 'a.png'));
    }
}
