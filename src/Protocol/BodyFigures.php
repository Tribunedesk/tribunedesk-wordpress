<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Finds the photos of an article body: every `<figure>` with exactly one `<img>`, and replaces
 * the ones a CMS wants to turn into its own native image.
 *
 * Level: 4 · Routes: webhook article.delivered (html) · Guide: guide/1-article-delivery.md#the-html-body
 *
 * Tribune Desk marks a library photo with `data-td-asset-id` (its id) and `data-td-cms-media-id`
 * (the id the CMS gave the file) on the `<img>`. Text matching, not a parser: a parser would
 * rewrite the rest of the document, and everything outside a replaced figure stays byte for byte.
 * Tags are matched with their quotes in mind: a `>` inside an attribute value does not end a tag.
 * A figure with no `<img>`, or with two (an embed, a gallery), is not a photo and is not returned.
 * Tags inside HTML comments or `<script>` are not excluded, and nested figures are not supported.
 */
final class BodyFigures
{
    private const TAG = '#<(/?)([a-z][a-z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#i';

    /**
     * @return list<array{start: int, end: int, src: string, alt: string, caption: string, align: string, asset_id: string, cms_media_id: string}>
     *         start/end = byte offsets of the whole `<figure>…</figure>`; caption = inner HTML of the
     *         `<figcaption>`, trimmed ('' when there is none — also for a caption hidden in
     *         `data-hf-caption`); align = left|right|center from the figure's alignleft|alignright|
     *         aligncenter class, otherwise none
     */
    public static function find(string $html): array
    {
        $tags = self::tags($html);
        $out  = [];
        $skip = -1;
        foreach ($tags as $i => $tag) {
            if ($tag['closing'] || $tag['name'] !== 'figure' || $tag['start'] < $skip) {
                continue;
            }
            // The first `</figure>` ends it — figures do not nest in the HTML Tribune Desk writes.
            $close = null;
            for ($j = $i + 1, $n = count($tags); $j < $n; $j++) {
                if ($tags[$j]['closing'] && $tags[$j]['name'] === 'figure') {
                    $close = $tags[$j];
                    break;
                }
            }
            if ($close === null) {
                continue;
            }
            $skip   = $close['end'];
            $inside = array_values(array_filter(
                $tags,
                static fn (array $t): bool => $t['start'] > $tag['end'] - 1 && $t['end'] <= $close['start'] && !$t['closing']
            ));
            $imgs = array_values(array_filter($inside, static fn (array $t): bool => $t['name'] === 'img'));
            if (count($imgs) !== 1) {
                continue;
            }

            $img_attrs = self::attributes($imgs[0]['attrs']);
            $classes   = ' ' . trim((string) preg_replace('/\s+/', ' ', self::attributes($tag['attrs'])['class'] ?? '')) . ' ';
            $align     = 'none';
            foreach (['alignleft' => 'left', 'alignright' => 'right', 'aligncenter' => 'center'] as $class => $value) {
                if (strpos($classes, ' ' . $class . ' ') !== false) {
                    $align = $value;
                    break;
                }
            }

            $out[] = [
                'start'        => $tag['start'],
                'end'          => $close['end'],
                'src'          => $img_attrs['src'] ?? '',
                'alt'          => $img_attrs['alt'] ?? '',
                'caption'      => self::caption($html, $tags, $i),
                'align'        => $align,
                'asset_id'     => $img_attrs['data-td-asset-id'] ?? '',
                'cms_media_id' => $img_attrs['data-td-cms-media-id'] ?? '',
            ];
        }

        return $out;
    }

    /**
     * Replaces photo figures. $render gets one entry of find() and returns the new HTML, or null
     * to keep that figure byte for byte.
     *
     * @param callable(array{start: int, end: int, src: string, alt: string, caption: string, align: string, asset_id: string, cms_media_id: string}): ?string $render
     */
    public static function replace(string $html, callable $render): string
    {
        $out  = '';
        $from = 0;
        foreach (self::find($html) as $figure) {
            $new = $render($figure);
            if ($new === null) {
                continue;
            }
            $out  .= substr($html, $from, $figure['start'] - $from) . $new;
            $from  = $figure['end'];
        }

        return $out . substr($html, $from);
    }

    /** @return list<array{start: int, end: int, closing: bool, name: string, attrs: string}> */
    private static function tags(string $html): array
    {
        if (preg_match_all(self::TAG, $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $tags = [];
        foreach ($m as $hit) {
            $tags[] = [
                'start'   => $hit[0][1],
                'end'     => $hit[0][1] + strlen($hit[0][0]),
                'closing' => $hit[1][0] === '/',
                'name'    => strtolower($hit[2][0]),
                'attrs'   => $hit[3][0],
            ];
        }

        return $tags;
    }

    /** @param list<array{start: int, end: int, closing: bool, name: string, attrs: string}> $tags */
    private static function caption(string $html, array $tags, int $figure_index): string
    {
        $open = null;
        for ($i = $figure_index + 1, $n = count($tags); $i < $n; $i++) {
            $t = $tags[$i];
            if ($t['name'] === 'figure' && $t['closing']) {
                return '';
            }
            if ($open === null && $t['name'] === 'figcaption' && !$t['closing']) {
                $open = $t;
                continue;
            }
            if ($open !== null && $t['name'] === 'figcaption' && $t['closing']) {
                return trim(substr($html, $open['end'], $t['start'] - $open['end']));
            }
        }

        return '';
    }

    /** @return array<string, string> lower-case name => entity-decoded value; the first occurrence wins */
    private static function attributes(string $attrs): array
    {
        $out = [];
        if (preg_match_all('/([^\s"\'<>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $attrs, $m, PREG_SET_ORDER) === false) {
            return $out;
        }
        foreach ($m as $hit) {
            $name = strtolower($hit[1]);
            if (isset($out[$name])) {
                continue;
            }
            $raw         = ($hit[2] ?? '') !== '' ? $hit[2] : (($hit[3] ?? '') !== '' ? $hit[3] : ($hit[4] ?? ''));
            $out[$name]  = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $out;
    }
}
