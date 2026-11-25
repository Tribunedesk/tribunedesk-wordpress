<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\RestEndpoint;

/**
 * Publication mode of a delivery (contract 3.7.0-draft, headlinesforge spec 2026-11-24 D4, D5):
 * `publication` makes a draft, a post scheduled for `at` or a published post; a scheduled post
 * takes a new send like a draft; a published post is never changed by the field.
 */
class Test_PublicationMode extends WP_UnitTestCase
{
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
        update_option('timezone_string', 'UTC');
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        delete_option(RestEndpoint::OPTION_PUBLISH);
        parent::tearDown();
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

    private int $sends = 0;

    /** @return array<string, mixed> the answer */
    private function deliver(string $article_id, ?array $publication, string $title = 'Tytuł'): array
    {
        $this->sends++;
        $body = [
            'article_id' => $article_id,
            'sent_at'    => gmdate('Y-m-d\TH:i:s+00:00', 1_700_000_000 + $this->sends),
            'title'      => $title,
            'html'       => '<p>' . $title . '</p>',
        ];
        if ($publication !== null) {
            $body['publication'] = $publication;
        }
        $req = new WP_REST_Request('POST', '/' . RestEndpoint::NAMESPACE . RestEndpoint::ROUTE);
        $req->set_body((string) json_encode($body));
        $req->set_header('Content-Type', 'application/json');
        tribune_desk_tests_sign($req, TRIBUNE_DESK_TESTS_SIGNING_SECRET);
        $response = rest_do_request($req);
        $this->assertSame(200, $response->get_status(), (string) json_encode($response->get_data()));

        return $response->get_data();
    }

    private static function moment(int $days): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() + $days * 86_400);
    }

    private function post_of(array $answer): WP_Post
    {
        $post = get_post((int) $answer['cms_post_id']);
        $this->assertInstanceOf(WP_Post::class, $post);

        return $post;
    }

    public function test_schedule_makes_a_future_post_at_the_utc_time(): void
    {
        $at = self::moment(2);
        $answer = $this->deliver(wp_generate_uuid4(), ['mode' => 'schedule', 'at' => $at]);
        $post = $this->post_of($answer);
        $this->assertSame('future', $post->post_status);
        $this->assertSame(gmdate('Y-m-d H:i:s', strtotime($at)), $post->post_date_gmt);
        $this->assertSame(['status' => 'scheduled', 'at' => $at], $answer['publication']);
        $this->assertArrayNotHasKey('permalink', $answer);
    }

    public function test_post_date_is_in_the_site_time_zone(): void
    {
        update_option('timezone_string', 'Europe/Warsaw');
        $at = '2027-07-01T06:00:00Z'; // 08:00 in Warsaw (summer time)
        if (strtotime($at) <= time()) {
            $this->markTestSkipped('the fixed date has passed');
        }
        $post = $this->post_of($this->deliver(wp_generate_uuid4(), ['mode' => 'schedule', 'at' => $at]));
        $this->assertSame('2027-07-01 06:00:00', $post->post_date_gmt);
        $this->assertSame('2027-07-01 08:00:00', $post->post_date);
    }

    public function test_a_time_already_past_is_published_at_once(): void
    {
        $answer = $this->deliver(wp_generate_uuid4(), ['mode' => 'schedule', 'at' => self::moment(-1)]);
        $this->assertSame('publish', $this->post_of($answer)->post_status);
        $this->assertSame('published', $answer['publication']['status']);
        $this->assertArrayHasKey('permalink', $answer);
    }

    public function test_draft_and_publish(): void
    {
        update_option(RestEndpoint::OPTION_PUBLISH, '1');
        $draft = $this->deliver(wp_generate_uuid4(), ['mode' => 'draft']);
        $this->assertSame('draft', $this->post_of($draft)->post_status);
        $this->assertSame(['status' => 'draft', 'at' => null], $draft['publication']);
        delete_option(RestEndpoint::OPTION_PUBLISH);
        $published = $this->deliver(wp_generate_uuid4(), ['mode' => 'publish']);
        $this->assertSame('publish', $this->post_of($published)->post_status);
        $this->assertSame('published', $published['publication']['status']);
    }

    public function test_without_the_field_the_site_option_decides_a_new_post(): void
    {
        update_option(RestEndpoint::OPTION_PUBLISH, '1');
        $this->assertSame('publish', $this->post_of($this->deliver(wp_generate_uuid4(), null))->post_status);
        delete_option(RestEndpoint::OPTION_PUBLISH);
        $answer = $this->deliver(wp_generate_uuid4(), null);
        $this->assertSame('draft', $this->post_of($answer)->post_status);
        $this->assertSame(['status' => 'draft', 'at' => null], $answer['publication']);
    }

    public function test_a_scheduled_post_takes_a_new_send_like_a_draft(): void
    {
        $article_id = wp_generate_uuid4();
        $at = self::moment(2);
        $first = $this->deliver($article_id, ['mode' => 'schedule', 'at' => $at], 'Pierwszy');
        // A send without the field: new content, same time.
        $again = $this->deliver($article_id, null, 'Drugi');
        $post = $this->post_of($again);
        $this->assertSame($first['cms_post_id'], $again['cms_post_id']);
        $this->assertSame('updated', $again['status']);
        $this->assertSame(['Drugi', 'future'], [$post->post_title, $post->post_status]);
        $this->assertSame(gmdate('Y-m-d H:i:s', strtotime($at)), $post->post_date_gmt);
        // A new time.
        $later = self::moment(3);
        $moved = $this->deliver($article_id, ['mode' => 'schedule', 'at' => $later], 'Trzeci');
        $this->assertSame(gmdate('Y-m-d H:i:s', strtotime($later)), $this->post_of($moved)->post_date_gmt);
        $this->assertSame(['status' => 'scheduled', 'at' => $later], $moved['publication']);
        // Back to a draft: no date of its own any more.
        $draft = $this->deliver($article_id, ['mode' => 'draft']);
        $post = $this->post_of($draft);
        $this->assertSame(['draft', '0000-00-00 00:00:00'], [$post->post_status, $post->post_date_gmt]);
        $this->assertSame(['status' => 'draft', 'at' => null], $draft['publication']);
    }

    public function test_a_published_post_is_never_unpublished(): void
    {
        $article_id = wp_generate_uuid4();
        $this->deliver($article_id, ['mode' => 'publish'], 'Opublikowany');
        foreach ([['mode' => 'schedule', 'at' => self::moment(2)], ['mode' => 'draft']] as $publication) {
            $answer = $this->deliver($article_id, $publication, 'Zmieniony');
            $post = $this->post_of($answer);
            $this->assertSame('skipped_already_published', $answer['status']);
            $this->assertSame(['Opublikowany', 'publish'], [$post->post_title, $post->post_status]);
            $this->assertSame('published', $answer['publication']['status']);
        }
    }
}
