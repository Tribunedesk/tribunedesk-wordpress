<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Decides what a delivery does to the post made from the same article.
 *
 * Level: 1 · Routes: webhook article.delivered · Guide: guide/1-article-delivery.md#article-delivery-webhook
 *
 * The same article_id comes again when an editor sends the article again. No post yet: create
 * one (a draft, or published when the site publishes at once). A draft: replace title, content
 * and author with the new send. Anything else (published, scheduled, private): leave title and
 * content alone, the newsroom may have edited them, and answer skipped_already_published; only
 * late metadata (featured image, SEO, read-more sources) still follows.
 */
final class DraftRules
{
    public const CREATE = 'created';
    public const UPDATE = 'updated';
    public const SKIP   = 'skipped_already_published';

    /** Statuses of a post that a new send of the article updates. */
    public const UPDATABLE = ['draft', 'pending'];

    /** @param string|null $existing_status status of the post with this article_id, null = none */
    public static function action(?string $existing_status): string
    {
        if ($existing_status === null) {
            return self::CREATE;
        }

        // A post nobody published yet (a draft or one waiting for review) takes the
        // new send; a published, scheduled or private one is left alone.
        return in_array($existing_status, self::UPDATABLE, true) ? self::UPDATE : self::SKIP;
    }

    /**
     * Title of the post: `title`, or `topic` when the delivery has no title.
     *
     * @param array<string, mixed> $payload
     */
    public static function title(array $payload): string
    {
        return (string) ($payload['title'] ?? $payload['topic'] ?? '');
    }

    /** The excerpt is only filled: one the editor wrote survives a new send. */
    public static function fills_excerpt(string $new_excerpt, string $current_excerpt): bool
    {
        return $new_excerpt !== '' && trim($current_excerpt) === '';
    }
}
