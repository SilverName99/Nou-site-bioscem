<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Precomanda: marfa care se vinde înainte să existe în gestiune.
 *
 * Un produs bifat „valabil pentru precomandă" se poate cumpăra deși stocul e
 * zero — tocmai de asta e precomandă. Ca să nu se vândă mai mult decât poate
 * aduce firma, fiecare produs are două plafoane:
 *
 *  - câte bucăți poate lua un client într-o comandă;
 *  - câte bucăți se pot precomanda în total, pe toate comenzile.
 *
 * Comanda care conține măcar un astfel de produs nu pleacă în ERP la plasare,
 * cum pleacă restul: rămâne în așteptare până când cineva apasă butonul din
 * „Precomenzi". Altfel ERP-ul ar primi o comandă pentru marfă care încă nu
 * există, ar rezerva stoc inexistent și ar cere o factură pe care n-o poate
 * emite nimeni.
 *
 * Câte bucăți s-au precomandat până acum NU se ține într-un contor separat: se
 * numără din comenzi, de fiecare dată. Un contor ar fi mai rapid, dar ar
 * rămâne în urmă la prima comandă anulată, iar plafonul ar bloca vânzarea
 * pentru marfă care de fapt e liberă.
 */
final class Precomanda
{
    /** Comanda așteaptă butonul din „Precomenzi". */
    public const ASTEPTARE = 'asteptare';
    /** Butonul a fost apăsat: comanda a plecat pe drumul obișnuit. */
    public const ELIBERATA = 'eliberata';

    public static function ensureSchema(?PDO $db): void
    {
        if (!$db instanceof PDO) {
            return;
        }
        foreach ([
            'ALTER TABLE products ADD COLUMN preorder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER out_of_stock',
            'ALTER TABLE products ADD COLUMN preorder_max_per_order INT UNSIGNED DEFAULT NULL AFTER preorder_enabled',
            'ALTER TABLE products ADD COLUMN preorder_max_total INT UNSIGNED DEFAULT NULL AFTER preorder_max_per_order',
            'ALTER TABLE orders ADD COLUMN preorder_status VARCHAR(12) DEFAULT NULL AFTER status',
            'ALTER TABLE orders ADD COLUMN preorder_released_at DATETIME DEFAULT NULL AFTER preorder_status',
        ] as $sql) {
            try {
                $db->exec($sql);
            } catch (Throwable) {
                // Coloana există deja.
            }
        }
    }

    /** Produsul se poate precomanda? */
    public static function estePrecomanda(array $product): bool
    {
        return (int) ($product['preorder_enabled'] ?? 0) === 1;
    }

    /** Câte bucăți poate lua un client într-o singură comandă. */
    public static function limitaPerComanda(array $product): ?int
    {
        $v = $product['preorder_max_per_order'] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }

    /** Câte bucăți se pot precomanda în total, pe toate comenzile. */
    public static function maximTotal(array $product): ?int
    {
        $v = $product['preorder_max_total'] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }

