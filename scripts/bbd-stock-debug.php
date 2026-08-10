<?php

declare(strict_types=1);

/**
 * Diagnostic: shows, for a product's BBD offers, how the remaining stock is computed
 * (set stock, quantity reserved by orders grouped by status, and what remains).
 *
 * Usage:  php scripts/bbd-stock-debug.php <product-slug>
 * Example: php scripts/bbd-stock-debug.php dastco
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    exit("Conexiunea la baza de date nu este disponibilă.\n");
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Report which relevant columns actually exist in this database.
try {
    $cols = $db->query("SHOW COLUMNS FROM order_items")->fetchAll(PDO::FETCH_COLUMN);
    echo 'Coloane order_items: ' . implode(', ', $cols) . "\n";
    echo 'Are coloana bbd_key: ' . (in_array('bbd_key', $cols, true) ? 'DA' : 'NU') . "\n\n";
} catch (Throwable $e) {
    echo 'Nu am putut citi coloanele order_items: ' . $e->getMessage() . "\n\n";
}

$slug = trim((string) ($argv[1] ?? ''));
if ($slug === '') {
    exit("Folosire: php scripts/bbd-stock-debug.php <product-slug>\n");
}

$stmt = $db->prepare('SELECT id, name, bbd_enabled, bbd_entries_json FROM products WHERE slug = :slug AND deleted_at IS NULL LIMIT 1');
$stmt->execute(['slug' => $slug]);
$product = $stmt->fetch();

if (!is_array($product)) {
    exit("Produsul cu slug '{$slug}' nu a fost găsit.\n");
}

$productId = (int) $product['id'];
echo "Produs: {$product['name']} (id {$productId}), BBD activ: " . ((int) $product['bbd_enabled'] === 1 ? 'DA' : 'NU') . "\n";
echo str_repeat('=', 70) . "\n";

$entries = json_decode((string) ($product['bbd_entries_json'] ?? '[]'), true);
if (!is_array($entries) || $entries === []) {
    exit("Produsul nu are oferte BBD.\n");
}

foreach ($entries as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $key = (string) ($entry['key'] ?? '');
    $date = (string) ($entry['date'] ?? '');
    $stockSet = $entry['stock'] ?? null;

    echo "\nOfertă: expiră {$date} | LOT " . (string) ($entry['lot'] ?? '-') . " | cheie {$key}\n";
    echo "  Stoc setat: " . ($stockSet === null ? 'GOL (nelimitat)' : (string) $stockSet) . "\n";

    if ($stockSet === null || $key === '') {
        echo "  => Disponibil (fără limită de stoc).\n";
        continue;
    }

    try {
        // Breakdown of reserved quantity by order status.
        $break = $db->prepare(
            'SELECT o.status AS ostatus, o.payment_status AS opay, COALESCE(SUM(oi.quantity),0) AS qty, COUNT(*) AS line_count
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = :pid AND oi.bbd_key = :key AND o.deleted_at IS NULL
             GROUP BY o.status, o.payment_status
             ORDER BY o.status'
        );
        $break->execute(['pid' => $productId, 'key' => $key]);
        $rows = $break->fetchAll() ?: [];

        if ($rows === []) {
            echo "  Comenzi care folosesc oferta: NICIUNA\n";
        } else {
            echo "  Comenzi care folosesc oferta (după status):\n";
            foreach ($rows as $r) {
                echo sprintf(
                    "    - status=%-16s plata=%-8s cantitate=%-4d (%d linii)\n",
                    (string) $r['ostatus'],
                    (string) $r['opay'],
                    (int) $r['qty'],
                    (int) $r['line_count']
                );
            }
        }

        // Same rule the site uses now: excludes cancelled/failed/refunded/pending_payment.
        $used = $db->prepare(
            "SELECT COALESCE(SUM(oi.quantity),0)
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = :pid AND oi.bbd_key = :key AND o.deleted_at IS NULL
               AND o.status NOT IN ('cancelled','failed','refunded','pending_payment')"
        );
        $used->execute(['pid' => $productId, 'key' => $key]);
        $usedQty = (int) $used->fetchColumn();
        $remaining = max(0, (int) $stockSet - $usedQty);

        echo "  => Consumat (după regula site-ului): {$usedQty}  |  RĂMAS: {$remaining}";
        echo $remaining <= 0 ? "  => STOC EPUIZAT pe site\n" : "  => Disponibil pe site\n";
    } catch (Throwable $e) {
        echo "  !! EROARE la interogarea comenzilor: " . $e->getMessage() . "\n";
    }
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "Notă: comenzile de test (chiar și necompletate) consumă stocul dacă nu sunt\n";
echo "în status cancelled/failed/refunded/pending_payment. Anulează-le sau șterge-le\n";
echo "din admin pentru a elibera stocul rezervat de ele.\n";
