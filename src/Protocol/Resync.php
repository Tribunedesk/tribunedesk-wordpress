<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Builds the pages of a resync preview and reads the answers.
 *
 * Level: 4 · Routes: POST /v3/cms/resync · Guide: guide/4-media-library.md#cursors-and-expiry
 *
 * After an outage or an expired cursor the CMS sends its field values page by page; Tribune Desk
 * compares them and answers with the number of differences. The first page has no resync_id,
 * every later page carries the one from the first answer, the last page says is_last. A value
 * longer than 5000 characters or with a NUL byte would fail the whole page (422), so it is left
 * out. An answer must carry a resync_id, and `ready` only after the last page.
 */
final class Resync
{
    public const DIRECTIONS = ['cms_to_td', 'td_to_cms'];
    public const FIELDS     = ['title', 'alt', 'caption', 'description'];
    public const MAX_FIELD  = 5000;

    /**
     * @param array<int, array<string, string>> $values media id => field => value
     * @return array<string, mixed>
     */
    public static function page(string $direction, array $values, bool $is_last, string $resync_id): array
    {
        $items = [];
        foreach ($values as $id => $fields) {
            $sent = [];
            foreach ($fields as $field => $value) {
                if (self::sendable($value)) {
                    $sent[] = ['field' => $field, 'value' => $value];
                }
            }
            $items[] = ['cms_media_id' => (string) $id, 'fields' => $sent];
        }
        $body = ['direction' => $direction, 'items' => $items, 'is_last' => $is_last];
        if ($resync_id !== '') {
            $body['resync_id'] = $resync_id;
        }

        return $body;
    }

    public static function sendable(string $value): bool
    {
        return mb_strlen($value) <= self::MAX_FIELD && !str_contains($value, "\0");
    }

    /** @param array<string, mixed> $answer */
    public static function answer_is_valid(array $answer, bool $is_last): bool
    {
        $rid    = $answer['resync_id'] ?? null;
        $status = $answer['status'] ?? null;

        return is_string($rid) && $rid !== ''
            && in_array($status, ['collecting', 'ready'], true)
            && ($is_last || $status !== 'ready');
    }
}
