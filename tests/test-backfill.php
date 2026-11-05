<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\Backfill;
use TribuneDesk\WordPress\DeliveriesLog;
use TribuneDesk\WordPress\PostWriter;
use TribuneDesk\WordPress\PublishedUrlsClient;
use TribuneDesk\WordPress\RestEndpoint;

class Test_Backfill extends WP_UnitTestCase
{
    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array<int, array|WP_Error> kolejka odpowiedzi; pusta = 200 ok zbudowane z body */
    private array $queue = [];

    /** @var array<int, int> sekundy pauzy przekazane do filtra */
    private array $pauses = [];

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        update_option(RestEndpoint::OPTION_SECRET, 'sekret-testowy');
        delete_option(Backfill::OPTION_CURSOR);
        delete_option(\TribuneDesk\WordPress\CmsApiClient::OPTION_TOKEN);
        $this->requests = [];
        $this->queue    = [];
        $this->pauses   = [];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        add_filter('headlinesforge_backfill_pause_seconds', [$this, 'record_pause']);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        remove_filter('headlinesforge_backfill_pause_seconds', [$this, 'record_pause']);
        delete_option(Backfill::OPTION_CURSOR);
        parent::tear_down();
    }

    public function record_pause(int $seconds): int
    {
        $this->pauses[] = $seconds;

        return 0; // test nie moze spac
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_http($preempt, array $args, string $url)
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }
        $body    = json_decode((string) ($args['body'] ?? ''), true);
        $results = [];
        foreach (($body['articles'] ?? $body['items'] ?? []) as $article) {
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

    private function http_error(int $code): array
    {
        return [
            'headers'  => [],
            'body'     => wp_json_encode(['detail' => 'x']),
            'response' => ['code' => $code, 'message' => 'x'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * Wpisy tworzone BEZ meta, meta dopisywane po publikacji — inaczej kazdy z 250 wpisow
     * odpalalby PublishNotifier (co jest poprawne, ale tu tylko zaciemnia licznik zadan).
     *
     * @return array<int, int> ID wpisow w kolejnosci tworzenia
     */
    private function make_posts(int $count, string $status = 'publish', ?string $article_id = null): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = (int) self::factory()->post->create(['post_status' => $status]);
            add_post_meta($id, PostWriter::META_ARTICLE_ID, $article_id ?? sprintf('%08x-0000-4000-8000-%012x', $id, $i));
            $ids[] = $id;
        }

        return $ids;
    }

    private function articles_in(int $request_index): array
    {
        return json_decode((string) $this->requests[$request_index]['args']['body'], true)['articles'];
    }

    public function test_with_token_batch_goes_through_v3_with_published_at(): void
    {
        update_option(\TribuneDesk\WordPress\CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        $ids = $this->make_posts(2);

        $result = Backfill::run_batch();

        $this->assertSame(PublishedUrlsClient::STATUS_SENT, $result['status']);
        $this->assertStringEndsWith('/v3/cms/articles/published', $this->requests[0]['url']);
        $items = json_decode((string) $this->requests[0]['args']['body'], true)['items'];
        $this->assertCount(2, $items);
        $gmt = get_post($ids[0])->post_date_gmt;
        $this->assertSame(gmdate('Y-m-d\TH:i:s+00:00', strtotime($gmt . ' UTC')), $items[0]['published_at']);
    }

    public function test_paczkuje_po_100_i_prowadzi_kursor(): void
    {
        $ids = $this->make_posts(250);

        $first = Backfill::run_batch();
        $this->assertSame(PublishedUrlsClient::STATUS_SENT, $first['status']);
        $this->assertSame(100, $first['sent']);
        $this->assertSame(150, $first['remaining']);
        $this->assertSame($ids[99], Backfill::cursor());
        $this->assertCount(100, $this->articles_in(0));
        $this->assertSame(get_permalink($ids[0]), $this->articles_in(0)[0]['url']);

        $second = Backfill::run_batch();
        $this->assertSame(100, $second['sent']);
        $this->assertSame(50, $second['remaining']);
        $this->assertSame($ids[199], Backfill::cursor());

        $third = Backfill::run_batch();
        $this->assertSame(50, $third['sent']);
        $this->assertSame(0, $third['remaining']);
        $this->assertSame(0, Backfill::cursor(), 'Po dojsciu do konca kursor wraca do 0.');

        $rows = DeliveriesLog::recent();
        $this->assertSame(['batch:50', 'batch:100', 'batch:100'], array_column(array_slice($rows, 0, 3), 'article_id'));
        $this->assertSame(DeliveriesLog::KIND_BACKFILL, $rows[0]['kind']);
        $this->assertSame('ok=50 not_found=0 forbidden=0 invalid_url=0 skipped_non_uuid=0', $rows[0]['error_msg']);
        $this->assertSame(10, $this->requests[0]['args']['timeout']);
    }

    public function test_pomija_szkice_i_wpisy_bez_uuid(): void
    {
        $this->make_posts(3);
        $this->make_posts(1, 'draft');
        $this->make_posts(1, 'publish', 'art-100');

        $result = Backfill::run_batch();

        $this->assertSame(PublishedUrlsClient::STATUS_SENT, $result['status']);
        $this->assertSame(3, $result['sent']);
        $this->assertCount(3, $this->articles_in(0));
        $this->assertSame(1, $result['counts']['skipped_non_uuid']);
        $this->assertSame(0, Backfill::cursor());
        $this->assertSame(0, $result['remaining']);
    }

    public function test_429_pauza_i_ponowienie_tej_samej_paczki(): void
    {
        $this->make_posts(5);
        $this->queue = [$this->http_error(429)];

        $result = Backfill::run_batch();

        $this->assertCount(2, $this->requests);
        $this->assertSame($this->requests[0]['args']['body'], $this->requests[1]['args']['body']);
        $this->assertSame([Backfill::PAUSE_SECONDS], $this->pauses);
        $this->assertSame(PublishedUrlsClient::STATUS_SENT, $result['status']);
        $this->assertSame(5, $result['sent']);
    }

    public function test_retry_after_ogranicza_pauze_do_maksimum(): void
    {
        $this->make_posts(1);
        $limited = $this->http_error(429);
        $limited['headers'] = ['retry-after' => '90'];
        $this->queue = [$limited];

        Backfill::run_batch();

        $this->assertSame([Backfill::PAUSE_MAX], $this->pauses);
    }

    public function test_drugie_429_nie_przesuwa_kursora(): void
    {
        $this->make_posts(150);
        $this->queue = [$this->http_error(429), $this->http_error(429)];

        $result = Backfill::run_batch();

        $this->assertSame(PublishedUrlsClient::STATUS_RATE_LIMITED, $result['status']);
        $this->assertSame(0, Backfill::cursor());
        $this->assertSame(150, $result['remaining']);
        $this->assertStringContainsString('spróbuj', (string) $result['detail']);
        $row = DeliveriesLog::recent()[0];
        $this->assertSame(PublishedUrlsClient::STATUS_RATE_LIMITED, $row['status']);
        $this->assertSame(DeliveriesLog::KIND_BACKFILL, $row['kind']);
    }

    public function test_401_nie_przesuwa_kursora_i_loguje(): void
    {
        $this->make_posts(3);
        $this->queue = [$this->http_error(401)];

        $result = Backfill::run_batch();

        $this->assertSame(PublishedUrlsClient::STATUS_UNAUTHORIZED, $result['status']);
        $this->assertSame(0, Backfill::cursor());
        $this->assertSame(3, $result['remaining']);
        $this->assertSame(PublishedUrlsClient::STATUS_UNAUTHORIZED, DeliveriesLog::recent()[0]['status']);
        $this->assertStringNotContainsString('sekret-testowy', (string) DeliveriesLog::recent()[0]['error_msg']);
    }

    public function test_brak_wpisow_nie_robi_zadania_ani_wiersza_logu(): void
    {
        $result = Backfill::run_batch();

        $this->assertSame(PublishedUrlsClient::STATUS_NOTHING_TO_SEND, $result['status']);
        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['remaining']);
        $this->assertCount(0, $this->requests);
        $this->assertSame([], DeliveriesLog::recent());
    }

    public function test_po_koncu_nastepny_przebieg_zaczyna_od_poczatku(): void
    {
        $this->make_posts(3);

        Backfill::run_batch();
        $again = Backfill::run_batch();

        $this->assertSame(3, $again['sent']);
        $this->assertCount(2, $this->requests);
    }

    public function test_remaining_liczy_od_kursora(): void
    {
        $ids = $this->make_posts(5);

        update_option(Backfill::OPTION_CURSOR, $ids[1]);

        $this->assertSame(3, Backfill::remaining());
    }

    public function test_take_result_zwraca_wynik_raz(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        set_transient(Backfill::TRANSIENT_RESULT . '_' . get_current_user_id(), ['status' => 'sent', 'sent' => 3, 'remaining' => 0, 'detail' => 'ok=3', 'counts' => []], MINUTE_IN_SECONDS);

        $first  = Backfill::take_result();
        $second = Backfill::take_result();

        $this->assertSame(3, $first['sent']);
        $this->assertNull($second);
    }

    public function test_akcja_admin_post_jest_zarejestrowana(): void
    {
        $this->assertNotFalse(has_action('admin_post_' . Backfill::ACTION, ['TribuneDesk\WordPress\Backfill', 'handle_admin_post']));
    }
}
