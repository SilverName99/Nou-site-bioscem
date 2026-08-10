INSERT INTO product_categories (name, slug)
SELECT seed.name, seed.slug
FROM (
    SELECT 'Uleiuri' AS name, 'uleiuri' AS slug
    UNION ALL
    SELECT 'Miere' AS name, 'miere' AS slug
    UNION ALL
    SELECT 'Suplimente' AS name, 'suplimente' AS slug
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM product_categories LIMIT 1);

INSERT INTO products (name, sku, category, category_id, slug, short_description, description, price, sale_price, stock, weight_grams, image_url, is_active, deleted_at)
SELECT
    seed.name,
    seed.sku,
    seed.category,
    (SELECT id FROM product_categories WHERE slug = seed.category_slug LIMIT 1),
    seed.slug,
    seed.short_description,
    seed.description,
    seed.price,
    seed.sale_price,
    seed.stock,
    seed.weight_grams,
    seed.image_url,
    seed.is_active,
    seed.deleted_at
FROM (
    SELECT
        'Ulei de cocos virgin BIO' AS name,
        'UCB-001' AS sku,
        'Uleiuri' AS category,
        'uleiuri' AS category_slug,
        'ulei-de-cocos-virgin-bio' AS slug,
        'Ulei de cocos presat la rece.' AS short_description,
        'Ulei de cocos presat la rece, 100% natural, certificat BIO.' AS description,
        45.99 AS price,
        NULL AS sale_price,
        25 AS stock,
        500 AS weight_grams,
        '/assets/img/product-placeholder.svg' AS image_url,
        1 AS is_active,
        NULL AS deleted_at
    UNION ALL
    SELECT
        'Miere de Manuka MGO 400+' AS name,
        'MMK-002' AS sku,
        'Miere' AS category,
        'miere' AS category_slug,
        'miere-de-manuka-mgo-400' AS slug,
        'Miere premium cu proprietăți antioxidante.' AS short_description,
        'Miere de Manuka MGO 400+ importată, calitate premium.' AS description,
        189.00 AS price,
        169.00 AS sale_price,
        12 AS stock,
        250 AS weight_grams,
        '/assets/img/product-placeholder.svg' AS image_url,
        1 AS is_active,
        NULL AS deleted_at
    UNION ALL
    SELECT
        'Spirulină tablete BIO' AS name,
        'SPR-003' AS sku,
        'Suplimente' AS category,
        'suplimente' AS category_slug,
        'spirulina-tablete-bio' AS slug,
        'Supliment natural pe bază de spirulină.' AS short_description,
        'Tablete de spirulină BIO pentru energie și vitalitate.' AS description,
        79.50 AS price,
        NULL AS sale_price,
        40 AS stock,
        200 AS weight_grams,
        '/assets/img/product-placeholder.svg' AS image_url,
        1 AS is_active,
        NULL AS deleted_at
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM products LIMIT 1);

INSERT INTO pages (title, slug, html_content, is_published, deleted_at)
SELECT
    'Despre noi',
    'despre-noi',
    '<h1>Despre noi</h1><p>Acesta este un exemplu de pagină administrabilă din modulul Pagini.</p>',
    1,
    NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM pages
    WHERE slug = 'despre-noi'
    LIMIT 1
);

INSERT INTO gallery_folders (name, slug)
SELECT seed.name, seed.slug
FROM (
    SELECT 'General' AS name, 'general' AS slug
    UNION ALL
    SELECT 'Campanii' AS name, 'campanii' AS slug
    UNION ALL
    SELECT 'Video' AS name, 'video' AS slug
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM gallery_folders LIMIT 1);

INSERT INTO gallery_images (title, media_type, image_url, folder_id, alt_text, sort_order, is_active)
SELECT
    seed.title,
    seed.media_type,
    seed.image_url,
    (SELECT id FROM gallery_folders WHERE slug = seed.folder_slug LIMIT 1),
    seed.alt_text,
    seed.sort_order,
    seed.is_active
FROM (
    SELECT
        'Imagine demo 1' AS title,
        'image' AS media_type,
        '/assets/img/product-placeholder.svg' AS image_url,
        'general' AS folder_slug,
        'Imagine demo 1' AS alt_text,
        1 AS sort_order,
        1 AS is_active
    UNION ALL
    SELECT
        'Imagine demo 2' AS title,
        'image' AS media_type,
        '/assets/img/product-placeholder.svg' AS image_url,
        'general' AS folder_slug,
        'Imagine demo 2' AS alt_text,
        2 AS sort_order,
        1 AS is_active
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM gallery_images LIMIT 1);
