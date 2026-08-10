<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

final class LoyaltyService
{
    public const TX_ORDER_REDEEM = 'order_redeem';
    public const TX_ORDER_REDEEM_REFUND = 'order_redeem_refund';
    public const TX_ORDER_EARN = 'order_earn';
    public const TX_ORDER_EARN_REVERSAL = 'order_earn_reversal';
    public const TX_ADMIN_ADJUST = 'admin_adjust';

    public static function ensureSchema(PDO $db): void
    {
        try {
            $db->exec('ALTER TABLE users ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0 AFTER google_id');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN loyalty_points_used INT NOT NULL DEFAULT 0 AFTER discount_total');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN loyalty_points_discount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER loyalty_points_used');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN loyalty_points_awarded INT NOT NULL DEFAULT 0 AFTER loyalty_points_discount');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN loyalty_points_awarded_at DATETIME DEFAULT NULL AFTER loyalty_points_awarded');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN loyalty_points_pending_claim INT NOT NULL DEFAULT 0 AFTER loyalty_points_awarded');
        } catch (Throwable) {
        }
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS loyalty_points_transactions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    order_id INT UNSIGNED DEFAULT NULL,
                    admin_id INT UNSIGNED DEFAULT NULL,
                    tx_type VARCHAR(50) NOT NULL,
                    points_delta INT NOT NULL,
                    balance_after INT NOT NULL,
                    reason VARCHAR(255) DEFAULT NULL,
                    meta_json LONGTEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_user_created (user_id, created_at),
                    KEY idx_order_type (order_id, tx_type),
                    UNIQUE KEY uniq_user_order_type (user_id, order_id, tx_type)
                )'
            );
        } catch (Throwable) {
        }
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS loyalty_points_rules (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    description VARCHAR(255) DEFAULT NULL,
                    multiplier DECIMAL(6,2) NOT NULL DEFAULT 1.00,
                    min_order_total DECIMAL(10,2) DEFAULT NULL,
                    category_id INT UNSIGNED DEFAULT NULL,
                    starts_at DATETIME DEFAULT NULL,
                    ends_at DATETIME DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_rules_active_window (is_active, starts_at, ends_at)
                )'
            );
        } catch (Throwable) {
        }
    }

    public static function config(array $settings): array
    {
        $enabled = (string) ($settings['loyalty_points_enabled'] ?? '1') === '1';
        $earnRate = self::toFloat($settings['loyalty_points_earn_rate'] ?? '1', 1.0);
        $redeemValue = self::toFloat($settings['loyalty_points_redeem_value'] ?? '0.10', 0.10);
        $minRedeem = self::toInt($settings['loyalty_points_min_redeem'] ?? '100', 100);
        $maxRedeemPercent = self::toFloat($settings['loyalty_points_max_redeem_percent'] ?? '50', 50.0);
        $promoActive = (string) ($settings['loyalty_points_promo_active'] ?? '0') === '1';
        $promoMultiplier = self::toFloat($settings['loyalty_points_promo_multiplier'] ?? '1', 1.0);
        $promoWeekendMultiplier = self::toFloat($settings['loyalty_points_weekend_multiplier'] ?? '1', 1.0);

        return [
            'enabled' => $enabled,
            'earn_rate' => max(0.0, min(1000.0, $earnRate)),
            'redeem_value' => max(0.01, min(1000.0, $redeemValue)),
            'min_redeem' => max(0, min(1000000, $minRedeem)),
            'max_redeem_percent' => max(1.0, min(100.0, $maxRedeemPercent)),
            'promo_active' => $promoActive,
            'promo_multiplier' => max(1.0, min(100.0, $promoMultiplier)),
            'promo_weekend_multiplier' => max(1.0, min(100.0, $promoWeekendMultiplier)),
            'promo_weekend_enabled' => max(1.0, min(100.0, $promoWeekendMultiplier)) > 1.0,
        ];
    }

    public static function currentUserPoints(?PDO $db): int
    {
        if (!$db instanceof PDO) {
            return 0;
        }
        $userId = CustomerAuth::id();
        if ($userId === null || $userId <= 0) {
            return 0;
        }
        $stmt = $db->prepare('SELECT loyalty_points FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        return max(0, (int) ($stmt->fetchColumn() ?: 0));
    }

    public static function cartRedemptionSummary(?PDO $db, array $settings, float $subtotal, float $couponDiscount): array
    {
        $cfg = self::config($settings);
        $requested = max(0, Cart::pointsRequested());
        $available = self::currentUserPoints($db);
        $baseAmount = max(0.0, $subtotal - $couponDiscount);

        $summary = [
            'enabled' => $cfg['enabled'],
            'available' => $available,
            'requested' => $requested,
            'applied' => 0,
            'discount' => 0.0,
            'error' => null,
            'value_per_point' => (float) $cfg['redeem_value'],
            'min_redeem' => (int) $cfg['min_redeem'],
            'max_percent' => (float) $cfg['max_redeem_percent'],
            'max_points' => 0,
        ];

        if (!$cfg['enabled']) {
            return $summary;
        }
        if ($requested <= 0) {
            return $summary;
        }
        if (CustomerAuth::id() === null) {
            $summary['error'] = 'Trebuie să fii autentificat ca să folosești punctele.';
            return $summary;
        }
        if ($available <= 0) {
            $summary['error'] = 'Nu ai puncte disponibile.';
            return $summary;
        }
        if ($baseAmount <= 0) {
            $summary['error'] = 'Nu există valoare eligibilă pentru folosirea punctelor.';
            return $summary;
        }

        $maxAmountByPercent = $baseAmount * (((float) $cfg['max_redeem_percent']) / 100.0);
        $maxRedeemAmount = min($baseAmount, max(0.0, $maxAmountByPercent));
        $maxPointsByAmount = (int) floor($maxRedeemAmount / (float) $cfg['redeem_value']);
        $maxPoints = min($available, max(0, $maxPointsByAmount));
        $summary['max_points'] = $maxPoints;

        if ($maxPoints <= 0) {
            $summary['error'] = 'Nu poți folosi puncte pentru coșul curent.';
            return $summary;
        }
        if ($requested < (int) $cfg['min_redeem']) {
            $summary['error'] = 'Minimul de folosire este ' . (int) $cfg['min_redeem'] . ' puncte.';
            return $summary;
        }

        $applied = min($requested, $maxPoints);
        if ($applied <= 0) {
            $summary['error'] = 'Nu poți folosi puncte pentru coșul curent.';
            return $summary;
        }

        $summary['applied'] = $applied;
        $summary['discount'] = round($applied * (float) $cfg['redeem_value'], 2);
        return $summary;
    }

    public static function estimateEarnPoints(array $settings, float $eligibleAmount): int
    {
        $cfg = self::config($settings);
        if (!$cfg['enabled']) {
            return 0;
        }
        $base = max(0.0, $eligibleAmount);
        return max(0, (int) floor($base * (float) $cfg['earn_rate']));
    }

    public static function consumePointsForOrder(
        PDO $db,
        int $userId,
        int $orderId,
        int $pointsUsed,
        float $discountValue
    ): bool {
        if ($userId <= 0 || $orderId <= 0 || $pointsUsed <= 0) {
            return false;
        }
        if (self::hasTransaction($db, $userId, $orderId, self::TX_ORDER_REDEEM)) {
            return true;
        }

        $reason = 'Puncte folosite la comanda #' . $orderId;
        return self::applyPointsDelta(
            $db,
            $userId,
            -abs($pointsUsed),
            self::TX_ORDER_REDEEM,
            $reason,
            $orderId,
            null,
            ['discount_value' => round($discountValue, 2)]
        );
    }

    public static function refundRedeemedPointsForOrder(PDO $db, int $orderId, ?int $adminId = null): bool
    {
        $order = self::orderForPoints($db, $orderId);
        if (!is_array($order)) {
            return false;
        }
        $userId = (int) ($order['user_id'] ?? 0);
        $pointsUsed = max(0, (int) ($order['loyalty_points_used'] ?? 0));
        if ($userId <= 0 || $pointsUsed <= 0) {
            return false;
        }
        if (!self::hasTransaction($db, $userId, $orderId, self::TX_ORDER_REDEEM)) {
            return false;
        }
        if (self::hasTransaction($db, $userId, $orderId, self::TX_ORDER_REDEEM_REFUND)) {
            return false;
        }

        $reason = 'Refund puncte folosite pentru comanda #' . ((string) ($order['order_number'] ?? $orderId));
        return self::applyPointsDelta(
            $db,
            $userId,
            $pointsUsed,
            self::TX_ORDER_REDEEM_REFUND,
            $reason,
            $orderId,
            $adminId
        );
    }

    public static function awardPointsForCompletedOrder(PDO $db, array $settings, int $orderId, ?int $adminId = null): bool
    {
        $order = self::orderForPoints($db, $orderId);
        if (!is_array($order)) {
            return false;
        }
        if ((string) ($order['status'] ?? '') !== 'completed') {
            return false;
        }

        $eligibleAmount = max(
            0.0,
            (float) ($order['subtotal'] ?? 0)
                - (float) ($order['discount_total'] ?? 0)
                - (float) ($order['loyalty_points_discount'] ?? 0)
        );
        $cfg = self::config($settings);
        $promo = self::resolvePromotionForOrder($db, $orderId, $eligibleAmount, $cfg);
        $effectiveRate = (float) ($cfg['earn_rate'] ?? 1.0) * (float) ($promo['multiplier'] ?? 1.0);
        $points = max(0, (int) floor($eligibleAmount * $effectiveRate));
        if ($points <= 0) {
            return false;
        }

        $userId = (int) ($order['user_id'] ?? 0);
        $billingEmail = trim((string) ($order['billing_email'] ?? ''));
        if ($userId > 0) {
            if (self::hasTransaction($db, $userId, $orderId, self::TX_ORDER_EARN)) {
                return false;
            }
        } else {
            $pending = max(0, (int) ($order['loyalty_points_pending_claim'] ?? 0));
            if ($pending > 0) {
                return false;
            }
            if ($billingEmail === '' || !filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
        }

        if ($userId <= 0) {
            $stmt = $db->prepare(
                'UPDATE orders
                 SET loyalty_points_pending_claim = :points
                 WHERE id = :id AND deleted_at IS NULL'
            );
            return $stmt->execute([
                'points' => $points,
                'id' => (int) ($order['id'] ?? $orderId),
            ]);
        }

        $reason = 'Puncte acordate pentru comanda #' . ((string) ($order['order_number'] ?? $orderId));
        $ok = self::applyPointsDelta(
            $db,
            $userId,
            $points,
            self::TX_ORDER_EARN,
            $reason,
            $orderId,
            $adminId,
            [
                'eligible_amount' => round($eligibleAmount, 2),
                'earn_rate_effective' => round($effectiveRate, 4),
                'promo_rule_id' => (int) ($promo['rule']['id'] ?? 0),
                'promo_rule_name' => (string) ($promo['rule']['name'] ?? ''),
                'promo_multiplier' => round((float) ($promo['multiplier'] ?? 1.0), 2),
            ]
        );

        if ($ok) {
            $stmt = $db->prepare(
                'UPDATE orders
                 SET loyalty_points_awarded = :points,
                     loyalty_points_awarded_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'points' => $points,
                'id' => $orderId,
            ]);
        }

        return $ok;
    }

    public static function claimPendingPointsByEmail(PDO $db, int $userId, string $email): int
    {
        $userId = max(0, $userId);
        $email = strtolower(trim($email));
        if ($userId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }

        $stmt = $db->prepare(
            'SELECT id, order_number, loyalty_points_pending_claim
             FROM orders
             WHERE deleted_at IS NULL
               AND user_id IS NULL
               AND billing_email = :email
               AND loyalty_points_pending_claim > 0
             ORDER BY id ASC'
        );
        $stmt->execute(['email' => $email]);
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return 0;
        }

        $totalClaimed = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = (int) ($row['id'] ?? 0);
            $points = max(0, (int) ($row['loyalty_points_pending_claim'] ?? 0));
            if ($orderId <= 0 || $points <= 0) {
                continue;
            }
            if (self::hasTransaction($db, $userId, $orderId, self::TX_ORDER_EARN)) {
                continue;
            }

            $reason = 'Puncte revendicate după creare cont pentru comanda #' . ((string) ($row['order_number'] ?? $orderId));
            $ok = self::applyPointsDelta(
                $db,
                $userId,
                $points,
                self::TX_ORDER_EARN,
                $reason,
                $orderId,
                null,
                ['claimed_after_registration' => true, 'billing_email' => $email]
            );
            if (!$ok) {
                continue;
            }

            $update = $db->prepare(
                'UPDATE orders
                 SET user_id = :user_id,
                     loyalty_points_awarded = :points,
                     loyalty_points_awarded_at = NOW(),
                     loyalty_points_pending_claim = 0
                 WHERE id = :id AND deleted_at IS NULL'
            );
            $update->execute([
                'user_id' => $userId,
                'points' => $points,
                'id' => $orderId,
            ]);
            $totalClaimed += $points;
        }

        return $totalClaimed;
    }

    public static function reverseAwardedPointsForOrder(PDO $db, int $orderId, ?int $adminId = null): bool
    {
        $order = self::orderForPoints($db, $orderId);
        if (!is_array($order)) {
            return false;
        }
        $userId = (int) ($order['user_id'] ?? 0);
        if ($userId <= 0) {
            $pending = max(0, (int) ($order['loyalty_points_pending_claim'] ?? 0));
            if ($pending <= 0) {
                return false;
            }
            $stmt = $db->prepare(
                'UPDATE orders
                 SET loyalty_points_pending_claim = 0,
                     loyalty_points_awarded = 0,
                     loyalty_points_awarded_at = NULL
                 WHERE id = :id AND deleted_at IS NULL'
            );
            return $stmt->execute(['id' => $orderId]);
        }
        if (!self::hasTransaction($db, $userId, $orderId, self::TX_ORDER_EARN)) {
            return false;
        }
        if (self::hasTransaction($db, $userId, $orderId, self::TX_ORDER_EARN_REVERSAL)) {
            return false;
        }

        $points = max(0, (int) ($order['loyalty_points_awarded'] ?? 0));
        if ($points <= 0) {
            $points = self::sumPointsForOrderType($db, $userId, $orderId, self::TX_ORDER_EARN);
        }
        if ($points <= 0) {
            return false;
        }

        $reason = 'Anulare puncte acordate pentru comanda #' . ((string) ($order['order_number'] ?? $orderId));
        $ok = self::applyPointsDelta(
            $db,
            $userId,
            -abs($points),
            self::TX_ORDER_EARN_REVERSAL,
            $reason,
            $orderId,
            $adminId
        );
        if ($ok) {
            $stmt = $db->prepare(
                'UPDATE orders
                 SET loyalty_points_awarded = 0,
                     loyalty_points_awarded_at = NULL
                 WHERE id = :id'
            );
            $stmt->execute(['id' => $orderId]);
        }

        return $ok;
    }

    public static function adjustUserPoints(PDO $db, int $userId, int $delta, string $reason, ?int $adminId = null): bool
    {
        if ($userId <= 0 || $delta === 0) {
            return false;
        }
        return self::applyPointsDelta(
            $db,
            $userId,
            $delta,
            self::TX_ADMIN_ADJUST,
            trim($reason) !== '' ? trim($reason) : 'Ajustare manuală puncte',
            null,
            $adminId
        );
    }

    public static function recentTransactions(PDO $db, int $limit = 120): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT t.id, t.user_id, t.order_id, t.admin_id, t.tx_type, t.points_delta, t.balance_after, t.reason, t.created_at,
                       u.first_name, u.last_name, u.email,
                       o.order_number, o.subtotal, o.total, o.loyalty_points_discount,
                       a.email AS admin_email
                FROM loyalty_points_transactions t
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN orders o ON o.id = t.order_id
                LEFT JOIN admins a ON a.id = t.admin_id
                ORDER BY t.id DESC
                LIMIT ' . $limit;
        return $db->query($sql)->fetchAll() ?: [];
    }

    public static function userTransactions(PDO $db, int $userId, int $limit = 120): array
    {
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $stmt = $db->prepare(
            'SELECT t.id, t.user_id, t.order_id, t.tx_type, t.points_delta, t.balance_after, t.reason, t.created_at,
                    o.order_number
             FROM loyalty_points_transactions t
             LEFT JOIN orders o ON o.id = t.order_id
             WHERE t.user_id = :user_id
             ORDER BY t.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function promoRules(PDO $db): array
    {
        $sql = 'SELECT id, name, description, multiplier, min_order_total, category_id, starts_at, ends_at, is_active, created_at
                FROM loyalty_points_rules
                ORDER BY is_active DESC, id DESC';
        return $db->query($sql)->fetchAll() ?: [];
    }

    public static function savePromoRule(PDO $db, array $payload): bool
    {
        $id = max(0, (int) ($payload['id'] ?? 0));
        $name = trim((string) ($payload['name'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $multiplier = max(0.01, self::toFloat($payload['multiplier'] ?? 1, 1.0));
        $minOrderTotalRaw = trim((string) ($payload['min_order_total'] ?? ''));
        $minOrderTotal = ($minOrderTotalRaw !== '' && is_numeric(str_replace(',', '.', $minOrderTotalRaw)))
            ? (float) str_replace(',', '.', $minOrderTotalRaw)
            : null;
        $categoryId = max(0, (int) ($payload['category_id'] ?? 0));
        $startsAt = self::normalizeDateTimeInput((string) ($payload['starts_at'] ?? ''));
        $endsAt = self::normalizeDateTimeInput((string) ($payload['ends_at'] ?? ''));
        $isActive = !empty($payload['is_active']) ? 1 : 0;

        if ($name === '') {
            return false;
        }
        if ($startsAt !== null && $endsAt !== null && strtotime($startsAt) > strtotime($endsAt)) {
            return false;
        }

        if ($id > 0) {
            $stmt = $db->prepare(
                'UPDATE loyalty_points_rules
                 SET name = :name,
                     description = :description,
                     multiplier = :multiplier,
                     min_order_total = :min_order_total,
                     category_id = :category_id,
                     starts_at = :starts_at,
                     ends_at = :ends_at,
                     is_active = :is_active
                 WHERE id = :id'
            );
            return $stmt->execute([
                'id' => $id,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'multiplier' => $multiplier,
                'min_order_total' => $minOrderTotal,
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'is_active' => $isActive,
            ]);
        }

        $stmt = $db->prepare(
            'INSERT INTO loyalty_points_rules (
                name, description, multiplier, min_order_total, category_id, starts_at, ends_at, is_active
             ) VALUES (
                :name, :description, :multiplier, :min_order_total, :category_id, :starts_at, :ends_at, :is_active
             )'
        );
        return $stmt->execute([
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'multiplier' => $multiplier,
            'min_order_total' => $minOrderTotal,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => $isActive,
        ]);
    }

    public static function deletePromoRule(PDO $db, int $ruleId): bool
    {
        if ($ruleId <= 0) {
            return false;
        }
        $stmt = $db->prepare('DELETE FROM loyalty_points_rules WHERE id = :id');
        return $stmt->execute(['id' => $ruleId]);
    }

    public static function activePromoRules(PDO $db): array
    {
        $rules = self::promoRules($db);
        $now = time();
        $active = [];
        foreach ($rules as $rule) {
            if ((int) ($rule['is_active'] ?? 0) !== 1) {
                continue;
            }
            $startsAt = trim((string) ($rule['starts_at'] ?? ''));
            $endsAt = trim((string) ($rule['ends_at'] ?? ''));
            if ($startsAt !== '' && strtotime($startsAt) > $now) {
                continue;
            }
            if ($endsAt !== '' && strtotime($endsAt) < $now) {
                continue;
            }
            $active[] = $rule;
        }
        return $active;
    }

    private static function applyPointsDelta(
        PDO $db,
        int $userId,
        int $delta,
        string $type,
        string $reason = '',
        ?int $orderId = null,
        ?int $adminId = null,
        array $meta = []
    ): bool {
        if ($userId <= 0 || $delta === 0) {
            return false;
        }

        $startedTx = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $startedTx = true;
        }

        try {
            $select = $db->prepare('SELECT loyalty_points FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
            $select->execute(['id' => $userId]);
            $current = $select->fetchColumn();
            if ($current === false) {
                if ($startedTx) {
                    $db->rollBack();
                }
                return false;
            }
            $currentPoints = (int) $current;
            $nextPoints = $currentPoints + $delta;
            if ($nextPoints < 0) {
                if ($startedTx) {
                    $db->rollBack();
                }
                return false;
            }

            $update = $db->prepare('UPDATE users SET loyalty_points = :points WHERE id = :id');
            $update->execute([
                'points' => $nextPoints,
                'id' => $userId,
            ]);

            $insert = $db->prepare(
                'INSERT INTO loyalty_points_transactions (
                    user_id, order_id, admin_id, tx_type, points_delta, balance_after, reason, meta_json
                 ) VALUES (
                    :user_id, :order_id, :admin_id, :tx_type, :points_delta, :balance_after, :reason, :meta_json
                 )'
            );
            $insert->execute([
                'user_id' => $userId,
                'order_id' => $orderId,
                'admin_id' => $adminId,
                'tx_type' => $type,
                'points_delta' => $delta,
                'balance_after' => $nextPoints,
                'reason' => trim($reason) !== '' ? trim($reason) : null,
                'meta_json' => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);

            if ($startedTx) {
                $db->commit();
            }
            return true;
        } catch (Throwable) {
            if ($startedTx && $db->inTransaction()) {
                $db->rollBack();
            }
            return false;
        }
    }

    private static function hasTransaction(PDO $db, int $userId, int $orderId, string $type): bool
    {
        if ($userId <= 0 || $orderId <= 0 || $type === '') {
            return false;
        }
        $stmt = $db->prepare(
            'SELECT id
             FROM loyalty_points_transactions
             WHERE user_id = :user_id
               AND order_id = :order_id
               AND tx_type = :tx_type
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'order_id' => $orderId,
            'tx_type' => $type,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private static function sumPointsForOrderType(PDO $db, int $userId, int $orderId, string $type): int
    {
        if ($userId <= 0 || $orderId <= 0 || $type === '') {
            return 0;
        }
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(points_delta), 0)
             FROM loyalty_points_transactions
             WHERE user_id = :user_id
               AND order_id = :order_id
               AND tx_type = :tx_type'
        );
        $stmt->execute([
            'user_id' => $userId,
            'order_id' => $orderId,
            'tx_type' => $type,
        ]);
        return abs((int) ($stmt->fetchColumn() ?: 0));
    }

    private static function orderForPoints(PDO $db, int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT id, order_number, user_id, status, payment_status, subtotal, discount_total,
                    loyalty_points_used, loyalty_points_discount, loyalty_points_awarded,
                    loyalty_points_pending_claim, billing_email
             FROM orders
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch() ?: null;
        return is_array($row) ? $row : null;
    }

    private static function resolvePromotionForOrder(PDO $db, int $orderId, float $eligibleAmount, array $config): array
    {
        $bestMultiplier = 1.0;
        $bestRule = null;
        $bestSource = 'standard';

        if (!empty($config['promo_active'])) {
            $bestMultiplier = max(1.0, (float) ($config['promo_multiplier'] ?? 1.0));
            $bestSource = $bestMultiplier > 1.0 ? 'global_promo' : 'standard';
        }

        $weekendMultiplier = max(1.0, (float) ($config['promo_weekend_multiplier'] ?? 1.0));
        if ((int) date('N') >= 6 && $weekendMultiplier > $bestMultiplier) {
            $bestMultiplier = $weekendMultiplier;
            $bestSource = 'weekend_promo';
        }

        $rules = self::activePromoRules($db);

        foreach ($rules as $rule) {
            $multiplier = max(0.01, (float) ($rule['multiplier'] ?? 1.0));
            if ($multiplier < 1.0) {
                continue;
            }

            $minOrderTotal = $rule['min_order_total'] ?? null;
            if ($minOrderTotal !== null && (float) $minOrderTotal > 0 && $eligibleAmount < (float) $minOrderTotal) {
                continue;
            }

            $categoryId = max(0, (int) ($rule['category_id'] ?? 0));
            if ($categoryId > 0 && !self::orderHasCategory($db, $orderId, $categoryId)) {
                continue;
            }

            if ($multiplier > $bestMultiplier) {
                $bestMultiplier = $multiplier;
                $bestRule = $rule;
                $bestSource = 'rule';
            }
        }

        return [
            'multiplier' => $bestMultiplier,
            'rule' => $bestRule,
            'source' => $bestSource,
        ];
    }

    private static function orderHasCategory(PDO $db, int $orderId, int $categoryId): bool
    {
        if ($orderId <= 0 || $categoryId <= 0) {
            return false;
        }
        $stmt = $db->prepare(
            'SELECT oi.id
             FROM order_items oi
             INNER JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :order_id
               AND p.category_id = :category_id
             LIMIT 1'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'category_id' => $categoryId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private static function normalizeDateTimeInput(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $normalized = str_replace('T', ' ', $raw);
        $timestamp = strtotime($normalized);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private static function toFloat(mixed $value, float $fallback): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $raw = str_replace(',', '.', trim((string) $value));
        if ($raw === '' || !is_numeric($raw)) {
            return $fallback;
        }
        return (float) $raw;
    }

    private static function toInt(mixed $value, int $fallback): int
    {
        if (is_int($value)) {
            return $value;
        }
        $raw = trim((string) $value);
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            return $fallback;
        }
        return (int) $raw;
    }
}
