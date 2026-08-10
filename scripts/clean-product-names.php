<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

/**
 * Curăță denumirile produselor de HTML rămas din importul WooCommerce
 * (ex. „QUINTON IZOTONIC <br>fiole 300 ml” devine „QUINTON IZOTONIC fiole 300 ml”).
 *
 * Denumirile sunt afișate ca text simplu, deci orice markup apare vizibil în pagină.
 *
 * Utilizare:
 *   php scripts/clean-product-names.php --dry-run
 *   php scripts/clean-product-names.php
 *
 * Optiuni:
 *   --dry-run       arata ce s-ar schimba, fara scriere in DB
 *   --with-pages    curata si titlurile paginilor
 */

$options = ['dry_run' => false, 'with_pages' => false];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $options['dry_run'] = true;
    } elseif ($arg === '--with-pages') {
        $options['with_pages'] = true;
    } else {
        exit("Optiune necunoscuta: {$arg}\nVezi antetul scriptului pentru optiuni.\n");
    }
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    exit("Conexiunea la baza de date nu este disponibila. Verifica .env.\n");
}

function cleanTitle(string $value): string
{
    // <br> și finalurile de bloc devin spațiu, ca să nu se lipească cuvintele.
    $value = (string) preg_replace('#<br\s*/?>|</p>|</div>|</li>#i', ' ', $value);
    $value = strip_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace("\xC2\xA0", ' ', $value);

    return trim((string) preg_replace('/\s+/u', ' ', $value));
}

/**
 * @return array{checked:int, changed:int}
 */
function cleanTable(PDO $db, string $table, string $column, bool $dryRun): array
{
    $rows = $db->query("SELECT id, {$column} AS title FROM {$table}")->fetchAll() ?: [];
    $update = $db->prepare("UPDATE {$table} SET {$column} = :title WHERE id = :id");

    $checked = 0;
    $changed = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $checked++;
        $original = (string) ($row['title'] ?? '');
        $cleaned = cleanTitle($original);

        if ($cleaned === $original || $cleaned === '') {
            continue;
        }

        $changed++;
        echo ($dryRun ? '  [dry-run] ' : '  ') . $original . "\n";
        echo '        -> ' . $cleaned . "\n";

        if (!$dryRun) {
            $update->execute(['id' => (int) ($row['id'] ?? 0), 'title' => mb_substr($cleaned, 0, 190)]);
        }
    }

    return ['checked' => $checked, 'changed' => $changed];
}

echo $options['dry_run'] ? "Mod: DRY-RUN (nu se scrie nimic in DB)\n\n" : "Mod: aplicare reala\n\n";

echo "Produse:\n";
$products = cleanTable($db, 'products', 'name', $options['dry_run']);
echo "  verificate: {$products['checked']}, de curatat: {$products['changed']}\n";

if ($options['with_pages']) {
    echo "\nPagini:\n";
    $pages = cleanTable($db, 'pages', 'title', $options['dry_run']);
    echo "  verificate: {$pages['checked']}, de curatat: {$pages['changed']}\n";
}

if ($options['dry_run']) {
    echo "\nRuleaza fara --dry-run pentru aplicarea reala.\n";
}
