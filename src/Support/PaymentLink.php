<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Linkuri de plată pentru diferența rămasă pe o comandă.
 *
 * Cazul tipic: clientul a plătit cu cardul, apoi cere un produs în plus.
 * Operatorul adaugă produsul, totalul crește, iar diferența se încasează
 * printr-un link trimis pe email. Plata se leagă de aceeași comandă: la
 * confirmare, `orders.paid_amount` crește, iar comanda pleacă din nou în
 * ERP, unde suma încasată se actualizează pe aceeași factură.
 *
 * Referința trimisă la procesator e `{numar_comanda}-P{n}`: unică pentru
 * fiecare link (procesatorul refuză același număr de două ori), dar
 * recunoscută la întoarcere ca aparținând comenzii.
 *
 * Fiecare link ține minte procesatorul cu care se plătește (`procesator`):
 * EuPlătesc, ca până acum, sau Banca Transilvania, când BT e pornit în
 * producție. Linkurile deja trimise merg mai departe pe procesatorul lor.
 */
final class PaymentLink
{
    public const STATUS_ASTEPTARE = 'pending';
    public const STATUS_PLATIT = 'paid';
    public const STATUS_ANULAT = 'cancelled';

    public const PROCESATOR_EUPLATESC = 'euplatesc';
    public const PROCESATOR_BT = 'btipay';

    /** Cât timp rămâne valabil un link netrimis la plată (zile). */
    private const VALABILITATE_ZILE = 30;

    /** Versiunea tabelului; se schimbă la fiecare coloană nouă. */
    private const VERSIUNE_SCHEMA = '2';
    private const CHEIE_VERSIUNE = 'payment_links_schema_version';

    /**
     * Tabelul linkurilor. Se creează (și se completează cu coloanele noi) o
     * singură dată: versiunea rămâne în `settings`, așa că paginile publice
     * (/plata/...) nu mai trimit DDL la fiecare vizită.
     */
    public static function ensureSchema(PDO $db): void
    {
        static $gata = false;
        if ($gata) {
            return;
        }
        $gata = true;

        try {
            $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
            $stmt->execute(['k' => self::CHEIE_VERSIUNE]);
            if ((string) ($stmt->fetchColumn() ?: '') === self::VERSIUNE_SCHEMA) {
                return;
            }
        } catch (Throwable) {
            // Fără tabelul de setări mergem pe drumul lung.
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS order_payment_links (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id INT UNSIGNED NOT NULL,
                    referinta VARCHAR(60) NOT NULL,
                    token VARCHAR(64) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT "pending",
                    procesator VARCHAR(20) NOT NULL DEFAULT "euplatesc",
                    ep_id VARCHAR(64) DEFAULT NULL,
                    approval VARCHAR(64) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME DEFAULT NULL,
                    paid_at DATETIME DEFAULT NULL,
                    UNIQUE KEY uq_order_payment_links_referinta (referinta),
                    UNIQUE KEY uq_order_payment_links_token (token),
                    KEY idx_order_payment_links_order (order_id, status)
                )'
            );
        } catch (Throwable) {
            // Tabelul există deja.
        }
        try {
            // Linkurile de dinainte au fost toate EuPlătesc.
            $db->exec('ALTER TABLE order_payment_links ADD COLUMN procesator VARCHAR(20) NOT NULL DEFAULT "euplatesc" AFTER status');
        } catch (Throwable) {
            // Coloana există deja.
        }

        try {
            $db->query('SELECT procesator FROM order_payment_links LIMIT 0');
            $db->prepare(
                'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            )->execute(['k' => self::CHEIE_VERSIUNE, 'v' => self::VERSIUNE_SCHEMA]);
        } catch (Throwable) {
            // Data viitoare încercăm din nou.
        }
    }

    /**
     * Cu ce procesator se plătește un link NOU: Banca Transilvania când e
     * pornită în producție, cu datele de acces și deschisă clienților (nu doar
     * administratorilor); altfel EuPlătesc, ca până acum. Gol = niciunul.
     */
    public static function procesorPentruLinkNou(array $settings): string
    {
        if (BtIpayPayments::poatePlatiLinkuri($settings)) {
            return self::PROCESATOR_BT;
        }
        if (EuPlatescGateway::isEnabled($settings)) {
            return self::PROCESATOR_EUPLATESC;
        }
        return '';
    }

