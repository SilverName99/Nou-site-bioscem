<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Disponibilitatea produselor, citită din ERP.
 *
 * Când integrarea de stoc e pornită, site-ul nu mai afișează cantitatea din
 * fișa produsului, ci doar dacă produsul e disponibil: ERP-ul întoarce stocul
 * din gestiune minus rezervările comenzilor în lucru.
 *
 * Regula de siguranță e „fail open": dacă ERP-ul nu răspunde sau nu cunoaște
 * SKU-ul, rămâne valabil stocul de pe site. Un ERP picat nu are voie să golească
 * magazinul.
 */
final class ErpStock
{
    /** Cât timp ținem răspunsul, ca să nu lovim ERP-ul la fiecare afișare. */
    private const TTL_SECONDS = 60;

    /** @var array<string, float>|null Disponibilul pe SKU, pe durata cererii. */
    private static ?array $memo = null;

    /** Integrarea de stoc e pornită și configurată? */
    public static function enabled(?PDO $db): bool
    {
        if (!$db instanceof PDO) {
            return false;
        }
        try {
            $settings = Settings::all($db);
        } catch (Throwable) {
            return false;
        }
        return (string) ($settings['erp_enabled'] ?? '0') === '1'
            && (string) ($settings['erp_stock_enabled'] ?? '0') === '1';
    }

    /**
     * Marchează produsele indisponibile după ERP.
     *
     * Rândurile primite trebuie să aibă cheia `id`; SKU-urile se citesc din
     * baza de date, ca să nu fie nevoie de modificări în fiecare interogare.
     *
     * @param array<int, array<string, mixed>> $products
     */
    public static function applyToProducts(?PDO $db, array &$products): void
    {
        if ($products === [] || !self::enabled($db)) {
            return;
        }

        $ids = [];
        foreach ($products as $product) {
            $id = (int) ($product['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return;
        }

        $skuById = self::skusByProductId($db, array_values($ids));
        if ($skuById === []) {
            return;
        }

        $availability = self::availability($db, array_values($skuById));
        if ($availability === []) {
            return;
        }

        foreach ($products as &$product) {
            $id = (int) ($product['id'] ?? 0);
            $sku = $skuById[$id] ?? null;
            if ($sku === null) {
                continue;
            }
            $key = strtoupper($sku);
            if (!array_key_exists($key, $availability)) {
                continue;
            }
            $disponibil = $availability[$key];
            $product['out_of_stock'] = $disponibil > 0 ? 0 : 1;
            $product['stock'] = (int) floor(max(0.0, $disponibil));
            // Marcaj pentru interfețe: cantitatea afișată vine din gestiune,
            // nu din fișa produsului de pe site.
            $product['stock_from_erp'] = 1;
        }
        unset($product);
    }

    /** Varianta pentru un singur produs. */
    public static function applyToProduct(?PDO $db, array $product): array
    {
        $rows = [$product];
        self::applyToProducts($db, $rows);
        return $rows[0];
    }

    /**
     * Cantitatea disponibilă pe SKU (majuscule). SKU-urile pe care ERP-ul nu le
     * cunoaște lipsesc din rezultat, ca apelantul să păstreze stocul de pe site.
     *
     * @param string[] $skus
     * @return array<string, float>
     */
    public static function availability(?PDO $db, array $skus): array
    {
        if (!$db instanceof PDO || $skus === []) {
            return [];
        }

        $wanted = [];
        foreach ($skus as $sku) {
            $value = trim((string) $sku);
            if ($value !== '') {
                $wanted[strtoupper($value)] = $value;
            }
        }
        if ($wanted === []) {
            return [];
        }

        self::$memo ??= self::readCache();

        $lipsa = array_diff_key($wanted, self::$memo);
        if ($lipsa !== []) {
            $client = ErpClient::fromDb($db);
            if ($client !== null) {
                try {
                    foreach ($client->stockBySku(array_values($lipsa)) as $sku => $info) {
                        // SKU-urile necunoscute în ERP nu intră în cache ca
                        // „indisponibil" — le lăsăm pe seama stocului de pe site.
                        if (($info['cunoscut'] ?? false) === true) {
                            self::$memo[$sku] = (float) ($info['disponibil'] ?? 0);
                        }
                    }
                    self::writeCache(self::$memo);
                } catch (Throwable) {
                    // ERP indisponibil: rămâne stocul de pe site.
                }
            }
        }

        $out = [];
        foreach ($wanted as $key => $_) {
            if (array_key_exists($key, self::$memo)) {
                $out[$key] = self::$memo[$key];
            }
        }
        return $out;
    }

    /** Golește cache-ul (după un import sau o schimbare de setări). */
    public static function flush(): void
    {
        self::$memo = null;
        $file = self::cacheFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * @param int[] $ids
     * @return array<int, string>
     */
    private static function skusByProductId(PDO $db, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $db->prepare(
                "SELECT id, sku FROM products WHERE id IN ($placeholders) AND sku IS NOT NULL AND sku <> ''"
            );
            $stmt->execute($ids);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '') {
                $out[(int) $row['id']] = $sku;
            }
        }
        return $out;
    }

    /** @return array<string, float> */
    private static function readCache(): array
    {
        $file = self::cacheFile();
        if (!is_file($file)) {
            return [];
        }
        if (time() - (int) @filemtime($file) > self::TTL_SECONDS) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $sku => $disponibil) {
            $out[strtoupper((string) $sku)] = (float) $disponibil;
        }
        return $out;
    }

    /** @param array<string, float> $data */
    private static function writeCache(array $data): void
    {
        $file = self::cacheFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $json = json_encode($data);
        if ($json !== false) {
            @file_put_contents($file, $json, LOCK_EX);
        }
    }

    private static function cacheFile(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/erp-stock.json';
    }
}
