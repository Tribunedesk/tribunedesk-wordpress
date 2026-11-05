<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\CmsApiClient;
use TribuneDesk\WordPress\CmsBackfill;
use TribuneDesk\WordPress\CmsResync;
use TribuneDesk\WordPress\CmsSettingsScreen;
use TribuneDesk\WordPress\CmsSync;
use TribuneDesk\WordPress\MediaMetaSync;
use TribuneDesk\WordPress\MediaPush;
use TribuneDesk\WordPress\PublishedUrlsClient;

/**
 * Umowa sciezek: kazde zadanie wtyczki do /v3/cms musi istniec w OpenAPI serwera. Mocki w pozostalych
 * testach przyjma kazda sciezke, wiec tylko to lapie wysylke na trase, ktorej serwer nie ma.
 * Kontrakt to `paths` z tests/contract/openapi.json, kopii z taga repo publicznego (tests/contract/VERSION).
 */
class Test_Cms_V3_Paths extends WP_UnitTestCase
{
    private const HELD_ID  = '6f1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
    private const ASSET_ID = '7f1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
    private const PNG_1X1  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @var list<array{0: string, 1: string}> metoda, sciezka */
    private array $seen = [];

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        require_once ABSPATH . 'wp-admin/includes/template.php';
        update_option(CmsApiClient::OPTION_TOKEN, 'tok-testowy');
        update_option(PublishedUrlsClient::OPTION_API_BASE, 'https://api.example.test');
        update_option(MediaPush::OPTION_FLOOR, 0);
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        add_filter('pre_http_request', [$this, 'capture'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'capture'], 10);
        $_POST    = [];
        $_REQUEST = [];
        parent::tear_down();
    }

    /** @return array<string, mixed> */
    public function capture($pre, array $args, string $url): array
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        // Pobranie pliku idzie pod podpisany adres z kanalu, nie do API: poza umowa.
        if (!str_starts_with($url, 'https://api.example.test/')) {
            file_put_contents((string) $args['filename'], (string) base64_decode(self::PNG_1X1));

            return ['response' => ['code' => 200, 'message' => ''], 'body' => '', 'headers' => [], 'cookies' => [], 'filename' => $args['filename']];
        }
        $this->seen[] = [$args['method'], $path];

        return ['response' => ['code' => 200, 'message' => ''], 'body' => wp_json_encode($this->reply($args['method'], $path, $args)), 'headers' => [], 'cookies' => []];
    }

    /** @return array<string, mixed> */
    private function reply(string $method, string $path, array $args): array
    {
        $fields = ['title' => true, 'alt' => true, 'caption' => true, 'description' => true];

        return match (true) {
            str_ends_with($path, '/status')   => ['org_code' => 'x', 'contract_version' => '3', 'scopes' => [], 'state' => 'active', 'pending_changes' => 0, 'held_batches' => 0, 'recent_errors' => []],
            str_ends_with($path, '/settings') => ['variant' => 'full', 'locked_options' => [], 'limits' => [], 'options' => [
                'new_files_cms_to_td' => true, 'new_files_cms_to_td_since' => null, 'new_files_cms_to_td_min_width' => null,
                'new_files_td_to_cms' => true, 'td_to_cms_editor_files' => true, 'td_to_cms_graphics' => true,
                'fields_cms_to_td' => $fields, 'fields_td_to_cms' => $fields,
                'conflict_rule' => 'last_change', 'keep_nonempty_cms_to_td' => false, 'keep_nonempty_td_to_cms' => false,
                'model_texts_to_cms' => 'empty_only', 'mass_change_threshold' => 500,
                'cms_deletion_trashes_in_td' => false, 'td_deletion_marks_in_cms' => false, 'keep_copies_in_td' => false,
            ]],
            str_ends_with($path, '/changes')  => ['changes' => $this->feed, 'next_cursor' => 'c1', 'has_more' => false],
            str_ends_with($path, '/held')     => ['items' => []],
            str_ends_with($path, '/backfill/requests') => ['items' => [['id' => self::HELD_ID, 'status' => 'collecting', 'filters' => [], 'created_at' => '2026-11-01T10:00:00Z']]],
            str_ends_with($path, '/pages')    => ['accepted' => 0, 'results' => []],
            str_ends_with($path, '/link')     => ['id' => '1', 'status' => 'ok'],
            str_ends_with($path, '/push-existing') => ['queued' => 0, 'held_batch_id' => null, 'next_after' => null],
            str_ends_with($path, '/decision') => ['id' => self::HELD_ID, 'kind' => 'fields', 'direction' => 'cms_to_td', 'state' => 'discarded', 'count' => 1, 'created_at' => '2026-11-01T10:00:00Z'],
            str_ends_with($path, '/resync')   => array_key_exists('confirm', json_decode($args['body'], true))
                ? ['status' => 'applied', 'cursor' => 'c2']
                : ['resync_id' => self::HELD_ID, 'status' => 'ready', 'differences' => 1, 'expires_at' => gmdate('c', time() + DAY_IN_SECONDS)],
            default                           => ['results' => []],
        };
    }

    /** @var list<array<string, mixed>> */
    private array $feed = [];

    /** Akcja admin_post konczy sie na przekierowaniu, zamiast na exit. */
    private function submit(callable $handler, string $nonce, array $post = []): void
    {
        $_POST                = $post;
        $_REQUEST['_wpnonce'] = wp_create_nonce($nonce);
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

    private function run_all_flows(): void
    {
        // Pierwsze pobranie bez kursora, potem z kursorem i wierszem, ktory czyta /settings.
        CmsSync::run_poll();
        $id         = (int) self::factory()->attachment->create(['post_title' => 'T', 'post_excerpt' => 'Moje']);
        $this->feed = [[
            'cursor' => 'x', 'kind' => 'fields', 'cms_media_id' => (string) $id, 'changed_at' => '2026-11-01T10:00:00Z',
            'fields' => [['field' => 'caption', 'value' => 'Z TD', 'version' => 3, 'changed_at' => '2026-11-01T10:00:00Z', 'changed_by' => 'editor']],
        ]];
        CmsSync::run_poll();

        // Nowy plik z TD: pobranie i /link.
        $this->feed = [[
            'cursor' => 'y', 'kind' => 'new_asset', 'asset_id' => self::ASSET_ID, 'changed_at' => '2026-11-01T10:00:00Z', 'fields' => [],
            'file_url' => 'https://pliki.example.test/a/b.png?sig=x', 'filename' => 'b.png',
        ]];
        CmsSync::run_poll();
        $this->feed = [];

        // Kolejka pol i nowy plik.
        wp_update_post(['ID' => $id, 'post_excerpt' => 'Redaktor']);
        update_post_meta($id, MediaMetaSync::META_ALT, 'Alt');
        $parent = self::factory()->post->create(['post_status' => 'publish']);
        $file   = (int) self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'post_parent' => $parent, 'post_title' => 'P']);
        update_post_meta($file, '_wp_attached_file', "2026/10/img-$file.jpg");
        wp_update_attachment_metadata($file, ['width' => 1200, 'height' => 800]);
        // Usuniecie w bibliotece: kolejka wyslana w tym samym przebiegu.
        wp_delete_attachment((int) self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']), true);
        CmsSync::run_send();

        // Wiersz czekajacy na paczke: poll pyta o /held.
        update_post_meta($id, CmsSync::META_PENDING_TD, ['alt' => ['value' => 'X', 'version' => 1, 'batch' => self::HELD_ID]]);
        CmsSync::run_poll();

        // Import wsteczny: lista zlecen i strona kandydatow.
        CmsBackfill::run();

        // Wyrownanie: strona podgladu i zatwierdzenie.
        update_option(CmsResync::OPTION_STATE, ['direction' => 'cms_to_td', 'status' => 'collecting']);
        CmsResync::run_page();
        $this->submit([CmsResync::class, 'handle_confirm'], CmsResync::ACTION_CONFIRM);

        // Ekran: odczyty, zapis ustawien i decyzja o paczce.
        ob_start();
        CmsSettingsScreen::render_block();
        ob_end_clean();
        $this->submit([CmsSettingsScreen::class, 'handle_save'], CmsSettingsScreen::ACTION_SAVE, ['hf_cms_variant' => 'full']);
        $this->submit([CmsSettingsScreen::class, 'handle_push_existing'], CmsSettingsScreen::ACTION_PUSH);
        $this->submit([CmsSettingsScreen::class, 'handle_held'], CmsSettingsScreen::ACTION_HELD . '_' . self::HELD_ID, ['hf_cms_held_id' => self::HELD_ID, 'hf_cms_decision' => 'discard']);
    }

    /**
     * `paths` of the published contract as path => methods.
     *
     * @return array<string, list<string>>
     */
    private function contract(): array
    {
        $openapi  = json_decode((string) file_get_contents(__DIR__ . '/contract/openapi.json'), true);
        $contract = [];
        foreach ((array) ($openapi['paths'] ?? []) as $path => $ops) {
            $contract[$path] = array_map('strtoupper', array_values(array_intersect(array_keys($ops), ['get', 'post', 'put', 'patch', 'delete'])));
        }

        return $contract;
    }

    public function test_every_plugin_request_exists_in_the_server_contract(): void
    {
        $contract = $this->contract();
        $this->assertNotEmpty($contract);
        $this->assertFalse($this->in_contract($contract, 'POST', '/v3/cms/resync/preview'));
        $this->assertFalse($this->in_contract($contract, 'GET', '/v3/cms/resync'));
        $this->assertTrue($this->in_contract($contract, 'POST', '/v3/cms/held/abc/decision'));
        // Link back goes outside CmsApiClient (PublishedUrlsClient), so no flow below reaches it.
        $this->assertTrue($this->in_contract($contract, 'POST', \TribuneDesk\Protocol\LinkBack::PATH));

        $this->run_all_flows();

        $this->assertNotEmpty($this->seen);
        foreach ($this->seen as [$method, $path]) {
            $this->assertTrue($this->in_contract($contract, $method, $path), "$method $path nie ma w kontrakcie serwera");
        }
    }

    public function test_flows_reach_every_call_site(): void
    {
        $this->run_all_flows();

        $source = '';
        foreach ((array) glob(dirname(__DIR__) . '/src/WordPress/*.php') as $file) {
            $source .= (string) file_get_contents((string) $file);
        }
        preg_match_all("/CmsApiClient::(get|post|put)\\(\\s*'([^']+)'/", $source, $sites, PREG_SET_ORDER);
        $this->assertNotEmpty($sites);
        foreach ($sites as [, $method, $literal]) {
            $prefix = CmsApiClient::PREFIX . strtok($literal, '?');
            $hit    = array_filter($this->seen, static fn ($s) => $s[0] === strtoupper($method) && str_starts_with($s[1], $prefix));
            $this->assertNotEmpty($hit, "Zaden przebieg nie doszedl do $method $literal");
        }
    }

    /** @param array<string, list<string>> $contract */
    private function in_contract(array $contract, string $method, string $path): bool
    {
        foreach ($contract as $template => $methods) {
            $regex = '#^' . preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($template, '#')) . '$#';
            if (preg_match($regex, $path) === 1 && in_array($method, $methods, true)) {
                return true;
            }
        }

        return false;
    }
}
