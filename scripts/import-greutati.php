<?php

declare(strict_types=1);

/**
 * Pune greutatea produselor dintr-un fișier CSV.
 *
 * Greutatea de pe fiecare produs e singura sursă a kilogramelor de pe AWB: FAN
 * recântărește coletul la depozit și taxează diferența, iar fără gramaje toate
 * coletele plecau declarate cu greutatea implicită din setări.
 *
 * Fișierul așteptat e chiar exportul din Admin → Produse („⭳ Export produse"),
 * completat la coloana `greutate_g`. Merge și un fișier simplu, cu două coloane
 * (cod produs și greutate) — coloanele se recunosc după antet:
 *
 *   sku / cod / cod_produs        → codul produsului
 *   id_site / id                  → id-ul din site (alternativă la cod)
 *   greutate_g / greutate / grame → greutatea în grame
 *   greutate_kg / kg             → greutatea în kilograme (se înmulțește cu 1000)
 *
 * Rulare:
 *   php scripts/import-greutati.php produse.csv
 *   php scripts/import-greutati.php produse.csv --aplica
 *
 * Fără `--aplica` doar raportează ce s-ar schimba. Rândurile fără greutate se
 * sar (nu se șterge un gramaj existent scriind 0 peste el), iar codurile care
 * nu se găsesc se listează la final, ca să se vadă ce a rămas pe dinafară.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));

if (!$db instanceof PDO) {
    fwrite(STDERR, "Nu am putut deschide conexiunea la baza de date.\n");
    exit(1);
}

$argumente = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $a): bool => !str_starts_with($a, '--')
));
$aplica = in_array('--aplica', $argv, true);
$fisier = (string) ($argumente[0] ?? '');

if ($fisier === '' || !is_file($fisier)) {
    fwrite(STDERR, "Dă calea către fișierul CSV: php scripts/import-greutati.php produse.csv [--aplica]\n");
    exit(1);
}

/** Textul unui antet, adus la o formă comparabilă. */
$normalizeaza = static function (string $valoare): string {
    $valoare = mb_strtolower(trim($valoare));
    $valoare = strtr($valoare, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', $valoare), '_');
};

$handle = fopen($fisier, 'r');
if ($handle === false) {
    fwrite(STDERR, "Nu am putut deschide fișierul.\n");
    exit(1);
}

// Excel salvează CSV-urile cu „;" în setările românești; ghicim separatorul
// după prima linie, altfel tot fișierul ar fi o singură coloană.
$primaLinie = (string) fgets($handle);
$primaLinie = preg_replace('/^\xEF\xBB\xBF/', '', $primaLinie) ?? $primaLinie;
$separator = substr_count($primaLinie, ';') > substr_count($primaLinie, ',') ? ';' : ',';
rewind($handle);

$antet = fgetcsv($handle, 0, $separator);
if (!is_array($antet)) {
    fwrite(STDERR, "Fișierul e gol.\n");
    exit(1);
}
$antet[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $antet[0]) ?? (string) $antet[0];

$coloane = [];
foreach ($antet as $index => $nume) {
    $coloane[$normalizeaza((string) $nume)] = $index;
}
$indexDupa = static function (array $variante) use ($coloane): ?int {
    foreach ($variante as $v) {
        if (array_key_exists($v, $coloane)) {
            return (int) $coloane[$v];
        }
    }
    return null;
};

$colSku = $indexDupa(['sku', 'cod', 'cod_produs', 'cod_articol']);
$colId = $indexDupa(['id_site', 'id', 'id_produs']);
$colGrame = $indexDupa(['greutate_g', 'greutate', 'grame', 'greutate_grame', 'gramaj']);
$colKg = $indexDupa(['greutate_kg', 'kg', 'greutate_kilograme']);

if ($colSku === null && $colId === null) {
    fwrite(STDERR, "Nu găsesc coloana cu codul produsului (sku) sau cu id-ul din site.\n");
    exit(1);
}
if ($colGrame === null && $colKg === null) {
    fwrite(STDERR, "Nu găsesc coloana cu greutatea (greutate_g sau greutate_kg).\n");
    exit(1);
}

$dupaSku = $db->prepare('SELECT id, name, weight_grams FROM products WHERE sku = :sku AND deleted_at IS NULL LIMIT 1');
$dupaId = $db->prepare('SELECT id, name, weight_grams FROM products WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$scrie = $db->prepare('UPDATE products SET weight_grams = :greutate WHERE id = :id');

$randuri = 0;
$modificate = 0;
$neschimbate = 0;
$faraGreutate = 0;
$negasite = [];
$exemple = [];

while (($rand = fgetcsv($handle, 0, $separator)) !== false) {
    if ($rand === [null] || $rand === []) {
        continue;
    }
    $randuri++;

    $sku = $colSku !== null ? trim((string) ($rand[$colSku] ?? '')) : '';
    $idSite = $colId !== null ? (int) trim((string) ($rand[$colId] ?? '')) : 0;

    $brut = $colGrame !== null ? (string) ($rand[$colGrame] ?? '') : (string) ($rand[$colKg] ?? '');
    $brut = trim(str_replace([' ', ','], ['', '.'], $brut));
    // „1.4 kg", „350g" — se ia doar numărul, unitatea o dă coloana.
    $brut = (string) preg_replace('/[^0-9.]/', '', $brut);
    if ($brut === '' || !is_numeric($brut)) {
        $faraGreutate++;
        continue;
    }
    $grame = $colGrame !== null ? (int) round((float) $brut) : (int) round(((float) $brut) * 1000);
    if ($grame <= 0) {
        $faraGreutate++;
        continue;
    }

    $produs = null;
    if ($sku !== '') {
        $dupaSku->execute(['sku' => $sku]);
        $produs = $dupaSku->fetch() ?: null;
    }
    if (!is_array($produs) && $idSite > 0) {
        $dupaId->execute(['id' => $idSite]);
        $produs = $dupaId->fetch() ?: null;
    }
    if (!is_array($produs)) {
        $negasite[] = $sku !== '' ? $sku : ('id ' . $idSite);
        continue;
    }

    if ((int) ($produs['weight_grams'] ?? 0) === $grame) {
        $neschimbate++;
        continue;
    }

    if (count($exemple) < 10) {
        $exemple[] = sprintf(
            '  %s: %s g → %d g',
            (string) ($produs['name'] ?? $sku),
            (int) ($produs['weight_grams'] ?? 0) > 0 ? (string) (int) $produs['weight_grams'] : '—',
            $grame
        );
    }

    if ($aplica) {
        $scrie->execute(['greutate' => $grame, 'id' => (int) $produs['id']]);
    }
    $modificate++;
}
fclose($handle);

printf("Rânduri citite: %d\n", $randuri);
printf("%s: %d\n", $aplica ? 'Produse actualizate' : 'Produse de actualizat', $modificate);
if ($neschimbate > 0) {
    printf("Aveau deja greutatea corectă: %d\n", $neschimbate);
}
if ($faraGreutate > 0) {
    printf("Rânduri fără greutate completată (sărite): %d\n", $faraGreutate);
}
if ($exemple !== []) {
    echo "\nExemple:\n" . implode("\n", $exemple) . "\n";
}
if ($negasite !== []) {
    printf("\nNegăsite în magazin (%d): %s\n", count($negasite), implode(', ', array_slice($negasite, 0, 30)));
    if (count($negasite) > 30) {
        echo "  … și încă " . (count($negasite) - 30) . "\n";
    }
}
if (!$aplica) {
    echo "\nRaport. Nu s-a scris nimic. Adaugă --aplica pentru a salva greutățile.\n";
}
