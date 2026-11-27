<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ci/GuideAnchors.php';
require_once dirname(__DIR__) . '/ci/ContractDrift.php';

use TribuneDesk\Ci\ContractDrift;

/**
 * ci/ContractDrift.php, the daily drift of .github/workflows/contract-drift.yml (spec
 * "kontrakt najpierw" D2b in Tribunedesk/tribunedesk-cms-kit): "contract", "protocol" and "kit" of
 * tribunedesk.json against the newest tags of tribunedesk-cms-api and tribunedesk-cms-php.
 * No WordPress, no network: git is a fake that answers from prepared directories.
 */
class Test_Contract_Drift extends \PHPUnit\Framework\TestCase
{
    private const CHANGELOG = <<<'MD'
# Changelog

## 3.5.0-draft – 2026-12-01

- Guide 3: embeds.

**Integrators:**

- `L1`, `read`: verify it
- Nothing to do.

## 3.4.2-draft – 2026-11-12

**Integrators:**

- `L3`: x

## Notes

**Integrators:**

- `L2`: not a version block

MD;

    public function test_version_key_orders_draft_before_release(): void
    {
        $this->assertSame([3, 4, 2, 0], ContractDrift::versionKey('v3.4.2-draft'));
        $this->assertSame([1, 1, 1, 1], ContractDrift::versionKey('1.1.1'));
        $this->assertLessThan(ContractDrift::versionKey('3.5.0'), ContractDrift::versionKey('3.5.0-draft'));
        $this->expectException(InvalidArgumentException::class);
        ContractDrift::versionKey('ship-lock');
    }

    public function test_latest_tag_ignores_foreign_names(): void
    {
        $lines = [
            "a\trefs/tags/v3.4.1-draft",
            "b\trefs/tags/ship-lock",
            "c\trefs/tags/v3.4.2-draft",
            "d\trefs/tags/v1.2.0^{}",
        ];
        $this->assertSame('v3.4.2-draft', ContractDrift::latestTag($lines, ContractDrift::CONTRACT_TAG));

        $library = ["a\trefs/tags/v1.1.0", "b\trefs/tags/v1.1.1", "c\trefs/tags/v1.2.0-draft", "d\trefs/tags/ship-lock"];
        $this->assertSame('v1.1.1', ContractDrift::latestTag($library, ContractDrift::PROTOCOL_TAG));

        $this->expectException(RuntimeException::class);
        ContractDrift::latestTag(["a\trefs/tags/ship-lock"], ContractDrift::PROTOCOL_TAG);
    }

    public function test_compare(): void
    {
        $this->assertSame('current', ContractDrift::compare('3.4.2-draft', 'v3.4.2-draft'));
        $this->assertSame('behind', ContractDrift::compare('3.4.1-draft', 'v3.4.2-draft'));
        $this->assertSame('ahead', ContractDrift::compare('1.2.0', 'v1.1.1'));
    }

    public function test_changelog_items_skip_nothing_to_do(): void
    {
        $this->assertSame([
            ['version' => '3.5.0-draft', 'tags' => ['L1', 'read'], 'action' => 'verify it'],
            ['version' => '3.4.2-draft', 'tags' => ['L3'], 'action' => 'x'],
        ], ContractDrift::changelogItems(self::CHANGELOG));
    }

    public function test_tested_contract_reads_constant(): void
    {
        $this->assertSame('3.4.2-draft', ContractDrift::testedContract("<?php\nfinal class ContractVersion\n{\n    public const TESTED = '3.4.2-draft';\n}\n"));
        $this->expectException(RuntimeException::class);
        ContractDrift::testedContract("<?php\nfinal class ContractVersion {}\n");
    }

