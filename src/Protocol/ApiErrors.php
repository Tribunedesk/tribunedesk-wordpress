<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Reads an error answer of the /v3/cms API: which error it is and how long to wait.
 *
 * Level: all · Routes: every /v3/cms route · Guide: README.md#errors
 *
 * Every error the application produces has the body {"code", "message", "retry_after"}; match on
 * `code`, the message is for people. A gateway in front of the API may answer without that body
 * (502, 504, an HTML page): then the HTTP status decides. `retry_after` is present only when
 * waiting helps.
 */
final class ApiErrors
{
    public const CODES = [
        'unauthorized', 'forbidden', 'not_found', 'project_deactivated', 'option_locked', 'cursor_expired',
        'validation_error', 'rate_limited', 'internal_error', 'unavailable', 'not_implemented', 'state_conflict',
    ];

    /** Code used when neither the body nor the status says what happened. */
    public const UNKNOWN = 'invalid_response';

    /** @param mixed $decoded the JSON-decoded body, or anything else */
    public static function code(int $status, $decoded): string
    {
        $code = is_array($decoded) ? ($decoded['code'] ?? null) : null;
        if (is_string($code) && in_array($code, self::CODES, true)) {
            return $code;
        }

        return match (true) {
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status === 503 => 'unavailable',
            $status >= 500  => 'internal_error',
            default         => self::UNKNOWN,
        };
    }

    /** @param mixed $decoded */
    public static function retry_after($decoded): ?int
    {
        $value = is_array($decoded) ? ($decoded['retry_after'] ?? null) : null;

        return is_int($value) ? $value : null;
    }
}
