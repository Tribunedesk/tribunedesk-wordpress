<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

class DeliveriesLog
{
    // 20, not 10: since 0.8.0 each article uses two rows (receipt + callback with the address).
    public const MAX_ROWS = 20;

    public const KIND_RECEIVE  = 'receive';
    public const KIND_NOTIFY   = 'notify';
    public const KIND_BACKFILL  = 'backfill';
    public const KIND_BACKLINKS = 'backlinks';
    public const KIND_MEDIA     = 'media';

    public static function record(
        string $article_id,
        ?int $post_id,
        string $status,
        ?string $error_msg = null,
        string $kind = self::KIND_RECEIVE
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;

        $wpdb->insert(
            $table,
            [
                'received_at' => current_time('mysql'),
                'article_id'  => $article_id,
                'post_id'     => $post_id,
                'kind'        => $kind,
                'status'      => $status,
                'error_msg'   => $error_msg,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s']
        );

        // Trimming to MAX_ROWS separately for the media kind and for the rest:
        // the hourly image send must not push out article receipt rows.
        $cmp      = $kind === self::KIND_MEDIA ? '=' : '<>';
        $keep_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE kind $cmp %s ORDER BY id DESC LIMIT %d",
                self::KIND_MEDIA,
                self::MAX_ROWS
            )
        );
        if (!empty($keep_ids)) {
            $placeholders = implode(',', array_fill(0, count($keep_ids), '%d'));
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table WHERE kind $cmp %s AND id NOT IN ($placeholders)",
                    self::KIND_MEDIA,
                    ...$keep_ids
                )
            );
        }
    }

    /** @return array<int, array{received_at: string, article_id: string, post_id: ?int, kind: string, status: string, error_msg: ?string}> */
    public static function recent(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;
        // The last MAX_ROWS from the non-media group and separately from the media group, merged descending by id:
        // hourly media rows must not push receipts out of the settings view.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "(SELECT id, received_at, article_id, post_id, kind, status, error_msg FROM $table WHERE kind <> %s ORDER BY id DESC LIMIT %d)
                 UNION ALL
                 (SELECT id, received_at, article_id, post_id, kind, status, error_msg FROM $table WHERE kind = %s ORDER BY id DESC LIMIT %d)
                 ORDER BY id DESC",
                self::KIND_MEDIA,
                self::MAX_ROWS,
                self::KIND_MEDIA,
                self::MAX_ROWS
            ),
            ARRAY_A
        );
        return array_map(static function (array $row): array {
            unset($row['id']);
            return $row;
        }, $rows ?: []);
    }
}
