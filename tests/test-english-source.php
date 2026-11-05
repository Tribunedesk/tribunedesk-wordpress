<?php
declare(strict_types=1);

/**
 * Code and comments are in English (spec D10 "Language"): a reader from another CMS must not
 * need Polish. Strings for people are checked by the i18n test; here only comments.
 */
class Test_English_Source extends WP_UnitTestCase
{
    private const POLISH_LETTERS = '/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u';

    /** Common Polish words that survive without diacritics ("nie", "zeby", "wpis"...). */
    private const POLISH_WORDS = '/\b(nie|jest|oraz|gdy|jesli|zeby|ktory|ktora|ktore|ktorych|wpis|wpisu|wpisow|sie|juz|tez|tylko|przy|bez|jak|dla|czy|albo|wiec|ale|dostawa|dostawy|sekretu|zeby|takze|teraz|potem|kazdy|kazda|kazdej)\b/iu';

    /** @return array<int, string> */
    private function files(): array
    {
        $root  = dirname(__DIR__);
        $files = [$root . '/uninstall.php', $root . '/headlinesforge-receiver.php'];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (str_ends_with((string) $file, '.php')) {
                $files[] = (string) $file;
            }
        }

        return $files;
    }

    public function test_php_comments_are_english(): void
    {
        $offenders = [];
        foreach ($this->files() as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (preg_match(self::POLISH_LETTERS, $token[1]) || preg_match(self::POLISH_WORDS, $token[1], $m)) {
                    $offenders[] = basename($file) . ':' . $token[2] . ' ' . trim(mb_substr($token[1], 0, 80));
                }
            }
        }
        $this->assertSame([], $offenders, implode("\n", array_slice($offenders, 0, 40)));
    }

    public function test_js_comments_are_english(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/assets/read-more-block.js');
        preg_match_all('#//[^\n]*|/\*.*?\*/#s', $js, $m);
        foreach ($m[0] as $comment) {
            $this->assertDoesNotMatchRegularExpression(self::POLISH_LETTERS, $comment);
            $this->assertDoesNotMatchRegularExpression(self::POLISH_WORDS, $comment);
        }
    }
}
