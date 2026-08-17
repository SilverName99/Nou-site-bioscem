<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Citește un dump SQL de WordPress (UpdraftPlus `*-db.gz`, mysqldump `.sql`)
 * și scoate din el conturile de client și abonații la newsletter.
 *
 * Fișierul se parcurge în FLUX, direct comprimat: dump-ul dezarhivat poate
 * trece de 4 GB, deci nimic nu se încarcă întreg în memorie. Reținem doar
 * rândurile care ne interesează, iar din `usermeta` doar cheile din listă —
 * altfel un magazin cu istoric mare ar umple memoria din meta de comenzi.
 *
 * Tabelele se recunosc după COLOANE, nu după nume: prefixul WordPress e de
 * multe ori aleator (`wpab12_`), iar numele singur nu e de încredere.
 */
final class WordPressDumpReader
{
    /** Chei din `usermeta` care ne interesează; restul se aruncă la citire. */
    private const CHEI_META = [
        'first_name', 'last_name', 'nickname',
        'billing_first_name', 'billing_last_name', 'billing_company',
        'billing_phone', 'billing_email',
        'billing_address_1', 'billing_address_2',
        'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
        'shipping_first_name', 'shipping_last_name', 'shipping_phone',
        'shipping_address_1', 'shipping_address_2',
        'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
    ];

    /** Roluri WordPress care NU sunt clienți — nu se importă. */
    private const ROLURI_INTERZISE = [
        'administrator', 'editor', 'author', 'contributor',
        'shop_manager', 'wpseo_manager', 'wpseo_editor', 'translator',
    ];

    /**
     * WooCommerce ține județul ca abreviere (`AG`, `B`), site-ul îl ține scris.
     * Fără harta asta, adresele importate ar arăta „Jud. AG".
     */
    private const JUDETE = [
        'AB' => 'Alba', 'AR' => 'Arad', 'AG' => 'Argeș', 'BC' => 'Bacău',
        'BH' => 'Bihor', 'BN' => 'Bistrița-Năsăud', 'BT' => 'Botoșani',
        'BV' => 'Brașov', 'BR' => 'Brăila', 'B' => 'București',
        'BZ' => 'Buzău', 'CS' => 'Caraș-Severin', 'CL' => 'Călărași',
        'CJ' => 'Cluj', 'CT' => 'Constanța', 'CV' => 'Covasna',
        'DB' => 'Dâmbovița', 'DJ' => 'Dolj', 'GL' => 'Galați',
        'GR' => 'Giurgiu', 'GJ' => 'Gorj', 'HR' => 'Harghita',
        'HD' => 'Hunedoara', 'IL' => 'Ialomița', 'IS' => 'Iași',
        'IF' => 'Ilfov', 'MM' => 'Maramureș', 'MH' => 'Mehedinți',
        'MS' => 'Mureș', 'NT' => 'Neamț', 'OT' => 'Olt', 'PH' => 'Prahova',
        'SM' => 'Satu Mare', 'SJ' => 'Sălaj', 'SB' => 'Sibiu',
        'SV' => 'Suceava', 'TR' => 'Teleorman', 'TM' => 'Timiș',
        'TL' => 'Tulcea', 'VS' => 'Vaslui', 'VL' => 'Vâlcea',
        'VN' => 'Vrancea',
    ];

