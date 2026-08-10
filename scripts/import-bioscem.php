<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

/**
 * Import produse + pagini de pe vechiul site WordPress/WooCommerce (bioscem.ro)
 * folosind DOAR endpoint-uri publice (fără acces admin WordPress):
 *   - produse: /wp-json/wc/store/v1/products (WooCommerce Store API, public)
 *   - pagini:  /wp-json/wp/v2/pages (WordPress REST API, public)
 *
 * Utilizare:
 *   php scripts/import-bioscem.php [optiuni]
 *
 * Optiuni:
 *   --base-url=https://bioscem.ro   sursa importului (implicit https://bioscem.ro)
 *   --dry-run                       doar afiseaza ce ar importa, fara scriere in DB
 *   --limit=N                       importa maxim N produse (pentru test)
 *   --skip-images                   nu descarca imaginile (pastreaza URL-urile de pe site-ul vechi)
 *   --skip-products                 sare peste produse
 *   --skip-pages                    sare peste pagini
 *   --default-stock=N               stoc setat produselor disponibile (implicit 100;
 *                                   stocul real nu este public in API)
 */

$options = [
    'base_url' => 'https://bioscem.ro',
    'dry_run' => false,
    'limit' => 0,
    'skip_images' => false,
    'skip_products' => false,
    'skip_pages' => false,
    'default_stock' => 100,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $options['base_url'] = rtrim(trim(substr($arg, 11)), '/');
    } elseif ($arg === '--dry-run') {
        $options['dry_run'] = true;
    } elseif (str_starts_with($arg, '--limit=')) {
        $options['limit'] = max(0, (int) substr($arg, 8));
    } elseif ($arg === '--skip-images') {
        $options['skip_images'] = true;
    } elseif ($arg === '--skip-products') {
        $options['skip_products'] = true;
    } elseif ($arg === '--skip-pages') {
        $options['skip_pages'] = true;
    } elseif (str_starts_with($arg, '--default-stock=')) {
        $options['default_stock'] = max(0, (int) substr($arg, 16));
    } else {
        exit("Optiune necunoscuta: {$arg}\nRuleaza fara argumente pentru import complet sau vezi antetul scriptului pentru optiuni.\n");
    }
}

if (!filter_var($options['base_url'], FILTER_VALIDATE_URL)) {
    exit("URL sursa invalid: {$options['base_url']}\n");
}

$config = require __DIR__ . '/../config/app.php';
$db = null;
if (!$options['dry_run']) {
    $db = Database::connection($config['db']);
    if (!$db instanceof PDO) {
        exit("Conexiunea la baza de date nu este disponibila. Verifica .env sau ruleaza cu --dry-run.\n");
    }
}

echo "Sursa: {$options['base_url']}\n";
echo $options['dry_run'] ? "Mod: DRY-RUN (nu se scrie nimic in DB)\n" : "Mod: import real\n";

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------

function httpGet(string $url, int $timeout = 30): ?string
{
    $attempts = 3;
    for ($i = 1; $i <= $attempts; $i++) {
        $body = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'BioscemImport/1.0 (+migrare site)',
                CURLOPT_ENCODING => '',
            ]);
            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (is_string($result) && $status >= 200 && $status < 300) {
                $body = $result;
            } elseif ($status === 404) {
                return null;
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'follow_location' => 1,
                    'user_agent' => 'BioscemImport/1.0 (+migrare site)',
                    'ignore_errors' => true,
                ],
            ]);
            $result = @file_get_contents($url, false, $context);
            $statusLine = (string) (($http_response_header[0] ?? ''));
            if (is_string($result) && str_contains($statusLine, '200')) {
                $body = $result;
            } elseif (str_contains($statusLine, '404')) {
                return null;
            }
        }

        if ($body !== null) {
            return $body;
        }
        if ($i < $attempts) {
            sleep($i * 2);
        }
    }

    return null;
}

function httpGetJson(string $url): ?array
{
    $body = httpGet($url);
    if ($body === null) {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

// ---------------------------------------------------------------------------
// Text/format helpers
// ---------------------------------------------------------------------------

function cleanText(string $html, int $maxLength = 0): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
        $text = rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
    }
    return $text;
}

function decodeTitle(string $value, int $maxLength = 190): string
{
    return mb_substr(trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, $maxLength);
}

