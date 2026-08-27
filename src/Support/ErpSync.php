<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Trimiterea comenzilor către ERP-ul ANDAXI.
 *
 * Comanda rămâne întotdeauna pe site: dacă ERP-ul nu răspunde, comanda se
 * salvează normal și se marchează pentru reîncercare. Reîncercările se fac
 * automat (cron: scripts/erp-sync.php) sau manual, din butonul „Retrimite în
 * ERP" din lista de comenzi.
 */
final class ErpSync
{
    /** Nu s-a încercat încă / așteaptă condiția de trimitere (ex. plata). */
    public const STATUS_PENDING = 'pending';
    /** Trimisă cu succes; ERP-ul a confirmat preluarea. */
    public const STATUS_SENT = 'sent';
    /** Ultima încercare a eșuat; se reîncearcă după `erp_next_retry_at`. */
    public const STATUS_FAILED = 'failed';
    /** Nu se trimite (comandă anulată, plată eșuată). */
    public const STATUS_SKIPPED = 'skipped';
    /** Trimisă în ERP, apoi anulată pe site; anularea n-a ajuns încă acolo. */
    public const STATUS_CANCEL_PENDING = 'cancel_pending';
    /** Trimisă în ERP și anulată și acolo. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Pauzele dintre reîncercări, în secunde. Ultima se repetă. */
    private const BACKOFF = [60, 300, 900, 3600, 21600, 43200];

    private const MAX_ATTEMPTS = 12;

    /**
     * Trimite comanda dacă e cazul.
     *
     * @param bool $force Ignoră fereastra de retry (folosit de butonul manual).
     * @return array{ok: bool, message: string}
     */
    public static function push(PDO $db, int $orderId, bool $force = false): array
    {
        if ($orderId <= 0) {
            return ['ok' => false, 'message' => 'Comandă inexistentă.'];
        }

        self::ensureSchema($db);

        try {
            $settings = Settings::all($db);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Nu am putut citi setările.'];
        }

        if ((string) ($settings['erp_enabled'] ?? '0') !== '1') {
            return ['ok' => false, 'message' => 'Integrarea cu ERP-ul este dezactivată.'];
        }

        $order = self::loadOrder($db, $orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'Comanda nu a fost găsită.'];
        }

        if ((string) ($order['erp_status'] ?? '') === self::STATUS_SENT && !$force) {
            return ['ok' => true, 'message' => 'Comanda era deja trimisă în ERP.'];
        }

        $blocker = self::blockingReason($order);
        if ($blocker !== null) {
            self::mark($db, $orderId, self::STATUS_PENDING, ['error' => $blocker]);
            return ['ok' => false, 'message' => $blocker];
        }

        if (!$force && !self::isDue($order)) {
            return ['ok' => false, 'message' => 'Reîncercarea e programată mai târziu.'];
        }

        $client = ErpClient::fromSettings($settings);
        if ($client === null) {
            $message = 'Adresa ERP-ului sau cheia de integrare lipsesc din Setări → ERP ANDAXI.';
            self::mark($db, $orderId, self::STATUS_FAILED, ['error' => $message, 'attempt' => true]);
            return ['ok' => false, 'message' => $message];
        }

        try {
            $payload = self::buildPayload($db, $order);
            $response = $client->pushOrder($payload);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            self::mark($db, $orderId, self::STATUS_FAILED, ['error' => $message, 'attempt' => true]);
            return ['ok' => false, 'message' => $message];
        }

        $probleme = [];
        foreach ((array) ($response['probleme'] ?? []) as $problema) {
            $text = trim((string) $problema);
            if ($text !== '') {
                $probleme[] = $text;
            }
        }

        // Modificarea unei comenzi facturate/anulate în ERP e refuzată acolo;
        // reținem motivul ca problemă vizibilă, nu ca eroare de reîncercat
        // (un retry ar primi exact același refuz).
        $motivRespins = trim((string) ($response['motivRespins'] ?? ''));
        if ($motivRespins !== '') {
            $probleme[] = $motivRespins;
        }

        self::mark($db, $orderId, self::STATUS_SENT, [
            'attempt' => true,
            'erp_order_id' => (string) ($response['comandaId'] ?? ''),
            'problems' => $probleme,
        ]);

        if ($motivRespins !== '') {
            return ['ok' => false, 'message' => $motivRespins];
        }