    /**
     * Parcurge dump-ul o singură dată.
     *
     * @param callable|null $progres apelat periodic cu octeții citiți (comprimați)
     * @return array{ok:bool,message:string,prefix:string,utilizatori:array<int,array<string,mixed>>,abonati:array<int,array<string,string>>,statistici:array<string,int>,tabele:array<int,string>}
     */
    public static function citeste(string $cale, ?callable $progres = null): array
    {
        if (!is_file($cale) || !is_readable($cale)) {
            throw new RuntimeException('Fișierul nu există sau nu poate fi citit: ' . basename($cale));
        }
        // gzopen citește transparent și fișiere necomprimate, deci merge și pe .sql.
        $stream = @gzopen($cale, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Nu am putut deschide arhiva. Fișierul pare corupt.');
        }

        $prefix = '';
        $tabeleGasite = [];
        $tabelCurent = null;          // tabelul din CREATE TABLE în curs de citire
        $coloaneCurente = [];
        $rol = [];                    // tabel -> rol funcțional (users/usermeta/...)
        $coloane = [];                // tabel -> listă de coloane, în ordine

        $utilizatori = [];            // wpUserId -> rând
        $meta = [];                   // wpUserId -> [cheie => valoare]
        $abonati = [];                // email => rând
        $stat = ['linii' => 0, 'octeti' => 0, 'admini_sariti' => 0];

        while (($linie = gzgets($stream)) !== false) {
            $stat['linii']++;
            if ($progres !== null && ($stat['linii'] % 20000) === 0) {
                $progres(gztell($stream));
            }

            // ── Antetul tabelelor: din CREATE TABLE luăm ordinea coloanelor ──
            if ($tabelCurent !== null) {
                $capat = ltrim($linie);
                if ($capat !== '' && $capat[0] === ')') {
                    $rolTabel = self::clasifica($tabelCurent, $coloaneCurente);
                    if ($rolTabel !== null) {
                        $rol[$tabelCurent] = $rolTabel;
                        $coloane[$tabelCurent] = $coloaneCurente;
                        $tabeleGasite[] = $tabelCurent . ' (' . $rolTabel . ')';
                        if ($rolTabel === 'users' && $prefix === '') {
                            $prefix = substr($tabelCurent, 0, -strlen('users'));
                        }
                    }
                    $tabelCurent = null;
                    $coloaneCurente = [];
                    continue;
                }
                if (preg_match('/^\s*`([^`]+)`\s/', $linie, $m) === 1) {
                    $coloaneCurente[] = strtolower($m[1]);
                }
                continue;
            }

            if (preg_match('/^\s*CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/i', $linie, $m) === 1) {
                $tabelCurent = strtolower($m[1]);
                $coloaneCurente = [];
                continue;
            }

            // ── Datele ──
            if (preg_match('/^\s*INSERT INTO `([^`]+)`\s*(\([^)]*\))?\s*VALUES\s*/i', $linie, $m) !== 1) {
                continue;
            }
            $tabel = strtolower($m[1]);
            if (!isset($rol[$tabel])) {
                continue;
            }
            $stat['octeti'] += strlen($linie);

            // Dacă INSERT-ul are lista lui de coloane, ea are prioritate.
            $ordine = $coloane[$tabel];
            if (($m[2] ?? '') !== '') {
                $explicite = [];
                foreach (explode(',', trim((string) $m[2], '() ')) as $bucata) {
                    $explicite[] = strtolower(trim(trim($bucata), '` '));
                }
                if ($explicite !== []) {
                    $ordine = $explicite;
                }
            }

            $segment = substr($linie, strlen($m[0]));
            foreach (self::tupluri($segment) as $valori) {
                $rand = self::asociaza($ordine, $valori);
                switch ($rol[$tabel]) {
                    case 'users':
                        $id = (int) ($rand['id'] ?? 0);
                        $email = strtolower(trim((string) ($rand['user_email'] ?? '')));
                        if ($id <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            break;
                        }
                        $utilizatori[$id] = [
                            'email' => $email,
                            'password_hash' => (string) ($rand['user_pass'] ?? ''),
                            'display_name' => trim((string) ($rand['display_name'] ?? '')),
                            'created_at' => trim((string) ($rand['user_registered'] ?? '')),
                        ];
                        break;

                    case 'usermeta':
                        $cheie = strtolower(trim((string) ($rand['meta_key'] ?? '')));
                        $userId = (int) ($rand['user_id'] ?? 0);
                        if ($userId <= 0 || $cheie === '') {
                            break;
                        }
                        $eCapabilities = str_ends_with($cheie, 'capabilities');
                        if (!$eCapabilities && !in_array($cheie, self::CHEI_META, true)) {
                            break;
                        }
                        $meta[$userId][$eCapabilities ? 'capabilities' : $cheie] =
                            (string) ($rand['meta_value'] ?? '');
                        break;

                    default: // tabelele de newsletter
                        $abonat = self::abonat($rol[$tabel], $rand);
                        if ($abonat !== null && !isset($abonati[$abonat['email']])) {
                            $abonati[$abonat['email']] = $abonat;
                        }
                        break;
                }
            }
        }
        gzclose($stream);

        if ($utilizatori === [] && $abonati === []) {
            return [
                'ok' => false,
                'message' => 'Nu am găsit nici tabelul de utilizatori, nici vreo listă de abonați în acest fișier. '
                    . 'Verifică dacă e chiar arhiva „-db.gz" (celelalte conțin doar fișiere, nu baza de date).',
                'prefix' => $prefix,
                'utilizatori' => [],
                'abonati' => [],
                'statistici' => $stat,
                'tabele' => $tabeleGasite,
            ];
        }

        // Îmbinăm meta peste utilizatori și scoatem conturile de administrare.
        $finali = [];
        foreach ($utilizatori as $id => $u) {
            $m = $meta[$id] ?? [];
            if (self::esteContDeAdministrare((string) ($m['capabilities'] ?? ''))) {
                $stat['admini_sariti']++;
                continue;
            }
            $finali[] = self::compune($u, $m);
        }

        $stat['utilizatori'] = count($finali);
        $stat['abonati'] = count($abonati);

        return [
            'ok' => true,
            'message' => '',
            'prefix' => $prefix,
            'utilizatori' => $finali,
            'abonati' => array_values($abonati),
            'statistici' => $stat,
            'tabele' => $tabeleGasite,
        ];
    }

