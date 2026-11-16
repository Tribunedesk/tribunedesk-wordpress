<?php
declare(strict_types=1);

namespace TribuneDesk\Ci;

/**
 * Anchors of Markdown headings, made as GitHub makes them. Used by the test of
 * docs/contract-map.json and by ci/ContractDrift.php. Not part of the plugin: the release ZIP
 * carries src/ only.
 */
final class GuideAnchors
{
    /** GitHub's anchor of one heading: lower case, punctuation dropped, spaces to hyphens. */
    public static function slug(string $heading): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}\p{M} _-]/u', '', mb_strtolower(trim($heading), 'UTF-8'));

        return str_replace(' ', '-', (string) $slug);
    }

    /**
     * The anchors of every heading outside fenced code blocks, in order; a repeated anchor gets
     * `-1`, `-2`, … as on GitHub.
     *
     * @return list<string>
     */
    public static function anchors(string $markdown): array
    {
        $anchors = [];
        $seen = [];
        $fence = null;
        foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                if ($fence === null) {
                    $fence = $m[1][0];
                } elseif ($m[1][0] === $fence) {
                    $fence = null;
                }
                continue;
            }
            if ($fence !== null || !preg_match('/^#{1,6} +(.+?) *#*$/', $line, $h)) {
                continue;
            }
            $slug = self::slug($h[1]);
            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            $anchors[] = $count === 0 ? $slug : "$slug-$count";
        }

        return $anchors;
    }
}
