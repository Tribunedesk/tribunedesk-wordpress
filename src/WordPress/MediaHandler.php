<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

class MediaHandler
{
    public const META_THUMBNAIL_SET = '_aw_thumbnail_set';
    public const META_ASSET_ID = '_hf_asset_id';
    /** Attachment created by the plugin from Tribune Desk — it does not go back to Tribune Desk. */
    public const META_FROM_TD = '_hf_from_td';

    /**
     * Seconds for the featured image download inside a delivery. Tribune Desk waits 30 s for
     * the whole answer; a slower download leaves the post without the image and the image
     * comes a minute later from `RETRY_HOOK` (see PostWriter).
     */
    public const DELIVERY_TIMEOUT = 15;
    /** Seconds for a download outside a delivery (cron). */
    public const BACKGROUND_TIMEOUT = 60;
    public const RETRY_HOOK = 'tribunedesk_featured_image_retry';

    /**
     * Returns attachment ID or null on failure. Idempotent per post via post_meta flag.
     * 
     * @param array<string, string> $metadata Optional metadata (filename, caption, description, alt)
     * @param array<string, mixed>  $hints    Optional: asset_id (string), cms_attachment_id (int)
     */
    public static function set_featured_from_url(int $post_id, string $image_url, string $title = '', array $metadata = [], array $hints = [], int $timeout = self::BACKGROUND_TIMEOUT): ?int
    {
        if (get_post_meta($post_id, self::META_THUMBNAIL_SET, true)) {
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $asset_id = self::asset_id($hints);

        // Existing attachment: no download and no change to its texts (alt, caption, description) —
        // the newsroom may have corrected them in WP.
        $existing = self::find_existing($image_url, $hints);
        if ($existing !== null) {
            if ($asset_id !== '') {
                update_post_meta($existing, self::META_ASSET_ID, $asset_id);
            }
            set_post_thumbnail($post_id, $existing);
            update_post_meta($post_id, self::META_THUMBNAIL_SET, 1);

            return $existing;
        }

        // Not media_sideload_image(): it takes the file name from the URL, and Tribune Desk serves
        // every image as .../result.jpg — the media library filled up with result-N.jpg.
        $attachment_id = self::sideload_from_url(
            $image_url,
            self::file_name($metadata['filename'] ?? '', $title, $image_url),
            $post_id,
            $title !== '' ? $title : null,
            null,
            $timeout
        );
        if ($attachment_id === null) {
            return null;
        }
        // The same thing media_sideload_image() stores — a trace of where the file came from.
        add_post_meta($attachment_id, '_source_url', $image_url);
        update_post_meta($attachment_id, self::META_FROM_TD, 1);
        if ($asset_id !== '') {
            update_post_meta($attachment_id, self::META_ASSET_ID, $asset_id);
        }

        // Apply metadata to the attachment
        self::apply_metadata_to_attachment($attachment_id, $metadata);

        set_post_thumbnail($post_id, $attachment_id);
        update_post_meta($post_id, self::META_THUMBNAIL_SET, 1);

        return $attachment_id;
    }

    /**
     * Downloads the file and adds it to the library. Null: failed download or save; the temporary file
     * is not left behind. $verify inspects the downloaded file before saving and may reject it.
     *
     * @param callable(string): bool|null $verify
     */
    public static function sideload_from_url(string $url, string $name, int $post_id = 0, ?string $title = null, ?callable $verify = null, int $timeout = self::BACKGROUND_TIMEOUT): ?int
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // The one place every image download passes (featured image and /v3 new_asset):
        // only https, and only the hosts Tribune Desk lists when the list is known.
        if (!ImageHosts::allows($url, ImageHosts::current())) {
            // A refused image must leave a trace: otherwise a missing featured image has no cause.
            DeliveriesLog::record(
                'image_hosts',
                $post_id > 0 ? $post_id : null,
                'error',
                sprintf(
                    /* translators: %s: image host name */
                    __('image skipped: host not in the Tribune Desk image_hosts list or address without https: %s', 'tribune-desk'),
                    (string) wp_parse_url($url, PHP_URL_HOST)
                ),
                DeliveriesLog::KIND_MEDIA
            );

            return null;
        }

        $tmp = download_url($url, $timeout);
        if (is_wp_error($tmp)) {
            return null;
        }
        if ($verify !== null && !$verify($tmp)) {
            wp_delete_file($tmp);

            return null;
        }

        $attachment_id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], $post_id, $title);
        if (is_wp_error($attachment_id) || !is_int($attachment_id)) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }

            return null;
        }

        return $attachment_id;
    }

    /**
     * Looks for an attachment before anything is downloaded: (1) cms_attachment_id, (2) meta
     * _hf_asset_id, (3) a URL from our own uploads, (4) a file in uploads without an attachment — then
     * an attachment pointing at that file is created, without a copy. Null = needs downloading.
     *
     * @param array<string, mixed> $hints asset_id, cms_attachment_id
     */
    public static function find_existing(string $url, array $hints): ?int
    {
        $cms_id = isset($hints['cms_attachment_id']) ? (int) $hints['cms_attachment_id'] : 0;
        if ($cms_id > 0 && get_post_type($cms_id) === 'attachment') {
            return $cms_id;
        }

        $asset_id = self::asset_id($hints);
        if ($asset_id !== '') {
            $found = get_posts([
                'post_type'     => 'attachment',
                'post_status'   => 'any',
                'meta_query'    => [['key' => self::META_ASSET_ID, 'value' => $asset_id]],
                'fields'        => 'ids',
                'numberposts'   => 1,
                'no_found_rows' => true,
            ]);
            if ($found !== []) {
                return (int) $found[0];
            }
        }

        $rel = self::upload_relative_path($url);
        if ($rel === null) {
            return null;
        }

        $by_url = attachment_url_to_postid($url);
        if ($by_url > 0) {
            return $by_url;
        }

        return self::attach_existing_file($rel);
    }

    /** @param array<string, mixed> $hints */
    private static function asset_id(array $hints): string
    {
        return isset($hints['asset_id']) && is_string($hints['asset_id']) ? trim($hints['asset_id']) : '';
    }

    /**
     * Path relative to uploads when the URL lies under wp_upload_dir()['baseurl']
     * (host and path prefix, regardless of scheme); otherwise null.
     */
    private static function upload_relative_path(string $url): ?string
    {
        $base = wp_parse_url((string) wp_upload_dir()['baseurl']);
        $u    = wp_parse_url($url);
        if (!is_array($base) || !is_array($u) || empty($base['host']) || empty($u['host']) || !isset($u['path'])) {
            return null;
        }
        if (strtolower($base['host']) !== strtolower($u['host']) || ($base['port'] ?? null) !== ($u['port'] ?? null)) {
            return null;
        }
        $prefix = rtrim((string) ($base['path'] ?? ''), '/') . '/';
        if (strpos($u['path'], $prefix) !== 0) {
            return null;
        }
        $rel = rawurldecode(substr($u['path'], strlen($prefix)));

        return $rel === '' ? null : $rel;
    }

    /** Attachment for a file that already lies in uploads — without copying the file. */
    private static function attach_existing_file(string $rel): ?int
    {
        if (strpos($rel, "\0") !== false || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $rel) === 1) {
            return null;
        }
        $basedir = realpath((string) wp_upload_dir()['basedir']);
        $abs     = $basedir !== false ? realpath($basedir . '/' . $rel) : false;
        if ($basedir === false || $abs === false || !is_file($abs)
            || strpos($abs, $basedir . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }

        $rel  = ltrim(str_replace('\\', '/', substr($abs, strlen($basedir))), '/');
        $type = wp_check_filetype(basename($abs));
        if (empty($type['type']) || strpos($type['type'], 'image/') !== 0) {
            return null;
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $type['type'],
            'post_title'     => sanitize_text_field(pathinfo($abs, PATHINFO_FILENAME)),
            'post_status'    => 'inherit',
        ], $abs);
        if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) {
            return null;
        }
        $attachment_id = (int) $attachment_id;
        update_post_meta($attachment_id, '_wp_attached_file', $rel);
        update_post_meta($attachment_id, self::META_FROM_TD, 1);
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $abs));

        return $attachment_id;
    }

    /**
     * Image file name: slug of `filename` from the payload, or without it from the article title
     * (up to ~60 characters, cut at a hyphen); extension from the source URL when it is an image,
     * otherwise jpg — the real type is checked by media_handle_sideload() anyway.
     */
    public static function file_name(string $filename, string $title, string $url): string
    {
        $base = $filename !== '' ? pathinfo($filename, PATHINFO_FILENAME) : $title;
        // sanitize_title() encodes non-ASCII characters (e.g. emoji) as %xx — those are dropped.
        $slug = (string) preg_replace('/%[a-f0-9]{2}/i', '', sanitize_title($base));
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');
        if (strlen($slug) > 60) {
            $slug = substr($slug, 0, 60);
            $cut  = strrpos($slug, '-');
            if ($cut !== false && $cut > 20) {
                $slug = substr($slug, 0, $cut);
            }
            $slug = trim($slug, '-');
        }
        if ($slug === '') {
            $slug = 'obraz';
        }

        $ext = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
            $ext = 'jpg';
        }

        return $slug . '.' . $ext;
    }

    /**
     * Apply metadata fields to a WordPress attachment.
     * 
     * @param int $attachment_id The attachment post ID
     * @param array<string, string> $metadata Metadata array with optional keys: title, filename, caption, description, alt
     */
    public static function apply_metadata_to_attachment(int $attachment_id, array $metadata): void
    {
        if (empty($metadata)) {
            return;
        }

        // A write from a Tribune Desk delivery, not a human's change: it does not go back as a sync.
        MediaMetaSync::without_tracking(static function () use ($attachment_id, $metadata): void {
            self::write_metadata($attachment_id, $metadata);
        });
    }

    /** @param array<string, string> $metadata */
    private static function write_metadata(int $attachment_id, array $metadata): void
    {

        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return;
        }

        $update_data = [];

        // caption → attachment excerpt
        if (!empty($metadata['caption'])) {
            $update_data['post_excerpt'] = sanitize_text_field($metadata['caption']);
        }

        // description → attachment post content
        if (!empty($metadata['description'])) {
            $update_data['post_content'] = sanitize_textarea_field($metadata['description']);
        }

        // Apply post updates if any
        if (!empty($update_data)) {
            $update_data['ID'] = $attachment_id;
            wp_update_post($update_data, false);
        }

        // alt → post meta _wp_attachment_image_alt
        if (!empty($metadata['alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($metadata['alt']));
        }

        // filename → attachment title (Media library "Title") + slug.
        // We deliberately do NOT rename the underlying file on disk; existing
        // <img src="…"> references in post content would break.
        if (!empty($metadata['filename'])) {
            $filename = sanitize_file_name($metadata['filename']);
            $filename_no_ext = pathinfo($filename, PATHINFO_FILENAME);
            wp_update_post([
                'ID'         => $attachment_id,
                'post_title' => sanitize_text_field($filename_no_ext),
                'post_name'  => sanitize_title($filename_no_ext),
            ], false);
        }

        // A title given explicitly wins over the file name; the slug stays from the file name.
        if (!empty($metadata['title'])) {
            wp_update_post([
                'ID'         => $attachment_id,
                'post_title' => sanitize_text_field($metadata['title']),
            ], false);
        }
    }
}
