<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\LoyaltyService;
use App\Support\NewsletterService;
use App\Support\Settings;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    exit("Conexiunea la baza de date nu este disponibilă.\n");
}

$schemaPath = __DIR__ . '/../database/schema.sql';
$schema = file_get_contents($schemaPath);

if ($schema === false) {
    exit("Nu am putut citi schema SQL.\n");
}

$db->exec($schema);

// Backward-compatible safety for local DBs initialized before latest schema.
try {
    $db->exec('ALTER TABLE orders ADD COLUMN coupon_code VARCHAR(100) DEFAULT NULL AFTER discount_total');
} catch (Throwable) {
    // Column already exists.
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN ad_source VARCHAR(50) DEFAULT NULL');
} catch (Throwable) {
    // Column already exists.
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN ad_click_id VARCHAR(255) DEFAULT NULL');
} catch (Throwable) {
    // Column already exists.
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN stripe_session_id VARCHAR(255) DEFAULT NULL AFTER payment_status');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN stripe_payment_intent_id VARCHAR(255) DEFAULT NULL AFTER stripe_session_id');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN paid_at DATETIME DEFAULT NULL AFTER stripe_payment_intent_id');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN payment_error TEXT DEFAULT NULL AFTER paid_at');
} catch (Throwable) {
}

try {
    $db->exec('ALTER TABLE orders ADD COLUMN euplatesc_ep_id VARCHAR(64) DEFAULT NULL AFTER payment_error');
} catch (Throwable) {
}

try {
    $db->exec('ALTER TABLE orders ADD COLUMN euplatesc_approval VARCHAR(64) DEFAULT NULL AFTER euplatesc_ep_id');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN fan_awb VARCHAR(100) DEFAULT NULL AFTER billing_postcode');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN fan_tracking_url TEXT DEFAULT NULL AFTER fan_awb');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN fan_tracking_status VARCHAR(190) DEFAULT NULL AFTER fan_tracking_url');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN fan_tracking_last_event_at DATETIME DEFAULT NULL AFTER fan_tracking_status');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN fan_tracking_synced_at DATETIME DEFAULT NULL AFTER fan_tracking_last_event_at');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN completed_awb_email_sent_at DATETIME DEFAULT NULL AFTER fan_tracking_synced_at');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN completed_awb_email_error TEXT DEFAULT NULL AFTER completed_awb_email_sent_at');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN billing_is_company TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_postcode');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN billing_company_name VARCHAR(255) DEFAULT NULL AFTER billing_is_company');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN billing_company_tax_id VARCHAR(120) DEFAULT NULL AFTER billing_company_name');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN billing_company_registration_no VARCHAR(120) DEFAULT NULL AFTER billing_company_tax_id');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN notes TEXT DEFAULT NULL');
} catch (Throwable) {
}

try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS email_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED DEFAULT NULL,
            email_type VARCHAR(80) NOT NULL,
            recipient VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'sent\',
            error_message TEXT DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_order_email_type (order_id, email_type)
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS cart_abandonments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(190) NOT NULL UNIQUE,
            email VARCHAR(190) DEFAULT NULL,
            customer_name VARCHAR(190) DEFAULT NULL,
            cart_snapshot LONGTEXT DEFAULT NULL,
            last_seen_at DATETIME NOT NULL,
            converted_at DATETIME DEFAULT NULL,
            abandoned_email_sent_at DATETIME DEFAULT NULL,
            abandoned_email_error TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS newsletter_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            blocks_json LONGTEXT NOT NULL,
            html_content LONGTEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS customer_password_resets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            token_hash VARCHAR(128) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS user_addresses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            label VARCHAR(100) DEFAULT NULL,
            full_name VARCHAR(190) NOT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            address_line1 VARCHAR(255) NOT NULL,
            address_line2 VARCHAR(255) DEFAULT NULL,
            city VARCHAR(120) NOT NULL,
            county VARCHAR(120) NOT NULL,
            postcode VARCHAR(20) DEFAULT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE users ADD COLUMN birth_date DATE DEFAULT NULL AFTER phone');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE users ADD COLUMN gender VARCHAR(20) DEFAULT NULL AFTER birth_date');
} catch (Throwable) {
}
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
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );
} catch (Throwable) {
}
NewsletterService::ensureSchema($db);
LoyaltyService::ensureSchema($db);

