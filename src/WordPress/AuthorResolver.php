<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\AuthorOrder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post author chosen from the people Tribune Desk sends (spec HF 2026-10-20 post author, D1/D4/D5).
 *
 * Order: `sent_by` (who clicked "Send to CMS"), then `author` (who ordered the article).
 * For each person: the manual assignment from settings, then the WP account with the same e-mail.
 * Only an account with `edit_posts` counts — a subscriber with the same address does not become the author.
 * Nobody matches = the default author, exactly as before 1.2.0.
 */
class AuthorResolver
{
    public const OPTION_MAP = 'headlinesforge_author_map';
    public const OPTION_SEEN = 'headlinesforge_seen_authors';
    public const SEEN_LIMIT = 100;


    /** @param array<string, mixed> $payload */
    public static function resolve(array $payload): int
    {
        $people = self::people_of($payload);
        self::remember($people);

        return AuthorOrder::choose($people, [self::class, 'match'], self::default_author_id());
    }

    /**
     * The match for one person, or null. Public because the settings screen shows, next to each
     * person, who the plugin would pick now — the same rule, not a copy of it.
     *
     * @param array{id: string, name: string, email: string} $person
     * @return array{user_id: int, via: string}|null
     */
    public static function match_with_reason(array $person): ?array
    {
        $map = self::map();
        if (isset($map[$person['id']]) && self::can_author($map[$person['id']])) {
            return ['user_id' => $map[$person['id']], 'via' => 'map'];
        }
        if ($person['email'] !== '') {
            $user = get_user_by('email', $person['email']);
            if ($user !== false && self::can_author((int) $user->ID)) {
                return ['user_id' => (int) $user->ID, 'via' => 'email'];
            }
        }

        return null;
    }

    /** @return array<string, int> Tribune Desk person id => WP user ID */
    public static function map(): array
    {
        $raw = get_option(self::OPTION_MAP, []);

        return is_array($raw) ? self::sanitize_map($raw) : [];
    }

    /**
     * Sanitizing the map option (register_setting). 0 = "automatic", i.e. no entry.
     *
     * @param mixed $value
     * @return array<string, int>
     */
    public static function sanitize_map($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $clean = [];
        foreach ($value as $hf_id => $user_id) {
            $key = sanitize_text_field((string) $hf_id);
            $uid = absint($user_id);
            if ($key !== '' && $uid > 0) {
                $clean[$key] = $uid;
            }
        }

        return $clean;
    }

    /** @return array<string, array{name: string, email: string, last_seen: int}> newest first */
    public static function seen(): array
    {
        $raw = get_option(self::OPTION_SEEN, []);
        if (!is_array($raw)) {
            return [];
        }
        $seen = array_filter($raw, 'is_array');
        uasort($seen, static fn(array $a, array $b): int => ($b['last_seen'] ?? 0) <=> ($a['last_seen'] ?? 0));

        return $seen;
    }

    /** Accounts that may be an author: the list to choose from in settings.
     *  @return \WP_User[] */
    public static function eligible_users(): array
    {
        // `capability` in get_users exists since WP 5.9; older core ignores it — the filter below
        // keeps the rule on every version, and on a new one the query is narrow anyway.
        $users = get_users(['capability' => 'edit_posts', 'orderby' => 'display_name']);

        return array_values(array_filter($users, static fn(\WP_User $u): bool => user_can($u, 'edit_posts')));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{id: string, name: string, email: string}>
     */
    private static function people_of(array $payload): array
    {
        // Order and shape from the protocol; WordPress sanitizing on top (ids and names are shown
        // in the settings screen, e-mails are looked up).
        $people = [];
        foreach (AuthorOrder::people($payload) as $person) {
            $id = sanitize_text_field($person['id']);
            if ($id === '') {
                continue;
            }
            $people[] = [
                'id'    => $id,
                'name'  => sanitize_text_field($person['name']),
                'email' => sanitize_email($person['email']),
            ];
        }

        return $people;
    }

    /** @param array{id: string, name: string, email: string} $person */
    public static function match(array $person): ?int
    {
        $found = self::match_with_reason($person);

        return $found === null ? null : $found['user_id'];
    }

    /** @param array<int, array{id: string, name: string, email: string}> $people */
    private static function remember(array $people): void
    {
        if ($people === []) {
            return;
        }
        $seen = self::seen();
        $now = time();
        foreach ($people as $person) {
            $previous = $seen[$person['id']] ?? ['name' => '', 'email' => ''];
            // An old delivery row in Tribune Desk may send just the id — then we do not erase
            // the name and e-mail remembered from an earlier send.
            $seen[$person['id']] = [
                'name'      => $person['name'] !== '' ? $person['name'] : (string) $previous['name'],
                'email'     => $person['email'] !== '' ? $person['email'] : (string) $previous['email'],
                'last_seen' => $now,
            ];
        }
        uasort($seen, static fn(array $a, array $b): int => $b['last_seen'] <=> $a['last_seen']);
        // No autoload: the list is needed only on receiving and on the settings screen.
        update_option(self::OPTION_SEEN, array_slice($seen, 0, self::SEEN_LIMIT, true), false);
    }

    private static function can_author(int $user_id): bool
    {
        return $user_id > 0 && get_user_by('id', $user_id) !== false && user_can($user_id, 'edit_posts');
    }

    /** The pre-1.2.0 rule: the author from settings, then whoever installed the plugin, then ID 1. */
    public static function default_author_id(): int
    {
        $configured = (int) get_option(RestEndpoint::OPTION_DEFAULT_AUTHOR, 0);
        if ($configured > 0 && get_user_by('id', $configured) !== false) {
            return $configured;
        }
        $fallback = (int) get_option(Activator::OPTION_INSTALL_USER, 0);

        return $fallback > 0 ? $fallback : 1; // last-resort: user ID 1 (first admin in fresh WP)
    }
}
