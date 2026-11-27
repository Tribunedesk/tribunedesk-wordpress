<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ci/GuideAnchors.php';
require_once dirname(__DIR__) . '/ci/ReleaseContract.php';

use TribuneDesk\Ci\ReleaseContract;

/**
 * ci/ReleaseContract.php, the release gate of release.yml: a change of behaviour in src/ ships
 * only with a changelog line that points at the pinned guide, or says wp_only.
 */
class Test_Release_Contract extends \PHPUnit\Framework\TestCase
{
    private const WP_ONLY = ['DeliveriesLog' => 'the log in wp-admin'];
    private const GUIDE = ['CmsTaxonomy' => '3-full-content.md#categories-and-tags'];
    private const ANCHORS = ['3-full-content.md' => ['categories-and-tags', 'the-read-more-box']];

    /** @param list<string> $changed */
    private static function problems(array $changed, string $section): array
    {
        return ReleaseContract::problems('2.8.0', $changed, self::WP_ONLY, self::GUIDE, $section, self::ANCHORS);
    }

    public function test_no_src_change_passes(): void
    {
        $this->assertSame([], self::problems([], '* Docs only.'));
    }

    public function test_only_wp_only_classes_pass(): void
    {
        $this->assertSame([], self::problems(['src/WordPress/DeliveriesLog.php', 'src/aliases.php'], '* Log fix.'));
    }

    public function test_behaviour_change_without_reference_fails_and_hints_the_section(): void
    {
        $problems = self::problems(['src/WordPress/CmsTaxonomy.php'], '* Implements contract 3.8.0-draft.');

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('CmsTaxonomy → 3-full-content.md#categories-and-tags', $problems[0]);
        $this->assertStringContainsString('wp_only:', $problems[0]);
    }

    public function test_reference_into_the_pinned_guide_passes(): void
    {
        $this->assertSame([], self::problems(['src/WordPress/CmsTaxonomy.php'], '* Terms (guide: 3-full-content.md#categories-and-tags).'));
    }

    public function test_reference_missing_from_the_pinned_guide_fails(): void
    {
        $problems = self::problems(['src/WordPress/CmsTaxonomy.php'], '* New thing, 4-media-library.md#brand-new.');

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('not in the pinned guide: 4-media-library.md#brand-new', $problems[0]);
    }

    public function test_protocol_copy_counts_as_behaviour(): void
    {
        $this->assertCount(1, self::problems(['src/Protocol/Taxonomy.php'], '* Protocol 1.5.0.'));
    }

    public function test_wp_only_line_passes(): void
    {
        $this->assertSame([], self::problems(['src/WordPress/CmsTaxonomy.php'], "* Fix.\n* wp_only: a WordPress hook order, nothing another CMS does"));
        $this->assertCount(1, self::problems(['src/WordPress/CmsTaxonomy.php'], '* wp_only:'));
    }

    public function test_section_is_the_versions_own(): void
    {
        $readme = "== Changelog ==\n\n= 2.8.0 =\n* New.\n\n= 2.7.0 =\n* 3-full-content.md#categories-and-tags\n";

        $this->assertSame("* New.\n", ReleaseContract::section($readme, '2.8.0'));
        $this->assertStringContainsString('categories-and-tags', ReleaseContract::section($readme, '2.7.0'));
    }

    public function test_release_workflow_runs_the_gate_with_tags(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/release.yml');
        $start = strpos($workflow, '  verify:');
        $verify = substr($workflow, $start, strpos($workflow, "
  ci:") - $start);

        $this->assertStringContainsString('fetch-depth: 0', $verify);
        $this->assertStringContainsString('php ci/release-contract.php "${GITHUB_REF_NAME#v}"', $verify);
    }
}
