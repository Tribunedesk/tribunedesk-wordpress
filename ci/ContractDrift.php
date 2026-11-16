<?php
declare(strict_types=1);

namespace TribuneDesk\Ci;

use InvalidArgumentException;
use RuntimeException;

/**
 * Are the pins of tribunedesk.json behind the newest tags? If so, move them.
 *
 *     php ci/contract-drift.php --body-out BODY.md [--open-pr-manifest FILE]
 *
 * Run by .github/workflows/contract-drift.yml (spec "kontrakt najpierw" D2b in
 * Tribunedesk/tribunedesk-cms-kit; a port of ci/ContractDrift.php of tribunedesk-cms-php). Two
 * pins, moved together on one branch because tests/test-contract.php ties them: "contract" must
 * equal ContractVersion::TESTED of the library copy in src/Protocol.
 *
 * - "contract" (newest v* tag of tribunedesk-cms-api): the manifest, tests/contract/openapi.json,
 *   tests/contract/article-delivered.json, tests/contract/guide/ and the README links;
 * - "protocol" (newest vX.Y.Z tag of tribunedesk-cms-php): the manifest and src/Protocol = src/.
 *
 * Both current: `behind=false`, exit 0. One ahead of every tag (a deleted tag): exit 1, nothing
 * changed. The open pull request (`--open-pr-manifest`, tribunedesk.json on contract/latest)
 * already at both newest tags: nothing changed, `behind=true rebuild=false`, so the workflow keeps
 * that branch and whatever was pushed onto it. Otherwise: moves what is behind, writes the pull
 * request body to `--body-out`, `behind=true rebuild=true`, exit 0. `title=` goes to
 * $GITHUB_OUTPUT with every `behind=true`.
 *
 * Network: `git ls-remote` and a shallow `git clone` of the two public repositories, nothing
 * else. Not part of the plugin: the release ZIP carries src/ only.
 */
final class ContractDrift
{
    public const CONTRACT_REPO = 'https://github.com/Tribunedesk/tribunedesk-cms-api';
    public const PROTOCOL_REPO = 'https://github.com/Tribunedesk/tribunedesk-cms-php';
    public const CONTRACT_TAG = '/^v\d+\.\d+\.\d+(?:-draft)?$/';
    public const PROTOCOL_TAG = '/^v\d+\.\d+\.\d+$/';

    private const TESTED = "/public const TESTED = '([^']*)';/";

    /**
     * Order of versions: a draft comes before the release of the same numbers.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function versionKey(string $version): array
    {
        if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)(-draft)?$/', trim($version), $m)) {
            throw new InvalidArgumentException("not a version: '$version'");
        }
        return [(int) $m[1], (int) $m[2], (int) $m[3], isset($m[4]) && $m[4] !== '' ? 0 : 1];
    }

    /**
     * The newest tag whose name matches `$pattern` (CONTRACT_TAG or PROTOCOL_TAG).
     *
     * @param list<string> $lsRemoteLines lines of `git ls-remote --tags --refs`
     */
    public static function latestTag(array $lsRemoteLines, string $pattern): string
    {
        $best = null;
        foreach ($lsRemoteLines as $line) {
            if (!preg_match('#refs/tags/(\S+)$#', trim($line), $m) || !preg_match($pattern, $m[1])) {
                continue;
            }
            if ($best === null || self::versionKey($m[1]) > self::versionKey($best)) {
                $best = $m[1];
            }
        }
        if ($best === null) {
            throw new RuntimeException("no tag matching $pattern");
        }
        return $best;
    }

    /** @return 'current'|'behind'|'ahead' */
    public static function compare(string $pinned, string $latest): string
    {
        $old = self::versionKey($pinned);
        $new = self::versionKey($latest);
        return $old === $new ? 'current' : ($old < $new ? 'behind' : 'ahead');
    }

