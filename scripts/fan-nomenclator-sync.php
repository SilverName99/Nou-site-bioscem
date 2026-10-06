<?php

declare(strict_types=1);

/**
 * Aduce din API-ul FAN nomenclatorul de localitati (cu lista de localitati cu
 * km suplimentari) si pe cel de strazi.
 *
 * Inainte listele intrau doar din fisiere incarcate de mana, iar lista de km
 * suplimentari ajunsese sa contina toata tara: aproape orice comanda primea
 * taxa. Acum lista de km e exact ce are FAN cu `exteriorKm > 0`.
 *
 * Fiecare lista se aduna intai intr-o tabela-ciorna si ia locul celei vechi
 * abia cand a venit toata. Daca FAN pica la jumatate sau intoarce gol, site-ul
 * ramane pe lista veche, iar eroarea apare pe tab-ul listei din
 * `Admin -> Setari livrare`. O sincronizare pornita din admin si lasata la
 * jumatate e dusa la capat de aici.
 *
 * Cron recomandat: zilnic, noaptea.
 *   15 1 * * *  php /home/USER/public_html/scripts/fan-nomenclator-sync.php
 *
 * Optiuni: --lista=localitati | --lista=strazi (implicit amandoua).
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\AdminActivityLog;
use App\Support\Database;
use App\Support\FanNomenclator;
use App\Support\ResponseCache;
use App\Support\Settings;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibila.\n");
    exit(1);
}

$credentials = FanNomenclator::credentialeDinSetari(Settings::all($db));
if ($credentials === null) {
    fwrite(STDERR, "Setarile FAN API sunt incomplete (client_id/username/parola).\n");
    exit(1);
}

$liste = [FanNomenclator::LOCALITATI, FanNomenclator::STRAZI];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--lista=(localitati|strazi)$/', (string) $arg, $m) === 1) {
        $liste = [$m[1]];
    }
}

@set_time_limit(0);
$cod = 0;
$schimbate = 0;
foreach ($liste as $lista) {
    $start = microtime(true);
    $rezultat = FanNomenclator::ruleaza($db, $credentials, $lista, 'cron');
    $durata = round(microtime(true) - $start, 1);

    if ($rezultat['stare'] === 'gata') {
        try {
            AdminActivityLog::log($db, 'fan_nomenclator_sync', [
                'sursa' => 'cron',
                'lista' => $lista,
                'randuri' => $rezultat['randuri'],
                'mesaj' => $rezultat['mesaj'],
            ]);
        } catch (Throwable) {
        }
        echo $rezultat['mesaj'] . ' (' . $durata . " s)\n";
        $schimbate++;
        continue;
    }

    // „ocupat" = o alta rulare lucreaza chiar acum pe aceeasi lista; nu e o
    // eroare, ea o va termina.
    if ($rezultat['stare'] === 'ocupat') {
        echo $rezultat['mesaj'] . "\n";
        continue;
    }

    fwrite(STDERR, 'Lista ' . $lista . ' nu s-a sincronizat: ' . $rezultat['mesaj'] . "\n");
    $cod = 1;
}

// Paginile din cache pot avea inca judetele si localitatile vechi.
if ($schimbate > 0) {
    try {
        ResponseCache::purgePageCache();
    } catch (Throwable) {
    }
}

exit($cod);
