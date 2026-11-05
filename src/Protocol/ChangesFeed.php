<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Rules of the media changes feed: versions of fields, the keep-nonempty rule, and field writes.
 *
 * Level: 4 · Routes: GET /v3/cms/changes, POST /v3/cms/media/fields · Guide: guide/4-media-library.md#the-sync-cycle
 *
 * Every field change carries a version. The CMS stores the version it applied and sends it back
 * as `base_version` with its own edits, so Tribune Desk can tell a conflict from an update.
 * With `keep_nonempty_td_to_cms`, a value from Tribune Desk does not overwrite a non-empty value
 * the CMS already has: the version is still recorded, the value is kept aside. A kept value is
 * replaced only by a newer one and dropped once a version at least as new was applied.
 */
final class ChangesFeed
{
    /** Results of a field send after which the sent versions are stored. */
    public const VERSION_STATUSES = ['ok', 'unchanged', 'conflict'];

    /**
     * The highest version among the fields of one change (0 when none): with the media id it
     * identifies one row of the feed, e.g. for counting failed attempts.
     *
     * @param array<string, mixed> $change
     */
    public static function row_version(array $change): int
    {
        $top = 0;
        foreach (is_array($change['fields'] ?? null) ? $change['fields'] : [] as $row) {
            $top = max($top, is_array($row) ? (int) ($row['version'] ?? 0) : 0);
        }

        return $top;
    }

    /** Would writing $incoming replace a different, non-empty value? Only then the keep rule matters. */
    public static function overwrites_nonempty(string $current, string $incoming): bool
    {
        return $current !== $incoming && trim($current) !== '';
    }

    /** A kept value is replaced by a value at least as new. */
    public static function replaces_kept(int $kept_version, int $version): bool
    {
        return $version >= $kept_version;
    }

    /** A kept value goes once a version at least as new has been applied. */
    public static function drops_kept(int $kept_version, int $applied_version): bool
    {
        return $kept_version <= $applied_version;
    }

    /**
     * One entry of POST /v3/cms/media/fields. Without `base_version` the server would ignore
     * versions and the newer date would win over the `td` rule; a field marked before dates were
     * recorded counts from the moment of sending.
     *
     * @return array{field: string, value: string, base_version: int, changed_at: string}
     */
    public static function field_write(string $field, string $value, ?int $base_version, ?int $changed_at, int $now): array
    {
        return [
            'field'        => $field,
            'value'        => $value,
            'base_version' => $base_version ?? 1,
            'changed_at'   => gmdate('c', $changed_at ?? $now),
        ];
    }

    /** @param mixed $status from the server's JSON; anything but a known string stores nothing */
    public static function stores_versions($status): bool
    {
        return is_string($status) && in_array($status, self::VERSION_STATUSES, true);
    }
}
