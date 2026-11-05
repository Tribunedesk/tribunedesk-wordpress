<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public filters of the plugin under their 2.0 names, with the 1.x names still honoured.
 *
 * Hooks::filter('reverse_limit', 50) runs `tribune_desk_reverse_limit` first, then passes the
 * result through `headlinesforge_reverse_limit`, so a site that filters under the old name keeps
 * working. The old names go in 3.0.0 (plan E4b, Ruling 4).
 */
final class Hooks
{
    public const PREFIX     = 'tribune_desk_';
    public const OLD_PREFIX = 'headlinesforge_';

    /**
     * @param mixed $value
     * @param mixed ...$args
     * @return mixed
     */
    public static function filter(string $name, $value, ...$args)
    {
        $value = apply_filters(self::PREFIX . $name, $value, ...$args);

        return apply_filters(self::OLD_PREFIX . $name, $value, ...$args);
    }
}
