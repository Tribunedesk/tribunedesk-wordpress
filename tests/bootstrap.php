<?php
declare(strict_types=1);

// Path to the WP test suite (provided by wp-env's test container).
$_tests_dir = getenv('WP_TESTS_DIR') ?: '/var/www/html/wp-content/plugins/wordpress-develop/tests/phpunit';

if (!file_exists($_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "Could not find $_tests_dir/includes/functions.php. Run via wp-env.\n");
    exit(1);
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/headlinesforge-receiver.php';
});

require $_tests_dir . '/includes/bootstrap.php';

/**
 * The suite runs as a Polish site, like every install today: the existing tests check the texts
 * people see in Polish, which since 2.0.0 come from languages/tribune-desk-pl_PL.mo.
 */
function tribune_desk_tests_load_polish(): void
{
    unload_textdomain('tribune-desk');
    load_textdomain('tribune-desk', dirname(__DIR__) . '/languages/tribune-desk-pl_PL.mo');
}
tribune_desk_tests_load_polish();
