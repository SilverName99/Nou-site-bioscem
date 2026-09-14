<?php

declare(strict_types=1);

/**
 * Aduce nomenclatorul de puncte FANbox la zi, din API-ul FAN.
 *
 * Pana acum lista se improspata doar cand apasa cineva butonul din
 * `Admin -> Setari livrare -> FANbox`. Intre doua apasari, un punct inchis de
 * FAN ramane vizibil la checkout, clientul il alege, iar AWB-ul e refuzat abia
 * la expediere, cu „awbGeneration.lockerInactive" — adica dupa ce comanda e
 * deja platita. Cronul asta tine lista aproape de realitate fara sa trebuiasca
 * sa-si aminteasca cineva de ea.
 *
 * Punctele existente se actualizeaza pe loc, nu se sterg si se re-creeaza, deci
 * comenzile vechi isi pastreaza punctul si AWB-ul se poate reemite.
 *
 * Cron recomandat: o data pe zi (lista se schimba rar).
 *   php /home/USER/public_html/scripts/fan-lockers-sync.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\AdminActivityLog;
use App\Support\Database;
use App\Support\FanCourierGateway;
use App\Support\FanLockers;
use App\Support\Settings;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibila.\n");
    exit(1);
}

$settings = Settings::all($db);
$clientId = (int) ($settings['fan_client_id'] ?? 0);
$username = trim((string) ($settings['fan_api_username'] ?? ''));
$password = trim((string) ($settings['fan_api_password'] ?? ''));

if ($clientId <= 0 || $username === '' || $password === '') {
    fwrite(STDERR, "Setarile FAN API sunt incomplete (client_id/username/parola).\n");
    exit(1);
}

$credentials = [
    'client_id' => $clientId,
    'username' => $username,
    'password' => $password,
];

try {
    $puncte = FanCourierGateway::pickupPoints($credentials, 'fanbox');
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'FAN nu a returnat lista de puncte: ' . $exception->getMessage() . "\n");
    exit(1);
}

// O lista goala nu inseamna „FAN nu mai are lockere", ci ca ceva e in neregula
// cu contul sau cu serviciul. Daca am merge mai departe, am dezactiva tot
// nomenclatorul si magazinul ar ramane fara livrare in FANbox.
if ($puncte === []) {
    fwrite(STDERR, "FAN a returnat o lista goala de puncte FANbox. Nu s-a modificat nimic.\n");
    exit(1);
}

$rezultat = FanLockers::sincronizeazaDinApi($db, $puncte);

try {
    AdminActivityLog::log($db, 'fan_lockers_sync', [
        'sursa' => 'cron',
        'importate' => $rezultat['importate'],
        'adaugate' => $rezultat['adaugate'],
        'actualizate' => $rezultat['actualizate'],
        'dezactivate' => $rezultat['dezactivate'],
    ]);
} catch (Throwable) {
}

echo 'Puncte FANbox sincronizate: ' . $rezultat['importate']
    . ' (' . $rezultat['adaugate'] . ' noi, ' . $rezultat['actualizate'] . ' actualizate, '
    . $rezultat['dezactivate'] . " dezactivate).\n";
