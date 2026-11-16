<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Developer commands `wp td …` (WP-CLI) for `tdkit check`, the test of the Tribune Desk CMS
 * API (Tribunedesk/tribunedesk-cms-kit; spec "kontrakt najpierw" D2e). A thin layer over
 * DevCommands: arguments in, JSON out, an error ends with a non-zero exit code.
 *
 *     wp td inspect <article_id>        the post made from the article, as JSON, or null
 *     wp td publish <article_id>        publish it as an editor would; the link back is sent
 *     wp td sync [--backfill]           one whole sync cycle of the media library (level 4)
 *     wp td media inspect <id>          an attachment, as JSON, or null
 *     wp td media add <file>            add an image as an editor would; {"cms_media_id": "…"}
 *     wp td media edit <id> <field> <value>   alt, caption, title or description
 *     wp td media delete <id>           delete an attachment as an editor would
 *     wp td reset                       clear the level 4 state (cursor, versions, queues, pairs)
 */
final class Cli
{
    public static function register(): void
    {
        \WP_CLI::add_command('td inspect', [self::class, 'inspect']);
        \WP_CLI::add_command('td publish', [self::class, 'publish']);
        \WP_CLI::add_command('td sync', [self::class, 'sync']);
        \WP_CLI::add_command('td media inspect', [self::class, 'media_inspect']);
        \WP_CLI::add_command('td media add', [self::class, 'media_add']);
        \WP_CLI::add_command('td media edit', [self::class, 'media_edit']);
        \WP_CLI::add_command('td media delete', [self::class, 'media_delete']);
        \WP_CLI::add_command('td reset', [self::class, 'reset']);
    }

    /** @param list<string> $args */
    public static function inspect(array $args): void
    {
        self::json(DevCommands::inspect(self::arg($args, 0)));
    }

    /** @param list<string> $args */
    public static function publish(array $args): void
    {
        self::run(static fn () => DevCommands::publish(self::arg($args, 0)));
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public static function sync(array $args, array $assoc = []): void
    {
        self::run(static fn () => DevCommands::sync(!empty($assoc['backfill'])));
    }

    /** @param list<string> $args */
    public static function media_inspect(array $args): void
    {
        self::json(DevCommands::mediaInspect((int) self::arg($args, 0)));
    }

    /** @param list<string> $args */
    public static function media_add(array $args): void
    {
        $id = 0;
        self::run(static function () use ($args, &$id): void {
            $id = DevCommands::mediaAdd(self::arg($args, 0));
        });
        self::json(['cms_media_id' => (string) $id]);
    }

    /** @param list<string> $args */
    public static function media_edit(array $args): void
    {
        self::run(static fn () => DevCommands::mediaEdit((int) self::arg($args, 0), self::arg($args, 1), self::arg($args, 2)));
    }

    /** @param list<string> $args */
    public static function media_delete(array $args): void
    {
        self::run(static fn () => DevCommands::mediaDelete((int) self::arg($args, 0)));
    }

    public static function reset(): void
    {
        self::run(static fn () => DevCommands::reset());
    }

    /** @param list<string> $args */
    private static function arg(array $args, int $i): string
    {
        if (!isset($args[$i])) {
            \WP_CLI::error('missing argument ' . ($i + 1));
        }

        return (string) $args[$i];
    }

    private static function run(callable $command): void
    {
        try {
            $command();
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /** @param mixed $value */
    private static function json($value): void
    {
        \WP_CLI::line((string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
