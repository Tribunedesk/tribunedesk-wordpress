<?php
declare(strict_types=1);

namespace TribuneDesk\Ci;

/**
 * Release gate "the pinned contract is not older than the behaviour" (audit of 2026-11-27,
 * Propagation: 2.5.0 shipped body images on contract 3.6.0 before the guide described them).
 *
 * A release whose src/ changed since the previous v* tag must say, in its readme.txt changelog
 * section, where the guide of the pinned contract (tests/contract/guide/, the copy CI checks
 * against the tag in tribunedesk.json) describes the change: a `<guide file>#<anchor>` that
 * exists there, e.g. `3-full-content.md#categories-and-tags`. A change that no other CMS needs
 * says so with a line `wp_only: <why>`. A release that changes only classes marked "wp_only" in
 * docs/contract-map.json (or no src/ at all) needs neither. Not part of the plugin: the ZIP
 * carries src/ only.
 */
final class ReleaseContract
{
    private const REF = '/\b(\d+-[a-z0-9-]+\.md)#([\p{L}\p{N}_-]+)/u';

    /**
     * @param list<string>               $changed   src/ paths changed since the previous tag
     * @param array<string, string>      $wpOnly    class => reason, the "wp_only" entries of the map
     * @param array<string, string>      $guideOf   class => "file#anchor", the "guide" entries
     * @param array<string, list<string>> $anchors  guide file => its anchors (pinned copy)
     * @return list<string> problems; empty = the release may go
     */
    public static function problems(string $version, array $changed, array $wpOnly, array $guideOf, string $section, array $anchors): array
    {
        $contract = [];
        foreach ($changed as $path) {
            if (str_starts_with($path, 'src/Protocol/')) {
                $contract[] = $path;
            } elseif (preg_match('#^src/WordPress/([A-Za-z0-9_]+)\.php$#', $path, $m) && !isset($wpOnly[$m[1]])) {
                $contract[] = $path;
            }
        }
        if ($contract === []) {
            return [];
        }
        if (preg_match('/^\s*\*?\s*wp_only:\s*\S/m', $section)) {
            return [];
        }
        $bad = [];
        if (preg_match_all(self::REF, $section, $refs, PREG_SET_ORDER)) {
            foreach ($refs as [, $file, $anchor]) {
                if (in_array($anchor, $anchors[$file] ?? [], true)) {
                    return [];
                }
                $bad[] = "$file#$anchor";
            }
        }
        $hints = [];
        foreach ($contract as $path) {
            $class = basename($path, '.php');
            $hints[] = isset($guideOf[$class]) ? "$class → {$guideOf[$class]}" : $class;
        }
        $problem = "readme.txt \"= $version =\" does not say where the pinned guide describes the change of "
            . implode(', ', $hints)
            . ': add a guide reference (e.g. `3-full-content.md#categories-and-tags`) that exists in tests/contract/guide,'
            . ' or a line `wp_only: <why>` when no other CMS needs it';
        if ($bad !== []) {
            $problem .= '; not in the pinned guide: ' . implode(', ', $bad)
                . ' (move the pin in tribunedesk.json to a contract that has it first)';
        }

        return [$problem];
    }

    /** The section `= <version> =` of readme.txt's changelog, without its heading. */
    public static function section(string $readme, string $version): string
    {
        $lines = preg_split('/\r?\n/', $readme) ?: [];
        $out = [];
        $in = false;
        foreach ($lines as $line) {
            if (preg_match('/^= (.+?) =\s*$/', $line, $m)) {
                if ($in) {
                    break;
                }
                $in = $m[1] === $version;
                continue;
            }
            if ($in) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    /**
     * php ci/release-contract.php <version>: 0 = may release, 1 = a problem (as ::error::).
     *
     * @param callable(list<string>): ?string $git output of `git <args>`, null when it failed
     */
    public static function run(string $version, string $root, callable $git): int
    {
        $previous = $git(['describe', '--tags', '--abbrev=0', '--match', 'v*', 'HEAD^']);
        if ($previous === null || trim($previous) === '') {
            echo "No earlier v* tag: nothing to compare.\n";
            return 0;
        }
        $previous = trim($previous);
        $diff = $git(['diff', '--no-renames', '--name-only', $previous, 'HEAD', '--', 'src/']);
        if ($diff === null) {
            echo "::error::git diff $previous..HEAD failed: fetch the tags (fetch-depth: 0)\n";
            return 1;
        }
        $changed = array_values(array_filter(array_map('trim', explode("\n", $diff))));

        $map = json_decode((string) @file_get_contents("$root/docs/contract-map.json"), true);
        $wpOnly = [];
        $guideOf = [];
        foreach (is_array($map) ? ($map['classes'] ?? []) : [] as $entry) {
            $class = (string) ($entry['class'] ?? '');
            if (trim((string) ($entry['wp_only'] ?? '')) !== '') {
                $wpOnly[$class] = (string) $entry['wp_only'];
            } elseif (trim((string) ($entry['guide'] ?? '')) !== '') {
                $guideOf[$class] = (string) $entry['guide'];
            }
        }
        $anchors = [];
        foreach (glob("$root/tests/contract/guide/*.md") ?: [] as $file) {
            $anchors[basename($file)] = GuideAnchors::anchors((string) file_get_contents($file));
        }
        $section = self::section((string) @file_get_contents("$root/readme.txt"), $version);

        $problems = self::problems($version, $changed, $wpOnly, $guideOf, $section, $anchors);
        foreach ($problems as $problem) {
            echo "::error::$problem\n";
        }
        if ($problems === []) {
            echo 'OK: ' . count($changed) . " src/ files changed since $previous; the changelog of $version names the pinned guide, wp_only, or only wp_only classes changed\n";
        }

        return $problems === [] ? 0 : 1;
    }

    /** @param list<string> $args */
    public static function git(array $args): ?string
    {
        $cmd = array_merge(['git'], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc) === 0 ? $out : null;
    }
}
