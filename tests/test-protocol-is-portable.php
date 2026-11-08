<?php
declare(strict_types=1);

/**
 * src/Protocol is the part of the plugin another CMS can port: plain PHP, no WordPress
 * (spec D10 "Structure"), each class headed by the level, routes and guide chapter it implements.
 */
class Test_Protocol_Is_Portable extends WP_UnitTestCase
{
    private const CLASSES = [
        'ApiErrors', 'AuthorOrder', 'BacklinkSources', 'ChangesFeed', 'DeliveryAnswer', 'DraftRules',
        'ImageHostRule', 'LinkBack', 'PlaceholderLinks', 'Resync', 'WebhookSignature',
    ];

    private const WORDPRESS_CALLS = [
        'get_option', 'update_option', 'delete_option', 'add_action', 'add_filter', 'apply_filters',
        'do_action', 'get_post', 'get_post_meta', 'update_post_meta', 'wp_remote_', 'wp_parse_url',
        'wp_json_encode', 'is_wp_error', '__', '_e', '_n', 'esc_html', 'esc_attr', 'esc_url',
        'current_time', 'get_permalink',
    ];

    /** @return array<string, string> file name => source without comments */
    private function sources(): array
    {
        $out = [];
        foreach ((array) glob(dirname(__DIR__) . '/src/Protocol/*.php') as $file) {
            $code = '';
            foreach (token_get_all((string) file_get_contents((string) $file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $out[basename((string) $file)] = $code;
        }

        return $out;
    }

    public function test_every_mapped_class_exists_in_protocol(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertTrue(class_exists('TribuneDesk\\Protocol\\' . $class), $class);
        }
    }

    public function test_protocol_calls_no_wordpress(): void
    {
        $sources = $this->sources();
        $this->assertNotEmpty($sources);
        foreach ($sources as $file => $code) {
            $this->assertStringNotContainsString('$wpdb', $code, $file);
            foreach (self::WORDPRESS_CALLS as $fn) {
                $this->assertDoesNotMatchRegularExpression('/(?<![\w>:$\\\\])' . preg_quote($fn, '/') . '\w*\s*\(/', $code, "$file calls $fn");
            }
            $this->assertDoesNotMatchRegularExpression('/\\\\?WP_[A-Z][A-Za-z_]+/', $code, "$file uses a WP_ class");
        }
    }

    public function test_every_protocol_class_has_the_reader_header(): void
    {
        foreach ((array) glob(dirname(__DIR__) . '/src/Protocol/*.php') as $file) {
            // ContractVersion (cms-protocol 1.0.0) is a version constant, not a protocol rule.
            if (basename((string) $file) === 'ContractVersion.php') {
                continue;
            }
            $text = (string) file_get_contents((string) $file);
            foreach (['Level:', 'Routes:', 'Guide: '] as $field) {
                $this->assertStringContainsString($field, $text, basename((string) $file) . " lacks $field");
            }
        }
    }
}
