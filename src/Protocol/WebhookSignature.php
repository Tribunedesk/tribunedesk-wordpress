<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Decides whether an article delivery is signed by Tribune Desk (Standard Webhooks).
 *
 * Level: 1 · Routes: webhook article.delivered · Guide: guide/1-article-delivery.md#verifying-the-signature
 *
 * Portable protocol logic: no WordPress calls, only PHP. Signed content is
 * "{webhook-id}.{webhook-timestamp}.{raw body}", the key is the base64 part of the secret after
 * "whsec_", and "webhook-signature" holds one or more "v1,<base64 HMAC-SHA256>" entries
 * separated by spaces (two while Tribune Desk rotates the secret). One match is enough.
 */
final class WebhookSignature
{
    public const PREFIX    = 'whsec_';
    public const TOLERANCE = 300; // seconds, both ways

    /**
     * @param array<string, mixed> $headers Plain headers, PSR-7 getHeaders() or Symfony
     *                                      headers->all() (any case, values as lists), or
     *                                      WP_REST_Request::get_headers() ('_' instead of '-').
     * @param string               $body    The raw request body, before any JSON parsing.
     */
    public static function verify(string $secret, array $headers, string $body, ?int $now = null, int $tolerance = self::TOLERANCE): bool
    {
        $key = self::key($secret);
        if ($key === null) {
            return false;
        }

        $id         = self::header($headers, 'webhook-id');
        $timestamp  = self::header($headers, 'webhook-timestamp');
        $signatures = self::header($headers, 'webhook-signature');
        if ($id === '' || $timestamp === '' || $signatures === '' || !ctype_digit($timestamp)) {
            return false;
        }

        // A recorded request must not be accepted later: the clock check comes first.
        $now = $now ?? time();
        if (abs($now - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true));
        foreach (explode(' ', $signatures) as $entry) {
            [$version, $value] = array_pad(explode(',', $entry, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }

    public static function is_valid_secret(string $secret): bool
    {
        return self::key($secret) !== null;
    }

    private static function key(string $secret): ?string
    {
        if (!str_starts_with($secret, self::PREFIX)) {
            return null;
        }
        $key = base64_decode(substr($secret, strlen(self::PREFIX)), true);

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** @param array<string, mixed> $headers */
    private static function header(array $headers, string $name): string
    {
        $wanted = [$name, str_replace('-', '_', $name)];
        foreach ($headers as $key => $value) {
            if (!in_array(strtolower((string) $key), $wanted, true)) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }

            return trim((string) $value);
        }

        return '';
    }
}
