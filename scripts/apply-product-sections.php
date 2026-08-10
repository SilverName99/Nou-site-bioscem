<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\ProductSections;

/**
 * Creează câmpuri suplimentare pe baza secțiunilor din descrieri și mută
 * conținutul fiecărei secțiuni în câmpul corespunzător, ca să apară în tab-uri.
 *
 * Fiecare produs primește doar câmpurile care există în descrierea lui —
 * tab-urile se afișează doar pentru câmpurile cu valoare, deci produsele
 * diferite ajung automat cu tab-uri diferite.
 *
 * Descrierea originală este salvată într-un fișier de backup înainte de
 * orice modificare și poate fi restaurată cu --restore.
 *
 * Utilizare:
 *   php scripts/apply-product-sections.php --min=3 --dry-run
 *   php scripts/apply-product-sections.php --min=3
 *   php scripts/apply-product-sections.php --restore=storage/backups/xxx.json
 *
 * Optiuni:
 *   --min=N              muta doar sectiunile prezente la minim N produse (implicit 3)
 *   --fields=a,b,c       muta doar cheile listate (ignora --min)
 *   --exclude=a,b        sare peste cheile listate
 *   --keep-description   NU sterge sectiunile din descriere (atentie: continut dublat)
 *   --dry-run            arata ce s-ar face, fara scriere in DB
 *   --restore=<fisier>   restaureaza descrierile dintr-un backup si iese
 */

$options = [
    'min' => 3,
    'fields' => [],
    'exclude' => [],
    'keep_description' => false,
    'dry_run' => false,
    'restore' => '',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--min=')) {
        $options['min'] = max(1, (int) substr($arg, 6));
    } elseif (str_starts_with($arg, '--fields=')) {
        $options['fields'] = array_values(array_filter(array_map(
            static fn(string $v): string => ProductSections::canonical(trim($v))['key'],
            explode(',', substr($arg, 9))
        )));
    } elseif (str_starts_with($arg, '--exclude=')) {
        $options['exclude'] = array_values(array_filter(array_map(
            static fn(string $v): string => ProductSections::canonical(trim($v))['key'],
            explode(',', substr($arg, 10))
        )));
    } elseif ($arg === '--keep-description') {
        $options['keep_description'] = true;
    } elseif ($arg === '--dry-run') {
        $options['dry_run'] = true;
    } elseif (str_starts_with($arg, '--restore=')) {
        $options['restore'] = trim(substr($arg, 10));
    } else {
        exit("Optiune necunoscuta: {$arg}\nVezi antetul scriptului pentru optiuni.\n");
    }
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    exit("Conexiunea la baza de date nu este disponibila. Verifica .env.\n");
}

// ---------------------------------------------------------------------------
// Restaurare din backup
// ---------------------------------------------------------------------------

