<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Nomenclatorul de puncte FANbox (lockere).
 *
 * Lista nu vine din API-ul FAN: interfața lor de curierat nu expune un
 * endpoint public cu lockerele, așa că punctele se importă dintr-un fișier
 * primit de la FAN și se țin local. Avantajul e că checkout-ul nu depinde de
 * disponibilitatea unui serviciu extern în momentul comenzii.
 */
final class FanLockers
{
    public static function ensureSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS fan_lockers (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(60) NOT NULL,
                    name VARCHAR(190) NOT NULL,
                    county VARCHAR(120) NOT NULL,
                    locality VARCHAR(190) NOT NULL,
                    address VARCHAR(255) NOT NULL DEFAULT "",
                    postcode VARCHAR(20) NOT NULL DEFAULT "",
                    lat DECIMAL(10, 7) DEFAULT NULL,
                    lng DECIMAL(10, 7) DEFAULT NULL,
                    county_norm VARCHAR(120) NOT NULL,
                    locality_norm VARCHAR(190) NOT NULL,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_fan_lockers_code (code),
                    KEY idx_fan_lockers_county (county_norm),
                    KEY idx_fan_lockers_locality (county_norm, locality_norm)
                )'
            );
        } catch (Throwable) {
        }

        // CREATE TABLE IF NOT EXISTS nu atinge o tabelă deja creată, așa că
        // fiecare coloană adăugată ulterior are nevoie de propriul ALTER.
        // Fără ele, interogările cedau în tăcere și lista de puncte ieșea
        // goală, deși nomenclatorul avea mii de rânduri.
        foreach ([
            'ALTER TABLE fan_lockers ADD COLUMN postcode VARCHAR(20) NOT NULL DEFAULT "" AFTER address',
            'ALTER TABLE fan_lockers ADD COLUMN lat DECIMAL(10, 7) DEFAULT NULL AFTER postcode',
            'ALTER TABLE fan_lockers ADD COLUMN lng DECIMAL(10, 7) DEFAULT NULL AFTER lat',
            // Id-ul punctului la FAN. Separat de `code` pentru că `code` e doar
            // cheia noastră de deduplicare la import, în timp ce AICI trebuie să
            // ajungă exact numărul din nomenclatorul FAN: doar el e acceptat la
            // emiterea AWB-ului. Gol = punctul n-a fost sincronizat din API.
            'ALTER TABLE fan_lockers ADD COLUMN fan_id VARCHAR(60) NOT NULL DEFAULT "" AFTER code',
        ] as $sql) {
            try {
                $db->exec($sql);
            } catch (Throwable) {
                // coloana există deja
            }
        }
    }

    /** Coordonată validă sau null (fișierul FAN le dă ca text). */
    private static function coordonata(mixed $value): ?float
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '' || !is_numeric($text)) {
            return null;
        }
        $numar = (float) $text;
        return ($numar >= -180.0 && $numar <= 180.0 && abs($numar) > 0.0001) ? $numar : null;
    }

    /** Text comparabil: fără diacritice, fără prefixe, litere mici. */
    public static function normalizeaza(string $value): string
    {
        $text = trim(mb_strtolower($value, 'UTF-8'));
        $harta = [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's',
            'ț' => 't', 'ţ' => 't', 'á' => 'a', 'é' => 'e', 'í' => 'i',
            'ó' => 'o', 'ú' => 'u',
        ];
        $text = strtr($text, $harta);
        $text = preg_replace('/^(jud\.?|judetul|județul|mun\.?|municipiul|oras(ul)?|orașul|com\.?|comuna|sat(ul)?)\s+/u', '', $text) ?? $text;
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /** Câte puncte sunt în nomenclator. */
    public static function numar(?PDO $db): int
    {
        if (!$db instanceof PDO) {
            return 0;
        }
        try {
            self::ensureSchema($db);
            return (int) ($db->query('SELECT COUNT(*) FROM fan_lockers WHERE active = 1')->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Câte puncte au coordonate. Diagnostic: dacă e 0 după un import, fișierul
     * n-avea coloanele de latitudine/longitudine sau coloanele din baza de date
     * lipseau la momentul importului.
     */
    public static function numarCuCoordonate(?PDO $db): int
    {
        if (!$db instanceof PDO) {
            return 0;
        }
        try {
            self::ensureSchema($db);
            return (int) ($db->query(
                'SELECT COUNT(*) FROM fan_lockers WHERE active = 1 AND lat IS NOT NULL AND lng IS NOT NULL'
            )->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Judeţele din nomenclator, cu numărul de puncte. Diagnostic pentru
     * „niciun punct în acest judeţ": arată exact cum sunt scrise judeţele.
     *
     * @return array<string,int>
     */
    public static function peJudete(?PDO $db): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        try {
            self::ensureSchema($db);
            $randuri = $db->query(
                'SELECT county, COUNT(*) AS n FROM fan_lockers WHERE active = 1 GROUP BY county ORDER BY county'
            )->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($randuri as $r) {
            $out[(string) ($r['county'] ?? '')] = (int) ($r['n'] ?? 0);
        }
        return $out;
    }

    /**
     * Punctele dintr-un județ (și, opțional, dintr-o localitate). Fără județ
     * întoarce lista goală: nu are sens să încărcăm toată țara în checkout.
     *
     * @return list<array{id:int,code:string,name:string,county:string,locality:string,address:string}>
     */
    public static function pentruJudet(?PDO $db, string $judet, string $localitate = ''): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        $judetNorm = self::normalizeaza($judet);
        if ($judetNorm === '') {
            return [];
        }
        try {
            self::ensureSchema($db);
            $sql = 'SELECT id, code, name, county, locality, address, postcode, lat, lng
                    FROM fan_lockers
                    WHERE active = 1 AND county_norm = :judet';
            $params = ['judet' => $judetNorm];
            // Localitatea nu filtrează, ci doar ordonează: punctele din
            // localitatea clientului apar primele, dar rămân vizibile și
            // celelalte din județ. Altfel, un oraș cu un singur locker
            // ascundea toate alternativele din apropiere.
            $localitateNorm = self::normalizeaza($localitate);
            if ($localitateNorm !== '') {
                $sql .= ' ORDER BY (locality_norm = :localitate) DESC, locality, name';
                $params['localitate'] = $localitateNorm;
            } else {
                $sql .= ' ORDER BY locality, name';
            }
            $sql .= ' LIMIT 2000';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $randuri = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($randuri as $r) {
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'county' => (string) ($r['county'] ?? ''),
                'locality' => (string) ($r['locality'] ?? ''),
                'address' => (string) ($r['address'] ?? ''),
                'postcode' => (string) ($r['postcode'] ?? ''),
                'lat' => $r['lat'] !== null ? (float) $r['lat'] : null,
                'lng' => $r['lng'] !== null ? (float) $r['lng'] : null,
            ];
        }

        return $out;
    }

    /**
     * Un punct după id — folosit la plasarea comenzii, ca să înghețăm datele
     * lockerului pe comandă, nu doar un id care s-ar putea schimba la un
     * import ulterior.
     *
     * @return array{id:int,code:string,name:string,county:string,locality:string,address:string}|null
     */
    public static function dupaId(?PDO $db, int $id): ?array
    {
        if (!$db instanceof PDO || $id <= 0) {
            return null;
        }
        try {
            self::ensureSchema($db);
            $stmt = $db->prepare(
                'SELECT id, code, fan_id, name, county, locality, address, postcode, lat, lng
                 FROM fan_lockers
                 WHERE id = :id AND active = 1
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $r = $stmt->fetch();
        } catch (Throwable) {
            return null;
        }
        if (!is_array($r)) {
            return null;
        }

        return [
            'id' => (int) ($r['id'] ?? 0),
            'code' => (string) ($r['code'] ?? ''),
            'fan_id' => (string) ($r['fan_id'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
            'county' => (string) ($r['county'] ?? ''),
            'locality' => (string) ($r['locality'] ?? ''),
            'address' => (string) ($r['address'] ?? ''),
            'postcode' => (string) ($r['postcode'] ?? ''),
            'lat' => $r['lat'] !== null ? (float) $r['lat'] : null,
            'lng' => $r['lng'] !== null ? (float) $r['lng'] : null,
        ];
    }

    /**
     * Antetele acceptate pentru fiecare câmp, în variantele în care le trimite
     * FAN sau în care le poate salva cineva din Excel.
     */
    private const ANTETE = [
        'code' => ['cod', 'code', 'cod_fanbox', 'cod_punct', 'id_punct', 'referinta'],
        'name' => ['denumire', 'nume', 'name', 'punct', 'fanbox', 'denumire_punct'],
        'county' => ['judet', 'county'],
        'locality' => ['localitate', 'oras', 'city', 'locality'],
        'address' => ['adresa', 'address'],
        'street' => ['strada', 'street'],
        'street_no' => ['numar', 'nr', 'number'],
        'postcode' => ['cod_postal', 'codpostal', 'postcode', 'zip'],
        'lat' => ['latitudine', 'latitude', 'lat'],
        'lng' => ['longitudine', 'longitude', 'lng', 'lon'],
    ];

    /** Antet normalizat: litere mici, fără diacritice, separatorii → „_". */
    private static function cheieAntet(string $value): string
    {
        $text = self::normalizeaza($value);
        return str_replace(' ', '_', $text);
    }

    /**
     * Citește punctele dintr-un CSV sau XLSX. Coloanele se recunosc după
     * antet, în orice ordine; lipsa antetului obligatoriu oprește importul.
     *
     * @return list<array{code:string,name:string,county:string,locality:string,address:string}>
     */
    public static function randuriDinFisier(string $path, string $ext): array
    {
        $brut = $ext === 'csv' ? self::randuriCsv($path) : self::randuriXlsx($path);
        if ($brut === []) {
            return [];
        }

        $antet = array_shift($brut);
        $pozitii = [];
        foreach ($antet as $i => $celula) {
            $cheie = self::cheieAntet((string) $celula);
            foreach (self::ANTETE as $camp => $variante) {
                if (in_array($cheie, $variante, true) && !isset($pozitii[$camp])) {
                    $pozitii[$camp] = $i;
                }
            }
        }
        // Fără județ și localitate nu putem filtra punctele în checkout.
        if (!isset($pozitii['county']) || !isset($pozitii['locality'])) {
            return [];
        }

        $out = [];
        foreach ($brut as $linie) {
            $ia = static function (string $camp) use ($linie, $pozitii): string {
                $i = $pozitii[$camp] ?? null;
                return $i === null ? '' : trim((string) ($linie[$i] ?? ''));
            };
            $judet = $ia('county');
            $localitate = $ia('locality');
            if ($judet === '' || $localitate === '') {
                continue;
            }
            $denumire = $ia('name');
            // Exportul FAN dă strada și numărul separat; adresa completă e ce
            // ajunge pe AWB, deci o compunem aici.
            $adresa = $ia('address');
            if ($adresa === '') {
                $adresa = trim($ia('street') . ' ' . $ia('street_no'));
            }
            $cod = $ia('code');
            // Fișierul FAN n-are cod de punct. Îl derivăm din datele care
            // identifică lockerul: același punct dă mereu același cod, deci
            // un import repetat actualizează, nu dublează.
            if ($cod === '') {
                $seminte = self::normalizeaza($judet) . '|' . self::normalizeaza($localitate)
                    . '|' . self::normalizeaza($denumire) . '|' . self::normalizeaza($adresa);
                $cod = mb_substr(self::normalizeaza($denumire), 0, 40) . '-' . substr(md5($seminte), 0, 8);
            }
            $out[] = [
                'code' => $cod,
                'name' => $denumire,
                'county' => $judet,
                'locality' => $localitate,
                'address' => $adresa,
                'postcode' => $ia('postcode'),
                'lat' => $ia('lat'),
                'lng' => $ia('lng'),
            ];
        }

        return $out;
    }

    /** @return list<list<string>> */
    private static function randuriCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        // Separatorul se ghicește din prima linie: FAN trimite și „;".
        $prima = (string) (fgets($handle) ?: '');
        rewind($handle);
        $sep = substr_count($prima, ';') > substr_count($prima, ',') ? ';' : ',';

        $randuri = [];
        while (($linie = fgetcsv($handle, 0, $sep)) !== false) {
            if (!is_array($linie)) {
                continue;
            }
            $curatat = array_map(static fn ($c) => trim((string) $c), $linie);
            if (implode('', $curatat) === '') {
                continue;
            }
            $randuri[] = $curatat;
        }
        fclose($handle);

        return $randuri;
    }

    /** @return list<list<string>> */
    private static function randuriXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        $texte = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    $parti = [];
                    if (isset($si->t)) {
                        $parti[] = (string) $si->t;
                    }
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $parti[] = (string) ($r->t ?? '');
                        }
                    }
                    $texte[] = trim(implode('', $parti));
                }
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!is_string($sheetXml) || $sheetXml === '') {
            return [];
        }
        $sx = @simplexml_load_string($sheetXml);
        if ($sx === false || !isset($sx->sheetData->row)) {
            return [];
        }

        $randuri = [];
        foreach ($sx->sheetData->row as $row) {
            $peColoane = [];
            foreach ($row->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $col = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?: '';
                if ($col === '') {
                    continue;
                }
                $tip = (string) ($cell['t'] ?? '');
                if ($tip === 's') {
                    $valoare = (string) ($texte[(int) ($cell->v ?? 0)] ?? '');
                } elseif (isset($cell->is->t)) {
                    $valoare = (string) $cell->is->t;
                } else {
                    $valoare = (string) ($cell->v ?? '');
                }
                $peColoane[self::indexColoana($col)] = trim($valoare);
            }
            if ($peColoane === []) {
                continue;
            }
            $max = max(array_keys($peColoane));
            $linie = [];
            for ($i = 0; $i <= $max; $i++) {
                $linie[] = (string) ($peColoane[$i] ?? '');
            }
            if (implode('', $linie) !== '') {
                $randuri[] = $linie;
            }
        }

        return $randuri;
    }

    /** „A" → 0, „B" → 1, „AA" → 26. */
    private static function indexColoana(string $litere): int
    {
        $index = 0;
        foreach (str_split($litere) as $litera) {
            $index = $index * 26 + (ord($litera) - 64);
        }

        return $index - 1;
    }

    /**
     * Scrie în nomenclator rândurile citite dintr-un fișier. Punctele care nu
     * mai apar în fișier se dezactivează, nu se șterg: o comandă veche trebuie
     * să rămână explicabilă.
     *
     * @param list<array{code:string,name:string,county:string,locality:string,address:string}> $randuri
     * @return array{importate:int,dezactivate:int}
     */
    public static function inlocuieste(PDO $db, array $randuri): array
    {
        self::ensureSchema($db);
        $acum = date('Y-m-d H:i:s');
        $coduri = [];

        $stmt = $db->prepare(
            'INSERT INTO fan_lockers
                (code, name, county, locality, address, postcode, lat, lng, county_norm, locality_norm, active, created_at, updated_at)
             VALUES (:code, :name, :county, :locality, :address, :postcode, :lat, :lng, :county_norm, :locality_norm, 1, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                county = VALUES(county),
                locality = VALUES(locality),
                address = VALUES(address),
                postcode = VALUES(postcode),
                lat = VALUES(lat),
                lng = VALUES(lng),
                county_norm = VALUES(county_norm),
                locality_norm = VALUES(locality_norm),
                active = 1,
                updated_at = VALUES(updated_at)'
        );

        $importate = 0;
        foreach ($randuri as $r) {
            $code = trim((string) ($r['code'] ?? ''));
            $county = trim((string) ($r['county'] ?? ''));
            $locality = trim((string) ($r['locality'] ?? ''));
            $name = trim((string) ($r['name'] ?? ''));
            if ($code === '' || $county === '' || $locality === '') {
                continue;
            }
            if ($name === '') {
                $name = $locality;
            }
            try {
                $stmt->execute([
                    'code' => mb_substr($code, 0, 60),
                    'name' => mb_substr($name, 0, 190),
                    'county' => mb_substr($county, 0, 120),
                    'locality' => mb_substr($locality, 0, 190),
                    'address' => mb_substr(trim((string) ($r['address'] ?? '')), 0, 255),
                    'postcode' => mb_substr(trim((string) ($r['postcode'] ?? '')), 0, 20),
                    'lat' => self::coordonata($r['lat'] ?? null),
                    'lng' => self::coordonata($r['lng'] ?? null),
                    'county_norm' => mb_substr(self::normalizeaza($county), 0, 120),
                    'locality_norm' => mb_substr(self::normalizeaza($locality), 0, 190),
                    'created_at' => $acum,
                    'updated_at' => $acum,
                ]);
                $coduri[] = mb_substr($code, 0, 60);
                $importate++;
            } catch (Throwable) {
            }
        }

        $dezactivate = 0;
        if ($coduri !== []) {
            try {
                $placeholders = implode(',', array_fill(0, count($coduri), '?'));
                $off = $db->prepare(
                    "UPDATE fan_lockers SET active = 0, updated_at = ? WHERE active = 1 AND code NOT IN ($placeholders)"
                );
                $off->execute(array_merge([$acum], $coduri));
                $dezactivate = $off->rowCount();
            } catch (Throwable) {
            }
        }

        return ['importate' => $importate, 'dezactivate' => $dezactivate];
    }

    /**
     * Traduce un punct așa cum îl dă API-ul FAN în rândul nostru.
     * Adresa vine când ca text, când desfăcută pe câmpuri — le acceptăm pe
     * amândouă, ca o schimbare de formă la ei să nu golească nomenclatorul.
     *
     * @return array<string, mixed>|null
     */
    public static function dinPunctApi(array $p): ?array
    {
        $fanId = trim((string) ($p['id'] ?? ''));
        if ($fanId === '') {
            return null;
        }

        $adresa = $p['address'] ?? null;
        $judet = '';
        $localitate = '';
        $strada = '';
        $codPostal = '';
        if (is_array($adresa)) {
            $judet = trim((string) ($adresa['county'] ?? $adresa['countyName'] ?? ''));
            $localitate = trim((string) ($adresa['locality'] ?? $adresa['localityName'] ?? $adresa['city'] ?? ''));
            $strada = trim((string) ($adresa['street'] ?? ''));
            $numar = trim((string) ($adresa['streetNo'] ?? $adresa['number'] ?? ''));
            if ($numar !== '') {
                $strada = trim($strada . ' ' . $numar);
            }
            $codPostal = trim((string) ($adresa['zipCode'] ?? $adresa['postalCode'] ?? ''));
        } elseif (is_string($adresa)) {
            $strada = trim($adresa);
        }
        if ($judet === '') {
            $judet = trim((string) ($p['county'] ?? ''));
        }
        if ($localitate === '') {
            $localitate = trim((string) ($p['locality'] ?? $p['city'] ?? ''));
        }
        if ($judet === '' || $localitate === '') {
            return null;
        }

        $denumire = trim((string) ($p['name'] ?? ''));
        if ($denumire === '') {
            $denumire = $localitate;
        }
        if ($strada === '') {
            $strada = trim((string) ($p['description'] ?? ''));
        }

        return [
            'fan_id' => mb_substr($fanId, 0, 60),
            'code' => mb_substr('fan-' . $fanId, 0, 60),
            'name' => mb_substr($denumire, 0, 190),
            'county' => mb_substr($judet, 0, 120),
            'locality' => mb_substr($localitate, 0, 190),
            'address' => mb_substr($strada, 0, 255),
            'postcode' => mb_substr($codPostal, 0, 20),
            'lat' => self::coordonata($p['latitude'] ?? null),
            'lng' => self::coordonata($p['longitude'] ?? null),
        ];
    }

    /**
     * Aduce nomenclatorul la zi din API-ul FAN.
     *
     * Punctele deja existente se actualizează PE LOC (același rând, același id
     * local), nu se șterg și se re-creează: comenzile vechi păstrează
     * `fan_locker_id`, deci un AWB se poate reemite și după sincronizare.
     *
     * @param list<array<string, mixed>> $puncte
     * @return array{importate:int, actualizate:int, adaugate:int, dezactivate:int}
     */
    public static function sincronizeazaDinApi(PDO $db, array $puncte): array
    {
        self::ensureSchema($db);
        $acum = date('Y-m-d H:i:s');

        $dupaFanId = $db->prepare('SELECT id FROM fan_lockers WHERE fan_id = :fan_id LIMIT 1');
        $dupaNume = $db->prepare(
            'SELECT id FROM fan_lockers
             WHERE county_norm = :county_norm AND locality_norm = :locality_norm AND name = :name
             ORDER BY active DESC, id ASC
             LIMIT 1'
        );
        $dupaCod = $db->prepare('SELECT id FROM fan_lockers WHERE code = :code LIMIT 1');
        $actualizeaza = $db->prepare(
            'UPDATE fan_lockers
                SET fan_id = :fan_id, name = :name, county = :county, locality = :locality,
                    address = :address, postcode = :postcode, lat = :lat, lng = :lng,
                    county_norm = :county_norm, locality_norm = :locality_norm,
                    active = 1, updated_at = :updated_at
              WHERE id = :id'
        );
        $adauga = $db->prepare(
            'INSERT INTO fan_lockers
                (code, fan_id, name, county, locality, address, postcode, lat, lng,
                 county_norm, locality_norm, active, created_at, updated_at)
             VALUES (:code, :fan_id, :name, :county, :locality, :address, :postcode, :lat, :lng,
                 :county_norm, :locality_norm, 1, :created_at, :updated_at)'
        );

        $atinse = [];
        $actualizate = 0;
        $adaugate = 0;
        foreach ($puncte as $punct) {
            if (!is_array($punct)) {
                continue;
            }
            $r = self::dinPunctApi($punct);
            if ($r === null) {
                continue;
            }

            $comune = [
                'fan_id' => $r['fan_id'],
                'name' => $r['name'],
                'county' => $r['county'],
                'locality' => $r['locality'],
                'address' => $r['address'],
                'postcode' => $r['postcode'],
                'lat' => $r['lat'],
                'lng' => $r['lng'],
                'county_norm' => mb_substr(self::normalizeaza($r['county']), 0, 120),
                'locality_norm' => mb_substr(self::normalizeaza($r['locality']), 0, 190),
                'updated_at' => $acum,
            ];

            try {
                $idLocal = 0;
                $dupaFanId->execute(['fan_id' => $r['fan_id']]);
                $idLocal = (int) ($dupaFanId->fetchColumn() ?: 0);

                if ($idLocal <= 0) {
                    // Punct importat înainte din fișier: are toate datele, dar nu
                    // și id-ul FAN. Îl recunoaștem după județ, localitate și
                    // denumire, ca să-i completăm id-ul fără să pierdem rândul.
                    $dupaNume->execute([
                        'county_norm' => $comune['county_norm'],
                        'locality_norm' => $comune['locality_norm'],
                        'name' => $comune['name'],
                    ]);
                    $idLocal = (int) ($dupaNume->fetchColumn() ?: 0);
                }
                if ($idLocal <= 0) {
                    $dupaCod->execute(['code' => $r['code']]);
                    $idLocal = (int) ($dupaCod->fetchColumn() ?: 0);
                }

                if ($idLocal > 0) {
                    $actualizeaza->execute($comune + ['id' => $idLocal]);
                    $actualizate++;
                } else {
                    $adauga->execute($comune + [
                        'code' => $r['code'],
                        'created_at' => $acum,
                    ]);
                    $idLocal = (int) $db->lastInsertId();
                    $adaugate++;
                }
                if ($idLocal > 0) {
                    $atinse[] = $idLocal;
                }
            } catch (Throwable) {
            }
        }

        $dezactivate = 0;
        if ($atinse !== []) {
            try {
                $placeholders = implode(',', array_fill(0, count($atinse), '?'));
                $off = $db->prepare(
                    "UPDATE fan_lockers SET active = 0, updated_at = ? WHERE active = 1 AND id NOT IN ($placeholders)"
                );
                $off->execute(array_merge([$acum], $atinse));
                $dezactivate = $off->rowCount();
            } catch (Throwable) {
            }
        }

        return [
            'importate' => count($atinse),
            'actualizate' => $actualizate,
            'adaugate' => $adaugate,
            'dezactivate' => $dezactivate,
        ];
    }

    /** Câte puncte au id-ul de la FAN (doar ele pot primi AWB). */
    public static function numarCuIdFan(?PDO $db): int
    {
        if (!$db instanceof PDO) {
            return 0;
        }
        try {
            self::ensureSchema($db);
            return (int) ($db->query(
                'SELECT COUNT(*) FROM fan_lockers WHERE active = 1 AND fan_id <> ""'
            )->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Caută un punct după datele păstrate pe comandă.
     *
     * Folosit la reemiterea unui AWB: dacă între timp nomenclatorul a fost
     * sincronizat, rândul la care trimitea comanda poate fi dezactivat, dar
     * punctul există sub alt rând. Fără asta, comenzile vechi ar rămâne
     * blocate.
     *
     * @return array<string, mixed>|null
     */
    public static function potrivestePunct(?PDO $db, string $nume, string $localitate, string $judet): ?array
    {
        if (!$db instanceof PDO) {
            return null;
        }
        $nume = trim($nume);
        $localitate = trim($localitate);
        if ($nume === '' || $localitate === '') {
            return null;
        }
        try {
            self::ensureSchema($db);
            $stmt = $db->prepare(
                'SELECT id, code, fan_id, name, county, locality, address, postcode
                 FROM fan_lockers
                 WHERE active = 1 AND fan_id <> "" AND locality_norm = :locality_norm
                   AND (:county_norm = "" OR county_norm = :county_norm2)
                 ORDER BY id ASC'
            );
            $judetNorm = self::normalizeaza($judet);
            $stmt->execute([
                'locality_norm' => self::normalizeaza($localitate),
                'county_norm' => $judetNorm,
                'county_norm2' => $judetNorm,
            ]);
            $randuri = $stmt->fetchAll();
        } catch (Throwable) {
            return null;
        }

        $numeNorm = self::normalizeaza($nume);
        foreach ($randuri as $r) {
            if (self::normalizeaza((string) ($r['name'] ?? '')) === $numeNorm) {
                return [
                    'id' => (int) ($r['id'] ?? 0),
                    'code' => (string) ($r['code'] ?? ''),
                    'fan_id' => (string) ($r['fan_id'] ?? ''),
                    'name' => (string) ($r['name'] ?? ''),
                    'county' => (string) ($r['county'] ?? ''),
                    'locality' => (string) ($r['locality'] ?? ''),
                    'address' => (string) ($r['address'] ?? ''),
                    'postcode' => (string) ($r['postcode'] ?? ''),
                ];
            }
        }

        return null;
    }
}
