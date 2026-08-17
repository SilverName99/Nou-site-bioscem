<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Settings
{
    private const DEFAULTS = [
        'shipping_include_coupons' => '1',
        'shipping_free_bucharest' => '200',
        'shipping_free_province' => '200',
        'shipping_cost_bucharest' => '15',
        'shipping_cost_province' => '15',
        'shipping_max_cost' => '40',
        // Prețuri fixe de transport (înlocuiesc tariful curierului când sunt active).
        'shipping_fixed_enabled' => '0',
        'shipping_fixed_base' => '0',
        'shipping_fixed_extra_km' => '0',
        'shipping_fixed_fanbox' => '0',
        'shipping_fixed_fanbox_enabled' => '0',
        'fan_live_tariff_enabled' => '0',
        'fan_awb_auto' => '0',
        'fan_service_type' => 'Standard',
        'fan_shipping_payer' => 'recipient',
        'fan_cod_payer' => 'sender',
        'fan_shipment_type' => 'parcel',
        'fan_parcel_count' => '1',
        'fan_envelope_count' => '0',
        'fan_option_codes' => '',
        'fan_declared_value_mode' => 'order_total',
        'fan_pickup_point' => '',
        'fan_client_id' => '',
        'fan_api_username' => '',
        'fan_api_password' => '',
        'fan_sender_name' => '',
        'fan_sender_phone' => '',
        'fan_sender_email' => '',
        'fan_sender_county' => '',
        'fan_sender_locality' => '',
        'fan_sender_street' => '',
        'fan_sender_street_no' => '',
        'fan_sender_zip_code' => '',
        'fan_default_weight_kg' => '1',
        'fan_parcel_length_cm' => '',
        'fan_parcel_width_cm' => '',
        'fan_parcel_height_cm' => '',
        'default_vat_percent' => '19',
        'order_number_mode' => 'sequential',
        'order_number_next' => '203800',
        'cookie_banner_enabled' => '1',
        'cookie_banner_text' => 'Folosim cookies pentru funcționarea site-ului și, cu acordul tău, pentru statistici de trafic. Poți accepta toate cookie-urile sau doar pe cele strict necesare.',
        'cookie_banner_policy_url' => '',
        'welcome_popup_enabled' => '1',
        'welcome_popup_title' => 'Bine ați venit pe noul site BIOSCEM!',
        'welcome_popup_body' => "Vă informăm că am lansat o nouă platformă BIOSCEM. Pentru a putea comanda și beneficia în continuare de produsele comercializate de către SC BIOSCEM SRL, toți clienții și utilizatorii sunt rugați să își creeze un CONT NOU.\n\n⚠️ IMPORTANT: Conturile vechi și datele de autentificare anterioare nu mai sunt valabile. Accesul la noua platformă se face exclusiv prin intermediul unui cont nou, pe care vă rugăm să vi-l creați.\n\nNe cerem scuze pentru eventualele neplăceri cauzate de această schimbare și vă mulțumim pentru înțelegere, răbdare și încredere.\n\nVă dorim o experiență cât mai plăcută pe noua platformă!\n\nCu drag,\nEchipa BIOSCEM – Vitalitate & Protecție",
        'welcome_popup_version' => '1',
        'email_delivery_method' => 'smtp',
        'order_email_from_name' => 'Bioscem',
        'order_email_from_address' => 'no-reply@localhost',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_username' => '',
        'smtp_password' => '',
        'sendgrid_api_key' => '',
        'email_template_new_order_active' => '1',
        'email_template_new_order_recipient_mode' => 'client',
        'email_template_new_order_admin_recipients' => '',
        'email_template_new_order_subject' => 'Comanda noua {{order_number}}',
        'email_template_new_order_body' => '<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} a fost inregistrata cu succes.</p>',
        'email_template_processing_active' => '1',
        'email_template_processing_recipient_mode' => 'client',
        'email_template_processing_admin_recipients' => '',
        'email_template_processing_subject' => 'Comanda {{order_number}} este in procesare',
        'email_template_processing_body' => '<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} este in procesare.</p>',
        'email_template_shipped_active' => '1',
        'email_template_shipped_recipient_mode' => 'client',
        'email_template_shipped_admin_recipients' => '',
        'email_template_shipped_subject' => 'Comanda {{order_number}} a fost expediata',
        'email_template_shipped_body' => '<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} a fost expediata.</p><p>AWB: <strong>{{awb}}</strong></p><p>{{tracking_link}}</p>',
        'email_template_delivered_active' => '1',
        'email_template_delivered_recipient_mode' => 'client',
        'email_template_delivered_admin_recipients' => '',
        'email_template_delivered_subject' => 'Comandă livrată {{order_number}}',
        'email_template_delivered_body' => '<div style="text-align:center;padding-top:8px;"><div style="margin:0 auto 12px;color:#2f8d5b;font-size:56px;line-height:1;font-weight:700;">✅</div><h2 style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2></div><p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}}! 🎉</p><p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Comanda ta a fost livrată cu succes! Sperăm că produsele noastre naturale îți vor aduce bucurie.</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f7f4;border-radius:14px;padding:18px 18px 14px;margin:0 0 22px;"><tr><td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Nr. comandă</td><td style="padding:7px 8px;color:#0f172a;font-size:34px;line-height:1.05;font-weight:700;text-align:right;">#{{order_number}}</td></tr><tr><td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Livrat pe</td><td style="padding:7px 8px;color:#0f172a;font-size:17px;line-height:1.35;font-weight:600;text-align:right;">{{order_date}}</td></tr></table><p style="margin:0 0 24px;text-align:center;"><a href="{{order_action_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#2f8d5b;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Lasă o recenzie →</a></p><hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;"><p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p><p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>',
        'email_template_cancelled_active' => '1',
        'email_template_cancelled_recipient_mode' => 'client',
        'email_template_cancelled_admin_recipients' => '',
        'email_template_cancelled_subject' => 'Comandă anulată {{order_number}}',
        'email_template_cancelled_body' => '<div style="text-align:center;padding-top:8px;"><div style="margin:0 auto 14px;width:92px;height:92px;border-radius:999px;border:3px solid #ef4444;color:#ef4444;font-size:56px;line-height:86px;font-weight:700;">✕</div><h2 style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2></div><p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}},</p><p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Comanda ta a fost anulată conform solicitării. Dacă ai plătit online, suma va fi returnată în 3-5 zile lucrătoare.</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f7f4;border-radius:14px;padding:18px 18px 14px;margin:0 0 22px;"><tr><td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Nr. comandă</td><td style="padding:7px 8px;color:#0f172a;font-size:34px;line-height:1.05;font-weight:700;text-align:right;">#{{order_number}}</td></tr><tr><td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Sumă returnată</td><td style="padding:7px 8px;color:#0f172a;font-size:17px;line-height:1.35;font-weight:600;text-align:right;">{{order_total}}</td></tr></table><p style="margin:0 0 24px;text-align:center;"><a href="{{order_action_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#ef4444;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Contactează-ne →</a></p><hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;"><p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p><p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>',
        'email_template_abandoned_cart_active' => '1',
        'email_template_abandoned_cart_recipient_mode' => 'client',
        'email_template_abandoned_cart_admin_recipients' => '',
        'email_template_abandoned_cart_subject' => 'Ai uitat produse în coș',
        'email_template_abandoned_cart_body' => '<div style="text-align:center;padding-top:8px;"><div style="margin:0 auto 12px;color:#eaa325;font-size:56px;line-height:1;font-weight:700;">🛒</div><h2 style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2></div><p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}}! 👋</p><p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Am observat că ai lăsat câteva produse în coș. Le păstrăm pentru tine, dar nu pentru mult timp!</p><p style="margin:0 0 8px;color:#0f172a;font-size:32px;line-height:1.1;font-weight:700;">Produse:</p><div style="margin:0 0 18px;">{{cart_items_html}}</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 20px;"><tr><td style="padding:12px 0;border-top:1px solid #e5e7eb;color:#0f172a;font-size:36px;line-height:1.1;font-weight:700;">Total</td><td style="padding:12px 0;border-top:1px solid #e5e7eb;color:#0f172a;font-size:36px;line-height:1.1;font-weight:700;text-align:right;">{{cart_total}}</td></tr></table><p style="margin:0 0 24px;text-align:center;"><a href="{{cart_action_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#eaa325;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Finalizează comanda →</a></p><hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;"><p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p><p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>',
        'email_abandoned_after_minutes' => '60',
        'stripe_publishable_key' => '',
        'stripe_secret_key' => '',
        'stripe_webhook_secret' => '',
        'stripe_currency' => 'ron',
        // EuPlătesc — plata cu cardul prin redirect (procesatorul implicit).
        'euplatesc_enabled' => '0',
        'euplatesc_merchant_id' => '',
        'euplatesc_secret_key' => '',
        'euplatesc_currency' => 'RON',
        // Stripe rămâne disponibil ca alternativă, dar ascuns implicit.
        'stripe_enabled' => '0',
        // Mod mentenanță: vizitatorii văd „Revenim în curând", testerii văd site-ul.
        'maintenance_enabled' => '0',
        'maintenance_title' => 'Revenim în curând',
        'maintenance_message' => 'Lucrăm la magazin chiar acum. Ne întoarcem cât de repede putem.',
        'maintenance_key' => '',
        'maintenance_allowed_ips' => '',
        'customer_google_auth_enabled' => '0',
        'customer_google_client_id' => '',
        'customer_google_client_secret' => '',
        'loyalty_points_enabled' => '1',
        'loyalty_points_earn_rate' => '1',
        'loyalty_points_redeem_value' => '0.10',
        'loyalty_points_min_redeem' => '100',
        'loyalty_points_max_redeem_percent' => '50',
        'loyalty_points_promo_active' => '0',
        'loyalty_points_promo_multiplier' => '1',
        'loyalty_points_weekend_multiplier' => '1',
        'design_header_html' => '',
        'design_header_css' => '',
        'design_header_js' => '',
        'design_footer_html' => '',
        'design_footer_css' => '',
        'design_footer_js' => '',
        'design_menu_html' => '<a href="/">Acasă</a><a href="/magazin">Magazin</a><a href="/contact">Contact</a>',
        'design_menu_css' => '',
        'design_menu_js' => '',
        'floating_cart_enabled' => '1',
        'floating_cart_show_desktop' => '1',
        'floating_cart_show_mobile' => '1',
        'floating_cart_auto_open_on_add' => '1',
        'floating_cart_position' => 'right',
        'floating_cart_trigger_label' => 'Coș',
        'floating_cart_title' => 'Coșul tău',
        'floating_cart_view_cart_label' => 'Vezi coșul',
        'floating_cart_checkout_label' => 'Checkout',
        'floating_cart_accent_color' => '#0f766e',
        'floating_cart_badge_bg' => '#ffffff',
        'floating_cart_badge_text' => '#0f766e',
        'floating_cart_panel_width' => '420',
        'floating_cart_offset_x' => '18',
        'floating_cart_offset_y' => '18',
        'floating_cart_show_product_images' => '1',
        'floating_cart_show_view_cart_button' => '1',
        'floating_cart_show_checkout_button' => '1',
        'floating_cart_show_subtotal' => '1',
        'floating_cart_show_discount' => '1',
        'floating_cart_show_points_discount' => '1',
        'floating_cart_show_shipping' => '1',
        'floating_cart_show_vat' => '1',
        'floating_cart_show_points_earned' => '1',
        'floating_cart_points_position' => 'before_total',
        'floating_cart_points_text' => 'Primești {points} puncte la această comandă.',
        'floating_cart_free_shipping_threshold' => '200',
        'floating_cart_excluded_urls' => '',
        'mannequin_enabled' => '1',
        'mannequin_title' => 'Recomandări pe zone',
        'mannequin_subtitle' => 'Alege un punct de pe manechin pentru a vedea produsele recomandate.',
        'mannequin_empty_text' => 'Nu sunt produse pentru această categorie.',
        'mannequin_code' => '{{mannequin_section}}',
        'store_quantity_control_style' => 'default',
        'store_quantity_apply_product_template' => '0',
        'store_quantity_apply_floating_cart' => '0',
        'store_quantity_apply_cart_page' => '0',
        // Sertarul lateral „Oferte” (produse cu preț redus / dată de expirare).
        'store_bbd_sidebar_enabled' => '1',
        // Integrare ERP ANDAXI (comenzi + stoc).
        'erp_enabled' => '0',
        'erp_url' => '',
        'erp_api_key' => '',
        'erp_timeout' => '20',
        'erp_stock_enabled' => '0',
        // Adrese FAN per depozit (JSON: gestiuneId → adresă), pentru gestiuni pe județe.
        'fan_depozite_json' => '',
        'store_favicon_url' => '',
        'store_seo_home_title' => '',
        'store_seo_home_description' => '',
        'store_seo_default_description' => '',
        'store_seo_default_image_url' => '',
        'microsoft_clarity_enabled' => '0',
        'microsoft_clarity_project_id' => '',
        'google_site_verification' => '',
        'google_analytics_enabled' => '0',
        'google_analytics_id' => '',
        'google_tag_manager_enabled' => '0',
        'google_tag_manager_id' => '',
        'google_tag_manager_head_code' => '',
        'google_tag_manager_body_code' => '',
        'google_analytics_code' => '',
        'google_ads_enabled' => '0',
        'google_ads_conversion_id' => '',
        'google_ads_conversion_label' => '',
        'cache_pages_enabled' => '0',
        'cache_pages_rules_json' => '[]',
        'cache_exclusions_custom' => '',
        'cache_assets_enabled' => '0',
        'cache_assets_ttl_seconds' => '2592000',
        'cache_uploads_ttl_seconds' => '2592000',
        'cache_assets_versioning_mode' => 'query',
        'cache_assets_etag_enabled' => '1',
        'cache_assets_version_token' => '',
    ];

    public static function all(?PDO $db): array
    {
        if (!$db instanceof PDO) {
            return self::DEFAULTS;
        }

        $settings = self::DEFAULTS;
        $rows = $db->query('SELECT `key`, `value` FROM settings')->fetchAll();

        foreach ($rows as $row) {
            $settings[(string) $row['key']] = (string) $row['value'];
        }

        return $settings;
    }

    public static function save(?PDO $db, array $values): void
    {
        if (!$db instanceof PDO) {
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );

        foreach ($values as $key => $value) {
            $stmt->execute([
                'key' => (string) $key,
                'value' => (string) $value,
            ]);
        }
    }
}
