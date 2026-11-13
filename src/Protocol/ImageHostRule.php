<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Decides whether an image URL from Tribune Desk may be downloaded.
 *
 * Level: 1 · Routes: GET /v3/cms/status, GET /v3/cms/settings (image_hosts) · Guide: guide/1-article-delivery.md#where-images-come-from
 *
 * Only https, and only the hosts in the list. The list is `image_hosts` of GET /v3/cms/status
 * (scope articles:read or media:read) or GET /v3/cms/settings. Tribune Desk never sends it empty,
 * so an empty or unknown list allows nothing. A CMS without a token passes its own list instead:
 * the Tribune Desk storage hosts its contact gives, the domain of the organization and the host
 * of its webhook.
 */
final class ImageHostRule
{
    /** @param array<int, string>|null $hosts lower-case host names; null or empty = nothing is allowed */
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

        return $hosts !== null && in_array($host, $hosts, true);
    }

    /**
     * The hosts of a GET /v3/cms/status or GET /v3/cms/settings answer, lower case, without
     * duplicates; null when the answer has no usable list (an empty list is an error).
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
