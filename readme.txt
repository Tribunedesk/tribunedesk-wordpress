=== Tribune Desk ===
Contributors: dczarnota
Tags: webhook, automation, content
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 2.2.2
License: MIT

Receives finished articles from Tribune Desk (ArticleWriter) and creates them as drafts in WordPress.

== Description ==

This plugin exposes one REST endpoint under two paths — `POST /wp-json/tribunedesk/v1/receive` and, unchanged, `POST /wp-json/headlinesforge/v1/receive` — that accepts a JSON payload from Tribune Desk / ArticleWriter and creates the article as a draft post. Every delivery must carry a valid Standard Webhooks signature made with the newsroom's signing secret (`whsec_…`).

Features:

* Idempotent — same `article_id` re-sent updates the existing draft (only if still in draft state)
* Featured image auto-set from the first image in `generated_images[]`; an existing library attachment is reused (optional `asset_id`, `cms_attachment_id`) instead of a duplicate, and the response reports it as `featured_image` {asset_id, attachment_id, url}
* HTML sanitization with `<iframe>` whitelist for YouTube, Vimeo, Twitter, Instagram, TikTok, Facebook embeds
* Post author taken from Tribune Desk: the person who clicked "Send to CMS", then the article author — matched by e-mail or assigned by hand; the configurable default author covers everyone else
* Last 20 deliveries visible in the admin settings page, each tagged as received / URL sent / backfill
* Yoast SEO fields (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`) filled from the payload — never overwriting a value an editor already set
* Sends the permalink of every published article back to Tribune Desk (`permalink` in the webhook response and, with a CMS API token, `POST /v3/cms/articles/published` on publish), so later articles link to it with the real URL
* Replaces Tribune Desk internal-link placeholders with permalinks of articles already published on this site
* One-click backfill of URLs for articles published before this version
* With a CMS API token, exchanges media with the Tribune Desk library through `/v3/cms`: new images, field edits and deletions; without a token no media is sent

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/`.
2. Activate the plugin in `Wtyczki`.
3. Go to `Ustawienia → Tribune Desk`, paste the signing secret (`whsec_…`) and the CMS API token you got from Tribune Desk, pick the default author, and copy the Webhook URL into Tribune Desk Settings → Integracje.

== Changelog ==

= 2.2.2 =
* Embeds (`iframe` from YouTube, Vimeo and the other allowed hosts) survive the save, also when a link placeholder is resolved later: WordPress filtered post content once more as the anonymous delivery user and dropped them.
* A post waiting for review (`pending`) is updated by a new send like a draft; only a published, scheduled or private post is left alone.
* The featured image is downloaded within 15 seconds, so the answer to Tribune Desk comes in time; a slower image is attached a minute later in the background.
* The link back is sent only to an `https://` API address, like every other call with the token. The default API address is `https://api.tribunedesk.com`; a saved address stays.
* A post published at once reports the "do not publish on Facebook" field in the delivery answer, so a flagged post never enters the Tribune Desk Facebook queue (contract 3.4.0).
* The delivery answer gives the featured image id also as `cms_media_id` (contract 3.4.0).
* Publishing a post with internal-link placeholders reports its address once, not twice.
* A sync run that outlived its lock no longer removes the lock of a newer run.
* Messages in the admin say Tribune Desk instead of HeadlinesForge. Deactivating clears pending retries; uninstalling also removes the resync state, the auto-update setting, the update manifest cache and the resync cron.

= 2.2.1 =
* New versions reach a site within about 13 hours instead of up to about 24: the plugin keeps the update manifest for 1 hour (before: 12), while WordPress itself checks for updates every 12 hours.

= 2.2.0 =
* Deliveries are accepted only with a valid signature. Tribune Desk stopped sending the shared secret (`X-Webhook-Secret`) on 2026-11-06, so the plugin no longer checks it and the "Webhook secret" field is gone from the settings.
* Without a signing secret every delivery is refused (401), and a red notice at the top of Settings → Tribune Desk says so until the secret is saved. The checkbox that removes the signing secret now says the same.
* The link back goes only through `/v3/cms/articles/published` with the CMS API token. The old `/v2/cms/published-urls` route, which Tribune Desk removed on 2026-11-06, is no longer called, not even after a refused token. Without a token nothing is sent and the deliveries log says a token with `articles:write` is needed; a post published at once still returns its `permalink` in the delivery answer.
* A shared secret saved by an earlier version stays in the database untouched, so going back to 2.1.0 still works; uninstalling removes it.

= 2.1.0 =
* New setting "CMS field: do not publish on Facebook" (Theme fields): the meta key of the post field that keeps an article off Facebook. When set, every report of a published post carries `social_queue_exclude` (true for 1, true, yes or on; false otherwise, also when the field is empty or deleted), so Tribune Desk keeps the article out of its Facebook queue. Empty setting: nothing changes and the field is never sent.
* A change of that field on a published post from Tribune Desk is reported again, once per post and request. An ordinary edit sends nothing.
* The publish report now goes after the post is saved (`wp_after_insert_post`), so fields the theme stores on save are already there.
* WordPress 5.6 or newer is now required (before: 5.3).
* `PublishNotifier::on_transition` (hooked to `transition_post_status`) is replaced by `PublishNotifier::on_after_insert` (hooked to `wp_after_insert_post`). Site code that calls or unhooks the old method must switch to the new one.
* The old `/v2/cms/published-urls` route never gets the new field.

= 2.0.3 =
* A delivery answer now always carries `edit_url`, so Tribune Desk can show "Edit in CMS". Before, the plugin built it with `get_edit_post_link()`, which returns nothing for a request without a logged-in editor, and every delivery is one.

= 2.0.2 =
* Settings and the "forbidden" message list `articles:write` among the token's required permissions (the link back has needed it since 1.17.0).
* After a 401 from Tribune Desk the delivery log says the token was rejected (revoked or invalid) instead of blaming a missing permission; the link back still falls back to the old route as before.
* Plugin URI points to the public source repository, https://github.com/Tribunedesk/tribunedesk-wordpress. README has a section for developers.

= 2.0.1 =
* The post excerpt now comes from the delivery's `excerpt` field, as the Tribune Desk CMS API guide describes. With "Excerpt from Facebook teaser" on, the teaser still goes first and `excerpt` fills in when a delivery has no teaser. As before, an excerpt an editor wrote is never overwritten.

= 2.0.0 =
* The plugin is now called "Tribune Desk". Its folder, main file, settings, post meta and tables keep their 1.x names, so the update keeps it active and keeps all data.
* Code is in English and split for readers: `src/Protocol/` holds the rules of the Tribune Desk CMS API in plain PHP (portable to other CMSs), `src/WordPress/` the WordPress side. Each protocol class says which integration level, routes and guide chapter it implements.
* Strings shown to people are English in code and translated through the `tribune-desk` text domain. Polish sites see the same Polish texts as before (languages/tribune-desk-pl_PL); other sites see English.
* Classes moved to the `TribuneDesk\` namespace. The 1.x names (`HeadlinesForge\...`) still work as aliases. Code that removed one of our hooks by the old class name (`remove_action(..., ['HeadlinesForge\X', ...])`) must use the new name.
* Public filters have new names `tribune_desk_*`; the old `headlinesforge_*` names keep working.
* README in English.

= 1.17.0 =
* Podpis dostaw (Standard Webhooks): nowe pole "Sekret podpisu (whsec_...)". Gdy jest ustawione, wtyczka przyjmuje tylko dostawy z poprawnym podpisem i znacznikiem czasu do 5 minut od zegara serwera, a sekret webhooka nie jest juz sprawdzany. Bez niego wszystko dziala jak w 1.16.0.
* Link zwrotny tokenem API CMS: z tokenem adres i data publikacji ida do `/v3/cms/articles/published`. Token bez uprawnienia `articles:write` (tylko media) dostaje odmowe - wtedy ta sama paczka idzie stara droga `/v2/cms/published-urls`, a log mowi dlaczego.
* Format zastepnika linku wewnetrznego z dostawy (`link_placeholder_prefix`) zamiast wpisanego na sztywno `/app/a/`. Stare zastepniki nadal sa podmieniane.
* Obrazki tylko przez https i, gdy jest token, tylko z hostow, ktore podaje Tribune Desk (`image_hosts`).
* Boks "Czytaj wiecej": shortcode `[tribune_desk_read_more limit="5" title="..."]` i blok "Czytaj wiecej (Tribune Desk)". Wtyczka nie wstawia go sama.
* Sekret webhooka, sekret podpisu i token sa polami hasla: nie widac ich w zrodle strony, a zapis z pustym polem zostawia zapisana wartosc. Sekret podpisu mozna usunac zaznaczeniem pod polem.
* Obrazek odrzucony przez sprawdzenie hostow zostawia wiersz w logu dostaw; pusta lista hostow znaczy "nieznana" (tylko https). Link zwrotny przechodzi na stara droge takze przy 404 z `/v3`.
* Odpowiedz na dostawe ma `cms_post_id`; odpowiedz 500 nie zawiera tekstu bledu (zostaje w logu dostaw).

= 1.16.0 =
* Usunieto stara droge synchronizacji mediow: wysylke do `/v2/cms/media` i `/v2/cms/media/meta` oraz trase `/media-meta`. Media ida wylacznie przez `/v3/cms`.
* Bez tokenu API CMS wtyczka nie wysyla mediow. Ekran Tribune Desk pokazuje wtedy "Synchronizacja mediow wymaga tokenu API CMS", a sekcji "Zdjecia do biblioteki Tribune Desk" juz nie ma.
* Zmiany pol bez tokenu albo przy wylaczonej integracji czekaja w kolejce, najwyzej dla 500 zalacznikow, i wychodza po wlaczeniu. Zmiany kolejnych zalacznikow ponad limit nie sa zapisywane.
* Aktualizacja raz usuwa martwe zadanie crona `headlinesforge_media_meta_push`. Godzinne `headlinesforge_media_push` zostaje jako siatka bezpieczenstwa wysylki przez `/v3`.
* Dostawa artykulow, `/receive` i wysylka `published-urls` bez zmian.

= 1.15.0 =
* Usuniecie obrazu w bibliotece mediow trafia do Tribune Desk: wtyczka dopisuje je do kolejki, a cron wysyla porcjami po 100, do 3 porcji na przebieg. Tribune Desk nie usuwa wtedy pliku trwale. Bez tokenu nic nie jest kolejkowane.
* Blad serwera albo polaczenia zostawia kolejke do ponowienia. Backend bez tej trasy (501) tez ja zostawia i niczego nie zatrzymuje. Kolejka ma limit 5000 wierszy: zostaja najstarsze, a o obcieciu mowi ekran Tribune Desk.
* Usuniecie w Tribune Desk moze oznaczyc plik ukrytym znacznikiem, gdy wlaczono opcje. Plik uzyty w opublikowanej tresci (wpis nadrzedny, obraz wyrozniajacy, adres w tresci) znacznika nie dostaje. Przywrocenie w Tribune Desk zdejmuje znacznik. Wtyczka niczego nie kasuje i nie wlacza kosza mediow.
* Filtr "Usuniete w TD" w bibliotece mediow, w widoku listy i siatki, widoczny tylko, gdy sa takie pliki.
* Opcje usuwania na ekranie Tribune Desk nie maja juz dopisku "zacznie dzialac pozniej".

= 1.14.0 =
* Import wsteczny: gdy administrator Tribune Desk zleci import, wtyczka co 5 minut oddaje kandydatow z biblioteki mediow, od najnowszych, do 3 stron po 500, wedlug filtrow z zadania. Tylko adresy i dane zalacznikow, bez czytania plikow. Pliki z Tribune Desk nie sa wysylane.
* Przerwany eksport wraca do ostatniej przyjetej strony; pozycja znika, gdy Tribune Desk odpowie, ze zadanie jest zamkniete albo nieznane, lub po 30 dniach bez ruchu. Wstrzymanie zadania jej nie kasuje. Strona odrzucona przez serwer wraca co 5 minut, a po 5 odrzuceniach eksport tego zadania czeka godzine.
* Token potrzebuje uprawnienia backfill:write; bez niego reszta wymiany dziala jak dotad, a ekran Tribune Desk pokazuje blad eksportu.
* Usunieto z ekranu pole "Wyslij tez zdjecia wgrane od dnia": starsze pliki obejmuje teraz import wsteczny. Wysylka nowych zdjec dziala bez zmian.

= 1.13.0 =
* Nowe obrazy z Tribune Desk trafiaja kanalem /v3/cms do biblioteki mediow razem z tekstami. Wtyczka sprawdza pobrany plik (pusty, nie-obraz, inny sha ponawia do 5 razy) i zglasza go do Tribune Desk. Plik juz obecny, np. z dostawy artykulu, nie jest pobierany drugi raz.
* Pobieranie plikow konczy przebieg po 120 sekundach; kursor jest zapisywany po kazdym pliku.
* Na ekranie Tribune Desk przycisk "Wyslij starsze pliki z Tribune Desk" z opcjonalna data, wysylajacy porcje przy kolejnych kliknieciach. Opcje TD -> CMS dla nowych plikow i kopii juz dzialaja.

= 1.12.0 =
* Wymiana z Tribune Desk przez /v3/cms, gdy jest token i wlaczona integracja: kanal zmian co 5 minut, wysylka pol i nowych plikow minute po zmianie, godzinny cron jako siatka bezpieczenstwa. Bez tego wszystko dziala starymi trasami.
* Pole zmienione po obu stronach rozstrzyga regula konfliktu z Tribune Desk, a wtyczka przyjmuje wartosc zwycieska. Zapisy z kanalu nie wracaja do wysylki.
* Na ekranie Tribune Desk: ostatni przebieg pobierania i wysylki wraz z bledem. Opcje CMS -> TD, konflikt i "nie nadpisuj niepustych" juz dzialaja.

= 1.11.0 =
* Minimalna wersja WordPressa to teraz 5.3.
* Nowy blok "Synchronizacja mediow z Tribune Desk" na stronie Tribune Desk: stan integracji, wariant i opcje zapisywane w Tribune Desk, decyzje o wstrzymanych paczkach. Wymaga nowego pola "Token API CMS".
* Synchronizacja meta tagow sledzi tylko zmiany z panelu i REST zalogowanego uzytkownika: WP-CLI, cron i zapisy anonimowe nie oznaczaja juz pol.

= 1.10.0 =
* Synchronizacja meta tagow zalacznikow z Tribune Desk w obie strony: tylko zmiany czlowieka od chwili wlaczenia.
* Tytul zalacznika z pola title w dostawie.

= 1.9.0 =
* Zdjecia z biblioteki mediow WordPressa trafiaja do biblioteki Tribune Desk: co godzine, po 50 sztuk,
  tylko nowsze niz moment pierwszego uruchomienia tej wersji.
* Zdjecia pobrane z Tribune Desk nie wracaja do niego.
* Nowe pole w Ustawieniach: "Wyslij tez zdjecia wgrane od dnia" - przesuwa prog wstecz.
* Wysylka jest wstrzymywana po bledzie lub limicie, a wynik widac w "Recent deliveries" jako "Zdjecia do biblioteki".
* Wylaczenie wtyczki zatrzymuje wysylke; usuniecie czysci opcje, znaczniki i zdarzenie.

= 1.8.0 =
* Pole `language` z Tribune Desk (jezyk pisania redakcji) przypisuje NOWY wpis do wersji
  jezykowej Polylang. Bez Polylang pole jest ignorowane i nic sie nie zmienia. Nieznany kod
  jezyka zostawia wpis w jezyku domyslnym i jest widoczny w "Recent deliveries".

= 1.7.0 =
* Pola `evergreen` i `backlink_sources` z Tribune Desk trafiaja do meta `_hf_evergreen` i
  `_hf_backlink_sources` (JSON). Gdy pol brak, nic sie nie zmienia.
* Nowa tabela `{prefix}hf_backlinks` zakladana automatycznie przy aktualizacji. Wiersze sa
  zastepowane przy publikacji wpisu i przy ponownej wysylce opublikowanego; tresc wpisu zostaje nietknieta.
* Blad zapisu powiazan nie blokuje publikacji i jest widoczny w "Recent deliveries" jako "powiazania".

= 1.6.0 =
* Zdjecie glowne bez duplikatow zalacznikow: wtyczka uzywa istniejacego zalacznika zamiast
  pobierac plik od nowa - rozpoznaje go po identyfikatorze zdjecia z Tribune Desk
  (`generated_images[0].asset_id`, `generated_images[0].cms_attachment_id`), po adresie
  wlasnego pliku z biblioteki mediow, a plik lezacy w uploads bez zalacznika dostaje
  zalacznik bez kopiowania. Teksty istniejacego zalacznika (alt, podpis, opis) zostaja nietkniete.
* Odpowiedz na webhook niesie `featured_image` (`asset_id`, `attachment_id`, `url` oryginalu
  pliku w bibliotece CMS) przy kazdej dostawie, gdy zdjecie glowne wpisu ma znak
  identyfikatora zdjecia. Bez znaku klucza nie ma.

= 1.5.0 =
* Znakowanie AI przy Yoast SEO: pola znakowania (digitalSourceType, podpis, identyfikator)
  trafiaja do artykulu w danych strukturalnych Yoast zamiast osobnego obiektu NewsArticle -
  jeden opis tekstu z autorem i oznaczeniem AI. Bez Yoast osobny blok jak dotad.

= 1.4.0 =
* Znakowanie AI: gdy ponownie wyslany szkic przychodzi bez bloku znakowania (wydawca
  wylaczyl je w Tribune Desk) albo z blokiem nieczytelnym, wtyczka usuwa zapisane
  wczesniej znakowanie wpisu - stary podpis nie pasowalby do nowej tresci.
  Opublikowanych wpisow to nie dotyczy.

= 1.3.0 =
* Pola motywu: opcja "Zajawka z teasera FB" — teaser na Facebooka z Tribune Desk trafia do
  pustej zajawki wpisu (np. motyw, w ktorym zajawka = opis karty na Facebooku i w RSS). Domyslnie
  wylaczona; zajawka wpisana przez redaktora nie jest nadpisywana.

= 1.2.0 =
* Autor wpisu z Tribune Desk: autorem zostaje osoba, ktora kliknela "Send to CMS",
  a gdy jej nie da sie przypisac - autor artykulu w Tribune Desk. Wtyczka sama dopasowuje
  konto WordPress z tym samym adresem e-mail (tylko konta z prawem pisania wpisow).
* Nowa sekcja w Ustawienia -> Tribune Desk: "Autorzy z Tribune Desk" - lista osob, ktore
  przyslaly artykuly, z recznym przypisaniem konta WordPress i podgladem, kto zostanie autorem.
* "Default author" dziala dalej dla osob bez przypisania i dla starszych wersji Tribune Desk,
  ktore nie przysylaja autora - bez tego nic sie nie zmienia.
* Ponowna wysylka do szkicu ustawia autora na nowo; opublikowanym wpisom autor sie nie zmienia.

= 1.1.0 =
* Pola motywu: w ustawieniach (sekcja "Pola motywu") mozna wskazac klucze meta, do ktorych
  wtyczka skopiuje opis meta i tytul na Facebooka z Tribune Desk — obok pol Yoast. Puste
  ustawienie = bez zmian. Wypelniane jest tylko puste pole wpisu.
* Obrazek wyrozniajacy dostaje nazwe pliku z Tribune Desk albo z tytulu artykulu zamiast
  `result.jpg`.
* Przy publikacji link do artykulu Tribune Desk, ktorego nie ma w tym WordPressie (np.
  opublikowanego inna droga), jest zdejmowany — tekst zostaje. Link do artykulu, ktory
  czeka tu jako szkic, zostaje i podmieni sie przy jego publikacji, jak dotad.

= 1.0.0 =
* Zmiana marki na Tribune Desk. Zmienia sie WYLACZNIE to, co widac: nazwa wtyczki,
  opis, pozycja w menu ustawien i etykiety pol. Nazwa katalogu, sekret webhooka,
  autor domyslny, tabela dostaw i wszystkie opcje zostaja nietkniete — aktualizacja
  nie gubi zadnych ustawien.
* Endpoint dostepny takze pod `POST /wp-json/tribunedesk/v1/receive`. Stary adres
  `headlinesforge/v1` DZIALA DALEJ i bedzie dzialal — nie trzeba niczego przestawiac.
* Poprawka: odinstalowanie wtyczki kasuje takze opcje `publish_immediately`, ktora
  jako jedyna zostawala w bazie.

= 0.8.0 =
* Odpowiedz na webhook niesie `permalink` opublikowanego wpisu. Tribune Desk zapisuje
  go jako adres artykulu i od tej chwili kolejne artykuly linkuja do niego prawdziwym URL-em.
* Przy kazdej publikacji wpisu z Tribune Desk wtyczka odsyla jego adres na
  `POST /v2/cms/published-urls` (naglowek `X-Webhook-Secret`, ten sam sekret co webhook).
  Blad nie blokuje publikacji; przy bledzie sieci, 429 albo 5xx jest jedno ponowienie po 5 min.
* Linki wewnetrzne: placeholdery `headlinesforge.com/app/a/<id>` sa podmieniane na permalinki
  celow opublikowanych w tym WordPressie - przy odbiorze, przy kazdym zapisie wpisu i przy
  publikacji celu, takze w juz opublikowanych wpisach, ktore do niego linkuja.
* Nowy przycisk w Ustawienia -> Tribune Desk: "Wyslij adresy opublikowanych wpisow do
  Tribune Desk" - paczkami po 100, dla wpisow opublikowanych przed ta wersja.
* Nowe ustawienie "Adres API Tribune Desk" (domyslnie `https://api.headlinesforge.com`,
  wymagane https).
