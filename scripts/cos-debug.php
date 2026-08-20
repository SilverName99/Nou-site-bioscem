<?php

declare(strict_types=1);

/**
 * De ce nu intră un produs în coș.
 *
 * Adăugarea în coș trece prin mai multe verificări (epuizat, ofertă cu dată de
 * expirare, plafon de stoc din gestiune), iar când una pică vizitatorul e trimis
 * înapoi la pagina produsului. Din afară arată la fel de fiecare dată: „am dat
 * adaugă și nu apare în coș". Scriptul spune care verificare a picat, pe fiecare
 * produs, folosind exact metodele site-ului — nu o copie a lor.
 *
 *   php scripts/cos-debug.php orgono
 *   php scripts/cos-debug.php "articomplex" "silicium"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Http\Controllers\SiteController;
use App\Support\Database;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));
if (!$db instanceof PDO) {
    fwrite(STDERR, "Nu am putut deschide conexiunea la baza de date.\n");
    exit(1);
}

$cautari = array_slice($argv, 1);
if ($cautari === []) {
    fwrite(STDERR, "Scrie o bucată din denumirea produsului. Ex: php scripts/cos-debug.php orgono\n");
    exit(1);
}

$ctrl = (new ReflectionClass(SiteController::class))->newInstanceWithoutConstructor();
$rc = new ReflectionClass($ctrl);
$priv = static function (string $nume) use ($rc): ReflectionMethod {
    $m = $rc->getMethod($nume);
    $m->setAccessible(true);
    return $m;
};
$findProductById = $priv('findProductById');
$limitaStocProdus = $priv('limitaStocProdus');
$cereOferta = $priv('productRequiresBbdSelection');
$mesajStoc = $priv('mesajStocInsuficient');

foreach ($cautari as $cautare) {
    echo "\n=== căutare: „{$cautare}”\n";
    $stmt = $db->prepare(
        'SELECT id, name, sku, price, out_of_stock, stock
         FROM products
         WHERE deleted_at IS NULL AND name LIKE :q
         ORDER BY name LIMIT 15'
    );
    $stmt->execute(['q' => '%' . $cautare . '%']);
    $randuri = $stmt->fetchAll() ?: [];
    if ($randuri === []) {
        echo "  (niciun produs cu denumirea asta)\n";
        continue;
    }

    foreach ($randuri as $r) {
        $id = (int) ($r['id'] ?? 0);
        $produs = $findProductById->invoke($ctrl, $id);
        if (!is_array($produs)) {
            echo "  #{$id} " . (string) $r['name'] . " — nu poate fi încărcat de site (verifică dacă e publicat)\n";
            continue;
        }

        $epuizat = (int) ($produs['out_of_stock'] ?? 0) === 1;
        $dinErp = (int) ($produs['stock_from_erp'] ?? 0) === 1;
        $limita = $limitaStocProdus->invoke($ctrl, $produs);
        $oferta = (bool) $cereOferta->invoke($ctrl, $produs);

        // Aceleași verificări, în aceeași ordine ca la adăugarea în coș.
        $verdict = '✅ intră în coș dintr-un clic din catalog';
        if ($epuizat) {
            $verdict = '❌ „Produsul este epuizat momentan." (out_of_stock = 1)';
        } elseif ($oferta) {
            $verdict = '❌ „Alege oferta dorită…" — produsul are oferte cu dată de expirare, '
                . 'deci NU poate fi adăugat din catalog, doar din pagina lui';
        } elseif ($limita !== null && $limita < 1) {
            $verdict = '❌ „' . $mesajStoc->invoke($ctrl, $limita) . '" (plafon din gestiune)';
        }

        printf(
            "  #%-5d %-46s sku=%-14s stoc_site=%-5s stoc_erp=%-5s plafon=%-5s ofertă=%s\n     %s\n",
            $id,
            mb_substr((string) ($produs['name'] ?? ''), 0, 45),
            (string) ($produs['sku'] ?? '-'),
            (string) ($r['stock'] ?? '-'),
            $dinErp ? (string) ($produs['stock'] ?? '-') : 'n/a',
            $limita === null ? 'fără' : (string) $limita,
            $oferta ? 'DA' : 'nu',
            $verdict
        );
    }
}

echo "\n";
