<?php
declare(strict_types=1);

/**
 * Sklada manifest z readme.txt. Uruchamiany DOKLADNIE RAZ, w release.yml.
 * publish-manifest.yml tylko kopiuje wynik — dzieki temu nie ma drugiego miejsca,
 * w ktorym manifest moglby powstac inaczej.
 *
 * Uzycie: php bin/build-manifest.php <wersja> <sha256> <url-pakietu>
 */

$version = $argv[1] ?? '';
$sha256  = $argv[2] ?? '';
$package = $argv[3] ?? '';

if ($version === '' || $sha256 === '' || $package === '') {
    fwrite(STDERR, "Uzycie: php bin/build-manifest.php <wersja> <sha256> <url-pakietu>\n");
    exit(2);
}

if (preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
    fwrite(STDERR, "sha256 musi miec 64 znaki hex, dostalem: {$sha256}\n");
    exit(2);
}

$root   = dirname(__DIR__);
$readme = (string) file_get_contents($root . '/readme.txt');

// Repo ma CRLF (checkout na Windows). Bez normalizacji "$" z flaga "m" laduje
// przed "\n", a "\r" zostaje wewnatrz przechwyconych wartosci (np. "4.7\r").
$readme = str_replace("\r\n", "\n", $readme);

/** Wyciaga wartosc naglowka typu "Requires at least: 4.7". */
$header = static function (string $name) use ($readme): string {
    $pattern = '/^' . preg_quote($name, '/') . ':\s*(.+)$/mi';

    return preg_match($pattern, $readme, $m) === 1 ? trim($m[1]) : '';
};

/**
 * Nazwa wtyczki z tytulu readme.txt ("=== Nazwa ==="), a nie wpisana tutaj.
 *
 * Stala tu byla nazwa marki, wiec rebranding pliku glownego i readme.txt zmienial
 * wszystko OPROCZ manifestu — a to wlasnie manifest niesie nazwe na ekran "Zobacz
 * szczegoly" i do powiadomienia o aktualizacji u klienta. Rozjazd bylby niewidoczny
 * w kodzie i widoczny wylacznie u klienta.
 */
$plugin_name = preg_match('/^===\s*(.+?)\s*===$/m', $readme, $m) === 1 ? trim($m[1]) : '';
if ($plugin_name === '') {
    fwrite(STDERR, "nie znalazlem tytulu '=== Nazwa ===' w readme.txt\n");
    exit(2);
}

/** Sekcja "== Description ==" do nastepnego naglowka "== ... ==". */
$description = '';
if (preg_match('/^== Description ==\s*(.*?)^==/ms', $readme, $m) === 1) {
    $description = trim($m[1]);
}

/** Sekcja changeloga "= <wersja> =" do nastepnej sekcji albo konca pliku. */
$changelog = '';
$pattern   = '/^= ' . preg_quote($version, '/') . ' =\s*$(.*?)(?=^= |\z)/ms';
if (preg_match($pattern, $readme, $m) === 1) {
    $changelog = trim($m[1]);
}

if ($changelog === '') {
    fwrite(STDERR, "Brak sekcji changeloga '= {$version} =' w readme.txt\n");
    exit(1);
}

/**
 * Tekst w linii: escape HTML, potem `kod` -> <code>. Kolejnosc ma znaczenie -
 * najpierw escape, zeby tresc z readme nie mogla wstrzyknac znacznikow.
 */
$inline = static function (string $s): string {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    return preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
};

/**
 * readme.txt -> HTML dla ekranu "Zobacz szczegoly". WordPress wypisuje sekcje
 * manifestu jako HTML (plugin-install.php, po wp_kses), wiec surowy tekst
 * z "* " sklejalby sie w jeden akapit - tak wygladal sidecar 0.7.1.
 * Obslugiwane: akapity rozdzielone pusta linia, listy "* " z kontynuacja
 * w kolejnych (wcietych) liniach, `kod`.
 */
$render = static function (string $text) use ($inline): string {
    $html = [];

    foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $block) {
        $lines = explode("\n", trim($block));

        if (preg_match('/^\* /', $lines[0]) !== 1) {
            $html[] = '<p>' . $inline(implode(' ', array_map('trim', $lines))) . '</p>';
            continue;
        }

        $items = [];
        foreach ($lines as $line) {
            if (preg_match('/^\* (.*)$/', $line, $m) === 1) {
                $items[] = $m[1];
            } elseif ($items !== []) {
                $items[count($items) - 1] .= ' ' . trim($line);
            }
        }

        $html[] = '<ul>' . implode('', array_map(
            static fn (string $item): string => '<li>' . $inline($item) . '</li>',
            $items
        )) . '</ul>';
    }

    return implode("\n", $html);
};

$manifest = [
    'name'         => $plugin_name,
    // Slug ZOSTAJE. To nazwa katalogu wtyczki u klienta i klucz, po ktorym WordPress
    // rozpoznaje, ze aktualizacja dotyczy TEJ instalacji. Zmiana odcielaby od
    // aktualizacji wszystkich, ktorzy juz ja maja.
    'slug'         => 'headlinesforge-receiver',
    'version'      => $version,
    'package'      => $package,
    'sha256'       => strtolower($sha256),
    'requires'     => $header('Requires at least'),
    'tested'       => $header('Tested up to'),
    'requires_php' => $header('Requires PHP'),
    'last_updated' => gmdate('Y-m-d H:i:s'),
    // Link klikalny z ekranu aktualizacji. Stara domena przekierowuje 308, wiec
    // dzialalaby dalej — ale klient ma trafiac wprost na zywa marke, bez skoku.
    'homepage'     => 'https://tribunedesk.com',
    'author'       => 'Dominik Czarnota',
    'sections'     => [
        'description' => $render($description),
        'changelog'   => $render($changelog),
    ],
];

echo json_encode(
    $manifest,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
