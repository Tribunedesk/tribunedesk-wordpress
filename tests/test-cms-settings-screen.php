<?php
declare(strict_types=1);

use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsSettingsScreen;
use TribuneDesk\WordPress\PublishedUrlsClient;
use TribuneDesk\WordPress\Settings;

class Test_Cms_Settings_Screen extends WP_UnitTestCase
{
    private const TOKEN   = 'tok-sekretny-123';
    private const HELD_ID = '6f1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array<string, array{0: int, 1: array|string}> klucz: "METODA /sciezka" */
    private array $routes = [];

    public function set_up(): void
    {
        parent::set_up();
        // submit_button i add_settings_field mieszkaja w wp-admin; $title jak w test-settings-api.
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $GLOBALS['title'] = 'Tribune Desk';
        update_option(CmsApiClient::OPTION_TOKEN, self::TOKEN);
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->requests = [];
        $this->routes   = [
            'GET /v3/cms/status'   => [200, ['org_code' => 'x', 'contract_version' => '3', 'scopes' => [], 'state' => 'active', 'pending_changes' => 2, 'held_batches' => 0, 'recent_errors' => []]],
            'GET /v3/cms/settings' => [200, $this->settings()],
            'GET /v3/cms/held'     => [200, ['items' => []]],
            'PUT /v3/cms/settings' => [200, $this->settings()],
        ];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        $_POST    = [];
        $_REQUEST = [];
        parent::tear_down();
    }

    /** @return array<string, mixed> */
    public function fake_http($pre, array $args, string $url): array
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        $route = $args['method'] . ' ' . wp_parse_url($url, PHP_URL_PATH);
        [$code, $body] = $this->routes[$route] ?? [404, ['code' => 'not_found', 'message' => 'x']];

