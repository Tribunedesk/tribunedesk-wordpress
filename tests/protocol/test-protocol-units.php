<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TribuneDesk\Protocol\ApiErrors;
use TribuneDesk\Protocol\AuthorOrder;
use TribuneDesk\Protocol\BacklinkSources;
use TribuneDesk\Protocol\ChangesFeed;
use TribuneDesk\Protocol\DeliveryAnswer;
use TribuneDesk\Protocol\DraftRules;
use TribuneDesk\Protocol\ImageHostRule;
use TribuneDesk\Protocol\LinkBack;
use TribuneDesk\Protocol\PlaceholderLinks;
use TribuneDesk\Protocol\Resync;

/**
 * The portable rules without WordPress: plain PHPUnit, no database, no hooks. The WordPress
 * tests of each adapter keep pinning the same behaviour end to end.
 */
class Test_Protocol_Units extends TestCase
{
    private const UUID = '514e9e08-c003-42b7-81e8-79f5167ec833';

    public function test_draft_rules(): void
    {
        $this->assertSame(DraftRules::CREATE, DraftRules::action(null));
        // 2.2.2: a post nobody published yet (waiting for review, an autosave) takes a new send.
        foreach (['draft', 'pending', 'auto-draft'] as $status) {
            $this->assertSame(DraftRules::UPDATE, DraftRules::action($status), $status);
        }
        foreach (['publish', 'future', 'private'] as $status) {
            $this->assertSame(DraftRules::SKIP, DraftRules::action($status), $status);
        }
        $this->assertSame('T', DraftRules::title(['title' => 'T', 'topic' => 'X']));
        $this->assertSame('X', DraftRules::title(['topic' => 'X']));
        $this->assertTrue(DraftRules::fills_excerpt('new', '  '));
        $this->assertFalse(DraftRules::fills_excerpt('new', 'editor wrote this'));
        $this->assertFalse(DraftRules::fills_excerpt('', ''));
    }

    public function test_delivery_answer(): void
    {
        $draft = DeliveryAnswer::build(12, 'created', 'https://cms.example.com/edit/12', null, null);
        $this->assertSame(['cms_post_id' => '12', 'post_id' => 12, 'edit_url' => 'https://cms.example.com/edit/12', 'status' => 'created'], $draft);
        $public = DeliveryAnswer::build(12, 'updated', null, 'https://example.com/a/', ['asset_id' => 'x', 'attachment_id' => 3, 'url' => 'https://example.com/f.jpg']);
        $this->assertSame('https://example.com/a/', $public['permalink']);
        $this->assertSame(3, $public['featured_image']['attachment_id']);
        $this->assertSame(['error' => 'internal_error'], DeliveryAnswer::internal_error());
    }

    public function test_author_order(): void
    {
        $payload = ['author' => ['id' => 'a', 'email' => 'a@example.com'], 'sent_by' => ['id' => 's', 'name' => ' S '], 'other' => ['id' => 'x']];
        $people  = AuthorOrder::people($payload);
        $this->assertSame(['s', 'a'], array_column($people, 'id'));
        $this->assertSame('S', $people[0]['name']);
        $this->assertSame(7, AuthorOrder::choose($people, static fn(array $p): ?int => $p['id'] === 'a' ? 7 : null, 1));
        $this->assertSame(1, AuthorOrder::choose($people, static fn(array $p): ?int => null, 1));
        $this->assertSame([], AuthorOrder::people(['sent_by' => ['id' => '']]));
    }

    public function test_backlink_sources(): void
    {
        $this->assertNull(BacklinkSources::from_payload([]));
        $this->assertSame([], BacklinkSources::from_payload(['backlink_sources' => []]));
        $list = BacklinkSources::from_payload(['backlink_sources' => [
            ['article_id' => strtoupper(self::UUID), 'distance' => 0.2],
            ['article_id' => self::UUID, 'distance' => 0.9],
            ['article_id' => 'not-a-uuid'],
            'junk',
        ]]);
        $this->assertSame([['article_id' => self::UUID, 'distance' => 0.2]], $list);
        $this->assertTrue(BacklinkSources::evergreen(['evergreen' => 'true']));
        $this->assertFalse(BacklinkSources::evergreen(['evergreen' => false]));
        $this->assertNull(BacklinkSources::evergreen(['evergreen' => null]));
        $this->assertNull(BacklinkSources::evergreen(['evergreen' => 'maybe']));
    }

