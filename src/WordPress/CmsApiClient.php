<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\ApiErrors;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Client of the Tribune Desk /v3/cms: token in a Bearer header, https only, no redirects
 * (a redirect could carry the token to another host). Error messages are fixed English
 * source strings translated through the tribune-desk text domain: they carry neither the response body nor the transport error, so the token has no way
 * to reach the screen. The client logs nothing.
 */
class CmsApiClient
{
    public const OPTION_TOKEN = 'headlinesforge_cms_token';
    public const PREFIX       = '/v3/cms';
    public const TIMEOUT      = 5;

    public const ERROR_NO_TOKEN         = 'no_token';
    public const ERROR_INSECURE_BASE    = 'insecure_base';
    public const ERROR_TRANSPORT        = 'transport';
    public const ERROR_INVALID_RESPONSE = ApiErrors::UNKNOWN;


    public static function has_token(): bool
    {
        return self::token() !== '';
    }

    /** @return array<mixed>|\WP_Error */
    public static function get(string $path)
    {
        return self::request('GET', $path);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>|\WP_Error
     */
    public static function put(string $path, array $body)
    {
        return self::request('PUT', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>|\WP_Error
     */
    public static function post(string $path, array $body, int $timeout = self::TIMEOUT)
    {
        return self::request('POST', $path, $body, $timeout);
    }

    private static function token(): string
    {
        return trim((string) get_option(self::OPTION_TOKEN, ''));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<mixed>|\WP_Error
     */
    private static function request(string $method, string $path, ?array $body = null, int $timeout = self::TIMEOUT)
    {
        $token = self::token();
        if ($token === '') {
            return self::error(self::ERROR_NO_TOKEN);
        }

        $base = PublishedUrlsClient::api_base();
        if (strtolower((string) wp_parse_url($base, PHP_URL_SCHEME)) !== 'https' || (string) wp_parse_url($base, PHP_URL_HOST) === '') {
            return self::error(self::ERROR_INSECURE_BASE);
        }

        $args = [
            'method'      => $method,
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        $response = wp_remote_request($base . self::PREFIX . $path, $args);
        if (is_wp_error($response)) {
            return self::error(self::ERROR_TRANSPORT);
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status >= 200 && $status < 300) {
            return is_array($decoded) ? $decoded : self::error(self::ERROR_INVALID_RESPONSE, $status);
        }

        return self::error(ApiErrors::code($status, $decoded), $status, ApiErrors::retry_after($decoded));
    }

    /** @param mixed $retry_after */
    private static function error(string $code, int $status = 0, $retry_after = null): \WP_Error
    {
        return new \WP_Error($code, self::message($code), [
            'status'      => $status,
            'retry_after' => is_int($retry_after) ? $retry_after : null,
        ]);
    }

    private static function message(string $code): string
    {
        return match ($code) {
            self::ERROR_NO_TOKEN      => __('The integration is not connected: the CMS API token is missing.', 'tribune-desk'),
            self::ERROR_INSECURE_BASE => __('The Tribune Desk API address must start with https://, so the plugin did not send the token.', 'tribune-desk'),
            self::ERROR_TRANSPORT     => __('Could not connect to Tribune Desk. Try again in a moment.', 'tribune-desk'),
            'unauthorized'            => __('Tribune Desk rejected the token: it is invalid or revoked.', 'tribune-desk'),
            'forbidden'               => __('The token lacks permission for this operation. Required: articles:write, settings:read, settings:write, media:read, media:write, backfill:write.', 'tribune-desk'),
            'not_found'               => __('Tribune Desk does not know this object in this organization.', 'tribune-desk'),
            'project_deactivated'     => __('The organization in Tribune Desk is frozen and does not accept changes.', 'tribune-desk'),
            'option_locked'           => __('This change was not saved: it includes an option locked by the Tribune Desk administrator.', 'tribune-desk'),
            'state_conflict'          => __('Tribune Desk cannot do this now: the integration is not enabled or the batch is no longer awaiting a decision. If the batch is too old, reject it and start a new resync.','tribune-desk'),
            'validation_error'        => __('Tribune Desk did not accept these values.', 'tribune-desk'),
            'cursor_expired'          => __('The changes cursor expired: fetching is paused until the fields are synced with Tribune Desk.', 'tribune-desk'),
            'rate_limited'            => __('Too many requests to Tribune Desk. Try again in a moment.', 'tribune-desk'),
            'internal_error',
            'unavailable'             => __('Tribune Desk is temporarily unavailable. Try again in a moment.', 'tribune-desk'),
            default                   => __('Tribune Desk returned an unexpected response.', 'tribune-desk'),
        };
    }
}
