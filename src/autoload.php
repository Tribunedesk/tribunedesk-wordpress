<?php
declare(strict_types=1);

/**
 * Autoloader of the plugin: TribuneDesk\<Dir>\<Class> lives in src/<Dir>/<Class>.php.
 *
 * No Composer in the release ZIP, and two directories need no more than this. A 1.x name
 * (HeadlinesForge\<Class>) is turned into an alias of its 2.0 class on first use, from the map
 * in src/aliases.php, so nothing is loaded before something asks for it.
 */

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    static $aliases = null;

    if (str_starts_with($class, 'TribuneDesk\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('TribuneDesk\\'))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }

        return;
    }

    if (str_starts_with($class, 'HeadlinesForge\\')) {
        $aliases ??= require __DIR__ . '/aliases.php';
        if (isset($aliases[$class])) {
            class_alias($aliases[$class], $class);
        }
    }
});
