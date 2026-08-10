CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'administrator_general',
    roles_json LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(30) DEFAULT NULL,
    birth_date DATE DEFAULT NULL,
    gender VARCHAR(20) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    google_id VARCHAR(120) DEFAULT NULL,
    loyalty_points INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customer_password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    token_hash VARCHAR(128) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_addresses (
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    sku VARCHAR(80) DEFAULT NULL,
    category VARCHAR(120) DEFAULT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    product_template_id INT UNSIGNED DEFAULT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    short_description TEXT DEFAULT NULL,
    description LONGTEXT DEFAULT NULL,
    product_highlights TEXT DEFAULT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    vat_percent DECIMAL(5,2) NOT NULL DEFAULT 19.00,
    sale_price DECIMAL(10,2) DEFAULT NULL,
    sale_price_periods_json LONGTEXT DEFAULT NULL,
    bbd_enabled TINYINT(1) NOT NULL DEFAULT 0,
    bbd_entries_json LONGTEXT DEFAULT NULL,
    post_cart_note_enabled TINYINT(1) NOT NULL DEFAULT 0,
    post_cart_note_text TEXT DEFAULT NULL,
    stock INT NOT NULL DEFAULT 0,
    out_of_stock TINYINT(1) NOT NULL DEFAULT 0,
    weight_grams INT DEFAULT NULL,
    image_url TEXT DEFAULT NULL,
    gallery_images_json LONGTEXT DEFAULT NULL,
    badge_popular TINYINT(1) NOT NULL DEFAULT 0,
    badge_best_seller TINYINT(1) NOT NULL DEFAULT 0,
    badge_seasonal TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    slug VARCHAR(140) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_extra_fields (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    field_key VARCHAR(120) NOT NULL UNIQUE,
    field_type VARCHAR(30) NOT NULL DEFAULT 'textarea',
    placeholder VARCHAR(255) DEFAULT NULL,
    default_value LONGTEXT DEFAULT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_templates (
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
);

CREATE TABLE IF NOT EXISTS product_template_fields (
    template_id INT UNSIGNED NOT NULL,
    field_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (template_id, field_id)
);

CREATE TABLE IF NOT EXISTS product_extra_field_values (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    field_id INT UNSIGNED NOT NULL,
    `value` LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_product_field (product_id, field_id)
);

CREATE TABLE IF NOT EXISTS coupons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(100) NOT NULL UNIQUE,
    type ENUM('fixed', 'percent') NOT NULL DEFAULT 'fixed',
    value DECIMAL(10,2) NOT NULL DEFAULT 0,
    product_ids_json LONGTEXT DEFAULT NULL,
    category_ids_json LONGTEXT DEFAULT NULL,
    min_items_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_items_count INT UNSIGNED DEFAULT NULL,
    max_uses_total INT UNSIGNED DEFAULT NULL,
    allowed_user_ids_json LONGTEXT DEFAULT NULL,
    apply_only_selected_products TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    user_id INT UNSIGNED DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(50) NOT NULL DEFAULT 'stripe',
    payment_status VARCHAR(50) NOT NULL DEFAULT 'unpaid',
    stripe_session_id VARCHAR(255) DEFAULT NULL,
    stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    payment_error TEXT DEFAULT NULL,
    shipping_method VARCHAR(50) NOT NULL DEFAULT 'fan_courier',
    shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    loyalty_points_used INT NOT NULL DEFAULT 0,
    loyalty_points_discount DECIMAL(10,2) NOT NULL DEFAULT 0,
    loyalty_points_awarded INT NOT NULL DEFAULT 0,
    loyalty_points_pending_claim INT NOT NULL DEFAULT 0,
    loyalty_points_awarded_at DATETIME DEFAULT NULL,
    coupon_code VARCHAR(100) DEFAULT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    billing_first_name VARCHAR(100) NOT NULL,
    billing_last_name VARCHAR(100) NOT NULL,
    billing_phone VARCHAR(30) NOT NULL,
    billing_email VARCHAR(190) NOT NULL,
    billing_address_line1 VARCHAR(255) NOT NULL,
    billing_address_line2 VARCHAR(255) DEFAULT NULL,
    billing_city VARCHAR(120) NOT NULL,
    billing_county VARCHAR(120) NOT NULL,
    billing_postcode VARCHAR(20) NOT NULL,
    billing_is_company TINYINT(1) NOT NULL DEFAULT 0,
    billing_company_name VARCHAR(255) DEFAULT NULL,
    billing_company_tax_id VARCHAR(120) DEFAULT NULL,
    billing_company_registration_no VARCHAR(120) DEFAULT NULL,
    fan_awb VARCHAR(100) DEFAULT NULL,
    fan_tracking_url TEXT DEFAULT NULL,
    fan_tracking_status VARCHAR(190) DEFAULT NULL,
    fan_tracking_last_event_at DATETIME DEFAULT NULL,
    fan_tracking_synced_at DATETIME DEFAULT NULL,
    completed_awb_email_sent_at DATETIME DEFAULT NULL,
    completed_awb_email_error TEXT DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS loyalty_points_transactions (
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
);

CREATE TABLE IF NOT EXISTS loyalty_points_rules (
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
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(190) NOT NULL,
    bbd_key VARCHAR(64) DEFAULT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    user_name VARCHAR(120) NOT NULL,
    rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
    review_text TEXT DEFAULT NULL,
    is_approved TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS product_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    user_name VARCHAR(120) NOT NULL,
    user_email VARCHAR(190) DEFAULT NULL,
    rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
    review_text TEXT DEFAULT NULL,
    is_approved TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    KEY idx_product_reviews_product (product_id),
    KEY idx_product_reviews_approved (is_approved)
);

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(120) PRIMARY KEY,
    `value` LONGTEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS email_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED DEFAULT NULL,
    email_type VARCHAR(80) NOT NULL,
    recipient VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    error_message TEXT DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_order_email_type (order_id, email_type)
);

CREATE TABLE IF NOT EXISTS email_send_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED DEFAULT NULL,
    email_type VARCHAR(80) NOT NULL,
    source VARCHAR(80) NOT NULL DEFAULT 'system',
    recipient VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    error_message TEXT DEFAULT NULL,
    provider VARCHAR(30) DEFAULT NULL,
    meta_json LONGTEXT DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_send_history_created (created_at),
    KEY idx_email_send_history_type (email_type),
    KEY idx_email_send_history_status (status),
    KEY idx_email_send_history_order (order_id)
);