        return ['response' => ['code' => $code, 'message' => ''], 'body' => is_string($body) ? $body : wp_json_encode($body), 'headers' => [], 'cookies' => []];
    }

    /** @return array<string, mixed> */
    private function settings(array $locked = [], string $variant = 'full'): array
    {
        $fields = ['title' => true, 'alt' => true, 'caption' => true, 'description' => true];

        return [
            'variant' => $variant,
            'options' => [
                'new_files_cms_to_td' => true, 'new_files_cms_to_td_since' => null, 'new_files_cms_to_td_min_width' => null,
                'new_files_td_to_cms' => true, 'td_to_cms_editor_files' => true, 'td_to_cms_graphics' => true,
                'fields_cms_to_td' => $fields, 'fields_td_to_cms' => $fields,
                'conflict_rule' => 'last_change', 'keep_nonempty_cms_to_td' => false, 'keep_nonempty_td_to_cms' => false,
                'model_texts_to_cms' => 'empty_only', 'mass_change_threshold' => 500,
                'cms_deletion_trashes_in_td' => false, 'td_deletion_marks_in_cms' => false, 'keep_copies_in_td' => false,
            ],
            'locked_options' => $locked,
            'limits' => [],
        ];
    }

    private function render(): string
    {
        ob_start();
        CmsSettingsScreen::render_block();

        return (string) ob_get_clean();
    }

    private function notice(): string
    {
        ob_start();
        CmsSettingsScreen::render_notice();

        return (string) ob_get_clean();
    }

    /** Uruchamia akcje admin_post i konczy ja na przekierowaniu, zamiast na exit. */
    private function submit(callable $handler): void
    {
        $stop = static function () {
            throw new RuntimeException('redirect');
        };
        add_filter('wp_redirect', $stop);
        try {
            $handler();
            $this->fail('Oczekiwano przekierowania.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $stop);
        }
    }

    private function save(array $post): void
    {
        $_POST = $post;
        $_REQUEST['_wpnonce'] = wp_create_nonce(CmsSettingsScreen::ACTION_SAVE);
        $this->submit([CmsSettingsScreen::class, 'handle_save']);
    }

    private function custom_post(): array
    {
        return [
            'hf_cms_variant' => 'custom',
            'hf_cms' => [
                'new_files_cms_to_td' => '1',
                'new_files_cms_to_td_min_width' => '800',
                'fields_cms_to_td' => ['alt' => '1'],
                'conflict_rule' => 'cms',
                'model_texts_to_cms' => 'never',
                'mass_change_threshold' => '250',
            ],
        ];
    }

    /** @return array<int, array{url: string, args: array}> */
    private function puts(): array
    {
        return array_values(array_filter($this->requests, static fn ($r) => $r['args']['method'] === 'PUT'));
    }

    public function test_settings_form_sends_variant_and_custom_options(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('name="hf_cms_variant"', $html);
        $this->assertStringContainsString('hf_cms[conflict_rule]', $html);
        $this->assertStringContainsString('name="_wpnonce"', $html);

        $this->save(['hf_cms_variant' => 'cms_to_td_only', 'hf_cms' => ['conflict_rule' => 'td']]);
        $this->assertSame(['variant' => 'cms_to_td_only'], json_decode($this->puts()[0]['args']['body'], true));

        $this->requests = [];
        $this->save($this->custom_post());
        $body = json_decode($this->puts()[0]['args']['body'], true);
        $this->assertSame('custom', $body['variant']);
        $options = $body['options'];
        $this->assertCount(16, $options);
        $this->assertTrue($options['new_files_cms_to_td']);
        $this->assertFalse($options['new_files_td_to_cms']);
        $this->assertSame(['title' => false, 'alt' => true, 'caption' => false, 'description' => false], $options['fields_cms_to_td']);
        $this->assertSame('cms', $options['conflict_rule']);
        $this->assertSame('never', $options['model_texts_to_cms']);
        $this->assertSame(250, $options['mass_change_threshold']);
        $this->assertSame(800, $options['new_files_cms_to_td_min_width']);
        $this->assertNull($options['new_files_cms_to_td_since']);
    }

    public function test_since_day_keeps_exact_time_when_unchanged(): void
    {
        update_option('timezone_string', 'Europe/Warsaw');
        $settings = $this->settings([], 'custom');
        $settings['options']['new_files_cms_to_td_since'] = '2026-10-01T12:34:56Z';
        $this->routes['GET /v3/cms/settings'] = [200, $settings];

        $post = $this->custom_post();
        $post['hf_cms']['new_files_cms_to_td_since'] = '2026-10-01';
        $this->save($post);
        $this->assertSame('2026-10-01T12:34:56Z', json_decode($this->puts()[0]['args']['body'], true)['options']['new_files_cms_to_td_since']);

        $this->requests = [];
        $post['hf_cms']['new_files_cms_to_td_since'] = '2026-10-05';
        $this->save($post);
        $this->assertSame('2026-10-05T00:00:00+02:00', json_decode($this->puts()[0]['args']['body'], true)['options']['new_files_cms_to_td_since']);

        $this->requests = [];
        $post['hf_cms']['new_files_cms_to_td_since'] = '2026-02-31';
        $this->save($post);
        $this->assertSame([], $this->puts());
    }

    public function test_save_shows_success(): void
    {
        $this->save(['hf_cms_variant' => 'full']);
        $this->assertStringContainsString('notice-success', $this->notice());
    }

    public function test_form_is_rendered_from_a_fresh_read(): void
    {
        $this->assertMatchesRegularExpression('/value="full"[^>]*checked/', $this->render());

        // Zmiana zrobiona gdzie indziej widoczna od razu.
        $this->routes['GET /v3/cms/settings'] = [200, $this->settings([], 'custom')];
        $this->assertMatchesRegularExpression('/value="custom"[^>]*checked/', $this->render());
    }

    public function test_notice_and_no_later_marks(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('Pola, nowe pliki i usunięcia idą przez nową wymianę', $html);
        $this->assertStringNotContainsString('zacznie działać później', $html);
        $this->assertStringNotContainsString('działa w obie strony bez zmian', $html);
        $this->assertLessThan(strpos($html, '<form'), strpos($html, 'Pola, nowe pliki i usunięcia'));
        $this->assertStringContainsString('Tylko TD → CMS', $html);
        $this->assertStringNotContainsString('na razie nie zatrzymuje', $html);
        $this->assertStringContainsString('name="hf_cms[cms_deletion_trashes_in_td]"', $html);
        $this->assertStringContainsString('name="hf_cms[td_deletion_marks_in_cms]"', $html);
    }

    public function test_times_are_shown_in_site_timezone(): void
    {
        update_option('timezone_string', 'Europe/Warsaw');
        $this->routes['GET /v3/cms/status'] = [200, ['state' => 'active', 'last_changes_poll_at' => '2026-11-01T10:00:00Z', 'recent_errors' => [
            ['at' => '2026-11-01T10:05:00Z', 'code' => 'x', 'message' => 'y'],
        ]]];
        $this->routes['GET /v3/cms/held'] = [200, ['items' => [
            ['id' => self::HELD_ID, 'kind' => 'fields', 'direction' => 'cms_to_td', 'state' => 'pending', 'count' => 1, 'created_at' => '2026-11-01T10:10:00Z'],
        ]]];
        $html = $this->render();
        $this->assertStringContainsString('2026-11-01 11:00', $html);
        $this->assertStringContainsString('2026-11-01 11:05', $html);
        $this->assertStringContainsString('2026-11-01 11:10', $html);
    }

    public function test_whole_group_lock_disables_all_fields_and_keeps_them(): void
    {
        $settings = $this->settings(['fields_cms_to_td']);
        $settings['options']['fields_cms_to_td']['alt'] = false;
        $this->routes['GET /v3/cms/settings'] = [200, $settings];

        $html = $this->render();
        foreach (['title', 'alt', 'caption', 'description'] as $field) {
            $this->assertMatchesRegularExpression('/name="hf_cms\[fields_cms_to_td\]\[' . $field . '\]"[^>]* disabled>/', $html);
            $this->assertStringContainsString('value="fields_cms_to_td.' . $field . '"', $html);
        }
        $this->assertDoesNotMatchRegularExpression('/name="hf_cms\[fields_td_to_cms\]\[alt\]"[^>]* disabled>/', $html);

        $post = $this->custom_post();
        unset($post['hf_cms']['fields_cms_to_td']);
        $post['hf_cms_kept'] = ['fields_cms_to_td.title', 'fields_cms_to_td.alt', 'fields_cms_to_td.caption', 'fields_cms_to_td.description'];
        $this->save($post);
        $sent = json_decode($this->puts()[0]['args']['body'], true)['options']['fields_cms_to_td'];
        $this->assertSame(['title' => true, 'alt' => false, 'caption' => true, 'description' => true], $sent);
    }

    public function test_invalid_threshold_saves_nothing(): void
    {
        $post = $this->custom_post();
        $post['hf_cms']['mass_change_threshold'] = '0';
        $this->save($post);

        $this->assertSame([], $this->puts());
        $this->assertStringContainsString('notice-error', $this->notice());
    }

    public function test_locked_option_is_disabled_and_409_is_shown(): void
    {
        $locked = ['conflict_rule', 'fields_td_to_cms.alt'];
        $this->routes['GET /v3/cms/settings'] = [200, $this->settings($locked)];

        $html = $this->render();
        $this->assertMatchesRegularExpression('/<select name="hf_cms\[conflict_rule\]"[^>]* disabled>/', $html);
        $this->assertMatchesRegularExpression('/name="hf_cms\[fields_td_to_cms\]\[alt\]"[^>]* disabled>/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="hf_cms\[fields_td_to_cms\]\[title\]"[^>]* disabled>/', $html);
        $this->assertStringContainsString('value="conflict_rule"', $html);

        // Formularz nie wysyla zablokowanych pol: ida aktualne wartosci z Tribune Desk.
        $post = $this->custom_post();
        unset($post['hf_cms']['conflict_rule']);
        $post['hf_cms_kept'] = $locked;
        $this->save($post);
        $options = json_decode($this->puts()[0]['args']['body'], true)['options'];
        $this->assertSame('last_change', $options['conflict_rule']);
        $this->assertTrue($options['fields_td_to_cms']['alt']);
        $this->notice();

        $this->routes['PUT /v3/cms/settings'] = [409, ['code' => 'option_locked', 'message' => 'x']];
        $this->save(['hf_cms_variant' => 'full']);
        $notice = $this->notice();
        $this->assertStringContainsString('notice-error', $notice);
        $this->assertStringContainsString('zablokowan', $notice);
    }

    public function test_held_send_and_discard_need_nonce_and_capability(): void
    {
        $this->routes['GET /v3/cms/held'] = [200, ['items' => [
            ['id' => self::HELD_ID, 'kind' => 'fields', 'direction' => 'cms_to_td', 'state' => 'pending', 'count' => 700, 'created_at' => '2026-11-01T10:00:00Z'],
            ['id' => '7f1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', 'kind' => 'files', 'direction' => 'td_to_cms', 'state' => 'discarded', 'count' => 9, 'created_at' => '2026-11-01T09:00:00Z'],
        ]]];
        $html = $this->render();
        $this->assertStringContainsString('700', $html);
        $this->assertSame(1, substr_count($html, 'value="send"'));
        $this->assertSame(1, substr_count($html, 'value="discard"'));

        $route = 'POST /v3/cms/held/' . self::HELD_ID . '/decision';
        $this->routes[$route] = [200, ['id' => self::HELD_ID, 'state' => 'sent']];
        $_POST = ['hf_cms_held_id' => self::HELD_ID, 'hf_cms_decision' => 'send'];
        $this->requests = [];

        // Bez nonce: koniec, bez wywolania Tribune Desk.
        try {
            CmsSettingsScreen::handle_held();
            $this->fail('Oczekiwano wp_die.');
        } catch (WPDieException $e) {
            $this->assertSame([], $this->requests);
        }

        // Nonce innej paczki nie pasuje.
        $_REQUEST['_wpnonce'] = wp_create_nonce(CmsSettingsScreen::ACTION_HELD . '_inna');
        try {
            CmsSettingsScreen::handle_held();
            $this->fail('Oczekiwano wp_die.');
        } catch (WPDieException $e) {
            $this->assertSame([], $this->requests);
        }

        // Bez uprawnien: koniec, mimo poprawnego nonce tego uzytkownika.
        wp_set_current_user((int) self::factory()->user->create(['role' => 'editor']));
        $_REQUEST['_wpnonce'] = wp_create_nonce(CmsSettingsScreen::ACTION_HELD . '_' . self::HELD_ID);
        try {
            CmsSettingsScreen::handle_held();
            $this->fail('Oczekiwano wp_die.');
        } catch (WPDieException $e) {
            $this->assertSame([], $this->requests);
        }

        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        foreach (['send', 'discard'] as $decision) {
            $_POST['hf_cms_decision'] = $decision;
            $_REQUEST['_wpnonce']     = wp_create_nonce(CmsSettingsScreen::ACTION_HELD . '_' . self::HELD_ID);
            $this->requests = [];
            $this->submit([CmsSettingsScreen::class, 'handle_held']);
            $this->assertSame(['decision' => $decision], json_decode($this->requests[0]['args']['body'], true));
            $this->assertSame([self::HELD_ID => $decision], get_option(\TribuneDesk\WordPress\CmsSync::OPTION_DECIDED));
        }
    }

    public function test_held_conflict_is_shown(): void
    {
        $this->routes['POST /v3/cms/held/' . self::HELD_ID . '/decision'] = [409, ['code' => 'state_conflict', 'message' => 'x']];
        $_POST = ['hf_cms_held_id' => self::HELD_ID, 'hf_cms_decision' => 'send'];
        $_REQUEST['_wpnonce'] = wp_create_nonce(CmsSettingsScreen::ACTION_HELD . '_' . self::HELD_ID);
        $this->submit([CmsSettingsScreen::class, 'handle_held']);
        $notice = $this->notice();
        $this->assertStringContainsString('notice-error', $notice);
        $this->assertStringContainsString('odrzuć ją i uruchom nowy resync', $notice);
    }

    public function test_save_needs_nonce_and_capability(): void
    {
        $_POST = ['hf_cms_variant' => 'full'];
        try {
            CmsSettingsScreen::handle_save();
            $this->fail('Oczekiwano wp_die.');
        } catch (WPDieException $e) {
            $this->assertSame([], $this->requests);
        }

        wp_set_current_user((int) self::factory()->user->create(['role' => 'editor']));
        $_REQUEST['_wpnonce'] = wp_create_nonce(CmsSettingsScreen::ACTION_SAVE);
        try {
            CmsSettingsScreen::handle_save();
            $this->fail('Oczekiwano wp_die.');
        } catch (WPDieException $e) {
            $this->assertSame([], $this->requests);
        }
    }

    public function test_td_error_shows_message_and_keeps_screen(): void
    {
        $this->routes['GET /v3/cms/status'] = [503, ['code' => 'unavailable', 'message' => 'x']];
        $html = $this->render();
        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);

        // Blad ustawien nie zabiera statusu ani paczek.
        $this->routes['GET /v3/cms/status']   = [200, ['state' => 'active', 'recent_errors' => []]];
        $this->routes['GET /v3/cms/settings'] = [401, ['code' => 'unauthorized', 'message' => 'x']];
        $html = $this->render();
        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('Stan integracji', $html);
        $this->assertStringContainsString('Wstrzymane paczki', $html);

        // Odpowiedz bez oczekiwanego ksztaltu to tez blad, nie awaria strony.
        $this->routes['GET /v3/cms/settings'] = [200, ['variant' => 'full']];
        $this->assertStringContainsString('notice-error', $this->render());

        // Cala strona renderuje sie dalej.
        $this->routes['GET /v3/cms/status'] = [500, ''];
        ob_start();
        Settings::render_page();
        $page = (string) ob_get_clean();
        $this->assertStringContainsString('notice-error', $page);
        $this->assertStringContainsString('options.php', $page);
        $this->assertStringContainsString('Synchronizacja mediów z Tribune Desk', $page);
        $this->assertStringNotContainsString('Zdjęcia do biblioteki Tribune Desk', $page);
    }

    public function test_disabled_integration_shows_state_without_form(): void
    {
        $this->routes['GET /v3/cms/status'] = [200, ['state' => 'disabled', 'recent_errors' => []]];
        $html = $this->render();
        $this->assertStringContainsString('niewłączona', $html);
        $this->assertStringNotContainsString('hf_cms_variant', $html);
        $this->assertCount(1, $this->requests);
    }

    public function test_td_text_is_escaped(): void
    {
        $this->routes['GET /v3/cms/status'] = [200, ['state' => 'active', 'recent_errors' => [
            ['at' => '2026-11-01', 'code' => 'x', 'message' => '<script>alert(1)</script>'],
        ]]];
        $html = $this->render();
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_no_token_shows_not_connected(): void
    {
        update_option(CmsApiClient::OPTION_TOKEN, '');
        $html = $this->render();
        $this->assertStringContainsString('Synchronizacja mediów wymaga tokenu API CMS', $html);
        $this->assertStringNotContainsString('Dotychczasowa', $html);
        $this->assertStringNotContainsString('hf_cms_variant', $html);
        $this->assertSame([], $this->requests);
    }

    public function test_actions_and_token_field_are_registered(): void
    {
        $this->assertNotFalse(has_action('admin_post_' . CmsSettingsScreen::ACTION_SAVE, [CmsSettingsScreen::class, 'handle_save']));
        $this->assertNotFalse(has_action('admin_post_' . CmsSettingsScreen::ACTION_HELD, [CmsSettingsScreen::class, 'handle_held']));

        Settings::register_settings();
        $this->assertArrayHasKey(CmsApiClient::OPTION_TOKEN, get_registered_settings());

        ob_start();
        Settings::render_cms_token_field();
        $field = (string) ob_get_clean();
        // 1.17.0: the token is a password input and never printed back (spec D10 "Security").
        $this->assertStringContainsString('type="password"', $field);
        $this->assertStringContainsString('value=""', $field);
        $this->assertStringNotContainsString(self::TOKEN, $field);
    }
}
