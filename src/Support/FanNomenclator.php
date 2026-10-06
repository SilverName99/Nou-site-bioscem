<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Nomenclatorul FAN de localități și străzi, ținut la zi din API.
 *
 * Până acum cele trei liste (localități, străzi, localități cu km
 * suplimentari) intrau doar din fișiere încărcate de mână. Lista de km a ajuns
 * astfel să conțină toată țara, iar aproape orice comandă primea taxa. Acum
 * listele se reconstruiesc din `/reports/localities` și `/reports/streets`:
 * lista de km suplimentari e exact ce are la FAN `exteriorKm > 0`.
 *
 * Datele se adună într-o tabelă-ciornă (`<tabela>__sync`) și abia la final
 * aceasta ia locul celei folosite de site, printr-un singur RENAME. Dacă FAN
 * pică la jumătate sau întoarce gol, lista veche rămâne neatinsă.
 *
 * Sincronizarea merge pe pași (un județ, o pagină de străzi) și își ține
 * cursorul în `fan_nomenclator_sync`, ca să poată fi dusă mai departe din mai
 * multe cereri web scurte: pe găzduirea comună o cerere care durează minute e
 * oprită de server. Cronul face toți pașii dintr-o bucată.
 */
final class FanNomenclator
{
    public const LOCALITATI = 'localitati';
    public const STRAZI = 'strazi';

    /** Sub atâtea rânduri, o listă „de km suplimentari" nu poate fi lista completă FAN. */
    public const PRAG_LISTA_COMPLETA = 5000;

    /** Maximul acceptat de FAN pe o pagină de străzi. */
    private const STRAZI_PE_PAGINA = 1000;

    /** O sincronizare lăsată neterminată mai mult de atât se ia de la capăt. */
    private const ORE_RELUARE = 12;

    /**
     * Sub atâtea localități răspunsul e ciuntit: nomenclatorul FAN are în jur
     * de 13.800 (cât avea și lista completă încărcată din fișier).
     */
    private const MIN_LOCALITATI = 5000;

    /** Județe fără nicio localitate tolerate înainte să socotim răspunsul ciuntit. */
    private const JUDETE_GOALE_TOLERATE = 2;

    private const NUME_LACAT = 'CONCAT("fan_nomenclator:", MD5(DATABASE()), ":", :lista)';

    /** Tabelele pe care le înlocuiește fiecare listă. */
    private const TABELE = [
        self::LOCALITATI => ['fan_localities', 'fan_localities_extra_km'],
        self::STRAZI => ['fan_streets'],
    ];