    public function test_link_back(): void
    {
        [$ready, $dropped] = LinkBack::prepare([
            ['article_id' => strtoupper(self::UUID), 'url' => 'https://example.com/a/', 'published_at' => '2026-11-04T09:30:00+00:00'],
            ['article_id' => 'art-100', 'url' => 'https://example.com/b/'],
            ['article_id' => self::UUID, 'url' => ''],
        ]);
        $this->assertSame(2, $dropped);
        $this->assertSame(['items' => $ready], LinkBack::body($ready));
        $this->assertSame([self::UUID => 'ok'], LinkBack::results('{"results":[{"article_id":"' . strtoupper(self::UUID) . '","status":"ok"},{"article_id":"x","status":"weird"}]}'));
        $this->assertNull(LinkBack::results('{"detail":"x"}'));
        $this->assertSame('2026-11-04T09:30:00+00:00', LinkBack::iso_date('2026-11-04 09:30:00'));
        $this->assertNull(LinkBack::iso_date('0000-00-00 00:00:00'));
    }

    public function test_link_back_social_queue_exclude(): void
    {
        [$ready] = LinkBack::prepare([
            ['article_id' => self::UUID, 'url' => 'https://example.com/a/', 'social_queue_exclude' => false],
            ['article_id' => self::UUID, 'url' => 'https://example.com/b/', 'social_queue_exclude' => true],
            ['article_id' => self::UUID, 'url' => 'https://example.com/c/', 'social_queue_exclude' => '1'],
            ['article_id' => self::UUID, 'url' => 'https://example.com/d/'],
        ]);
        $this->assertFalse($ready[0]['social_queue_exclude']);
        $this->assertTrue($ready[1]['social_queue_exclude']);
        $this->assertArrayNotHasKey('social_queue_exclude', $ready[2], 'only a boolean is passed on');
        $this->assertArrayNotHasKey('social_queue_exclude', $ready[3]);
        foreach (['1', 'true', 'TRUE', ' Yes ', 'on', 1, true] as $value) {
            $this->assertTrue(LinkBack::is_truthy($value), var_export($value, true));
        }
        foreach (['0', '', 'false', 'no', 'off', 'y', '2', 0, false, null, ['1']] as $value) {
            $this->assertFalse(LinkBack::is_truthy($value), var_export($value, true));
        }
    }

    public function test_image_host_rule(): void
    {
        $this->assertTrue(ImageHostRule::allows('https://media.example.com/a.png', ['media.example.com']));
        $this->assertFalse(ImageHostRule::allows('https://evil.example.com/a.png', ['media.example.com']));
        $this->assertTrue(ImageHostRule::allows('https://any.example.com/a.png', null));
        $this->assertTrue(ImageHostRule::allows('https://any.example.com/a.png', []));
        $this->assertFalse(ImageHostRule::allows('http://media.example.com/a.png', null));
        $this->assertSame(['a.example.com'], ImageHostRule::hosts_from_settings(['image_hosts' => ['A.example.com', 'a.example.com', 3]]));
        $this->assertNull(ImageHostRule::hosts_from_settings(['image_hosts' => []]));
        $this->assertNull(ImageHostRule::hosts_from_settings([]));
    }

