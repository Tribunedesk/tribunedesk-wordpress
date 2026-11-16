<?php
/**
 * Plugin Name: tdkit check (CI only)
 * Description: Lets the WordPress of the `tdkit` job in .github/workflows/plugin-ci.yml talk to
 * the tdkit mock on 127.0.0.1. Never in the release ZIP, never on a real site.
 *
 * Both filters act only while TD_CA_BUNDLE is set, which `tdkit check` sets for its commands
 * and the job sets for the web server:
 * - the mock serves its API (through the job's TLS proxy) and its files over https with a test
 *   certificate: trust that CA, for these requests only;
 * - WordPress refuses loopback addresses in wp_safe_remote_* (image downloads): allow
 *   127.0.0.1, where the mock lives. Which hosts images may come from stays the plugin's own
 *   rule (ImageHosts), which `l1-image-host` checks.
 */

declare(strict_types=1);

$tdkit_ca = (string) getenv('TD_CA_BUNDLE');
if ($tdkit_ca !== '' && is_file($tdkit_ca)) {
    add_filter('http_request_args', static function (array $args) use ($tdkit_ca): array {
        $args['sslcertificates'] = $tdkit_ca;

        return $args;
    });
    add_filter('http_request_host_is_external', static function (bool $external, string $host): bool {
        return $external || $host === '127.0.0.1';
    }, 10, 2);
    // wp_safe_remote_* also allows only ports 80, 443 and 8080; the mock's files are on a free
    // port of its own.
    add_filter('http_allowed_safe_ports', static function (array $ports, string $host, string $url): array {
        $port = (int) wp_parse_url($url, PHP_URL_PORT);
        if ($host === '127.0.0.1' && $port > 0) {
            $ports[] = $port;
        }

        return $ports;
    }, 10, 3);
}
