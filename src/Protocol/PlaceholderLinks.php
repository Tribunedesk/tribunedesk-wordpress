<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Replaces placeholder links in delivered HTML with the CMS's own addresses.
 *
 * Level: 3 · Routes: webhook article.delivered (html, internal_links, link_placeholder_prefix) · Guide: guide/3-full-content.md#internal-links
 *
 * A link in `html` to another article of the newsroom points to a placeholder:
 * `link_placeholder_prefix` + the target's article_id. Step by step:
 *  1. for each `internal_links` entry of kind "placeholder", find the CMS post of its article_id;
 *  2. found and public: replace the placeholder (with an optional trailing "/") by its address;
 *  3. when publishing, a target that does not exist in the CMS at all: drop the <a>, keep its text;
 *  4. a target that exists but is not public yet keeps its placeholder, so publishing it later
 *     can fix the earlier posts.
 * Every placeholder contains the path of the prefix (today "/app/a/"); that path is the cheap
 * check "is there anything to replace" before any lookup.
 */
final class PlaceholderLinks
{
    public const DEFAULT_PATH = '/app/a/';

    /** Path of a `link_placeholder_prefix` ("https://host/app/a/" => "/app/a/"), or null when unusable. */
    public static function path_of_prefix(mixed $prefix): ?string
    {
        if (!is_string($prefix) || !preg_match('#^https?://[^/]+(/\S*/)$#i', $prefix, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Step 2: placeholders whose target has a public address.
     *
     * @param array<int, mixed>          $links      `internal_links` entries
     * @param callable(string): ?string  $address_of article_id => public address, or null
     */
    public static function resolve(string $html, array $links, string $marker, callable $address_of): string
    {
        if ($html === '' || !str_contains($html, $marker)) {
            return $html;
        }
        foreach ($links as $link) {
            if (!is_array($link) || ($link['kind'] ?? '') !== 'placeholder') {
                continue;
            }
            $href      = (string) ($link['href'] ?? '');
            $target_id = (string) ($link['article_id'] ?? '');
            if ($href === '' || $target_id === '' || !str_contains($href, $marker) || !str_contains($html, $href)) {
                continue;
            }
            $address = $address_of($target_id);
            if ($address !== null) {
                $html = self::replace_href($html, $href, $address);
            }
        }

        return $html;
    }

    /**
     * Step 3: placeholders whose target does not exist in the CMS in any state.
     *
     * @param array<int, mixed>       $links  `internal_links` entries
     * @param callable(string): bool  $exists article_id => is there a post for it, in any status
     */
    public static function unwrap_missing(string $html, array $links, string $marker, callable $exists): string
    {
        if ($html === '' || !str_contains($html, $marker)) {
            return $html;
        }
        foreach ($links as $link) {
            if (!is_array($link) || ($link['kind'] ?? '') !== 'placeholder') {
                continue;
            }
            $href      = (string) ($link['href'] ?? '');
            $target_id = (string) ($link['article_id'] ?? '');
            if ($href === '' || $target_id === '' || !str_contains($html, $href) || $exists($target_id)) {
                continue;
            }
            $html = self::unwrap_href($html, $href);
        }

        return $html;
    }

    /**
     * The whole <a> with exactly this placeholder in href => its content. Tolerates slashed
     * quotes (\" while WordPress saves a post) and extra attributes (target, rel).
     */
    public static function unwrap_href(string $html, string $placeholder): string
    {
        $pattern = '~<a\s[^>]*?\bhref=\\\\?(["\'])' . preg_quote($placeholder, '~') . '/?\\\\?\1[^>]*>(.*?)</a>~is';
        $out     = preg_replace_callback($pattern, static fn(array $m): string => $m[2], $html);

        return $out ?? $html;
    }

    /**
     * The placeholder with an optional trailing "/", but only when an attribute delimiter
     * follows (quote, apostrophe, backslash, white space, ">", or a quote entity): a longer URL
     * with the same prefix stays untouched, and an address ending in "/" gets no second slash.
     * A callback, not a replacement string, so the address is never read as "$1".
     */
    public static function replace_href(string $html, string $placeholder, string $address): string
    {
        $pattern = '~' . preg_quote($placeholder, '~') . '/?(?=["\'\\\\\s>]|&quot;|&#0?39;)~';
        $out     = preg_replace_callback($pattern, static fn(array $m): string => $address, $html);

        return $out ?? $html;
    }
}
