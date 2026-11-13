<?php
declare(strict_types=1);

use TribuneDesk\WordPress\MediaHandler;

/**
 * 1.1.0: obrazek wyrozniajacy dostaje nazwe pliku z `filename` albo z tytulu,
 * nie z adresu zrodla (Tribune Desk serwuje kazdy obrazek jako .../result.jpg).
 */
class Test_Media_Handler extends WP_UnitTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private int $post_id;

    public function set_up(): void
    {
        parent::set_up();
        $this->post_id = self::factory()->post->create(['post_status' => 'draft']);
        add_filter('pre_http_request', [$this, 'fake_image'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_image'], 10);
        parent::tear_down();
    }

    /** @param false|array|WP_Error $preempt */
    public function fake_image($preempt, array $args, string $url)
    {
        // download_url() strumieniuje do pliku tymczasowego: tresc trzeba tam zapisac samemu.
        if (!empty($args['filename'])) {
            file_put_contents($args['filename'], base64_decode(self::PNG_1X1));
        }

        return [
            'headers'  => ['content-type' => 'image/png'],
            'body'     => '',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => $args['filename'] ?? null,
        ];
    }

    private function attached_file(int $attachment_id): string
    {
        return wp_basename((string) get_attached_file($attachment_id));
    }

    public function test_nazwa_z_filename_z_payloadu(): void
    {
        $id = MediaHandler::set_featured_from_url(
            $this->post_id,
            'https://storage.tribunedesk.com/abc/result.png',
            'Tytuł artykułu',
            ['filename' => 'Pola Wiśniewska w dżungli.jpg']
        );

        $this->assertIsInt($id);
        $this->assertSame('pola-wisniewska-w-dzungli.png', $this->attached_file($id));
        $this->assertSame($id, (int) get_post_thumbnail_id($this->post_id));
        $this->assertSame('https://storage.tribunedesk.com/abc/result.png', get_post_meta($id, '_source_url', true));
    }

    public function test_bez_filename_nazwa_z_tytulu_przycieta(): void
    {
        $title = 'Szczęka opada! Pola Wiśniewska w kontrowersyjnym show. Kolejna żona lidera Ich Troje przerywa milczenie [zdjęcia] 🌙';

        $id = MediaHandler::set_featured_from_url($this->post_id, 'https://storage.tribunedesk.com/abc/result.png', $title);

        $name = $this->attached_file($id);
        $this->assertStringStartsWith('szczeka-opada-pola-wisniewska', $name);
        $this->assertStringEndsWith('.png', $name);
        $this->assertLessThanOrEqual(64, strlen($name));
        $this->assertStringNotContainsString('%', $name);
        $this->assertStringNotContainsString('--', $name);
    }

    public function test_file_name_bez_tytulu_i_z_obcym_rozszerzeniem(): void
    {
        $this->assertSame('obraz.jpg', MediaHandler::file_name('', '', 'https://cdn.example.com/render?id=5'));
        $this->assertSame('obraz.webp', MediaHandler::file_name('', '🌙 !!!', 'https://cdn.example.com/x/result.WEBP'));
        $this->assertSame('dlugi.jpg', MediaHandler::file_name('dlugi.exe', 'x', 'https://cdn.example.com/x/result.jpg'));
    }

    public function test_drugie_wywolanie_dla_tego_samego_wpisu_nic_nie_robi(): void
    {
        MediaHandler::set_featured_from_url($this->post_id, 'https://storage.tribunedesk.com/abc/result.png', 'Tytuł');

        $this->assertNull(MediaHandler::set_featured_from_url($this->post_id, 'https://storage.tribunedesk.com/abc/result.png', 'Tytuł'));
    }

    private int $http_calls = 0;

    public function count_http($preempt)
    {
        $this->http_calls++;

        return $preempt;
    }

    /** Plik PNG w uploads (jak z galerii Yii) — bez zalacznika; zwraca sciezke wzgledna. */
    private function put_file_in_uploads(string $rel): string
    {
        $up = wp_upload_dir();
        $abs = $up['basedir'] . '/' . $rel;
        wp_mkdir_p(dirname($abs));
        file_put_contents($abs, base64_decode(self::PNG_1X1));

        return $rel;
    }

    private function make_attachment(string $rel, array $post = []): int
    {
        $id = wp_insert_attachment(array_merge([
            'post_mime_type' => 'image/png',
            'post_title'     => 'Stary tytul',
            'post_excerpt'   => 'Stary podpis',
            'post_content'   => 'Stary opis',
            'post_status'    => 'inherit',
        ], $post), wp_upload_dir()['basedir'] . '/' . $rel);
        update_post_meta($id, '_wp_attached_file', $rel);
        update_post_meta($id, '_wp_attachment_image_alt', 'Stary alt');

        return (int) $id;
    }

    private function count_upload_files(): int
    {
        $n = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(wp_upload_dir()['basedir'], FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $n++;
            }
        }

        return $n;
    }

    public function test_same_asset_id_reuses_attachment_across_posts(): void
    {
        add_filter('pre_http_request', [$this, 'count_http'], 5);
        $post2 = self::factory()->post->create(['post_status' => 'draft']);
        $hints = ['asset_id' => '11111111-1111-1111-1111-111111111111'];

        $a = MediaHandler::set_featured_from_url($this->post_id, 'https://storage.tribunedesk.com/abc/result.png', 'T1', [], $hints);
        $b = MediaHandler::set_featured_from_url($post2, 'https://storage.tribunedesk.com/zzz/result.png', 'T2', [], $hints);
        remove_filter('pre_http_request', [$this, 'count_http'], 5);

        $this->assertIsInt($a);
        $this->assertSame($a, $b);
        $this->assertSame(1, $this->http_calls);
        $this->assertSame('11111111-1111-1111-1111-111111111111', get_post_meta($a, '_hf_asset_id', true));
        $this->assertSame($a, (int) get_post_thumbnail_id($post2));
    }

    public function test_cms_attachment_id_is_used_when_present(): void
    {
        $existing = $this->make_attachment($this->put_file_in_uploads('2026/10/cms.png'));
        add_filter('pre_http_request', [$this, 'count_http'], 5);

        $id = MediaHandler::set_featured_from_url($this->post_id, 'https://storage.tribunedesk.com/abc/result.png', 'T', [], [
            'cms_attachment_id' => $existing,
            'asset_id'          => '22222222-2222-2222-2222-222222222222',
        ]);
        remove_filter('pre_http_request', [$this, 'count_http'], 5);

        $this->assertSame($existing, $id);
        $this->assertSame(0, $this->http_calls);
        $this->assertSame('22222222-2222-2222-2222-222222222222', get_post_meta($existing, '_hf_asset_id', true));
    }

    public function test_cms_attachment_id_of_non_attachment_is_ignored(): void
    {
        $id = MediaHandler::set_featured_from_url($this->post_id, 'https://storage.tribunedesk.com/abc/result.png', 'T', [], [
            'cms_attachment_id' => $this->post_id,
        ]);

        $this->assertNotSame($this->post_id, $id);
        $this->assertSame('attachment', get_post_type($id));
    }

    public function test_own_upload_url_resolves_without_download(): void
    {
        $rel = $this->put_file_in_uploads('2026/10/wlasny.png');
        $existing = $this->make_attachment($rel);
        add_filter('pre_http_request', [$this, 'count_http'], 5);

        $id = MediaHandler::set_featured_from_url($this->post_id, wp_upload_dir()['baseurl'] . '/' . $rel, 'T');
        remove_filter('pre_http_request', [$this, 'count_http'], 5);

        $this->assertSame($existing, $id);
        $this->assertSame(0, $this->http_calls);
        $this->assertSame($existing, (int) get_post_thumbnail_id($this->post_id));
    }

    public function test_file_without_attachment_gets_attachment_without_copy(): void
    {
        $rel = $this->put_file_in_uploads('2026/10/galeria-yii.png');
        $before = $this->count_upload_files();
        add_filter('pre_http_request', [$this, 'count_http'], 5);

        $id = MediaHandler::set_featured_from_url($this->post_id, wp_upload_dir()['baseurl'] . '/' . $rel, 'T', [], [
            'asset_id' => '33333333-3333-3333-3333-333333333333',
        ]);
        remove_filter('pre_http_request', [$this, 'count_http'], 5);

        $this->assertIsInt($id);
        $this->assertSame('attachment', get_post_type($id));
        $this->assertSame($rel, get_post_meta($id, '_wp_attached_file', true));
        $this->assertSame('image/png', get_post_mime_type($id));
        $this->assertSame(0, $this->http_calls);
        $this->assertSame('33333333-3333-3333-3333-333333333333', get_post_meta($id, '_hf_asset_id', true));
        $this->assertSame($id, (int) get_post_thumbnail_id($this->post_id));
        // Metadane (miniatury) moga dodac pliki, ale oryginal nie jest kopiowany.
        $this->assertFileDoesNotExist(wp_upload_dir()['basedir'] . '/2026/10/galeria-yii-1.png');
        $this->assertGreaterThanOrEqual($before, $this->count_upload_files());
    }

    public function test_path_traversal_url_does_not_create_attachment(): void
    {
        $url = wp_upload_dir()['baseurl'] . '/../wp-config.php';
        $this->assertNull(MediaHandler::find_existing($url, []));
    }

    public function test_existing_attachment_texts_not_overwritten(): void
    {
        $rel = $this->put_file_in_uploads('2026/10/teksty.png');
        $existing = $this->make_attachment($rel);

        MediaHandler::set_featured_from_url($this->post_id, wp_upload_dir()['baseurl'] . '/' . $rel, 'T', [
            'alt' => 'Nowy alt', 'caption' => 'Nowy podpis', 'description' => 'Nowy opis', 'filename' => 'nowa.jpg',
        ]);

        $p = get_post($existing);
        $this->assertSame('Stary podpis', $p->post_excerpt);
        $this->assertSame('Stary opis', $p->post_content);
        $this->assertSame('Stary tytul', $p->post_title);
        $this->assertSame('Stary alt', get_post_meta($existing, '_wp_attachment_image_alt', true));
    }

    public function test_sideload_sets_from_td_meta(): void
    {
        $id = MediaHandler::set_featured_from_url($this->post_id, 'https://storage.tribunedesk.com/abc/result.png', 'T');

        $this->assertIsInt($id);
        $this->assertSame('1', get_post_meta($id, MediaHandler::META_FROM_TD, true));
        $this->assertSame('_hf_from_td', MediaHandler::META_FROM_TD);
    }

    public function test_orphan_file_sets_from_td_meta(): void
    {
        $rel = $this->put_file_in_uploads('2026/10/sierota-td.png');

        $id = MediaHandler::set_featured_from_url($this->post_id, wp_upload_dir()['baseurl'] . '/' . $rel, 'T');

        $this->assertIsInt($id);
        $this->assertSame('1', get_post_meta($id, MediaHandler::META_FROM_TD, true));
    }

    public function test_existing_attachment_not_tagged(): void
    {
        $rel = $this->put_file_in_uploads('2026/10/redakcyjny.png');
        $existing = $this->make_attachment($rel);

        MediaHandler::set_featured_from_url($this->post_id, wp_upload_dir()['baseurl'] . '/' . $rel, 'T');

        $this->assertSame('', get_post_meta($existing, MediaHandler::META_FROM_TD, true));
    }
}
