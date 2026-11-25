<?php
declare(strict_types=1);

/**
 * 2.6.1: functions from wp-admin/includes are loaded by the method that calls them.
 *
 * A REST delivery runs without wp-admin/includes/image.php. 2.5.0–2.6.0 called
 * wp_generate_attachment_metadata() in MediaHandler::attach_existing_file(), reached from
 * BodyImages::convert(), which loaded only media.php: the delivery ended with 500 (styl.fm, 25.11)
 * and left an attachment without metadata. PHPUnit cannot show it at run time, because the test
 * bootstrap of WordPress loads image.php for every test (and WP-CLI does too), so this test reads
 * the code: every method that calls such a function also has the require_once of its file.
 */
class Test_AttachExistingWithoutAdminIncludes extends WP_UnitTestCase
{
    /** Function => the file of wp-admin/includes it lives in. */
    private const ADMIN_FUNCTIONS = [
        'wp_generate_attachment_metadata' => 'image.php',
        'wp_read_image_metadata'          => 'image.php',
        'media_handle_sideload'           => 'media.php',
        'download_url'                    => 'file.php',
        'wp_handle_sideload'              => 'file.php',
    ];

    /** @return array<string, string> "File::method" => body */
    private static function methods(): array
    {
        $out = [];
        $files = glob(dirname(__DIR__) . '/src/WordPress/*.php') ?: [];
        foreach ($files as $file) {
            // Code without comments: a docblock that names a function is not a call.
            $code = '';
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $parts = preg_split('/\n\s*(?:(?:public|private|protected|static|final)\s+)*function\s+/', $code) ?: [];
            array_shift($parts);
            foreach ($parts as $part) {
                $name = (string) strtok($part, '(');
                $out[basename($file) . '::' . $name] = $part;
            }
        }

        return $out;
    }

    public function test_every_caller_loads_the_admin_file_itself(): void
    {
        $missing = [];
        foreach (self::methods() as $method => $body) {
            foreach (self::ADMIN_FUNCTIONS as $function => $include) {
                if (preg_match('/(?<![\w>:])' . $function . '\s*\(/', $body) !== 1) {
                    continue;
                }
                $guarded = str_contains($body, "wp-admin/includes/$include")
                    || str_contains($body, "function_exists('$function')");
                if (!$guarded) {
                    $missing[] = "$method calls $function() without wp-admin/includes/$include";
                }
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_the_guard_sees_a_call_without_its_include(): void
    {
        $body = "attach(): int {\n wp_update_attachment_metadata(1, wp_generate_attachment_metadata(1, 'x'));\n}";
        $this->assertSame(1, preg_match('/(?<![\w>:])wp_generate_attachment_metadata\s*\(/', $body));
        $this->assertFalse(str_contains($body, 'wp-admin/includes/image.php'));
    }
}
