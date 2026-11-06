<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\Backfill;
use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\PostWriter;
use TribuneDesk\WordPress\PublishNotifier;
use TribuneDesk\WordPress\RestEndpoint;
use TribuneDesk\WordPress\Settings;
use TribuneDesk\WordPress\SocialQueueFlag;

/**
 * 2.1.0: the "do not publish on Facebook" CMS field goes to Tribune Desk as
 * `social_queue_exclude` in the link back (spec HF 2026-11-06 D5, D1a).
 */
class Test_Social_Queue_Flag extends WP_UnitTestCase
{
    private const UUID_A = '11111111-1111-4111-8111-111111111111';
    private const KEY    = 'exclude_facebook_rss';

    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array|WP_Error|null null = a 200 answer built from the request body */
    private $next_response = null;

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        update_option(SocialQueueFlag::OPTION, self::KEY);
        SocialQueueFlag::reset();
        $this->requests      = [];
        $this->next_response = null;
        _set_cron_array([]);
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        unset($_REQUEST['bulk_edit']);
        SocialQueueFlag::reset();
        _set_cron_array([]);
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_http($preempt, array $args, string $url)
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        if ($this->next_response !== null) {
            return $this->next_response;
        }
        $body    = json_decode((string) ($args['body'] ?? ''), true);
        $results = [];
        foreach (($body['items'] ?? []) as $article) {
            $results[] = ['article_id' => $article['article_id'], 'status' => 'ok'];
        }

