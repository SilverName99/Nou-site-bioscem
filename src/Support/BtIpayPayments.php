<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Plățile cu cardul prin Banca Transilvania iPay: starea fiecărei plăți și
 * tot ce se face cu banii (încasare, eliberare, rambursare).
 *
 * Plata e în două faze, ca în modulul oficial BT: la comandă banca doar
 * BLOCHEAZĂ suma pe card (autorizare); banii se iau abia la „încasare"
 * (deposit), care trebuie făcută în cel mult 5 zile. Pe site:
 *  - autorizarea marchează comanda „plătită" (ca la EuPlătesc): pleacă emailul
 *    de comandă nouă și comanda intră în ERP;
 *  - încasarea se face singură când comanda e aprobată (facturată) în ERP,
 *    din butonul „Încasează" din comandă, sau de cron în ziua 4;
 *  - comanda anulată înainte de încasare își eliberează automat suma blocată;
 *    după încasare, rambursarea se face doar din butonul „Rambursează".
 *
 * Plata cu puncte STAR („LOY") vine de la bancă împărțită în două comenzi BT:
 * partea pe card și partea în puncte. Orice operație atinge întâi partea LOY,
 * apoi pe cea de card (CaptureTransaction / CancelTransaction / RefundTransaction
 * din modulul BT). Rambursarea întoarce întâi punctele.
 *
 * Nicio decizie nu se ia pe baza mesajelor primite (callback, întoarcerea din
 * pagina băncii): starea se citește mereu din nou de la bancă
 * (getOrderStatusExtended), sub un lacăt MySQL pe tranzacție, ca returul,
 * callback-ul, cronul și adminul să nu se calce pe picioare.
 */
final class BtIpayPayments
{
    public const STARE_INREGISTRATA = 'registered';
    public const STARE_3DS = 'pending_3ds';
    public const STARE_AUTORIZATA = 'authorized';
    public const STARE_INCASATA = 'deposited';
    public const STARE_ANULATA = 'reversed';
    public const STARE_RAMBURSATA = 'refunded';
    public const STARE_RAMBURSATA_PARTIAL = 'partially_refunded';
    public const STARE_REFUZATA = 'declined';
    public const STARE_EXPIRATA = 'expired';
    public const STARE_EROARE = 'error';

    public const TIP_COMANDA = 'order';
    public const TIP_TEST = 'test';

    /** Stările în care banii au fost (sau sunt) luați de la client. */
    private const STARI_CU_BANI = [
        self::STARE_AUTORIZATA,
        self::STARE_INCASATA,
        self::STARE_RAMBURSATA_PARTIAL,
        self::STARE_RAMBURSATA,
    ];

    /** Stările de dinainte de plată (sau de după un eșec). */
    private const STARI_FARA_BANI = [
        self::STARE_INREGISTRATA,
        self::STARE_3DS,
        self::STARE_EXPIRATA,
        self::STARE_REFUZATA,
        self::STARE_EROARE,
    ];

    /** Comanda nu mai e vie: o sumă doar blocată pentru ea se eliberează. */
    private const COMENZI_INCHISE = ['cancelled', 'refunded', 'returned', 'failed'];

    /** Cât poate sta o plată de test autorizată până o eliberează cronul (minute). */
    private const MINUTE_TEST_AUTORIZAT = 30;

    /** Cât timp păstrăm jurnalul apelurilor către bancă (zile). */
    private const ZILE_JURNAL = 180;

    private const ETICHETE = [
        self::STARE_INREGISTRATA => 'Înregistrată, încă neplătită',
        self::STARE_3DS => 'În verificare 3-D Secure',
        self::STARE_AUTORIZATA => 'Autorizată — suma e blocată, neîncasată',
        self::STARE_INCASATA => 'Încasată',
        self::STARE_ANULATA => 'Autorizare anulată — suma a fost eliberată',
        self::STARE_RAMBURSATA => 'Rambursată integral',
        self::STARE_RAMBURSATA_PARTIAL => 'Rambursată parțial',
        self::STARE_REFUZATA => 'Refuzată de bancă',
        self::STARE_EXPIRATA => 'Expirată (neplătită)',
        self::STARE_EROARE => 'Eroare la pornirea plății',
    ];

    // ------------------------------------------------------------------
    // Schemă și setări
    // ------------------------------------------------------------------

    public static function ensureSchema(PDO $db): void
    {
        static $gata = false;
        if ($gata) {
            return;
        }
        $gata = true;

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS bt_ipay_transactions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id INT UNSIGNED DEFAULT NULL,
                    kind VARCHAR(10) NOT NULL DEFAULT "order",
                    mode VARCHAR(4) NOT NULL DEFAULT "test",
                    bt_order_number VARCHAR(40) NOT NULL,
                    bt_order_id VARCHAR(64) DEFAULT NULL,
                    loy_order_id VARCHAR(64) DEFAULT NULL,
                    amount_minor INT UNSIGNED NOT NULL DEFAULT 0,
                    currency SMALLINT UNSIGNED NOT NULL DEFAULT 946,
                    approved_minor INT UNSIGNED NOT NULL DEFAULT 0,
                    loy_minor INT UNSIGNED NOT NULL DEFAULT 0,
                    deposited_minor INT UNSIGNED NOT NULL DEFAULT 0,
                    loy_deposited_minor INT UNSIGNED NOT NULL DEFAULT 0,
                    refunded_minor INT UNSIGNED NOT NULL DEFAULT 0,
                    loy_refunded_minor INT UNSIGNED NOT NULL DEFAULT 0,
                    state VARCHAR(20) NOT NULL DEFAULT "registered",
                    bt_status TINYINT DEFAULT NULL,
                    loy_status TINYINT DEFAULT NULL,
                    in_comanda TINYINT(1) NOT NULL DEFAULT 0,
                    action_code INT DEFAULT NULL,
                    action_desc VARCHAR(255) DEFAULT NULL,
                    approval_code VARCHAR(20) DEFAULT NULL,
                    refund_reference VARCHAR(64) DEFAULT NULL,
                    last_error TEXT DEFAULT NULL,
                    op_pending TEXT DEFAULT NULL,
                    alerts_json TEXT DEFAULT NULL,
                    deposit_requested_at DATETIME DEFAULT NULL,
                    deposit_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                    reminder_sent_at DATETIME DEFAULT NULL,
                    authorized_at DATETIME DEFAULT NULL,
                    deposited_at DATETIME DEFAULT NULL,
                    reversed_at DATETIME DEFAULT NULL,
                    refunded_at DATETIME DEFAULT NULL,
                    last_checked_at DATETIME DEFAULT NULL,
                    last_status_json MEDIUMTEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_bt_ipay_tx_number (bt_order_number),
                    UNIQUE KEY uq_bt_ipay_tx_bt_id (bt_order_id),
                    KEY idx_bt_ipay_tx_order (order_id),
                    KEY idx_bt_ipay_tx_state (state, created_at)
                ) DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable) {
            // Tabelul există deja.
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS bt_ipay_log (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    tx_id INT UNSIGNED DEFAULT NULL,
                    order_id INT UNSIGNED DEFAULT NULL,
                    mode VARCHAR(4) NOT NULL DEFAULT "",
                    action VARCHAR(40) NOT NULL,
                    source VARCHAR(30) NOT NULL DEFAULT "",
                    http_code SMALLINT DEFAULT NULL,
                    error_code VARCHAR(20) DEFAULT NULL,
                    duration_ms INT UNSIGNED DEFAULT NULL,
                    request_json MEDIUMTEXT DEFAULT NULL,
                    response_json MEDIUMTEXT DEFAULT NULL,
                    message VARCHAR(500) DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    KEY idx_bt_ipay_log_created (created_at),
                    KEY idx_bt_ipay_log_tx (tx_id)
                ) DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable) {
            // Tabelul există deja.
        }
    }

    /**
     * Setările BT din admin, cu limitele aplicate. Pragurile de timp rămân sub
     * cele 5 zile (120 de ore) în care banca cere încasarea.
     *
     * @return array{activ: bool, doar_admin: bool, incaseaza_la_aprobare: bool, ore_reamintire: int,
     *               ore_incasare_automata: int, minute_expirare: int, emailuri: list<string>}
     */
    public static function setari(array $settings): array
    {
        $intre = static fn (int $v, int $min, int $max): int => max($min, min($max, $v));
        $automat = $intre((int) ($settings['bt_ipay_autodeposit_hours'] ?? 96), 24, 110);
        $reamintire = $intre((int) ($settings['bt_ipay_reminder_hours'] ?? 72), 12, $automat - 1);
        $expirare = $intre((int) ($settings['bt_ipay_expire_minutes'] ?? 60), 30, 1440);

        $emailuri = [];
        foreach (preg_split('/[\s,;]+/', (string) ($settings['bt_ipay_notify_emails'] ?? '')) ?: [] as $adresa) {
            $adresa = trim((string) $adresa);
            if ($adresa !== '' && filter_var($adresa, FILTER_VALIDATE_EMAIL)) {
                $emailuri[strtolower($adresa)] = $adresa;
            }
        }
        if ($emailuri === []) {
            foreach (preg_split('/[\s,;]+/', (string) ($settings['contact_form_recipients'] ?? '')) ?: [] as $adresa) {
                $adresa = trim((string) $adresa);
                if ($adresa !== '' && filter_var($adresa, FILTER_VALIDATE_EMAIL)) {
                    $emailuri[strtolower($adresa)] = $adresa;
                }
            }
        }
        if ($emailuri === []) {
            $emailuri = ['contact@bioscem.ro' => 'contact@bioscem.ro'];
        }

        return [
            'activ' => (string) ($settings['bt_ipay_enabled'] ?? '0') === '1',
            'doar_admin' => (string) ($settings['bt_ipay_admin_only'] ?? '0') === '1',
            'incaseaza_la_aprobare' => (string) ($settings['bt_ipay_deposit_on_erp'] ?? '1') === '1',
            'ore_reamintire' => $reamintire,
            'ore_incasare_automata' => $automat,
            'minute_expirare' => $expirare,
            'emailuri' => array_values($emailuri),
        ];
    }

    /**
     * Apare BT în checkout? Doar dacă e pornit din admin și are datele de acces.
     * În modul test (sandbox) — sau cu bifa „doar administratori" — îl văd doar
     * administratorii logați: un client nu are voie să ajungă pe pagina de test.
     */
    public static function vizibilInCheckout(array $settings, bool $esteAdmin): bool
    {
        $s = self::setari($settings);
        if (!$s['activ']) {
            return false;
        }
        $mod = BtIpayGateway::mod();
        if (!BtIpayGateway::esteConfigurat($mod)) {
            return false;
        }
        if (($s['doar_admin'] || $mod === BtIpayGateway::MOD_TEST) && !$esteAdmin) {
            return false;
        }
        return true;
    }

    public static function etichetaStare(string $stare): string
    {
        return self::ETICHETE[$stare] ?? $stare;
    }

    // ------------------------------------------------------------------
    // Pornirea plății
    // ------------------------------------------------------------------

    /**
     * Înregistrează plata la bancă (registerPreAuth.do) pentru o comandă abia
     * salvată și întoarce adresa paginii de plată. Fiecare încercare are propriul
     * număr la bancă: prima e numărul comenzii, următoarele „{nr}-R2", „{nr}-R3".
     *
     * @return array{ok: bool, url: string, eroare: string, tx_id: int}
     */
    public static function pornestePlata(PDO $db, int $orderId, string $returnUrlBaza, string $userAgent = ''): array
    {
        self::ensureSchema($db);
        $stmt = $db->prepare(
            'SELECT id, order_number, total, billing_first_name, billing_last_name, billing_email, billing_phone,
                    billing_address_line1, billing_address_line2, billing_city,
                    shipping_same_as_billing, shipping_address_line1, shipping_address_line2, shipping_city,
                    fan_locker_id, fan_locker_address, fan_locker_city
             FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $comanda = $stmt->fetch();
        if (!is_array($comanda)) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Comanda nu a fost găsită.', 'tx_id' => 0];
        }

        $suma = (int) round((float) ($comanda['total'] ?? 0) * 100);
        if ($suma <= 0) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Valoarea comenzii este invalidă.', 'tx_id' => 0];
        }

        $numarComanda = (string) $comanda['order_number'];
        $adresaFacturare = trim(implode(', ', array_filter([
            trim((string) ($comanda['billing_address_line1'] ?? '')),
            trim((string) ($comanda['billing_address_line2'] ?? '')),
        ])));
        if (!empty($comanda['fan_locker_id'])) {
            $orasLivrare = (string) ($comanda['fan_locker_city'] ?? '');
            $adresaLivrare = (string) ($comanda['fan_locker_address'] ?? '');
        } elseif ((int) ($comanda['shipping_same_as_billing'] ?? 1) === 1) {
            $orasLivrare = (string) ($comanda['billing_city'] ?? '');
            $adresaLivrare = $adresaFacturare;
        } else {
            $orasLivrare = (string) ($comanda['shipping_city'] ?? '');
            $adresaLivrare = trim(implode(', ', array_filter([
                trim((string) ($comanda['shipping_address_line1'] ?? '')),
                trim((string) ($comanda['shipping_address_line2'] ?? '')),
            ])));
        }

        $date = [
            'suma' => $suma,
            'descriere' => 'Comanda ' . $numarComanda . ' bioscem.ro',
            'email' => (string) ($comanda['billing_email'] ?? ''),
            'telefon' => (string) ($comanda['billing_phone'] ?? ''),
            'nume' => trim((string) ($comanda['billing_first_name'] ?? '') . ' ' . (string) ($comanda['billing_last_name'] ?? '')),
            'oras_facturare' => (string) ($comanda['billing_city'] ?? ''),
            'adresa_facturare' => $adresaFacturare,
            'oras_livrare' => $orasLivrare,
            'adresa_livrare' => $adresaLivrare,
            'user_agent' => $userAgent,
        ];

        return self::inregistreaza($db, self::TIP_COMANDA, $orderId, $numarComanda, $date, $returnUrlBaza);
    }

    /**
     * Plata de test din admin: 1 leu, legată de nicio comandă. Nu atinge
     * comenzi, emailuri sau ERP-ul; cronul o eliberează singur după 30 de minute.
     *
     * @return array{ok: bool, url: string, eroare: string, tx_id: int}
     */
    public static function pornesteTest(PDO $db, string $returnUrlBaza, string $emailAdmin, string $userAgent = ''): array
    {
        self::ensureSchema($db);
        $numar = 'TEST-' . date('ymd-His') . '-' . bin2hex(random_bytes(2));
        return self::inregistreaza($db, self::TIP_TEST, 0, $numar, [
            'suma' => 100,
            'descriere' => 'Plata de test 1 leu bioscem.ro',
            'email' => $emailAdmin,
            'user_agent' => $userAgent,
        ], $returnUrlBaza);
    }

    /**
     * @param array<string, mixed> $date
     * @return array{ok: bool, url: string, eroare: string, tx_id: int}
     */
    private static function inregistreaza(PDO $db, string $tip, int $orderId, string $numarBaza, array $date, string $returnUrlBaza): array
    {
        $mod = BtIpayGateway::mod();
        if (!BtIpayGateway::esteConfigurat($mod)) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Plata prin Banca Transilvania nu este configurată.', 'tx_id' => 0];
        }

        $ultimaEroare = 'Banca nu a putut porni plata.';
        $txId = 0;
        for ($incercare = 1; $incercare <= 5; $incercare++) {
            $numar = self::numarNou($db, $tip, $orderId, $numarBaza);
            if ($numar === '') {
                break;
            }
            $acum = date('Y-m-d H:i:s');
            try {
                $db->prepare(
                    'INSERT INTO bt_ipay_transactions
                        (order_id, kind, mode, bt_order_number, amount_minor, currency, state, created_at, updated_at)
                     VALUES (:order_id, :kind, :mode, :numar, :suma, :moneda, :stare, :acum, :acum2)'
                )->execute([
                    'order_id' => $orderId > 0 ? $orderId : null,
                    'kind' => $tip,
                    'mode' => $mod,
                    'numar' => $numar,
                    'suma' => (int) $date['suma'],
                    'moneda' => BtIpayGateway::MONEDA_RON,
                    'stare' => self::STARE_INREGISTRATA,
                    'acum' => $acum,
                    'acum2' => $acum,
                ]);
                $txId = (int) $db->lastInsertId();
            } catch (Throwable) {
                // Număr deja folosit (două cereri deodată): încercăm următorul.
                continue;
            }

            $separator = str_contains($returnUrlBaza, '?') ? '&' : '?';
            $cerere = BtIpayGateway::cerereInregistrare(array_merge($date, [
                'numar' => $numar,
                'return_url' => $returnUrlBaza . $separator . 'ref=' . rawurlencode($numar),
            ]));
            $rez = BtIpayGateway::apel($mod, 'registerPreAuth', $cerere, $db, [
                'tx_id' => $txId,
                'order_id' => $orderId,
                'sursa' => $tip === self::TIP_TEST ? 'test' : 'checkout',
            ]);

            $btId = trim((string) ($rez['date']['orderId'] ?? ''));
            $url = trim((string) ($rez['date']['formUrl'] ?? ''));
            if ($rez['ok'] && $btId !== '' && $url !== '' && preg_match('/^[A-Za-z0-9\-]{1,64}$/', $btId) === 1) {
                if (!BtIpayGateway::urlPlataValid($url, $mod)) {
                    $ultimaEroare = 'Banca a întors o adresă de plată neașteptată.';
                    self::scrieEroare($db, $txId, $ultimaEroare, self::STARE_EROARE);
                    break;
                }
                $db->prepare('UPDATE bt_ipay_transactions SET bt_order_id = :bt, updated_at = :acum WHERE id = :id')
                    ->execute(['bt' => $btId, 'acum' => date('Y-m-d H:i:s'), 'id' => $txId]);
                return ['ok' => true, 'url' => $url, 'eroare' => '', 'tx_id' => $txId];
            }

            $ultimaEroare = $rez['eroare'] !== '' ? $rez['eroare'] : 'Banca nu a întors pagina de plată.';
            self::scrieEroare($db, $txId, $ultimaEroare, self::STARE_EROARE);
            // Cod 1: numărul a mai fost folosit la bancă (bază resetată, test
            // anterior). Mergem pe următorul sufix; orice altă eroare e definitivă.
            if ((int) $rez['cod'] !== 1) {
                break;
            }
        }

        return ['ok' => false, 'url' => '', 'eroare' => $ultimaEroare, 'tx_id' => $txId];
    }

    /** Următorul număr liber la bancă pentru comanda dată (max 32 de caractere). */
    private static function numarNou(PDO $db, string $tip, int $orderId, string $numarBaza): string
    {
        $numarBaza = preg_replace('/[^A-Za-z0-9\-_]/', '', $numarBaza) ?? '';
        if ($numarBaza === '') {
            return '';
        }
        $numarBaza = substr($numarBaza, 0, BtIpayGateway::LUNGIME_MAX_NUMAR - 5);
        $existente = 0;
        if ($tip === self::TIP_COMANDA) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM bt_ipay_transactions WHERE order_id = :o AND kind = :k');
            $stmt->execute(['o' => $orderId, 'k' => self::TIP_COMANDA]);
            $existente = (int) $stmt->fetchColumn();
        }
        $verifica = $db->prepare('SELECT 1 FROM bt_ipay_transactions WHERE bt_order_number = :n LIMIT 1');
        for ($n = $existente + 1; $n <= $existente + 50; $n++) {
            $numar = $n === 1 ? $numarBaza : $numarBaza . '-R' . $n;
            $verifica->execute(['n' => $numar]);
            if ($verifica->fetchColumn() === false) {
                return $numar;
            }
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Citiri
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public static function tx(PDO $db, int $txId): ?array
    {
        if ($txId <= 0) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM bt_ipay_transactions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $txId]);
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    /** @return array<string, mixed>|null */
    public static function dupaNumar(PDO $db, string $numar): ?array
    {
        $numar = trim($numar);
        if ($numar === '' || strlen($numar) > 40) {
            return null;
        }
        self::ensureSchema($db);
        $stmt = $db->prepare('SELECT * FROM bt_ipay_transactions WHERE bt_order_number = :n LIMIT 1');
        $stmt->execute(['n' => $numar]);
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    /** Toate plățile BT ale unei comenzi, cea mai nouă prima. @return list<array<string, mixed>> */
    public static function tranzactiiComanda(PDO $db, int $orderId): array
    {
        self::ensureSchema($db);
        $stmt = $db->prepare('SELECT * FROM bt_ipay_transactions WHERE order_id = :o AND kind = :k ORDER BY id DESC');
        $stmt->execute(['o' => $orderId, 'k' => self::TIP_COMANDA]);
        return array_values(array_filter($stmt->fetchAll() ?: [], 'is_array'));
    }

    /**
     * Plata care contează pentru comandă: cea cu bani pe ea, altfel ultima.
     *
     * @param list<array<string, mixed>> $lista
     * @return array<string, mixed>|null
     */
    private static function principala(array $lista): ?array
    {
        foreach ($lista as $tx) {
            if ((int) ($tx['in_comanda'] ?? 0) === 1) {
                return $tx;
            }
        }
        foreach ($lista as $tx) {
            if (in_array((string) $tx['state'], self::STARI_CU_BANI, true)) {
                return $tx;
            }
        }
        return $lista[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private static function comanda(PDO $db, int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        ErpSync::ensureSchema($db);
        $stmt = $db->prepare(
            'SELECT id, order_number, status, payment_status, payment_method, paid_amount, total, deleted_at,
                    billing_first_name, billing_last_name, billing_email
             FROM orders WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    // ------------------------------------------------------------------
    // Lacăte
    // ------------------------------------------------------------------

    /**
     * Lacăt MySQL pe numele dat, cu amprenta bazei (serverul MySQL e împărțit pe
     * găzduirea comună). Dacă procesul moare, MySQL îl eliberează singur.
     */
    private static function iaLacat(PDO $db, string $nume, int $asteptareSecunde): bool
    {
        try {
            $stmt = $db->prepare('SELECT GET_LOCK(CONCAT("btipay:", MD5(DATABASE()), ":", :nume), :asteptare)');
            $stmt->bindValue('nume', $nume);
            $stmt->bindValue('asteptare', max(0, $asteptareSecunde), PDO::PARAM_INT);
            $stmt->execute();
            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable) {
            // Bază fără GET_LOCK (nu e cazul pe MySQL/MariaDB): mergem mai departe.
            // Încasarea și eliberarea sunt oricum refuzate de bancă a doua oară,
            // iar rambursarea are marcajul ei.
            return true;
        }
    }

    private static function elibereazaLacat(PDO $db, string $nume): void
    {
        try {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(CONCAT("btipay:", MD5(DATABASE()), ":", :nume))');
            $stmt->execute(['nume' => $nume]);
        } catch (Throwable) {
        }
    }

    /**
     * @param callable(): array<string, mixed> $lucru
     * @return array<string, mixed>
     */
    private static function cuLacat(PDO $db, int $txId, int $asteptare, callable $lucru): array
    {
        $nume = 'tx:' . $txId;
        if (!self::iaLacat($db, $nume, $asteptare)) {
            return ['ok' => false, 'mesaj' => 'Plata e procesată chiar acum de altă cerere; reîncearcă în câteva secunde.', 'ocupat' => true];
        }
        try {
            return $lucru();
        } finally {
            self::elibereazaLacat($db, $nume);
        }
    }

    // ------------------------------------------------------------------
    // Sincronizarea stării de la bancă
    // ------------------------------------------------------------------

    /**
     * Citește starea de la bancă și o aplică pe tranzacție și pe comandă.
     * Idempotentă: oricât de des ar fi chemată, emailul și trimiterea în ERP
     * pleacă o singură dată, iar suma încasată pe comandă se corectează o dată.
     *
     * @return array{ok: bool, mesaj: string, stare?: string}
     */
    public static function sincronizeaza(PDO $db, int $txId, string $sursa, bool $forteazaExpirare = false, int $asteptare = 20): array
    {
        self::ensureSchema($db);
        return self::cuLacat($db, $txId, $asteptare, static function () use ($db, $txId, $sursa, $forteazaExpirare): array {
            $tx = self::tx($db, $txId);
            if ($tx === null) {
                return ['ok' => false, 'mesaj' => 'Plata nu există.'];
            }
            return self::sincronizeazaFaraLacat($db, $tx, $sursa, $forteazaExpirare);
        });
    }

    /** @return array{ok: bool, mesaj: string, stare?: string} */
    private static function sincronizeazaFaraLacat(PDO $db, array $tx, string $sursa, bool $forteazaExpirare): array
    {
        $mod = BtIpayGateway::modValid((string) $tx['mode']);
        $context = ['tx_id' => (int) $tx['id'], 'order_id' => (int) ($tx['order_id'] ?? 0), 'sursa' => $sursa];
        $btId = trim((string) ($tx['bt_order_id'] ?? ''));

        if ($btId === '') {
            // Înregistrarea n-a întors id-ul (eroare, timp depășit). Întrebăm după număr.
            $rez = BtIpayGateway::apel($mod, 'getOrderStatusExtended', ['orderNumber' => (string) $tx['bt_order_number']], $db, $context);
            $gasit = $rez['ok'] && isset($rez['date']['orderStatus']);
            $idGasit = '';
            if ($gasit) {
                foreach ((array) ($rez['date']['attributes'] ?? []) as $atribut) {
                    if (is_array($atribut) && ($atribut['name'] ?? null) === 'mdOrder') {
                        $idGasit = trim((string) ($atribut['value'] ?? ''));
                    }
                }
            }
            if ($gasit && preg_match('/^[A-Za-z0-9\-]{1,64}$/', $idGasit) === 1) {
                try {
                    $db->prepare('UPDATE bt_ipay_transactions SET bt_order_id = :bt WHERE id = :id AND bt_order_id IS NULL')
                        ->execute(['bt' => $idGasit, 'id' => (int) $tx['id']]);
                    $tx['bt_order_id'] = $idGasit;
                    $btId = $idGasit;
                } catch (Throwable) {
                    $gasit = false;
                }
            } else {
                $gasit = false;
            }
            if (!$gasit) {
                if ($forteazaExpirare && in_array((string) $tx['state'], [self::STARE_INREGISTRATA, self::STARE_3DS, self::STARE_EROARE], true)) {
                    return self::aplicaStare($db, $tx, ['orderStatus' => BtIpayGateway::STATUS_INREGISTRATA, 'amount' => 0, 'currency' => BtIpayGateway::MONEDA_RON], null, '', $sursa, true);
                }
                return ['ok' => true, 'mesaj' => 'Plata nu a ajuns să fie înregistrată la bancă.', 'stare' => (string) $tx['state']];
            }
        }

        $rez = BtIpayGateway::apel($mod, 'getOrderStatusExtended', ['orderId' => $btId], $db, $context);
        if (!$rez['ok'] || !isset($rez['date']['orderStatus'])) {
            $mesaj = $rez['eroare'] !== '' ? $rez['eroare'] : 'Banca nu a întors starea plății.';
            $db->prepare('UPDATE bt_ipay_transactions SET last_checked_at = :acum, updated_at = :acum2 WHERE id = :id')
                ->execute(['acum' => date('Y-m-d H:i:s'), 'acum2' => date('Y-m-d H:i:s'), 'id' => (int) $tx['id']]);
            return ['ok' => false, 'mesaj' => $mesaj];
        }
        $stare = $rez['date'];

        $stareLoy = null;
        $loyId = BtIpayGateway::comandaLoy($stare);
        if ($loyId === '') {
            $loyId = trim((string) ($tx['loy_order_id'] ?? ''));
        }
        if ($loyId !== '') {
            $rezLoy = BtIpayGateway::apel($mod, 'getOrderStatusExtended', ['orderId' => $loyId], $db, $context);
            if (!$rezLoy['ok'] || !isset($rezLoy['date']['orderStatus'])) {
                return ['ok' => false, 'mesaj' => 'Nu am putut citi partea plătită în puncte STAR: ' . $rezLoy['eroare']];
            }
            $stareLoy = $rezLoy['date'];
        }

        return self::aplicaStare($db, $tx, $stare, $stareLoy, $loyId, $sursa, $forteazaExpirare);
    }

    /**
     * Banii de pe o parte a plății (card sau puncte) care țin de comandă acum:
     * suma blocată cât e autorizată, încasat minus rambursat după încasare.
     */
    private static function netParte(?int $status, int $autorizat, int $incasat, int $rambursat): int
    {
        return match ($status) {
            BtIpayGateway::STATUS_AUTORIZATA => $autorizat,
            BtIpayGateway::STATUS_INCASATA, BtIpayGateway::STATUS_RAMBURSATA, BtIpayGateway::STATUS_RAMBURSATA_PARTIAL => max(0, $incasat - $rambursat),
            default => 0,
        };
    }

    /** Banii plății ținuți acum pentru comandă, din rândul salvat. */
    private static function netTx(array $tx): int
    {
        $net = self::netParte(
            isset($tx['bt_status']) ? (int) $tx['bt_status'] : null,
            (int) $tx['approved_minor'],
            (int) $tx['deposited_minor'],
            (int) $tx['refunded_minor']
        );
        if (trim((string) ($tx['loy_order_id'] ?? '')) !== '') {
            $net += self::netParte(
                isset($tx['loy_status']) ? (int) $tx['loy_status'] : null,
                (int) $tx['loy_minor'],
                (int) $tx['loy_deposited_minor'],
                (int) $tx['loy_refunded_minor']
            );
        }
        return $net;
    }

    /**
     * Aplică starea citită de la bancă. Rulează sub lacătul tranzacției.
     *
     * @param array<string, mixed> $stare
     * @param array<string, mixed>|null $stareLoy
     * @return array{ok: bool, mesaj: string, stare?: string}
     */
    private static function aplicaStare(PDO $db, array $tx, array $stare, ?array $stareLoy, string $loyId, string $sursa, bool $forteazaExpirare): array
    {
        $txId = (int) $tx['id'];
        $vechi = (string) $tx['state'];
        $status = (int) ($stare['orderStatus'] ?? -1);
        $statusLoy = $stareLoy !== null ? (int) ($stareLoy['orderStatus'] ?? -1) : null;

        $card = max(0, (int) ($stare['amount'] ?? 0));
        $loy = BtIpayGateway::puncteLoy($stare);
        $moneda = (int) ($stare['currency'] ?? 0);
        // Garda de sumă din modulul BT (hasOrderValidCurrencyAndAmount): moneda
        // RON (sau LOY la o comandă în lei) și card + puncte = suma cerută.
        $sumeCorecte = in_array($moneda, [BtIpayGateway::MONEDA_RON, BtIpayGateway::MONEDA_LOY], true)
            && ($card + $loy) === (int) $tx['amount_minor'];

        $incasatCard = BtIpayGateway::sumaIncasata($stare);
        $rambursatCard = BtIpayGateway::sumaRambursata($stare);
        $incasatLoy = $stareLoy !== null ? BtIpayGateway::sumaIncasata($stareLoy) : (int) $tx['loy_deposited_minor'];
        $rambursatLoy = $stareLoy !== null ? BtIpayGateway::sumaRambursata($stareLoy) : (int) $tx['loy_refunded_minor'];
        if ($loyId === '') {
            $incasatLoy = 0;
            $rambursatLoy = 0;
        }

        $areAutorizare = $status === BtIpayGateway::STATUS_AUTORIZATA || $statusLoy === BtIpayGateway::STATUS_AUTORIZATA;
        $colectat = in_array($status, [2, 4, 7], true) || in_array($statusLoy, [2, 4, 7], true);
        $nota = '';

        $nou = $vechi;
        if ($areAutorizare) {
            // O parte încă doar blocată (chiar dacă cealaltă s-a încasat): plata
            // rămâne „autorizată", ca încasarea să fie dusă la capăt.
            if ($sumeCorecte || in_array($vechi, self::STARI_CU_BANI, true)) {
                $nou = self::STARE_AUTORIZATA;
            } else {
                $nota = self::notaSumeDiferite($tx, $card, $loy, $moneda);
            }
        } elseif ($colectat) {
            if ($sumeCorecte || in_array($vechi, self::STARI_CU_BANI, true) || $vechi === self::STARE_ANULATA) {
                $incasatTotal = $incasatCard + $incasatLoy;
                $rambursatTotal = $rambursatCard + $rambursatLoy;
                $nou = $rambursatTotal <= 0
                    ? self::STARE_INCASATA
                    : ($rambursatTotal >= $incasatTotal ? self::STARE_RAMBURSATA : self::STARE_RAMBURSATA_PARTIAL);
            } else {
                $nota = self::notaSumeDiferite($tx, $card, $loy, $moneda);
            }
        } else {
            $nou = match ($status) {
                BtIpayGateway::STATUS_INREGISTRATA => ($forteazaExpirare || $vechi === self::STARE_EXPIRATA)
                    ? self::STARE_EXPIRATA
                    : ($vechi === self::STARE_EROARE ? self::STARE_EROARE : self::STARE_INREGISTRATA),
                BtIpayGateway::STATUS_ACS => $forteazaExpirare ? self::STARE_EXPIRATA : self::STARE_3DS,
                BtIpayGateway::STATUS_AUTORIZARE_ANULATA => self::STARE_ANULATA,
                BtIpayGateway::STATUS_REFUZATA => self::STARE_REFUZATA,
                default => $vechi,
            };
            if (!in_array($status, [0, 3, 5, 6], true)) {
                $nota = 'Stare necunoscută primită de la BT: ' . $status . '.';
            }
        }
        // O plată deja eliberată nu „reînvie" ca refuzată sau expirată.
        if ($vechi === self::STARE_ANULATA && in_array($nou, self::STARI_FARA_BANI, true)) {
            $nou = self::STARE_ANULATA;
        }

        $codActiune = isset($stare['actionCode']) && is_numeric($stare['actionCode']) ? (int) $stare['actionCode'] : null;
        if (($codActiune === null || $codActiune === 0) && $stareLoy !== null && isset($stareLoy['actionCode']) && is_numeric($stareLoy['actionCode'])) {
            $codLoy = (int) $stareLoy['actionCode'];
            if ($codLoy !== 0) {
                $codActiune = $codLoy;
            }
        }
        $descriereActiune = mb_substr(trim((string) ($stare['actionCodeDescription'] ?? '')), 0, 255);
        $aprobare = substr(trim((string) ($stare['cardAuthInfo']['approvalCode'] ?? '')), 0, 20);

        $txNou = $tx;
        $txNou['state'] = $nou;
        $txNou['bt_status'] = $status;
        $txNou['loy_status'] = $statusLoy ?? ($tx['loy_status'] ?? null);
        $txNou['loy_order_id'] = $loyId !== '' ? $loyId : null;
        if (in_array($status, [1, 2, 3, 4, 7], true)) {
            $txNou['approved_minor'] = $card;
            $txNou['loy_minor'] = $loy;
        }
        $txNou['deposited_minor'] = $incasatCard;
        $txNou['refunded_minor'] = $rambursatCard;
        $txNou['loy_deposited_minor'] = $incasatLoy;
        $txNou['loy_refunded_minor'] = $rambursatLoy;

        $netInainte = self::netTx($tx);
        $netDupa = self::netTx($txNou);
        $acum = date('Y-m-d H:i:s');
        $referinta = BtIpayGateway::referintaRambursare($stareLoy !== null && $rambursatLoy > (int) $tx['loy_refunded_minor'] ? $stareLoy : $stare);

        $efecte = ['prima_plata' => false, 'esuata' => '', 'elibereaza' => false, 'alerte' => []];
        $setariComanda = [];
        $inComanda = (int) ($tx['in_comanda'] ?? 0) === 1;

        if ((string) $tx['kind'] === self::TIP_COMANDA && (int) ($tx['order_id'] ?? 0) > 0) {
            $comanda = self::comanda($db, (int) $tx['order_id']);
            $inchisa = $comanda === null
                || $comanda['deleted_at'] !== null
                || in_array((string) $comanda['status'], ['cancelled', 'refunded', 'returned'], true);
            $platita = $comanda !== null && strtolower((string) $comanda['payment_status']) === 'paid';
            $ultima = self::esteUltima($db, $tx);

            if (!$inComanda && in_array($nou, self::STARI_CU_BANI, true) && !in_array($vechi, [self::STARE_ANULATA], true)) {
                if ($inchisa) {
                    // Banii au venit pentru o comandă anulată între timp: nu o
                    // reînviem, eliberăm suma (sau cerem rambursare, dacă e luată).
                    $efecte['elibereaza'] = $nou === self::STARE_AUTORIZATA;
                    $efecte['alerte'][] = 'plata_comanda_inchisa';
                } elseif ($platita || self::altaPlataNumarata($db, $tx)) {
                    $efecte['elibereaza'] = $nou === self::STARE_AUTORIZATA && self::altaPlataNumarata($db, $tx);
                    $efecte['alerte'][] = 'plata_in_plus';
                } else {
                    $setariComanda['prima_plata'] = $netDupa;
                }
            } elseif ($inComanda && $netDupa !== $netInainte) {
                $setariComanda['delta'] = $netDupa - $netInainte;
                if ($nou === self::STARE_ANULATA && !$inchisa && !in_array($sursa, ['anulare', 'admin', 'cron'], true)) {
                    $efecte['alerte'][] = 'eliberata_la_banca';
                }
            }

            if (!$inComanda && $ultima && !$platita && $vechi !== $nou
                && in_array($nou, [self::STARE_REFUZATA, self::STARE_EXPIRATA], true)) {
                $efecte['esuata'] = $nou === self::STARE_REFUZATA
                    ? 'Plata cu cardul (Banca Transilvania) a fost refuzată: ' . BtIpayGateway::mesajRefuz((int) $codActiune)
                        . ($codActiune !== null ? ' [cod ' . $codActiune . ']' : '')
                    : 'Plata cu cardul (Banca Transilvania) nu a fost finalizată la timp; comanda a expirat.';
            }
            if ($nota !== '') {
                $efecte['alerte'][] = 'sume_diferite';
            }
        }

        $inTranzactie = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $inTranzactie = true;
            }

            $db->prepare(
                'UPDATE bt_ipay_transactions SET
                    state = :stare, bt_status = :bt_status, loy_status = :loy_status, loy_order_id = :loy_id,
                    approved_minor = :aprobat, loy_minor = :loy,
                    deposited_minor = :incasat, refunded_minor = :rambursat,
                    loy_deposited_minor = :incasat_loy, loy_refunded_minor = :rambursat_loy,
                    action_code = :cod, action_desc = :descriere,
                    approval_code = COALESCE(NULLIF(:aprobare, ""), approval_code),
                    refund_reference = COALESCE(NULLIF(:referinta, ""), refund_reference),
                    authorized_at = CASE WHEN :autorizata = 1 THEN COALESCE(authorized_at, :acum1) ELSE authorized_at END,
                    deposited_at = CASE WHEN :colectata = 1 THEN COALESCE(deposited_at, :acum2) ELSE deposited_at END,
                    reversed_at = CASE WHEN :eliberata = 1 THEN COALESCE(reversed_at, :acum3) ELSE reversed_at END,
                    refunded_at = CASE WHEN :rambursare_noua = 1 THEN :acum4 ELSE refunded_at END,
                    last_error = CASE WHEN :are_nota = 1 THEN :nota WHEN :schimbata = 1 THEN NULL ELSE last_error END,
                    last_checked_at = :acum5, updated_at = :acum6,
                    last_status_json = :json
                 WHERE id = :id'
            )->execute([
                'stare' => $nou,
                'bt_status' => $status,
                'loy_status' => $txNou['loy_status'],
                'loy_id' => $txNou['loy_order_id'],
                'aprobat' => (int) $txNou['approved_minor'],
                'loy' => (int) $txNou['loy_minor'],
                'incasat' => $incasatCard,
                'rambursat' => $rambursatCard,
                'incasat_loy' => $incasatLoy,
                'rambursat_loy' => $rambursatLoy,
                'cod' => $codActiune,
                'descriere' => $descriereActiune !== '' ? $descriereActiune : null,
                'aprobare' => $aprobare,
                'referinta' => $referinta,
                'autorizata' => in_array($nou, self::STARI_CU_BANI, true) ? 1 : 0,
                'colectata' => $colectat ? 1 : 0,
                'eliberata' => $nou === self::STARE_ANULATA ? 1 : 0,
                'rambursare_noua' => ($rambursatCard + $rambursatLoy) > ((int) $tx['refunded_minor'] + (int) $tx['loy_refunded_minor']) ? 1 : 0,
                'are_nota' => $nota !== '' ? 1 : 0,
                'nota' => $nota,
                'schimbata' => $nou !== $vechi ? 1 : 0,
                'acum1' => $acum, 'acum2' => $acum, 'acum3' => $acum, 'acum4' => $acum, 'acum5' => $acum, 'acum6' => $acum,
                'json' => json_encode(
                    BtIpayGateway::redacteaza(['card' => $stare, 'loy' => $stareLoy]),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                ) ?: null,
                'id' => $txId,
            ]);

            $orderId = (int) ($tx['order_id'] ?? 0);
            if (isset($setariComanda['prima_plata'])) {
                ErpSync::ensureSchema($db);
                $stmt = $db->prepare(
                    "UPDATE orders
                     SET payment_status = 'paid',
                         payment_error = CASE WHEN status = 'failed'
                             THEN 'Plata BT a fost aprobată după ce comanda fusese marcată eșuată; comanda a fost reactivată. Verifică stocul și punctele de fidelitate folosite.'
                             ELSE NULL END,
                         status = CASE WHEN status IN ('pending_payment', 'failed') THEN 'pending' ELSE status END,
                         paid_at = COALESCE(paid_at, :acum),
                         paid_amount = COALESCE(paid_amount, :suma)
                     WHERE id = :id AND payment_status <> 'paid'"
                );
                $stmt->execute([
                    'acum' => $acum,
                    'suma' => number_format($setariComanda['prima_plata'] / 100, 2, '.', ''),
                    'id' => $orderId,
                ]);
                if ($stmt->rowCount() > 0) {
                    $db->prepare('UPDATE bt_ipay_transactions SET in_comanda = 1 WHERE id = :id')->execute(['id' => $txId]);
                    $efecte['prima_plata'] = true;
                } else {
                    $efecte['alerte'][] = 'plata_in_plus';
                }
            } elseif (isset($setariComanda['delta'])) {
                $delta = (int) $setariComanda['delta'];
                $db->prepare(
                    'UPDATE orders SET paid_amount = ROUND(COALESCE(paid_amount, total) + :delta, 2) WHERE id = :id'
                )->execute(['delta' => number_format($delta / 100, 2, '.', ''), 'id' => $orderId]);
                if ($nou === self::STARE_ANULATA) {
                    // Suma doar blocată a fost eliberată: comanda nu mai are bani pe ea.
                    $db->prepare(
                        "UPDATE orders SET payment_status = 'unpaid', paid_amount = NULL
                         WHERE id = :id AND payment_status = 'paid' AND COALESCE(paid_amount, 0) <= 0.004"
                    )->execute(['id' => $orderId]);
                } elseif ($nou === self::STARE_RAMBURSATA) {
                    $db->prepare(
                        "UPDATE orders SET payment_status = 'refunded'
                         WHERE id = :id AND payment_status = 'paid' AND COALESCE(paid_amount, 0) <= 0.004"
                    )->execute(['id' => $orderId]);
                }
            }

            if ($efecte['esuata'] !== '') {
                $stmt = $db->prepare(
                    "UPDATE orders
                     SET payment_status = 'failed',
                         status = CASE WHEN status = 'pending_payment' THEN 'failed' ELSE status END,
                         payment_error = :eroare
                     WHERE id = :id AND payment_status <> 'paid'"
                );
                $stmt->execute(['eroare' => mb_substr($efecte['esuata'], 0, 1000), 'id' => $orderId]);
                if ($stmt->rowCount() === 0) {
                    $efecte['esuata'] = '';
                }
            }
            if ($nota !== '' && $orderId > 0) {
                $db->prepare(
                    "UPDATE orders SET payment_error = :nota WHERE id = :id AND payment_status <> 'paid'"
                )->execute(['nota' => mb_substr($nota, 0, 1000), 'id' => $orderId]);
            }

            if ($inTranzactie) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($inTranzactie && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'mesaj' => 'Nu am putut salva starea plății: ' . $e->getMessage()];
        }

        // Efectele care pleacă în afară, după ce baza e la zi.
        $orderId = (int) ($tx['order_id'] ?? 0);
        if ($efecte['prima_plata']) {
            try {
                EmailAutomation::sendOrderTemplateById($db, Settings::all($db), $orderId, 'new_order');
            } catch (Throwable) {
                // Emailul nu ține plata pe loc.
            }
            try {
                // Comanda pleacă spre ERP acum, ca la EuPlătesc după confirmarea plății.
                ErpSync::push($db, $orderId);
            } catch (Throwable) {
                // Cronul ERP reîncearcă.
            }
        }
        if ($efecte['esuata'] !== '') {
            LoyaltyService::refundRedeemedPointsForOrder($db, $orderId);
            LoyaltyService::reverseAwardedPointsForOrder($db, $orderId);
            ErpSync::anuleaza($db, $orderId, 'Plata cu cardul nu a reușit; comanda nu se trimite în ERP.');
        }

        $txActual = self::tx($db, $txId) ?? $txNou;
        foreach (array_unique($efecte['alerte']) as $tipAlerta) {
            self::alerteaza($db, $txActual, $tipAlerta, $nota);
        }
        if ($efecte['elibereaza']) {
            self::anuleazaFaraLacat($db, $txActual, 'anulare');
        }

        return ['ok' => true, 'mesaj' => self::etichetaStare($nou), 'stare' => $nou];
    }

    private static function notaSumeDiferite(array $tx, int $card, int $loy, int $moneda): string
    {
        return 'Banca raportează o sumă diferită de cea a plății: card ' . self::lei($card) . ' + puncte STAR ' . self::lei($loy)
            . ' (moneda ' . $moneda . '), față de ' . self::lei((int) $tx['amount_minor'])
            . ' cerute. Plata NU a fost confirmată pe site; verifică în portalul BT.';
    }

    private static function esteUltima(PDO $db, array $tx): bool
    {
        $stmt = $db->prepare('SELECT MAX(id) FROM bt_ipay_transactions WHERE order_id = :o AND kind = :k');
        $stmt->execute(['o' => (int) $tx['order_id'], 'k' => self::TIP_COMANDA]);
        return (int) $stmt->fetchColumn() === (int) $tx['id'];
    }

    private static function altaPlataNumarata(PDO $db, array $tx): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM bt_ipay_transactions
             WHERE order_id = :o AND kind = :k AND id <> :id AND in_comanda = 1
               AND state IN ("authorized", "deposited", "partially_refunded")'
        );
        $stmt->execute(['o' => (int) $tx['order_id'], 'k' => self::TIP_COMANDA, 'id' => (int) $tx['id']]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function scrieEroare(PDO $db, int $txId, string $mesaj, ?string $stare = null): void
    {
        try {
            if ($stare !== null) {
                $db->prepare('UPDATE bt_ipay_transactions SET last_error = :m, state = :s, updated_at = :acum WHERE id = :id')
                    ->execute(['m' => mb_substr($mesaj, 0, 2000), 's' => $stare, 'acum' => date('Y-m-d H:i:s'), 'id' => $txId]);
            } else {
                $db->prepare('UPDATE bt_ipay_transactions SET last_error = :m, updated_at = :acum WHERE id = :id')
                    ->execute(['m' => mb_substr($mesaj, 0, 2000), 'acum' => date('Y-m-d H:i:s'), 'id' => $txId]);
            }
        } catch (Throwable) {
        }
    }

    // ------------------------------------------------------------------
    // Încasare (deposit)
    // ------------------------------------------------------------------

    /**
     * Încasează o plată autorizată: întâi partea în puncte, apoi cea pe card.
     * Suma poate fi mai mică decât cea blocată (comanda s-a micșorat); restul
     * rămâne neîncasat. Niciodată 0 — la bancă, 0 înseamnă „toată suma".
     *
     * @return array{ok: bool, mesaj: string}
     */
    public static function incaseaza(PDO $db, int $txId, ?int $sumaMinor, string $sursa, int $asteptare = 20): array
    {
        self::ensureSchema($db);
        return self::cuLacat($db, $txId, $asteptare, static function () use ($db, $txId, $sumaMinor, $sursa): array {
            $tx = self::tx($db, $txId);
            if ($tx === null) {
                return ['ok' => false, 'mesaj' => 'Plata nu există.'];
            }
            return self::incaseazaFaraLacat($db, $tx, $sumaMinor, $sursa);
        });
    }

    /** @return array{ok: bool, mesaj: string} */
    private static function incaseazaFaraLacat(PDO $db, array $tx, ?int $sumaMinor, string $sursa): array
    {
        $txId = (int) $tx['id'];
        // Întâi starea de acum de la bancă: nu încasăm pe baza unei stări vechi.
        $verificare = self::sincronizeazaFaraLacat($db, $tx, $sursa, false);
        $tx = self::tx($db, $txId) ?? $tx;
        if (in_array((string) $tx['state'], [self::STARE_INCASATA, self::STARE_RAMBURSATA_PARTIAL, self::STARE_RAMBURSATA], true)) {
            return ['ok' => true, 'mesaj' => 'Plata era deja încasată (' . self::lei((int) $tx['deposited_minor'] + (int) $tx['loy_deposited_minor']) . ').'];
        }
        if (!$verificare['ok']) {
            self::noteazaEsecIncasare($db, $tx, 'Nu am putut verifica starea la bancă: ' . $verificare['mesaj']);
            return ['ok' => false, 'mesaj' => 'Nu am putut verifica starea la bancă: ' . $verificare['mesaj']];
        }
        if ((string) $tx['state'] !== self::STARE_AUTORIZATA) {
            return ['ok' => false, 'mesaj' => 'Plata nu poate fi încasată: ' . self::etichetaStare((string) $tx['state']) . '.'];
        }

        $areLoy = trim((string) ($tx['loy_order_id'] ?? '')) !== '';
        $statusCard = isset($tx['bt_status']) ? (int) $tx['bt_status'] : -1;
        $statusLoy = isset($tx['loy_status']) ? (int) $tx['loy_status'] : -1;
        $cardBlocat = $statusCard === BtIpayGateway::STATUS_AUTORIZATA ? (int) $tx['approved_minor'] : 0;
        $loyBlocat = $areLoy && $statusLoy === BtIpayGateway::STATUS_AUTORIZATA ? (int) $tx['loy_minor'] : 0;
        $loyDejaIncasat = $areLoy && in_array($statusLoy, [2, 4, 7], true) ? (int) $tx['loy_deposited_minor'] : 0;
        $cardDejaIncasat = in_array($statusCard, [2, 4, 7], true) ? (int) $tx['deposited_minor'] : 0;
        $maxim = $cardBlocat + $loyBlocat + $loyDejaIncasat + $cardDejaIncasat;

        $suma = $sumaMinor ?? $maxim;
        if ($suma < 1) {
            return ['ok' => false, 'mesaj' => 'Suma de încasat trebuie să fie de cel puțin 0,01 lei.'];
        }
        if ($suma > $maxim) {
            return ['ok' => false, 'mesaj' => 'Suma depășește cât e autorizat pe card (' . self::lei($maxim) . ').'];
        }

        // Întâi punctele (ca modulul BT), apoi cardul cu restul.
        $loyDeIncasat = min($loyBlocat, max(0, $suma - $loyDejaIncasat - $cardDejaIncasat));
        $cardDeIncasat = $suma - $loyDejaIncasat - $cardDejaIncasat - $loyDeIncasat;
        if ($cardDeIncasat > $cardBlocat) {
            return ['ok' => false, 'mesaj' => 'Suma depășește partea autorizată pe card.'];
        }

        $mod = BtIpayGateway::modValid((string) $tx['mode']);
        $context = ['tx_id' => $txId, 'order_id' => (int) ($tx['order_id'] ?? 0), 'sursa' => $sursa];
        $eroareApel = '';
        if ($loyDeIncasat > 0) {
            $rez = BtIpayGateway::apel($mod, 'deposit', ['orderId' => (string) $tx['loy_order_id'], 'amount' => (string) $loyDeIncasat], $db, $context);
            if (!$rez['ok']) {
                $eroareApel = 'Încasarea punctelor STAR a eșuat: ' . $rez['eroare'];
            }
        } elseif ($loyBlocat > 0) {
            // Comanda s-a micșorat sub partea plătită în puncte (caz rar): restul
            // punctelor nu se încasează, deci autorizarea lor se eliberează.
            BtIpayGateway::apel($mod, 'reverse', ['orderId' => (string) $tx['loy_order_id']], $db, $context);
        }

        // Cardul doar după ce punctele au mers: altfel plata rămâne pe jumătate.
        if ($eroareApel === '') {
            if ($cardDeIncasat > 0) {
                $rez = BtIpayGateway::apel($mod, 'deposit', ['orderId' => (string) $tx['bt_order_id'], 'amount' => (string) $cardDeIncasat], $db, $context);
                if (!$rez['ok']) {
                    $eroareApel = 'Încasarea a eșuat: ' . $rez['eroare'];
                }
            } elseif ($cardBlocat > 0) {
                // Totul a intrat pe puncte: partea de card nu mai are ce încasa.
                BtIpayGateway::apel($mod, 'reverse', ['orderId' => (string) $tx['bt_order_id']], $db, $context);
            }
        }

        // Verificarea de după, ca în CaptureDetailsHandler: starea și suma încasată.
        // Se face și după un apel eșuat: dacă răspunsul s-a pierdut pe drum, dar
        // banca a încasat, starea de la ea e cea care contează.
        $dupa = self::sincronizeazaFaraLacat($db, self::tx($db, $txId) ?? $tx, $sursa, false);
        $tx = self::tx($db, $txId) ?? $tx;
        $incasat = (int) $tx['deposited_minor'] + (int) $tx['loy_deposited_minor'];
        if (!$dupa['ok'] || (string) $tx['state'] !== self::STARE_INCASATA || $incasat !== $suma) {
            $mesaj = $eroareApel !== ''
                ? $eroareApel
                : 'Banca nu confirmă încasarea (stare: ' . self::etichetaStare((string) $tx['state'])
                    . ', încasat ' . self::lei($incasat) . ' din ' . self::lei($suma) . '). Verifică în portalul BT.';
            self::noteazaEsecIncasare($db, $tx, $mesaj);
            return ['ok' => false, 'mesaj' => $mesaj];
        }

        $db->prepare('UPDATE bt_ipay_transactions SET deposit_requested_at = NULL, last_error = NULL, updated_at = :acum WHERE id = :id')
            ->execute(['acum' => date('Y-m-d H:i:s'), 'id' => $txId]);
        return ['ok' => true, 'mesaj' => 'Plata a fost încasată: ' . self::lei($incasat) . '.'
            . ($eroareApel !== '' ? ' (Banca a confirmat încasarea la verificare, deși răspunsul ei inițial s-a pierdut.)' : '')];
    }

    private static function noteazaEsecIncasare(PDO $db, array $tx, string $mesaj): void
    {
        try {
            $db->prepare(
                'UPDATE bt_ipay_transactions
                 SET last_error = :m, deposit_attempts = deposit_attempts + 1,
                     deposit_requested_at = COALESCE(deposit_requested_at, :acum), updated_at = :acum2
                 WHERE id = :id'
            )->execute(['m' => mb_substr($mesaj, 0, 2000), 'acum' => date('Y-m-d H:i:s'), 'acum2' => date('Y-m-d H:i:s'), 'id' => (int) $tx['id']]);
        } catch (Throwable) {
        }
    }

    /**
     * Comanda a fost aprobată (facturată) în ERP: încasăm plata BT, dacă are una
     * doar autorizată. Suma = minimul dintre cât e blocat și totalul de acum al
     * comenzii de pe site (la precomenzi, factura de avans nu e totalul).
     *
     * @return array{ok: bool, mesaj: string, facut: bool}
     */
    public static function incaseazaLaAprobare(PDO $db, int $orderId, ?float $totalEveniment, array $settings): array
    {
        self::ensureSchema($db);
        if (!self::setari($settings)['incaseaza_la_aprobare']) {
            return ['ok' => true, 'mesaj' => '', 'facut' => false];
        }
        $tx = null;
        foreach (self::tranzactiiComanda($db, $orderId) as $candidat) {
            if ((string) $candidat['state'] === self::STARE_AUTORIZATA && (int) $candidat['in_comanda'] === 1) {
                $tx = $candidat;
                break;
            }
        }
        if ($tx === null) {
            return ['ok' => true, 'mesaj' => '', 'facut' => false];
        }

        $suma = self::sumaDeIncasat($db, $tx);
        $rez = self::incaseaza($db, (int) $tx['id'], $suma, 'erp');
        $mesaj = 'BT: ' . $rez['mesaj'];
        $comanda = self::comanda($db, $orderId);
        if ($totalEveniment !== null && $comanda !== null && abs((float) $comanda['total'] - $totalEveniment) > 0.009) {
            $mesaj .= ' (Totalul facturii din ERP, ' . number_format($totalEveniment, 2, ',', '.') . ' lei, diferă de totalul comenzii de pe site; am încasat după site.)';
        }
        if (!$rez['ok']) {
            self::alerteaza($db, self::tx($db, (int) $tx['id']) ?? $tx, 'incasare_esuata', $rez['mesaj']);
        }
        return ['ok' => $rez['ok'], 'mesaj' => $mesaj, 'facut' => true];
    }

    /** Cât se încasează automat: min(blocat, totalul comenzii), în bani. */
    private static function sumaDeIncasat(PDO $db, array $tx): int
    {
        $blocat = self::netTx($tx);
        $comanda = self::comanda($db, (int) ($tx['order_id'] ?? 0));
        if ($comanda === null) {
            return $blocat;
        }
        $total = (int) round((float) $comanda['total'] * 100);
        return max(0, min($blocat, $total));
    }

    // ------------------------------------------------------------------
    // Eliberarea sumei blocate (reverse)
    // ------------------------------------------------------------------

    /** @return array{ok: bool, mesaj: string} */
    public static function anuleaza(PDO $db, int $txId, string $sursa, int $asteptare = 20): array
    {
        self::ensureSchema($db);
        return self::cuLacat($db, $txId, $asteptare, static function () use ($db, $txId, $sursa): array {
            $tx = self::tx($db, $txId);
            if ($tx === null) {
                return ['ok' => false, 'mesaj' => 'Plata nu există.'];
            }
            return self::anuleazaFaraLacat($db, $tx, $sursa);
        });
    }

    /** @return array{ok: bool, mesaj: string} */
    private static function anuleazaFaraLacat(PDO $db, array $tx, string $sursa): array
    {
        $txId = (int) $tx['id'];
        $verificare = self::sincronizeazaFaraLacat($db, $tx, $sursa === 'anulare' ? 'anulare' : $sursa, false);
        $tx = self::tx($db, $txId) ?? $tx;
        if ((string) $tx['state'] === self::STARE_ANULATA) {
            return ['ok' => true, 'mesaj' => 'Autorizarea era deja anulată; suma e eliberată.'];
        }
        if (!$verificare['ok']) {
            return ['ok' => false, 'mesaj' => 'Nu am putut verifica starea la bancă: ' . $verificare['mesaj']];
        }
        if ((string) $tx['state'] !== self::STARE_AUTORIZATA) {
            return ['ok' => false, 'mesaj' => 'Autorizarea nu se mai poate anula: ' . self::etichetaStare((string) $tx['state'])
                . (in_array((string) $tx['state'], [self::STARE_INCASATA, self::STARE_RAMBURSATA_PARTIAL], true) ? ' Folosește „Rambursează".' : '')];
        }

        $mod = BtIpayGateway::modValid((string) $tx['mode']);
        $context = ['tx_id' => $txId, 'order_id' => (int) ($tx['order_id'] ?? 0), 'sursa' => $sursa];
        $eroareApel = '';
        // Ca în CancelTransaction: întâi comanda LOY, apoi cea pe card.
        if (trim((string) ($tx['loy_order_id'] ?? '')) !== '' && (int) ($tx['loy_status'] ?? -1) === BtIpayGateway::STATUS_AUTORIZATA) {
            $rez = BtIpayGateway::apel($mod, 'reverse', ['orderId' => (string) $tx['loy_order_id']], $db, $context);
            if (!$rez['ok']) {
                $eroareApel = 'Eliberarea punctelor STAR a eșuat: ' . $rez['eroare'];
            }
        }
        if ($eroareApel === '' && (int) ($tx['bt_status'] ?? -1) === BtIpayGateway::STATUS_AUTORIZATA) {
            $rez = BtIpayGateway::apel($mod, 'reverse', ['orderId' => (string) $tx['bt_order_id']], $db, $context);
            if (!$rez['ok']) {
                $eroareApel = 'Eliberarea sumei a eșuat: ' . $rez['eroare'];
            }
        }

        // Starea de la bancă decide, și după un răspuns pierdut.
        $dupa = self::sincronizeazaFaraLacat($db, self::tx($db, $txId) ?? $tx, 'anulare', false);
        $tx = self::tx($db, $txId) ?? $tx;
        if (!$dupa['ok'] || (string) $tx['state'] !== self::STARE_ANULATA) {
            $mesaj = $eroareApel !== ''
                ? $eroareApel
                : 'Banca nu confirmă eliberarea întregii sume (stare: ' . self::etichetaStare((string) $tx['state']) . ').';
            self::scrieEroare($db, $txId, $mesaj);
            return ['ok' => false, 'mesaj' => $mesaj];
        }
        return ['ok' => true, 'mesaj' => 'Autorizarea a fost anulată: suma blocată pe cardul clientului a fost eliberată.'];
    }

    // ------------------------------------------------------------------
    // Rambursare (refund)
    // ------------------------------------------------------------------

    /**
     * Rambursează o sumă dintr-o plată încasată. Întâi punctele STAR (până la cât
     * s-a încasat în puncte), apoi cardul — ca RefundDataBuilder din modulul BT.
     *
     * O rambursare dublă ar fi bani adevărați, deci înainte de apel se scrie un
     * marcaj; dacă cererea se rupe (timp depășit), următoarea încercare citește
     * întâi starea de la bancă și nu mai trimite nimic până nu e lămurit.
     *
     * @return array{ok: bool, mesaj: string}
     */
    public static function ramburseaza(PDO $db, int $txId, int $sumaMinor, string $sursa, int $asteptare = 20): array
    {
        self::ensureSchema($db);
        return self::cuLacat($db, $txId, $asteptare, static function () use ($db, $txId, $sumaMinor, $sursa): array {
            $tx = self::tx($db, $txId);
            if ($tx === null) {
                return ['ok' => false, 'mesaj' => 'Plata nu există.'];
            }
            return self::ramburseazaFaraLacat($db, $tx, $sumaMinor, $sursa);
        });
    }

    /** @return array{ok: bool, mesaj: string} */
    private static function ramburseazaFaraLacat(PDO $db, array $tx, int $sumaMinor, string $sursa): array
    {
        $txId = (int) $tx['id'];
        $verificare = self::sincronizeazaFaraLacat($db, $tx, $sursa, false);
        $tx = self::tx($db, $txId) ?? $tx;
        if (!$verificare['ok']) {
            return ['ok' => false, 'mesaj' => 'Nu am putut verifica starea la bancă: ' . $verificare['mesaj'] . ' Nu am trimis nicio rambursare.'];
        }

        // O rambursare anterioară rămasă fără răspuns: o lămurim întâi.
        $marcaj = json_decode((string) ($tx['op_pending'] ?? ''), true);
        if (is_array($marcaj) && ($marcaj['op'] ?? '') === 'refund') {
            $rambursatAcum = (int) $tx['refunded_minor'] + (int) $tx['loy_refunded_minor'];
            $inainte = (int) ($marcaj['inainte'] ?? 0);
            $cerut = (int) ($marcaj['suma'] ?? 0);
            $db->prepare('UPDATE bt_ipay_transactions SET op_pending = NULL WHERE id = :id')->execute(['id' => $txId]);
            if ($rambursatAcum >= $inainte + $cerut) {
                return ['ok' => false, 'mesaj' => 'Rambursarea anterioară de ' . self::lei($cerut)
                    . ' a fost deja făcută la bancă (rambursat în total: ' . self::lei($rambursatAcum) . '). Nu am trimis alta; verifică suma înainte să repeți.'];
            }
            if ($rambursatAcum > $inainte) {
                return ['ok' => false, 'mesaj' => 'Rambursarea anterioară s-a făcut doar în parte (rambursat în total: ' . self::lei($rambursatAcum)
                    . '). Verifică în portalul BT, apoi rambursează restul dacă e cazul.'];
            }
            // Nimic nu s-a mișcat la bancă: putem continua.
        }

        if (!in_array((string) $tx['state'], [self::STARE_INCASATA, self::STARE_RAMBURSATA_PARTIAL], true)) {
            return ['ok' => false, 'mesaj' => 'Plata nu poate fi rambursată: ' . self::etichetaStare((string) $tx['state'])
                . ((string) $tx['state'] === self::STARE_AUTORIZATA ? ' Suma nu e încă încasată — folosește „Anulează autorizarea".' : '')];
        }

        $areLoy = trim((string) ($tx['loy_order_id'] ?? '')) !== '';
        $cardRambursabil = max(0, (int) $tx['deposited_minor'] - (int) $tx['refunded_minor']);
        $loyRambursabil = $areLoy ? max(0, (int) $tx['loy_deposited_minor'] - (int) $tx['loy_refunded_minor']) : 0;
        $maxim = $cardRambursabil + $loyRambursabil;
        if ($sumaMinor < 1) {
            return ['ok' => false, 'mesaj' => 'Scrie suma de rambursat (cel puțin 0,01 lei).'];
        }
        if ($sumaMinor > $maxim) {
            return ['ok' => false, 'mesaj' => 'Suma depășește cât se mai poate rambursa (' . self::lei($maxim) . ').'];
        }
        $loyParte = min($loyRambursabil, $sumaMinor);
        $cardParte = $sumaMinor - $loyParte;

        $inainte = (int) $tx['refunded_minor'] + (int) $tx['loy_refunded_minor'];
        $marcajNou = json_encode([
            'op' => 'refund',
            'suma' => $sumaMinor,
            'loy' => $loyParte,
            'card' => $cardParte,
            'inainte' => $inainte,
            'la' => date('Y-m-d H:i:s'),
            'sursa' => $sursa,
        ]);
        $stmt = $db->prepare('UPDATE bt_ipay_transactions SET op_pending = :m, updated_at = :acum WHERE id = :id AND op_pending IS NULL');
        $stmt->execute(['m' => $marcajNou, 'acum' => date('Y-m-d H:i:s'), 'id' => $txId]);
        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'mesaj' => 'O altă operație pe această plată e în curs.'];
        }

        $mod = BtIpayGateway::modValid((string) $tx['mode']);
        $context = ['tx_id' => $txId, 'order_id' => (int) ($tx['order_id'] ?? 0), 'sursa' => $sursa];
        $eroare = '';
        $raspunsSigur = true;
        if ($loyParte > 0) {
            $rez = BtIpayGateway::apel($mod, 'refund', ['orderId' => (string) $tx['loy_order_id'], 'amount' => (string) $loyParte], $db, $context);
            if (!$rez['ok']) {
                $eroare = 'Rambursarea punctelor STAR a eșuat: ' . $rez['eroare'];
                $raspunsSigur = $rez['http'] >= 200 && $rez['http'] < 300 && $rez['cod'] !== '';
            }
        }
        if ($eroare === '' && $cardParte > 0) {
            $rez = BtIpayGateway::apel($mod, 'refund', ['orderId' => (string) $tx['bt_order_id'], 'amount' => (string) $cardParte], $db, $context);
            if (!$rez['ok']) {
                $eroare = 'Rambursarea pe card a eșuat: ' . $rez['eroare'] . ($loyParte > 0 ? ' Punctele STAR (' . self::lei($loyParte) . ') au fost rambursate.' : '');
                $raspunsSigur = $rez['http'] >= 200 && $rez['http'] < 300 && $rez['cod'] !== '';
            }
        }

        // Verificarea de după (RefundDetailsHandler): starea și suma rambursată.
        $dupa = self::sincronizeazaFaraLacat($db, self::tx($db, $txId) ?? $tx, $sursa, false);
        $tx = self::tx($db, $txId) ?? $tx;
        $rambursatDupa = (int) $tx['refunded_minor'] + (int) $tx['loy_refunded_minor'];

        if ($dupa['ok'] && $rambursatDupa >= $inainte + $sumaMinor) {
            $db->prepare('UPDATE bt_ipay_transactions SET op_pending = NULL WHERE id = :id')->execute(['id' => $txId]);
            $referinta = trim((string) ($tx['refund_reference'] ?? ''));
            return ['ok' => true, 'mesaj' => 'Am rambursat ' . self::lei($sumaMinor)
                . ($loyParte > 0 ? ' (din care ' . self::lei($loyParte) . ' în puncte STAR)' : '')
                . ($referinta !== '' ? '. Referință bancă: ' . $referinta : '') . '.'];
        }
        $marcajRamas = true;
        if ($dupa['ok'] && $raspunsSigur && $rambursatDupa === $inainte) {
            // Banca a refuzat clar și nimic nu s-a mișcat: se poate reîncerca.
            $marcajRamas = false;
        } elseif ($dupa['ok'] && $eroare !== '' && $raspunsSigur && $rambursatDupa > $inainte) {
            // O parte (punctele) s-a rambursat, cealaltă a fost refuzată clar.
            $marcajRamas = false;
        }
        if (!$marcajRamas) {
            $db->prepare('UPDATE bt_ipay_transactions SET op_pending = NULL WHERE id = :id')->execute(['id' => $txId]);
        }
        $mesaj = $eroare !== '' ? $eroare : 'Banca nu confirmă încă rambursarea.';
        if ($marcajRamas) {
            $mesaj .= ' Rezultatul nu e sigur, așa că nu mai trimit altă rambursare până nu se lămurește: '
                . 'apasă „Verifică la bancă" peste un minut; următoarea încercare citește întâi starea de la bancă.';
        }
        self::scrieEroare($db, $txId, $mesaj);
        return ['ok' => false, 'mesaj' => $mesaj];
    }

    // ------------------------------------------------------------------
    // Legături cu comanda
    // ------------------------------------------------------------------

    /**
     * Comanda a fost anulată / returnată / marcată eșuată sau rambursată pe site:
     * suma doar blocată se eliberează automat. Una deja încasată NU se
     * rambursează singură — se anunță magazinul.
     *
     * @return list<string> mesaje pentru operator
     */
    public static function laInchidereComanda(PDO $db, int $orderId, string $sursa): array
    {
        self::ensureSchema($db);
        $mesaje = [];
        foreach (self::tranzactiiComanda($db, $orderId) as $tx) {
            $stare = (string) $tx['state'];
            if ($stare === self::STARE_AUTORIZATA) {
                $rez = self::anuleaza($db, (int) $tx['id'], 'anulare');
                $mesaje[] = $rez['ok']
                    ? 'BT: suma blocată pe card a fost eliberată.'
                    : 'BT: eliberarea sumei blocate n-a mers acum (' . $rez['mesaj'] . '); cronul reîncearcă.';
            } elseif (in_array($stare, [self::STARE_INCASATA, self::STARE_RAMBURSATA_PARTIAL], true)
                && self::netTx($tx) > 0) {
                $mesaje[] = 'BT: plata e deja încasată (' . self::lei(self::netTx($tx)) . ') — rambursarea se face din comandă, cu butonul „Rambursează".';
                self::alerteaza($db, $tx, 'necesita_rambursare', 'Încasat și nerambursat: ' . self::lei(self::netTx($tx))
                    . ($sursa === 'erp' ? ' (comanda a fost anulată din ERP).' : '.'));
            }
        }
        return $mesaje;
    }

    // ------------------------------------------------------------------
    // Pentru admin
    // ------------------------------------------------------------------

    /**
     * Rezumatul plăților BT pentru comenzile din listă (doar cele care au).
     *
     * @param list<int> $orderIds
     * @param array<int, array<string, mixed>> $comenzi id → rând comandă (status, total)
     * @return array<int, array<string, mixed>>
     */
    public static function rezumatPentruAdmin(PDO $db, array $orderIds, array $comenzi, array $settings): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds), static fn (int $id): bool => $id > 0));
        if ($orderIds === []) {
            return [];
        }
        self::ensureSchema($db);
        $semne = implode(',', array_fill(0, count($orderIds), '?'));
        try {
            $stmt = $db->prepare(
                'SELECT * FROM bt_ipay_transactions WHERE kind = "order" AND order_id IN (' . $semne . ') ORDER BY id DESC'
            );
            $stmt->execute($orderIds);
            $randuri = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
        $peComanda = [];
        foreach ($randuri as $rand) {
            if (is_array($rand)) {
                $peComanda[(int) $rand['order_id']][] = $rand;
            }
        }
        $s = self::setari($settings);
        $rez = [];
        foreach ($peComanda as $orderId => $lista) {
            $tx = self::principala($lista);
            if ($tx !== null) {
                $rez[$orderId] = self::rezumatTx($tx, $comenzi[$orderId] ?? [], $s, count($lista));
            }
        }
        return $rez;
    }

    /** @return array<string, mixed> */
    private static function rezumatTx(array $tx, array $comanda, array $setari, int $numar): array
    {
        $stare = (string) $tx['state'];
        $areLoy = trim((string) ($tx['loy_order_id'] ?? '')) !== '';
        $net = self::netTx($tx);
        $incasat = (int) $tx['deposited_minor'] + ($areLoy ? (int) $tx['loy_deposited_minor'] : 0);
        $rambursat = (int) $tx['refunded_minor'] + ($areLoy ? (int) $tx['loy_refunded_minor'] : 0);
        $rambursabil = in_array($stare, [self::STARE_INCASATA, self::STARE_RAMBURSATA_PARTIAL], true) ? max(0, $incasat - $rambursat) : 0;
        $totalComanda = (int) round((float) ($comanda['total'] ?? 0) * 100);
        $statusComanda = (string) ($comanda['status'] ?? '');
        $inchisa = in_array($statusComanda, self::COMENZI_INCHISE, true);

        $termen = '';
        if ($stare === self::STARE_AUTORIZATA && trim((string) ($tx['authorized_at'] ?? '')) !== '') {
            $ts = strtotime((string) $tx['authorized_at']);
            if ($ts !== false) {
                $termen = date('d.m.Y H:i', $ts + $setari['ore_incasare_automata'] * 3600);
            }
        }
        $clasa = match ($stare) {
            self::STARE_AUTORIZATA => 'warn',
            self::STARE_INCASATA => 'ok',
            self::STARE_RAMBURSATA, self::STARE_RAMBURSATA_PARTIAL, self::STARE_ANULATA, self::STARE_EXPIRATA => 'muted',
            self::STARE_REFUZATA, self::STARE_EROARE => 'off',
            default => 'info',
        };
        $necesitaRambursare = $inchisa && $rambursabil > 0;

        return [
            'tx_id' => (int) $tx['id'],
            'numar_bt' => (string) $tx['bt_order_number'],
            'id_bt' => (string) ($tx['bt_order_id'] ?? ''),
            'id_loy' => (string) ($tx['loy_order_id'] ?? ''),
            'mod' => (string) $tx['mode'],
            'stare' => $stare,
            'eticheta' => self::etichetaStare($stare),
            'clasa' => $necesitaRambursare ? 'off' : $clasa,
            'suma' => self::bani((int) $tx['amount_minor']),
            'autorizat' => self::bani((int) $tx['approved_minor'] + ($areLoy ? (int) $tx['loy_minor'] : 0)),
            'puncte' => self::bani((int) $tx['loy_minor']),
            'incasat' => self::bani($incasat),
            'rambursat' => self::bani($rambursat),
            'rambursabil' => self::bani($rambursabil),
            'incasabil' => $stare === self::STARE_AUTORIZATA ? self::bani($net) : 0.0,
            'sugestie_incasare' => $stare === self::STARE_AUTORIZATA ? self::bani(max(0, min($net, $totalComanda))) : 0.0,
            'termen' => $termen,
            'autorizat_la' => (string) ($tx['authorized_at'] ?? ''),
            'incasat_la' => (string) ($tx['deposited_at'] ?? ''),
            'cod_aprobare' => (string) ($tx['approval_code'] ?? ''),
            'referinta_rambursare' => (string) ($tx['refund_reference'] ?? ''),
            'eroare' => trim((string) ($tx['last_error'] ?? '')),
            'in_comanda' => (int) ($tx['in_comanda'] ?? 0) === 1,
            'poate_incasa' => $stare === self::STARE_AUTORIZATA,
            'poate_anula' => $stare === self::STARE_AUTORIZATA,
            'poate_rambursa' => $rambursabil > 0,
            'necesita_rambursare' => $necesitaRambursare,
            'incercari' => $numar,
        ];
    }

    /** Plățile de test recente, pentru tab-ul BT. @return list<array<string, mixed>> */
    public static function platiTestRecente(PDO $db, int $limita = 10): array
    {
        self::ensureSchema($db);
        try {
            $stmt = $db->query(
                'SELECT * FROM bt_ipay_transactions WHERE kind = "test" ORDER BY id DESC LIMIT ' . max(1, min(50, $limita))
            );
            $lista = [];
            foreach (($stmt->fetchAll() ?: []) as $tx) {
                if (is_array($tx)) {
                    $rezumat = self::rezumatTx($tx, ['total' => self::bani((int) $tx['amount_minor'])], self::setari([]), 1);
                    $rezumat['creat_la'] = (string) $tx['created_at'];
                    $lista[] = $rezumat;
                }
            }
            return $lista;
        } catch (Throwable) {
            return [];
        }
    }

    /** Ultimele apeluri către bancă (fără parole), pentru diagnostic. @return list<array<string, mixed>> */
    public static function jurnalRecent(PDO $db, int $limita = 25): array
    {
        self::ensureSchema($db);
        try {
            $stmt = $db->query(
                'SELECT l.id, l.tx_id, l.order_id, l.mode, l.action, l.source, l.http_code, l.error_code,
                        l.duration_ms, l.message, l.created_at, t.bt_order_number
                 FROM bt_ipay_log l LEFT JOIN bt_ipay_transactions t ON t.id = l.tx_id
                 ORDER BY l.id DESC LIMIT ' . max(1, min(100, $limita))
            );
            return array_values(array_filter($stmt->fetchAll() ?: [], 'is_array'));
        } catch (Throwable) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Cron
    // ------------------------------------------------------------------

    /**
     * Plasa de siguranță, rulată la 15 minute (scripts/bt-ipay-sync.php):
     *  a) plățile începute și neterminate se verifică și, după 60 de minute, expiră;
     *  b) suma blocată pentru comenzi anulate/șterse se eliberează (și testele, după 30 min);
     *  c) încasările cerute care au eșuat se reîncearcă;
     *  d) la 72 de ore: email cu plățile încă neîncasate;
     *  e) la 96 de ore (ziua 4): încasare automată, inclusiv precomenzile, și email;
     *  f) bătaia de inimă (vizibilă în admin) și curățenia jurnalului.
     *
     * @param callable(string): void|null $scrie
     * @return array<string, int|bool>
     */
    public static function cron(PDO $db, array $settings, ?callable $scrie = null): array
    {
        self::ensureSchema($db);
        $scrie ??= static function (string $text): void {
        };
        if (!self::iaLacat($db, 'cron', 0)) {
            $scrie('O altă rulare e încă în curs; ies.');
            return ['ocupat' => true];
        }

        $rez = ['verificate' => 0, 'expirate' => 0, 'eliberate' => 0, 'reincercate' => 0, 'reamintiri' => 0,
            'incasate_automat' => 0, 'erori' => 0, 'ocupat' => false];
        try {
            Settings::save($db, ['bt_ipay_cron_heartbeat' => date('Y-m-d H:i:s')]);
            $s = self::setari($settings);
            $acum = time();

            // a) Plăți începute, neterminate: le verificăm la fiecare trecere; după
            //    pragul de expirare, ce n-a fost plătit expiră (comanda devine eșuată).
            $limitaExpirare = date('Y-m-d H:i:s', $acum - $s['minute_expirare'] * 60);
            $stmt = $db->query(
                'SELECT id, created_at FROM bt_ipay_transactions
                 WHERE state IN ("registered", "pending_3ds")
                 ORDER BY id ASC LIMIT 100'
            );
            foreach (($stmt->fetchAll() ?: []) as $rand) {
                $expira = (string) $rand['created_at'] <= $limitaExpirare;
                $r = self::sincronizeaza($db, (int) $rand['id'], 'cron', $expira, 0);
                $rez['verificate']++;
                if (!$r['ok']) {
                    $rez['erori']++;
                } elseif (($r['stare'] ?? '') === self::STARE_EXPIRATA) {
                    $rez['expirate']++;
                }
            }

            // b) Bani doar blocați pentru comenzi care nu mai sunt vii (anulate,
            //    returnate, rambursate, eșuate, șterse), plus testele uitate.
            $stmt = $db->prepare(
                'SELECT t.id FROM bt_ipay_transactions t
                 LEFT JOIN orders o ON o.id = t.order_id
                 WHERE t.state = "authorized" AND (
                     (t.kind = "order" AND (o.id IS NULL OR o.deleted_at IS NOT NULL
                        OR o.status IN ("cancelled", "refunded", "returned")
                        OR (o.status = "failed" AND t.in_comanda = 1)))
                     OR (t.kind = "test" AND t.authorized_at <= :limita_test)
                 ) ORDER BY t.id ASC LIMIT 50'
            );
            $stmt->execute(['limita_test' => date('Y-m-d H:i:s', $acum - self::MINUTE_TEST_AUTORIZAT * 60)]);
            foreach (($stmt->fetchAll() ?: []) as $rand) {
                $r = self::anuleaza($db, (int) $rand['id'], 'cron', 0);
                $r['ok'] ? $rez['eliberate']++ : $rez['erori']++;
            }

            // c) Încasări cerute (aprobare ERP, buton) care n-au mers: reîncercăm.
            $stmt = $db->query(
                'SELECT t.* FROM bt_ipay_transactions t JOIN orders o ON o.id = t.order_id
                 WHERE t.state = "authorized" AND t.kind = "order" AND t.in_comanda = 1
                   AND t.deposit_requested_at IS NOT NULL AND o.deleted_at IS NULL
                   AND o.status NOT IN ("cancelled", "refunded", "returned", "failed")
                 ORDER BY t.id ASC LIMIT 25'
            );
            foreach (($stmt->fetchAll() ?: []) as $tx) {
                $r = self::incaseaza($db, (int) $tx['id'], self::sumaDeIncasat($db, $tx), 'cron', 0);
                $rez['reincercate']++;
                if (!$r['ok']) {
                    $rez['erori']++;
                    $txActual = self::tx($db, (int) $tx['id']) ?? $tx;
                    if ((int) $txActual['deposit_attempts'] >= 3) {
                        self::alerteaza($db, $txActual, 'incasare_esuata_repetat', $r['mesaj']);
                    }
                }
            }

            // d) Reamintire: plăți autorizate, încă neîncasate.
            $limitaReamintire = date('Y-m-d H:i:s', $acum - $s['ore_reamintire'] * 3600);
            Precomanda::ensureSchema($db);
            $stmt = $db->prepare(
                'SELECT t.*, o.order_number, o.total, o.billing_first_name, o.billing_last_name, o.preorder_status
                 FROM bt_ipay_transactions t JOIN orders o ON o.id = t.order_id
                 WHERE t.state = "authorized" AND t.kind = "order" AND t.in_comanda = 1 AND t.authorized_at <= :limita
                   AND t.reminder_sent_at IS NULL AND o.deleted_at IS NULL
                   AND o.status NOT IN ("cancelled", "refunded", "returned", "failed")
                 ORDER BY t.authorized_at ASC LIMIT 100'
            );
            $stmt->execute(['limita' => $limitaReamintire]);
            $deReamintit = array_values(array_filter($stmt->fetchAll() ?: [], 'is_array'));
            if ($deReamintit !== []) {
                self::emailReamintire($db, $settings, $s, $deReamintit);
                $marcheaza = $db->prepare('UPDATE bt_ipay_transactions SET reminder_sent_at = :acum WHERE id = :id');
                foreach ($deReamintit as $tx) {
                    $marcheaza->execute(['acum' => date('Y-m-d H:i:s'), 'id' => (int) $tx['id']]);
                }
                $rez['reamintiri'] = count($deReamintit);
            }

            // e) Ziua 4: încasare automată, ca să nu pierdem plata (inclusiv precomenzile).
            $limitaAutomat = date('Y-m-d H:i:s', $acum - $s['ore_incasare_automata'] * 3600);
            $stmt = $db->prepare(
                'SELECT t.*, o.order_number, o.total, o.billing_first_name, o.billing_last_name
                 FROM bt_ipay_transactions t JOIN orders o ON o.id = t.order_id
                 WHERE t.state = "authorized" AND t.kind = "order" AND t.in_comanda = 1
                   AND t.authorized_at <= :limita AND o.deleted_at IS NULL
                   AND o.status NOT IN ("cancelled", "refunded", "returned", "failed")
                 ORDER BY t.authorized_at ASC LIMIT 50'
            );
            $stmt->execute(['limita' => $limitaAutomat]);
            $rezultateAutomat = [];
            foreach (($stmt->fetchAll() ?: []) as $tx) {
                $suma = self::sumaDeIncasat($db, $tx);
                if ($suma < 1) {
                    $r = self::anuleaza($db, (int) $tx['id'], 'cron', 0);
                    $r['mesaj'] = 'Totalul comenzii e 0, așa că am eliberat suma: ' . $r['mesaj'];
                } else {
                    $r = self::incaseaza($db, (int) $tx['id'], $suma, 'cron', 0);
                }
                $r['ok'] ? $rez['incasate_automat']++ : $rez['erori']++;
                $rezultateAutomat[] = ['tx' => $tx, 'suma' => $suma, 'rezultat' => $r];
            }
            if ($rezultateAutomat !== []) {
                self::emailIncasareAutomata($db, $settings, $s, $rezultateAutomat);
            }

            // Plăți autorizate care n-au putut fi legate de comandă (venite pentru
            // o comandă deja plătită altfel): nu le încasăm singuri, dar le semnalăm.
            $stmt = $db->prepare(
                'SELECT t.* FROM bt_ipay_transactions t
                 WHERE t.state = "authorized" AND t.kind = "order" AND t.in_comanda = 0 AND t.authorized_at <= :limita
                 ORDER BY t.id ASC LIMIT 25'
            );
            $stmt->execute(['limita' => $limitaReamintire]);
            foreach (($stmt->fetchAll() ?: []) as $tx) {
                self::alerteaza($db, $tx, 'plata_in_plus', '');
            }

            // f) Curățenia jurnalului.
            $db->prepare('DELETE FROM bt_ipay_log WHERE created_at < :limita')
                ->execute(['limita' => date('Y-m-d H:i:s', $acum - self::ZILE_JURNAL * 86400)]);
        } catch (Throwable $e) {
            $rez['erori']++;
            $scrie('Eroare: ' . $e->getMessage());
        } finally {
            self::elibereazaLacat($db, 'cron');
        }
        return $rez;
    }

    // ------------------------------------------------------------------
    // Emailuri către magazin
    // ------------------------------------------------------------------

    /** Trimite o alertă o singură dată pe tip, pe plată. */
    private static function alerteaza(PDO $db, array $tx, string $tip, string $detalii): void
    {
        $trimise = json_decode((string) ($tx['alerts_json'] ?? ''), true);
        $trimise = is_array($trimise) ? $trimise : [];
        if (isset($trimise[$tip])) {
            return;
        }
        $trimise[$tip] = date('Y-m-d H:i:s');
        try {
            $db->prepare('UPDATE bt_ipay_transactions SET alerts_json = :j WHERE id = :id')
                ->execute(['j' => json_encode($trimise), 'id' => (int) $tx['id']]);
        } catch (Throwable) {
            return;
        }

        $comanda = self::comanda($db, (int) ($tx['order_id'] ?? 0));
        $numar = $comanda !== null ? (string) $comanda['order_number'] : (string) $tx['bt_order_number'];
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES);
        $titluri = [
            'plata_comanda_inchisa' => 'Plată primită pentru o comandă anulată — ' . $numar,
            'plata_in_plus' => 'Plată în plus pe comanda ' . $numar,
            'eliberata_la_banca' => 'Suma blocată a fost eliberată la bancă — comanda ' . $numar . ' e încă activă',
            'sume_diferite' => 'Sumă diferită raportată de bancă — comanda ' . $numar,
            'incasare_esuata' => 'Încasarea plății BT a eșuat — comanda ' . $numar,
            'incasare_esuata_repetat' => 'Încasarea plății BT eșuează repetat — comanda ' . $numar,
            'necesita_rambursare' => 'Comanda ' . $numar . ' a fost închisă, dar plata BT e încasată — rambursează',
        ];
        $explicatii = [
            'plata_comanda_inchisa' => 'Clientul a plătit cu cardul după ce comanda fusese anulată pe site. '
                . 'Dacă banii erau doar blocați, site-ul i-a eliberat automat; dacă erau deja încasați, rambursează din comandă.',
            'plata_in_plus' => 'Pe comandă a venit o plată BT în plus (comanda era deja plătită). Site-ul NU o încasează singur. '
                . 'Verifică și, dacă e cazul, anuleaz-o din comandă („Anulează autorizarea").',
            'eliberata_la_banca' => 'Banca raportează că suma blocată pentru această comandă a fost eliberată (din portalul BT sau la expirarea '
                . 'autorizării), dar comanda e încă activă pe site. Banii NU mai sunt garantați: cere clientului altă plată sau anulează comanda.',
            'sume_diferite' => 'Banca raportează o sumă diferită de cea a comenzii, așa că site-ul NU a confirmat plata.',
            'incasare_esuata' => 'Comanda a fost aprobată, dar încasarea sumei blocate pe card nu a mers. Cronul reîncearcă la 15 minute; '
                . 'o poți face și din comandă, cu „Încasează". Termenul băncii e de 5 zile de la autorizare.',
            'incasare_esuata_repetat' => 'Încasarea a eșuat de cel puțin 3 ori. Verifică în portalul BT și încearcă din comandă („Încasează").',
            'necesita_rambursare' => 'Comanda a fost anulată sau returnată după ce plata fusese încasată. Rambursarea NU se face automat: '
                . 'deschide comanda în admin și apasă „Rambursează" (suma e completată, se poate modifica).',
        ];

        $html = '<p><strong>' . $e($titluri[$tip] ?? ('Plată BT — ' . $numar)) . '</strong></p>'
            . '<p>' . $e($explicatii[$tip] ?? '') . '</p>'
            . '<table cellpadding="6" style="border-collapse:collapse;font-size:14px;">'
            . '<tr><td style="color:#64748b;">Comanda</td><td><strong>' . $e($numar) . '</strong></td></tr>'
            . '<tr><td style="color:#64748b;">Nr. plată la bancă</td><td>' . $e((string) $tx['bt_order_number']) . '</td></tr>'
            . '<tr><td style="color:#64748b;">Stare plată</td><td>' . $e(self::etichetaStare((string) $tx['state'])) . '</td></tr>'
            . '<tr><td style="color:#64748b;">Sumă</td><td>' . $e(self::lei((int) $tx['amount_minor'])) . '</td></tr>'
            . ($detalii !== '' ? '<tr><td style="color:#64748b;">Detalii</td><td>' . $e($detalii) . '</td></tr>' : '')
            . '</table>'
            . '<p><a href="' . $e(AppUrl::absolut('/admin/orders?q=' . rawurlencode($numar))) . '">Deschide comanda în admin</a></p>';
        self::trimiteMagazinului($db, Settings::all($db), '[BT iPay] ' . ($titluri[$tip] ?? 'Plată BT'), $html, 'bt_ipay_' . $tip, (int) ($tx['order_id'] ?? 0));
    }

    /** @param list<array<string, mixed>> $lista */
    private static function emailReamintire(PDO $db, array $settings, array $s, array $lista): void
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES);
        $randuri = '';
        $celMaiDevreme = null;
        foreach ($lista as $tx) {
            $ts = strtotime((string) $tx['authorized_at']) ?: time();
            $termen = $ts + $s['ore_incasare_automata'] * 3600;
            $celMaiDevreme = $celMaiDevreme === null ? $termen : min($celMaiDevreme, $termen);
            $randuri .= '<tr>'
                . '<td>' . $e((string) $tx['order_number']) . (trim((string) ($tx['preorder_status'] ?? '')) !== '' ? ' (precomandă)' : '') . '</td>'
                . '<td>' . $e(trim((string) $tx['billing_first_name'] . ' ' . (string) $tx['billing_last_name'])) . '</td>'
                . '<td style="text-align:right;">' . $e(self::lei(self::netTx($tx))) . '</td>'
                . '<td>' . $e(date('d.m.Y H:i', $ts)) . '</td>'
                . '<td>' . $e(date('d.m.Y H:i', $termen)) . '</td>'
                . '</tr>';
        }
        $html = '<p><strong>' . count($lista) . ' plăți cu cardul (Banca Transilvania) sunt doar autorizate, încă neîncasate.</strong></p>'
            . '<p>Banca cere încasarea în cel mult 5 zile de la autorizare. Dacă nu le încasezi până atunci (aprobând comanda în ERP sau cu '
            . '„Încasează" din comandă), site-ul le încasează singur la termenul din tabel.</p>'
            . '<table cellpadding="6" border="1" style="border-collapse:collapse;font-size:13px;border-color:#e2e8f0;">'
            . '<tr style="background:#f1f5f9;"><th>Comanda</th><th>Client</th><th>Sumă</th><th>Autorizată la</th><th>Încasare automată la</th></tr>'
            . $randuri . '</table>'
            . '<p><a href="' . $e(AppUrl::absolut('/admin/orders')) . '">Deschide comenzile</a></p>';
        $subiect = '[BT iPay] Plăți de încasat până la ' . date('d.m.Y H:i', (int) $celMaiDevreme);
        self::trimiteMagazinului($db, $settings, $subiect, $html, 'bt_ipay_reamintire', 0);
    }

    /** @param list<array{tx: array<string, mixed>, suma: int, rezultat: array<string, mixed>}> $rezultate */
    private static function emailIncasareAutomata(PDO $db, array $settings, array $s, array $rezultate): void
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES);
        $randuri = '';
        $reusite = 0;
        foreach ($rezultate as $r) {
            $ok = (bool) ($r['rezultat']['ok'] ?? false);
            $reusite += $ok ? 1 : 0;
            $randuri .= '<tr>'
                . '<td>' . $e((string) $r['tx']['order_number']) . '</td>'
                . '<td style="text-align:right;">' . $e(self::lei((int) $r['suma'])) . '</td>'
                . '<td style="color:' . ($ok ? '#166534' : '#b91c1c') . ';">' . $e((string) ($r['rezultat']['mesaj'] ?? '')) . '</td>'
                . '</tr>';
        }
        $html = '<p><strong>Încasare automată BT (ziua ' . (int) ceil($s['ore_incasare_automata'] / 24) . '):</strong> '
            . $reusite . ' din ' . count($rezultate) . ' plăți încasate.</p>'
            . '<p>Comenzile de mai jos nu fuseseră aprobate în ERP la timp, așa că site-ul a încasat singur sumele blocate, ca plata să nu se piardă. '
            . 'Dacă una dintre ele se anulează ulterior, rambursează din comandă („Rambursează").</p>'
            . '<table cellpadding="6" border="1" style="border-collapse:collapse;font-size:13px;border-color:#e2e8f0;">'
            . '<tr style="background:#f1f5f9;"><th>Comanda</th><th>Sumă</th><th>Rezultat</th></tr>' . $randuri . '</table>'
            . '<p><a href="' . $e(AppUrl::absolut('/admin/orders')) . '">Deschide comenzile</a></p>';
        self::trimiteMagazinului($db, $settings, '[BT iPay] Încasare automată: ' . $reusite . ' din ' . count($rezultate), $html, 'bt_ipay_incasare_automata', 0);
    }

    private static function trimiteMagazinului(PDO $db, array $settings, string $subiect, string $html, string $tip, int $orderId): void
    {
        foreach (self::setari($settings)['emailuri'] as $destinatar) {
            try {
                OrderMailer::sendCustom($destinatar, $subiect, $html, $settings, $db, [
                    'email_type' => $tip,
                    'source' => 'bt_ipay',
                    'order_id' => $orderId,
                ]);
            } catch (Throwable) {
                // Emailul ratat rămâne în istoricul de emailuri; plata merge mai departe.
            }
        }
    }

    // ------------------------------------------------------------------
    // Mărunțișuri
    // ------------------------------------------------------------------

    public static function lei(int $bani): string
    {
        return number_format($bani / 100, 2, ',', '.') . ' lei';
    }

    private static function bani(int $bani): float
    {
        return round($bani / 100, 2);
    }

    /** „12,34" sau „12.34" (lei) → 1234 (bani); null dacă nu e o sumă. */
    public static function leiInBani(string $text): ?int
    {
        $text = str_replace([' ', "\u{00A0}"], '', trim($text));
        if ($text === '') {
            return null;
        }
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = str_replace('.', '', $text);
        }
        $text = str_replace(',', '.', $text);
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $text) !== 1) {
            return null;
        }
        return (int) round((float) $text * 100);
    }
}
