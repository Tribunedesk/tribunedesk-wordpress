<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ci/GuideAnchors.php';
require_once dirname(__DIR__) . '/ci/ContractDrift.php';

use TribuneDesk\Ci\ContractDrift;

/**
 * ci/ContractDrift.php, the daily drift of .github/workflows/contract-drift.yml (spec
 * "kontrakt najpierw" D2b in Tribunedesk/tribunedesk-cms-kit): "contract" and "protocol" of
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
}
