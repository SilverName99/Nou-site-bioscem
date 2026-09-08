<?php

declare(strict_types=1);

/**
 * Scoate mărcile, etichetele și setările SEO ale produselor din backup-ul
 * site-ului WordPress și le pune pe produsele magazinului de acum.
 *
 * La migrare s-au luat din WooCommerce doar denumirile, prețurile și pozele;
 * brandul și tag-urile au rămas în urmă. Sunt câteva sute de valori pe ~110
 * produse — de scris de mână înseamnă o zi pierdută, iar ele există deja în
 * dump-ul vechi.
 *
 * Acceptă fișierul de bază de date făcut de UpdraftPlus, oricum ar fi ambalat:
 * `.sql`, `.sql.gz` sau `.gz`. Se citește ca text, în flux — dump-ul e de
 * ordinul gigabaiților desfăcut, deci nu se încarcă în memorie.
 *
 * Potrivirea produselor se face pe slug (`post_name` din WordPress = `slug` în
 * magazin), care a rămas neschimbat la migrare. Produsele care nu se
 * regăsesc se listează la final.
 *
 * Rulare:
 *   php scripts/import-brand-taguri-wp.php /cale/backup_..._-db.gz
 *   php scripts/import-brand-taguri-wp.php /cale/backup_..._-db.gz --aplica
 *
 * Fără `--aplica` doar raportează și scrie un CSV lângă fișierul de intrare, ca
 * să se poată verifica înainte. Cu `--csv=/alta/cale.csv` se alege calea.
 *
 * Ce se scrie: `brand` și `tags_json` pe produs, plus titlul și descrierea SEO
 * (în `seo_pages`). Un produs care are deja valoarea respectivă nu se atinge,
 * decât cu `--suprascrie`.
 *
 * SEO-ul se caută în ordinea în care îl țin pluginurile obișnuite: Yoast,
 * RankMath, SEOPress. Șabloanele Yoast („%%title%% %%sep%% %%sitename%%") se
 * desfac, altfel s-ar scrie pe site chiar textul cu procente.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));
if (!$db instanceof PDO) {
    fwrite(STDERR, "Nu am putut deschide conexiunea la baza de date.\n");
    exit(1);
}

$argumente = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $a): bool => !str_starts_with($a, '--')
));
$aplica = in_array('--aplica', $argv, true);
$suprascrie = in_array('--suprascrie', $argv, true);
$caleCsv = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--csv=')) {
        $caleCsv = substr($a, 6);
    }
}
$fisier = (string) ($argumente[0] ?? '');
// „~" îl desface de obicei shell-ul, dar nu și când calea vine în ghilimele.
if (str_starts_with($fisier, '~/')) {
    $acasa = getenv('HOME');
    if (is_string($acasa) && $acasa !== '') {
        $fisier = $acasa . substr($fisier, 1);
    }
}
if ($fisier === '') {
    fwrite(STDERR, "Dă calea către fișierul de bază de date din backup:\n  php scripts/import-brand-taguri-wp.php backup_..._-db.gz [--aplica]\n");
    exit(1);
}
if (!is_file($fisier)) {
    // Pe găzduirile partajate, PHP e adesea închis în folderul domeniului
    // (`open_basedir`), iar un fișier din afara lui pare că nu există. Spunem
    // ce am încercat, altfel omul caută greșeala în numele fișierului.
    fwrite(STDERR, "Nu găsesc fișierul: {$fisier}\n");
    $limita = (string) ini_get('open_basedir');
    if ($limita !== '') {
        fwrite(STDERR, "PHP are voie să citească doar în: {$limita}\nMută backup-ul într-una dintre căile astea (de exemplu în folderul site-ului, dar NU în public_html).\n");
    }
    exit(1);
}
if ($caleCsv === '') {
    $caleCsv = dirname($fisier) . '/brand-taguri-wp.csv';
}

/** Deschide dump-ul, comprimat sau nu. */
function deschide(string $cale)
{
    $handle = @gzopen($cale, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Nu am putut deschide fișierul.\n");
        exit(1);
    }
    // gzopen citește și fișiere necomprimate, deci nu ne interesează extensia.
    return $handle;
}

/**
 * Desparte valorile unui `INSERT INTO ... VALUES (...),(...)` în rânduri, cu
 * respectarea ghilimelelor și a caracterelor scăpate. Nu e un parser de SQL,
 * dar dump-urile mysqldump au exact forma asta.
 *
 * @return array<int, array<int, ?string>>
 */