        return [
            'headers'  => [],
            'body'     => wp_json_encode(['results' => $results]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /** @param array<string, mixed> $meta */
    private function make_post(string $status = 'draft', array $meta = [], string $article_id = self::UUID_A): int
    {
        return (int) self::factory()->post->create([
            'post_status' => $status,
            'meta_input'  => [PostWriter::META_ARTICLE_ID => $article_id] + $meta,
        ]);
    }

    /** @return array<string, mixed> the only item of the request */
    private function sent_item(int $index = 0): array
    {
        $this->assertArrayHasKey($index, $this->requests, 'Expected a request.');
        $body  = json_decode((string) $this->requests[$index]['args']['body'], true);
        $items = $body['items'] ?? $body['articles'];
        $this->assertCount(1, $items);

        return $items[0];
    }

    /** The theme stores its meta box in save_post, from the edit form. */
    private function theme_saves_field(string $value): void
    {
        add_action('save_post', static function (int $post_id) use ($value): void {
            update_post_meta($post_id, self::KEY, $value);
        });
    }

    public function test_without_the_setting_the_field_is_never_sent(): void
    {
        update_option(SocialQueueFlag::OPTION, '');
        $id = $this->make_post('draft', [self::KEY => '1']);

        wp_publish_post($id);

        $this->assertCount(1, $this->requests);
        $this->assertArrayNotHasKey('social_queue_exclude', $this->sent_item());
    }

    public function test_an_invalid_key_in_the_option_switches_the_feature_off(): void
    {
        update_option(SocialQueueFlag::OPTION, 'not a key!');
        $id = $this->make_post('draft', [self::KEY => '1']);

        wp_publish_post($id);

        $this->assertArrayNotHasKey('social_queue_exclude', $this->sent_item());
    }

    public function test_with_the_setting_the_field_is_always_sent(): void
    {
        $cases = ['1' => true, 'Yes' => true, 'on' => true, 'TRUE' => true, '0' => false, '' => false, 'nope' => false];
        foreach ($cases as $value => $expected) {
            $this->requests = [];
            $id = $this->make_post('draft', [self::KEY => (string) $value]);

            wp_publish_post($id);

            $this->assertCount(1, $this->requests, "value '$value'");
            $this->assertStringEndsWith('/v3/cms/articles/published', $this->requests[0]['url']);
            $this->assertSame($expected, $this->sent_item()['social_queue_exclude'], "value '$value'");
        }
    }

    public function test_missing_meta_is_sent_as_false(): void
    {
        $id = $this->make_post();

        wp_publish_post($id);

        $this->assertFalse($this->sent_item()['social_queue_exclude']);
    }

    public function test_first_publication_from_the_edit_form_reads_the_field_saved_in_save_post(): void
    {
        $id = $this->make_post();
        $this->theme_saves_field('1');

        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertCount(1, $this->requests, 'One report: the publication carries the field, no second send.');
        $this->assertTrue($this->sent_item()['social_queue_exclude']);

        PublishNotifier::flush_pending();
        $this->assertCount(1, $this->requests, 'shutdown sends nothing more for the same post');
    }

    public function test_new_post_published_at_once_is_reported_once(): void
    {
        $this->make_post('publish', [self::KEY => '1']);

        PublishNotifier::flush_pending();

        $this->assertCount(1, $this->requests);
        $this->assertTrue($this->sent_item()['social_queue_exclude']);
    }

    public function test_cron_publication_of_a_scheduled_post_is_reported_with_the_field(): void
    {
        $id = (int) self::factory()->post->create([
            'post_status'   => 'future',
            'post_date'     => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
            'meta_input'    => [PostWriter::META_ARTICLE_ID => self::UUID_A, self::KEY => '1'],
        ]);
        $this->assertSame('future', get_post_status($id));
        $this->assertCount(0, $this->requests);

        // What check_and_publish_future_post() calls from cron.
        wp_publish_post($id);

        $this->assertSame('publish', get_post_status($id));
        $this->assertCount(1, $this->requests);
        $this->assertTrue($this->sent_item()['social_queue_exclude']);
    }

    public function test_field_change_on_a_published_post_is_reported_once_at_shutdown(): void
    {
        $id = $this->make_post('publish');
        $this->requests = [];

        update_post_meta($id, self::KEY, '1');
        $this->assertCount(0, $this->requests, 'The meta hook only marks the post.');

        PublishNotifier::flush_pending();
        PublishNotifier::flush_pending();

        $this->assertCount(1, $this->requests);
        $this->assertTrue($this->sent_item()['social_queue_exclude']);
        $this->assertSame(PublishNotifier::TIMEOUT, $this->requests[0]['args']['timeout']);
    }

    public function test_several_writes_in_one_request_make_one_report(): void
    {
        $id = $this->make_post('publish');
        $this->requests = [];

        update_post_meta($id, self::KEY, '1');
        update_post_meta($id, self::KEY, 'yes');
        update_post_meta($id, self::KEY, 'on');
        PublishNotifier::flush_pending();

        $this->assertCount(1, $this->requests);
    }

    public function test_deleting_the_field_reports_false(): void
    {
        $id = $this->make_post('publish', [self::KEY => '1']);
        SocialQueueFlag::reset(); // a new request
        $this->requests = [];

        delete_post_meta($id, self::KEY);
        PublishNotifier::flush_pending();

        $this->assertCount(1, $this->requests);
        $this->assertFalse($this->sent_item()['social_queue_exclude']);
    }

    public function test_field_change_saved_with_the_post_is_reported_in_the_save_not_again_at_shutdown(): void
    {
        $id = $this->make_post('publish');
        SocialQueueFlag::reset();
        $this->requests = [];
        $this->theme_saves_field('1');

        wp_update_post(['ID' => $id, 'post_title' => 'edited']);

        $this->assertCount(1, $this->requests);
        $this->assertTrue($this->sent_item()['social_queue_exclude']);
        PublishNotifier::flush_pending();
        $this->assertCount(1, $this->requests);
    }

    public function test_a_write_that_keeps_the_boolean_reports_nothing(): void
    {
        $id = $this->make_post('publish', [self::KEY => '1']);
        $other = $this->make_post('publish', [], '22222222-2222-4222-8222-222222222222');
        SocialQueueFlag::reset();
        $this->requests = [];

        update_post_meta($id, self::KEY, 'yes');   // still true
        update_post_meta($other, self::KEY, '0');  // missing -> '0', still false
        PublishNotifier::flush_pending();

        $this->assertCount(0, $this->requests);
    }

    public function test_change_and_change_back_in_one_request_reports_nothing(): void
    {
        $id = $this->make_post('publish');
        SocialQueueFlag::reset();
        $this->requests = [];

        update_post_meta($id, self::KEY, '1');
        delete_post_meta($id, self::KEY);
        PublishNotifier::flush_pending();

        $this->assertCount(0, $this->requests);
    }

    public function test_ordinary_edit_of_a_published_post_reports_nothing(): void
    {
        $id = $this->make_post('publish', [self::KEY => '1']);
        SocialQueueFlag::reset();
        $this->requests = [];
        $this->theme_saves_field('1'); // the theme writes the same value again

        wp_update_post(['ID' => $id, 'post_title' => 'edited']);
        PublishNotifier::flush_pending();

        $this->assertCount(0, $this->requests);
    }

    public function test_other_keys_drafts_and_posts_not_from_tribune_desk_report_nothing(): void
    {
        $published = $this->make_post('publish');
        $draft     = $this->make_post('draft', [], '22222222-2222-4222-8222-222222222222');
        $foreign   = (int) self::factory()->post->create(['post_status' => 'publish']);
        SocialQueueFlag::reset();
        $this->requests = [];

        update_post_meta($published, 'some_other_key', '1');
        update_post_meta($draft, self::KEY, '1');
        update_post_meta($foreign, self::KEY, '1');
        PublishNotifier::flush_pending();

        $this->assertCount(0, $this->requests);
    }

    public function test_retry_reads_the_value_at_sending(): void
    {
        $this->next_response = new WP_Error('http_request_failed', 'timeout');
        $id = $this->make_post();
        wp_publish_post($id);
        $this->assertFalse($this->sent_item()['social_queue_exclude']);
        $this->assertIsInt(wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$id]));

        update_post_meta($id, self::KEY, '1');
        SocialQueueFlag::reset(); // cron runs in a later request
        $this->next_response = null;
        $this->requests      = [];

        PublishNotifier::retry($id);

        $this->assertCount(1, $this->requests);
        $this->assertTrue($this->sent_item()['social_queue_exclude']);
    }

