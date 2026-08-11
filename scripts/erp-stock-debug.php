<?php

declare(strict_types=1);

/**
 * Diagnostic pentru afișarea stocului din ERP.
 *
 * Arată, pentru unul sau mai multe SKU-uri: ce are site-ul în fișa produsului,
 * ce răspunde ERP-ul și ce ar afișa site-ul în final.
 *
 *   php scripts/erp-stock-debug.php AFA90C MP12/JV21
 *   php scripts/erp-stock-debug.php            # primele 5 produse
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\ErpClient;
use App\Support\ErpStock;
use App\Support\Settings;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));

if (!$db instanceof PDO) {
    fwrite(STDERR, "Nu am putut deschide conexiunea la baza de date.\n");
    exit(1);
}

$settings = Settings::all($db);

echo "== Setări site ==\n";
printf("  erp_enabled        : %s\n", (string) ($settings['erp_enabled'] ?? '0'));
printf("  erp_stock_enabled  : %s\n", (string) ($settings['erp_stock_enabled'] ?? '0'));
printf("  erp_url            : %s\n", (string) ($settings['erp_url'] ?? '(gol)'));
printf("  erp_api_key        : %s\n", trim((string) ($settings['erp_api_key'] ?? '')) !== '' ? 'setată' : '(goală)');
printf("  ErpStock::enabled  : %s\n", ErpStock::enabled($db) ? 'DA' : 'NU');
echo "\n";

$client = ErpClient::fromSettings($settings);
if ($client === null) {
    fwrite(STDERR, "Adresa sau cheia lipsesc — nu pot interoga ERP-ul.\n");
    exit(1);
}

echo "== Ping ERP ==\n";
try {
    $ping = $client->ping();
    printf(
        "  ok=%s, gestiuneConfigurata=%s\n\n",
        ($ping['ok'] ?? false) ? 'da' : 'nu',
        ($ping['gestiuneConfigurata'] ?? false) ? 'DA' : 'NU  <-- fără gestiune, ERP-ul nu poate raporta stoc'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, '  EROARE: ' . $exception->getMessage() . "\n");
    exit(1);
}

// SKU-urile de verificat: din argumente, altfel primele câteva din magazin.
$skus = array_slice($argv, 1);
if ($skus === []) {
    $rows = $db->query(
        "SELECT sku FROM products
         WHERE deleted_at IS NULL AND sku IS NOT NULL AND sku <> ''
         ORDER BY id ASC LIMIT 5"
    )->fetchAll() ?: [];
    $skus = array_map(static fn (array $r): string => (string) $r['sku'], $rows);
}

if ($skus === []) {
    fwrite(STDERR, "Niciun SKU de verificat.\n");
    exit(1);
}

echo "== Răspunsul ERP-ului ==\n";
try {
    $stoc = $client->stockBySku($skus);
} catch (Throwable $exception) {
    fwrite(STDERR, '  EROARE: ' . $exception->getMessage() . "\n");
    exit(1);
}

$placeholders = implode(',', array_fill(0, count($skus), '?'));
$stmt = $db->prepare(
    "SELECT id, sku, name, stock, out_of_stock, is_active
     FROM products WHERE sku IN ($placeholders) AND deleted_at IS NULL"
);
$stmt->execute($skus);
$peSite = [];
foreach (($stmt->fetchAll() ?: []) as $row) {
    $peSite[strtoupper((string) $row['sku'])] = $row;
}

printf(
    "%-14s | %-22s | %-18s | %s\n",
    'SKU',
    'SITE (stoc / epuizat)',
    'ERP (cunoscut/disp.)',
    'AFIȘAT PE SITE'
);
echo str_repeat('-', 92) . "\n";

foreach ($skus as $sku) {
    $key = strtoupper(trim($sku));
    $site = $peSite[$key] ?? null;
    $erp = $stoc[$key] ?? null;

    $siteText = $site === null
        ? 'produs inexistent'
        : sprintf('%d / %s', (int) $site['stock'], ((int) $site['out_of_stock']) === 1 ? 'DA' : 'nu');

    if ($erp === null) {
        $erpText = 'fără răspuns';
        $final = $siteText;
    } elseif (($erp['cunoscut'] ?? false) !== true) {
        $erpText = 'necunoscut în ERP';
        $final = 'rămâne stocul de pe site';
    } else {
        $erpText = sprintf('cunoscut / %.4f', (float) ($erp['disponibil'] ?? 0));
        $final = ($erp['existent'] ?? false) ? 'DISPONIBIL' : 'Stoc epuizat';
    }

    printf("%-14s | %-22s | %-18s | %s\n", $key, $siteText, $erpText, $final);
}

echo "\n";
echo "„necunoscut în ERP\" = SKU-ul nu există în nomenclatorul ERP (sau e scris diferit).\n";
echo "„cunoscut / 0\"      = produsul există, dar nu are stoc în gestiunea aleasă în Setări site.\n";
