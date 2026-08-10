<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

/**
 * Alocă fiecărui produs un set de produse similare (caruselul „Produse similare”),
 * ca să nu fie nevoie de selecție manuală la fiecare produs.
 *
 * Implicit alege între 5 și 8 produse, preferând aceeași categorie și
 * completând din restul catalogului dacă nu sunt suficiente.
 *
 * Utilizare:
 *   php scripts/set-similar-products.php --dry-run
 *   php scripts/set-similar-products.php
 *
 * Optiuni:
 *   --min=N            numarul minim de produse similare (implicit 5)
 *   --max=N            numarul maxim (implicit 8)
 *   --only-missing     doar produsele care nu au deja produse similare setate
 *   --any-category     ignora categoria, alege complet aleatoriu
 *   --seed=N           rezultat reproductibil (aceeasi selectie la fiecare rulare)
 *   --clear            sterge toate selectiile de produse similare si iese
 *   --dry-run          arata ce s-ar face, fara scriere in DB
 */

$options = [
    'min' => 5,
    'max' => 8,
    'only_missing' => false,
    'any_category' => false,
    'seed' => null,
    'clear' => false,
    'dry_run' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--min=')) {
        $options['min'] = max(1, (int) substr($arg, 6));
    } elseif (str_starts_with($arg, '--max=')) {
        $options['max'] = max(1, (int) substr($arg, 6));
    } elseif ($arg === '--only-missing') {
        $options['only_missing'] = true;
    } elseif ($arg === '--any-category') {
        $options['any_category'] = true;
    } elseif (str_starts_with($arg, '--seed=')) {
        $options['seed'] = (int) substr($arg, 7);
    } elseif ($arg === '--clear') {
        $options['clear'] = true;
    } elseif ($arg === '--dry-run') {
        $options['dry_run'] = true;
    } else {
        exit("Optiune necunoscuta: {$arg}\nVezi antetul scriptului pentru optiuni.\n");
    }
}

if ($options['max'] < $options['min']) {
    exit("--max ({$options['max']}) nu poate fi mai mic decat --min ({$options['min']}).\n");
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    exit("Conexiunea la baza de date nu este disponibila. Verifica .env.\n");
}

// Coloana este creata lazy de aplicatie; ne asiguram ca exista.
try {
    $db->exec('ALTER TABLE products ADD COLUMN similar_products_json LONGTEXT DEFAULT NULL AFTER gallery_images_json');
} catch (Throwable) {
    // Coloana exista deja.
}

// ---------------------------------------------------------------------------
// Stergere selectii
// ---------------------------------------------------------------------------

