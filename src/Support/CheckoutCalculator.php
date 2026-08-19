<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;
use Throwable;

final class CheckoutCalculator
{
    /** Cheia din sesiune în care se ține punctul FANbox ales de client. */
    public const FANBOX_SESSION_KEY = 'checkout_fan_locker';

    /** Modul de livrare ales, independent de punctul concret. */
    private const FANBOX_INTENT_KEY = 'checkout_fanbox_mod';

    /**
     * Localitatea completată în checkout.
     *
     * Taxa de km suplimentari depinde de localitate, nu doar de județ. Fără ea,
     * sumarul arăta prețul de bază (19 lei), iar la finalizare se adăuga taxa
     * (încă 11 lei) — clientul vedea un total și plătea altul.
     */
    private const LOCALITATE_KEY = 'checkout_localitate';

    /**
     * Clientul a ales livrarea la FANbox pentru comanda curentă?
     *
     * Alegerea trăiește în sesiune cât timp se completează checkout-ul, ca
     * sumarul și prețul transportului să se potrivească cu ce vede clientul
     * pe ecran înainte de a trimite comanda.
     */
    public static function livrareAleasaLaFanbox(): bool
    {
        // Prețul îl dă alegerea modului de livrare, nu punctul concret: clientul
        // bifează „Livrare la FANbox" înainte să apuce să aleagă lockerul, iar
        // până atunci sumarul arăta tariful de livrare la adresă.
        return !empty($_SESSION[self::FANBOX_INTENT_KEY])
            || (int) ($_SESSION[self::FANBOX_SESSION_KEY] ?? 0) > 0;
    }

    /** Id-ul punctului FANbox ales (0 = niciunul ales încă). */
    public static function fanboxAles(): int
    {
        return max(0, (int) ($_SESSION[self::FANBOX_SESSION_KEY] ?? 0));
    }

    /**
     * Reține alegerea clientului. `$laFanbox` e modul de livrare (bifa), iar
     * `$lockerId` punctul concret — pot exista separat, fiindcă bifa se pune
     * prima. `null` la `$laFanbox` păstrează modul dedus din id, pentru
     * apelurile vechi care trimit doar lockerul.
     */
    /** Reține localitatea din formular, pentru calculul taxei de km. */
    public static function setLocalitate(string $localitate): void
    {
        $valoare = trim($localitate);
        if ($valoare === '') {
            unset($_SESSION[self::LOCALITATE_KEY]);
            return;
        }
        $_SESSION[self::LOCALITATE_KEY] = mb_substr($valoare, 0, 190);
    }

    public static function localitate(): string
    {
        return trim((string) ($_SESSION[self::LOCALITATE_KEY] ?? ''));
    }

    public static function alegeFanbox(int $lockerId, ?bool $laFanbox = null): void
    {
        $activ = $laFanbox ?? ($lockerId > 0);
        if ($activ) {
            $_SESSION[self::FANBOX_INTENT_KEY] = true;
        } else {
            unset($_SESSION[self::FANBOX_INTENT_KEY]);
        }
        if ($lockerId > 0 && $activ) {
            $_SESSION[self::FANBOX_SESSION_KEY] = $lockerId;
            return;
        }
        unset($_SESSION[self::FANBOX_SESSION_KEY]);
    }

