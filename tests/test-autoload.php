<?php
declare(strict_types=1);

/**
 * Layout of 2.0.0 (spec D10 "Structure"): classes under src/ in the TribuneDesk namespace,
 * loaded by the plugin's own autoloader; the 1.x names (HeadlinesForge\...) stay as aliases so
 * themes or site code calling them keep working (plan E4b, Ruling 1).
 */
class Test_Autoload extends WP_UnitTestCase
{
    /** @return array<string, string> old name => new name */
    private function aliases(): array
    {
        return require dirname(__DIR__) . '/src/aliases.php';
    }

    public function test_every_old_class_name_resolves_to_the_new_class(): void
    {
        $aliases = $this->aliases();
        $this->assertGreaterThanOrEqual(30, count($aliases));
        foreach ($aliases as $old => $new) {
            $this->assertTrue(class_exists($old) || enum_exists($old), $old);
            $this->assertSame($new, (new ReflectionClass($old))->getName(), $old);
        }
    }

    public function test_every_class_lives_in_a_file_named_after_it(): void
    {
        $root = dirname(__DIR__) . '/src/';
        foreach ($this->aliases() as $new) {
            $relative = str_replace(['TribuneDesk\\', '\\'], ['', '/'], $new) . '.php';
            $this->assertFileExists($root . $relative, $new);
        }
    }

    public function test_no_includes_directory_left(): void
    {
        $this->assertDirectoryDoesNotExist(dirname(__DIR__) . '/includes');
    }

    public function test_release_zip_takes_src_not_includes(): void
    {
        $release = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/release.yml');
        $this->assertStringContainsString('cp -r src ', $release);
        $this->assertStringNotContainsString('cp -r includes ', $release);
    }
}
