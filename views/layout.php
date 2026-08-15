<!doctype html>
<?php
$appConfig = require __DIR__ . '/../config/app.php';
$db = \App\Support\Database::connection($appConfig['db']);
$currentCustomer = $db instanceof \PDO ? \App\Support\CustomerAuth::user($db) : null;
$designSettings = \App\Support\Settings::all($db);
$assetVersionToken = \App\Support\ResponseCache::assetVersionToken($designSettings);
$assetVersionQuery = $assetVersionToken !== '' ? '&v=' . urlencode($assetVersionToken) : '';
$designHeader = (string) ($designSettings['design_header_html'] ?? '');
$designFooter = (string) ($designSettings['design_footer_html'] ?? '');
$designMenu = (string) ($designSettings['design_menu_html'] ?? '');
$designHeaderCss = (string) ($designSettings['design_header_css'] ?? '');
$designHeaderJs = (string) ($designSettings['design_header_js'] ?? '');
$designFooterCss = (string) ($designSettings['design_footer_css'] ?? '');
$designFooterJs = (string) ($designSettings['design_footer_js'] ?? '');
$designMenuCss = (string) ($designSettings['design_menu_css'] ?? '');
$designMenuJs = (string) ($designSettings['design_menu_js'] ?? '');
$clarityEnabled = (string) ($designSettings['microsoft_clarity_enabled'] ?? '0') === '1';
$clarityProjectId = preg_replace('/[^a-zA-Z0-9]/', '', trim((string) ($designSettings['microsoft_clarity_project_id'] ?? ''))) ?? '';
$googleSiteVerification = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) ($designSettings['google_site_verification'] ?? ''))) ?? '';
$gaEnabled = (string) ($designSettings['google_analytics_enabled'] ?? '0') === '1';
$gaId = preg_replace('/[^A-Za-z0-9\-]/', '', trim((string) ($designSettings['google_analytics_id'] ?? ''))) ?? '';
$gtmEnabled = (string) ($designSettings['google_tag_manager_enabled'] ?? '0') === '1';
$gtmId = preg_replace('/[^A-Za-z0-9\-]/', '', trim((string) ($designSettings['google_tag_manager_id'] ?? ''))) ?? '';
$gtmHeadCode = trim((string) ($designSettings['google_tag_manager_head_code'] ?? ''));
$gtmBodyCode = trim((string) ($designSettings['google_tag_manager_body_code'] ?? ''));
$gaCode = trim((string) ($designSettings['google_analytics_code'] ?? ''));
$adsEnabled = (string) ($designSettings['google_ads_enabled'] ?? '0') === '1';
$adsConversionId = preg_replace('/[^A-Za-z0-9\-]/', '', trim((string) ($designSettings['google_ads_conversion_id'] ?? ''))) ?? '';
$headerCartCount = \App\Support\Cart::countItems();
$headerCustomerLoggedIn = is_array($currentCustomer);
$headerCustomerPoints = $headerCustomerLoggedIn ? max(0, (int) ($currentCustomer['loyalty_points'] ?? 0)) : 0;
$cartCountTokens = ['{{cart_count}}', '{{ cart_count }}'];
$mobileMenuToken = '{{mobile_menu}}';
$mobileMenuTokenPattern = '/\{\{\s*mobile_menu\s*\}\}/i';
// Logo pentru sertarul de meniu mobil; se ia primul fișier existent din galerie.
$mobileMenuLogoPath = '/uploads/gallery/bioscem-logo.png';
foreach (['bioscem-logo.webp', 'bioscem-logo.png', 'bioscem-logo.jpg', 'bioscem-logo.svg'] as $logoCandidate) {
    if (is_file(__DIR__ . '/../public/uploads/gallery/' . $logoCandidate)) {
        $mobileMenuLogoPath = '/uploads/gallery/' . $logoCandidate;
        break;
    }
}
$mobileMenuFallbackHtml = '<ul class="menu-root"><li><a href="/">Acasă</a></li><li><a href="/magazin">Magazin</a></li><li><a href="/blog">Blog</a></li><li><a href="/contact">Contact</a></li></ul>';
$mobileMenuTokenAssetsEnabled = false;
$floatingCartEnabled = (string) ($designSettings['floating_cart_enabled'] ?? '1') === '1';
$floatingCartExcludedRaw = (string) ($designSettings['floating_cart_excluded_urls'] ?? '');
$floatingCartExcludedPaths = [];
foreach (preg_split('/\r\n|\r|\n/', $floatingCartExcludedRaw) ?: [] as $rawPath) {
    $candidatePath = trim((string) $rawPath);
    if ($candidatePath === '') {
        continue;
    }
    if (preg_match('/^https?:\/\//i', $candidatePath) === 1) {
        $parsedPath = parse_url($candidatePath, PHP_URL_PATH);
        if (is_string($parsedPath) && trim($parsedPath) !== '') {
            $candidatePath = trim($parsedPath);
        } else {
            continue;
        }
    }
    if (!str_starts_with($candidatePath, '/')) {
        $candidatePath = '/' . ltrim($candidatePath, '/');
    }
    $candidatePath = rtrim($candidatePath, '/');
    if ($candidatePath === '') {
        $candidatePath = '/';
    }
    $floatingCartExcludedPaths[] = $candidatePath;
}
$floatingCartExcludedPaths = array_values(array_unique($floatingCartExcludedPaths));
$currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$currentPath = is_string($currentPath) ? $currentPath : '/';
$currentPath = rtrim($currentPath, '/');
if ($currentPath === '') {
    $currentPath = '/';
}
if (in_array($currentPath, $floatingCartExcludedPaths, true)) {
    $floatingCartEnabled = false;
}
$pointsTextTemplate = trim((string) ($designSettings['floating_cart_points_text'] ?? ''));
if ($pointsTextTemplate === '') {
    $legacyPrefix = trim((string) ($designSettings['floating_cart_points_label_prefix'] ?? 'Primești'));
    $legacySuffix = trim((string) ($designSettings['floating_cart_points_label_suffix'] ?? 'puncte la această comandă'));
    $pointsTextTemplate = trim($legacyPrefix . ' {points} ' . $legacySuffix);
    if ($pointsTextTemplate === '') {
        $pointsTextTemplate = 'Primești {points} puncte la această comandă.';
    }
}
$floatingCartConfig = [
    'enabled' => $floatingCartEnabled ? 1 : 0,
    'label' => (string) ($designSettings['floating_cart_trigger_label'] ?? 'Coș'),
    'position' => in_array((string) ($designSettings['floating_cart_position'] ?? 'right'), ['left', 'right'], true)
        ? (string) ($designSettings['floating_cart_position'] ?? 'right')
        : 'right',
    'panelTitle' => (string) ($designSettings['floating_cart_title'] ?? 'Coșul tău'),
    'showSku' => 0,
    'showPrice' => 1,
    'showSubtotal' => ((string) ($designSettings['floating_cart_show_subtotal'] ?? '1') === '1') ? 1 : 0,
    'showDiscount' => ((string) ($designSettings['floating_cart_show_discount'] ?? '1') === '1') ? 1 : 0,
    'showPointsDiscount' => ((string) ($designSettings['floating_cart_show_points_discount'] ?? '1') === '1') ? 1 : 0,
    'showShipping' => ((string) ($designSettings['floating_cart_show_shipping'] ?? '1') === '1') ? 1 : 0,
    'showVat' => ((string) ($designSettings['floating_cart_show_vat'] ?? '1') === '1') ? 1 : 0,
    'showEstimatedEarnedPoints' => ((string) ($designSettings['floating_cart_show_points_earned'] ?? '1') === '1') ? 1 : 0,
    'pointsPosition' => in_array((string) ($designSettings['floating_cart_points_position'] ?? 'before_total'), ['before_total', 'after_total'], true)
        ? (string) ($designSettings['floating_cart_points_position'] ?? 'before_total')
        : 'before_total',
    'pointsTextTemplate' => $pointsTextTemplate,
    'buttonBg' => (string) ($designSettings['floating_cart_accent_color'] ?? '#0f766e'),
    'buttonText' => '#ffffff',
    'buttonCounterBg' => (string) ($designSettings['floating_cart_badge_bg'] ?? '#ffffff'),
    'buttonCounterText' => (string) ($designSettings['floating_cart_badge_text'] ?? '#0f766e'),
    'showOnDesktop' => ((string) ($designSettings['floating_cart_show_desktop'] ?? '1') === '1') ? 1 : 0,
    'showOnMobile' => ((string) ($designSettings['floating_cart_show_mobile'] ?? '1') === '1') ? 1 : 0,
    // Keep both keys for backward compatibility across script revisions.
    'autoOpenOnAdd' => ((string) ($designSettings['floating_cart_auto_open_on_add'] ?? '1') === '1') ? 1 : 0,
    'openOnAdd' => ((string) ($designSettings['floating_cart_auto_open_on_add'] ?? '1') === '1') ? 1 : 0,
    'showViewCartButton' => ((string) ($designSettings['floating_cart_show_view_cart_button'] ?? '1') === '1') ? 1 : 0,
    'showCheckoutButton' => ((string) ($designSettings['floating_cart_show_checkout_button'] ?? '1') === '1') ? 1 : 0,
    'viewCartLabel' => (string) ($designSettings['floating_cart_view_cart_label'] ?? 'Vezi coșul'),
    'checkoutLabel' => (string) ($designSettings['floating_cart_checkout_label'] ?? 'Finalizează comanda'),
    'continueShoppingLabel' => (string) ($designSettings['floating_cart_continue_shopping_label'] ?? 'Continuă cumpărăturile'),
    'emptyCtaLabel' => (string) ($designSettings['floating_cart_empty_cta_label'] ?? 'Vezi produsele'),
    'panelWidth' => (int) ($designSettings['floating_cart_panel_width'] ?? 420),
    'offsetX' => (int) ($designSettings['floating_cart_offset_x'] ?? 18),
    'offsetY' => (int) ($designSettings['floating_cart_offset_y'] ?? 18),
    'triggerIconSvg' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l1.7 8.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 1.9-1.4l1.5-5.2H7.4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.7" cy="19" r="1.4" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.5" cy="19" r="1.4" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>',
    'showImages' => ((string) ($designSettings['floating_cart_show_product_images'] ?? '1') === '1') ? 1 : 0,
    'triggerIcon' => (string) ($designSettings['floating_cart_trigger_icon'] ?? ''),
    'quantityControlStyle' => in_array((string) ($designSettings['store_quantity_control_style'] ?? 'default'), ['default', 'stepper'], true)
        ? (string) ($designSettings['store_quantity_control_style'] ?? 'default')
        : 'default',
    'quantityApplyFloatingCart' => (
        ((string) ($designSettings['store_quantity_apply_floating_cart'] ?? '0') === '1')
        && ((string) ($designSettings['store_quantity_control_style'] ?? 'default') === 'stepper')
    ) ? 1 : 0,
    'quantityControlEnabled' => (
        ((string) ($designSettings['store_quantity_apply_floating_cart'] ?? '0') === '1')
        && ((string) ($designSettings['store_quantity_control_style'] ?? 'default') === 'stepper')
    ) ? 1 : 0,
];
// Sertarul lateral „Oferte” se poate dezactiva din Admin -> Setări magazin.
$bbdSidebarEnabled = (string) ($designSettings['store_bbd_sidebar_enabled'] ?? '1') !== '0';
$bbdSidebarOffers = $bbdSidebarEnabled ? \App\Support\BbdSidebarOffers::load($db, 10) : [];
$siteFaviconUrl = trim((string) ($designSettings['store_favicon_url'] ?? ''));
if ($siteFaviconUrl === '') {
    $siteFaviconUrl = '/assets/img/product-placeholder.svg';
}
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';
$requestPath = rtrim($requestPath, '/');
if ($requestPath === '') {
    $requestPath = '/';
}
$siteBaseTitle = trim((string) ($designSettings['store_name'] ?? 'Bioscem Shop'));
if ($siteBaseTitle === '') {
    $siteBaseTitle = 'Bioscem Shop';
}
$pageTitle = trim((string) ($title ?? ''));
$homeSeoTitle = trim((string) ($designSettings['store_seo_home_title'] ?? ''));
$metaTitleFinal = trim((string) ($metaTitle ?? ''));
if ($metaTitleFinal === '') {
    if ($requestPath === '/' && $homeSeoTitle !== '') {
        $metaTitleFinal = $homeSeoTitle;
    } elseif ($pageTitle === '' || strcasecmp($pageTitle, $siteBaseTitle) === 0) {
        $metaTitleFinal = $siteBaseTitle;
    } else {
        $metaTitleFinal = $pageTitle . ' | ' . $siteBaseTitle;
    }
}
$metaDescriptionFinal = trim((string) ($metaDescription ?? ''));
if ($metaDescriptionFinal === '') {
    if ($requestPath === '/') {
        $metaDescriptionFinal = trim((string) ($designSettings['store_seo_home_description'] ?? ''));
    }
    if ($metaDescriptionFinal === '') {
        $metaDescriptionFinal = trim((string) ($designSettings['store_seo_default_description'] ?? ''));
    }
}
$appUrl = rtrim((string) ($appConfig['url'] ?? ''), '/');
if ($appUrl === '') {
    $https = (string) ($_SERVER['HTTPS'] ?? '');
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $appUrl = $scheme . '://' . $host;
}
$canonicalUrlFinal = trim((string) ($metaCanonicalUrl ?? ''));
if ($canonicalUrlFinal === '') {
    $canonicalUrlFinal = $appUrl . ($requestPath === '/' ? '/' : $requestPath);
}
$metaImageUrlFinal = trim((string) ($metaImageUrl ?? ''));
if ($metaImageUrlFinal === '') {
    $metaImageUrlFinal = trim((string) ($designSettings['store_seo_default_image_url'] ?? ''));
}
if ($metaImageUrlFinal !== '' && !preg_match('/^https?:\/\//i', $metaImageUrlFinal)) {
    $metaImageUrlFinal = str_starts_with($metaImageUrlFinal, '/')
        ? ($appUrl . $metaImageUrlFinal)
        : ($appUrl . '/' . ltrim($metaImageUrlFinal, '/'));
}
$siteFaviconUrlEscaped = htmlspecialchars($siteFaviconUrl, ENT_QUOTES);
$designHeaderOutput = $designHeader;
if (trim($designHeaderOutput) !== '' && trim($designMenu) !== '') {
    $designHeaderOutput = str_replace(['{{menu}}', '{{ menu }}'], $designMenu, $designHeaderOutput);
}
if (trim($designHeaderOutput) !== '') {
    $designHeaderOutput = str_replace($cartCountTokens, (string) $headerCartCount, $designHeaderOutput);
}
if (trim($designHeaderOutput) !== '' && preg_match($mobileMenuTokenPattern, $designHeaderOutput) === 1) {
    $mobileMenuTokenAssetsEnabled = true;
    $mobileMenuHtml = trim($designMenu) !== '' ? $designMenu : $mobileMenuFallbackHtml;
    $mobileMenuTokenMarkup = '<div class="bv-mobile-menu-token" data-bv-mobile-menu>'
        . '<button class="bv-mobile-menu-token__toggle" type="button" aria-label="Deschide meniul" aria-expanded="false" data-bv-mobile-menu-toggle>'
        . '<span></span><span></span><span></span>'
        . '</button>'
        . '<div class="bv-mobile-menu-token__overlay" data-bv-mobile-menu-overlay hidden></div>'
        . '<aside class="bv-mobile-menu-token__drawer" data-bv-mobile-menu-drawer aria-hidden="true">'
        . '<div class="bv-mobile-menu-token__drawer-head">'
        . '<a href="/" class="bv-mobile-menu-token__brand" aria-label="Acasă">'
        . '<img src="' . htmlspecialchars($mobileMenuLogoPath, ENT_QUOTES) . '" alt="Bioscem" loading="lazy">'
        . '</a>'
        . '<button class="bv-mobile-menu-token__close" type="button" aria-label="Închide meniul" data-bv-mobile-menu-close>&times;</button>'
        . '</div>'
        . '<nav class="bv-mobile-menu-token__nav" aria-label="Meniu mobil">' . $mobileMenuHtml . '</nav>'
        . '<div class="bv-mobile-menu-token__links">'
        . '<a href="/login">Contul meu</a>'
        . '</div>'
        . '</aside>'
        . '</div>';
    $designHeaderOutput = (string) preg_replace($mobileMenuTokenPattern, $mobileMenuTokenMarkup, $designHeaderOutput);
}
?>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($googleSiteVerification !== ''): ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars($googleSiteVerification, ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php if ($gtmEnabled && $gtmHeadCode !== ''): ?>
    <?= $gtmHeadCode ?>
    <?php elseif ($gtmEnabled && $gtmId !== ''): ?>
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','<?= htmlspecialchars($gtmId, ENT_QUOTES) ?>');</script>
    <!-- End Google Tag Manager -->
    <?php endif; ?>
    <?php if ($gaEnabled && $gaCode !== ''): ?>
    <?= $gaCode ?>
        <?php if ($adsEnabled && $adsConversionId !== ''): ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('config', '<?= htmlspecialchars($adsConversionId, ENT_QUOTES) ?>');
        </script>
        <?php endif; ?>
    <?php elseif (($gaEnabled && $gaId !== '') || ($adsEnabled && $adsConversionId !== '')): ?>
    <?php $gtagBoot = $gaEnabled && $gaId !== '' ? $gaId : $adsConversionId; ?>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($gtagBoot, ENT_QUOTES) ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        <?php if ($gaEnabled && $gaId !== ''): ?>
        gtag('config', '<?= htmlspecialchars($gaId, ENT_QUOTES) ?>');
        <?php endif; ?>
        <?php if ($adsEnabled && $adsConversionId !== ''): ?>
        gtag('config', '<?= htmlspecialchars($adsConversionId, ENT_QUOTES) ?>');
        <?php endif; ?>
    </script>
    <?php endif; ?>
    <title><?= htmlspecialchars($metaTitleFinal, ENT_QUOTES) ?></title>
    <?php if ($metaDescriptionFinal !== ''): ?>
        <meta name="description" content="<?= htmlspecialchars($metaDescriptionFinal, ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php if ($canonicalUrlFinal !== ''): ?>
        <link rel="canonical" href="<?= htmlspecialchars($canonicalUrlFinal, ENT_QUOTES) ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($metaTitleFinal, ENT_QUOTES) ?>">
    <?php if ($metaDescriptionFinal !== ''): ?>
        <meta property="og:description" content="<?= htmlspecialchars($metaDescriptionFinal, ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php if ($canonicalUrlFinal !== ''): ?>
        <meta property="og:url" content="<?= htmlspecialchars($canonicalUrlFinal, ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php if ($metaImageUrlFinal !== ''): ?>
        <meta property="og:image" content="<?= htmlspecialchars($metaImageUrlFinal, ENT_QUOTES) ?>">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="<?= htmlspecialchars($metaImageUrlFinal, ENT_QUOTES) ?>">
    <?php else: ?>
        <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <meta name="twitter:title" content="<?= htmlspecialchars($metaTitleFinal, ENT_QUOTES) ?>">
    <?php if ($metaDescriptionFinal !== ''): ?>
        <meta name="twitter:description" content="<?= htmlspecialchars($metaDescriptionFinal, ENT_QUOTES) ?>">
    <?php endif; ?>
    <link rel="icon" href="<?= $siteFaviconUrlEscaped ?>">
    <link rel="shortcut icon" href="<?= $siteFaviconUrlEscaped ?>">
    <link rel="apple-touch-icon" href="<?= $siteFaviconUrlEscaped ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <?php $cssVersion = @filemtime(__DIR__ . '/../public/assets/css/app.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $cssVersion ?><?= $assetVersionQuery ?>">
    <?php
    $designCss = trim(implode("\n", array_filter([
        $designHeaderCss,
        $designMenuCss,
        $designFooterCss,
    ], static fn (string $part): bool => trim($part) !== '')));
    ?>
    <?php if ($designCss !== ''): ?>
        <style><?= $designCss ?></style>
    <?php endif; ?>
    <style>
        .bv-header-search-shell{
            width:100%;
            max-height:0;
            overflow:hidden;
            opacity:0;
            transform:translateY(-8px);
            transition:max-height .28s ease,opacity .22s ease,transform .28s ease;
            background:#ffffff;
            border-bottom:1px solid #e6ece8;
        }
        .bv-header-search-shell.is-open{
            max-height:560px;
            opacity:1;
            transform:translateY(0);
        }
        .bv-header-search-shell__inner{
            width:min(1100px,calc(100% - 20px));
            margin:0 auto;
            padding:12px 0 14px;
        }
        .bv-header-search-box{
            position:relative;
            background:#ffffff;
            border:1px solid #dfe8e2;
            border-radius:14px;
            box-shadow:0 10px 26px rgba(13,32,23,.08);
            overflow:hidden;
        }
        .bv-header-search-input-wrap{
            display:flex;
            align-items:center;
            gap:10px;
            padding:10px 12px;
            border-bottom:1px solid #edf2ee;
        }
        .bv-header-search-input-wrap svg{
            width:18px;
            height:18px;
            color:#5d7868;
            flex:0 0 auto;
        }
        .bv-header-search-input{
            border:0;
            outline:none;
            flex:1 1 auto;
            font:500 15px/1.25 "DM Sans",Arial,sans-serif;
            color:#1f372b;
            background:transparent;
            padding:2px 0;
        }
        .bv-header-search-close{
            border:0;
            background:#f2f7f4;
            color:#3f6451;
            width:28px;
            height:28px;
            border-radius:999px;
            font:700 16px/1 "DM Sans",Arial,sans-serif;
            cursor:pointer;
            flex:0 0 auto;
        }
        .bv-header-search-results{
            max-height:380px;
            overflow:auto;
            display:grid;
        }
        .bv-header-search-state{
            margin:0;
            padding:12px 14px;
            font:500 13px/1.35 "DM Sans",Arial,sans-serif;
            color:#6b8275;
        }
        .bv-header-search-item{
            display:grid;
            grid-template-columns:54px minmax(0,1fr);
            gap:10px;
            align-items:start;
            text-decoration:none;
            padding:10px 12px;
            border-top:1px solid #edf2ee;
            background:#fff;
        }
        .bv-header-search-item:hover{background:#f8fcfa;}
        .bv-header-search-item img{
            width:54px;
            height:54px;
            border-radius:10px;
            object-fit:cover;
            border:1px solid #e5ece7;
        }
        .bv-header-search-item h4{
            margin:1px 0 4px;
            font:700 14px/1.2 "DM Sans",Arial,sans-serif;
            color:#20382d;
        }
        .bv-header-search-item p{
            margin:0;
            font:500 12px/1.4 "DM Sans",Arial,sans-serif;
            color:#5f786b;
        }
        .bv-header-search-item mark{
            background:#d7f4e4;
            color:#114d31;
            padding:0 1px;
            border-radius:3px;
        }
        .bv-header-account-points-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:22px;
            height:20px;
            padding:0 6px;
            margin-left:6px;
            border-radius:999px;
            border:1px solid #9fd7b6;
            background:#ebf8f0;
            color:#1f7a50;
            font:700 11px/1 "DM Sans",Arial,sans-serif;
            letter-spacing:0;
            white-space:nowrap;
            line-height:1;
        }
        .bv-header-account-points-anchor{
            display:inline-flex;
            align-items:center;
            gap:6px;
            position:relative;
            vertical-align:middle;
        }
        .bv-header-account-points-anchor--icon{
            overflow:visible !important;
        }
        .bv-header-account-points-anchor--icon .bv-header-account-points-badge{
            position:absolute;
            top:-7px;
            right:-16px;
            margin-left:0;
            z-index:2;
        }
        .bv-bbd-sidebar{
            --bv-bbd-sidebar-handle: 54px;
            position:fixed;
            left:0;
            top:50%;
            width:min(330px, calc(100vw - 12px));
            max-height:min(74vh, 620px);
            transform:translate3d(calc(-100% + var(--bv-bbd-sidebar-handle)), -50%, 0);
            z-index:1300;
            display:grid;
            grid-template-columns:minmax(0,1fr) var(--bv-bbd-sidebar-handle);
            border-radius:0 16px 16px 0;
            overflow:hidden;
            box-shadow:0 18px 38px rgba(2, 6, 23, .24);
            background:#ffffff;
            border:1px solid #e5e7eb;
            border-left:0;
            transition:transform .32s cubic-bezier(.22,.61,.36,1), box-shadow .2s ease;
        }
        .bv-bbd-sidebar.is-open{
            transform:translate3d(0, -50%, 0);
            box-shadow:0 20px 46px rgba(2, 6, 23, .28);
        }
        .bv-bbd-sidebar.is-dragging{
            transition:none;
            user-select:none;
        }
        .bv-bbd-sidebar__handle{
            order:2;
            border:0;
            border-left:1px solid #e5e7eb;
            background:linear-gradient(180deg, #16a34a 0%, #15803d 100%) !important;
            color:#ffffff !important;
            cursor:grab;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:10px 0;
            touch-action:none;
        }
        .bv-bbd-sidebar__handle span{
            display:inline-block;
            transform:rotate(-90deg);
            transform-origin:center;
            text-transform:uppercase;
            letter-spacing:.16em;
            font:800 12px/1 "DM Sans", Arial, sans-serif;
            white-space:nowrap;
            pointer-events:none;
        }
        .bv-bbd-sidebar__handle:focus-visible{
            outline:2px solid #0f766e;
            outline-offset:-3px;
        }
        .bv-bbd-sidebar__panel{
            order:1;
            background:#ffffff;
            display:grid;
            grid-template-rows:auto minmax(0,1fr);
            min-height:220px;
        }
        .bv-bbd-sidebar__head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:12px 14px;
            border-bottom:1px solid #eef2f7;
        }
        .bv-bbd-sidebar__head h3{
            margin:0;
            font:800 16px/1.2 "DM Sans", Arial, sans-serif;
            color:#0f172a;
        }
        .bv-bbd-sidebar__head p{
            margin:2px 0 0;
            font:500 12px/1.3 "DM Sans", Arial, sans-serif;
            color:#64748b;
        }
        .bv-bbd-sidebar__close{
            border:1px solid #dbe2ea;
            background:#f8fafc;
            color:#334155;
            border-radius:10px;
            width:30px;
            height:30px;
            font:700 18px/1 "DM Sans", Arial, sans-serif;
            cursor:pointer;
        }
        .bv-bbd-sidebar__list{
            margin:0;
            padding:8px 10px 10px;
            list-style:none;
            display:grid;
            gap:8px;
            overflow:auto;
            min-height:0;
        }
        .bv-bbd-sidebar__item{
            display:grid;
            grid-template-columns:56px minmax(0,1fr);
            gap:10px;
            text-decoration:none;
            border:1px solid #ebf0f5;
            border-radius:12px;
            padding:8px;
            color:#0f172a;
            background:#fff;
            transition:border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }
        .bv-bbd-sidebar__item:hover{
            border-color:#d1fae5;
            transform:translateY(-1px);
            box-shadow:0 8px 18px rgba(15, 118, 110, .13);
        }
        .bv-bbd-sidebar__item img{
            width:56px;
            height:56px;
            border-radius:10px;
            object-fit:cover;
            border:1px solid #e2e8f0;
            background:#fff;
        }
        .bv-bbd-sidebar__item-name{
            margin:0;
            font:700 13px/1.3 "DM Sans", Arial, sans-serif;
            color:#0f172a;
        }
        .bv-bbd-sidebar__item-label{
            margin:3px 0 0;
            font:500 11px/1.35 "DM Sans", Arial, sans-serif;
            color:#64748b;
        }
        .bv-bbd-sidebar__item-prices{
            margin-top:5px;
            display:flex;
            align-items:baseline;
            flex-wrap:wrap;
            gap:6px;
        }
        .bv-bbd-sidebar__item-prices strong{
            font:800 14px/1.2 "DM Sans", Arial, sans-serif;
            color:#0f766e;
        }
        .bv-bbd-sidebar__item-prices del{
            font:600 11px/1.2 "DM Sans", Arial, sans-serif;
            color:#94a3b8;
        }
        .bv-bbd-sidebar.is-intro{
            animation:bvBbdSidebarNudge .82s ease-in-out 2;
        }
        @keyframes bvBbdSidebarNudge{
            0%,100%{margin-left:0;}
            40%{margin-left:8px;}
            70%{margin-left:2px;}
        }
        @media (max-width:768px){
            .bv-bbd-sidebar{
                --bv-bbd-sidebar-handle: 36px;
                top:auto;
                bottom:14px;
                width:min(285px, calc(100vw - 10px));
                transform:translate3d(calc(-100% + var(--bv-bbd-sidebar-handle)), 0, 0);
                max-height:min(68vh, 440px);
                border-radius:0 16px 16px 0;
            }
            .bv-bbd-sidebar:not(.is-open){
                clip-path:inset(26% 0 26% calc(100% - var(--bv-bbd-sidebar-handle)));
                background:transparent;
                border-color:transparent;
                box-shadow:none;
            }
            .bv-bbd-sidebar.is-open{
                clip-path:none;
            }
            .bv-bbd-sidebar__handle{
                padding:4px 0;
                align-self:center;
                height:48%;
                min-height:92px;
                max-height:170px;
                border-radius:12px 0 0 12px;
                transform:rotate(180deg);
                transform-origin:center;
            }
            .bv-bbd-sidebar__handle span{
                font-size:10px;
                letter-spacing:.11em;
                transform:rotate(90deg);
            }
            .bv-bbd-sidebar__list{
                max-height:248px;
                overflow-y:auto;
                overscroll-behavior:contain;
            }
            .bv-bbd-sidebar.is-open{
                transform:translate3d(0, 0, 0);
            }
        }
        @media (min-width:981px){
            .bv-header-account-points-anchor--icon{
                margin-right:12px;
            }
            .bv-header-account-points-anchor--icon .bv-header-account-points-badge{
                top:calc(100% + 2px);
                right:auto;
                left:50%;
                transform:translateX(-50%);
                min-width:18px;
                height:16px;
                padding:0 4px;
                font-size:9px;
            }
        }
        .bv-mobile-menu-token{display:none}
        @media (max-width:980px){
            .bv-mainbar-row.bv-mobile-menu-token-row{
                grid-template-columns:auto minmax(0,1fr) auto!important;
                gap:10px!important;
                padding:8px 0!important;
                width:100%!important;
                overflow:hidden;
            }
            .bv-mainbar-row.bv-mobile-menu-token-row .bv-menu{
                display:none!important;
            }
            .bv-mainbar-row.bv-mobile-menu-token-row .bv-logo{
                min-width:0;
                overflow:hidden;
            }
            .bv-mainbar-row.bv-mobile-menu-token-row .bv-logo img{
                max-width:100%;
                height:auto;
                max-height:48px;
                display:block;
            }
            .bv-mainbar-row.bv-mobile-menu-token-row .bv-right{
                min-width:0;
            }
            body.site-shell{overflow-x:hidden}
            .bv-mobile-menu-token{display:inline-flex;align-items:center}
            .bv-mobile-menu-token__toggle{
                width:38px;height:38px;border-radius:10px;border:1px solid #d6dde0;background:#fff;
                display:inline-flex;flex-direction:column;justify-content:center;align-items:center;gap:4px;cursor:pointer;
            }
            .bv-mobile-menu-token__toggle span{
                width:18px;height:2px;border-radius:2px;background:#2f4a3c;display:block;
            }
            .bv-mobile-menu-token__overlay{
                position:fixed;inset:0;background:rgba(15,23,42,.45);opacity:0;pointer-events:none;
                transition:opacity .24s ease;z-index:1398;
            }
            .bv-mobile-menu-token__drawer{
                position:fixed;left:0;top:0;bottom:0;width:min(86vw,360px);background:#fff;
                transform:translateX(-104%);transition:transform .28s ease;z-index:1399;
                box-shadow:16px 0 30px rgba(2,6,23,.15);display:grid;grid-template-rows:auto minmax(0,1fr) auto;
                max-width:100vw;
            }
            .bv-mobile-menu-token__drawer-head{
                min-height:68px;padding:12px 14px;border-bottom:1px solid #e5e7eb;
                display:flex;align-items:center;justify-content:space-between;
            }
            .bv-mobile-menu-token__brand img{height:40px;width:auto;display:block}
            .bv-mobile-menu-token__close{
                width:34px;height:34px;border-radius:10px;border:1px solid #d1d5db;background:#fff;
                font-size:22px;line-height:1;color:#334155;cursor:pointer;
            }
            .bv-mobile-menu-token__nav{
                padding:8px 12px 4px;overflow:auto;display:block;min-height:0;
            }
            .bv-mobile-menu-token__nav .menu-root,
            .bv-mobile-menu-token__nav .submenu{
                list-style:none;margin:0;padding:0;display:block;
            }
            .bv-mobile-menu-token__nav .menu-root>li{
                border-bottom:1px solid #eef2f7;position:relative;
            }
            .bv-mobile-menu-token__nav .menu-root>li>a,
            .bv-mobile-menu-token__nav .submenu a{
                display:block;text-decoration:none;color:#1f2937;padding:12px 8px;font-weight:700;font-size:15px;
            }
            .bv-mobile-menu-token__nav .menu-root>li.has-submenu>a{
                position:relative;
                padding-right:52px;
            }
            .bv-mobile-menu-token__nav .menu-root>li.has-submenu>a::before{
                content:"";
                position:absolute;
                right:8px;
                top:50%;
                transform:translateY(-50%);
                width:24px;
                height:24px;
                border-radius:8px;
                border:1px solid #dbe2ea;
                background:#f8fafc;
                box-shadow:0 1px 0 rgba(2,6,23,.03);
            }
            .bv-mobile-menu-token__nav .menu-root>li.has-submenu>a::after{
                content:"";
                position:absolute;
                right:16px;
                top:50%;
                width:7px;
                height:7px;
                border-right:2px solid #475569;
                border-bottom:2px solid #475569;
                transform:translateY(-65%) rotate(45deg);
                transition:transform .18s ease;
                pointer-events:none;
            }
            .bv-mobile-menu-token__nav .menu-root>li.has-submenu.is-open>a::after{
                transform:translateY(-28%) rotate(-135deg);
            }
            .bv-mobile-menu-token__nav .submenu{
                display:block!important;
                position:static!important;
                margin:0!important;
                min-width:0!important;
                box-shadow:none!important;
                border:0!important;
                border-radius:0!important;
                background:transparent!important;
                padding:0 0 8px 12px!important;
                max-height:0;
                opacity:0;
                overflow:hidden;
                transform:translateY(-4px);
                transition:max-height .26s ease, opacity .2s ease, transform .2s ease;
            }
            .bv-mobile-menu-token__nav li:hover>.submenu,
            .bv-mobile-menu-token__nav li:focus-within>.submenu{
                max-height:0;
            }
            .bv-mobile-menu-token__nav li.is-open>.submenu,
            .bv-mobile-menu-token__nav li.has-submenu.is-open:hover>.submenu,
            .bv-mobile-menu-token__nav li.has-submenu.is-open:focus-within>.submenu{
                max-height:280px;
                opacity:1;
                transform:translateY(0);
            }
            .bv-mobile-menu-token__nav .submenu a{
                font-size:14px;font-weight:600;color:#4b5563;padding:9px 8px;
            }
            .bv-mobile-menu-token__links{
                margin-top:0;border-top:1px solid #e5e7eb;padding:10px 12px calc(12px + env(safe-area-inset-bottom));display:grid;gap:8px;background:#fff;
            }
            .bv-mobile-menu-token__links a{
                text-decoration:none;color:#1f2937;background:#f8fafc;border:1px solid #e2e8f0;
                border-radius:10px;padding:10px 12px;font-weight:700;display:flex;justify-content:space-between;align-items:center;
            }
            body.bv-mobile-menu-open{overflow:hidden}
            body.bv-mobile-menu-open .bv-mobile-menu-token__overlay{opacity:1;pointer-events:auto}
            body.bv-mobile-menu-open .bv-mobile-menu-token__drawer{transform:translateX(0)}
        }
        @media (min-width:981px){
            .bv-mobile-menu-token{display:none!important}
        }
        @media (max-width:1024px){
            .bv-head .bv-wrap{
                width:95%!important;
            }
        }
        @media (max-width:980px){
            .bv-header-account-points-anchor--icon .bv-header-account-points-badge{
                top:-5px;
                right:-10px;
                left:auto;
                transform:none;
                min-width:18px;
                height:16px;
                padding:0 4px;
                font-size:9px;
            }
        }
    </style>
</head>
<body class="site-shell">
    <?php if ($gtmEnabled && $gtmBodyCode !== ''): ?>
    <?= $gtmBodyCode ?>
    <?php elseif ($gtmEnabled && $gtmId !== ''): ?>
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= htmlspecialchars($gtmId, ENT_QUOTES) ?>"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
    <?php endif; ?>
    <?php if (trim($designHeaderOutput) !== ''): ?>
        <?= $designHeaderOutput ?>
    <?php else: ?>
        <header class="header">
            <div class="container site-container header-inner">
                <a href="/" class="logo">Bioscem Shop (Custom)</a>
                <nav class="nav">
                    <?php if (trim($designMenu) !== ''): ?>
                        <?= $designMenu ?>
                    <?php else: ?>
                        <a href="/">Acasă</a>
                        <a href="/magazin">Magazin</a>
                        <a href="/cos">Coș (<?= \App\Support\Cart::countItems() ?>)</a>
                        <a href="/checkout">Checkout</a>
                        <a href="/blog">Blog</a>
                        <?php if (is_array($currentCustomer)): ?>
                            <a href="/contul-meu">Cont (<?= htmlspecialchars((string) ($currentCustomer['first_name'] ?? 'Client'), ENT_QUOTES) ?>)</a>
                        <?php else: ?>
                            <a href="/login">Contul meu</a>
                        <?php endif; ?>
                        <a href="/contact">Contact</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>
    <?php endif; ?>

    <?php if ($bbdSidebarEnabled): ?>
    <aside class="bv-bbd-sidebar" data-bv-bbd-sidebar>
        <button
            class="bv-bbd-sidebar__handle"
            type="button"
            aria-label="Deschide ofertele BBD"
            aria-expanded="false"
            aria-controls="bv-bbd-sidebar-panel"
            data-bv-bbd-sidebar-handle
        ><span>Oferte</span></button>
        <div class="bv-bbd-sidebar__panel" id="bv-bbd-sidebar-panel">
            <div class="bv-bbd-sidebar__head">
                <div>
                    <h3>Produse cu preț redus</h3>
                </div>
                <button type="button" class="bv-bbd-sidebar__close" data-bv-bbd-sidebar-close aria-label="Închide panoul">×</button>
            </div>
            <ul class="bv-bbd-sidebar__list">
                <?php if ($bbdSidebarOffers === []): ?>
                    <li>
                        <a class="bv-bbd-sidebar__item" href="/magazin">
                            <img src="/assets/img/product-placeholder.svg" alt="Produse" loading="lazy">
                            <div>
                                <h4 class="bv-bbd-sidebar__item-name">Momentan nu sunt oferte disponibile</h4>
                                <p class="bv-bbd-sidebar__item-label">Verifică produsele din magazin pentru oferte active.</p>
                            </div>
                        </a>
                    </li>
                <?php else: ?>
                    <?php foreach ($bbdSidebarOffers as $offer): ?>
                        <?php
                        $offerName = trim((string) ($offer['name'] ?? 'Produs'));
                        $offerUrl = trim((string) ($offer['url'] ?? '/magazin'));
                        $offerImage = trim((string) ($offer['image_url'] ?? '/assets/img/product-placeholder.svg'));
                        $offerCount = max(1, (int) ($offer['offer_count'] ?? 1));
                        $offerPrice = max(0.0, (float) ($offer['price'] ?? 0.0));
                        $offerComparePrice = isset($offer['compare_at_price']) ? max(0.0, (float) ($offer['compare_at_price'] ?? 0.0)) : 0.0;
                        ?>
                        <li>
                            <a class="bv-bbd-sidebar__item" href="<?= htmlspecialchars($offerUrl, ENT_QUOTES) ?>">
                                <img src="<?= htmlspecialchars($offerImage, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($offerName, ENT_QUOTES) ?>" loading="lazy">
                                <div>
                                    <h4 class="bv-bbd-sidebar__item-name"><?= htmlspecialchars($offerName, ENT_QUOTES) ?></h4>
                                    <p class="bv-bbd-sidebar__item-label">
                                        <?= htmlspecialchars($offerCount === 1 ? '1 ofertă activă' : ($offerCount . ' oferte active'), ENT_QUOTES) ?>
                                    </p>
                                    <div class="bv-bbd-sidebar__item-prices">
                                        <strong><?= htmlspecialchars(number_format($offerPrice, 2, ',', '.'), ENT_QUOTES) ?> lei</strong>
                                        <?php if ($offerComparePrice > $offerPrice): ?>
                                            <del><?= htmlspecialchars(number_format($offerComparePrice, 2, ',', '.'), ENT_QUOTES) ?> lei</del>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </aside>
    <?php endif; ?>

    <main class="container site-container">
        <?php if (($message = \App\Support\Flash::get('success')) !== null): ?>
            <section class="panel" style="border-color:#34d399;background:#ecfdf5;color:#065f46;">
                <?= htmlspecialchars($message, ENT_QUOTES) ?>
            </section>
        <?php endif; ?>
        <?php if (($message = \App\Support\Flash::get('error')) !== null): ?>
            <section class="panel" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b;">
                <?= htmlspecialchars($message, ENT_QUOTES) ?>
            </section>
        <?php endif; ?>
        <?= $content ?>
    </main>

    <?php if (trim($designFooter) !== ''): ?>
        <?php
        // Aceleasi token-uri ca in header: meniul si numarul de produse din cos.
        $designFooterOutput = $designFooter;
        if (trim($designMenu) !== '') {
            $designFooterOutput = str_replace(['{{menu}}', '{{ menu }}'], $designMenu, $designFooterOutput);
        }
        $designFooterOutput = str_replace($cartCountTokens, (string) $headerCartCount, $designFooterOutput);
        ?>
        <?= $designFooterOutput ?>
    <?php endif; ?>
    <?php
    $designJs = trim(implode("\n", array_filter([
        $designHeaderJs,
        $designMenuJs,
        $designFooterJs,
    ], static fn (string $part): bool => trim($part) !== '')));
    $designJs = str_replace('</script>', '<\/script>', $designJs);
    $floatingCartJsVersion = @filemtime(__DIR__ . '/../public/assets/js/floating-cart.js') ?: time();
    $qtyStocJsVersion = @filemtime(__DIR__ . '/../public/assets/js/qty-stoc.js') ?: time();
    ?>
    <script src="/assets/js/qty-stoc.js?v=<?= $qtyStocJsVersion ?><?= $assetVersionQuery ?>" defer></script>
    <?php if ($floatingCartEnabled): ?>
        <script>
            window.floatingCartConfig = <?= json_encode($floatingCartConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <script src="/assets/js/floating-cart.js?v=<?= $floatingCartJsVersion ?><?= $assetVersionQuery ?>" defer></script>
    <?php endif; ?>
    <?php if ($designJs !== ''): ?>
        <script><?= $designJs ?></script>
    <?php endif; ?>
    <?php if ($clarityEnabled && $clarityProjectId !== ''): ?>
        <script>
            (function(c,l,a,r,i,t,y){
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "<?= htmlspecialchars($clarityProjectId, ENT_QUOTES) ?>");
        </script>
    <?php endif; ?>
    <?php
    // Google Ads conversion: fire once on the checkout success page, then clear the flag.
    $adsConversion = $_SESSION['google_ads_conversion'] ?? null;
    if (is_array($adsConversion)) {
        unset($_SESSION['google_ads_conversion']);
    }
    $adsConversionLabel = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) ($designSettings['google_ads_conversion_label'] ?? ''))) ?? '';
    if ($adsEnabled && $adsConversionId !== '' && $adsConversionLabel !== '' && is_array($adsConversion)):
    ?>
        <script>
            if (typeof gtag === 'function') {
                gtag('event', 'conversion', {
                    'send_to': '<?= htmlspecialchars($adsConversionId . '/' . $adsConversionLabel, ENT_QUOTES) ?>',
                    'value': <?= json_encode((float) ($adsConversion['value'] ?? 0)) ?>,
                    'currency': 'RON',
                    'transaction_id': '<?= htmlspecialchars((string) ($adsConversion['order'] ?? ''), ENT_QUOTES) ?>'
                });
            }
        </script>
    <?php endif; ?>
    <?php
    // GA4 ecommerce "purchase" event — fired once per order, captured by GTM.
    $ga4Purchase = $_SESSION['ga4_purchase'] ?? null;
    if (is_array($ga4Purchase)) {
        unset($_SESSION['ga4_purchase']);
    }
    if (is_array($ga4Purchase) && trim((string) ($ga4Purchase['transaction_id'] ?? '')) !== ''):
        $ga4PurchaseJson = json_encode([
            'event' => 'purchase',
            'ecommerce' => [
                'transaction_id' => (string) ($ga4Purchase['transaction_id'] ?? ''),
                'value' => (float) ($ga4Purchase['value'] ?? 0),
                'currency' => (string) ($ga4Purchase['currency'] ?? 'RON'),
                'items' => array_values((array) ($ga4Purchase['items'] ?? [])),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $ga4PurchaseTxnKey = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($ga4Purchase['transaction_id'] ?? '')) ?? '';
    ?>
        <script>
            (function () {
                var key = 'purchase_tracked_<?= $ga4PurchaseTxnKey ?>';
                try {
                    if (window.sessionStorage && sessionStorage.getItem(key)) { return; }
                } catch (e) {}
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push(<?= $ga4PurchaseJson ?>);
                try { if (window.sessionStorage) { sessionStorage.setItem(key, '1'); } } catch (e) {}
            })();
        </script>
    <?php endif; ?>
    <script>
        (() => {
            const normalize = (value) => String(value ?? '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .trim();
            const escapeHtml = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
            const splitTerms = (query) => normalize(query)
                .split(/\s+/)
                .map((part) => part.trim())
                .filter((part) => part.length >= 2)
                .slice(0, 6);
            const isSearchTrigger = (el) => {
                if (!(el instanceof HTMLElement)) {
                    return false;
                }
                const haystack = normalize([
                    el.id,
                    el.className,
                    el.getAttribute('aria-label') || '',
                    el.getAttribute('title') || '',
                    el.getAttribute('data-action') || '',
                    el.getAttribute('data-icon') || '',
                    el.getAttribute('href') || '',
                    (el.textContent || '').trim(),
                ].join(' '));
                if (/(search|cauta|lupa)/.test(haystack)) {
                    return true;
                }
                return (el.textContent || '').trim() === '🔍';
            };
            const findHeader = () => document.querySelector('header') || document.querySelector('.header');
            const findSearchTriggers = (header) => {
                if (!(header instanceof HTMLElement)) {
                    return [];
                }
                const controls = Array.from(header.querySelectorAll('a, button, [role="button"]'));
                const direct = controls.filter(isSearchTrigger);
                if (direct.length > 0) {
                    return direct;
                }

                // Fallback: detect icon actions cluster (search/account/cart) and use first control.
                const groups = Array.from(header.querySelectorAll('div, nav, ul'));
                for (const group of groups) {
                    const children = Array.from(group.querySelectorAll(':scope > a, :scope > button, :scope > [role="button"]'));
                    if (children.length < 2) {
                        continue;
                    }
                    const hasCartLike = children.some((child) => normalize((child.getAttribute('href') || '') + ' ' + (child.textContent || '')).includes('cos'));
                    if (!hasCartLike) {
                        continue;
                    }
                    const iconLikeChildren = children.filter((child) => {
                        const text = (child.textContent || '').replace(/\s+/g, '').trim();
                        const hasSvg = child.querySelector('svg') !== null;
                        return text.length <= 2 || (hasSvg && text.length <= 6);
                    });
                    if (iconLikeChildren.length < children.length) {
                        continue;
                    }
                    const first = children[0];
                    if (first instanceof HTMLElement) {
                        return [first];
                    }
                }
                return [];
            };
            const buildSearchShell = () => {
                const shell = document.createElement('section');
                shell.className = 'bv-header-search-shell';
                shell.setAttribute('aria-hidden', 'true');
                shell.innerHTML = `
                    <div class="bv-header-search-shell__inner">
                        <div class="bv-header-search-box">
                            <div class="bv-header-search-input-wrap">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"></circle><path d="M20 20l-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path></svg>
                                <input class="bv-header-search-input" type="search" placeholder="Caută produse..." autocomplete="off">
                                <button type="button" class="bv-header-search-close" aria-label="Închide căutarea">×</button>
                            </div>
                            <div class="bv-header-search-results" hidden></div>
                        </div>
                    </div>
                `;
                return shell;
            };
            const highlightText = (text, query) => {
                const safe = escapeHtml(text);
                const terms = splitTerms(query).map((term) => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
                if (terms.length === 0) {
                    return safe;
                }
                return safe.replace(new RegExp('(' + terms.join('|') + ')', 'gi'), '<mark>$1</mark>');
            };
            const snippetForItem = (item, query) => {
                const source = String(item.short_description || item.description || '').trim();
                if (source === '') {
                    return '';
                }
                const terms = splitTerms(query);
                const sourceNorm = normalize(source);
                let firstPos = -1;
                for (const term of terms) {
                    const pos = sourceNorm.indexOf(term);
                    if (pos >= 0 && (firstPos === -1 || pos < firstPos)) {
                        firstPos = pos;
                    }
                }
                if (firstPos < 0) {
                    return source.slice(0, 170) + (source.length > 170 ? '…' : '');
                }
                const start = Math.max(0, firstPos - 65);
                const end = Math.min(source.length, firstPos + 130);
                const prefix = start > 0 ? '…' : '';
                const suffix = end < source.length ? '…' : '';
                return prefix + source.slice(start, end).trim() + suffix;
            };

            const initHeaderSearch = () => {
                const header = findHeader();
                if (!(header instanceof HTMLElement)) {
                    return;
                }
                const triggers = findSearchTriggers(header);
                if (triggers.length === 0) {
                    return;
                }

                const shell = buildSearchShell();
                header.insertAdjacentElement('afterend', shell);
                const input = shell.querySelector('.bv-header-search-input');
                const closeBtn = shell.querySelector('.bv-header-search-close');
                const results = shell.querySelector('.bv-header-search-results');
                if (!(input instanceof HTMLInputElement) || !(closeBtn instanceof HTMLButtonElement) || !(results instanceof HTMLElement)) {
                    return;
                }

                let open = false;
                let debounce = null;
                let activeController = null;
                const setOpen = (value) => {
                    open = Boolean(value);
                    shell.classList.toggle('is-open', open);
                    shell.setAttribute('aria-hidden', open ? 'false' : 'true');
                    triggers.forEach((trigger) => {
                        if (!(trigger instanceof HTMLElement)) {
                            return;
                        }
                        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });
                    if (open) {
                        window.setTimeout(() => input.focus(), 40);
                    } else {
                        results.hidden = true;
                        results.innerHTML = '';
                    }
                };
                const setResultsState = (text) => {
                    results.hidden = false;
                    results.innerHTML = '<p class="bv-header-search-state">' + escapeHtml(text) + '</p>';
                };
                const renderItems = (items, query) => {
                    if (!Array.isArray(items) || items.length === 0) {
                        setResultsState('Nu am găsit produse pentru căutarea ta.');
                        return;
                    }
                    const html = items.map((item) => {
                        const name = highlightText(item.name || 'Produs', query);
                        const snippet = highlightText(snippetForItem(item, query), query);
                        const imageUrl = escapeHtml(item.image_url || '/assets/img/product-placeholder.svg');
                        const url = escapeHtml(item.url || '/magazin');
                        return `
                            <a class="bv-header-search-item" href="${url}">
                                <img src="${imageUrl}" alt="">
                                <div>
                                    <h4>${name}</h4>
                                    ${snippet !== '' ? '<p>' + snippet + '</p>' : ''}
                                </div>
                            </a>
                        `;
                    }).join('');
                    results.hidden = false;
                    results.innerHTML = html;
                };
                const fetchItems = (query) => {
                    if (activeController) {
                        activeController.abort();
                    }
                    activeController = new AbortController();
                    setResultsState('Căutăm produse…');
                    fetch('/api/search/products?q=' + encodeURIComponent(query), {
                        method: 'GET',
                        headers: { 'Accept': 'application/json' },
                        signal: activeController.signal,
                    })
                        .then((response) => response.ok ? response.json() : Promise.reject(new Error('Eroare de rețea')))
                        .then((payload) => {
                            if (!open) {
                                return;
                            }
                            if (!payload || payload.ok !== true) {
                                setResultsState('Nu am putut încărca rezultatele.');
                                return;
                            }
                            renderItems(Array.isArray(payload.items) ? payload.items : [], query);
                        })
                        .catch((error) => {
                            if (error && error.name === 'AbortError') {
                                return;
                            }
                            if (open) {
                                setResultsState('A apărut o eroare la căutare.');
                            }
                        });
                };

                const onQueryChange = () => {
                    const query = input.value.trim();
                    if (!open) {
                        return;
                    }
                    if (debounce) {
                        window.clearTimeout(debounce);
                    }
                    if (query.length < 2) {
                        setResultsState('Scrie cel puțin 2 caractere ca să căutăm.');
                        return;
                    }
                    debounce = window.setTimeout(() => fetchItems(query), 220);
                };

                triggers.forEach((trigger) => {
                    trigger.addEventListener('click', (event) => {
                        event.preventDefault();
                        setOpen(!open);
                        if (open) {
                            onQueryChange();
                        }
                    });
                });
                closeBtn.addEventListener('click', () => setOpen(false));
                input.addEventListener('input', onQueryChange);
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && open) {
                        setOpen(false);
                    }
                });
                document.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof Node) || !open) {
                        return;
                    }
                    const clickedInside = shell.contains(target) || triggers.some((trigger) => trigger.contains(target));
                    if (!clickedInside) {
                        setOpen(false);
                    }
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initHeaderSearch);
            } else {
                initHeaderSearch();
            }
        })();
    </script>
    <script>
        (() => {
            const isLoggedIn = <?= $headerCustomerLoggedIn ? 'true' : 'false' ?>;
            if (!isLoggedIn) {
                return;
            }
            const points = <?= (int) $headerCustomerPoints ?>;
            const normalizePath = (href) => {
                try {
                    const url = new URL(String(href || ''), window.location.origin);
                    const path = url.pathname.replace(/\/+$/, '');
                    return path === '' ? '/' : path;
                } catch (_error) {
                    return '';
                }
            };
            const isInsideMobileDrawer = (node) => node instanceof HTMLElement
                && node.closest('.bv-mobile-menu-token__drawer') !== null;
            const isIconLikeLink = (link) => {
                if (!(link instanceof HTMLAnchorElement)) {
                    return false;
                }
                if (link.querySelector('svg, i, .icon, [class*="icon"]')) {
                    return true;
                }
                const text = String(link.textContent || '').replace(/\s+/g, '').trim();
                return text.length > 0 && text.length <= 3;
            };
            const attachBadge = (link) => {
                if (!(link instanceof HTMLAnchorElement)) {
                    return;
                }
                if (link.querySelector('[data-header-account-points]')) {
                    return;
                }
                link.classList.add('bv-header-account-points-anchor');
                if (isIconLikeLink(link)) {
                    link.classList.add('bv-header-account-points-anchor--icon');
                }
                if (normalizePath(link.getAttribute('href') || '') === '/login') {
                    link.setAttribute('href', '/contul-meu');
                }
                const badge = document.createElement('span');
                badge.className = 'bv-header-account-points-badge';
                badge.setAttribute('data-header-account-points', '1');
                badge.textContent = String(points);
                badge.title = `${points} puncte fidelitate`;
                link.appendChild(badge);
            };

            const allLinks = Array.from(document.querySelectorAll('header a[href], .header a[href]'))
                .filter((node) => node instanceof HTMLAnchorElement)
                .filter((node) => !isInsideMobileDrawer(node));
            const candidates = allLinks.filter((link) => {
                const path = normalizePath(link.getAttribute('href') || '');
                return path === '/contul-meu' || path === '/login';
            });
            if (candidates.length === 0) {
                return;
            }

            const iconLike = candidates.filter(isIconLikeLink);
            const targets = iconLike.length > 0 ? iconLike : [candidates[0]];
            targets.forEach(attachBadge);
        })();
    </script>
    <?php if ($mobileMenuTokenAssetsEnabled): ?>
        <script>
            (() => {
                const initMobileMenuToken = (root) => {
                    if (!(root instanceof HTMLElement) || root.dataset.mobileMenuReady === '1') {
                        return;
                    }
                    root.dataset.mobileMenuReady = '1';
                    const maybeRow = root.closest('.bv-mainbar-row');
                    if (maybeRow instanceof HTMLElement) {
                        maybeRow.classList.add('bv-mobile-menu-token-row');
                    }
                    const toggle = root.querySelector('[data-bv-mobile-menu-toggle]');
                    const overlay = root.querySelector('[data-bv-mobile-menu-overlay]');
                    const drawer = root.querySelector('[data-bv-mobile-menu-drawer]');
                    const close = root.querySelector('[data-bv-mobile-menu-close]');
                    const nav = root.querySelector('.bv-mobile-menu-token__nav');
                    if (!(toggle instanceof HTMLButtonElement) || !(overlay instanceof HTMLElement) || !(drawer instanceof HTMLElement)) {
                        return;
                    }

                    if (nav instanceof HTMLElement) {
                        nav.querySelectorAll('.menu-root > li').forEach((item) => {
                            if (!(item instanceof HTMLElement)) {
                                return;
                            }
                            const submenu = item.querySelector(':scope > .submenu');
                            if (!(submenu instanceof HTMLElement)) {
                                return;
                            }
                            item.classList.add('has-submenu');
                            const link = item.querySelector(':scope > a');
                            if (!(link instanceof HTMLAnchorElement) || link.dataset.bvSubmenuBound === '1') {
                                return;
                            }
                            link.dataset.bvSubmenuBound = '1';
                            link.setAttribute('aria-expanded', 'false');
                            link.addEventListener('click', (event) => {
                                event.preventDefault();
                                const isOpen = item.classList.toggle('is-open');
                                link.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                            });
                        });
                    }

                    const setOpen = (open) => {
                        const isOpen = Boolean(open);
                        document.body.classList.toggle('bv-mobile-menu-open', isOpen);
                        root.classList.toggle('is-open', isOpen);
                        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                        drawer.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                        overlay.hidden = !isOpen;
                    };

                    toggle.addEventListener('click', () => {
                        const isOpen = document.body.classList.contains('bv-mobile-menu-open');
                        setOpen(!isOpen);
                    });
                    overlay.addEventListener('click', () => setOpen(false));
                    close?.addEventListener('click', () => setOpen(false));
                    nav?.addEventListener('click', (event) => {
                        const target = event.target;
                        if (!(target instanceof HTMLElement)) {
                            return;
                        }
                        const link = target.closest('a');
                        if (!(link instanceof HTMLAnchorElement)) {
                            return;
                        }
                        const owner = link.closest('li.has-submenu');
                        if (owner instanceof HTMLElement && owner.firstElementChild === link) {
                            return;
                        }
                        setOpen(false);
                    });
                    document.addEventListener('keydown', (event) => {
                        if (event.key === 'Escape' && document.body.classList.contains('bv-mobile-menu-open')) {
                            setOpen(false);
                        }
                    });
                    window.addEventListener('resize', () => {
                        if (window.innerWidth > 980 && document.body.classList.contains('bv-mobile-menu-open')) {
                            setOpen(false);
                        }
                    });
                };

                const boot = () => {
                    document.querySelectorAll('[data-bv-mobile-menu]').forEach((node) => initMobileMenuToken(node));
                };
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', boot);
                } else {
                    boot();
                }
            })();
        </script>
    <?php endif; ?>
    <script>
        (() => {
            const sidebar = document.querySelector('[data-bv-bbd-sidebar]');
            if (!(sidebar instanceof HTMLElement)) {
                return;
            }
            const handle = sidebar.querySelector('[data-bv-bbd-sidebar-handle]');
            const closeBtn = sidebar.querySelector('[data-bv-bbd-sidebar-close]');
            if (!(handle instanceof HTMLButtonElement)) {
                return;
            }

            const introKey = 'bv-bbd-sidebar-intro-v1';
            const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
            let currentX = 0;
            let pointerActive = false;
            let dragMoved = false;
            let suppressClick = false;
            let startClientX = 0;
            let startOffsetX = 0;

            const sidebarWidth = () => Math.max(sidebar.getBoundingClientRect().width, 220);
            const handleWidth = () => {
                const cssValue = Number.parseFloat(
                    String(window.getComputedStyle(sidebar).getPropertyValue('--bv-bbd-sidebar-handle') || '').trim()
                );
                if (Number.isFinite(cssValue) && cssValue >= 32) {
                    return cssValue;
                }
                return 54;
            };
            const closedOffset = () => -(sidebarWidth() - handleWidth());
            const isOpen = () => currentX > (closedOffset() / 2);

            const applyOffset = (offset) => {
                const min = closedOffset();
                currentX = clamp(offset, min, 0);
                sidebar.style.transform = `translate3d(${currentX}px, ${window.innerWidth <= 768 ? '0' : '-50%'}, 0)`;
                const expanded = isOpen();
                sidebar.classList.toggle('is-open', expanded);
                handle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            };

            const setOpen = (open) => {
                applyOffset(open ? 0 : closedOffset());
            };

            const showIntro = () => {
                try {
                    if (window.localStorage.getItem(introKey) === '1') {
                        return;
                    }
                    window.localStorage.setItem(introKey, '1');
                } catch (_error) {
                    return;
                }
                sidebar.classList.add('is-intro');
                window.setTimeout(() => sidebar.classList.remove('is-intro'), 1900);
            };

            handle.addEventListener('pointerdown', (event) => {
                if (event.button !== 0) {
                    return;
                }
                pointerActive = true;
                dragMoved = false;
                suppressClick = false;
                startClientX = event.clientX;
                startOffsetX = currentX;
                sidebar.classList.add('is-dragging');
                handle.style.cursor = 'grabbing';
                handle.setPointerCapture(event.pointerId);
                event.preventDefault();
            });

            handle.addEventListener('pointermove', (event) => {
                if (!pointerActive) {
                    return;
                }
                const delta = event.clientX - startClientX;
                if (Math.abs(delta) > 6) {
                    dragMoved = true;
                }
                applyOffset(startOffsetX + delta);
            });

            const finishDrag = (event) => {
                if (!pointerActive) {
                    return;
                }
                pointerActive = false;
                sidebar.classList.remove('is-dragging');
                handle.style.cursor = '';
                if (typeof event.pointerId === 'number' && handle.hasPointerCapture(event.pointerId)) {
                    handle.releasePointerCapture(event.pointerId);
                }
                if (dragMoved) {
                    suppressClick = true;
                    setOpen(isOpen());
                }
            };

            handle.addEventListener('pointerup', finishDrag);
            handle.addEventListener('pointercancel', finishDrag);

            handle.addEventListener('click', (event) => {
                if (suppressClick) {
                    suppressClick = false;
                    event.preventDefault();
                    return;
                }
                setOpen(!isOpen());
            });

            closeBtn?.addEventListener('click', () => setOpen(false));
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && isOpen()) {
                    setOpen(false);
                }
            });
            document.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Node) || !isOpen()) {
                    return;
                }
                if (!sidebar.contains(target)) {
                    setOpen(false);
                }
            });
            window.addEventListener('resize', () => {
                setOpen(isOpen());
            });

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => {
                    setOpen(false);
                    showIntro();
                }, { once: true });
            } else {
                setOpen(false);
                showIntro();
            }
        })();
    </script>
    <script>
        (() => {
            const initialCount = <?= (int) $headerCartCount ?>;
            const normalizePath = (href) => {
                try {
                    const url = new URL(String(href || ''), window.location.origin);
                    const path = url.pathname.replace(/\/+$/, '');
                    return path === '' ? '/' : path;
                } catch (_error) {
                    return '';
                }
            };
            const isCartLink = (node) => (
                node instanceof HTMLAnchorElement
                && normalizePath(node.getAttribute('href') || '') === '/cos'
            );
            const cartTargets = new Set();
            const cartTextLinks = new Set();

            const registerTargets = () => {
                document.querySelectorAll('[data-cart-count], [data-floating-cart-count]').forEach((node) => {
                    if (node instanceof HTMLElement) {
                        cartTargets.add(node);
                    }
                });
                document.querySelectorAll('a[href]').forEach((node) => {
                    if (!isCartLink(node)) {
                        return;
                    }
                    const badge = node.querySelector('[data-cart-count], em, sup, .count, .badge, [class*="count"], [class*="badge"]');
                    if (badge instanceof HTMLElement) {
                        badge.setAttribute('data-cart-count', '1');
                        cartTargets.add(badge);
                        return;
                    }
                    if (node.children.length === 0) {
                        cartTextLinks.add(node);
                    }
                });
            };

            const applyCount = (count) => {
                const safeCount = Math.max(0, Number.parseInt(String(count), 10) || 0);
                registerTargets();
                cartTargets.forEach((node) => {
                    node.textContent = String(safeCount);
                });
                cartTextLinks.forEach((node) => {
                    if (!(node instanceof HTMLAnchorElement)) {
                        return;
                    }
                    const existingTemplate = node.getAttribute('data-cart-count-template');
                    if (!existingTemplate) {
                        const source = String(node.textContent || '').trim();
                        const template = /\(\s*\d+\s*\)/.test(source)
                            ? source.replace(/\(\s*\d+\s*\)/, '({{count}})')
                            : (source !== '' ? source + ' ({{count}})' : 'Coș ({{count}})');
                        node.setAttribute('data-cart-count-template', template);
                    }
                    const template = node.getAttribute('data-cart-count-template') || 'Coș ({{count}})';
                    node.textContent = template.replace('{{count}}', String(safeCount));
                });
            };

            const refreshFromApi = () => {
                fetch('/api/cart/summary', {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                })
                    .then((response) => response.ok ? response.json() : Promise.reject(new Error('Cart summary failed')))
                    .then((payload) => {
                        if (payload && payload.ok === true) {
                            applyCount(payload.items_count ?? initialCount);
                        }
                    })
                    .catch(() => {});
            };

            const init = () => {
                applyCount(initialCount);
                refreshFromApi();
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }

            window.addEventListener('floating-cart:added', (event) => {
                const detailCount = event && event.detail
                    ? (
                        event.detail.items_count
                        ?? event.detail.count
                    )
                    : null;
                if (detailCount === null || typeof detailCount === 'undefined') {
                    refreshFromApi();
                    return;
                }
                applyCount(detailCount);
            });
            window.addEventListener('cart:count-updated', (event) => {
                const detailCount = event && event.detail
                    ? (
                        event.detail.items_count
                        ?? event.detail.count
                    )
                    : null;
                if (detailCount === null || typeof detailCount === 'undefined') {
                    return;
                }
                applyCount(detailCount);
            });

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    refreshFromApi();
                }
            });
        })();
    </script>
</body>
</html>