if ($options['clear']) {
    if ($options['dry_run']) {
        $count = (int) $db->query(
            "SELECT COUNT(*) FROM products WHERE similar_products_json IS NOT NULL AND similar_products_json <> '' AND similar_products_json <> '[]'"
        )->fetchColumn();
        echo "[dry-run] S-ar sterge selectia de produse similare la {$count} produse.\n";
        exit(0);
    }

    $affected = $db->exec("UPDATE products SET similar_products_json = NULL WHERE similar_products_json IS NOT NULL");
    echo "Selectii sterse: " . (int) $affected . "\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Produse disponibile
// ---------------------------------------------------------------------------

$products = $db->query(
    'SELECT id, name, slug, category, similar_products_json
     FROM products
     WHERE deleted_at IS NULL AND is_active = 1
     ORDER BY id'
)->fetchAll() ?: [];

$total = count($products);
if ($total < 2) {
    exit("Sunt necesare cel putin 2 produse active. Gasite: {$total}.\n");
}

if ($options['seed'] !== null) {
    mt_srand($options['seed']);
}

// Index pe categorie, ca sa preferam produse inrudite.
$idsByCategory = [];
$allIds = [];
foreach ($products as $product) {
    $id = (int) $product['id'];
    $allIds[] = $id;
    $category = trim((string) ($product['category'] ?? ''));
    if ($category !== '') {
        $idsByCategory[$category][] = $id;
    }
}

/**
 * Alege aleatoriu $count valori dintr-o listă, fără repetiții.
 *
 * @param list<int> $pool
 * @return list<int>
 */
function pickRandom(array $pool, int $count): array
{
    if ($pool === [] || $count <= 0) {
        return [];
    }
    if (count($pool) <= $count) {
        shuffle($pool);
        return $pool;
    }

    $keys = (array) array_rand($pool, $count);
    $picked = [];
    foreach ($keys as $key) {
        $picked[] = $pool[$key];
    }
    shuffle($picked);

    return $picked;
}

$maxPossible = $total - 1;
if ($options['max'] > $maxPossible) {
    echo "Atentie: exista doar {$maxPossible} produse disponibile pentru asociere; "
        . "limita superioara devine {$maxPossible}.\n\n";
}

$plan = [];
$skipped = 0;

foreach ($products as $product) {
    $productId = (int) $product['id'];

    if ($options['only_missing']) {
        $existing = trim((string) ($product['similar_products_json'] ?? ''));
        if ($existing !== '' && $existing !== '[]' && $existing !== 'null') {
            $skipped++;
            continue;
        }
    }

    $category = trim((string) ($product['category'] ?? ''));
    $target = random_int(
        min($options['min'], $maxPossible),
        min($options['max'], $maxPossible)
    );

    $selected = [];

    if (!$options['any_category'] && $category !== '' && isset($idsByCategory[$category])) {
        $sameCategory = array_values(array_filter(
            $idsByCategory[$category],
            static fn(int $id): bool => $id !== $productId
        ));
        $selected = pickRandom($sameCategory, $target);
    }

    // Completare din restul catalogului daca nu sunt destule in categorie.
    if (count($selected) < $target) {
        $rest = array_values(array_filter(
            $allIds,
            static fn(int $id): bool => $id !== $productId && !in_array($id, $selected, true)
        ));
        $selected = array_merge($selected, pickRandom($rest, $target - count($selected)));
    }

    if ($selected === []) {
        continue;
    }

    $plan[] = [
        'id' => $productId,
        'name' => (string) $product['name'],
        'slug' => (string) $product['slug'],
        'category' => $category !== '' ? $category : '(fara categorie)',
        'similar' => $selected,
    ];
}

echo "Produse active: {$total}\n";
echo "Produse de actualizat: " . count($plan) . ($skipped > 0 ? " (sarite, au deja selectie: {$skipped})" : '') . "\n";
echo "Produse similare per produs: intre {$options['min']} si {$options['max']}\n";
echo $options['any_category'] ? "Selectie: aleatorie din tot catalogul\n" : "Selectie: preferabil din aceeasi categorie\n";
echo $options['dry_run'] ? "Mod: DRY-RUN (nu se scrie nimic in DB)\n\n" : "Mod: aplicare reala\n\n";

if ($plan === []) {
    echo "Nimic de facut.\n";
    exit(0);
}

if ($options['dry_run']) {
    foreach (array_slice($plan, 0, 10) as $entry) {
        echo '  ' . $entry['slug'] . ' [' . $entry['category'] . '] -> '
            . count($entry['similar']) . ' produse: ' . implode(', ', $entry['similar']) . "\n";
    }
    if (count($plan) > 10) {
        echo '  ... si inca ' . (count($plan) - 10) . " produse\n";
    }
    echo "\nRuleaza fara --dry-run pentru aplicarea reala.\n";
    exit(0);
}

$update = $db->prepare('UPDATE products SET similar_products_json = :json WHERE id = :id');
$updated = 0;

$db->beginTransaction();
try {
    foreach ($plan as $entry) {
        $update->execute([
            'id' => $entry['id'],
            'json' => json_encode(array_values($entry['similar'])),
        ]);
        $updated++;
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    exit("Eroare la actualizare, nicio modificare salvata: " . $e->getMessage() . "\n");
}

echo "Produse actualizate: {$updated}\n";
echo "Gata. Verifica un produs pe site - caruselul „Produse similare” este populat.\n";
echo "Poti reveni oricand cu: php scripts/set-similar-products.php --clear\n";
