<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

class Sanitizer
{
    private const ALLOWED_IFRAME_HOSTS = [
        'youtube.com/embed/',
        'www.youtube.com/embed/',
        'player.vimeo.com/video/',
        'platform.twitter.com/',
        'www.instagram.com/embed',
        'www.tiktok.com/embed/',
        'www.facebook.com/plugins/',
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
        $allowed['iframe'] = [
            'src' => true,
            'width' => true,
            'height' => true,
            'frameborder' => true,
            'allowfullscreen' => true,
            'allow' => true,
            'title' => true,
        ];

        $sanitized = wp_kses($html, $allowed);

        // Second pass: drop iframes whose src host is not whitelisted.
        // wp_kses doesn't validate URL host, only the protocol.
        return preg_replace_callback(
            '/<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>.*?<\/iframe>/is',
            static function (array $m): string {
                $url = $m[1];
                $stripped = preg_replace('#^https?://#', '', $url);
                foreach (self::ALLOWED_IFRAME_HOSTS as $host) {
                    if (str_starts_with($stripped, $host)) {
                        return $m[0];
                    }
                }
                return '';
            },
            $sanitized
        ) ?? '';
    }
}
