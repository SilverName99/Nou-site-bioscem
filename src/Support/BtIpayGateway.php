<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Banca Transilvania iPay — clientul API.
 *
 * Totul e copiat după modulul oficial BT pentru Magento (btrl/ipay 100.0.2):
 * aceleași adrese (`/payment/rest/*.do`), aceleași câmpuri, sume în bani,
 * moneda numerică (RON = 946, punctele STAR = „LOY" = 777) și aceeași ordine
 * la operațiile pe plățile cu puncte: întâi comanda LOY, apoi cea pe card.
 *
 * Datele de acces NU stau în baza de date, ci în fișierul `.env` de pe server,
 * separat pentru test (sandbox) și producție. Parola se adaugă în cerere abia
 * după ce cererea a fost scrisă în jurnal, deci nu ajunge niciodată acolo
 * (exact ca în modulul BT).
 */
final class BtIpayGateway
{
    public const URL_TEST = 'https://ecclients-sandbox.btrl.ro';
    public const URL_LIVE = 'https://ecclients.btrl.ro';
    private const CALE_API = '/payment/rest/';

    public const MOD_TEST = 'test';
    public const MOD_LIVE = 'live';

    /** Variabilele din `.env`. */
    public const ENV_MOD = 'BT_IPAY_MODE';
    public const ENV_URL_OVERRIDE = 'BT_IPAY_BASE_URL_OVERRIDE';
    public const ENV_VARIABILE = [
        self::MOD_TEST => [
            'user' => 'BT_IPAY_TEST_USER',
            'pass' => 'BT_IPAY_TEST_PASS',
            'cheie' => 'BT_IPAY_TEST_CALLBACK_KEY',
        ],
        self::MOD_LIVE => [
            'user' => 'BT_IPAY_LIVE_USER',
            'pass' => 'BT_IPAY_LIVE_PASS',
            'cheie' => 'BT_IPAY_LIVE_CALLBACK_KEY',
        ],
    ];

    public const MONEDA_RON = 946;
    public const MONEDA_LOY = 777;
    public const TARA_RO = 642;

    /** `orderStatus` din getOrderStatusExtended (modulul BT, PaymentStatus.php). */
    public const STATUS_INREGISTRATA = 0;
    public const STATUS_AUTORIZATA = 1;
    public const STATUS_INCASATA = 2;
    public const STATUS_AUTORIZARE_ANULATA = 3;
    public const STATUS_RAMBURSATA = 4;
    public const STATUS_ACS = 5;
    public const STATUS_REFUZATA = 6;
    public const STATUS_RAMBURSATA_PARTIAL = 7;

    /** Lungimea maximă a `orderNumber` acceptată de platforma BT. */
    public const LUNGIME_MAX_NUMAR = 32;

    /** Operațiile permise și adresele lor. Nimic altceva nu pleacă spre bancă. */
    private const ACTIUNI = [
        'registerPreAuth' => 'registerPreAuth.do',
        'register' => 'register.do',
        'getOrderStatusExtended' => 'getOrderStatusExtended.do',
        'deposit' => 'deposit.do',
        'reverse' => 'reverse.do',
        'refund' => 'refund.do',
    ];

    // ------------------------------------------------------------------
    // Configurare (.env)
    // ------------------------------------------------------------------

    /** Modul curent: „live" doar dacă e cerut explicit; orice altceva e test. */
    public static function mod(): string
    {
        $valoare = strtolower(trim((string) Env::get(self::ENV_MOD, '')));
        return in_array($valoare, ['live', 'productie', 'producție', 'production', 'prod'], true)
            ? self::MOD_LIVE
            : self::MOD_TEST;
    }

    /** Valoarea scrisă în `.env` pentru mod e una pe care o înțelegem? */
    public static function modRecunoscut(): bool
    {
        $valoare = strtolower(trim((string) Env::get(self::ENV_MOD, '')));
        return in_array($valoare, ['', 'test', 'live', 'productie', 'producție', 'production', 'prod'], true);
    }

    public static function modValid(string $mod): string
    {
        return $mod === self::MOD_LIVE ? self::MOD_LIVE : self::MOD_TEST;
    }

    /** @return array{user: string, pass: string, cheie: string} */
    private static function credentiale(string $mod): array
    {
        $nume = self::ENV_VARIABILE[self::modValid($mod)];
        return [
            'user' => trim((string) Env::get($nume['user'], '')),
            'pass' => (string) Env::get($nume['pass'], ''),
            'cheie' => trim((string) Env::get($nume['cheie'], '')),
        ];
    }

    /** Utilizatorul și parola pentru modul dat există? */
    public static function esteConfigurat(string $mod): bool
    {
        $c = self::credentiale($mod);
        return $c['user'] !== '' && $c['pass'] !== '';
    }

    /**
     * Ce e completat în `.env`, fără valori: doar „configurat" / „lipsește".
     *
     * @return array<string, array<string, array{variabila: string, configurat: bool, avertisment: string}>>
     */
    public static function stareVariabile(): array
    {
        $rez = [];
        foreach (self::ENV_VARIABILE as $mod => $variabile) {
            foreach ($variabile as $tip => $nume) {
                $valoare = trim((string) Env::get($nume, ''));
                $avertisment = '';
                if ($tip === 'cheie' && $valoare !== '' && !self::cheieBase64Valida($valoare)) {
                    $avertisment = 'nu pare o cheie Base64 întreagă — verifică dacă ai copiat-o complet';
                }
                $rez[$mod][$tip] = [
                    'variabila' => $nume,
                    'configurat' => $valoare !== '',
                    'avertisment' => $avertisment,
                ];
            }
        }
        return $rez;
    }

    /** Calea absolută a fișierului `.env` citit de site (bootstrap.php). */
    public static function caleEnv(): string
    {
        $radacina = realpath(dirname(__DIR__, 2));
        return ($radacina !== false ? $radacina : dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR . '.env';
    }

    /**
     * Adresa băncii. `BT_IPAY_BASE_URL_OVERRIDE` există DOAR pentru teste (un
     * server care imită banca); pe site-ul live rămâne necompletat.
     */
    public static function urlBaza(string $mod): string
    {
        $override = self::urlOverride();
        if ($override !== '') {
            return $override;
        }
        return self::modValid($mod) === self::MOD_LIVE ? self::URL_LIVE : self::URL_TEST;
    }

    /** Adresa de test din `.env`, dacă e o adresă http(s) validă; altfel gol. */
    public static function urlOverride(): string
    {
        $valoare = rtrim(trim((string) Env::get(self::ENV_URL_OVERRIDE, '')), '/');
        if ($valoare === '') {
            return '';
        }
        $parti = parse_url($valoare);
        if (!is_array($parti) || !in_array(strtolower((string) ($parti['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parti['host'] ?? '')) === '' || isset($parti['user']) || isset($parti['query'])) {
            return '';
        }
        return $valoare;
    }

    /**
     * Adresa paginii de plată primite de la bancă e chiar a băncii? Clientul e
     * trimis acolo, deci nu acceptăm decât gazdele BT (sau serverul de test).
     */
    public static function urlPlataValid(string $url, string $mod): bool
    {
        $parti = parse_url($url);
        if (!is_array($parti)) {
            return false;
        }
        $schema = strtolower((string) ($parti['scheme'] ?? ''));
        $gazda = strtolower((string) ($parti['host'] ?? ''));
        if ($gazda === '' || isset($parti['user'])) {
            return false;
        }
        $override = self::urlOverride();
        if ($override !== '') {
            $gazdaOverride = strtolower((string) parse_url($override, PHP_URL_HOST));
            return in_array($schema, ['http', 'https'], true) && $gazda === $gazdaOverride;
        }
        return $schema === 'https' && ($gazda === 'btrl.ro' || str_ends_with($gazda, '.btrl.ro'));
    }

    // ------------------------------------------------------------------
    // Apelul către bancă
    // ------------------------------------------------------------------

    /**
     * Un apel către BT. Niciodată nu aruncă: întoarce rezultatul și eroarea.
     *
     * @param array<string, scalar> $parametri fără userName/password
     * @param array{tx_id?: int, order_id?: int, sursa?: string} $context
     * @return array{ok: bool, http: int, date: array<string, mixed>, eroare: string, cod: string}
     */
    public static function apel(string $mod, string $actiune, array $parametri, ?PDO $db = null, array $context = [], int $timeout = 30): array
    {
        $mod = self::modValid($mod);
        $rezultat = ['ok' => false, 'http' => 0, 'date' => [], 'eroare' => '', 'cod' => ''];

        if (!isset(self::ACTIUNI[$actiune])) {
            $rezultat['eroare'] = 'Operație BT necunoscută.';
            return $rezultat;
        }
        if (!function_exists('curl_init')) {
            $rezultat['eroare'] = 'Extensia cURL lipsește pe server.';
            return $rezultat;
        }
        $cred = self::credentiale($mod);
        if ($cred['user'] === '' || $cred['pass'] === '') {
            $rezultat['eroare'] = 'Lipsesc datele de acces BT pentru modul ' . ($mod === self::MOD_LIVE ? 'producție' : 'test')
                . ' (' . self::ENV_VARIABILE[$mod]['user'] . ' / ' . self::ENV_VARIABILE[$mod]['pass'] . ' în .env).';
            self::jurnal($db, $mod, $actiune, $parametri, 0, null, '', 0, $rezultat['eroare'], $context);
            return $rezultat;
        }

        $url = self::urlBaza($mod) . self::CALE_API . self::ACTIUNI[$actiune];
        $deTrimis = $parametri;
        // Ca în modulul BT: credențialele intră în cerere după jurnalizare.
        $deTrimis['userName'] = $cred['user'];
        $deTrimis['password'] = $cred['pass'];

        $start = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            $rezultat['eroare'] = 'Nu am putut deschide conexiunea către BT.';
            return $rezultat;
        }
        $protocoale = str_starts_with($url, 'https://') ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($deTrimis, '', '&', PHP_QUERY_RFC1738),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
            ],
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $protocoale,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'bioscem-shop/bt-ipay',
        ]);
        $corp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $eroareCurl = curl_error($ch);
        curl_close($ch);
        unset($deTrimis);
        $durata = (int) round((microtime(true) - $start) * 1000);

        $rezultat['http'] = $http;
        if ($corp === false) {
            $rezultat['eroare'] = 'Banca nu a răspuns: ' . ($eroareCurl !== '' ? $eroareCurl : 'eroare de rețea') . '.';
            self::jurnal($db, $mod, $actiune, $parametri, $http, null, '', $durata, $rezultat['eroare'], $context);
            return $rezultat;
        }

        $date = json_decode((string) $corp, true);
        if (!is_array($date)) {
            $rezultat['eroare'] = 'Răspuns neașteptat de la BT (HTTP ' . $http . ').';
            self::jurnal($db, $mod, $actiune, $parametri, $http, ['raspuns_brut' => mb_substr((string) $corp, 0, 500)], '', $durata, $rezultat['eroare'], $context);
            return $rezultat;
        }
        $rezultat['date'] = $date;

        $cod = trim((string) ($date['errorCode'] ?? '0'));
        $rezultat['cod'] = $cod;
        if ($http < 200 || $http >= 300) {
            $rezultat['eroare'] = 'BT a răspuns cu HTTP ' . $http . '.';
        } elseif ($cod !== '' && $cod !== '0') {
            $rezultat['eroare'] = self::mesajEroareApi($actiune, (int) $cod, (string) ($date['errorMessage'] ?? ''));
        } else {
            $rezultat['ok'] = true;
        }

        self::jurnal($db, $mod, $actiune, $parametri, $http, $date, $cod, $durata, $rezultat['eroare'], $context);
        return $rezultat;
    }

    /**
     * Scrie apelul în `bt_ipay_log`, fără parolă și fără date de card.
     *
     * @param array<string, mixed> $cerere
     * @param array<string, mixed>|null $raspuns
     * @param array{tx_id?: int, order_id?: int, sursa?: string} $context
     */
    public static function jurnal(
        ?PDO $db,
        string $mod,
        string $actiune,
        array $cerere,
        int $http,
        ?array $raspuns,
        string $cod,
        int $durataMs,
        string $mesaj,
        array $context = []
    ): void {
        if (!$db instanceof PDO) {
            return;
        }
        try {
            $db->prepare(
                'INSERT INTO bt_ipay_log
                    (tx_id, order_id, mode, action, source, http_code, error_code, duration_ms,
                     request_json, response_json, message, created_at)
                 VALUES
                    (:tx_id, :order_id, :mode, :action, :source, :http_code, :error_code, :duration_ms,
                     :request_json, :response_json, :message, :created_at)'
            )->execute([
                'tx_id' => ($context['tx_id'] ?? 0) > 0 ? (int) $context['tx_id'] : null,
                'order_id' => ($context['order_id'] ?? 0) > 0 ? (int) $context['order_id'] : null,
                'mode' => substr($mod, 0, 4),
                'action' => substr($actiune, 0, 40),
                'source' => substr((string) ($context['sursa'] ?? ''), 0, 30),
                'http_code' => $http > 0 ? $http : null,
                'error_code' => $cod !== '' ? substr($cod, 0, 20) : null,
                'duration_ms' => max(0, $durataMs),
                'request_json' => self::json(self::redacteaza($cerere)),
                'response_json' => $raspuns !== null ? self::json(self::redacteaza($raspuns)) : null,
                'message' => $mesaj !== '' ? mb_substr($mesaj, 0, 500) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // Jurnalul nu are voie să oprească o plată.
        }
    }

    /**
     * Scoate din orice structură parolele, cheile și datele cardului.
     */
    public static function redacteaza(mixed $date): mixed
    {
        if (!is_array($date)) {
            return $date;
        }
        $ascunse = [
            'username', 'password', 'pass', 'parola', 'token', 'jwt', 'cheie', 'secret',
            'maskedpan', 'pan', 'expiration', 'cardholdername', 'cvc', 'cvv', 'cvv2',
            'bindinginfo', 'bindingid', 'secureauthinfo', 'cavv', 'xid',
        ];
        $out = [];
        foreach ($date as $cheie => $valoare) {
            if (is_string($cheie) && in_array(strtolower($cheie), $ascunse, true)) {
                $out[$cheie] = '[ascuns]';
                continue;
            }
            $out[$cheie] = is_array($valoare) ? self::redacteaza($valoare) : $valoare;
        }
        return $out;
    }

    private static function json(mixed $date): string
    {
        $text = json_encode($date, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $text === false ? '' : mb_substr($text, 0, 60000);
    }

    // ------------------------------------------------------------------
    // Cererea de înregistrare (registerPreAuth.do)
    // ------------------------------------------------------------------

    /**
     * Câmpurile pentru registerPreAuth.do, ca în PaymentDataBuilder.php.
     *
     * @param array{
     *     numar: string, suma: int, return_url: string, descriere: string, email?: string,
     *     telefon?: string, nume?: string, oras_facturare?: string, adresa_facturare?: string,
     *     oras_livrare?: string, adresa_livrare?: string, user_agent?: string
     * } $date
     * @return array<string, string>
     */
    public static function cerereInregistrare(array $date): array
    {
        $email = trim((string) ($date['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }

        $cerere = [
            'orderNumber' => (string) $date['numar'],
            'amount' => (string) max(0, (int) $date['suma']),
            'currency' => (string) self::MONEDA_RON,
            'returnUrl' => (string) $date['return_url'],
            'description' => self::taie(self::translitereaza((string) $date['descriere']), 99),
            'language' => 'ro',
            'pageView' => self::dispozitiv((string) ($date['user_agent'] ?? '')),
        ];
        if ($email !== '') {
            $cerere['email'] = $email;
        }

        $client = ['email' => $email];
        $telefon = self::telefon((string) ($date['telefon'] ?? ''));
        if ($telefon !== '') {
            $client['phone'] = $telefon;
        }
        $nume = self::taie(self::translitereaza((string) ($date['nume'] ?? '')), 100);
        if ($nume !== '') {
            $client['contact'] = $nume;
        }
        $orasFacturare = self::taie(self::translitereaza((string) ($date['oras_facturare'] ?? '')), 50);
        $adresaFacturare = self::taie(self::translitereaza((string) ($date['adresa_facturare'] ?? '')), 255);
        $orasLivrare = self::taie(self::translitereaza((string) ($date['oras_livrare'] ?? $date['oras_facturare'] ?? '')), 50);
        $adresaLivrare = self::taie(self::translitereaza((string) ($date['adresa_livrare'] ?? $date['adresa_facturare'] ?? '')), 255);
        if ($orasLivrare !== '' || $adresaLivrare !== '') {
            $client['deliveryInfo'] = [
                'deliveryType' => 'courier',
                'country' => self::TARA_RO,
                'city' => $orasLivrare,
                'postAddress' => $adresaLivrare,
            ];
        }
        if ($orasFacturare !== '' || $adresaFacturare !== '') {
            $client['billingInfo'] = [
                'country' => self::TARA_RO,
                'city' => $orasFacturare,
                'postAddress' => $adresaFacturare,
            ];
        }
        if ($email === '') {
            unset($client['email']);
        }

        $bundle = json_encode(
            ['orderCreationDate' => date('Y-m-d'), 'customerDetails' => $client],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($bundle !== false) {
            $cerere['orderBundle'] = $bundle;
        }

        return $cerere;
    }

    /**
     * Telefonul cum îl vrea BT: doar cifre, fără zerouri în față, cu prefixul
     * țării (România: 407xxxxxxxx). Port după Helper/Phone.php.
     */
    public static function telefon(string $telefon, string $prefix = '40'): string
    {
        $cifre = preg_replace('/\D+/', '', $telefon) ?? '';
        if ($cifre === '') {
            return '';
        }
        $radacina = ltrim($cifre, '0');
        if ($radacina === '') {
            return '';
        }
        if (!str_starts_with($radacina, $prefix)) {
            // „07xx…": ultima cifră a prefixului e chiar primul zero.
            if ($prefix[strlen($prefix) - 1] === $cifre[0] && strlen($cifre) >= 9) {
                $radacina = substr($cifre, -9);
            }
            $radacina = $prefix . $radacina;
        }
        return substr($radacina, 0, 20);
    }

    /**
     * Text doar ASCII, cum îl cere BT pe pagina de plată și pe extras. Hartă
     * proprie (cu ș/ş, ț/ţ, ghilimele românești), fără să depindem de
     * `iconv //TRANSLIT`, care se poartă diferit de la server la server.
     */
    public static function translitereaza(string $text): string
    {
        $harta = [
            'ă' => 'a', 'â' => 'a', 'á' => 'a', 'à' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ª' => 'a',
            'Ă' => 'A', 'Â' => 'A', 'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'î' => 'i', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'Î' => 'I', 'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ő' => 'o', 'º' => 'o',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ő' => 'O',
            'ș' => 's', 'ş' => 's', 'š' => 's', 'Ș' => 'S', 'Ş' => 'S', 'Š' => 'S',
            'ț' => 't', 'ţ' => 't', 'Ț' => 'T', 'Ţ' => 'T',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ű' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ű' => 'U',
            'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N', 'ß' => 'ss', 'ž' => 'z', 'Ž' => 'Z', 'č' => 'c', 'Č' => 'C',
            '–' => '-', '—' => '-', '’' => ' ', '‘' => ' ', '‹' => ' ', '›' => ' ', '‚' => ' ',
            '“' => ' ', '”' => ' ', '«' => ' ', '»' => ' ', '„' => ' ', "\u{00A0}" => ' ',
        ];
        $text = strtr($text, $harta);
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** DESKTOP sau MOBILE, după User-Agent (Helper/UserData.php). */
    public static function dispozitiv(string $userAgent): string
    {
        return preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $userAgent) === 1
            ? 'MOBILE'
            : 'DESKTOP';
    }

    private static function taie(string $text, int $max): string
    {
        return strlen($text) > $max ? rtrim(substr($text, 0, $max)) : $text;
    }

    // ------------------------------------------------------------------
    // Citirea stării (getOrderStatusExtended.do)
    // ------------------------------------------------------------------

    /** Partea plătită în puncte STAR, în bani (merchantOrderParams.loyaltyAmount). */
    public static function puncteLoy(array $stare): int
    {
        foreach ((array) ($stare['merchantOrderParams'] ?? []) as $param) {
            if (is_array($param) && ($param['name'] ?? null) === 'loyaltyAmount' && isset($param['value'])) {
                return max(0, (int) $param['value']);
            }
        }
        return 0;
    }

    /** Id-ul comenzii LOY separate, din attributes.loyalties („…mdOrder:<id>…"). */
    public static function comandaLoy(array $stare): string
    {
        foreach ((array) ($stare['attributes'] ?? []) as $atribut) {
            if (!is_array($atribut) || ($atribut['name'] ?? null) !== 'loyalties' || !isset($atribut['value'])) {
                continue;
            }
            $valoare = trim((string) $atribut['value'], '{[]} ');
            foreach (explode(',', $valoare) as $bucata) {
                $date = explode(':', $bucata, 2);
                if (trim($date[0]) === 'mdOrder' && isset($date[1])) {
                    $id = trim($date[1], " \t\"'");
                    if (preg_match('/^[A-Za-z0-9\-]{1,64}$/', $id) === 1) {
                        return $id;
                    }
                }
            }
        }
        return '';
    }

    /** Cât s-a încasat pe comanda dată (paymentAmountInfo.depositedAmount), în bani. */
    public static function sumaIncasata(array $stare): int
    {
        return max(0, (int) ($stare['paymentAmountInfo']['depositedAmount'] ?? 0));
    }

    /** Cât s-a rambursat pe comanda dată, în bani (suma din refunds[]). */
    public static function sumaRambursata(array $stare): int
    {
        $total = 0;
        $gasite = false;
        foreach ((array) ($stare['refunds'] ?? []) as $rambursare) {
            if (is_array($rambursare) && isset($rambursare['amount'])) {
                $total += max(0, (int) $rambursare['amount']);
                $gasite = true;
            }
        }
        if (!$gasite) {
            $total = max(0, (int) ($stare['paymentAmountInfo']['refundedAmount'] ?? 0));
        }
        return $total;
    }

    /** Referința ultimei rambursări (referenceNumber), pentru evidență. */
    public static function referintaRambursare(array $stare): string
    {
        $lista = array_values(array_filter((array) ($stare['refunds'] ?? []), 'is_array'));
        if ($lista === []) {
            return '';
        }
        $ultima = $lista[count($lista) - 1];
        return substr(trim((string) ($ultima['referenceNumber'] ?? '')), 0, 64);
    }

    // ------------------------------------------------------------------
    // Callback (JWT HS256)
    // ------------------------------------------------------------------

    /** Cheile de callback configurate: întâi cea a modului curent. @return list<string> */
    public static function cheiCallback(): array
    {
        $mod = self::mod();
        $ordine = $mod === self::MOD_LIVE ? [self::MOD_LIVE, self::MOD_TEST] : [self::MOD_TEST, self::MOD_LIVE];
        $chei = [];
        foreach ($ordine as $m) {
            $cheie = self::credentiale($m)['cheie'];
            if ($cheie !== '') {
                $chei[] = $cheie;
            }
        }
        return array_values(array_unique($chei));
    }

    /** Cheia din `.env` (Base64) transformată în octeți; gol dacă nu se poate. */
    private static function cheieDecodata(string $cheieBase64): string
    {
        $curata = preg_replace('/\s+/', '', $cheieBase64) ?? '';
        if ($curata === '') {
            return '';
        }
        // Base64 obișnuit sau varianta „URL-safe"; octeții rezultați sunt aceiași.
        $octeti = base64_decode(strtr($curata, '-_', '+/'), true);
        if (!is_string($octeti) || $octeti === '') {
            // Ca modulul BT (base64_decode fără mod strict): caracterele din afara
            // alfabetului se ignoră, ca o cheie copiată cu ceva în plus să meargă
            // la fel ca în Magento. Tab-ul BT arată oricum un avertisment.
            $octeti = base64_decode($curata);
        }
        return is_string($octeti) ? $octeti : '';
    }

    /** Cheia arată ca o cheie Base64 întreagă (cel puțin 16 octeți)? Doar pentru avertismentul din admin. */
    private static function cheieBase64Valida(string $cheieBase64): bool
    {
        $curata = preg_replace('/\s+/', '', $cheieBase64) ?? '';
        $octeti = base64_decode(strtr($curata, '-_', '+/'), true);
        return is_string($octeti) && strlen($octeti) >= 16;
    }

    /**
     * Verifică un JWT de la BT: semnătură HS256 cu cheia Base64 din `.env`,
     * apoi `nbf`/`iat`/`exp` cu toleranță de 60 de secunde (ca modulul BT, care
     * folosește firebase/php-jwt cu `leeway = 60`).
     *
     * Antetul trebuie să spună exact `HS256`: `none`, `HS384`, `RS256` sau
     * orice altceva sunt respinse, ca un atacator să nu poată alege el algoritmul.
     *
     * @param list<string> $chei cheile Base64 acceptate
     * @return array<string, mixed>|null conținutul (claims) sau null dacă nu e valid
     */
    public static function verificaJwt(string $jwt, array $chei, int $toleranta = 60, ?int $acum = null): ?array
    {
        $jwt = trim($jwt);
        if ($jwt === '' || strlen($jwt) > 16384) {
            return null;
        }
        $parti = explode('.', $jwt);
        if (count($parti) !== 3) {
            return null;
        }
        [$antet64, $continut64, $semnatura64] = $parti;

        $antet = json_decode((string) self::base64UrlDecode($antet64), true);
        if (!is_array($antet) || !isset($antet['alg']) || !is_string($antet['alg']) || $antet['alg'] !== 'HS256') {
            return null;
        }
        // Extensii „critice" pe care nu le înțelegem = token respins (RFC 7515).
        if (isset($antet['crit'])) {
            return null;
        }

        $semnatura = self::base64UrlDecode($semnatura64);
        if ($semnatura === null || strlen($semnatura) !== 32) {
            return null;
        }

        $valid = false;
        foreach ($chei as $cheieBase64) {
            $cheie = self::cheieDecodata((string) $cheieBase64);
            if ($cheie === '') {
                continue;
            }
            $calculata = hash_hmac('sha256', $antet64 . '.' . $continut64, $cheie, true);
            if (hash_equals($calculata, $semnatura)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            return null;
        }

        $continut = json_decode((string) self::base64UrlDecode($continut64), true);
        if (!is_array($continut) || ($continut !== [] && array_is_list($continut))) {
            return null;
        }

        $acum ??= time();
        foreach (['nbf', 'iat', 'exp'] as $camp) {
            if (isset($continut[$camp]) && !is_numeric($continut[$camp])) {
                return null;
            }
        }
        if (isset($continut['nbf']) && floor((float) $continut['nbf']) > $acum + $toleranta) {
            return null;
        }
        if (!isset($continut['nbf']) && isset($continut['iat']) && floor((float) $continut['iat']) > $acum + $toleranta) {
            return null;
        }
        if (isset($continut['exp']) && ($acum - $toleranta) >= (float) $continut['exp']) {
            return null;
        }

        return $continut;
    }

    /** Numărul comenzii din conținutul callback-ului (payload.orderNumber). */
    public static function numarDinCallback(array $continut): string
    {
        $payload = $continut['payload'] ?? null;
        $numar = is_array($payload) ? ($payload['orderNumber'] ?? '') : ($continut['orderNumber'] ?? '');
        $numar = is_scalar($numar) ? trim((string) $numar) : '';
        return preg_match('/^[A-Za-z0-9\-_.]{1,40}$/', $numar) === 1 ? $numar : '';
    }

    private static function base64UrlDecode(string $text): ?string
    {
        // Ca firebase/php-jwt: acceptă și alfabetul Base64 obișnuit. Semnătura
        // se calculează oricum pe segmentele brute, deci nu slăbește verificarea.
        if ($text === '' || preg_match('/^[A-Za-z0-9\-_+\/]+={0,2}$/', $text) !== 1) {
            return null;
        }
        $text = rtrim($text, '=');
        $rest = strlen($text) % 4;
        if ($rest === 1) {
            return null;
        }
        if ($rest > 0) {
            $text .= str_repeat('=', 4 - $rest);
        }
        $rezultat = base64_decode(strtr($text, '-_', '+/'), true);
        return $rezultat === false ? null : $rezultat;
    }

    // ------------------------------------------------------------------
    // Mesaje
    // ------------------------------------------------------------------

    /**
     * Motivul refuzului, pe românește, după `actionCode`
     * (etc/ipay_payment_response_error_mapping.xml + i18n/ro_RO.csv).
     */
    public static function mesajRefuz(int $actionCode): string
    {
        $mesaje = [
            -20010 => 'Tranzacția a fost respinsă deoarece suma depășește limitele stabilite de banca emitentă.',
            -2011 => 'Banca emitentă nu a putut efectua autorizarea 3-D Secure a cardului.',
            -2007 => 'Timpul pentru plată a expirat.',
            -2006 => 'Autorizarea 3-D Secure nu a fost efectuată.',
            -2002 => 'Tranzacția a fost respinsă deoarece suma plății a depășit limitele stabilite.',
            -2001 => 'Tranzacția a fost respinsă de sistemul de securitate al băncii.',
            -2000 => 'Tranzacția a fost respinsă de sistemul de securitate al băncii.',
            -102 => 'Plata a fost anulată.',
            -1 => 'Banca nu a răspuns la timp.',
            1 => 'Numărul comenzii lipsește sau este greșit.',
            2 => 'Plata a fost refuzată din cauza unei erori în datele de plată.',
            5 => 'Acces refuzat.',
            6 => 'Comandă necunoscută la bancă.',
            7 => 'Eroare de sistem la bancă.',
            100 => 'Banca emitentă nu permite plăți pe internet cu acest card.',
            101 => 'Cardul este expirat.',
            103 => 'Nu s-a putut contacta banca emitentă.',
            104 => 'Card restricționat (blocat temporar sau permanent).',
            106 => 'S-a depășit numărul maxim de încercări pentru PIN. Cardul poate fi blocat temporar.',
            107 => 'Plata a fost refuzată. Te rugăm să contactezi banca emitentă.',
            110 => 'Suma tranzacției este incorectă.',
            111 => 'Numărul cardului este incorect.',
            116 => 'Suma depășește soldul disponibil.',
            119 => 'Tranzacție nepermisă.',
            120 => 'Tranzacția nu este permisă de banca emitentă.',
            121 => 'Suma plății depășește limitele stabilite.',
            123 => 'Suma plății depășește limitele stabilite.',
            124 => 'Tranzacția nu poate fi autorizată din cauza unor reglementări.',
            125 => 'Numărul cardului este incorect.',
            208 => 'Cardul este declarat pierdut.',
            209 => 'Limitele cardului au fost depășite.',
            320 => 'Card inactiv. Te rugăm să activezi cardul.',
            801 => 'Banca emitentă nu este disponibilă.',
            803 => 'Card blocat. Contactează banca emitentă sau încearcă alt card.',
            804 => 'Tranzacție nepermisă. Contactează banca emitentă sau încearcă alt card.',
            805 => 'Tranzacție respinsă.',
            861 => 'Data de expirare a cardului este greșită.',
            871 => 'Codul CVV este greșit.',
            902 => 'Limitele cardului au fost depășite.',
            903 => 'Suma plății depășește limitele stabilite.',
            905 => 'Card invalid.',
            906 => 'Cardul este expirat.',
            907 => 'Nu s-a putut contacta banca emitentă.',
            910 => 'Banca emitentă nu este disponibilă.',
            913 => 'Tranzacție invalidă. Contactează banca emitentă sau încearcă alt card.',
            914 => 'Cont invalid. Contactează banca emitentă.',
            915 => 'Fonduri insuficiente.',
            917 => 'Limita de tranzacționare a fost depășită.',
            952 => 'Tranzacție oprită de sistemul antifraudă.',
            998 => 'Plata în rate nu este permisă cu acest card. Folosește un card de credit STAR emis de Banca Transilvania.',
            999 => 'Tranzacție oprită de sistemul antifraudă.',
            2001 => 'Tranzacție oprită de sistemul antifraudă.',
            341016 => 'Autentificarea 3-D Secure a fost refuzată de banca emitentă.',
            341017 => 'Starea autentificării 3-D Secure este necunoscută.',
            341018 => 'Autentificarea 3-D Secure a fost anulată.',
            341019 => 'Autentificarea 3-D Secure a eșuat.',
            341020 => 'Starea autentificării 3-D Secure este necunoscută.',
        ];
        return $mesaje[$actionCode] ?? 'Plata a fost refuzată de bancă.';
    }

    /** Erorile API (errorCode), pe operații — din fișierele de mapare ale modulului. */
    private static function mesajEroareApi(string $actiune, int $cod, string $mesajBanca): string
    {
        $generale = [
            5 => 'Acces refuzat (utilizator sau parolă BT greșite, ori operație nepermisă).',
            6 => 'Comandă necunoscută la bancă.',
            7 => 'Plata nu este în starea potrivită pentru această operație.',
            8 => 'Suma depășește cât se mai poate rambursa.',
        ];
        $inregistrare = [
            1 => 'Comanda cu acest număr a fost deja procesată la bancă.',
            3 => 'Monedă necunoscută.',
            4 => 'Numărul comenzii sau suma lipsesc.',
            5 => 'Valoare nevalidă pentru unul dintre parametri (sau acces refuzat).',
            7 => 'Eroare de sistem la bancă.',
        ];
        $rambursare = [5 => 'Suma nu este validă.'];

        $text = match ($actiune) {
            'registerPreAuth', 'register' => $inregistrare[$cod] ?? ($generale[$cod] ?? ''),
            'refund' => $rambursare[$cod] ?? ($generale[$cod] ?? ''),
            default => $generale[$cod] ?? '',
        };
        if ($text === '') {
            $text = 'Eroare BT.';
        }
        $mesajBanca = trim($mesajBanca);
        return 'BT [cod ' . $cod . ']: ' . $text . ($mesajBanca !== '' ? ' (' . mb_substr($mesajBanca, 0, 200) . ')' : '');
    }
}
