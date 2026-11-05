<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Hooks;

/**
 * Public filters have 2.0 names (tribune_desk_*); the 1.x names (headlinesforge_*) keep working,
 * so sites that already filter do not break (plan E4b, Ruling 4).
 */
class Test_Hook_Names extends WP_UnitTestCase
{
    private const NAMES = ['backfill_pause_seconds', 'cms_backfill_budget', 'cms_poll_budget', 'read_more_html', 'reverse_limit'];

    public function test_new_name_alone_works(): void
    {
        foreach (self::NAMES as $name) {
            add_filter('tribune_desk_' . $name, static fn($v) => 'new');
            $this->assertSame('new', Hooks::filter($name, 'default'), $name);
        }
    }

    public function test_old_name_alone_works(): void
    {
        foreach (self::NAMES as $name) {
            add_filter('headlinesforge_' . $name, static fn($v) => 'old');
            $this->assertSame('old', Hooks::filter($name, 'default'), $name);
        }
    }

    public function test_old_name_gets_the_result_of_the_new_one(): void
    {
        add_filter('tribune_desk_reverse_limit', static fn($v) => $v + 1);
        add_filter('headlinesforge_reverse_limit', static fn($v) => $v * 10);
        $this->assertSame(60, Hooks::filter('reverse_limit', 5));
    }

    public function test_extra_arguments_reach_both_names(): void
    {
        $seen = [];
        add_filter('tribune_desk_read_more_html', static function ($html, $posts, $id) use (&$seen) {
            $seen[] = $id;
            return $html;
        }, 10, 3);
        add_filter('headlinesforge_read_more_html', static function ($html, $posts, $id) use (&$seen) {
            $seen[] = $id;
            return $html;
        }, 10, 3);
        Hooks::filter('read_more_html', '<aside></aside>', [], 42);
        $this->assertSame([42, 42], $seen);
    }

    public function test_code_calls_no_old_filter_name_directly(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $code = (string) file_get_contents((string) $file);
            $this->assertDoesNotMatchRegularExpression("/apply_filters\(\s*'headlinesforge_/", $code, (string) $file);
        }
    }

    public function test_plugin_is_named_tribune_desk(): void
    {
        $header = get_file_data(dirname(__DIR__) . '/headlinesforge-receiver.php', ['name' => 'Plugin Name']);
        $this->assertSame('Tribune Desk', $header['name']);
        $readme = (string) file_get_contents(dirname(__DIR__) . '/readme.txt');
        $this->assertStringStartsWith('=== Tribune Desk ===', $readme);
    }
}
