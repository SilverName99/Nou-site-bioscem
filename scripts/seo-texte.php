<?php

declare(strict_types=1);

/**
 * Textele Google scrise pentru livrarea 2 SEO: titlul și descrierea pentru
 * prima pagină, cele 3 categorii și cele 20 de produse din planul SEO (§3.1),
 * plus numele magazinului din titluri („Bioscem”, ca titlul primei pagini).
 * Textele sunt în scripts/seo-texte.json (conținut public al site-ului).
 *
 * Unde ajung (aceleași câmpuri pe care le scrie adminul):
 *  - prima pagină: Setări magazin → SEO Google (store_seo_home_title /
 *    store_seo_home_description), care au întâietate pe prima pagină;
 *  - numele magazinului: Setări magazin → SEO Google → Nume magazin
 *    (store_name). Apare la finalul titlurilor automate („Produs | Bioscem”), în
 *    og:site_name și în datele structurate (WebSite, Organization), ca Google să
 *    vadă peste tot același nume ca în titlul primei pagini;
 *  - categoriile: câmpurile SEO ale categoriei (seo_pages, tip „category”);
 *  - produsele: câmpurile SEO ale produsului (seo_pages, tip „product”).
 * Nu se schimbă nimic altceva: numele produselor, codurile, adresele, prețurile
 * și pozele rămân cum sunt.
 *
 * Rulare, prin SSH, din folderul site-ului:
 *   php scripts/seo-texte.php --plan          arată „acum → nou”; nu scrie nimic
 *   php scripts/seo-texte.php --aplica        scrie; înainte salvează valorile de acum
 *                                             în storage/seo-texte-copie-<data>.json
 *   php scripts/seo-texte.php --restaureaza   pune înapoi valorile din cea mai nouă copie
 *        [--fisier=storage/seo-texte-copie-….json] [--forteaza]
 *
 * Siguranță:
 *  - refuză să scrie ceva dacă baza nu e cea așteptată: lipsesc tabelele, o
 *    categorie sau un produs din listă, ori un titlu n-ar apărea în Google exact
 *    cum e scris (regula titlurilor din SeoMeta l-ar reface) — verificat cu
 *    numele magazinului de acum, cu cel nou și cu cel implicit, ca o schimbare
 *    ulterioară a numelui din admin să nu refacă pe ascuns un titlu scris;
 *  - se poate rula de oricâte ori: a doua oară nu mai are ce schimba și nu mai
 *    face o copie nouă;
 *  - restaurarea nu calcă peste ce s-a modificat de mână între timp (doar cu
 *    --forteaza);
 *  - scrie totul într-o singură tranzacție (totul sau nimic), apoi golește
 *    cache-ul de pagini. CDN-ul (hPanel) se golește de mână.
 *  - afișează doar texte de pe site, nicio dată personală.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\ResponseCache;
use App\Support\SeoMeta;
use App\Support\Settings;

const FISIER_DATE = __DIR__ . '/seo-texte.json';
const DOSAR_COPII = __DIR__ . '/../storage';
const PREFIX_COPIE = 'seo-texte-copie-';

/** Câte texte trebuie să fie în fișier (prima pagină, 3 categorii, 20 de produse). */
const ASTEPTAT = ['home' => 1, 'categorie' => 3, 'produs' => 20];

/** Numele magazinului din titluri, același ca titlul primei pagini (decizia din 20 oct). */
const NUME_MAGAZIN = 'Bioscem';

function iesire(string $mesaj, int $cod): never
{
    fwrite($cod === 0 ? STDOUT : STDERR, $mesaj . "\n");
    exit($cod);
}

function scrie(string $linie = ''): void
{
    fwrite(STDOUT, $linie . "\n");
}

/** „«text»” sau „(gol)”, pentru afișare. */
function arata(?string $text): string
{
    return ($text === null || trim($text) === '') ? '(gol)' : '«' . $text . '»';
}

// ─────────────────────────────────────────────────────────────
// Argumente
// ─────────────────────────────────────────────────────────────

