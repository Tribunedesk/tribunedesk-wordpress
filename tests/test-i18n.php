<?php
declare(strict_types=1);

use TribuneDesk\WordPress\PublishedUrlsClient;
use TribuneDesk\WordPress\ReadMore;

/**
 * Strings for people are English in code and go through the `tribune-desk` text domain; Polish
 * lives in languages/tribune-desk-pl_PL.po/.mo (spec D10 "Language", plan E4b Ruling 5).
 */
class Test_I18n extends WP_UnitTestCase
{
    private const GETTEXT = '/\b(__|_e|_x|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)\s*\(/';

    public function tear_down(): void
    {
        tribune_desk_tests_load_polish();
        parent::tear_down();
    }

    public function test_without_a_translation_people_see_english(): void
    {
        unload_textdomain('tribune-desk');
        $this->assertSame('Signing secret (whsec_…)', __('Signing secret (whsec_…)', 'tribune-desk'));
        $this->assertSame('Tribune Desk does not know this article_id', PublishedUrlsClient::describe('not_found', ''));
    }

    /** @return array<int, string> */
    private function php_files(): array
    {
        $root  = dirname(__DIR__);
        $files = [$root . '/headlinesforge-receiver.php', $root . '/uninstall.php'];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (str_ends_with((string) $file, '.php')) {
                $files[] = (string) $file;
            }
        }

        return $files;
    }

    public function test_every_gettext_call_uses_the_tribune_desk_domain(): void
    {
        $bad = [];
        foreach ($this->php_files() as $file) {
            $code = (string) file_get_contents($file);
            // The domain argument of a gettext call; 'headlinesforge-receiver' as a page or
            // updater slug is an identifier, not a text domain.
            $call = '/\b(?:__|_e|_x|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)\s*\((?:[^()]|\([^()]*\))*?,\s*\'([a-z-]+)\'\s*\)/s';
            preg_match_all($call, $code, $m);
            foreach (array_unique($m[1]) as $domain) {
                if ($domain !== 'tribune-desk') {
                    $bad[] = basename($file) . " uses text domain $domain";
                }
            }
        }
        $js = (string) file_get_contents(dirname(__DIR__) . '/assets/read-more-block.js');
        if (str_contains($js, "'headlinesforge-receiver'")) {
            $bad[] = 'read-more-block.js still uses headlinesforge-receiver';
        }
        $this->assertSame([], $bad);
    }

    public function test_no_polish_in_php_string_literals(): void
    {
        $bad = [];
        foreach ($this->php_files() as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match('/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', $token[1])) {
                    $bad[] = basename($file) . ':' . $token[2] . ' ' . mb_substr($token[1], 0, 60);
                }
            }
        }
        $this->assertSame([], $bad, implode("\n", array_slice($bad, 0, 30)));
    }

    public function test_header_declares_the_text_domain(): void
    {
        $header = get_file_data(dirname(__DIR__) . '/headlinesforge-receiver.php', ['td' => 'Text Domain', 'path' => 'Domain Path']);
        $this->assertSame('tribune-desk', $header['td']);
        $this->assertSame('/languages', $header['path']);
        $this->assertNotFalse(has_action('init', 'tribune_desk_load_textdomain'));
    }

    public function test_polish_translation_is_complete_and_loads(): void
    {
        $dir = dirname(__DIR__) . '/languages/';
        $this->assertFileExists($dir . 'tribune-desk.pot');
        $po = (string) file_get_contents($dir . 'tribune-desk-pl_PL.po');
        $this->assertDoesNotMatchRegularExpression('/^msgstr ""\n\n/m', substr($po, (int) strpos($po, "\n\n")), 'empty msgstr in the .po');

        preg_match_all('/^msgid "(.+)"$/m', (string) file_get_contents($dir . 'tribune-desk.pot'), $pot);
        preg_match_all('/^msgid "(.+)"$/m', $po, $pl);
        $this->assertSame([], array_values(array_diff($pot[1], $pl[1])), 'msgids missing from the .po');

        $this->assertTrue(load_textdomain('tribune-desk', $dir . 'tribune-desk-pl_PL.mo'));
        $this->assertSame('Sekret podpisu (whsec_…)', __('Signing secret (whsec_…)', 'tribune-desk'));
        // A log line follows the site's language at the time it is written.
        $this->assertSame('Tribune Desk nie zna tego article_id', PublishedUrlsClient::describe('not_found', ''));
    }

    public function test_block_script_has_translations(): void
    {
        ReadMore::register();
        $script = wp_scripts()->registered[ReadMore::SCRIPT] ?? null;
        $this->assertNotNull($script);
        $this->assertSame('tribune-desk', $script->textdomain);
    }
}
