<?php

declare(strict_types=1);

namespace App\Support;

final class Cart
{
    public static function add(int $productId, int $quantity = 1, ?string $bbdKey = null): void
    {
        self::boot();
        if ($productId <= 0) {
            return;
        }
        $itemKey = self::itemKey($productId, $bbdKey);
        $_SESSION['cart']['items'][$itemKey] = (int) ($_SESSION['cart']['items'][$itemKey] ?? 0) + max(1, $quantity);
    }

    public static function update(int|string $itemRef, int $quantity): void
    {
        self::boot();
        $itemKey = self::resolveItemKey($itemRef);
        if ($itemKey === null) {
            return;
        }

        if ($quantity <= 0) {
            unset($_SESSION['cart']['items'][$itemKey]);
            return;
        }

        $_SESSION['cart']['items'][$itemKey] = $quantity;
    }

    public static function remove(int|string $itemRef): void
    {
        self::boot();
        $raw = trim((string) $itemRef);
        $parsed = self::parseItemKey($itemRef);
        $productId = (int) ($parsed['product_id'] ?? 0);
        if ($productId <= 0) {
            return;
        }
        // Backward-compatible behavior: numeric references remove all variants for a product.
        if ($raw !== '' && ctype_digit($raw) && !str_contains($raw, '__')) {
            foreach (array_keys((array) ($_SESSION['cart']['items'] ?? [])) as $candidateKey) {
                $candidateParsed = self::parseItemKey((string) $candidateKey);
                if ((int) ($candidateParsed['product_id'] ?? 0) === $productId) {
                    unset($_SESSION['cart']['items'][$candidateKey]);
                }
            }
            return;
        }
        $itemKey = self::resolveItemKey($itemRef);
        if ($itemKey !== null) {
            unset($_SESSION['cart']['items'][$itemKey]);
        }
    }

    public static function items(): array
    {
        self::boot();
        $_SESSION['cart']['items'] = self::normalizeItemsMap((array) ($_SESSION['cart']['items'] ?? []));
        return $_SESSION['cart']['items'];
    }

    public static function clear(): void
    {
        $_SESSION['cart'] = [
            'items' => [],
            'coupon' => null,
            'points_requested' => 0,
            'county' => 'Bucuresti',
        ];
    }

    public static function applyCoupon(string $code): void
    {
        self::boot();
        $_SESSION['cart']['coupon'] = strtoupper(trim($code));
    }

    public static function clearCoupon(): void
    {
        self::boot();
        $_SESSION['cart']['coupon'] = null;
    }

    public static function coupon(): ?string
    {
        self::boot();
        $coupon = $_SESSION['cart']['coupon'] ?? null;
        return $coupon === '' ? null : $coupon;
    }

    public static function requestPoints(int $points): void
    {
        self::boot();
        $_SESSION['cart']['points_requested'] = max(0, $points);
    }

    public static function clearPointsRequest(): void
    {
        self::boot();
        $_SESSION['cart']['points_requested'] = 0;
    }

    public static function pointsRequested(): int
    {
        self::boot();
        return max(0, (int) ($_SESSION['cart']['points_requested'] ?? 0));
    }

    public static function setCounty(string $county): void
    {
        self::boot();
        $_SESSION['cart']['county'] = trim($county) !== '' ? trim($county) : 'Bucuresti';
    }

    public static function county(): string
    {
        self::boot();
        return (string) ($_SESSION['cart']['county'] ?? 'Bucuresti');
    }

    public static function countItems(): int
    {
        self::boot();
        return array_sum(self::items());
    }

    public static function itemKey(int $productId, ?string $bbdKey = null): string
    {
        $safeProductId = max(0, $productId);
        $safeBbdKey = self::normalizeBbdKey($bbdKey);
        if ($safeBbdKey !== '') {
            return $safeProductId . '__' . $safeBbdKey;
        }
        return (string) $safeProductId;
    }

    public static function parseItemKey(int|string $itemRef): array
    {
        $raw = trim((string) $itemRef);
        if ($raw === '') {
            return ['product_id' => 0, 'bbd_key' => '', 'item_key' => ''];
        }
        if (preg_match('/^([1-9][0-9]*)(?:__([a-z0-9]{1,64}))?$/', strtolower($raw), $match) !== 1) {
            return ['product_id' => 0, 'bbd_key' => '', 'item_key' => ''];
        }
        $productId = (int) ($match[1] ?? 0);
        if ($productId <= 0) {
            return ['product_id' => 0, 'bbd_key' => '', 'item_key' => ''];
        }
        $bbdKey = self::normalizeBbdKey((string) ($match[2] ?? ''));
        return [
            'product_id' => $productId,
            'bbd_key' => $bbdKey,
            'item_key' => self::itemKey($productId, $bbdKey),
        ];
    }

    private static function boot(): void
    {
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [
                'items' => [],
                'coupon' => null,
                'points_requested' => 0,
                'county' => 'Bucuresti',
            ];
            return;
        }
        if (!is_array($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        if (!isset($_SESSION['cart']['items']) || !is_array($_SESSION['cart']['items'])) {
            $_SESSION['cart']['items'] = [];
        }
        if (!array_key_exists('coupon', $_SESSION['cart'])) {
            $_SESSION['cart']['coupon'] = null;
        }
        if (!array_key_exists('points_requested', $_SESSION['cart'])) {
            $_SESSION['cart']['points_requested'] = 0;
        }
        if (!array_key_exists('county', $_SESSION['cart']) || trim((string) $_SESSION['cart']['county']) === '') {
            $_SESSION['cart']['county'] = 'Bucuresti';
        }
    }

    private static function normalizeItemsMap(array $rawItems): array
    {
        $normalized = [];
        foreach ($rawItems as $rawKey => $rawQty) {
            $quantity = max(0, (int) $rawQty);
            if ($quantity <= 0) {
                continue;
            }
            $parsed = self::parseItemKey((string) $rawKey);
            $productId = (int) ($parsed['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $itemKey = (string) ($parsed['item_key'] ?? self::itemKey($productId));
            $normalized[$itemKey] = (int) ($normalized[$itemKey] ?? 0) + $quantity;
        }
        return $normalized;
    }

    private static function resolveItemKey(int|string $itemRef): ?string
    {
        $parsed = self::parseItemKey($itemRef);
        $productId = (int) ($parsed['product_id'] ?? 0);
        if ($productId <= 0) {
            return null;
        }
        $itemKey = (string) ($parsed['item_key'] ?? '');
        if ($itemKey !== '' && array_key_exists($itemKey, (array) ($_SESSION['cart']['items'] ?? []))) {
            return $itemKey;
        }
        // Fallback for legacy carts that stored only product id.
        $legacyKey = (string) $productId;
        if (array_key_exists($legacyKey, (array) ($_SESSION['cart']['items'] ?? []))) {
            return $legacyKey;
        }
        return null;
    }

    private static function normalizeBbdKey(?string $bbdKey): string
    {
        $raw = strtolower(trim((string) $bbdKey));
        if ($raw === '') {
            return '';
        }
        $safe = preg_replace('/[^a-z0-9]/', '', $raw) ?? '';
        if ($safe === '' || strlen($safe) > 64) {
            return '';
        }
        return $safe;
    }
}
