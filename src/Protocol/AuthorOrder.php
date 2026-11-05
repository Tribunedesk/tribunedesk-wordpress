<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Decides who becomes the author of a delivered post.
 *
 * Level: 3 · Routes: webhook article.delivered (sent_by, author) · Guide: guide/3-full-content.md#seo-author-and-language
 *
 * People are tried in this order: `sent_by` (who clicked "Send to CMS"), then `author` (who
 * ordered the article). For each person the CMS first looks at its own mapping of Tribune Desk
 * ids to users, then at a user with the same e-mail. The first match wins; without one the
 * site's default author is used.
 */
final class AuthorOrder
{
    public const PEOPLE_KEYS = ['sent_by', 'author'];

    /**
     * The people of a delivery, in the order they are tried. Entries without an id are dropped.
     *
     * @param array<string, mixed> $payload
     * @return array<int, array{id: string, name: string, email: string}>
     */
    public static function people(array $payload): array
    {
        $people = [];
        foreach (self::PEOPLE_KEYS as $key) {
            $raw = $payload[$key] ?? null;
            if (!is_array($raw)) {
                continue;
            }
            $id = trim((string) ($raw['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $people[] = [
                'id'    => $id,
                'name'  => trim((string) ($raw['name'] ?? '')),
                'email' => trim((string) ($raw['email'] ?? '')),
            ];
        }

        return $people;
    }

    /**
     * The first person a matcher finds, else the default author.
     *
     * @param array<int, array{id: string, name: string, email: string}> $people
     * @param callable(array{id: string, name: string, email: string}): ?int $match
     */
    public static function choose(array $people, callable $match, int $default_author): int
    {
        foreach ($people as $person) {
            $user_id = $match($person);
            if ($user_id !== null) {
                return $user_id;
            }
        }

        return $default_author;
    }
}
