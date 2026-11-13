<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\RestEndpoint;

/**
 * The plugin against the published contract: copies of `openapi.json` and the sample
 * `article-delivered.json` from the tag of Tribunedesk/tribunedesk-cms-api named by the
 * `contract` field of tribunedesk.json. CI checks the copies byte for byte against that tag, so these tests
 * run offline and a contract change shows up in the diff.
 */
class Test_Contract extends WP_UnitTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, TRIBUNE_DESK_TESTS_SIGNING_SECRET);
        update_option(RestEndpoint::OPTION_DEFAULT_AUTHOR, (int) self::factory()->user->create(['role' => 'editor']));
        // The sample's image is on media.example.com: the list Tribune Desk gives a CMS with a
        // token (GET /v3/cms/status), already read this hour.
        update_option(\TribuneDesk\WordPress\CmsApiClient::OPTION_TOKEN, 'tdcms_contract');
        update_option(\TribuneDesk\WordPress\ImageHosts::OPTION, ['hosts' => ['media.example.com'], 'fetched_at' => time()]);
        do_action('rest_api_init');
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        parent::tear_down();
    }

    /** Image downloads get a 1x1 PNG; anything else an empty 200. */
    public function fake_http($pre, array $args, string $url): array
    {
        if (!empty($args['filename'])) {
            file_put_contents((string) $args['filename'], (string) base64_decode(self::PNG_1X1));
        }

        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '{}', 'headers' => [], 'cookies' => [], 'filename' => $args['filename'] ?? null];
    }

    /** @return array<string, mixed> */
    private static function json(string $file): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/contract/' . $file), true);
        self::assertIsArray($data, "tests/contract/$file is missing or not JSON");

        return $data;
    }

    public function test_version_names_a_tag(): void
    {
        // 2.2.3: the manifest tribunedesk.json replaces tests/contract/VERSION; CI fetches tag
        // v<contract> of the contract and v<protocol> of tribunedesk/cms-protocol from it.
        $manifest = json_decode((string) @file_get_contents(dirname(__DIR__) . '/tribunedesk.json'), true);
        $this->assertIsArray($manifest, 'tribunedesk.json is missing or not JSON');
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/', (string) ($manifest['contract'] ?? ''));
        $this->assertSame($manifest['contract'], self::json('openapi.json')['info']['version']);
        $this->assertSame($manifest['contract'], \TribuneDesk\Protocol\ContractVersion::TESTED, 'src/Protocol is tested on the same contract');
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) ($manifest['protocol'] ?? ''));
    }

    public function test_sample_delivery_creates_a_draft_and_answers_in_contract_keys(): void
    {
        // Level 1: the post excerpt is `excerpt`, with the teaser option off (the default).
        $payload = self::json('article-delivered.json');
        $req     = new WP_REST_Request('POST', '/' . RestEndpoint::NAMESPACE . RestEndpoint::ROUTE);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body((string) wp_json_encode($payload));

        tribune_desk_tests_sign($req);
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $data = $res->get_data();
        $post = get_post((int) $data['cms_post_id']);
        $this->assertSame('draft', $post->post_status);
        $this->assertSame($payload['title'], $post->post_title);
        $this->assertStringContainsString('approved the 2027 budget', $post->post_content);
        $this->assertSame($payload['excerpt'], $post->post_excerpt);
        $this->assertTrue(has_post_thumbnail($post), 'the sample image becomes the featured image');

        $schema  = self::json('openapi.json')['components']['schemas']['ArticleDeliveryResult'];
        $allowed = array_keys($schema['properties']);
        $this->assertSame([], array_values(array_diff(array_keys($data), $allowed)), 'response keys outside ArticleDeliveryResult');
        foreach ((array) ($schema['required'] ?? []) as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }
}