$argumente = array_slice($argv, 1);
$mod = null;
$fisierCopie = null;
$forteaza = false;
foreach ($argumente as $arg) {
    if (in_array($arg, ['--plan', '--aplica', '--restaureaza'], true)) {
        if ($mod !== null) {
            iesire('Alege un singur mod: --plan, --aplica sau --restaureaza.', 2);
        }
        $mod = substr($arg, 2);
    } elseif (str_starts_with($arg, '--fisier=')) {
        $fisierCopie = substr($arg, strlen('--fisier='));
    } elseif ($arg === '--forteaza') {
        $forteaza = true;
    } else {
        iesire("Argument necunoscut: {$arg}\nFolosire: php scripts/seo-texte.php --plan | --aplica | --restaureaza [--fisier=…] [--forteaza]", 2);
    }
}
if ($mod === null) {
    iesire("Folosire: php scripts/seo-texte.php --plan | --aplica | --restaureaza [--fisier=…] [--forteaza]\n--plan doar arată ce s-ar schimba.", 2);
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));
if (!$db instanceof PDO) {
    iesire('Nu am putut deschide conexiunea la baza de date (vezi .env).', 1);
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ─────────────────────────────────────────────────────────────
// Baza e cea așteptată?
// ─────────────────────────────────────────────────────────────

/** Coloanele unui tabel (litere mici), sau [] dacă tabelul lipsește. */
function coloane(PDO $db, string $tabel): array
{
    try {
        $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '', $tabel) . '`');
        return array_map(static fn (array $r): string => strtolower((string) $r['Field']), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable) {
        return [];
    }
}

$probleme = [];
foreach ([
    'settings' => ['key', 'value'],
    'products' => ['id', 'slug', 'name', 'brand', 'deleted_at'],
    'product_categories' => ['id', 'slug', 'name'],
    'seo_pages' => ['page_type', 'page_ref', 'title', 'description', 'canonical_url', 'image_url'],
] as $tabel => $necesare) {
    $are = coloane($db, $tabel);
    if ($are === []) {
        $probleme[] = "lipsește tabelul {$tabel}";
        continue;
    }
    foreach ($necesare as $coloana) {
        if (!in_array($coloana, $are, true)) {
            $probleme[] = "tabelul {$tabel} n-are coloana {$coloana}";
        }
    }
}
if ($probleme !== []) {
    iesire("Refuz: baza de date nu e cea așteptată (" . implode('; ', $probleme) . "). Nu am scris nimic.", 3);
}

// ─────────────────────────────────────────────────────────────
// Restaurarea (nu are nevoie de fișierul cu texte)
// ─────────────────────────────────────────────────────────────

/** Valoarea de acum a unei intrări: ['exista' => bool, 'titlu' => ?string, 'descriere' => ?string, 'rand' => array|null]. */
function valoareaDeAcum(PDO $db, array $cheie): array
{
    if (($cheie['tip'] ?? '') === 'setare') {
        // O singură setare (numele magazinului): valoarea ei e „titlul”.
        $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$cheie['cheie']]);
        $valoare = $stmt->fetchColumn();
        return [
            'exista' => $valoare !== false,
            'titlu' => $valoare === false ? null : (string) $valoare,
            'descriere' => null,
        ];
    }
    if (($cheie['tip'] ?? '') === 'setari') {
        $stmt = $db->prepare('SELECT `key`, `value` FROM settings WHERE `key` IN (?, ?)');
        $stmt->execute([$cheie['titlu'], $cheie['descriere']]);
        $randuri = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $randuri[(string) $r['key']] = (string) $r['value'];
        }
        return [
            'exista_titlu' => array_key_exists($cheie['titlu'], $randuri),
            'exista_descriere' => array_key_exists($cheie['descriere'], $randuri),
            'titlu' => $randuri[$cheie['titlu']] ?? null,
            'descriere' => $randuri[$cheie['descriere']] ?? null,
        ];
    }
    $stmt = $db->prepare('SELECT title, description, canonical_url, image_url FROM seo_pages WHERE page_type = ? AND page_ref = ? LIMIT 1');
    $stmt->execute([$cheie['page_type'], $cheie['page_ref']]);
    $rand = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return [
        'exista' => is_array($rand),
        'titlu' => is_array($rand) ? ($rand['title'] === null ? null : (string) $rand['title']) : null,
        'descriere' => is_array($rand) ? ($rand['description'] === null ? null : (string) $rand['description']) : null,
        'canonical_url' => is_array($rand) ? (string) ($rand['canonical_url'] ?? '') : '',
        'image_url' => is_array($rand) ? (string) ($rand['image_url'] ?? '') : '',
    ];
}

