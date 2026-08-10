<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\ProductSections;

/**
 * Analizează descrierile tuturor produselor și raportează ce secțiuni
 * („Caracteristici”, „Mod de utilizare”, „Ingrediente” etc.) apar și la
 * câte produse, ca să știm ce câmpuri suplimentare merită create.
 *
 * NU modifică nimic în baza de date.
 *
 * Utilizare:
 *   php scripts/analyze-product-sections.php
 *   php scripts/analyze-product-sections.php --csv=sectiuni.csv
 *   php scripts/analyze-product-sections.php --product=<slug>
 *
 * Optiuni:
 *   --min=N            afiseaza doar sectiunile prezente la minim N produse (implicit 1)
 *   --by-category      detaliaza raportul pe categorii
 *   --csv=<fisier>     export CSV (produs, categorie, sectiune) pentru Excel
 *   --summary-csv=<f>  export CSV cu sumarul (sectiune, nr produse, categorii)
 *   --product=<slug>   afiseaza detaliat sectiunile unui singur produs
 *   --show-missing     listeaza produsele fara nicio sectiune detectata
 *   --raw              nu grupeaza denumirile sinonime (vezi titlurile brute)
 */

$options = [
    'min' => 1,
    'by_category' => false,
    'csv' => '',
    'summary_csv' => '',
    'product' => '',
    'show_missing' => false,
    'raw' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--min=')) {
        $options['min'] = max(1, (int) substr($arg, 6));
    } elseif ($arg === '--by-category') {
        $options['by_category'] = true;
    } elseif (str_starts_with($arg, '--csv=')) {
        $options['csv'] = trim(substr($arg, 6));
    } elseif (str_starts_with($arg, '--summary-csv=')) {
        $options['summary_csv'] = trim(substr($arg, 14));
    } elseif (str_starts_with($arg, '--product=')) {
        $options['product'] = trim(substr($arg, 10));
    } elseif ($arg === '--show-missing') {
        $options['show_missing'] = true;
    } elseif ($arg === '--raw') {
        $options['raw'] = true;
    } else {
        exit("Optiune necunoscuta: {$arg}\nVezi antetul scriptului pentru optiuni.\n");
    }
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    exit("Conexiunea la baza de date nu este disponibila. Verifica .env.\n");
}

$sql = 'SELECT id, name, slug, category, description
        FROM products
        WHERE deleted_at IS NULL AND is_active = 1';
$params = [];
if ($options['product'] !== '') {
    $sql .= ' AND slug = :slug';
    $params['slug'] = $options['product'];
}
$sql .= ' ORDER BY category, name';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll() ?: [];

if ($products === []) {
    exit("Nu am gasit produse" . ($options['product'] !== '' ? " cu slug-ul {$options['product']}." : ".") . "\n");
}

// ---------------------------------------------------------------------------
// Mod detaliat pentru un singur produs
// ---------------------------------------------------------------------------

if ($options['product'] !== '') {
    $product = $products[0];
    $parsed = ProductSections::parse((string) ($product['description'] ?? ''));

    echo "Produs: " . (string) $product['name'] . "\n";
    echo "Categorie: " . ((string) ($product['category'] ?? '') ?: '(fara categorie)') . "\n\n";
    echo "--- INTRODUCERE (ramane in descriere) ---\n";
    echo wordwrap(trim(strip_tags($parsed['intro'])), 100) . "\n\n";

    if ($parsed['sections'] === []) {
        echo "Nu am detectat nicio sectiune in acest produs.\n";
        exit(0);
    }

    echo "--- SECTIUNI DETECTATE (" . count($parsed['sections']) . ") ---\n";
    foreach ($parsed['sections'] as $section) {
        echo "\n[" . $section['label'] . "]  (cheie: " . ProductSections::fieldKey($section['label']) . ")\n";
        echo wordwrap(trim(strip_tags($section['content_html'])), 100) . "\n";
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Analiza pe toate produsele
// ---------------------------------------------------------------------------

$sectionStats = [];     // groupKey => ['label'=>.., 'products'=>int, 'categories'=>[cat=>int]]
$rows = [];             // pentru CSV detaliat
$withoutSections = [];
$withoutDescription = 0;

foreach ($products as $product) {
    $description = (string) ($product['description'] ?? '');
    $category = trim((string) ($product['category'] ?? '')) ?: '(fara categorie)';

    if (trim(strip_tags($description)) === '') {
        $withoutDescription++;
        $withoutSections[] = (string) $product['slug'] . ' (fara descriere)';
        continue;
    }

    $parsed = ProductSections::parse($description);
    if ($parsed['sections'] === []) {
        $withoutSections[] = (string) $product['slug'];
        continue;
    }

    foreach ($parsed['sections'] as $section) {
        $canonical = $options['raw']
            ? ['key' => ProductSections::fieldKey($section['label']), 'name' => $section['label']]
            : ProductSections::canonical($section['label']);
        $groupKey = $canonical['key'];
        if ($groupKey === '') {
            continue;
        }

        if (!isset($sectionStats[$groupKey])) {
            $sectionStats[$groupKey] = [
                'label' => $canonical['name'],
                'labels' => [],
                'products' => 0,
                'categories' => [],
            ];
        }

        $sectionStats[$groupKey]['products']++;
        $sectionStats[$groupKey]['labels'][$section['label']] =
            ($sectionStats[$groupKey]['labels'][$section['label']] ?? 0) + 1;
        $sectionStats[$groupKey]['categories'][$category] =
            ($sectionStats[$groupKey]['categories'][$category] ?? 0) + 1;

        $rows[] = [
            (string) $product['slug'],
            (string) $product['name'],
            $category,
            $section['label'],
            $canonical['name'],
            $groupKey,
            mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($section['content_html']))), 0, 300),
        ];
    }
}