    public function test_title_names_only_moving_pins(): void
    {
        $this->assertSame(
            'contract: 3.4.1-draft → 3.4.2-draft, protocol: 1.1.0 → 1.1.1',
            ContractDrift::title(['contract' => ['3.4.1-draft', '3.4.2-draft'], 'protocol' => ['1.1.0', '1.1.1']])
        );
        $this->assertSame('protocol: 1.1.1 → 1.1.2', ContractDrift::title(['protocol' => ['1.1.1', '1.1.2']]));
    }

    public function test_pr_body_lists_integrators_between(): void
    {
        $body = ContractDrift::prBody(['contract' => ['3.4.2-draft', '3.5.0-draft']], self::CHANGELOG, '3.5.0-draft', '3.5.0-draft', []);
        $this->assertStringContainsString('`3.4.2-draft` → `3.5.0-draft`', $body);
        $this->assertStringContainsString(ContractDrift::CONTRACT_REPO . '/blob/v3.5.0-draft/CHANGELOG.md', $body);
        $this->assertStringContainsString('- 3.5.0-draft `L1`, `read`: verify it', $body);
        $this->assertStringNotContainsString('`L3`', $body);
        $this->assertStringNotContainsString('cms-php', $body);

        $none = ContractDrift::prBody(['contract' => ['3.5.0-draft', '3.5.0']], self::CHANGELOG, '3.5.0', '3.5.0', []);
        $this->assertStringContainsString('- Nothing to do.', $none);
    }

    public function test_pr_body_links_protocol_compare(): void
    {
        $body = ContractDrift::prBody(['protocol' => ['1.1.0', '1.1.1']], '', '3.4.2-draft', '3.4.2-draft', []);
        $this->assertStringContainsString(ContractDrift::PROTOCOL_REPO . '/compare/v1.1.0...v1.1.1', $body);
        $this->assertStringNotContainsString('Integrators', $body);
    }

    public function test_pr_body_warns_when_protocol_lags_contract(): void
    {
        $body = ContractDrift::prBody(['contract' => ['3.4.2-draft', '3.5.0-draft']], self::CHANGELOG, '3.5.0-draft', '3.4.2-draft', []);
        $this->assertStringContainsString('tested on 3.4.2-draft', $body);
        $this->assertStringContainsString('test_version_names_a_tag', $body);

        $fine = ContractDrift::prBody(['contract' => ['3.4.2-draft', '3.5.0-draft']], self::CHANGELOG, '3.5.0-draft', '3.5.0-draft', []);
        $this->assertStringNotContainsString('test_version_names_a_tag', $fine);
    }

    public function test_pr_body_lists_missing_anchors(): void
    {
        $body = ContractDrift::prBody(['contract' => ['3.4.2-draft', '3.5.0-draft']], self::CHANGELOG, '3.5.0-draft', '3.5.0-draft', ['docs/contract-map.json: AuthorResolver → 3-full-content.md#gone']);
        $this->assertStringContainsString('the new guide does not have', $body);
        $this->assertStringContainsString('- docs/contract-map.json: AuthorResolver → 3-full-content.md#gone', $body);
    }

