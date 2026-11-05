#!/usr/bin/env bash
# Bramka spojnosci wersji. Szesc miejsc, nie cztery: piatym jest sekcja changeloga
# w readme.txt, bo z niej powstaje sections.changelog w manifescie — bez niej
# ekran "Zobacz szczegoly" bylby pusty, a CI zameldowaloby sukces. Szostym jest
# link do ZIP-a w README.md — to on trafia bezposrednio w rece klienta.
set -euo pipefail

VERSION="${1:-}"

if [[ -z "$VERSION" ]]; then
    echo "Uzycie: bin/check-version.sh <wersja>   (np. 0.7.1)" >&2
    exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fail=0

check() {
    local label="$1" actual="$2"
    if [[ "$actual" != "$VERSION" ]]; then
        echo "ROZJAZD: ${label} = '${actual:-<brak>}', oczekiwano '${VERSION}'" >&2
        fail=1
    else
        echo "OK: ${label} = ${actual}"
    fi
}

strip_cr() { tr -d '\r'; }

header=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' \
    "$ROOT/headlinesforge-receiver.php" | head -1 | strip_cr)

constant=$(sed -n "s/^define('HEADLINESFORGE_VERSION',[[:space:]]*'\([^']*\)').*/\1/p" \
    "$ROOT/headlinesforge-receiver.php" | head -1 | strip_cr)

stable=$(sed -n 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p' "$ROOT/readme.txt" | head -1 | strip_cr)

readme_md=$(sed -n 's/^\*\*Version:\*\*[[:space:]]*v\(.*\)$/\1/p' "$ROOT/README.md" | head -1 | strip_cr)

check "naglowek Version w headlinesforge-receiver.php" "$header"
check "stala HEADLINESFORGE_VERSION"                   "$constant"
check "Stable tag w readme.txt"                        "$stable"
check "**Version:** w README.md"                       "$readme_md"

if grep -q "^= ${VERSION} =[[:space:]]*$" "$ROOT/readme.txt"; then
    echo "OK: sekcja changeloga '= ${VERSION} =' w readme.txt"
else
    echo "ROZJAZD: brak sekcji changeloga '= ${VERSION} =' w readme.txt" >&2
    fail=1
fi

if grep -q "headlinesforge-receiver-${VERSION}\.zip" "$ROOT/README.md"; then
    echo "OK: link do ZIP-a 'headlinesforge-receiver-${VERSION}.zip' w README.md"
else
    echo "ROZJAZD: brak linku do ZIP-a 'headlinesforge-receiver-${VERSION}.zip' w README.md" >&2
    fail=1
fi

exit "$fail"
