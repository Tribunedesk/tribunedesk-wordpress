<?php
declare(strict_types=1);

use TribuneDesk\WordPress\BodyImages;
use TribuneDesk\WordPress\DeliveriesLog;
use TribuneDesk\WordPress\MediaHandler;

/**
 * 2.5.0: figury ze zdjeciami z biblioteki Tribune Desku staja sie w tresci natywnymi zdjeciami
 * WordPressa (to samo, co "Wstaw do wpisu" w edytorze klasycznym).
 */
class Test_Body_Images extends WP_UnitTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private const ASSET   = '11111111-1111-1111-1111-111111111111';
    private const SRC     = 'https://storage.tribunedesk.com/abc/result.png';

    private int $post_id;
    /** @var string[] */
    private array $http_urls = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->post_id = self::factory()->post->create(['post_status' => 'draft']);
        $this->http_urls = [];
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
        $this->http_urls[] = $url;
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

    private function put_file_in_uploads(string $rel): string
    {
        $abs = wp_upload_dir()['basedir'] . '/' . $rel;
        wp_mkdir_p(dirname($abs));
        file_put_contents($abs, base64_decode(self::PNG_1X1));

        return $rel;
    }

    /** Zalacznik 1280x1920 z metadanymi jak po wgraniu przez redakcje. */
    private function make_attachment(string $rel): int
    {
        $this->put_file_in_uploads($rel);
        $id = (int) wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title'     => 'Stary tytul',
            'post_excerpt'   => 'Stary podpis',
            'post_content'   => 'Stary opis',
            'post_status'    => 'inherit',
        ], wp_upload_dir()['basedir'] . '/' . $rel);
        update_post_meta($id, '_wp_attached_file', $rel);
        update_post_meta($id, '_wp_attachment_image_alt', 'Stary alt');
        wp_update_attachment_metadata($id, ['width' => 1280, 'height' => 1920, 'file' => $rel, 'sizes' => []]);

        return $id;
    }

    private function figure(string $src, string $img_attrs = '', string $caption = '', string $figure_attrs = ''): string
    {
        $cap = $caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '';

        return '<figure' . $figure_attrs . '><img src="' . $src . '" alt="Ksiądz"' . $img_attrs . '>' . $cap . '</figure>';
    }

    /** @return array{html: string, pending: bool, created: int[]} */
    private function convert(string $html, float $budget = 10.0, bool $hinted_only = false): array
    {
        return BodyImages::convert($html, $this->post_id, $budget, $hinted_only);
    }

    private function attachments_count(): int
    {
        return count(get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']));
    }

    public function test_hinted_figure_with_cms_attachment_becomes_caption_shortcode(): void
    {
        $id   = $this->make_attachment('2026/10/x.png');
        update_post_meta($id, '_hf_asset_id', self::ASSET);
        $html = $this->figure(
            self::SRC,
            ' data-td-asset-id="' . self::ASSET . '" data-td-cms-media-id="' . $id . '"',
            'archiwalne zdjęcie/Fot. AKPA'
        );

        $out = $this->convert($html);

        $this->assertStringContainsString('[caption id="attachment_' . $id . '" align="alignnone" width="1280"]', $out['html']);
        $this->assertStringContainsString('class="size-full wp-image-' . $id . '"', $out['html']);
        $this->assertStringContainsString('width="1280" height="1920"', $out['html']);
        $this->assertStringContainsString('alt="Ksiądz"', $out['html']);
        $this->assertStringContainsString('archiwalne zdjęcie/Fot. AKPA[/caption]', $out['html']);
        $this->assertStringNotContainsString('<figure', $out['html']);
        $this->assertSame([], $this->http_urls);
        $this->assertFalse($out['pending']);
        $this->assertSame([], $out['created']);
    }

    public function test_own_upload_url_without_hint_converted_without_new_attachment(): void
    {
        $id     = $this->make_attachment('2026/10/wlasny.png');
        $src    = wp_upload_dir()['baseurl'] . '/2026/10/wlasny.png';
        $before = $this->attachments_count();

        $out = $this->convert($this->figure($src));

        $this->assertStringContainsString('wp-image-' . $id, $out['html']);
        $this->assertStringNotContainsString('<figure', $out['html']);
        $this->assertSame($before, $this->attachments_count());
        $this->assertSame([], $this->http_urls);
        $this->assertFalse($out['pending']);
    }

    public function test_own_upload_file_without_attachment_and_without_hint_untouched(): void
    {
        $rel  = $this->put_file_in_uploads('2026/10/sierota.png');
        $html = $this->figure(wp_upload_dir()['baseurl'] . '/' . $rel);

        $out = $this->convert($html);

        $this->assertSame($html, $out['html']);
        $this->assertSame(0, $this->attachments_count());
    }

    public function test_foreign_url_without_hint_untouched(): void
    {
        $html = '<p>x</p>' . $this->figure('https://obcy.example.com/a.jpg', '', 'Podpis') . '<p>y</p>';

        $out = $this->convert($html);

        $this->assertSame($html, $out['html']);
        $this->assertFalse($out['pending']);
        $this->assertSame([], $this->http_urls);
    }

    public function test_hinted_unknown_file_sideloaded_once_then_found_by_asset_id(): void
    {
        $html = $this->figure(self::SRC, ' data-td-asset-id="' . self::ASSET . '"', 'Podpis');

        $first  = $this->convert($html);
        $second = $this->convert($html);

        $this->assertCount(1, $this->http_urls);
        $this->assertCount(1, $first['created']);
        $this->assertSame([], $second['created']);
        $id = $first['created'][0];
        $this->assertStringContainsString('wp-image-' . $id, $first['html']);
        $this->assertSame($first['html'], $second['html']);
        $this->assertSame(self::SRC, get_post_meta($id, '_source_url', true));
        $this->assertSame(self::ASSET, get_post_meta($id, MediaHandler::META_ASSET_ID, true));
        $this->assertSame('1', get_post_meta($id, MediaHandler::META_FROM_TD, true));
        $this->assertSame('Podpis', get_post($id)->post_excerpt);
        $this->assertSame('Ksiądz', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertFalse($first['pending']);
    }

    public function test_same_src_with_other_asset_id_found_only_by_source_url(): void
    {
        $first  = $this->convert($this->figure(self::SRC, ' data-td-asset-id="' . self::ASSET . '"'));
        $second = $this->convert($this->figure(self::SRC, ' data-td-asset-id="22222222-2222-2222-2222-222222222222"'));

        $this->assertCount(1, $this->http_urls);
        $this->assertSame([], $second['created']);
        $this->assertStringContainsString('wp-image-' . $first['created'][0] . '"', $second['html']);
    }

    /** 2.7.1 (audit 27.11): `data-td-cms-media-id` of a file paired with another asset is not used. */
    public function test_cms_media_id_of_file_with_other_asset_is_not_used(): void
    {
        $other = $this->make_attachment('2026/10/inny.png');
        update_post_meta($other, '_hf_asset_id', '99999999-9999-9999-9999-999999999999');
        $html = $this->figure(self::SRC, ' data-td-asset-id="' . self::ASSET . '" data-td-cms-media-id="' . $other . '"', 'Podpis');

        $out = $this->convert($html);

        $this->assertStringNotContainsString('wp-image-' . $other . '"', $out['html']);
        $this->assertSame('99999999-9999-9999-9999-999999999999', get_post_meta($other, '_hf_asset_id', true));
    }

    public function test_cms_media_id_without_asset_id_and_foreign_src_untouched(): void
    {
        $id   = $this->make_attachment('2026/10/obcy-id.png');
        $html = $this->figure('https://obcy.example.com/a.jpg', ' data-td-cms-media-id="' . $id . '"', 'Podpis');

        $out = $this->convert($html);

        $this->assertSame($html, $out['html']);
        $this->assertFalse($out['pending']);
    }

    public function test_find_existing_uses_source_url_meta(): void
    {
        $id = $this->make_attachment('2026/10/zrodlo.png');
        add_post_meta($id, '_source_url', 'https://cdn.example.com/obcy/zdjecie.jpg');

        $this->assertSame($id, MediaHandler::find_existing('https://cdn.example.com/obcy/zdjecie.jpg', []));
        $this->assertNull(MediaHandler::find_existing('https://cdn.example.com/obcy/inne.jpg', []));
    }

    public function test_find_by_url_only_never_creates_attachment(): void
    {
        $rel = $this->put_file_in_uploads('2026/10/bez-zalacznika.png');

        $this->assertNull(MediaHandler::find_by_url_only(wp_upload_dir()['baseurl'] . '/' . $rel));
        $this->assertNull(MediaHandler::find_by_url_only('https://obcy.example.com/a.jpg'));
        $this->assertSame(0, $this->attachments_count());
    }

    public function test_same_src_twice_one_attachment(): void
    {
        $fig = $this->figure(self::SRC, ' data-td-asset-id="' . self::ASSET . '"');

        $out = $this->convert('<p>a</p>' . $fig . '<p>b</p>' . $fig);

        $this->assertCount(1, $this->http_urls);
        $this->assertCount(1, $out['created']);
        $this->assertSame(2, substr_count($out['html'], 'wp-image-' . $out['created'][0] . '"'));
        $this->assertStringNotContainsString('<figure', $out['html']);
    }

    public function test_hidden_caption_and_alignwide_give_plain_img_alignnone(): void
    {
        $id   = $this->make_attachment('2026/10/szeroki.png');
        update_post_meta($id, MediaHandler::META_ASSET_ID, self::ASSET);
        $html = '<figure class="wp-block-image alignwide size-large" data-hf-caption="Ukryty podpis">'
            . '<img src="x" alt="Ksiądz" data-td-asset-id="' . self::ASSET . '" data-td-cms-media-id="' . $id . '"></figure>';

        $out = $this->convert($html);

        $this->assertStringNotContainsString('[caption', $out['html']);
        $this->assertStringNotContainsString('Ukryty podpis', $out['html']);
        $this->assertStringContainsString('<img ', $out['html']);
        $this->assertStringContainsString('alignnone', $out['html']);
        $this->assertStringContainsString('wp-image-' . $id, $out['html']);
    }

    public function test_alignleft_maps_to_align_left(): void
    {
        $id   = $this->make_attachment('2026/10/lewy.png');
        update_post_meta($id, MediaHandler::META_ASSET_ID, self::ASSET);
        $html = $this->figure(
            'x',
            ' data-td-asset-id="' . self::ASSET . '" data-td-cms-media-id="' . $id . '"',
            'Podpis',
            ' class="wp-block-image alignleft size-large is-resized" style="width:44%"'
        );

        $out = $this->convert($html);

        $this->assertStringContainsString('[caption id="attachment_' . $id . '" align="alignleft"', $out['html']);
    }

    public function test_host_outside_image_hosts_left_bare_and_logged(): void
    {
        $html = $this->figure('https://obcy.example.com/a.jpg', ' data-td-asset-id="' . self::ASSET . '"', 'Podpis');

        $out = $this->convert($html);

        $this->assertSame($html, $out['html']);
        $this->assertFalse($out['pending'], 'a refused host cannot succeed on a retry');
        $this->assertSame([], $this->http_urls);
        $rows = array_values(array_filter(DeliveriesLog::recent(), static fn (array $r): bool => $r['article_id'] === 'body_images'));
        $this->assertNotEmpty($rows);
        $this->assertSame('error', $rows[0]['status']);
        $this->assertSame(DeliveriesLog::KIND_MEDIA, $rows[0]['kind']);
        $this->assertStringContainsString('obcy.example.com', (string) $rows[0]['error_msg']);
        $this->assertStringNotContainsString('a.jpg', (string) $rows[0]['error_msg']);
    }

    public function test_zero_budget_leaves_pending_without_http(): void
    {
        $html = $this->figure(self::SRC, ' data-td-asset-id="' . self::ASSET . '"');

        $out = $this->convert($html, 0.0);

        $this->assertSame($html, $out['html']);
        $this->assertTrue($out['pending']);
        $this->assertSame([], $this->http_urls);
        $this->assertSame([], $out['created']);
    }

    public function test_embed_iframe_and_text_untouched_byte_identical(): void
    {
        $html = "<p>Tekst  z  [shortcode] i &nbsp; encjami</p>\n"
            . "<figure class=\"wp-block-embed\"><iframe src=\"https://www.youtube.com/embed/x\"></iframe><figcaption>Film</figcaption></figure>\n"
            . '<figure><img src="a.jpg"><img src="b.jpg"></figure>'
            . "\n<p>Koniec<br></p>";

        $out = $this->convert($html);

        $this->assertSame($html, $out['html']);
        $this->assertFalse($out['pending']);
    }

    public function test_existing_attachment_texts_not_changed(): void
    {
        $id   = $this->make_attachment('2026/10/teksty.png');
        // A file this site sent to Tribune Desk, paired there without telling (2.7.1: only then
        // is an id without its asset_id taken, and the pair is stored here).
        update_post_meta($id, '_hf_media_pushed', 'accepted@' . time());
        $html = $this->figure('x', ' data-td-asset-id="' . self::ASSET . '" data-td-cms-media-id="' . $id . '"', 'Nowy podpis');

        $this->convert($html);

        $p = get_post($id);
        $this->assertSame('Stary podpis', $p->post_excerpt);
        $this->assertSame('Stary opis', $p->post_content);
        $this->assertSame('Stary tytul', $p->post_title);
        $this->assertSame('Stary alt', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame(self::ASSET, get_post_meta($id, MediaHandler::META_ASSET_ID, true));
    }

    public function test_hinted_only_skips_unhinted_own_upload_figure(): void
    {
        $this->make_attachment('2026/10/wlasny2.png');
        $html = $this->figure(wp_upload_dir()['baseurl'] . '/2026/10/wlasny2.png');

        $out = $this->convert($html, 10.0, true);

        $this->assertSame($html, $out['html']);
    }
}
