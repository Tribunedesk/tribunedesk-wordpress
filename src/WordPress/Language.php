<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 1.8.0: post language from the payload's `language` field (the org's writing language in Tribune Desk).
 *
 * Works ONLY with Polylang active (`pll_set_post_language`). The plugin runs at customers
 * without Polylang — for them the field is ignored and everything works as in 1.7.0. The language code must
 * be on the Polylang language list; an unknown one leaves the post in the default language and records an error
 * in "Recent deliveries", so the editorial team can see why the post did not reach the language version.
 * Spec: headlinesforge docs/specs/2026-10-27-chronicle-wersje-jezykowe-design.md (D4).
 */
class Language
{
    /**
     * @param array<string, mixed> $payload
     * @param callable(int, string): mixed|null $set  default pll_set_post_language
     * @param callable(): array<int, string>|null $list default slugs from pll_languages_list
     * @return string|null code of the language set, or null when nothing was done
     */
    public static function apply(int $post_id, array $payload, ?callable $set = null, ?callable $list = null): ?string
    {
        $lang = strtolower(trim((string) ($payload['language'] ?? '')));
        if ($lang === '') {
            return null;
        }
        if ($set === null || $list === null) {
            if (!function_exists('pll_set_post_language') || !function_exists('pll_languages_list')) {
                return null;
            }
            $set = $set ?? 'pll_set_post_language';
            $list = $list ?? static fn (): array => (array) pll_languages_list(['fields' => 'slug']);
        }

        $known = array_map('strval', (array) $list());
        if (!in_array($lang, $known, true)) {
            DeliveriesLog::record(
                (string) ($payload['article_id'] ?? ''),
                $post_id,
                'error',
                sprintf(
                    /* translators: 1: language code, 2: comma-separated list of configured languages */
                    __('language "%1$s" is not configured in Polylang (configured: %2$s)', 'tribune-desk'),
                    $lang,
                    implode(', ', $known)
                )
            );
            return null;
        }

        $set($post_id, $lang);
        return $lang;
    }
}