function slugify(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = strtr($value, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim($value, '-');
}

function minorUnitPrice(mixed $raw, int $minorUnit): ?float
{
    $raw = trim((string) $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return round(((float) $raw) / (10 ** max(0, $minorUnit)), 2);
}

// ---------------------------------------------------------------------------
// Descarcare imagini
// ---------------------------------------------------------------------------

function downloadImage(string $remoteUrl, string $slug, int $index): ?string
{
    $path = (string) parse_url($remoteUrl, PHP_URL_PATH);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        $ext = 'jpg';
    }

    $dir = __DIR__ . '/../public/uploads/products';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }

    $base = slugify($slug) !== '' ? slugify($slug) : 'produs';
    $filename = $index === 0 ? "{$base}.{$ext}" : "{$base}-{$index}.{$ext}";
    $target = $dir . '/' . $filename;
    $publicUrl = '/uploads/products/' . $filename;

    if (is_file($target) && filesize($target) > 0) {
        return $publicUrl;
    }

    $data = httpGet($remoteUrl, 60);
    if ($data === null || $data === '') {
        return null;
    }
    if (@file_put_contents($target, $data) === false) {
        return null;
    }

    return $publicUrl;
}

// ---------------------------------------------------------------------------
// Import produse (WooCommerce Store API)
// ---------------------------------------------------------------------------

$summary = [
    'products_inserted' => 0,
    'products_updated' => 0,
    'products_skipped' => 0,
    'images_downloaded' => 0,
    'images_failed' => 0,
    'pages_inserted' => 0,
    'pages_updated' => 0,
    'pages_skipped' => 0,
];