function golesteCache(): void
{
    try {
        $n = ResponseCache::purgePageCache();
        scrie("Cache-ul de pagini a fost golit ({$n} pagini). Goliți și CDN-ul din hPanel („Purge all”).");
    } catch (Throwable) {
        scrie('Atenție: nu am putut goli cache-ul de pagini. Goliți-l din Admin → Setări magazin → Caching, apoi CDN-ul din hPanel.');
    }
}

if ($mod === 'restaureaza') {
    if ($fisierCopie === null) {
        $copii = glob(DOSAR_COPII . '/' . PREFIX_COPIE . '*.json') ?: [];
        sort($copii);
        $fisierCopie = $copii === [] ? null : end($copii);
        if ($fisierCopie === null) {
            iesire('Nu există nicio copie ' . PREFIX_COPIE . '*.json în storage/. Nu am ce restaura.', 1);
        }
    }
    $copie = json_decode((string) @file_get_contents($fisierCopie), true);
    if (!is_array($copie) || ($copie['facut_de'] ?? '') !== 'scripts/seo-texte.php' || !is_array($copie['intrari'] ?? null)) {
        iesire("Refuz: {$fisierCopie} nu e o copie făcută de acest script.", 3);
    }
    $bazaActuala = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    if ((string) ($copie['baza'] ?? '') !== $bazaActuala) {
        iesire("Refuz: copia e din baza „{$copie['baza']}”, iar acum sunt conectat la „{$bazaActuala}”.", 3);
    }
    scrie('Restaurez din ' . basename($fisierCopie) . ' (făcută la ' . ($copie['facut_la'] ?? '?') . ').');
    $operatii = [];
    $sarite = 0;
    foreach ($copie['intrari'] as $intrare) {
        $cheie = (array) ($intrare['cheie'] ?? []);
        $acum = valoareaDeAcum($db, $cheie);
        $eticheta = (string) ($intrare['eticheta'] ?? '?');
        $scris = [(string) ($intrare['titlu_scris'] ?? ''), (string) ($intrare['descriere_scrisa'] ?? '')];
        $vechi = [$intrare['titlu_vechi'] ?? null, $intrare['descriere_veche'] ?? null];
        if ([$acum['titlu'], $acum['descriere']] === $vechi) {
            scrie("  {$eticheta}: deja ca înainte");
            continue;
        }
        if ([(string) $acum['titlu'], (string) $acum['descriere']] !== $scris && !$forteaza) {
            scrie("  {$eticheta}: SĂRIT — a fost modificat după aplicare (acum " . arata($acum['titlu']) . '); folosiți --forteaza ca să-l suprascrieți');
            $sarite++;
            continue;
        }
        $operatii[] = [$intrare, $acum];
        if (($cheie['tip'] ?? '') === 'setare') {
            scrie("  {$eticheta}: " . arata($acum['titlu']) . ' → ' . arata($vechi[0]));
            continue;
        }
        scrie("  {$eticheta}: titlu " . arata($acum['titlu']) . ' → ' . arata($vechi[0]) . '; descriere ' . arata($acum['descriere']) . ' → ' . arata($vechi[1]));
    }
    if ($operatii === []) {
        iesire($sarite > 0 ? "Nimic restaurat ({$sarite} sărite)." : 'Nimic de restaurat: valorile sunt deja cele dinainte.', 0);
    }
    $db->beginTransaction();
    try {
        foreach ($operatii as [$intrare, $acum]) {
            $cheie = (array) $intrare['cheie'];
            if (($cheie['tip'] ?? '') === 'setare') {
                $valoare = $intrare['titlu_vechi'] ?? null;
                if ($valoare === null) {
                    $db->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$cheie['cheie']]);
                } else {
                    $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
                        ->execute([$cheie['cheie'], (string) $valoare]);
                }
                continue;
            }
            if (($cheie['tip'] ?? '') === 'setari') {
                foreach (['titlu' => 'titlu_vechi', 'descriere' => 'descriere_veche'] as $camp => $campVechi) {
                    $valoare = $intrare[$campVechi] ?? null;
                    if ($valoare === null) {
                        $db->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$cheie[$camp]]);
                    } else {
                        $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
                            ->execute([$cheie[$camp], (string) $valoare]);
                    }
                }
                continue;
            }
            if (!($intrare['exista_inainte'] ?? false) && $acum['canonical_url'] === '' && $acum['image_url'] === '') {
                // Rândul l-a creat --aplica și n-a primit altceva între timp: îl scoatem.
                $db->prepare('DELETE FROM seo_pages WHERE page_type = ? AND page_ref = ?')->execute([$cheie['page_type'], $cheie['page_ref']]);
            } else {
                $db->prepare('UPDATE seo_pages SET title = ?, description = ? WHERE page_type = ? AND page_ref = ?')
                    ->execute([$intrare['titlu_vechi'] ?? null, $intrare['descriere_veche'] ?? null, $cheie['page_type'], $cheie['page_ref']]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        iesire('Eroare la restaurare; nu s-a schimbat nimic: ' . $e->getMessage(), 1);
    }
    scrie('Restaurat: ' . count($operatii) . ($sarite > 0 ? ", sărite: {$sarite}" : '') . '.');
    golesteCache();
    exit(0);
}

