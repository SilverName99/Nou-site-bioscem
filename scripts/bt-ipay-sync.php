<?php

declare(strict_types=1);

/**
 * Plasa de siguranță pentru plățile Banca Transilvania iPay.
 *
 * De rulat din cron la 15 minute (linia exactă, cu calea reală de pe server,
 * e afișată în Admin → Setări plăți → Banca Transilvania):
 *   *\/15 * * * * php /cale/catre/site/scripts/bt-ipay-sync.php >/dev/null 2>&1
 *
 * La fiecare trecere:
 *  - verifică la bancă plățile începute și neterminate; după 60 de minute
 *    (setabil) cele neplătite expiră, iar comanda devine eșuată;
 *  - eliberează suma blocată pentru comenzile anulate, returnate, eșuate sau
 *    șterse, plus plățile de test uitate (după 30 de minute);
 *  - reîncearcă încasările cerute (aprobare în ERP, buton) care au eșuat;
 *  - la 72 de ore trimite magazinului lista plăților încă neîncasate;
 *  - la 96 de ore (ziua 4) le încasează singur, inclusiv precomenzile, și
 *    trimite email — banca cere încasarea în cel mult 5 zile;
 *  - lasă o „bătaie de inimă" vizibilă în admin și curăță jurnalul vechi.
 *
 * Rulările nu se suprapun (lacăt MySQL). Cu BT neconfigurat și fără plăți,
 * nu face nimic.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\BtIpayPayments;
use App\Support\Database;
use App\Support\Settings;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));

if (!$db instanceof PDO) {
    fwrite(STDERR, "Nu am putut deschide conexiunea la baza de date.\n");
    exit(1);
}

$settings = Settings::all($db);
$rezultat = BtIpayPayments::cron($db, $settings, static function (string $text): void {
    fwrite(STDERR, $text . "\n");
});

if (($rezultat['ocupat'] ?? false) === true) {
    echo '[' . date('Y-m-d H:i:s') . "] O altă rulare BT e încă în curs; ies.\n";
    exit(0);
}

printf(
    "[%s] BT iPay: verificate %d, expirate %d, eliberate %d, reîncercări încasare %d, reamintiri %d, încasate automat %d, erori %d\n",
    date('Y-m-d H:i:s'),
    (int) ($rezultat['verificate'] ?? 0),
    (int) ($rezultat['expirate'] ?? 0),
    (int) ($rezultat['eliberate'] ?? 0),
    (int) ($rezultat['reincercate'] ?? 0),
    (int) ($rezultat['reamintiri'] ?? 0),
    (int) ($rezultat['incasate_automat'] ?? 0),
    (int) ($rezultat['erori'] ?? 0)
);

exit(((int) ($rezultat['erori'] ?? 0)) > 0 ? 1 : 0);