    public function test_bulk_publication_defers_and_cron_sends_the_current_value(): void
    {
        $id = $this->make_post();
        $_REQUEST['bulk_edit'] = 'Update';
        wp_publish_post($id);
        unset($_REQUEST['bulk_edit']);
        $this->assertCount(0, $this->requests);
        $this->assertIsInt(wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$id]));

        update_post_meta($id, self::KEY, 'on');
        SocialQueueFlag::reset();

        PublishNotifier::retry($id);

        $this->assertCount(1, $this->requests);
        $this->assertTrue($this->sent_item()['social_queue_exclude']);
    }

    public function test_without_token_nothing_is_sent(): void
    {
        // 2.2.0: the /v2 route that once carried a flagless link back is gone (E7).
        delete_option(CmsApiClient::OPTION_TOKEN);
        $id = $this->make_post('draft', [self::KEY => '1']);

        wp_publish_post($id);

        $this->assertSame([], $this->requests);
    }

    public function test_backfill_sends_the_field_too(): void
    {
        $id = $this->make_post('publish', [self::KEY => '1']);
        $this->requests = [];

        Backfill::run_batch();

        $this->assertCount(1, $this->requests);
        $this->assertTrue($this->sent_item()['social_queue_exclude']);
        $this->assertSame((string) get_permalink($id), $this->sent_item()['url']);
    }

    public function test_hooks_are_registered(): void
    {
        $this->assertSame(20, has_action('wp_after_insert_post', [PublishNotifier::class, 'on_after_insert']));
        $this->assertNotFalse(has_action('shutdown', [PublishNotifier::class, 'flush_pending']));
        foreach (['add_post_meta' => 'before_add', 'update_post_meta' => 'before_change', 'delete_post_meta' => 'before_change',
            'added_post_meta' => 'after_change', 'updated_post_meta' => 'after_change', 'deleted_post_meta' => 'after_change'] as $hook => $method) {
            $this->assertNotFalse(has_action($hook, [SocialQueueFlag::class, $method]), $hook);
        }
    }

    public function test_setting_is_registered_on_the_settings_page_and_sanitized(): void
    {
        global $wp_settings_fields;

        Settings::register_settings();

        $this->assertArrayHasKey(SocialQueueFlag::OPTION, $wp_settings_fields[Settings::PAGE_SLUG]['headlinesforge_theme_fields']);
        $registered = get_registered_settings();
        $this->assertSame('', $registered[SocialQueueFlag::OPTION]['default']);
        $this->assertSame([Settings::class, 'sanitize_meta_key'], $registered[SocialQueueFlag::OPTION]['sanitize_callback']);

        ob_start();
        Settings::render_social_exclude_key_field();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="' . SocialQueueFlag::OPTION . '"', $html);
        $this->assertStringContainsString('value="' . self::KEY . '"', $html);
    }

    public function test_requires_wordpress_5_6(): void
    {
        $root   = dirname(__DIR__);
        $header = get_file_data($root . '/headlinesforge-receiver.php', ['requires' => 'Requires at least']);
        $this->assertSame('5.6', $header['requires']);
        $this->assertMatchesRegularExpression('/^Requires at least: 5\.6$/m', (string) file_get_contents($root . '/readme.txt'));
    }
}
