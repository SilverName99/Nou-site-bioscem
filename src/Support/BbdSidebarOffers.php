<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;
use Throwable;

final class BbdSidebarOffers
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function load(?PDO $db, int $limit = 12): array
    {
        if (!$db instanceof PDO) {
            return [];
        }

        $safeLimit = max(1, min(24, $limit));
        $rows = self::fetchRows($db, max($safeLimit * 3, 24));
        if ($rows === []) {
            return [];
        }

        $now = new DateTimeImmutable('now');
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::normalizeRow($row, $now);
            if ($item === null) {
                continue;
            }
            $items[] = $item;
            if (count($items) >= $safeLimit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetchRows(PDO $db, int $limit): array
    {
        $safeLimit = max(1, min(120, $limit));
        $queries = [
            'SELECT id, name, slug, image_url, price, sale_price, sale_price_periods_json, bbd_enabled, bbd_entries_json, out_of_stock
             FROM products
             WHERE deleted_at IS NULL
               AND is_active = 1
             ORDER BY id DESC
             LIMIT ' . $safeLimit,
            'SELECT id, name, slug, image_url, price, sale_price, NULL AS sale_price_periods_json, bbd_enabled, bbd_entries_json, 0 AS out_of_stock
             FROM products
             WHERE deleted_at IS NULL
               AND is_active = 1
             ORDER BY id DESC
             LIMIT ' . $safeLimit,
            'SELECT id, name, slug, image_url, price, sale_price, NULL AS sale_price_periods_json, 0 AS bbd_enabled, NULL AS bbd_entries_json, 0 AS out_of_stock
             FROM products
             WHERE deleted_at IS NULL
               AND is_active = 1
             ORDER BY id DESC
             LIMIT ' . $safeLimit,
            'SELECT id, name, slug, image_url, price, sale_price, NULL AS sale_price_periods_json, 0 AS bbd_enabled, NULL AS bbd_entries_json, 0 AS out_of_stock
             FROM products
             ORDER BY id DESC
             LIMIT ' . $safeLimit,
        ];

        foreach ($queries as $sql) {
            try {
                $stmt = $db->query($sql);
                $rows = $stmt ? $stmt->fetchAll() : [];
                if (is_array($rows)) {
                    return $rows;
                }
            } catch (Throwable) {
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function normalizeRow(array $row, DateTimeImmutable $now): ?array
    {
        if ((int) ($row['out_of_stock'] ?? 0) === 1) {
            return null;
        }

        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $slug = trim((string) ($row['slug'] ?? ''));
        $url = $slug !== ''
            ? '/produs/' . rawurlencode($slug)
            : '/magazin';

        $regularPrice = self::normalizeMoney($row['price'] ?? 0.0);
        $effectivePrice = self::resolveEffectiveBasePrice($row, $now);
        $offers = [];

        if ($regularPrice > 0.0 && $effectivePrice > 0.0 && $effectivePrice < $regularPrice) {
            $offers[] = [
                'price' => $effectivePrice,
                'label' => 'Reducere activă',
            ];
        }

        $bbdEnabled = (int) ($row['bbd_enabled'] ?? 0) === 1;
        if ($bbdEnabled) {
            $entries = self::normalizeBbdEntries($row['bbd_entries_json'] ?? '[]');
            $availableEntries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => (bool) ($entry['is_available'] ?? false)
            ));
            foreach ($availableEntries as $entry) {
                $candidate = $entry['reduced_price'] ?? null;
                $candidatePrice = is_numeric((string) $candidate) ? (float) $candidate : $effectivePrice;
                if ($candidatePrice <= 0.0 && $effectivePrice > 0.0) {
                    $candidatePrice = $effectivePrice;
                } elseif ($candidatePrice <= 0.0 && $regularPrice > 0.0) {
                    $candidatePrice = $regularPrice;
                }
                if ($candidatePrice <= 0.0) {
                    continue;
                }
                $offers[] = [
                    'price' => $candidatePrice,
                    'label' => trim((string) ($entry['label'] ?? '')),
                ];
            }
        }

        if ($offers === []) {
            return null;
        }

        usort($offers, static fn (array $left, array $right): int => ($left['price'] <=> $right['price']));
        $bestOffer = $offers[0];
        $bestPrice = max(0.0, (float) ($bestOffer['price'] ?? 0.0));
        if ($bestPrice <= 0.0) {
            return null;
        }
        $offerCount = count($offers);
        $compareAt = $regularPrice > $bestPrice ? $regularPrice : null;

        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'url' => $url,
            'image_url' => trim((string) ($row['image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg',
            'offer_count' => $offerCount,
            'price' => (float) number_format($bestPrice, 2, '.', ''),
            'compare_at_price' => $compareAt !== null ? (float) number_format($compareAt, 2, '.', '') : null,
            'label' => trim((string) ($bestOffer['label'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function resolveEffectiveBasePrice(array $row, DateTimeImmutable $now): float
    {
        $basePrice = self::normalizeMoney($row['price'] ?? 0.0);
        $salePrice = self::normalizeMoney($row['sale_price'] ?? null);
        if ($salePrice <= 0.0) {
            $salePrice = null;
        }

        $periodsRaw = $row['sale_price_periods_json'] ?? '';
        $periods = [];
        if (is_array($periodsRaw)) {
            $periods = $periodsRaw;
        } elseif (is_string($periodsRaw) && trim($periodsRaw) !== '') {
            $decoded = json_decode($periodsRaw, true);
            if (is_array($decoded)) {
                $periods = $decoded;
            }
        }

        $activePeriodSale = null;
        foreach ($periods as $period) {
            if (!is_array($period)) {
                continue;
            }
            $periodSale = self::normalizeMoney($period['sale_price'] ?? null);
            if ($periodSale <= 0.0) {
                continue;
            }
            $startRaw = trim((string) ($period['start_date'] ?? ''));
            $endRaw = trim((string) ($period['end_date'] ?? ''));
            if ($startRaw === '' || $endRaw === '') {
                continue;
            }
            try {
                $start = new DateTimeImmutable($startRaw);
                $end = new DateTimeImmutable($endRaw);
            } catch (Throwable) {
                continue;
            }
            if ($now < $start || $now > $end) {
                continue;
            }
            if ($activePeriodSale === null || $periodSale < $activePeriodSale) {
                $activePeriodSale = $periodSale;
            }
        }

        if ($activePeriodSale !== null) {
            $salePrice = $activePeriodSale;
        }

        if ($salePrice !== null) {
            if ($basePrice <= 0.0) {
                return $salePrice;
            }
            if ($salePrice < $basePrice) {
                return $salePrice;
            }
        }

        return $basePrice;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeBbdEntries(mixed $raw): array
    {
        $decoded = [];
        if (is_string($raw) && trim($raw) !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $decoded = $json;
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

            $price = self::normalizeMoney($item['reduced_price'] ?? $item['price'] ?? null);
            $reducedPrice = $price > 0.0 ? $price : null;

            $lot = strtoupper(trim((string) ($item['lot'] ?? $item['bbd_lot'] ?? '')));
            if ($lot !== '') {
                $lot = substr((string) (preg_replace('/[^A-Z0-9\-_.\/]/', '', $lot) ?? ''), 0, 40);
            }

            $stock = null;
            $stockRaw = trim((string) ($item['stock'] ?? $item['bbd_stock'] ?? ''));
            if ($stockRaw !== '') {
                $stockNumeric = str_replace(',', '.', $stockRaw);
                if (is_numeric($stockNumeric)) {
                    $stock = max(0, (int) floor((float) $stockNumeric));
                }
            }

            $key = strtolower(trim((string) ($item['key'] ?? '')));
            if ($key === '' || preg_match('/^[a-z0-9]{1,64}$/', $key) !== 1) {
                $key = substr(sha1($date . '|' . $lot . '|' . ($reducedPrice === null ? '-' : number_format($reducedPrice, 2, '.', ''))), 0, 20);
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                $label = 'Expiră la data de: ' . date('d.m.Y', $timestamp);
            }

            $entries[] = [
                'key' => $key,
                'date' => $date,
                'label' => $label,
                'reduced_price' => $reducedPrice,
                'stock' => $stock,
                'is_available' => $stock === null ? true : $stock > 0,
            ];
            if (count($entries) >= 60) {
                break;
            }
        }

        usort($entries, static fn (array $left, array $right): int => strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? '')));
        return $entries;
    }

    private static function normalizeMoney(mixed $value): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }
        $raw = str_replace(',', '.', $raw);
        if (!is_numeric($raw)) {
            return 0.0;
        }
        return (float) number_format(max(0.0, (float) $raw), 2, '.', '');
    }
}
