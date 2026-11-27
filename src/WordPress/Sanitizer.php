<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

class Sanitizer
{
    /**
     * Attributes an embed keeps. PostWriter allows the same set while it saves the post,
     * because WordPress filters post content again on save (kses), and a delivery runs as
     * user 0, who has no `unfiltered_html`.
     */
    public const IFRAME_ATTRIBUTES = [
        'src'             => true,
        'width'           => true,
        'height'          => true,
        'frameborder'     => true,
        'allowfullscreen' => true,
        'allow'           => true,
        'title'           => true,
    ];

    /**
     * Embeds kept in content: exact host (any case) => path prefix. 2.7.1: the address is parsed
     * first; before that a regex compared the start of the text, with the last `src=` of the tag.
     */
    private const ALLOWED_IFRAME_HOSTS = [
        'youtube.com'         => '/embed/',
        'www.youtube.com'     => '/embed/',
        'player.vimeo.com'    => '/video/',
        'platform.twitter.com' => '/',
        'www.instagram.com'   => '/embed',
        'www.tiktok.com'      => '/embed/',
        'www.facebook.com'    => '/plugins/',
    ];

    public static function sanitize(string $html): string
    {
        // wp_kses removes a DISALLOWED TAG but leaves its content as
        // plain text - so <script> itself disappears, but its code lands in the post content.
        // That is how the AI markup block (JSON-LD) ended up on published pages as a base64
        // paragraph. We cut out the whole element with its inside before kses
        // takes it apart. Fail-closed like the second pass below: a null from preg_replace
        // (e.g. backtracking limit) gives empty content, not unchecked content.
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';

        $allowed = wp_kses_allowed_html('post');
        $allowed['iframe'] = self::IFRAME_ATTRIBUTES;

        $sanitized = wp_kses($html, $allowed);

        return self::filter_iframes($sanitized);
    }

    /**
     * Second pass: kses checks only the protocol of `src`, so iframes are walked tag by tag.
     * An iframe whose `src` is not an allowed embed is removed with its content; an allowed one
     * keeps its tag and loses its content (fallback text a browser never shows, and the place
     * where an iframe could hide inside another). An iframe without `</iframe>` is taken as empty:
     * a foreign one disappears, an allowed one is closed, and what follows it stays visible.
     * A stray `</iframe>` is dropped. After kses no tag holds a `>` inside it (kses splits on
     * it), so `[^>]*` covers a whole opening tag. Fail-closed: a split that fails gives ''.
     */
    private static function filter_iframes(string $html): string
    {
        $parts = preg_split('#(<iframe\b[^>]*>|</iframe\s*>)#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return '';
        }
        $out    = '';
        $inside = null; // null: outside an iframe; true/false: inside one that is kept / dropped
        $count  = count($parts);
        for ($i = 0; $i < $count; $i++) {
            $part = $parts[$i];
            if ($i % 2 === 0) {
                if ($inside === null) {
                    $out .= $part;
                }
                continue;
            }
            $closing = str_starts_with($part, '</');
            if ($inside !== null) {
                if ($closing) {
                    $out   .= $inside ? '</iframe>' : '';
                    $inside = null;
                }
                continue;
            }
            if ($closing) {
                continue;
            }
            $keep = self::allowed_embed($part);
            if (self::has_closing($parts, $i + 1)) {
                $out   .= $keep ? $part : '';
                $inside = $keep;
            } elseif ($keep) {
                $out .= $part . '</iframe>';
            }
        }

        return $out;
    }

    /** @param array<int, string> $parts */
    private static function has_closing(array $parts, int $from): bool
    {
        for ($i = $from, $n = count($parts); $i < $n; $i++) {
            if ($i % 2 === 1 && str_starts_with($parts[$i], '</')) {
                return true;
            }
        }

        return false;
    }

    /** Whether the opening tag's `src` (its first one, as browsers read it) is an allowed embed. */
    private static function allowed_embed(string $tag): bool
    {
        $attrs = substr($tag, strlen('<iframe'), -1);
        $src   = null;
        foreach (wp_kses_hair(rtrim($attrs, '/'), ['http', 'https']) as $attr) {
            if (strtolower((string) $attr['name']) === 'src') {
                $src = html_entity_decode((string) $attr['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                break;
            }
        }

        return $src !== null && self::allowed_src($src);
    }

    private static function allowed_src(string $src): bool
    {
        // A backslash, space or control character is read differently by browsers and parse_url.
        if (preg_match('#[\x5c\s\x00-\x1f\x7f]#', $src)) {
            return false;
        }
        $parts = wp_parse_url($src);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' && $scheme !== 'http') {
            return false;
        }
        $prefix = self::ALLOWED_IFRAME_HOSTS[strtolower((string) ($parts['host'] ?? ''))] ?? null;

        return $prefix !== null && str_starts_with((string) ($parts['path'] ?? ''), $prefix);
    }
}