// ─────────────────────────────────────────────────────────────
// Fișierul cu texte
// ─────────────────────────────────────────────────────────────

$brut = @file_get_contents(FISIER_DATE);
$texte = is_string($brut) ? json_decode($brut, true) : null;
if (!is_array($texte)) {
    iesire('Refuz: nu pot citi scripts/seo-texte.json.', 3);
}
$numar = ['home' => 0, 'categorie' => 0, 'produs' => 0];
$vazute = [];
foreach ($texte as $i => $t) {
    $tip = (string) ($t['tip'] ?? '');
    $slug = (string) ($t['slug'] ?? '');
    $titlu = (string) ($t['titlu_nou'] ?? '');
    $descriere = (string) ($t['descriere_noua'] ?? '');
    if (!array_key_exists($tip, $numar) || ($tip !== 'home' && $slug === '') || trim($titlu) === '' || trim($descriere) === '') {
        iesire("Refuz: rândul {$i} din scripts/seo-texte.json e incomplet.", 3);
    }
    if (isset($vazute[$tip . '|' . $slug])) {
        iesire("Refuz: {$tip} {$slug} apare de două ori în scripts/seo-texte.json.", 3);
    }
    $vazute[$tip . '|' . $slug] = true;
    // Plafoane largi, doar împotriva unui fișier stricat (textele au ≤ 60 / ≤ 155).
    if (mb_strlen($titlu) > 70 || mb_strlen($descriere) > 200 || $titlu !== trim($titlu) || $descriere !== trim($descriere)) {
        iesire("Refuz: textul pentru {$tip} {$slug} e prea lung sau are spații la capete.", 3);
    }
    // Diacriticele corecte (ș, ț cu virgulă), nu cele cu sedilă.
    if (preg_match('/[\x{015E}\x{015F}\x{0162}\x{0163}]/u', $titlu . $descriere) === 1) {
        iesire("Refuz: textul pentru {$tip} {$slug} are ş/ţ cu sedilă.", 3);
    }
    $numar[$tip]++;
}
if ($numar !== ASTEPTAT) {
    iesire('Refuz: scripts/seo-texte.json trebuie să aibă 1 prima pagină, 3 categorii și 20 de produse.', 3);
}

// ─────────────────────────────────────────────────────────────
// Ce s-ar schimba
// ─────────────────────────────────────────────────────────────

$setari = Settings::all($db);
$magazinAcum = SeoMeta::numeMagazin($setari);
// Un titlu scris trebuie să rămână exact cum e cu oricare dintre aceste nume
// (acum, după aplicare, sau dacă cineva golește câmpul din admin).
$numeVerificate = array_values(array_unique([$magazinAcum, NUME_MAGAZIN, SeoMeta::NUME_MAGAZIN_IMPLICIT]));
$intrari = [];
$probleme = [];