function randuriDinInsert(string $linie): array
{
    $start = strpos($linie, ' VALUES ');
    if ($start === false) {
        return [];
    }
    $corp = substr($linie, $start + 8);
    $randuri = [];
    $curent = [];
    $valoare = '';
    $inSir = false;
    $inRand = false;
    $eNull = false;
    $lungime = strlen($corp);

    for ($i = 0; $i < $lungime; $i++) {
        $c = $corp[$i];

        if ($inSir) {
            if ($c === '\\' && $i + 1 < $lungime) {
                $urm = $corp[$i + 1];
                $valoare .= match ($urm) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '0' => "\0",
                    default => $urm,
                };
                $i++;
                continue;
            }
            if ($c === "'") {
                // '' înseamnă un apostrof, nu sfârșitul șirului.
                if ($i + 1 < $lungime && $corp[$i + 1] === "'") {
                    $valoare .= "'";
                    $i++;
                    continue;
                }
                $inSir = false;
                continue;
            }
            $valoare .= $c;
            continue;
        }

        if (!$inRand) {
            if ($c === '(') {
                $inRand = true;
                $curent = [];
                $valoare = '';
                $eNull = false;
            }
            continue;
        }

        if ($c === "'") {
            $inSir = true;
            continue;
        }
        if ($c === ',') {
            $curent[] = $eNull ? null : $valoare;
            $valoare = '';
            $eNull = false;
            continue;
        }
        if ($c === ')') {
            $curent[] = $eNull ? null : $valoare;
            $randuri[] = $curent;
            $inRand = false;
            $valoare = '';
            $eNull = false;
            continue;
        }
        if ($c === ' ') {
            continue;
        }
        $valoare .= $c;
        if (strcasecmp($valoare, 'NULL') === 0) {
            $eNull = true;
        }
    }

    return $randuri;
}

/**
 * Cheile din `wp_postmeta` sub care pluginurile de SEO țin titlul și descrierea.
 * Ordinea contează: prima găsită câștigă.
 */
const SEO_CHEI_TITLU = ['_yoast_wpseo_title', 'rank_math_title', '_seopress_titles_title'];
const SEO_CHEI_DESCRIERE = ['_yoast_wpseo_metadesc', 'rank_math_description', '_seopress_titles_desc'];

/** Prefixul tabelelor din dump (wp_, wpxx_ etc.), aflat din prima potrivire. */
$prefix = null;
$produse = [];      // post_id → ['slug' => ..., 'titlu' => ...]
$termeni = [];      // term_id → ['nume' => ..., 'slug' => ...]
$taxonomii = [];    // term_taxonomy_id → ['term_id' => ..., 'taxonomie' => ...]
$legaturi = [];     // post_id → [term_taxonomy_id, ...]
$seoMeta = [];      // post_id → ['titlu' => ..., 'descriere' => ...]
$numeSite = '';