    public function test_placeholder_links(): void
    {
        $this->assertSame('/app/a/', PlaceholderLinks::path_of_prefix('https://tribunedesk.com/app/a/'));
        $this->assertNull(PlaceholderLinks::path_of_prefix('ftp://x/app/a/'));
        $this->assertNull(PlaceholderLinks::path_of_prefix(42));

        $ph    = 'https://tribunedesk.com/app/a/' . self::UUID;
        $links = [['kind' => 'placeholder', 'href' => $ph, 'article_id' => self::UUID]];
        $html  = '<p><a href="' . $ph . '/">tekst</a> <a href="' . $ph . 'x">dłuższy</a></p>';
        $out   = PlaceholderLinks::resolve($html, $links, '/app/a/', static fn(string $id): ?string => 'https://example.com/post/');
        $this->assertStringContainsString('href="https://example.com/post/"', $out);
        $this->assertStringContainsString('href="' . $ph . 'x"', $out, 'a longer URL with the same prefix stays');
        $this->assertSame($html, PlaceholderLinks::resolve($html, $links, '/app/a/', static fn(string $id): ?string => null));
        $this->assertSame('<p>tekst</p>', PlaceholderLinks::unwrap_missing('<p><a href="' . $ph . '" rel="x">tekst</a></p>', $links, '/app/a/', static fn(string $id): bool => false));
        $kept = '<p><a href="' . $ph . '">tekst</a></p>';
        $this->assertSame($kept, PlaceholderLinks::unwrap_missing($kept, $links, '/app/a/', static fn(string $id): bool => true));
    }

    public function test_changes_feed(): void
    {
        $this->assertSame(7, ChangesFeed::row_version(['fields' => [['version' => 3], ['version' => 7], 'junk']]));
        $this->assertSame(0, ChangesFeed::row_version([]));
        $this->assertTrue(ChangesFeed::overwrites_nonempty('old', 'new'));
        $this->assertFalse(ChangesFeed::overwrites_nonempty('  ', 'new'));
        $this->assertFalse(ChangesFeed::overwrites_nonempty('same', 'same'));
        $this->assertTrue(ChangesFeed::replaces_kept(3, 3));
        $this->assertFalse(ChangesFeed::replaces_kept(4, 3));
        $this->assertTrue(ChangesFeed::drops_kept(3, 5));
        $this->assertFalse(ChangesFeed::drops_kept(6, 5));
        $this->assertSame(
            ['field' => 'alt', 'value' => 'A', 'base_version' => 1, 'changed_at' => gmdate('c', 100)],
            ChangesFeed::field_write('alt', 'A', null, null, 100)
        );
        $this->assertSame(4, ChangesFeed::field_write('alt', 'A', 4, 50, 100)['base_version']);
        $this->assertTrue(ChangesFeed::stores_versions('conflict'));
        $this->assertFalse(ChangesFeed::stores_versions('held'));
        // Review E4b: a malformed status from the server must not stop the sync cron.
        $this->assertFalse(ChangesFeed::stores_versions(5));
        $this->assertFalse(ChangesFeed::stores_versions(null));
    }

    public function test_resync(): void
    {
        $page = Resync::page('cms_to_td', [5 => ['title' => 'T', 'alt' => str_repeat('x', 5001), 'caption' => "a\0b"]], false, '');
        $this->assertSame(['direction' => 'cms_to_td', 'items' => [['cms_media_id' => '5', 'fields' => [['field' => 'title', 'value' => 'T']]]], 'is_last' => false], $page);
        $this->assertSame('r1', Resync::page('td_to_cms', [], true, 'r1')['resync_id']);
        $this->assertTrue(Resync::answer_is_valid(['resync_id' => 'r1', 'status' => 'collecting'], false));
        $this->assertFalse(Resync::answer_is_valid(['resync_id' => 'r1', 'status' => 'ready'], false));
        $this->assertTrue(Resync::answer_is_valid(['resync_id' => 'r1', 'status' => 'ready'], true));
        $this->assertFalse(Resync::answer_is_valid(['status' => 'ready'], true));
    }

    public function test_api_errors(): void
    {
        $this->assertSame('option_locked', ApiErrors::code(409, ['code' => 'option_locked']));
        $this->assertSame('unavailable', ApiErrors::code(503, '<html>'));
        $this->assertSame('internal_error', ApiErrors::code(502, null));
        $this->assertSame(ApiErrors::UNKNOWN, ApiErrors::code(418, ['code' => 'made_up']));
        $this->assertSame(5, ApiErrors::retry_after(['retry_after' => 5]));
        $this->assertNull(ApiErrors::retry_after(['retry_after' => '5']));
    }
}
