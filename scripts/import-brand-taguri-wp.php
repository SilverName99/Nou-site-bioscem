<?php

declare(strict_types=1);

/**
 * Scoate mărcile și etichetele produselor din backup-ul site-ului WordPress și
 * le pune pe produsele magazinului de acum.
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
 * Ce se scrie: `brand` și `tags_json` pe produs. Un produs care are deja brand
 * sau etichete nu se atinge, decât cu `--suprascrie`.
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
if ($fisier === '' || !is_file($fisier)) {
    fwrite(STDERR, "Dă calea către fișierul de bază de date din backup:\n  php scripts/import-brand-taguri-wp.php backup_..._-db.gz [--aplica]\n");
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

/** Prefixul tabelelor din dump (wp_, wpxx_ etc.), aflat din prima potrivire. */
$prefix = null;
$produse = [];      // post_id → ['slug' => ..., 'titlu' => ...]
$termeni = [];      // term_id → ['nume' => ..., 'slug' => ...]
$taxonomii = [];    // term_taxonomy_id → ['term_id' => ..., 'taxonomie' => ...]
$legaturi = [];     // post_id → [term_taxonomy_id, ...]

$handle = deschide($fisier);
$linii = 0;
while (($linie = gzgets($handle, 1024 * 1024 * 8)) !== false) {
    $linii++;
    if (strncmp($linie, 'INSERT INTO', 11) !== 0) {
        continue;
    }
    if (preg_match('/^INSERT INTO `([a-z0-9_]*?)(posts|terms|term_taxonomy|term_relationships)` /i', $linie, $m) !== 1) {
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

$peSlug = [];
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
    $areBrand = trim((string) ($produs['brand'] ?? '')) !== '';
    $areTaguri = trim((string) ($produs['tags_json'] ?? '')) !== '';

    if (!$suprascrie) {
        if ($areBrand) {
            $brandNou = '';
        }
        if ($areTaguri) {
            $taguriNoi = [];
        }
    }
    if ($brandNou === '' && $taguriNoi === []) {
        $sarite++;
        continue;
    }
    $deScris[] = [
        'id' => (int) $produs['id'],
        'slug' => $slug,
        'nume' => (string) $produs['name'],
        'brand' => $brandNou,
        'taguri' => $taguriNoi,
    ];
}

// CSV de verificat, întotdeauna.
$csv = fopen($caleCsv, 'w');
if ($csv !== false) {
    fwrite($csv, "\xEF\xBB\xBF");
    fputcsv($csv, ['slug', 'produs', 'brand', 'etichete'], ',');
    foreach ($deScris as $r) {
        fputcsv($csv, [$r['slug'], $r['nume'], $r['brand'], implode(', ', $r['taguri'])], ',');
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
    $scrise++;
}
printf("\n✓ Scrise: %d produse.\n", $scrise);
