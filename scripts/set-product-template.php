<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

/**
 * Atribuie un template de produs mai multor produse deodată,
 * ca sa nu fie nevoie de editare manuala produs cu produs.
 *
 * Utilizare:
 *   php scripts/set-product-template.php --list
 *   php scripts/set-product-template.php --template=<slug|id> [optiuni]
 *
 * Optiuni:
 *   --template=<slug|id>   template-ul de aplicat (obligatoriu)
 *                          foloseste --template=none pentru a scoate template-ul
 *   --category=<slug|nume> aplica doar produselor dintr-o categorie
 *   --only-missing         aplica doar produselor care nu au deja un template
 *   --include-inactive     include si produsele inactive (implicit doar cele active)
 *   --include-trashed      include si produsele din cosul de gunoi
 *   --dry-run              arata ce s-ar schimba, fara sa scrie in DB
 */

$options = [
    'list' => false,
    'template' => '',
    'category' => '',
    'only_missing' => false,
    'include_inactive' => false,
    'include_trashed' => false,
    'dry_run' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--list') {
        $options['list'] = true;
    } elseif (str_starts_with($arg, '--template=')) {
        $options['template'] = trim(substr($arg, 11));
    } elseif (str_starts_with($arg, '--category=')) {
        $options['category'] = trim(substr($arg, 11));
    } elseif ($arg === '--only-missing') {
        $options['only_missing'] = true;
    } elseif ($arg === '--include-inactive') {
        $options['include_inactive'] = true;
    } elseif ($arg === '--include-trashed') {
        $options['include_trashed'] = true;
    } elseif ($arg === '--dry-run') {
        $options['dry_run'] = true;
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
// Lista template-urilor disponibile
// ---------------------------------------------------------------------------

$templates = $db->query('SELECT id, name, slug, is_active FROM product_templates ORDER BY id')->fetchAll() ?: [];

if ($options['list'] || $options['template'] === '') {
    if ($templates === []) {
        echo "Nu exista niciun template de produs. Creeaza unul din Admin -> Template-uri produs.\n";
        exit(0);
    }

    echo "Template-uri disponibile:\n";
    foreach ($templates as $template) {
        $state = (int) ($template['is_active'] ?? 1) === 1 ? 'activ' : 'inactiv';
        echo sprintf(
            "  id=%-3d slug=%-28s %s  (%s)\n",
            (int) $template['id'],
            (string) $template['slug'],
            (string) $template['name'],
            $state
        );
    }

    if ($options['template'] === '') {
        echo "\nExemplu:\n";
        echo "  php scripts/set-product-template.php --template=" . (string) ($templates[0]['slug'] ?? 'slug') . "\n";
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Rezolvarea template-ului
// ---------------------------------------------------------------------------

$templateId = null;
$templateLabel = 'fara template';

if (strtolower($options['template']) !== 'none') {
    foreach ($templates as $template) {
        $matchesSlug = strcasecmp((string) $template['slug'], $options['template']) === 0;
        $matchesId = ctype_digit($options['template']) && (int) $template['id'] === (int) $options['template'];
        if ($matchesSlug || $matchesId) {
            $templateId = (int) $template['id'];
            $templateLabel = (string) $template['name'] . ' (' . (string) $template['slug'] . ')';
            break;
        }
    }

    if ($templateId === null) {
        echo "Nu am gasit template-ul: {$options['template']}\n";
        echo "Ruleaza cu --list ca sa vezi optiunile disponibile.\n";
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Selectia produselor
// ---------------------------------------------------------------------------

$where = [];
$params = [];

if (!$options['include_trashed']) {
    $where[] = 'deleted_at IS NULL';
}
if (!$options['include_inactive']) {
    $where[] = 'is_active = 1';
}
if ($options['only_missing']) {
    $where[] = 'product_template_id IS NULL';
}
if ($options['category'] !== '') {
    $where[] = '(category = :category OR category_id = (SELECT id FROM product_categories WHERE slug = :category_slug LIMIT 1))';
    $params['category'] = $options['category'];
    $params['category_slug'] = $options['category'];
}

// Nu rescrie produsele care au deja exact acest template.
if ($templateId === null) {
    $where[] = 'product_template_id IS NOT NULL';
} else {
    $where[] = '(product_template_id IS NULL OR product_template_id <> :current_template)';
    $params['current_template'] = $templateId;
}

$whereSql = $where === [] ? '1' : implode(' AND ', $where);

$select = $db->prepare('SELECT id, name, slug FROM products WHERE ' . $whereSql . ' ORDER BY id');
$select->execute($params);
$products = $select->fetchAll() ?: [];

echo "Template tinta: {$templateLabel}\n";
if ($options['category'] !== '') {
    echo "Filtru categorie: {$options['category']}\n";
}
echo $options['dry_run'] ? "Mod: DRY-RUN (nu se scrie nimic in DB)\n" : "Mod: aplicare reala\n";
echo "Produse de actualizat: " . count($products) . "\n";

if ($products === []) {
    echo "Nimic de facut - toate produsele selectate au deja acest template.\n";
    exit(0);
}

if ($options['dry_run']) {
    foreach ($products as $product) {
        echo '  [dry-run] #' . (int) $product['id'] . ' ' . (string) $product['slug'] . ' | ' . (string) $product['name'] . "\n";
    }
    echo "\nRuleaza fara --dry-run pentru aplicarea reala.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Aplicare
// ---------------------------------------------------------------------------

$update = $db->prepare('UPDATE products SET product_template_id = :template_id WHERE id = :id');
$updated = 0;

$db->beginTransaction();
try {
    foreach ($products as $product) {
        $update->execute([
            'template_id' => $templateId,
            'id' => (int) $product['id'],
        ]);
        $updated += $update->rowCount() > 0 ? 1 : 0;
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    exit("Eroare la actualizare, nicio modificare salvata: " . $e->getMessage() . "\n");
}

echo "Produse actualizate: {$updated}\n";
echo "Gata.\n";
