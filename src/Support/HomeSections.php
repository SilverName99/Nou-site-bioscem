<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Secțiuni dinamice folosite în paginile custom din admin.
 *
 * Sunt disponibile ca placeholdere în HTML-ul paginii:
 *   {{featured_products:4}}   - grilă cu ultimele N produse
 *   {{latest_posts:3}}        - ultimele N articole de blog
 *   {{product_categories:6}}  - primele N categorii, cu iconițe
 *
 * Markup-ul generat folosește clasele `bs-*`, stilizate din CSS-ul paginii.
 */
final class HomeSections
{
    /** Iconițe alese după numele categoriei; „default” acoperă restul. */
    private const CATEGORY_ICONS = [
        'digestie' => '<path d="M8 3v6a4 4 0 0 0 8 0V3"/><path d="M12 13v8"/><path d="M9 21h6"/>',
        'probiotice' => '<path d="M8 3v6a4 4 0 0 0 8 0V3"/><path d="M12 13v8"/><path d="M9 21h6"/>',
        'imunitate' => '<path d="M12 3 5 6v6c0 4.4 2.8 7.8 7 9 4.2-1.2 7-4.6 7-9V6l-7-3z"/><path d="m9.4 12.3 1.8 1.8 3.5-3.5"/>',
        'sport' => '<path d="M13 2 4 14h7l-1 8 10-13h-7z"/>',
        'vitamine' => '<path d="M13 2 4 14h7l-1 8 10-13h-7z"/>',
        'minerale' => '<path d="m12 2 4 6-4 14-4-14z"/><path d="M8 8h8"/>',
        'siliciu' => '<path d="m12 2 4 6-4 14-4-14z"/><path d="M8 8h8"/>',
        'detoxifiere' => '<path d="M12 2C8 7 5 11 5 15a7 7 0 0 0 14 0c0-4-3-8-7-13z"/>',
        'alge' => '<path d="M12 21c0-6 2-11 6-14"/><path d="M12 21c0-6-2-11-6-14"/><path d="M12 21v-9"/>',
        'neuro' => '<path d="M9 4a3 3 0 0 0-3 3 3 3 0 0 0-1 5 3 3 0 0 0 2 5 3 3 0 0 0 5 1"/><path d="M15 4a3 3 0 0 1 3 3 3 3 0 0 1 1 5 3 3 0 0 1-2 5 3 3 0 0 1-5 1"/><path d="M12 4v16"/>',
        'tiroida' => '<path d="M7 4c-1 4-1 8 1 11 1.5 2.2 4.5 2.2 6 0 2-3 2-7 1-11"/><path d="M12 15v6"/>',
        'menopauza' => '<path d="M7 4c-1 4-1 8 1 11 1.5 2.2 4.5 2.2 6 0 2-3 2-7 1-11"/><path d="M12 15v6"/>',
        'dermato' => '<path d="M12 21s8-4.5 8-11a4.5 4.5 0 0 0-8-3 4.5 4.5 0 0 0-8 3c0 6.5 8 11 8 11z"/>',
        'beauty' => '<path d="M12 21s8-4.5 8-11a4.5 4.5 0 0 0-8-3 4.5 4.5 0 0 0-8 3c0 6.5 8 11 8 11z"/>',
        'respiro' => '<path d="M12 4v8"/><path d="M8 8a4 4 0 0 0-4 4c0 3 2 6 4 8"/><path d="M16 8a4 4 0 0 1 4 4c0 3-2 6-4 8"/>',
        'hrana' => '<path d="M6 3v8a3 3 0 0 0 6 0V3"/><path d="M9 11v10"/><path d="M17 3c-1.5 2-2 4-2 6v3h4V9c0-2-.5-4-2-6z"/><path d="M17 12v9"/>',
        'quinton' => '<path d="M12 3c3 4 6 7 6 10a6 6 0 0 1-12 0c0-3 3-6 6-10z"/>',
        'cmo' => '<path d="M12 3a9 9 0 1 0 9 9"/><path d="M12 7a5 5 0 1 0 5 5"/><circle cx="12" cy="12" r="1.5"/>',
        'default' => '<path d="M19.1 5.1c-6 .2-10.4 3.9-10.4 6.5 0 2.9 2 4.9 4.5 4.9 4.2 0 7.7-4 8.2-9.6.1-.9-.6-1.8-2.3-1.8Z"/><path d="M6.6 20.4c2.1-4.7 6.1-8 11.3-10.2"/>',
    ];

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }

    private static function iconFor(string $label): string
    {
        $normalized = mb_strtolower($label);
        $normalized = strtr($normalized, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        foreach (self::CATEGORY_ICONS as $key => $path) {
            if ($key !== 'default' && str_contains($normalized, $key)) {
                return $path;
            }
        }
        return self::CATEGORY_ICONS['default'];
    }

    private static function categoryUrl(string $name): string
    {
        $name = trim($name);
        return $name === '' ? '/magazin' : '/magazin?category=' . rawurlencode($name);
    }

    // -----------------------------------------------------------------
    // Produse
    // -----------------------------------------------------------------

    public static function renderFeaturedProducts(?PDO $db, int $limit = 4): string
    {
        if (!$db instanceof PDO) {
            return '';
        }

        $limit = max(1, min(24, $limit));

        try {
            $stmt = $db->prepare(
                'SELECT id, name, slug, price, sale_price, image_url, out_of_stock
                 FROM products
                 WHERE deleted_at IS NULL AND is_active = 1
                 ORDER BY id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return '';
        }

        // Disponibilitatea reală, când site-ul e legat de ERP.
        ErpStock::applyToProducts($db, $rows);

        $cards = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['slug'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($slug === '' || $name === '') {
                continue;
            }

            $basePrice = max(0.0, (float) ($row['price'] ?? 0));
            $salePrice = (float) ($row['sale_price'] ?? 0);
            $hasSale = $salePrice > 0 && $salePrice < $basePrice;
            $price = $hasSale ? $salePrice : $basePrice;

            $url = '/produs/' . rawurlencode($slug);
            $image = trim((string) ($row['image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
            $outOfStock = (int) ($row['out_of_stock'] ?? 0) === 1;

            $action = $outOfStock
                ? '<span class="bs-product-card__out">Stoc epuizat</span>'
                : '<form method="post" action="/cos/adauga/' . (int) ($row['id'] ?? 0) . '">'
                    . '<button class="bs-product-card__cart" type="submit">'
                    . '<svg viewBox="0 0 24 24" aria-hidden="true">'
                    . '<path d="M3 4h2l1.7 8.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 1.9-1.4l1.5-5.2H7.4"/>'
                    . '<circle cx="8.7" cy="19" r="1.4"/><circle cx="17.5" cy="19" r="1.4"/>'
                    . '</svg>Adauga in cos</button></form>';

            $priceHtml = ($hasSale
                    ? '<span class="bs-product-card__price-old">' . number_format($basePrice, 2, ',', '.') . ' lei</span>'
                    : '')
                . '<span>' . number_format($price, 2, ',', '.') . ' lei</span>';

            $cards[] = '<article class="bs-product-card">'
                . '<a class="bs-product-card__media" href="' . self::e($url) . '">'
                . '<img src="' . self::e($image) . '" alt="' . self::e($name) . '" loading="lazy" decoding="async"'
                . ' onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';"></a>'
                . '<div class="bs-product-card__body">'
                . '<a class="bs-product-card__name" href="' . self::e($url) . '">' . self::e($name) . '</a>'
                . '<p class="bs-product-card__price">' . $priceHtml . '</p>'
                . $action
                . '</div></article>';
        }

        return $cards === [] ? '' : '<div class="bs-products-grid">' . implode('', $cards) . '</div>';
    }

    // -----------------------------------------------------------------
    // Articole
    // -----------------------------------------------------------------

    public static function renderLatestPosts(?PDO $db, int $limit = 3): string
    {
        if (!$db instanceof PDO) {
            return '';
        }

        $limit = max(1, min(12, $limit));

        try {
            $stmt = $db->prepare(
                'SELECT p.title, p.slug, p.excerpt, p.content, p.featured_image_url,
                        COALESCE(a.name, "") AS author_name
                 FROM blog_posts p
                 LEFT JOIN blog_authors a ON a.id = p.author_id
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()
                 ORDER BY p.published_at DESC, p.id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return '';
        }

        $cards = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($title === '') {
                continue;
            }

            $url = $slug !== '' ? '/blog/' . rawurlencode($slug) : '/blog';
            $image = trim((string) ($row['featured_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';

            $excerpt = trim((string) ($row['excerpt'] ?? ''));
            if ($excerpt === '') {
                $excerpt = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($row['content'] ?? ''))));
                $excerpt = mb_substr($excerpt, 0, 140);
            }

            $author = trim((string) ($row['author_name'] ?? ''));

            $cards[] = '<article class="bs-post">'
                . '<a class="bs-post__media" href="' . self::e($url) . '">'
                . '<img src="' . self::e($image) . '" alt="' . self::e($title) . '" loading="lazy" decoding="async"'
                . ' onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';"></a>'
                . '<div class="bs-post__body">'
                . ($author !== '' ? '<span class="bs-post__tag">' . self::e($author) . '</span>' : '')
                . '<a class="bs-post__title" href="' . self::e($url) . '">' . self::e($title) . '</a>'
                . ($excerpt !== '' ? '<p class="bs-post__text">' . self::e($excerpt) . '</p>' : '')
                . '<a class="bs-link bs-link--small" href="' . self::e($url) . '">Citeste articolul <span aria-hidden="true">&rarr;</span></a>'
                . '</div></article>';
        }

        return $cards === [] ? '' : '<div class="bs-posts-grid">' . implode('', $cards) . '</div>';
    }

    // -----------------------------------------------------------------
    // Categorii
    // -----------------------------------------------------------------

    public static function renderCategoryTiles(?PDO $db, int $limit = 6): string
    {
        if (!$db instanceof PDO) {
            return '';
        }

        $limit = max(1, min(12, $limit));

        try {
            $stmt = $db->prepare(
                'SELECT TRIM(category) AS name, COUNT(*) AS products_count
                 FROM products
                 WHERE deleted_at IS NULL
                   AND is_active = 1
                   AND TRIM(COALESCE(category, "")) <> ""
                 GROUP BY TRIM(category)
                 ORDER BY products_count DESC, name ASC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return '';
        }

        $tiles = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $tiles[] = '<a class="bs-need" href="' . self::e(self::categoryUrl($name)) . '">'
                . '<span class="bs-need__icon"><svg viewBox="0 0 24 24" aria-hidden="true">'
                . self::iconFor($name)
                . '</svg></span>'
                . '<span class="bs-need__title">' . self::e($name) . '</span>'
                . '<span class="bs-need__arrow" aria-hidden="true">&rarr;</span>'
                . '</a>';
        }

        return $tiles === [] ? '' : '<div class="bs-needs-grid">' . implode('', $tiles) . '</div>';
    }
}