try {
    $db->exec('ALTER TABLE products ADD COLUMN sku VARCHAR(80) DEFAULT NULL AFTER name');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN category VARCHAR(120) DEFAULT NULL AFTER sku');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN sale_price DECIMAL(10,2) DEFAULT NULL AFTER price');
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
    $db->exec('ALTER TABLE products ADD COLUMN post_cart_note_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER bbd_entries_json');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN post_cart_note_text TEXT DEFAULT NULL AFTER post_cart_note_enabled');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE order_items ADD COLUMN bbd_key VARCHAR(64) DEFAULT NULL AFTER product_name');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN vat_percent DECIMAL(5,2) NOT NULL DEFAULT 19.00 AFTER price');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN weight_grams INT DEFAULT NULL AFTER stock');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER is_active');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN category_id INT UNSIGNED DEFAULT NULL AFTER category');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN product_template_id INT UNSIGNED DEFAULT NULL AFTER category_id');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN product_highlights TEXT DEFAULT NULL AFTER description');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN gallery_images_json LONGTEXT DEFAULT NULL AFTER image_url');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN gallery_json LONGTEXT DEFAULT NULL AFTER image_url');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN badge_popular TINYINT(1) NOT NULL DEFAULT 0 AFTER gallery_images_json');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN badge_best_seller TINYINT(1) NOT NULL DEFAULT 0 AFTER badge_popular');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE products ADD COLUMN badge_seasonal TINYINT(1) NOT NULL DEFAULT 0 AFTER badge_best_seller');
} catch (Throwable) {
}
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS product_extra_fields (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            field_key VARCHAR(120) NOT NULL UNIQUE,
            field_type VARCHAR(30) NOT NULL DEFAULT \'textarea\',
            placeholder VARCHAR(255) DEFAULT NULL,
            default_value LONGTEXT DEFAULT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS product_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL UNIQUE,
            description VARCHAR(255) DEFAULT NULL,
            html_content LONGTEXT NOT NULL,
            css_content LONGTEXT DEFAULT NULL,
            js_content LONGTEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS product_extra_field_values (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            field_id INT UNSIGNED NOT NULL,
            `value` LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_product_field (product_id, field_id)
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS product_reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            user_name VARCHAR(120) NOT NULL,
            user_email VARCHAR(190) DEFAULT NULL,
            rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
            review_text TEXT DEFAULT NULL,
            is_approved TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_reviews_product_approved (product_id, is_approved, created_at)
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE pages ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER is_published');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE pages ADD COLUMN css_content LONGTEXT NULL AFTER html_content');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE pages ADD COLUMN js_content LONGTEXT NULL AFTER css_content');
} catch (Throwable) {
}
try {
    $db->exec("ALTER TABLE gallery_images ADD COLUMN media_type VARCHAR(20) NOT NULL DEFAULT 'image' AFTER title");
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE gallery_images ADD COLUMN folder_id INT UNSIGNED DEFAULT NULL AFTER image_url');
} catch (Throwable) {
}
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS mannequin_points (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            point_key VARCHAR(120) NOT NULL UNIQUE,
            label VARCHAR(190) NOT NULL,
            pos_x DECIMAL(6,2) NOT NULL DEFAULT 50.00,
            pos_y DECIMAL(6,2) NOT NULL DEFAULT 50.00,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS mannequin_point_products (
            point_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (point_id, product_id)
        )'
    );
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE admins ADD COLUMN roles_json LONGTEXT DEFAULT NULL AFTER role');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE coupons ADD COLUMN apply_only_selected_products TINYINT(1) NOT NULL DEFAULT 0 AFTER allowed_user_ids_json');
} catch (Throwable) {
}
try {
    $rows = $db->query('SELECT id, role, roles_json FROM admins')->fetchAll() ?: [];
    $updateRoles = $db->prepare('UPDATE admins SET role = :role, roles_json = :roles_json WHERE id = :id');
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $roles = [];
        $rawRolesJson = $row['roles_json'] ?? null;
        if (is_string($rawRolesJson) && trim($rawRolesJson) !== '') {
            $decoded = json_decode($rawRolesJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $decodedRole) {
                    $roleValue = trim((string) $decodedRole);
                    if ($roleValue !== '' && !in_array($roleValue, $roles, true)) {
                        $roles[] = $roleValue;
                    }
                }
            }
        }
        $legacyRole = trim((string) ($row['role'] ?? 'administrator_general'));
        if ($legacyRole !== '' && !in_array($legacyRole, $roles, true)) {
            $roles[] = $legacyRole;
        }
        if ($roles === []) {
            $roles[] = 'administrator_general';
        }

        $primaryRole = 'administrator_general';
        if (in_array('administrator_general', $roles, true)) {
            $primaryRole = 'administrator_general';
        } elseif (in_array('administrator_emailuri', $roles, true)) {
            $primaryRole = 'administrator_emailuri';
        } elseif (in_array('administrator_magazin', $roles, true)) {
            $primaryRole = 'administrator_magazin';
        } elseif (in_array('administrator_blog', $roles, true)) {
            $primaryRole = 'administrator_blog';
        } elseif (isset($roles[0])) {
            $primaryRole = (string) $roles[0];
        }

        $encodedRoles = json_encode(array_values($roles), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedRoles) || $encodedRoles === '') {
            continue;
        }

        $updateRoles->execute([
            'id' => (int) ($row['id'] ?? 0),
            'role' => $primaryRole,
            'roles_json' => $encodedRoles,
        ]);
    }
} catch (Throwable) {
}

$email = strtolower(trim((string) $config['admin']['default_email']));
$password = (string) $config['admin']['default_password'];
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $db->prepare('SELECT id FROM admins WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
$exists = $stmt->fetchColumn();

if (!$exists) {
    $insert = $db->prepare('INSERT INTO admins (email, password_hash, role) VALUES (:email, :password_hash, :role)');
    $insert->execute([
        'email' => $email,
        'password_hash' => $hash,
        'role' => 'administrator_general',
    ]);
}

// Keep existing values; only ensure any new default keys are materialized.
Settings::save($db, Settings::all($db));

echo "Install finalizat cu succes.\n";
echo "Admin: {$email}\n";
