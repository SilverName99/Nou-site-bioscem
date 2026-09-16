<?php

declare(strict_types=1);

/**
 * Ce numar de telefon pleaca spre FAN pentru o comanda, si de ce il refuza.
 *
 * FAN raspunde doar „phoneInvalid", fara sa spuna ce anume nu-i place. Aici se
 * vede tot: ce a scris clientul, caracter cu caracter, ce iese dupa curatare
 * si daca forma rezultata e una pe care FAN o accepta.
 *
 * Rulare:
 *   php scripts/verifica-telefon-comanda.php 2760      # id intern
 *   php scripts/verifica-telefon-comanda.php BIO-2760  # numar comanda
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\FanCourierGateway;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db'] ?? []);
if (!$db instanceof PDO) {
    fwrite(STDERR, "Nu ma pot conecta la baza de date.\n");
    exit(1);
}

$cautare = trim((string) ($argv[1] ?? ''));
if ($cautare === '') {
    fwrite(STDERR, "Da-mi id-ul sau numarul comenzii.\n");
    exit(1);
}

$stmt = $db->prepare(
    'SELECT id, order_number, billing_first_name, billing_last_name,
            billing_phone, shipping_phone, shipping_same_as_billing,
            fan_locker_id, fan_awb
       FROM orders
      WHERE (order_number = :nr OR id = :id) AND deleted_at IS NULL
      LIMIT 1'
);
$stmt->execute(['nr' => $cautare, 'id' => ctype_digit($cautare) ? (int) $cautare : 0]);
$comanda = $stmt->fetch();
if (!is_array($comanda)) {
    fwrite(STDERR, "Nu am gasit comanda „{$cautare}”.\n");
    exit(1);
}

/** Fiecare caracter, cu codul lui — asa se vad spatiile ascunse. */
$peCaractere = static function (string $text): string {
    if ($text === '') {
        return '(gol)';
    }
    $out = [];
    $n = mb_strlen($text);
    for ($i = 0; $i < $n; $i++) {
        $c = mb_substr($text, $i, 1);
        $cod = mb_ord($c) ?: 0;
        $out[] = ($c === ' ' ? '␣' : $c) . '(' . $cod . ')';
    }
    return implode(' ', $out);
};

$arata = static function (string $eticheta, string $brut) use ($peCaractere): void {
    $curat = FanCourierGateway::normalizeazaTelefon($brut);
    $valid = FanCourierGateway::telefonValidPentruFan($brut);
    $cifre = preg_replace('/\D+/', '', $brut) ?? '';
    echo "\n  {$eticheta}\n";
    echo '    scris de client : ' . ($brut === '' ? '(gol)' : '„' . $brut . '"') . "\n";
    echo '    caracter cu caracter: ' . $peCaractere($brut) . "\n";
    echo '    doar cifrele    : ' . ($cifre === '' ? '(niciuna)' : $cifre) . ' (' . strlen($cifre) . " cifre)\n";
    echo '    pleaca spre FAN : ' . ($curat === '' ? '(gol)' : '„' . $curat . '"') . "\n";
    echo '    acceptat de FAN : ' . ($valid ? 'DA' : 'NU') . "\n";
    if (!$valid) {
        if ($cifre === '') {
            echo "    → nu e niciun numar acolo.\n";
        } elseif (strlen($cifre) !== 10) {
            echo '    → are ' . strlen($cifre) . " cifre; FAN vrea exact 10 (07xxxxxxxx sau 02/03xxxxxxxx).\n";
        } else {
            echo "    → are 10 cifre, dar nu incepe cu 07, 02 sau 03.\n";
        }
    }
};

$nume = trim((string) ($comanda['billing_first_name'] ?? '') . ' ' . (string) ($comanda['billing_last_name'] ?? ''));
echo "Comanda #{$comanda['id']} / {$comanda['order_number']} — {$nume}\n";
if (trim((string) ($comanda['fan_locker_id'] ?? '')) !== '') {
    echo "  Livrare la FANbox: {$comanda['fan_locker_id']}\n";
}
if (trim((string) ($comanda['fan_awb'] ?? '')) !== '') {
    echo "  AWB existent: {$comanda['fan_awb']}\n";
}

$arata('Telefon facturare (billing_phone)', (string) ($comanda['billing_phone'] ?? ''));
$arata('Telefon livrare (shipping_phone)', (string) ($comanda['shipping_phone'] ?? ''));

// Care dintre ele pleaca de fapt: acelasi drum ca la emiterea AWB-ului.
$aceeasiAdresa = (int) ($comanda['shipping_same_as_billing'] ?? 1) === 1;
$ales = !$aceeasiAdresa && trim((string) ($comanda['shipping_phone'] ?? '')) !== ''
    ? (string) $comanda['shipping_phone']
    : (string) ($comanda['billing_phone'] ?? '');
echo "\n  Pe AWB pleaca: " . ($ales === '' ? '(gol)' : '„' . FanCourierGateway::normalizeazaTelefon($ales) . '"') . "\n";
echo '  ' . (FanCourierGateway::telefonValidPentruFan($ales)
    ? "Forma e buna. Daca FAN tot refuza, numarul exista dar nu e alocat — cere-i clientului altul."
    : "Asta e problema. Corecteaza telefonul pe comanda si reemite AWB-ul.") . "\n";
