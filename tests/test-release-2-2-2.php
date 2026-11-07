<?php
declare(strict_types=1);

use TribuneDesk\Protocol\DeliveryAnswer;
use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsSync;
use TribuneDesk\WordPress\PublishedUrlsClient;

/**
 * 2.2.2: the answer carries `cms_media_id` and the Facebook flag (contract 3.4.0), the link back
 * goes only over https, and a lock is released only by the run that holds it.
 */
class Test_Release_2_2_2 extends WP_UnitTestCase
{
    /** @var array<int, string> */
    private array $urls = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->urls = [];
        add_filter('pre_http_request', [$this, 'record'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'record'], 10);
        delete_option(PublishedUrlsClient::OPTION_API_BASE);
        delete_option(CmsApiClient::OPTION_TOKEN);
        delete_option(CmsSync::OPTION_LOCK);
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function record($preempt, array $args, string $url)
    {
        $this->urls[] = $url;

        return ['headers' => [], 'body' => '{"results":[]}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }

    public function test_answer_carries_the_featured_image_id_as_text_too(): void
    {
        $answer = DeliveryAnswer::build(7, 'created', null, null, ['asset_id' => 'a-1', 'attachment_id' => 42, 'url' => 'https://example.com/a.jpg']);

        $this->assertSame('42', $answer['featured_image']['cms_media_id']);
        $this->assertSame(42, $answer['featured_image']['attachment_id']);
    }

    public function test_facebook_flag_goes_only_with_a_permalink(): void
    {
        $published = DeliveryAnswer::build(7, 'created', null, 'https://example.com/p/', null, true);
        $draft = DeliveryAnswer::build(7, 'created', null, null, null, true);
        $unconfigured = DeliveryAnswer::build(7, 'created', null, 'https://example.com/p/', null, null);

        $this->assertTrue($published['social_queue_exclude']);
        $this->assertArrayNotHasKey('social_queue_exclude', $draft);
        $this->assertArrayNotHasKey('social_queue_exclude', $unconfigured);
    }

    public function test_link_back_never_sends_the_token_over_http(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'http://api.example.com');

        $result = PublishedUrlsClient::send([[
            'article_id' => '0b8f1c2a-3d4e-4f5a-8b6c-7d8e9f0a1b2c',
            'url'        => 'https://example.com/a/',
        ]], 5);

        $this->assertSame([], $this->urls);
        $this->assertSame(PublishedUrlsClient::STATUS_REJECTED, $result['status']);
        $this->assertFalse($result['retryable']);
    }

    public function test_default_api_base_is_tribunedesk(): void
    {
        $this->assertSame('https://api.tribunedesk.com', PublishedUrlsClient::api_base());
    }

    public function test_a_run_does_not_release_a_lock_another_run_took_over(): void
    {
        global $wpdb;
        $this->assertTrue(CmsSync::acquire_lock());
        // The lock expired and a newer run took it over meanwhile.
        $wpdb->update($wpdb->options, ['option_value' => (time() + 300) . ':newer'], ['option_name' => CmsSync::OPTION_LOCK]);

        CmsSync::release_lock();

        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CmsSync::OPTION_LOCK));
        $this->assertStringEndsWith(':newer', (string) $value);
        $this->assertFalse(CmsSync::acquire_lock());
    }

    public function test_the_holder_releases_its_own_lock(): void
    {
        $this->assertTrue(CmsSync::acquire_lock());
        CmsSync::release_lock();

        $this->assertTrue(CmsSync::acquire_lock());
        CmsSync::release_lock();
    }
}