        $duplicat = (bool) ($response['duplicat'] ?? false);
        $actualizat = (bool) ($response['actualizat'] ?? false);
        return [
            'ok' => true,
            'message' => $actualizat
                ? 'Modificarea comenzii a fost propagată în ERP.'
                : ($duplicat
                    ? 'Comanda exista deja în ERP; am actualizat referința.'
                    : 'Comanda a fost trimisă în ERP.'),
        ];
    }

    /**
     * Reîncearcă comenzile eșuate al căror termen a venit. Rulează din cron.
     *
     * @return array{incercate: int, reusite: int, esuate: int}
     */
    public static function retryPending(PDO $db, int $limit = 25): array
    {
        self::ensureSchema($db);

        $stmt = $db->prepare(
            "SELECT id FROM orders
             WHERE deleted_at IS NULL
               AND erp_status IN ('pending', 'failed')
               AND erp_attempts < :max_attempts
               AND (erp_next_retry_at IS NULL OR erp_next_retry_at <= NOW())
               AND status NOT IN ('cancelled', 'refunded', 'failed')
               AND (payment_method NOT IN ('stripe', 'euplatesc') OR payment_status = 'paid')
             ORDER BY id ASC
             LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute(['max_attempts' => self::MAX_ATTEMPTS]);

        $rezultat = ['incercate' => 0, 'reusite' => 0, 'esuate' => 0];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $rezultat['incercate']++;
            $push = self::push($db, (int) $row['id']);
            $rezultat[$push['ok'] ? 'reusite' : 'esuate']++;
        }
        return $rezultat;
    }

    /** Marchează o comandă ca netrimisă (anulare, plată eșuată). */
    public static function skip(PDO $db, int $orderId, string $motiv = ''): void
    {
        self::ensureSchema($db);
        self::mark($db, $orderId, self::STATUS_SKIPPED, ['error' => $motiv]);
    }

    /**
     * Comanda nu mai are ce căuta în ERP (anulată, eșuată, rambursată).
     * O marcăm netrimisă doar dacă chiar nu a plecat încă — dacă ERP-ul o are
     * deja, referința rămâne, iar anularea se face acolo.
     */
    public static function skipDacaNetrimisa(PDO $db, int $orderId, string $motiv = ''): void
    {
        if ($orderId <= 0) {
            return;
        }
        try {
            self::ensureSchema($db);
            $stmt = $db->prepare('SELECT erp_status FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $orderId]);
            $status = strtolower(trim((string) ($stmt->fetchColumn() ?: self::STATUS_PENDING)));
            if ($status === self::STATUS_SENT || $status === self::STATUS_SKIPPED) {
                return;
            }
            self::skip($db, $orderId, $motiv);
        } catch (Throwable) {
            // Marcajul e informativ; o eroare aici nu trebuie să oprească anularea.
        }
    }

    /**
     * Anularea comenzii, dusă până la capăt în ERP.
     *
     * Dacă în ERP comanda n-a ajuns încă, doar o scoatem din coada de
     * trimitere (comportamentul vechi). Dacă a ajuns, cerem ERP-ului să o
     * anuleze, ca să dispară din lista „Comenzi site". Când ERP-ul nu
     * răspunde, comanda rămâne pe `cancel_pending` și cron-ul reia anularea.
     *
     * @return array{ok: bool, message: string}
     */
    public static function anuleaza(PDO $db, int $orderId, string $motiv = ''): array
    {
        if ($orderId <= 0) {
            return ['ok' => false, 'message' => 'Comandă inexistentă.'];
        }

        try {
            self::ensureSchema($db);
            $stmt = $db->prepare(
                'SELECT id, order_number, erp_status FROM orders WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        if (!is_array($order)) {
            return ['ok' => false, 'message' => 'Comanda nu a fost găsită.'];
        }

        $status = strtolower(trim((string) ($order['erp_status'] ?? '')));
        if ($status === self::STATUS_CANCELLED) {
            return ['ok' => true, 'message' => 'Comanda era deja anulată în ERP.'];
        }
        if ($status !== self::STATUS_SENT && $status !== self::STATUS_CANCEL_PENDING) {
            // N-a plecat niciodată: nu are ce anula acolo.
            self::skipDacaNetrimisa($db, $orderId, $motiv);
            return ['ok' => true, 'message' => 'Comanda nu ajunsese în ERP; am oprit trimiterea.'];
        }

        return self::trimiteAnularea($db, $orderId, (string) ($order['order_number'] ?? ''), $motiv);
    }

    /**
     * Reia anulările care n-au ajuns în ERP (ERP oprit în momentul anulării).
     * Rulează din cron, imediat după `retryPending()`.
     *
     * @return array{incercate: int, reusite: int, esuate: int}
     */
    public static function retryCancels(PDO $db, int $limit = 25): array
    {
        self::ensureSchema($db);

        $stmt = $db->prepare(
            "SELECT id, order_number FROM orders
             WHERE erp_status = :status
             ORDER BY id ASC
             LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute(['status' => self::STATUS_CANCEL_PENDING]);

        $rezultat = ['incercate' => 0, 'reusite' => 0, 'esuate' => 0];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $rezultat['incercate']++;
            $anulare = self::trimiteAnularea(
                $db,
                (int) $row['id'],
                (string) ($row['order_number'] ?? ''),
                'Comandă anulată pe site.'
            );
            $rezultat[$anulare['ok'] ? 'reusite' : 'esuate']++;
        }
        return $rezultat;
    }

    /** Cererea propriu-zisă de anulare către ERP, cu marcarea rezultatului. */
    private static function trimiteAnularea(PDO $db, int $orderId, string $numarSite, string $motiv): array
    {
        $numar = trim($numarSite);
        if ($numar === '') {
            return ['ok' => false, 'message' => 'Comanda nu are număr; nu o pot anula în ERP.'];
        }

        $client = ErpClient::fromDb($db);
        if ($client === null) {
            $message = 'Integrarea cu ERP-ul e oprită sau neconfigurată; anularea nu a fost trimisă.';
            self::mark($db, $orderId, self::STATUS_CANCEL_PENDING, ['error' => $message]);
            return ['ok' => false, 'message' => $message];
        }

        try {
            $raspuns = $client->cancelOrder($numar, $motiv);
        } catch (Throwable $exception) {
            $message = 'Anularea nu a ajuns în ERP: ' . $exception->getMessage();
            self::mark($db, $orderId, self::STATUS_CANCEL_PENDING, ['error' => $message]);
            return ['ok' => false, 'message' => $message];
        }

        // Comanda e anulată în ERP; dacă factura de acolo a rămas de verificat,
        // ERP-ul ne spune de ce, iar noi păstrăm motivul pe comandă.
        $avertisment = (string) ($raspuns['avertisment'] ?? '');
        $nota = $avertisment !== ''
            ? 'Anulată în ERP, dar factura de acolo trebuie verificată: ' . $avertisment
            : (trim($motiv) !== '' ? $motiv : 'Comandă anulată pe site și în ERP.');

        self::mark($db, $orderId, self::STATUS_CANCELLED, ['error' => $nota]);
        return [
            'ok' => true,
            'message' => $avertisment !== '' ? $nota : 'Comanda a fost anulată și în ERP.',
        ];
    }

    // ───────────────────────────────────────────────────────────
    // Payload
    // ───────────────────────────────────────────────────────────

    /** Construiește corpul cererii de ingestie din comanda de pe site. */
    public static function buildPayload(PDO $db, array $order): array
    {
        $orderId = (int) $order['id'];
        $sameAsBilling = ((int) ($order['shipping_same_as_billing'] ?? 1)) === 1;

        $livrareNume = $sameAsBilling
            ? trim((string) $order['billing_first_name'] . ' ' . (string) $order['billing_last_name'])
            : trim((string) ($order['shipping_first_name'] ?? '') . ' ' . (string) ($order['shipping_last_name'] ?? ''));

        // Bloc, scară, apartament, etaj intră în adresă: ERP-ul o folosește și
        // pe factură, iar fără bucata a doua clientul de la bloc rămâne cu o
        // adresă la care nu se poate livra.
        $cuBloc = static function (string $strada, string $bloc): string {
            $strada = trim($strada);
            $bloc = trim($bloc);
            if ($bloc === '') {
                return $strada;
            }
            return $strada === '' ? $bloc : $strada . ', ' . $bloc;
        };
        $adresa = $sameAsBilling
            ? $cuBloc((string) ($order['billing_address_line1'] ?? ''), (string) ($order['billing_address_line2'] ?? ''))
            : $cuBloc((string) ($order['shipping_address_line1'] ?? ''), (string) ($order['shipping_address_line2'] ?? ''));

        $esteFirma = ((int) ($order['billing_is_company'] ?? 0)) === 1;
        $metoda = strtolower((string) ($order['payment_method'] ?? 'cod'));

        // Conturile create doar cu email + parolă au numele generic „Client Nou".
        // Trimis așa în ERP, partenerul apărea acolo tot „Client Nou". Pentru
        // persoanele fizice fără nume real folosim adresa de email — singurul
        // identificator pe care îl avem.
        $clientNume = CustomerDisplay::nume(
            (string) ($order['billing_first_name'] ?? ''),
            (string) ($order['billing_last_name'] ?? ''),
            (string) ($order['billing_email'] ?? ''),
        );

        return [
            'numarSite' => (string) $order['order_number'],
            'dataComanda' => self::iso((string) ($order['created_at'] ?? '')),

            'clientNume' => $clientNume,
            'clientEmail' => (string) ($order['billing_email'] ?? ''),
            'clientTelefon' => (string) ($order['billing_phone'] ?? ''),
            'esteFirma' => $esteFirma,
            'firmaDenumire' => $esteFirma ? (string) ($order['billing_company_name'] ?? '') : '',
            'firmaCui' => $esteFirma ? (string) ($order['billing_company_tax_id'] ?? '') : '',
            'firmaRegCom' => $esteFirma ? (string) ($order['billing_company_registration_no'] ?? '') : '',

            // Adresa fiscală a clientului (sediul social), separată de cea de
            // livrare. Pe factură trebuie asta, nu locul unde ajunge coletul.
            'facturareAdresa' => $cuBloc(
                (string) ($order['billing_address_line1'] ?? ''),
                (string) ($order['billing_address_line2'] ?? ''),
            ),
            'facturareOras' => (string) ($order['billing_city'] ?? ''),
            'facturareJudet' => (string) ($order['billing_county'] ?? ''),
            'facturareCodPostal' => (string) ($order['billing_postcode'] ?? ''),

            'livrareNume' => $livrareNume,
            'livrareAdresa' => $adresa,
            'livrareOras' => (string) ($sameAsBilling ? ($order['billing_city'] ?? '') : ($order['shipping_city'] ?? '')),
            'livrareJudet' => (string) ($sameAsBilling ? ($order['billing_county'] ?? '') : ($order['shipping_county'] ?? '')),
            'livrareCodPostal' => (string) ($sameAsBilling ? ($order['billing_postcode'] ?? '') : ($order['shipping_postcode'] ?? '')),
            'livrareTara' => 'RO',

            'transport' => round((float) ($order['shipping_cost'] ?? 0), 2),
            'cuponCod' => (string) ($order['coupon_code'] ?? ''),
            'discountCupon' => round((float) ($order['discount_total'] ?? 0), 2),
            // Reducere negociată după plasarea comenzii; pe factură apare
            // ca linie distinctă, separat de cupon și de puncte.
            'discountManual' => round((float) ($order['manual_discount'] ?? 0), 2),
            'motivDiscountManual' => trim((string) ($order['manual_discount_reason'] ?? '')) ?: null,
            'puncteFolosite' => (float) ($order['loyalty_points_used'] ?? 0),
            'discountPuncte' => round((float) ($order['loyalty_points_discount'] ?? 0), 2),
            'total' => round((float) ($order['total'] ?? 0), 2),

            'metodaPlata' => $metoda === 'cod'
                ? 'ramburs'
                : ($metoda === 'bank_transfer' ? 'op' : 'card'),
            'platit' => strtolower((string) ($order['payment_status'] ?? '')) === 'paid',
            // Cât s-a încasat efectiv. Dacă operatorul a adăugat produse după
            // plată, e mai mic decât totalul, iar diferența rămâne de încasat.
            'sumaIncasata' => self::sumaIncasata($order),
            'observatii' => (string) ($order['notes'] ?? ''),

            'linii' => self::buildLines($db, $orderId),
        ];
    }

    /**
     * Suma încasată prin card pentru o comandă.
     *
     * `paid_amount` se scrie la confirmarea plății și nu se mai schimbă. Pentru
     * comenzile plătite înainte de introducerea coloanei, cădem pe totalul
     * comenzii — ele nu fuseseră modificate după plată, fiindcă propagarea
     * modificărilor a apărut odată cu această coloană.
     */
    private static function sumaIncasata(array $order): float
    {
        $platit = strtolower((string) ($order['payment_status'] ?? '')) === 'paid';
        if (!$platit) {
            return 0.0;
        }
        $incasat = $order['paid_amount'] ?? null;
        if ($incasat === null || $incasat === '') {
            return round((float) ($order['total'] ?? 0), 2);
        }
        return round((float) $incasat, 2);
    }

    /** Liniile comenzii, cu SKU-ul și cota de TVA luate din fișa produsului. */
    private static function buildLines(PDO $db, int $orderId): array
    {
        $cotaImplicita = round((float) (Settings::all($db)['default_vat_percent'] ?? 19), 2);

        try {
            $stmt = $db->prepare(
                'SELECT oi.product_id, oi.product_name, oi.quantity, oi.unit_price,
                        p.sku, p.vat_percent, p.vat_included
                 FROM order_items oi
                 LEFT JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id = :order_id
                 ORDER BY oi.id ASC'
            );
            $stmt->execute(['order_id' => $orderId]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            // Schemă fără coloanele de TVA pe produs: cădem pe cota implicită
            // configurată în Setări magazin, nu pe una scrisă în cod.
            $stmt = $db->prepare(
                'SELECT oi.product_id, oi.product_name, oi.quantity, oi.unit_price,
                        p.sku, :cota_implicita AS vat_percent, 1 AS vat_included
                 FROM order_items oi
                 LEFT JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id = :order_id
                 ORDER BY oi.id ASC'
            );
            $stmt->execute(['order_id' => $orderId, 'cota_implicita' => $cotaImplicita]);
            $rows = $stmt->fetchAll() ?: [];
        }

        $lines = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                // Fără SKU nu se poate mapa în ERP, dar nici nu blocăm comanda:
                // linia ajunge acolo „nemapată" și se rezolvă din interfața ERP.
                $sku = 'SITE-' . (int) ($row['product_id'] ?? 0);
            }
            $lines[] = [
                'sku' => $sku,
                'denumire' => (string) ($row['product_name'] ?? 'Produs'),
                'cantitate' => max(1, (int) ($row['quantity'] ?? 1)),
                'pretUnitar' => round((float) ($row['unit_price'] ?? 0), 4),
                'tvaInclus' => ((int) ($row['vat_included'] ?? 1)) === 1,
                'cotaTva' => round((float) ($row['vat_percent'] ?? $cotaImplicita), 2),
            ];
        }
        return $lines;
    }

    // ───────────────────────────────────────────────────────────
    // Stare
    // ───────────────────────────────────────────────────────────

    /** Motivul pentru care comanda nu are voie să plece încă, sau null. */
    private static function blockingReason(array $order): ?string
    {
        $status = strtolower((string) ($order['status'] ?? ''));
        if (in_array($status, ['cancelled', 'refunded', 'failed'], true)) {
            return 'Comanda e anulată/eșuată, nu se trimite în ERP.';
        }
        $metoda = strtolower((string) ($order['payment_method'] ?? ''));
        $platit = strtolower((string) ($order['payment_status'] ?? '')) === 'paid';
        if (in_array($metoda, ['stripe', 'euplatesc'], true) && !$platit) {
            return 'Comanda cu plata prin card se trimite după confirmarea plății.';
        }
        // Aceeași regulă la OP: comanda pleacă spre ERP abia când banii sunt
        // în extras și cineva confirmă plata din admin.
        if ($metoda === 'bank_transfer' && !$platit) {
            return 'Comanda cu plata prin OP se trimite după confirmarea plății în admin.';
        }
        return null;
    }

    /** A venit momentul reîncercării? */
    private static function isDue(array $order): bool
    {
        if ((int) ($order['erp_attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            return false;
        }
        $next = trim((string) ($order['erp_next_retry_at'] ?? ''));
        if ($next === '') {
            return true;
        }
        return strtotime($next) <= time();
    }

    /**
     * @param array{error?: string, attempt?: bool, erp_order_id?: string, problems?: string[]} $extra
     */
    private static function mark(PDO $db, int $orderId, string $status, array $extra = []): void
    {
        $sets = ['erp_status = :status'];
        $params = ['status' => $status, 'id' => $orderId];

        if (($extra['attempt'] ?? false) === true) {
            $sets[] = 'erp_attempts = erp_attempts + 1';
            $sets[] = 'erp_last_attempt_at = NOW()';
        }

        if ($status === self::STATUS_SENT) {
            $sets[] = 'erp_synced_at = NOW()';
            $sets[] = 'erp_next_retry_at = NULL';
            $sets[] = 'erp_last_error = NULL';
            $sets[] = 'erp_order_id = :erp_order_id';
            $params['erp_order_id'] = ($extra['erp_order_id'] ?? '') !== '' ? $extra['erp_order_id'] : null;
            $sets[] = 'erp_problems = :problems';
            $problems = $extra['problems'] ?? [];
            $params['problems'] = $problems === [] ? null : implode("\n", $problems);
        } else {
            if (isset($extra['error'])) {
                $sets[] = 'erp_last_error = :error';
                $params['error'] = mb_substr((string) $extra['error'], 0, 1000);
            }
            if ($status === self::STATUS_FAILED) {
                $sets[] = 'erp_next_retry_at = :next_retry';
                $params['next_retry'] = self::nextRetryAt($db, $orderId);
            }
        }

        try {
            $db->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        } catch (Throwable) {
            // Marcarea e auxiliară; nu are voie să pice fluxul de checkout.
        }
    }

    /** Momentul următoarei reîncercări, cu backoff exponențial. */
    private static function nextRetryAt(PDO $db, int $orderId): string
    {
        $attempts = 0;
        try {
            $stmt = $db->prepare('SELECT erp_attempts FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $orderId]);
            $attempts = (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable) {
        }

        // `mark()` incrementează în același UPDATE, deci încercarea curentă e
        // cea de la indexul `attempts`.
        $index = min($attempts, count(self::BACKOFF) - 1);
        $delay = self::BACKOFF[max(0, $index)];

        return (new DateTimeImmutable('now'))
            ->modify('+' . $delay . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    private static function loadOrder(PDO $db, int $orderId): ?array
    {
        try {
            $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();
        } catch (Throwable) {
            return null;
        }
        return is_array($order) ? $order : null;
    }

    private static function iso(string $value): string
    {
        $ts = $value !== '' ? strtotime($value) : false;
        return date('c', $ts !== false ? $ts : time());
    }

    // ───────────────────────────────────────────────────────────
    // Schemă
    // ───────────────────────────────────────────────────────────

    /** Adaugă coloanele de sincronizare pe `orders`, dacă lipsesc. */
    public static function ensureSchema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $columns = [
            'erp_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'erp_order_id' => 'VARCHAR(32) DEFAULT NULL',
            'erp_attempts' => 'INT NOT NULL DEFAULT 0',
            'erp_last_error' => 'TEXT DEFAULT NULL',
            'erp_last_attempt_at' => 'DATETIME DEFAULT NULL',
            'erp_next_retry_at' => 'DATETIME DEFAULT NULL',
            'erp_synced_at' => 'DATETIME DEFAULT NULL',
            'erp_problems' => 'TEXT DEFAULT NULL',
            'erp_factura_numar' => 'VARCHAR(40) DEFAULT NULL',
            // Toate AWB-urile comenzii (JSON), când pleacă în mai multe colete
            // din depozite diferite; `fan_awb` rămâne primul colet.
            'fan_awb_list' => 'TEXT DEFAULT NULL',
            // Suma chiar încasată prin card. Rămâne cea de la plată chiar dacă
            // totalul comenzii crește ulterior (produse adăugate de operator),
            // ca ERP-ul să nu înregistreze o încasare mai mare decât cea reală.
            'paid_amount' => 'DECIMAL(10,2) DEFAULT NULL',
            // Deblocarea pentru corecție: după facturare comanda e închisă, dar
            // se întâmplă să iasă un produs din stoc după emitere. Cine o
            // redeschide lasă în urmă motivul, ora și numele — corectura pe o
            // comandă deja facturată trebuie să fie explicabilă peste un an.
            'edit_unlocked_at' => 'DATETIME DEFAULT NULL',
            'edit_unlock_reason' => 'VARCHAR(500) DEFAULT NULL',
            'edit_unlocked_by' => 'VARCHAR(190) DEFAULT NULL',
        ];

        // Reducerea pusă de operator din ERP pe o linie. Prețul din
        // `order_items` e deja cel redus — coloana asta există doar ca să se
        // vadă în admin de ce s-a schimbat, și prețul dinainte.
        foreach ([
            'erp_discount_percent' => 'DECIMAL(5,2) DEFAULT NULL',
            'erp_price_before' => 'DECIMAL(10,2) DEFAULT NULL',
        ] as $nume => $definitie) {
            try {
                $db->exec('ALTER TABLE order_items ADD COLUMN ' . $nume . ' ' . $definitie);
            } catch (Throwable) {
                // Coloana există deja.
            }
        }

        foreach ($columns as $name => $definition) {
            try {
                $db->exec('ALTER TABLE orders ADD COLUMN ' . $name . ' ' . $definition);
            } catch (Throwable) {
                // Coloana există deja.
            }
        }

        try {
            $db->exec('CREATE INDEX idx_orders_erp_status ON orders (erp_status, erp_next_retry_at)');
        } catch (Throwable) {
            // Indexul există deja.
        }
    }
}