    /** Se mai poate plăti azi un link cu procesatorul lui? */
    public static function procesorDisponibil(string $procesator, array $settings): bool
    {
        return $procesator === self::PROCESATOR_BT
            ? BtIpayPayments::poatePlatiLinkuri($settings)
            : EuPlatescGateway::isEnabled($settings);
    }

    public static function procesator(array $link): string
    {
        return (string) ($link['procesator'] ?? '') === self::PROCESATOR_BT
            ? self::PROCESATOR_BT
            : self::PROCESATOR_EUPLATESC;
    }

    /**
     * Cu ce se plătește linkul ACUM: procesatorul lui; un link BT trece pe
     * EuPlătesc doar dacă BT nu mai e disponibil (oprit, mod test) și
     * EuPlătesc e pornit — ca un link deja trimis să nu ducă într-o fundătură.
     * Referința `{comanda}-P{n}` e recunoscută la fel de ambele drumuri.
     */
    public static function procesorEfectiv(array $link, array $settings): string
    {
        $procesator = self::procesator($link);
        if ($procesator === self::PROCESATOR_BT
            && !BtIpayPayments::poatePlatiLinkuri($settings)
            && EuPlatescGateway::isEnabled($settings)) {
            return self::PROCESATOR_EUPLATESC;
        }
        return $procesator;
    }

    /** Restul de încasat pentru o comandă: total − cât s-a încasat deja. */
    public static function restDeIncasat(array $order): float
    {
        if (strtolower((string) ($order['payment_status'] ?? '')) !== 'paid') {
            return 0.0;
        }
        $total = round((float) ($order['total'] ?? 0), 2);
        $incasat = $order['paid_amount'] ?? null;
        if ($incasat === null || $incasat === '') {
            $incasat = $total;
        }
        return round(max(0.0, $total - (float) $incasat), 2);
    }

