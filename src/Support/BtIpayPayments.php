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
 * Diferențele de plată (linkurile `{comanda}-P{n}`, vezi PaymentLink) pot merge
 * și ele prin BT: fiecare e o comandă separată la bancă, tot în două faze, dar
 * încasată imediat după autorizare (nu există o aprobare ERP pentru ea).
 *
 * Plata cu puncte STAR („LOY") vine de la bancă împărțită în două comenzi BT:
 * partea pe card și partea în puncte. Starea comenzii de card decide (ca în
 * modulul BT), iar o plată cu puncte e întreagă abia când AMBELE părți sunt
 * autorizate. Orice operație atinge întâi partea LOY, apoi pe cea de card
 * (CaptureTransaction / CancelTransaction / RefundTransaction din modulul BT).
 * Rambursarea întoarce întâi punctele.
 *
 * Nicio decizie nu se ia pe baza mesajelor primite (callback, întoarcerea din
 * pagina băncii): starea se citește mereu din nou de la bancă
 * (getOrderStatusExtended), sub un lacăt MySQL pe tranzacție, ca returul,
 * callback-ul, cronul și adminul să nu se calce pe picioare.
 *
 * Operațiile cu banii pornite AUTOMAT dintr-o verificare (eliberarea unei sume
 * venite pentru o comandă anulată, încasarea imediată a unei diferențe) se fac
 * doar din verificarea „de sus", niciodată dintr-una făcută în interiorul altei
 * operații: o verificare de control nu mai pornește nimic. Peste asta, o gardă
 * pe adâncime și plafonul de apeluri din BtIpayGateway opresc orice buclă.
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
    public const TIP_LINK = 'link';
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

    /**
     * Comanda anulată de om (sau ștearsă): o plată venită acum pentru ea nu se
     * socotește. „failed" lipsește intenționat: o comandă expirată din lipsa
     * plății se reactivează dacă plata vine totuși.
     */
    private const COMENZI_ANULATE = ['cancelled', 'refunded', 'returned'];

    /** Cât poate sta o plată de test autorizată până o eliberează cronul (minute). */
    private const MINUTE_TEST_AUTORIZAT = 30;

    /** Cât timp păstrăm jurnalul apelurilor către bancă (zile). */
    private const ZILE_JURNAL = 180;

    /** Versiunea tabelelor BT; se schimbă la fiecare coloană nouă. */
    private const VERSIUNE_SCHEMA = '3';
    private const CHEIE_VERSIUNE = 'bt_ipay_schema_version';

    /** Adresa publică de întoarcere: cel mult o întrebare la bancă la 30 de secunde, pe plată. */
    private const INTERVAL_VERIFICARE_PUBLICA = 30;
    /** Callback-ul (semnat de bancă): cel mult o întrebare la 2 secunde, pe plată. */
    private const INTERVAL_VERIFICARE_CALLBACK = 2;
    /** O plată rămasă în eroare sau expirată nu se mai verifică de pe adresele publice după atât (secunde). */
    private const VECHIME_MAXIMA_VERIFICARE_PUBLICA = 7200;
    /** Cât așteaptă adresele publice lacătul unei plăți (secunde). */
    private const ASTEPTARE_PUBLICA = 2;
    /** Câte verificări înlănțuite are voie o cerere (verificarea de sus + una de control). */
    private const ADANCIME_MAXIMA = 2;
    /** Plafonul de apeluri către bancă pentru o rulare de cron. */
    private const LIMITA_APELURI_CRON = 600;

    /** Cheia din sesiune cu plățile pornite din acest browser (pentru adresa de întoarcere). */
    private const CHEIE_SESIUNE = 'bt_ipay_plati';
    private const MAX_PLATI_IN_SESIUNE = 10;

    /**
     * Plata unui link pornită de curând și încă neplătită se refolosește (a doua
     * filă, dublu-click, „înapoi" din pagina băncii): clientul e trimis pe aceeași
     * pagină de plată. Pagina băncii trăiește 20 de minute; refolosim doar în primele 10.
     */
    private const MINUTE_REFOLOSIRE_LINK = 10;
    /** Câte plăți NOI se pot înregistra la bancă pe același link într-o oră. */
    private const MAX_PORNIRI_LINK_PE_ORA = 5;

    /**
     * Ce poate face magazinul cu o comandă BT activă rămasă neplătită (suma
     * eliberată): uneltele care există azi pentru o comandă neplătită.
     */
    public const CUM_SE_REZOLVA = 'Anulează comanda sau, după ce clientul plătește pe alt drum (link EuPlătesc trimis separat, OP), '
        . 'marcheaz-o din „Acțiuni comandă" → „Plătit prin link extern de plată". Dacă pe comandă era deja încasată o parte '
        . '(o diferență plătită: comanda apare „Plătit parțial"), restul se cere din fereastra comenzii cu „Trimite link de plată '
        . 'pentru diferență" sau, dacă banii au venit altfel, se consemnează cu „Înregistrează încasarea".';

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

    /** Câte verificări sunt în lucru acum, una în alta (garda anti-buclă). */
    private static int $adancime = 0;

    /** Plățile pe care rulează acum o eliberare / încasare automată (garda de reintrare). @var array<int, true> */
    private static array $efecteInCurs = [];

    // ------------------------------------------------------------------
    // Schemă și setări
    // ------------------------------------------------------------------

    /**
     * Tabelele BT (și coloanele din `orders` pe care le scrie plata). Se
     * creează o singură dată: versiunea rămâne în `settings`, așa că returul,
     * callback-ul și cronul nu trimit DDL la fiecare cerere.
     */
    public static function ensureSchema(PDO $db): void
    {
        static $gata = false;
        if ($gata) {
            return;
        }
        $gata = true;
        if (self::versiuneSchema($db) === self::VERSIUNE_SCHEMA) {
            return;
        }
        self::migreaza($db);
    }

    private static function versiuneSchema(PDO $db): string
    {
        try {
            $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
            $stmt->execute(['k' => self::CHEIE_VERSIUNE]);
            return (string) ($stmt->fetchColumn() ?: '');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * S-a lucrat vreodată cu BT pe site (există tabelele)? Fără DDL dacă nu:
     * lista de comenzi nu creează tabele pe un site care n-a pornit BT.
     */
    public static function areTabele(PDO $db): bool
    {
        if (self::versiuneSchema($db) === '') {
            try {
                $exista = $db->query("SHOW TABLES LIKE 'bt_ipay_transactions'")->fetchColumn() !== false;
            } catch (Throwable) {
                return false;
            }
            if (!$exista) {
                return false;
            }
        }
        self::ensureSchema($db);
        return true;
    }

    private static function migreaza(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS bt_ipay_transactions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id INT UNSIGNED DEFAULT NULL,
                    link_id INT UNSIGNED DEFAULT NULL,
                    kind VARCHAR(10) NOT NULL DEFAULT "order",
                    mode VARCHAR(4) NOT NULL DEFAULT "test",
                    bt_order_number VARCHAR(40) NOT NULL,
                    bt_order_id VARCHAR(64) DEFAULT NULL,
                    form_url VARCHAR(1000) DEFAULT NULL,
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
                    deposit_requested_minor INT UNSIGNED DEFAULT NULL,
                    deposit_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                    reminder_sent_at DATETIME DEFAULT NULL,
                    authorized_at DATETIME DEFAULT NULL,
                    deposited_at DATETIME DEFAULT NULL,
                    reversed_at DATETIME DEFAULT NULL,
                    refunded_at DATETIME DEFAULT NULL,
                    last_checked_at DATETIME DEFAULT NULL,
                    public_checked_at DATETIME DEFAULT NULL,
                    callback_checked_at DATETIME DEFAULT NULL,
                    last_status_json MEDIUMTEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_bt_ipay_tx_number (bt_order_number),
                    UNIQUE KEY uq_bt_ipay_tx_bt_id (bt_order_id),
                    KEY idx_bt_ipay_tx_order (order_id),
                    KEY idx_bt_ipay_tx_link (link_id),
                    KEY idx_bt_ipay_tx_state (state, created_at)
                ) DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable) {
            // Tabelul există deja.
        }

        // Coloanele adăugate după prima versiune a tabelului.
        foreach ([
            'link_id' => 'INT UNSIGNED DEFAULT NULL AFTER order_id',
            'form_url' => 'VARCHAR(1000) DEFAULT NULL AFTER bt_order_id',
            'deposit_requested_minor' => 'INT UNSIGNED DEFAULT NULL AFTER deposit_requested_at',
            'public_checked_at' => 'DATETIME DEFAULT NULL AFTER last_checked_at',
            'callback_checked_at' => 'DATETIME DEFAULT NULL AFTER public_checked_at',
        ] as $coloana => $definitie) {
            try {
                $db->exec('ALTER TABLE bt_ipay_transactions ADD COLUMN ' . $coloana . ' ' . $definitie);
            } catch (Throwable) {
                // Coloana există deja.
            }
        }
        try {
            $db->exec('ALTER TABLE bt_ipay_transactions ADD KEY idx_bt_ipay_tx_link (link_id)');
        } catch (Throwable) {
            // Indexul există deja.
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

        // Coloanele din `orders` scrise de confirmarea plății, aceleași ca la
        // EuPlătesc (pe un site vechi le adaugă checkout-ul; aici, o dată).
        foreach (['paid_at' => 'DATETIME DEFAULT NULL', 'payment_error' => 'TEXT DEFAULT NULL'] as $coloana => $definitie) {
            try {
                $db->exec('ALTER TABLE orders ADD COLUMN ' . $coloana . ' ' . $definitie);
            } catch (Throwable) {
                // Coloana există deja.
            }
        }
        ErpSync::ensureSchema($db);
        PaymentLink::ensureSchema($db);

        try {
            $db->query('SELECT link_id, form_url, deposit_requested_minor, public_checked_at, callback_checked_at FROM bt_ipay_transactions LIMIT 0');
            $db->query('SELECT id FROM bt_ipay_log LIMIT 0');
            $db->query('SELECT paid_at, payment_error, paid_amount FROM orders LIMIT 0');
            Settings::save($db, [self::CHEIE_VERSIUNE => self::VERSIUNE_SCHEMA]);
        } catch (Throwable) {
            // Ceva lipsește încă: data viitoare încercăm din nou.
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
     * Apare BT în checkout? Doar în modul PRODUCȚIE, pornit din admin și cu
     * datele de acces. În modul test (sandbox) nu apare deloc: testele se fac
     * doar cu „Plată de test 1 leu", ca nicio comandă reală să nu fie „plătită"
     * pe platforma de test. Bifa „Doar pentru administratori" îl arată doar
     * administratorilor GENERALI logați (pentru o comandă reală de probă).
     */
    public static function vizibilInCheckout(array $settings, bool $adminGeneralLogat): bool
    {
        $s = self::setari($settings);
        if (!$s['activ']) {
            return false;
        }
        $mod = BtIpayGateway::mod();
        if ($mod !== BtIpayGateway::MOD_LIVE || !BtIpayGateway::esteConfigurat($mod)) {
            return false;
        }
        if ($s['doar_admin'] && !$adminGeneralLogat) {
            return false;
        }
        return true;
    }

    /**
     * Linkurile NOI pentru diferență merg prin BT? Când BT e pornit în
     * producție, are datele de acces și e deschis clienților (cu „Doar pentru
     * administratori" bifat, linkurile rămân pe EuPlătesc: ajung la clienți).
     */
    public static function poatePlatiLinkuri(array $settings): bool
    {
        $s = self::setari($settings);
        return $s['activ']
            && !$s['doar_admin']
            && BtIpayGateway::mod() === BtIpayGateway::MOD_LIVE
            && BtIpayGateway::esteConfigurat(BtIpayGateway::MOD_LIVE);
    }

    public static function etichetaStare(string $stare): string
    {
        return self::ETICHETE[$stare] ?? $stare;
    }

    // ------------------------------------------------------------------
    // Sesiunea clientului (adresa de întoarcere e publică)
    // ------------------------------------------------------------------

    /** Ține minte că plata cu numărul dat a pornit din sesiunea (browserul) curentă. */
    public static function memoreazaInSesiune(string $numar): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || $numar === '') {
            return;
        }
        $lista = $_SESSION[self::CHEIE_SESIUNE] ?? [];
        if (!is_array($lista)) {
            $lista = [];
        }
        $lista[$numar] = time();
        // Doar ultimele câteva, și nu mai vechi de două zile.
        $lista = array_filter($lista, static fn ($la): bool => is_int($la) && $la >= time() - 2 * 86400);
        arsort($lista);
        $_SESSION[self::CHEIE_SESIUNE] = array_slice($lista, 0, self::MAX_PLATI_IN_SESIUNE, true);
    }

    /** Plata cu numărul dat a pornit din sesiunea curentă? */
    public static function sesiuneaDetine(string $numar): bool
    {
        $lista = $_SESSION[self::CHEIE_SESIUNE] ?? null;
        return $numar !== '' && is_array($lista) && isset($lista[$numar]);
    }

    // ------------------------------------------------------------------
    // Pornirea plății
    // ------------------------------------------------------------------

    /**
     * Înregistrează plata la bancă (registerPreAuth.do) pentru o comandă abia
     * salvată și întoarce adresa paginii de plată. Fiecare încercare are propriul
     * număr la bancă: prima e numărul comenzii, următoarele „{nr}-R2", „{nr}-R3".
     *
     * @return array{ok: bool, url: string, eroare: string, tx_id: int, numar: string}
     */
    public static function pornestePlata(PDO $db, int $orderId, string $returnUrlBaza, string $userAgent = ''): array
    {
        self::ensureSchema($db);
        if (BtIpayGateway::mod() !== BtIpayGateway::MOD_LIVE) {
            // Comenzile reale nu se plătesc niciodată pe platforma de test.
            return ['ok' => false, 'url' => '', 'eroare' => 'Banca Transilvania e în modul test: plățile pentru comenzi reale nu se pornesc.', 'tx_id' => 0, 'numar' => ''];
        }
        $comanda = self::comandaPentruPlata($db, $orderId);
        if ($comanda === null) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Comanda nu a fost găsită.', 'tx_id' => 0, 'numar' => ''];
        }

        $suma = (int) round((float) ($comanda['total'] ?? 0) * 100);
        if ($suma <= 0) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Valoarea comenzii este invalidă.', 'tx_id' => 0, 'numar' => ''];
        }
        $numarComanda = (string) $comanda['order_number'];
        $date = self::dateClient($comanda) + [
            'suma' => $suma,
            'descriere' => 'Comanda ' . $numarComanda . ' bioscem.ro',
            'user_agent' => $userAgent,
        ];

        return self::inregistreaza($db, self::TIP_COMANDA, $orderId, $numarComanda, $date, $returnUrlBaza);
    }

    /**
     * Plata unei diferențe printr-un link (vezi PaymentLink) cu BT: o comandă
     * separată la bancă, cu numărul linkului („{comanda}-P{n}", la reîncercări
     * „-R2"…), pentru suma linkului. După autorizare se încasează imediat.
     *
     * Adresa /plata/{token} e la îndemâna oricui are linkul, deci nu orice
     * „Plătește" înregistrează o plată nouă la bancă: una pornită în ultimele
     * 10 minute, încă neplătită, se refolosește (aceeași pagină a băncii), iar
     * plăți noi se pot înregistra cel mult 5 pe oră pe link.
     *
     * @param array<string, mixed> $link
     * @return array{ok: bool, url: string, eroare: string, tx_id: int, numar: string}
     */
    public static function pornestePlataLink(PDO $db, array $link, string $returnUrlBaza, string $userAgent = ''): array
    {
        self::ensureSchema($db);
        $linkId = (int) ($link['id'] ?? 0);
        $orderId = (int) ($link['order_id'] ?? 0);
        if (BtIpayGateway::mod() !== BtIpayGateway::MOD_LIVE) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Plata prin Banca Transilvania nu este disponibilă acum.', 'tx_id' => 0, 'numar' => ''];
        }
        if ($linkId <= 0 || (string) ($link['status'] ?? '') !== PaymentLink::STATUS_ASTEPTARE) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Linkul de plată nu mai este valabil.', 'tx_id' => 0, 'numar' => ''];
        }
        $comanda = self::comandaPentruPlata($db, $orderId);
        if ($comanda === null) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Comanda asociată nu mai există.', 'tx_id' => 0, 'numar' => ''];
        }
        $suma = (int) round((float) ($link['amount'] ?? 0) * 100);
        if ($suma <= 0) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Suma linkului este invalidă.', 'tx_id' => 0, 'numar' => ''];
        }

        // Cererile venite deodată pe același link (dublu-click, două file) se
        // așază la rând: altfel ar trece toate de refolosire și de plafon.
        $lacat = 'link-start:' . $linkId;
        if (!self::iaLacat($db, $lacat, 5)) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Plata acestui link se pornește chiar acum; reîncearcă în câteva secunde.', 'tx_id' => 0, 'numar' => ''];
        }
        try {
            $recenta = self::plataLinkDeRefolosit($db, $linkId, $suma);
            if ($recenta !== null) {
                return ['ok' => true, 'url' => (string) $recenta['form_url'], 'eroare' => '', 'tx_id' => (int) $recenta['id'], 'numar' => (string) $recenta['bt_order_number']];
            }
            // Verificarea de mai sus (sau altă cerere) poate să fi găsit linkul plătit între timp.
            $linkAcum = PaymentLink::dupaId($db, $linkId);
            if ($linkAcum === null || (string) ($linkAcum['status'] ?? '') !== PaymentLink::STATUS_ASTEPTARE) {
                return ['ok' => false, 'url' => '', 'eroare' => $linkAcum !== null && (string) ($linkAcum['status'] ?? '') === PaymentLink::STATUS_PLATIT
                    ? 'Plata acestui link a fost deja făcută; nu e nevoie să plătești din nou.'
                    : 'Linkul de plată nu mai este valabil.', 'tx_id' => 0, 'numar' => ''];
            }
            if (self::plataLinkInCurs($db, $linkId) !== null) {
                return ['ok' => false, 'url' => '', 'eroare' => 'Plata acestui link a fost deja autorizată și se încasează acum; nu e nevoie să plătești din nou.', 'tx_id' => 0, 'numar' => ''];
            }
            if (self::porniriLinkInUltimaOra($db, $linkId) >= self::MAX_PORNIRI_LINK_PE_ORA) {
                return ['ok' => false, 'url' => '', 'eroare' => 'Plata acestui link a fost pornită de prea multe ori în ultima oră. Din motive de siguranță, '
                    . 'poți încerca din nou puțin mai târziu; dacă te grăbești, scrie-ne și te ajutăm imediat.', 'tx_id' => 0, 'numar' => ''];
            }
            $date = self::dateClient($comanda) + [
                'suma' => $suma,
                'descriere' => 'Diferenta comanda ' . (string) $comanda['order_number'] . ' bioscem.ro',
                'user_agent' => $userAgent,
            ];

            return self::inregistreaza($db, self::TIP_LINK, $orderId, (string) ($link['referinta'] ?? ''), $date, $returnUrlBaza, $linkId);
        } finally {
            self::elibereazaLacat($db, $lacat);
        }
    }

    /**
     * Plata linkului pornită în ultimele 10 minute, pentru aceeași sumă și încă
     * neplătită (pagina ei de la bancă e încă valabilă). Dacă n-a mai fost
     * verificată de 30 de secunde, starea se citește întâi de la bancă: una
     * plătită sau refuzată între timp, fără întoarcere pe site, nu se refolosește.
     *
     * @return array<string, mixed>|null
     */
    private static function plataLinkDeRefolosit(PDO $db, int $linkId, int $suma): ?array
    {
        $stmt = $db->prepare(
            'SELECT id FROM bt_ipay_transactions
             WHERE kind = :k AND link_id = :l AND mode = :mod AND amount_minor = :suma AND state = :stare
               AND bt_order_id IS NOT NULL AND form_url IS NOT NULL AND form_url <> "" AND created_at >= :dupa
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            'k' => self::TIP_LINK,
            'l' => $linkId,
            'mod' => BtIpayGateway::MOD_LIVE,
            'suma' => $suma,
            'stare' => self::STARE_INREGISTRATA,
            'dupa' => date('Y-m-d H:i:s', time() - self::MINUTE_REFOLOSIRE_LINK * 60),
        ]);
        $tx = self::tx($db, (int) ($stmt->fetchColumn() ?: 0));
        if ($tx === null) {
            return null;
        }
        $reper = max(strtotime((string) $tx['created_at']) ?: 0, strtotime((string) ($tx['last_checked_at'] ?? '')) ?: 0);
        if ($reper < time() - self::INTERVAL_VERIFICARE_PUBLICA) {
            self::sincronizeaza($db, (int) $tx['id'], 'link', false, self::ASTEPTARE_PUBLICA);
            $tx = self::tx($db, (int) $tx['id']);
        }
        if ($tx === null || (string) $tx['state'] !== self::STARE_INREGISTRATA
            || !BtIpayGateway::urlPlataValid((string) ($tx['form_url'] ?? ''), BtIpayGateway::MOD_LIVE)) {
            return null;
        }
        return $tx;
    }

    /** Câte plăți s-au înregistrat pe link în ultima oră (plafonul încercărilor noi). */
    private static function porniriLinkInUltimaOra(PDO $db, int $linkId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM bt_ipay_transactions WHERE kind = :k AND link_id = :l AND created_at >= :dupa');
        $stmt->execute(['k' => self::TIP_LINK, 'l' => $linkId, 'dupa' => date('Y-m-d H:i:s', time() - 3600)]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * O plată BT a linkului deja autorizată sau încasată, dar încă neconfirmată
     * pe comandă: nu pornim alta (clientul ar plăti de două ori).
     *
     * @return array<string, mixed>|null
     */
    public static function plataLinkInCurs(PDO $db, int $linkId): ?array
    {
        self::ensureSchema($db);
        if ($linkId <= 0) {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT * FROM bt_ipay_transactions
             WHERE kind = :k AND link_id = :l AND in_comanda = 0 AND state IN ("authorized", "deposited")
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['k' => self::TIP_LINK, 'l' => $linkId]);
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    /**
     * Plata de test din admin: 1 leu, legată de nicio comandă. Nu atinge
     * comenzi, emailuri sau ERP-ul; cronul o eliberează singur după 30 de minute.
     *
     * @return array{ok: bool, url: string, eroare: string, tx_id: int, numar: string}
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

    /** @return array<string, mixed>|null */
    private static function comandaPentruPlata(PDO $db, int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT id, order_number, total, billing_first_name, billing_last_name, billing_email, billing_phone,
                    billing_address_line1, billing_address_line2, billing_city,
                    shipping_same_as_billing, shipping_address_line1, shipping_address_line2, shipping_city,
                    fan_locker_id, fan_locker_address, fan_locker_city
             FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $comanda = $stmt->fetch();
        return is_array($comanda) ? $comanda : null;
    }

    /**
     * Datele clientului pentru pagina băncii (orderBundle).
     *
     * @param array<string, mixed> $comanda
     * @return array<string, string>
     */
    private static function dateClient(array $comanda): array
    {
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

        return [
            'email' => (string) ($comanda['billing_email'] ?? ''),
            'telefon' => (string) ($comanda['billing_phone'] ?? ''),
            'nume' => trim((string) ($comanda['billing_first_name'] ?? '') . ' ' . (string) ($comanda['billing_last_name'] ?? '')),
            'oras_facturare' => (string) ($comanda['billing_city'] ?? ''),
            'adresa_facturare' => $adresaFacturare,
            'oras_livrare' => $orasLivrare,
            'adresa_livrare' => $adresaLivrare,
        ];
    }

    /**
     * @param array<string, mixed> $date
     * @return array{ok: bool, url: string, eroare: string, tx_id: int, numar: string}
     */
    private static function inregistreaza(PDO $db, string $tip, int $orderId, string $numarBaza, array $date, string $returnUrlBaza, int $linkId = 0): array
    {
        $mod = BtIpayGateway::mod();
        if (!BtIpayGateway::esteConfigurat($mod)) {
            return ['ok' => false, 'url' => '', 'eroare' => 'Plata prin Banca Transilvania nu este configurată.', 'tx_id' => 0, 'numar' => ''];
        }

        $ultimaEroare = 'Banca nu a putut porni plata.';
        $txId = 0;
        for ($incercare = 1; $incercare <= 5; $incercare++) {
            $numar = self::numarNou($db, $tip, $orderId, $numarBaza, $linkId);
            if ($numar === '') {
                break;
            }
            $acum = date('Y-m-d H:i:s');
            try {
                $db->prepare(
                    'INSERT INTO bt_ipay_transactions
                        (order_id, link_id, kind, mode, bt_order_number, amount_minor, currency, state, created_at, updated_at)
                     VALUES (:order_id, :link_id, :kind, :mode, :numar, :suma, :moneda, :stare, :acum, :acum2)'
                )->execute([
                    'order_id' => $orderId > 0 ? $orderId : null,
                    'link_id' => $linkId > 0 ? $linkId : null,
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
                'sursa' => $tip === self::TIP_TEST ? 'test' : ($tip === self::TIP_LINK ? 'link' : 'checkout'),
            ]);

            $btId = trim((string) ($rez['date']['orderId'] ?? ''));
            $url = trim((string) ($rez['date']['formUrl'] ?? ''));
            if ($rez['ok'] && $btId !== '' && $url !== '' && preg_match('/^[A-Za-z0-9\-]{1,64}$/', $btId) === 1) {
                if (!BtIpayGateway::urlPlataValid($url, $mod)) {
                    $ultimaEroare = 'Banca a întors o adresă de plată neașteptată.';
                    self::scrieEroare($db, $txId, $ultimaEroare, self::STARE_EROARE);
                    break;
                }
                // Adresa paginii de plată rămâne pe plată: un al doilea „Plătește"
                // pe același link duce tot acolo (vezi pornestePlataLink).
                $db->prepare('UPDATE bt_ipay_transactions SET bt_order_id = :bt, form_url = :url, updated_at = :acum WHERE id = :id')
                    ->execute(['bt' => $btId, 'url' => strlen($url) <= 1000 ? $url : null, 'acum' => date('Y-m-d H:i:s'), 'id' => $txId]);
                return ['ok' => true, 'url' => $url, 'eroare' => '', 'tx_id' => $txId, 'numar' => $numar];
            }

            $ultimaEroare = $rez['eroare'] !== '' ? $rez['eroare'] : 'Banca nu a întors pagina de plată.';
            self::scrieEroare($db, $txId, $ultimaEroare, self::STARE_EROARE);
            // Cod 1: numărul a mai fost folosit la bancă (bază resetată, test
            // anterior). Mergem pe următorul sufix; orice altă eroare e definitivă.
            if ((int) $rez['cod'] !== 1) {
                break;
            }
        }

        return ['ok' => false, 'url' => '', 'eroare' => $ultimaEroare, 'tx_id' => $txId, 'numar' => ''];
    }

    /** Următorul număr liber la bancă pentru comanda (sau linkul) dat (max 32 de caractere). */
    private static function numarNou(PDO $db, string $tip, int $orderId, string $numarBaza, int $linkId = 0): string
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
        } elseif ($tip === self::TIP_LINK) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM bt_ipay_transactions WHERE link_id = :l AND kind = :k');
            $stmt->execute(['l' => $linkId, 'k' => self::TIP_LINK]);
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

    /** Toate plățile BT ale comenzii (nu și ale linkurilor), cea mai nouă prima. @return list<array<string, mixed>> */
    public static function tranzactiiComanda(PDO $db, int $orderId): array
    {
        self::ensureSchema($db);
        $stmt = $db->prepare('SELECT * FROM bt_ipay_transactions WHERE order_id = :o AND kind = :k ORDER BY id DESC');
        $stmt->execute(['o' => $orderId, 'k' => self::TIP_COMANDA]);
        return array_values(array_filter($stmt->fetchAll() ?: [], 'is_array'));
    }

    /** Plățile BT ale linkurilor de diferență ale comenzii, cea mai nouă prima. @return list<array<string, mixed>> */
    public static function tranzactiiLinkuri(PDO $db, int $orderId): array
    {
        self::ensureSchema($db);
        $stmt = $db->prepare('SELECT * FROM bt_ipay_transactions WHERE order_id = :o AND kind = :k ORDER BY id DESC');
        $stmt->execute(['o' => $orderId, 'k' => self::TIP_LINK]);
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
        $stmt = $db->prepare(
            'SELECT id, order_number, status, payment_status, payment_method, paid_amount, total, deleted_at,
                    billing_first_name, billing_last_name, billing_email
             FROM orders WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    /** Comanda e anulată / rambursată / returnată, în coș sau ștearsă? */
    private static function comandaAnulata(?array $comanda): bool
    {
        return $comanda === null
            || ($comanda['deleted_at'] ?? null) !== null
            || in_array((string) ($comanda['status'] ?? ''), self::COMENZI_ANULATE, true);
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
     * Pentru admin, cron și plățile de test; adresele publice au variantele lor
     * (verificaDinRetur, verificaDinCallback), cu așteptare scurtă și pauze.
     *
     * @return array{ok: bool, mesaj: string, stare?: string, ocupat?: bool}
     */
    public static function sincronizeaza(PDO $db, int $txId, string $sursa, bool $forteazaExpirare = false, int $asteptare = 20): array
    {
        self::ensureSchema($db);
        return self::cuLacat($db, $txId, $asteptare, static function () use ($db, $txId, $sursa, $forteazaExpirare): array {
            $tx = self::tx($db, $txId);
            if ($tx === null) {
                return ['ok' => false, 'mesaj' => 'Plata nu există.'];
            }
            return self::sincronizeazaFaraLacat($db, $tx, $sursa, $forteazaExpirare, false);
        });
    }

    /**
     * Întoarcerea clientului pe site (adresa de retur, legată de sesiunea lui).
     * Banca e întrebată doar cât plata se mai poate schimba din partea
     * clientului, cel mult o dată la 30 de secunde pe plată, iar lacătul se
     * așteaptă cel mult 2 secunde (altfel pagina spune „verificăm plata").
     *
     * @return array{ok: bool, mesaj: string, stare?: string, ocupat?: bool, verificat?: bool, amanat?: bool}
     */
    public static function verificaDinRetur(PDO $db, int $txId): array
    {
        self::ensureSchema($db);
        return self::cuLacat($db, $txId, self::ASTEPTARE_PUBLICA, static function () use ($db, $txId): array {
            $tx = self::tx($db, $txId);
            if ($tx === null) {
                return ['ok' => false, 'mesaj' => 'Plata nu există.'];
            }
            if (!self::deVerificatPublic($tx)) {
                return ['ok' => true, 'mesaj' => '', 'stare' => (string) $tx['state'], 'verificat' => false];
            }
            $ultima = strtotime((string) ($tx['public_checked_at'] ?? '')) ?: 0;
            if ($ultima > time() - self::INTERVAL_VERIFICARE_PUBLICA) {
                return ['ok' => true, 'mesaj' => '', 'stare' => (string) $tx['state'], 'verificat' => false, 'amanat' => true];
            }
            $db->prepare('UPDATE bt_ipay_transactions SET public_checked_at = :acum WHERE id = :id')
                ->execute(['acum' => date('Y-m-d H:i:s'), 'id' => (int) $tx['id']]);
            $rez = self::sincronizeazaFaraLacat($db, $tx, 'retur', false, false);
            $rez['verificat'] = true;
            return $rez;
        });
    }

    /**
     * Notificarea băncii (JWT verificat). Lacăt așteptat cel mult 2 secunde;
     * cel mult o întrebare la bancă la 2 secunde pe plată (un token valid
     * retrimis în buclă nu se transformă în apeluri la bancă).
     *
     * @return array{ok: bool, mesaj: string, stare?: string, ocupat?: bool, verificat?: bool, amanat?: bool}
     */
    public static function verificaDinCallback(PDO $db, int $txId): array
    {
        self::ensureSchema($db);
        return self::cuLacat($db, $txId, self::ASTEPTARE_PUBLICA, static function () use ($db, $txId): array {
            $tx = self::tx($db, $txId);
            if ($tx === null) {
                return ['ok' => false, 'mesaj' => 'Plata nu există.'];
            }
            $stare = (string) $tx['state'];
            $creata = strtotime((string) ($tx['created_at'] ?? '')) ?: 0;
            if ($stare === self::STARE_EROARE && $creata < time() - self::VECHIME_MAXIMA_VERIFICARE_PUBLICA) {
                return ['ok' => true, 'mesaj' => 'Plata n-a ajuns la bancă; nimic de verificat.', 'stare' => $stare, 'verificat' => false];
            }
            $ultima = strtotime((string) ($tx['callback_checked_at'] ?? '')) ?: 0;
            if ($ultima > time() - self::INTERVAL_VERIFICARE_CALLBACK) {
                return ['ok' => true, 'mesaj' => 'Verificată chiar acum.', 'stare' => $stare, 'verificat' => false, 'amanat' => true];
            }
            $db->prepare('UPDATE bt_ipay_transactions SET callback_checked_at = :acum WHERE id = :id')
                ->execute(['acum' => date('Y-m-d H:i:s'), 'id' => (int) $tx['id']]);
            $rez = self::sincronizeazaFaraLacat($db, $tx, 'callback', false, false);
            $rez['verificat'] = true;
            return $rez;
        });
    }

    /**
     * Mai are rost să întrebe banca o adresă publică? Doar cât plata se mai
     * poate schimba din partea clientului: înregistrată sau în 3-D Secure; în
     * eroare sau expirată, doar în primele 2 ore. Restul stărilor au fost
     * citite tot de la bancă și se schimbă doar prin operații ale magazinului.
     */
    private static function deVerificatPublic(array $tx): bool
    {
        $stare = (string) $tx['state'];
        if (in_array($stare, [self::STARE_INREGISTRATA, self::STARE_3DS], true)) {
            return true;
        }
        if (in_array($stare, [self::STARE_EROARE, self::STARE_EXPIRATA], true)) {
            $creata = strtotime((string) ($tx['created_at'] ?? '')) ?: 0;
            return $creata >= time() - self::VECHIME_MAXIMA_VERIFICARE_PUBLICA;
        }
        return false;
    }

    /**
     * @param bool $imbricata verificare de control din interiorul unei operații:
     *                        nu pornește nicio operație cu banii (eliberare, încasare)
     * @param bool $eliberareProprie verificarea de după o eliberare făcută chiar acum de site
     * @return array{ok: bool, mesaj: string, stare?: string}
     */
    private static function sincronizeazaFaraLacat(PDO $db, array $tx, string $sursa, bool $forteazaExpirare, bool $imbricata, bool $eliberareProprie = false): array
    {
        if (self::$adancime >= self::ADANCIME_MAXIMA) {
            // Nu are cum să se întâmple pe drumurile de azi; dacă totuși se
            // întâmplă, ne oprim aici în loc să bombardăm banca.
            BtIpayGateway::jurnal($db, BtIpayGateway::modValid((string) $tx['mode']), 'protectie', ['adancime' => self::$adancime], 0, null, '', 0,
                'Verificare oprită: prea multe verificări înlănțuite pe aceeași cerere.',
                ['tx_id' => (int) $tx['id'], 'order_id' => (int) ($tx['order_id'] ?? 0), 'sursa' => $sursa]);
            return ['ok' => false, 'mesaj' => 'Verificare oprită (protecție anti-buclă).'];
        }
        self::$adancime++;
        try {
            return self::citesteSiAplica($db, $tx, $sursa, $forteazaExpirare, $imbricata, $eliberareProprie);
        } finally {
            self::$adancime--;
        }
    }

    /** @return array{ok: bool, mesaj: string, stare?: string} */
    private static function citesteSiAplica(PDO $db, array $tx, string $sursa, bool $forteazaExpirare, bool $imbricata, bool $eliberareProprie): array
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
                $acum = date('Y-m-d H:i:s');
                $db->prepare('UPDATE bt_ipay_transactions SET last_checked_at = :acum, updated_at = :acum2 WHERE id = :id')
                    ->execute(['acum' => $acum, 'acum2' => $acum, 'id' => (int) $tx['id']]);
                if ($forteazaExpirare && in_array((string) $tx['state'], [self::STARE_INREGISTRATA, self::STARE_3DS, self::STARE_EROARE], true)) {
                    return self::aplicaStare($db, $tx, ['orderStatus' => BtIpayGateway::STATUS_INREGISTRATA, 'amount' => 0, 'currency' => BtIpayGateway::MONEDA_RON], null, '', $sursa, true, $imbricata, $eliberareProprie);
                }
                return ['ok' => true, 'mesaj' => 'Plata nu a ajuns să fie înregistrată la bancă.', 'stare' => (string) $tx['state']];
            }
        }

        $rez = BtIpayGateway::apel($mod, 'getOrderStatusExtended', ['orderId' => $btId], $db, $context);
        if (!$rez['ok'] || !isset($rez['date']['orderStatus'])) {
            $mesaj = $rez['eroare'] !== '' ? $rez['eroare'] : 'Banca nu a întors starea plății.';
            $acum = date('Y-m-d H:i:s');
            $db->prepare('UPDATE bt_ipay_transactions SET last_checked_at = :acum, updated_at = :acum2 WHERE id = :id')
                ->execute(['acum' => $acum, 'acum2' => $acum, 'id' => (int) $tx['id']]);
            return ['ok' => false, 'mesaj' => $mesaj];
        }
        $stare = $rez['date'];

        // Ca în modulul BT: id-ul comenzii LOY vine din attributes.loyalties,
        // la fiecare citire (cel salvat rămâne doar ca rezervă).
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

        return self::aplicaStare($db, $tx, $stare, $stareLoy, $loyId, $sursa, $forteazaExpirare, $imbricata, $eliberareProprie);
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
     * Starea plății din ce a răspuns banca. Ca în modulul BT, starea comenzii
     * de CARD decide; cu o parte în puncte STAR, plata e întreagă abia când și
     * partea în puncte e autorizată (sau încasată).
     *
     * @return array{0: string, 1: string} starea nouă și o notă (sau '')
     */
    private static function stareNoua(array $tx, int $status, ?int $statusLoy, bool $areLoy, bool $sumeCorecte, int $card, int $loy, int $moneda,
        int $incasatTotal, int $rambursatTotal, bool $forteazaExpirare): array
    {
        $vechi = (string) $tx['state'];
        $cuBaniInainte = in_array($vechi, self::STARI_CU_BANI, true);
        $baniCard = in_array($status, [1, 2, 4, 7], true);
        $baniLoy = $areLoy && in_array($statusLoy, [1, 2, 4, 7], true);
        $areAutorizare = $status === BtIpayGateway::STATUS_AUTORIZATA || $statusLoy === BtIpayGateway::STATUS_AUTORIZATA;
        $colectat = in_array($status, [2, 4, 7], true) || in_array($statusLoy, [2, 4, 7], true);

        $dinBani = static function () use ($areAutorizare, $colectat, $sumeCorecte, $cuBaniInainte, $vechi, $incasatTotal, $rambursatTotal, $tx, $card, $loy, $moneda): array {
            if ($areAutorizare) {
                // O parte încă doar blocată (chiar dacă cealaltă s-a încasat): plata
                // rămâne „autorizată", ca încasarea să fie dusă la capăt.
                return $sumeCorecte || $cuBaniInainte
                    ? [self::STARE_AUTORIZATA, '']
                    : [$vechi, self::notaSumeDiferite($tx, $card, $loy, $moneda)];
            }
            if ($colectat) {
                if ($sumeCorecte || $cuBaniInainte || $vechi === self::STARE_ANULATA) {
                    $stare = $rambursatTotal <= 0
                        ? self::STARE_INCASATA
                        : ($rambursatTotal >= $incasatTotal ? self::STARE_RAMBURSATA : self::STARE_RAMBURSATA_PARTIAL);
                    return [$stare, ''];
                }
                return [$vechi, self::notaSumeDiferite($tx, $card, $loy, $moneda)];
            }
            return [$vechi, ''];
        };

        if ($baniCard) {
            if ($areLoy && !$baniLoy && !$cuBaniInainte) {
                // Cardul a trecut, punctele nu (încă / deloc): plata NU e întreagă.
                $nou = match (true) {
                    in_array($statusLoy, [BtIpayGateway::STATUS_REFUZATA, BtIpayGateway::STATUS_AUTORIZARE_ANULATA], true) => self::STARE_REFUZATA,
                    $forteazaExpirare => self::STARE_EXPIRATA,
                    $statusLoy === BtIpayGateway::STATUS_ACS => self::STARE_3DS,
                    $vechi === self::STARE_EXPIRATA => self::STARE_EXPIRATA,
                    default => self::STARE_INREGISTRATA,
                };
                return [$nou, 'Partea în puncte STAR nu e autorizată (stare ' . ($statusLoy ?? '?') . '), deși cea pe card este: plata nu e întreagă și nu se confirmă.'];
            }
            return $dinBani();
        }

        if ($status === BtIpayGateway::STATUS_AUTORIZARE_ANULATA) {
            // Cardul eliberat; dacă punctele au încă bani (încasate, sau încă
            // blocate), plata trăiește pe partea lor.
            return $baniLoy ? $dinBani() : [self::STARE_ANULATA, ''];
        }

        $nou = match ($status) {
            BtIpayGateway::STATUS_INREGISTRATA => ($forteazaExpirare || $vechi === self::STARE_EXPIRATA)
                ? self::STARE_EXPIRATA
                : ($vechi === self::STARE_EROARE ? self::STARE_EROARE : self::STARE_INREGISTRATA),
            BtIpayGateway::STATUS_ACS => $forteazaExpirare ? self::STARE_EXPIRATA : self::STARE_3DS,
            BtIpayGateway::STATUS_REFUZATA => self::STARE_REFUZATA,
            default => $vechi,
        };
        $nota = in_array($status, [0, 5, 6], true) ? '' : 'Stare necunoscută primită de la BT: ' . $status . '.';
        return [$nou, $nota];
    }

    /**
     * Aplică starea citită de la bancă. Rulează sub lacătul tranzacției.
     *
     * @param array<string, mixed> $stare
     * @param array<string, mixed>|null $stareLoy
     * @return array{ok: bool, mesaj: string, stare?: string}
     */
    private static function aplicaStare(PDO $db, array $tx, array $stare, ?array $stareLoy, string $loyId, string $sursa, bool $forteazaExpirare,
        bool $imbricata, bool $eliberareProprie): array
    {
        $txId = (int) $tx['id'];
        $tip = (string) $tx['kind'];
        $vechi = (string) $tx['state'];
        $status = (int) ($stare['orderStatus'] ?? -1);
        $statusLoy = $stareLoy !== null ? (int) ($stareLoy['orderStatus'] ?? -1) : null;
        $areLoy = $loyId !== '';
        $modLive = BtIpayGateway::modValid((string) $tx['mode']) === BtIpayGateway::MOD_LIVE;

        $card = max(0, (int) ($stare['amount'] ?? 0));
        // Partea în puncte, mereu din merchantOrderParams (ca modulul BT).
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
        if (!$areLoy) {
            $incasatLoy = 0;
            $rambursatLoy = 0;
        }

        [$nou, $nota] = self::stareNoua($tx, $status, $statusLoy, $areLoy, $sumeCorecte, $card, $loy, $moneda,
            $incasatCard + $incasatLoy, $rambursatCard + $rambursatLoy, $forteazaExpirare);
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
        }
        if (in_array($status, [1, 2, 3, 4, 7], true) || $loy > 0) {
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

        $efecte = [
            'prima_plata' => false,     // comanda devine plătită: email + ERP
            'esuata' => '',             // comanda devine eșuată
            'confirma_link' => false,   // diferența (linkul) devine plătită
            'elibereaza' => false,      // banii blocați se eliberează (operație la bancă)
            'incaseaza' => false,       // diferența se încasează imediat (operație la bancă)
            'alerte' => [],
        ];
        $setariComanda = [];
        $cereIncasare = 0;
        $inComanda = (int) ($tx['in_comanda'] ?? 0) === 1;
        $orderId = (int) ($tx['order_id'] ?? 0);
        $intraBani = !$inComanda && in_array($nou, self::STARI_CU_BANI, true) && $vechi !== self::STARE_ANULATA;
        $link = null;

        if ($tip === self::TIP_COMANDA && $orderId > 0) {
            $comanda = self::comanda($db, $orderId);
            $anulata = self::comandaAnulata($comanda);
            $platita = $comanda !== null && strtolower((string) $comanda['payment_status']) === 'paid';
            $altaPlata = self::altaPlataNumarata($db, $tx);

            if ($intraBani) {
                if (!$modLive) {
                    // O plată făcută pe platforma de test nu se socotește NICIODATĂ
                    // pe o comandă reală: nici „plătită", nici în ERP. Suma (de test)
                    // se eliberează.
                    $efecte['elibereaza'] = $nou === self::STARE_AUTORIZATA;
                    $efecte['alerte'][] = 'plata_test_pe_comanda';
                } elseif ($anulata) {
                    // Banii au venit pentru o comandă anulată între timp: nu o
                    // reînviem, eliberăm suma (sau cerem rambursare, dacă e luată).
                    $efecte['elibereaza'] = $nou === self::STARE_AUTORIZATA;
                    $efecte['alerte'][] = $nou === self::STARE_AUTORIZATA ? 'plata_comanda_inchisa' : 'necesita_rambursare';
                } elseif ($platita || $altaPlata) {
                    $efecte['elibereaza'] = $nou === self::STARE_AUTORIZATA && $altaPlata;
                    $efecte['alerte'][] = 'plata_in_plus';
                } elseif ($netDupa <= 0) {
                    // Nu se poate întâmpla cu un răspuns corect al băncii; dacă
                    // totuși, nu marcăm comanda plătită cu 0 lei.
                    $nota = $nota !== '' ? $nota : 'Banca raportează plata fără nicio sumă; plata NU a fost confirmată pe site.';
                } else {
                    $setariComanda['prima_plata'] = $netDupa;
                }
            } elseif ($inComanda && $netDupa !== $netInainte) {
                $setariComanda['delta'] = $netDupa - $netInainte;
                if ($nou === self::STARE_ANULATA && !$anulata && !$eliberareProprie
                    && !in_array((string) ($comanda['status'] ?? ''), self::COMENZI_INCHISE, true)) {
                    // Eliberată din portalul BT sau la expirarea autorizării, cu
                    // comanda încă vie: anunțăm magazinul (aprobarea din ERP va fi
                    // refuzată până se rezolvă plata).
                    $efecte['alerte'][] = 'eliberata_la_banca';
                }
            }

            if (!$inComanda && $modLive && !$platita && $vechi !== $nou && self::esteUltima($db, $tx)
                && in_array($nou, [self::STARE_REFUZATA, self::STARE_EXPIRATA], true)) {
                $efecte['esuata'] = $nou === self::STARE_REFUZATA
                    ? 'Plata cu cardul (Banca Transilvania) a fost refuzată: ' . BtIpayGateway::mesajRefuz((int) $codActiune)
                        . ($codActiune !== null ? ' [cod ' . $codActiune . ']' : '')
                    : 'Plata cu cardul (Banca Transilvania) nu a fost finalizată la timp; comanda a expirat.';
            }
            // Doar o sumă care nu se potrivește e „sumă diferită"; nota despre
            // partea în puncte încă neautorizată rămâne doar pe plată și comandă.
            if ($nota !== '' && !$sumeCorecte) {
                $efecte['alerte'][] = 'sume_diferite';
            }
        } elseif ($tip === self::TIP_LINK && $orderId > 0) {
            $comanda = self::comanda($db, $orderId);
            $anulata = self::comandaAnulata($comanda);
            $link = PaymentLink::dupaId($db, (int) ($tx['link_id'] ?? 0));
            $linkValabil = $link !== null && (string) ($link['status'] ?? '') === PaymentLink::STATUS_ASTEPTARE;

            if ($intraBani && $nou === self::STARE_AUTORIZATA) {
                if (!$modLive || $anulata || !$linkValabil) {
                    $efecte['elibereaza'] = true;
                    $efecte['alerte'][] = !$modLive ? 'plata_test_pe_comanda' : ($anulata ? 'plata_comanda_inchisa' : 'plata_link_nevalida');
                } else {
                    // Diferența nu are o aprobare ERP după care să aștepte: se
                    // încasează imediat. Cererea rămâne scrisă, ca dacă încasarea
                    // nu merge acum s-o reia cronul (cu plasa de 96 de ore).
                    $cereIncasare = self::netTx($txNou);
                    $efecte['incaseaza'] = $cereIncasare > 0;
                }
            } elseif ($intraBani) {
                // Încasată (de site, imediat după autorizare, sau din portalul BT).
                if ($linkValabil && $modLive) {
                    $efecte['confirma_link'] = true;
                    if ($anulata) {
                        $efecte['alerte'][] = 'necesita_rambursare';
                    }
                } else {
                    $efecte['alerte'][] = 'necesita_rambursare';
                }
            } elseif ($inComanda && $netDupa !== $netInainte) {
                // Diferența era deja socotită pe comandă: o rambursare scade suma încasată.
                $setariComanda['delta'] = $netDupa - $netInainte;
            }
            if ($nota !== '' && !$sumeCorecte) {
                $efecte['alerte'][] = 'sume_diferite';
            }
        }

        // Părți rămase blocate pe o plată care a picat (ex. cardul refuzat, dar
        // punctele STAR autorizate): se eliberează, altfel rămân blocate la client.
        $parteBlocata = $status === BtIpayGateway::STATUS_AUTORIZATA || ($areLoy && $statusLoy === BtIpayGateway::STATUS_AUTORIZATA);
        if ($parteBlocata && in_array($nou, [self::STARE_REFUZATA, self::STARE_EXPIRATA], true)) {
            $efecte['elibereaza'] = true;
        }

        // Scrierile în `orders` au nevoie de coloanele ERP; DDL-ul nu are voie
        // să ruleze în mijlocul tranzacției de mai jos (ar închide-o).
        if (isset($setariComanda['prima_plata']) || $efecte['confirma_link'] || $efecte['esuata'] !== '') {
            ErpSync::ensureSchema($db);
        }

        $inTranzactie = false;
        $linkConfirmat = false;
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
                    deposit_requested_at = CASE WHEN :cere_incasare > 0 THEN COALESCE(deposit_requested_at, :acum7) ELSE deposit_requested_at END,
                    deposit_requested_minor = CASE WHEN :cere_incasare2 > 0 THEN :cere_suma ELSE deposit_requested_minor END,
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
                'colectata' => in_array($status, [2, 4, 7], true) || in_array($statusLoy, [2, 4, 7], true) ? 1 : 0,
                'eliberata' => $nou === self::STARE_ANULATA ? 1 : 0,
                'rambursare_noua' => ($rambursatCard + $rambursatLoy) > ((int) $tx['refunded_minor'] + (int) $tx['loy_refunded_minor']) ? 1 : 0,
                'cere_incasare' => $cereIncasare,
                'cere_incasare2' => $cereIncasare,
                'cere_suma' => $cereIncasare > 0 ? $cereIncasare : null,
                'are_nota' => $nota !== '' ? 1 : 0,
                'nota' => $nota,
                'schimbata' => $nou !== $vechi ? 1 : 0,
                'acum1' => $acum, 'acum2' => $acum, 'acum3' => $acum, 'acum4' => $acum, 'acum5' => $acum, 'acum6' => $acum, 'acum7' => $acum,
                'json' => json_encode(
                    BtIpayGateway::redacteaza(['card' => $stare, 'loy' => $stareLoy]),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                ) ?: null,
                'id' => $txId,
            ]);

            if (isset($setariComanda['prima_plata'])) {
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

            if ($efecte['confirma_link'] && $link !== null) {
                // Exact drumul unei diferențe plătite prin EuPlătesc.
                $linkConfirmat = PaymentLink::confirmaPlata($db, $link, (string) ($tx['bt_order_id'] ?? ''), $aprobare !== '' ? $aprobare : (string) ($tx['approval_code'] ?? ''));
                if ($linkConfirmat) {
                    $db->prepare('UPDATE bt_ipay_transactions SET in_comanda = 1, deposit_requested_at = NULL, deposit_requested_minor = NULL WHERE id = :id')
                        ->execute(['id' => $txId]);
                } else {
                    $efecte['alerte'][] = 'necesita_rambursare';
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
            if ($nota !== '' && $orderId > 0 && $tip === self::TIP_COMANDA) {
                // Lângă motivul eșecului (refuzată / expirată), nu în locul lui.
                $db->prepare(
                    "UPDATE orders SET payment_error = :nota WHERE id = :id AND payment_status <> 'paid'"
                )->execute(['nota' => mb_substr(($efecte['esuata'] !== '' ? $efecte['esuata'] . ' ' : '') . $nota, 0, 1000), 'id' => $orderId]);
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
        if ($linkConfirmat) {
            try {
                // ERP-ul trebuie să afle noua sumă încasată pe aceeași comandă.
                ErpSync::push($db, $orderId, true);
            } catch (Throwable) {
                // Cronul ERP reîncearcă; banii sunt deja înregistrați pe site.
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

        // Operațiile cu banii pornite de verificare: doar din verificarea „de
        // sus". O verificare de control (din interiorul unei eliberări,
        // încasări sau rambursări) nu pornește nimic — altfel eliberarea ar
        // verifica, verificarea ar elibera… (bucla din 6 oct. 2026). Ce rămâne
        // de făcut preia cronul.
        if (!$imbricata) {
            if ($efecte['elibereaza']) {
                self::elibereazaParti($db, $txId, $sursa);
            } elseif ($efecte['incaseaza']) {
                $incasare = self::incaseazaFaraLacat($db, self::tx($db, $txId) ?? $txActual, null, $sursa, true);
                $dupaIncasare = self::tx($db, $txId) ?? $txActual;
                $linkAcum = PaymentLink::dupaId($db, (int) ($dupaIncasare['link_id'] ?? 0));
                if (!$incasare['ok'] && (string) $dupaIncasare['state'] === self::STARE_AUTORIZATA
                    && (int) ($dupaIncasare['in_comanda'] ?? 0) === 0
                    && ($linkAcum === null || (string) ($linkAcum['status'] ?? '') !== PaymentLink::STATUS_ASTEPTARE)) {
                    // Linkul a fost plătit între timp de altă plată (altă filă): asta nu se mai încasează.
                    self::alerteaza($db, $dupaIncasare, 'plata_link_nevalida', '');
                    self::elibereazaParti($db, $txId, $sursa);
                }
            }
            $txActual = self::tx($db, $txId) ?? $txActual;
        }

        $stareFinala = (string) ($txActual['state'] ?? $nou);
        return ['ok' => true, 'mesaj' => self::etichetaStare($stareFinala), 'stare' => $stareFinala];
    }

    /**
     * Eliberează părțile încă blocate ale unei plăți, după starea abia citită
     * de la bancă: întâi punctele STAR, apoi cardul (CancelTransaction din
     * modulul BT). Apoi o singură verificare de control, fără alte efecte.
     *
     * @return array{ok: bool, mesaj: string}
     */
    private static function elibereazaParti(PDO $db, int $txId, string $sursa): array
    {
        if (isset(self::$efecteInCurs[$txId])) {
            return ['ok' => false, 'mesaj' => 'Eliberarea acestei plăți e deja în curs.'];
        }
        self::$efecteInCurs[$txId] = true;
        try {
            $tx = self::tx($db, $txId);
            if ($tx === null) {
                return ['ok' => false, 'mesaj' => 'Plata nu există.'];
            }
            $mod = BtIpayGateway::modValid((string) $tx['mode']);
            $context = ['tx_id' => $txId, 'order_id' => (int) ($tx['order_id'] ?? 0), 'sursa' => $sursa];
            $eroare = '';
            $trimis = false;
            $loyId = trim((string) ($tx['loy_order_id'] ?? ''));
            if ($loyId !== '' && (int) ($tx['loy_status'] ?? -1) === BtIpayGateway::STATUS_AUTORIZATA) {
                $trimis = true;
                $rez = BtIpayGateway::apel($mod, 'reverse', ['orderId' => $loyId], $db, $context);
                if (!$rez['ok']) {
                    $eroare = 'Eliberarea punctelor STAR a eșuat: ' . $rez['eroare'];
                }
            }
            $cardId = trim((string) ($tx['bt_order_id'] ?? ''));
            if ($eroare === '' && $cardId !== '' && (int) ($tx['bt_status'] ?? -1) === BtIpayGateway::STATUS_AUTORIZATA) {
                $trimis = true;
                $rez = BtIpayGateway::apel($mod, 'reverse', ['orderId' => $cardId], $db, $context);
                if (!$rez['ok']) {
                    $eroare = 'Eliberarea sumei a eșuat: ' . $rez['eroare'];
                }
            }
            if (!$trimis) {
                return ['ok' => true, 'mesaj' => 'Nimic blocat de eliberat.'];
            }

            // Starea de la bancă decide, și după un răspuns pierdut.
            $dupa = self::sincronizeazaFaraLacat($db, self::tx($db, $txId) ?? $tx, $sursa, false, true, true);
            $tx = self::tx($db, $txId) ?? $tx;
            $ramase = (int) ($tx['bt_status'] ?? -1) === BtIpayGateway::STATUS_AUTORIZATA
                || ($loyId !== '' && (int) ($tx['loy_status'] ?? -1) === BtIpayGateway::STATUS_AUTORIZATA);
            if (!$dupa['ok'] || $ramase) {
                $mesaj = $eroare !== '' ? $eroare : 'Banca nu confirmă eliberarea întregii sume (stare: ' . self::etichetaStare((string) $tx['state']) . ').';
                self::scrieEroare($db, $txId, $mesaj . ' Cronul reîncearcă.');
                return ['ok' => false, 'mesaj' => $mesaj];
            }
            return ['ok' => true, 'mesaj' => 'Suma blocată a fost eliberată.'];
        } finally {
            unset(self::$efecteInCurs[$txId]);
        }
    }

    /**
     * Cronul: eliberează ce a rămas blocat pe o plată care a picat (ex. cardul
     * refuzat, punctele autorizate), dacă eliberarea de la verificare n-a mers.
     *
     * @return array{ok: bool, mesaj: string}
     */
    public static function elibereazaRamasite(PDO $db, int $txId, string $sursa, int $asteptare = 0): array
    {
        self::ensureSchema($db);
        return self::cuLacat($db, $txId, $asteptare, static function () use ($db, $txId, $sursa): array {
            $tx = self::tx($db, $txId);
            if ($tx === null) {
                return ['ok' => false, 'mesaj' => 'Plata nu există.'];
            }
            $verificare = self::sincronizeazaFaraLacat($db, $tx, $sursa, false, true);
            if (!$verificare['ok']) {
                return $verificare;
            }
            $tx = self::tx($db, $txId) ?? $tx;
            if (in_array((string) $tx['state'], self::STARI_CU_BANI, true)) {
                return ['ok' => true, 'mesaj' => 'Plata are bani pe ea; nu e o rămășiță.'];
            }
            return self::elibereazaParti($db, $txId, $sursa);
        });
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
     * rămâne neîncasat. Plata unei diferențe (link) se încasează doar întreagă.
     * Niciodată 0 — la bancă, 0 înseamnă „toată suma".
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
            return self::incaseazaFaraLacat($db, $tx, $sumaMinor, $sursa, false);
        });
    }

    /**
     * @param bool $stareProaspata starea tocmai a fost citită de la bancă (încasarea
     *                             imediată a unei diferențe): fără verificarea de dinainte
     * @return array{ok: bool, mesaj: string}
     */
    private static function incaseazaFaraLacat(PDO $db, array $tx, ?int $sumaMinor, string $sursa, bool $stareProaspata): array
    {
        $txId = (int) $tx['id'];
        if (isset(self::$efecteInCurs[$txId])) {
            return ['ok' => false, 'mesaj' => 'O operație pe această plată e deja în curs.'];
        }
        // Două plăți ale aceluiași link (două file), confirmate deodată: se încasează
        // pe rând, ca a doua să găsească linkul deja plătit și să nu mai fie încasată.
        $lacatLink = (string) $tx['kind'] === self::TIP_LINK ? 'link:' . (int) ($tx['link_id'] ?? 0) : '';
        if ($lacatLink !== '' && !self::iaLacat($db, $lacatLink, 15)) {
            return ['ok' => false, 'mesaj' => 'Altă plată a aceluiași link se încasează chiar acum; reîncearcă peste câteva secunde.'];
        }
        self::$efecteInCurs[$txId] = true;
        try {
            return self::incaseazaEfectiv($db, $tx, $sumaMinor, $sursa, $stareProaspata);
        } finally {
            unset(self::$efecteInCurs[$txId]);
            if ($lacatLink !== '') {
                self::elibereazaLacat($db, $lacatLink);
            }
        }
    }

    /** @return array{ok: bool, mesaj: string} */
    private static function incaseazaEfectiv(PDO $db, array $tx, ?int $sumaMinor, string $sursa, bool $stareProaspata): array
    {
        $txId = (int) $tx['id'];
        $tip = (string) $tx['kind'];
        // Întâi starea de acum de la bancă: nu încasăm pe baza unei stări vechi.
        $verificare = $stareProaspata
            ? ['ok' => true, 'mesaj' => '']
            : self::sincronizeazaFaraLacat($db, $tx, $sursa, false, true);
        $tx = self::tx($db, $txId) ?? $tx;
        if (in_array((string) $tx['state'], [self::STARE_INCASATA, self::STARE_RAMBURSATA_PARTIAL, self::STARE_RAMBURSATA], true)) {
            return ['ok' => true, 'mesaj' => 'Plata era deja încasată (' . self::lei((int) $tx['deposited_minor'] + (int) $tx['loy_deposited_minor']) . ').'];
        }
        if (!$verificare['ok']) {
            self::noteazaEsecIncasare($db, $tx, 'Nu am putut verifica starea la bancă: ' . $verificare['mesaj'], $sumaMinor);
            return ['ok' => false, 'mesaj' => 'Nu am putut verifica starea la bancă: ' . $verificare['mesaj']];
        }
        if ((string) $tx['state'] !== self::STARE_AUTORIZATA) {
            return ['ok' => false, 'mesaj' => 'Plata nu poate fi încasată: ' . self::etichetaStare((string) $tx['state']) . '.'];
        }
        if ($tip !== self::TIP_TEST) {
            if (BtIpayGateway::modValid((string) $tx['mode']) !== BtIpayGateway::MOD_LIVE) {
                return ['ok' => false, 'mesaj' => 'Plata a fost făcută în modul test; nu se încasează pe o comandă reală — eliberează suma.'];
            }
            if (self::comandaAnulata(self::comanda($db, (int) ($tx['order_id'] ?? 0)))) {
                return ['ok' => false, 'mesaj' => 'Comanda e anulată sau ștearsă: nu încasăm. Eliberează suma cu „Anulează autorizarea".'];
            }
            if ($tip === self::TIP_COMANDA && (int) ($tx['in_comanda'] ?? 0) !== 1) {
                return ['ok' => false, 'mesaj' => 'Plata nu e socotită pe comandă (plată în plus); nu se încasează — eliberează suma dacă nu e nevoie de ea.'];
            }
            if ($tip === self::TIP_LINK) {
                $link = PaymentLink::dupaId($db, (int) ($tx['link_id'] ?? 0));
                if ($link === null || (string) ($link['status'] ?? '') !== PaymentLink::STATUS_ASTEPTARE) {
                    return ['ok' => false, 'mesaj' => 'Linkul de plată nu mai e valabil (anulat sau deja plătit): nu încasăm. Eliberează suma.'];
                }
            }
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
        if ($tip === self::TIP_LINK && $suma !== $maxim) {
            // Linkul se trece pe comandă cu toată suma lui (PaymentLink::confirmaPlata):
            // o încasare parțială ar socoti pe comandă bani pe care nu i-a luat nimeni.
            return ['ok' => false, 'mesaj' => 'Diferența plătită prin link se încasează doar întreagă (' . self::lei($maxim)
                . '), fiindcă linkul se trece pe comandă cu toată suma lui. Dacă nu vrei s-o încasezi, folosește „Anulează autorizarea".'];
        }
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
        $dupa = self::sincronizeazaFaraLacat($db, self::tx($db, $txId) ?? $tx, $sursa, false, true);
        $tx = self::tx($db, $txId) ?? $tx;
        $incasat = (int) $tx['deposited_minor'] + (int) $tx['loy_deposited_minor'];
        if (!$dupa['ok'] || (string) $tx['state'] !== self::STARE_INCASATA || $incasat !== $suma) {
            $mesaj = $eroareApel !== ''
                ? $eroareApel
                : 'Banca nu confirmă încasarea (stare: ' . self::etichetaStare((string) $tx['state'])
                    . ', încasat ' . self::lei($incasat) . ' din ' . self::lei($suma) . '). Verifică în portalul BT.';
            if ((string) $tx['state'] === self::STARE_AUTORIZATA) {
                self::noteazaEsecIncasare($db, $tx, $mesaj, $suma);
            }
            return ['ok' => false, 'mesaj' => $mesaj];
        }

        $db->prepare('UPDATE bt_ipay_transactions SET deposit_requested_at = NULL, deposit_requested_minor = NULL, last_error = NULL, updated_at = :acum WHERE id = :id')
            ->execute(['acum' => date('Y-m-d H:i:s'), 'id' => $txId]);
        return ['ok' => true, 'mesaj' => 'Plata a fost încasată: ' . self::lei($incasat) . '.'
            . ($eroareApel !== '' ? ' (Banca a confirmat încasarea la verificare, deși răspunsul ei inițial s-a pierdut.)' : '')];
    }

    /** Încasarea n-a mers: o lăsăm cerută (cu suma cerută), ca s-o reia cronul. */
    private static function noteazaEsecIncasare(PDO $db, array $tx, string $mesaj, ?int $sumaCeruta = null): void
    {
        try {
            $db->prepare(
                'UPDATE bt_ipay_transactions
                 SET last_error = :m, deposit_attempts = deposit_attempts + 1,
                     deposit_requested_at = COALESCE(deposit_requested_at, :acum),
                     deposit_requested_minor = COALESCE(:suma, deposit_requested_minor),
                     updated_at = :acum2
                 WHERE id = :id'
            )->execute([
                'm' => mb_substr($mesaj, 0, 2000),
                'acum' => date('Y-m-d H:i:s'),
                'suma' => $sumaCeruta !== null && $sumaCeruta > 0 ? $sumaCeruta : null,
                'acum2' => date('Y-m-d H:i:s'),
                'id' => (int) $tx['id'],
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Comanda a fost aprobată (facturată) în ERP: încasăm plata BT, dacă are una
     * doar autorizată. Suma = cât a mai rămas de încasat pe comandă (totalul de
     * acum de pe site minus ce s-a încasat deja pe alt drum), dar nu peste cât
     * e blocat (la precomenzi, factura de avans nu e totalul). Dacă nu mai e
     * nimic de încasat, suma blocată se eliberează (vezi incaseazaSauElibereaza).
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
        $rez = self::incaseazaSauElibereaza($db, $tx, $suma, 'erp', 20, true);
        $mesaj = 'BT: ' . $rez['mesaj'];
        $comanda = self::comanda($db, $orderId);
        if ($totalEveniment !== null && $comanda !== null && abs((float) $comanda['total'] - $totalEveniment) > 0.009) {
            $mesaj .= ' (Totalul facturii din ERP, ' . number_format($totalEveniment, 2, ',', '.') . ' lei, diferă de totalul comenzii de pe site; am încasat după site.)';
        }
        $txDupa = self::tx($db, (int) $tx['id']) ?? $tx;
        if (!$rez['ok'] && !$rez['eliberata'] && (string) $txDupa['state'] === self::STARE_AUTORIZATA) {
            // Doar o încasare care se poate relua (autorizarea e încă valabilă).
            // Dacă suma a fost între timp eliberată, nu e „încasare eșuată": e o
            // comandă neplătită, iar aprobarea o refuză (cu emailul ei).
            self::alerteaza($db, $txDupa, 'incasare_esuata', $rez['mesaj']);
        }
        return ['ok' => $rez['ok'], 'mesaj' => $mesaj, 'facut' => true];
    }

    /**
     * Aprobarea din ERP a unei comenzi plătite prin BT (înainte de „în
     * procesare" și de AWB): încasează (dacă e setat așa) sau măcar verifică
     * la bancă, apoi spune dacă plata mai acoperă comanda. Dacă nu — suma
     * blocată a fost eliberată (din admin, din portalul BT, la expirare, cât
     * comanda a stat în coș), comanda nu e plătită — aprobarea e REFUZATĂ:
     * fără „în procesare", fără AWB (marfa ar pleca neplătită, fără ramburs).
     * Magazinul primește un email (o dată), iar ERP-ul vede refuzul în jurnal.
     *
     * @return array{blocata: bool, mesaj: string}
     */
    public static function laAprobareErp(PDO $db, int $orderId, ?float $totalEveniment, array $settings): array
    {
        self::ensureSchema($db);
        $mesaj = '';
        if (self::setari($settings)['incaseaza_la_aprobare']) {
            $rez = self::incaseazaLaAprobare($db, $orderId, $totalEveniment, $settings);
            if ($rez['facut']) {
                $mesaj = $rez['mesaj'];
            }
        } else {
            $principala = self::principala(self::tranzactiiComanda($db, $orderId));
            if ($principala !== null && (string) $principala['state'] === self::STARE_AUTORIZATA) {
                // Fără încasare la aprobare, măcar starea de acum de la bancă.
                self::sincronizeaza($db, (int) $principala['id'], 'erp');
            }
        }

        $motiv = self::motivBlocareAprobare($db, $orderId);
        $principala = self::principala(self::tranzactiiComanda($db, $orderId));
        if ($motiv === '') {
            if ($principala !== null) {
                self::stergeAlerta($db, (int) $principala['id'], 'aprobare_refuzata');
            }
            return ['blocata' => false, 'mesaj' => $mesaj];
        }
        if ($principala !== null) {
            self::alerteaza($db, $principala, 'aprobare_refuzata', $motiv);
        }
        return ['blocata' => true, 'mesaj' => $motiv];
    }

    /** De ce nu se poate aproba (expedia) comanda BT; gol = se poate. */
    private static function motivBlocareAprobare(PDO $db, int $orderId): string
    {
        $comanda = self::comanda($db, $orderId);
        if ($comanda === null) {
            return '';
        }
        $platita = strtolower((string) $comanda['payment_status']) === 'paid';
        $total = round((float) ($comanda['total'] ?? 0), 2);
        $incasat = $comanda['paid_amount'] === null || $comanda['paid_amount'] === '' ? $total : round((float) $comanda['paid_amount'], 2);
        $principala = self::principala(self::tranzactiiComanda($db, $orderId));
        $baniBt = $principala !== null
            && (int) ($principala['in_comanda'] ?? 0) === 1
            && BtIpayGateway::modValid((string) $principala['mode']) === BtIpayGateway::MOD_LIVE
            && in_array((string) $principala['state'], [self::STARE_AUTORIZATA, self::STARE_INCASATA, self::STARE_RAMBURSATA_PARTIAL], true)
            && self::netTx($principala) > 0;
        // Plătită integral pe alt drum (încasare înregistrată, link extern).
        $integral = $platita && $total > 0 && $incasat >= $total - 0.01;
        if ($platita && ($baniBt || $integral)) {
            return '';
        }

        $stare = $principala !== null ? (string) $principala['state'] : '';
        $cauza = match (true) {
            $principala !== null && BtIpayGateway::modValid((string) $principala['mode']) !== BtIpayGateway::MOD_LIVE => 'plata a fost făcută pe platforma de test',
            $stare === self::STARE_ANULATA => 'suma blocată pe card a fost eliberată (din admin, din portalul BT sau la expirarea autorizării)',
            in_array($stare, [self::STARE_RAMBURSATA, self::STARE_RAMBURSATA_PARTIAL], true) => 'plata a fost rambursată',
            in_array($stare, [self::STARE_REFUZATA, self::STARE_EXPIRATA, self::STARE_INREGISTRATA, self::STARE_3DS, self::STARE_EROARE], true) => 'plata cu cardul nu a fost finalizată',
            default => 'comanda nu e plătită integral',
        };
        return 'Plata cu cardul (Banca Transilvania) nu mai acoperă comanda: ' . $cauza . '.';
    }

    /**
     * Cât se încasează automat, în bani: ce a mai rămas de încasat pe comandă,
     * dar nu peste cât e blocat — max(0, min(blocat, total − încasat pe alt
     * drum)). Pe alt drum = diferențe plătite prin link, „Înregistrează
     * încasarea" (paid_amount fără partea acestei plăți). 0 = nimic de încasat.
     */
    private static function sumaDeIncasat(PDO $db, array $tx): int
    {
        $blocat = self::netTx($tx);
        if ((string) $tx['kind'] === self::TIP_LINK) {
            // Diferența se încasează întreagă: suma ei e chiar suma linkului.
            return $blocat;
        }
        $comanda = self::comanda($db, (int) ($tx['order_id'] ?? 0));
        if ($comanda === null) {
            return $blocat;
        }
        return self::deIncasatPeComanda($tx, $comanda, $blocat);
    }

    /** max(0, min(blocat, total − încasat pe alt drum)), în bani. */
    private static function deIncasatPeComanda(array $tx, array $comanda, int $blocat): int
    {
        $total = (int) round((float) ($comanda['total'] ?? 0) * 100);
        return max(0, min($blocat, $total - self::incasatAltfel($tx, $comanda)));
    }

    /**
     * Banii încasați pe comandă pe alt drum decât plata dată (diferențe prin
     * link, încasări înregistrate de mână), în bani: paid_amount minus partea
     * plății, când plata e socotită pe comandă. paid_amount gol = nimic știut
     * încasat altfel (plata BT își scrie suma acolo când devine plătită; gol
     * rămâne doar pe comenzi vechi), deci se încasează ca înainte: min(blocat, total).
     */
    private static function incasatAltfel(array $tx, array $comanda): int
    {
        $platit = $comanda['paid_amount'] ?? null;
        if ($platit === null || $platit === '') {
            return 0;
        }
        $incasat = (int) round((float) $platit * 100);
        $alPlatii = (int) ($tx['in_comanda'] ?? 0) === 1 ? self::netTx($tx) : 0;
        return max(0, $incasat - $alPlatii);
    }

    /**
     * Suma pentru reîncercare: cea cerută (de admin, la aprobare), dar nu peste
     * ce a mai rămas de încasat pe comandă; altfel cea automată. Diferențele se
     * încasează doar întregi.
     */
    private static function sumaPentruReincercare(PDO $db, array $tx): int
    {
        $automat = self::sumaDeIncasat($db, $tx);
        if ((string) $tx['kind'] === self::TIP_LINK) {
            return $automat;
        }
        $ceruta = (int) ($tx['deposit_requested_minor'] ?? 0);
        if ($ceruta > 0 && $ceruta <= self::netTx($tx)) {
            return min($ceruta, $automat);
        }
        return $automat;
    }

    /**
     * Încasarea automată a unei plăți (aprobarea din ERP, reîncercarea și ziua 4
     * din cron), cu suma dată. Sub 1 ban nu e nimic de luat de pe card — comanda
     * e deja încasată integral pe alt drum (diferență plătită, încasare
     * înregistrată) sau totalul ei e 0 —, așa că suma blocată se ELIBEREAZĂ în
     * loc să se încaseze. Magazinul află din email (`$email`; ziua 4 are emailul
     * ei, cu rezultatul) și din nota de pe plată; dacă eliberarea nu merge acum,
     * plata rămâne cerută, ca s-o reia cronul.
     *
     * @return array{ok: bool, mesaj: string, eliberata: bool}
     */
    private static function incaseazaSauElibereaza(PDO $db, array $tx, int $suma, string $sursa, int $asteptare, bool $email): array
    {
        if ($suma >= 1) {
            $r = self::incaseaza($db, (int) $tx['id'], $suma, $sursa, $asteptare);
            return ['ok' => $r['ok'], 'mesaj' => $r['mesaj'], 'eliberata' => false];
        }
        $blocat = self::lei(self::netTx($tx));
        $motiv = 'Nimic de încasat de pe card: comanda e deja încasată integral pe alt drum (diferență plătită sau încasare înregistrată)'
            . ' sau totalul ei e 0.';
        $r = self::anuleaza($db, (int) $tx['id'], $sursa, $asteptare);
        $txDupa = self::tx($db, (int) $tx['id']) ?? $tx;
        if ($r['ok']) {
            $mesaj = $motiv . ' Suma blocată (' . $blocat . ') a fost eliberată: clientul nu plătește nimic în plus.';
            if ($email) {
                self::alerteaza($db, $txDupa, 'eliberata_incasata_altfel', $mesaj);
            } else {
                self::marcheaza($db, $txDupa, 'eliberata_incasata_altfel');
            }
            return ['ok' => true, 'mesaj' => $mesaj, 'eliberata' => true];
        }
        $mesaj = $motiv . ' Eliberarea sumei blocate (' . $blocat . ') n-a mers acum (' . $r['mesaj'] . '); cronul reîncearcă.';
        if ((string) $txDupa['state'] === self::STARE_AUTORIZATA) {
            self::noteazaEsecIncasare($db, $txDupa, $mesaj);
        }
        return ['ok' => false, 'mesaj' => $mesaj, 'eliberata' => true];
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
        // Verificare de control: nu pornește nimic, doar aduce starea la zi.
        $verificare = self::sincronizeazaFaraLacat($db, $tx, $sursa, false, true);
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

        $rez = self::elibereazaParti($db, $txId, $sursa);
        $tx = self::tx($db, $txId) ?? $tx;
        if (!$rez['ok'] || (string) $tx['state'] !== self::STARE_ANULATA) {
            return ['ok' => false, 'mesaj' => $rez['ok'] ? 'Banca nu confirmă eliberarea întregii sume (stare: ' . self::etichetaStare((string) $tx['state']) . ').' : $rez['mesaj']];
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
        $verificare = self::sincronizeazaFaraLacat($db, $tx, $sursa, false, true);
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
        $dupa = self::sincronizeazaFaraLacat($db, self::tx($db, $txId) ?? $tx, $sursa, false, true);
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
     * suma doar blocată se eliberează automat (și cea a unei diferențe încă
     * neîncasate). Una deja încasată NU se rambursează singură — se anunță
     * magazinul.
     *
     * @return list<string> mesaje pentru operator
     */
    public static function laInchidereComanda(PDO $db, int $orderId, string $sursa): array
    {
        self::ensureSchema($db);
        $mesaje = [];
        foreach (array_merge(self::tranzactiiComanda($db, $orderId), self::tranzactiiLinkuri($db, $orderId)) as $tx) {
            $stare = (string) $tx['state'];
            $eticheta = (string) $tx['kind'] === self::TIP_LINK ? 'BT (diferență ' . (string) $tx['bt_order_number'] . '): ' : 'BT: ';
            if ($stare === self::STARE_AUTORIZATA) {
                $rez = self::anuleaza($db, (int) $tx['id'], 'anulare');
                $mesaje[] = $rez['ok']
                    ? $eticheta . 'suma blocată pe card a fost eliberată.'
                    : $eticheta . 'eliberarea sumei blocate n-a mers acum (' . $rez['mesaj'] . '); cronul reîncearcă.';
            } elseif (in_array($stare, [self::STARE_INCASATA, self::STARE_RAMBURSATA_PARTIAL], true)
                && self::netTx($tx) > 0) {
                $mesaje[] = $eticheta . 'plata e deja încasată (' . self::lei(self::netTx($tx)) . ') — rambursarea se face din comandă, cu butonul „Rambursează".';
                self::alerteaza($db, $tx, 'necesita_rambursare', 'Încasat și nerambursat: ' . self::lei(self::netTx($tx))
                    . ($sursa === 'erp' ? ' (comanda a fost anulată din ERP).' : '.'));
            }
        }
        return $mesaje;
    }

    /**
     * Comanda a fost scoasă din coș. Dacă între timp plata BT a fost eliberată
     * (cronul eliberează suma comenzilor din coș), comanda revine NEPLĂTITĂ:
     * magazinul primește un email, iar comanda e marcată în admin.
     *
     * @return string avertisment pentru operator, sau ''
     */
    public static function laRestaurareComanda(PDO $db, int $orderId): string
    {
        if (!self::areTabele($db)) {
            return '';
        }
        $principala = self::principala(self::tranzactiiComanda($db, $orderId));
        if ($principala === null || (int) ($principala['in_comanda'] ?? 0) !== 1
            || (string) $principala['state'] !== self::STARE_ANULATA) {
            return '';
        }
        $mesaj = 'Atenție: plata BT a acestei comenzi a fost eliberată cât timp comanda a stat în coș, deci comanda e acum NEPLĂTITĂ '
            . '(aprobarea din ERP e refuzată până se rezolvă). ' . self::CUM_SE_REZOLVA;
        self::alerteaza($db, $principala, 'eliberata_comanda_restaurata', $mesaj);
        return $mesaj;
    }

    // ------------------------------------------------------------------
    // Pentru admin
    // ------------------------------------------------------------------

    /**
     * Rezumatul plăților BT pentru comenzile din listă (doar cele care au):
     * plata comenzii (`bt`) și plățile diferențelor prin link (`linkuri`).
     *
     * @param list<int> $orderIds
     * @param array<int, array<string, mixed>> $comenzi id → rând comandă (status, total)
     * @return array<int, array{bt: array<string, mixed>|null, linkuri: list<array<string, mixed>>}>
     */
    public static function rezumatPentruAdmin(PDO $db, array $orderIds, array $comenzi, array $settings): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds), static fn (int $id): bool => $id > 0));
        if ($orderIds === [] || !self::areTabele($db)) {
            return [];
        }
        $semne = implode(',', array_fill(0, count($orderIds), '?'));
        try {
            $stmt = $db->prepare(
                'SELECT * FROM bt_ipay_transactions WHERE kind IN ("order", "link") AND order_id IN (' . $semne . ') ORDER BY id DESC'
            );
            $stmt->execute($orderIds);
            $randuri = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
        $peComanda = [];
        foreach ($randuri as $rand) {
            if (is_array($rand)) {
                $peComanda[(int) $rand['order_id']][(string) $rand['kind']][] = $rand;
            }
        }
        $linkuri = [];
        $idLinkuri = [];
        foreach ($peComanda as $grupuri) {
            foreach ($grupuri[self::TIP_LINK] ?? [] as $tx) {
                $idLinkuri[(int) ($tx['link_id'] ?? 0)] = true;
            }
        }
        unset($idLinkuri[0]);
        if ($idLinkuri !== []) {
            try {
                $ids = array_keys($idLinkuri);
                $stmt = $db->prepare('SELECT id, referinta, amount, status FROM order_payment_links WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                $stmt->execute($ids);
                foreach ($stmt->fetchAll() ?: [] as $l) {
                    $linkuri[(int) $l['id']] = $l;
                }
            } catch (Throwable) {
            }
        }

        $s = self::setari($settings);
        $rez = [];
        foreach ($peComanda as $orderId => $grupuri) {
            $comanda = $comenzi[$orderId] ?? [];
            $principala = self::principala($grupuri[self::TIP_COMANDA] ?? []);
            $listaLinkuri = [];
            foreach ($grupuri[self::TIP_LINK] ?? [] as $tx) {
                $listaLinkuri[] = self::rezumatTx($tx, $comanda, $s, 1, $linkuri[(int) ($tx['link_id'] ?? 0)] ?? []);
            }
            $rez[$orderId] = [
                'bt' => $principala !== null ? self::rezumatTx($principala, $comanda, $s, count($grupuri[self::TIP_COMANDA] ?? [])) : null,
                'linkuri' => $listaLinkuri,
            ];
        }
        return $rez;
    }

    /**
     * @param array<string, mixed> $link rândul linkului, pentru plățile de diferență
     * @return array<string, mixed>
     */
    private static function rezumatTx(array $tx, array $comanda, array $setari, int $numar, array $link = []): array
    {
        $stare = (string) $tx['state'];
        $tip = (string) $tx['kind'];
        $areLoy = trim((string) ($tx['loy_order_id'] ?? '')) !== '';
        $net = self::netTx($tx);
        $incasat = (int) $tx['deposited_minor'] + ($areLoy ? (int) $tx['loy_deposited_minor'] : 0);
        $rambursat = (int) $tx['refunded_minor'] + ($areLoy ? (int) $tx['loy_refunded_minor'] : 0);
        $rambursabil = in_array($stare, [self::STARE_INCASATA, self::STARE_RAMBURSATA_PARTIAL], true) ? max(0, $incasat - $rambursat) : 0;
        $totalComanda = (int) round((float) ($comanda['total'] ?? 0) * 100);
        $statusComanda = (string) ($comanda['status'] ?? '');
        $inchisa = in_array($statusComanda, self::COMENZI_INCHISE, true) || !empty($comanda['deleted_at']);
        $inComanda = (int) ($tx['in_comanda'] ?? 0) === 1;
        $modLive = BtIpayGateway::modValid((string) $tx['mode']) === BtIpayGateway::MOD_LIVE;
        $alerte = json_decode((string) ($tx['alerts_json'] ?? ''), true);
        $alerte = is_array($alerte) ? $alerte : [];

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
        // Comanda a fost plătită între timp pe alt drum („Plătit prin link
        // extern de plată", OP)? Atunci marcajele de mai jos nu mai au obiect.
        $platita = strtolower((string) ($comanda['payment_status'] ?? '')) === 'paid';
        $incasatComanda = ($comanda['paid_amount'] ?? null) === null || ($comanda['paid_amount'] ?? '') === ''
            ? $totalComanda
            : (int) round((float) $comanda['paid_amount'] * 100);
        $platitaIntegral = $platita && $incasatComanda >= $totalComanda;
        // Suma eliberată (din admin, portal, expirare, coș) cu comanda încă vie
        // și neplătită integral (poate avea doar o diferență plătită): comanda
        // nu are voie să plece.
        $eliberataActiva = $tip === self::TIP_COMANDA && $stare === self::STARE_ANULATA && $inComanda && !$inchisa
            && $statusComanda !== '' && !$platitaIntegral;
        $aprobareRefuzata = isset($alerte['aprobare_refuzata']) && !$inchisa && !$platitaIntegral;
        $poateIncasa = $stare === self::STARE_AUTORIZATA && !$inchisa
            && ($tip === self::TIP_TEST || ($modLive && ($tip === self::TIP_LINK || $inComanda)));

        return [
            'tx_id' => (int) $tx['id'],
            'tip' => $tip,
            'numar_bt' => (string) $tx['bt_order_number'],
            'id_bt' => (string) ($tx['bt_order_id'] ?? ''),
            'id_loy' => (string) ($tx['loy_order_id'] ?? ''),
            'mod' => (string) $tx['mode'],
            'stare' => $stare,
            'eticheta' => self::etichetaStare($stare),
            'clasa' => ($necesitaRambursare || $eliberataActiva || $aprobareRefuzata) ? 'off' : $clasa,
            'suma' => self::bani((int) $tx['amount_minor']),
            'autorizat' => self::bani((int) $tx['approved_minor'] + ($areLoy ? (int) $tx['loy_minor'] : 0)),
            'puncte' => self::bani((int) $tx['loy_minor']),
            'incasat' => self::bani($incasat),
            'rambursat' => self::bani($rambursat),
            'rambursabil' => self::bani($rambursabil),
            'incasabil' => $stare === self::STARE_AUTORIZATA ? self::bani($net) : 0.0,
            // Ca la încasarea automată: restul de încasat pe comandă, cel mult cât e blocat.
            'sugestie_incasare' => $stare === self::STARE_AUTORIZATA
                ? self::bani($tip === self::TIP_LINK ? $net : self::deIncasatPeComanda($tx, $comanda, $net))
                : 0.0,
            'termen' => $termen,
            'autorizat_la' => (string) ($tx['authorized_at'] ?? ''),
            'incasat_la' => (string) ($tx['deposited_at'] ?? ''),
            'cod_aprobare' => (string) ($tx['approval_code'] ?? ''),
            'referinta_rambursare' => (string) ($tx['refund_reference'] ?? ''),
            'eroare' => trim((string) ($tx['last_error'] ?? '')),
            'in_comanda' => $inComanda,
            'comanda_activa' => !$inchisa,
            'poate_incasa' => $poateIncasa,
            'poate_anula' => $stare === self::STARE_AUTORIZATA,
            // „Anulează autorizarea" pe plata unei comenzi vii: cere alegerea
            // explicită (anulează comanda / doar eliberează suma).
            'cere_confirmare_eliberare' => $tip === self::TIP_COMANDA && $stare === self::STARE_AUTORIZATA && $inComanda && !$inchisa,
            'poate_rambursa' => $rambursabil > 0,
            'necesita_rambursare' => $necesitaRambursare,
            'eliberata_comanda_activa' => $eliberataActiva,
            // Comanda are totuși o parte încasată (o diferență): e „plătită parțial", nu neplătită.
            'comanda_platita_partial' => $platita && !$platitaIntegral,
            'rest_comanda' => self::bani($platita ? max(0, $totalComanda - $incasatComanda) : $totalComanda),
            'aprobare_refuzata' => $aprobareRefuzata,
            'nota' => isset($alerte['eliberata_incasata_altfel'])
                ? 'Nimic de încasat de pe card: comanda era deja încasată integral pe alt drum (diferență plătită sau încasare înregistrată), așa că suma blocată a fost eliberată.'
                : '',
            'plata_test' => !$modLive && $tip !== self::TIP_TEST,
            'link_referinta' => (string) ($link['referinta'] ?? ''),
            'link_status' => (string) ($link['status'] ?? ''),
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

    /** Ultima rulare a cronului (scrisă la sfârșitul ei), pentru admin. @return array<string, mixed>|null */
    public static function ultimaRulareCron(array $settings): ?array
    {
        $date = json_decode((string) ($settings['bt_ipay_cron_last'] ?? ''), true);
        return is_array($date) ? $date : null;
    }

    // ------------------------------------------------------------------
    // Cron
    // ------------------------------------------------------------------

    /**
     * Plasa de siguranță, rulată la 15 minute (scripts/bt-ipay-sync.php):
     *  a) plățile începute și neterminate se verifică și, după 60 de minute, expiră;
     *  b) suma blocată pentru comenzi anulate/șterse, plățile în plus, cele din
     *     modul test pe comenzi reale, diferențele cu link nevalid și părțile
     *     rămase blocate pe plăți picate se eliberează (testele, după 30 min);
     *  c) încasările cerute (aprobare ERP, buton, diferențe) care au eșuat se
     *     reîncearcă, cu suma cerută (dar nu peste restul de încasat pe comandă);
     *  d) la 72 de ore: email cu plățile încă neîncasate;
     *  e) la 96 de ore (ziua 4): încasare automată, inclusiv precomenzile, și email;
     *     în c) și e), o comandă încasată deja integral pe alt drum își
     *     eliberează suma blocată în loc s-o încaseze;
     *  f) curățenia jurnalului; la SFÂRȘIT, bătaia de inimă și rezultatul rulării.
     *
     * Fiecare plată e atinsă cel mult o dată pe rulare, iar rularea are un
     * plafon de apeluri către bancă.
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

        // Plafonul de apeluri pe rulare (setarea ascunsă `bt_ipay_cron_max_apeluri`, între 20 și 2000).
        $plafon = (int) ($settings['bt_ipay_cron_max_apeluri'] ?? self::LIMITA_APELURI_CRON);
        BtIpayGateway::seteazaLimitaApeluri(BtIpayGateway::apeluriFacute() + max(20, min(2000, $plafon > 0 ? $plafon : self::LIMITA_APELURI_CRON)));
        $inceput = microtime(true);
        $rez = ['verificate' => 0, 'expirate' => 0, 'eliberate' => 0, 'reincercate' => 0, 'reamintiri' => 0,
            'incasate_automat' => 0, 'erori' => 0, 'ocupat' => false, 'limita_atinsa' => false];
        $procesate = [];
        $mesajeEroare = [];
        // Încă o plată de atins în rularea asta? (o dată pe plată, sub plafonul de apeluri)
        $poate = static function (int $id) use (&$procesate, &$rez): bool {
            if (isset($procesate[$id])) {
                return false;
            }
            if (BtIpayGateway::apeluriRamase() <= 0) {
                $rez['limita_atinsa'] = true;
                return false;
            }
            $procesate[$id] = true;
            return true;
        };
        $eroare = static function (string $mesaj) use (&$rez, &$mesajeEroare): void {
            $rez['erori']++;
            if (count($mesajeEroare) < 5) {
                $mesajeEroare[] = mb_substr($mesaj, 0, 200);
            }
        };

        try {
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
                if (!$poate((int) $rand['id'])) {
                    continue;
                }
                $expira = (string) $rand['created_at'] <= $limitaExpirare;
                $r = self::sincronizeaza($db, (int) $rand['id'], 'cron', $expira, 0);
                $rez['verificate']++;
                if (!$r['ok']) {
                    $eroare($r['mesaj']);
                } elseif (($r['stare'] ?? '') === self::STARE_EXPIRATA
                    || ($expira && ($r['stare'] ?? '') === self::STARE_ANULATA)) {
                    // Expirată; cu o parte rămasă blocată, eliberată pe loc (apare „anulată").
                    $rez['expirate']++;
                }
            }

            // b) Bani doar blocați care nu mai au ce căuta blocați.
            $stmt = $db->prepare(
                'SELECT t.id, t.state FROM bt_ipay_transactions t
                 LEFT JOIN orders o ON o.id = t.order_id
                 LEFT JOIN order_payment_links l ON l.id = t.link_id
                 WHERE (t.state = "authorized" AND (
                        (t.kind IN ("order", "link") AND (o.id IS NULL OR o.deleted_at IS NOT NULL
                            OR o.status IN ("cancelled", "refunded", "returned")))
                     OR (t.kind = "order" AND o.status = "failed" AND t.in_comanda = 1)
                     OR (t.kind IN ("order", "link") AND t.mode <> "live")
                     OR (t.kind = "order" AND t.in_comanda = 0 AND EXISTS (
                            SELECT 1 FROM bt_ipay_transactions t2
                            WHERE t2.order_id = t.order_id AND t2.kind = "order" AND t2.id <> t.id AND t2.in_comanda = 1
                              AND t2.state IN ("authorized", "deposited", "partially_refunded")))
                     OR (t.kind = "link" AND t.in_comanda = 0 AND (l.id IS NULL OR l.status <> "pending"))
                     OR (t.kind = "test" AND t.authorized_at <= :limita_test)
                 ))
                 OR (t.state IN ("declined", "expired") AND (t.bt_status = 1 OR t.loy_status = 1))
                 ORDER BY t.id ASC LIMIT 50'
            );
            $stmt->execute(['limita_test' => date('Y-m-d H:i:s', $acum - self::MINUTE_TEST_AUTORIZAT * 60)]);
            foreach (($stmt->fetchAll() ?: []) as $rand) {
                if (!$poate((int) $rand['id'])) {
                    continue;
                }
                $r = (string) $rand['state'] === self::STARE_AUTORIZATA
                    ? self::anuleaza($db, (int) $rand['id'], 'cron', 0)
                    : self::elibereazaRamasite($db, (int) $rand['id'], 'cron', 0);
                $r['ok'] ? $rez['eliberate']++ : $eroare($r['mesaj']);
            }

            // c) Încasări cerute (aprobare ERP, buton, diferențe prin link) care
            //    n-au mers: reîncercăm, cu suma cerută atunci.
            $autoInEmail = [];
            $stmt = $db->query(
                'SELECT t.* FROM bt_ipay_transactions t JOIN orders o ON o.id = t.order_id
                 WHERE t.state = "authorized" AND t.mode = "live" AND t.deposit_requested_at IS NOT NULL
                   AND ((t.kind = "order" AND t.in_comanda = 1) OR (t.kind = "link" AND t.in_comanda = 0))
                   AND o.deleted_at IS NULL AND o.status NOT IN ("cancelled", "refunded", "returned", "failed")
                 ORDER BY t.id ASC LIMIT 25'
            );
            foreach (($stmt->fetchAll() ?: []) as $tx) {
                if (!$poate((int) $tx['id'])) {
                    continue;
                }
                $suma = self::sumaPentruReincercare($db, $tx);
                $r = self::incaseazaSauElibereaza($db, $tx, $suma, 'cron', 0, true);
                $rez[$r['ok'] && $r['eliberata'] ? 'eliberate' : 'reincercate']++;
                $txActual = self::tx($db, (int) $tx['id']) ?? $tx;
                if (!$r['ok']) {
                    $eroare($r['mesaj']);
                    if ((int) $txActual['deposit_attempts'] >= 3) {
                        self::alerteaza($db, $txActual, 'incasare_esuata_repetat', $r['mesaj']);
                    }
                } elseif (self::areAlerta($txActual, 'incasare_automata_esuata')) {
                    // Încasarea automată de ziua 4 picase (cu email); acum a mers.
                    $autoInEmail[] = ['tx' => $tx, 'suma' => $suma, 'rezultat' => $r];
                }
            }

            // d) Reamintire: plăți autorizate, încă neîncasate.
            $limitaReamintire = date('Y-m-d H:i:s', $acum - $s['ore_reamintire'] * 3600);
            Precomanda::ensureSchema($db);
            $stmt = $db->prepare(
                'SELECT t.*, o.order_number, o.total, o.billing_first_name, o.billing_last_name, o.preorder_status
                 FROM bt_ipay_transactions t JOIN orders o ON o.id = t.order_id
                 WHERE t.state = "authorized" AND t.mode = "live"
                   AND ((t.kind = "order" AND t.in_comanda = 1) OR (t.kind = "link" AND t.in_comanda = 0))
                   AND t.authorized_at <= :limita AND t.reminder_sent_at IS NULL AND o.deleted_at IS NULL
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

            // e) Ziua 4: încasare automată, ca să nu pierdem plata (inclusiv
            //    precomenzile și diferențele). Emailul spune doar ce e nou: o
            //    încasare reușită, sau PRIMUL eșec al unei plăți (nu la fiecare rulare).
            $limitaAutomat = date('Y-m-d H:i:s', $acum - $s['ore_incasare_automata'] * 3600);
            $stmt = $db->prepare(
                'SELECT t.*, o.order_number, o.total, o.billing_first_name, o.billing_last_name
                 FROM bt_ipay_transactions t JOIN orders o ON o.id = t.order_id
                 WHERE t.state = "authorized" AND t.mode = "live"
                   AND ((t.kind = "order" AND t.in_comanda = 1) OR (t.kind = "link" AND t.in_comanda = 0))
                   AND t.authorized_at <= :limita AND o.deleted_at IS NULL
                   AND o.status NOT IN ("cancelled", "refunded", "returned", "failed")
                 ORDER BY t.authorized_at ASC LIMIT 50'
            );
            $stmt->execute(['limita' => $limitaAutomat]);
            foreach (($stmt->fetchAll() ?: []) as $tx) {
                if (!$poate((int) $tx['id'])) {
                    continue;
                }
                $suma = self::sumaPentruReincercare($db, $tx);
                // Sub 1 ban (comanda e încasată integral altfel), suma se eliberează;
                // emailul încasării automate de mai jos spune asta.
                $r = self::incaseazaSauElibereaza($db, $tx, $suma, 'cron', 0, false);
                if (!$r['ok']) {
                    $eroare($r['mesaj']);
                } elseif ($r['eliberata']) {
                    $rez['eliberate']++;
                } else {
                    $rez['incasate_automat']++;
                }
                $txActual = self::tx($db, (int) $tx['id']) ?? $tx;
                if ($r['ok'] || self::marcheaza($db, $txActual, 'incasare_automata_esuata')) {
                    $autoInEmail[] = ['tx' => $tx, 'suma' => $suma, 'rezultat' => $r];
                }
            }
            if ($autoInEmail !== []) {
                self::emailIncasareAutomata($db, $settings, $s, $autoInEmail);
            }

            // Plăți autorizate care n-au putut fi legate de comandă (venite pentru
            // o comandă deja plătită altfel): nu le încasăm singuri, dar le semnalăm.
            $stmt = $db->prepare(
                'SELECT t.* FROM bt_ipay_transactions t
                 WHERE t.state = "authorized" AND t.kind = "order" AND t.in_comanda = 0 AND t.mode = "live" AND t.authorized_at <= :limita
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
            $eroare('Eroare: ' . $e->getMessage());
            $scrie('Eroare: ' . $e->getMessage());
        } finally {
            if ($rez['limita_atinsa']) {
                $mesajeEroare[] = 'Plafonul de apeluri către bancă pe rulare a fost atins; restul plăților se iau la rularea următoare.';
            }
            // Bătaia de inimă se scrie la SFÂRȘITUL rulării: o rulare care moare
            // pe drum (memorie, timp) nu lasă în urmă un „a rulat acum" fals.
            try {
                Settings::save($db, [
                    'bt_ipay_cron_heartbeat' => date('Y-m-d H:i:s'),
                    'bt_ipay_cron_last' => (string) json_encode([
                        'la' => date('Y-m-d H:i:s'),
                        'durata_s' => round(microtime(true) - $inceput, 1),
                        'apeluri' => BtIpayGateway::apeluriFacute(),
                        'rezultat' => array_diff_key($rez, ['ocupat' => true]),
                        'mesaje' => $mesajeEroare,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (Throwable) {
            }
            self::elibereazaLacat($db, 'cron');
        }
        return $rez;
    }

    // ------------------------------------------------------------------
    // Emailuri către magazin
    // ------------------------------------------------------------------

    private static function areAlerta(array $tx, string $tip): bool
    {
        $trimise = json_decode((string) ($tx['alerts_json'] ?? ''), true);
        return is_array($trimise) && isset($trimise[$tip]);
    }

    /** Pune un marcaj pe plată; true dacă e nou (n-a mai fost pus). */
    private static function marcheaza(PDO $db, array $tx, string $tip): bool
    {
        $actual = self::tx($db, (int) $tx['id']) ?? $tx;
        $trimise = json_decode((string) ($actual['alerts_json'] ?? ''), true);
        $trimise = is_array($trimise) ? $trimise : [];
        if (isset($trimise[$tip])) {
            return false;
        }
        $trimise[$tip] = date('Y-m-d H:i:s');
        try {
            $db->prepare('UPDATE bt_ipay_transactions SET alerts_json = :j WHERE id = :id')
                ->execute(['j' => json_encode($trimise), 'id' => (int) $tx['id']]);
        } catch (Throwable) {
            return false;
        }
        return true;
    }

    /** Șterge un marcaj (situația s-a rezolvat; dacă revine, se anunță din nou). */
    private static function stergeAlerta(PDO $db, int $txId, string $tip): void
    {
        $tx = self::tx($db, $txId);
        if ($tx === null) {
            return;
        }
        $trimise = json_decode((string) ($tx['alerts_json'] ?? ''), true);
        if (!is_array($trimise) || !isset($trimise[$tip])) {
            return;
        }
        unset($trimise[$tip]);
        try {
            $db->prepare('UPDATE bt_ipay_transactions SET alerts_json = :j WHERE id = :id')
                ->execute(['j' => $trimise === [] ? null : json_encode($trimise), 'id' => $txId]);
        } catch (Throwable) {
        }
    }

    /** Trimite o alertă o singură dată pe tip, pe plată. */
    private static function alerteaza(PDO $db, array $tx, string $tip, string $detalii): void
    {
        if (!self::marcheaza($db, $tx, $tip)) {
            return;
        }

        $comanda = self::comanda($db, (int) ($tx['order_id'] ?? 0));
        $numar = $comanda !== null ? (string) $comanda['order_number'] : (string) $tx['bt_order_number'];
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES);
        $titluri = [
            'plata_comanda_inchisa' => 'Plată primită pentru o comandă anulată — ' . $numar,
            'plata_in_plus' => 'Plată în plus pe comanda ' . $numar,
            'plata_test_pe_comanda' => 'Plată din modul TEST pe comanda reală ' . $numar,
            'plata_link_nevalida' => 'Plată pe un link de diferență care nu mai era valabil — comanda ' . $numar,
            'eliberata_la_banca' => 'Suma blocată a fost eliberată la bancă — comanda ' . $numar . ' e încă activă',
            'eliberata_comanda_restaurata' => 'Comanda ' . $numar . ' a fost scoasă din coș, dar plata ei BT fusese eliberată',
            'aprobare_refuzata' => 'Aprobarea din ERP a fost refuzată: comanda ' . $numar . ' nu e plătită',
            'sume_diferite' => 'Sumă diferită raportată de bancă — comanda ' . $numar,
            'incasare_esuata' => 'Încasarea plății BT a eșuat — comanda ' . $numar,
            'incasare_esuata_repetat' => 'Încasarea plății BT eșuează repetat — comanda ' . $numar,
            'necesita_rambursare' => 'Comanda ' . $numar . ' a fost închisă, dar plata BT e încasată — rambursează',
            'eliberata_incasata_altfel' => 'Plata BT a comenzii ' . $numar . ' nu s-a încasat: comanda era deja încasată integral',
        ];
        $explicatii = [
            'plata_comanda_inchisa' => 'Clientul a plătit cu cardul după ce comanda fusese anulată pe site. '
                . 'Site-ul a eliberat imediat suma blocată (clientul nu plătește nimic); dacă eliberarea n-a mers, cronul o reia.',
            'plata_in_plus' => 'Pe comandă a venit o plată BT în plus (comanda era deja plătită). Site-ul NU o încasează. '
                . 'Dacă există deja o plată BT socotită pe comandă, suma în plus a fost eliberată automat; altfel verifică și, '
                . 'dacă e cazul, anuleaz-o din comandă („Anulează autorizarea").',
            'plata_test_pe_comanda' => 'O plată făcută pe platforma de TEST a băncii a ajuns pe o comandă reală. Ea nu se socotește niciodată '
                . '(comanda nu devine plătită și nu pleacă în ERP ca plătită); suma de test a fost eliberată.',
            'plata_link_nevalida' => 'Clientul a plătit un link de diferență după ce linkul fusese anulat (înlocuit de altul) sau plătit pe alt drum. '
                . 'Site-ul NU a încasat suma: a eliberat-o. Verifică ce mai are clientul de plătit.',
            'eliberata_la_banca' => 'Banca raportează că suma blocată pentru această comandă a fost eliberată (din portalul BT sau la expirarea '
                . 'autorizării), dar comanda e încă activă pe site. Comanda a devenit NEPLĂTITĂ (sau, dacă avea și o diferență încasată, '
                . 'plătită doar parțial): aprobarea din ERP va fi refuzată '
                . '(fără AWB) până se rezolvă. ' . self::CUM_SE_REZOLVA,
            'eliberata_comanda_restaurata' => 'Cât timp comanda a stat în coș, cronul i-a eliberat suma blocată pe card. Comanda e acum activă, '
                . 'dar NEPLĂTITĂ: aprobarea din ERP va fi refuzată (fără AWB) până se rezolvă plata. ' . self::CUM_SE_REZOLVA,
            'aprobare_refuzata' => 'Comanda a fost aprobată (facturată) în ERP, dar plata cu cardul nu mai acoperă comanda. Site-ul NU a trecut-o '
                . 'în procesare și NU a generat AWB (marfa ar fi plecat neplătită, fără ramburs), iar în jurnalul ERP apare refuzul. '
                . self::CUM_SE_REZOLVA . ' Aprobarea se reia singură la următoarea sincronizare cu ERP-ul.',
            'sume_diferite' => 'Banca raportează o sumă diferită de cea a comenzii, așa că site-ul NU a confirmat plata.',
            'incasare_esuata' => 'Comanda a fost aprobată, dar încasarea sumei blocate pe card nu a mers. Cronul reîncearcă la 15 minute; '
                . 'o poți face și din comandă, cu „Încasează". Termenul băncii e de 5 zile de la autorizare.',
            'incasare_esuata_repetat' => 'Încasarea a eșuat de cel puțin 3 ori. Verifică în portalul BT și încearcă din comandă („Încasează").',
            'necesita_rambursare' => 'Plata a fost încasată, dar comanda a fost anulată / returnată (sau plata nu mai poate fi socotită pe ea). '
                . 'Rambursarea NU se face automat: deschide comanda în admin și apasă „Rambursează" (suma e completată, se poate modifica).',
            'eliberata_incasata_altfel' => 'La încasarea automată (aprobarea din ERP sau cronul), comanda era deja încasată integral pe alt drum — '
                . 'o diferență plătită prin link sau o încasare înregistrată în comandă — (sau totalul ei era 0). Ca clientul să nu plătească '
                . 'de două ori, site-ul NU a încasat nimic de pe card și a eliberat suma blocată. Verifică în comandă că suma încasată e cea corectă.',
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

    /**
     * Fiecare rambursare și fiecare eliberare manuală a unei sume: email către
     * magazin (cine, ce comandă, cât, cu ce rezultat). Butoanele sunt la
     * îndemâna oricărui administrator care lucrează cu comenzile, deci orice
     * mișcare de bani se vede și în căsuța magazinului, nu doar în jurnal.
     */
    public static function emailOperatieManuala(PDO $db, int $txId, string $operatie, ?int $sumaMinor, array $rezultat, string $cine): void
    {
        $tx = self::tx($db, $txId);
        if ($tx === null) {
            return;
        }
        $comanda = self::comanda($db, (int) ($tx['order_id'] ?? 0));
        $numar = $comanda !== null ? (string) $comanda['order_number'] : (string) $tx['bt_order_number'];
        $ok = !empty($rezultat['ok']);
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES);
        $tipPlata = match ((string) $tx['kind']) {
            self::TIP_TEST => 'plata de test',
            self::TIP_LINK => 'plata diferenței (link)',
            default => 'plata comenzii',
        };
        $suma = $sumaMinor !== null && $sumaMinor > 0 ? self::lei($sumaMinor) : self::lei(self::netTx($tx) > 0 ? self::netTx($tx) : (int) $tx['amount_minor']);
        $titlu = $operatie === 'refund'
            ? 'Rambursare ' . $suma . ' — ' . ($comanda !== null ? 'comanda ' . $numar : $numar)
            : 'Eliberare manuală a sumei blocate — ' . ($comanda !== null ? 'comanda ' . $numar : $numar);
        $activa = $comanda !== null && !in_array((string) $comanda['status'], self::COMENZI_INCHISE, true) && $comanda['deleted_at'] === null;
        $avertisment = $operatie === 'reverse' && $ok && $activa && (string) $tx['kind'] === self::TIP_COMANDA
            ? '<p style="color:#b91c1c;"><strong>Comanda rămâne activă și e acum NEPLĂTITĂ</strong> (sau doar parțial plătită, dacă avea și o '
                . 'diferență încasată). Aprobarea din ERP va fi refuzată (fără AWB) '
                . 'până se rezolvă plata sau se anulează comanda.</p>'
            : '';

        $html = '<p><strong>' . $e($titlu) . '</strong></p>'
            . $avertisment
            . '<table cellpadding="6" style="border-collapse:collapse;font-size:14px;">'
            . '<tr><td style="color:#64748b;">Operație</td><td>' . $e($operatie === 'refund' ? 'Rambursare (refund)' : 'Eliberare sumă blocată (reverse)') . ' — ' . $e($tipPlata) . '</td></tr>'
            . '<tr><td style="color:#64748b;">Făcută de</td><td><strong>' . $e($cine !== '' ? $cine : 'necunoscut') . '</strong></td></tr>'
            . '<tr><td style="color:#64748b;">Comanda</td><td>' . $e($numar) . '</td></tr>'
            . '<tr><td style="color:#64748b;">Nr. plată la bancă</td><td>' . $e((string) $tx['bt_order_number']) . ((string) $tx['mode'] !== 'live' ? ' (mod test)' : '') . '</td></tr>'
            . '<tr><td style="color:#64748b;">Sumă</td><td>' . $e($suma) . '</td></tr>'
            . '<tr><td style="color:#64748b;">Rezultat</td><td style="color:' . ($ok ? '#166534' : '#b91c1c') . ';">' . $e(($ok ? 'Reușită: ' : 'Nereușită: ') . (string) ($rezultat['mesaj'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#64748b;">Când</td><td>' . $e(date('d.m.Y H:i:s')) . '</td></tr>'
            . '</table>'
            . ($comanda !== null ? '<p><a href="' . $e(AppUrl::absolut('/admin/orders?q=' . rawurlencode($numar))) . '">Deschide comanda în admin</a></p>' : '');
        self::trimiteMagazinului($db, Settings::all($db), '[BT iPay] ' . $titlu . ($ok ? '' : ' (NEREUȘITĂ)'), $html,
            'bt_ipay_' . ($operatie === 'refund' ? 'rambursare' : 'eliberare_manuala'), (int) ($tx['order_id'] ?? 0));
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
                . '<td>' . $e((string) $tx['order_number']) . ((string) $tx['kind'] === self::TIP_LINK ? ' (diferență ' . $e((string) $tx['bt_order_number']) . ')' : '')
                . (trim((string) ($tx['preorder_status'] ?? '')) !== '' ? ' (precomandă)' : '') . '</td>'
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
        $eliberate = 0;
        foreach ($rezultate as $r) {
            $ok = (bool) ($r['rezultat']['ok'] ?? false);
            // Comanda era deja încasată integral pe alt drum: suma blocată s-a eliberat, nu s-a încasat.
            $eliberata = $ok && !empty($r['rezultat']['eliberata']);
            $reusite += $ok && !$eliberata ? 1 : 0;
            $eliberate += $eliberata ? 1 : 0;
            $randuri .= '<tr>'
                . '<td>' . $e((string) ($r['tx']['order_number'] ?? $r['tx']['bt_order_number'] ?? ''))
                . ((string) ($r['tx']['kind'] ?? '') === self::TIP_LINK ? ' (diferență)' : '') . '</td>'
                . '<td style="text-align:right;">' . $e($eliberata ? '0,00 lei (eliberat ' . self::lei(self::netTx($r['tx'])) . ')' : self::lei((int) $r['suma'])) . '</td>'
                . '<td style="color:' . ($ok ? '#166534' : '#b91c1c') . ';">' . $e((string) ($r['rezultat']['mesaj'] ?? ''))
                . ($ok ? '' : ' — cronul reîncearcă la 15 minute; acest email nu se mai repetă pentru plata asta.') . '</td>'
                . '</tr>';
        }
        $html = '<p><strong>Încasare automată BT (ziua ' . (int) ceil($s['ore_incasare_automata'] / 24) . '):</strong> '
            . $reusite . ' din ' . count($rezultate) . ' plăți încasate'
            . ($eliberate > 0 ? ', ' . $eliberate . ' eliberate fără încasare (comanda era deja încasată integral pe alt drum)' : '') . '.</p>'
            . '<p>Comenzile de mai jos nu fuseseră aprobate în ERP la timp, așa că site-ul a încasat singur sumele blocate, ca plata să nu se piardă. '
            . 'Dacă una dintre ele se anulează ulterior, rambursează din comandă („Rambursează").'
            . ($eliberate > 0 ? ' Unde comanda era deja încasată integral pe alt drum (diferență plătită prin link, încasare înregistrată), '
                . 'site-ul NU a încasat nimic de pe card: a eliberat suma blocată, ca clientul să nu plătească de două ori.' : '') . '</p>'
            . '<table cellpadding="6" border="1" style="border-collapse:collapse;font-size:13px;border-color:#e2e8f0;">'
            . '<tr style="background:#f1f5f9;"><th>Comanda</th><th>Sumă</th><th>Rezultat</th></tr>' . $randuri . '</table>'
            . '<p><a href="' . $e(AppUrl::absolut('/admin/orders')) . '">Deschide comenzile</a></p>';
        self::trimiteMagazinului($db, $settings, '[BT iPay] Încasare automată: ' . $reusite . ' din ' . count($rezultate)
            . ($eliberate > 0 ? ' (' . $eliberate . ' eliberate)' : ''), $html, 'bt_ipay_incasare_automata', 0);
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