* Tabela "Recent deliveries" ma kolumne "Rodzaj" (odebrano / adres wyslany / backfill)
  i pokazuje 20 ostatnich wierszy zamiast 10. Schemat tabeli aktualizuje sie sam przy
  pierwszym zaladowaniu nowej wersji - takze po auto-aktualizacji.

= 0.7.2 =
* Dodany plik LICENSE (MIT) - repo deklarowalo licencje w naglowku wtyczki
  i w composer.json, ale samego pliku nie bylo. Od teraz jest tez w ZIP-ie.
* Ekran "Zobacz szczegoly" pokazuje opis i changelog jako akapity i listy,
  a nie jeden zlepiony blok tekstu - manifest niesie je teraz jako HTML.
* Pierwsze wydanie, ktore rozchodzi sie samo przez auto-aktualizacje z 0.7.1.

= 0.7.1 =
* Wtyczka sama sprawdza i instaluje nowe wersje z serwera Tribune Desk.
  Nowe wydania pojawiaja sie w "Wtyczki" jak kazda inna aktualizacja, razem
  z dzialajacym "Zobacz szczegoly".
* Automatyczne aktualizacje sa domyslnie WLACZONE. Mozna je wylaczyc w
  Ustawienia -> Tribune Desk albo przy wtyczce na liscie "Wtyczki" — to jedno
  i to samo ustawienie.