$handle = deschide($fisier);
$bucata = '';
while (($citit = gzgets($handle, 1024 * 1024)) !== false) {
    // Un `INSERT` din mysqldump ține sute de rânduri pe o singură linie și
    // trece ușor de un megabait. Citim în bucăți și le lipim până dăm de
    // capătul instrucțiunii, altfel am tăia un rând în două și l-am pierde.
    $bucata .= $citit;
    if (substr($bucata, -2) !== ";\n" && substr($bucata, -1) !== ';') {
        continue;
    }
    $linie = $bucata;
    $bucata = '';

    if (strncmp($linie, 'INSERT INTO', 11) !== 0) {
        continue;
    }
    if (preg_match('/^INSERT INTO `([a-z0-9_]*?)(posts|terms|term_taxonomy|term_relationships|postmeta|options|aioseo_posts)` /i', $linie, $m) !== 1) {
        continue;
    }
    $prefix ??= $m[1];
    if ($m[1] !== $prefix) {
        continue; // alt site în același dump (multisite) — se sare
    }

    switch (strtolower($m[2])) {
        case 'posts':
            foreach (randuriDinInsert($linie) as $rand) {
                // wp_posts: ID, post_author, post_date, ..., post_title(6),
                // ..., post_name(11), ..., post_type(20)
                $id = (int) ($rand[0] ?? 0);
                $titlu = (string) ($rand[5] ?? '');
                $slug = (string) ($rand[11] ?? '');
                $tip = (string) ($rand[20] ?? '');
                if ($id > 0 && $tip === 'product' && $slug !== '') {
                    $produse[$id] = ['slug' => $slug, 'titlu' => $titlu];
                }
            }
            break;
        case 'terms':
            foreach (randuriDinInsert($linie) as $rand) {
                $id = (int) ($rand[0] ?? 0);
                if ($id > 0) {
                    $termeni[$id] = ['nume' => (string) ($rand[1] ?? ''), 'slug' => (string) ($rand[2] ?? '')];
                }
            }
            break;
        case 'term_taxonomy':
            foreach (randuriDinInsert($linie) as $rand) {
                $ttId = (int) ($rand[0] ?? 0);
                if ($ttId > 0) {
                    $taxonomii[$ttId] = [
                        'term_id' => (int) ($rand[1] ?? 0),
                        'taxonomie' => (string) ($rand[2] ?? ''),
                    ];
                }
            }
            break;
        case 'postmeta':
            // Tabela e uriașă, dar ne interesează câteva chei. Linia se
            // desface abia dacă una dintre ele apare în text.
            $areCheie = false;
            foreach ([...SEO_CHEI_TITLU, ...SEO_CHEI_DESCRIERE] as $cheie) {
                if (str_contains($linie, $cheie)) {
                    $areCheie = true;
                    break;
                }
            }
            if (!$areCheie) {
                break;
            }
            foreach (randuriDinInsert($linie) as $rand) {
                // meta_id, post_id, meta_key, meta_value
                $postId = (int) ($rand[1] ?? 0);
                $cheie = (string) ($rand[2] ?? '');
                $valoare = trim((string) ($rand[3] ?? ''));
                if ($postId <= 0 || $valoare === '') {
                    continue;
                }
                $pozTitlu = array_search($cheie, SEO_CHEI_TITLU, true);
                if ($pozTitlu !== false) {
                    $existent = $seoMeta[$postId]['titlu_rang'] ?? PHP_INT_MAX;
                    if ($pozTitlu < $existent) {
                        $seoMeta[$postId]['titlu'] = $valoare;
                        $seoMeta[$postId]['titlu_rang'] = $pozTitlu;
                    }
                    continue;
                }
                $pozDesc = array_search($cheie, SEO_CHEI_DESCRIERE, true);
                if ($pozDesc !== false) {
                    $existent = $seoMeta[$postId]['descriere_rang'] ?? PHP_INT_MAX;
                    if ($pozDesc < $existent) {
                        $seoMeta[$postId]['descriere'] = $valoare;
                        $seoMeta[$postId]['descriere_rang'] = $pozDesc;
                    }
                }
            }
            break;
        case 'aioseo_posts':
            // All in One SEO ține datele în tabela lui, nu în postmeta.
            foreach (randuriDinInsert($linie) as $rand) {
                $postId = (int) ($rand[1] ?? 0);
                if ($postId <= 0) {
                    continue;
                }
                foreach ($rand as $celula) {
                    $text = trim((string) $celula);
                    if ($text === '' || mb_strlen($text) < 10) {
                        continue;
                    }
                    if (!isset($seoMeta[$postId]['titlu']) && mb_strlen($text) <= 120 && !str_contains($text, '{')) {
                        $seoMeta[$postId]['titlu'] = $text;
                        continue;
                    }
                    if (!isset($seoMeta[$postId]['descriere']) && mb_strlen($text) > 60 && !str_contains($text, '{')) {
                        $seoMeta[$postId]['descriere'] = $text;
                        break;
                    }
                }
            }
            break;
        case 'options':
            if (!str_contains($linie, 'blogname')) {
                break;
            }
            foreach (randuriDinInsert($linie) as $rand) {
                if ((string) ($rand[1] ?? '') === 'blogname') {
                    $numeSite = trim((string) ($rand[2] ?? ''));
                }
            }
            break;
        case 'term_relationships':
            foreach (randuriDinInsert($linie) as $rand) {
                $obiect = (int) ($rand[0] ?? 0);
                $ttId = (int) ($rand[1] ?? 0);
                if ($obiect > 0 && $ttId > 0) {
                    $legaturi[$obiect][] = $ttId;
                }
            }
            break;
    }
}
gzclose($handle);

if ($produse === []) {
    fwrite(STDERR, "N-am găsit produse în dump. Sigur e fișierul de bază de date, cel cu „-db' în nume?\n");
    exit(1);
}

