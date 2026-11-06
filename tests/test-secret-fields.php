<?php
declare(strict_types=1);

use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\RestEndpoint;
use TribuneDesk\WordPress\Settings;

/**
 * Secret fields (spec D10 "Security"): password inputs, the stored value never in the HTML,
 * and an empty submit keeps a working configuration.
 */
class Test_SecretFields extends WP_UnitTestCase
{
    private const SIGNING = 'whsec_C2FVsBQIhrscChlQIMV+b5sSYspob7oD';

    public function set_up(): void
    {
        parent::set_up();
        require_once ABSPATH . 'wp-admin/includes/template.php';
        global $wp_settings_errors;
        $wp_settings_errors = [];
        Settings::register_settings();
    }

    /** @return array<int, string> */
    private function secret_options(): array
    {
        return [RestEndpoint::OPTION_SIGNING_SECRET, CmsApiClient::OPTION_TOKEN];
    }

    public function test_empty_submit_keeps_each_stored_secret(): void
    {
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, self::SIGNING);
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_oldtoken1234');

        $this->assertSame(self::SIGNING, sanitize_option(RestEndpoint::OPTION_SIGNING_SECRET, ''));
        $this->assertSame('tdcms_oldtoken1234', sanitize_option(CmsApiClient::OPTION_TOKEN, '  '));
    }

    public function test_new_value_replaces_the_stored_secret(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_oldtoken1234');

        $this->assertSame('tdcms_newtoken5678', sanitize_option(CmsApiClient::OPTION_TOKEN, 'tdcms_newtoken5678'));
        $this->assertSame(self::SIGNING, sanitize_option(RestEndpoint::OPTION_SIGNING_SECRET, self::SIGNING));
    }

    public function test_invalid_signing_secret_keeps_the_old_one_and_reports_an_error(): void
    {
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, self::SIGNING);

        $this->assertSame(self::SIGNING, sanitize_option(RestEndpoint::OPTION_SIGNING_SECRET, 'not-a-secret'));
        $codes = array_column(get_settings_errors(), 'setting');
        $this->assertContains(RestEndpoint::OPTION_SIGNING_SECRET, $codes);
    }

    public function test_fields_are_password_inputs_without_the_stored_value(): void
    {
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, self::SIGNING);
        update_option(CmsApiClient::OPTION_TOKEN, 'tdcms_token-WXYZ');

        $renderers = [
            RestEndpoint::OPTION_SIGNING_SECRET => ['render_signing_secret_field', self::SIGNING, '7oD'],
            CmsApiClient::OPTION_TOKEN          => ['render_cms_token_field', 'tdcms_token-WXYZ', 'WXYZ'],
        ];
        foreach ($renderers as $option => [$method, $value, $tail]) {
            ob_start();
            Settings::$method();
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('type="password"', $html, $option);
            $this->assertStringContainsString('name="' . $option . '"', $html, $option);
            $this->assertStringNotContainsString($value, $html, $option);
            $this->assertStringContainsString($tail, $html, $option);
        }
    }

    public function test_empty_field_says_nothing_is_saved(): void
    {
        delete_option(RestEndpoint::OPTION_SIGNING_SECRET);
        ob_start();
        Settings::render_signing_secret_field();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringNotContainsString('…', $html);
    }

    public function test_signing_secret_can_be_removed_from_the_page(): void
    {
        // Review E4a: a secret pasted before Tribune Desk signs locks deliveries out; the editor
        // must be able to undo it without WP-CLI.
        update_option(RestEndpoint::OPTION_SIGNING_SECRET, self::SIGNING);
        $_POST[Settings::FIELD_REMOVE_SIGNING_SECRET] = '1';
        $value = sanitize_option(RestEndpoint::OPTION_SIGNING_SECRET, '');
        unset($_POST[Settings::FIELD_REMOVE_SIGNING_SECRET]);
        $this->assertSame('', $value);

        update_option(RestEndpoint::OPTION_SIGNING_SECRET, self::SIGNING);
        ob_start();
        Settings::render_signing_secret_field();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="' . Settings::FIELD_REMOVE_SIGNING_SECRET . '"', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function test_signing_secret_field_is_registered_on_the_page(): void
    {
        global $wp_settings_fields;
        $fields = $wp_settings_fields[Settings::PAGE_SLUG]['headlinesforge_main'] ?? [];
        $this->assertArrayHasKey(RestEndpoint::OPTION_SIGNING_SECRET, $fields);
    }
}