CREATE TABLE IF NOT EXISTS cart_abandonments (
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
);

CREATE TABLE IF NOT EXISTS checkout_submit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(64) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'allowed',
    created_at DATETIME NOT NULL,
    KEY idx_checkout_submit_logs_ip_created (ip_address, created_at),
    KEY idx_checkout_submit_logs_created (created_at)
);

CREATE TABLE IF NOT EXISTS register_submit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(64) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'allowed',
    created_at DATETIME NOT NULL,
    KEY idx_register_submit_logs_ip_created (ip_address, created_at),
    KEY idx_register_submit_logs_created (created_at)
);

CREATE TABLE IF NOT EXISTS newsletter_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    blocks_json LONGTEXT NOT NULL,
    html_content LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS newsletter_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    name VARCHAR(190) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS newsletter_list_subscribers (
    list_id INT UNSIGNED NOT NULL,
    subscriber_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (list_id, subscriber_id)
);

CREATE TABLE IF NOT EXISTS newsletter_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    template_type VARCHAR(20) NOT NULL DEFAULT 'newsletter',
    template_ref VARCHAR(190) DEFAULT NULL,
    subscriber_list_id INT UNSIGNED DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    blocks_json LONGTEXT DEFAULT NULL,
    html_content LONGTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    total_recipients INT NOT NULL DEFAULT 0,
    total_sent INT NOT NULL DEFAULT 0,
    total_failed INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS newsletter_campaign_sends (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    subscriber_id INT UNSIGNED DEFAULT NULL,
    email VARCHAR(190) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    error_message TEXT DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_campaign_subscriber (campaign_id, subscriber_id)
);

CREATE TABLE IF NOT EXISTS newsletter_optin_forms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    list_id INT UNSIGNED NOT NULL,
    button_label VARCHAR(120) NOT NULL DEFAULT 'Ma abonez',
    success_message VARCHAR(255) NOT NULL DEFAULT 'Te-ai abonat cu succes.',
    fields_json LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS seo_pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_type VARCHAR(50) NOT NULL,
    page_ref VARCHAR(190) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    canonical_url VARCHAR(255) DEFAULT NULL,
    image_url VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uniq_page_ref (page_type, page_ref)
);

CREATE TABLE IF NOT EXISTS pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    html_content LONGTEXT NOT NULL,
    css_content LONGTEXT DEFAULT NULL,
    js_content LONGTEXT DEFAULT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS gallery_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    media_type VARCHAR(20) NOT NULL DEFAULT 'image',
    image_url TEXT NOT NULL,
    folder_id INT UNSIGNED DEFAULT NULL,
    alt_text VARCHAR(255) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS gallery_folders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    slug VARCHAR(140) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mannequin_points (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    point_key VARCHAR(120) NOT NULL UNIQUE,
    label VARCHAR(190) NOT NULL,
    x_percent DECIMAL(6,2) NOT NULL DEFAULT 50.00,
    y_percent DECIMAL(6,2) NOT NULL DEFAULT 50.00,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mannequin_point_products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    point_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mannequin_point_product (point_id, product_id)
);