$cheieMagazin = ['tip' => 'setare', 'cheie' => 'store_name'];
$acumMagazin = valoareaDeAcum($db, $cheieMagazin);
$intrari[] = [
    'eticheta' => 'numele magazinului din titluri',
    'cheie' => $cheieMagazin,
    'acum' => $acumMagazin,
    'titlu' => NUME_MAGAZIN,
    'descriere' => '',
    'schimba' => (string) $acumMagazin['titlu'] !== NUME_MAGAZIN,
];

foreach ($texte as $t) {
    $tip = (string) $t['tip'];
    $slug = (string) $t['slug'];
    $titlu = (string) $t['titlu_nou'];
    $descriere = (string) $t['descriere_noua'];
    if ($tip === 'home') {
        $cheie = ['tip' => 'setari', 'titlu' => 'store_seo_home_title', 'descriere' => 'store_seo_home_description'];
        $eticheta = 'prima pagină';
        foreach ($numeVerificate as $magazin) {
            if (SeoMeta::titluPagina($titlu, '', $magazin) !== $titlu) {
                $probleme[] = "titlul primei pagini s-ar afișa altfel decât e scris (magazin „{$magazin}”)";
            }
        }
    } elseif ($tip === 'categorie') {
        $stmt = $db->prepare('SELECT id, name FROM product_categories WHERE slug = ?');
        $stmt->execute([$slug]);
        $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($randuri) !== 1) {
            $probleme[] = 'categoria ' . $slug . (count($randuri) === 0 ? ' nu există' : ' apare de mai multe ori');
            continue;
        }
        $cheie = ['tip' => 'seo_pages', 'page_type' => 'category', 'page_ref' => (string) (int) $randuri[0]['id']];
        $eticheta = 'categoria ' . $slug;
        foreach ($numeVerificate as $magazin) {
            if (SeoMeta::titluPagina($titlu, (string) $randuri[0]['name'], $magazin) !== $titlu) {
                $probleme[] = "titlul categoriei {$slug} s-ar afișa altfel decât e scris (magazin „{$magazin}”)";
            }
        }
    } else {
        $stmt = $db->prepare('SELECT id, name, brand FROM products WHERE slug = ? AND deleted_at IS NULL');
        $stmt->execute([$slug]);
        $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($randuri) !== 1) {
            $probleme[] = 'produsul ' . $slug . (count($randuri) === 0 ? ' nu există' : ' apare de mai multe ori');
            continue;
        }
        $cheie = ['tip' => 'seo_pages', 'page_type' => 'product', 'page_ref' => (string) (int) $randuri[0]['id']];
        $eticheta = 'produsul ' . $slug;
        // Regula titlurilor reface un titlu care doar repetă numele și marca
        // (și numele magazinului): textul scris trebuie să rămână exact cum e,
        // cu oricare dintre numele magazinului.
        foreach ($numeVerificate as $magazin) {
            $afisat = SeoMeta::titluProdusDetaliat((string) $randuri[0]['name'], (string) ($randuri[0]['brand'] ?? ''), $titlu, $magazin);
            if ($afisat['sursa'] !== 'scris' || $afisat['titlu'] !== $titlu) {
                $probleme[] = "titlul produsului {$slug} s-ar afișa „{$afisat['titlu']}”, nu cum e scris (magazin „{$magazin}”)";
            }
        }
    }
    $acum = valoareaDeAcum($db, $cheie);
    $intrari[] = [
        'eticheta' => $eticheta,
        'cheie' => $cheie,
        'acum' => $acum,
        'titlu' => $titlu,
        'descriere' => $descriere,
        'schimba' => (string) $acum['titlu'] !== $titlu || (string) $acum['descriere'] !== $descriere,
    ];
}
if ($probleme !== []) {
    iesire("Refuz: baza de date nu e cea așteptată sau un text n-ar apărea cum e scris:\n  - " . implode("\n  - ", $probleme) . "\nNu am scris nimic.", 3);
}

