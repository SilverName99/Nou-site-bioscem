<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Categoriile suplimentare ale produselor.
 *
 * Fiecare produs păstrează o categorie principală (products.category_id,
 * folosită la breadcrumbs și SEO), dar poate apărea și în alte categorii
 * prin tabela de legături. Site-ul, cupoanele și adminul citesc de aici.
 */
final class ProductCategories
{
    public static function ensureSchema(?PDO $db): void
    {
        if (!$db instanceof PDO) {
            return;
        }
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS product_category_links (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    product_id INT UNSIGNED NOT NULL,
                    category_id INT UNSIGNED NOT NULL,
                    UNIQUE KEY uniq_product_category (product_id, category_id),
                    KEY idx_pcl_category (category_id)
                )'
            );
        } catch (Throwable) {
            // sqlite (teste) nu știe sintaxa MySQL de index inline.
            try {
                $db->exec(
                    'CREATE TABLE IF NOT EXISTS product_category_links (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        product_id INTEGER NOT NULL,
                        category_id INTEGER NOT NULL,
                        UNIQUE (product_id, category_id)
                    )'
                );
            } catch (Throwable) {
            }
        }
    }

    /**
     * Rescrie legăturile suplimentare ale unui produs.
     * Categoria principală nu se stochează în legături — rămâne pe produs.
     */
    public static function sync(?PDO $db, int $productId, array $categoryIds, int $primaryCategoryId = 0): void
    {
        if (!$db instanceof PDO || $productId <= 0) {
            return;
        }
        self::ensureSchema($db);

        $curate = [];
        foreach ($categoryIds as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $primaryCategoryId) {
                $curate[$id] = true;
            }
        }

        try {
            $db->prepare('DELETE FROM product_category_links WHERE product_id = :pid')
                ->execute(['pid' => $productId]);
            if ($curate === []) {
                return;
            }
            $insert = $db->prepare(
                'INSERT INTO product_category_links (product_id, category_id) VALUES (:pid, :cid)'
            );
            foreach (array_keys($curate) as $categoryId) {
                $insert->execute(['pid' => $productId, 'cid' => $categoryId]);
            }
        } catch (Throwable) {
            // Fără tabelă (bază veche, ensure eșuat) produsul rămâne cu categoria principală.
        }
    }

    /**
     * ID-urile categoriilor suplimentare, grupate pe produs.
     *
     * @param array<int, int|string> $productIds
     * @return array<int, array<int, int>> product_id => [category_id, ...]
     */
    public static function idsForProducts(?PDO $db, array $productIds): array
    {
        if (!$db instanceof PDO || $productIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn (int $v): bool => $v > 0)));
        if ($ids === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare(
                "SELECT product_id, category_id FROM product_category_links WHERE product_id IN ($placeholders)"
            );
            $stmt->execute($ids);
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $map[(int) $row['product_id']][] = (int) $row['category_id'];
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Numele categoriilor suplimentare, grupate pe produs — pentru filtrarea
     * din catalog, care compară pe nume.
     *
     * @return array<int, array<int, string>> product_id => [nume, ...]
     */
    public static function namesForProducts(?PDO $db, array $productIds): array
    {
        if (!$db instanceof PDO || $productIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn (int $v): bool => $v > 0)));
        if ($ids === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare(
                "SELECT l.product_id, c.name
                 FROM product_category_links l
                 INNER JOIN product_categories c ON c.id = l.category_id
                 WHERE l.product_id IN ($placeholders)"
            );
            $stmt->execute($ids);
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $nume = trim((string) $row['name']);
                if ($nume !== '') {
                    $map[(int) $row['product_id']][] = $nume;
                }
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Produsul aparține categoriei cu numele dat? Verifică principala și
     * suplimentarele (cheia `extra_categories`, pusă de loadProducts).
     */
    public static function matchesName(array $product, string $categoryName): bool
    {
        $tinta = mb_strtolower(trim($categoryName));
        if ($tinta === '') {
            return true;
        }
        if (mb_strtolower(trim((string) ($product['category'] ?? ''))) === $tinta) {
            return true;
        }
        foreach ((array) ($product['extra_categories'] ?? []) as $nume) {
            if (mb_strtolower(trim((string) $nume)) === $tinta) {
                return true;
            }
        }
        return false;
    }

    /**
     * Toate ID-urile de categorie ale unui rând de coș (principala + extra),
     * pentru cupoanele restrânse pe categorii.
     *
     * @param array<int, int> $extraIds
     * @return array<int, int>
     */
    public static function allCategoryIds(int $primaryId, array $extraIds): array
    {
        $toate = [];
        if ($primaryId > 0) {
            $toate[$primaryId] = true;
        }
        foreach ($extraIds as $id) {
            if ((int) $id > 0) {
                $toate[(int) $id] = true;
            }
        }
        return array_keys($toate);
    }
}
