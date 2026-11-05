<?php
declare(strict_types=1);

use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\PublishedUrlsClient;

class Test_Cms_Api_Client extends WP_UnitTestCase
{
    private const TOKEN = 'tok-sekretny-123';

    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array<string, mixed>|WP_Error */
    private $reply = ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '{"ok":true}', 'headers' => [], 'cookies' => []];

    public function set_up(): void
    {
        parent::set_up();
        update_option(CmsApiClient::OPTION_TOKEN, self::TOKEN);
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        $this->requests = [];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        parent::tear_down();
    }

    /** @return array<string, mixed>|WP_Error */
    public function fake_http($pre, array $args, string $url)
    {
        $this->requests[] = ['url' => $url, 'args' => $args];

        return $this->reply;
    }

    private function reply_with(int $code, string $body): void
    {
        $this->reply = ['response' => ['code' => $code, 'message' => ''], 'body' => $body, 'headers' => [], 'cookies' => []];
    }

    public function test_client_sends_bearer_and_maps_errors(): void
    {
        $this->assertSame(['ok' => true], CmsApiClient::get('/settings'));
        $request = $this->requests[0];
        $this->assertSame('https://api.example.test/v3/cms/settings', $request['url']);
        $this->assertSame('GET', $request['args']['method']);
        $this->assertSame('Bearer ' . self::TOKEN, $request['args']['headers']['Authorization']);
        $this->assertSame(0, $request['args']['redirection']);

        $cases = [
            [409, '{"code":"option_locked","message":"x"}', 'option_locked'],
            [401, '{"code":"unauthorized","message":"x"}', 'unauthorized'],
            [503, '<html>bramka</html>', 'unavailable'],
            [500, '', 'internal_error'],
            [302, '', CmsApiClient::ERROR_INVALID_RESPONSE],
            [200, 'to nie json', CmsApiClient::ERROR_INVALID_RESPONSE],
        ];
        foreach ($cases as [$status, $body, $code]) {
            $this->reply_with($status, $body);
            $error = CmsApiClient::get('/settings');
            $this->assertWPError($error);
            $this->assertSame($code, $error->get_error_code());
        }
    }

    public function test_put_and_post_send_json_body(): void
    {
        CmsApiClient::put('/settings', ['variant' => 'full']);
        CmsApiClient::post('/held/abc/decision', ['decision' => 'send']);

        $this->assertSame('PUT', $this->requests[0]['args']['method']);
        $this->assertSame(['variant' => 'full'], json_decode($this->requests[0]['args']['body'], true));
        $this->assertSame('POST', $this->requests[1]['args']['method']);
        $this->assertStringEndsWith('/v3/cms/held/abc/decision', $this->requests[1]['url']);
    }

    public function test_no_token_sends_nothing(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, '');
        $this->assertSame(CmsApiClient::ERROR_NO_TOKEN, CmsApiClient::get('/status')->get_error_code());
        $this->assertSame([], $this->requests);
    }

    public function test_token_is_not_sent_without_https(): void
    {
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'http://api.example.test');
        $this->assertSame(CmsApiClient::ERROR_INSECURE_BASE, CmsApiClient::get('/status')->get_error_code());
        $this->assertSame([], $this->requests);
    }

    public function test_error_message_never_carries_token_or_transport_detail(): void
    {
        $this->reply = new WP_Error('http_request_failed', 'cURL error for Bearer ' . self::TOKEN);
        $error = CmsApiClient::get('/status');
        $this->assertSame(CmsApiClient::ERROR_TRANSPORT, $error->get_error_code());
        $this->assertStringNotContainsString(self::TOKEN, $error->get_error_message());

        $this->reply_with(500, '{"code":"internal_error","message":"zla odpowiedz ' . self::TOKEN . '"}');
        $this->assertStringNotContainsString(self::TOKEN, CmsApiClient::get('/status')->get_error_message());
    }
}