/**
 * Taxonomiile care înseamnă „marcă". WooCommerce nu are brand în nucleu, așa
 * că fiecare magazin îl ține altfel: atribut (`pa_brand`), plugin (`pwb-brand`,
 * `product_brand`) sau taxonomia nouă din WooCommerce 9.
 */
$taxonomiiBrand = ['product_brand', 'pwb-brand', 'pa_brand', 'yith_product_brand', 'berocket_brand'];

/**
 * Desface șabloanele pluginurilor („%%title%% %%sep%% %%sitename%%") în text.
 * Ce nu se recunoaște se scoate, ca să nu ajungă procente pe pagină.
 */
function desfaSablon(string $text, string $titluProdus, string $numeSite): string
{
    $text = strtr($text, [
        '%%title%%' => $titluProdus,
        '%%sitename%%' => $numeSite,
        '%%sep%%' => '-',
        '%%page%%' => '',
        '%%primary_category%%' => '',
        '%%excerpt%%' => '',
        '%%sitedesc%%' => '',
        '%title%' => $titluProdus,
        '%sitename%' => $numeSite,
        '%sep%' => '-',
    ]);
    $text = (string) preg_replace('/%%[a-z_]+%%/i', '', $text);
    $text = (string) preg_replace('/%[a-z_]+%/i', '', $text);
    $text = (string) preg_replace('/\s{2,}/u', ' ', $text);
    $text = trim($text, " -|·\t\n\r");
    return trim($text);
}

$peSlug = [];
foreach ($seoMeta as $postId => $seo) {
    if (!isset($produse[$postId])) {
        continue;
    }
    $slug = $produse[$postId]['slug'];
    $titluProdus = (string) ($produse[$postId]['titlu'] ?? '');
    $titlu = desfaSablon((string) ($seo['titlu'] ?? ''), $titluProdus, $numeSite);
    $descriere = desfaSablon((string) ($seo['descriere'] ?? ''), $titluProdus, $numeSite);
    if ($titlu !== '') {
        $peSlug[$slug]['seo_titlu'] = mb_substr($titlu, 0, 255);
    }
    if ($descriere !== '') {
        $peSlug[$slug]['seo_descriere'] = mb_substr($descriere, 0, 500);
    }
}
foreach ($legaturi as $postId => $ttIds) {
    if (!isset($produse[$postId])) {
        continue;
    }
    $slug = $produse[$postId]['slug'];
    foreach ($ttIds as $ttId) {
        $tax = $taxonomii[$ttId] ?? null;
        if ($tax === null) {
            continue;
        }
        $termen = $termeni[$tax['term_id']] ?? null;
        if ($termen === null || trim($termen['nume']) === '') {
            continue;
        }
        $nume = trim($termen['nume']);
        if ($tax['taxonomie'] === 'product_tag') {
            $peSlug[$slug]['taguri'][$nume] = $nume;
        } elseif (in_array($tax['taxonomie'], $taxonomiiBrand, true)) {
            // Dacă sunt mai multe, rămâne prima — un produs are un producător.
            $peSlug[$slug]['brand'] ??= $nume;
        }
    }
}

// ── Potrivirea cu magazinul de acum ────────────────────────────
$stmt = $db->query('SELECT id, slug, name, brand, tags_json FROM products WHERE deleted_at IS NULL');
$magazin = [];
foreach ($stmt->fetchAll() ?: [] as $rand) {
    $magazin[(string) $rand['slug']] = $rand;
}

// SEO-ul deja pus în magazin, ca să nu-l călcăm.
$seoExistent = [];
try {
    foreach ($db->query("SELECT page_ref, title, description FROM seo_pages WHERE page_type = 'product'")->fetchAll() ?: [] as $rand) {
        $seoExistent[(int) $rand['page_ref']] = [
            'titlu' => trim((string) ($rand['title'] ?? '')),
            'descriere' => trim((string) ($rand['description'] ?? '')),
        ];
    }
} catch (Throwable) {
}