// Numele afisat: denumirea canonica, altfel varianta cea mai frecventa.
foreach ($sectionStats as $groupKey => $stat) {
    arsort($stat['labels']);
    $mostCommon = (string) array_key_first($stat['labels']);
    $sectionStats[$groupKey]['label'] = $options['raw']
        ? $mostCommon
        : ProductSections::canonical($mostCommon)['name'];
}

uasort($sectionStats, static fn(array $a, array $b): int => $b['products'] <=> $a['products']);

// ---------------------------------------------------------------------------
// Raport
// ---------------------------------------------------------------------------

$totalProducts = count($products);
$productsWithSections = $totalProducts - count($withoutSections);

echo "Produse analizate: {$totalProducts}\n";
echo "Produse cu sectiuni detectate: {$productsWithSections}\n";
echo "Produse fara sectiuni: " . count($withoutSections) . " (din care fara descriere: {$withoutDescription})\n";
echo "Sectiuni distincte gasite: " . count($sectionStats) . "\n\n";

if (!$options['raw']) {
    echo "Denumirile sinonime sunt grupate automat (ruleaza cu --raw pentru titlurile brute).\n\n";
}

printf("%-38s %8s   %s\n", 'SECTIUNE', 'PRODUSE', 'CHEIE CAMP');
echo str_repeat('-', 96) . "\n";

$shown = 0;
foreach ($sectionStats as $groupKey => $stat) {
    if ($stat['products'] < $options['min']) {
        continue;
    }
    $shown++;
    printf(
        "%-38s %8d   %s\n",
        mb_strimwidth($stat['label'], 0, 37, '…'),
        $stat['products'],
        $groupKey
    );

    if (!$options['raw'] && count($stat['labels']) > 1) {
        arsort($stat['labels']);
        echo "      (grupate: " . implode(', ', array_slice(array_keys($stat['labels']), 0, 5))
            . (count($stat['labels']) > 5 ? ', …' : '') . ")\n";
    }

    if ($options['by_category']) {
        arsort($stat['categories']);
        foreach ($stat['categories'] as $category => $count) {
            printf("      %-32s %8d\n", mb_strimwidth($category, 0, 31, '…'), $count);
        }
    }
}

if ($shown === 0) {
    echo "(nicio sectiune peste pragul --min={$options['min']})\n";
}

if ($options['show_missing'] && $withoutSections !== []) {
    echo "\n--- PRODUSE FARA SECTIUNI DETECTATE ---\n";
    foreach ($withoutSections as $slug) {
        echo "  {$slug}\n";
    }
}

// ---------------------------------------------------------------------------
// Export CSV
// ---------------------------------------------------------------------------

function writeCsv(string $path, array $header, array $rows): bool
{
    $handle = @fopen($path, 'w');
    if ($handle === false) {
        return false;
    }
    // BOM pentru diacritice corecte in Excel.
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $header, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '\\');
    }
    fclose($handle);
    return true;
}

if ($options['csv'] !== '') {
    $ok = writeCsv(
        $options['csv'],
        ['slug', 'produs', 'categorie', 'titlu_original', 'camp_final', 'cheie_camp', 'continut (primele 300 caractere)'],
        $rows
    );
    echo "\n" . ($ok
        ? "CSV detaliat scris in: {$options['csv']} (" . count($rows) . " randuri)\n"
        : "Nu am putut scrie fisierul CSV: {$options['csv']}\n");
}

if ($options['summary_csv'] !== '') {
    $summaryRows = [];
    foreach ($sectionStats as $groupKey => $stat) {
        arsort($stat['categories']);
        $categoryList = [];
        foreach ($stat['categories'] as $category => $count) {
            $categoryList[] = $category . ' (' . $count . ')';
        }
        $summaryRows[] = [
            $stat['label'],
            $groupKey,
            $stat['products'],
            implode('; ', $categoryList),
        ];
    }

    $ok = writeCsv(
        $options['summary_csv'],
        ['sectiune', 'cheie_camp', 'nr_produse', 'categorii'],
        $summaryRows
    );
    echo ($ok
        ? "CSV sumar scris in: {$options['summary_csv']} (" . count($summaryRows) . " randuri)\n"
        : "Nu am putut scrie fisierul CSV: {$options['summary_csv']}\n");
}

echo "\nUrmatorul pas: creeaza campurile si mută secțiunile cu\n";
echo "  php scripts/apply-product-sections.php --min=3 --dry-run\n";
