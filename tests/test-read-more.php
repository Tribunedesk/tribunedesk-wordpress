<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\Backlinks;
use TribuneDesk\WordPress\ReadMore;

/**
 * Sample "Read more" box (spec D6; guide/3-full-content.md "The read more box").
 * Rows of hf_backlinks: on the page of source S the box lists targets T (source_post_id = S).
 */
class Test_ReadMore extends WP_UnitTestCase
{
    private int $source;

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Backlinks::table());
        $this->source = (int) self::factory()->post->create(['post_status' => 'publish', 'post_title' => 'Źródło']);
        ReadMore::register();
    }

    private function link_to(int $target, string $created): void
    {
        global $wpdb;
        $wpdb->insert(Backlinks::table(), [
            'source_post_id' => $this->source,
            'target_post_id' => $target,
            'target_kind'    => Backlinks::KIND_NEWS,
            'created_at'     => $created,
        ]);
    }

    private function target(string $title, string $status = 'publish'): int
    {
        return (int) self::factory()->post->create(['post_status' => $status, 'post_title' => $title]);
    }

    public function test_lists_published_targets_newest_first_without_drafts(): void
    {
        $old   = $this->target('Starszy');
        $new   = $this->target('Nowszy');
        $draft = $this->target('Szkic', 'draft');
        $this->link_to($old, '2026-11-01 10:00:00');
        $this->link_to($new, '2026-11-03 10:00:00');
        $this->link_to($draft, '2026-11-04 10:00:00');

        $html = ReadMore::render($this->source);

        $this->assertStringContainsString('<aside class="td-read-more">', $html);
        $this->assertStringContainsString(get_permalink($new), $html);
        $this->assertStringContainsString(get_permalink($old), $html);
        $this->assertLessThan(strpos($html, 'Starszy'), strpos($html, 'Nowszy'));
        $this->assertStringNotContainsString('Szkic', $html);
    }

    public function test_limit_and_title_attributes(): void
    {
        $this->link_to($this->target('A'), '2026-11-01 10:00:00');
        $this->link_to($this->target('B'), '2026-11-02 10:00:00');

        $html = ReadMore::render($this->source, ['limit' => 1, 'title' => 'Zobacz też']);

        $this->assertSame(1, substr_count($html, '<li>'));
        $this->assertStringContainsString('>Zobacz też</h2>', $html);
    }

    public function test_no_rows_gives_an_empty_string(): void
    {
        $this->assertSame('', ReadMore::render($this->source));
    }

    public function test_title_is_escaped(): void
    {
        $this->link_to($this->target('A'), '2026-11-01 10:00:00');
        $html = ReadMore::render($this->source, ['title' => '<script>alert(1)</script>']);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_filter_replaces_the_html(): void
    {
        $this->link_to($this->target('A'), '2026-11-01 10:00:00');
        $cb = static fn(string $html, array $posts, int $post_id): string => 'custom:' . count($posts) . ':' . $post_id;
        add_filter('headlinesforge_read_more_html', $cb, 10, 3);
        $html = ReadMore::render($this->source);
        remove_filter('headlinesforge_read_more_html', $cb, 10);
        $this->assertSame('custom:1:' . $this->source, $html);
    }

    public function test_shortcode_renders_on_the_post_page(): void
    {
        $this->link_to($this->target('A'), '2026-11-01 10:00:00');
        $this->go_to(get_permalink($this->source));
        $html = do_shortcode('[tribune_desk_read_more limit="5"]');
        $this->assertStringContainsString('td-read-more', $html);
    }

    public function test_block_is_registered_and_renders(): void
    {
        $this->assertTrue(WP_Block_Type_Registry::get_instance()->is_registered('tribune-desk/read-more'));
        $this->link_to($this->target('A'), '2026-11-01 10:00:00');
        $this->go_to(get_permalink($this->source));
        $html = do_blocks('<!-- wp:tribune-desk/read-more {"limit":3} /-->');
        $this->assertStringContainsString('td-read-more', $html);
    }

    public function test_plugin_does_not_add_the_box_by_itself(): void
    {
        $this->link_to($this->target('A'), '2026-11-01 10:00:00');
        $this->go_to(get_permalink($this->source));
        $content = apply_filters('the_content', 'Treść wpisu.');
        $this->assertStringNotContainsString('td-read-more', $content);
    }
}
