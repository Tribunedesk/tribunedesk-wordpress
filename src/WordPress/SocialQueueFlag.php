<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\LinkBack;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The "do not publish on Facebook" CMS field: a post meta key from the settings whose value goes
 * to Tribune Desk as `social_queue_exclude` in the link back (2.1.0).
 *
 * An empty setting switches the feature off and the field is never sent. With a key set, every
 * report carries the field, false included, read at the moment of sending (also in the cron
 * retry), so clearing the field in the CMS reaches Tribune Desk too.
 *
 * A change of the field on an already published post is reported again, but the meta hooks only
 * note it: the value before the first change in this request is kept, and PublishNotifier sends
 * once per post, when the effective boolean differs from that value.
 */
final class SocialQueueFlag
{
    public const OPTION = 'headlinesforge_social_exclude_key';

    /** @var array<int, bool> post ID => effective value before the first change in this request */
    private static array $before = [];

    /** @var array<int, true> posts whose field was written in this request */
    private static array $touched = [];

    /** The configured meta key, or '' when the feature is off. */
    public static function meta_key(): string
    {
        return Settings::sanitize_meta_key(get_option(self::OPTION, ''));
    }

    /** The flag of a post as sent to Tribune Desk, or null when the feature is off. */
    public static function value_of(int $post_id): ?bool
    {
        $key = self::meta_key();
        if ($key === '') {
            return null;
        }

        return LinkBack::is_truthy(get_post_meta($post_id, $key, true));
    }

    /**
     * A link-back item with `social_queue_exclude` added when the feature is on.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function add_to(array $item, int $post_id): array
    {
        $value = self::value_of($post_id);
        if ($value !== null) {
            $item['social_queue_exclude'] = $value;
        }

        return $item;
    }

    /**
     * `add_post_meta` action (before the row is written): remember the value before the change.
     *
     * @param int|string $object_id
     * @param mixed      $meta_key
     */
    public static function before_add($object_id, $meta_key): void
    {
        self::remember((int) $object_id, $meta_key);
    }

    /**
     * `update_post_meta` and `delete_post_meta` actions (before the write).
     *
     * @param mixed      $meta_id meta ID, or a list of them for a delete
     * @param int|string $object_id
     * @param mixed      $meta_key
     */
    public static function before_change($meta_id, $object_id, $meta_key): void
    {
        self::remember((int) $object_id, $meta_key);
    }

    /**
     * `added_post_meta`, `updated_post_meta` and `deleted_post_meta` actions: only mark the post.
     * The report goes from PublishNotifier (wp_after_insert_post, or shutdown).
     *
     * @param mixed      $meta_id
     * @param int|string $object_id
     * @param mixed      $meta_key
     */
    public static function after_change($meta_id, $object_id, $meta_key): void
    {
        $post_id = (int) $object_id;
        if ($post_id > 0 && isset(self::$before[$post_id]) && self::is_our_key($meta_key)) {
            self::$touched[$post_id] = true;
        }
    }

    /** True when the field of the post was written in this request and its boolean changed. */
    public static function changed(int $post_id): bool
    {
        if (!isset(self::$touched[$post_id])) {
            return false;
        }
        $now = self::value_of($post_id);

        return $now !== null && $now !== self::$before[$post_id];
    }

    /** @return array<int, int> IDs of posts whose field was written in this request */
    public static function touched(): array
    {
        return array_keys(self::$touched);
    }

    /**
     * The post has been reported (or its report scheduled): the current value becomes the base,
     * so the same change is not reported a second time in this request.
     */
    public static function reported(int $post_id): void
    {
        unset(self::$touched[$post_id]);
        $now = self::value_of($post_id);
        if ($now === null) {
            unset(self::$before[$post_id]);
        } else {
            self::$before[$post_id] = $now;
        }
    }

    /** Forget everything noted in this request (tests). */
    public static function reset(): void
    {
        self::$before  = [];
        self::$touched = [];
    }

    /** @param mixed $meta_key */
    private static function remember(int $post_id, $meta_key): void
    {
        if ($post_id <= 0 || isset(self::$before[$post_id]) || !self::is_our_key($meta_key)) {
            return;
        }
        self::$before[$post_id] = (bool) self::value_of($post_id);
    }

    /** @param mixed $meta_key */
    private static function is_our_key($meta_key): bool
    {
        $key = self::meta_key();

        return $key !== '' && is_string($meta_key) && $meta_key === $key;
    }
}