$deScris = [];
$negasite = [];
$sarite = 0;
foreach ($peSlug as $slug => $date) {
    $produs = $magazin[$slug] ?? null;
    if (!is_array($produs)) {
        $negasite[] = $slug;
        continue;
    }
    $brandNou = trim((string) ($date['brand'] ?? ''));
    $taguriNoi = array_values($date['taguri'] ?? []);
    $seoTitluNou = trim((string) ($date['seo_titlu'] ?? ''));
    $seoDescriereNoua = trim((string) ($date['seo_descriere'] ?? ''));
    $idProdus = (int) $produs['id'];
    $areBrand = trim((string) ($produs['brand'] ?? '')) !== '';
    $areTaguri = trim((string) ($produs['tags_json'] ?? '')) !== '';
    $areSeoTitlu = ($seoExistent[$idProdus]['titlu'] ?? '') !== '';
    $areSeoDescriere = ($seoExistent[$idProdus]['descriere'] ?? '') !== '';

    if (!$suprascrie) {
        if ($areBrand) {
            $brandNou = '';
        }
        if ($areTaguri) {
            $taguriNoi = [];
        }
        if ($areSeoTitlu) {
            $seoTitluNou = '';
        }
        if ($areSeoDescriere) {
            $seoDescriereNoua = '';
        }
    }
    if ($brandNou === '' && $taguriNoi === [] && $seoTitluNou === '' && $seoDescriereNoua === '') {
        $sarite++;
        continue;
    }
    $deScris[] = [
        'id' => $idProdus,
        'slug' => $slug,
        'nume' => (string) $produs['name'],
        'brand' => $brandNou,
        'taguri' => $taguriNoi,
        'seo_titlu' => $seoTitluNou,
        'seo_descriere' => $seoDescriereNoua,
    ];
}

// CSV de verificat, întotdeauna.
$csv = fopen($caleCsv, 'w');
if ($csv !== false) {
    fwrite($csv, "\xEF\xBB\xBF");
    fputcsv($csv, ['slug', 'produs', 'brand', 'etichete', 'seo_titlu', 'seo_descriere'], ',');
    foreach ($deScris as $r) {
        fputcsv($csv, [
            $r['slug'],
            $r['nume'],
            $r['brand'],
            implode(', ', $r['taguri']),
            $r['seo_titlu'],
            $r['seo_descriere'],
        ], ',');
    }
    fclose($csv);
}

printf("Produse în backup: %d\n", count($produse));
printf("Produse cu brand sau etichete: %d\n", count($peSlug));
printf("%s: %d\n", $aplica ? 'Produse actualizate' : 'Produse de actualizat', count($deScris));
if ($sarite > 0) {
    printf("Sărite (au deja brand/etichete; folosește --suprascrie ca să le înlocuiești): %d\n", $sarite);
}
if ($negasite !== []) {
    printf("Nu mai există în magazin (%d): %s\n", count($negasite), implode(', ', array_slice($negasite, 0, 15)));
}
printf("Fișier de verificare: %s\n", $caleCsv);

if (!$aplica) {
    echo "\nRaport. Nu s-a scris nimic în magazin. Verifică fișierul CSV, apoi adaugă --aplica.\n";
    exit(0);
}

$scrieBrand = $db->prepare('UPDATE products SET brand = :brand WHERE id = :id');
$scrieTaguri = $db->prepare('UPDATE products SET tags_json = :taguri WHERE id = :id');
// SEO-ul stă în `seo_pages`, cu o linie pe produs. Se scriu doar câmpurile
// aduse: un titlu nou nu trebuie să șteargă descrierea pusă între timp.
$scrieSeo = $db->prepare(
    "INSERT INTO seo_pages (page_type, page_ref, title, description)
     VALUES ('product', :ref, :titlu, :descriere)
     ON DUPLICATE KEY UPDATE
        title = COALESCE(VALUES(title), title),
        description = COALESCE(VALUES(description), description)"
);
$scrise = 0;
foreach ($deScris as $r) {
    if ($r['brand'] !== '') {
        $scrieBrand->execute(['brand' => mb_substr($r['brand'], 0, 120), 'id' => $r['id']]);
    }
    if ($r['taguri'] !== []) {
        $scrieTaguri->execute([
            'taguri' => json_encode(array_values($r['taguri']), JSON_UNESCAPED_UNICODE),
            'id' => $r['id'],
        ]);
    }
    if ($r['seo_titlu'] !== '' || $r['seo_descriere'] !== '') {
        $scrieSeo->execute([
            'ref' => (string) $r['id'],
            'titlu' => $r['seo_titlu'] !== '' ? $r['seo_titlu'] : null,
            'descriere' => $r['seo_descriere'] !== '' ? $r['seo_descriere'] : null,
        ]);
    }
    $scrise++;
}
printf("\n✓ Scrise: %d produse.\n", $scrise);
