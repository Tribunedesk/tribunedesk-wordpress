<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Decides whether an image URL from Tribune Desk may be downloaded.
 *
 * Level: 1 · Routes: GET /v3/cms/settings (image_hosts) · Guide: guide/1-article-delivery.md#where-images-come-from
 *
 * Only https, and only the hosts Tribune Desk lists in `image_hosts`. Without a list (a CMS on
 * level 1 has no token to read it, or the list is not known yet) only https is checked. An empty
 * list counts as unknown: an empty list would drop every image.
 */
final class ImageHostRule
{
    /** @param array<int, string>|null $hosts lower-case host names; null = list unknown */
    public static function allows(string $url, ?array $hosts): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        return $hosts === null || $hosts === [] || in_array($host, $hosts, true);
    }

    /**
     * The hosts of a GET /v3/cms/settings answer, lower case, without duplicates; null when the
     * answer has no usable list.
     *
     * @param array<string, mixed> $settings
     * @return array<int, string>|null
     */
    public static function hosts_from_settings(array $settings): ?array
    {
        if (!is_array($settings['image_hosts'] ?? null)) {
            return null;
        }
        $hosts = [];
        foreach ($settings['image_hosts'] as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }
        $hosts = array_values(array_unique($hosts));

        return $hosts === [] ? null : $hosts;
    }
}
