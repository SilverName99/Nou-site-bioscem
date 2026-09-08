<?php

declare(strict_types=1);

/**
 * Completează titlul și descrierea SEO la produsele care n-au.
 *
 * Nu ține loc de un text scris de om — un titlu bun spune și de ce să cumperi,
 * nu doar ce e produsul. Dar un produs fără nimic la SEO apare în Google cu ce
 * apucă motorul să taie din pagină, iar o descriere tăiată la mijlocul unei
 * fraze arată mai rău decât una scurtă și corectă. Asta umple golul, iar omul
 * de marketing rescrie apoi doar ce merită rescris.
 *
 * Titlul: denumirea produsului, plus marca dacă nu e deja în denumire, tăiat la
 * 60 de caractere — cât arată Google.
 * Descrierea: descrierea scurtă a produsului (sau primele fraze din cea lungă),
 * curățată de HTML și tăiată la 155 de caractere, la sfârșit de cuvânt.
 *
 * Rulare:
 *   php scripts/genereaza-seo-produse.php
 *   php scripts/genereaza-seo-produse.php --aplica
 *
 * Fără `--aplica` doar arată ce ar scrie. Produsele care au deja titlu sau
 * descriere nu se ating: se completează doar ce lipsește.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));
if (!$db instanceof PDO) {
    fwrite(STDERR, "Nu am putut deschide conexiunea la baza de date.\n");
    exit(1);
}

$aplica = in_array('--aplica', $argv, true);

/** Text curat dintr-un câmp cu HTML. */
function textCurat(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

/** Taie la sfârșit de cuvânt, nu la mijlocul lui. */
function taieLaCuvant(string $text, int $maxim): string
{
    if (mb_strlen($text) <= $maxim) {
        return $text;
    }
    $bucata = mb_substr($text, 0, $maxim);
    $ultimSpatiu = mb_strrpos($bucata, ' ');
    if ($ultimSpatiu !== false && $ultimSpatiu > $maxim * 0.6) {
        $bucata = mb_substr($bucata, 0, $ultimSpatiu);
    }
    return rtrim($bucata, " ,;:-–—") ;
}

$produse = $db->query(
    'SELECT id, name, brand, short_description, description
     FROM products
     WHERE deleted_at IS NULL AND is_active = 1
     ORDER BY name ASC'
)->fetchAll() ?: [];

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
$complete = 0;
foreach ($produse as $p) {
    $id = (int) $p['id'];
    $nume = trim((string) ($p['name'] ?? ''));
    if ($nume === '') {
        continue;
    }
    $areTitlu = ($seoExistent[$id]['titlu'] ?? '') !== '';
    $areDescriere = ($seoExistent[$id]['descriere'] ?? '') !== '';
    if ($areTitlu && $areDescriere) {
        $complete++;
        continue;
    }

    $titlu = '';
    if (!$areTitlu) {
        $marca = trim((string) ($p['brand'] ?? ''));
        // Marca se adaugă doar dacă lipsește din denumire — „Spaghete
        // Farabella | Farabella" nu ajută pe nimeni.
        $titlu = ($marca !== '' && mb_stripos($nume, $marca) === false)
            ? $nume . ' | ' . $marca
            : $nume;
        $titlu = taieLaCuvant($titlu, 60);
    }

    $descriere = '';
    if (!$areDescriere) {
        $sursa = textCurat((string) ($p['short_description'] ?? ''));
        if (mb_strlen($sursa) < 50) {
            $lunga = textCurat((string) ($p['description'] ?? ''));
            if (mb_strlen($lunga) > mb_strlen($sursa)) {
                $sursa = $lunga;
            }
        }
        if ($sursa !== '') {
            $descriere = taieLaCuvant($sursa, 155);
        }
    }

    if ($titlu === '' && $descriere === '') {
        continue;
    }
    $deScris[] = [
        'id' => $id,
        'nume' => $nume,
        'titlu' => $titlu,
        'descriere' => $descriere,
    ];
}

printf("Produse active: %d\n", count($produse));
printf("Aveau deja titlu și descriere: %d\n", $complete);
printf("%s: %d\n", $aplica ? 'Completate' : 'De completat', count($deScris));

foreach (array_slice($deScris, 0, 5) as $r) {
    printf("\n  %s\n", $r['nume']);
    if ($r['titlu'] !== '') {
        printf("    titlu:     %s\n", $r['titlu']);
    }
    if ($r['descriere'] !== '') {
        printf("    descriere: %s\n", $r['descriere']);
    }
}
if (count($deScris) > 5) {
    printf("\n  … și încă %d\n", count($deScris) - 5);
}

if (!$aplica) {
    echo "\nRaport. Nu s-a scris nimic. Adaugă --aplica pentru a completa.\n";
    exit(0);
}

$scrie = $db->prepare(
    "INSERT INTO seo_pages (page_type, page_ref, title, description)
     VALUES ('product', :ref, :titlu, :descriere)
     ON DUPLICATE KEY UPDATE
        title = COALESCE(VALUES(title), title),
        description = COALESCE(VALUES(description), description)"
);
foreach ($deScris as $r) {
    $scrie->execute([
        'ref' => (string) $r['id'],
        'titlu' => $r['titlu'] !== '' ? $r['titlu'] : null,
        'descriere' => $r['descriere'] !== '' ? $r['descriere'] : null,
    ]);
}
printf("\n✓ Completate: %d produse.\n", count($deScris));
