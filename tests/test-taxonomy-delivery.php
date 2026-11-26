<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\RestEndpoint;

/**
 * Categories and tags of a delivery (contract 3.8.0-draft, headlinesforge spec 2026-11-25 D2, D5):
 * a term is found by id, then by name; one WordPress does not have is never created and goes to
 * `ignored`; a missing key leaves the taxonomy alone; a published post keeps its terms; the answer
 * carries the terms on the post after the save.
 */
class Test_TaxonomyDelivery extends WP_UnitTestCase
{
    private int $sends = 0;
    private int $created_terms = 0;
    private int $news;
    private int $sport;
    private int $budget;
    private int $theatre;

    public function setUp(): void
    {
        parent::setUp();
        $author = self::factory()->user->create(['role' => 'editor']);
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, TRIBUNE_DESK_TESTS_SIGNING_SECRET);
        update_option(RestEndpoint::OPTION_DEFAULT_AUTHOR, $author);
        update_option(Activator::OPTION_INSTALL_USER, $author);
        Activator::activate();
        do_action('rest_api_init');
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);

        $this->news    = self::factory()->category->create(['name' => 'Newsy']);
        $this->sport   = self::factory()->category->create(['name' => 'Sport']);
        $this->budget  = self::factory()->tag->create(['name' => 'Budżet miasta']);
        $this->theatre = self::factory()->tag->create(['name' => 'Teatr']);
        add_action('created_term', [$this, 'count_created_term']);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        remove_action('created_term', [$this, 'count_created_term']);
        parent::tearDown();
    }

    public function count_created_term(): void
    {
        $this->created_terms++;
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_http($preempt, array $args, string $url)
    {
        return [
            'headers'  => [],
            'body'     => wp_json_encode(['results' => []]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * @param array<string, mixed>|null $taxonomy null = no `taxonomy` in the delivery
     * @return array<string, mixed> the answer
     */
    private function deliver(string $article_id, ?array $taxonomy, int $sent_offset = 0): array
    {
        $this->sends++;
        $body = [
            'article_id' => $article_id,
            'sent_at'    => gmdate('Y-m-d\TH:i:s+00:00', 1_700_000_000 + ($sent_offset !== 0 ? $sent_offset : $this->sends * 10)),
            'title'      => 'Tytuł ' . $this->sends,
            'html'       => '<p>Treść ' . $this->sends . '</p>',
        ];
        if ($taxonomy !== null) {
            $body['taxonomy'] = $taxonomy;
        }
        $req = new WP_REST_Request('POST', '/' . RestEndpoint::NAMESPACE . RestEndpoint::ROUTE);
        $req->set_body((string) json_encode($body));
        $req->set_header('Content-Type', 'application/json');
        tribune_desk_tests_sign($req, TRIBUNE_DESK_TESTS_SIGNING_SECRET);
        $response = rest_do_request($req);
        $this->assertSame(200, $response->get_status(), (string) json_encode($response->get_data()));

        return $response->get_data();
    }

    /** @return list<int> */
    private static function ids(int $post_id, string $taxonomy): array
    {
        $ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        $ids = array_map('intval', is_array($ids) ? $ids : []);
        sort($ids);

        return $ids;
    }

    private function term_count(): int
    {
        return (int) wp_count_terms(['taxonomy' => ['category', 'post_tag'], 'hide_empty' => false]);
    }

    private static function ref(int $id, string $name): array
    {
        return ['cms_term_id' => (string) $id, 'name' => $name];
    }

    public function test_new_post_gets_category_and_tags_by_id(): void
    {
        // Names deliberately stale: the id wins (a term renamed after the sync).
        $answer = $this->deliver('art-tax-1', [
            'categories' => [self::ref($this->sport, 'Sport stary')],
            'tags'       => [self::ref($this->budget, 'Budzet'), self::ref($this->theatre, 'Teatr')],
        ]);
        $post_id = (int) $answer['cms_post_id'];

        $this->assertSame('created', $answer['status']);
        $this->assertSame([$this->sport], self::ids($post_id, 'category'));
        $this->assertSame([$this->budget, $this->theatre], self::ids($post_id, 'post_tag'));
        $this->assertSame([self::ref($this->sport, 'Sport')], $answer['taxonomy']['categories']);
        $tags = $answer['taxonomy']['tags'];
        usort($tags, static fn (array $a, array $b): int => (int) $a['cms_term_id'] <=> (int) $b['cms_term_id']);
        $this->assertSame([self::ref($this->budget, 'Budżet miasta'), self::ref($this->theatre, 'Teatr')], $tags);
        $this->assertSame([], $answer['taxonomy']['ignored']);
    }

    public function test_new_post_finds_terms_by_name_ignoring_case(): void
    {
        $answer = $this->deliver('art-tax-2', [
            'categories' => [['name' => 'newsy']],
            // An id WordPress does not know falls back to the name.
            'tags'       => [['cms_term_id' => '999999', 'name' => 'BUDŻET MIASTA']],
        ]);
        $post_id = (int) $answer['cms_post_id'];

        $this->assertSame([$this->news], self::ids($post_id, 'category'));
        $this->assertSame([$this->budget], self::ids($post_id, 'post_tag'));
        $this->assertSame([], $answer['taxonomy']['ignored']);
    }

    public function test_unknown_term_is_ignored_and_never_created(): void
    {
        $before = $this->term_count();

        $answer = $this->deliver('art-tax-3', [
            'categories' => [['name' => 'Nie ma takiej']],
            'tags'       => [
                ['name' => 'Halinka Mlynkova'],
                // A category's name and id among the tags: it exists, but in the other taxonomy.
                self::ref($this->sport, 'Sport'),
                self::ref($this->theatre, 'Teatr'),
            ],
        ]);
        $post_id = (int) $answer['cms_post_id'];

        $this->assertSame($before, $this->term_count(), 'no term was created');
        $this->assertSame(0, $this->created_terms, 'created_term never fired');
        $this->assertSame([$this->theatre], self::ids($post_id, 'post_tag'));
        // All categories unknown: WordPress keeps the default category.
        $this->assertSame([(int) get_option('default_category')], self::ids($post_id, 'category'));
        $this->assertSame([
            ['name' => 'Nie ma takiej', 'reason' => 'unknown_term'],
            ['name' => 'Halinka Mlynkova', 'reason' => 'unknown_term'],
            ['name' => 'Sport', 'reason' => 'wrong_taxonomy'],
        ], $answer['taxonomy']['ignored']);
    }

    /** Review Focus 1: a re-send without `taxonomy` keeps the terms an editor set by hand. */
    public function test_draft_resend_without_taxonomy_keeps_manual_terms(): void
    {
        $first   = $this->deliver('art-tax-4', null);
        $post_id = (int) $first['cms_post_id'];
        $this->assertArrayNotHasKey('taxonomy', $first, 'no taxonomy in the delivery, none in the answer');
        wp_set_post_terms($post_id, [$this->theatre], 'post_tag');
        wp_set_post_categories($post_id, [$this->news]);

        $again = $this->deliver('art-tax-4', null);

        $this->assertSame('updated', $again['status']);
        $this->assertSame('Tytuł 2', get_post($post_id)->post_title, 'the content did follow the send');
        $this->assertSame([$this->theatre], self::ids($post_id, 'post_tag'));
        $this->assertSame([$this->news], self::ids($post_id, 'category'));
        $this->assertArrayNotHasKey('taxonomy', $again);
    }

    public function test_empty_tags_clear_tags_and_missing_categories_stay(): void
    {
        $first   = $this->deliver('art-tax-5', [
            'categories' => [self::ref($this->news, 'Newsy')],
            'tags'       => [self::ref($this->theatre, 'Teatr')],
        ]);
        $post_id = (int) $first['cms_post_id'];

        $again = $this->deliver('art-tax-5', ['tags' => []]);

        $this->assertSame([], self::ids($post_id, 'post_tag'));
        $this->assertSame([$this->news], self::ids($post_id, 'category'));
        $this->assertSame([], $again['taxonomy']['tags']);
        $this->assertSame([self::ref($this->news, 'Newsy')], $again['taxonomy']['categories']);
    }

    public function test_empty_categories_fall_back_to_default_category(): void
    {
        $first   = $this->deliver('art-tax-6', ['categories' => [self::ref($this->news, 'Newsy')]]);
        $post_id = (int) $first['cms_post_id'];

        $this->deliver('art-tax-6', ['categories' => []]);

        $this->assertSame([(int) get_option('default_category')], self::ids($post_id, 'category'));
    }

    public function test_published_post_keeps_terms_and_answers_its_state(): void
    {
        $first   = $this->deliver('art-tax-7', [
            'categories' => [self::ref($this->news, 'Newsy')],
            'tags'       => [self::ref($this->theatre, 'Teatr')],
        ]);
        $post_id = (int) $first['cms_post_id'];
        wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);

        $answer = $this->deliver('art-tax-7', [
            'categories' => [self::ref($this->sport, 'Sport')],
            'tags'       => [],
        ]);

        $this->assertSame('skipped_already_published', $answer['status']);
        $this->assertSame([$this->news], self::ids($post_id, 'category'));
        $this->assertSame([$this->theatre], self::ids($post_id, 'post_tag'));
        $this->assertSame([
            'categories' => [self::ref($this->news, 'Newsy')],
            'tags'       => [self::ref($this->theatre, 'Teatr')],
            'ignored'    => [],
        ], $answer['taxonomy']);
    }

    public function test_older_send_changes_nothing(): void
    {
        $first   = $this->deliver('art-tax-8', ['tags' => [self::ref($this->theatre, 'Teatr')]], 1000);
        $post_id = (int) $first['cms_post_id'];

        $answer = $this->deliver('art-tax-8', ['tags' => [self::ref($this->budget, 'Budżet miasta')]], 500);

        $this->assertSame([$this->theatre], self::ids($post_id, 'post_tag'));
        $this->assertSame([self::ref($this->theatre, 'Teatr')], $answer['taxonomy']['tags']);
        $this->assertSame([], $answer['taxonomy']['ignored']);
    }
}