    /**
     * Aceeași normalizare ca la importul din fișier (normalizeFanLocalityToken
     * din controllere): datele din API și cele din fișier trebuie să dea
     * aceleași chei, altfel căutările din checkout nu le mai găsesc.
     */
    public static function normalizeaza(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'ă' => 'a',
            'â' => 'a',
            'î' => 'i',
            'ș' => 's',
            'ş' => 's',
            'ț' => 't',
            'ţ' => 't',
        ]);
        $value = preg_replace('/[^a-z0-9\s\-]/', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return trim($value);
    }

    /**
     * Cheia de județ pentru lista de km suplimentari. Clientul scrie și
     * „Județul Alba" sau „Municipiul București", FAN scrie „Alba" și
     * „Bucuresti" (în documentația engleză chiar „Bucharest"): toate trebuie
     * să ajungă la aceeași cheie, și la import, și la căutarea din
     * ShippingPricing.
     */
    public static function normalizeazaJudet(string $judet): string
    {
        $norm = self::normalizeaza($judet);
        $norm = preg_replace('/^(judetul|judet|jud|municipiul)\s+/', '', $norm) ?? $norm;
        if (str_contains($norm, 'bucuresti') || str_contains($norm, 'bucharest') || $norm === 'b') {
            return 'bucuresti';
        }
        return trim($norm);
    }

    /** Datele de acces FAN din setări, sau null dacă lipsesc. */
    public static function credentialeDinSetari(array $settings): ?array
    {
        $clientId = (int) ($settings['fan_client_id'] ?? 0);
        $username = trim((string) ($settings['fan_api_username'] ?? ''));
        $password = trim((string) ($settings['fan_api_password'] ?? ''));
        if ($clientId <= 0 || $username === '' || $password === '') {
            return null;
        }

        return ['client_id' => $clientId, 'username' => $username, 'password' => $password];
    }

    public static function asiguraSchema(PDO $db): void
    {
        self::asiguraSchemaLocalitati($db);
        self::asiguraSchemaKm($db);
        self::asiguraSchemaStrazi($db);
        self::asiguraSchemaJurnal($db);
    }

    /** Jurnalul sincronizărilor: un rând pe listă, cu cursorul celei în curs. */
    private static function asiguraSchemaJurnal(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS fan_nomenclator_sync (
                    lista VARCHAR(20) NOT NULL PRIMARY KEY,
                    stare VARCHAR(20) NOT NULL DEFAULT "",
                    sursa VARCHAR(20) NOT NULL DEFAULT "",
                    cursor_json MEDIUMTEXT DEFAULT NULL,
                    pas INT UNSIGNED NOT NULL DEFAULT 0,
                    pasi_total INT UNSIGNED NOT NULL DEFAULT 0,
                    randuri INT UNSIGNED NOT NULL DEFAULT 0,
                    pornit_la DATETIME DEFAULT NULL,
                    activ_la DATETIME DEFAULT NULL,
                    reusit_la DATETIME DEFAULT NULL,
                    reusit_sursa VARCHAR(20) NOT NULL DEFAULT "",
                    reusit_randuri INT UNSIGNED NOT NULL DEFAULT 0,
                    reusit_detalii VARCHAR(255) NOT NULL DEFAULT "",
                    eroare TEXT DEFAULT NULL,
                    eroare_la DATETIME DEFAULT NULL
                )'
            );
        } catch (Throwable) {
        }
    }

    public static function asiguraSchemaLocalitati(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS fan_localities (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    county VARCHAR(120) NOT NULL,
                    locality VARCHAR(190) NOT NULL,
                    county_norm VARCHAR(120) NOT NULL,
                    locality_norm VARCHAR(190) NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_fan_localities (county_norm, locality_norm),
                    KEY idx_fan_localities_county (county_norm),
                    KEY idx_fan_localities_locality (locality_norm)
                )'
            );
        } catch (Throwable) {
        }
    }

    public static function asiguraSchemaKm(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS fan_localities_extra_km (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    county VARCHAR(120) NOT NULL,
                    locality VARCHAR(190) NOT NULL,
                    county_norm VARCHAR(120) NOT NULL,
                    locality_norm VARCHAR(190) NOT NULL,
                    exterior_km DECIMAL(8, 2) DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_fan_localities_extra_km (county_norm, locality_norm),
                    KEY idx_fan_localities_extra_km_county (county_norm),
                    KEY idx_fan_localities_extra_km_locality (locality_norm)
                )'
            );
        } catch (Throwable) {
        }
        // Câți km în afara localității de reședință a agenției. Gol = rândul a
        // venit dintr-un fișier fără coloana asta.
        try {
            $db->exec('ALTER TABLE fan_localities_extra_km ADD COLUMN exterior_km DECIMAL(8, 2) DEFAULT NULL AFTER locality_norm');
        } catch (Throwable) {
            // coloana există deja
        }
    }

    public static function asiguraSchemaStrazi(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS fan_streets (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    county VARCHAR(120) NOT NULL,
                    locality VARCHAR(190) NOT NULL,
                    street VARCHAR(255) NOT NULL,
                    street_id VARCHAR(64) NOT NULL DEFAULT "",
                    range_from VARCHAR(40) NOT NULL DEFAULT "",
                    range_to VARCHAR(40) NOT NULL DEFAULT "",
                    parity VARCHAR(32) NOT NULL DEFAULT "",
                    postal_code VARCHAR(32) NOT NULL DEFAULT "",
                    street_type VARCHAR(80) NOT NULL DEFAULT "",
                    agency VARCHAR(160) NOT NULL DEFAULT "",
                    county_norm VARCHAR(120) NOT NULL,
                    locality_norm VARCHAR(190) NOT NULL,
                    street_norm VARCHAR(255) NOT NULL,
                    row_key CHAR(40) NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_fan_streets_row (row_key),
                    KEY idx_fan_streets_county (county_norm),
                    KEY idx_fan_streets_locality (locality_norm),
                    KEY idx_fan_streets_street (street_norm)
                )'
            );
        } catch (Throwable) {
        }

        foreach ([
            'ALTER TABLE fan_streets ADD COLUMN street VARCHAR(255) NOT NULL AFTER locality',
            'ALTER TABLE fan_streets ADD COLUMN street_norm VARCHAR(255) NOT NULL AFTER locality_norm',
            'ALTER TABLE fan_streets ADD COLUMN street_id VARCHAR(64) NOT NULL DEFAULT "" AFTER street',
            'ALTER TABLE fan_streets ADD COLUMN range_from VARCHAR(40) NOT NULL DEFAULT "" AFTER street_id',
            'ALTER TABLE fan_streets ADD COLUMN range_to VARCHAR(40) NOT NULL DEFAULT "" AFTER range_from',
            'ALTER TABLE fan_streets ADD COLUMN parity VARCHAR(32) NOT NULL DEFAULT "" AFTER range_to',
            'ALTER TABLE fan_streets ADD COLUMN postal_code VARCHAR(32) NOT NULL DEFAULT "" AFTER parity',
            'ALTER TABLE fan_streets ADD COLUMN street_type VARCHAR(80) NOT NULL DEFAULT "" AFTER postal_code',
            'ALTER TABLE fan_streets ADD COLUMN agency VARCHAR(160) NOT NULL DEFAULT "" AFTER street_type',
            'ALTER TABLE fan_streets ADD COLUMN row_key CHAR(40) NOT NULL DEFAULT "" AFTER street_norm',
            'ALTER TABLE fan_streets DROP INDEX uniq_fan_streets',
            'UPDATE fan_streets
             SET row_key = SHA1(CONCAT_WS("|",
                county_norm,
                locality_norm,
                street_norm,
                COALESCE(street_id, ""),
                COALESCE(range_from, ""),
                COALESCE(range_to, ""),
                COALESCE(parity, ""),
                COALESCE(postal_code, ""),
                COALESCE(street_type, ""),
                COALESCE(agency, ""),
                CAST(id AS CHAR)
             ))
             WHERE row_key = ""',
            'ALTER TABLE fan_streets ADD UNIQUE KEY uniq_fan_streets_row (row_key)',
        ] as $sql) {
            try {
                $db->exec($sql);
            } catch (Throwable) {
                // migrarea a fost deja aplicată
            }
        }
    }

    /**
     * Cheia unică a unui rând de stradă: aceeași formulă ca la importul din
     * fișier, ca un import ulterior să actualizeze rândurile din API, nu să le
     * dubleze.
     */
    public static function cheieStrada(array $r): string
    {
        return sha1(implode('|', [
            self::normalizeaza((string) ($r['county'] ?? '')),
            self::normalizeaza((string) ($r['locality'] ?? '')),
            self::normalizeaza((string) ($r['street'] ?? '')),
            self::normalizeaza((string) ($r['street_id'] ?? '')),
            self::normalizeaza((string) ($r['range_from'] ?? '')),
            self::normalizeaza((string) ($r['range_to'] ?? '')),
            self::normalizeaza((string) ($r['parity'] ?? '')),
            self::normalizeaza((string) ($r['postal_code'] ?? '')),
            self::normalizeaza((string) ($r['street_type'] ?? '')),
            self::normalizeaza((string) ($r['agency'] ?? '')),
        ]));
    }

    /**
     * Rulează sincronizarea unei liste: pornește una nouă sau o continuă pe
     * cea rămasă neterminată.
     *
     * Cu `$bugetSecunde` > 0 se oprește după primul pas care trece de buget și
     * întoarce `in_curs` (cererile web); cu 0 merge până la capăt (cronul).
     *
     * @return array{stare:string, mesaj:string, pas:int, pasi_total:int, randuri:int}
     */
    public static function ruleaza(
        PDO $db,
        array $credentials,
        string $lista,
        string $sursa,
        float $bugetSecunde = 0.0
    ): array {
        if (!isset(self::TABELE[$lista])) {
            throw new InvalidArgumentException('Listă FAN necunoscută: ' . $lista);
        }
        self::asiguraSchema($db);

        if (!self::iaLacat($db, $lista)) {
            return self::rezultat('ocupat', 'O sincronizare a listei ' . self::eticheta($lista)
                . ' rulează deja (pornită de cron sau din altă fereastră). Încearcă din nou peste câteva minute.');
        }

        $start = microtime(true);
        try {
            $job = self::jobDeLucru($db, $lista, $sursa);
            while (true) {
                $cursor = $job['cursor'];
                if (($cursor['faza'] ?? '') === 'final') {
                    return self::finalizeaza($db, $lista, $job);
                }
                if ($bugetSecunde > 0 && (microtime(true) - $start) >= $bugetSecunde) {
                    return self::rezultat('in_curs', self::progres($lista, $job), $job);
                }
                $ramas = $bugetSecunde > 0 ? $bugetSecunde - (microtime(true) - $start) : 0.0;
                $job = $lista === self::LOCALITATI
                    ? self::pasLocalitati($db, $credentials, $job, $ramas)
                    : self::pasStrazi($db, $credentials, $job, $ramas);
                self::salveazaJob($db, $lista, $job);
            }
        } catch (Throwable $exception) {
            $mesaj = trim($exception->getMessage()) !== '' ? trim($exception->getMessage()) : get_class($exception);
            self::noteazaEroare($db, $lista, $mesaj, $exception instanceof DateFanSuspecte);

            return self::rezultat('eroare', $mesaj);
        } finally {
            self::elibereazaLacat($db, $lista);
        }
    }

    /**
     * Starea listei, pentru afișarea pe tab-uri.
     *
     * @return array<string, mixed>
     */
    public static function stare(?PDO $db, string $lista): array
    {
        $gol = [
            'lista' => $lista,
            'stare' => '',
            'sursa' => '',
            'pas' => 0,
            'pasi_total' => 0,
            'randuri' => 0,
            'pornit_la' => null,
            'activ_la' => null,
            'reusit_la' => null,
            'reusit_sursa' => '',
            'reusit_randuri' => 0,
            'reusit_detalii' => '',
            'eroare' => null,
            'eroare_la' => null,
            'eroare_dupa_reusita' => false,
            'in_curs' => false,
            'activ_acum' => false,
            'progres' => '',
        ];
        if (!$db instanceof PDO) {
            return $gol;
        }
        try {
            self::asiguraSchemaJurnal($db);
            $stmt = $db->prepare('SELECT * FROM fan_nomenclator_sync WHERE lista = :lista LIMIT 1');
            $stmt->execute(['lista' => $lista]);
            $rand = $stmt->fetch();
        } catch (Throwable) {
            return $gol;
        }
        if (!is_array($rand)) {
            return $gol;
        }

        $out = array_merge($gol, $rand);
        foreach (['pas', 'pasi_total', 'randuri', 'reusit_randuri'] as $camp) {
            $out[$camp] = (int) ($out[$camp] ?? 0);
        }
        $out['eroare_dupa_reusita'] = !empty($rand['eroare_la'])
            && (empty($rand['reusit_la']) || strcmp((string) $rand['eroare_la'], (string) $rand['reusit_la']) > 0);
        // Aceeași regulă ca la reluare (jobDeLucru): una mai veche de
        // ORE_RELUARE nu se mai continuă, se ia de la capăt.
        $out['in_curs'] = in_array((string) $rand['stare'], ['in_curs', 'eroare'], true)
            && !empty($rand['cursor_json'])
            && !empty($rand['pornit_la'])
            && strtotime((string) $rand['pornit_la']) >= time() - self::ORE_RELUARE * 3600;
        // „Activă acum" = a mișcat ceva în ultimele minute. Altfel e o
        // sincronizare rămasă la jumătate (fereastră închisă, eroare), pe care
        // o reia următoarea apăsare sau cronul.
        $out['activ_acum'] = (string) $rand['stare'] === 'in_curs'
            && !empty($rand['activ_la'])
            && strtotime((string) $rand['activ_la']) >= time() - 120;
        $out['progres'] = $out['in_curs']
            ? self::progres($lista, ['pas' => $out['pas'], 'pasi_total' => $out['pasi_total'], 'randuri' => $out['randuri']])
            : '';
        unset($out['cursor_json']);

        return $out;
    }

    /** Câte rânduri are o listă folosită de site, sau 0 dacă nu există încă. */
    public static function numar(?PDO $db, string $tabela): int
    {
        if (!$db instanceof PDO || !in_array($tabela, ['fan_localities', 'fan_localities_extra_km', 'fan_streets'], true)) {
            return 0;
        }
        return self::numarRanduri($db, $tabela);
    }

    /** @param string $tabela nume fix din cod, niciodată venit din cerere */
    private static function numarRanduri(PDO $db, string $tabela): int
    {
        try {
            return (int) ($db->query('SELECT COUNT(*) FROM ' . $tabela)->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function eticheta(string $lista): string
    {
        return $lista === self::STRAZI ? 'de străzi' : 'de localități';
    }

    // ---------------------------------------------------------------------
    // Pașii
    // ---------------------------------------------------------------------

    /**
     * Un pas din lista de localități: la început lista de județe, apoi câte un
     * județ (sau o pagină din el, dacă FAN paginează) pe pas.
     */
    private static function pasLocalitati(PDO $db, array $credentials, array $job, float $ramas): array
    {
        $cursor = $job['cursor'];
        if (($cursor['faza'] ?? '') === 'judete') {
            $judete = self::cuReincercari(
                static fn () => FanCourierGateway::counties($credentials),
                $ramas,
                'Lista de județe FAN'
            );
            // Fără județe cerem toată țara dintr-o dată; verificarea de la
            // final cere atunci un număr de localități pe măsura țării.
            $cursor = [
                'faza' => 'localitati',
                'judete' => $judete !== [] ? $judete : [''],
                'i' => 0,
                'pagina' => 1,
                'goale' => [],
                'cu_camp_km' => false,
                'cu_km' => 0,
            ];
            $job['cursor'] = $cursor;
            $job['pas'] = 0;
            $job['pasi_total'] = count($cursor['judete']);
            return $job;
        }

        $judete = (array) ($cursor['judete'] ?? []);
        $i = (int) ($cursor['i'] ?? 0);
        $judet = (string) ($judete[$i] ?? '');
        $pagina = max(1, (int) ($cursor['pagina'] ?? 1));

        $raspuns = self::cuReincercari(
            static fn () => FanCourierGateway::localitiesPage($credentials, $judet, $pagina > 1 ? $pagina : 0),
            $ramas,
            'Localitățile din ' . ($judet !== '' ? 'județul ' . $judet : 'toată țara') . ($pagina > 1 ? ', pagina ' . $pagina : '')
        );

        $acum = date('Y-m-d H:i:s');
        $localitati = [];
        $cuKm = [];
        foreach ($raspuns['data'] as $rand) {
            $nume = trim((string) ($rand['name'] ?? $rand['locality'] ?? ''));
            $judetRand = trim((string) ($rand['county'] ?? ''));
            if ($judetRand === '') {
                $judetRand = $judet;
            }
            if ($nume === '' || $judetRand === '') {
                continue;
            }
            $nume = mb_substr($nume, 0, 190);
            $judetRand = mb_substr($judetRand, 0, 120);
            $localitati[] = [
                $judetRand,
                $nume,
                mb_substr(self::normalizeaza($judetRand), 0, 120),
                mb_substr(self::normalizeaza($nume), 0, 190),
                $acum,
                $acum,
            ];
            if (array_key_exists('exteriorKm', $rand)) {
                $cursor['cu_camp_km'] = true;
            }
            $km = self::numarKm($rand['exteriorKm'] ?? null);
            if ($km !== null && $km > 0) {
                $cuKm[] = [
                    $judetRand,
                    $nume,
                    mb_substr(self::normalizeazaJudet($judetRand), 0, 120),
                    mb_substr(self::normalizeaza($nume), 0, 190),
                    $km,
                    $acum,
                    $acum,
                ];
            }
        }

        self::insereazaInLoturi(
            $db,
            'fan_localities__sync',
            ['county', 'locality', 'county_norm', 'locality_norm', 'created_at', 'updated_at'],
            $localitati,
            'updated_at = VALUES(updated_at)'
        );
        // Două sate cu același nume în același județ nu se pot deosebi după
        // ce scrie clientul în adresă; dacă unul e cu km, luăm taxa.
        self::insereazaInLoturi(
            $db,
            'fan_localities_extra_km__sync',
            ['county', 'locality', 'county_norm', 'locality_norm', 'exterior_km', 'created_at', 'updated_at'],
            $cuKm,
            'exterior_km = GREATEST(COALESCE(exterior_km, 0), VALUES(exterior_km)), updated_at = VALUES(updated_at)'
        );
        $cursor['cu_km'] = (int) ($cursor['cu_km'] ?? 0) + count($cuKm);
        $job['randuri'] += count($localitati);

        if ($pagina === 1 && $localitati === []) {
            $cursor['goale'][] = $judet;
        }

        // Documentația nu paginează lista; dacă totuși vine cu `total` și
        // `perPage`, cerem și paginile următoare ale aceluiași județ.
        $total = $raspuns['total'];
        $pePagina = $raspuns['perPage'] ?? 0;
        $maiSuntPagini = $total !== null && $pePagina > 0 && $raspuns['data'] !== []
            && $pagina < (int) ceil($total / $pePagina);
        if ($maiSuntPagini) {
            $cursor['pagina'] = $pagina + 1;
        } else {
            $cursor['i'] = $i + 1;
            $cursor['pagina'] = 1;
            $job['pas'] = $i + 1;
            if ($cursor['i'] >= count($judete)) {
                $cursor['faza'] = 'final';
            }
        }
        $job['cursor'] = $cursor;

        return $job;
    }

    /** Un pas din lista de străzi: o pagină de cel mult 1000 de rânduri. */
    private static function pasStrazi(PDO $db, array $credentials, array $job, float $ramas): array
    {
        $cursor = $job['cursor'];
        $pagina = max(1, (int) ($cursor['pagina'] ?? 1));

        $raspuns = self::cuReincercari(
            static fn () => FanCourierGateway::streetsPage($credentials, $pagina, self::STRAZI_PE_PAGINA),
            $ramas,
            'Străzile, pagina ' . $pagina
        );
        $pePagina = (int) ($raspuns['perPage'] ?? 0);
        if ($pePagina <= 0) {
            $pePagina = self::STRAZI_PE_PAGINA;
        }
        if ($raspuns['total'] !== null) {
            $cursor['total'] = $raspuns['total'];
            $job['pasi_total'] = (int) ceil($raspuns['total'] / $pePagina);
        }
        $total = isset($cursor['total']) ? (int) $cursor['total'] : null;

        if ($raspuns['data'] === []) {
            if ($pagina === 1) {
                throw new DateFanSuspecte('FAN a întors o listă goală de străzi. Lista existentă a rămas neschimbată.');
            }
            // O pagină goală înainte de ultima înseamnă că lista s-a rupt pe drum.
            if ($total !== null && $pagina <= (int) ceil($total / $pePagina)) {
                throw new RuntimeException(
                    'FAN a întors pagina ' . $pagina . ' de străzi goală, deși ar fi trebuit să fie '
                    . (int) ceil($total / $pePagina) . ' pagini.'
                );
            }
            $cursor['faza'] = 'final';
            $job['cursor'] = $cursor;
            return $job;
        }

        $acum = date('Y-m-d H:i:s');
        $randuri = [];
        $incomplete = 0;
        foreach ($raspuns['data'] as $s) {
            // `details` poate fi un singur tronson (obiect) sau o listă de
            // tronsoane ale aceleiași străzi. Ca listă, citit ca obiect, dădea
            // numere goale și toate tronsoanele străzii se contopeau într-unul.
            $detalii = is_array($s['details'] ?? null) ? $s['details'] : [];
            $tronsoane = isset($detalii[0])
                ? array_values(array_filter($detalii, 'is_array'))
                : [$detalii];
            if ($tronsoane === []) {
                $tronsoane = [[]];
            }
            foreach ($tronsoane as $t) {
                $r = [
                    'county' => mb_substr(trim((string) ($s['county'] ?? '')), 0, 120),
                    'locality' => mb_substr(trim((string) ($s['locality'] ?? '')), 0, 190),
                    'street' => mb_substr(trim((string) ($s['street'] ?? $s['name'] ?? '')), 0, 255),
                    'street_id' => mb_substr(trim((string) ($s['id'] ?? '')), 0, 64),
                    'range_from' => mb_substr(trim((string) ($t['fromNo'] ?? '')), 0, 40),
                    'range_to' => mb_substr(trim((string) ($t['toNo'] ?? '')), 0, 40),
                    'parity' => mb_substr(trim((string) ($t['parityNo'] ?? '')), 0, 32),
                    'postal_code' => mb_substr(trim((string) ($t['zipCode'] ?? '')), 0, 32),
                    'street_type' => mb_substr(trim((string) ($s['type'] ?? '')), 0, 80),
                    'agency' => mb_substr(trim((string) ($s['agency'] ?? $t['agency'] ?? '')), 0, 160),
                ];
                if ($r['county'] === '' || $r['locality'] === '' || $r['street'] === '') {
                    $incomplete++;
                    continue;
                }
                $randuri[] = [
                    $r['county'], $r['locality'], $r['street'], $r['street_id'], $r['range_from'], $r['range_to'],
                    $r['parity'], $r['postal_code'], $r['street_type'], $r['agency'],
                    mb_substr(self::normalizeaza($r['county']), 0, 120),
                    mb_substr(self::normalizeaza($r['locality']), 0, 190),
                    mb_substr(self::normalizeaza($r['street']), 0, 255),
                    self::cheieStrada($r),
                    $acum,
                    $acum,
                ];
            }
        }
        // Completitudinea se judecă după rândurile primite de la FAN, nu după
        // cele rămase în tabel: rândurile identice se contopesc la scriere și
        // nu înseamnă că s-a pierdut vreo pagină.
        $cursor['primite'] = (int) ($cursor['primite'] ?? 0) + count($raspuns['data']);
        $cursor['incomplete'] = (int) ($cursor['incomplete'] ?? 0) + $incomplete;
        self::insereazaInLoturi(
            $db,
            'fan_streets__sync',
            [
                'county', 'locality', 'street', 'street_id', 'range_from', 'range_to', 'parity', 'postal_code',
                'street_type', 'agency', 'county_norm', 'locality_norm', 'street_norm', 'row_key',
                'created_at', 'updated_at',
            ],
            $randuri,
            'updated_at = VALUES(updated_at)'
        );
        $job['randuri'] += count($randuri);
        $job['pas'] = $pagina;

        $gata = $total !== null
            ? $pagina >= (int) ceil($total / $pePagina)
            : count($raspuns['data']) < $pePagina;
        if ($gata) {
            $cursor['faza'] = 'final';
        } else {
            $cursor['pagina'] = $pagina + 1;
        }
        $job['cursor'] = $cursor;

        return $job;
    }

    /**
     * Verifică ce s-a adunat și, doar dacă arată a listă întreagă, o pune în
     * locul celei vechi.
     */
    private static function finalizeaza(PDO $db, string $lista, array $job): array
    {
        $cursor = $job['cursor'];
        if ($lista === self::LOCALITATI) {
            $n = self::numarRanduri($db, 'fan_localities__sync');
            $cuKm = self::numarRanduri($db, 'fan_localities_extra_km__sync');
            $goale = array_values(array_filter((array) ($cursor['goale'] ?? []), 'is_string'));

            if ($n === 0) {
                throw new DateFanSuspecte('FAN nu a întors nicio localitate. Listele existente au rămas neschimbate.');
            }
            if (count($goale) > self::JUDETE_GOALE_TOLERATE) {
                throw new DateFanSuspecte(
                    'FAN nu a întors localități pentru ' . count($goale) . ' județe ('
                    . implode(', ', array_slice($goale, 0, 8)) . '). Listele existente au rămas neschimbate.'
                );
            }
            if ($n < self::MIN_LOCALITATI) {
                throw new DateFanSuspecte(
                    'FAN a întors doar ' . $n . ' localități pentru toată țara: pare o listă ciuntită. Listele existente au rămas neschimbate.'
                );
            }
            // Fără câmpul de km nu putem ști care localități au taxă: o listă
            // goală ar scoate taxa de la toate comenzile.
            if (empty($cursor['cu_camp_km'])) {
                throw new DateFanSuspecte(
                    'Răspunsul FAN nu mai conține câmpul exteriorKm, deci nu se poate reface lista de km suplimentari. Listele existente au rămas neschimbate.'
                );
            }
            if ($cuKm === 0) {
                throw new DateFanSuspecte(
                    'FAN a întors toate localitățile cu 0 km suplimentari, ceea ce nu e plauzibil. Listele existente au rămas neschimbate.'
                );
            }

            self::inlocuiesteTabele($db, self::TABELE[$lista]);
            $detalii = number_format($n, 0, ',', '.') . ' localități, din care '
                . number_format($cuKm, 0, ',', '.') . ' cu km suplimentari';
            if ($goale !== []) {
                $detalii .= '; fără localități: ' . implode(', ', $goale);
            }
            $randuri = $n;
            $mesaj = 'Localități FAN sincronizate: ' . $detalii . '.';
        } else {
            $n = self::numarRanduri($db, 'fan_streets__sync');
            $total = isset($cursor['total']) ? (int) $cursor['total'] : null;
            // Un cursor pornit înainte de numărătoare n-o are: atunci rămâne tabelul.
            $primite = isset($cursor['primite']) ? (int) $cursor['primite'] : $n;
            $incomplete = (int) ($cursor['incomplete'] ?? 0);
            if ($n === 0) {
                throw new DateFanSuspecte('FAN nu a întors nicio stradă. Lista existentă a rămas neschimbată.');
            }
            // Câteva rânduri pot lipsi dacă lista s-a mișcat între pagini;
            // mai multe înseamnă o listă ruptă. Se numără ce a trimis FAN:
            // dublurile din lista lor se contopesc în tabel și nu sunt pierderi.
            if ($total !== null && $primite < (int) floor($total * 0.98)) {
                throw new DateFanSuspecte(
                    'Au venit doar ' . $primite . ' străzi din ' . $total . ' anunțate de FAN. Lista existentă a rămas neschimbată.'
                );
            }
            self::inlocuiesteTabele($db, self::TABELE[$lista]);
            $detalii = number_format($n, 0, ',', '.') . ' străzi';
            $note = [];
            if ($incomplete > 0) {
                $note[] = number_format($incomplete, 0, ',', '.') . ' fără județ, localitate sau nume, sărite';
            }
            $dubluri = $primite - $incomplete - $n;
            if ($dubluri > 0) {
                $note[] = number_format($dubluri, 0, ',', '.') . ' dubluri în lista FAN, păstrate o dată';
            }
            if ($note !== []) {
                $detalii .= ' (' . implode('; ', $note) . ')';
            }
            $randuri = $n;
            $mesaj = 'Străzi FAN sincronizate: ' . $detalii . '.';
        }

        $acum = date('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'UPDATE fan_nomenclator_sync
                SET stare = "gata", cursor_json = NULL, activ_la = :acum,
                    reusit_la = :acum2, reusit_sursa = sursa, reusit_randuri = :randuri, reusit_detalii = :detalii
              WHERE lista = :lista'
        );
        $stmt->execute([
            'acum' => $acum,
            'acum2' => $acum,
            'randuri' => $randuri,
            'detalii' => mb_substr($detalii, 0, 255),
            'lista' => $lista,
        ]);
        $job['randuri'] = $randuri;

        return self::rezultat('gata', $mesaj, $job);
    }

    // ---------------------------------------------------------------------
    // Jurnal, cursor, lacăt
    // ---------------------------------------------------------------------

    /** Sincronizarea în curs (dacă se poate relua) sau una nouă. */
    private static function jobDeLucru(PDO $db, string $lista, string $sursa): array
    {
        $stmt = $db->prepare('SELECT * FROM fan_nomenclator_sync WHERE lista = :lista LIMIT 1');
        $stmt->execute(['lista' => $lista]);
        $rand = $stmt->fetch();

        if (is_array($rand)) {
            $cursor = json_decode((string) ($rand['cursor_json'] ?? ''), true);
            $proaspat = !empty($rand['pornit_la'])
                && strtotime((string) $rand['pornit_la']) >= time() - self::ORE_RELUARE * 3600;
            // Se reia de unde a rămas (inclusiv după o eroare de rețea): pentru
            // străzi înseamnă zeci de pagini care nu se mai cer o dată.
            if (is_array($cursor) && $proaspat
                && in_array((string) $rand['stare'], ['in_curs', 'eroare'], true)
                && self::tabeleleDeLucruExista($db, $lista)
            ) {
                $job = [
                    'cursor' => $cursor,
                    'pas' => (int) $rand['pas'],
                    'pasi_total' => (int) $rand['pasi_total'],
                    'randuri' => (int) $rand['randuri'],
                ];
                $db->prepare(
                    'UPDATE fan_nomenclator_sync SET stare = "in_curs", activ_la = :acum WHERE lista = :lista'
                )->execute(['acum' => date('Y-m-d H:i:s'), 'lista' => $lista]);
                return $job;
            }
        }

        foreach (self::TABELE[$lista] as $tabela) {
            $db->exec('DROP TABLE IF EXISTS ' . $tabela . '__sync');
            $db->exec('CREATE TABLE ' . $tabela . '__sync LIKE ' . $tabela);
        }
        $job = [
            'cursor' => $lista === self::LOCALITATI ? ['faza' => 'judete'] : ['pagina' => 1],
            'pas' => 0,
            'pasi_total' => 0,
            'randuri' => 0,
        ];
        $acum = date('Y-m-d H:i:s');
        $db->prepare(
            'INSERT INTO fan_nomenclator_sync (lista, stare, sursa, cursor_json, pas, pasi_total, randuri, pornit_la, activ_la)
             VALUES (:lista, "in_curs", :sursa, :cursor, 0, 0, 0, :pornit, :activ)
             ON DUPLICATE KEY UPDATE stare = "in_curs", sursa = VALUES(sursa), cursor_json = VALUES(cursor_json),
                pas = 0, pasi_total = 0, randuri = 0, pornit_la = VALUES(pornit_la), activ_la = VALUES(activ_la)'
        )->execute([
            'lista' => $lista,
            'sursa' => mb_substr($sursa, 0, 20),
            'cursor' => json_encode($job['cursor'], JSON_UNESCAPED_UNICODE),
            'pornit' => $acum,
            'activ' => $acum,
        ]);

        return $job;
    }

    private static function salveazaJob(PDO $db, string $lista, array $job): void
    {
        $db->prepare(
            'UPDATE fan_nomenclator_sync
                SET cursor_json = :cursor, pas = :pas, pasi_total = :pasi_total, randuri = :randuri, activ_la = :acum
              WHERE lista = :lista'
        )->execute([
            'cursor' => json_encode($job['cursor'], JSON_UNESCAPED_UNICODE),
            'pas' => max(0, (int) $job['pas']),
            'pasi_total' => max(0, (int) $job['pasi_total']),
            'randuri' => max(0, (int) $job['randuri']),
            'acum' => date('Y-m-d H:i:s'),
            'lista' => $lista,
        ]);
    }

    /**
     * Notează eroarea. Când datele adunate sunt de neîncredere (nu doar o
     * cerere picată), cursorul se șterge: următoarea rulare o ia de la capăt
     * în loc să ajungă din nou la aceeași verificare.
     */
    private static function noteazaEroare(PDO $db, string $lista, string $mesaj, bool $deLaCapat): void
    {
        try {
            $sql = 'UPDATE fan_nomenclator_sync SET stare = "eroare", eroare = :eroare, eroare_la = :acum'
                . ($deLaCapat ? ', cursor_json = NULL' : '')
                . ' WHERE lista = :lista';
            $db->prepare($sql)->execute([
                'eroare' => mb_substr($mesaj, 0, 2000),
                'acum' => date('Y-m-d H:i:s'),
                'lista' => $lista,
            ]);
        } catch (Throwable) {
        }
    }

    private static function tabeleleDeLucruExista(PDO $db, string $lista): bool
    {
        foreach (self::TABELE[$lista] as $tabela) {
            try {
                $db->query('SELECT 1 FROM ' . $tabela . '__sync LIMIT 1');
            } catch (Throwable) {
                return false;
            }
        }
        return true;
    }

    /**
     * Pune tabelele-ciornă în locul celor folosite de site. RENAME TABLE cu
     * mai multe perechi e atomic: checkout-ul vede fie listele vechi, fie pe
     * cele noi, niciodată o tabelă lipsă sau pe jumătate.
     */
    private static function inlocuiesteTabele(PDO $db, array $tabele): void
    {
        $perechi = [];
        foreach ($tabele as $tabela) {
            $db->exec('DROP TABLE IF EXISTS ' . $tabela . '__vechi');
            $perechi[] = $tabela . ' TO ' . $tabela . '__vechi';
            $perechi[] = $tabela . '__sync TO ' . $tabela;
        }
        $db->exec('RENAME TABLE ' . implode(', ', $perechi));
        foreach ($tabele as $tabela) {
            try {
                $db->exec('DROP TABLE IF EXISTS ' . $tabela . '__vechi');
            } catch (Throwable) {
            }
        }
    }

    /**
     * Lacăt pe serverul MySQL, nu pe fișier: îl văd și cronul, și cererile
     * web, iar dacă procesul moare, MySQL îl eliberează singur. Numele poartă
     * amprenta bazei, fiindcă pe găzduirea comună serverul e împărțit; MD5 și
     * nu numele întreg, ca să nu treacă de cele 64 de caractere permise.
     */
    private static function iaLacat(PDO $db, string $lista): bool
    {
        try {
            $stmt = $db->prepare('SELECT GET_LOCK(' . self::NUME_LACAT . ', 0)');
            $stmt->execute(['lista' => $lista]);
            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private static function elibereazaLacat(PDO $db, string $lista): void
    {
        try {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(' . self::NUME_LACAT . ')');
            $stmt->execute(['lista' => $lista]);
        } catch (Throwable) {
        }
    }

    // ---------------------------------------------------------------------
    // Mărunțișuri
    // ---------------------------------------------------------------------

    /**
     * O cerere către FAN, cu două reîncercări la o eroare de rețea. Pe web se
     * reîncearcă doar dacă mai e timp în cerere: altfel pică cererea toată, iar
     * pasul se reia la apăsarea următoare.
     */
    private static function cuReincercari(callable $cerere, float $ramas, string $unde): mixed
    {
        $start = microtime(true);
        $ultima = null;
        for ($incercare = 1; $incercare <= 3; $incercare++) {
            try {
                return $cerere();
            } catch (RuntimeException $exception) {
                $ultima = $exception;
                if ($ramas > 0 && ($ramas - (microtime(true) - $start)) < 10) {
                    break;
                }
                if ($incercare < 3) {
                    usleep(1500000);
                }
            }
        }

        // Locul unde s-a rupt ajunge în jurnal: „Iasi: HTTP 500" spune mai
        // mult decât corpul gol al răspunsului.
        throw new RuntimeException(
            $unde . ': ' . ($ultima !== null ? $ultima->getMessage() : 'cererea către FAN a eșuat.'),
            0,
            $ultima
        );
    }

    /** @param list<list<mixed>> $randuri */
    private static function insereazaInLoturi(PDO $db, string $tabela, array $coloane, array $randuri, string $laDublura): void
    {
        $semne = '(' . implode(', ', array_fill(0, count($coloane), '?')) . ')';
        foreach (array_chunk($randuri, 250) as $lot) {
            $sql = 'INSERT INTO ' . $tabela . ' (' . implode(', ', $coloane) . ') VALUES '
                . implode(', ', array_fill(0, count($lot), $semne))
                . ' ON DUPLICATE KEY UPDATE ' . $laDublura;
            $db->prepare($sql)->execute(array_merge(...$lot));
        }
    }

    private static function numarKm(mixed $valoare): ?float
    {
        if ($valoare === null || $valoare === '') {
            return null;
        }
        $text = str_replace(',', '.', trim((string) $valoare));
        return is_numeric($text) ? (float) $text : null;
    }

    private static function progres(string $lista, array $job): string
    {
        $pas = (int) ($job['pas'] ?? 0);
        $total = (int) ($job['pasi_total'] ?? 0);
        $randuri = number_format((int) ($job['randuri'] ?? 0), 0, ',', '.');
        if ($lista === self::LOCALITATI) {
            return $total > 0
                ? 'județul ' . $pas . ' din ' . $total . ', ' . $randuri . ' localități până acum'
                : 'se cere lista de județe';
        }
        return $total > 0
            ? 'pagina ' . $pas . ' din ' . $total . ', ' . $randuri . ' străzi până acum'
            : 'pagina ' . $pas . ', ' . $randuri . ' străzi până acum';
    }

    private static function rezultat(string $stare, string $mesaj, array $job = []): array
    {
        return [
            'stare' => $stare,
            'mesaj' => $mesaj,
            'pas' => (int) ($job['pas'] ?? 0),
            'pasi_total' => (int) ($job['pasi_total'] ?? 0),
            'randuri' => (int) ($job['randuri'] ?? 0),
        ];
    }
}

