<?php

declare(strict_types=1);

/**
 * Înlocuiește domeniul vechi cu cel nou în toate coloanele de text ale bazei.
 *
 * După mutarea domeniului, codul ia adresa din APP_URL, dar conținutul scris
 * în editor (pagini, design, șabloane de email, descrieri) poate conține
 * legături absolute către domeniul temporar. Scriptul le găsește și le
 * înlocuiește, tratând și variantele http/https și cu/fără „www.".
 *
 * Rulare (întâi în gol, ca să vezi ce s-ar schimba):
 *   php scripts/domain-replace.php vechi.tld bioscem.ro
 *   php scripts/domain-replace.php vechi.tld bioscem.ro --apply
 *
 * Fă întâi o copie a bazei de date.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$argumente = array_slice($argv, 1);
$aplica = in_array('--apply', $argumente, true);
$argumente = array_values(array_filter($argumente, static fn (string $a): bool => $a !== '--apply'));

if (count($argumente) < 2) {
    fwrite(STDERR, "Folosire: php scripts/domain-replace.php <domeniu-vechi> <domeniu-nou> [--apply]\n");
    exit(1);
}

$vechi = normalizeazaDomeniu($argumente[0]);
$nou = normalizeazaDomeniu($argumente[1]);
if ($vechi === '' || $nou === '' || $vechi === $nou) {
    fwrite(STDERR, "Domenii invalide sau identice.\n");
    exit(1);
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);
if (!$db instanceof PDO) {
    fwrite(STDERR, "Nu mă pot conecta la baza de date.\n");
    exit(1);
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Perechile de căutat, de la cea mai specifică la cea mai generală. Ordinea
 * contează: întâi formele complete cu protocol, ca să nu rămână „https://"
 * lipit de un domeniu deja înlocuit.
 */
$inlocuiri = [];
foreach (['https://www.', 'https://', 'http://www.', 'http://'] as $prefix) {
    $inlocuiri[] = [$prefix . $vechi, 'https://' . $nou];
}
$inlocuiri[] = ['//' . $vechi, '//' . $nou];
$inlocuiri[] = [$vechi, $nou];

$coloane = $db->query(
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND DATA_TYPE IN ('char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext')
     ORDER BY TABLE_NAME, ORDINAL_POSITION"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo ($aplica ? "ÎNLOCUIESC" : "SIMULARE (nimic nu se modifică)") . ": {$vechi} → {$nou}\n";
echo str_repeat('-', 60) . "\n";

$totalRanduri = 0;
$totalColoane = 0;

foreach ($coloane as $rand) {
    $tabel = (string) $rand['t'];
    $coloana = (string) $rand['c'];
    $tabelSql = '`' . str_replace('`', '``', $tabel) . '`';
    $coloanaSql = '`' . str_replace('`', '``', $coloana) . '`';

    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM {$tabelSql} WHERE {$coloanaSql} LIKE :cauta"
        );
        $stmt->execute(['cauta' => '%' . $vechi . '%']);
        $gasite = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // Vederi sau tabele fără drepturi de citire: le sărim.
        continue;
    }

    if ($gasite === 0) {
        continue;
    }

    $totalColoane++;
    $totalRanduri += $gasite;
    printf("%-28s %-28s %5d rânduri\n", $tabel, $coloana, $gasite);

    if (!$aplica) {
        continue;
    }

    try {
        $expresie = $coloanaSql;
        $parametri = [];
        foreach ($inlocuiri as $i => [$de_la, $la]) {
            $expresie = "REPLACE({$expresie}, :de_la{$i}, :la{$i})";
            $parametri["de_la{$i}"] = $de_la;
            $parametri["la{$i}"] = $la;
        }
        $update = $db->prepare(
            "UPDATE {$tabelSql} SET {$coloanaSql} = {$expresie} WHERE {$coloanaSql} LIKE :cauta"
        );
        $update->execute($parametri + ['cauta' => '%' . $vechi . '%']);
    } catch (Throwable $e) {
        fwrite(STDERR, "  ! {$tabel}.{$coloana}: " . $e->getMessage() . "\n");
    }
}

echo str_repeat('-', 60) . "\n";
if ($totalColoane === 0) {
    echo "Domeniul vechi nu apare nicăieri în baza de date. Nu e nimic de făcut.\n";
    exit(0);
}

printf("%d coloane, %d rânduri care conțin domeniul vechi.\n", $totalColoane, $totalRanduri);
if ($aplica) {
    echo "Înlocuire terminată. Golește cache-ul: rm -rf storage/cache/*\n";
} else {
    echo "Rulează din nou cu --apply ca să se scrie efectiv (după ce faci o copie a bazei).\n";
}

function normalizeazaDomeniu(string $valoare): string
{
    $valoare = trim($valoare);
    $valoare = (string) preg_replace('#^https?://#i', '', $valoare);
    $valoare = (string) preg_replace('#^www\.#i', '', $valoare);
    return rtrim(strtolower($valoare), '/');
}
