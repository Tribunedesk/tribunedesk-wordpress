<?php
declare(strict_types=1);

use TribuneDesk\WordPress\PublishedUrlsClient;
use TribuneDesk\WordPress\RestEndpoint;

/**
 * Wzorzec zaslepki HTTP dla calego planu: pre_http_request zwraca gotowa odpowiedz
 * albo WP_Error i zapisuje kazde zadanie. Pakiet WP nie blokuje ruchu HTTP, wiec bez
 * tej zaslepki test poszedlby do prawdziwego api.headlinesforge.com.
 */
class Test_Published_Urls_Client extends WP_UnitTestCase
{
    private const UUID_A = '514e9e08-c003-42b7-81e8-79f5167ec833';
    private const UUID_B = 'af36be4b-1961-4f66-9723-705862656927';

    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array|WP_Error|null */
    private $next_response = null;

    public function set_up(): void
    {
        parent::set_up();
        update_option(RestEndpoint::OPTION_SECRET, 'sekret-testowy');
        delete_option(PublishedUrlsClient::OPTION_API_BASE);
        delete_option(\TribuneDesk\WordPress\CmsApiClient::OPTION_TOKEN);
        $this->requests = [];
        $this->next_response = $this->http(200, ['results' => [['article_id' => self::UUID_A, 'status' => 'ok']]]);
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        delete_option(PublishedUrlsClient::OPTION_API_BASE);
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_http($preempt, array $args, string $url)
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        return $this->next_response;
    }