    public static function buildSummary(?PDO $db, array $settings): array
    {
        $cartMap = Cart::items();
        if ($cartMap === []) {
            return [
                'lines' => [],
                'subtotal' => 0.0,
                'subtotal_without_vat' => 0.0,
                'discount' => 0.0,
                'vat' => 0.0,
                'vat_additional' => 0.0,
                'shipping' => 0.0,
                'total' => 0.0,
                'coupon' => null,
                'coupon_error' => null,
                'county' => Cart::county(),
                'total_weight_kg' => 0.0,
            ];
        }

        $cartItems = [];
        $productIds = [];
        foreach ($cartMap as $rawItemKey => $rawQuantity) {
            $parsed = Cart::parseItemKey((string) $rawItemKey);
            $productId = (int) ($parsed['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $quantity = max(1, (int) $rawQuantity);
            $bbdKey = (string) ($parsed['bbd_key'] ?? '');
            $itemKey = (string) ($parsed['item_key'] ?? Cart::itemKey($productId, $bbdKey));
            $cartItems[] = [
                'item_key' => $itemKey,
                'product_id' => $productId,
                'bbd_key' => $bbdKey,
                'quantity' => $quantity,
            ];
            $productIds[$productId] = $productId;
        }
        if ($cartItems === []) {
            return [
                'lines' => [],
                'subtotal' => 0.0,
                'subtotal_without_vat' => 0.0,
                'discount' => 0.0,
                'vat' => 0.0,
                'vat_additional' => 0.0,
                'shipping' => 0.0,
                'total' => 0.0,
                'coupon' => null,
                'coupon_error' => null,
                'county' => Cart::county(),
                'total_weight_kg' => 0.0,
            ];
        }

        $products = self::fetchProducts($db, array_values($productIds));
        $productMap = [];
        foreach ($products as $productRow) {
            if (!is_array($productRow)) {
                continue;
            }
            $productId = (int) ($productRow['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $productMap[$productId] = $productRow;
        }
        $lines = [];
        $subtotal = 0.0;
        $subtotalWithoutVat = 0.0;
        $vatTotal = 0.0;
        $vatAdditional = 0.0;
        $totalWeightGrams = 0;
        $nowTs = (new DateTimeImmutable('now'))->getTimestamp();

        foreach ($cartItems as $cartItem) {
            if (!is_array($cartItem)) {
                continue;
            }
            $id = (int) ($cartItem['product_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $product = $productMap[$id] ?? null;
            if (!is_array($product)) {
                continue;
            }
            $qty = max(1, (int) ($cartItem['quantity'] ?? 1));
            $bbdSelection = self::resolveBbdSelection($product, (string) ($cartItem['bbd_key'] ?? ''));
            $price = self::effectiveProductPrice(
                $product,
                $nowTs,
                $bbdSelection['reduced_price'] ?? null
            );
            $lineTotal = $price * $qty;
            $vatPercent = max(0.0, min(100.0, (float) ($product['vat_percent'] ?? 19.0)));
            $vatIncluded = ((int) ($product['vat_included'] ?? 1)) === 1;
            $lineVat = $vatPercent > 0
                ? (
                    $vatIncluded
                        ? ($lineTotal - ($lineTotal / (1.0 + ($vatPercent / 100.0))))
                        : ($lineTotal * ($vatPercent / 100.0))
                )
                : 0.0;
            $subtotal += $lineTotal;
            $vatTotal += $lineVat;
            $subtotalWithoutVat += $vatIncluded ? max(0.0, $lineTotal - $lineVat) : $lineTotal;
            if (!$vatIncluded) {
                $vatAdditional += $lineVat;
            }
            $weightGrams = max(0, (int) ($product['weight_grams'] ?? 0));
            $totalWeightGrams += ($weightGrams * $qty);

            $lines[] = [
                'id' => $id,
                'cart_item_key' => (string) ($cartItem['item_key'] ?? Cart::itemKey($id, (string) ($cartItem['bbd_key'] ?? ''))),
                'name' => (string) $product['name'],
                'slug' => (string) $product['slug'],
                'category_id' => max(0, (int) ($product['category_id'] ?? 0)),
                'extra_category_ids' => array_map('intval', (array) ($product['extra_category_ids'] ?? [])),
                // Plafonul de vânzare; null când stocul nu vine din ERP.
                'stoc_maxim' => ((int) ($product['stock_from_erp'] ?? 0) === 1)
                    ? max(0, (int) ($product['stock'] ?? 0))
                    : null,
                'short_description' => trim((string) ($product['short_description'] ?? '')),
                'bbd_key' => (string) ($bbdSelection['key'] ?? ''),
                'bbd_date' => (string) ($bbdSelection['date'] ?? ''),
                'bbd_label' => (string) ($bbdSelection['label'] ?? ''),
                'bbd_reduced_price' => isset($bbdSelection['reduced_price']) ? (float) $bbdSelection['reduced_price'] : null,
                'bbd_stock' => isset($bbdSelection['stock']) && is_numeric((string) $bbdSelection['stock'])
                    ? max(0, (int) $bbdSelection['stock'])
                    : null,
                'price' => $price,
                'quantity' => $qty,
                'line_total' => $lineTotal,
                // Produsul are o reducere/promoție activă dacă prețul efectiv (sale,
                // perioadă programată sau BBD) e sub prețul de bază.
                'is_discounted' => $price < (max(0.0, (float) ($product['price'] ?? 0.0)) - 0.0001),
                'image_url' => trim((string) ($product['image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg',
                'vat_percent' => $vatPercent,
                'vat_included' => $vatIncluded,
                'vat_value' => $lineVat,
                'weight_grams' => $weightGrams,
                'coupon_discount' => 0.0,
                'line_total_after_coupon' => $lineTotal,
            ];
        }

        $couponCode = Cart::coupon();
        $discount = 0.0;
        $couponError = null;
        $coupon = null;
        $points = null;
        $pointsDiscount = 0.0;

        if ($couponCode !== null && $couponCode !== '') {
            $coupon = self::validateCoupon($db, $couponCode, $lines);
            if ($coupon === null) {
                $couponError = 'Cupon invalid sau inactiv.';
            } else {
                $discountBase = self::couponDiscountBaseSubtotal($coupon, $subtotal);
                $discount = self::calculateDiscount($coupon, $discountBase);
                $couponDistribution = self::applyCouponDiscountsToLines($lines, $coupon, $discount);
                $lines = $couponDistribution['lines'];
                $discount = (float) ($couponDistribution['total_discount'] ?? $discount);
                $coupon['line_discounts'] = $couponDistribution['line_discounts'];
            }
        }

        $points = LoyaltyService::cartRedemptionSummary($db, $settings, $subtotal, $discount);
        $pointsDiscount = (float) ($points['discount'] ?? 0.0);

        $county = Cart::county();
        $shippingDiscountReference = $discount + $pointsDiscount;
        $shipping = self::calculateShipping($db, $settings, $county, $subtotal, $shippingDiscountReference);
        $isBucharest = mb_strtolower(trim($county)) === 'bucuresti';
        $includeCoupons = ((string) ($settings['shipping_include_coupons'] ?? '1')) === '1';
        $shippingFreeThreshold = $isBucharest
            ? (float) ($settings['shipping_free_bucharest'] ?? 200)
            : (float) ($settings['shipping_free_province'] ?? 200);
        $shippingReference = $includeCoupons
            ? max(0.0, $subtotal - $shippingDiscountReference)
            : max(0.0, $subtotal);
        $total = max(0, $subtotal - $discount - $pointsDiscount + $shipping + $vatAdditional);

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'subtotal_without_vat' => $subtotalWithoutVat,
            'discount' => $discount,
            'points_discount' => $pointsDiscount,
            'vat' => $vatTotal,
            'vat_additional' => $vatAdditional,
            'shipping' => $shipping,
            'total' => $total,
            'coupon' => $coupon,
            'coupon_error' => $couponError,
            'points' => $points,
            'county' => $county,
            'shipping_reference' => $shippingReference,
            'shipping_free_threshold' => max(0.0, $shippingFreeThreshold),
            'total_weight_kg' => $totalWeightGrams > 0 ? ($totalWeightGrams / 1000) : 0.0,
        ];
    }

    private static function fetchProducts(?PDO $db, array $ids): array
    {
        if (!$db instanceof PDO || $ids === []) {
            return [];
        }
        $safeIds = array_map('intval', $ids);
        $safeIds = array_filter($safeIds, static fn (int $id): bool => $id > 0);
        if ($safeIds === []) {
            return [];
        }

        self::ensureProductVatSchema($db);

        $placeholders = implode(',', array_fill(0, count($safeIds), '?'));
        try {
            $stmt = $db->prepare(
                "SELECT id, name, slug, category_id, short_description, price, sale_price, sale_price_periods_json, bbd_enabled, bbd_entries_json, vat_percent, vat_included, stock, out_of_stock, weight_grams, image_url
                 FROM products
                 WHERE is_active = 1 AND deleted_at IS NULL AND out_of_stock = 0 AND id IN ($placeholders)"
            );
            $stmt->execute($safeIds);
        } catch (Throwable) {
            $stmt = $db->prepare(
                "SELECT id, name, slug, category_id, NULL AS short_description, price, NULL AS sale_price, NULL AS sale_price_periods_json, 0 AS bbd_enabled, NULL AS bbd_entries_json, 19.00 AS vat_percent, 1 AS vat_included, stock, 0 AS out_of_stock, weight_grams, NULL AS image_url
                 FROM products
                 WHERE is_active = 1 AND deleted_at IS NULL AND id IN ($placeholders)"
            );
            $stmt->execute($safeIds);
        }

        $produse = $stmt->fetchAll();
        // Disponibilul real din gestiune, pentru plafonarea cantității în coș.
        ErpStock::applyToProducts($db, $produse);
        // Categoriile suplimentare, pentru cupoanele restrânse pe categorii.
        $extraIds = ProductCategories::idsForProducts($db, $safeIds);
        foreach ($produse as &$produs) {
            if (is_array($produs)) {
                $produs['extra_category_ids'] = $extraIds[(int) ($produs['id'] ?? 0)] ?? [];
            }
        }
        unset($produs);

        return $produse;
    }

    private static bool $orderShippingSchemaEnsured = false;

    /** Coloane pentru adresa de livrare separată de facturare (pe comenzi). */
    public static function ensureOrderShippingSchema(PDO $db): void
    {
        if (self::$orderShippingSchemaEnsured) {
            return;
        }
        self::$orderShippingSchemaEnsured = true;
        $cols = [
            "ALTER TABLE orders ADD COLUMN shipping_same_as_billing TINYINT(1) NOT NULL DEFAULT 1 AFTER billing_company_registration_no",
            "ALTER TABLE orders ADD COLUMN shipping_first_name VARCHAR(190) DEFAULT NULL AFTER shipping_same_as_billing",
            "ALTER TABLE orders ADD COLUMN shipping_last_name VARCHAR(190) DEFAULT NULL AFTER shipping_first_name",
            "ALTER TABLE orders ADD COLUMN shipping_phone VARCHAR(50) DEFAULT NULL AFTER shipping_last_name",
            "ALTER TABLE orders ADD COLUMN shipping_address_line1 VARCHAR(255) DEFAULT NULL AFTER shipping_phone",
            "ALTER TABLE orders ADD COLUMN shipping_city VARCHAR(190) DEFAULT NULL AFTER shipping_address_line1",
            "ALTER TABLE orders ADD COLUMN shipping_county VARCHAR(190) DEFAULT NULL AFTER shipping_city",
            "ALTER TABLE orders ADD COLUMN shipping_postcode VARCHAR(20) DEFAULT NULL AFTER shipping_county",
            // Livrarea la FANbox: punctul ales se îngheață pe comandă, nu doar
            // ca id — nomenclatorul se reimportă, comanda trebuie să rămână
            // explicabilă peste un an.
            "ALTER TABLE orders ADD COLUMN fan_locker_id INT UNSIGNED DEFAULT NULL AFTER shipping_postcode",
            "ALTER TABLE orders ADD COLUMN fan_locker_name VARCHAR(190) DEFAULT NULL AFTER fan_locker_id",
            "ALTER TABLE orders ADD COLUMN fan_locker_address VARCHAR(255) DEFAULT NULL AFTER fan_locker_name",
            "ALTER TABLE orders ADD COLUMN fan_locker_city VARCHAR(190) DEFAULT NULL AFTER fan_locker_address",
            "ALTER TABLE orders ADD COLUMN fan_locker_county VARCHAR(190) DEFAULT NULL AFTER fan_locker_city",
            "ALTER TABLE orders ADD COLUMN fan_locker_postcode VARCHAR(20) DEFAULT NULL AFTER fan_locker_county",
        ];
        foreach ($cols as $sql) {
            try {
                $db->exec($sql);
            } catch (Throwable) {
            }
        }
    }

    private static bool $productSchemaEnsured = false;

    public static function ensureProductVatSchema(PDO $db): void
    {
        if (self::$productSchemaEnsured) {
            return;
        }
        self::$productSchemaEnsured = true;
        try {
            $db->exec('ALTER TABLE products ADD COLUMN vat_percent DECIMAL(5,2) NOT NULL DEFAULT 19.00 AFTER price');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE products ADD COLUMN vat_included TINYINT(1) NOT NULL DEFAULT 1 AFTER vat_percent');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE products ADD COLUMN sale_price_periods_json LONGTEXT DEFAULT NULL AFTER sale_price');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE products ADD COLUMN bbd_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER sale_price_periods_json');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE products ADD COLUMN bbd_entries_json LONGTEXT DEFAULT NULL AFTER bbd_enabled');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE products ADD COLUMN out_of_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER stock');
        } catch (Throwable) {
        }
        try {
            $db->exec("ALTER TABLE products ADD COLUMN discount_badge_mode VARCHAR(10) NOT NULL DEFAULT 'percent' AFTER sale_price_periods_json");
        } catch (Throwable) {
        }
    }

    private static function effectiveProductPrice(array $product, int $nowTs, mixed $bbdReducedPriceRaw = null): float
    {
        $basePrice = max(0.0, (float) ($product['price'] ?? 0.0));
        $saleRaw = $product['sale_price'] ?? null;
        $salePrice = null;
        if ($saleRaw !== null && $saleRaw !== '') {
            $candidate = max(0.0, (float) $saleRaw);
            if ($candidate > 0.0) {
                $salePrice = $candidate;
            }
        }

        $periodRaw = (string) ($product['sale_price_periods_json'] ?? '');
        if (trim($periodRaw) !== '') {
            $decoded = json_decode($periodRaw, true);
            if (is_array($decoded)) {
                $activePeriodSale = null;
                foreach ($decoded as $period) {
                    if (!is_array($period)) {
                        continue;
                    }
                    $startTs = strtotime((string) ($period['start_date'] ?? ''));
                    $endTs = strtotime((string) ($period['end_date'] ?? ''));
                    $periodSale = max(0.0, (float) ($period['sale_price'] ?? 0.0));
                    if ($startTs === false || $endTs === false || $periodSale <= 0.0) {
                        continue;
                    }
                    if ($nowTs < $startTs || $nowTs > $endTs) {
                        continue;
                    }
                    if ($activePeriodSale === null || $periodSale < $activePeriodSale) {
                        $activePeriodSale = $periodSale;
                    }
                }
                if ($activePeriodSale !== null) {
                    $salePrice = $activePeriodSale;
                }
            }
        }

        if ($salePrice === null) {
            $effective = $basePrice;
        } elseif ($basePrice <= 0.0) {
            $effective = $salePrice;
        } else {
            $effective = min($basePrice, $salePrice);
        }

        if ($bbdReducedPriceRaw !== null && $bbdReducedPriceRaw !== '') {
            $bbdCandidate = max(0.0, (float) $bbdReducedPriceRaw);
            if ($bbdCandidate > 0.0) {
                $effective = min($effective > 0.0 ? $effective : $bbdCandidate, $bbdCandidate);
            }
        }

        return max(0.0, $effective);
    }

    private static function resolveBbdSelection(array $product, string $requestedKey): array
    {
        $enabled = ((int) ($product['bbd_enabled'] ?? 0)) === 1;
        if (!$enabled) {
            return [];
        }
        $entries = self::normalizeProductBbdEntries($product['bbd_entries_json'] ?? '[]');
        if ($entries === []) {
            return [];
        }
        $safeRequested = strtolower(trim($requestedKey));
        if ($safeRequested === '') {
            return [];
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryKey = strtolower(trim((string) ($entry['key'] ?? '')));
            if ($entryKey === '' || $entryKey !== $safeRequested) {
                continue;
            }
            return $entry;
        }
        return [];
    }

    private static function normalizeProductBbdEntries(mixed $raw): array
    {
        $decoded = [];
        if (is_string($raw) && trim($raw) !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }

        $entries = [];
        $seen = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $dateRaw = trim((string) ($item['date'] ?? $item['bbd_date'] ?? ''));
            $timestamp = strtotime($dateRaw);
            if ($dateRaw === '' || $timestamp === false) {
                continue;
            }
            $date = date('Y-m-d', $timestamp);
            $priceRawForKey = trim((string) ($item['reduced_price'] ?? $item['price'] ?? ''));
            $reducedPriceForKey = null;
            if ($priceRawForKey !== '') {
                $numericKeyPrice = str_replace(',', '.', $priceRawForKey);
                if (is_numeric($numericKeyPrice)) {
                    $candidateKeyPrice = max(0.0, (float) $numericKeyPrice);
                    if ($candidateKeyPrice > 0.0) {
                        $reducedPriceForKey = (float) number_format($candidateKeyPrice, 2, '.', '');
                    }
                }
            }
            $lotRawForKey = strtoupper(trim((string) ($item['lot'] ?? $item['bbd_lot'] ?? '')));
            $lotForKey = preg_replace('/[^A-Z0-9\-_.\/]/', '', $lotRawForKey) ?? '';
            if ($lotForKey !== '') {
                $lotForKey = substr($lotForKey, 0, 40);
            }
            $key = strtolower(trim((string) ($item['key'] ?? '')));
            if ($key === '' || preg_match('/^[a-z0-9]{1,64}$/', $key) !== 1) {
                $key = substr(sha1($date . '|' . $lotForKey . '|' . ($reducedPriceForKey === null ? '-' : number_format($reducedPriceForKey, 2, '.', ''))), 0, 20);
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $priceRaw = $priceRawForKey;
            $reducedPrice = null;
            if ($priceRaw !== '') {
                $numeric = str_replace(',', '.', $priceRaw);
                if (is_numeric($numeric)) {
                    $candidate = max(0.0, (float) $numeric);
                    if ($candidate > 0.0) {
                        $reducedPrice = (float) number_format($candidate, 2, '.', '');
                    }
                }
            }
            $lot = $lotForKey;
            $stockRaw = trim((string) ($item['stock'] ?? $item['bbd_stock'] ?? ''));
            $stock = null;
            if ($stockRaw !== '') {
                $numericStock = str_replace(',', '.', $stockRaw);
                if (is_numeric($numericStock)) {
                    $stock = max(0, (int) floor((float) $numericStock));
                }
            }
            $entries[] = [
                'key' => $key,
                'date' => $date,
                'lot' => $lot,
                'label' => trim((string) ($item['label'] ?? ('Expiră la data de: ' . date('d.m.Y', $timestamp)))),
                'reduced_price' => $reducedPrice,
                'stock' => $stock,
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            return strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
        });

        return $entries;
    }

    private static function validateCoupon(?PDO $db, string $code, array $lines = []): ?array
    {
        if (!$db instanceof PDO) {
            return null;
        }

        self::ensureCouponsSchema($db);
        $coupon = self::loadCouponByCode($db, strtoupper($code));

        if (!$coupon) {
            return null;
        }

        $now = new \DateTimeImmutable('now');
        if (!empty($coupon['starts_at']) && $now < new \DateTimeImmutable((string) $coupon['starts_at'])) {
            return null;
        }
        if (!empty($coupon['ends_at']) && $now > new \DateTimeImmutable((string) $coupon['ends_at'])) {
            return null;
        }

        if (!self::couponMeetsCartRules($coupon, $lines)) {
            return null;
        }

        if (!self::couponUsageAvailable($db, $coupon)) {
            return null;
        }

        if (!self::couponAllowedForCurrentUser($coupon)) {
            return null;
        }

        return $coupon;
    }

    private static function calculateDiscount(array $coupon, float $baseAmount): float
    {
        $value = (float) $coupon['value'];
        if ($value <= 0) {
            return 0.0;
        }

        if ((string) $coupon['type'] === 'percent') {
            return min($baseAmount, ($baseAmount * $value) / 100);
        }

        return min($baseAmount, $value);
    }

    private static function couponDiscountBaseSubtotal(array $coupon, float $subtotal): float
    {
        $eligibleSubtotal = max(0.0, (float) ($coupon['eligible_subtotal'] ?? 0.0));
        $appliesOnlySelectedProducts = ((int) ($coupon['applies_only_selected_products'] ?? 0)) === 1;
        if ($appliesOnlySelectedProducts && $eligibleSubtotal > 0.0) {
            return $eligibleSubtotal;
        }
        // Cupon fără cumulare: baza de reducere e doar subtotalul produselor la preț întreg.
        if (!empty($coupon['use_eligible_subtotal'])) {
            return $eligibleSubtotal;
        }

        return max(0.0, $subtotal);
    }

    private static function applyCouponDiscountsToLines(array $lines, array $coupon, float $discount): array
    {
        $discountCents = max(0, (int) round($discount * 100));
        if ($discountCents <= 0 || $lines === []) {
            return [
                'lines' => $lines,
                'total_discount' => 0.0,
                'line_discounts' => [],
            ];
        }

        $eligibleKeyMap = [];
        $eligibleKeysRaw = $coupon['eligible_cart_item_keys'] ?? [];
        if (is_array($eligibleKeysRaw)) {
            foreach ($eligibleKeysRaw as $key) {
                $safeKey = trim((string) $key);
                if ($safeKey !== '') {
                    $eligibleKeyMap[$safeKey] = true;
                }
            }
        }

        $eligibleCandidates = [];
        $eligibleTotalCents = 0;
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineTotalCents = max(0, (int) round(max(0.0, (float) ($line['line_total'] ?? 0.0)) * 100));
            if ($lineTotalCents <= 0) {
                continue;
            }
            $cartItemKey = trim((string) ($line['cart_item_key'] ?? ''));
            if ($eligibleKeyMap !== [] && !isset($eligibleKeyMap[$cartItemKey])) {
                continue;
            }
            $eligibleCandidates[] = [
                'index' => $index,
                'line_total_cents' => $lineTotalCents,
                'cart_item_key' => $cartItemKey,
            ];
            $eligibleTotalCents += $lineTotalCents;
        }

        if ($eligibleCandidates === [] || $eligibleTotalCents <= 0) {
            return [
                'lines' => $lines,
                'total_discount' => 0.0,
                'line_discounts' => [],
            ];
        }

        $discountCents = min($discountCents, $eligibleTotalCents);
        $allocations = [];
        $ranking = [];
        foreach ($eligibleCandidates as $candidateIndex => $candidate) {
            $lineTotalCents = (int) ($candidate['line_total_cents'] ?? 0);
            if ($lineTotalCents <= 0) {
                continue;
            }
            $rawShare = ($lineTotalCents * $discountCents) / $eligibleTotalCents;
            $baseShare = max(0, min($lineTotalCents, (int) floor($rawShare)));
            $allocations[$candidateIndex] = $baseShare;
            $ranking[] = [
                'candidate_index' => $candidateIndex,
                'fractional' => $rawShare - $baseShare,
                'line_total_cents' => $lineTotalCents,
            ];
        }

        $allocatedCents = array_sum($allocations);
        $leftoverCents = max(0, $discountCents - $allocatedCents);
        usort($ranking, static function (array $left, array $right): int {
            $fractionCompare = ($right['fractional'] <=> $left['fractional']);
            if ($fractionCompare !== 0) {
                return $fractionCompare;
            }
            return ((int) ($right['line_total_cents'] ?? 0)) <=> ((int) ($left['line_total_cents'] ?? 0));
        });

        while ($leftoverCents > 0 && $ranking !== []) {
            $changed = false;
            foreach ($ranking as $entry) {
                if ($leftoverCents <= 0) {
                    break;
                }
                $candidateIndex = (int) ($entry['candidate_index'] ?? -1);
                if (!isset($eligibleCandidates[$candidateIndex])) {
                    continue;
                }
                $maxForCandidate = (int) ($eligibleCandidates[$candidateIndex]['line_total_cents'] ?? 0);
                $current = (int) ($allocations[$candidateIndex] ?? 0);
                if ($current >= $maxForCandidate) {
                    continue;
                }
                $allocations[$candidateIndex] = $current + 1;
                $leftoverCents--;
                $changed = true;
            }
            if (!$changed) {
                break;
            }
        }

        $lineDiscounts = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            $line['coupon_discount'] = 0.0;
            $line['line_total_after_coupon'] = max(0.0, (float) ($line['line_total'] ?? 0.0));
            $lines[$index] = $line;
        }

        $discountAppliedCents = 0;
        foreach ($eligibleCandidates as $candidateIndex => $candidate) {
            $index = (int) ($candidate['index'] ?? -1);
            if (!isset($lines[$index]) || !is_array($lines[$index])) {
                continue;
            }
            $line = $lines[$index];
            $allocatedForLineCents = max(0, (int) ($allocations[$candidateIndex] ?? 0));
            if ($allocatedForLineCents <= 0) {
                continue;
            }
            $lineDiscount = $allocatedForLineCents / 100;
            $lineTotal = max(0.0, (float) ($lines[$index]['line_total'] ?? 0.0));
            $line['coupon_discount'] = $lineDiscount;
            $line['line_total_after_coupon'] = max(0.0, $lineTotal - $lineDiscount);
            $lines[$index] = $line;
            $discountAppliedCents += $allocatedForLineCents;
            $cartItemKey = trim((string) ($candidate['cart_item_key'] ?? ''));
            if ($cartItemKey !== '') {
                $lineDiscounts[$cartItemKey] = $lineDiscount;
            }
        }

        return [
            'lines' => $lines,
            'total_discount' => $discountAppliedCents / 100,
            'line_discounts' => $lineDiscounts,
        ];
    }

    private static function loadCouponByCode(PDO $db, string $code): ?array
    {
        $stmt = $db->prepare(
            "SELECT id, code, type, value, starts_at, ends_at,
                    product_ids_json, category_ids_json,
                    min_items_count, max_items_count,
                    max_uses_total, allowed_user_ids_json, apply_only_selected_products,
                    stacks_with_discounts,
                    is_unique, used_at
             FROM coupons
             WHERE code = :code AND is_active = 1
             LIMIT 1"
        );
        $stmt->execute(['code' => strtoupper(trim($code))]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private static function couponMeetsCartRules(array &$coupon, array $lines): bool
    {
        $cartItemsCount = 0;
        $cartRows = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $cartItemsCount += $qty;
            $cartRows[] = [
                'cart_item_key' => trim((string) ($line['cart_item_key'] ?? '')),
                'product_id' => max(0, (int) ($line['id'] ?? 0)),
                'category_id' => max(0, (int) ($line['category_id'] ?? 0)),
                'all_category_ids' => ProductCategories::allCategoryIds(
                    max(0, (int) ($line['category_id'] ?? 0)),
                    (array) ($line['extra_category_ids'] ?? [])
                ),
                'quantity' => $qty,
                'line_total' => max(0.0, (float) ($line['line_total'] ?? 0.0)),
                'is_discounted' => !empty($line['is_discounted']),
            ];
        }

        $minItems = max(0, (int) ($coupon['min_items_count'] ?? 0));
        $maxItems = max(0, (int) ($coupon['max_items_count'] ?? 0));
        if ($minItems > 0 && $cartItemsCount < $minItems) {
            return false;
        }
        if ($maxItems > 0 && $cartItemsCount > $maxItems) {
            return false;
        }

        // Când cuponul NU se cumulează cu alte reduceri, se aplică doar produselor la
        // preț întreg (produsele deja la promoție sunt excluse din baza de reducere).
        $stacksWithDiscounts = ((int) ($coupon['stacks_with_discounts'] ?? 1)) === 1;
        if (!$stacksWithDiscounts) {
            $coupon['use_eligible_subtotal'] = 1;
        }

        $allowedProductIds = array_fill_keys(self::decodePositiveIds((string) ($coupon['product_ids_json'] ?? '')), true);
        $allowedCategoryIds = array_fill_keys(self::decodePositiveIds((string) ($coupon['category_ids_json'] ?? '')), true);
        $hasProductRestriction = $allowedProductIds !== [];
        $hasCategoryRestriction = $allowedCategoryIds !== [];
        $allowSelectedProductsWithOtherItems = ((int) ($coupon['apply_only_selected_products'] ?? 0)) === 1 && $hasProductRestriction;
        $coupon['applies_only_selected_products'] = $allowSelectedProductsWithOtherItems ? 1 : 0;
        if (!$hasProductRestriction && !$hasCategoryRestriction) {
            $eligibleKeys = [];
            $eligibleSubtotal = 0.0;
            foreach ($cartRows as $row) {
                if (!$stacksWithDiscounts && !empty($row['is_discounted'])) {
                    continue;
                }
                $cartItemKey = (string) ($row['cart_item_key'] ?? '');
                if ($cartItemKey !== '') {
                    $eligibleKeys[] = $cartItemKey;
                }
                $eligibleSubtotal += max(0.0, (float) ($row['line_total'] ?? 0.0));
            }
            $coupon['eligible_cart_item_keys'] = array_values(array_unique($eligibleKeys));
            $coupon['eligible_subtotal'] = $eligibleSubtotal;
            return true;
        }

        $eligibleCount = 0;
        $eligibleKeys = [];
        $eligibleSubtotal = 0.0;
        foreach ($cartRows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $categoriiRand = (array) ($row['all_category_ids'] ?? [(int) ($row['category_id'] ?? 0)]);
            $matchesProduct = !$hasProductRestriction || isset($allowedProductIds[$productId]);
            // Un produs e eligibil dacă oricare dintre categoriile lui e pe listă.
            $matchesCategory = !$hasCategoryRestriction;
            if (!$matchesCategory) {
                foreach ($categoriiRand as $categorieRand) {
                    if (isset($allowedCategoryIds[(int) $categorieRand])) {
                        $matchesCategory = true;
                        break;
                    }
                }
            }
            if ($matchesProduct && $matchesCategory) {
                // Cupon fără cumulare: sare peste produsele deja la reducere.
                if (!$stacksWithDiscounts && !empty($row['is_discounted'])) {
                    continue;
                }
                $eligibleCount++;
                $cartItemKey = (string) ($row['cart_item_key'] ?? '');
                if ($cartItemKey !== '') {
                    $eligibleKeys[] = $cartItemKey;
                }
                $eligibleSubtotal += max(0.0, (float) ($row['line_total'] ?? 0.0));
                continue;
            }
            // Legacy behavior remains unchanged unless the new option is explicitly enabled.
            if (!$allowSelectedProductsWithOtherItems) {
                return false;
            }
        }

        if ($eligibleCount <= 0) {
            return false;
        }
        $coupon['eligible_cart_item_keys'] = array_values(array_unique($eligibleKeys));
        $coupon['eligible_subtotal'] = $eligibleSubtotal;
        return true;
    }

    private static function decodePositiveIds(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    public static function ensureCouponsSchema(PDO $db): void
    {
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN product_ids_json LONGTEXT DEFAULT NULL AFTER ends_at');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN category_ids_json LONGTEXT DEFAULT NULL AFTER product_ids_json');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN min_items_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER category_ids_json');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN max_items_count INT UNSIGNED DEFAULT NULL AFTER min_items_count');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN max_uses_total INT UNSIGNED DEFAULT NULL AFTER max_items_count');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN allowed_user_ids_json LONGTEXT DEFAULT NULL AFTER category_ids_json');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN is_unique TINYINT(1) NOT NULL DEFAULT 0 AFTER apply_only_selected_products');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN used_at DATETIME DEFAULT NULL AFTER is_unique');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN import_batch VARCHAR(190) DEFAULT NULL AFTER used_at');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN apply_only_selected_products TINYINT(1) NOT NULL DEFAULT 0 AFTER allowed_user_ids_json');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN stacks_with_discounts TINYINT(1) NOT NULL DEFAULT 1 AFTER apply_only_selected_products');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE coupons SET min_items_count = COALESCE(min_items, 0) WHERE min_items_count = 0 AND min_items IS NOT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE coupons SET max_items_count = max_items WHERE max_items_count IS NULL AND max_items IS NOT NULL');
        } catch (Throwable) {
        }
    }

    private static function couponUsageAvailable(PDO $db, array $coupon): bool
    {
        // Cupoanele unice (single-use) sunt guvernate exclusiv de flag-ul used_at,
        // nu de numărul de comenzi — ca reactivarea la teste să funcționeze corect.
        if ((int) ($coupon['is_unique'] ?? 0) === 1) {
            return trim((string) ($coupon['used_at'] ?? '')) === '';
        }

        $maxUses = max(0, (int) ($coupon['max_uses_total'] ?? 0));
        if ($maxUses <= 0) {
            return true;
        }

        $couponCode = strtoupper(trim((string) ($coupon['code'] ?? '')));
        if ($couponCode === '') {
            return false;
        }

        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM orders
                 WHERE coupon_code = :coupon_code
                   AND deleted_at IS NULL
                   AND status NOT IN ('cancelled', 'failed', 'refunded')"
            );
            $stmt->execute(['coupon_code' => $couponCode]);
            $usedCount = (int) $stmt->fetchColumn();
            return $usedCount < $maxUses;
        } catch (Throwable) {
            try {
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM orders
                     WHERE coupon_code = :coupon_code
                       AND status NOT IN ('cancelled', 'failed', 'refunded')"
                );
                $stmt->execute(['coupon_code' => $couponCode]);
                $usedCount = (int) $stmt->fetchColumn();
                return $usedCount < $maxUses;
            } catch (Throwable) {
                return true;
            }
        }
    }

    private static function couponAllowedForCurrentUser(array $coupon): bool
    {
        $allowedUserIds = array_fill_keys(self::decodePositiveIds((string) ($coupon['allowed_user_ids_json'] ?? '')), true);
        if ($allowedUserIds === []) {
            return true;
        }

        $userId = CustomerAuth::id();
        if ($userId === null || $userId <= 0) {
            return false;
        }

        return isset($allowedUserIds[$userId]);
    }

    private static function calculateShipping(?PDO $db, array $settings, string $county, float $subtotal, float $discount): float
    {
        $isBucharest = strtolower(trim($county)) === 'bucuresti';

        // Prețul fix are prioritate. Include și taxa de km suplimentari, dacă
        // localitatea completată în formular e în lista importată de la FAN:
        // sumarul trebuie să arate exact cât se va plăti, nu prețul de bază.
        $laFanbox = self::livrareAleasaLaFanbox();
        $pretFix = ShippingPricing::pret($db, $settings, $county, self::localitate(), $laFanbox);
        if ($pretFix !== null) {
            return self::transportGratuit($settings, $isBucharest, $subtotal, $discount)
                ? 0.0
                : $pretFix;
        }

        // In FAN live mode we no longer expose legacy manual tariffs in summaries.
        // The final shipping value is resolved from FAN at checkout submit time.
        if ((string) ($settings['fan_live_tariff_enabled'] ?? '0') === '1') {
            return 0.0;
        }

        $includeCoupons = ((string) ($settings['shipping_include_coupons'] ?? '1')) === '1';

        $threshold = $isBucharest
            ? (float) ($settings['shipping_free_bucharest'] ?? 200)
            : (float) ($settings['shipping_free_province'] ?? 200);

        $cartReference = $includeCoupons ? ($subtotal - $discount) : $subtotal;

        if ($cartReference >= $threshold) {
            return 0.0;
        }

        $baseShipping = $isBucharest
            ? (float) ($settings['shipping_cost_bucharest'] ?? 15)
            : (float) ($settings['shipping_cost_province'] ?? 15);

        $maxShipping = (float) ($settings['shipping_max_cost'] ?? 40);
        return min($baseShipping, $maxShipping);
    }

    /** Coșul atinge pragul de transport gratuit? */
    private static function transportGratuit(
        array $settings,
        bool $isBucharest,
        float $subtotal,
        float $discount
    ): bool {
        $includeCoupons = ((string) ($settings['shipping_include_coupons'] ?? '1')) === '1';
        $threshold = $isBucharest
            ? (float) ($settings['shipping_free_bucharest'] ?? 200)
            : (float) ($settings['shipping_free_province'] ?? 200);
        if ($threshold <= 0) {
            return false;
        }
        $referinta = $includeCoupons ? ($subtotal - $discount) : $subtotal;
        return $referinta >= $threshold;
    }

    /**
     * Recalcul transport pentru editarea manuală a comenzii din admin.
     * Dacă subtotalul (minus reduceri) atinge pragul de transport gratuit → 0.
     * Altfel păstrează transportul curent dacă e > 0, ori estimează din setări.
     */
    public static function adminRecalcShipping(array $settings, string $county, float $subtotal, float $discount, float $currentShipping): float
    {
        $isBucharest = strtolower(trim($county)) === 'bucuresti';
        $includeCoupons = ((string) ($settings['shipping_include_coupons'] ?? '1')) === '1';
        $threshold = $isBucharest
            ? (float) ($settings['shipping_free_bucharest'] ?? 200)
            : (float) ($settings['shipping_free_province'] ?? 200);
        $reference = $includeCoupons ? max(0.0, $subtotal - $discount) : max(0.0, $subtotal);
        if ($threshold > 0 && $reference >= $threshold) {
            return 0.0;
        }
        if ($currentShipping > 0.0) {
            return round($currentShipping, 2);
        }
        // Fără un transport deja stabilit, prețul fix (dacă e activ) e referința.
        $pretFix = ShippingPricing::pretDeBaza($settings, self::livrareAleasaLaFanbox());
        if ($pretFix !== null) {
            return $pretFix;
        }
        $base = $isBucharest
            ? (float) ($settings['shipping_cost_bucharest'] ?? 15)
            : (float) ($settings['shipping_cost_province'] ?? 15);
        $max = (float) ($settings['shipping_max_cost'] ?? 40);
        return round(min($base, $max), 2);
    }
}
