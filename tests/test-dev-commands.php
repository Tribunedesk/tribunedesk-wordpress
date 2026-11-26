<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\DevCommands;
use TribuneDesk\WordPress\ImageHosts;
use TribuneDesk\WordPress\PostWriter;
use TribuneDesk\WordPress\PublishedUrlsClient;
use TribuneDesk\WordPress\RestEndpoint;
use TribuneDesk\WordPress\SeoMeta;

/**
 * DevCommands, levels 1-3: what `wp td inspect` and `wp td publish` do for `tdkit check`
 * (spec "kontrakt najpierw" D2e). inspect reads what the plugin stored, never the delivery.
 */
class Test_Dev_Commands extends WP_UnitTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @var list<array{url: string, args: array<string, mixed>}> */
    private array $requests = [];

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, TRIBUNE_DESK_TESTS_SIGNING_SECRET);
        update_option(RestEndpoint::OPTION_DEFAULT_AUTHOR, (int) self::factory()->user->create(['role' => 'editor', 'display_name' => 'Desk Editor']));
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_dev');
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        update_option(ImageHosts::OPTION, ['hosts' => ['media.example.com'], 'fetched_at' => time()]);
        do_action('rest_api_init');
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        parent::tear_down();
    }

    /** Image downloads get a 1x1 PNG; the link back answers ok for every item. */
    public function fake_http($pre, array $args, string $url): array
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        if (!empty($args['filename'])) {
            file_put_contents((string) $args['filename'], (string) base64_decode(self::PNG_1X1));
        }
        $body = '{}';
        if (str_contains($url, '/v3/cms/articles/published')) {
            $items = json_decode((string) ($args['body'] ?? '{}'), true)['items'] ?? [];
            $body = (string) wp_json_encode(['results' => array_map(static fn (): array => ['status' => 'ok'], $items)]);
        }

        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => $body, 'headers' => [], 'cookies' => [], 'filename' => $args['filename'] ?? null];
    }

    /** @return array<string, mixed> the sample delivery of the pinned contract, delivered */
    private function deliver(array $override = []): array
    {
        $payload = array_merge(json_decode((string) file_get_contents(__DIR__ . '/contract/article-delivered.json'), true), $override);
        $req = new WP_REST_Request('POST', '/' . RestEndpoint::NAMESPACE . RestEndpoint::ROUTE);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body((string) wp_json_encode($payload));
        tribune_desk_tests_sign($req);
        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return $payload;
    }

    public function test_inspect_unknown_article_is_null(): void
    {
        $this->assertNull(DevCommands::inspect('00000000-0000-4000-8000-000000000000'));
    }

    public function test_inspect_after_delivery_has_level_1_keys(): void
    {
        $payload = $this->deliver();

        $post = DevCommands::inspect($payload['article_id']);

        $this->assertIsArray($post);
        $id = PostWriter::find_by_article_id($payload['article_id'])->ID;
        $this->assertSame((string) $id, $post['cms_post_id']);
        $this->assertSame($payload['title'], $post['title']);
        $this->assertStringContainsString('approved the 2027 budget', $post['html']);
        $this->assertSame($payload['excerpt'], $post['excerpt']);
        $this->assertSame('draft', $post['status']);
        $this->assertNull($post['permalink']);
        $this->assertStringStartsWith('http', (string) $post['featured_image_url']);
        foreach (['seo_title', 'meta_description', 'language', 'author', 'read_more', 'ai_provenance_jws'] as $key) {
            $this->assertArrayHasKey($key, $post);
        }
        $this->assertSame('Desk Editor', $post['author']);
    }

    public function test_inspect_reads_stored_values_not_the_delivery(): void
    {
        $payload = $this->deliver();
        $id = PostWriter::find_by_article_id($payload['article_id'])->ID;
        update_post_meta($id, SeoMeta::META_TITLE, 'Changed in WordPress');

        $this->assertSame('Changed in WordPress', DevCommands::inspect($payload['article_id'])['seo_title']);
    }

    public function test_inspect_read_more_lists_article_ids(): void
    {
        // B names A in backlink_sources, so A's box shows B (tdkit l3-backlinks-rules).
        $a = $this->deliver(['article_id' => 'c4d5e6f7-a8b9-4c0d-9e1f-2a3b4c5d6e7f', 'backlink_sources' => []]);
        DevCommands::publish($a['article_id']);
        $b = $this->deliver(['backlink_sources' => [['article_id' => $a['article_id'], 'distance' => 0.2]]]);
        DevCommands::publish($b['article_id']);

        $this->assertSame([$b['article_id']], DevCommands::inspect($a['article_id'])['read_more']);
    }

    public function test_publish_sends_link_back_before_returning(): void
    {
        $payload = $this->deliver();
        $this->requests = [];

        DevCommands::publish($payload['article_id']);

        $post = DevCommands::inspect($payload['article_id']);
        $this->assertSame('published', $post['status'], 'the contract word, not the WordPress one');
        $this->assertNotNull($post['permalink']);
        $sent = array_values(array_filter($this->requests, static fn (array $r): bool => str_contains($r['url'], '/v3/cms/articles/published')));
        $this->assertCount(1, $sent, 'the link back is sent before publish returns');
        $items = json_decode((string) $sent[0]['args']['body'], true)['items'];
        $this->assertSame([$payload['article_id'], $post['permalink']], [$items[0]['article_id'], $items[0]['url']]);
    }

    public function test_inspect_status_scheduled_and_pending_use_contract_words(): void
    {
        $payload = $this->deliver();
        $post = PostWriter::find_by_article_id($payload['article_id']);
        wp_update_post(['ID' => $post->ID, 'post_status' => 'future', 'edit_date' => true, 'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS), 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);
        $this->assertSame('future', get_post_status($post->ID));
        $this->assertSame('scheduled', DevCommands::inspect($payload['article_id'])['status']);
        wp_update_post(['ID' => $post->ID, 'post_status' => 'pending']);
        $this->assertSame('draft', DevCommands::inspect($payload['article_id'])['status']);
    }

    public function test_publish_unknown_article_throws(): void
    {
        $this->expectException(RuntimeException::class);
        DevCommands::publish('00000000-0000-4000-8000-000000000000');
    }
}
