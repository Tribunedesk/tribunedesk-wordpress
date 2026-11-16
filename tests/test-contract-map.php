<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ci/GuideAnchors.php';

use TribuneDesk\Ci\GuideAnchors;

/**
 * The plugin's feature map, docs/contract-map.json (spec "kontrakt najpierw" D1 in
 * Tribunedesk/tribunedesk-cms-kit): every class of src/WordPress/ points at a section of the guide
 * of the contract tag named in tribunedesk.json (copied to tests/contract/guide/), or says why it
 * is WordPress only. A new class without an entry fails here, so a behaviour another CMS should
 * have does not stay in the plugin alone.
 */
class Test_Contract_Map extends \PHPUnit\Framework\TestCase
{
    private const FIX = 'add "guide" or "wp_only" for it in docs/contract-map.json';

    /** @return list<array<string, mixed>> */
    private static function entries(): array
    {
        $map = json_decode((string) @file_get_contents(dirname(__DIR__) . '/docs/contract-map.json'), true);
        self::assertIsArray($map, 'docs/contract-map.json is missing or not JSON');
        self::assertIsArray($map['classes'] ?? null, 'docs/contract-map.json has no "classes" list');

        return array_values($map['classes']);
    }

    /** @return list<string> */
    private static function mapped(): array
    {
        return array_map(static fn (array $e): string => (string) ($e['class'] ?? ''), self::entries());
    }

    /** @return list<string> */
    private static function classes(): array
    {
        return array_map(static fn (string $f): string => basename($f, '.php'), glob(dirname(__DIR__) . '/src/WordPress/*.php') ?: []);
    }

    public function test_every_class_has_an_entry(): void
    {
        $missing = array_values(array_diff(self::classes(), self::mapped()));

        $this->assertSame([], $missing, 'src/WordPress classes without an entry: ' . self::FIX);
    }

    public function test_every_entry_names_a_class(): void
    {
        $stale = array_values(array_diff(self::mapped(), self::classes()));

        $this->assertSame([], $stale, 'docs/contract-map.json names classes src/WordPress/ does not have');
    }

    public function test_no_duplicate_classes(): void
    {
        $counts = array_count_values(self::mapped());

        $this->assertSame([], array_keys(array_filter($counts, static fn (int $n): bool => $n > 1)), 'classes listed twice');
    }

    public function test_entry_has_guide_xor_wp_only(): void
    {
        foreach (self::entries() as $entry) {
            $guide = trim((string) ($entry['guide'] ?? ''));
            $wpOnly = trim((string) ($entry['wp_only'] ?? ''));
            $this->assertTrue(($guide === '') !== ($wpOnly === ''), "{$entry['class']}: exactly one of \"guide\" and \"wp_only\", not empty");
        }
    }

    public function test_guide_points_at_a_heading_of_the_pinned_guide(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/tribunedesk.json'), true);
        $contract = (string) ($manifest['contract'] ?? '?');
        foreach (self::entries() as $entry) {
            $guide = (string) ($entry['guide'] ?? '');
            if ($guide === '') {
                continue;
            }
            [$file, $anchor] = array_pad(explode('#', $guide, 2), 2, '');
            $path = __DIR__ . '/contract/guide/' . $file;
            $this->assertFileExists($path, "{$entry['class']}: \"$guide\" names no file of guide/ in contract $contract");
            $this->assertContains($anchor, GuideAnchors::anchors((string) file_get_contents($path)), "{$entry['class']}: \"$guide\" names no heading of guide/$file in contract $contract");
        }
    }
}