if ($options['restore'] !== '') {
    $path = $options['restore'];
    if (!is_file($path)) {
        exit("Fisierul de backup nu exista: {$path}\n");
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || !is_array($data['products'] ?? null)) {
        exit("Fisierul de backup nu are formatul asteptat: {$path}\n");
    }

    $update = $db->prepare('UPDATE products SET description = :description WHERE id = :id');
    $restored = 0;
    $db->beginTransaction();
    try {
        foreach ($data['products'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $update->execute([
                'id' => (int) ($entry['id'] ?? 0),
                'description' => (string) ($entry['description'] ?? ''),
            ]);
            $restored++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        exit("Eroare la restaurare, nicio modificare salvata: " . $e->getMessage() . "\n");
    }

    echo "Descrieri restaurate: {$restored}\n";
    echo "Nota: campurile suplimentare create raman; le poti sterge din Admin -> Campuri suplimentare.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Analiza descrierilor
// ---------------------------------------------------------------------------

$stmt = $db->query(
    'SELECT id, name, slug, category, description
     FROM products
     WHERE deleted_at IS NULL AND is_active = 1
     ORDER BY id'
);
$products = $stmt->fetchAll() ?: [];

if ($products === []) {
    exit("Nu exista produse active.\n");
}

$parsedByProduct = [];
$frequency = [];
$labelVariants = [];

foreach ($products as $product) {
    $description = (string) ($product['description'] ?? '');
    if (trim(strip_tags($description)) === '') {
        continue;
    }

    $parsed = ProductSections::parse($description);
    if ($parsed['sections'] === []) {
        continue;
    }

    $parsedByProduct[(int) $product['id']] = $parsed;

    foreach ($parsed['sections'] as $section) {
        $fieldKey = ProductSections::canonical($section['label'])['key'];
        if ($fieldKey === '') {
            continue;
        }
        $frequency[$fieldKey] = ($frequency[$fieldKey] ?? 0) + 1;
        $labelVariants[$fieldKey][$section['label']] = ($labelVariants[$fieldKey][$section['label']] ?? 0) + 1;
    }
}

// Cheile care vor deveni câmpuri suplimentare.
$selectedKeys = [];
foreach ($frequency as $fieldKey => $count) {
    if ($options['fields'] !== []) {
        if (!in_array($fieldKey, $options['fields'], true)) {
            continue;
        }
    } elseif ($count < $options['min']) {
        continue;
    }
    if (in_array($fieldKey, $options['exclude'], true)) {
        continue;
    }
    $selectedKeys[$fieldKey] = $count;
}

if ($selectedKeys === []) {
    echo "Nicio sectiune nu indeplineste criteriile (min={$options['min']}).\n";
    echo "Ruleaza intai: php scripts/analyze-product-sections.php\n";
    exit(0);
}

arsort($selectedKeys);

// Numele afișat: denumirea canonică, altfel varianta cea mai frecventă.
$fieldNames = [];
foreach ($selectedKeys as $fieldKey => $count) {
    $variants = $labelVariants[$fieldKey] ?? [];
    arsort($variants);
    $mostCommon = (string) (array_key_first($variants) ?: $fieldKey);
    $fieldNames[$fieldKey] = ProductSections::canonical($mostCommon)['name'] ?: $mostCommon;
}

echo "Sectiuni care devin campuri suplimentare:\n";
foreach ($selectedKeys as $fieldKey => $count) {
    printf("  %-28s %-30s %d produse\n", $fieldKey, $fieldNames[$fieldKey], $count);
}
echo "\n";

// ---------------------------------------------------------------------------
// Ce se schimbă la fiecare produs
// ---------------------------------------------------------------------------

$plan = [];
foreach ($products as $product) {
    $productId = (int) $product['id'];
    if (!isset($parsedByProduct[$productId])) {
        continue;
    }

    $parsed = $parsedByProduct[$productId];
    $values = [];
    $keptSections = [];

    foreach ($parsed['sections'] as $section) {
        $fieldKey = ProductSections::canonical($section['label'])['key'];
        if ($fieldKey !== '' && isset($selectedKeys[$fieldKey])) {
            // Dacă aceeași secțiune apare de două ori, se concatenează.
            $values[$fieldKey] = isset($values[$fieldKey])
                ? $values[$fieldKey] . "\n" . $section['content_html']
                : $section['content_html'];
            continue;
        }
        $keptSections[] = $section;
    }

    if ($values === []) {
        continue;
    }

    // Descrierea rămasă: introducerea + secțiunile nemutate.
    $newDescription = $parsed['intro'];
    foreach ($keptSections as $section) {
        $newDescription .= '<p><strong>' . htmlspecialchars($section['label'], ENT_QUOTES) . '</strong>:</p>'
            . $section['content_html'];
    }

    $plan[] = [
        'id' => $productId,
        'slug' => (string) $product['slug'],
        'name' => (string) $product['name'],
        'original_description' => (string) ($product['description'] ?? ''),
        'new_description' => trim($newDescription),
        'values' => $values,
    ];
}

if ($plan === []) {
    echo "Nu exista produse de actualizat.\n";
    exit(0);
}

echo "Produse de actualizat: " . count($plan) . "\n";
echo $options['dry_run'] ? "Mod: DRY-RUN (nu se scrie nimic in DB)\n\n" : "Mod: aplicare reala\n\n";

if ($options['dry_run']) {
    foreach (array_slice($plan, 0, 15) as $entry) {
        echo '  ' . $entry['slug'] . ' -> ' . implode(', ', array_keys($entry['values'])) . "\n";
    }
    if (count($plan) > 15) {
        echo '  ... si inca ' . (count($plan) - 15) . " produse\n";
    }
    echo "\nRuleaza fara --dry-run pentru aplicarea reala.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Backup descrieri
// ---------------------------------------------------------------------------

$backupDir = __DIR__ . '/../storage/backups';
if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    exit("Nu am putut crea directorul de backup: {$backupDir}\n");
}

$backupPath = $backupDir . '/product-descriptions-' . date('Ymd-His') . '.json';
$backupPayload = [
    'created_at' => date('c'),
    'products' => array_map(
        static fn(array $entry): array => [
            'id' => $entry['id'],
            'slug' => $entry['slug'],
            'description' => $entry['original_description'],
        ],
        $plan
    ),
];

if (@file_put_contents($backupPath, json_encode($backupPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
    exit("Nu am putut scrie backup-ul in {$backupPath}. Opresc, ca sa nu pierdem descrierile.\n");
}

echo "Backup descrieri: {$backupPath}\n";

// ---------------------------------------------------------------------------
// Aplicare
// ---------------------------------------------------------------------------

$db->beginTransaction();
try {
    // 1. Câmpurile suplimentare (tip html, ca sa pastreze formatarea).
    $fieldIds = [];
    $selectField = $db->prepare('SELECT id FROM product_extra_fields WHERE field_key = :field_key LIMIT 1');
    $insertField = $db->prepare(
        'INSERT INTO product_extra_fields (name, field_key, field_type, is_required, sort_order, is_active)
         VALUES (:name, :field_key, :field_type, 0, :sort_order, 1)'
    );
    $updateField = $db->prepare(
        'UPDATE product_extra_fields SET name = :name, field_type = :field_type, is_active = 1 WHERE id = :id'
    );

    $sortOrder = 0;
    foreach ($selectedKeys as $fieldKey => $count) {
        $sortOrder += 10;
        $selectField->execute(['field_key' => $fieldKey]);
        $existingId = $selectField->fetchColumn();

        if ($existingId === false) {
            $insertField->execute([
                'name' => $fieldNames[$fieldKey],
                'field_key' => $fieldKey,
                'field_type' => 'html',
                'sort_order' => $sortOrder,
            ]);
            $fieldIds[$fieldKey] = (int) $db->lastInsertId();
        } else {
            $updateField->execute([
                'id' => (int) $existingId,
                'name' => $fieldNames[$fieldKey],
                'field_type' => 'html',
            ]);
            $fieldIds[$fieldKey] = (int) $existingId;
        }
    }

    // 2. Valorile per produs + descrierea curatata.
    $upsertValue = $db->prepare(
        'INSERT INTO product_extra_field_values (product_id, field_id, `value`)
         VALUES (:product_id, :field_id, :value)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    );
    $updateDescription = $db->prepare('UPDATE products SET description = :description WHERE id = :id');

    $valuesWritten = 0;
    $descriptionsUpdated = 0;

    foreach ($plan as $entry) {
        foreach ($entry['values'] as $fieldKey => $value) {
            if (!isset($fieldIds[$fieldKey])) {
                continue;
            }
            $upsertValue->execute([
                'product_id' => $entry['id'],
                'field_id' => $fieldIds[$fieldKey],
                'value' => $value,
            ]);
            $valuesWritten++;
        }

        if (!$options['keep_description']) {
            $updateDescription->execute([
                'id' => $entry['id'],
                'description' => $entry['new_description'],
            ]);
            $descriptionsUpdated++;
        }
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    exit("Eroare la aplicare, nicio modificare salvata: " . $e->getMessage()
        . "\nDescrierile originale sunt in {$backupPath}\n");
}

echo "Campuri suplimentare create/actualizate: " . count($fieldIds) . "\n";
echo "Valori scrise: {$valuesWritten}\n";
echo "Descrieri curatate: {$descriptionsUpdated}\n";
echo "\nGata. Verifica un produs pe site - sectiunile apar acum ca tab-uri.\n";
echo "Daca ceva nu arata bine, poti reveni cu:\n";
echo "  php scripts/apply-product-sections.php --restore={$backupPath}\n";