* Kazdy pobrany pakiet jest sprawdzany suma kontrolna sha256 przed instalacja.
  Niezgodnosc przerywa aktualizacje i zostawia dzialajaca wersje nietknieta.
* Aktualizacja NIE uruchamia uninstall.php: sekret webhooka, wybrany autor
  i historia dostarczen zostaja na miejscu.
* Zmieniono "Tested up to" na 7.1 — wersje 6.4 zostawiono szesc majorow wstecz.

= 0.7.0 =
* Usuniete mapowanie `related_topics` na tagi WordPressa. To sa PROPOZYCJE KOLEJNYCH
  ARTYKULOW z Tribune Desk, a nie opis biezacego wpisu: produkowaly taksonomie ze
  zdan (96 tagow o sredniej dlugosci 90 znakow na jednej stronie, kazdy uzyty raz),
  a `append` dokladal kolejne przy kazdej ponownej wysylce.
* Istniejace tagi zostaja nietkniete - wtyczka przestaje je tylko TWORZYC.

= 0.6.0 =
* Znakowanie AI (art. 50 AI Act) trafia wreszcie na opublikowana strone: blok JSON-LD
  jest zapisywany w post meta i wypisywany w <head>, zamiast przechodzic przez tresc.
* Poprawka bledu: wp_kses usuwal znacznik <script>, ale zostawial jego zawartosc, wiec
  podpis ladowal na stronie jako widoczny akapit base64 — i jednoczesnie przestawal byc
  czytelny maszynowo. Sanitizer wycina teraz skrypty razem z wnetrzem.
* Ponowna wysylka szkicu odswieza znakowanie (opisuje hash tresci). Na juz opublikowanym
  wpisie zostaje stare, bo tresc nie jest podmieniana.

= 0.5.0 =
* SEO title, meta description i focus keyphrase z Tribune Desk trafiaja do pol Yoast SEO.
* Pole, w ktorym redaktor juz cos wpisal, nigdy nie jest nadpisywane przy ponownej wysylce.
* Dziala takze na juz opublikowanym wpisie (pozno wygenerowane meta description dochodzi).

= 0.4.0 =
* Re-send of an already-published article now still attaches a late-arriving featured image and syncs tags (content/title are left untouched).

= 0.3.0 =
* Opcja "Publikuj od razu" — posty mogą być publikowane natychmiast zamiast jako szkice.

= 0.2.0 =
* Featured image attachment now applies optional `filename`, `caption`, `description`, `alt` metadata sent by ArticleWriter.
* `filename` updates the attachment title (Media library "Tytuł") and slug; underlying file on disk is not renamed (would break existing `<img src>` references).

= 0.1.0 =
* Initial release.
