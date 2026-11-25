<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * The publication mode of a delivery: what the post becomes, and the state the answer reports.
 *
 * Level: 1 · Routes: webhook article.delivered · Guide: guide/1-article-delivery.md#article-delivery-webhook
 *
 * Contract 3.7: the optional `publication` asks for a draft (`draft`), a post published now
 * (`publish`) or one scheduled for `at` (`schedule`, ISO 8601 with an offset). Without the field
 * the site publishes as it does today. A scheduled post is not public yet: a new send updates it
 * like a draft (DraftRules) and may move it, make it a draft again or publish it. A published or
 * private post is never changed by the field. A time that has already passed is published at
 * once. A field that cannot be read is ignored, like any field this library does not know.
 *
 * The statuses are WordPress's (`draft`, `pending`, `future`, `publish`, `private`): another CMS
 * maps its own states to them before calling target() and answer().
 */
final class Publication
{
    /** target(): leave the post's status and date as they are. */
    public const KEEP = 'keep';

    /** Statuses whose post the field may change: not public yet. */
    private const CHANGEABLE = ['draft', 'pending', 'future'];

    /**
     * The status and publication moment (Unix time, GMT) the post gets from this delivery.
     *
     * `date_gmt` is set only for `future`; for `publish` the CMS uses the moment of publishing,
     * for `draft` the date is cleared (a draft has no date of its own).
     *
     * @param string|null $existing_status status of the post with this article_id, null = none
     * @param array<string, mixed>|null $publication the delivery's `publication`, null = absent
     * @param bool $site_publishes the site's own choice for a new post without the field
     * @return array{status: string, date_gmt: int|null} status `draft`, `publish`, `future` or KEEP
     */
    public static function target(?string $existing_status, ?array $publication, bool $site_publishes, int $now_gmt): array
    {
        $wanted = self::wanted($publication, $now_gmt);
        if ($existing_status === null) {
            return $wanted ?? ['status' => $site_publishes ? 'publish' : 'draft', 'date_gmt' => null];
        }
        if ($wanted === null || !in_array($existing_status, self::CHANGEABLE, true)) {
            return ['status' => self::KEEP, 'date_gmt' => null];
        }

        return $wanted;
    }

    /**
     * The answer's `publication` for a post in $status with its publication moment $date_gmt;
     * null for a state the contract does not name (private, trash, a schedule without a date).
     *
     * @return array{status: string, at: string|null}|null
     */
    public static function answer(string $status, ?int $date_gmt): ?array
    {
        $at = $date_gmt === null ? null : gmdate('Y-m-d\TH:i:s\Z', $date_gmt);

        return match ($status) {
            'draft', 'pending' => ['status' => 'draft', 'at' => null],
            'future' => $at === null ? null : ['status' => 'scheduled', 'at' => $at],
            'publish' => ['status' => 'published', 'at' => $at],
            default => null,
        };
    }

    /**
     * @param array<string, mixed>|null $publication
     * @return array{status: string, date_gmt: int|null}|null null when the field is absent or unreadable
     */
    private static function wanted(?array $publication, int $now_gmt): ?array
    {
        $mode = $publication['mode'] ?? null;
        if ($mode === 'draft') {
            return ['status' => 'draft', 'date_gmt' => null];
        }
        if ($mode === 'publish') {
            return ['status' => 'publish', 'date_gmt' => null];
        }
        if ($mode !== 'schedule') {
            return null;
        }
        $at = self::instant($publication['at'] ?? null);
        if ($at === null) {
            return null;
        }

        return $at > $now_gmt
            ? ['status' => 'future', 'date_gmt' => $at]
            : ['status' => 'publish', 'date_gmt' => null];
    }

    /** An ISO 8601 moment with an offset (`Z` or `±hh:mm`) as Unix time; null otherwise. */
    private static function instant(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, str_replace('Z', '+00:00', $value))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', str_replace('Z', '+00:00', $value));

        return $date === false ? null : $date->getTimestamp();
    }
}