    /** @param array<string, mixed> $json */
    private function http(int $code, array $json = [], array $headers = []): array
    {
        return [
            'headers'  => $headers,
            'body'     => wp_json_encode($json),
            'response' => ['code' => $code, 'message' => 'x'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function one(): array
    {
        return [['article_id' => self::UUID_A, 'url' => 'https://example.com/a/']];
    }

    // --- 1.17.0: link back with the tdcms_ token through /v3 (spec D5) -------------------

    /** @var array<int, array|WP_Error> */
    private array $queue = [];

    private function with_token(): void
    {
        update_option(\TribuneDesk\WordPress\CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
    }

    private function dated(): array
    {
        return [[
            'article_id'   => self::UUID_A,
            'url'          => 'https://example.com/a/',
            'published_at' => '2026-11-04T09:30:00+00:00',
        ]];
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_queue($preempt, array $args, string $url)
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        return array_shift($this->queue);
    }

    public function test_with_token_sends_through_v3_with_bearer_and_published_at(): void
    {
        $this->with_token();
        $result = PublishedUrlsClient::send($this->dated(), 5);

        $this->assertCount(1, $this->requests);
        $this->assertSame('https://api.headlinesforge.com/v3/cms/articles/published', $this->requests[0]['url']);
        $args = $this->requests[0]['args'];
        $this->assertSame('Bearer tdcms_testtoken', $args['headers']['Authorization']);
        $this->assertArrayNotHasKey('X-Webhook-Secret', $args['headers']);
        $this->assertSame(0, $args['redirection']);
        $this->assertSame(['items' => $this->dated()], json_decode((string) $args['body'], true));
        $this->assertSame(PublishedUrlsClient::STATUS_SENT, $result['status']);
        $this->assertSame('v3', $result['route']);
        $this->assertSame(1, $result['counts']['ok']);
    }

    public function test_v3_403_falls_back_to_v2_with_the_old_secret(): void
    {
        $this->with_token();
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        add_filter('pre_http_request', [$this, 'fake_queue'], 10, 3);
        $this->queue = [
            $this->http(403, ['code' => 'forbidden', 'message' => 'x']),
            $this->http(200, ['results' => [['article_id' => self::UUID_A, 'status' => 'ok']]]),
        ];

        $result = PublishedUrlsClient::send($this->dated(), 5);
        remove_filter('pre_http_request', [$this, 'fake_queue'], 10);

        $this->assertCount(2, $this->requests);
        $this->assertStringEndsWith('/v3/cms/articles/published', $this->requests[0]['url']);
        $this->assertStringEndsWith('/v2/cms/published-urls', $this->requests[1]['url']);
        $this->assertSame('sekret-testowy', $this->requests[1]['args']['headers']['X-Webhook-Secret']);
        // The old route keeps its old body: no published_at.
        $this->assertSame(
            ['articles' => [['article_id' => self::UUID_A, 'url' => 'https://example.com/a/']]],
            json_decode((string) $this->requests[1]['args']['body'], true)
        );
        $this->assertSame(PublishedUrlsClient::STATUS_SENT, $result['status']);
        $this->assertSame('v2', $result['route']);
        $this->assertStringContainsString('articles:write', (string) $result['notice']);
    }

    public function test_v3_401_falls_back_to_v2_and_says_the_token_was_rejected(): void
    {
        // E5: a revoked or invalid token is not a missing permission; the log must not say it is.
        $this->with_token();
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        add_filter('pre_http_request', [$this, 'fake_queue'], 10, 3);
        $this->queue = [
            $this->http(401, ['code' => 'unauthorized', 'message' => 'x']),
            $this->http(200, ['results' => [['article_id' => self::UUID_A, 'status' => 'ok']]]),
        ];

        $result = PublishedUrlsClient::send($this->dated(), 5);
        remove_filter('pre_http_request', [$this, 'fake_queue'], 10);

        $this->assertCount(2, $this->requests);
        $this->assertStringEndsWith('/v2/cms/published-urls', $this->requests[1]['url']);
        $this->assertSame('v2', $result['route']);
        $this->assertStringContainsString(__('Tribune Desk rejected the token (revoked or invalid)', 'tribune-desk'), (string) $result['notice']);
        $this->assertStringNotContainsString('articles:write', (string) $result['notice']);
    }

    public function test_v3_404_falls_back_to_v2(): void
    {
        // Review E4a: an older backend (or api_base pointing at one) has no /v3 route.
        $this->with_token();
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        add_filter('pre_http_request', [$this, 'fake_queue'], 10, 3);
        $this->queue = [
            $this->http(404, ['detail' => 'Not Found']),
            $this->http(200, ['results' => [['article_id' => self::UUID_A, 'status' => 'ok']]]),
        ];

        $result = PublishedUrlsClient::send($this->dated(), 5);
        remove_filter('pre_http_request', [$this, 'fake_queue'], 10);

        $this->assertCount(2, $this->requests);
        $this->assertStringEndsWith('/v2/cms/published-urls', $this->requests[1]['url']);
        $this->assertSame(PublishedUrlsClient::STATUS_SENT, $result['status']);
        $this->assertSame('v2', $result['route']);
    }

    public function test_v3_403_without_old_secret_is_unauthorized_about_the_token(): void
    {
        $this->with_token();
        delete_option(RestEndpoint::OPTION_SECRET);
        $this->next_response = $this->http(403, ['code' => 'forbidden', 'message' => 'x']);

        $result = PublishedUrlsClient::send($this->dated(), 5);

        $this->assertCount(1, $this->requests);
        $this->assertSame(PublishedUrlsClient::STATUS_UNAUTHORIZED, $result['status']);
        $this->assertStringContainsString('articles:write', (string) $result['error']);
    }

    public function test_without_token_the_old_route_is_used_and_marked(): void
    {
        $result = PublishedUrlsClient::send($this->dated(), 5);
        $this->assertStringEndsWith('/v2/cms/published-urls', $this->requests[0]['url']);
        $this->assertSame('v2', $result['route']);
        $this->assertNull($result['notice']);
    }

    public function test_published_at_falls_back_to_post_date_when_gmt_is_unset(): void
    {
        $post = new WP_Post((object) ['post_date' => '2026-11-04 10:30:00', 'post_date_gmt' => '0000-00-00 00:00:00']);
        $this->assertSame(
            gmdate('Y-m-d\TH:i:s+00:00', strtotime(get_gmt_from_date('2026-11-04 10:30:00') . ' UTC')),
            PublishedUrlsClient::published_at($post)
        );
        $set = new WP_Post((object) ['post_date' => '2026-11-04 10:30:00', 'post_date_gmt' => '2026-11-04 09:30:00']);
        $this->assertSame('2026-11-04T09:30:00+00:00', PublishedUrlsClient::published_at($set));
    }

    public function test_iso_date_from_post_date_gmt(): void
    {
        $this->assertSame('2026-11-04T09:30:00+00:00', PublishedUrlsClient::iso_date('2026-11-04 09:30:00'));
        $this->assertNull(PublishedUrlsClient::iso_date('0000-00-00 00:00:00'));
        $this->assertNull(PublishedUrlsClient::iso_date(''));
    }

    public function test_zadanie_ma_adres_naglowek_i_body(): void
    {
        PublishedUrlsClient::send($this->one(), 5);

        $this->assertCount(1, $this->requests);
        $this->assertSame('https://api.headlinesforge.com/v2/cms/published-urls', $this->requests[0]['url']);
        $args = $this->requests[0]['args'];
        $this->assertSame('sekret-testowy', $args['headers']['X-Webhook-Secret']);
        $this->assertSame('application/json', $args['headers']['Content-Type']);
        $this->assertSame(5, $args['timeout']);
        $this->assertSame(
            ['articles' => [['article_id' => self::UUID_A, 'url' => 'https://example.com/a/']]],
            json_decode((string) $args['body'], true)
        );
    }

    public function test_api_base_z_opcji_bez_koncowego_slasha(): void
    {
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://staging.example.com/');

        PublishedUrlsClient::send($this->one(), 5);

        $this->assertSame('https://staging.example.com/v2/cms/published-urls', $this->requests[0]['url']);
    }

    public function test_pozycje_bez_uuid_odpadaja_przed_wysylka(): void
    {
        $result = PublishedUrlsClient::send([
            ['article_id' => 'art-100', 'url' => 'https://example.com/x/'],
            ['article_id' => self::UUID_A, 'url' => 'https://example.com/a/'],
        ], 5);

        $this->assertCount(1, $this->requests);
        $body = json_decode((string) $this->requests[0]['args']['body'], true);
        $this->assertCount(1, $body['articles']);
        $this->assertSame(self::UUID_A, $body['articles'][0]['article_id']);
        $this->assertSame(1, $result['counts']['skipped_non_uuid']);
        $this->assertSame(1, $result['items_sent']);
    }

    public function test_same_pozycje_bez_uuid_nie_robia_zadania(): void
    {
        $result = PublishedUrlsClient::send([['article_id' => 'art-100', 'url' => 'https://example.com/x/']], 5);

        $this->assertSame(PublishedUrlsClient::STATUS_NOTHING_TO_SEND, $result['status']);
        $this->assertCount(0, $this->requests);
        $this->assertFalse($result['retryable']);
    }

    public function test_brak_sekretu_nie_robi_zadania(): void
    {
        update_option(RestEndpoint::OPTION_SECRET, '');

        $result = PublishedUrlsClient::send($this->one(), 5);

        $this->assertSame(PublishedUrlsClient::STATUS_UNAUTHORIZED, $result['status']);
        $this->assertCount(0, $this->requests);
        $this->assertStringContainsString('brak sekretu', (string) $result['error']);
    }

    public function test_paczka_ponad_100_pozycji_odrzucona_lokalnie(): void
    {
        $items = [];
        for ($i = 0; $i < 101; $i++) {
            $items[] = ['article_id' => sprintf('%08x-0000-4000-8000-%012x', $i, $i), 'url' => 'https://example.com/' . $i . '/'];
        }

        $result = PublishedUrlsClient::send($items, 10);

        $this->assertSame(PublishedUrlsClient::STATUS_REJECTED, $result['status']);
        $this->assertCount(0, $this->requests);
    }

    public function test_200_jedna_pozycja_ok(): void
    {
        $result = PublishedUrlsClient::send($this->one(), 5);

        $this->assertSame(PublishedUrlsClient::STATUS_SENT, $result['status']);
        $this->assertSame(200, $result['http']);
        $this->assertSame('ok', $result['results'][self::UUID_A]);
        $this->assertSame(1, $result['counts']['ok']);
        $this->assertFalse($result['retryable']);
        $this->assertNull($result['error']);
    }

    public function test_200_paczka_liczy_statusy_per_pozycja(): void
    {
        $this->next_response = $this->http(200, ['results' => [
            ['article_id' => self::UUID_A, 'status' => 'ok'],
            ['article_id' => self::UUID_B, 'status' => 'forbidden'],
        ]]);

        $result = PublishedUrlsClient::send([
            ['article_id' => self::UUID_A, 'url' => 'https://example.com/a/'],
            ['article_id' => self::UUID_B, 'url' => 'https://example.com/b/'],
        ], 10);

        $this->assertSame(PublishedUrlsClient::STATUS_SENT, $result['status']);
        $this->assertSame('forbidden', $result['results'][self::UUID_B]);
        $this->assertSame(1, $result['counts']['ok']);
        $this->assertSame(1, $result['counts']['forbidden']);
        $this->assertSame(
            'ok=1 not_found=0 forbidden=1 invalid_url=0 skipped_non_uuid=0',
            PublishedUrlsClient::format_counts($result['counts'])
        );
    }

    public function test_401_unauthorized_bez_retry(): void
    {
        $this->next_response = $this->http(401, ['results' => [['article_id' => self::UUID_A, 'status' => 'forbidden']]]);

        $result = PublishedUrlsClient::send($this->one(), 5);

        $this->assertSame(PublishedUrlsClient::STATUS_UNAUTHORIZED, $result['status']);
        $this->assertSame(401, $result['http']);
        $this->assertFalse($result['retryable']);
        $this->assertStringContainsString('sekret', (string) $result['error']);
    }

    public function test_429_retryable_z_retry_after(): void
    {
        $this->next_response = $this->http(429, ['detail' => 'Too many requests'], ['retry-after' => '3']);

        $result = PublishedUrlsClient::send($this->one(), 5);

        $this->assertSame(PublishedUrlsClient::STATUS_RATE_LIMITED, $result['status']);
        $this->assertTrue($result['retryable']);
        $this->assertSame(3, $result['retry_after']);
    }

    public function test_429_bez_retry_after(): void
    {
        $this->next_response = $this->http(429, ['detail' => 'Too many requests']);

        $result = PublishedUrlsClient::send($this->one(), 5);

        $this->assertSame(PublishedUrlsClient::STATUS_RATE_LIMITED, $result['status']);
        $this->assertNull($result['retry_after']);
    }

    public function test_5xx_error_retryable(): void
    {
        $this->next_response = $this->http(503, ['detail' => 'down']);

        $result = PublishedUrlsClient::send($this->one(), 5);

        $this->assertSame(PublishedUrlsClient::STATUS_ERROR, $result['status']);
        $this->assertTrue($result['retryable']);
        $this->assertSame('http 503', $result['error']);
    }

    public function test_422_rejected_z_wycinkiem_ciala_bez_retry(): void
    {
        $this->next_response = $this->http(422, ['detail' => [['msg' => 'value is not a valid uuid']]]);

        $result = PublishedUrlsClient::send($this->one(), 5);

        $this->assertSame(PublishedUrlsClient::STATUS_REJECTED, $result['status']);
        $this->assertFalse($result['retryable']);
        $this->assertStringContainsString('http 422', (string) $result['error']);
        $this->assertStringContainsString('valid uuid', (string) $result['error']);
    }

    public function test_wp_error_retryable(): void
    {
        $this->next_response = new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');

        $result = PublishedUrlsClient::send($this->one(), 5);

        $this->assertSame(PublishedUrlsClient::STATUS_ERROR, $result['status']);
        $this->assertNull($result['http']);
        $this->assertTrue($result['retryable']);
        $this->assertStringContainsString('cURL error 28', (string) $result['error']);
    }

    public function test_2xx_bez_pola_results_rejected(): void
    {
        $this->next_response = [
            'headers'  => [],
            'body'     => '<!doctype html><html><body>Pages</body></html>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];

        $result = PublishedUrlsClient::send($this->one(), 5);

        $this->assertSame(PublishedUrlsClient::STATUS_REJECTED, $result['status']);
        $this->assertSame('odpowiedź bez pola results', $result['error']);
    }

    public function test_describe_ma_osobny_tekst_dla_kazdego_statusu(): void
    {
        $this->assertNull(PublishedUrlsClient::describe('ok', 'https://example.com/a/'));
        $this->assertStringContainsString('nie w redakcji', (string) PublishedUrlsClient::describe('forbidden', 'u'));
        $this->assertStringContainsString('nie zna', (string) PublishedUrlsClient::describe('not_found', 'u'));
        $this->assertStringContainsString('https://example.com/a/', (string) PublishedUrlsClient::describe('invalid_url', 'https://example.com/a/'));
        $this->assertNotSame(PublishedUrlsClient::describe('forbidden', 'u'), PublishedUrlsClient::describe('not_found', 'u'));
    }

    public function test_is_uuid(): void
    {
        $this->assertTrue(PublishedUrlsClient::is_uuid(self::UUID_A));
        $this->assertTrue(PublishedUrlsClient::is_uuid(strtoupper(self::UUID_A)));
        $this->assertFalse(PublishedUrlsClient::is_uuid('art-100'));
        $this->assertFalse(PublishedUrlsClient::is_uuid(''));
        $this->assertFalse(PublishedUrlsClient::is_uuid(self::UUID_A . 'x'));
    }
}