    /** Rolul funcțional al unui tabel, după coloanele lui (null = ignorat). */
    private static function clasifica(string $tabel, array $coloane): ?string
    {
        $are = static fn (string ...$cerute): bool => array_diff($cerute, $coloane) === [];

        if ($are('user_email', 'user_pass') && str_ends_with($tabel, 'users')) {
            return 'users';
        }
        if ($are('user_id', 'meta_key', 'meta_value') && str_ends_with($tabel, 'usermeta')) {
            return 'usermeta';
        }
        // Newsletter (Stefano Lissa): status C=confirmat, U=dezabonat, S=neconfirmat.
        if (str_ends_with($tabel, 'newsletter') && $are('email', 'status', 'name')) {
            return 'nl_newsletter';
        }
        if (str_ends_with($tabel, 'mailpoet_subscribers') && $are('email', 'status')) {
            return 'nl_mailpoet';
        }
        if (str_ends_with($tabel, 'mailster_subscribers') && $are('email', 'status')) {
            return 'nl_mailster';
        }
        if (str_ends_with($tabel, 'wysija_user') && $are('email', 'status')) {
            return 'nl_wysija';
        }
        return null;
    }

    /**
     * Un abonat activ din tabelul de newsletter. Dezabonații și adresele moarte
     * NU se întorc: cine și-a retras consimțământul rămâne retras.
     */
    private static function abonat(string $rol, array $rand): ?array
    {
        $email = strtolower(trim((string) ($rand['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $status = trim((string) ($rand['status'] ?? ''));
        $activ = match ($rol) {
            'nl_newsletter' => strtoupper($status) === 'C',
            'nl_mailpoet' => strtolower($status) === 'subscribed',
            'nl_mailster' => $status === '1',
            'nl_wysija' => $status === '1',
            default => false,
        };
        if (!$activ) {
            return null;
        }

        $nume = trim(
            trim((string) ($rand['name'] ?? $rand['first_name'] ?? $rand['firstname'] ?? ''))
            . ' '
            . trim((string) ($rand['surname'] ?? $rand['last_name'] ?? $rand['lastname'] ?? ''))
        );

        return ['email' => $email, 'name' => $nume, 'sursa' => $rol];
    }

    /** Rândul final pentru import, în forma așteptată de importul existent. */
    private static function compune(array $u, array $m): array
    {
        $prenume = trim((string) ($m['first_name'] ?? '')) !== ''
            ? trim((string) $m['first_name'])
            : trim((string) ($m['billing_first_name'] ?? ''));
        $nume = trim((string) ($m['last_name'] ?? '')) !== ''
            ? trim((string) $m['last_name'])
            : trim((string) ($m['billing_last_name'] ?? ''));

        // Fără nume în meta, îl despărțim din display_name („Ion Popescu").
        if ($prenume === '' && ($u['display_name'] ?? '') !== '') {
            $bucati = preg_split('/\s+/', (string) $u['display_name']) ?: [];
            $prenume = rtrim(trim((string) ($bucati[0] ?? '')), ',;');
            $nume = trim(implode(' ', array_slice($bucati, 1)));
        }

        $telefon = trim((string) ($m['billing_phone'] ?? ''));
        if ($telefon === '') {
            $telefon = trim((string) ($m['shipping_phone'] ?? ''));
        }

        return [
            'email' => $u['email'],
            'password_hash' => $u['password_hash'],
            'first_name' => $prenume,
            'last_name' => $nume,
            'phone' => $telefon,
            'gender' => '',
            'birth_date' => '',
            'created_at' => (string) ($u['created_at'] ?? ''),
            'adresa' => self::adresa($m, trim($prenume . ' ' . $nume), $telefon),
        ];
    }

    /** Adresa de facturare, dacă are măcar stradă și localitate. */
    private static function adresa(array $m, string $numeComplet, string $telefon): ?array
    {
        $strada = trim((string) ($m['billing_address_1'] ?? ''));
        $oras = trim((string) ($m['billing_city'] ?? ''));
        if ($strada === '' || $oras === '') {
            return null;
        }
        $judetCod = strtoupper(trim((string) ($m['billing_state'] ?? '')));

        return [
            'label' => 'Adresă din site-ul vechi',
            'full_name' => $numeComplet,
            'phone' => $telefon,
            'address_line1' => $strada,
            'address_line2' => trim((string) ($m['billing_address_2'] ?? '')),
            'city' => $oras,
            'county' => self::JUDETE[$judetCod] ?? $judetCod,
            'postcode' => trim((string) ($m['billing_postcode'] ?? '')),
        ];
    }

    /** `wp_capabilities` e un array serializat; ne uităm doar după rolurile grele. */
    private static function esteContDeAdministrare(string $capabilities): bool
    {
        if ($capabilities === '') {
            return false;
        }
        foreach (self::ROLURI_INTERZISE as $rol) {
            if (str_contains($capabilities, '"' . $rol . '"')) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,string|null> */
    private static function asociaza(array $coloane, array $valori): array
    {
        $rand = [];
        foreach ($coloane as $i => $nume) {
            $rand[$nume] = $valori[$i] ?? null;
        }
        return $rand;
    }

    /**
     * Desparte partea de după `VALUES` în tupluri, respectând ghilimelele și
     * secvențele escapate — o virgulă dintr-o adresă nu trebuie să rupă rândul.
     *
     * @return iterable<int,array<int,string|null>>
     */
    private static function tupluri(string $segment): iterable
    {
        $lungime = strlen($segment);
        $i = 0;
        while ($i < $lungime) {
            // Sărim până la începutul tuplului.
            while ($i < $lungime && $segment[$i] !== '(') {
                if ($segment[$i] === ';') {
                    return;
                }
                $i++;
            }
            if ($i >= $lungime) {
                return;
            }
            $i++; // trecem de '('

            $valori = [];
            $curent = '';
            $eText = false;
            $inGhilimele = false;

            while ($i < $lungime) {
                $c = $segment[$i];

                if ($inGhilimele) {
                    if ($c === '\\' && $i + 1 < $lungime) {
                        $curent .= self::deEscapeaza($segment[$i + 1]);
                        $i += 2;
                        continue;
                    }
                    if ($c === "'") {
                        // „''" înseamnă un apostrof, nu sfârșitul textului.
                        if ($i + 1 < $lungime && $segment[$i + 1] === "'") {
                            $curent .= "'";
                            $i += 2;
                            continue;
                        }
                        $inGhilimele = false;
                        $i++;
                        continue;
                    }
                    $curent .= $c;
                    $i++;
                    continue;
                }

                if ($c === "'") {
                    $inGhilimele = true;
                    $eText = true;
                    $i++;
                    continue;
                }
                if ($c === ',') {
                    $valori[] = self::finalizeaza($curent, $eText);
                    $curent = '';
                    $eText = false;
                    $i++;
                    continue;
                }
                if ($c === ')') {
                    $valori[] = self::finalizeaza($curent, $eText);
                    $i++;
                    break;
                }
                $curent .= $c;
                $i++;
            }

            yield $valori;
        }
    }

    private static function finalizeaza(string $brut, bool $eText): ?string
    {
        if ($eText) {
            return $brut;
        }
        $curatat = trim($brut);
        return strcasecmp($curatat, 'NULL') === 0 ? null : $curatat;
    }

    private static function deEscapeaza(string $c): string
    {
        return match ($c) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            '0' => "\0",
            'b' => "\x08",
            'Z' => "\x1a",
            default => $c,
        };
    }
}
