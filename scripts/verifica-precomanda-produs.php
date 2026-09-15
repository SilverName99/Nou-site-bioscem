<?php

declare(strict_types=1);

/**
 * Spune de ce nu se pastreaza setarile de precomanda pe un produs.
 *
 * Bifa „Valabil pentru precomanda" si cele doua plafoane stau in trei coloane
 * adaugate prin ALTER. Daca ele lipsesc, interogarea din panou cade pe ramura
 * de rezerva — care nu le cere —, iar formularul se deschide gol, ca si cum
 * salvarea n-ar fi mers. Scriptul asta se uita direct in baza: exista
 * coloanele? ce scrie in ele, pentru produsul cerut?
 *
 * Rulare:
 *   php scripts/verifica-precomanda-produs.php            # doar coloanele
 *   php scripts/verifica-precomanda-produs.php 123        # si produsul cu id 123
 *   php scripts/verifica-precomanda-produs.php "biofer"   # cautare dupa nume/cod
 *   php scripts/verifica-precomanda-produs.php --repara   # creeaza coloanele lipsa
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\Precomanda;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db'] ?? []);
if (!$db instanceof PDO) {
    fwrite(STDERR, "Nu ma pot conecta la baza de date.\n");
    exit(1);
}

$argumente = array_slice($argv, 1);
$repara = in_array('--repara', $argumente, true);
$cautare = '';
foreach ($argumente as $a) {
    if ($a !== '--repara') {
        $cautare = trim($a);
        break;
    }
}

/** Coloanele existente ale unui tabel. */
$coloane = static function (PDO $db, string $tabel): array {
    try {
        $stmt = $db->query('SHOW COLUMNS FROM ' . $tabel);
        return array_map(static fn(array $r): string => (string) $r['Field'], $stmt->fetchAll() ?: []);
    } catch (Throwable $e) {
        return [];
    }
};

if ($repara) {
    echo "Creez coloanele lipsa…\n";
    Precomanda::ensureSchema($db);
}

echo "\n1) Coloanele precomenzii\n";
$aleProduselor = $coloane($db, 'products');
$aleComenzilor = $coloane($db, 'orders');
$lipsa = [];
foreach ([
    ['products', 'preorder_enabled', $aleProduselor],
    ['products', 'preorder_max_per_order', $aleProduselor],
    ['products', 'preorder_max_total', $aleProduselor],
    ['orders', 'preorder_status', $aleComenzilor],
    ['orders', 'preorder_released_at', $aleComenzilor],
] as [$tabel, $coloana, $existente]) {
    $are = in_array($coloana, $existente, true);
    echo ($are ? '  ✓ ' : '  ✗ ') . $tabel . '.' . $coloana . ($are ? '' : ' — LIPSESTE') . "\n";
    if (!$are) {
        $lipsa[] = $tabel . '.' . $coloana;
    }
}

if ($lipsa !== []) {
    echo "\n  Astea lipsesc, de aia nu se pastreaza nimic. Ruleaza:\n";
    echo "    php scripts/verifica-precomanda-produs.php --repara\n";
    echo "  Daca tot nu apar, utilizatorul de baza de date n-are drept de ALTER.\n";
}

if ($cautare === '') {
    echo "\nGata. Da-mi un id sau o bucata din nume ca sa ma uit si pe un produs.\n";
    exit($lipsa === [] ? 0 : 1);
}

echo "\n2) Produsul cerut\n";
if ($lipsa !== []) {
    echo "  Sar peste: fara coloane n-am ce citi.\n";
    exit(1);
}

if (ctype_digit($cautare)) {
    $stmt = $db->prepare(
        'SELECT id, name, sku, stock, out_of_stock, preorder_enabled, preorder_max_per_order, preorder_max_total
           FROM products WHERE id = :id LIMIT 5'
    );
    $stmt->execute(['id' => (int) $cautare]);
} else {
    $stmt = $db->prepare(
        'SELECT id, name, sku, stock, out_of_stock, preorder_enabled, preorder_max_per_order, preorder_max_total
           FROM products WHERE name LIKE :q OR sku LIKE :q ORDER BY id DESC LIMIT 5'
    );
    $stmt->execute(['q' => '%' . $cautare . '%']);
}
$randuri = $stmt->fetchAll() ?: [];
if ($randuri === []) {
    echo "  Nu am gasit niciun produs dupa „{$cautare}”.\n";
    exit(1);
}

foreach ($randuri as $r) {
    $fara = static fn($v): string => $v === null || $v === '' ? 'fara limita' : (string) $v;
    echo "\n  #{$r['id']} — {$r['name']} ({$r['sku']})\n";
    echo '    stoc: ' . (int) $r['stock'] . ', epuizat: ' . ((int) $r['out_of_stock'] === 1 ? 'da' : 'nu') . "\n";
    echo '    valabil pentru precomanda: ' . ((int) $r['preorder_enabled'] === 1 ? 'DA' : 'nu') . "\n";
    echo '    maxim per comanda: ' . $fara($r['preorder_max_per_order']) . "\n";
    echo '    maxim precomandat (total): ' . $fara($r['preorder_max_total']) . "\n";

    if ((int) $r['preorder_enabled'] === 1) {
        $luate = Precomanda::cantitatePrecomandata($db, (int) $r['id']);
        echo "    precomandate pana acum: {$luate} bucati\n";
        $limita = Precomanda::limitaEfectiva($db, (array) $r);
        echo '    cat mai poate lua un client: ' . ($limita === null ? 'oricat' : (string) $limita) . "\n";
    }
}

echo "\nDaca aici scrie DA si 180, dar in panou vezi gol, atunci nu e salvarea de vina: goleste cache-ul si reincarca pagina.\n";
exit(0);
