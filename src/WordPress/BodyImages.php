<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\BodyFigures;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns `<figure>` blocks with a library photo into what "Insert into post" gives in the classic
 * editor: `[caption]<img class="size-full wp-image-N">…[/caption]` or a bare `<img>`. Finding and
 * replacing the figures is `Protocol\BodyFigures` (plain PHP); this class holds the WordPress part.
 */
final class BodyImages
{
    /** Seconds for all body image downloads inside one delivery (the featured image has its own 15 s). */
    public const DELIVERY_BUDGET = 10;
    public const RETRY_HOOK      = 'tribunedesk_body_images_retry';

    /**
     * @param bool $hinted_only retry mode: touch only figures carrying `data-td-asset-id`
     * @return array{html: string, pending: bool, created: int[]}
     *         pending = a figure with `data-td-asset-id` was left unconverted (budget, failure);
     *         created = IDs of attachments created by this call
     */
    public static function convert(string $html, int $post_id, float $budget_seconds, bool $hinted_only = false): array
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $deadline = microtime(true) + $budget_seconds;
        $pending  = false;
        $created  = [];

        $out = BodyFigures::replace(
            $html,
            static function (array $figure) use ($post_id, $deadline, $hinted_only, &$pending, &$created): ?string {
                return self::convert_figure($figure, $post_id, $deadline, $hinted_only, $pending, $created);
            }
        );

        return ['html' => $out, 'pending' => $pending, 'created' => $created];
    }

    /**
     * @param array{src: string, alt: string, caption: string, align: string, asset_id: string, cms_media_id: string} $figure
     * @param int[] $created
     * @return string|null null = leave the figure as it is
     */
    private static function convert_figure(array $figure, int $post_id, float $deadline, bool $hinted_only, bool &$pending, array &$created): ?string
    {
        $src      = $figure['src'];
        $alt      = $figure['alt'];
        $caption  = $figure['caption'];
        $asset_id = $figure['asset_id'];
        $cms_id   = ctype_digit($figure['cms_media_id']) ? (int) $figure['cms_media_id'] : 0;
        $hinted   = $asset_id !== '';

        if ($hinted_only && !$hinted) {
            return null;
        }

        $id = null;
        if ($hinted) {
            $hints = ['asset_id' => $asset_id];
            if ($cms_id > 0) {
                $hints['cms_attachment_id'] = $cms_id;
            }
            $id = MediaHandler::find_existing($src, $hints);
        } elseif ($src !== '') {
            // No hint: only an attachment that already exists — never a new one for a file nobody pointed at.
            $id = MediaHandler::find_by_url_only($src);
        }

        if ($id !== null) {
            if ($hinted) {
                update_post_meta($id, MediaHandler::META_ASSET_ID, $asset_id);
            }
        } elseif ($hinted) {
            $id = self::download($src, $alt, $caption, $asset_id, $post_id, $deadline);
            if ($id === null) {
                $pending = true;
                self::log_failure($src, $post_id);

                return null;
            }
            $created[] = $id;
        } else {
            return null;
        }

        $result = get_image_send_to_editor($id, $caption, '', $figure['align'], '', false, 'full', $alt);

        return is_string($result) && $result !== '' ? $result : null;
    }

    private static function download(string $src, string $alt, string $caption, string $asset_id, int $post_id, float $deadline): ?int
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0 || $src === '') {
            return null;
        }

        $id = MediaHandler::sideload_from_url(
            $src,
            MediaHandler::file_name('', $alt, $src),
            $post_id,
            null,
            null,
            max(1, (int) ceil($remaining))
        );
        if ($id === null) {
            return null;
        }

        add_post_meta($id, '_source_url', $src);
        update_post_meta($id, MediaHandler::META_FROM_TD, 1);
        update_post_meta($id, MediaHandler::META_ASSET_ID, $asset_id);
        MediaHandler::apply_metadata_to_attachment($id, [
            'alt'     => $alt,
            'caption' => wp_strip_all_tags($caption),
        ]);

        return $id;
    }

    private static function log_failure(string $src, int $post_id): void
    {
        DeliveriesLog::record(
            'body_images',
            $post_id > 0 ? $post_id : null,
            'error',
            sprintf(
                /* translators: %s: image host name */
                __('body image not imported (host outside image_hosts, download error or time budget used up): %s', 'tribune-desk'),
                (string) wp_parse_url($src, PHP_URL_HOST)
            ),
            DeliveriesLog::KIND_MEDIA
        );
    }
}