    // -- moving the pins, and the run with a fake git -------------------------------------------

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::remove($dir);
        }
        putenv('GITHUB_OUTPUT');
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/contract-drift-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->dirs[] = $dir;
        return $dir;
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
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    /** @param array<string, string> $files */
    private static function write(string $root, array $files): void
    {
        foreach ($files as $name => $content) {
            if (!is_dir(dirname("$root/$name"))) {
                mkdir(dirname("$root/$name"), 0777, true);
            }
            file_put_contents("$root/$name", $content);
        }
    }

    /** @return array<string, string> path => content of every file under $root */
    private static function snapshot(string $root): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $files[str_replace('\\', '/', substr((string) $file, strlen($root) + 1))] = (string) file_get_contents((string) $file);
        }
        ksort($files);
        return $files;
    }

    /** @return array<string, string> */
    private static function outputs(string $file): array
    {
        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }
        return $values;
    }

    private static function versionPhp(string $tested): string
    {
        return "<?php\nfinal class ContractVersion\n{\n    public const TESTED = '$tested';\n}\n";
    }

    private const MANIFEST = "{\"contract\": \"3.4.1-draft\", \"protocol\": \"1.1.0\", \"kit\": \"1.3.0\", \"levels\": [1, 2, 3, 4], \"delivery\": \"webhook\", \"cms\": \"wordpress\"}\n";

    /** The plugin today: contract 3.4.1-draft, library 1.1.0. */
    private function plugin(): string
    {
        $repo = $this->tempDir();
        self::write($repo, [
            'tribunedesk.json' => self::MANIFEST,
            'tests/contract/openapi.json' => 'old-openapi',
            'tests/contract/article-delivered.json' => 'old-sample',
            'tests/contract/guide/1-article-delivery.md' => "# Level 1\n\n## Article delivery webhook\n",
            'tests/contract/guide/2-link-back.md' => "# Level 2\n\n## Done when\n",
            'src/Protocol/ContractVersion.php' => self::versionPhp('3.4.1-draft'),
            'src/Protocol/Old.php' => '<?php // gone in 1.1.1',
            'README.md' => "- Guide: https://github.com/Tribunedesk/tribunedesk-cms-api/tree/v3.4.1-draft/guide (levels 1-4)\n"
                . "- Contract: https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/openapi.json\n"
                . "- Done: https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/2-link-back.md#done-when\n",
            'docs/contract-map.json' => "{\"classes\": [{\"class\": \"RestEndpoint\", \"guide\": \"1-article-delivery.md#article-delivery-webhook\"}, {\"class\": \"PublishNotifier\", \"guide\": \"2-link-back.md#done-when\"}]}\n",
        ]);
        return $repo;
    }

    /** Trees of the tags the fake git clones, "url@tag" => files. */
    private const TAGS = [
        ContractDrift::CONTRACT_REPO . '@v3.4.2-draft' => [
            'openapi.json' => 'new-openapi',
            'examples/payloads/article-delivered.json' => 'new-sample',
            'guide/1-article-delivery.md' => "# Level 1\n\n## Article delivery webhook\n",
            'guide/3-full-content.md' => "# Level 3\n\n## SEO, author and language\n",
            'CHANGELOG.md' => "# Changelog\n\n## 3.4.2-draft – 2026-11-12\n\n**Integrators:**\n\n- Nothing to do.\n",
        ],
        ContractDrift::PROTOCOL_REPO . '@v1.1.1' => [
            'src/ContractVersion.php' => "<?php\nfinal class ContractVersion\n{\n    public const TESTED = '3.4.2-draft';\n}\n",
            'src/New.php' => '<?php // new in 1.1.1',
        ],
    ];

    private const NEWEST = [
        ContractDrift::CONTRACT_REPO => ['v3.4.1-draft', 'v3.4.2-draft', 'ship-lock'],
        ContractDrift::PROTOCOL_REPO => ['v1.1.0', 'v1.1.1', 'ship-lock'],
        ContractDrift::KIT_REPO => ['v1.2.0', 'v1.3.0', 'v1.1.2', 'ship-lock'],
    ];

    /**
     * @param array<string, list<string>> $tags repository URL => tag names
     * @param list<list<string>> $calls
     * @param array<string, array<string, string>> $trees "url@tag" => files
     */
    private static function fakeGit(array $tags, array &$calls, array $trees = self::TAGS): callable
    {
        return static function (array $args) use ($tags, &$calls, $trees): string {
            $calls[] = $args;
            $n = count($args);
            if (in_array('ls-remote', $args, true)) {
                $lines = array_map(static fn (string $t): string => "0000\trefs/tags/$t", $tags[$args[$n - 1]] ?? []);
                return implode("\n", $lines) . "\n";
            }
            $key = $args[$n - 2] . '@' . $args[(int) array_search('--branch', $args, true) + 1];
            if (!isset($trees[$key])) {
                throw new RuntimeException("fake git: no tree $key");
            }
            self::write($args[$n - 1], $trees[$key]);
            return '';
        };
    }

    public function test_clone_args_disable_autocrlf(): void
    {
        $this->assertSame(
            ['-c', 'core.autocrlf=false', 'clone', '--quiet', '--depth', '1', '--branch', 'v1.1.1', ContractDrift::PROTOCOL_REPO, '/tmp/x'],
            ContractDrift::cloneArgs(ContractDrift::PROTOCOL_REPO, 'v1.1.1', '/tmp/x')
        );
    }

    public function test_apply_contract_moves_manifest_copies_guide_and_links(): void
    {
        $repo = $this->plugin();
        $tag = $this->tempDir();
        self::write($tag, self::TAGS[ContractDrift::CONTRACT_REPO . '@v3.4.2-draft']);

        ContractDrift::applyContract($repo, $tag, '3.4.2-draft');

        $this->assertSame(str_replace('"3.4.1-draft"', '"3.4.2-draft"', self::MANIFEST), file_get_contents("$repo/tribunedesk.json"));
        $this->assertSame('new-openapi', file_get_contents("$repo/tests/contract/openapi.json"));
        $this->assertSame('new-sample', file_get_contents("$repo/tests/contract/article-delivered.json"));
        $this->assertFileDoesNotExist("$repo/tests/contract/guide/2-link-back.md");
        $this->assertFileExists("$repo/tests/contract/guide/3-full-content.md");
        $readme = (string) file_get_contents("$repo/README.md");
        $this->assertStringContainsString('tribunedesk-cms-api/tree/v3.4.2-draft/guide', $readme);
        $this->assertStringContainsString('tribunedesk-cms-api/blob/v3.4.2-draft/openapi.json', $readme);
        $this->assertStringNotContainsString('3.4.1-draft', $readme);
    }

    public function test_apply_contract_copies_the_whole_guide_tree(): void
    {
        // plugin-ci.yml compares the copy with `diff -r` of the tag's guide/: a file that is not
        // top-level Markdown must be copied too, or every drift pull request stays red.
        $repo = $this->plugin();
        $tag = $this->tempDir();
        self::write($tag, self::TAGS[ContractDrift::CONTRACT_REPO . '@v3.4.2-draft'] + ['guide/img/flow.png' => 'png']);

        ContractDrift::applyContract($repo, $tag, '3.4.2-draft');

        $this->assertSame('png', @file_get_contents("$repo/tests/contract/guide/img/flow.png"));
    }

    public function test_apply_contract_fails_before_writing_without_sample(): void
    {
        $repo = $this->plugin();
        $tag = $this->tempDir();
        $tree = self::TAGS[ContractDrift::CONTRACT_REPO . '@v3.4.2-draft'];
        unset($tree['examples/payloads/article-delivered.json']);
        self::write($tag, $tree);
        $before = self::snapshot($repo);

        try {
            ContractDrift::applyContract($repo, $tag, '3.4.2-draft');
            $this->fail('a tag without the sample delivery was applied');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('article-delivered.json', $e->getMessage());
        }
        $this->assertSame($before, self::snapshot($repo));
    }

    public function test_apply_protocol_replaces_src_protocol(): void
    {
        $repo = $this->plugin();
        $tag = $this->tempDir();
        self::write($tag, self::TAGS[ContractDrift::PROTOCOL_REPO . '@v1.1.1']);

        ContractDrift::applyProtocol($repo, $tag, '1.1.1');

        $this->assertSame(str_replace('"1.1.0"', '"1.1.1"', self::MANIFEST), file_get_contents("$repo/tribunedesk.json"));
        $this->assertFileDoesNotExist("$repo/src/Protocol/Old.php");
        $this->assertFileExists("$repo/src/Protocol/New.php");
        $this->assertSame(self::versionPhp('3.4.2-draft'), file_get_contents("$repo/src/Protocol/ContractVersion.php"));
    }

    public function test_missing_anchors_lists_readme_links_and_map_entries(): void
    {
        $repo = $this->plugin();
        $tag = $this->tempDir();
        self::write($tag, self::TAGS[ContractDrift::CONTRACT_REPO . '@v3.4.2-draft']);
        ContractDrift::applyContract($repo, $tag, '3.4.2-draft');

        $this->assertSame([
            'README.md: https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.2-draft/guide/2-link-back.md#done-when',
            'docs/contract-map.json: PublishNotifier → 2-link-back.md#done-when',
        ], ContractDrift::missingAnchors($repo));
    }

    public function test_run_current_changes_nothing(): void
    {
        $repo = $this->plugin();
        self::write($repo, ['tribunedesk.json' => str_replace(['3.4.1-draft', '1.1.0'], ['3.4.2-draft', '1.1.1'], self::MANIFEST)]);
        $out = $this->tempDir() . '/output';
        putenv("GITHUB_OUTPUT=$out");
        $calls = [];
        $before = self::snapshot($repo);

        $this->expectOutputRegex('/are the newest tags/');
        $code = ContractDrift::run(['x', '--body-out', "$repo/body.md"], $repo, self::fakeGit(self::NEWEST, $calls));

        $this->assertSame(0, $code);
        $this->assertSame(['behind' => 'false'], self::outputs($out));
        $this->assertCount(3, $calls);
        $this->assertSame($before, self::snapshot($repo));
    }

    public function test_run_ahead_fails(): void
    {
        $repo = $this->plugin();
        self::write($repo, ['tribunedesk.json' => str_replace('1.1.0', '1.2.0', self::MANIFEST)]);
        $calls = [];
        $before = self::snapshot($repo);

        $code = ContractDrift::run(['x', '--body-out', "$repo/body.md"], $repo, self::fakeGit(self::NEWEST, $calls));

        $this->assertSame(1, $code);
        $this->assertSame($before, self::snapshot($repo));
    }

    public function test_run_open_pr_at_latest_is_left_alone(): void
    {
        $repo = $this->plugin();
        $open = $this->tempDir() . '/open.json';
        file_put_contents($open, str_replace(['3.4.1-draft', '1.1.0'], ['3.4.2-draft', '1.1.1'], self::MANIFEST));
        $out = $this->tempDir() . '/output';
        putenv("GITHUB_OUTPUT=$out");
        $calls = [];
        $before = self::snapshot($repo);

        $this->expectOutputRegex('/contract\/latest already moves them/');
        $code = ContractDrift::run(['x', '--body-out', "$repo/body.md", '--open-pr-manifest', $open], $repo, self::fakeGit(self::NEWEST, $calls));

        $this->assertSame(0, $code);
        $this->assertSame(
            ['behind' => 'true', 'rebuild' => 'false', 'title' => 'contract: 3.4.1-draft → 3.4.2-draft, protocol: 1.1.0 → 1.1.1'],
            self::outputs($out)
        );
        $this->assertCount(3, $calls);
        $this->assertSame($before, self::snapshot($repo));
        $this->assertFileDoesNotExist("$repo/body.md");
    }

    public function test_run_rebuilds_when_one_tag_moved(): void
    {
        $repo = $this->plugin();
        $open = $this->tempDir() . '/open.json';
        file_put_contents($open, str_replace('3.4.1-draft', '3.4.2-draft', self::MANIFEST));
        $out = $this->tempDir() . '/output';
        putenv("GITHUB_OUTPUT=$out");
        $calls = [];

        $this->expectOutputRegex('/moved/');
        $code = ContractDrift::run(['x', '--body-out', "$repo/body.md", '--open-pr-manifest', $open], $repo, self::fakeGit(self::NEWEST, $calls));

        $this->assertSame(0, $code);
        $this->assertSame('true', self::outputs($out)['rebuild']);
    }

    public function test_run_behind_moves_both(): void
    {
        $repo = $this->plugin();
        $out = $this->tempDir() . '/output';
        putenv("GITHUB_OUTPUT=$out");
        $calls = [];

        $this->expectOutputRegex('/moved/');
        $code = ContractDrift::run(['x', '--body-out', "$repo/body.md", '--open-pr-manifest', ''], $repo, self::fakeGit(self::NEWEST, $calls));

        $this->assertSame(0, $code);
        $this->assertSame(
            ['behind' => 'true', 'rebuild' => 'true', 'title' => 'contract: 3.4.1-draft → 3.4.2-draft, protocol: 1.1.0 → 1.1.1'],
            self::outputs($out)
        );
        $this->assertSame(str_replace(['3.4.1-draft', '1.1.0'], ['3.4.2-draft', '1.1.1'], self::MANIFEST), file_get_contents("$repo/tribunedesk.json"));
        $body = (string) file_get_contents("$repo/body.md");
        $this->assertStringContainsString('`3.4.1-draft` → `3.4.2-draft`', $body);
        $this->assertStringContainsString('compare/v1.1.0...v1.1.1', $body);
        $this->assertStringContainsString('- Nothing to do.', $body);
        $this->assertStringNotContainsString('stays red', $body);
        $this->assertStringContainsString('PublishNotifier → 2-link-back.md#done-when', $body);
        $this->assertCount(5, $calls);
        foreach ([3, 4] as $clone) {
            $this->assertDirectoryDoesNotExist($calls[$clone][count($calls[$clone]) - 1]);
        }
    }

    public function test_run_fails_without_changelog_in_tag(): void
    {
        $repo = $this->plugin();
        $trees = self::TAGS;
        unset($trees[ContractDrift::CONTRACT_REPO . '@v3.4.2-draft']['CHANGELOG.md']);
        $calls = [];
        $before = self::snapshot($repo);

        // On the runner a missing file is only a warning; PHPUnit would turn it into an exception.
        $level = error_reporting(E_ALL & ~E_WARNING);
        try {
            ContractDrift::run(['x', '--body-out', "$repo/body.md"], $repo, self::fakeGit(self::NEWEST, $calls, $trees));
            $this->fail('a tag without CHANGELOG.md became a pull request body');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CHANGELOG.md', $e->getMessage());
        } finally {
            error_reporting($level);
        }
        $this->assertSame($before, self::snapshot($repo));
        $this->assertFileDoesNotExist("$repo/body.md");
    }

    public function test_run_rebuilds_when_the_open_manifest_lacks_a_pin(): void
    {
        // A pull request opened before "kit" existed (or a broken hand-made merge) must not jam
        // the drift: an open manifest it cannot read is rebuilt like a closed one.
        $repo = $this->plugin();
        $open = $this->tempDir() . '/open.json';
        file_put_contents($open, "{\"contract\": \"3.4.2-draft\", \"protocol\": \"1.1.1\", \"levels\": [1, 2, 3, 4]}\n");
        $out = $this->tempDir() . '/output';
        putenv("GITHUB_OUTPUT=$out");
        $calls = [];

        $this->expectOutputRegex('/moved/');
        $code = ContractDrift::run(['x', '--body-out', "$repo/body.md", '--open-pr-manifest', $open], $repo, self::fakeGit(self::NEWEST, $calls));

        $this->assertSame(0, $code);
        $this->assertSame('true', self::outputs($out)['rebuild']);
    }

    public function test_latest_kit_tag(): void
    {
        $this->assertSame('v1.3.0', ContractDrift::latestTag(["a\trefs/tags/v1.2.0", "b\trefs/tags/v1.3.0", "c\trefs/tags/v1.10.0-draft"], ContractDrift::KIT_TAG));
    }

    public function test_title_and_body_name_the_kit(): void
    {
        $moves = ['contract' => ['3.4.2-draft', '3.5.0-draft'], 'kit' => ['1.3.0', '1.4.0']];
        $this->assertSame('contract: 3.4.2-draft → 3.5.0-draft, kit: 1.3.0 → 1.4.0', ContractDrift::title($moves));
        $body = ContractDrift::prBody($moves, self::CHANGELOG, '3.5.0-draft', '3.5.0-draft', []);
        $this->assertStringContainsString(ContractDrift::KIT_REPO . '/compare/v1.3.0...v1.4.0', $body);
    }

    public function test_apply_kit_moves_only_the_kit_value(): void
    {
        $repo = $this->plugin();

        ContractDrift::applyKit($repo, '1.4.0');

        $this->assertSame(str_replace('"kit": "1.3.0"', '"kit": "1.4.0"', self::MANIFEST), file_get_contents("$repo/tribunedesk.json"));
    }

    public function test_run_moves_three_pins(): void
    {
        $repo = $this->plugin();
        self::write($repo, ['tribunedesk.json' => str_replace('"kit": "1.3.0"', '"kit": "1.2.0"', self::MANIFEST)]);
        $out = $this->tempDir() . '/output';
        putenv("GITHUB_OUTPUT=$out");
        $calls = [];

        $this->expectOutputRegex('/moved/');
        $code = ContractDrift::run(['x', '--body-out', "$repo/body.md"], $repo, self::fakeGit(self::NEWEST, $calls));

        $this->assertSame(0, $code);
        $this->assertSame('contract: 3.4.1-draft → 3.4.2-draft, protocol: 1.1.0 → 1.1.1, kit: 1.2.0 → 1.3.0', self::outputs($out)['title']);
        $this->assertStringContainsString('"kit": "1.3.0"', (string) file_get_contents("$repo/tribunedesk.json"));
        $this->assertCount(5, $calls, 'three ls-remote, two clones: the kit pin needs no clone');
    }

    // -- .github/workflows/contract-drift.yml ---------------------------------------------------

    private static function workflow(): string
    {
        $text = @file_get_contents(dirname(__DIR__) . '/.github/workflows/contract-drift.yml');
        self::assertIsString($text, '.github/workflows/contract-drift.yml is missing');
        return $text;
    }

    public function test_workflow_passes_the_manifest_only_with_an_open_pull_request(): void
    {
        $this->assertSame(1, preg_match('/id: open\n(.+?)\n\n/s', self::workflow(), $m));
        $open = strpos($m[1], 'gh pr list --head contract/latest --state open');
        $this->assertNotFalse($open, 'a branch left by a closed or never-opened pull request must be rebuilt');
        $this->assertLessThan(strpos($m[1], 'git fetch'), $open);
    }

    public function test_workflow_commits_no_workflow_file(): void
    {
        $this->assertSame(1, preg_match('/^\s*OPEN_PR=.*ci\/drift-branch\.sh (.+)$/m', self::workflow(), $m));
        $this->assertStringNotContainsString('.github', $m[1]);
        foreach (['tribunedesk.json', 'tests/contract', 'src/Protocol', 'README.md'] as $place) {
            $this->assertStringContainsString($place, $m[1], "the drift changes $place, so the workflow must commit it");
        }
    }

    /** 2026-11-25: a failed `gh pr create` left a run as red as a plain "pin behind". */
    public function test_push_step_fails_loudly_without_its_pull_request(): void
    {
        $workflow = self::workflow();
        $start = strpos($workflow, '- name: Push contract/latest');
        $step = substr($workflow, $start, strpos($workflow, '- name: Approve') - $start);
        $this->assertStringContainsString('for try in 1 2 3', $step);
        $this->assertLessThan(strpos($step, 'exit 1'), strpos($step, 'NOT opened or updated'));
        $this->assertLessThan(strpos($step, 'gh workflow run'), strpos($step, 'exit 1'));
        $this->assertStringNotContainsString('/ship', $workflow);
    }
}
