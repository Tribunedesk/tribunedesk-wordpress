<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ci/GuideAnchors.php';

use TribuneDesk\Ci\GuideAnchors;

/**
 * Anchors of the guide's headings, computed the way GitHub does: docs/contract-map.json and the
 * README link to them, and the contract drift reports the ones a new tag no longer has.
 */
class Test_Guide_Anchors extends \PHPUnit\Framework\TestCase
{
    public function test_slug_matches_github(): void
    {
        $this->assertSame('the-read-more-box', GuideAnchors::slug('The “read more” box'));
        $this->assertSame('level-1--article-delivery', GuideAnchors::slug('Level 1 – Article delivery'));
        $this->assertSame('conflicts-and-base_version', GuideAnchors::slug('Conflicts and base_version'));
        $this->assertSame('seo-author-and-language', GuideAnchors::slug('SEO, author and language'));
    }

    public function test_anchors_skip_fenced_code(): void
    {
        $markdown = "## Sync\n\n```bash\n# first poll: no cursor\ncurl …\n```\n";

        $this->assertSame(['sync'], GuideAnchors::anchors($markdown));
    }

    public function test_anchors_number_duplicates(): void
    {
        $markdown = "## Done when\n\ntext\n\n## Done when\n";

        $this->assertSame(['done-when', 'done-when-1'], GuideAnchors::anchors($markdown));
    }
}
