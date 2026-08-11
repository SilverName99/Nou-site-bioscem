<!doctype html>
<?php
$adminAppConfig = require __DIR__ . '/../../config/app.php';
$adminDb = \App\Support\Database::connection($adminAppConfig['db']);
$adminSettings = \App\Support\Settings::all($adminDb);
$adminAssetVersionToken = \App\Support\ResponseCache::assetVersionToken($adminSettings);
$adminAssetVersionQuery = $adminAssetVersionToken !== '' ? '&v=' . rawurlencode($adminAssetVersionToken) : '';
$adminFavicon = trim((string) ($adminSettings['store_favicon_url'] ?? ''));
if ($adminFavicon === '') {
    $adminFavicon = '/assets/img/product-placeholder.svg';
}
?>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(($title ?? 'Admin') . ' | Admin Bioscem', ENT_QUOTES) ?></title>
    <link rel="icon" href="<?= htmlspecialchars($adminFavicon, ENT_QUOTES) ?>">
    <?php $cssVersion = @filemtime(__DIR__ . '/../../public/assets/css/app.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $cssVersion ?><?= $adminAssetVersionQuery ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/lib/codemirror.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/theme/material-darker.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/dialog/dialog.min.css">
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/lib/codemirror.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/mode/javascript/javascript.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/mode/css/css.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/mode/xml/xml.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/dialog/dialog.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/search/searchcursor.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/search/search.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/edit/closebrackets.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/edit/matchbrackets.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/selection/active-line.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/js-beautify@1.15.1/js/lib/beautify.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/js-beautify@1.15.1/js/lib/beautify-css.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/js-beautify@1.15.1/js/lib/beautify-html.min.js"></script>
</head>
<body>
    <?php if (\App\Support\Auth::check()): ?>
        <?php
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH) ?: '/admin';
        $isActive = static function (array $prefixes) use ($path): bool {
            foreach ($prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    return true;
                }
            }
            return false;
        };
        $can = static fn (string $route): bool => \App\Support\Auth::canAccessPath($route);
        $icon = static function (string $name): string {
            return match ($name) {
                'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
                'store' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16l-1.5 11H5.5L4 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>',
                'products' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>',
                'categories' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7v7H4z"/><path d="M13 5h7v7h-7z"/><path d="M4 14h7v7H4z"/><path d="M13 14h7v7h-7z"/></svg>',
                'orders' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="19" r="1.6"/><circle cx="17" cy="19" r="1.6"/><path d="M4 5h2l2.1 9.2h9.9l2-7.2H7.2"/></svg>',
                'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 18a5.5 5.5 0 0 1 11 0"/><circle cx="17" cy="9" r="2.3"/><path d="M14.5 18a4.2 4.2 0 0 1 6 0"/></svg>',
                'shield' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v6c0 5 3.4 7.9 7 9 3.6-1.1 7-4 7-9V6l-7-3Z"/><path d="m9.5 12 1.8 1.8L15 10.2"/></svg>',
                'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.8a3.2 3.2 0 1 0 0 6.4 3.2 3.2 0 0 0 0-6.4Z"/><path d="M20 13.2v-2.4l-2.1-.5a6 6 0 0 0-.5-1.1l1.2-1.8-1.7-1.7-1.8 1.2a6 6 0 0 0-1.1-.5L13.2 4h-2.4l-.5 2.1a6 6 0 0 0-1.1.5L7.4 5.4 5.7 7.1l1.2 1.8a6 6 0 0 0-.5 1.1l-2.1.5v2.4l2.1.5a6 6 0 0 0 .5 1.1l-1.2 1.8 1.7 1.7 1.8-1.2a6 6 0 0 0 1.1.5l.5 2.1h2.4l.5-2.1a6 6 0 0 0 1.1-.5l1.8 1.2 1.7-1.7-1.2-1.8a6 6 0 0 0 .5-1.1l2.1-.5Z"/></svg>',
                'shipping' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h12v10H3z"/><path d="M15 9h4l2 2v5h-6z"/><circle cx="7" cy="18" r="1.7"/><circle cx="17" cy="18" r="1.7"/></svg>',
                'payments' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
                'emails' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>',
                'automation' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>',
                'newsletter' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="m4 7 8 6 8-6"/></svg>',
                'stats' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16"/><rect x="6" y="11" width="3" height="6" rx="1"/><rect x="11" y="8" width="3" height="9" rx="1"/><rect x="16" y="5" width="3" height="12" rx="1"/></svg>',
                'star' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.9 5.88 6.5.95-4.7 4.58 1.1 6.49L12 17.8 6.2 20.9l1.1-6.49L2.6 9.83l6.5-.95L12 3z"/></svg>',
                'coupon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V8z"/><path d="M12 7v10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" fill="none"/></svg>',
                'pages' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h9l4 4v14H7z"/><path d="M16 3v5h5"/></svg>',
                'gallery' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m5 16 4-3 3 2 3-4 4 5"/></svg>',
                'design' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4 4 14l-1 5 5-1L18 8l-4-4Z"/><path d="m13 5 4 4"/><path d="M3 21h18"/></svg>',
                'blog' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M7 8h10M7 12h10M7 16h6"/></svg>',
                default => '',
            };
        };

        $magazinOpen = $isActive(['/admin/products', '/admin/orders', '/admin/coupons', '/admin/categories', '/admin/promo-products', '/admin/settings/floating-cart', '/admin/products/fields', '/admin/products/templates', '/admin/products/reviews'])
            || $path === '/admin/orders/trash';
        $usersOpen = $isActive(['/admin/users']);
        $usersSettingsActive = $isActive(['/admin/users/settings']) && trim((string) ($_GET['tab'] ?? 'settings')) !== 'points';
        $usersPointsActive = $isActive(['/admin/users/points']) || ($isActive(['/admin/users/settings']) && trim((string) ($_GET['tab'] ?? 'settings')) === 'points');
        $usersSecurityActive = $isActive(['/admin/users/security']);
        $usersGdprActive = $isActive(['/admin/users/gdpr-agreements']);
        $usersListActive = $isActive(['/admin/users']) && !$usersSettingsActive && !$usersPointsActive && !$usersSecurityActive && !$usersGdprActive;
        $configOpen = $isActive(['/admin/settings/store', '/admin/settings/shipping', '/admin/settings/payments', '/admin/settings/erp', '/admin/settings/mannequin', '/admin/settings/admins']);
        $activityLogActive = $isActive(['/admin/activity-log']);
        $emailsOpen = $isActive(['/admin/emails/sender', '/admin/emails/test']);
        $newsletterOpen = $isActive(['/admin/emails/newsletters']);
        $newsletterTab = trim((string) ($_GET['tab'] ?? 'templates'));
        $blogOpen = $isActive(['/admin/blog/posts', '/admin/blog/categories', '/admin/blog/templates', '/admin/blog/authors']);
        ?>
        <div class="admin-shell" id="admin-shell">
            <aside class="admin-sidebar">
                <div class="admin-brand">
                    <strong>Admin Panel</strong>
                </div>

                <nav class="admin-nav">
                    <a class="<?= $isActive(['/admin']) && !$isActive(['/admin/users', '/admin/products', '/admin/categories', '/admin/orders', '/admin/settings', '/admin/pages', '/admin/gallery', '/admin/design', '/admin/emails']) ? 'active' : '' ?>" href="/admin">
                        <span class="nav-icon"><?= $icon('dashboard') ?></span>
                        Dashboard
                    </a>

                    <?php if ($can('/admin/users')): ?>
                    <div class="admin-nav-group toggle-group <?= $usersOpen ? 'open' : '' ?>" data-toggle-group="utilizatori">
                        <button type="button" class="group-toggle <?= $usersOpen ? 'active' : '' ?>">
                            <span class="left">
                                <span class="nav-icon"><?= $icon('users') ?></span> Utilizatori
                            </span>
                            <span class="caret"><?= $usersOpen ? '↑' : '↓' ?></span>
                        </button>
                        <div class="group-items">
                            <a class="<?= $usersListActive ? 'active' : '' ?>" href="/admin/users">
                                <span class="nav-icon"><?= $icon('users') ?></span> Listă utilizatori
                            </a>
                            <a class="<?= $usersSettingsActive ? 'active' : '' ?>" href="/admin/users/settings">
                                <span class="nav-icon"><?= $icon('settings') ?></span> Setări utilizatori
                            </a>
                            <a class="<?= $usersPointsActive ? 'active' : '' ?>" href="/admin/users/points">
                                <span class="nav-icon"><?= $icon('stats') ?></span> Puncte fidelitate
                            </a>
                            <a class="<?= $usersSecurityActive ? 'active' : '' ?>" href="/admin/users/security">
                                <span class="nav-icon"><?= $icon('shield') ?></span> Securitate formulare
                            </a>
                            <a class="<?= $usersGdprActive ? 'active' : '' ?>" href="/admin/users/gdpr-agreements">
                                <span class="nav-icon"><?= $icon('pages') ?></span> Acorduri GDPR
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($can('/admin/products')): ?>
                    <div class="admin-nav-group toggle-group <?= $magazinOpen ? 'open' : '' ?>" data-toggle-group="magazin">
                        <button type="button" class="group-toggle <?= $magazinOpen ? 'active' : '' ?>">
                            <span class="left">
                                <span class="nav-icon"><?= $icon('store') ?></span> Magazin
                            </span>
                            <span class="caret"><?= $magazinOpen ? '↑' : '↓' ?></span>
                        </button>
                        <div class="group-items">
                            <a class="<?= ($path === '/admin/products') ? 'active' : '' ?>" href="/admin/products">
                                <span class="nav-icon"><?= $icon('products') ?></span> Produse
                            </a>
                            <a class="<?= $isActive(['/admin/categories']) ? 'active' : '' ?>" href="/admin/categories">
                                <span class="nav-icon"><?= $icon('categories') ?></span> Categorii produse
                            </a>
                            <a class="<?= $isActive(['/admin/products/fields']) ? 'active' : '' ?>" href="/admin/products/fields">
                                <span class="nav-icon"><?= $icon('settings') ?></span> Câmpuri suplimentare
                            </a>
                            <a class="<?= $isActive(['/admin/products/templates']) ? 'active' : '' ?>" href="/admin/products/templates">
                                <span class="nav-icon"><?= $icon('design') ?></span> Template-uri produse
                            </a>
                            <a class="<?= ($path === '/admin/orders' || $path === '/admin/orders/trash') ? 'active' : '' ?>" href="/admin/orders">
                                <span class="nav-icon"><?= $icon('orders') ?></span> Comenzi
                            </a>
                            <a class="<?= ($path === '/admin/coupons') ? 'active' : '' ?>" href="/admin/coupons">
                                <span class="nav-icon"><?= $icon('coupon') ?></span> Cupoane
                            </a>
                            <a class="<?= $isActive(['/admin/coupons/unique']) ? 'active' : '' ?>" href="/admin/coupons/unique">
                                <span class="nav-icon"><?= $icon('coupon') ?></span> Cupoane unice
                            </a>
                            <a class="<?= $isActive(['/admin/products/reviews']) ? 'active' : '' ?>" href="/admin/products/reviews">
                                <span class="nav-icon"><?= $icon('star') ?></span> Recenzii
                            </a>
                            <a class="<?= $isActive(['/admin/promo-products']) ? 'active' : '' ?>" href="/admin/promo-products">
                                <span class="nav-icon"><?= $icon('coupon') ?></span> Produse promoționale
                            </a>
                            <a class="<?= $isActive(['/admin/settings/floating-cart']) ? 'active' : '' ?>" href="/admin/settings/floating-cart">
                                <span class="nav-icon"><?= $icon('store') ?></span> Coș flotant
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($can('/admin/settings/store') || $can('/admin/settings/shipping') || $can('/admin/settings/payments') || $can('/admin/settings/erp') || $can('/admin/settings/mannequin') || $can('/admin/settings/google') || $can('/admin/settings/admins')): ?>
                    <div class="admin-nav-group toggle-group <?= $configOpen ? 'open' : '' ?>" data-toggle-group="configurare">
                        <button type="button" class="group-toggle <?= $configOpen ? 'active' : '' ?>">
                            <span class="left">
                                <span class="nav-icon"><?= $icon('settings') ?></span> Configurare
                            </span>
                            <span class="caret"><?= $configOpen ? '↑' : '↓' ?></span>
                        </button>
                        <div class="group-items">
                            <?php if ($can('/admin/settings/store')): ?>
                            <a class="<?= $isActive(['/admin/settings/store']) ? 'active' : '' ?>" href="/admin/settings/store">
                                <span class="nav-icon"><?= $icon('settings') ?></span> Setări magazin
                            </a>
                            <?php endif; ?>
                            <?php if ($can('/admin/settings/shipping')): ?>
                            <a class="<?= $isActive(['/admin/settings/shipping']) ? 'active' : '' ?>" href="/admin/settings/shipping">
                                <span class="nav-icon"><?= $icon('shipping') ?></span> Setări livrare
                            </a>
                            <?php endif; ?>
                            <?php if ($can('/admin/settings/payments')): ?>
                            <a class="<?= $isActive(['/admin/settings/payments']) ? 'active' : '' ?>" href="/admin/settings/payments">
                                <span class="nav-icon"><?= $icon('payments') ?></span> Setări plăți
                            </a>
                            <?php endif; ?>
                            <?php if ($can('/admin/settings/erp')): ?>
                            <a class="<?= $isActive(['/admin/settings/erp']) ? 'active' : '' ?>" href="/admin/settings/erp">
                                <span class="nav-icon"><?= $icon('settings') ?></span> ERP ANDAXI
                            </a>
                            <?php endif; ?>
                            <?php if ($can('/admin/settings/mannequin')): ?>
                            <a class="<?= $isActive(['/admin/settings/mannequin']) ? 'active' : '' ?>" href="/admin/settings/mannequin">
                                <span class="nav-icon"><?= $icon('design') ?></span> Configurare manechin
                            </a>
                            <?php endif; ?>
                            <?php if ($can('/admin/settings/google')): ?>
                            <a class="<?= $isActive(['/admin/settings/google']) ? 'active' : '' ?>" href="/admin/settings/google">
                                <span class="nav-icon"><?= $icon('settings') ?></span> Google
                            </a>
                            <?php endif; ?>
                            <?php if ($can('/admin/settings/admins')): ?>
                            <a class="<?= $isActive(['/admin/settings/admins']) ? 'active' : '' ?>" href="/admin/settings/admins">
                                <span class="nav-icon"><?= $icon('users') ?></span> Administratori
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (\App\Support\Auth::isGeneralAdmin()): ?>
                    <a class="<?= $activityLogActive ? 'active' : '' ?>" href="/admin/activity-log">
                        <span class="nav-icon"><?= $icon('shield') ?></span> Jurnal activitate
                    </a>
                    <?php endif; ?>

                    <?php if ($can('/admin/pages')): ?>
                    <a class="<?= $isActive(['/admin/pages']) ? 'active' : '' ?>" href="/admin/pages">
                        <span class="nav-icon"><?= $icon('pages') ?></span> Pagini
                    </a>
                    <?php endif; ?>
                    <?php if ($can('/admin/gallery')): ?>
                    <a class="<?= $isActive(['/admin/gallery']) ? 'active' : '' ?>" href="/admin/gallery">
                        <span class="nav-icon"><?= $icon('gallery') ?></span> Galerie
                    </a>
                    <?php endif; ?>
                    <?php if ($can('/admin/design')): ?>
                    <a class="<?= $isActive(['/admin/design']) ? 'active' : '' ?>" href="/admin/design">
                        <span class="nav-icon"><?= $icon('design') ?></span> Header/Footer/Meniu
                    </a>
                    <?php endif; ?>
                    <?php if ($can('/admin/blog/posts') || $can('/admin/blog/templates') || $can('/admin/blog/authors')): ?>
                    <div class="admin-nav-group toggle-group <?= $blogOpen ? 'open' : '' ?>" data-toggle-group="blog">
                        <button type="button" class="group-toggle <?= $blogOpen ? 'active' : '' ?>">
                            <span class="left">
                                <span class="nav-icon"><?= $icon('blog') ?></span> Blog
                            </span>
                            <span class="caret"><?= $blogOpen ? '↑' : '↓' ?></span>
                        </button>
                        <div class="group-items">
                            <?php if ($can('/admin/blog/posts')): ?>
                            <a class="<?= $isActive(['/admin/blog/posts']) ? 'active' : '' ?>" href="/admin/blog/posts">
                                <span class="nav-icon"><?= $icon('pages') ?></span> Postări blog
                            </a>
                            <?php endif; ?>
                            <?php if ($can('/admin/blog/posts')): ?>
                            <a class="<?= $isActive(['/admin/blog/categories']) ? 'active' : '' ?>" href="/admin/blog/categories">
                                <span class="nav-icon"><?= $icon('pages') ?></span> Categorii
                            </a>
                            <?php endif; ?>
                            <?php if ($can('/admin/blog/templates')): ?>
                            <a class="<?= $isActive(['/admin/blog/templates']) ? 'active' : '' ?>" href="/admin/blog/templates">
                                <span class="nav-icon"><?= $icon('design') ?></span> Template blog
                            </a>
                            <?php endif; ?>
                            <?php if ($can('/admin/blog/authors')): ?>
                            <a class="<?= $isActive(['/admin/blog/authors']) ? 'active' : '' ?>" href="/admin/blog/authors">
                                <span class="nav-icon"><?= $icon('users') ?></span> Autori blog
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($can('/admin/emails/sender') || $can('/admin/emails/test')): ?>
                    <div class="admin-nav-group toggle-group <?= $emailsOpen ? 'open' : '' ?>" data-toggle-group="emailuri">
                        <button type="button" class="group-toggle <?= $emailsOpen ? 'active' : '' ?>">
                            <span class="left">
                                <span class="nav-icon"><?= $icon('emails') ?></span> Setări email
                            </span>
                            <span class="caret"><?= $emailsOpen ? '↑' : '↓' ?></span>
                        </button>
                        <div class="group-items">
                            <a class="<?= $isActive(['/admin/emails/sender']) ? 'active' : '' ?>" href="/admin/emails/sender">
                                <span class="nav-icon"><?= $icon('emails') ?></span> Setări trimitere
                            </a>
                            <a class="<?= $isActive(['/admin/emails/test']) ? 'active' : '' ?>" href="/admin/emails/test">
                                <span class="nav-icon"><?= $icon('orders') ?></span> Trimite email test
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($can('/admin/emails/automation')): ?>
                    <a class="<?= $isActive(['/admin/emails/automation']) ? 'active' : '' ?>" href="/admin/emails/automation">
                        <span class="nav-icon"><?= $icon('automation') ?></span> Automatizări
                    </a>
                    <?php endif; ?>
                    <?php if ($can('/admin/emails/newsletters')): ?>
                    <div class="admin-nav-group toggle-group <?= $newsletterOpen ? 'open' : '' ?>" data-toggle-group="newslettere">
                        <button type="button" class="group-toggle <?= $newsletterOpen ? 'active' : '' ?>">
                            <span class="left">
                                <span class="nav-icon"><?= $icon('newsletter') ?></span> Email-uri
                            </span>
                            <span class="caret"><?= $newsletterOpen ? '↑' : '↓' ?></span>
                        </button>
                        <div class="group-items">
                            <a class="<?= $isActive(['/admin/emails/newsletters']) && $newsletterTab === 'templates' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=templates">
                                <span class="nav-icon"><?= $icon('pages') ?></span> Template-uri
                            </a>
                            <a class="<?= $isActive(['/admin/emails/newsletters']) && $newsletterTab === 'ecommerce' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=ecommerce">
                                <span class="nav-icon"><?= $icon('orders') ?></span> Email-uri ecommerce
                            </a>
                            <a class="<?= $isActive(['/admin/emails/newsletters']) && $newsletterTab === 'subscribers' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=subscribers">
                                <span class="nav-icon"><?= $icon('dashboard') ?></span> Liste abonați
                            </a>
                            <a class="<?= $isActive(['/admin/emails/newsletters']) && $newsletterTab === 'optin' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=optin">
                                <span class="nav-icon"><?= $icon('emails') ?></span> Formulare opt-in
                            </a>
                            <a class="<?= $isActive(['/admin/emails/newsletters']) && $newsletterTab === 'contact_forms' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=contact_forms">
                                <span class="nav-icon"><?= $icon('emails') ?></span> Formulare contact
                            </a>
                            <a class="<?= $isActive(['/admin/emails/newsletters']) && $newsletterTab === 'stats' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=stats">
                                <span class="nav-icon"><?= $icon('stats') ?></span> Statistici
                            </a>
                            <a class="<?= $isActive(['/admin/emails/newsletters']) && $newsletterTab === 'history' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=history">
                                <span class="nav-icon"><?= $icon('emails') ?></span> Istoric trimitere
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </nav>

                <div class="admin-sidebar-footer">
                    <a href="/" target="_blank" rel="noreferrer">Vezi site</a>
                    <form method="post" action="/admin/logout">
                        <button type="submit" class="btn btn-secondary">Logout</button>
                    </form>
                </div>
            </aside>

            <main class="admin-content">
                <div class="admin-topbar">
                    <button type="button" class="icon-btn" id="sidebar-toggle-btn" title="Toggle meniu">☰</button>
                </div>
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
        </div>
        <script>
            (() => {
                const groups = document.querySelectorAll('.toggle-group');
                const syncCaret = (group) => {
                    const caret = group.querySelector('.caret');
                    if (!caret) return;
                    caret.textContent = group.classList.contains('open') ? '↑' : '↓';
                };
                groups.forEach((group) => {
                    const key = 'admin_toggle_' + group.dataset.toggleGroup;
                    const button = group.querySelector('.group-toggle');
                    const saved = window.localStorage.getItem(key);
                    if (saved === 'open') group.classList.add('open');
                    if (saved === 'closed') group.classList.remove('open');
                    syncCaret(group);
                    button.addEventListener('click', () => {
                        group.classList.toggle('open');
                        syncCaret(group);
                        window.localStorage.setItem(key, group.classList.contains('open') ? 'open' : 'closed');
                    });
                });

                const shell = document.getElementById('admin-shell');
                const toggleBtn = document.getElementById('sidebar-toggle-btn');
                const hidden = window.localStorage.getItem('admin_sidebar_hidden');
                if (hidden === '1') shell.classList.add('sidebar-hidden');
                toggleBtn?.addEventListener('click', () => {
                    shell.classList.toggle('sidebar-hidden');
                    window.localStorage.setItem('admin_sidebar_hidden', shell.classList.contains('sidebar-hidden') ? '1' : '0');
                });
            })();
        </script>
    <?php else: ?>
        <main class="container">
            <?= $content ?>
        </main>
    <?php endif; ?>
</body>
</html>