    /**
     * Creează un link de plată. Un link nefolosit pentru aceeași sumă se
     * refolosește, ca să nu umplem tabelul la retrimiteri — dacă procesatorul
     * lui mai e disponibil (un link EuPlătesc trimis deja merge mai departe pe
     * EuPlătesc). Linkul nou primește procesatorul dat.
     *
     * @return array<string, mixed>|null
     */
    public static function creeaza(PDO $db, int $orderId, string $orderNumber, float $suma, string $procesator = self::PROCESATOR_EUPLATESC, array $settings = []): ?array
    {
        self::ensureSchema($db);
        $suma = round($suma, 2);
        if ($orderId <= 0 || $suma <= 0) {
            return null;
        }
        $procesator = $procesator === self::PROCESATOR_BT ? self::PROCESATOR_BT : self::PROCESATOR_EUPLATESC;

        $existent = self::linkActivPentruSuma($db, $orderId, $suma);
        if ($existent !== null && ($settings === [] || self::procesorDisponibil(self::procesator($existent), $settings))) {
            return $existent;
        }

        // Anulăm linkurile în așteptare pentru alte sume: nu mai sunt valide.
        try {
            $db->prepare(
                'UPDATE order_payment_links SET status = :anulat
                 WHERE order_id = :order_id AND status = :asteptare'
            )->execute([
                'anulat' => self::STATUS_ANULAT,
                'order_id' => $orderId,
                'asteptare' => self::STATUS_ASTEPTARE,
            ]);
        } catch (Throwable) {
            return null;
        }

        for ($incercare = 1; $incercare <= 20; $incercare++) {
            $numar = self::urmatorulNumar($db, $orderId);
            $referinta = substr($orderNumber, 0, 50) . '-P' . $numar;
            $token = bin2hex(random_bytes(24));
            try {
                $db->prepare(
                    'INSERT INTO order_payment_links (order_id, referinta, token, amount, status, procesator, expires_at)
                     VALUES (:order_id, :referinta, :token, :amount, :status, :procesator, :expires_at)'
                )->execute([
                    'order_id' => $orderId,
                    'referinta' => $referinta,
                    'token' => $token,
                    'amount' => number_format($suma, 2, '.', ''),
                    'status' => self::STATUS_ASTEPTARE,
                    'procesator' => $procesator,
                    'expires_at' => date('Y-m-d H:i:s', time() + self::VALABILITATE_ZILE * 86400),
                ]);
                return self::dupaToken($db, $token);
            } catch (Throwable) {
                // Referință deja folosită (două cereri simultane) — reîncercăm.
                continue;
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    public static function dupaToken(PDO $db, string $token): ?array
    {
        self::ensureSchema($db);
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM order_payment_links WHERE token = :token LIMIT 1');
            $stmt->execute(['token' => $token]);
        } catch (Throwable) {
            return null;
        }
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    /** @return array<string, mixed>|null */
    public static function dupaId(PDO $db, int $id): ?array
    {
        self::ensureSchema($db);
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM order_payment_links WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
        } catch (Throwable) {
            return null;
        }
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    /** @return array<string, mixed>|null */
    public static function dupaReferinta(PDO $db, string $referinta): ?array
    {
        self::ensureSchema($db);
        $referinta = trim($referinta);
        if ($referinta === '') {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM order_payment_links WHERE referinta = :referinta LIMIT 1');
            $stmt->execute(['referinta' => $referinta]);
        } catch (Throwable) {
            return null;
        }
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    /** Referința arată ca un link de plată („204026-P1")? */
    public static function pareReferintaDeLink(string $referinta): bool
    {
        return preg_match('/-P\d+$/', trim($referinta)) === 1;
    }

    public static function esteExpirat(array $link): bool
    {
        $expira = trim((string) ($link['expires_at'] ?? ''));
        if ($expira === '') {
            return false;
        }
        return strtotime($expira) !== false && strtotime($expira) < time();
    }

    /**
     * Confirmă plata unui link și adaugă suma la cea deja încasată pe comandă.
     * Idempotentă: un link deja plătit nu adaugă suma a doua oară.
     *
     * @return bool true dacă acum a fost marcat plătit (prima confirmare)
     */
    public static function confirmaPlata(PDO $db, array $link, string $epId = '', string $approval = ''): bool
    {
        $linkId = (int) ($link['id'] ?? 0);
        $orderId = (int) ($link['order_id'] ?? 0);
        $suma = round((float) ($link['amount'] ?? 0), 2);
        if ($linkId <= 0 || $orderId <= 0 || $suma <= 0) {
            return false;
        }

        try {
            // Doar tranziția „în așteptare" → „plătit" adaugă banii.
            $stmt = $db->prepare(
                'UPDATE order_payment_links
                 SET status = :platit, paid_at = NOW(), ep_id = :ep_id, approval = :approval
                 WHERE id = :id AND status = :asteptare'
            );
            $stmt->execute([
                'platit' => self::STATUS_PLATIT,
                'ep_id' => $epId !== '' ? substr($epId, 0, 64) : null,
                'approval' => $approval !== '' ? substr($approval, 0, 64) : null,
                'id' => $linkId,
                'asteptare' => self::STATUS_ASTEPTARE,
            ]);
            if ($stmt->rowCount() < 1) {
                return false;
            }

            ErpSync::ensureSchema($db);
            $db->prepare(
                'UPDATE orders
                 SET paid_amount = ROUND(COALESCE(paid_amount, total) + :suma, 2),
                     payment_status = \'paid\',
                     paid_at = COALESCE(paid_at, NOW())
                 WHERE id = :order_id AND deleted_at IS NULL'
            )->execute([
                'suma' => number_format($suma, 2, '.', ''),
                'order_id' => $orderId,
            ]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private static function linkActivPentruSuma(PDO $db, int $orderId, float $suma): ?array
    {
        try {
            $stmt = $db->prepare(
                'SELECT * FROM order_payment_links
                 WHERE order_id = :order_id AND status = :asteptare AND amount = :amount
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([
                'order_id' => $orderId,
                'asteptare' => self::STATUS_ASTEPTARE,
                'amount' => number_format($suma, 2, '.', ''),
            ]);
        } catch (Throwable) {
            return null;
        }
        $rand = $stmt->fetch();
        if (!is_array($rand) || self::esteExpirat($rand)) {
            return null;
        }
        return $rand;
    }

    private static function urmatorulNumar(PDO $db, int $orderId): int
    {
        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM order_payment_links WHERE order_id = :order_id');
            $stmt->execute(['order_id' => $orderId]);
            return max(1, (int) $stmt->fetchColumn() + 1);
        } catch (Throwable) {
            return 1;
        }
    }
}