    /**
     * Câte bucăți s-au precomandat deja pentru un produs.
     *
     * Se numără doar comenzile care chiar contează: cele anulate, eșuate sau
     * șterse eliberează locul înapoi. Comenzile deja trimise în ERP rămân la
     * socoteală — marfa aceea tot trebuie adusă.
     */
    public static function cantitatePrecomandata(?PDO $db, int $productId): int
    {
        if (!$db instanceof PDO || $productId <= 0) {
            return 0;
        }
        try {
            $stmt = $db->prepare(
                'SELECT COALESCE(SUM(oi.quantity), 0)
                   FROM order_items oi
                   JOIN orders o ON o.id = oi.order_id
                  WHERE oi.product_id = :pid
                    AND o.preorder_status IS NOT NULL
                    AND o.deleted_at IS NULL
                    AND LOWER(o.status) NOT IN ("cancelled", "refunded", "failed")'
            );
            $stmt->execute(['pid' => $productId]);
            return max(0, (int) $stmt->fetchColumn());
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Câte bucăți mai pot fi precomandate din plafonul total.
     * `null` = fără plafon.
     */
    public static function ramasDinTotal(?PDO $db, array $product): ?int
    {
        $maxim = self::maximTotal($product);
        if ($maxim === null) {
            return null;
        }
        $luate = self::cantitatePrecomandata($db, (int) ($product['id'] ?? 0));
        return max(0, $maxim - $luate);
    }

    /**
     * Cât poate lua clientul acum, dintr-o dată.
     *
     * E cea mai mică dintre limita pe comandă și ce a mai rămas din plafonul
     * total: dacă mai sunt 3 bucăți în campanie, degeaba limita pe comandă e 5.
     * `null` = fără nicio limită.
     */
    public static function limitaEfectiva(?PDO $db, array $product): ?int
    {
        if (!self::estePrecomanda($product)) {
            return null;
        }
        $perComanda = self::limitaPerComanda($product);
        $ramas = self::ramasDinTotal($db, $product);
        if ($perComanda === null) {
            return $ramas;
        }
        if ($ramas === null) {
            return $perComanda;
        }
        return min($perComanda, $ramas);
    }

    /** Mesajul pentru clientul care cere mai mult decât se poate. */
    public static function mesajLimita(int $limita): string
    {
        if ($limita <= 0) {
            return 'Precomanda pentru acest produs s-a închis — s-a atins numărul maxim de bucăți.';
        }
        return $limita === 1
            ? 'La acest produs se poate precomanda o singură bucată.'
            : "La acest produs se pot precomanda cel mult {$limita} bucăți.";
    }

    /**
     * Comanda conține măcar un produs de precomandă?
     *
     * Se citește din produsele comenzii, nu din coș: coșul poate fi golit, dar
     * comanda rămâne, iar întrebarea se pune și mult mai târziu, din admin.
     */
    public static function comandaArePrecomanda(?PDO $db, int $orderId): bool
    {
        if (!$db instanceof PDO || $orderId <= 0) {
            return false;
        }
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*)
                   FROM order_items oi
                   JOIN products p ON p.id = oi.product_id
                  WHERE oi.order_id = :oid AND p.preorder_enabled = 1'
            );
            $stmt->execute(['oid' => $orderId]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Pune comanda în așteptare, dacă are produse de precomandă.
     *
     * Se cheamă la plasarea comenzii, înainte de trimiterea în ERP. Întoarce
     * `true` dacă a oprit-o — atunci apelantul știe să nu mai împingă nimic.
     */
    public static function marcheazaComanda(?PDO $db, int $orderId): bool
    {
        if (!$db instanceof PDO || $orderId <= 0) {
            return false;
        }
        if (!self::comandaArePrecomanda($db, $orderId)) {
            return false;
        }
        try {
            self::ensureSchema($db);
            $db->prepare(
                'UPDATE orders SET preorder_status = :st
                  WHERE id = :id AND preorder_status IS NULL AND deleted_at IS NULL'
            )->execute(['st' => self::ASTEPTARE, 'id' => $orderId]);
        } catch (Throwable) {
            return false;
        }
        return true;
    }

    /** Comanda așteaptă butonul? */
    public static function esteInAsteptare(array $order): bool
    {
        return (string) ($order['preorder_status'] ?? '') === self::ASTEPTARE;
    }

    /**
     * Eliberează comanda: o scoate din așteptare și o trimite în ERP.
     *
     * Marcajul se scrie ÎNAINTE de trimitere, altfel `ErpSync` ar vedea tot o
     * comandă în așteptare și s-ar bloca singur.
     *
     * @return array{ok: bool, message: string}
     */
    public static function elibereaza(?PDO $db, int $orderId): array
    {
        if (!$db instanceof PDO || $orderId <= 0) {
            return ['ok' => false, 'message' => 'Comandă invalidă.'];
        }
        self::ensureSchema($db);

        try {
            $stmt = $db->prepare(
                'SELECT id, order_number, preorder_status FROM orders
                  WHERE id = :id AND deleted_at IS NULL LIMIT 1'
            );
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Nu am putut citi comanda: ' . $e->getMessage()];
        }
        if (!is_array($order)) {
            return ['ok' => false, 'message' => 'Comanda nu a fost găsită.'];
        }
        if ((string) ($order['preorder_status'] ?? '') !== self::ASTEPTARE) {
            return ['ok' => false, 'message' => 'Comanda nu e în precomandă.'];
        }

        try {
            $db->prepare(
                'UPDATE orders SET preorder_status = :st, preorder_released_at = NOW() WHERE id = :id'
            )->execute(['st' => self::ELIBERATA, 'id' => $orderId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Nu am putut elibera comanda: ' . $e->getMessage()];
        }

        $rezultat = ErpSync::push($db, $orderId, true);
        return [
            'ok' => (bool) ($rezultat['ok'] ?? false),
            'message' => (string) ($rezultat['message'] ?? ''),
        ];
    }
}