    /**
     * Every tagged item of every `**Integrators:**` list, newest version first (as
     * `changelog_items` in tdkit/integrators.py). "Nothing to do." and untagged lines are skipped.
     *
     * @return list<array{version: string, tags: list<string>, action: string}>
     */
    public static function changelogItems(string $changelog): array
    {
        $items = [];
        $version = null;
        $inList = false;
        foreach (preg_split('/\r?\n/', $changelog) ?: [] as $line) {
            if (preg_match('/^## (\d+)\.(\d+)\.(\d+)(-draft)?\b/', $line)) {
                $version = explode(' ', substr($line, 3))[0];
                $inList = false;
                continue;
            }
            if (str_starts_with($line, '## ')) {
                $version = null;
                $inList = false;
                continue;
            }
            if (trim($line) === '**Integrators:**') {
                $inList = true;
                continue;
            }
            if (!$inList || $version === null || !str_starts_with($line, '- ')) {
                continue;
            }
            if (!preg_match('/^- ((?:`[^`]+`(?:,\s*|\s+)?)+):\s*(.+)$/', $line, $m)) {
                continue;
            }
            preg_match_all('/`([^`]+)`/', $m[1], $tags);
            $items[] = ['version' => $version, 'tags' => $tags[1], 'action' => trim($m[2])];
        }
        return $items;
    }

    /** ContractVersion::TESTED of a library's src/ContractVersion.php. */
    public static function testedContract(string $contractVersionPhp): string
    {
        if (preg_match_all(self::TESTED, $contractVersionPhp, $m) !== 1) {
            throw new RuntimeException("ContractVersion.php has no single `public const TESTED = '…';`");
        }
        return $m[1][0];
    }

    /**
     * Title of the commit and the pull request: the pins that move, contract first.
     *
     * @param array{contract?: array{0: string, 1: string}, protocol?: array{0: string, 1: string}} $moves
     */
    public static function title(array $moves): string
    {
        $parts = [];
        foreach (['contract', 'protocol'] as $pin) {
            if (isset($moves[$pin])) {
                $parts[] = "$pin: {$moves[$pin][0]} → {$moves[$pin][1]}";
            }
        }
        return implode(', ', $parts);
    }

    /**
     * The pull request body. `$contract` is the contract after this pull request, `$tested` the
     * TESTED of the library copy after it: when they differ, the pull request stays red until
     * tribunedesk-cms-php releases a library tested on `$contract`.
     *
     * @param array{contract?: array{0: string, 1: string}, protocol?: array{0: string, 1: string}} $moves
     * @param list<string> $missingAnchors
     */
    public static function prBody(array $moves, string $changelog, string $contract, string $tested, array $missingAnchors): string
    {
        $lines = [];
        if (isset($moves['contract'])) {
            [$old, $new] = $moves['contract'];
            $lines[] = "The contract has a newer tag: `$old` → `$new`.";
            $lines[] = '';
            $lines[] = 'CHANGELOG: ' . self::CONTRACT_REPO . "/blob/v$new/CHANGELOG.md";
            $lines[] = '';
            $lines[] = '**Integrators** items in between:';
            $lines[] = '';
            $low = self::versionKey($old);
            $high = self::versionKey($new);
            $items = array_filter(
                self::changelogItems($changelog),
                static fn (array $i): bool => $low < self::versionKey($i['version']) && self::versionKey($i['version']) <= $high
            );
            foreach ($items as $item) {
                $tags = implode(', ', array_map(static fn (string $t): string => "`$t`", $item['tags']));
                $lines[] = "- {$item['version']} $tags: {$item['action']}";
            }
            if ($items === []) {
                $lines[] = '- Nothing to do.';
            }
            $lines[] = '';
        }
        if (isset($moves['protocol'])) {
            [$old, $new] = $moves['protocol'];
            $lines[] = "The library tribunedesk/cms-protocol has a newer tag: `$old` → `$new` (src/Protocol is its src/).";
            $lines[] = '';
            $lines[] = 'Changes: ' . self::PROTOCOL_REPO . "/compare/v$old...v$new";
            $lines[] = '';
        }
        if ($tested !== $contract) {
            $lines[] = "**This pull request stays red:** the newest library is tested on $tested, not on contract $contract,";
            $lines[] = 'so `test_version_names_a_tag` fails until tribunedesk-cms-php releases a tag tested on it.';
            $lines[] = 'The next run picks that tag up.';
            $lines[] = '';
        }
        if ($missingAnchors !== []) {
            $lines[] = '**Links and map entries the new guide does not have** (fix them on this branch):';
            $lines[] = '';
            foreach ($missingAnchors as $link) {
                $lines[] = "- $link";
            }
            $lines[] = '';
        }
        $lines[] = 'Its CI shows what the new tags break. Landing it does not release the plugin: the version,';
        $lines[] = 'the changelog and a `vX.Y.Z` tag are a separate decision. Commits pushed onto this branch stay';
        $lines[] = 'until a newer tag replaces it.';
        return implode("\n", $lines) . "\n";
    }
}
