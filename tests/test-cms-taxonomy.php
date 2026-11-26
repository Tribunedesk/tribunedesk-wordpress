<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsSync;
use TribuneDesk\WordPress\CmsTaxonomy;
use TribuneDesk\WordPress\DevCommands;
use TribuneDesk\WordPress\MediaPush;
use TribuneDesk\WordPress\PublishedUrlsClient;

/** Categories and tags to Tribune Desk: the daily full run and the queue of single changes. */
class Test_Cms_Taxonomy extends WP_UnitTestCase
{
    private const ROUTE = '/v3/cms/taxonomy/terms';

    /** @var list<array{method: string, path: string, body: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<string, array{0: int, 1: array|string}|\WP_Error> */
    private array $routes = [];

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        update_option(CmsApiClient::OPTION_TOKEN, 'tok-testowy');
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        update_option(MediaPush::OPTION_FLOOR, PHP_INT_MAX);
        update_option(CmsSync::OPTION_CURSOR, 'c0');
        foreach ([CmsTaxonomy::OPTION_RUN, CmsTaxonomy::OPTION_QUEUE, CmsTaxonomy::OPTION_LOCK, CmsSync::OPTION_LOCK, CmsSync::OPTION_LAST_RUN] as $option) {
            delete_option($option);
        }
        delete_transient(CmsSync::TRANSIENT_PAUSE);
        delete_transient(CmsSync::TRANSIENT_ACTIVE);
        wp_clear_scheduled_hook(CmsSync::HOOK_SEND);
        wp_clear_scheduled_hook(CmsTaxonomy::HOOK_FULL);
        $this->requests = [];
        $this->routes   = ['GET /v3/cms/status' => [200, ['state' => 'active', 'recent_errors' => []]]];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        remove_all_filters('tribune_desk_cms_taxonomy_budget');
        parent::tear_down();
    }

    /** @return array<string, mixed>|\WP_Error */
    public function fake_http($pre, array $args, string $url)
    {
        $path             = (string) wp_parse_url($url, PHP_URL_PATH);
        $body             = json_decode((string) ($args['body'] ?? '[]'), true) ?: [];
        $this->requests[] = ['method' => $args['method'], 'path' => $path, 'body' => $body];
        $reply            = $this->routes[$args['method'] . ' ' . $path] ?? null;
        if ($reply instanceof \WP_Error) {
            return $reply;
        }
        if ($reply === null) {
            $reply = match (true) {
                $path === self::ROUTE            => [200, ['results' => array_map(static fn ($i) => ['id' => $i['cms_term_id'], 'status' => 'ok'], $body['items'])]],
                str_ends_with($path, '/changes') => [200, ['changes' => [], 'next_cursor' => 'c1', 'has_more' => false]],
                default                          => [404, ['code' => 'not_found', 'message' => 'x']],
            };
        }
        [$code, $content] = $reply;

        return ['response' => ['code' => $code, 'message' => ''], 'body' => is_string($content) ? $content : wp_json_encode($content), 'headers' => [], 'cookies' => []];
    }

    /** @return list<array<string, mixed>> request bodies sent to the taxonomy route */
    private function pages(): array
    {
        return array_values(array_map(
            static fn ($r) => $r['body'],
            array_filter($this->requests, static fn ($r) => $r['path'] === self::ROUTE)
        ));
    }

    /** Tags straight into the tables: thousands through wp_insert_term take too long, and no hook fires. */
    private function bulk_tags(int $count): void
    {
        global $wpdb;
        $start = (int) $wpdb->get_var("SELECT COALESCE(MAX(term_id), 0) FROM {$wpdb->terms}");
        $rows  = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = $wpdb->prepare('(%s, %s, 0)', "Tag $i", "tag-$start-$i");
        }
        $wpdb->query("INSERT INTO {$wpdb->terms} (name, slug, term_group) VALUES " . implode(',', $rows));
        $ids = $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->terms} WHERE term_id > %d", $start));
        $tt  = array_map(static fn ($id) => $wpdb->prepare("(%d, 'post_tag', '', 0, 0)", $id), $ids);
        $wpdb->query("INSERT INTO {$wpdb->term_taxonomy} (term_id, taxonomy, description, parent, count) VALUES " . implode(',', $tt));
    }

    private function terms_in_wp(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('category', 'post_tag')");
    }

    public function test_full_run_pages_by_1000_and_marks_the_last(): void
    {
        $this->bulk_tags(2500);

        CmsTaxonomy::run_full();

        $pages = $this->pages();
        $this->assertCount(3, $pages);
        $this->assertSame([1000, 1000, $this->terms_in_wp() - 2000], array_map(static fn ($p) => count($p['items']), $pages));
        $this->assertSame([false, false, true], array_column($pages, 'is_last'));
        $this->assertCount(1, array_unique(array_column($pages, 'run_id')));
        $this->assertTrue(wp_is_uuid($pages[0]['run_id'], 4));

        $ids = array_map('intval', array_column(array_merge(...array_column($pages, 'items')), 'cms_term_id'));
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'in order of term_id');
        $this->assertCount(count($ids), array_unique($ids));

        $run = get_option(CmsTaxonomy::OPTION_RUN);
        $this->assertSame($pages[0]['run_id'], $run['run_id']);
        $this->assertGreaterThan(0, $run['finished_at']);
        $this->assertFalse(wp_next_scheduled(CmsTaxonomy::HOOK_FULL));
    }

    public function test_items_carry_plain_names_parents_and_counts(): void
    {
        $parent = self::factory()->category->create(['name' => 'Sport']);
        $child  = self::factory()->category->create(['name' => 'Piłka & siatka', 'parent' => $parent]);
        $tag    = self::factory()->tag->create(['name' => 'Łódź']);
        self::factory()->post->create(['tags_input' => ['Łódź']]);

        CmsTaxonomy::run_full();

        $items = [];
        foreach ($this->pages()[0]['items'] as $item) {
            $items[$item['cms_term_id']] = $item;
        }
        $this->assertSame('category', $items[(string) $child]['taxonomy']);
        $this->assertSame('Piłka & siatka', $items[(string) $child]['name']);
        $this->assertSame((string) $parent, $items[(string) $child]['parent_id']);
        $this->assertNull($items[(string) $parent]['parent_id']);
        $this->assertSame('tag', $items[(string) $tag]['taxonomy']);
        $this->assertSame('Łódź', $items[(string) $tag]['name']);
        $this->assertSame(1, $items[(string) $tag]['post_count']);
        $this->assertArrayNotHasKey('parent_id', $items[(string) $tag]);
    }

    public function test_interrupted_run_continues_from_last_term_id(): void
    {
        $this->bulk_tags(1500);
        add_filter('tribune_desk_cms_taxonomy_budget', static fn () => 0);

        CmsTaxonomy::run_full();

        $first = $this->pages();
        $this->assertCount(1, $first, 'budget 0: one page, then a break');
        $this->assertFalse($first[0]['is_last']);
        $this->assertNotFalse(wp_next_scheduled(CmsTaxonomy::HOOK_FULL));
        $last = (int) end($first[0]['items'])['cms_term_id'];
        $this->assertSame($last, get_option(CmsTaxonomy::OPTION_RUN)['last_term_id']);

        remove_all_filters('tribune_desk_cms_taxonomy_budget');
        $this->requests = [];
        CmsTaxonomy::run_full();

        $second = $this->pages();
        $this->assertCount(1, $second);
        $this->assertSame($first[0]['run_id'], $second[0]['run_id']);
        $this->assertTrue($second[0]['is_last']);
        $this->assertSame($last + 1, (int) $second[0]['items'][0]['cms_term_id']);
        $this->assertSame($this->terms_in_wp(), count($first[0]['items']) + count($second[0]['items']));
    }

    public function test_finished_run_is_fresh_for_23_hours(): void
    {
        CmsTaxonomy::run_full();
        $this->assertCount(1, $this->pages());

        CmsTaxonomy::run_full();
        $this->assertCount(1, $this->pages(), 'fresh: nothing sent');

        $run                = get_option(CmsTaxonomy::OPTION_RUN);
        $run['finished_at'] = time() - CmsTaxonomy::FRESH;
        update_option(CmsTaxonomy::OPTION_RUN, $run, false);
        CmsTaxonomy::run_full();

        $pages = $this->pages();
        $this->assertCount(2, $pages);
        $this->assertNotSame($pages[0]['run_id'], $pages[1]['run_id']);
    }

    public function test_conflict_abandons_the_run_and_the_next_starts_over(): void
    {
        $this->routes['POST ' . self::ROUTE] = [409, ['code' => 'state_conflict', 'message' => 'x']];
        CmsTaxonomy::run_full();
        $stale = $this->pages()[0]['run_id'];

        unset($this->routes['POST ' . self::ROUTE]);
        CmsTaxonomy::run_full();

        $pages = $this->pages();
        $this->assertCount(2, $pages);
        $this->assertNotSame($stale, $pages[1]['run_id']);
        $this->assertSame($pages[0]['items'][0]['cms_term_id'], $pages[1]['items'][0]['cms_term_id'], 'from zero');
        $this->assertTrue($pages[1]['is_last']);
        $this->assertGreaterThan(0, get_option(CmsTaxonomy::OPTION_RUN)['finished_at']);
    }

    public function test_lock_keeps_one_full_run_at_a_time(): void
    {
        $this->assertTrue(CmsSync::acquire_lock(CmsTaxonomy::OPTION_LOCK));
        try {
            CmsTaxonomy::run_full();
            $this->assertNull(CmsTaxonomy::sync_now());
        } finally {
            CmsSync::release_lock(CmsTaxonomy::OPTION_LOCK);
        }
        $this->assertSame([], $this->pages());
    }

    public function test_without_token_nothing_runs(): void
    {
        delete_option(CmsApiClient::OPTION_TOKEN);
        CmsTaxonomy::run_full();
        self::factory()->tag->create(['name' => 'Bez tokena']);

        $this->assertSame([], $this->pages());
        $this->assertSame([], CmsTaxonomy::queue());
    }

    public function test_sync_now_runs_a_whole_new_run_regardless_of_freshness(): void
    {
        $this->bulk_tags(1200);
        add_filter('tribune_desk_cms_taxonomy_budget', static fn () => 0);
        CmsTaxonomy::run_full();
        $open = $this->pages()[0]['run_id'];
        $this->requests = [];

        DevCommands::taxonomySync();

        $pages = $this->pages();
        $this->assertCount(2, $pages, 'no time budget');
        $this->assertNotSame($open, $pages[0]['run_id']);
        $this->assertSame([false, true], array_column($pages, 'is_last'));

        $this->requests = [];
        DevCommands::taxonomySync();
        $this->assertCount(2, $this->pages(), 'no freshness rule');
    }

    public function test_sync_now_fails_loudly(): void
    {
        $this->routes['POST ' . self::ROUTE] = [501, ['code' => 'not_implemented', 'message' => 'x']];

        $this->expectException(\RuntimeException::class);
        DevCommands::taxonomySync();
    }

    public function test_first_full_run_is_planned_at_once(): void
    {
        CmsTaxonomy::ensure_scheduled();
        $this->assertLessThanOrEqual(time(), wp_next_scheduled(CmsTaxonomy::HOOK_FULL));

        wp_clear_scheduled_hook(CmsTaxonomy::HOOK_FULL);
        update_option(CmsTaxonomy::OPTION_RUN, ['run_id' => wp_generate_uuid4(), 'last_term_id' => 0, 'started_at' => time(), 'finished_at' => time()], false);
        CmsTaxonomy::ensure_scheduled();
        $this->assertGreaterThan(time() + HOUR_IN_SECONDS, wp_next_scheduled(CmsTaxonomy::HOOK_FULL));
    }

    public function test_daily_event_planned_before_the_token_runs_now_once_it_exists(): void
    {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', CmsTaxonomy::HOOK_FULL);
        CmsTaxonomy::ensure_scheduled();
        $this->assertLessThanOrEqual(time(), wp_next_scheduled(CmsTaxonomy::HOOK_FULL));
    }

    public function test_created_term_queues_only_categories_and_tags(): void
    {
        $tag = self::factory()->tag->create(['name' => 'Nowy tag']);
        $menu = wp_insert_term('Menu', 'nav_menu');

        $this->assertSame(["post_tag:$tag"], array_keys(CmsTaxonomy::queue()));
        $this->assertIsArray($menu);
        $this->assertSame([], $this->requests, 'the hook sends nothing itself');
        $this->assertNotFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
    }

    public function test_queue_sends_the_current_term_without_run_id(): void
    {
        $category = self::factory()->category->create(['name' => 'Stara nazwa']);
        wp_update_term($category, 'category', ['name' => 'Nowa nazwa']);
        $this->assertSame(["category:$category"], array_keys(CmsTaxonomy::queue()));

        CmsSync::run_send();

        $pages = $this->pages();
        $this->assertCount(1, $pages);
        $this->assertArrayNotHasKey('run_id', $pages[0]);
        $this->assertSame([['taxonomy' => 'category', 'cms_term_id' => (string) $category, 'name' => 'Nowa nazwa', 'slug' => 'stara-nazwa', 'post_count' => 0, 'parent_id' => null]], $pages[0]['items']);
        $this->assertSame([], CmsTaxonomy::queue());
        $this->assertSame('', CmsSync::last_runs()['send']['error']);
    }

    public function test_delete_term_sends_deleted(): void
    {
        $tag = self::factory()->tag->create(['name' => 'Do usunięcia']);
        delete_option(CmsTaxonomy::OPTION_QUEUE);

        wp_delete_term($tag, 'post_tag');
        CmsSync::run_send();

        $pages = $this->pages();
        $this->assertCount(1, $pages);
        $this->assertSame([['taxonomy' => 'tag', 'cms_term_id' => (string) $tag, 'name' => 'Do usunięcia', 'deleted' => true]], $pages[0]['items']);
        $this->assertSame([], CmsTaxonomy::queue());
    }

    public function test_only_rows_with_a_result_leave_the_queue(): void
    {
        $a = self::factory()->tag->create(['name' => 'A']);
        $b = self::factory()->tag->create(['name' => 'B']);
        $this->routes['POST ' . self::ROUTE] = [200, ['results' => [['id' => (string) $a, 'status' => 'ok']]]];

        CmsSync::run_send();

        $this->assertSame(["post_tag:$b"], array_keys(CmsTaxonomy::queue()));
    }

    public function test_not_implemented_is_no_error_and_keeps_the_queue(): void
    {
        $tag = self::factory()->tag->create(['name' => 'Czeka']);
        $this->routes['POST ' . self::ROUTE] = [501, ['code' => 'not_implemented', 'message' => 'x']];

        CmsSync::run_send();

        $this->assertSame('', CmsSync::last_runs()['send']['error']);
        $this->assertSame(["post_tag:$tag"], array_keys(CmsTaxonomy::queue()));
    }

    public function test_forbidden_is_no_error(): void
    {
        self::factory()->tag->create(['name' => 'Bez zakresu']);
        $this->routes['POST ' . self::ROUTE] = [403, ['code' => 'forbidden', 'message' => 'x']];

        CmsSync::run_send();

        $this->assertSame('', CmsSync::last_runs()['send']['error']);
    }

    public function test_server_error_is_recorded_and_retried(): void
    {
        self::factory()->tag->create(['name' => 'Awaria']);
        $this->routes['POST ' . self::ROUTE] = [500, ['code' => 'internal_error', 'message' => 'x']];

        CmsSync::run_send();

        $this->assertNotSame('', CmsSync::last_runs()['send']['error']);
        $this->assertCount(1, CmsTaxonomy::queue());
        $this->assertNotFalse(wp_next_scheduled(CmsSync::HOOK_SEND));
    }

    public function test_inspect_lists_category_and_tag_names(): void
    {
        $post = self::factory()->post->create(['tags_input' => ['Łódź', 'A & B']]);
        update_post_meta($post, \TribuneDesk\WordPress\PostWriter::META_ARTICLE_ID, 'art-tax');

        $inspected = DevCommands::inspect('art-tax');
        $tags      = $inspected['tags'];
        sort($tags);
        $this->assertSame(['A & B', 'Łódź'], $tags);
        $this->assertSame(['Uncategorized'], $inspected['categories']);
    }
}
