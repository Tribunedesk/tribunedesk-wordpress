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

    /**
     * Runs git with an argument list (no shell) and returns its stdout.
     *
     * @param list<string> $args
     */
    public static function git(array $args): string
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start git');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException('git ' . implode(' ', $args) . " failed ($code): " . trim($stderr));
        }
        return $stdout;
    }

    /**
     * A shallow clone of one tag. autocrlf off: CI compares the copies byte for byte.
     *
     * @return list<string>
     */
    public static function cloneArgs(string $repoUrl, string $tag, string $dest): array
    {
        return ['-c', 'core.autocrlf=false', 'clone', '--quiet', '--depth', '1', '--branch', $tag, $repoUrl, $dest];
    }

    /**
     * Moves "contract" to `$latest` (no `v`) from the tag tree in `$tagRoot`: the manifest, the
     * copies in tests/contract/ (guide/ replaced whole) and the README links. Everything is checked
     * before anything is written.
     */
    public static function applyContract(string $repo, string $tagRoot, string $latest): void
    {
        $manifest = self::pinnedManifest($repo, 'contract');
        foreach (['openapi.json', 'examples/payloads/article-delivered.json'] as $file) {
            if (!is_file("$tagRoot/$file")) {
                throw new RuntimeException("v$latest has no $file");
            }
        }
        $guides = glob("$tagRoot/guide/*.md") ?: [];
        if ($guides === []) {
            throw new RuntimeException("v$latest has no guide/*.md");
        }
        $readme = self::read("$repo/README.md");

        file_put_contents("$repo/tribunedesk.json", self::setPin($manifest, 'contract', $latest));
        self::copy("$tagRoot/openapi.json", "$repo/tests/contract/openapi.json");
        self::copy("$tagRoot/examples/payloads/article-delivered.json", "$repo/tests/contract/article-delivered.json");
        // The whole tree, not only *.md: plugin-ci.yml compares it with `diff -r`.
        self::remove("$repo/tests/contract/guide");
        self::copyTree("$tagRoot/guide", "$repo/tests/contract/guide");
        $readme = preg_replace('#(tribunedesk-cms-api/(?:blob|tree)/)v\d+\.\d+\.\d+(?:-draft)?/#', '${1}v' . $latest . '/', $readme);
        file_put_contents("$repo/README.md", (string) $readme);
    }

    /**
     * Moves "protocol" to `$latest` (no `v`): the manifest, and src/Protocol replaced whole by
     * src/ of the library tag in `$tagRoot`.
     */
    public static function applyProtocol(string $repo, string $tagRoot, string $latest): void
    {
        $manifest = self::pinnedManifest($repo, 'protocol');
        if (!is_file("$tagRoot/src/ContractVersion.php")) {
            throw new RuntimeException("v$latest has no src/ContractVersion.php");
        }

        file_put_contents("$repo/tribunedesk.json", self::setPin($manifest, 'protocol', $latest));
        self::remove("$repo/src/Protocol");
        self::copyTree("$tagRoot/src", "$repo/src/Protocol");
    }

    /**
     * README links into guide/ and docs/contract-map.json entries whose file or heading
     * tests/contract/guide/ does not have.
     *
     * @return list<string>
     */
    public static function missingAnchors(string $repo): array
    {
        $guide = "$repo/tests/contract/guide";
        $has = static function (string $file, string $anchor) use ($guide): bool {
            if (!is_file("$guide/$file")) {
                return false;
            }
            return $anchor === '' || in_array($anchor, GuideAnchors::anchors(self::read("$guide/$file")), true);
        };
        $missing = [];
        preg_match_all('#https://github\.com/Tribunedesk/tribunedesk-cms-api/blob/[^/\s)]+/guide/([^\s)\#]+)(?:\#([^\s)]+))?#', self::read("$repo/README.md"), $links, PREG_SET_ORDER);
        foreach ($links as $link) {
            if (!$has($link[1], $link[2] ?? '')) {
                $missing[] = "README.md: {$link[0]}";
            }
        }
        $map = json_decode(self::read("$repo/docs/contract-map.json"), true);
        foreach ((array) ($map['classes'] ?? []) as $entry) {
            $target = (string) ($entry['guide'] ?? '');
            if ($target === '') {
                continue;
            }
            [$file, $anchor] = array_pad(explode('#', $target, 2), 2, '');
            if (!$has($file, $anchor)) {
                $missing[] = "docs/contract-map.json: {$entry['class']} → $target";
            }
        }
        return $missing;
    }

    /**
     * The CLI run (see the class comment). `$git` takes an argument list and returns stdout.
     *
     * @param list<string> $argv
     * @param callable(list<string>): string $git
     */
    public static function run(array $argv, string $repo, callable $git): int
    {
        $bodyOut = null;
        $openManifest = '';
        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '--body-out' && isset($argv[$i + 1])) {
                $bodyOut = $argv[++$i];
            } elseif ($argv[$i] === '--open-pr-manifest' && isset($argv[$i + 1])) {
                $openManifest = trim($argv[++$i]);
            } else {
                fwrite(STDERR, "unknown argument: {$argv[$i]}\n");
                return 2;
            }
        }
        if ($bodyOut === null) {
            fwrite(STDERR, "usage: php ci/contract-drift.php --body-out FILE [--open-pr-manifest FILE]\n");
            return 2;
        }

        $pinned = self::pins(self::read("$repo/tribunedesk.json"));
        $latest = [];
        foreach (['contract' => [self::CONTRACT_REPO, self::CONTRACT_TAG], 'protocol' => [self::PROTOCOL_REPO, self::PROTOCOL_TAG]] as $pin => [$url, $pattern]) {
            $lines = preg_split('/\r?\n/', $git(['ls-remote', '--tags', '--refs', $url])) ?: [];
            $latest[$pin] = substr(self::latestTag($lines, $pattern), 1);
        }

        $moves = [];
        foreach (['contract', 'protocol'] as $pin) {
            $state = self::compare($pinned[$pin], $latest[$pin]);
            if ($state === 'ahead') {
                fwrite(STDERR, "\"$pin\" {$pinned[$pin]} is newer than the newest tag v{$latest[$pin]}.\n");
                return 1;
            }
            if ($state === 'behind') {
                $moves[$pin] = [$pinned[$pin], $latest[$pin]];
            }
        }
        if ($moves === []) {
            echo "contract {$pinned['contract']} and protocol {$pinned['protocol']} are the newest tags.\n";
            self::output(['behind' => 'false']);
            return 0;
        }
        $title = self::title($moves);
        if ($openManifest !== '' && is_file($openManifest)) {
            $open = self::pins(self::read($openManifest));
            if ($open === $latest) {
                echo "$title: contract/latest already moves them.\n";
                self::output(['behind' => 'true', 'rebuild' => 'false', 'title' => $title]);
                return 0;
            }
        }

        $tmp = sys_get_temp_dir() . '/contract-drift-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0777, true);
        try {
            $changelog = '';
            $contractRoot = null;
            if (isset($moves['contract'])) {
                $contractRoot = "$tmp/cms-api";
                $git(self::cloneArgs(self::CONTRACT_REPO, "v{$latest['contract']}", $contractRoot));
                $changelog = self::read("$contractRoot/CHANGELOG.md");
            }
            $protocolRoot = null;
            if (isset($moves['protocol'])) {
                $protocolRoot = "$tmp/cms-php";
                $git(self::cloneArgs(self::PROTOCOL_REPO, "v{$latest['protocol']}", $protocolRoot));
                self::testedContract(self::read("$protocolRoot/src/ContractVersion.php"));
            }
            if ($contractRoot !== null) {
                self::applyContract($repo, $contractRoot, $latest['contract']);
            }
            if ($protocolRoot !== null) {
                self::applyProtocol($repo, $protocolRoot, $latest['protocol']);
            }
        } finally {
            self::remove($tmp);
        }
        $tested = self::testedContract(self::read("$repo/src/Protocol/ContractVersion.php"));
        $body = self::prBody($moves, $changelog, $latest['contract'], $tested, self::missingAnchors($repo));
        file_put_contents($bodyOut, $body);
        echo "$title: moved.\n";
        self::output(['behind' => 'true', 'rebuild' => 'true', 'title' => $title]);
        return 0;
    }

    /** @return array{contract: string, protocol: string} the two pins of a tribunedesk.json */
    private static function pins(string $manifest): array
    {
        $data = json_decode($manifest, true);
        if (!is_array($data) || !is_string($data['contract'] ?? null) || !is_string($data['protocol'] ?? null)) {
            throw new RuntimeException('tribunedesk.json has no "contract" and "protocol"');
        }
        self::versionKey($data['contract']);
        self::versionKey($data['protocol']);
        return ['contract' => $data['contract'], 'protocol' => $data['protocol']];
    }

    /** The manifest's text, checked to carry exactly one `"<pin>": "…"`. */
    private static function pinnedManifest(string $repo, string $pin): string
    {
        $manifest = self::read("$repo/tribunedesk.json");
        if (preg_match_all('/"' . $pin . '"\s*:\s*"[^"]*"/', $manifest) !== 1) {
            throw new RuntimeException("tribunedesk.json has no single \"$pin\"");
        }
        return $manifest;
    }

    /** The manifest with the value of `"<pin>"` replaced, every other byte kept. */
    private static function setPin(string $manifest, string $pin, string $version): string
    {
        return (string) preg_replace('/("' . $pin . '"\s*:\s*")[^"]*(")/', '${1}' . $version . '${2}', $manifest);
    }

    /** The file's text; a missing or unreadable file is an error, never an empty string. */
    private static function read(string $path): string
    {
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new RuntimeException("cannot read $path");
        }
        return $text;
    }

    private static function copy(string $from, string $to): void
    {
        if (!copy($from, $to)) {
            throw new RuntimeException("cannot copy $from");
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir("$from/$name") ? self::copyTree("$from/$name", "$to/$name") : self::copy("$from/$name", "$to/$name");
        }
    }

    /** @param array<string, string> $values */
    private static function output(array $values): void
    {
        $target = getenv('GITHUB_OUTPUT');
        if ($target === false || $target === '') {
            return;
        }
        $text = '';
        foreach ($values as $key => $value) {
            $text .= "$key=$value\n";
        }
        file_put_contents($target, $text, FILE_APPEND);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove("$path/$name");
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @chmod($path, 0666);
            unlink($path);
        }
    }
}