$deSchimbat = array_values(array_filter($intrari, static fn (array $x): bool => $x['schimba']));
scrie(($mod === 'plan' ? 'PLAN (nu scriu nimic)' : 'APLIC') . ': ' . count($intrari) . ' valori (numele magazinului + ' . (count($intrari) - 1) . ' texte), ' . count($deSchimbat) . ' de schimbat.');
foreach ($intrari as $x) {
    if (!$x['schimba']) {
        scrie("  {$x['eticheta']}: neschimbat (deja aplicat)");
        continue;
    }
    if ($x['cheie']['tip'] === 'setare') {
        scrie("  {$x['eticheta']}: " . arata($x['acum']['titlu']) . ($x['acum']['titlu'] === null || trim((string) $x['acum']['titlu']) === '' ? ' (adică „' . SeoMeta::NUME_MAGAZIN_IMPLICIT . '”)' : '') . ' → ' . arata($x['titlu']));
        continue;
    }
    scrie("  {$x['eticheta']}:");
    scrie('      titlu:     ' . arata($x['acum']['titlu']) . ' → ' . arata($x['titlu']));
    scrie('      descriere: ' . arata($x['acum']['descriere']) . ' → ' . arata($x['descriere']));
}
if ($mod === 'plan') {
    exit(0);
}
if ($deSchimbat === []) {
    iesire('Nimic de schimbat: textele sunt deja aplicate. Nu am făcut o copie nouă.', 0);
}

// ─────────────────────────────────────────────────────────────
// Aplicarea: întâi copia, apoi totul într-o tranzacție
// ─────────────────────────────────────────────────────────────

$copie = [
    'facut_de' => 'scripts/seo-texte.php',
    'facut_la' => date('Y-m-d H:i:s'),
    'baza' => (string) $db->query('SELECT DATABASE()')->fetchColumn(),
    'fisier_date_sha1' => sha1((string) $brut),
    'intrari' => array_map(static function (array $x): array {
        $intrare = [
            'eticheta' => $x['eticheta'],
            'cheie' => $x['cheie'],
            'titlu_vechi' => $x['acum']['titlu'],
            'descriere_veche' => $x['acum']['descriere'],
            'titlu_scris' => $x['titlu'],
            'descriere_scrisa' => $x['descriere'],
        ];
        if (in_array($x['cheie']['tip'] ?? '', ['seo_pages', 'setare'], true)) {
            $intrare['exista_inainte'] = (bool) $x['acum']['exista'];
        }
        return $intrare;
    }, $deSchimbat),
];
if (!is_dir(DOSAR_COPII)) {
    @mkdir(DOSAR_COPII, 0775, true);
}
$fisier = DOSAR_COPII . '/' . PREFIX_COPIE . date('Ymd-His') . '.json';
for ($n = 2; is_file($fisier); $n++) {
    $fisier = DOSAR_COPII . '/' . PREFIX_COPIE . date('Ymd-His') . '-' . $n . '.json';
}
$json = json_encode($copie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || @file_put_contents($fisier, $json . "\n") !== strlen($json) + 1) {
    iesire("Nu am putut scrie copia {$fisier}. Nu am schimbat nimic.", 1);
}
scrie('Copia valorilor de acum: storage/' . basename($fisier));

$db->beginTransaction();
try {
    $setare = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $pagina = $db->prepare(
        'INSERT INTO seo_pages (page_type, page_ref, title, description) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)'
    );
    foreach ($deSchimbat as $x) {
        $cheie = $x['cheie'];
        if ($cheie['tip'] === 'setare') {
            $setare->execute([$cheie['cheie'], $x['titlu']]);
        } elseif ($cheie['tip'] === 'setari') {
            $setare->execute([$cheie['titlu'], $x['titlu']]);
            $setare->execute([$cheie['descriere'], $x['descriere']]);
        } else {
            $pagina->execute([$cheie['page_type'], $cheie['page_ref'], $x['titlu'], $x['descriere']]);
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    iesire('Eroare la scriere; nu s-a schimbat nimic (copia rămâne, nu strică): ' . $e->getMessage(), 1);
}
scrie('Aplicat: ' . count($deSchimbat) . ' valori. Revenire: php scripts/seo-texte.php --restaureaza');
golesteCache();