if (!$options['skip_products']) {
    $storeApiBase = null;
    foreach (['/wp-json/wc/store/v1/products', '/wp-json/wc/store/products'] as $candidate) {
        $probe = httpGetJson($options['base_url'] . $candidate . '?per_page=1');
        if (is_array($probe)) {
            $storeApiBase = $candidate;
            break;
        }
    }

    if ($storeApiBase === null) {
        echo "EROARE: nu am gasit WooCommerce Store API pe {$options['base_url']}.\n";
        echo "Verifica in browser: {$options['base_url']}/wp-json/wc/store/v1/products\n";
        echo "Daca site-ul nu e WooCommerce sau are REST API dezactivat, importul automat nu e posibil pe aceasta cale.\n";
    } else {
        echo "Store API gasit: {$storeApiBase}\n";

        $categoryIds = [];
        $ensureCategory = static function (string $name, string $slug) use (&$categoryIds, $db, $options): ?int {
            $key = mb_strtolower($slug);
            if (isset($categoryIds[$key])) {
                return $categoryIds[$key];
            }
            if ($options['dry_run'] || !$db instanceof PDO) {
                $categoryIds[$key] = null;
                return null;
            }
            $stmt = $db->prepare('SELECT id FROM product_categories WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            $id = $stmt->fetchColumn();
            if ($id === false) {
                $insert = $db->prepare('INSERT INTO product_categories (name, slug) VALUES (:name, :slug)');
                $insert->execute(['name' => mb_substr($name, 0, 120), 'slug' => mb_substr($slug, 0, 140)]);
                $id = $db->lastInsertId();
            }
            $categoryIds[$key] = (int) $id;
            return $categoryIds[$key];
        };

        $selectProduct = $db instanceof PDO
            ? $db->prepare('SELECT id FROM products WHERE slug = :slug LIMIT 1')
            : null;
        $insertProduct = $db instanceof PDO
            ? $db->prepare(
                'INSERT INTO products
                    (name, sku, category, category_id, slug, short_description, description,
                     price, sale_price, stock, out_of_stock, image_url, gallery_images_json, is_active)
                 VALUES
                    (:name, :sku, :category, :category_id, :slug, :short_description, :description,
                     :price, :sale_price, :stock, :out_of_stock, :image_url, :gallery_images_json, 1)'
            )
            : null;
        $updateProduct = $db instanceof PDO
            ? $db->prepare(
                'UPDATE products SET
                    name = :name, sku = :sku, category = :category, category_id = :category_id,
                    short_description = :short_description, description = :description,
                    price = :price, sale_price = :sale_price, stock = :stock,
                    out_of_stock = :out_of_stock, image_url = :image_url,
                    gallery_images_json = :gallery_images_json, deleted_at = NULL
                 WHERE id = :id'
            )
            : null;

        $page = 1;
        $perPage = 100;
        $imported = 0;
        $done = false;

        while (!$done) {
            $batch = httpGetJson($options['base_url'] . $storeApiBase . '?per_page=' . $perPage . '&page=' . $page);
            if (!is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $product) {
                if (!is_array($product)) {
                    continue;
                }
                if ($options['limit'] > 0 && $imported >= $options['limit']) {
                    $done = true;
                    break;
                }

                $slug = trim(rawurldecode((string) ($product['slug'] ?? '')));
                $name = decodeTitle((string) ($product['name'] ?? ''));
                if ($slug === '' || $name === '') {
                    $summary['products_skipped']++;
                    continue;
                }
                $slug = mb_substr($slug, 0, 190);

                $prices = is_array($product['prices'] ?? null) ? $product['prices'] : [];
                $minorUnit = (int) ($prices['currency_minor_unit'] ?? 2);
                $currentPrice = minorUnitPrice($prices['price'] ?? '', $minorUnit);
                $regularPrice = minorUnitPrice($prices['regular_price'] ?? '', $minorUnit) ?? $currentPrice;
                if ($regularPrice === null) {
                    $summary['products_skipped']++;
                    echo "  ! sarit (fara pret): {$slug}\n";
                    continue;
                }
                $salePrice = ($currentPrice !== null && $currentPrice < $regularPrice) ? $currentPrice : null;

                $categoryName = null;
                $categoryId = null;
                $categories = is_array($product['categories'] ?? null) ? $product['categories'] : [];
                foreach ($categories as $category) {
                    $catName = decodeTitle((string) ($category['name'] ?? ''), 120);
                    $catSlug = trim(rawurldecode((string) ($category['slug'] ?? '')));
                    if ($catName === '') {
                        continue;
                    }
                    if ($catSlug === '') {
                        $catSlug = slugify($catName);
                    }
                    $categoryName = $catName;
                    $categoryId = $ensureCategory($catName, mb_substr($catSlug, 0, 140));
                    break;
                }

                $inStock = (bool) ($product['is_in_stock'] ?? true);

                $imageUrl = null;
                $galleryUrls = [];
                $images = is_array($product['images'] ?? null) ? $product['images'] : [];
                foreach (array_values($images) as $index => $image) {
                    $src = trim((string) ($image['src'] ?? ''));
                    if ($src === '' || count($galleryUrls) >= 12) {
                        continue;
                    }
                    if ($options['skip_images'] || $options['dry_run']) {
                        $local = $src;
                    } else {
                        $local = downloadImage($src, $slug, $index);
                        if ($local === null) {
                            $summary['images_failed']++;
                            echo "  ! imagine esuata: {$src}\n";
                            continue;
                        }
                        $summary['images_downloaded']++;
                    }
                    if ($imageUrl === null) {
                        $imageUrl = $local;
                    } else {
                        $galleryUrls[] = $local;
                    }
                }

                $row = [
                    'name' => $name,
                    'sku' => mb_substr(trim((string) ($product['sku'] ?? '')), 0, 80) ?: null,
                    'category' => $categoryName,
                    'category_id' => $categoryId,
                    'short_description' => cleanText((string) ($product['short_description'] ?? ''), 500) ?: null,
                    'description' => trim((string) ($product['description'] ?? '')) ?: null,
                    'price' => number_format($regularPrice, 2, '.', ''),
                    'sale_price' => $salePrice !== null ? number_format($salePrice, 2, '.', '') : null,
                    'stock' => $inStock ? $options['default_stock'] : 0,
                    'out_of_stock' => $inStock ? 0 : 1,
                    'image_url' => $imageUrl,
                    'gallery_images_json' => json_encode($galleryUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                ];

                if ($options['dry_run'] || !$selectProduct || !$insertProduct || !$updateProduct) {
                    $priceLabel = $row['price'] . ($row['sale_price'] !== null ? ' -> ' . $row['sale_price'] : '');
                    echo "  [dry-run] {$slug} | {$name} | {$priceLabel} lei | " . count($images) . " imagini\n";
                    $imported++;
                    continue;
                }

                $selectProduct->execute(['slug' => $slug]);
                $existingId = $selectProduct->fetchColumn();
                if ($existingId === false) {
                    $insertProduct->execute($row + ['slug' => $slug]);
                    $summary['products_inserted']++;
                } else {
                    $updateProduct->execute($row + ['id' => (int) $existingId]);
                    $summary['products_updated']++;
                }
                $imported++;
                if ($imported % 25 === 0) {
                    echo "  ... {$imported} produse procesate\n";
                }
            }

            if (count($batch) < $perPage) {
                break;
            }
            $page++;
        }

        echo "Produse procesate: {$imported}\n";
    }
}

// ---------------------------------------------------------------------------
// Import pagini (WordPress REST API)
// ---------------------------------------------------------------------------

if (!$options['skip_pages']) {
    // Pagini de sistem WooCommerce/WordPress care au echivalent propriu in noua aplicatie.
    $skipSlugs = [
        'cart', 'cos', 'checkout', 'finalizare-comanda', 'comanda', 'my-account', 'contul-meu', 'cont',
        'shop', 'magazin', 'wishlist', 'refund_returns', 'refund-returns', 'blog', 'home', 'acasa',
        'sample-page', 'pagina-exemplu',
    ];

    $selectPage = $db instanceof PDO
        ? $db->prepare('SELECT id FROM pages WHERE slug = :slug LIMIT 1')
        : null;
    $insertPage = $db instanceof PDO
        ? $db->prepare('INSERT INTO pages (title, slug, html_content, is_published) VALUES (:title, :slug, :html_content, 1)')
        : null;
    $updatePage = $db instanceof PDO
        ? $db->prepare('UPDATE pages SET title = :title, html_content = :html_content, deleted_at = NULL WHERE id = :id')
        : null;

    $page = 1;
    $perPage = 100;
    $found = false;

    while (true) {
        $batch = httpGetJson($options['base_url'] . '/wp-json/wp/v2/pages?per_page=' . $perPage . '&page=' . $page . '&status=publish');
        if (!is_array($batch) || $batch === [] || isset($batch['code'])) {
            if ($page === 1 && !$found) {
                echo "Nu am putut citi paginile din /wp-json/wp/v2/pages (posibil REST API dezactivat).\n";
            }
            break;
        }
        $found = true;

        foreach ($batch as $wpPage) {
            if (!is_array($wpPage)) {
                continue;
            }
            $slug = mb_substr(trim(rawurldecode((string) ($wpPage['slug'] ?? ''))), 0, 190);
            $title = decodeTitle((string) ($wpPage['title']['rendered'] ?? ''));
            $content = trim((string) ($wpPage['content']['rendered'] ?? ''));
            if ($slug === '' || $title === '' || $content === '') {
                $summary['pages_skipped']++;
                continue;
            }
            if (in_array(mb_strtolower($slug), $skipSlugs, true)) {
                $summary['pages_skipped']++;
                echo "  - pagina de sistem sarita: {$slug}\n";
                continue;
            }

            if ($options['dry_run'] || !$selectPage || !$insertPage || !$updatePage) {
                echo "  [dry-run] pagina: {$slug} | {$title} | " . mb_strlen($content) . " caractere\n";
                continue;
            }

            $selectPage->execute(['slug' => $slug]);
            $existingId = $selectPage->fetchColumn();
            if ($existingId === false) {
                $insertPage->execute(['title' => $title, 'slug' => $slug, 'html_content' => $content]);
                $summary['pages_inserted']++;
            } else {
                $updatePage->execute(['title' => $title, 'html_content' => $content, 'id' => (int) $existingId]);
                $summary['pages_updated']++;
            }
        }

        if (count($batch) < $perPage) {
            break;
        }
        $page++;
    }
}

// ---------------------------------------------------------------------------
// Sumar
// ---------------------------------------------------------------------------

echo "\nImport finalizat.\n";
if (!$options['skip_products']) {
    echo "Produse inserate: {$summary['products_inserted']}\n";
    echo "Produse actualizate: {$summary['products_updated']}\n";
    echo "Produse sarite: {$summary['products_skipped']}\n";
    echo "Imagini descarcate: {$summary['images_downloaded']}\n";
    echo "Imagini esuate: {$summary['images_failed']}\n";
}
if (!$options['skip_pages']) {
    echo "Pagini inserate: {$summary['pages_inserted']}\n";
    echo "Pagini actualizate: {$summary['pages_updated']}\n";
    echo "Pagini sarite (sistem/goale): {$summary['pages_skipped']}\n";
}
if ($options['dry_run']) {
    echo "\nAcesta a fost un DRY-RUN. Ruleaza fara --dry-run pentru importul real.\n";
}
