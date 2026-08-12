<?php

declare(strict_types=1);

// Serve /uploads/ files directly from disk; avoids 502 when nginx forwards missing static files to PHP.
(static function (): void {
    $reqPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    if (!str_starts_with($reqPath, '/uploads/')) {
        return;
    }
    // Prevent path traversal
    $resolved = realpath(__DIR__ . $reqPath);
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    if ($resolved === false || $uploadsRoot === false || !str_starts_with($resolved, $uploadsRoot . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        exit;
    }
    if (!is_file($resolved)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    $mimeMap = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4', 'pdf' => 'application/pdf',
    ];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($resolved));
    header('Cache-Control: public, max-age=604800');
    readfile($resolved);
    exit;
})();

require_once __DIR__ . '/../bootstrap.php';

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SiteController;
use App\Http\Router;
use App\Support\Database;
use App\Support\ResponseCache;
use App\Support\Settings;

// Capture Google Ads click id (gclid / gbraid / wbraid) so an order placed
// later in the session can be attributed to the ad that brought the visitor.
(static function (): void {
    $clickId = '';
    foreach (['gclid', 'gbraid', 'wbraid'] as $param) {
        $value = trim((string) ($_GET[$param] ?? ''));
        if ($value !== '') {
            $clickId = $value;
            break;
        }
    }
    if ($clickId === '' && strtolower(trim((string) ($_GET['utm_source'] ?? ''))) === 'google') {
        $clickId = 'utm-google';
    }
    if ($clickId === '') {
        return;
    }
    $clickId = substr(preg_replace('/[^A-Za-z0-9_\-\.]/', '', $clickId) ?? '', 0, 255);
    if ($clickId === '') {
        return;
    }
    setcookie('bv_gclid', $clickId, [
        'expires' => time() + 60 * 60 * 24 * 30,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
    $_COOKIE['bv_gclid'] = $clickId;
})();

$appConfig = require __DIR__ . '/../config/app.php';
$cacheDb = Database::connection((array) ($appConfig['db'] ?? []));
$cacheSettings = Settings::all($cacheDb);
ResponseCache::applyAssetCacheHeaders($cacheSettings, (string) ($_SERVER['REQUEST_URI'] ?? ''));
$pageCacheContext = ResponseCache::beginRequest(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    $cacheSettings
);
if (($pageCacheContext['served'] ?? false) === true) {
    return;
}

$router = new Router();

$router->get('/health', static function (): void {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
});

$router->get('/', [SiteController::class, 'home']);
$router->get('/magazin', [SiteController::class, 'shop']);
// URL-urile de categorie din site-ul vechi (/categorie/probiotice) rămân valide.
$router->get('/categorie/{slug}', [SiteController::class, 'shopCategory']);
$router->get('/blog', [SiteController::class, 'blog']);
$router->get('/blog/{slug}', [SiteController::class, 'blogPost']);
$router->get('/api/blog/posts', [SiteController::class, 'blogPostsApi']);
$router->get('/api/blog/posts/{slug}', [SiteController::class, 'blogPostApi']);
$router->get('/produs/{slug}', [SiteController::class, 'product']);
$router->post('/produs/{slug}/review', [SiteController::class, 'productReviewSubmit']);
$router->post('/review-form/submit', [SiteController::class, 'reviewFormSubmit']);
$router->post('/gdpr-agreements/submit', [SiteController::class, 'gdprAgreementSubmit']);
$router->get('/gdpr-agreements/success', [SiteController::class, 'gdprAgreementSuccess']);
$router->get('/cos', [SiteController::class, 'cart']);
$router->post('/cos/adauga/{id}', [SiteController::class, 'cartAdd']);
$router->post('/cos/actualizeaza', [SiteController::class, 'cartUpdate']);
$router->post('/cos/sterge/{id*}', [SiteController::class, 'cartRemove']);
$router->post('/cos/cupon', [SiteController::class, 'cartApplyCoupon']);
$router->post('/cos/cupon/sterge', [SiteController::class, 'cartClearCoupon']);
$router->post('/cos/puncte', [SiteController::class, 'cartApplyPoints']);
$router->post('/cos/puncte/sterge', [SiteController::class, 'cartClearPoints']);
$router->post('/cos/judet', [SiteController::class, 'cartSetCounty']);
$router->post('/api/cart/heartbeat', [SiteController::class, 'cartHeartbeat']);
$router->get('/api/cart/summary', [SiteController::class, 'cartSummaryApi']);
$router->get('/api/products/best-sellers', [SiteController::class, 'bestSellersApi']);
$router->get('/api/search/products', [SiteController::class, 'productSearchApi']);
$router->get('/api/shop/catalog', [SiteController::class, 'shopCatalogApi']);
$router->get('/api/fan/localities', [SiteController::class, 'fanLocalitiesApi']);
$router->get('/api/checkout/shipping-quote', [SiteController::class, 'checkoutShippingQuoteApi']);
$router->post('/api/checkout/shipping-quote', [SiteController::class, 'checkoutShippingQuoteApi']);
$router->post('/api/cart/items/{id}/add', [SiteController::class, 'cartItemAddApi']);
$router->post('/api/cart/items/{id*}/set', [SiteController::class, 'cartItemSetApi']);
$router->post('/api/cart/items/{id*}/remove', [SiteController::class, 'cartItemRemoveApi']);
$router->post('/api/cart/coupon/apply', [SiteController::class, 'cartApplyCouponApi']);
$router->post('/api/cart/coupon/clear', [SiteController::class, 'cartClearCouponApi']);
$router->get('/checkout', [SiteController::class, 'checkout']);
$router->post('/checkout', [SiteController::class, 'checkoutSubmit']);
$router->get('/checkout/succes/{orderNumber}', [SiteController::class, 'checkoutSuccess']);
$router->post('/checkout/succes/{orderNumber}', [SiteController::class, 'checkoutSuccess']);
$router->post('/webhook/stripe', [SiteController::class, 'stripeWebhook']);
$router->post('/webhook/euplatesc', [SiteController::class, 'euPlatescIpn']);
// Notificările venite din ERP (aprobare/anulare comandă), autentificate cu cheia de integrare.
$router->post('/api/erp/notificare', [AdminController::class, 'erpNotification']);
$router->post('/newsletter/optin/{slug}', [SiteController::class, 'optInSubmit']);
$router->get('/newsletter/unsubscribe/{token}', [SiteController::class, 'newsletterUnsubscribe']);
$router->get('/newsletter/track/open/{campaignId}/{subscriberId}/{token}', [SiteController::class, 'newsletterTrackOpen']);
$router->get('/newsletter/track/click/{campaignId}/{subscriberId}/{token}', [SiteController::class, 'newsletterTrackClick']);
$router->get('/contul-meu', [SiteController::class, 'account']);
$router->get('/login', [SiteController::class, 'loginPage']);
$router->get('/register', [SiteController::class, 'registerPage']);
$router->get('/auth/google', [SiteController::class, 'googleAuthStart']);
$router->get('/auth/google/callback', [SiteController::class, 'googleAuthCallback']);
$router->post('/login', [SiteController::class, 'accountLogin']);
$router->post('/register', [SiteController::class, 'accountRegister']);
$router->post('/contul-meu/inregistrare', [SiteController::class, 'accountRegister']);
$router->post('/contul-meu/login', [SiteController::class, 'accountLogin']);
$router->post('/contul-meu/logout', [SiteController::class, 'accountLogout']);
$router->post('/contul-meu/profil', [SiteController::class, 'accountProfileUpdate']);
$router->post('/contul-meu/parola', [SiteController::class, 'accountPasswordUpdate']);
$router->post('/contul-meu/adrese', [SiteController::class, 'accountAddressCreate']);
$router->post('/contul-meu/sterge', [SiteController::class, 'accountDelete']);
$router->get('/contul-meu/resetare-parola', [SiteController::class, 'passwordResetRequestForm']);
$router->post('/contul-meu/resetare-parola', [SiteController::class, 'passwordResetRequestSend']);
$router->get('/contul-meu/resetare-parola/{token}', [SiteController::class, 'passwordResetTokenForm']);
$router->post('/contul-meu/resetare-parola/{token}', [SiteController::class, 'passwordResetTokenSubmit']);
$router->get('/contact', [SiteController::class, 'contact']);
$router->post('/contact/send', [SiteController::class, 'contactSend']);

$router->get('/admin/login', [AuthController::class, 'showLogin']);
$router->post('/admin/login', [AuthController::class, 'login']);
$router->post('/admin/logout', [AuthController::class, 'logout']);

$router->get('/admin', [AdminController::class, 'dashboard']);
$router->get('/admin/users', [AdminController::class, 'users']);
$router->post('/admin/users/import', [AdminController::class, 'usersImport']);
$router->post('/admin/users/save', [AdminController::class, 'usersSave']);
$router->post('/admin/users/delete-selected', [AdminController::class, 'usersDeleteSelected']);
$router->get('/admin/users/settings', [AdminController::class, 'usersSettings']);
$router->post('/admin/users/settings', [AdminController::class, 'usersSettingsSave']);
$router->get('/admin/users/points', [AdminController::class, 'usersPoints']);
$router->get('/admin/users/points/history', [AdminController::class, 'usersPointsHistory']);
$router->post('/admin/users/points', [AdminController::class, 'usersPointsSave']);
$router->post('/admin/users/points/import', [AdminController::class, 'usersPointsImport']);
$router->get('/admin/users/security', [AdminController::class, 'usersFormSecurity']);
$router->get('/admin/users/gdpr-agreements', [AdminController::class, 'gdprAgreements']);
$router->get('/admin/users/gdpr-agreements/export', [AdminController::class, 'gdprAgreementsExport']);
$router->get('/admin/users/{id}', [AdminController::class, 'userDetails']);
$router->get('/admin/products', [AdminController::class, 'products']);
$router->get('/admin/products/fields', [AdminController::class, 'productFields']);
$router->post('/admin/products/fields', [AdminController::class, 'productFieldsSave']);
$router->get('/admin/products/templates/new', [AdminController::class, 'productTemplatesNew']);
$router->get('/admin/products/templates/builder', [AdminController::class, 'productTemplateBuilderEntry']);
$router->get('/admin/products/templates/{id}/builder', [AdminController::class, 'productTemplateBuilder']);
$router->post('/admin/products/templates/create-basic', [AdminController::class, 'productTemplatesSave']);
$router->get('/admin/products/templates', [AdminController::class, 'productTemplates']);
$router->post('/admin/products/templates', [AdminController::class, 'productTemplatesSave']);
$router->get('/admin/blog/posts', [AdminController::class, 'blogPosts']);
$router->post('/admin/blog/posts', [AdminController::class, 'blogPostsSave']);
$router->post('/admin/blog/posts/image-upload', [AdminController::class, 'productImageUpload']);
$router->post('/admin/blog/posts/import', [AdminController::class, 'blogPostsImport']);
$router->get('/admin/blog/posts/trash', [AdminController::class, 'blogPostsTrash']);
$router->get('/admin/blog/posts/new', [AdminController::class, 'blogPostNew']);
$router->get('/admin/blog/posts/editor', [AdminController::class, 'blogPostEditor']);
$router->get('/admin/blog/categories', [AdminController::class, 'blogCategories']);
$router->post('/admin/blog/categories', [AdminController::class, 'blogCategoryCreate']);
$router->post('/admin/blog/categories/{id}/update', [AdminController::class, 'blogCategoryUpdate']);
$router->post('/admin/blog/categories/{id}/delete', [AdminController::class, 'blogCategoryDelete']);
$router->get('/admin/blog/templates', [AdminController::class, 'blogTemplates']);
$router->post('/admin/blog/templates', [AdminController::class, 'blogTemplatesSave']);
$router->get('/admin/blog/templates/builder', [AdminController::class, 'blogTemplateBuilderEntry']);
$router->get('/admin/blog/templates/{id}/builder', [AdminController::class, 'blogTemplateBuilder']);
$router->get('/admin/blog/authors', [AdminController::class, 'blogAuthors']);
$router->post('/admin/blog/authors', [AdminController::class, 'blogAuthorsSave']);
$router->get('/admin/products/reviews', [AdminController::class, 'productReviews']);
$router->post('/admin/products/reviews', [AdminController::class, 'productReviewsSave']);
$router->post('/admin/products/reviews/import', [AdminController::class, 'productReviewsImport']);
$router->get('/admin/categories', [AdminController::class, 'categories']);
$router->post('/admin/categories', [AdminController::class, 'createCategory']);
$router->post('/admin/categories/{id}/update', [AdminController::class, 'updateCategory']);
$router->post('/admin/categories/{id}/delete', [AdminController::class, 'deleteCategory']);
$router->get('/admin/coupons', [AdminController::class, 'coupons']);
$router->post('/admin/coupons', [AdminController::class, 'couponsSave']);
$router->get('/admin/coupons/unique', [AdminController::class, 'couponsUnique']);
$router->post('/admin/coupons/unique/import', [AdminController::class, 'couponsUniqueImport']);
$router->post('/admin/coupons/unique/action', [AdminController::class, 'couponsUniqueAction']);
$router->get('/admin/products/trash', [AdminController::class, 'productsTrash']);
$router->get('/admin/products/export', [AdminController::class, 'productsExport']);
$router->get('/admin/products/new', [AdminController::class, 'createProductForm']);
$router->post('/admin/products', [AdminController::class, 'createProduct']);
$router->post('/admin/products/{id}/update', [AdminController::class, 'updateProduct']);
$router->post('/admin/products/{id}/delete', [AdminController::class, 'deleteProduct']);
$router->post('/admin/products/{id}/restore', [AdminController::class, 'restoreProduct']);
$router->post('/admin/products/{id}/force-delete', [AdminController::class, 'forceDeleteProduct']);
$router->post('/admin/products/image-upload', [AdminController::class, 'productImageUpload']);
$router->get('/admin/orders', [AdminController::class, 'orders']);
$router->get('/admin/orders/export', [AdminController::class, 'ordersExport']);
$router->post('/admin/orders/{id}/erp-retry', [AdminController::class, 'orderErpRetry']);
$router->get('/admin/orders/trash', [AdminController::class, 'ordersTrash']);
$router->post('/admin/orders/{id}/status', [AdminController::class, 'updateOrderStatus']);
$router->post('/admin/orders/{id}/delete', [AdminController::class, 'deleteOrder']);
$router->post('/admin/orders/{id}/restore', [AdminController::class, 'restoreOrder']);
$router->post('/admin/orders/{id}/force-delete', [AdminController::class, 'forceDeleteOrder']);
$router->post('/admin/orders/{id}/address', [AdminController::class, 'updateOrderAddress']);
$router->post('/admin/orders/{id}/promo', [AdminController::class, 'orderPromoSave']);
$router->post('/admin/orders/{id}/items', [AdminController::class, 'orderItemsSave']);
$router->get('/admin/orders/{id}/client-promo', [AdminController::class, 'orderClientPromo']);
$router->get('/admin/promo-products/search', [AdminController::class, 'promoClientSearchApi']);
$router->get('/admin/promo-products/{id}/recipients', [AdminController::class, 'promoProductRecipientsApi']);
$router->get('/admin/promo-products', [AdminController::class, 'promoProducts']);
$router->post('/admin/promo-products', [AdminController::class, 'promoProductSave']);
$router->post('/admin/promo-products/{id}/delete', [AdminController::class, 'promoProductDelete']);
$router->post('/admin/orders/{id}/fan-awb', [AdminController::class, 'createFanAwb']);
$router->post('/admin/orders/{id}/fan-tracking', [AdminController::class, 'refreshFanTracking']);
$router->post('/admin/orders/bulk', [AdminController::class, 'ordersBulkAction']);
$router->get('/admin/settings/store', [AdminController::class, 'storeSettingsForm']);
$router->post('/admin/settings/store', [AdminController::class, 'storeSettingsSave']);
$router->get('/admin/settings/admins', [AdminController::class, 'adminsSettingsForm']);
$router->post('/admin/settings/admins', [AdminController::class, 'adminsSettingsSave']);
$router->get('/admin/activity-log', [AdminController::class, 'activityLog']);
$router->get('/admin/settings/mannequin', [AdminController::class, 'mannequinSettingsForm']);
$router->post('/admin/settings/mannequin', [AdminController::class, 'mannequinSettingsSave']);
$router->get('/admin/settings/google', [AdminController::class, 'googleSettingsForm']);
$router->post('/admin/settings/google', [AdminController::class, 'googleSettingsSave']);
$router->get('/admin/settings/floating-cart', [AdminController::class, 'floatingCartSettingsForm']);
$router->post('/admin/settings/floating-cart', [AdminController::class, 'floatingCartSettingsSave']);
$router->get('/admin/settings/shipping', [AdminController::class, 'shippingSettingsForm']);
$router->get('/admin/settings/shipping/judete/export', [AdminController::class, 'shippingJudeteExport']);
$router->get('/admin/settings/shipping/localities/search', [AdminController::class, 'shippingLocalitiesSearch']);
$router->post('/admin/settings/shipping', [AdminController::class, 'shippingSettingsSave']);
$router->post('/admin/settings/shipping/localities/import', [AdminController::class, 'shippingLocalitiesImport']);
$router->post('/admin/settings/shipping/streets/import', [AdminController::class, 'shippingStreetsImport']);
$router->post('/admin/settings/shipping/extra-km/import', [AdminController::class, 'shippingLocalitiesKmImport']);
$router->get('/admin/settings/erp', [AdminController::class, 'erpSettingsForm']);
$router->post('/admin/settings/erp', [AdminController::class, 'erpSettingsSave']);
$router->get('/admin/settings/payments', [AdminController::class, 'paymentSettingsForm']);
$router->post('/admin/settings/payments', [AdminController::class, 'paymentSettingsSave']);
$router->get('/admin/emails', [AdminController::class, 'emails']);
$router->get('/admin/emails/builder', [AdminController::class, 'emailsBuilder']);
$router->post('/admin/emails/builder', [AdminController::class, 'emailsBuilderSave']);
$router->post('/admin/emails/builder/preview', [AdminController::class, 'emailsBuilderPreview']);
$router->get('/admin/emails/{section}', [AdminController::class, 'emailsSection']);
$router->post('/admin/emails', [AdminController::class, 'emailsSave']);
$router->post('/admin/emails/test', [AdminController::class, 'emailsSendTest']);
$router->get('/admin/pages', [AdminController::class, 'pages']);
$router->get('/admin/pages/trash', [AdminController::class, 'pagesTrash']);
$router->get('/admin/pages/new', [AdminController::class, 'pageCreateForm']);
$router->get('/admin/pages/{id}/edit', [AdminController::class, 'pageEditForm']);
$router->post('/admin/pages/save', [AdminController::class, 'pageSave']);
$router->post('/admin/pages/{id}/delete', [AdminController::class, 'deletePage']);
$router->post('/admin/pages/{id}/restore', [AdminController::class, 'restorePage']);
$router->post('/admin/pages/{id}/force-delete', [AdminController::class, 'forceDeletePage']);
$router->get('/admin/pages/gdpr-agreements', [AdminController::class, 'gdprAgreements']);
$router->get('/admin/pages/gdpr-agreements/export', [AdminController::class, 'gdprAgreementsExport']);
$router->get('/admin/gallery', [AdminController::class, 'gallery']);
$router->post('/admin/gallery/folders', [AdminController::class, 'createGalleryFolder']);
$router->post('/admin/gallery/folders/{id}/delete', [AdminController::class, 'deleteGalleryFolder']);
$router->post('/admin/gallery/move-folder', [AdminController::class, 'moveGalleryItemToFolder']);
$router->post('/admin/gallery', [AdminController::class, 'galleryCreate']);
$router->post('/admin/gallery/{id}/update', [AdminController::class, 'galleryUpdate']);
$router->post('/admin/gallery/{id}/delete', [AdminController::class, 'galleryDelete']);
$router->post('/admin/gallery/bulk-delete', [AdminController::class, 'galleryBulkDelete']);
$router->get('/admin/design', [AdminController::class, 'designSite']);
$router->post('/admin/design/save', [AdminController::class, 'designSiteSave']);

$router->get('/{slug*}', [SiteController::class, 'customPage']);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
ResponseCache::finishRequest($pageCacheContext);
