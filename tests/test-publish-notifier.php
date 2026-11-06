<?php
declare(strict_types=1);

use TribuneDesk\WordPress\DeliveriesLog;
use TribuneDesk\WordPress\PostWriter;
use TribuneDesk\WordPress\PublishNotifier;
use TribuneDesk\WordPress\PublishedUrlsClient;
use TribuneDesk\WordPress\RestEndpoint;
use TribuneDesk\WordPress\Activator;

class Test_Publish_Notifier extends WP_UnitTestCase
{
    private const UUID_A = '11111111-1111-4111-8111-111111111111';

    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array|WP_Error|null null = odpowiedz 200 zbudowana z body zadania */
    private $next_response = null;

    private string $item_status = 'ok';

    private int $v3_status = 200;

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        update_option(RestEndpoint::OPTION_SECRET, 'sekret-testowy');
        $this->requests      = [];
        $this->next_response = null;
        $this->item_status   = 'ok';
        $this->v3_status     = 200;
        delete_option(\TribuneDesk\WordPress\CmsApiClient::OPTION_TOKEN);
        _set_cron_array([]);
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
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
        if (str_contains($url, '/v3/') && $this->v3_status !== 200) {
            return $this->http_error($this->v3_status);
        }
        foreach (($body['articles'] ?? $body['items'] ?? []) as $article) {
            $results[] = ['article_id' => $article['article_id'], 'status' => $this->item_status];
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

    private function make_post(string $article_id, string $status = 'draft', string $type = 'post'): int
    {
        return (int) self::factory()->post->create([
            'post_type'   => $type,
            'post_status' => $status,
            'meta_input'  => [PostWriter::META_ARTICLE_ID => $article_id],
        ]);
    }

    private function last_row(): array
    {
        $rows = DeliveriesLog::recent();
        $this->assertNotEmpty($rows, 'Oczekiwano wiersza w logu dostaw.');

        return $rows[0];
    }

    public function test_with_token_publication_goes_through_v3_with_published_at(): void
    {
        update_option(\TribuneDesk\WordPress\CmsApiClient::OPTION_TOKEN, 'tdcms_testtoken');
        $id = $this->make_post(self::UUID_A);

        wp_publish_post($id);

        $this->assertCount(1, $this->requests);
        $this->assertStringEndsWith('/v3/cms/articles/published', $this->requests[0]['url']);
        $body = json_decode((string) $this->requests[0]['args']['body'], true);
        // A draft from the factory keeps post_date_gmt 0000-00-00 after wp_publish_post():
        // the date comes from post_date converted to GMT.
        $gmt = get_gmt_from_date(get_post($id)->post_date);
        $this->assertSame(
            [['article_id' => self::UUID_A, 'url' => get_permalink($id), 'published_at' => gmdate('Y-m-d\TH:i:s+00:00', strtotime($gmt . ' UTC'))]],
            $body['items']
        );
        $this->assertSame('ok', $this->last_row()['status']);
    }

    public function test_token_without_articles_write_falls_back_and_says_so_in_the_log(): void
    {
        update_option(\TribuneDesk\WordPress\CmsApiClient::OPTION_TOKEN, 'tdcms_mediaonly');
        $this->v3_status = 403;
        $id = $this->make_post(self::UUID_A);

        wp_publish_post($id);

        $this->assertCount(2, $this->requests);
        $this->assertStringEndsWith('/v2/cms/published-urls', $this->requests[1]['url']);
        $row = $this->last_row();
        $this->assertSame('ok', $row['status']);
        $this->assertStringContainsString('articles:write', (string) $row['error_msg']);
    }

    public function test_publikacja_wysyla_jedno_zadanie_i_loguje_ok(): void
    {
        $id = $this->make_post(self::UUID_A);

        wp_publish_post($id);

        $this->assertCount(1, $this->requests);
        $body = json_decode((string) $this->requests[0]['args']['body'], true);
        $this->assertSame([['article_id' => self::UUID_A, 'url' => get_permalink($id)]], $body['articles']);
        $this->assertSame(PublishNotifier::TIMEOUT, $this->requests[0]['args']['timeout']);

        $row = $this->last_row();
        $this->assertSame(DeliveriesLog::KIND_NOTIFY, $row['kind']);
        $this->assertSame('ok', $row['status']);
        $this->assertSame($id, (int) $row['post_id']);
        $this->assertSame(self::UUID_A, $row['article_id']);
        $this->assertFalse(wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$id]));
    }

    public function test_publish_immediately_przez_wp_insert_post_tez_wysyla(): void
    {
        // meta_input jest zapisywane PRZED transition_post_status (core post.php), wiec hak
        // widzi _aw_article_id juz przy tworzeniu wpisu w statusie publish.
        $id = $this->make_post(self::UUID_A, 'publish');

        $this->assertCount(1, $this->requests);
        $this->assertSame('ok', $this->last_row()['status']);
        $this->assertSame($id, (int) $this->last_row()['post_id']);
    }

    public function test_publish_do_publish_nie_wysyla(): void
    {
        $id = $this->make_post(self::UUID_A, 'publish');
        $this->requests = [];

        wp_update_post(['ID' => $id, 'post_title' => 'edycja']);

        $this->assertCount(0, $this->requests);
    }

    public function test_wpis_bez_uuid_i_inny_post_type_nie_wysylaja(): void
    {
        wp_publish_post($this->make_post('art-100'));
        wp_publish_post($this->make_post(self::UUID_A, 'draft', 'page'));
        wp_publish_post((int) self::factory()->post->create(['post_status' => 'draft']));

        $this->assertCount(0, $this->requests);
    }

    public function test_blad_sieci_loguje_error_i_planuje_jeden_retry(): void
    {
        $this->next_response = new WP_Error('http_request_failed', 'cURL error 28: timeout');
        $id = $this->make_post(self::UUID_A);

        wp_publish_post($id);

        $row = $this->last_row();
        $this->assertSame(PublishedUrlsClient::STATUS_ERROR, $row['status']);
        $this->assertStringContainsString('cURL error 28', (string) $row['error_msg']);
        $this->assertStringContainsString('ponowienie za 5 min', (string) $row['error_msg']);
        $next = wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$id]);
        $this->assertIsInt($next);
        $this->assertEqualsWithDelta(time() + PublishNotifier::RETRY_DELAY, $next, 5);
    }

    public function test_5xx_i_429_planuja_retry(): void
    {
        $this->next_response = $this->http_error(503);
        $a = $this->make_post(self::UUID_A);
        wp_publish_post($a);
        $this->assertIsInt(wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$a]));

        $this->next_response = $this->http_error(429);
        $b = $this->make_post('22222222-2222-4222-8222-222222222222');
        wp_publish_post($b);
        $this->assertIsInt(wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$b]));
        $this->assertSame(PublishedUrlsClient::STATUS_RATE_LIMITED, $this->last_row()['status']);
    }

    public function test_401_nie_planuje_retry_i_nie_loguje_sekretu(): void
    {
        $this->next_response = $this->http_error(401);
        $id = $this->make_post(self::UUID_A);

        wp_publish_post($id);

        $row = $this->last_row();
        $this->assertSame(PublishedUrlsClient::STATUS_UNAUTHORIZED, $row['status']);
        $this->assertStringContainsString('sekret nie pasuje', (string) $row['error_msg']);
        $this->assertStringNotContainsString('sekret-testowy', (string) $row['error_msg']);
        $this->assertFalse(wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$id]));
    }

    public function test_forbidden_per_pozycja_loguje_forbidden_bez_retry(): void
    {
        $this->item_status = 'forbidden';
        $id = $this->make_post(self::UUID_A);

        wp_publish_post($id);

        $row = $this->last_row();
        $this->assertSame('forbidden', $row['status']);
        $this->assertStringContainsString('nie w redakcji', (string) $row['error_msg']);
        $this->assertFalse(wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$id]));
    }

    public function test_retry_wysyla_raz_i_nie_planuje_kolejnego(): void
    {
        $this->next_response = new WP_Error('http_request_failed', 'timeout');
        $id = $this->make_post(self::UUID_A);
        wp_publish_post($id);
        _set_cron_array([]); // jakby cron wlasnie odpalil zaplanowane zdarzenie
        $this->requests = [];

        $this->next_response = null; // teraz HF odpowiada 200 ok
        PublishNotifier::retry($id);

        $this->assertCount(1, $this->requests);
        $row = $this->last_row();
        $this->assertSame('ok', $row['status']);
        $this->assertStringStartsWith('ponowienie', (string) $row['error_msg']);
        $this->assertFalse(wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$id]));
    }

    public function test_nieudany_retry_nie_planuje_trzeciej_proby(): void
    {
        $id = $this->make_post(self::UUID_A, 'publish');
        _set_cron_array([]);
        $this->next_response = new WP_Error('http_request_failed', 'timeout');

        PublishNotifier::retry($id);

        $row = $this->last_row();
        $this->assertSame(PublishedUrlsClient::STATUS_ERROR, $row['status']);
        $this->assertStringStartsWith('ponowienie:', (string) $row['error_msg']);
        $this->assertFalse(wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$id]));
    }

    public function test_retry_pomija_wpis_cofniety_do_szkicu(): void
    {
        $id = $this->make_post(self::UUID_A, 'publish');
        wp_update_post(['ID' => $id, 'post_status' => 'draft']);
        $this->requests = [];

        PublishNotifier::retry($id);

        $this->assertCount(0, $this->requests);
    }

    public function test_publikacja_b_naprawia_a_ale_callback_idzie_tylko_dla_b(): void
    {
        // Integracja z Taskiem 5: naprawa wpisu A (wp_update_post, publish -> publish) nie moze
        // wyslac drugiego callbacku — do HF idzie wylacznie adres wlasnie publikowanego B.
        $uuid_b      = '22222222-2222-4222-8222-222222222222';
        $placeholder = 'https://headlinesforge.com/app/a/' . $uuid_b;
        $link_b      = ['article_id' => $uuid_b, 'href' => $placeholder, 'anchor_text' => 'x', 'kind' => 'placeholder', 'url' => $placeholder, 'headline' => null];
        // B jako szkic PRZED publikacja A: link do celu, ktorego w WP nie ma, publikacja zdejmuje (1.1.0).
        $b = $this->make_post($uuid_b);
        $a = (int) self::factory()->post->create([
            'post_status'  => 'publish',
            'post_content' => '<p><a href="' . $placeholder . '">x</a></p>',
            'meta_input'   => [PostWriter::META_ARTICLE_ID => self::UUID_A, \TribuneDesk\WordPress\LinkResolver::META_LINKS => wp_slash([$link_b])],
        ]);
        $this->requests = [];

        wp_publish_post($b);

        $this->assertCount(1, $this->requests);
        $body = json_decode((string) $this->requests[0]['args']['body'], true);
        $this->assertSame($uuid_b, $body['articles'][0]['article_id']);
        $this->assertStringContainsString(get_permalink($b), get_post($a)->post_content);
    }

    public function test_haki_sa_zarejestrowane(): void
    {
        // 2.1.0: after the save, not on the status transition (meta from save_post must be stored).
        $this->assertSame(20, has_action('wp_after_insert_post', ['TribuneDesk\WordPress\PublishNotifier', 'on_after_insert']));
        $this->assertFalse(has_action('transition_post_status', ['TribuneDesk\WordPress\PublishNotifier', 'on_transition']));
        $this->assertNotFalse(has_action(PublishNotifier::RETRY_HOOK, ['TribuneDesk\WordPress\PublishNotifier', 'retry']));
    }

    public function test_publikacja_zbiorcza_odracza_wysylke_do_crona(): void
    {
        $id = $this->make_post(self::UUID_A);
        $_REQUEST['bulk_edit'] = 'Update';

        try {
            wp_publish_post($id);
        } finally {
            unset($_REQUEST['bulk_edit']);
        }

        $this->assertCount(0, $this->requests, 'Przy publikacji zbiorczej nie wolno wysylac synchronicznie.');
        $next = wp_next_scheduled(PublishNotifier::RETRY_HOOK, [$id]);
        $this->assertIsInt($next);
        $this->assertEqualsWithDelta(time() + PublishNotifier::BULK_DELAY, $next, 5);
        $this->assertSame([], DeliveriesLog::recent(), 'Odroczenie samo w sobie nie tworzy wiersza logu.');
    }

    public function test_odroczona_wysylka_z_crona_dochodzi_normalnie(): void
    {
        $id = $this->make_post(self::UUID_A);
        $_REQUEST['bulk_edit'] = 'Update';
        try {
            wp_publish_post($id);
        } finally {
            unset($_REQUEST['bulk_edit']);
        }
        _set_cron_array([]);

        PublishNotifier::retry($id);

        $this->assertCount(1, $this->requests);
        $this->assertSame('ok', $this->last_row()['status']);
    }
}
