<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Cart;
use App\Support\CheckoutCalculator;
use App\Support\CustomerAuth;
use App\Support\Database;
use App\Support\EmailAutomation;
use App\Support\FanCourierGateway;
use App\Support\Flash;
use App\Support\LoyaltyService;
use App\Support\NewsletterService;
use App\Support\OrderMailer;
use App\Support\OrderNumber;
use App\Support\Settings;
use App\Support\EuPlatescGateway;
use App\Support\StripeGateway;
use App\Support\View;
use App\Support\WordPressPassword;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class SiteController
{
    private const CART_FORM_TOKEN = '{{cart_form}}';
    private const CHECKOUT_FORM_TOKEN = '{{checkout_form}}';
    private const ACCOUNT_SECTION_TOKEN = '{{account_section}}';
    private const PRODUCT_REVIEW_FORM_TOKEN = '{{product_review_form}}';
    private const GDPR_AGREEMENTS_FORM_TOKEN = '{{gdpr_agreements_form}}';
    private const CHECKOUT_SUCCESS_ORDER_INFO_TOKEN = '{{checkout_success_order_info}}';
    private const BLOG_POSTS_PER_PAGE = 13;
    private const FAN_LOCALITIES_API_ENDPOINT = '/api/fan/localities';
    private const CHECKOUT_SHIPPING_QUOTE_API_ENDPOINT = '/api/checkout/shipping-quote';
    private const CHECKOUT_ANTIBOT_SESSION_KEY = 'checkout_antibot_tokens';
    /**
     * Numele capcanei anti-bot. Nu trebuie să conțină cuvinte pe care le
     * recunoaște completarea automată din browser („company”, „website”,
     * „address”, „name”…), altfel Chrome/Safari o completează singur și
     * clientul real e blocat.
     */
    public const CHECKOUT_HONEYPOT_FIELD = 'bv_extra_ref';
    private const CHECKOUT_ANTIBOT_MIN_SECONDS = 2;
    private const CHECKOUT_ANTIBOT_MAX_AGE_SECONDS = 7200;
    private const CHECKOUT_ANTIBOT_MAX_ATTEMPTS_PER_15_MIN = 10;
    private const REGISTER_ANTIBOT_SESSION_KEY = 'register_antibot_tokens';
    private const REGISTER_ANTIBOT_MIN_SECONDS = 2;
    private const REGISTER_ANTIBOT_MAX_AGE_SECONDS = 7200;
    private const REGISTER_ANTIBOT_MAX_ATTEMPTS_PER_15_MIN = 8;
    private array $cachedSettings = [];
    public function home(): void
    {
        $homePage = $this->findPublishedRootPage();
        if (is_array($homePage)) {
            $db = $this->db();
            $settings = $this->cachedSettings($db);
            View::render('site/custom-page', array_merge([
                'title' => (string) ($homePage['title'] ?? 'Acasă'),
                'page' => $homePage,
                'mannequinSectionHtml' => $this->renderMannequinSection($db, $settings),
            ], $this->customPageSeoMeta($db instanceof PDO ? $db : null, $homePage)));
            return;
        }

        $db = $this->db();
        [$products] = $this->loadProducts(limit: 4);

        View::render('site/home', [
            'products' => $products,
            'categories' => $this->loadShopCatalogCategories($db instanceof PDO ? $db : null),
            'blogPosts' => $this->loadPublishedBlogPosts($db instanceof PDO ? $db : null, 3),
            'title' => 'Acasă',
        ]);
    }

    /**
     * Păstrează structura de URL din site-ul vechi: /categorie/<slug>.
     * Slug-ul este rezolvat la numele categoriei, apoi se afișează magazinul filtrat.
     */
    public function shopCategory(array $params = []): void
    {
        $slug = trim((string) ($params['slug'] ?? ''), '/');
        $categoryName = $this->resolveCategoryNameBySlug($slug);

        if ($categoryName === '') {
            http_response_code(404);
        }

        // shop() citește filtrul din query string.
        $_GET['categorie'] = $categoryName;
        $this->shop();
    }

    /**
     * Caută numele categoriei după slug: întâi în product_categories
     * (unde importul a păstrat slug-urile din WooCommerce), apoi prin
     * comparație pe slug generat din numele categoriilor existente.
     */
    private function resolveCategoryNameBySlug(string $slug): string
    {
        $slug = mb_strtolower(trim($slug));
        if ($slug === '') {
            return '';
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            return '';
        }

        try {
            $stmt = $db->prepare('SELECT name FROM product_categories WHERE LOWER(slug) = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            $name = $stmt->fetchColumn();
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        } catch (Throwable) {
            // Continuăm cu potrivirea pe slug generat.
        }

        foreach ($this->loadShopCatalogCategories($db) as $category) {
            if (!is_array($category)) {
                continue;
            }
            $value = trim((string) ($category['value'] ?? ''));
            if ($value !== '' && $this->slugifyCategoryName($value) === $slug) {
                return $value;
            }
        }

        return '';
    }

    private function slugifyCategoryName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($value, '-');
    }

    public function shop(): void
    {
        $shopPage = $this->findPublishedPageBySlug('magazin');
        $categoryFilter = $this->requestCategoryFilter();
        if (is_array($shopPage)) {
            $db = $this->db();
            $settings = $this->cachedSettings($db);
            // Categoria validată (goală dacă filtrul din URL nu există),
            // ca pagina să poată afișa titlul categoriei curente.
            $shopCategoryLabel = $this->normalizeShopCategoryFilter(
                $categoryFilter,
                $this->loadShopCatalogCategories($db instanceof PDO ? $db : null)
            );
            $pageTitle = $shopCategoryLabel !== ''
                ? $shopCategoryLabel
                : (string) ($shopPage['title'] ?? 'Magazin');
            View::render('site/custom-page', array_merge([
                'title' => $pageTitle,
                'page' => $shopPage,
                'mannequinSectionHtml' => $this->renderMannequinSection($db, $settings),
                'shopCatalogHtml' => $this->renderShopCatalogSection($db, $categoryFilter),
                'shopCategoryLabel' => $shopCategoryLabel,
                'shopPageTitle' => (string) ($shopPage['title'] ?? 'Magazin'),
            ], $this->customPageSeoMeta($db instanceof PDO ? $db : null, $shopPage)));
            return;
        }

        [$products] = $this->loadProducts(categoryFilter: $categoryFilter);
        View::render('site/shop', [
            'products' => $products,
            'title' => 'Magazin',
            'activeCategory' => $categoryFilter,
        ]);
    }

    public function blog(): void
    {
        $db = $this->db();
        $blogPostsHtml = $this->renderBlogPostsSection($db instanceof PDO ? $db : null);
        $blogPage = $this->findPublishedPageBySlug('blog');
        if (is_array($blogPage)) {
            $settings = $this->cachedSettings($db instanceof PDO ? $db : null);
            View::render('site/custom-page', array_merge([
                'title' => (string) ($blogPage['title'] ?? 'Blog'),
                'page' => $blogPage,
                'mannequinSectionHtml' => $this->renderMannequinSection($db instanceof PDO ? $db : null, $settings),
                'blogPostsHtml' => $blogPostsHtml,
            ], $this->customPageSeoMeta($db instanceof PDO ? $db : null, $blogPage)));
            return;
        }

        View::render('site/custom-page', [
            'title' => 'Blog',
            'page' => [
                'html_content' => '{{blog_posts}}',
                'css_content' => '',
                'js_content' => '',
            ],
            'blogPostsHtml' => $blogPostsHtml,
            'mannequinSectionHtml' => '',
        ]);
    }

    public function blogPostsApi(): void
    {
        $db = $this->db();
        $posts = $this->loadPublishedBlogPosts($db, 24);
        $this->jsonResponse([
            'ok' => true,
            'count' => count($posts),
            'posts' => $posts,
        ]);
    }

    public function blogPostApi(array $params): void
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        if ($slug === '') {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Articol invalid.',
            ], 400);
            return;
        }

        $post = $this->loadPublishedBlogPostBySlug($this->db(), $slug);
        if (!is_array($post)) {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Articolul nu a fost găsit.',
            ], 404);
            return;
        }

        $this->jsonResponse([
            'ok' => true,
            'post' => $post,
        ]);
    }

    public function blogPost(array $params): void
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        if ($slug === '') {
            http_response_code(404);
            echo 'Articolul nu a fost găsit.';
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(404);
            echo 'Articolul nu a fost găsit.';
            return;
        }
        $post = $this->loadPublishedBlogPostBySlug($db, $slug);
        if (!is_array($post)) {
            http_response_code(404);
            echo 'Articolul nu a fost găsit.';
            return;
        }

        $render = $this->buildBlogTemplateRender($post);
        $seoMeta = $this->seoMetaFor($db, 'blog_post', (string) ((int) ($post['id'] ?? 0)));
        View::render('site/custom-page', array_merge([
            'title' => (string) ($post['title'] ?? 'Articol'),
            'page' => [
                'html_content' => (string) ($render['html'] ?? ''),
                'css_content' => (string) ($render['css'] ?? ''),
                'js_content' => (string) ($render['js'] ?? ''),
            ],
            'mannequinSectionHtml' => '',
        ], $seoMeta));
    }

    public function product(array $params): void
    {
        [$products, $db] = $this->loadProducts();
        $slug = $params['slug'] ?? '';

        $product = null;
        if ($db instanceof PDO) {
            $stmt = $db->prepare('SELECT * FROM products WHERE slug = :slug AND deleted_at IS NULL LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            $product = $stmt->fetch() ?: null;
        }

        if ($product === null) {
            foreach ($products as $item) {
                if (($item['slug'] ?? '') === $slug) {
                    $product = $item;
                    break;
                }
            }
        }

        if ($product === null) {
            // Link vechi (site-ul anterior, Google, bookmark): dacă putem
            // identifica fără dubiu produsul, trimitem clientul direct la el.
            $potrivit = $this->gasesteProdusDupaSlugAproximativ($db, (string) $slug);
            if ($potrivit !== null && trim((string) ($potrivit['slug'] ?? '')) !== '') {
                header('Location: /produs/' . rawurlencode((string) $potrivit['slug']), true, 301);
                return;
            }
            $this->renderPagina404(
                'Produsul căutat nu mai există',
                'Este posibil ca linkul să fie vechi sau produsul să nu mai fie disponibil. Caută-l după nume sau vezi toate produsele din magazin.',
                $db
            );
            return;
        }

        $product = $this->normalizeProduct($product);
        // Cu ERP-ul conectat, disponibilitatea vine din gestiune, nu din fișa
        // produsului. Dacă ERP-ul nu răspunde, rămâne stocul de pe site.
        $product = \App\Support\ErpStock::applyToProduct($db, $product);
        $product['bbd_entries'] = $this->decorateProductBbdEntriesWithAvailability($product);
        $isOutOfStock = (int) ($product['out_of_stock'] ?? 0) === 1;
        $extraFields = [];
        $template = null;
        $templateRender = null;
        $productReviews = [
            'count' => 0,
            'average' => 0.0,
            'average_label' => '0.0',
            'stars_html' => '',
            'list_html' => '<p class="product-reviews-empty">Nu există review-uri încă.</p>',
            'form_html' => '',
            'section_html' => '',
            'items' => [],
        ];
        $settings = [];
        $similarProducts = $this->buildSimilarProductsForSite($products, $product);
        $similarProductsSectionHtml = $this->buildSimilarProductsSectionHtml($similarProducts);
        if ($db instanceof PDO) {
            $this->ensureProductCustomSchema($db);
            $settings = Settings::all($db);
            $extraFields = $this->loadProductExtraFieldsForSite($db, (int) ($product['id'] ?? 0));
            $productReviews = $this->loadProductReviewsForSite($db, $product);
            $template = $this->loadProductTemplateForSite(
                $db,
                (int) ($product['product_template_id'] ?? 0)
            );
            if (is_array($template)) {
                $templateRender = $this->buildProductTemplateRender($template, $product, $extraFields, $settings, $productReviews, $similarProducts);
            }
        }

        $productSeoMeta = [];
        if ($db instanceof PDO) {
            $productSeoMeta = $this->seoMetaFor($db, 'product', (string) ((int) ($product['id'] ?? 0)));
        }
        View::render('site/product', array_merge([
            'product' => $product,
            'extraFields' => $extraFields,
            'productReviews' => $productReviews,
            'similarProducts' => $similarProducts,
            'similarProductsSectionHtml' => $similarProductsSectionHtml,
            'productTemplate' => $template,
            'productTemplateRender' => $templateRender,
            'quantityConfig' => [
                'style' => in_array((string) ($settings['store_quantity_control_style'] ?? 'default'), ['default', 'stepper'], true)
                    ? (string) ($settings['store_quantity_control_style'] ?? 'default')
                    : 'default',
                'apply_product_template' => (string) ($settings['store_quantity_apply_product_template'] ?? '0') === '1',
            ],
            'isOutOfStock' => $isOutOfStock,
            'title' => (string) $product['name'],
        ], $productSeoMeta));
    }

    public function productReviewSubmit(array $params): void
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        if ($slug === '') {
            header('Location: /magazin');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă momentan.');
            header('Location: /produs/' . rawurlencode($slug) . '#product-reviews');
            return;
        }
        $this->ensureProductCustomSchema($db);

        $stmt = $db->prepare('SELECT id, slug, name FROM products WHERE slug = :slug AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $product = $stmt->fetch() ?: null;
        if (!is_array($product)) {
            $this->renderPagina404(
                'Produsul căutat nu mai există',
                'Recenzia nu a putut fi salvată pentru că produsul nu mai este disponibil.',
                $db
            );
            return;
        }

        $customer = CustomerAuth::user($db);
        $defaultName = is_array($customer)
            ? trim((string) (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')))
            : '';
        $name = trim((string) ($_POST['review_name'] ?? $defaultName));
        $rating = (int) ($_POST['review_rating'] ?? 5);
        $rating = max(1, min(5, $rating));
        $text = trim((string) ($_POST['review_text'] ?? ''));

        if (mb_strlen($name) < 2) {
            Flash::set('error', 'Completează numele pentru review.');
            header('Location: /produs/' . rawurlencode((string) $product['slug']) . '#product-reviews');
            return;
        }
        if (mb_strlen($text) < 6) {
            Flash::set('error', 'Review-ul este prea scurt.');
            header('Location: /produs/' . rawurlencode((string) $product['slug']) . '#product-reviews');
            return;
        }

        $payload = [
            'product_id' => (int) ($product['id'] ?? 0),
            'user_name' => $name,
            'user_email' => (string) (($customer['email'] ?? '') ?: ''),
            'rating' => $rating,
            'review_text' => $text,
            'source' => 'product_page',
        ];
        try {
            $insert = $db->prepare(
                'INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, source, is_approved)
                 VALUES (:product_id, :user_name, :user_email, :rating, :review_text, :source, 0)'
            );
            $insert->execute($payload);
        } catch (Throwable) {
            try {
                $insert = $db->prepare(
                    'INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, is_approved)
                     VALUES (:product_id, :user_name, :user_email, :rating, :review_text, 0)'
                );
                $insert->execute([
                    'product_id' => (int) $payload['product_id'],
                    'user_name' => (string) $payload['user_name'],
                    'user_email' => (string) $payload['user_email'],
                    'rating' => (int) $payload['rating'],
                    'review_text' => (string) $payload['review_text'],
                ]);
            } catch (Throwable) {
                $legacyInsert = $db->prepare(
                    'INSERT INTO reviews (product_id, user_name, rating, review_text, is_approved)
                     VALUES (:product_id, :user_name, :rating, :review_text, 0)'
                );
                $legacyInsert->execute([
                    'product_id' => (int) $payload['product_id'],
                    'user_name' => (string) $payload['user_name'],
                    'rating' => (int) $payload['rating'],
                    'review_text' => (string) $payload['review_text'],
                ]);
            }
        }

        Flash::set('success', 'Mulțumim! Review-ul tău a fost trimis și așteaptă aprobarea.');
        header('Location: /produs/' . rawurlencode((string) $product['slug']) . '#product-reviews');
    }

    public function reviewFormSubmit(): void
    {
        $redirectRaw = trim((string) ($_POST['redirect_to'] ?? '/'));
        $redirectTo = $this->safeRedirectPath($redirectRaw, '/');

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă momentan.');
            header('Location: ' . $redirectTo);
            return;
        }
        $this->ensureProductCustomSchema($db);

        $productId = (int) ($_POST['product_id'] ?? 0);
        $customer = CustomerAuth::user($db);
        $defaultName = is_array($customer)
            ? trim((string) (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')))
            : '';
        $name = trim((string) ($_POST['review_name'] ?? $defaultName));
        $email = trim((string) ($_POST['review_email'] ?? ''));
        if ($email === '' && is_array($customer)) {
            $email = trim((string) ($customer['email'] ?? ''));
        }
        $rating = (int) ($_POST['review_rating'] ?? 5);
        $rating = max(1, min(5, $rating));
        $text = trim((string) ($_POST['review_text'] ?? ''));

        if ($productId <= 0) {
            Flash::set('error', 'Selectează un produs pentru recenzie.');
            header('Location: ' . $redirectTo);
            return;
        }

        $stmt = $db->prepare('SELECT id FROM products WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch() ?: null;
        if (!is_array($product)) {
            Flash::set('error', 'Produsul selectat nu a fost găsit.');
            header('Location: ' . $redirectTo);
            return;
        }

        if (mb_strlen($name) < 2) {
            Flash::set('error', 'Completează numele pentru recenzie.');
            header('Location: ' . $redirectTo);
            return;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Flash::set('error', 'Adresa de email nu este validă.');
            header('Location: ' . $redirectTo);
            return;
        }
        if (mb_strlen($text) < 6) {
            Flash::set('error', 'Recenzia este prea scurtă.');
            header('Location: ' . $redirectTo);
            return;
        }

        $payload = [
            'product_id' => $productId,
            'user_name' => $name,
            'user_email' => $email,
            'rating' => $rating,
            'review_text' => $text,
            'source' => 'qr_form',
        ];
        try {
            $insert = $db->prepare(
                'INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, source, is_approved)
                 VALUES (:product_id, :user_name, :user_email, :rating, :review_text, :source, 0)'
            );
            $insert->execute($payload);
        } catch (Throwable) {
            $insert = $db->prepare(
                'INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, is_approved)
                 VALUES (:product_id, :user_name, :user_email, :rating, :review_text, 0)'
            );
            $insert->execute([
                'product_id' => (int) $payload['product_id'],
                'user_name' => (string) $payload['user_name'],
                'user_email' => (string) $payload['user_email'],
                'rating' => (int) $payload['rating'],
                'review_text' => (string) $payload['review_text'],
            ]);
        }

        Flash::set('success', 'Mulțumim! Recenzia a fost trimisă și va apărea după aprobare.');
        header('Location: ' . $redirectTo);
    }

    public function gdprAgreementSubmit(): void
    {
        $redirectRaw = trim((string) ($_POST['redirect_to'] ?? '/acorduri-gdpr#gdpr-agreement-form'));
        $redirectTo = $this->safeRedirectPath($redirectRaw, '/acorduri-gdpr#gdpr-agreement-form');

        $subiectNumeComplet = trim((string) ($_POST['subiect_nume_complet'] ?? ''));
        $ciSerie = strtoupper(trim((string) ($_POST['ci_serie'] ?? '')));
        $ciNumar = trim((string) ($_POST['ci_numar'] ?? ''));
        $ciEmitent = trim((string) ($_POST['ci_emitent'] ?? ''));
        $ciDataEliberare = trim((string) ($_POST['ci_data_eliberare'] ?? ''));
        $nume = trim((string) ($_POST['nume'] ?? ''));
        $prenume = trim((string) ($_POST['prenume'] ?? ''));
        $cnp = preg_replace('/\s+/', '', (string) ($_POST['cnp'] ?? '')) ?? '';
        $cuim = trim((string) ($_POST['cuim'] ?? ''));
        $telefon = trim((string) ($_POST['telefon'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $adresaCorespondenta = trim((string) ($_POST['adresa_corespondenta'] ?? ''));
        $institutieMedicala = trim((string) ($_POST['institutie_medicala'] ?? ''));
        $institutieActivitate = trim((string) ($_POST['institutie_activitate'] ?? ''));
        $institutieAdresa = trim((string) ($_POST['institutie_adresa'] ?? ''));
        $institutieActivitateAdresa = trim((string) ($_POST['institutie_activitate_adresa'] ?? ''));
        $tipMedic = trim((string) ($_POST['tip_medic'] ?? ''));
        $specializare = trim((string) ($_POST['specializare'] ?? ''));
        $dataSemnare = trim((string) ($_POST['data_semnare'] ?? ''));
        $numeSemnatura = trim((string) ($_POST['nume_semnatura'] ?? ''));
        $signatureDataUrl = trim((string) ($_POST['signature_data_url'] ?? ''));
        $formInput = [
            'subiect_nume_complet' => $subiectNumeComplet,
            'ci_serie' => $ciSerie,
            'ci_numar' => $ciNumar,
            'ci_emitent' => $ciEmitent,
            'ci_data_eliberare' => $ciDataEliberare,
            'nume' => $nume,
            'prenume' => $prenume,
            'cnp' => $cnp,
            'cuim' => $cuim,
            'telefon' => $telefon,
            'email' => $email,
            'adresa_corespondenta' => $adresaCorespondenta,
            'institutie_medicala' => $institutieMedicala,
            'institutie_activitate' => $institutieActivitate,
            'institutie_adresa' => $institutieAdresa,
            'institutie_activitate_adresa' => $institutieActivitateAdresa,
            'tip_medic' => $tipMedic,
            'specializare' => $specializare,
            'data_semnare' => $dataSemnare,
            'nume_semnatura' => $numeSemnatura,
            'signature_data_url' => $signatureDataUrl,
        ];
        $fail = function (string $message) use ($formInput, $redirectTo): void {
            $this->storeGdprAgreementOldInput($formInput);
            Flash::set('error', $message);
            header('Location: ' . $redirectTo);
        };

        $db = $this->db();
        if (!$db instanceof PDO) {
            $fail('Conexiunea DB nu este disponibilă momentan.');
            return;
        }
        $this->ensureGdprAgreementsSchema($db);

        if ($numeSemnatura === '') {
            $fail('Numele în clar este obligatoriu.');
            return;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fail('Adresa de email nu este validă.');
            return;
        }
        if ($cnp !== '' && preg_match('/^\d{13}$/', $cnp) !== 1) {
            $fail('CNP-ul trebuie să conțină exact 13 cifre.');
            return;
        }

        $ciDateNormalized = null;
        if ($ciDataEliberare !== '') {
            $ciDateNormalized = $this->normalizeFormDate($ciDataEliberare);
        }
        if ($ciDataEliberare !== '' && $ciDateNormalized === null) {
            $fail('Data eliberării CI nu este validă.');
            return;
        }
        $semnareDateNormalized = null;
        if ($dataSemnare !== '') {
            $semnareDateNormalized = $this->normalizeFormDate($dataSemnare);
        }
        if ($dataSemnare !== '' && $semnareDateNormalized === null) {
            $fail('Data semnării nu este validă.');
            return;
        }

        if (!str_starts_with($signatureDataUrl, 'data:image/png;base64,')
            && !str_starts_with($signatureDataUrl, 'data:image/jpeg;base64,')
            && !str_starts_with($signatureDataUrl, 'data:image/webp;base64,')
        ) {
            $fail('Semnătura este invalidă. Te rugăm semnează din nou.');
            return;
        }
        if (strlen($signatureDataUrl) > 2_500_000) {
            $fail('Semnătura este prea mare. Șterge și semnează din nou.');
            return;
        }

        try {
            $insert = $db->prepare(
                'INSERT INTO gdpr_agreements (
                    subiect_nume_complet, ci_serie, ci_numar, ci_emitent, ci_data_eliberare,
                    nume, prenume, cnp, cuim, telefon, email, adresa_corespondenta,
                    institutie_medicala, institutie_activitate, institutie_adresa, institutie_activitate_adresa,
                    tip_medic, specializare, data_semnare, nume_semnatura, signature_data_url,
                    source_url, ip_address, user_agent, created_at
                 ) VALUES (
                    :subiect_nume_complet, :ci_serie, :ci_numar, :ci_emitent, :ci_data_eliberare,
                    :nume, :prenume, :cnp, :cuim, :telefon, :email, :adresa_corespondenta,
                    :institutie_medicala, :institutie_activitate, :institutie_adresa, :institutie_activitate_adresa,
                    :tip_medic, :specializare, :data_semnare, :nume_semnatura, :signature_data_url,
                    :source_url, :ip_address, :user_agent, NOW()
                )'
            );
            $insert->execute([
                'subiect_nume_complet' => $subiectNumeComplet,
                'ci_serie' => $ciSerie,
                'ci_numar' => $ciNumar,
                'ci_emitent' => $ciEmitent,
                'ci_data_eliberare' => $ciDateNormalized,
                'nume' => $nume,
                'prenume' => $prenume,
                'cnp' => $cnp,
                'cuim' => $cuim,
                'telefon' => $telefon,
                'email' => $email,
                'adresa_corespondenta' => $adresaCorespondenta,
                'institutie_medicala' => $institutieMedicala,
                'institutie_activitate' => $institutieActivitate,
                'institutie_adresa' => $institutieAdresa,
                'institutie_activitate_adresa' => $institutieActivitateAdresa,
                'tip_medic' => $tipMedic,
                'specializare' => $specializare,
                'data_semnare' => $semnareDateNormalized,
                'nume_semnatura' => $numeSemnatura,
                'signature_data_url' => $signatureDataUrl,
                'source_url' => trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''))) ?: null,
                'ip_address' => trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?: null,
                'user_agent' => trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) ?: null,
            ]);
        } catch (Throwable) {
            $fail('Nu am putut salva acordul GDPR. Încearcă din nou.');
            return;
        }

        $this->clearGdprAgreementOldInput();
        header('Location: /gdpr-agreements/success?back=' . rawurlencode($redirectTo));
    }

    public function gdprAgreementSuccess(): void
    {
        $backRaw = trim((string) ($_GET['back'] ?? '/acorduri-gdpr'));
        $backUrl = $this->safeRedirectPath($backRaw, '/acorduri-gdpr');
        View::render('site/gdpr-agreement-success', [
            'title' => 'Acord GDPR salvat',
            'backUrl' => $backUrl,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function storeGdprAgreementOldInput(array $input): void
    {
        $allowed = [
            'subiect_nume_complet',
            'ci_serie',
            'ci_numar',
            'ci_emitent',
            'ci_data_eliberare',
            'nume',
            'prenume',
            'cnp',
            'cuim',
            'telefon',
            'email',
            'adresa_corespondenta',
            'institutie_medicala',
            'institutie_activitate',
            'institutie_adresa',
            'institutie_activitate_adresa',
            'tip_medic',
            'specializare',
            'data_semnare',
            'nume_semnatura',
            'signature_data_url',
        ];
        $old = [];
        foreach ($allowed as $field) {
            $value = (string) ($input[$field] ?? '');
            if ($field === 'signature_data_url' && strlen($value) > 2_500_000) {
                $value = '';
            }
            $old[$field] = $value;
        }
        $_SESSION['gdpr_agreement_old_input'] = $old;
    }

    /**
     * @return array<string, string>
     */
    private function consumeGdprAgreementOldInput(): array
    {
        $raw = $_SESSION['gdpr_agreement_old_input'] ?? null;
        unset($_SESSION['gdpr_agreement_old_input']);
        if (!is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach ($raw as $field => $value) {
            if (!is_string($field)) {
                continue;
            }
            $clean[$field] = (string) $value;
        }
        return $clean;
    }

    private function clearGdprAgreementOldInput(): void
    {
        unset($_SESSION['gdpr_agreement_old_input']);
    }

    public function cart(): void
    {
        $db = $this->db();
        $cartState = $this->buildCartRenderState($db);
        $summary = $cartState['summary'];
        $quantityUi = $cartState['quantity_ui'];

        $cartPage = $this->findPublishedPageBySlug('cos');
        if (is_array($cartPage)) {
            $settings = $this->cachedSettings($db);
            View::render('site/custom-page', array_merge([
                'title' => (string) ($cartPage['title'] ?? 'Coș'),
                'page' => $cartPage,
                'mannequinSectionHtml' => $this->renderMannequinSection($db, $settings),
                'cartFormHtml' => $this->renderCartSection($summary, $quantityUi, (bool) ($cartState['is_logged_in'] ?? false)),
            ], $this->customPageSeoMeta($db instanceof PDO ? $db : null, $cartPage)));
            return;
        }

        View::render('site/cart', [
            'title' => 'Coș',
            'summary' => $summary,
            'quantityUi' => $quantityUi,
        ]);
    }

    public function checkout(): void
    {
        if (isset($_GET['stripe_cancelled']) || isset($_GET['euplatesc_cancelled'])) {
            Flash::set('error', 'Plata cu cardul a fost anulată. Poți reîncerca checkout-ul.');
        }
        if (isset($_GET['euplatesc_failed'])) {
            Flash::set('error', 'Plata cu cardul nu a fost aprobată. Poți reîncerca sau alege plata ramburs.');
        }

        $db = $this->db();
        $checkoutState = $this->buildCheckoutRenderState($db);
        $summary = $checkoutState['summary'];
        $values = $checkoutState['values'];
        $fanCounties = $checkoutState['fan_counties'];
        $settings = $this->cachedSettings($db);
        $checkoutPage = $this->findPublishedPageBySlug('checkout');
        if (is_array($checkoutPage)) {
            View::render('site/custom-page', array_merge([
                'title' => (string) ($checkoutPage['title'] ?? 'Checkout'),
                'page' => $checkoutPage,
                'mannequinSectionHtml' => $this->renderMannequinSection($db, $settings),
                'checkoutFormHtml' => $this->renderCheckoutSection($summary, $values, $fanCounties, (bool) ($checkoutState['is_logged_in'] ?? false)),
            ], $this->customPageSeoMeta($db instanceof PDO ? $db : null, $checkoutPage)));
            return;
        }

        View::render('site/checkout', [
            'title' => 'Checkout',
            'summary' => $summary,
            'values' => $values,
            'checkoutHtml' => $this->renderCheckoutSection($summary, $values, $fanCounties, (bool) ($checkoutState['is_logged_in'] ?? false)),
        ]);
    }

    public function checkoutSubmit(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Baza de date nu este configurată. Comanda nu poate fi salvată.');
            header('Location: /checkout');
            return;
        }
        $this->ensureStripeSchema($db);
        $this->ensureCustomerSchema($db);
        $this->ensureCheckoutAntiBotSchema($db);
        $this->ensureProductCustomSchema($db);
        CheckoutCalculator::ensureOrderShippingSchema($db);

        // Datele completate se rețin ÎNAINTE de filtrul anti-bot: dacă filtrul
        // respinge cererea, clientul se întoarce pe /checkout cu formularul
        // completat, nu gol (altfel ar retasta toată adresa la fiecare eroare).
        $this->rememberCheckoutInput();

        // Alegerea FANbox vine din formular; fără punct ales, comanda n-are
        // unde fi livrată, deci o oprim aici, nu la generarea AWB-ului.
        $laFanbox = isset($_POST['livrare_fanbox']);
        $lockerId = $laFanbox ? (int) ($_POST['fan_locker_id'] ?? 0) : 0;
        \App\Support\CheckoutCalculator::alegeFanbox($lockerId, $laFanbox);
        $locker = null;
        if ($lockerId > 0) {
            $locker = \App\Support\FanLockers::dupaId($db, $lockerId);
            if ($locker === null) {
                Flash::set('error', 'Punctul FANbox ales nu mai este disponibil. Alege altul.');
                header('Location: /checkout');
                return;
            }
        } elseif (isset($_POST['livrare_fanbox'])) {
            Flash::set('error', 'Alege punctul FANbox la care vrei livrarea.');
            header('Location: /checkout');
            return;
        }

        $antiBotError = $this->validateCheckoutAntiBot($db);
        if ($antiBotError !== null) {
            Flash::set('error', $antiBotError);
            header('Location: /checkout');
            return;
        }

        $billing = $this->validateCheckout();
        if ($billing === null) {
            header('Location: /checkout');
            return;
        }

        Cart::setCounty($billing['billing_county']);
        CheckoutCalculator::setLocalitate((string) ($billing['billing_city'] ?? ''));
        $settings = Settings::all($db);
        $summary = CheckoutCalculator::buildSummary($db, $settings);
        $requiresShippingCharge = $this->requiresFanLiveShippingCharge($settings, $summary);
        $shippingError = null;
        $liveShippingCost = $this->resolveFanLiveShipping($settings, $summary, $billing, $shippingError);
        if ($liveShippingCost !== null) {
            $summary['shipping'] = $liveShippingCost;
            $summary['total'] = max(
                0,
                (float) $summary['subtotal']
                    - (float) $summary['discount']
                    - (float) ($summary['points_discount'] ?? 0.0)
                    + $liveShippingCost
                    + (float) ($summary['vat_additional'] ?? 0.0)
            );
        } elseif ((string) ($settings['fan_live_tariff_enabled'] ?? '0') === '1' && $requiresShippingCharge) {
            $message = trim((string) $shippingError);
            if ($message === '') {
                $message = 'Nu am putut calcula transportul prin FAN Courier. Verifică localitatea/județul și încearcă din nou.';
            }
            Flash::set('error', $message);
            header('Location: /checkout');
            return;
        }

        if ($summary['lines'] === []) {
            Flash::set('error', 'Coșul este gol.');
            header('Location: /cos');
            return;
        }

        // Ultima verificare de stoc înainte de a scrie comanda: între adăugarea
        // în coș și plasare, altcineva putea cumpăra ultimele bucăți.
        foreach ($summary['lines'] as $linie) {
            $produsStoc = $this->findProductById((int) ($linie['id'] ?? 0));
            // Coșul arată acum și produsele marcate epuizate în fișa de pe site
            // (înainte dispăreau tăcut din listă, deși contorul le număra), deci
            // oprirea lor se face aici, la finalizare, cu mesaj — nu prin
            // ștergerea lor pe nesimțite din coș.
            if (is_array($produsStoc) && (int) ($produsStoc['out_of_stock'] ?? 0) === 1) {
                Flash::set(
                    'error',
                    trim((string) ($linie['name'] ?? 'Un produs')) . ': produsul este epuizat momentan.'
                        . ' Scoate-l din coș ca să poți finaliza comanda.'
                );
                header('Location: /cos');
                return;
            }
            $limitaStoc = is_array($produsStoc) ? $this->limitaStocProdus($produsStoc) : null;
            if ($limitaStoc !== null && (int) ($linie['quantity'] ?? 0) > $limitaStoc) {
                Flash::set(
                    'error',
                    trim((string) ($linie['name'] ?? 'Un produs')) . ': ' . $this->mesajStocInsuficient($limitaStoc)
                        . ' Ajustează cantitatea din coș.'
                );
                header('Location: /cos');
                return;
            }
        }

        $orderNumber = $this->generateOrderNumber($db, $settings);
        // Cardul și OP-ul așteaptă banii înainte de procesare; doar rambursul
        // intră direct în lucru.
        $status = in_array($billing['payment_method'], self::METODE_CARD, true)
            || $billing['payment_method'] === 'bank_transfer'
            ? 'pending_payment'
            : 'pending';
        $now = new DateTimeImmutable('now');
        $customer = CustomerAuth::user($db);
        $customerUserId = is_array($customer) ? (int) ($customer['id'] ?? 0) : 0;
        $pointsApplied = max(0, (int) ($summary['points']['applied'] ?? 0));
        $pointsDiscount = round(max(0.0, (float) ($summary['points_discount'] ?? 0.0)), 2);
        if ($customerUserId <= 0) {
            $pointsApplied = 0;
            $pointsDiscount = 0.0;
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                'INSERT INTO orders (
                    order_number, user_id, status, payment_method, payment_status, shipping_method, shipping_cost,
                    discount_total, loyalty_points_used, loyalty_points_discount, subtotal, total, coupon_code,
                    billing_first_name, billing_last_name, billing_phone, billing_email,
                    billing_address_line1, billing_address_line2, billing_city, billing_county, billing_postcode,
                    billing_is_company, billing_company_name, billing_company_tax_id, billing_company_registration_no,
                    shipping_same_as_billing, shipping_first_name, shipping_last_name, shipping_phone,
                    shipping_address_line1, shipping_address_line2, shipping_city, shipping_county, shipping_postcode,
                    fan_locker_id, fan_locker_name, fan_locker_address, fan_locker_city,
                    fan_locker_county, fan_locker_postcode,
                    notes, terms_accepted_at, created_at
                ) VALUES (
                    :order_number, :user_id, :status, :payment_method, :payment_status, :shipping_method, :shipping_cost,
                    :discount_total, :loyalty_points_used, :loyalty_points_discount, :subtotal, :total, :coupon_code,
                    :billing_first_name, :billing_last_name, :billing_phone, :billing_email,
                    :billing_address_line1, :billing_address_line2, :billing_city, :billing_county, :billing_postcode,
                    :billing_is_company, :billing_company_name, :billing_company_tax_id, :billing_company_registration_no,
                    :shipping_same_as_billing, :shipping_first_name, :shipping_last_name, :shipping_phone,
                    :shipping_address_line1, :shipping_address_line2, :shipping_city, :shipping_county, :shipping_postcode,
                    :fan_locker_id, :fan_locker_name, :fan_locker_address, :fan_locker_city,
                    :fan_locker_county, :fan_locker_postcode,
                    :notes, :terms_accepted_at, :created_at
                )'
            );

            $stmt->execute([
                'order_number' => $orderNumber,
                'user_id' => $customerUserId > 0 ? $customerUserId : null,
                'status' => $status,
                'payment_method' => $billing['payment_method'],
                'payment_status' => 'unpaid',
                'fan_locker_id' => $locker !== null ? $locker['id'] : null,
                'fan_locker_name' => $locker !== null ? $locker['name'] : null,
                'fan_locker_address' => $locker !== null ? $locker['address'] : null,
                'fan_locker_city' => $locker !== null ? $locker['locality'] : null,
                'fan_locker_county' => $locker !== null ? $locker['county'] : null,
                'fan_locker_postcode' => $locker !== null ? ($locker['postcode'] ?? null) : null,
                'shipping_method' => $locker !== null ? 'fan_box' : 'fan_courier',
                'shipping_cost' => $summary['shipping'],
                'discount_total' => $summary['discount'],
                'loyalty_points_used' => $pointsApplied,
                'loyalty_points_discount' => $pointsDiscount,
                'subtotal' => $summary['subtotal'],
                'total' => $summary['total'],
                'coupon_code' => $summary['coupon']['code'] ?? null,
                'billing_first_name' => $billing['billing_first_name'],
                'billing_last_name' => $billing['billing_last_name'],
                'billing_phone' => $billing['billing_phone'],
                'billing_email' => $billing['billing_email'],
                'billing_address_line1' => $billing['billing_address_line1'],
                'billing_address_line2' => $billing['billing_address_line2'],
                'billing_city' => $billing['billing_city'],
                'billing_county' => $billing['billing_county'],
                'billing_postcode' => $billing['billing_postcode'],
                'billing_is_company' => (int) ($billing['billing_is_company'] ?? 0),
                'billing_company_name' => trim((string) ($billing['billing_company_name'] ?? '')) !== '' ? trim((string) ($billing['billing_company_name'] ?? '')) : null,
                'billing_company_tax_id' => trim((string) ($billing['billing_company_tax_id'] ?? '')) !== '' ? trim((string) ($billing['billing_company_tax_id'] ?? '')) : null,
                'billing_company_registration_no' => trim((string) ($billing['billing_company_registration_no'] ?? '')) !== '' ? trim((string) ($billing['billing_company_registration_no'] ?? '')) : null,
                'shipping_same_as_billing' => (int) ($billing['shipping_same_as_billing'] ?? 1),
                'shipping_first_name' => trim((string) ($billing['shipping_first_name'] ?? '')) !== '' ? trim((string) ($billing['shipping_first_name'] ?? '')) : null,
                'shipping_last_name' => trim((string) ($billing['shipping_last_name'] ?? '')) !== '' ? trim((string) ($billing['shipping_last_name'] ?? '')) : null,
                'shipping_phone' => trim((string) ($billing['shipping_phone'] ?? '')) !== '' ? trim((string) ($billing['shipping_phone'] ?? '')) : null,
                'shipping_address_line1' => trim((string) ($billing['shipping_address_line1'] ?? '')) !== '' ? trim((string) ($billing['shipping_address_line1'] ?? '')) : null,
                'shipping_address_line2' => trim((string) ($billing['shipping_address_line2'] ?? '')) !== '' ? trim((string) ($billing['shipping_address_line2'] ?? '')) : null,
                'shipping_city' => trim((string) ($billing['shipping_city'] ?? '')) !== '' ? trim((string) ($billing['shipping_city'] ?? '')) : null,
                'shipping_county' => trim((string) ($billing['shipping_county'] ?? '')) !== '' ? trim((string) ($billing['shipping_county'] ?? '')) : null,
                'shipping_postcode' => trim((string) ($billing['shipping_postcode'] ?? '')) !== '' ? trim((string) ($billing['shipping_postcode'] ?? '')) : null,
                'notes' => $billing['notes'],
                'terms_accepted_at' => $billing['terms_accepted_at'] ?? null,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);

            $orderId = (int) $db->lastInsertId();

            // Numele scris la checkout repară contul rămas „Client Nou": data
            // viitoare clientul se vede cu numele lui, nu cu marcajul generic.
            $this->completeazaNumeleContului(
                $db,
                (string) $billing['billing_first_name'],
                (string) $billing['billing_last_name']
            );

            // Cupon unic (single-use): marchează-l ca folosit → trece în tabul „Cupoane folosite"
            $appliedCouponCode = strtoupper(trim((string) ($summary['coupon']['code'] ?? '')));
            if ($appliedCouponCode !== '') {
                try {
                    $markUsed = $db->prepare(
                        'UPDATE coupons SET used_at = :now
                         WHERE code = :code AND is_unique = 1 AND used_at IS NULL
                         LIMIT 1'
                    );
                    $markUsed->execute(['now' => $now->format('Y-m-d H:i:s'), 'code' => $appliedCouponCode]);
                } catch (\Throwable) {
                }
            }

            // Newsletter revenue attribution
            try {
                $nlCid = (int) ($_COOKIE['nl_cid'] ?? 0);
                if ($nlCid > 0 && $orderId > 0) {
                    $db->prepare('UPDATE orders SET nl_campaign_id=? WHERE id=?')->execute([$nlCid, $orderId]);
                }
            } catch (\Throwable) {
            }

            // Google Ads attribution
            try {
                $adClickId = substr(preg_replace('/[^A-Za-z0-9_\-\.]/', '', trim((string) ($_COOKIE['bv_gclid'] ?? ''))) ?? '', 0, 255);
                if ($adClickId !== '' && $orderId > 0) {
                    $db->prepare('UPDATE orders SET ad_source=?, ad_click_id=? WHERE id=?')
                        ->execute(['google_ads', $adClickId, $orderId]);
                }
            } catch (\Throwable) {
            }

            $itemStmt = $db->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, bbd_key, quantity, unit_price, line_total)
                 VALUES (:order_id, :product_id, :product_name, :bbd_key, :quantity, :unit_price, :line_total)'
            );

            foreach ($summary['lines'] as $line) {
                $productName = (string) ($line['name'] ?? 'Produs');
                $bbdLabel = trim((string) ($line['bbd_label'] ?? ''));
                if ($bbdLabel !== '') {
                    $productName .= ' [Ofertă: ' . $bbdLabel . ']';
                }
                $itemStmt->execute([
                    'order_id' => $orderId,
                    'product_id' => $line['id'],
                    'product_name' => $productName,
                    'bbd_key' => trim((string) ($line['bbd_key'] ?? '')) !== '' ? trim((string) ($line['bbd_key'] ?? '')) : null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['price'],
                    'line_total' => $line['line_total'],
                ]);
            }
            if ($customerUserId > 0 && $pointsApplied > 0) {
                $consumed = LoyaltyService::consumePointsForOrder(
                    $db,
                    $customerUserId,
                    $orderId,
                    $pointsApplied,
                    $pointsDiscount
                );
                if (!$consumed) {
                    throw new RuntimeException('Nu am putut aplica punctele folosite pentru această comandă.');
                }
            }

            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Flash::set('error', 'A apărut o eroare la salvarea comenzii. Reîncearcă.');
            header('Location: /checkout');
            return;
        }

        if ($billing['payment_method'] === 'euplatesc') {
            try {
                $fields = $this->createEuPlatescRequest($db, $orderId, $orderNumber, $summary, $billing);
                // Coșul se golește abia la confirmarea plății (pe silent URL),
                // ca un client care renunță să-și găsească produsele la loc.
                unset($_SESSION['checkout_form']);
                View::render('site/euplatesc-redirect', [
                    'title' => 'Se deschide plata securizată',
                    'gatewayUrl' => EuPlatescGateway::GATEWAY_URL,
                    'fields' => $fields,
                    'orderNumber' => $orderNumber,
                ], 'layout-blank');
                return;
            } catch (RuntimeException $exception) {
                $this->markOrderPaymentFailed($db, $orderId, $exception->getMessage());
                Flash::set('error', 'Nu am putut inițializa plata cu cardul. ' . $exception->getMessage());
                header('Location: /checkout');
                return;
            }
        }

        if ($billing['payment_method'] === 'stripe') {
            try {
                $session = $this->createStripeCheckoutSession($db, $orderId, $orderNumber, $summary, $billing);
                header('Location: ' . (string) $session['url']);
                return;
            } catch (RuntimeException $exception) {
                $this->markOrderPaymentFailed($db, $orderId, $exception->getMessage());
                Flash::set('error', 'Nu am putut inițializa plata cu cardul. ' . $exception->getMessage());
                header('Location: /checkout');
                return;
            }
        }

        EmailAutomation::sendOrderTemplateById($db, $settings, $orderId, 'new_order');
        // Comanda pleacă spre ERP; dacă ERP-ul nu răspunde, rămâne marcată
        // pentru reîncercare și clientul nu vede nicio eroare.
        \App\Support\ErpSync::push($db, $orderId);
        EmailAutomation::markCartConverted($db, session_id());
        Cart::clear();
        unset($_SESSION['checkout_form']);

        header('Location: /checkout/succes/' . urlencode($orderNumber));
    }

    public function checkoutSuccess(array $params): void
    {
        $orderNumber = (string) ($params['orderNumber'] ?? '');
        $db = $this->db();
        $stripeReturn = isset($_GET['stripe']) && (string) $_GET['stripe'] === '1';
        // EuPlătesc trimite clientul înapoi prin POST, cu rezultatul semnat.
        // Confirmarea „oficială" vine pe silent URL; aici doar ne asigurăm că
        // pagina arată starea corectă chiar dacă notificarea a întârziat.
        $euplatescReturn = isset($_GET['euplatesc']) && (string) $_GET['euplatesc'] === '1';
        if ($db instanceof PDO && $euplatescReturn && $_POST !== []) {
            try {
                $this->applyEuPlatescResult($db, $_POST);
            } catch (Throwable) {
                // Silent URL-ul rămâne sursa de adevăr; pagina se afișează oricum.
            }
        }
        if ($db instanceof PDO && $stripeReturn) {
            try {
                $this->syncStripeSessionFromReturn($db, $orderNumber, (string) ($_GET['session_id'] ?? ''));
            } catch (Throwable) {
                // Keep checkout success page accessible even if Stripe sync fails.
            }
        }
        // Coșul se marchează convertit la ORICE întoarcere de pe pagina de
        // plată, nu doar la Stripe. Altfel, clienții care plătesc prin
        // EuPlătesc — procesatorul implicit — rămâneau cu coșul „abandonat" și
        // primeau ulterior emailul „ați uitat produse în coș", deși plătiseră.
        if ($db instanceof PDO && ($stripeReturn || $euplatescReturn)) {
            EmailAutomation::markCartConverted($db, session_id());
        }

        $paymentStatus = null;
        $orderStatus = null;
        $orderTotal = null;
        $orderEmail = '';
        $orderCurrency = strtoupper(trim((string) (($db instanceof PDO ? $this->cachedSettings($db) : [])['stripe_currency'] ?? 'ron')));
        if ($orderCurrency === '') {
            $orderCurrency = 'RON';
        }
        if ($db instanceof PDO && $orderNumber !== '') {
            $stmt = $db->prepare('SELECT id, payment_status, status, total, billing_email FROM orders WHERE order_number = :order_number AND deleted_at IS NULL LIMIT 1');
            $stmt->execute(['order_number' => $orderNumber]);
            $order = $stmt->fetch() ?: null;
            if (is_array($order)) {
                $paymentStatus = (string) ($order['payment_status'] ?? '');
                $orderStatus = (string) ($order['status'] ?? '');
                $orderTotal = round((float) ($order['total'] ?? 0), 2);
                $orderEmail = (string) ($order['billing_email'] ?? '');
                // Flag a one-time Google Ads conversion for the site layout to fire and clear.
                $_SESSION['google_ads_conversion'] = [
                    'order' => $orderNumber,
                    'value' => $orderTotal,
                ];

                // Build a GA4 ecommerce "purchase" event (captured by GTM) — fired once.
                $items = [];
                try {
                    $itemsStmt = $db->prepare(
                        'SELECT oi.product_name, oi.quantity, oi.unit_price, p.sku
                         FROM order_items oi
                         LEFT JOIN products p ON p.id = oi.product_id
                         WHERE oi.order_id = :order_id'
                    );
                    $itemsStmt->execute(['order_id' => (int) $order['id']]);
                    foreach (($itemsStmt->fetchAll() ?: []) as $row) {
                        $items[] = [
                            'item_id' => (string) ($row['sku'] ?? '') !== '' ? (string) $row['sku'] : (string) ($row['product_name'] ?? ''),
                            'item_name' => (string) ($row['product_name'] ?? ''),
                            'price' => round((float) ($row['unit_price'] ?? 0), 2),
                            'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                        ];
                    }
                } catch (Throwable) {
                    $items = [];
                }

                $_SESSION['ga4_purchase'] = [
                    'transaction_id' => $orderNumber,
                    'value' => $orderTotal,
                    'currency' => $orderCurrency,
                    'items' => $items,
                ];
            }
        }

        if ($stripeReturn || $euplatescReturn) {
            Cart::clear();
            unset($_SESSION['checkout_form']);
        }

        if ($db instanceof PDO) {
            $successPage = $this->findPublishedPageBySlug('checkout/succes');
            if (is_array($successPage)) {
                $settings = $this->cachedSettings($db);
                $pageHtmlContent = (string) ($successPage['html_content'] ?? '');
                $checkoutSuccessOrderInfoHtml = '';
                if (str_contains($pageHtmlContent, self::CHECKOUT_SUCCESS_ORDER_INFO_TOKEN)) {
                    $checkoutSuccessOrderInfoHtml = $this->renderCheckoutSuccessOrderInfoSection(
                        $orderNumber,
                        $orderTotal,
                        $orderCurrency,
                        $orderEmail
                    );
                }

                View::render('site/custom-page', array_merge([
                    'title' => (string) ($successPage['title'] ?? 'Comandă plasată'),
                    'page' => $successPage,
                    'mannequinSectionHtml' => $this->renderMannequinSection($db, $settings),
                    'checkoutSuccessOrderInfoHtml' => $checkoutSuccessOrderInfoHtml,
                ], $this->customPageSeoMeta($db, $successPage)));
                return;
            }
        }

        // Comanda plătită prin OP: clientul are nevoie de datele de plată acum,
        // pe ecran — emailul poate ajunge în spam, iar banii nu pleacă singuri.
        $opInstructiuni = '';
        if ($db instanceof PDO && $orderNumber !== '') {
            $stmtOp = $db->prepare('SELECT payment_method FROM orders WHERE order_number = :nr AND deleted_at IS NULL LIMIT 1');
            $stmtOp->execute(['nr' => $orderNumber]);
            $metodaOp = strtolower((string) ($stmtOp->fetchColumn() ?: ''));
            if ($metodaOp === 'bank_transfer') {
                $opInstructiuni = trim((string) ($this->cachedSettings($db)['bank_transfer_instructiuni'] ?? ''));
            }
        }

        View::render('site/checkout-success', [
            'title' => 'Comandă plasată',
            'opInstructiuni' => $opInstructiuni,
            'orderNumber' => $orderNumber,
            'paymentStatus' => $paymentStatus,
            'orderStatus' => $orderStatus,
            'orderTotal' => $orderTotal,
            'orderCurrency' => $orderCurrency,
            'orderEmail' => $orderEmail,
            'stripeReturn' => $stripeReturn,
            'euplatescReturn' => $euplatescReturn,
        ]);
    }

    public function stripeWebhook(): void
    {
        $payload = file_get_contents('php://input');
        $payload = $payload === false ? '' : $payload;
        $signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(500);
            echo 'db_unavailable';
            return;
        }
        $this->ensureStripeSchema($db);

        $settings = Settings::all($db);
        $webhookSecret = trim((string) ($settings['stripe_webhook_secret'] ?? ''));
        if ($webhookSecret === '') {
            http_response_code(400);
            echo 'webhook_not_configured';
            return;
        }

        if (!StripeGateway::verifyWebhookSignature($payload, $signature, $webhookSecret)) {
            http_response_code(400);
            echo 'invalid_signature';
            return;
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            http_response_code(400);
            echo 'invalid_payload';
            return;
        }

        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? null;

        if (!is_array($object)) {
            http_response_code(200);
            echo 'ok';
            return;
        }

        if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
            $isPaid = ((string) ($object['payment_status'] ?? '') === 'paid') || $type === 'checkout.session.async_payment_succeeded';
            $this->applyStripeSessionResult($db, $object, $isPaid);
        } elseif ($type === 'checkout.session.expired' || $type === 'checkout.session.async_payment_failed') {
            $this->applyStripeSessionResult($db, $object, false);
        } elseif ($type === 'payment_intent.succeeded') {
            $this->applyStripePaymentIntentResult($db, $object, true);
        } elseif ($type === 'payment_intent.payment_failed') {
            $this->applyStripePaymentIntentResult($db, $object, false);
        }

        http_response_code(200);
        echo 'ok';
    }

    public function cartHeartbeat(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(204);
            return;
        }

        $settings = Settings::all($db);
        $summary = CheckoutCalculator::buildSummary($db, $settings);
        if (($summary['lines'] ?? []) === []) {
            EmailAutomation::markCartConverted($db, session_id());
            http_response_code(204);
            return;
        }

        $payload = $_POST;
        if ($payload === []) {
            $raw = file_get_contents('php://input');
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $checkout = $_SESSION['checkout_form'] ?? [];
        $email = trim((string) ($payload['email'] ?? $checkout['billing_email'] ?? ''));
        $customerName = trim((string) ($payload['customer_name'] ?? ((string) ($checkout['billing_first_name'] ?? '') . ' ' . (string) ($checkout['billing_last_name'] ?? ''))));

        $parts = [];
        foreach ((array) ($summary['lines'] ?? []) as $line) {
            $name = trim((string) ($line['name'] ?? 'Produs'));
            $qty = (int) ($line['quantity'] ?? 1);
            $lineTotal = number_format((float) ($line['line_total'] ?? 0), 2);
            $parts[] = $name . ' x' . $qty . ' - ' . $lineTotal . ' RON';
        }
        $cartSnapshot = implode("\n", $parts);

        EmailAutomation::touchCartAbandonment($db, session_id(), [
            'email' => $email,
            'customer_name' => $customerName,
            'cart_snapshot' => $cartSnapshot,
        ]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    public function cartSummaryApi(): void
    {
        $db = $this->db();
        $payload = $this->buildFloatingCartPayload($db);
        $this->jsonResponse($payload);
    }

    public function bestSellersApi(): void
    {
        $limit = (int) ($_GET['limit'] ?? 4);
        $limit = max(1, min(24, $limit));
        $items = $this->loadBestSellersFromCompletedOrders($limit);
        $this->jsonResponse([
            'ok' => true,
            'items' => $items,
            'count' => count($items),
        ]);
    }

    public function shopCatalogApi(): void
    {
        $categoryFilter = $this->requestCategoryFilter();
        $sort = $this->requestShopCatalogSort();
        [$products] = $this->loadProducts();
        $products = $this->enrichShopCatalogProducts($this->db(), $products);
        $categories = $this->loadShopCatalogCategories($this->db());
        if ($categories === []) {
            $categories = $this->buildShopCatalogCategoriesFromProducts($products);
        }
        $categoryFilter = $this->normalizeShopCategoryFilter($categoryFilter, $categories);
        if ($categoryFilter !== '') {
            $products = array_values(array_filter(
                $products,
                static fn (array $product): bool => \App\Support\ProductCategories::matchesName($product, $categoryFilter)
            ));
        }
        $products = $this->applyShopCatalogSort($products, $sort);
        $items = array_map(static function (array $product): array {
            $slug = trim((string) ($product['slug'] ?? ''));
            return [
                'id' => (int) ($product['id'] ?? 0),
                'name' => (string) ($product['name'] ?? 'Produs'),
                'slug' => $slug,
                'url' => $slug !== '' ? ('/produs/' . rawurlencode($slug)) : '/magazin',
                'category' => trim((string) ($product['category'] ?? '')),
                'short_description' => trim((string) ($product['short_description'] ?? '')),
                'image_url' => trim((string) ($product['image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg',
                'price' => (float) ($product['price'] ?? 0.0),
                'base_price' => (float) ($product['base_price'] ?? $product['price'] ?? 0.0),
                'has_sale_price' => (bool) ($product['has_sale_price'] ?? false),
                'discount_badge_mode' => (string) ($product['discount_badge_mode'] ?? 'percent') === 'value' ? 'value' : 'percent',
                'discount_value_label' => ((bool) ($product['has_sale_price'] ?? false) && (float) ($product['base_price'] ?? 0) > (float) ($product['price'] ?? 0))
                    ? rtrim(rtrim(number_format((float) ($product['base_price'] ?? 0) - (float) ($product['price'] ?? 0), 2, '.', ''), '0'), '.')
                    : '',
                'reviews_count' => max(0, (int) ($product['reviews_count'] ?? 0)),
                'reviews_average' => max(0.0, min(5.0, (float) ($product['reviews_average'] ?? 0.0))),
                'sold_qty' => max(0, (int) ($product['sold_qty'] ?? 0)),
            ];
        }, $products);

        $this->jsonResponse([
            'ok' => true,
            'items' => array_values($items),
            'categories' => array_values($categories),
            'activeCategory' => $categoryFilter,
            'sort' => $sort,
            'sortOptions' => $this->shopCatalogSortOptions(),
            'count' => count($items),
        ]);
    }

    public function productSearchApi(): void
    {
        $queryRaw = trim((string) ($_GET['q'] ?? ''));
        $query = preg_replace('/\s+/', ' ', $queryRaw);
        $query = is_string($query) ? trim($query) : '';
        if (mb_strlen($query) < 2) {
            $this->jsonResponse([
                'ok' => true,
                'query' => $query,
                'count' => 0,
                'items' => [],
            ]);
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->jsonResponse([
                'ok' => true,
                'query' => $query,
                'count' => 0,
                'items' => [],
            ]);
            return;
        }

        $this->ensureProductCustomSchema($db);
        $queryLower = mb_strtolower($query);
        $like = '%' . $queryLower . '%';
        $prefix = $queryLower . '%';
        $items = [];

        try {
            $stmt = $db->prepare(
                'SELECT id, name, slug, short_description, description, image_url
                 FROM products
                 WHERE deleted_at IS NULL
                   AND is_active = 1
                   AND (
                        LOWER(COALESCE(name, "")) LIKE :like
                        OR LOWER(COALESCE(short_description, "")) LIKE :like
                        OR LOWER(COALESCE(description, "")) LIKE :like
                   )
                 ORDER BY
                    CASE WHEN LOWER(COALESCE(name, "")) LIKE :prefix THEN 0 ELSE 1 END,
                    CASE WHEN LOWER(COALESCE(name, "")) LIKE :like THEN 0 ELSE 1 END,
                    id DESC
                 LIMIT 12'
            );
            $stmt->execute([
                'like' => $like,
                'prefix' => $prefix,
            ]);
            $rows = $stmt->fetchAll() ?: [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $slug = trim((string) ($row['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $items[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => trim((string) ($row['name'] ?? 'Produs')),
                    'slug' => $slug,
                    'url' => '/produs/' . rawurlencode($slug),
                    'short_description' => trim((string) ($row['short_description'] ?? '')),
                    'description' => trim(strip_tags((string) ($row['description'] ?? ''))),
                    'image_url' => trim((string) ($row['image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg',
                ];
            }
        } catch (Throwable) {
            $items = [];
        }

        $this->jsonResponse([
            'ok' => true,
            'query' => $query,
            'count' => count($items),
            'items' => array_values($items),
        ]);
    }

    public function cartItemAddApi(array $params): void
    {
        $productId = (int) ($params['id'] ?? 0);
        $payload = $this->requestPayload();
        $quantity = max(1, (int) ($payload['quantity'] ?? $_POST['quantity'] ?? 1));
        $requestedBbdKey = strtolower(trim((string) ($payload['bbd_key'] ?? $_POST['bbd_key'] ?? '')));

        $product = $this->findProductById($productId);
        if ($product === null) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Produsul nu a fost găsit.',
            ], 404);
            return;
        }
        if ((int) ($product['out_of_stock'] ?? 0) === 1) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Produsul este epuizat momentan.',
            ], 422);
            return;
        }
        $selectedBbd = $this->resolveRequestedBbdSelection($product, $requestedBbdKey);
        if ($this->productRequiresBbdSelection($product) && $selectedBbd === []) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Alege oferta dorită înainte de a adăuga produsul în coș.',
            ], 422);
            return;
        }
        $selectedBbdKey = (string) ($selectedBbd['key'] ?? '');
        $existingCartQuantity = $selectedBbdKey !== ''
            ? $this->cartQuantityForProductBbd($productId, $selectedBbdKey)
            : 0;
        if ($selectedBbd !== [] && !$this->bbdSelectionHasAvailableStock($selectedBbd, $quantity + $existingCartQuantity)) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Oferta selectată nu mai are stoc disponibil.',
            ], 422);
            return;
        }

        $limitaStoc = $this->limitaStocProdus($product);
        if ($limitaStoc !== null && $this->cantitateInCos($productId) + $quantity > $limitaStoc) {
            $this->jsonResponse([
                'ok' => false,
                'message' => $this->mesajStocInsuficient($limitaStoc),
            ], 422);
            return;
        }

        Cart::add($productId, $quantity, (string) ($selectedBbd['key'] ?? ''));
        $db = $this->db();
        $response = $this->buildFloatingCartPayload($db);
        $response['message'] = 'Produs adăugat în coș.';
        $this->jsonResponse($response);
    }

    public function cartItemSetApi(array $params): void
    {
        $itemKey = trim((string) ($params['id'] ?? ''));
        $parsedItem = Cart::parseItemKey($itemKey);
        $productId = (int) ($parsedItem['product_id'] ?? 0);
        $bbdKey = $this->normalizeProductBbdKey((string) ($parsedItem['bbd_key'] ?? ''));
        $payload = $this->requestPayload();
        $quantity = (int) ($payload['quantity'] ?? $_POST['quantity'] ?? 1);
        $product = $this->findProductById($productId);
        if ($productId <= 0 || !is_array($product)) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Produsul nu a fost găsit.',
            ], 404);
            return;
        }
        if ($quantity > 0 && $bbdKey !== '') {
            $selectedBbd = $this->resolveRequestedBbdSelection($product, $bbdKey);
            if ($selectedBbd === [] || !$this->bbdSelectionHasAvailableStock($selectedBbd, $quantity)) {
                $this->jsonResponse([
                    'ok' => false,
                    'message' => 'Oferta selectată nu mai are stoc disponibil.',
                ], 422);
                return;
            }
        }

        if ($quantity > 0) {
            $limitaStoc = $this->limitaStocProdus($product);
            $inAlteVariante = $this->cantitateInCos($productId)
                - max(0, (int) (Cart::items()[$itemKey] ?? 0));
            if ($limitaStoc !== null && $inAlteVariante + $quantity > $limitaStoc) {
                $this->jsonResponse([
                    'ok' => false,
                    'message' => $this->mesajStocInsuficient($limitaStoc),
                ], 422);
                return;
            }
        }

        Cart::update($itemKey, $quantity);
        $db = $this->db();
        $response = $this->buildFloatingCartPayload($db);
        $response['message'] = $quantity <= 0 ? 'Produs eliminat din coș.' : 'Coș actualizat.';
        $this->jsonResponse($response);
    }

    public function cartItemRemoveApi(array $params): void
    {
        $itemKey = trim((string) ($params['id'] ?? ''));
        $parsedItem = Cart::parseItemKey($itemKey);
        if ((int) ($parsedItem['product_id'] ?? 0) <= 0) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Produsul nu a fost găsit.',
            ], 404);
            return;
        }
        Cart::remove($itemKey);
        $db = $this->db();
        $response = $this->buildFloatingCartPayload($db);
        $response['message'] = 'Produs eliminat din coș.';
        $this->jsonResponse($response);
    }

    public function cartApplyCouponApi(): void
    {
        $payload = $this->requestPayload();
        $code = trim((string) ($payload['coupon_code'] ?? $_POST['coupon_code'] ?? ''));
        if ($code === '') {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Introdu un cod de cupon.',
            ], 422);
            return;
        }

        Cart::applyCoupon($code);
        $db = $this->db();
        $settings = Settings::all($db);
        $summary = CheckoutCalculator::buildSummary($db, $settings);
        if (($summary['coupon_error'] ?? null) !== null) {
            Cart::clearCoupon();
            $this->jsonResponse([
                'ok' => false,
                'message' => (string) ($summary['coupon_error'] ?? 'Cupon invalid.'),
            ], 422);
            return;
        }

        $response = $this->buildFloatingCartPayload($db);
        $response['message'] = 'Cupon aplicat.';
        $this->jsonResponse($response);
    }

    public function cartClearCouponApi(): void
    {
        Cart::clearCoupon();
        $db = $this->db();
        $response = $this->buildFloatingCartPayload($db);
        $response['message'] = 'Cupon eliminat.';
        $this->jsonResponse($response);
    }

    public function cartAdd(array $params): void
    {
        $productId = (int) ($params['id'] ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        $requestedBbdKey = strtolower(trim((string) ($_POST['bbd_key'] ?? '')));

        $product = $this->findProductById($productId);
        if ($product === null) {
            Flash::set('error', 'Produsul nu a fost găsit.');
            header('Location: /magazin');
            return;
        }
        if ((int) ($product['out_of_stock'] ?? 0) === 1) {
            Flash::set('error', 'Produsul este epuizat momentan.');
            header('Location: /produs/' . rawurlencode((string) ($product['slug'] ?? '')));
            return;
        }
        $selectedBbd = $this->resolveRequestedBbdSelection($product, $requestedBbdKey);
        if ($this->productRequiresBbdSelection($product) && $selectedBbd === []) {
            Flash::set('error', 'Alege oferta dorită înainte de a adăuga produsul în coș.');
            header('Location: /produs/' . rawurlencode((string) ($product['slug'] ?? '')));
            return;
        }
        $selectedBbdKey = (string) ($selectedBbd['key'] ?? '');
        $existingCartQuantity = $selectedBbdKey !== ''
            ? $this->cartQuantityForProductBbd($productId, $selectedBbdKey)
            : 0;
        if ($selectedBbd !== [] && !$this->bbdSelectionHasAvailableStock($selectedBbd, $quantity + $existingCartQuantity)) {
            Flash::set('error', 'Oferta selectată nu mai are stoc disponibil.');
            header('Location: /produs/' . rawurlencode((string) ($product['slug'] ?? '')));
            return;
        }
        $limitaStoc = $this->limitaStocProdus($product);
        if ($limitaStoc !== null && $this->cantitateInCos($productId) + $quantity > $limitaStoc) {
            Flash::set('error', $this->mesajStocInsuficient($limitaStoc));
            header('Location: /produs/' . rawurlencode((string) ($product['slug'] ?? '')));
            return;
        }

        Cart::add($productId, $quantity, (string) ($selectedBbd['key'] ?? ''));
        Flash::set('success', 'Produs adăugat în coș.');
        header('Location: /cos');
    }

    public function cartUpdate(): void
    {
        $quantities = $_POST['quantities'] ?? [];
        if (!is_array($quantities)) {
            header('Location: /cos');
            return;
        }

        foreach ($quantities as $itemKey => $quantity) {
            $safeItemKey = (string) $itemKey;
            $safeQuantity = (int) $quantity;
            if ($safeQuantity > 0) {
                $parsedItem = Cart::parseItemKey($safeItemKey);
                $productId = (int) ($parsedItem['product_id'] ?? 0);
                $bbdKey = $this->normalizeProductBbdKey((string) ($parsedItem['bbd_key'] ?? ''));
                if ($productId > 0 && $bbdKey !== '') {
                    $product = $this->findProductById($productId);
                    $selectedBbd = is_array($product) ? $this->resolveRequestedBbdSelection($product, $bbdKey) : [];
                    if ($selectedBbd === [] || !$this->bbdSelectionHasAvailableStock($selectedBbd, $safeQuantity)) {
                        Flash::set('error', 'Una dintre ofertele selectate nu mai are stoc disponibil.');
                        header('Location: /cos');
                        return;
                    }
                }
                if ($productId > 0) {
                    $produsStoc = $this->findProductById($productId);
                    $limitaStoc = is_array($produsStoc) ? $this->limitaStocProdus($produsStoc) : null;
                    $inAlteVariante = $this->cantitateInCos($productId)
                        - max(0, (int) (Cart::items()[$safeItemKey] ?? 0));
                    if ($limitaStoc !== null && $inAlteVariante + $safeQuantity > $limitaStoc) {
                        Flash::set(
                            'error',
                            trim((string) ($produsStoc['name'] ?? 'Un produs')) . ': ' . $this->mesajStocInsuficient($limitaStoc)
                        );
                        header('Location: /cos');
                        return;
                    }
                }
            }
            Cart::update($safeItemKey, $safeQuantity);
        }

        header('Location: /cos');
    }

    public function cartRemove(array $params): void
    {
        Cart::remove((string) ($params['id'] ?? ''));
        Flash::set('success', 'Produs eliminat din coș.');
        header('Location: /cos');
    }

    public function cartApplyCoupon(): void
    {
        $code = trim($_POST['coupon_code'] ?? '');
        $redirectTo = trim((string) ($_POST['redirect_to'] ?? '/cos'));
        if (!in_array($redirectTo, ['/cos', '/checkout'], true)) {
            $redirectTo = '/cos';
        }
        if ($code === '') {
            Flash::set('error', 'Introdu un cod de cupon.');
            header('Location: ' . $redirectTo);
            return;
        }

        Cart::applyCoupon($code);
        $db = $this->db();
        $settings = Settings::all($db);
        $summary = CheckoutCalculator::buildSummary($db, $settings);

        if ($summary['coupon_error'] !== null) {
            Cart::clearCoupon();
            Flash::set('error', $summary['coupon_error']);
            header('Location: ' . $redirectTo);
            return;
        }

        Flash::set('success', 'Cupon aplicat.');
        header('Location: ' . $redirectTo);
    }

    public function cartApplyPoints(): void
    {
        $redirectTo = trim((string) ($_POST['redirect_to'] ?? '/cos'));
        if (!in_array($redirectTo, ['/cos', '/checkout'], true)) {
            $redirectTo = '/cos';
        }
        $points = max(0, (int) ($_POST['points'] ?? 0));
        if ($points <= 0) {
            Flash::set('error', 'Introdu numărul de puncte pe care vrei să le folosești.');
            header('Location: ' . $redirectTo);
            return;
        }

        Cart::requestPoints($points);
        $db = $this->db();
        $settings = Settings::all($db);
        $summary = CheckoutCalculator::buildSummary($db, $settings);
        $pointsSummary = is_array($summary['points'] ?? null) ? $summary['points'] : [];
        $error = trim((string) ($pointsSummary['error'] ?? ''));
        if ($error !== '') {
            Cart::clearPointsRequest();
            Flash::set('error', $error);
            header('Location: ' . $redirectTo);
            return;
        }

        $applied = max(0, (int) ($pointsSummary['applied'] ?? 0));
        $discount = max(0.0, (float) ($summary['points_discount'] ?? 0.0));
        Flash::set('success', 'Puncte aplicate: ' . $applied . ' (reducere ' . number_format($discount, 2) . ' lei).');
        header('Location: ' . $redirectTo);
    }

    public function cartClearCoupon(): void
    {
        Cart::clearCoupon();
        Flash::set('success', 'Cupon eliminat.');
        header('Location: /cos');
    }

    public function cartClearPoints(): void
    {
        $redirectTo = trim((string) ($_POST['redirect_to'] ?? '/cos'));
        if (!in_array($redirectTo, ['/cos', '/checkout'], true)) {
            $redirectTo = '/cos';
        }
        Cart::clearPointsRequest();
        Flash::set('success', 'Punctele aplicate au fost eliminate.');
        header('Location: ' . $redirectTo);
    }

    public function cartSetCounty(): void
    {
        Cart::setCounty((string) ($_POST['county'] ?? 'Bucuresti'));
        header('Location: /cos');
    }

    public function account(): void
    {
        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $db = $this->db();
        $user = null;
        $settings = [];
        $orders = [];
        $addresses = [];
        $latestBillingOrder = null;
        $loyaltyTransactions = [];
        $registrationFields = [
            'first_name' => true,
            'last_name' => true,
            'email' => true,
            'phone' => true,
            'birth_date' => false,
            'gender' => false,
            'password' => true,
            'password_confirm' => true,
        ];
        $socialAuthConfig = [
            'google_enabled' => false,
            'google_auth_url' => '/auth/google',
        ];
        if ($db instanceof PDO) {
            $this->ensureCustomerSchema($db);
            $settings = Settings::all($db);
            $registrationFields = $this->customerRegistrationFields($settings);
            $user = CustomerAuth::user($db);
            if (is_array($user)) {
                $stmt = $db->prepare(
                    'SELECT id, order_number, status, payment_status, total, created_at,
                            billing_first_name, billing_last_name, billing_phone, billing_email,
                            billing_address_line1, billing_address_line2, billing_city, billing_county, billing_postcode
                     FROM orders
                     WHERE deleted_at IS NULL
                       AND (user_id = :user_id OR (user_id IS NULL AND billing_email = :email))
                     ORDER BY id DESC
                     LIMIT 200'
                );
                $stmt->execute([
                    'user_id' => (int) ($user['id'] ?? 0),
                    'email' => (string) ($user['email'] ?? ''),
                ]);
                $orders = $stmt->fetchAll();
                if (!empty($orders) && is_array($orders[0])) {
                    $latestBillingOrder = $orders[0];
                }

                $addresses = $this->loadCustomerAddresses($db, (int) ($user['id'] ?? 0));
                $loyaltyTransactions = LoyaltyService::userTransactions($db, (int) ($user['id'] ?? 0), 120);
            }
        }
        if ($requestPath === '/register') {
            $activeView = 'register';
        } elseif ($requestPath === '/login') {
            $activeView = 'login';
        } else {
            $activeView = trim((string) ($_GET['view'] ?? 'login'));
            if (!in_array($activeView, ['login', 'register'], true)) {
                $activeView = 'login';
            }
        }
        $nextQuery = trim((string) ($_GET['next'] ?? ''));
        if (!is_array($user) && $requestPath === '/contul-meu') {
            $redirectTo = $activeView === 'register' ? '/register' : '/login';
            $safeNext = $this->safeRedirectPath($nextQuery, '/contul-meu');
            if ($nextQuery !== '') {
                $redirectTo .= '?' . http_build_query(['next' => $safeNext]);
            }
            header('Location: ' . $redirectTo);
            return;
        }
        if (is_array($user) && in_array($requestPath, ['/login', '/register'], true)) {
            header('Location: /contul-meu');
            return;
        }
        $accountSection = trim((string) ($_GET['section'] ?? 'profile'));
        if (!in_array($accountSection, ['profile', 'orders', 'addresses', 'points', 'settings'], true)) {
            $accountSection = 'profile';
        }
        $next = $this->safeRedirectPath($nextQuery, '/contul-meu');
        $socialAuthConfig = $this->googleAuthViewConfig($settings, $next);
        $totalSpent = 0.0;
        foreach ($orders as $order) {
            $totalSpent += (float) ($order['total'] ?? 0);
        }

        if ($requestPath === '/contul-meu' && $db instanceof PDO) {
            $accountPage = $this->findPublishedPageBySlug('contul-meu');
            if (is_array($accountPage)) {
                $accountState = $this->buildAccountSectionState($db);
                View::render('site/custom-page', array_merge([
                    'title' => (string) ($accountPage['title'] ?? 'Contul meu'),
                    'page' => $accountPage,
                    'mannequinSectionHtml' => $this->renderMannequinSection($db, $settings),
                    'accountSectionHtml' => $this->renderAccountSection($accountState),
                ], $this->customPageSeoMeta($db, $accountPage)));
                return;
            }
        }

        View::render('site/account', [
            'title' => 'Contul meu',
            'customer' => $user,
            'orders' => $orders,
            'addresses' => $addresses,
            'latestBillingOrder' => $latestBillingOrder,
            'activeView' => $activeView,
            'accountSection' => $accountSection,
            'ordersCount' => count($orders),
            'ordersTotal' => $totalSpent,
            'registrationFields' => $registrationFields,
            'socialAuthConfig' => $socialAuthConfig,
            'loyaltyTransactions' => $loyaltyTransactions,
            'loyaltyConfig' => LoyaltyService::config($settings),
            'dbReady' => $db instanceof PDO,
            'next' => $next,
            'registerAntiBot' => $this->issueRegisterAntiBotPayload(),
        ]);
    }

    public function loginPage(): void
    {
        $db = $this->db();
        $settings = [];
        if ($db instanceof PDO) {
            $this->ensureCustomerSchema($db);
            $settings = Settings::all($db);
            $user = CustomerAuth::user($db);
            if (is_array($user)) {
                header('Location: /contul-meu');
                return;
            }
        }

        $runtimeSettings = $this->cachedSettings($db);
        $page = $this->findPublishedPageBySlug('login');
        if (is_array($page)) {
            $next = $this->safeRedirectPath((string) ($_GET['next'] ?? ''), '/contul-meu');
            View::render('site/custom-page', array_merge([
                'title' => (string) ($page['title'] ?? 'Login'),
                'page' => $page,
                'socialAuthConfig' => $this->googleAuthViewConfig($runtimeSettings, $next),
                'registerAntiBot' => $this->issueRegisterAntiBotPayload(),
                'mannequinSectionHtml' => $this->renderMannequinSection($db, $runtimeSettings),
            ], $this->customPageSeoMeta($db instanceof PDO ? $db : null, $page)));
            return;
        }

        $this->account();
    }

    public function registerPage(): void
    {
        $db = $this->db();
        $settings = [];
        if ($db instanceof PDO) {
            $this->ensureCustomerSchema($db);
            $settings = Settings::all($db);
            $user = CustomerAuth::user($db);
            if (is_array($user)) {
                header('Location: /contul-meu');
                return;
            }
        }

        $runtimeSettings = $this->cachedSettings($db);
        $page = $this->findPublishedPageBySlug('register');
        if (is_array($page)) {
            $next = $this->safeRedirectPath((string) ($_GET['next'] ?? ''), '/contul-meu');
            $registrationFields = $this->customerRegistrationFields($settings);
            View::render('site/custom-page', array_merge([
                'title' => (string) ($page['title'] ?? 'Register'),
                'page' => $page,
                'registrationFieldVisibility' => $registrationFields,
                'socialAuthConfig' => $this->googleAuthViewConfig($runtimeSettings, $next),
                'registerAntiBot' => $this->issueRegisterAntiBotPayload(),
                'mannequinSectionHtml' => $this->renderMannequinSection($db, $runtimeSettings),
            ], $this->customPageSeoMeta($db instanceof PDO ? $db : null, $page)));
            return;
        }

        $this->account();
    }

    public function googleAuthStart(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea la baza de date nu este disponibilă.');
            header('Location: /login');
            return;
        }
        $this->ensureCustomerSchema($db);

        $settings = Settings::all($db);
        $oauth = $this->googleOAuthSettings($settings);
        if (!$oauth['enabled']) {
            Flash::set('error', 'Autentificarea cu Google nu este activă.');
            header('Location: /login');
            return;
        }

        $next = $this->safeRedirectPath((string) ($_GET['next'] ?? ''), '/contul-meu');
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;
        $_SESSION['google_oauth_next'] = $next;

        $query = http_build_query([
            'client_id' => $oauth['client_id'],
            'redirect_uri' => $oauth['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query);
    }

    public function googleAuthCallback(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea la baza de date nu este disponibilă.');
            header('Location: /login');
            return;
        }
        $this->ensureCustomerSchema($db);

        $settings = Settings::all($db);
        $oauth = $this->googleOAuthSettings($settings);
        if (!$oauth['enabled']) {
            Flash::set('error', 'Autentificarea cu Google nu este activă.');
            header('Location: /login');
            return;
        }

        $state = trim((string) ($_GET['state'] ?? ''));
        $expectedState = (string) ($_SESSION['google_oauth_state'] ?? '');
        unset($_SESSION['google_oauth_state']);
        $next = $this->safeRedirectPath((string) ($_SESSION['google_oauth_next'] ?? ''), '/contul-meu');
        unset($_SESSION['google_oauth_next']);
        if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            Flash::set('error', 'Sesiunea Google a expirat. Încearcă din nou.');
            header('Location: /login');
            return;
        }

        $error = trim((string) ($_GET['error'] ?? ''));
        if ($error !== '') {
            Flash::set('error', 'Autentificarea Google a fost anulată.');
            header('Location: /login');
            return;
        }

        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            Flash::set('error', 'Codul Google lipsește.');
            header('Location: /login');
            return;
        }

        try {
            $token = $this->googleTokenExchange($oauth, $code);
            $userInfo = $this->googleFetchUserInfo((string) ($token['access_token'] ?? ''));
            $userId = $this->resolveGoogleUser($db, $userInfo);
        } catch (RuntimeException $exception) {
            Flash::set('error', $exception->getMessage());
            header('Location: /login');
            return;
        }

        CustomerAuth::login($userId);
        $claimedPoints = $this->claimPendingLoyaltyPointsForUser($db, $userId);
        $successMessage = 'Te-ai autentificat cu Google.';
        if ($claimedPoints > 0) {
            $successMessage .= ' Am adăugat ' . $claimedPoints . ' puncte de fidelitate din comenzile anterioare.';
        }
        Flash::set('success', $successMessage);
        header('Location: ' . $next);
    }

    public function accountRegister(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea la baza de date nu este disponibilă.');
            header('Location: /register');
            return;
        }
        $this->ensureCustomerSchema($db);
        $this->ensureRegisterAntiBotSchema($db);
        $antiBotError = $this->validateRegisterAntiBot($db);
        if ($antiBotError !== null) {
            Flash::set('error', $antiBotError);
            header('Location: /register');
            return;
        }
        $settings = Settings::all($db);
        $registrationFields = $this->customerRegistrationFields($settings);

        $firstName = $registrationFields['first_name'] ? trim((string) ($_POST['first_name'] ?? '')) : 'Client';
        $lastName = $registrationFields['last_name'] ? trim((string) ($_POST['last_name'] ?? '')) : 'Nou';
        $email = $registrationFields['email']
            ? strtolower(trim((string) ($_POST['email'] ?? '')))
            : 'guest+' . bin2hex(random_bytes(6)) . '@local.invalid';
        $phone = $registrationFields['phone'] ? trim((string) ($_POST['phone'] ?? '')) : '';
        $birthDateRaw = $registrationFields['birth_date'] ? trim((string) ($_POST['birth_date'] ?? '')) : '';
        $genderRaw = $registrationFields['gender'] ? trim((string) ($_POST['gender'] ?? '')) : '';
        $gender = in_array($genderRaw, ['feminin', 'masculin'], true) ? $genderRaw : '';
        $password = $registrationFields['password'] ? (string) ($_POST['password'] ?? '') : bin2hex(random_bytes(16));
        $passwordConfirm = $registrationFields['password_confirm'] ? (string) ($_POST['password_confirm'] ?? '') : $password;
        $birthDate = null;

        if ($registrationFields['first_name'] && $firstName === '') {
            Flash::set('error', 'Prenumele este obligatoriu.');
            header('Location: /register');
            return;
        }
        if ($registrationFields['last_name'] && $lastName === '') {
            Flash::set('error', 'Numele este obligatoriu.');
            header('Location: /register');
            return;
        }
        if ($registrationFields['email'] && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            Flash::set('error', 'Adresa de email este invalidă.');
            header('Location: /register');
            return;
        }
        if ($registrationFields['password'] && strlen($password) < 8) {
            Flash::set('error', 'Parola trebuie să aibă minimum 8 caractere.');
            header('Location: /register');
            return;
        }
        if ($registrationFields['password'] && $registrationFields['password_confirm'] && $password !== $passwordConfirm) {
            Flash::set('error', 'Parolele nu coincid.');
            header('Location: /register');
            return;
        }
        if ($registrationFields['birth_date'] && $birthDateRaw !== '') {
            $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDateRaw) === 1;
            if (!$validDate || strtotime($birthDateRaw) === false) {
                Flash::set('error', 'Data nașterii este invalidă.');
                header('Location: /register');
                return;
            }
            $birthDate = $birthDateRaw;
        }

        $existsStmt = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $attempts = 0;
        while (true) {
            $existsStmt->execute(['email' => $email]);
            if (!$existsStmt->fetchColumn()) {
                break;
            }

            if ($registrationFields['email']) {
                Flash::set('error', 'Există deja un cont cu această adresă de email.');
                header('Location: /login');
                return;
            }

            $attempts++;
            if ($attempts > 5) {
                Flash::set('error', 'Contul nu a putut fi creat acum. Încearcă din nou.');
                header('Location: /register');
                return;
            }
            $email = 'guest+' . bin2hex(random_bytes(6)) . '@local.invalid';
        }

        $insert = $db->prepare(
            'INSERT INTO users (first_name, last_name, email, phone, birth_date, gender, password_hash)
             VALUES (:first_name, :last_name, :email, :phone, :birth_date, :gender, :password_hash)'
        );
        $insert->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'birth_date' => $birthDate,
            'gender' => $gender !== '' ? $gender : null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);

        $userId = (int) $db->lastInsertId();
        CustomerAuth::login($userId);
        EmailAutomation::sendAccountWelcome($db, $settings, $email, trim($firstName . ' ' . $lastName));
        $claimedPoints = $this->claimPendingLoyaltyPointsForUser($db, $userId);
        $successMessage = 'Cont creat cu succes. Bine ai venit!';
        if ($claimedPoints > 0) {
            $successMessage .= ' Am adăugat ' . $claimedPoints . ' puncte de fidelitate din comenzile anterioare.';
        }
        Flash::set('success', $successMessage);
        $next = $this->safeRedirectPath((string) ($_POST['next'] ?? ''), '/contul-meu');
        header('Location: ' . $next);
    }

    public function accountLogin(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea la baza de date nu este disponibilă.');
            header('Location: /login');
            return;
        }
        $this->ensureCustomerSchema($db);

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($email === '' || $password === '') {
            Flash::set('error', 'Introdu adresa de email și parola.');
            header('Location: /login');
            return;
        }

        $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch() ?: null;
        $storedHash = (string) ($user['password_hash'] ?? '');
        if (!is_array($user) || !WordPressPassword::verify($password, $storedHash)) {
            Flash::set('error', 'Datele de autentificare nu sunt corecte.');
            header('Location: /login');
            return;
        }

        if (WordPressPassword::needsUpgradeToBcrypt($storedHash)) {
            $upgrade = $db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $upgrade->execute([
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'id' => (int) ($user['id'] ?? 0),
            ]);
        }

        CustomerAuth::login((int) ($user['id'] ?? 0));
        $claimedPoints = $this->claimPendingLoyaltyPointsForUser($db, (int) ($user['id'] ?? 0));
        $successMessage = 'Te-ai autentificat cu succes.';
        if ($claimedPoints > 0) {
            $successMessage .= ' Am adăugat ' . $claimedPoints . ' puncte de fidelitate din comenzile anterioare.';
        }
        Flash::set('success', $successMessage);
        $next = $this->safeRedirectPath((string) ($_POST['next'] ?? ''), '/contul-meu');
        header('Location: ' . $next);
    }

    public function accountLogout(): void
    {
        CustomerAuth::logout();
        Flash::set('success', 'Te-ai delogat cu succes.');
        header('Location: /login');
    }

    public function accountProfileUpdate(): void
    {
        $db = $this->db();
        $userId = CustomerAuth::id();
        if (!$db instanceof PDO || $userId === null || $userId <= 0) {
            Flash::set('error', 'Trebuie să fii autentificat pentru a modifica profilul.');
            header('Location: /login');
            return;
        }
        $this->ensureCustomerSchema($db);

        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $birthDateRaw = trim((string) ($_POST['birth_date'] ?? ''));
        $gender = trim((string) ($_POST['gender'] ?? ''));
        $allowedGenders = ['', 'feminin', 'masculin'];

        if ($firstName === '' || $lastName === '') {
            Flash::set('error', 'Prenumele și numele sunt obligatorii.');
            header('Location: /contul-meu?section=profile');
            return;
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Adresa de email este invalidă.');
            header('Location: /contul-meu?section=profile');
            return;
        }
        if (!in_array($gender, $allowedGenders, true)) {
            $gender = '';
        }

        $birthDate = null;
        if ($birthDateRaw !== '') {
            $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDateRaw) === 1;
            if (!$validDate || strtotime($birthDateRaw) === false) {
                Flash::set('error', 'Data nașterii este invalidă.');
                header('Location: /contul-meu?section=profile');
                return;
            }
            $birthDate = $birthDateRaw;
        }

        $existsStmt = $db->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $existsStmt->execute([
            'email' => $email,
            'id' => $userId,
        ]);
        if ($existsStmt->fetchColumn()) {
            Flash::set('error', 'Adresa de email este folosită deja de alt cont.');
            header('Location: /contul-meu?section=profile');
            return;
        }

        $stmt = $db->prepare(
            'UPDATE users
             SET first_name = :first_name,
                 last_name = :last_name,
                 email = :email,
                 phone = :phone,
                 birth_date = :birth_date,
                 gender = :gender
             WHERE id = :id'
        );
        $stmt->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'birth_date' => $birthDate,
            'gender' => $gender !== '' ? $gender : null,
            'id' => $userId,
        ]);

        Flash::set('success', 'Profilul a fost actualizat.');
        header('Location: /contul-meu?section=profile');
    }

    public function accountPasswordUpdate(): void
    {
        $db = $this->db();
        $userId = CustomerAuth::id();
        if (!$db instanceof PDO || $userId === null || $userId <= 0) {
            Flash::set('error', 'Trebuie să fii autentificat pentru a schimba parola.');
            header('Location: /login');
            return;
        }
        $this->ensureCustomerSchema($db);

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $newPasswordConfirm === '') {
            Flash::set('error', 'Completează toate câmpurile pentru schimbarea parolei.');
            header('Location: /contul-meu?section=settings');
            return;
        }
        if (strlen($newPassword) < 8) {
            Flash::set('error', 'Parola nouă trebuie să aibă minimum 8 caractere.');
            header('Location: /contul-meu?section=settings');
            return;
        }
        if ($newPassword !== $newPasswordConfirm) {
            Flash::set('error', 'Confirmarea parolei nu coincide.');
            header('Location: /contul-meu?section=settings');
            return;
        }

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $hash = (string) ($stmt->fetchColumn() ?: '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            Flash::set('error', 'Parola curentă nu este corectă.');
            header('Location: /contul-meu?section=settings');
            return;
        }

        $update = $db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $update->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            'id' => $userId,
        ]);

        Flash::set('success', 'Parola a fost schimbată cu succes.');
        header('Location: /contul-meu?section=settings');
    }

    public function accountAddressCreate(): void
    {
        $db = $this->db();
        $userId = CustomerAuth::id();
        if (!$db instanceof PDO || $userId === null || $userId <= 0) {
            Flash::set('error', 'Trebuie să fii autentificat pentru a adăuga o adresă.');
            header('Location: /login');
            return;
        }
        $this->ensureCustomerSchema($db);

        $label = trim((string) ($_POST['label'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $street = trim((string) ($_POST['street'] ?? $_POST['billing_street'] ?? ''));
        $streetNo = trim((string) ($_POST['street_no'] ?? $_POST['billing_street_no'] ?? ''));
        $address1 = trim((string) ($_POST['address_line1'] ?? ''));
        $address2 = trim((string) ($_POST['address_line2'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $county = trim((string) ($_POST['county'] ?? ''));
        $postcode = trim((string) ($_POST['postcode'] ?? ''));
        $isDefault = isset($_POST['is_default']) ? 1 : 0;

        if ($address1 === '' && $street !== '') {
            $address1 = $street . ($streetNo !== '' ? ' ' . $streetNo : '');
        }
        if ($street === '') {
            $street = $address1;
        }

        if ($fullName === '' || $street === '' || $streetNo === '' || $city === '' || $county === '' || $postcode === '') {
            Flash::set('error', 'Completează toate câmpurile obligatorii pentru adresă.');
            header('Location: /contul-meu?section=addresses');
            return;
        }

        if ($isDefault === 1) {
            $resetDefault = $db->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = :user_id');
            $resetDefault->execute(['user_id' => $userId]);
        } else {
            $countStmt = $db->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id = :user_id');
            $countStmt->execute(['user_id' => $userId]);
            $isDefault = ((int) $countStmt->fetchColumn() === 0) ? 1 : 0;
        }

        try {
            $insert = $db->prepare(
                'INSERT INTO user_addresses (
                    user_id, label, full_name, phone, street, street_no, address_line1, address_line2, city, county, postcode, is_default
                 ) VALUES (
                    :user_id, :label, :full_name, :phone, :street, :street_no, :address_line1, :address_line2, :city, :county, :postcode, :is_default
                 )'
            );
            $insert->execute([
                'user_id' => $userId,
                'label' => $label !== '' ? $label : null,
                'full_name' => $fullName,
                'phone' => $phone !== '' ? $phone : null,
                'street' => $street !== '' ? $street : null,
                'street_no' => $streetNo !== '' ? $streetNo : null,
                'address_line1' => $address1,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city,
                'county' => $county,
                'postcode' => $postcode !== '' ? $postcode : null,
                'is_default' => $isDefault,
            ]);
        } catch (Throwable) {
            $insert = $db->prepare(
                'INSERT INTO user_addresses (
                    user_id, label, full_name, phone, address_line1, address_line2, city, county, postcode, is_default
                 ) VALUES (
                    :user_id, :label, :full_name, :phone, :address_line1, :address_line2, :city, :county, :postcode, :is_default
                 )'
            );
            $insert->execute([
                'user_id' => $userId,
                'label' => $label !== '' ? $label : null,
                'full_name' => $fullName,
                'phone' => $phone !== '' ? $phone : null,
                'address_line1' => $address1,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city,
                'county' => $county,
                'postcode' => $postcode !== '' ? $postcode : null,
                'is_default' => $isDefault,
            ]);
        }

        Flash::set('success', 'Adresa a fost adăugată.');
        header('Location: /contul-meu?section=addresses');
    }

    public function accountDelete(): void
    {
        $db = $this->db();
        $userId = CustomerAuth::id();
        if (!$db instanceof PDO || $userId === null || $userId <= 0) {
            Flash::set('error', 'Trebuie să fii autentificat pentru a șterge contul.');
            header('Location: /login');
            return;
        }
        $this->ensureCustomerSchema($db);

        if ((string) ($_POST['confirm_delete'] ?? '0') !== '1') {
            Flash::set('error', 'Confirmă ștergerea contului.');
            header('Location: /contul-meu?section=settings');
            return;
        }

        try {
            $db->beginTransaction();
            $db->prepare('UPDATE orders SET user_id = NULL WHERE user_id = :user_id')
                ->execute(['user_id' => $userId]);
            $db->prepare('DELETE FROM user_addresses WHERE user_id = :user_id')
                ->execute(['user_id' => $userId]);
            $db->prepare('DELETE FROM loyalty_points_transactions WHERE user_id = :user_id')
                ->execute(['user_id' => $userId]);
            $db->prepare('DELETE FROM users WHERE id = :id LIMIT 1')
                ->execute(['id' => $userId]);
            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Flash::set('error', 'Nu am putut șterge contul acum. Încearcă din nou.');
            header('Location: /contul-meu?section=settings');
            return;
        }

        CustomerAuth::logout();
        Flash::set('success', 'Contul a fost șters.');
        header('Location: /register');
    }

    public function passwordResetRequestForm(): void
    {
        View::render('site/account-reset-request', [
            'title' => 'Resetare parolă',
        ]);
    }

    public function passwordResetRequestSend(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea la baza de date nu este disponibilă.');
            header('Location: /contul-meu/resetare-parola');
            return;
        }
        $this->ensureCustomerSchema($db);

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $payload = $this->issuePasswordResetToken($db, $email);
            if (is_array($payload)) {
                $this->sendPasswordResetEmail(
                    $db,
                    (string) $payload['email'],
                    (string) $payload['name'],
                    (string) $payload['token']
                );
            }
        }

        Flash::set('success', 'Dacă adresa există în sistem, am trimis instrucțiuni pentru resetarea parolei.');
        header('Location: /contul-meu/resetare-parola');
    }

    public function passwordResetTokenForm(array $params): void
    {
        $token = trim((string) ($params['token'] ?? ''));
        $db = $this->db();
        if ($token === '' || !$db instanceof PDO) {
            Flash::set('error', 'Link-ul de resetare este invalid.');
            header('Location: /contul-meu/resetare-parola');
            return;
        }
        $this->ensureCustomerSchema($db);

        $reset = $this->findValidPasswordReset($db, $token);
        if (!is_array($reset)) {
            Flash::set('error', 'Link-ul de resetare este invalid sau expirat.');
            header('Location: /contul-meu/resetare-parola');
            return;
        }

        View::render('site/account-reset-password', [
            'title' => 'Setează parolă nouă',
            'token' => $token,
            'email' => (string) ($reset['email'] ?? ''),
        ]);
    }

    public function passwordResetTokenSubmit(array $params): void
    {
        $token = trim((string) ($params['token'] ?? ''));
        $db = $this->db();
        if ($token === '' || !$db instanceof PDO) {
            Flash::set('error', 'Link-ul de resetare este invalid.');
            header('Location: /contul-meu/resetare-parola');
            return;
        }
        $this->ensureCustomerSchema($db);

        $reset = $this->findValidPasswordReset($db, $token);
        if (!is_array($reset)) {
            Flash::set('error', 'Link-ul de resetare este invalid sau expirat.');
            header('Location: /contul-meu/resetare-parola');
            return;
        }

        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($password) < 8) {
            Flash::set('error', 'Parola trebuie să aibă minimum 8 caractere.');
            header('Location: /contul-meu/resetare-parola/' . urlencode($token));
            return;
        }
        if ($password !== $passwordConfirm) {
            Flash::set('error', 'Parolele nu coincid.');
            header('Location: /contul-meu/resetare-parola/' . urlencode($token));
            return;
        }

        $email = (string) ($reset['email'] ?? '');
        if ($email === '') {
            Flash::set('error', 'Resetarea parolei nu a putut fi finalizată.');
            header('Location: /contul-meu/resetare-parola');
            return;
        }

        $update = $db->prepare('UPDATE users SET password_hash = :password_hash WHERE email = :email');
        $update->execute([
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'email' => $email,
        ]);
        $use = $db->prepare('UPDATE customer_password_resets SET used_at = NOW() WHERE email = :email AND used_at IS NULL');
        $use->execute(['email' => $email]);

        CustomerAuth::logout();
        Flash::set('success', 'Parola a fost actualizată. Te poți autentifica acum.');
        header('Location: /login');
    }

    public function contact(): void
    {
        $contactPage = $this->findPublishedPageBySlug('contact');
        if (is_array($contactPage)) {
            $db = $this->db();
            $settings = $this->cachedSettings($db);
            View::render('site/custom-page', array_merge([
                'title' => (string) ($contactPage['title'] ?? 'Contact'),
                'page' => $contactPage,
                'mannequinSectionHtml' => $this->renderMannequinSection($db, $settings),
            ], $this->customPageSeoMeta($db instanceof PDO ? $db : null, $contactPage)));
            return;
        }

        View::render('site/contact', ['title' => 'Contact']);
    }

    public function contactSend(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Conexiunea DB nu este disponibilă.',
            ], 500);
            return;
        }
        $this->ensureContactFormsSchema($db);

        $rawPayload = trim((string) file_get_contents('php://input'));
        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Date invalide.',
            ], 400);
            return;
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));

        if ($name === '' || $subject === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Completează corect câmpurile obligatorii.',
            ], 422);
            return;
        }

        $settings = Settings::all($db);
        $destinatari = $this->destinatariFormularContact($settings);
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safePhone = htmlspecialchars($phone !== '' ? $phone : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $mailSubject = '[Contact site] ' . $subject;
        $mailHtml = '<h2 style="margin:0 0 12px;">Mesaj nou din formularul de contact</h2>'
            . '<p style="margin:0 0 8px;"><strong>Nume:</strong> ' . $safeName . '</p>'
            . '<p style="margin:0 0 8px;"><strong>Email:</strong> ' . $safeEmail . '</p>'
            . '<p style="margin:0 0 8px;"><strong>Telefon:</strong> ' . $safePhone . '</p>'
            . '<p style="margin:0 0 8px;"><strong>Subiect:</strong> ' . $safeSubject . '</p>'
            . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:12px 0;">'
            . '<div><strong>Mesaj:</strong><br>' . $safeMessage . '</div>';

        try {
            $insert = $db->prepare(
                'INSERT INTO contact_form_messages (name, email, phone, subject, message, status, source_url, ip_address, user_agent, created_at)
                 VALUES (:name, :email, :phone, :subject, :message, "new", :source_url, :ip_address, :user_agent, NOW())'
            );
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'subject' => $subject,
                'message' => $message,
                'source_url' => trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''))) ?: null,
                'ip_address' => trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?: null,
                'user_agent' => trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) ?: null,
            ]);
        } catch (Throwable) {
            // Keep request flow and still attempt email send even if logging fails.
        }

        try {
            // Mesajul pleacă separat către fiecare destinatar: expedierea e
            // reușită dacă ajunge măcar la unul, ca o adresă greșită din
            // setări să nu blocheze restul.
            $trimise = 0;
            $ultimaEroare = null;
            foreach ($destinatari as $destinatar) {
                try {
                    OrderMailer::sendCustom($destinatar, $mailSubject, $mailHtml, $settings, $db, [
                        'email_type' => 'contact_form_notification',
                        'source' => 'contact_form',
                        'trigger' => 'contact_form_submit',
                        'from_email' => $email,
                        'from_name' => $name,
                    ]);
                    $trimise++;
                } catch (Throwable $e) {
                    $ultimaEroare = $e;
                }
            }
            if ($trimise === 0) {
                throw $ultimaEroare ?? new RuntimeException('Niciun destinatar valid pentru formularul de contact.');
            }
        } catch (Throwable) {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Mesajul nu a putut fi trimis momentan.',
            ], 500);
            return;
        }

        $this->jsonResponse([
            'ok' => true,
            'message' => 'Mesaj trimis cu succes.',
        ]);
    }

    /**
     * Adresele care primesc mesajele din formularul de contact. Se acceptă mai
     * multe, separate prin virgulă, punct-virgulă sau linii noi. Fără setare
     * validă, rămâne adresa implicită, ca formularul să nu tacă niciodată.
     *
     * @return list<string>
     */
    private function destinatariFormularContact(array $settings): array
    {
        $brut = trim((string) ($settings['contact_form_recipients'] ?? ''));
        $bucati = preg_split('/[\s,;]+/', $brut) ?: [];
        $out = [];
        foreach ($bucati as $adresa) {
            $adresa = trim((string) $adresa);
            if ($adresa !== '' && filter_var($adresa, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($adresa)] = $adresa;
            }
        }
        return $out !== [] ? array_values($out) : ['contact@bioscem.ro'];
    }

    public function optInSubmit(array $params): void
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        $db = $this->db();
        if ($slug === '' || !$db instanceof PDO) {
            http_response_code(404);
            echo 'Formularul nu există.';
            return;
        }

        NewsletterService::ensureSchema($db);
        $stmt = $db->prepare(
            'SELECT id, name, list_id, button_label, fields_json, success_message, is_active
             FROM newsletter_optin_forms
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $form = $stmt->fetch() ?: null;
        if (!is_array($form) || (int) ($form['is_active'] ?? 0) !== 1) {
            http_response_code(404);
            echo 'Formularul nu există.';
            return;
        }

        $fields = json_decode((string) ($form['fields_json'] ?? '[]'), true);
        if (!is_array($fields)) {
            $fields = [];
        }
        $normalizedFields = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = trim((string) ($field['type'] ?? ''));
            if (in_array($type, ['__layout', '__meta', '__submit'], true)) {
                continue;
            }
            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalizedFields[] = [
                'name' => $name,
                'required' => (int) ($field['required'] ?? 0) === 1,
            ];
        }

        $payload = [];
        foreach ($normalizedFields as $field) {
            $value = trim((string) ($_POST[$field['name']] ?? ''));
            if ($field['required'] && $value === '') {
                $this->respondOptInError('Completează câmpurile obligatorii.');
                return;
            }
            $payload[$field['name']] = $value;
        }

        $email = strtolower(trim((string) ($payload['email'] ?? ($_POST['email'] ?? ''))));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respondOptInError('Adresa de email este invalidă.');
            return;
        }
        $name = trim((string) ($payload['name'] ?? ($_POST['name'] ?? '')));
        if ($name === '') {
            $name = trim((string) ($payload['full_name'] ?? ''));
        }

        try {
            NewsletterService::subscribeToList($db, (int) ($form['list_id'] ?? 0), $email, $name);
        } catch (RuntimeException $exception) {
            $this->respondOptInError($exception->getMessage());
            return;
        }

        $message = trim((string) ($form['success_message'] ?? 'Te-ai abonat cu succes.'));
        if ($message === '') {
            $message = 'Te-ai abonat cu succes.';
        }
        $this->respondOptInSuccess($message);
    }

    public function newsletterUnsubscribe(array $params): void
    {
        $token = trim((string) ($params['token'] ?? ''));
        if ($token === '') {
            http_response_code(400);
            echo 'Link invalid de dezabonare.';
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(500);
            echo 'Conexiunea DB nu este disponibilă.';
            return;
        }

        NewsletterService::ensureSchema($db);
        $result = NewsletterService::unsubscribeByToken($db, $token);
        if (!$result['ok']) {
            http_response_code(404);
            echo 'Linkul de dezabonare nu este valid sau a expirat.';
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dezabonare</title></head><body style="margin:0;padding:24px;background:#f4f6f8;font-family:Arial,sans-serif;color:#1f2937;"><div style="max-width:560px;margin:24px auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;text-align:center;"><h1 style="margin:0 0 10px;font-size:28px;color:#0f172a;">Te-ai dezabonat cu succes.</h1><p style="margin:0;color:#475569;line-height:1.6;">Nu vei mai primi newslettere de la noi pe adresa <strong style="color:#0f172a;">' . htmlspecialchars((string) ($result['email'] ?? ''), ENT_QUOTES) . '</strong>.</p></div></body></html>';
    }

    public function newsletterTrackOpen(array $params): void
    {
        $campaignId  = (int) ($params['campaignId'] ?? 0);
        $subscriberId = (int) ($params['subscriberId'] ?? 0);
        $token       = trim((string) ($params['token'] ?? ''));

        $db = $this->db();
        $gif = '';
        if ($db instanceof \PDO && $campaignId > 0 && $subscriberId > 0 && $token !== '') {
            $gif = NewsletterService::handleOpenTrack($db, $campaignId, $subscriberId, $token);
        } else {
            $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        }

        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $gif;
    }

    public function newsletterTrackClick(array $params): void
    {
        $campaignId  = (int) ($params['campaignId'] ?? 0);
        $subscriberId = (int) ($params['subscriberId'] ?? 0);
        $token       = trim((string) ($params['token'] ?? ''));
        $destUrl     = trim((string) ($_GET['url'] ?? ''));

        $fallback = 'https://bioscem.ro';
        if ($destUrl === '' || !in_array(parse_url($destUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            header('Location: ' . $fallback);
            return;
        }

        $db = $this->db();
        if ($db instanceof \PDO && $campaignId > 0 && $subscriberId > 0 && $token !== '') {
            $destUrl = NewsletterService::handleClickTrack($db, $campaignId, $subscriberId, $token, $destUrl);
        }

        if ($campaignId > 0) {
            setcookie('nl_cid', (string) $campaignId, [
                'expires'  => time() + 7 * 86400,
                'path'     => '/',
                'samesite' => 'Lax',
                'httponly' => false,
            ]);
        }

        if (!in_array(parse_url($destUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $destUrl = $fallback;
        }

        header('Location: ' . $destUrl);
    }

    public function customPage(array $params): void
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        if ($slug === '') {
            $this->renderPagina404('Pagina nu a fost găsită', 'Linkul accesat nu există.');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->renderPagina404('Pagina nu a fost găsită', 'Linkul accesat nu există.');
            return;
        }
        $this->ensureOptionalPageSchema($db);

        $page = $this->findPublishedPageBySlug($slug);

        if ($page === null) {
            $notFoundPage = $this->findPublishedPageBySlug('404');
            if (!is_array($notFoundPage)) {
                // Poate fi un link vechi către un produs, fără /produs/ în față.
                $potrivit = $this->gasesteProdusDupaSlugAproximativ($db, $slug);
                if ($potrivit !== null && trim((string) ($potrivit['slug'] ?? '')) !== '') {
                    header('Location: /produs/' . rawurlencode((string) $potrivit['slug']), true, 301);
                    return;
                }
                $this->renderPagina404(
                    'Pagina nu a fost găsită',
                    'Este posibil ca linkul să fie vechi. Caută produsul dorit sau vezi tot magazinul.',
                    $db
                );
                return;
            }
            http_response_code(404);
            View::render('site/custom-page', array_merge([
                'title' => (string) ($notFoundPage['title'] ?? 'Pagina nu a fost găsită'),
                'page' => $notFoundPage,
                'mannequinSectionHtml' => $this->renderMannequinSection($db, $this->cachedSettings($db)),
                'shopCatalogHtml' => '',
                'blogPostsHtml' => '',
                'cartFormHtml' => '',
                'checkoutFormHtml' => '',
                'accountSectionHtml' => '',
                'productReviewFormHtml' => '',
                'gdprAgreementsFormHtml' => '',
                'checkoutSuccessOrderInfoHtml' => '',
            ], $this->customPageSeoMeta($db, $notFoundPage)));
            return;
        }

        $settings = $this->cachedSettings($db);
        $checkoutFormHtml = '';
        $cartFormHtml = '';
        $accountSectionHtml = '';
        $productReviewFormHtml = '';
        $gdprAgreementsFormHtml = '';
        $pageHtmlContent = (string) ($page['html_content'] ?? '');
        if (str_contains($pageHtmlContent, self::CART_FORM_TOKEN)) {
            $cartState = $this->buildCartRenderState($db);
            $cartFormHtml = $this->renderCartSection(
                $cartState['summary'],
                $cartState['quantity_ui'],
                (bool) ($cartState['is_logged_in'] ?? false)
            );
        }
        if (str_contains($pageHtmlContent, self::CHECKOUT_FORM_TOKEN)) {
            $checkoutState = $this->buildCheckoutRenderState($db);
            $checkoutFormHtml = $this->renderCheckoutSection(
                $checkoutState['summary'],
                $checkoutState['values'],
                $checkoutState['fan_counties'],
                (bool) ($checkoutState['is_logged_in'] ?? false)
            );
        }
        if ($slug === 'contul-meu' && str_contains($pageHtmlContent, self::ACCOUNT_SECTION_TOKEN)) {
            $accountState = $this->buildAccountSectionState($db);
            if (!(bool) ($accountState['is_logged_in'] ?? false)) {
                $redirectTo = '/login?next=' . rawurlencode('/contul-meu');
                header('Location: ' . $redirectTo);
                return;
            }
            $accountSectionHtml = $this->renderAccountSection($accountState);
        }
        if (str_contains($pageHtmlContent, self::PRODUCT_REVIEW_FORM_TOKEN)) {
            $selectedProductId = (int) ($_GET['review_product_id'] ?? $_GET['product_id'] ?? 0);
            $productReviewFormHtml = $this->renderPublicProductReviewFormSection(
                $db,
                $selectedProductId > 0 ? $selectedProductId : null
            );
        }
        if (str_contains($pageHtmlContent, self::GDPR_AGREEMENTS_FORM_TOKEN)) {
            $gdprAgreementsFormHtml = $this->renderGdprAgreementFormSection();
        }
        $checkoutSuccessOrderInfoHtml = '';
        if ($slug === 'checkout/succes' && str_contains($pageHtmlContent, self::CHECKOUT_SUCCESS_ORDER_INFO_TOKEN)) {
            $orderNumber = trim((string) ($_GET['order_number'] ?? ''));
            $orderStatus = trim((string) ($_GET['order_status'] ?? ''));
            $checkoutSuccessOrderInfoHtml = $this->renderCheckoutSuccessOrderInfoSection($orderNumber, $orderStatus);
        }
        View::render('site/custom-page', array_merge([
            'title' => (string) $page['title'],
            'page' => $page,
            'mannequinSectionHtml' => $this->renderMannequinSection($db, $settings),
            'shopCatalogHtml' => $slug === 'magazin' ? $this->renderShopCatalogSection($db, $this->requestCategoryFilter()) : '',
            'blogPostsHtml' => $slug === 'blog' ? $this->renderBlogPostsSection($db) : '',
            'cartFormHtml' => $cartFormHtml,
            'checkoutFormHtml' => $checkoutFormHtml,
            'accountSectionHtml' => $accountSectionHtml,
            'productReviewFormHtml' => $productReviewFormHtml,
            'gdprAgreementsFormHtml' => $gdprAgreementsFormHtml,
            'checkoutSuccessOrderInfoHtml' => $checkoutSuccessOrderInfoHtml,
        ], $this->customPageSeoMeta($db, $page)));
    }

    /**
     * Punctele FANbox dintr-un județ, pentru selecția din checkout.
     *
     * Lista vine din nomenclatorul importat local, nu din API-ul FAN: nu
     * există un endpoint public cu lockerele, iar o cerere externă la fiecare
     * pas din checkout ar fi oricum un punct de cădere în plus.
     */
    public function fanLockersApi(): void
    {
        // Lista de lockere e informație publică de la FAN; nu o închidem după
        // bifa magazinului. Altfel administratorul nu putea schimba destinația
        // unei comenzi cât timp bifa era oprită, iar mesajul spunea „niciun
        // punct în acest județ" — ceea ce e altceva decât „opțiunea e oprită".
        $db = $this->db();
        $judet = trim((string) ($_GET['county'] ?? ''));
        $localitate = trim((string) ($_GET['locality'] ?? ''));
        // Se întorc toate punctele din județ, cu cele din localitatea clientului
        // primele — nu doar acelea, ca să rămână vizibile alternativele.
        $puncte = \App\Support\FanLockers::pentruJudet($db, $judet, $localitate);

        $this->jsonResponse([
            'ok' => true,
            'items' => array_map(static fn (array $p): array => [
                'id' => $p['id'],
                'name' => $p['name'],
                'locality' => $p['locality'],
                'address' => $p['address'],
                'lat' => $p['lat'] ?? null,
                'lng' => $p['lng'] ?? null,
                'label' => trim($p['locality'] . ' — ' . $p['name'] . ($p['address'] !== '' ? ', ' . $p['address'] : '')),
            ], $puncte),
        ], 200);
    }

    public function fanLocalitiesApi(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->jsonResponse([
                'ok' => false,
                'items' => [],
            ], 503);
            return;
        }

        $this->ensureFanLocalitiesSchema($db);
        $county = trim((string) ($_GET['county'] ?? ''));
        $query = trim((string) ($_GET['q'] ?? ''));
        $limit = max(5, min(30, (int) ($_GET['limit'] ?? 20)));
        $countyNorm = $this->normalizeFanLocalityToken($county);
        $queryNorm = $this->normalizeFanLocalityToken($query);

        $sql = 'SELECT locality, county
                FROM fan_localities
                WHERE (:county_norm = "" OR county_norm = :county_norm)
                  AND (:query_norm = "" OR locality_norm LIKE :query_like)
                ORDER BY locality ASC
                LIMIT :limit';
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':county_norm', $countyNorm, PDO::PARAM_STR);
        $stmt->bindValue(':query_norm', $queryNorm, PDO::PARAM_STR);
        $stmt->bindValue(':query_like', $queryNorm . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $locality = trim((string) ($row['locality'] ?? ''));
            $rowCounty = trim((string) ($row['county'] ?? ''));
            if ($locality === '' || $rowCounty === '') {
                continue;
            }
            $items[] = [
                'locality' => $locality,
                'county' => $rowCounty,
            ];
        }

        if ($countyNorm === 'bucuresti') {
            $queryMatchesSector = $queryNorm === ''
                || str_contains($queryNorm, 'bucuresti')
                || str_contains($queryNorm, 'sector')
                || preg_match('/\b[1-6]\b/', $queryNorm) === 1;
            if ($queryMatchesSector) {
                for ($sector = 1; $sector <= 6; $sector++) {
                    $label = 'Sector ' . $sector;
                    $normalized = $this->normalizeFanLocalityToken($label);
                    if ($queryNorm !== '' && !str_contains($normalized, $queryNorm) && !str_contains('bucuresti', $queryNorm)) {
                        continue;
                    }
                    $items[] = [
                        'locality' => $label,
                        'county' => 'Bucuresti',
                    ];
                }
            }
        }

        $deduplicated = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $locality = trim((string) ($item['locality'] ?? ''));
            $itemCounty = trim((string) ($item['county'] ?? ''));
            if ($locality === '' || $itemCounty === '') {
                continue;
            }
            $key = $this->normalizeFanLocalityToken($itemCounty) . '|' . $this->normalizeFanLocalityToken($locality);
            if ($key === '') {
                continue;
            }
            $deduplicated[$key] = [
                'locality' => $locality,
                'county' => $itemCounty,
            ];
        }
        $items = array_values($deduplicated);
        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($a['locality'] ?? ''), (string) ($b['locality'] ?? ''));
        });
        if (count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
        }

        $this->jsonResponse([
            'ok' => true,
            'items' => $items,
            'count' => count($items),
        ]);
    }

    public function checkoutShippingQuoteApi(): void
    {
        $payload = $this->requestPayload();
        $payload['billing_county'] = trim((string) ($payload['billing_county'] ?? $_GET['billing_county'] ?? ''));
        $payload['billing_city'] = trim((string) ($payload['billing_city'] ?? $_GET['billing_city'] ?? ''));
        $payload['billing_street'] = trim((string) ($payload['billing_street'] ?? $_GET['billing_street'] ?? ''));
        $payload['billing_street_no'] = trim((string) ($payload['billing_street_no'] ?? $_GET['billing_street_no'] ?? ''));
        $payload['billing_postcode'] = trim((string) ($payload['billing_postcode'] ?? $_GET['billing_postcode'] ?? ''));
        // Alegerea FANbox schimbă prețul, deci o reținem înainte de a calcula:
        // sumarul afișat trebuie să fie cel pe care îl va plăti clientul.
        $fanboxBifat = $payload['livrare_fanbox'] ?? $_GET['livrare_fanbox'] ?? null;
        \App\Support\CheckoutCalculator::alegeFanbox(
            (int) ($payload['fan_locker_id'] ?? $_GET['fan_locker_id'] ?? 0),
            $fanboxBifat === null ? null : (bool) (int) $fanboxBifat
        );
        // Taxa de km suplimentari depinde de localitate: fără ea, sumarul ar
        // arăta prețul de bază, iar la finalizare s-ar adăuga taxa.
        \App\Support\Cart::setCounty($payload['billing_county']);
        \App\Support\CheckoutCalculator::setLocalitate($payload['billing_city']);
        $result = $this->checkoutShippingQuoteForPayload($payload);
        $status = trim((string) ($result['error'] ?? '')) === 'Conexiunea DB nu este disponibilă.' ? 503 : 200;
        $this->jsonResponse($result, $status);
    }

    private function checkoutShippingQuoteForPayload(array $payload): array
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            return [
                'ok' => false,
                'error' => 'Conexiunea DB nu este disponibilă.',
            ];
        }

        $county = trim((string) ($payload['billing_county'] ?? ''));
        $locality = trim((string) ($payload['billing_city'] ?? ''));
        $street = trim((string) ($payload['billing_street'] ?? ''));
        $streetNo = trim((string) ($payload['billing_street_no'] ?? ''));
        $postcode = trim((string) ($payload['billing_postcode'] ?? ''));
        $billing = [
            'billing_county' => $county,
            'billing_city' => $locality,
            'billing_street' => $street,
            'billing_street_no' => $streetNo,
            'billing_postcode' => $postcode,
        ];
        if (!$this->isFanShippingAddressComplete($billing)) {
            return [
                'ok' => false,
                'error' => 'Completează județul, localitatea, strada, numărul și codul poștal pentru calcul transport.',
            ];
        }

        $settings = Settings::all($db);
        $summary = CheckoutCalculator::buildSummary($db, $settings);
        $summary['county'] = $county;
        // Prețul fix e deja în sumar și nu depinde de interogarea FAN, deci
        // pentru acest răspuns tratăm magazinul ca fiind pe tarif propriu.
        $fanLiveEnabled = ((string) ($settings['fan_live_tariff_enabled'] ?? '0')) === '1'
            && !\App\Support\ShippingPricing::esteActiv($settings);
        $requiresShippingCharge = $this->requiresFanLiveShippingCharge($settings, $summary);

        if (!$fanLiveEnabled) {
            return [
                'ok' => true,
                'fan_live' => false,
                'shipping' => (float) ($summary['shipping'] ?? 0.0),
                'total' => (float) ($summary['total'] ?? 0.0),
                'requires_shipping_charge' => $requiresShippingCharge,
            ];
        }

        if (!$requiresShippingCharge) {
            $baseTotal = max(
                0.0,
                (float) ($summary['subtotal'] ?? 0.0)
                    - (float) ($summary['discount'] ?? 0.0)
                    - (float) ($summary['points_discount'] ?? 0.0)
                    + (float) ($summary['vat_additional'] ?? 0.0)
            );

            return [
                'ok' => true,
                'fan_live' => true,
                'shipping' => 0.0,
                'total' => $baseTotal,
                'requires_shipping_charge' => false,
            ];
        }

        $shippingError = null;
        $liveShipping = $this->resolveFanLiveShipping($settings, $summary, $billing, $shippingError);
        if ($liveShipping === null) {
            return [
                'ok' => false,
                'error' => trim((string) $shippingError) !== ''
                    ? trim((string) $shippingError)
                    : 'Nu am putut calcula transportul FAN pentru localitatea selectată.',
            ];
        }

        $total = max(
            0.0,
            (float) ($summary['subtotal'] ?? 0.0)
                - (float) ($summary['discount'] ?? 0.0)
                - (float) ($summary['points_discount'] ?? 0.0)
                + $liveShipping
                + (float) ($summary['vat_additional'] ?? 0.0)
        );

        return [
            'ok' => true,
            'fan_live' => true,
            'shipping' => $liveShipping,
            'total' => $total,
            'requires_shipping_charge' => true,
        ];
    }

    private function findPublishedPageBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            return null;
        }
        $this->ensureOptionalPageSchema($db);

        $stmt = $db->prepare(
            'SELECT id, title, slug, html_content, css_content, js_content
             FROM pages
             WHERE slug = :slug AND is_published = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $page = $stmt->fetch() ?: null;
        return is_array($page) ? $page : null;
    }

    private function findPublishedRootPage(): ?array
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            return null;
        }
        $this->ensureOptionalPageSchema($db);

        $stmt = $db->query(
            "SELECT id, title, slug, html_content, css_content, js_content
             FROM pages
             WHERE (slug = '' OR LOWER(slug) IN ('acasa', 'home'))
               AND is_published = 1
               AND deleted_at IS NULL
             ORDER BY CASE WHEN slug = '' THEN 0 WHEN LOWER(slug) = 'acasa' THEN 1 ELSE 2 END, id DESC
             LIMIT 1"
        );
        $page = $stmt ? ($stmt->fetch() ?: null) : null;
        return is_array($page) ? $page : null;
    }

    private function seoMetaFor(?PDO $db, string $pageType, string $pageRef): array
    {
        if (!$db instanceof PDO) {
            return [];
        }

        $seo = $this->loadSeoSettings($db, $pageType, $pageRef);
        if ($seo === []) {
            return [];
        }

        return [
            'metaTitle' => (string) ($seo['title'] ?? ''),
            'metaDescription' => (string) ($seo['description'] ?? ''),
            'metaCanonicalUrl' => (string) ($seo['canonical_url'] ?? ''),
            'metaImageUrl' => (string) ($seo['image_url'] ?? ''),
        ];
    }

    private function customPageSeoMeta(?PDO $db, array $page): array
    {
        $pageId = (int) ($page['id'] ?? 0);
        if ($pageId <= 0) {
            return [];
        }
        return $this->seoMetaFor($db, 'custom_page', (string) $pageId);
    }

    private function normalizeSeoPageType(string $pageType): string
    {
        $pageType = trim($pageType);
        if (!in_array($pageType, ['custom_page', 'product', 'blog_post'], true)) {
            return '';
        }
        return $pageType;
    }

    private function loadSeoSettings(PDO $db, string $pageType, string $pageRef): array
    {
        $pageType = $this->normalizeSeoPageType($pageType);
        $pageRef = trim($pageRef);
        if ($pageType === '' || $pageRef === '') {
            return [];
        }
        $this->ensureOptionalPageSchema($db);

        try {
            $stmt = $db->prepare(
                'SELECT title, description, canonical_url, image_url
                 FROM seo_pages
                 WHERE page_type = :page_type
                   AND page_ref = :page_ref
                 LIMIT 1'
            );
            $stmt->execute([
                'page_type' => $pageType,
                'page_ref' => $pageRef,
            ]);
            $row = $stmt->fetch() ?: null;
            if (!is_array($row)) {
                return [];
            }

            return [
                'title' => trim((string) ($row['title'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')),
                'canonical_url' => trim((string) ($row['canonical_url'] ?? '')),
                'image_url' => trim((string) ($row['image_url'] ?? '')),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    private function requestCategoryFilter(): string
    {
        return trim((string) ($_GET['categorie'] ?? $_GET['category'] ?? ''));
    }

    private function requestShopCatalogSort(): string
    {
        return $this->normalizeShopCatalogSort((string) ($_GET['sort'] ?? 'alpha_asc'));
    }

    private function renderShopCatalogSection(?PDO $db, string $categoryFilter = ''): string
    {
        $categoryFilter = trim($categoryFilter);
        $sort = $this->requestShopCatalogSort();
        [$products] = $this->loadProducts();
        $products = $this->enrichShopCatalogProducts($db, $products);
        $categories = $this->loadShopCatalogCategories($db);
        if ($categories === []) {
            $categories = $this->buildShopCatalogCategoriesFromProducts($products);
        }
        $categoryFilter = $this->normalizeShopCategoryFilter($categoryFilter, $categories);
        if ($categoryFilter !== '') {
            $products = array_values(array_filter(
                $products,
                static fn (array $product): bool => \App\Support\ProductCategories::matchesName($product, $categoryFilter)
            ));
        }
        $products = $this->applyShopCatalogSort($products, $sort);
        return $this->renderPhpView('site/components/shop-catalog', [
            'products' => $products,
            'categories' => $categories,
            'activeCategory' => $categoryFilter,
            'sort' => $sort,
            'sortOptions' => $this->shopCatalogSortOptions(),
            'baseUrl' => '/magazin',
        ]);
    }

    private function renderBlogPostsSection(?PDO $db): string
    {
        $perPage = self::BLOG_POSTS_PER_PAGE;
        $totalPosts = $this->countPublishedBlogPosts($db);
        $totalPages = max(1, (int) ceil($totalPosts / $perPage));
        $currentPage = min($this->requestBlogPostsPage(), $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $posts = $this->loadPublishedBlogPosts($db, $perPage, $offset);

        $numericPages = array_values(array_unique(array_filter([
            1,
            2,
            3,
            $currentPage - 1,
            $currentPage,
            $currentPage + 1,
            $totalPages - 2,
            $totalPages - 1,
            $totalPages,
        ], static fn (int $page): bool => $page >= 1 && $page <= $totalPages)));
        sort($numericPages, SORT_NUMERIC);

        $paginationItems = [];
        $previousPage = null;
        foreach ($numericPages as $page) {
            if ($previousPage !== null) {
                $gap = $page - $previousPage;
                if ($gap === 2) {
                    $missingPage = $previousPage + 1;
                    $paginationItems[] = [
                        'type' => 'page',
                        'page' => $missingPage,
                        'url' => $this->blogPostsPaginationUrl($missingPage),
                        'is_current' => $missingPage === $currentPage,
                    ];
                } elseif ($gap > 2) {
                    $paginationItems[] = ['type' => 'ellipsis'];
                }
            }

            $paginationItems[] = [
                'type' => 'page',
                'page' => $page,
                'url' => $this->blogPostsPaginationUrl($page),
                'is_current' => $page === $currentPage,
            ];
            $previousPage = $page;
        }

        $pagination = [
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'total_posts' => $totalPosts,
            'per_page' => $perPage,
            'first_url' => $this->blogPostsPaginationUrl(1),
            'last_url' => $this->blogPostsPaginationUrl($totalPages),
            'prev_url' => $currentPage > 1 ? $this->blogPostsPaginationUrl($currentPage - 1) : '',
            'next_url' => $currentPage < $totalPages ? $this->blogPostsPaginationUrl($currentPage + 1) : '',
            'page_links' => $paginationItems,
            'items' => $paginationItems,
        ];

        return $this->renderPhpView('site/components/blog-posts', [
            'posts' => $posts,
            'baseUrl' => '/blog',
            'pagination' => $pagination,
        ]);
    }

    private function renderPublicProductReviewFormSection(?PDO $db, ?int $selectedProductId = null): string
    {
        $products = $this->loadReviewFormProducts($db);
        if ($products === []) {
            return '<section id="qr-product-review-form" class="product-reviews product-reviews--public">'
                . '<div class="product-reviews-head"><div class="product-reviews-head__inline"><span class="product-reviews-head__label">Recenzie produs</span><p>Formularul este momentan indisponibil.</p></div></div>'
                . '</section>';
        }

        $selectedId = $selectedProductId !== null ? (int) $selectedProductId : 0;
        if ($selectedId <= 0) {
            $selectedId = (int) ($products[0]['id'] ?? 0);
        }
        $selectedExists = false;
        $options = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $id = (int) ($product['id'] ?? 0);
            $name = trim((string) ($product['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $isSelected = $id === $selectedId;
            if ($isSelected) {
                $selectedExists = true;
            }
            $options[] = '<option value="' . $id . '"' . ($isSelected ? ' selected' : '') . '>'
                . htmlspecialchars($name, ENT_QUOTES)
                . '</option>';
        }
        if (!$selectedExists && $options !== []) {
            $options[0] = str_replace('<option ', '<option selected ', $options[0]);
        }

        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $requestPath = trim($requestPath) !== '' ? $requestPath : '/';
        $redirectTo = htmlspecialchars($requestPath . '#qr-product-review-form', ENT_QUOTES);
        $defaultName = '';
        $defaultEmail = '';
        if ($db instanceof PDO) {
            $customer = CustomerAuth::user($db);
            if (is_array($customer)) {
                $defaultName = trim((string) (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')));
                $defaultEmail = trim((string) ($customer['email'] ?? ''));
            }
        }
        $ratingControls = '';
        for ($value = 1; $value <= 5; $value++) {
            $isActive = $value <= 5;
            $ratingControls .= '<button type="button" class="product-review-rating__star' . ($isActive ? ' is-active' : '') . '" data-rating-value="' . $value . '" aria-label="' . $value . ' stele" aria-pressed="' . ($isActive ? 'true' : 'false') . '">★</button>';
        }

        return '<section id="qr-product-review-form" class="product-reviews product-reviews--public">'
            . '<div class="product-reviews-head"><div class="product-reviews-head__inline"><span class="product-reviews-head__label">Recenzie produs</span></div></div>'
            . '<form method="post" action="/review-form/submit" class="product-review-form">'
            . '<input type="hidden" name="redirect_to" value="' . $redirectTo . '">'
            . '<div class="field"><label>Produs</label><select name="product_id" required>' . implode('', $options) . '</select></div>'
            . '<div class="field"><label>Nume</label><input type="text" name="review_name" value="' . htmlspecialchars($defaultName, ENT_QUOTES) . '" required></div>'
            . '<div class="field"><label>Email (opțional)</label><input type="email" name="review_email" value="' . htmlspecialchars($defaultEmail, ENT_QUOTES) . '" placeholder="nume@email.com"></div>'
            . '<div class="field"><label>Rating</label><div class="product-review-rating" data-review-rating="1"><input type="hidden" name="review_rating" value="5" data-review-rating-input="1">' . $ratingControls . '</div></div>'
            . '<div class="field"><label>Recenzie</label><textarea name="review_text" rows="4" required></textarea></div>'
            . '<button class="btn" type="submit">Trimite recenzia</button>'
            . '</form>'
            . '</section>';
    }

    private function renderGdprAgreementFormSection(): string
    {
        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/acorduri-gdpr'), PHP_URL_PATH);
        $requestPath = trim($requestPath) !== '' ? $requestPath : '/acorduri-gdpr';
        $redirectTo = htmlspecialchars($requestPath . '#gdpr-agreement-form', ENT_QUOTES);
        $oldInput = $this->consumeGdprAgreementOldInput();
        $old = static function (array $data, string $key, string $default = ''): string {
            if (!array_key_exists($key, $data)) {
                return $default;
            }
            return trim((string) ($data[$key] ?? ''));
        };
        $today = date('Y-m-d');
        $subiectNumeComplet = htmlspecialchars($old($oldInput, 'subiect_nume_complet'), ENT_QUOTES);
        $ciSerie = htmlspecialchars($old($oldInput, 'ci_serie'), ENT_QUOTES);
        $ciNumar = htmlspecialchars($old($oldInput, 'ci_numar'), ENT_QUOTES);
        $ciEmitent = htmlspecialchars($old($oldInput, 'ci_emitent'), ENT_QUOTES);
        $ciDataEliberare = htmlspecialchars($old($oldInput, 'ci_data_eliberare'), ENT_QUOTES);
        $nume = htmlspecialchars($old($oldInput, 'nume'), ENT_QUOTES);
        $prenume = htmlspecialchars($old($oldInput, 'prenume'), ENT_QUOTES);
        $cnp = htmlspecialchars($old($oldInput, 'cnp'), ENT_QUOTES);
        $cuim = htmlspecialchars($old($oldInput, 'cuim'), ENT_QUOTES);
        $telefon = htmlspecialchars($old($oldInput, 'telefon'), ENT_QUOTES);
        $email = htmlspecialchars($old($oldInput, 'email'), ENT_QUOTES);
        $adresaCorespondenta = htmlspecialchars($old($oldInput, 'adresa_corespondenta'), ENT_QUOTES);
        $institutieMedicala = htmlspecialchars($old($oldInput, 'institutie_medicala'), ENT_QUOTES);
        $institutieAdresa = htmlspecialchars($old($oldInput, 'institutie_adresa'), ENT_QUOTES);
        $institutieActivitate = htmlspecialchars($old($oldInput, 'institutie_activitate'), ENT_QUOTES);
        $institutieActivitateAdresa = htmlspecialchars($old($oldInput, 'institutie_activitate_adresa'), ENT_QUOTES);
        $tipMedic = htmlspecialchars($old($oldInput, 'tip_medic'), ENT_QUOTES);
        $specializare = htmlspecialchars($old($oldInput, 'specializare'), ENT_QUOTES);
        $dataSemnare = htmlspecialchars($old($oldInput, 'data_semnare', $today), ENT_QUOTES);
        $numeSemnatura = htmlspecialchars($old($oldInput, 'nume_semnatura'), ENT_QUOTES);
        $signatureDataUrl = htmlspecialchars($old($oldInput, 'signature_data_url'), ENT_QUOTES);

        return <<<HTML
<section id="gdpr-agreement-form" class="gdpr-agreement" data-gdpr-agreement-root="1">
    <style>
        .gdpr-agreement{max-width:980px;margin:0 auto;border:1px solid #d7dee7;background:#fff;padding:26px;border-radius:14px;color:#0f172a}
        .gdpr-agreement h2{margin:0 0 18px;font-size:32px;line-height:1.2;text-align:center}
        .gdpr-agreement h3{margin:22px 0 12px;font-size:32px;line-height:1.2}
        .gdpr-agreement p{margin:0 0 16px;line-height:1.78}
        .gdpr-agreement table{width:100%;border-collapse:collapse;margin:8px 0 16px}
        .gdpr-agreement th,.gdpr-agreement td{border:1px solid #9aa6b2;padding:10px;vertical-align:top}
        .gdpr-agreement th{width:34%;font-weight:700;background:#f8fafc;text-align:left}
        .gdpr-agreement .gdpr-form-error{margin:0 0 14px;padding:10px 12px;border:1px solid #fca5a5;border-radius:10px;background:#fef2f2;color:#991b1b;font:600 14px/1.45 "DM Sans",Arial,sans-serif}
        .gdpr-agreement .inline-fields{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .gdpr-agreement input[type="text"],
        .gdpr-agreement input[type="email"],
        .gdpr-agreement input[type="date"],
        .gdpr-agreement textarea,
        .gdpr-agreement select{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:9px 11px;font:500 14px/1.4 "DM Sans",Arial,sans-serif;box-sizing:border-box}
        .gdpr-agreement textarea{resize:vertical;min-height:60px}
        .gdpr-agreement .line-input{display:inline-flex;min-width:170px;max-width:280px;vertical-align:middle;margin:0 10px 10px 10px}
        .gdpr-agreement .line-input input{margin:0}
        .gdpr-agreement .signature-box{border:1px dashed #64748b;border-radius:10px;padding:10px;background:#f8fafc}
        .gdpr-agreement canvas{width:100%;height:180px;background:#fff;border:1px solid #cbd5e1;border-radius:8px;touch-action:none;display:block}
        .gdpr-agreement .signature-actions{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap}
        .gdpr-agreement .muted{color:#64748b;font-size:13px}
        .gdpr-agreement .form-actions{margin-top:18px;display:flex;justify-content:flex-end}
        .gdpr-agreement .bullet-list{margin:0 0 12px 18px}
        .gdpr-agreement .bullet-list li{margin-bottom:6px}
        .gdpr-agreement .bottom-grid{display:grid;gap:16px;grid-template-columns:1fr 1.2fr;margin-top:18px}
        .gdpr-agreement .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#fff;font-weight:600;cursor:pointer}
        .gdpr-agreement .btn.btn-secondary{background:#fff;color:#0f172a}
        @media (max-width:1024px){
            .gdpr-agreement{margin-inline:24px}
        }
        @media (max-width:760px){
            .gdpr-agreement{padding:16px;margin-inline:12px}
            .gdpr-agreement h2{font-size:24px}
            .gdpr-agreement h3{font-size:21px}
            .gdpr-agreement .line-input{min-width:120px;max-width:100%;margin:0 8px 8px 8px}
            .gdpr-agreement .bottom-grid{grid-template-columns:1fr}
        }
    </style>
    <h2>Acord privind utilizarea și procesarea datelor cu caracter personal</h2>
    <form method="post" action="/gdpr-agreements/submit" novalidate>
        <input type="hidden" name="redirect_to" value="{$redirectTo}">
        <div class="gdpr-form-error" data-gdpr-form-error hidden></div>
        <p>
            Subsemnatul/a
            <span class="line-input"><input type="text" name="subiect_nume_complet" value="{$subiectNumeComplet}"></span>,
            legitimat/ă cu CI seria
            <span class="line-input"><input type="text" name="ci_serie" value="{$ciSerie}"></span>
            nr
            <span class="line-input"><input type="text" name="ci_numar" value="{$ciNumar}"></span>,
            eliberată de către
            <span class="line-input"><input type="text" name="ci_emitent" value="{$ciEmitent}"></span>
            la data de
            <span class="line-input"><input type="date" name="ci_data_eliberare" value="{$ciDataEliberare}"></span>,
            denumit în continuare <strong>subiect</strong>, îmi exprim acordul cu privire la utilizarea datelor cu caracter personal pentru înscrierea mea la evenimentul medical
            <strong>“Conferințele Neurocare – Ediția a 2-a Multidisciplinaritate în acțiune – Frontiere în neuropediatrie” - București, între 14-16 mai 2026</strong>,
            de către operatorul de date cu caracter personal <strong>SC Amalbo Consulting SRL</strong>, persoană juridică română, cu sediul în București, str.Dr.Rațiu nr. 13, sector 1, telefon 0756.195.323, înregistrată la Registrul Comerțului sub nr. J2006017724407, cod unic de înregistrare/cod TVA (RO)19166493, identificată în acest document prin marca <strong>„Bioscem”</strong>, reprezentată legal prin Director General Tătaru Bogdan, în calitate de <strong>beneficiar</strong>, cunoscând următoarele:
        </p>

        <h3>I. Date procesate</h3>
        <table>
            <tbody>
            <tr><th>NUME</th><td><input type="text" name="nume" value="{$nume}"></td></tr>
            <tr><th>PRENUME</th><td><input type="text" name="prenume" value="{$prenume}"></td></tr>
            <tr><th>CNP</th><td><input type="text" name="cnp" inputmode="numeric" maxlength="13" value="{$cnp}"></td></tr>
            <tr><th>CUIM</th><td><input type="text" name="cuim" value="{$cuim}"></td></tr>
            <tr><th>TELEFON</th><td><input type="text" name="telefon" value="{$telefon}"></td></tr>
            <tr><th>EMAIL</th><td><input type="email" name="email" value="{$email}"></td></tr>
            <tr><th>ADRESĂ DE CORESPONDENȚĂ</th><td><textarea name="adresa_corespondenta">{$adresaCorespondenta}</textarea></td></tr>
            <tr><th>DENUMIRE INSTITUȚIE MEDICALĂ<br><small>DESFĂȘURARE ACTIVITATE</small></th><td><textarea name="institutie_medicala">{$institutieMedicala}</textarea></td></tr>
            <tr><th>ADRESĂ INSTITUȚIE MEDICALĂ<br><small>DESFĂȘURARE ACTIVITATE</small></th><td><textarea name="institutie_adresa">{$institutieAdresa}</textarea></td></tr>
            <tr><th>DESFĂȘURARE ACTIVITATE (INSTITUȚIE)</th><td><input type="text" name="institutie_activitate" value="{$institutieActivitate}"></td></tr>
            <tr><th>DESFĂȘURARE ACTIVITATE (ADRESĂ)</th><td><input type="text" name="institutie_activitate_adresa" value="{$institutieActivitateAdresa}"></td></tr>
            <tr><th>TIP MEDIC (primar, specialist, rezident)</th><td><input type="text" name="tip_medic" value="{$tipMedic}"></td></tr>
            <tr><th>SPECIALIZARE</th><td><input type="text" name="specializare" value="{$specializare}"></td></tr>
            </tbody>
        </table>

        <h3>II. Scopul în care va fi utilizat consimțământul</h3>
        <p>Datele cu caracter personal vor fi utilizate strict pentru înscrierea la evenimentul medical menționat.</p>

        <h3>III. Drepturile subiectului</h3>
        <p>Subiectul este protejat de către Regulamentul nr. 679/2016 privind protecția persoanelor fizice în ceea ce privește prelucrarea datelor cu caracter personal și privind libera circulație a acestor date și de abrogare a Directivei 95/46/CE (Regulament General privind protecția datelor) și are dreptul de a solicita în orice moment:</p>
        <ul class="bullet-list">
            <li>Informarea și consultarea informațiilor vizate;</li>
            <li>Actualizarea informațiilor vizate;</li>
            <li>Ștergerea informațiilor vizate;</li>
            <li>Restricționarea și opunerea în prelucrarea informațiilor vizate.</li>
        </ul>

        <h3>IV. Valabilitate</h3>
        <p>Prezentul consimțământ este valabil până la retragerea expresă a acestuia, în formă scrisă prin utilizarea serviciilor poștale la SC AMALBO CONSULTING SRL - str.Dr.Rațiu nr.13, sector 1, București sau prin utilizarea serviciilor de poștă electronică la adresa <a href="mailto:gdpr@bioscem.ro">gdpr@bioscem.ro</a>.</p>

        <h3>V. Declarație</h3>
        <p>Subiectul își exprimă consimțământul în favoarea beneficiarului cu privire la utilizarea neremunerată a datelor cu caracter personal descrise mai sus. Utilizarea datelor cu caracter personal, al imaginilor fotografice, înregistrărilor audio și video în alte scopuri decât cele descrise mai sus sau pentru comercializarea prin transferul datelor cu caracter personal, al imaginilor către alți terți decât cei menționați este strict interzisă.</p>

        <div class="bottom-grid">
            <div class="field">
                <label for="gdpr-data-semnare"><strong>Data</strong></label>
                <input id="gdpr-data-semnare" type="date" name="data_semnare" value="{$dataSemnare}">
            </div>
            <div class="field">
                <label><strong>Numele în clar și semnătura subiectului</strong></label>
                <input type="text" name="nume_semnatura" placeholder="Nume complet" value="{$numeSemnatura}">
                <div class="signature-box">
                    <canvas data-signature-canvas width="760" height="220"></canvas>
                    <div class="signature-actions">
                        <small class="muted">Semnează cu degetul pe tabletă în chenarul de mai sus.</small>
                        <button class="btn btn-secondary" type="button" data-signature-clear="1">Șterge semnătura</button>
                    </div>
                </div>
                <input type="hidden" name="signature_data_url" value="{$signatureDataUrl}" data-signature-output="1">
            </div>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">Salvează</button>
        </div>
    </form>
    <script>
        (() => {
            const root = document.querySelector('[data-gdpr-agreement-root="1"]');
            if (!(root instanceof HTMLElement) || root.dataset.gdprSignatureReady === '1') {
                return;
            }
            root.dataset.gdprSignatureReady = '1';
            const form = root.querySelector('form');
            const canvas = root.querySelector('[data-signature-canvas]');
            const output = root.querySelector('[data-signature-output]');
            const errorBox = root.querySelector('[data-gdpr-form-error]');
            const nameInput = form?.querySelector('[name="nume_semnatura"]');
            const cnpInput = form?.querySelector('[name="cnp"]');
            const emailInput = form?.querySelector('[name="email"]');
            const clearBtn = root.querySelector('[data-signature-clear]');
            if (!(form instanceof HTMLFormElement) || !(canvas instanceof HTMLCanvasElement) || !(output instanceof HTMLInputElement)) {
                return;
            }
            const showError = (message) => {
                if (!(errorBox instanceof HTMLElement)) {
                    return;
                }
                errorBox.textContent = String(message || 'Completează câmpurile obligatorii.');
                errorBox.hidden = false;
                if (typeof window.scrollTo === 'function') {
                    window.requestAnimationFrame(() => {
                        const top = Math.max(0, window.scrollY + root.getBoundingClientRect().top - 20);
                        window.scrollTo({
                            top,
                            behavior: 'smooth',
                        });
                    });
                }
            };
            const clearError = () => {
                if (!(errorBox instanceof HTMLElement)) {
                    return;
                }
                errorBox.hidden = true;
                errorBox.textContent = '';
            };
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return;
            }
            let drawing = false;
            let persistedSignature = output.value.trim();
            let hasInk = persistedSignature !== '';
            let hasRenderedSignature = false;
            let lastPoint = null;
            const resizeCanvas = () => {
                const ratio = Math.max(1, window.devicePixelRatio || 1);
                const rect = canvas.getBoundingClientRect();
                const backup = hasRenderedSignature ? canvas.toDataURL('image/png') : persistedSignature;
                canvas.width = Math.max(320, Math.round(rect.width * ratio));
                canvas.height = Math.max(140, Math.round(rect.height * ratio));
                ctx.setTransform(1, 0, 0, 1, 0, 0);
                ctx.scale(ratio, ratio);
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#0f172a';
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, rect.width, rect.height);
                if (backup !== '') {
                    const image = new Image();
                    image.onload = () => {
                        ctx.drawImage(image, 0, 0, rect.width, rect.height);
                        output.value = canvas.toDataURL('image/png');
                        persistedSignature = output.value;
                        hasInk = true;
                        hasRenderedSignature = true;
                    };
                    image.src = backup;
                }
            };
            const pointFromEvent = (event) => {
                const rect = canvas.getBoundingClientRect();
                return {
                    x: event.clientX - rect.left,
                    y: event.clientY - rect.top,
                };
            };
            const begin = (event) => {
                drawing = true;
                lastPoint = pointFromEvent(event);
                event.preventDefault();
            };
            const move = (event) => {
                if (!drawing || !lastPoint) {
                    return;
                }
                const next = pointFromEvent(event);
                ctx.beginPath();
                ctx.moveTo(lastPoint.x, lastPoint.y);
                ctx.lineTo(next.x, next.y);
                ctx.stroke();
                lastPoint = next;
                hasInk = true;
                output.value = canvas.toDataURL('image/png');
                persistedSignature = output.value;
                hasRenderedSignature = true;
                clearError();
                event.preventDefault();
            };
            const end = () => {
                if (!drawing) {
                    return;
                }
                drawing = false;
                lastPoint = null;
                if (hasInk) {
                    output.value = canvas.toDataURL('image/png');
                    persistedSignature = output.value;
                    hasRenderedSignature = true;
                }
            };
            canvas.addEventListener('pointerdown', begin);
            canvas.addEventListener('pointermove', move);
            canvas.addEventListener('pointerup', end);
            canvas.addEventListener('pointerleave', end);
            clearBtn?.addEventListener('click', () => {
                hasInk = false;
                hasRenderedSignature = false;
                persistedSignature = '';
                output.value = '';
                resizeCanvas();
            });
            if (nameInput instanceof HTMLInputElement) {
                nameInput.addEventListener('input', clearError);
            }
            if (cnpInput instanceof HTMLInputElement) {
                cnpInput.addEventListener('input', () => {
                    clearError();
                    cnpInput.value = cnpInput.value.replace(/[^\d]/g, '').slice(0, 13);
                });
            }
            if (emailInput instanceof HTMLInputElement) {
                emailInput.addEventListener('input', clearError);
            }
            form.addEventListener('submit', (event) => {
                const nameValue = nameInput instanceof HTMLInputElement ? nameInput.value.trim() : '';
                const cnpValue = cnpInput instanceof HTMLInputElement ? cnpInput.value.trim() : '';
                const emailValue = emailInput instanceof HTMLInputElement ? emailInput.value.trim() : '';
                if (nameValue === '') {
                    event.preventDefault();
                    showError('Câmpul „Numele în clar și semnătura subiectului” este obligatoriu.');
                    return;
                }
                if (cnpValue !== '' && !/^\d{13}$/.test(cnpValue)) {
                    event.preventDefault();
                    showError('CNP-ul trebuie să conțină exact 13 cifre.');
                    return;
                }
                if (emailInput instanceof HTMLInputElement && emailValue !== '' && !emailInput.checkValidity()) {
                    event.preventDefault();
                    showError('Adresa de email nu este validă.');
                    return;
                }
                if (!hasInk || output.value.trim() === '') {
                    event.preventDefault();
                    showError('Semnătura este obligatorie.');
                    return;
                }
                clearError();
            });
            window.addEventListener('resize', () => {
                resizeCanvas();
            });
            resizeCanvas();
        })();
    </script>
</section>
HTML;
    }

    private function renderCheckoutSuccessOrderInfoSection(
        string $orderNumber,
        ?float $orderTotal = null,
        string $orderCurrency = 'RON',
        string $orderEmail = ''
    ): string {
        $safeOrderNumber = trim($orderNumber);
        $safeCurrency = trim($orderCurrency) !== '' ? strtoupper(trim($orderCurrency)) : 'RON';

        // The label (text before ":") is non-selectable so dragging selects only the value.
        $labelStyle = 'user-select:none;-webkit-user-select:none;-moz-user-select:none;';
        $row = static function (string $label, string $value) use ($labelStyle): string {
            return '<p><strong style="' . $labelStyle . '">' . htmlspecialchars($label, ENT_QUOTES) . ': </strong>'
                . '<span class="order-info-value">' . htmlspecialchars($value, ENT_QUOTES) . '</span></p>';
        };

        $html = '<div class="checkout-success-order-info" data-checkout-success-order-info="1">'
            . $row('Număr comandă', $safeOrderNumber);
        if ($orderTotal !== null) {
            $html .= $row('Suma comenzii', number_format($orderTotal, 2, ',', '.'))
                . $row('Valută', $safeCurrency);
        }
        if (trim($orderEmail) !== '') {
            $html .= $row('Email', trim($orderEmail));
        }
        $html .= '</div>';

        return $html;
    }

    private function claimPendingLoyaltyPointsForUser(PDO $db, int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $db->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $email = strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }

        return LoyaltyService::claimPendingPointsByEmail($db, $userId, $email);
    }

    private function loadReviewFormProducts(?PDO $db): array
    {
        if (!$db instanceof PDO) {
            return [];
        }

        try {
            $stmt = $db->query(
                'SELECT id, name
                 FROM products
                 WHERE deleted_at IS NULL AND is_active = 1
                 ORDER BY name ASC, id DESC
                 LIMIT 600'
            );
            $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        } catch (Throwable) {
            try {
                $stmt = $db->query(
                    'SELECT id, name
                     FROM products
                     WHERE deleted_at IS NULL
                     ORDER BY name ASC, id DESC
                     LIMIT 600'
                );
                $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
            } catch (Throwable) {
                $rows = [];
            }
        }

        $products = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $products[] = [
                'id' => $id,
                'name' => $name,
            ];
        }
        return $products;
    }

    private function requestBlogPostsPage(): int
    {
        $page = (int) ($_GET['page'] ?? $_GET['blog_page'] ?? 1);
        return max(1, $page);
    }

    private function blogPostsPaginationUrl(int $page): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/blog'), PHP_URL_PATH);
        $path = trim($path) !== '' ? $path : '/blog';
        $page = max(1, $page);

        $query = $_GET;
        if (!is_array($query)) {
            $query = [];
        }
        unset($query['page'], $query['blog_page']);
        if ($page > 1) {
            $query['page'] = $page;
        }

        return $path . ($query !== [] ? ('?' . http_build_query($query)) : '');
    }

    private function renderAccountSection(array $state): string
    {
        $orders = is_array($state['orders'] ?? null) ? $state['orders'] : [];
        $addresses = is_array($state['addresses'] ?? null) ? $state['addresses'] : [];
        $latestOrder = [];
        if ($orders !== [] && is_array($orders[0])) {
            $latestOrder = $orders[0];
        }
        $settingsCardRows = [
            [
                'label' => 'Limbă',
                'description' => 'Limba interfeței',
                'value' => 'Română',
            ],
            [
                'label' => 'Monedă',
                'description' => 'Moneda afișată',
                'value' => 'RON (lei)',
            ],
        ];

        return $this->renderPhpView('site/components/account-section', [
            'loyaltyEnabled' => !empty($state['loyalty_enabled']),
            'customer' => $state['customer'] ?? null,
            'orders' => $orders,
            'addresses' => $addresses,
            'accountSection' => $state['account_section'] ?? 'profile',
            'ordersCount' => $state['orders_count'] ?? 0,
            'loyaltyPoints' => $state['loyalty_points'] ?? 0,
            'latestOrderDateLabel' => $state['last_order_label'] ?? '-',
            'membershipLabel' => $state['membership_label'] ?? '-',
            'fullName' => $state['full_name'] ?? '',
            'email' => $state['email'] ?? '',
            'phone' => $state['phone'] ?? '',
            'avatarInitials' => $state['avatar_initials'] ?? 'CL',
            'latestOrder' => $latestOrder,
            'pointsHistory' => $state['loyalty_transactions'] ?? [],
            'profileEdit' => !empty($state['profile_edit']),
            'settingsCardRows' => $settingsCardRows,
        ]);
    }

    private function buildAccountSectionState(?PDO $db): array
    {
        $state = [
            'is_logged_in' => false,
            'customer' => null,
            'orders' => [],
            'addresses' => [],
            'loyalty_transactions' => [],
            'orders_count' => 0,
            'loyalty_points' => 0,
            'membership_label' => '-',
            'last_order_label' => '-',
            'full_name' => '',
            'email' => '',
            'phone' => '',
            'avatar_initials' => 'CL',
            'account_section' => 'profile',
            'profile_edit' => false,
            'loyalty_enabled' => true,
        ];
        if (!$db instanceof PDO) {
            return $state;
        }
        $this->ensureCustomerSchema($db);
        $state['loyalty_enabled'] = !empty(LoyaltyService::config(Settings::all($db))['enabled']);

        $customer = CustomerAuth::user($db);
        if (!is_array($customer)) {
            return $state;
        }
        $state['is_logged_in'] = true;
        $state['customer'] = $customer;

        $email = trim((string) ($customer['email'] ?? ''));
        $userId = (int) ($customer['id'] ?? 0);
        $ordersStmt = $db->prepare(
            'SELECT id, order_number, status, payment_status, total, created_at,
                    billing_first_name, billing_last_name, billing_phone, billing_email,
                    billing_address_line1, billing_address_line2, billing_city, billing_county, billing_postcode
             FROM orders
             WHERE deleted_at IS NULL
               AND (user_id = :user_id OR (user_id IS NULL AND billing_email = :email))
             ORDER BY id DESC
             LIMIT 200'
        );
        $ordersStmt->execute([
            'user_id' => $userId,
            'email' => $email,
        ]);
        $orders = $ordersStmt->fetchAll() ?: [];
        $addresses = $this->loadCustomerAddresses($db, $userId);

        $firstName = trim((string) ($customer['first_name'] ?? ''));
        $lastName = trim((string) ($customer['last_name'] ?? ''));
        // Numele generat la înregistrarea doar cu email se afișează ca email.
        $fullName = \App\Support\CustomerDisplay::nume($firstName, $lastName, $email);
        $state['orders'] = $orders;
        $state['addresses'] = $addresses;
        $state['loyalty_transactions'] = LoyaltyService::userTransactions($db, $userId, 120);
        $state['orders_count'] = count($orders);
        $state['loyalty_points'] = max(0, (int) ($customer['loyalty_points'] ?? 0));
        $state['membership_label'] = $this->formatAccountMonthYear((string) ($customer['created_at'] ?? ''));
        $state['last_order_label'] = $orders !== []
            ? $this->formatAccountShortDate((string) ($orders[0]['created_at'] ?? ''))
            : '-';
        $state['full_name'] = $fullName;
        $state['email'] = $email;
        $state['phone'] = trim((string) ($customer['phone'] ?? ''));
        $state['avatar_initials'] = \App\Support\CustomerDisplay::initiale($firstName, $lastName, $email);

        $section = trim((string) ($_GET['section'] ?? 'profile'));
        if (!in_array($section, ['profile', 'orders', 'addresses', 'points', 'settings'], true)) {
            $section = 'profile';
        }
        $editRaw = trim((string) ($_GET['edit'] ?? ''));
        $profileEdit = $section === 'profile' && in_array(mb_strtolower($editRaw), ['1', 'true', 'da', 'yes'], true);
        $state['account_section'] = $section;
        $state['profile_edit'] = $profileEdit;

        return $state;
    }

    private function formatAccountMonthYear(string $rawDate): string
    {
        $timestamp = strtotime($rawDate);
        if ($timestamp === false) {
            return '-';
        }
        $months = [
            1 => 'Ianuarie',
            2 => 'Februarie',
            3 => 'Martie',
            4 => 'Aprilie',
            5 => 'Mai',
            6 => 'Iunie',
            7 => 'Iulie',
            8 => 'August',
            9 => 'Septembrie',
            10 => 'Octombrie',
            11 => 'Noiembrie',
            12 => 'Decembrie',
        ];
        $monthIndex = (int) date('n', $timestamp);
        $year = (int) date('Y', $timestamp);
        $month = $months[$monthIndex] ?? date('F', $timestamp);
        return $month . ' ' . $year;
    }

    private function formatAccountShortDate(string $rawDate): string
    {
        $timestamp = strtotime($rawDate);
        if ($timestamp === false) {
            return '-';
        }
        $months = [
            1 => 'Ian',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'Mai',
            6 => 'Iun',
            7 => 'Iul',
            8 => 'Aug',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Noi',
            12 => 'Dec',
        ];
        $day = (int) date('j', $timestamp);
        $monthIndex = (int) date('n', $timestamp);
        $month = $months[$monthIndex] ?? date('M', $timestamp);
        return $day . ' ' . $month;
    }

    /**
     * Magazinul oferă FANbox ȘI are puncte în nomenclator? Fără puncte,
     * opțiunea ar fi o promisiune goală în checkout.
     */
    private function fanboxDisponibil(): bool
    {
        $db = $this->db();
        if (!\App\Support\ShippingPricing::ofertaFanbox($this->cachedSettings($db))) {
            return false;
        }
        return \App\Support\FanLockers::numar($db) > 0;
    }

    /** Prețul de livrare la FANbox, când prețurile fixe sunt active. */
    private function fanboxPret(): ?float
    {
        $settings = $this->cachedSettings($this->db());
        return \App\Support\ShippingPricing::pretDeBaza($settings, true);
    }

    /**
     * Coșul a trecut deja de pragul de transport gratuit?
     *
     * Pragul se aplică la fel și la livrarea în FANbox — calculul o face
     * dintotdeauna —, dar eticheta opțiunii arăta tariful fix indiferent de
     * coș, iar clienții citeau de acolo că pragul nu se aplică la FANbox.
     */
    private function fanboxGratuit(array $summary): bool
    {
        $settings = $this->cachedSettings($this->db());
        if (!\App\Support\ShippingPricing::esteActiv($settings)) {
            return false;
        }
        return !$this->requiresFanLiveShippingCharge($settings, $summary);
    }

    private function renderCheckoutSection(array $summary, array $values, array $fanCounties, bool $isLoggedIn = false): string
    {
        return $this->renderPhpView('site/components/checkout-form', [
            'summary' => $summary,
            'values' => $values,
            'cardMethods' => $this->metodeCardActive(),
            'opActiv' => $this->opActiv(),
            'fanCounties' => $fanCounties,
            'localitiesEndpoint' => self::FAN_LOCALITIES_API_ENDPOINT,
            'shippingQuoteEndpoint' => self::CHECKOUT_SHIPPING_QUOTE_API_ENDPOINT,
            'isLoggedIn' => $isLoggedIn,
            'antiBot' => $this->issueCheckoutAntiBotPayload(),
            'fanboxDisponibil' => $this->fanboxDisponibil(),
            'fanboxAles' => \App\Support\CheckoutCalculator::fanboxAles(),
            'fanboxPret' => $this->fanboxPret(),
            'fanboxGratuit' => $this->fanboxGratuit($summary),
            'previewMode' => false,
            'checkoutInstanceId' => 'checkout-live',
        ]);
    }

    private function renderCartSection(
        array $summary,
        array $quantityUi,
        bool $isLoggedIn = false,
        bool $previewMode = false
    ): string
    {
        return $this->renderPhpView('site/cart', [
            'summary' => $summary,
            'quantityUi' => $quantityUi,
            'isLoggedIn' => $isLoggedIn,
            'previewMode' => $previewMode,
        ]);
    }

    private function buildCartRenderState(?PDO $db): array
    {
        $settings = Settings::all($db);
        $summary = CheckoutCalculator::buildSummary($db, $settings);
        if ($summary['coupon_error'] !== null && Cart::coupon() !== null) {
            Cart::clearCoupon();
            Flash::set('error', $summary['coupon_error']);
            $summary = CheckoutCalculator::buildSummary($db, $settings);
        }
        if (($summary['points']['error'] ?? null) !== null && Cart::pointsRequested() > 0) {
            Cart::clearPointsRequest();
            Flash::set('error', (string) ($summary['points']['error'] ?? 'Nu am putut aplica punctele.'));
            $summary = CheckoutCalculator::buildSummary($db, $settings);
        }
        return [
            'summary' => $summary,
            'quantity_ui' => [
                'style' => in_array((string) ($settings['store_quantity_control_style'] ?? 'default'), ['default', 'stepper'], true)
                    ? (string) ($settings['store_quantity_control_style'] ?? 'default')
                    : 'default',
                'apply_cart_page' => (string) ($settings['store_quantity_apply_cart_page'] ?? '0') === '1',
            ],
            'is_logged_in' => CustomerAuth::check(),
        ];
    }

    private function buildCheckoutRenderState(?PDO $db): array
    {
        if ($db instanceof PDO) {
            $this->ensureCustomerSchema($db);
        }
        $settings = Settings::all($db);
        $summary = CheckoutCalculator::buildSummary($db, $settings);
        if ($summary['coupon_error'] !== null && Cart::coupon() !== null) {
            Cart::clearCoupon();
            Flash::set('error', $summary['coupon_error']);
            $summary = CheckoutCalculator::buildSummary($db, $settings);
        }
        if (($summary['points']['error'] ?? null) !== null && Cart::pointsRequested() > 0) {
            Cart::clearPointsRequest();
            Flash::set('error', (string) ($summary['points']['error'] ?? 'Nu am putut aplica punctele.'));
            $summary = CheckoutCalculator::buildSummary($db, $settings);
        }

        $values = is_array($_SESSION['checkout_form'] ?? null) ? (array) $_SESSION['checkout_form'] : [];
        if ($db instanceof PDO) {
            $user = CustomerAuth::user($db);
            if (is_array($user)) {
                // Conturile făcute doar cu email + parolă au numele generic
                // „Client Nou". Precompletat în checkout, clientul trecea peste
                // el fără să observe și numele generic ajungea pe comandă (și
                // de acolo în ERP). Lăsăm câmpurile goale: sunt obligatorii,
                // deci își scrie numele adevărat.
                $numeGenerat = \App\Support\CustomerDisplay::esteNumeGenerat(
                    (string) ($user['first_name'] ?? ''),
                    (string) ($user['last_name'] ?? '')
                );
                $defaults = [
                    'billing_first_name' => $numeGenerat ? '' : (string) ($user['first_name'] ?? ''),
                    'billing_last_name' => $numeGenerat ? '' : (string) ($user['last_name'] ?? ''),
                    'billing_phone' => (string) ($user['phone'] ?? ''),
                    'billing_email' => (string) ($user['email'] ?? ''),
                ];
                foreach ($defaults as $key => $value) {
                    if (!isset($values[$key]) || trim((string) $values[$key]) === '') {
                        $values[$key] = $value;
                    }
                }
            }
        }

        $selectedCounty = trim((string) ($values['billing_county'] ?? ($summary['county'] ?? Cart::county())));
        if ($selectedCounty !== '') {
            $summary['county'] = $selectedCounty;
        }
        $pretFixActiv = \App\Support\ShippingPricing::esteActiv($settings);
        if ($pretFixActiv || (string) ($settings['fan_live_tariff_enabled'] ?? '0') === '1') {
            $previewBilling = [
                'billing_county' => $selectedCounty,
                'billing_city' => trim((string) ($values['billing_city'] ?? '')),
                'billing_street' => trim((string) ($values['billing_street'] ?? '')),
                'billing_street_no' => trim((string) ($values['billing_street_no'] ?? '')),
                'billing_postcode' => trim((string) ($values['billing_postcode'] ?? '')),
            ];
            // Tariful FAN are nevoie de adresa completă; prețul fix nu — el se
            // știe din prima, iar localitatea doar decide taxa de km suplimentari.
            $previewShipping =
                $pretFixActiv || $this->isFanShippingAddressComplete($previewBilling)
                    ? $this->resolveFanLiveShipping($settings, $summary, $previewBilling)
                    : null;
            $summary['shipping'] = $previewShipping ?? 0.0;
            $summary['total'] = max(
                0,
                (float) ($summary['subtotal'] ?? 0)
                    - (float) ($summary['discount'] ?? 0)
                    - (float) ($summary['points_discount'] ?? 0.0)
                    + (float) ($summary['shipping'] ?? 0.0)
                    + (float) ($summary['vat_additional'] ?? 0.0)
            );
        }

        return [
            'summary' => $summary,
            'values' => $values,
            'fan_counties' => $this->loadFanCounties($db),
            'is_logged_in' => CustomerAuth::check(),
        ];
    }

    private function loadFanCounties(?PDO $db): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        $this->ensureFanLocalitiesSchema($db);
        try {
            $stmt = $db->query('SELECT county FROM fan_localities GROUP BY county ORDER BY county ASC');
            $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
            $counties = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $county = trim((string) ($row['county'] ?? ''));
                if ($county !== '') {
                    $counties[] = $county;
                }
            }
            return array_values(array_unique($counties));
        } catch (Throwable) {
            return [];
        }
    }

    private function loadPublishedBlogPosts(?PDO $db, int $limit = 24, int $offset = 0): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        $this->ensureOptionalPageSchema($db);
        $safeLimit = max(1, min(60, $limit));
        $safeOffset = max(0, $offset);
        try {
            $stmt = $db->prepare(
                'SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.reading_minutes, p.published_at,
                        p.featured_image_url AS cover_image_url, COALESCE(a.name, "") AS author_name,
                        COALESCE(a.avatar_url, "") AS author_avatar_url
                 FROM blog_posts p
                 LEFT JOIN blog_authors a ON a.id = p.author_id
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()
                 ORDER BY p.published_at DESC, p.id DESC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            if (!is_array($rows)) {
                return [];
            }
            foreach ($rows as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['cover_image_url'] = trim((string) ($row['cover_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
                $published = trim((string) ($row['published_at'] ?? ''));
                $row['published_at_label'] = $published !== '' ? date('d.m.Y', strtotime($published) ?: time()) : '';
                $row['content_text'] = trim(strip_tags((string) ($row['content'] ?? '')));
            }
            unset($row);
            return array_values(array_filter($rows, static fn ($row): bool => is_array($row)));
        } catch (Throwable) {
            return [];
        }
    }

    private function countPublishedBlogPosts(?PDO $db): int
    {
        if (!$db instanceof PDO) {
            return 0;
        }
        $this->ensureOptionalPageSchema($db);
        try {
            $stmt = $db->query(
                'SELECT COUNT(*) FROM blog_posts p
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()'
            );
            return max(0, (int) ($stmt ? $stmt->fetchColumn() : 0));
        } catch (Throwable) {
            return 0;
        }
    }

    private function loadPublishedBlogPostBySlug(?PDO $db, string $slug): ?array
    {
        $slug = trim($slug);
        if (!$db instanceof PDO || $slug === '') {
            return null;
        }
        $this->ensureOptionalPageSchema($db);
        try {
            $stmt = $db->prepare(
                'SELECT p.*, p.featured_image_url AS cover_image_url,
                        COALESCE(a.name, "") AS author_name, COALESCE(a.slug, "") AS author_slug, COALESCE(a.bio, "") AS author_bio,
                        COALESCE(a.avatar_url, "") AS author_avatar_url,
                        COALESCE(t.name, "") AS template_name, t.html_content AS template_html_content, t.css_content AS template_css_content, t.js_content AS template_js_content
                 FROM blog_posts p
                 LEFT JOIN blog_authors a ON a.id = p.author_id
                 LEFT JOIN blog_templates t ON t.id = p.template_id
                 WHERE p.slug = :slug
                   AND p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()
                 LIMIT 1'
            );
            $stmt->execute(['slug' => $slug]);
            $row = $stmt->fetch() ?: null;
            if (!is_array($row)) {
                return null;
            }
            $row['cover_image_url'] = trim((string) ($row['cover_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
            $row['published_at_label'] = date('d.m.Y', strtotime((string) ($row['published_at'] ?? 'now')) ?: time());
            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    private function buildBlogTemplateRender(array $post): array
    {
        $title = trim((string) ($post['title'] ?? 'Articol blog'));
        $slug = trim((string) ($post['slug'] ?? ''));
        $content = (string) ($post['content'] ?? '');
        $contentHtml = trim($content) !== '' ? $content : '<p>Conținut indisponibil.</p>';
        $contentText = trim(strip_tags($contentHtml));
        $excerpt = trim((string) ($post['excerpt'] ?? ''));
        if ($excerpt === '' && $contentText !== '') {
            $excerpt = mb_substr($contentText, 0, 180) . (mb_strlen($contentText) > 180 ? '…' : '');
        }
        $image = trim((string) ($post['cover_image_url'] ?? ''));
        if ($image === '') {
            $image = '/assets/img/product-placeholder.svg';
        }
        $authorName = trim((string) ($post['author_name'] ?? 'Bioscem'));
        $authorInitials = mb_strtoupper(mb_substr($authorName !== '' ? $authorName : 'B', 0, 1));
        $blogCategory = trim((string) ($post['category'] ?? 'Jurnal Bioscem'));
        $authorSlug = trim((string) ($post['author_slug'] ?? ''));
        $publishedAt = trim((string) ($post['published_at'] ?? ''));
        $publishedDate = $publishedAt !== '' ? date('d.m.Y', strtotime($publishedAt) ?: time()) : date('d.m.Y');
        $readingMinutes = max(1, (int) ($post['reading_minutes'] ?? 1));
        $readingLabel = $readingMinutes . ' min';
        $postUrl = $slug !== '' ? '/blog/' . rawurlencode($slug) : '/blog';
        $authorUrl = $authorSlug !== '' ? '/blog?autor=' . rawurlencode($authorSlug) : '/blog';
        $nowDate = date('d.m.Y');
        $nowYear = date('Y');

        $eventStartRaw = trim((string) ($post['event_start_date'] ?? ''));
        $eventEndRaw = trim((string) ($post['event_end_date'] ?? ''));
        $eventStartLabel = $eventStartRaw !== '' ? date('d.m.Y', strtotime($eventStartRaw) ?: time()) : '';
        $eventEndLabel = $eventEndRaw !== '' ? date('d.m.Y', strtotime($eventEndRaw) ?: time()) : '';
        if ($eventStartLabel !== '' && $eventEndLabel !== '' && $eventStartLabel !== $eventEndLabel) {
            $eventPeriod = $eventStartLabel . ' – ' . $eventEndLabel;
        } else {
            $eventPeriod = $eventStartLabel;
        }
        $eventPrice = trim((string) ($post['event_price'] ?? ''));
        $eventTicketUrl = trim((string) ($post['event_ticket_url'] ?? ''));
        $eventLocation = trim((string) ($post['event_location'] ?? ''));
        $videoUrl = trim((string) ($post['video_url'] ?? ''));
        $videoEmbed = $videoUrl !== ''
            ? '<video controls preload="metadata" style="width:100%;height:auto;border-radius:14px;display:block;"><source src="' . htmlspecialchars($videoUrl, ENT_QUOTES) . '" type="video/mp4">Browserul tău nu suportă redarea video.</video>'
            : '';

        $templateHtml = trim((string) ($post['template_html_content'] ?? ''));
        if ($templateHtml === '') {
            $templateHtml = <<<HTML
<article class="blog-article-default">
    <header class="blog-article-default__hero">
        <span class="blog-article-default__category">{{blog_category}}</span>
        <h1>{{blog_title}}</h1>
        <div class="blog-article-default__meta-bar">
            <span class="blog-article-default__author-name">{{blog_author_name}}</span>
            <span class="blog-article-default__meta-item">{{blog_published_date}}</span>
            <span class="blog-article-default__meta-item">{{blog_reading_label}}</span>
            <span class="blog-article-default__share">Distribuie</span>
        </div>
    </header>
    <figure class="blog-article-default__cover">
        <img src="{{blog_image_url}}" alt="{{blog_title}}">
    </figure>
    <section class="blog-article-default__content">
        {{blog_content_html}}
    </section>
</article>
HTML;
        }
        $templateCss = trim((string) ($post['template_css_content'] ?? ''));
        if ($templateCss === '') {
            $templateCss = <<<CSS
.blog-article-default{max-width:860px;margin:0 auto;padding:8px 0 30px;}
.blog-article-default__hero{margin:0 0 16px;}
.blog-article-default__category{display:inline-flex;margin:0 0 10px;padding:4px 10px;border-radius:999px;background:#e8f7f1;color:#0f7b53;font-family:"DM Sans",Arial,sans-serif;font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;}
.blog-article-default__hero h1{margin:0 0 10px;font-family:"Playfair Display",Georgia,serif;font-size:48px;line-height:1.08;font-weight:700;color:#0f172a;}
.blog-article-default__meta-bar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;color:#64748b;}
.blog-article-default__meta-bar .blog-article-default__author-name{font-family:"DM Sans",Arial,sans-serif;font-size:14px;font-weight:600;color:#0f172a;}
.blog-article-default__meta-bar .blog-article-default__meta-item{font-family:"DM Sans",Arial,sans-serif;font-size:12px;font-weight:400;color:#64748b;}
.blog-article-default__meta-bar .blog-article-default__meta-item::before{content:"•";margin:0 8px 0 2px;color:#94a3b8;}
.blog-article-default__meta-bar .blog-article-default__share{margin-left:auto;font-family:"DM Sans",Arial,sans-serif;font-size:14px;font-weight:400;color:#64748b;}
.blog-article-default__cover{margin:0 0 16px;border-radius:20px;overflow:hidden;background:#f8fafc;}
.blog-article-default__cover img{display:block;width:100%;height:auto;object-fit:cover;}
.blog-article-default__content{color:#475569;font-family:"DM Sans",Arial,sans-serif;font-size:18px;line-height:1.75;}
.blog-article-default__content p{margin:0 0 20px;}
.blog-article-default__content h2{margin:40px 0 16px;font-family:"Playfair Display",Georgia,serif;font-size:24px;line-height:1.2;font-weight:700;color:#0f172a;}
.blog-article-default__content h3{margin:32px 0 12px;font-family:"Playfair Display",Georgia,serif;font-size:20px;line-height:1.25;font-weight:700;color:#0f172a;}
.blog-article-default__content strong,.blog-article-default__content b{font-weight:600;color:#0f172a;}
.blog-article-default__content a{color:#0f7b53;text-decoration:none;}
.blog-article-default__content a:hover{text-decoration:underline;}
@media (max-width:1024px){.blog-article-default__hero h1{font-size:36px;}}
@media (max-width:720px){.blog-article-default{max-width:90%;}.blog-article-default__hero h1{font-size:30px;}.blog-article-default__meta-bar .blog-article-default__share{width:100%;margin-left:0;}}
CSS;
        }

        $templateJs = (string) ($post['template_js_content'] ?? '');
        $tokens = [
            '{{blog_title}}' => $title,
            '{{blog_slug}}' => $slug,
            '{{blog_excerpt}}' => $excerpt,
            '{{blog_content}}' => $contentText,
            '{{blog_content_html}}' => $contentHtml,
            '{{blog_image_url}}' => $image,
            '{{blog_category}}' => $blogCategory,
            '{{blog_published_date}}' => $publishedDate,
            '{{blog_published_date_raw}}' => $publishedAt,
            '{{blog_reading_minutes}}' => (string) $readingMinutes,
            '{{blog_reading_label}}' => $readingLabel,
            '{{blog_author_name}}' => $authorName,
            '{{blog_author_initials}}' => $authorInitials,
            '{{blog_author_bio}}' => trim((string) ($post['author_bio'] ?? '')),
            '{{blog_author_avatar}}' => trim((string) ($post['author_avatar_url'] ?? '')),
            '{{blog_author_url}}' => $authorUrl,
            '{{blog_post_url}}' => $postUrl,
            '{{blog_now_date}}' => $nowDate,
            '{{blog_now_year}}' => $nowYear,
            '{{blog_event_start_date}}' => $eventStartLabel,
            '{{blog_event_end_date}}' => $eventEndLabel,
            '{{blog_event_period}}' => $eventPeriod,
            '{{blog_event_price}}' => $eventPrice,
            '{{blog_event_ticket_url}}' => $eventTicketUrl,
            '{{blog_event_location}}' => $eventLocation,
            '{{blog_video_url}}' => $videoUrl,
            '{{blog_video}}' => $videoEmbed,
        ];

        $renderHtml = $templateHtml;
        $renderCss = $templateCss;
        $renderJs = $templateJs;
        foreach ($tokens as $needle => $value) {
            $renderHtml = str_replace($needle, $value, $renderHtml);
            $renderCss = str_replace($needle, $value, $renderCss);
            $renderJs = str_replace($needle, $value, $renderJs);
        }

        return [
            'html' => $renderHtml,
            'css' => $renderCss,
            'js' => $renderJs,
        ];
    }

    private function loadShopCatalogCategories(?PDO $db): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        $counts = [];
        try {
            $stmt = $db->query(
                'SELECT TRIM(category) AS category_name, COUNT(*) AS products_count
                 FROM products
                 WHERE deleted_at IS NULL
                   AND TRIM(COALESCE(category, "")) <> ""
                 GROUP BY TRIM(category)'
            );
            $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['category_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $key = mb_strtolower($name);
                $counts[$key] = [
                    'value' => $name,
                    'label' => $name,
                    'count' => max(0, (int) ($row['products_count'] ?? 0)),
                ];
            }
            // Produsele legate suplimentar se adaugă la numărătoarea categoriei.
            try {
                $extraStmt = $db->query(
                    'SELECT TRIM(pc.name) AS category_name, COUNT(*) AS products_count
                     FROM product_category_links l
                     INNER JOIN product_categories pc ON pc.id = l.category_id
                     INNER JOIN products p ON p.id = l.product_id AND p.deleted_at IS NULL
                     WHERE TRIM(COALESCE(pc.name, "")) <> ""
                       AND LOWER(TRIM(COALESCE(p.category, ""))) <> LOWER(TRIM(pc.name))
                     GROUP BY TRIM(pc.name)'
                );
                foreach (($extraStmt ? ($extraStmt->fetchAll() ?: []) : []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $name = trim((string) ($row['category_name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $key = mb_strtolower($name);
                    if (!isset($counts[$key])) {
                        $counts[$key] = ['value' => $name, 'label' => $name, 'count' => 0];
                    }
                    $counts[$key]['count'] += max(0, (int) ($row['products_count'] ?? 0));
                }
            } catch (Throwable) {
                // Fără tabela de legături, numărătoarea rămâne pe categoria principală.
            }
        } catch (Throwable) {
        }

        try {
            $stmt = $db->query('SELECT name FROM product_categories ORDER BY name ASC');
            $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $key = mb_strtolower($name);
                if (isset($counts[$key])) {
                    continue;
                }
                $counts[$key] = [
                    'value' => $name,
                    'label' => $name,
                    'count' => 0,
                ];
            }
        } catch (Throwable) {
        }

        $categories = array_values($counts);
        usort($categories, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });
        return $categories;
    }

    private function buildShopCatalogCategoriesFromProducts(array $products): array
    {
        $counts = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $name = trim((string) ($product['category'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (!isset($counts[$key])) {
                $counts[$key] = [
                    'value' => $name,
                    'label' => $name,
                    'count' => 0,
                ];
            }
            $counts[$key]['count'] = (int) ($counts[$key]['count'] ?? 0) + 1;
        }

        $categories = array_values($counts);
        usort($categories, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });
        return $categories;
    }

    private function normalizeShopCategoryFilter(string $categoryFilter, array $categories): string
    {
        $categoryFilter = trim($categoryFilter);
        if ($categoryFilter === '') {
            return '';
        }
        $filterKey = mb_strtolower($categoryFilter);
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $value = trim((string) ($category['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if (mb_strtolower($value) === $filterKey) {
                return $value;
            }
        }

        return '';
    }

    private function normalizeShopCatalogSort(string $sort): string
    {
        $sort = trim(strtolower($sort));
        return match ($sort) {
            'alpha_asc', 'featured', 'price_asc', 'price_desc' => $sort,
            default => 'alpha_asc',
        };
    }

    private function shopCatalogSortOptions(): array
    {
        return [
            'alpha_asc' => 'Alfabetică',
            'featured' => 'Popularitate',
            'price_asc' => 'Preț: mic → mare',
            'price_desc' => 'Preț: mare → mic',
        ];
    }

    private function applyShopCatalogSort(array $products, string $sort): array
    {
        $sort = $this->normalizeShopCatalogSort($sort);
        usort($products, static function (array $left, array $right) use ($sort): int {
            $leftId = (int) ($left['id'] ?? 0);
            $rightId = (int) ($right['id'] ?? 0);
            $leftPrice = (float) ($left['price'] ?? 0.0);
            $rightPrice = (float) ($right['price'] ?? 0.0);
            $leftReviews = (int) ($left['reviews_count'] ?? 0);
            $rightReviews = (int) ($right['reviews_count'] ?? 0);
            $leftSold = (int) ($left['sold_qty'] ?? 0);
            $rightSold = (int) ($right['sold_qty'] ?? 0);
            $leftName = trim((string) ($left['name'] ?? ''));
            $rightName = trim((string) ($right['name'] ?? ''));
            $leftBest = (int) ($left['badge_best_seller'] ?? $left['label_best_seller'] ?? 0);
            $rightBest = (int) ($right['badge_best_seller'] ?? $right['label_best_seller'] ?? 0);
            $leftPopular = (int) ($left['badge_popular'] ?? $left['label_popular'] ?? 0);
            $rightPopular = (int) ($right['badge_popular'] ?? $right['label_popular'] ?? 0);

            if ($sort === 'alpha_asc') {
                $cmp = strcasecmp($leftName, $rightName);
                return $cmp !== 0 ? $cmp : ($rightId <=> $leftId);
            }
            if ($sort === 'price_asc') {
                $cmp = $leftPrice <=> $rightPrice;
                return $cmp !== 0 ? $cmp : ($rightId <=> $leftId);
            }
            if ($sort === 'price_desc') {
                $cmp = $rightPrice <=> $leftPrice;
                return $cmp !== 0 ? $cmp : ($rightId <=> $leftId);
            }
            $cmp = $rightSold <=> $leftSold;
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $rightBest <=> $leftBest;
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $rightPopular <=> $leftPopular;
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $rightReviews <=> $leftReviews;
            return $cmp !== 0 ? $cmp : ($rightId <=> $leftId);
        });

        return $products;
    }

    private function enrichShopCatalogProducts(?PDO $db, array $products): array
    {
        if ($products === []) {
            return [];
        }

        $ids = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $id = (int) ($product['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return $products;
        }

        $reviewMap = $this->loadShopCatalogReviewMap($db, $ids);
        $soldMap = $this->loadShopCatalogSoldQtyMap($db, $ids);

        foreach ($products as &$product) {
            if (!is_array($product)) {
                continue;
            }
            $id = (int) ($product['id'] ?? 0);
            $reviewStats = $reviewMap[$id] ?? ['count' => 0, 'average' => 0.0];
            $product['reviews_count'] = max(0, (int) ($reviewStats['count'] ?? 0));
            $product['reviews_average'] = max(0.0, min(5.0, (float) ($reviewStats['average'] ?? 0.0)));
            $product['sold_qty'] = max(0, (int) ($soldMap[$id] ?? 0));
        }
        unset($product);

        return $products;
    }

    private function loadShopCatalogReviewMap(?PDO $db, array $productIds): array
    {
        if (!$db instanceof PDO || $productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $params = $productIds;

        try {
            $stmt = $db->prepare(
                "SELECT product_id, COUNT(*) AS reviews_count, AVG(rating) AS reviews_average
                 FROM product_reviews
                 WHERE is_approved = 1
                   AND product_id IN ($placeholders)
                 GROUP BY product_id"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            try {
                $stmt = $db->prepare(
                    "SELECT product_id, COUNT(*) AS reviews_count, AVG(rating) AS reviews_average
                     FROM reviews
                     WHERE is_approved = 1
                       AND product_id IN ($placeholders)
                     GROUP BY product_id"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll() ?: [];
            } catch (Throwable) {
                return [];
            }
        }

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $map[$productId] = [
                'count' => max(0, (int) ($row['reviews_count'] ?? 0)),
                'average' => max(0.0, min(5.0, (float) ($row['reviews_average'] ?? 0.0))),
            ];
        }

        return $map;
    }

    private function loadShopCatalogSoldQtyMap(?PDO $db, array $productIds): array
    {
        if (!$db instanceof PDO || $productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        try {
            $stmt = $db->prepare(
                "SELECT oi.product_id, SUM(oi.quantity) AS sold_qty
                 FROM order_items oi
                 INNER JOIN orders o ON o.id = oi.order_id
                 WHERE o.status = 'completed'
                   AND oi.product_id IN ($placeholders)
                 GROUP BY oi.product_id"
            );
            $stmt->execute($productIds);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $map[$productId] = max(0, (int) ($row['sold_qty'] ?? 0));
        }

        return $map;
    }

    private function loadProducts(int $limit = 0, string $categoryFilter = ''): array
    {
        $db = $this->db();
        $categoryFilter = trim($categoryFilter);

        if ($db instanceof PDO) {
            $this->ensureProductCustomSchema($db);
            \App\Support\ProductCategories::ensureSchema($db);
            $limitSql = $limit > 0 ? (' LIMIT ' . $limit) : '';
            $whereSql = 'deleted_at IS NULL';
            $params = [];
            if ($categoryFilter !== '') {
                // Categoria principală sau oricare dintre categoriile suplimentare.
                $whereSql .= ' AND (LOWER(TRIM(COALESCE(category, ""))) = LOWER(TRIM(:category_filter))'
                    . ' OR id IN (SELECT l.product_id FROM product_category_links l'
                    . ' INNER JOIN product_categories pc ON pc.id = l.category_id'
                    . ' WHERE LOWER(TRIM(pc.name)) = LOWER(TRIM(:category_filter2))))';
                $params['category_filter'] = $categoryFilter;
                $params['category_filter2'] = $categoryFilter;
            }
            try {
                $sql = 'SELECT id, name, slug, short_description, product_highlights, category, price, sale_price, sale_price_periods_json, discount_badge_mode, bbd_enabled, bbd_entries_json, post_cart_note_enabled, post_cart_note_text, out_of_stock, image_url, gallery_images_json, similar_products_json, badge_popular, badge_best_seller, badge_seasonal FROM products WHERE ' . $whereSql . ' ORDER BY id DESC' . $limitSql;
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
            } catch (Throwable) {
                try {
                    $sql = 'SELECT id, name, slug, short_description, NULL AS product_highlights, category, price, sale_price, NULL AS sale_price_periods_json, 0 AS bbd_enabled, NULL AS bbd_entries_json, 0 AS post_cart_note_enabled, NULL AS post_cart_note_text, 0 AS out_of_stock, image_url, gallery_json AS gallery_images_json, similar_products_json, label_popular AS badge_popular, label_best_seller AS badge_best_seller, label_seasonal AS badge_seasonal FROM products WHERE ' . $whereSql . ' ORDER BY id DESC' . $limitSql;
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $rows = $stmt->fetchAll();
                } catch (Throwable) {
                    $sql = 'SELECT id, name, slug, short_description, NULL AS product_highlights, NULL AS category, price, NULL AS sale_price, NULL AS sale_price_periods_json, 0 AS bbd_enabled, NULL AS bbd_entries_json, 0 AS post_cart_note_enabled, NULL AS post_cart_note_text, 0 AS out_of_stock, image_url FROM products WHERE deleted_at IS NULL ORDER BY id DESC' . $limitSql;
                    $stmt = $db->prepare($sql);
                    $stmt->execute([]);
                    $rows = $stmt->fetchAll();
                }
            }

            $rows = is_array($rows) ? $rows : [];
            $rows = array_map(fn (array $row): array => $this->normalizeProduct($row), $rows);
            // Numele categoriilor suplimentare, pentru filtrarea din catalog.
            $extraNume = \App\Support\ProductCategories::namesForProducts(
                $db,
                array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows)
            );
            foreach ($rows as &$row) {
                $row['extra_categories'] = $extraNume[(int) ($row['id'] ?? 0)] ?? [];
            }
            unset($row);
            // Cu ERP-ul conectat, disponibilitatea vine din gestiune — pentru toate
            // listele construite din aici (acasă, produse similare, widget-uri).
            \App\Support\ErpStock::applyToProducts($db, $rows);
            return [$rows, $db];
        }

        $fallback = [
            [
                'id' => 1,
                'name' => 'Colagen Premium',
                'slug' => 'colagen-premium',
                'short_description' => 'Supliment pentru articulații și piele.',
                'price' => 149.00,
                'image_url' => '/assets/img/product-placeholder.svg',
            ],
            [
                'id' => 2,
                'name' => 'Vitamina C 1000',
                'slug' => 'vitamina-c-1000',
                'short_description' => 'Suport imunitar zilnic.',
                'price' => 59.00,
                'image_url' => '/assets/img/product-placeholder.svg',
            ],
        ];

        if ($categoryFilter !== '') {
            $categoryFilterLc = mb_strtolower($categoryFilter);
            $fallback = array_values(array_filter($fallback, static function (array $item) use ($categoryFilterLc): bool {
                return mb_strtolower(trim((string) ($item['category'] ?? ''))) === $categoryFilterLc;
            }));
        }
        if ($limit > 0) {
            $fallback = array_slice($fallback, 0, $limit);
        }

        return [$fallback, null];
    }

    private function db(): ?PDO
    {
        $config = require __DIR__ . '/../../../config/app.php';
        return Database::connection($config['db']);
    }

    private function findProductById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            [$fallbackProducts] = $this->loadProducts();
            foreach ($fallbackProducts as $product) {
                if ((int) ($product['id'] ?? 0) === $id) {
                    return $product;
                }
            }

            return null;
        }

        $stmt = $db->prepare('SELECT id, name, slug, stock, out_of_stock, bbd_enabled, bbd_entries_json FROM products WHERE id = :id AND is_active = 1 AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch() ?: null;
        if (!is_array($product)) {
            return null;
        }

        $product = $this->normalizeProduct($product);
        // Disponibilitatea reală vine din gestiunea ERP (stoc minus rezervări).
        $product = \App\Support\ErpStock::applyToProduct($db, $product);
        $product['bbd_entries'] = $this->decorateProductBbdEntriesWithAvailability($product);
        return $product;
    }

    private function buildFloatingCartPayload(?PDO $db): array
    {
        $settings = Settings::all($db);
        $summary = CheckoutCalculator::buildSummary($db, $settings);
        $loyaltyConfig = LoyaltyService::config($settings);
        $eligibleAmount = max(
            0.0,
            (float) ($summary['subtotal'] ?? 0.0)
                - (float) ($summary['discount'] ?? 0.0)
                - (float) ($summary['points_discount'] ?? 0.0)
        );
        $earnedPoints = LoyaltyService::estimateEarnPoints($settings, $eligibleAmount);

        $productMap = [];
        $cartIds = array_map(static fn (array $line): int => (int) ($line['id'] ?? 0), (array) ($summary['lines'] ?? []));
        $safeIds = array_values(array_filter($cartIds, static fn (int $id): bool => $id > 0));
        if ($safeIds !== [] && $db instanceof PDO) {
            $placeholders = implode(',', array_fill(0, count($safeIds), '?'));
            $stmt = $db->prepare(
                "SELECT id, image_url, short_description, vat_percent, vat_included
                 FROM products
                 WHERE id IN ($placeholders)"
            );
            $stmt->execute($safeIds);
            foreach ($stmt->fetchAll() as $productRow) {
                if (!is_array($productRow)) {
                    continue;
                }
                $productMap[(int) ($productRow['id'] ?? 0)] = [
                    'image_url' => (string) ($productRow['image_url'] ?? ''),
                    'short_description' => trim((string) ($productRow['short_description'] ?? '')),
                    'vat_percent' => (float) ($productRow['vat_percent'] ?? 19.0),
                    'vat_included' => (int) ($productRow['vat_included'] ?? 1) === 1,
                ];
            }
        }

        $isBucharest = strtolower(trim((string) ($summary['county'] ?? ''))) === 'bucuresti';
        $includeCouponsForShipping = ((string) ($settings['shipping_include_coupons'] ?? '1')) === '1';
        $floatingCartFreeShippingThresholdRaw = (float) str_replace(',', '.', (string) ($settings['floating_cart_free_shipping_threshold'] ?? '0'));
        $shippingFreeThreshold = $floatingCartFreeShippingThresholdRaw > 0
            ? $floatingCartFreeShippingThresholdRaw
            : (
                $isBucharest
                    ? (float) ($settings['shipping_free_bucharest'] ?? 200)
                    : (float) ($settings['shipping_free_province'] ?? 200)
            );
        $shippingReference = $includeCouponsForShipping
            ? max(
                0.0,
                (float) ($summary['subtotal'] ?? 0.0)
                    - (float) ($summary['discount'] ?? 0.0)
                    - (float) ($summary['points_discount'] ?? 0.0)
            )
            : max(0.0, (float) ($summary['subtotal'] ?? 0.0));
        $shippingRemainingForFree = max(0.0, $shippingFreeThreshold - $shippingReference);

        $lines = [];
        $vatTotal = 0.0;
        foreach ((array) ($summary['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $id = (int) ($line['id'] ?? 0);
            $productMeta = is_array($productMap[$id] ?? null) ? $productMap[$id] : [];
            $lineTotal = (float) ($line['line_total'] ?? 0);
            $vatPercent = max(0.0, min(100.0, (float) ($productMeta['vat_percent'] ?? 19.0)));
            $vatIncluded = (bool) ($productMeta['vat_included'] ?? true);
            $vatValue = $vatPercent > 0.0
                ? (
                    $vatIncluded
                        ? ($lineTotal - ($lineTotal / (1.0 + ($vatPercent / 100.0))))
                        : ($lineTotal * ($vatPercent / 100.0))
                )
                : 0.0;
            $vatTotal += $vatValue;
            $cartItemKey = (string) ($line['cart_item_key'] ?? Cart::itemKey($id, (string) ($line['bbd_key'] ?? '')));
            $lineCouponDiscount = max(0.0, (float) ($line['coupon_discount'] ?? 0.0));
            $lineTotalAfterCoupon = max(
                0.0,
                (float) ($line['line_total_after_coupon'] ?? ($lineTotal - $lineCouponDiscount))
            );
            $lines[] = [
                'id' => $cartItemKey,
                'item_key' => $cartItemKey,
                'product_id' => $id,
                'name' => (string) ($line['name'] ?? ''),
                'slug' => (string) ($line['slug'] ?? ''),
                'url' => '/produs/' . rawurlencode((string) ($line['slug'] ?? '')),
                'image_url' => (string) ($productMeta['image_url'] ?? '/assets/img/product-placeholder.svg'),
                'short_description' => (string) ($productMeta['short_description'] ?? ''),
                'bbd_label' => trim((string) ($line['bbd_label'] ?? '')),
                'quantity' => (int) ($line['quantity'] ?? 1),
                'price' => (float) ($line['price'] ?? 0),
                'line_total' => $lineTotal,
                'coupon_discount' => $lineCouponDiscount,
                'line_total_after_coupon' => $lineTotalAfterCoupon,
                'vat_percent' => $vatPercent,
                'vat_included' => $vatIncluded,
                'vat_value' => $vatValue,
            ];
        }

        return [
            'ok' => true,
            'items_count' => Cart::countItems(),
            'lines' => $lines,
            'subtotal' => (float) ($summary['subtotal'] ?? 0),
            'subtotal_without_vat' => (float) ($summary['subtotal_without_vat'] ?? ($summary['subtotal'] ?? 0)),
            'discount' => (float) ($summary['discount'] ?? 0),
            'points_discount' => (float) ($summary['points_discount'] ?? 0),
            'shipping' => (float) ($summary['shipping'] ?? 0),
            'vat_total' => $vatTotal,
            'total' => (float) ($summary['total'] ?? 0),
            'coupon' => is_array($summary['coupon'] ?? null) ? [
                'code' => (string) (($summary['coupon']['code'] ?? '')),
                'type' => (string) (($summary['coupon']['type'] ?? '')),
                'value' => (float) (($summary['coupon']['value'] ?? 0.0)),
                'applies_only_selected_products' => ((int) (($summary['coupon']['applies_only_selected_products'] ?? 0))) === 1,
                'eligible_subtotal' => (float) (($summary['coupon']['eligible_subtotal'] ?? 0.0)),
            ] : null,
            'coupon_error' => trim((string) ($summary['coupon_error'] ?? '')),
            'points' => [
                'available' => (int) ($summary['points']['available'] ?? 0),
                'requested' => (int) ($summary['points']['requested'] ?? 0),
                'applied' => (int) ($summary['points']['applied'] ?? 0),
                'error' => (string) ($summary['points']['error'] ?? ''),
            ],
            'estimated_earned_points' => $earnedPoints,
            'shipping_free_threshold' => $shippingFreeThreshold,
            'shipping_remaining_for_free' => $shippingRemainingForFree,
            'checkout_url' => '/checkout',
            'cart_url' => '/cos',
            'continue_shopping_url' => '/magazin',
        ];
    }

    private function requestPayload(): array
    {
        $payload = $_POST;
        if ($payload !== [] && is_array($payload)) {
            return $payload;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function loadBestSellersFromCompletedOrders(int $limit = 4): array
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            return [];
        }
        CheckoutCalculator::ensureProductVatSchema($db);

        $limitSql = max(1, min(24, $limit));
        $queryCandidates = [
            'SELECT p.id, p.name, p.slug, p.short_description, p.category, p.price, p.sale_price, p.sale_price_periods_json, p.discount_badge_mode, p.out_of_stock, p.image_url,
                    COALESCE(r.reviews_count, 0) AS reviews_count,
                    COALESCE(r.reviews_average, 0) AS reviews_average,
                    SUM(oi.quantity) AS sold_qty
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             INNER JOIN products p ON p.id = oi.product_id
             LEFT JOIN (
                SELECT product_id, COUNT(*) AS reviews_count, AVG(rating) AS reviews_average
                FROM product_reviews
                WHERE is_approved = 1
                GROUP BY product_id
             ) r ON r.product_id = p.id
             WHERE o.status = "completed"
               AND p.deleted_at IS NULL
             GROUP BY p.id, p.name, p.slug, p.short_description, p.category, p.price, p.sale_price, p.sale_price_periods_json, p.discount_badge_mode, p.out_of_stock, p.image_url, r.reviews_count, r.reviews_average
             ORDER BY sold_qty DESC, p.id DESC
             LIMIT ' . $limitSql,
            'SELECT p.id, p.name, p.slug, p.short_description, p.category, p.price, p.sale_price, p.sale_price_periods_json, p.discount_badge_mode, p.out_of_stock, p.image_url,
                    COALESCE(r.reviews_count, 0) AS reviews_count,
                    COALESCE(r.reviews_average, 0) AS reviews_average,
                    SUM(oi.quantity) AS sold_qty
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             INNER JOIN products p ON p.id = oi.product_id
             LEFT JOIN (
                SELECT product_id, COUNT(*) AS reviews_count, AVG(rating) AS reviews_average
                FROM reviews
                WHERE is_approved = 1
                GROUP BY product_id
             ) r ON r.product_id = p.id
             WHERE o.status = "completed"
               AND p.deleted_at IS NULL
             GROUP BY p.id, p.name, p.slug, p.short_description, p.category, p.price, p.sale_price, p.sale_price_periods_json, p.discount_badge_mode, p.out_of_stock, p.image_url, r.reviews_count, r.reviews_average
             ORDER BY sold_qty DESC, p.id DESC
             LIMIT ' . $limitSql,
            'SELECT p.id, p.name, p.slug, p.short_description, p.category, p.price, p.sale_price, NULL AS sale_price_periods_json, 0 AS out_of_stock, p.image_url,
                    COALESCE(r.reviews_count, 0) AS reviews_count,
                    COALESCE(r.reviews_average, 0) AS reviews_average,
                    SUM(oi.quantity) AS sold_qty
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             INNER JOIN products p ON p.id = oi.product_id
             LEFT JOIN (
                SELECT product_id, COUNT(*) AS reviews_count, AVG(rating) AS reviews_average
                FROM product_reviews
                WHERE is_approved = 1
                GROUP BY product_id
             ) r ON r.product_id = p.id
             WHERE o.status = "completed"
               AND p.deleted_at IS NULL
             GROUP BY p.id, p.name, p.slug, p.short_description, p.category, p.price, p.sale_price, p.image_url, r.reviews_count, r.reviews_average
             ORDER BY sold_qty DESC, p.id DESC
             LIMIT ' . $limitSql,
            'SELECT p.id, p.name, p.slug, p.short_description, p.category, p.price, p.sale_price, NULL AS sale_price_periods_json, 0 AS out_of_stock, p.image_url,
                    COALESCE(r.reviews_count, 0) AS reviews_count,
                    COALESCE(r.reviews_average, 0) AS reviews_average,
                    SUM(oi.quantity) AS sold_qty
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             INNER JOIN products p ON p.id = oi.product_id
             LEFT JOIN (
                SELECT product_id, COUNT(*) AS reviews_count, AVG(rating) AS reviews_average
                FROM reviews
                WHERE is_approved = 1
                GROUP BY product_id
             ) r ON r.product_id = p.id
             WHERE o.status = "completed"
               AND p.deleted_at IS NULL
             GROUP BY p.id, p.name, p.slug, p.short_description, p.category, p.price, p.sale_price, p.image_url, r.reviews_count, r.reviews_average
             ORDER BY sold_qty DESC, p.id DESC
             LIMIT ' . $limitSql,
        ];
        $stmt = false;
        foreach ($queryCandidates as $sql) {
            try {
                $stmt = $db->query($sql);
                if ($stmt) {
                    break;
                }
            } catch (Throwable) {
                $stmt = false;
            }
        }

        if (!$stmt) {
            return [];
        }

        $rows = $stmt->fetchAll() ?: [];
        \App\Support\ErpStock::applyToProducts($db, $rows);
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeProduct($row);
            $slug = trim((string) ($normalized['slug'] ?? ''));
            $price = max(0.0, (float) ($normalized['price'] ?? 0.0));
            $basePrice = max(0.0, (float) ($normalized['base_price'] ?? $price));
            $hasSalePrice = (bool) ($normalized['has_sale_price'] ?? false)
                && $price > 0.0
                && $basePrice > $price;
            $items[] = [
                'id' => (int) ($normalized['id'] ?? 0),
                'name' => (string) ($normalized['name'] ?? 'Produs'),
                'slug' => $slug,
                'url' => $slug !== '' ? ('/produs/' . rawurlencode($slug)) : '/magazin',
                'category' => trim((string) ($normalized['category'] ?? '')),
                'short_description' => trim((string) ($normalized['short_description'] ?? '')),
                'image_url' => (string) ($normalized['image_url'] ?? '/assets/img/product-placeholder.svg'),
                'price' => $price,
                'sale_price' => $hasSalePrice ? $price : null,
                'regular_price' => $basePrice,
                'base_price' => $basePrice,
                'has_sale_price' => $hasSalePrice ? 1 : 0,
                'discount_badge_mode' => (string) ($normalized['discount_badge_mode'] ?? 'percent') === 'value' ? 'value' : 'percent',
                'discount_value_label' => $hasSalePrice ? rtrim(rtrim(number_format($basePrice - $price, 2, '.', ''), '0'), '.') : '',
                'price_label' => number_format($price, 2) . ' lei',
                'regular_price_label' => $hasSalePrice ? number_format($basePrice, 2) . ' lei' : '',
                'reviews_count' => max(0, (int) ($normalized['reviews_count'] ?? 0)),
                'reviews_average' => max(0.0, min(5.0, (float) ($normalized['reviews_average'] ?? 0.0))),
                'sold_qty' => max(0, (int) ($normalized['sold_qty'] ?? 0)),
                'out_of_stock' => (int) ($normalized['out_of_stock'] ?? 0) === 1 ? 1 : 0,
            ];
        }

        return $items;
    }

    private function jsonResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Metodele de plată online: comanda se confirmă abia după încasare. */
    private const METODE_CARD = ['stripe', 'euplatesc'];

    /**
     * Metodele de card oferite în checkout, în ordinea preferinței. EuPlătesc
     * e procesatorul implicit; Stripe apare doar dacă e bifat explicit.
     *
     * @return string[]
     */
    /** Plata prin ordin de plată (transfer bancar), pornită din Setări plăți. */
    public function opActiv(?array $settings = null): bool
    {
        if ($settings === null) {
            $db = $this->db();
            $settings = $db instanceof PDO ? $this->cachedSettings($db) : [];
        }
        return (string) ($settings['bank_transfer_enabled'] ?? '0') === '1';
    }

    public function metodeCardActive(?array $settings = null): array
    {
        if ($settings === null) {
            $db = $this->db();
            $settings = $db instanceof PDO ? $this->cachedSettings($db) : [];
        }

        $metode = [];
        if (EuPlatescGateway::isEnabled($settings)) {
            $metode[] = 'euplatesc';
        }
        if (
            (string) ($settings['stripe_enabled'] ?? '0') === '1'
            && trim((string) ($settings['stripe_secret_key'] ?? '')) !== ''
        ) {
            $metode[] = 'stripe';
        }
        return $metode;
    }

    /**
     * Pornește plata EuPlătesc: construiește formularul semnat și îl afișează
     * într-o pagină care se auto-trimite către gateway. (EuPlătesc nu are un
     * URL de sesiune ca Stripe — cererea pleacă prin POST din browser.)
     *
     * @return array<string, string> câmpurile formularului
     */
    private function createEuPlatescRequest(
        PDO $db,
        int $orderId,
        string $orderNumber,
        array $summary,
        array $billing
    ): array {
        $settings = Settings::all($db);
        $appUrl = $this->appUrl();

        $fields = EuPlatescGateway::buildRequest($settings, [
            'order_number' => $orderNumber,
            'amount' => (float) ($summary['total'] ?? 0),
            'description' => 'Comanda ' . $orderNumber,
            'first_name' => (string) ($billing['billing_first_name'] ?? ''),
            'last_name' => (string) ($billing['billing_last_name'] ?? ''),
            'address' => (string) ($billing['billing_address_line1'] ?? ''),
            'city' => (string) ($billing['billing_city'] ?? ''),
            'county' => (string) ($billing['billing_county'] ?? ''),
            'zip' => (string) ($billing['billing_postcode'] ?? ''),
            'phone' => (string) ($billing['billing_phone'] ?? ''),
            'email' => (string) ($billing['billing_email'] ?? ''),
            'success_url' => $appUrl . '/checkout/succes/' . rawurlencode($orderNumber) . '?euplatesc=1',
            'failed_url' => $appUrl . '/checkout?euplatesc_failed=1',
            'silent_url' => $appUrl . '/webhook/euplatesc',
            'back_url' => $appUrl . '/checkout?euplatesc_cancelled=1',
        ]);

        $stmt = $db->prepare(
            'UPDATE orders SET payment_error = NULL WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $orderId]);

        return $fields;
    }

    /**
     * Confirmarea unei plăți de diferență. Adaugă suma la `paid_amount` și
     * retrimite comanda în ERP, ca încasarea de acolo să fie la zi.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $settings
     */
    private function aplicaPlataLink(PDO $db, array $response, string $referinta, array $settings): bool
    {
        if (!EuPlatescGateway::verifyResponse($response, (string) ($settings['euplatesc_secret_key'] ?? ''))) {
            return false;
        }
        $link = \App\Support\PaymentLink::dupaReferinta($db, $referinta);
        if ($link === null) {
            return false;
        }
        if (!EuPlatescGateway::isApproved($response)) {
            return true; // Refuz de card: linkul rămâne valabil pentru o nouă încercare.
        }

        // Suma încasată trebuie să fie exact cea cerută pe link.
        $incasat = round((float) ($response['amount'] ?? 0), 2);
        $cerut = round((float) ($link['amount'] ?? 0), 2);
        if (abs($incasat - $cerut) > 0.01) {
            return false;
        }

        $prima = \App\Support\PaymentLink::confirmaPlata(
            $db,
            $link,
            (string) ($response['ep_id'] ?? ''),
            (string) ($response['approval'] ?? '')
        );
        if ($prima) {
            // ERP-ul trebuie să afle noua sumă încasată pe aceeași comandă.
            try {
                \App\Support\ErpSync::push($db, (int) $link['order_id'], true);
            } catch (Throwable) {
                // Cronul reîncearcă; banii sunt deja înregistrați pe site.
            }
        }
        return true;
    }

    /** Pagina pe care o deschide clientul din emailul cu diferența de plată. */
    public function paymentLinkPage(array $params): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->renderPagina404('Linkul de plată nu este disponibil', 'Încearcă din nou peste câteva minute.');
            return;
        }
        [$link, $order, $eroare] = $this->incarcaLinkPlata($db, (string) ($params['token'] ?? ''));
        if ($link === null) {
            $this->renderPagina404('Link de plată invalid', $eroare, $db);
            return;
        }

        View::render('site/payment-link', [
            'title' => 'Plata diferenței — comanda ' . (string) ($order['order_number'] ?? ''),
            'link' => $link,
            'order' => $order,
            'esteplatit' => (string) ($link['status'] ?? '') === \App\Support\PaymentLink::STATUS_PLATIT,
        ]);
    }

    /** Pornește plata cu cardul pentru diferența de pe link. */
    public function paymentLinkStart(array $params): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->renderPagina404('Linkul de plată nu este disponibil', 'Încearcă din nou peste câteva minute.');
            return;
        }
        [$link, $order, $eroare] = $this->incarcaLinkPlata($db, (string) ($params['token'] ?? ''));
        if ($link === null || (string) ($link['status'] ?? '') !== \App\Support\PaymentLink::STATUS_ASTEPTARE) {
            $this->renderPagina404('Link de plată invalid', $eroare !== '' ? $eroare : 'Plata a fost deja făcută.', $db);
            return;
        }

        $settings = Settings::all($db);
        $appUrl = $this->appUrl();
        $numarComanda = (string) ($order['order_number'] ?? '');
        try {
            $fields = EuPlatescGateway::buildRequest($settings, [
                // Referința e unică per încercare; la întoarcere o recunoaștem
                // ca aparținând comenzii.
                'order_number' => (string) $link['referinta'],
                'amount' => round((float) $link['amount'], 2),
                'description' => 'Diferență comanda ' . $numarComanda,
                'first_name' => (string) ($order['billing_first_name'] ?? ''),
                'last_name' => (string) ($order['billing_last_name'] ?? ''),
                'address' => (string) ($order['billing_address_line1'] ?? ''),
                'city' => (string) ($order['billing_city'] ?? ''),
                'county' => (string) ($order['billing_county'] ?? ''),
                'zip' => (string) ($order['billing_postcode'] ?? ''),
                'phone' => (string) ($order['billing_phone'] ?? ''),
                'email' => (string) ($order['billing_email'] ?? ''),
                'success_url' => $appUrl . '/plata/' . rawurlencode((string) $link['token']) . '?platit=1',
                'failed_url' => $appUrl . '/plata/' . rawurlencode((string) $link['token']) . '?esuat=1',
                'silent_url' => $appUrl . '/webhook/euplatesc',
                'back_url' => $appUrl . '/plata/' . rawurlencode((string) $link['token']),
            ]);
        } catch (Throwable $e) {
            $this->renderPagina404('Plata nu a putut fi pornită', $e->getMessage(), $db);
            return;
        }

        View::render('site/euplatesc-redirect', [
            'title' => 'Redirecționare către plată',
            'gatewayUrl' => EuPlatescGateway::GATEWAY_URL,
            'fields' => $fields,
            'orderNumber' => $numarComanda,
        ]);
    }

    /**
     * Linkul de plată și comanda lui, validate.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>, 2: string}
     */
    private function incarcaLinkPlata(PDO $db, string $token): array
    {
        $link = \App\Support\PaymentLink::dupaToken($db, $token);
        if ($link === null) {
            return [null, [], 'Linkul nu există sau a fost înlocuit de unul mai nou.'];
        }
        if ((string) ($link['status'] ?? '') === \App\Support\PaymentLink::STATUS_ANULAT) {
            return [null, [], 'Linkul a fost anulat. Cere-ne unul nou.'];
        }
        if (\App\Support\PaymentLink::esteExpirat($link)) {
            return [null, [], 'Linkul a expirat. Cere-ne unul nou.'];
        }

        $stmt = $db->prepare(
            'SELECT id, order_number, total, paid_amount, payment_status,
                    billing_first_name, billing_last_name, billing_email, billing_phone,
                    billing_address_line1, billing_city, billing_county, billing_postcode
             FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => (int) $link['order_id']]);
        $order = $stmt->fetch();
        if (!is_array($order)) {
            return [null, [], 'Comanda asociată nu mai există.'];
        }
        return [$link, $order, ''];
    }

    /**
     * Confirmarea EuPlătesc, venită fie pe silent URL (sursa de adevăr), fie
     * odată cu întoarcerea clientului. Idempotentă: o comandă deja plătită nu
     * retrimite emailul și nu repetă împingerea în ERP.
     *
     * @param array<string, mixed> $response
     */
    private function applyEuPlatescResult(PDO $db, array $response): bool
    {
        $orderNumber = trim((string) ($response['invoice_id'] ?? ''));
        if ($orderNumber === '') {
            return false;
        }

        $this->ensureStripeSchema($db);
        $settings = Settings::all($db);
        // Plata unei diferențe („204026-P1") merge pe alt drum: adaugă suma la
        // ce s-a încasat deja, fără să atingă starea comenzii.
        if (\App\Support\PaymentLink::pareReferintaDeLink($orderNumber)) {
            return $this->aplicaPlataLink($db, $response, $orderNumber, $settings);
        }
        if (!EuPlatescGateway::verifyResponse($response, (string) ($settings['euplatesc_secret_key'] ?? ''))) {
            // Un răspuns nesemnat corect nu are voie să schimbe starea plății,
            // dar tăcerea completă ar lăsa comanda blocată în „în așteptare"
            // fără nicio urmă. Lăsăm doar o notă de diagnostic.
            $this->noteazaRaspunsEuPlatescNevalidat($db, $orderNumber);

            return false;
        }

        $stmt = $db->prepare(
            'SELECT id, payment_status, total FROM orders
             WHERE order_number = :order_number AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['order_number' => $orderNumber]);
        $order = $stmt->fetch() ?: null;
        if (!is_array($order)) {
            return false;
        }
        $orderId = (int) $order['id'];
        $eraPlatita = strtolower((string) ($order['payment_status'] ?? '')) === 'paid';

        $epId = substr(trim((string) ($response['ep_id'] ?? '')), 0, 64);
        $approval = substr(trim((string) ($response['approval'] ?? '')), 0, 64);

        if (!EuPlatescGateway::isApproved($response)) {
            if ($eraPlatita) {
                // O notificare de refuz nu poate anula o plată deja încasată.
                return true;
            }
            $db->prepare(
                "UPDATE orders
                 SET payment_status = 'failed',
                     status = CASE WHEN status IN ('pending_payment') THEN 'failed' ELSE status END,
                     payment_error = :payment_error,
                     euplatesc_ep_id = :ep_id
                 WHERE id = :id"
            )->execute([
                'payment_error' => substr(EuPlatescGateway::errorMessage($response), 0, 1000),
                'ep_id' => $epId !== '' ? $epId : null,
                'id' => $orderId,
            ]);
            LoyaltyService::refundRedeemedPointsForOrder($db, $orderId);
            LoyaltyService::reverseAwardedPointsForOrder($db, $orderId);
            \App\Support\ErpSync::anuleaza(
                $db,
                $orderId,
                'Plata a fost respinsă; comanda nu se trimite în ERP.'
            );
            return true;
        }

        // Suma încasată trebuie să fie cea a comenzii; altfel marcăm și oprim.
        $incasat = round((float) ($response['amount'] ?? 0), 2);
        $datorat = round((float) ($order['total'] ?? 0), 2);
        if (abs($incasat - $datorat) > 0.01) {
            $db->prepare(
                'UPDATE orders SET payment_error = :payment_error, euplatesc_ep_id = :ep_id WHERE id = :id'
            )->execute([
                'payment_error' => 'Suma încasată (' . number_format($incasat, 2) . ') diferă de totalul comenzii ('
                    . number_format($datorat, 2) . '). Verifică în panoul EuPlătesc.',
                'ep_id' => $epId !== '' ? $epId : null,
                'id' => $orderId,
            ]);
            return true;
        }

        \App\Support\ErpSync::ensureSchema($db);
        $db->prepare(
            "UPDATE orders
             SET payment_status = 'paid',
                 status = CASE WHEN status IN ('pending_payment', 'failed') THEN 'pending' ELSE status END,
                 paid_at = COALESCE(paid_at, NOW()),
                 payment_error = NULL,
                 paid_amount = COALESCE(paid_amount, :incasat),
                 euplatesc_ep_id = :ep_id,
                 euplatesc_approval = :approval
             WHERE id = :id"
        )->execute([
            // Suma reală de la procesator; rămâne neschimbată dacă totalul
            // comenzii se modifică ulterior.
            'incasat' => number_format($incasat, 2, '.', ''),
            'ep_id' => $epId !== '' ? $epId : null,
            'approval' => $approval !== '' ? $approval : null,
            'id' => $orderId,
        ]);

        if (!$eraPlatita) {
            EmailAutomation::sendOrderTemplateById($db, $settings, $orderId, 'new_order');
            // Comanda pleacă spre ERP abia acum, după confirmarea încasării.
            \App\Support\ErpSync::push($db, $orderId);
        }
        return true;
    }

    /**
     * Silent URL-ul EuPlătesc: confirmarea server-to-server. E singura sursă
     * de adevăr pentru plată — pagina de retur poate lipsi dacă clientul
     * închide browserul.
     */
    public function euPlatescIpn(): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(500);
            echo 'db_unavailable';
            return;
        }

        $raspuns = $_POST !== [] ? $_POST : [];
        if ($raspuns === []) {
            http_response_code(400);
            echo 'empty_payload';
            return;
        }

        if (!$this->applyEuPlatescResult($db, $raspuns)) {
            http_response_code(400);
            echo 'invalid_signature_or_order';
            return;
        }

        echo 'ok';
    }

    private function createStripeCheckoutSession(
        PDO $db,
        int $orderId,
        string $orderNumber,
        array $summary,
        array $billing
    ): array {
        $settings = Settings::all($db);
        $secretKey = trim((string) ($settings['stripe_secret_key'] ?? ''));
        if ($secretKey === '') {
            throw new RuntimeException('Cheia Stripe secret nu este configurată în admin.');
        }

        $currency = strtolower(trim((string) ($settings['stripe_currency'] ?? 'ron')));
        if (!preg_match('/^[a-z]{3}$/', $currency)) {
            $currency = 'ron';
        }

        $totalAmount = $this->toMinorAmount((float) ($summary['total'] ?? 0));
        if ($totalAmount <= 0) {
            throw new RuntimeException('Valoarea totală a comenzii este invalidă.');
        }

        $successUrl = $this->appUrl() . '/checkout/succes/' . rawurlencode($orderNumber) . '?stripe=1&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $this->appUrl() . '/checkout?stripe_cancelled=1';

        $session = StripeGateway::createCheckoutSession($secretKey, [
            'payment_method_types[0]' => 'card',
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $orderNumber,
            'metadata[order_id]' => (string) $orderId,
            'metadata[order_number]' => $orderNumber,
            'metadata[billing_email]' => (string) ($billing['billing_email'] ?? ''),
            'payment_intent_data[metadata][order_id]' => (string) $orderId,
            'payment_intent_data[metadata][order_number]' => $orderNumber,
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][product_data][name]' => 'Comandă ' . $orderNumber,
            'line_items[0][price_data][unit_amount]' => (string) $totalAmount,
            'line_items[0][quantity]' => '1',
            'locale' => 'auto',
        ]);

        $sessionId = (string) ($session['id'] ?? '');
        $checkoutUrl = (string) ($session['url'] ?? '');
        if ($sessionId === '' || $checkoutUrl === '') {
            throw new RuntimeException('Stripe nu a returnat un URL de plată valid.');
        }

        $stmt = $db->prepare(
            'UPDATE orders
             SET stripe_session_id = :session_id,
                 stripe_payment_intent_id = :payment_intent_id,
                 payment_error = NULL
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'payment_intent_id' => (string) ($session['payment_intent'] ?? ''),
            'id' => $orderId,
        ]);

        return $session;
    }

    /**
     * Notează că a venit un răspuns EuPlătesc a cărui semnătură nu se verifică.
     * Nu atinge starea plății: mesajul e neautentificat, deci nu are voie să
     * decidă nimic. Scrie doar pe comenzile care încă așteaptă plata, ca o
     * cerere trimisă la întâmplare să nu poată murdări comenzi încheiate.
     */
    private function noteazaRaspunsEuPlatescNevalidat(PDO $db, string $orderNumber): void
    {
        try {
            $db->prepare(
                "UPDATE orders
                    SET payment_error = :payment_error
                  WHERE order_number = :order_number
                    AND deleted_at IS NULL
                    AND payment_status <> 'paid'
                    AND status = 'pending_payment'"
            )->execute([
                'payment_error' => 'Am primit un răspuns de la EuPlătesc a cărui semnătură nu se verifică. '
                    . 'Cel mai probabil cheia secretă din Setări plăți nu este cea din contul EuPlătesc.',
                'order_number' => $orderNumber,
            ]);
        } catch (Throwable) {
            // Diagnosticul nu are voie să întrerupă procesarea notificării.
        }
    }

    /** Inițializarea plății a eșuat (orice procesator): comanda rămâne eșuată. */
    /**
     * Scrie în contul clientului numele completat la checkout, dacă în cont
     * stătea încă marcajul generic „Client Nou".
     *
     * Numele de pe comandă e cel adevărat — l-a scris clientul acum, pe un
     * câmp obligatoriu. Contul rămâne altfel veșnic „Client Nou", fiindcă
     * nimeni nu intră special în „Contul meu" ca să-l completeze.
     */
    private function completeazaNumeleContului(PDO $db, string $firstName, string $lastName): void
    {
        try {
            $user = CustomerAuth::user($db);
            if (!is_array($user)) {
                return;
            }
            if (!\App\Support\CustomerDisplay::esteNumeGenerat(
                (string) ($user['first_name'] ?? ''),
                (string) ($user['last_name'] ?? '')
            )) {
                return; // contul are deja un nume real
            }
            if (\App\Support\CustomerDisplay::esteNumeGenerat($firstName, $lastName)) {
                return; // nici comanda n-are un nume mai bun
            }
            $db->prepare(
                'UPDATE customers SET first_name = :first_name, last_name = :last_name WHERE id = :id'
            )->execute([
                'first_name' => trim($firstName),
                'last_name' => trim($lastName),
                'id' => (int) ($user['id'] ?? 0),
            ]);
        } catch (\Throwable) {
            // Completarea e un bonus; o eroare aici nu are voie să oprească comanda.
        }
    }

    private function markOrderPaymentFailed(PDO $db, int $orderId, string $message): void
    {
        $stmt = $db->prepare(
            'UPDATE orders
             SET status = :status,
                 payment_status = :payment_status,
                 payment_error = :payment_error
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'status' => 'failed',
            'payment_status' => 'failed',
            'payment_error' => substr($message, 0, 1000),
            'id' => $orderId,
        ]);
        LoyaltyService::refundRedeemedPointsForOrder($db, $orderId);
        LoyaltyService::reverseAwardedPointsForOrder($db, $orderId);
        \App\Support\ErpSync::anuleaza(
            $db,
            $orderId,
            'Plata nu a putut fi inițializată; comanda nu se trimite în ERP.'
        );
    }

    private function applyStripeSessionResult(PDO $db, array $session, bool $paid): void
    {
        $orderNumber = trim((string) ($session['metadata']['order_number'] ?? $session['client_reference_id'] ?? ''));
        $orderId = (int) ($session['metadata']['order_id'] ?? 0);
        $sessionId = trim((string) ($session['id'] ?? ''));
        $paymentIntentId = trim((string) ($session['payment_intent'] ?? ''));

        if ($orderNumber === '' && $orderId <= 0) {
            return;
        }

        \App\Support\ErpSync::ensureSchema($db);
        $where = $orderNumber !== '' ? 'order_number = :order_number AND deleted_at IS NULL' : 'id = :id AND deleted_at IS NULL';
        $sql = $paid
            ? "UPDATE orders
               SET payment_status = 'paid',
                   status = CASE WHEN status IN ('pending_payment', 'failed') THEN 'pending' ELSE status END,
                   stripe_session_id = :session_id,
                   stripe_payment_intent_id = :payment_intent_id,
                   paid_at = NOW(),
                   payment_error = NULL,
                   paid_amount = COALESCE(paid_amount, total)
               WHERE $where"
            : "UPDATE orders
               SET payment_status = CASE WHEN payment_status = 'paid' THEN payment_status ELSE 'failed' END,
                   status = CASE WHEN payment_status = 'paid' THEN status WHEN status IN ('pending_payment') THEN 'failed' ELSE status END,
                   stripe_session_id = :session_id,
                   stripe_payment_intent_id = :payment_intent_id,
                   payment_error = 'Plata nu a fost finalizată: card refuzat sau sesiune expirată.'
               WHERE $where";

        $stmt = $db->prepare($sql);
        $params = [
            'session_id' => $sessionId,
            'payment_intent_id' => $paymentIntentId,
        ];
        if ($orderNumber !== '') {
            $params['order_number'] = $orderNumber;
        } else {
            $params['id'] = $orderId;
        }
        $stmt->execute($params);

        $targetOrderId = $this->resolveOrderIdForStripeSession($db, $orderNumber, $orderId);
        if ($paid) {
            $settings = Settings::all($db);
            if ($targetOrderId > 0) {
                EmailAutomation::sendOrderTemplateById($db, $settings, $targetOrderId, 'new_order');
                \App\Support\ErpSync::push($db, $targetOrderId);
            }
        } else {
            if ($targetOrderId > 0) {
                LoyaltyService::refundRedeemedPointsForOrder($db, $targetOrderId);
                LoyaltyService::reverseAwardedPointsForOrder($db, $targetOrderId);
            }
        }
    }

    private function applyStripePaymentIntentResult(PDO $db, array $paymentIntent, bool $paid): void
    {
        $orderNumber = trim((string) ($paymentIntent['metadata']['order_number'] ?? ''));
        $orderId = (int) ($paymentIntent['metadata']['order_id'] ?? 0);
        $paymentIntentId = trim((string) ($paymentIntent['id'] ?? ''));
        if ($orderNumber === '' && $orderId <= 0) {
            return;
        }

        $where = $orderNumber !== '' ? 'order_number = :order_number AND deleted_at IS NULL' : 'id = :id AND deleted_at IS NULL';
        $sql = $paid
            ? "UPDATE orders
               SET payment_status = 'paid',
                   status = CASE WHEN status IN ('pending_payment', 'failed') THEN 'pending' ELSE status END,
                   stripe_payment_intent_id = :payment_intent_id,
                   paid_at = NOW(),
                   payment_error = NULL
               WHERE $where"
            : "UPDATE orders
               SET payment_status = CASE WHEN payment_status = 'paid' THEN payment_status ELSE 'failed' END,
                   status = CASE WHEN payment_status = 'paid' THEN status WHEN status IN ('pending_payment') THEN 'failed' ELSE status END,
                   stripe_payment_intent_id = :payment_intent_id,
                   payment_error = 'Plata nu a fost finalizată: card refuzat sau sesiune expirată.'
               WHERE $where";

        $stmt = $db->prepare($sql);
        $params = ['payment_intent_id' => $paymentIntentId];
        if ($orderNumber !== '') {
            $params['order_number'] = $orderNumber;
        } else {
            $params['id'] = $orderId;
        }
        $stmt->execute($params);

        $targetOrderId = $this->resolveOrderIdForStripeSession($db, $orderNumber, $orderId);
        if ($targetOrderId <= 0) {
            return;
        }

        if ($paid) {
            $settings = Settings::all($db);
            EmailAutomation::sendOrderTemplateById($db, $settings, $targetOrderId, 'new_order');
            \App\Support\ErpSync::push($db, $targetOrderId);
            return;
        }

        LoyaltyService::refundRedeemedPointsForOrder($db, $targetOrderId);
        LoyaltyService::reverseAwardedPointsForOrder($db, $targetOrderId);
    }

    private function resolveOrderIdForStripeSession(PDO $db, string $orderNumber, int $orderId): int
    {
        if ($orderId > 0) {
            return $orderId;
        }
        if ($orderNumber === '') {
            return 0;
        }

        $lookup = $db->prepare('SELECT id FROM orders WHERE order_number = :order_number AND deleted_at IS NULL LIMIT 1');
        $lookup->execute(['order_number' => $orderNumber]);
        return (int) ($lookup->fetchColumn() ?: 0);
    }

    private function syncStripeSessionFromReturn(PDO $db, string $orderNumber, string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        $this->ensureStripeSchema($db);
        $settings = Settings::all($db);
        $secretKey = trim((string) ($settings['stripe_secret_key'] ?? ''));
        if ($secretKey === '') {
            return;
        }

        $session = StripeGateway::retrieveCheckoutSession($secretKey, $sessionId, ['expand[]' => 'payment_intent']);
        $sessionOrderNumber = trim((string) ($session['metadata']['order_number'] ?? $session['client_reference_id'] ?? ''));
        if ($orderNumber !== '' && $sessionOrderNumber !== '' && !hash_equals($orderNumber, $sessionOrderNumber)) {
            return;
        }

        $paymentStatus = strtolower(trim((string) ($session['payment_status'] ?? '')));
        $intentStatus = '';
        if (is_array($session['payment_intent'] ?? null)) {
            $intentStatus = strtolower(trim((string) (($session['payment_intent']['status'] ?? ''))));
        }
        $isPaid = in_array($paymentStatus, ['paid', 'no_payment_required'], true) || $intentStatus === 'succeeded';
        $this->applyStripeSessionResult($db, $session, $isPaid);
    }

    /**
     * robots.txt, generat din setări. Blochează zonele private și tranzacționale
     * (n-au ce căuta în index și consumă degeaba bugetul de crawl) și indică
     * sitemap-ul, cu numele configurat în Setări → Magazin.
     */
    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $settings = $this->cachedSettings($this->db());
        $baseUrl = rtrim($this->appUrl(), '/');

        // În mentenanță nu vrem pagina de „revenim imediat" în index.
        if ((string) ($settings['maintenance_enabled'] ?? '0') === '1') {
            echo "User-agent: *\nDisallow: /\n";
            return;
        }

        $interzise = [
            '/admin', '/api/', '/auth/', '/webhook/',
            '/cos', '/checkout', '/contul-meu', '/login', '/register',
            '/plata/', '/newsletter/', '/gdpr-agreements',
        ];

        $linii = ['User-agent: *'];
        foreach ($interzise as $cale) {
            $linii[] = 'Disallow: ' . $cale;
        }
        $linii[] = '';

        $sitemap = $this->numeSitemap((string) ($settings['store_sitemap_filename'] ?? 'sitemap.xml'));
        if ($baseUrl !== '') {
            $linii[] = 'Sitemap: ' . $baseUrl . '/' . $sitemap;
        }

        echo implode("\n", $linii) . "\n";
    }

    /** Numele fișierului de sitemap, curățat la fel ca în admin. */
    private function numeSitemap(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['\\', '/'], '', $value);
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        $value = trim($value, '-._');
        if ($value === '') {
            $value = 'sitemap';
        }
        if (!str_ends_with($value, '.xml')) {
            $value .= '.xml';
        }
        return $value;
    }

    private function appUrl(): string
    {
        $config = require __DIR__ . '/../../../config/app.php';
        $appUrl = rtrim((string) ($config['url'] ?? ''), '/');
        if ($appUrl !== '') {
            return $appUrl;
        }

        $https = (string) ($_SERVER['HTTPS'] ?? '');
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }

    private function googleOAuthSettings(array $settings): array
    {
        $clientId = trim((string) ($settings['customer_google_client_id'] ?? ''));
        $clientSecret = trim((string) ($settings['customer_google_client_secret'] ?? ''));
        $enabled = (string) ($settings['customer_google_auth_enabled'] ?? '0') === '1'
            && $clientId !== ''
            && $clientSecret !== '';

        return [
            'enabled' => $enabled,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $this->appUrl() . '/auth/google/callback',
        ];
    }

    private function googleAuthViewConfig(array $settings, string $next): array
    {
        $oauth = $this->googleOAuthSettings($settings);
        $authUrl = '/auth/google';
        if ($next !== '') {
            $authUrl .= '?' . http_build_query(['next' => $next]);
        }

        return [
            'google_enabled' => $oauth['enabled'],
            'google_auth_url' => $authUrl,
        ];
    }

    private function googleTokenExchange(array $oauth, string $code): array
    {
        if ($code === '') {
            throw new RuntimeException('Codul Google lipsește.');
        }

        $payload = http_build_query([
            'client_id' => (string) ($oauth['client_id'] ?? ''),
            'client_secret' => (string) ($oauth['client_secret'] ?? ''),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => (string) ($oauth['redirect_uri'] ?? ''),
        ]);

        return $this->requestJson(
            'POST',
            'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            $payload
        );
    }

    private function googleFetchUserInfo(string $accessToken): array
    {
        if ($accessToken === '') {
            throw new RuntimeException('Google nu a returnat token de acces.');
        }

        return $this->requestJson(
            'GET',
            'https://www.googleapis.com/oauth2/v3/userinfo',
            ['Authorization: Bearer ' . $accessToken]
        );
    }

    private function requestJson(string $method, string $url, array $headers = [], string $payload = ''): array
    {
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init();
            if ($ch === false) {
                throw new RuntimeException('Nu s-a putut inițializa conexiunea externă.');
            }

            $rawHeaders = $headers;
            if ($payload !== '') {
                $rawHeaders[] = 'Content-Length: ' . strlen($payload);
            }

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $rawHeaders);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            } elseif ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($payload !== '') {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
            }

            $rawBody = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($rawBody === false) {
                throw new RuntimeException('Serviciul extern nu răspunde: ' . $error);
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'content' => $payload,
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
            ]);
            $rawBody = @file_get_contents($url, false, $context);
            if ($rawBody === false) {
                throw new RuntimeException('Serviciul extern nu răspunde.');
            }

            $responseHeaders = $http_response_header ?? [];
            foreach ($responseHeaders as $headerLine) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', (string) $headerLine, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
            }
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Răspuns invalid de la serviciul extern.');
        }
        if ($status < 200 || $status >= 300) {
            $errorValue = $decoded['error'] ?? null;
            $message = (string) ($decoded['error_description'] ?? '');
            if ($message === '' && is_array($errorValue)) {
                $message = (string) ($errorValue['message'] ?? '');
            }
            if ($message === '' && is_string($errorValue)) {
                $message = $errorValue;
            }
            if ($message === '') {
                $message = 'Autentificarea nu a putut fi finalizată.';
            }
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    private function resolveGoogleUser(PDO $db, array $googleUser): int
    {
        $googleId = trim((string) ($googleUser['sub'] ?? ''));
        $email = strtolower(trim((string) ($googleUser['email'] ?? '')));
        $emailVerified = filter_var($googleUser['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
        if ($googleId === '') {
            throw new RuntimeException('Google nu a trimis identificatorul utilizatorului.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$emailVerified) {
            throw new RuntimeException('Contul Google trebuie să aibă email valid și verificat.');
        }

        $firstName = trim((string) ($googleUser['given_name'] ?? ''));
        $lastName = trim((string) ($googleUser['family_name'] ?? ''));
        if ($firstName === '' && $lastName === '') {
            $fullName = trim((string) ($googleUser['name'] ?? ''));
            if ($fullName !== '') {
                $parts = preg_split('/\s+/', $fullName) ?: [];
                $firstName = (string) ($parts[0] ?? '');
                $lastName = trim(implode(' ', array_slice($parts, 1)));
            }
        }
        if ($firstName === '') {
            $firstName = 'Client';
        }
        if ($lastName === '') {
            $lastName = 'Google';
        }

        $byGoogleId = $db->prepare('SELECT id FROM users WHERE google_id = :google_id LIMIT 1');
        $byGoogleId->execute(['google_id' => $googleId]);
        $userId = (int) ($byGoogleId->fetchColumn() ?: 0);
        if ($userId > 0) {
            return $userId;
        }

        $byEmail = $db->prepare('SELECT id, first_name, last_name FROM users WHERE email = :email LIMIT 1');
        $byEmail->execute(['email' => $email]);
        $existing = $byEmail->fetch() ?: null;
        if (is_array($existing)) {
            $userId = (int) ($existing['id'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('Contul nu poate fi asociat cu Google.');
            }

            $currentFirstName = trim((string) ($existing['first_name'] ?? ''));
            $currentLastName = trim((string) ($existing['last_name'] ?? ''));
            $update = $db->prepare(
                'UPDATE users
                 SET google_id = :google_id,
                     first_name = :first_name,
                     last_name = :last_name
                 WHERE id = :id'
            );
            $update->execute([
                'google_id' => $googleId,
                'first_name' => $currentFirstName !== '' ? $currentFirstName : $firstName,
                'last_name' => $currentLastName !== '' ? $currentLastName : $lastName,
                'id' => $userId,
            ]);
            return $userId;
        }

        $insert = $db->prepare(
            'INSERT INTO users (first_name, last_name, email, password_hash, google_id)
             VALUES (:first_name, :last_name, :email, :password_hash, :google_id)'
        );
        $insert->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT),
            'google_id' => $googleId,
        ]);

        $newUserId = (int) $db->lastInsertId();
        if ($newUserId <= 0) {
            throw new RuntimeException('Contul Google nu a putut fi creat.');
        }

        EmailAutomation::sendAccountWelcome(
            $db,
            Settings::all($db),
            $email,
            trim($firstName . ' ' . $lastName)
        );

        return $newUserId;
    }

    private function toMinorAmount(float $amount): int
    {
        return (int) max(0, round($amount * 100));
    }

    private function resolveFanLiveShipping(array $settings, array $summary, array $billing, ?string &$errorMessage = null): ?float
    {
        $errorMessage = null;

        // Prețul fix configurat în Setări livrare bate tariful curierului: îl
        // aplicăm aici, unde știm deja județul și localitatea comenzii, ca să
        // putem adăuga și taxa pentru localitățile cu km suplimentari.
        if (\App\Support\ShippingPricing::esteActiv($settings)) {
            if (!$this->requiresFanLiveShippingCharge($settings, $summary)) {
                return null; // comanda a atins pragul de transport gratuit
            }
            $dbFix = $this->db();
            return \App\Support\ShippingPricing::pret(
                $dbFix instanceof PDO ? $dbFix : null,
                $settings,
                trim((string) ($billing['billing_county'] ?? '')),
                trim((string) ($billing['billing_city'] ?? '')),
                \App\Support\CheckoutCalculator::livrareAleasaLaFanbox()
            );
        }

        if ((string) ($settings['fan_live_tariff_enabled'] ?? '0') !== '1') {
            return null;
        }

        if (!$this->requiresFanLiveShippingCharge($settings, $summary)) {
            return null;
        }

        $credentials = $this->fanCredentialsFromSettings($settings);
        if ($credentials === null) {
            $errorMessage = 'Date FAN lipsă: completează Client ID + Username API + Parolă API în Setări livrare (FAN).';
            return null;
        }

        $weightKg = $this->estimateShipmentWeightKg($summary, $settings);
        $recipientCounty = trim((string) ($billing['billing_county'] ?? ''));
        $recipientLocality = trim((string) ($billing['billing_city'] ?? ''));
        if (!$this->isFanShippingAddressComplete($billing)) {
            $errorMessage = 'Completează județul, localitatea, strada, numărul și codul poștal pentru calcul FAN.';
            return null;
        }
        $db = $this->db();
        $resolvedRecipient = $this->resolveFanAddressForApi(
            $db instanceof PDO ? $db : null,
            $recipientCounty,
            $recipientLocality
        );
        $recipientCounty = $resolvedRecipient['county'];
        $recipientLocality = $resolvedRecipient['locality'];
        // Gestiuni pe județe: expeditorul devine depozitul care deservește
        // județul clientului. Dacă ERP-ul nu răspunde sau județul nu e mapat,
        // rămâne expeditorul global.
        $senderOverride = \App\Support\ErpShipping::senderForCounty(
            $db instanceof PDO ? $db : null,
            $settings,
            $recipientCounty
        );
        if ($senderOverride !== null) {
            $settings = array_merge($settings, $senderOverride);
        }
        $senderCounty = trim((string) ($settings['fan_sender_county'] ?? ''));
        $senderLocality = trim((string) ($settings['fan_sender_locality'] ?? ''));
        $resolvedSender = $this->resolveFanAddressForApi(
            $db instanceof PDO ? $db : null,
            $senderCounty,
            $senderLocality
        );
        $senderCounty = $resolvedSender['county'];
        $senderLocality = $resolvedSender['locality'];

        $dimensions = $this->fanDimensionsFromSettings($settings);
        $shipmentType = trim((string) ($settings['fan_shipment_type'] ?? 'parcel'));
        if (!in_array($shipmentType, ['parcel', 'envelope'], true)) {
            $shipmentType = 'parcel';
        }
        $parcelCount = $shipmentType === 'parcel' ? max(1, (int) ($settings['fan_parcel_count'] ?? 1)) : 0;
        $envelopeCount = $shipmentType === 'envelope' ? max(1, (int) ($settings['fan_envelope_count'] ?? 1)) : 0;
        $shippingPayer = trim((string) ($settings['fan_shipping_payer'] ?? 'recipient'));
        if (!in_array($shippingPayer, ['recipient', 'sender', 'third_party'], true)) {
            $shippingPayer = 'recipient';
        }
        $shippingOptions = $this->fanOptionCodesFromSettings($settings);
        $codPayer = trim((string) ($settings['fan_cod_payer'] ?? 'sender'));
        if (!in_array($codPayer, ['recipient', 'sender'], true)) {
            $codPayer = 'sender';
        }
        $declaredValueMode = trim((string) ($settings['fan_declared_value_mode'] ?? 'order_total'));
        $query = [
            'clientId' => $credentials['client_id'],
            'service' => trim((string) ($settings['fan_service_type'] ?? 'Standard')),
            'payment' => $shippingPayer,
            'weight' => $weightKg,
            'parcel' => $parcelCount,
            'envelope' => $envelopeCount,
            'recipientCounty' => $recipientCounty,
            'recipientLocality' => $recipientLocality,
            'senderCounty' => $senderCounty,
            'senderLocality' => $senderLocality,
        ];
        if ($declaredValueMode !== 'none') {
            $query['declaredValue'] = $declaredValueMode === 'zero'
                ? 0.0
                : max(0, (float) ($summary['total'] ?? 0));
        }
        if ($shippingOptions !== []) {
            $query['options'] = $shippingOptions;
        }
        if (($dimensions['height'] ?? 0) > 0) {
            $query['height'] = (float) $dimensions['height'];
        }
        if (($dimensions['width'] ?? 0) > 0) {
            $query['width'] = (float) $dimensions['width'];
        }
        if (($dimensions['length'] ?? 0) > 0) {
            $query['length'] = (float) $dimensions['length'];
        }

        if (trim((string) $query['senderCounty']) === '' || trim((string) $query['senderLocality']) === '') {
            $errorMessage = 'Date FAN incomplete: completează și Județ expeditor + Localitate expeditor în Setări livrare (FAN).';
            return null;
        }

        try {
            $result = FanCourierGateway::quoteInternalTariff($credentials, $query);
            $shipping = max(0, (float) ($result['total'] ?? 0));
            if ($shipping <= 0) {
                $errorMessage = 'FAN nu a returnat un tarif valid pentru localitatea selectată.';
                return null;
            }
            return $shipping;
        } catch (RuntimeException $exception) {
            $errorMessage = $this->friendlyFanShippingErrorMessage((string) $exception->getMessage());
            return null;
        }
    }

    private function friendlyFanShippingErrorMessage(string $rawMessage): string
    {
        $rawMessage = trim($rawMessage);
        if ($rawMessage === '') {
            return 'Nu am putut calcula transportul prin FAN Courier. Verifică localitatea/județul și încearcă din nou.';
        }

        $messageForChecks = mb_strtolower($rawMessage);
        $decoded = json_decode($rawMessage, true);
        if (is_array($decoded)) {
            $messageTokens = [];
            $rootMessage = trim((string) ($decoded['message'] ?? ''));
            if ($rootMessage !== '') {
                $messageTokens[] = $rootMessage;
            }
            $errors = $decoded['data']['errors'] ?? [];
            if (is_array($errors)) {
                foreach ($errors as $field => $errorList) {
                    $fieldText = trim((string) $field);
                    if ($fieldText !== '') {
                        $messageTokens[] = $fieldText;
                    }
                    if (!is_array($errorList)) {
                        continue;
                    }
                    foreach ($errorList as $errorValue) {
                        $errorText = trim((string) $errorValue);
                        if ($errorText !== '') {
                            $messageTokens[] = $errorText;
                        }
                    }
                }
            }
            if ($messageTokens !== []) {
                $messageForChecks = mb_strtolower(implode(' | ', $messageTokens));
            }
        }

        if (str_contains($messageForChecks, 'recipient.locality') || str_contains($messageForChecks, 'recipient locality')) {
            return 'Localitatea introdusă nu este recunoscută de FAN pentru județul selectat. Verifică județul/localitatea și încearcă din nou.';
        }
        if (str_contains($messageForChecks, 'recipient.county') || str_contains($messageForChecks, 'recipient county')) {
            return 'Județul selectat nu este recunoscut de FAN. Verifică datele adresei de livrare și încearcă din nou.';
        }
        if (str_contains($messageForChecks, 'sender.locality') || str_contains($messageForChecks, 'sender locality')) {
            return 'Momentan nu putem calcula transportul FAN (configurare expeditor invalidă). Te rugăm să încerci din nou puțin mai târziu.';
        }

        return 'Nu am putut calcula transportul prin FAN Courier. Verifică localitatea/județul și încearcă din nou.';
    }

    private function isFanShippingAddressComplete(array $billing): bool
    {
        $required = ['billing_county', 'billing_city', 'billing_street', 'billing_street_no', 'billing_postcode'];
        foreach ($required as $field) {
            if (trim((string) ($billing[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    private function requiresFanLiveShippingCharge(array $settings, array $summary): bool
    {
        $county = trim((string) ($summary['county'] ?? Cart::county()));
        $isBucharest = mb_strtolower($county) === 'bucuresti';
        $includeCoupons = ((string) ($settings['shipping_include_coupons'] ?? '1')) === '1';
        $threshold = $isBucharest
            ? (float) ($settings['shipping_free_bucharest'] ?? 200)
            : (float) ($settings['shipping_free_province'] ?? 200);
        $subtotal = max(0.0, (float) ($summary['subtotal'] ?? 0));
        $discount = max(0.0, (float) ($summary['discount'] ?? 0));
        $pointsDiscount = max(0.0, (float) ($summary['points_discount'] ?? 0));
        $effectiveDiscount = $discount + $pointsDiscount;
        $reference = $includeCoupons
            ? max(0.0, $subtotal - $effectiveDiscount)
            : $subtotal;
        return $reference < $threshold;
    }

    private function ensureStripeSchema(PDO $db): void
    {
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

        // EuPlătesc: identificatorul tranzacției lor și codul de autorizare,
        // ca plata să poată fi regăsită în panoul procesatorului.
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN euplatesc_ep_id VARCHAR(64) DEFAULT NULL AFTER payment_error');
        } catch (Throwable) {
        }

        try {
            $db->exec('ALTER TABLE orders ADD COLUMN euplatesc_approval VARCHAR(64) DEFAULT NULL AFTER euplatesc_ep_id');
        } catch (Throwable) {
        }
    }

    private function ensureOptionalPageSchema(PDO $db): void
    {
        try {
            $db->exec('ALTER TABLE pages ADD COLUMN css_content LONGTEXT NULL AFTER html_content');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE pages ADD COLUMN js_content LONGTEXT NULL AFTER css_content');
        } catch (Throwable) {
        }
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS seo_pages (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    page_type VARCHAR(50) NOT NULL,
                    page_ref VARCHAR(190) NOT NULL,
                    title VARCHAR(255) DEFAULT NULL,
                    description TEXT DEFAULT NULL,
                    canonical_url VARCHAR(255) DEFAULT NULL,
                    image_url VARCHAR(255) DEFAULT NULL,
                    UNIQUE KEY uniq_page_ref (page_type, page_ref)
                )'
            );
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE seo_pages ADD COLUMN image_url VARCHAR(255) DEFAULT NULL AFTER canonical_url');
        } catch (Throwable) {
        }
    }

    private function ensureContactFormsSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS contact_form_messages (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    email VARCHAR(190) NOT NULL,
                    phone VARCHAR(60) DEFAULT NULL,
                    subject VARCHAR(255) NOT NULL,
                    message LONGTEXT NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT "new",
                    source_url VARCHAR(500) DEFAULT NULL,
                    ip_address VARCHAR(64) DEFAULT NULL,
                    user_agent VARCHAR(255) DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    KEY idx_contact_form_messages_created (created_at),
                    KEY idx_contact_form_messages_status (status)
                )'
            );
        } catch (Throwable) {
        }
    }

    private function ensureGdprAgreementsSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS gdpr_agreements (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    subiect_nume_complet VARCHAR(190) NOT NULL,
                    ci_serie VARCHAR(30) NOT NULL,
                    ci_numar VARCHAR(40) NOT NULL,
                    ci_emitent VARCHAR(190) NOT NULL,
                    ci_data_eliberare DATE DEFAULT NULL,
                    nume VARCHAR(120) NOT NULL,
                    prenume VARCHAR(120) NOT NULL,
                    cnp VARCHAR(20) NOT NULL,
                    cuim VARCHAR(50) NOT NULL,
                    telefon VARCHAR(60) NOT NULL,
                    email VARCHAR(190) NOT NULL,
                    adresa_corespondenta VARCHAR(255) NOT NULL,
                    institutie_medicala VARCHAR(255) NOT NULL,
                    institutie_activitate VARCHAR(255) NOT NULL,
                    institutie_adresa VARCHAR(255) NOT NULL,
                    institutie_activitate_adresa VARCHAR(255) NOT NULL,
                    tip_medic VARCHAR(120) NOT NULL,
                    specializare VARCHAR(190) NOT NULL,
                    data_semnare DATE DEFAULT NULL,
                    nume_semnatura VARCHAR(190) NOT NULL,
                    signature_data_url LONGTEXT NOT NULL,
                    source_url VARCHAR(500) DEFAULT NULL,
                    ip_address VARCHAR(64) DEFAULT NULL,
                    user_agent VARCHAR(255) DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    KEY idx_gdpr_agreements_created (created_at),
                    KEY idx_gdpr_agreements_name (nume, prenume),
                    KEY idx_gdpr_agreements_email (email)
                )'
            );
        } catch (Throwable) {
        }
    }

    private function ensureCheckoutAntiBotSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS checkout_submit_logs (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ip_address VARCHAR(64) DEFAULT NULL,
                    user_agent VARCHAR(255) DEFAULT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT "allowed",
                    created_at DATETIME NOT NULL,
                    KEY idx_checkout_submit_logs_ip_created (ip_address, created_at),
                    KEY idx_checkout_submit_logs_created (created_at)
                )'
            );
        } catch (Throwable) {
        }
    }

    private function issueCheckoutAntiBotPayload(): array
    {
        $token = bin2hex(random_bytes(16));
        $issuedAt = time();
        if (!isset($_SESSION[self::CHECKOUT_ANTIBOT_SESSION_KEY]) || !is_array($_SESSION[self::CHECKOUT_ANTIBOT_SESSION_KEY])) {
            $_SESSION[self::CHECKOUT_ANTIBOT_SESSION_KEY] = [];
        }
        $bucket = (array) $_SESSION[self::CHECKOUT_ANTIBOT_SESSION_KEY];
        foreach ($bucket as $savedToken => $savedTimestamp) {
            if (!is_string($savedToken)) {
                unset($bucket[$savedToken]);
                continue;
            }
            if (!is_int($savedTimestamp) && !is_numeric($savedTimestamp)) {
                unset($bucket[$savedToken]);
                continue;
            }
            if ((int) $savedTimestamp < ($issuedAt - self::CHECKOUT_ANTIBOT_MAX_AGE_SECONDS)) {
                unset($bucket[$savedToken]);
            }
        }
        while (count($bucket) > 40) {
            array_shift($bucket);
        }
        $bucket[$token] = $issuedAt;
        $_SESSION[self::CHECKOUT_ANTIBOT_SESSION_KEY] = $bucket;

        return [
            'token' => $token,
            'rendered_at' => $issuedAt,
        ];
    }

    /**
     * Reține în sesiune ce a completat clientul, ca formularul să se repopuleze
     * după orice redirect cu eroare. Nu validează nimic — validarea rămâne în
     * validateCheckout(), care rescrie oricum aceleași chei.
     */
    private function rememberCheckoutInput(): void
    {
        $chei = [
            'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone',
            'billing_street', 'billing_street_no', 'billing_city', 'billing_county',
            'billing_postcode', 'billing_company_name', 'billing_company_tax_id',
            'billing_company_registration_no', 'notes', 'payment_method',
            'shipping_first_name', 'shipping_last_name', 'shipping_phone',
            'shipping_street', 'shipping_street_no', 'shipping_city',
            'shipping_county', 'shipping_postcode',
        ];

        $values = is_array($_SESSION['checkout_form'] ?? null) ? (array) $_SESSION['checkout_form'] : [];
        foreach ($chei as $cheie) {
            if (isset($_POST[$cheie]) && is_scalar($_POST[$cheie])) {
                $values[$cheie] = mb_substr(trim((string) $_POST[$cheie]), 0, 255);
            }
        }
        $values['billing_is_company'] = isset($_POST['billing_is_company']) ? 1 : 0;
        $hasShippingToggle = (string) ($_POST['has_shipping_toggle'] ?? '') === '1';
        $values['shipping_same_as_billing'] =
            ($hasShippingToggle ? isset($_POST['shipping_same_as_billing']) : true) ? 1 : 0;

        $_SESSION['checkout_form'] = $values;
    }

    private function validateCheckoutAntiBot(PDO $db): ?string
    {
        $ip = $this->checkoutClientIp();
        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $honeypot = trim((string) ($_POST[self::CHECKOUT_HONEYPOT_FIELD] ?? ''));
        $token = trim((string) ($_POST['checkout_form_token'] ?? ''));
        $renderedAt = (int) ($_POST['checkout_form_rendered_at'] ?? 0);
        $now = time();

        if ($this->checkoutRateLimited($db, $ip)) {
            $this->logCheckoutSubmitAttempt($db, $ip, $userAgent, 'rate_limited');
            return 'Prea multe încercări de comandă. Încearcă din nou în câteva minute.';
        }

        // Capcana veche se numea „company_website”, iar browserele (Chrome, în
        // special) o completau automat din profilul de adresă pentru că numele
        // conținea „company”. O mai citim, dar doar o marcăm în log — nu mai
        // blocăm clientul din cauza ei.
        if (trim((string) ($_POST['company_website'] ?? '')) !== '' && $honeypot === '') {
            $this->logCheckoutSubmitAttempt($db, $ip, $userAgent, 'honeypot_legacy');
        }

        if ($honeypot !== '') {
            $this->logCheckoutSubmitAttempt($db, $ip, $userAgent, 'bot_honeypot');
            return 'Nu am putut valida cererea (capcană anti-bot). Reîncarcă pagina checkout și încearcă din nou.';
        }

        if ($token === '' || strlen($token) < 20 || $renderedAt <= 0) {
            $this->logCheckoutSubmitAttempt($db, $ip, $userAgent, 'invalid_payload');
            return 'Nu am putut valida formularul. Reîncarcă pagina și încearcă din nou.';
        }

        $bucket = $_SESSION[self::CHECKOUT_ANTIBOT_SESSION_KEY] ?? [];
        if (!is_array($bucket)) {
            $bucket = [];
        }
        $issuedAt = (int) ($bucket[$token] ?? 0);
        if ($issuedAt <= 0) {
            $this->logCheckoutSubmitAttempt($db, $ip, $userAgent, 'token_missing');
            return 'Sesiunea checkout a expirat. Reîncarcă pagina și încearcă din nou.';
        }
        unset($bucket[$token]);
        $_SESSION[self::CHECKOUT_ANTIBOT_SESSION_KEY] = $bucket;

        $elapsed = $now - $issuedAt;
        if ($elapsed < self::CHECKOUT_ANTIBOT_MIN_SECONDS) {
            $this->logCheckoutSubmitAttempt($db, $ip, $userAgent, 'too_fast');
            return 'Comanda a fost trimisă prea repede după încărcarea paginii. Mai încearcă o dată.';
        }
        if ($elapsed > self::CHECKOUT_ANTIBOT_MAX_AGE_SECONDS) {
            $this->logCheckoutSubmitAttempt($db, $ip, $userAgent, 'expired');
            return 'Sesiunea checkout a expirat. Reîncarcă pagina și încearcă din nou.';
        }
        if (abs($renderedAt - $issuedAt) > 60) {
            $this->logCheckoutSubmitAttempt($db, $ip, $userAgent, 'timestamp_mismatch');
            return 'Nu am putut valida formularul. Reîncarcă pagina și încearcă din nou.';
        }

        $this->logCheckoutSubmitAttempt($db, $ip, $userAgent, 'allowed');
        return null;
    }

    private function checkoutRateLimited(PDO $db, string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*)
                 FROM checkout_submit_logs
                 WHERE ip_address = :ip
                   AND created_at >= (NOW() - INTERVAL 15 MINUTE)'
            );
            $stmt->execute(['ip' => $ip]);
            return ((int) $stmt->fetchColumn()) >= self::CHECKOUT_ANTIBOT_MAX_ATTEMPTS_PER_15_MIN;
        } catch (Throwable) {
            return false;
        }
    }

    private function logCheckoutSubmitAttempt(PDO $db, string $ip, string $userAgent, string $status): void
    {
        try {
            $stmt = $db->prepare(
                'INSERT INTO checkout_submit_logs (ip_address, user_agent, status, created_at)
                 VALUES (:ip_address, :user_agent, :status, NOW())'
            );
            $stmt->execute([
                'ip_address' => $ip !== '' ? $ip : null,
                'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
                'status' => mb_substr(trim($status) !== '' ? $status : 'allowed', 0, 20),
            ]);
        } catch (Throwable) {
        }
    }

    private function checkoutClientIp(): string
    {
        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            if ($parts !== []) {
                $candidate = trim((string) ($parts[0] ?? ''));
                if ($candidate !== '') {
                    return mb_substr($candidate, 0, 64);
                }
            }
        }
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $remote !== '' ? mb_substr($remote, 0, 64) : '';
    }

    private function ensureRegisterAntiBotSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS register_submit_logs (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ip_address VARCHAR(64) DEFAULT NULL,
                    user_agent VARCHAR(255) DEFAULT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT "allowed",
                    created_at DATETIME NOT NULL,
                    KEY idx_register_submit_logs_ip_created (ip_address, created_at),
                    KEY idx_register_submit_logs_created (created_at)
                )'
            );
        } catch (Throwable) {
        }
    }

    private function issueRegisterAntiBotPayload(): array
    {
        $token = bin2hex(random_bytes(16));
        $issuedAt = time();
        if (!isset($_SESSION[self::REGISTER_ANTIBOT_SESSION_KEY]) || !is_array($_SESSION[self::REGISTER_ANTIBOT_SESSION_KEY])) {
            $_SESSION[self::REGISTER_ANTIBOT_SESSION_KEY] = [];
        }
        $bucket = (array) $_SESSION[self::REGISTER_ANTIBOT_SESSION_KEY];
        foreach ($bucket as $savedToken => $savedTimestamp) {
            if (!is_string($savedToken)) {
                unset($bucket[$savedToken]);
                continue;
            }
            if (!is_int($savedTimestamp) && !is_numeric($savedTimestamp)) {
                unset($bucket[$savedToken]);
                continue;
            }
            if ((int) $savedTimestamp < ($issuedAt - self::REGISTER_ANTIBOT_MAX_AGE_SECONDS)) {
                unset($bucket[$savedToken]);
            }
        }
        while (count($bucket) > 60) {
            array_shift($bucket);
        }
        $bucket[$token] = $issuedAt;
        $_SESSION[self::REGISTER_ANTIBOT_SESSION_KEY] = $bucket;

        return [
            'token' => $token,
            'rendered_at' => $issuedAt,
        ];
    }

    private function validateRegisterAntiBot(PDO $db): ?string
    {
        $ip = $this->registerClientIp();
        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $honeypot = trim((string) ($_POST['register_hp_website'] ?? ''));
        $token = trim((string) ($_POST['register_form_token'] ?? ''));
        $renderedAt = (int) ($_POST['register_form_rendered_at'] ?? 0);
        $now = time();

        if ($this->registerRateLimited($db, $ip)) {
            $this->logRegisterSubmitAttempt($db, $ip, $userAgent, 'rate_limited');
            return 'Prea multe încercări de înregistrare. Încearcă din nou în câteva minute.';
        }

        if ($honeypot !== '') {
            $this->logRegisterSubmitAttempt($db, $ip, $userAgent, 'bot_honeypot');
            return 'Nu am putut valida înregistrarea. Reîncarcă pagina și încearcă din nou.';
        }

        if ($token === '' || strlen($token) < 20 || $renderedAt <= 0) {
            $this->logRegisterSubmitAttempt($db, $ip, $userAgent, 'invalid_payload');
            return 'Nu am putut valida formularul de înregistrare. Reîncarcă pagina și încearcă din nou.';
        }

        $bucket = $_SESSION[self::REGISTER_ANTIBOT_SESSION_KEY] ?? [];
        if (!is_array($bucket)) {
            $bucket = [];
        }
        $issuedAt = (int) ($bucket[$token] ?? 0);
        if ($issuedAt <= 0) {
            $this->logRegisterSubmitAttempt($db, $ip, $userAgent, 'token_missing');
            return 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.';
        }
        unset($bucket[$token]);
        $_SESSION[self::REGISTER_ANTIBOT_SESSION_KEY] = $bucket;

        $elapsed = $now - $issuedAt;
        if ($elapsed < self::REGISTER_ANTIBOT_MIN_SECONDS) {
            $this->logRegisterSubmitAttempt($db, $ip, $userAgent, 'too_fast');
            return 'Nu am putut valida înregistrarea. Reîncarcă pagina și încearcă din nou.';
        }
        if ($elapsed > self::REGISTER_ANTIBOT_MAX_AGE_SECONDS) {
            $this->logRegisterSubmitAttempt($db, $ip, $userAgent, 'expired');
            return 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.';
        }
        if (abs($renderedAt - $issuedAt) > 60) {
            $this->logRegisterSubmitAttempt($db, $ip, $userAgent, 'timestamp_mismatch');
            return 'Nu am putut valida formularul de înregistrare. Reîncarcă pagina și încearcă din nou.';
        }

        $this->logRegisterSubmitAttempt($db, $ip, $userAgent, 'allowed');
        return null;
    }

    private function registerRateLimited(PDO $db, string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*)
                 FROM register_submit_logs
                 WHERE ip_address = :ip
                   AND created_at >= (NOW() - INTERVAL 15 MINUTE)'
            );
            $stmt->execute(['ip' => $ip]);
            return ((int) $stmt->fetchColumn()) >= self::REGISTER_ANTIBOT_MAX_ATTEMPTS_PER_15_MIN;
        } catch (Throwable) {
            return false;
        }
    }

    private function logRegisterSubmitAttempt(PDO $db, string $ip, string $userAgent, string $status): void
    {
        try {
            $stmt = $db->prepare(
                'INSERT INTO register_submit_logs (ip_address, user_agent, status, created_at)
                 VALUES (:ip_address, :user_agent, :status, NOW())'
            );
            $stmt->execute([
                'ip_address' => $ip !== '' ? $ip : null,
                'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
                'status' => mb_substr(trim($status) !== '' ? $status : 'allowed', 0, 20),
            ]);
        } catch (Throwable) {
        }
    }

    private function registerClientIp(): string
    {
        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            if ($parts !== []) {
                $candidate = trim((string) ($parts[0] ?? ''));
                if ($candidate !== '') {
                    return mb_substr($candidate, 0, 64);
                }
            }
        }
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $remote !== '' ? mb_substr($remote, 0, 64) : '';
    }

    private function ensureFanLocalitiesSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS fan_localities (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    county VARCHAR(120) NOT NULL,
                    locality VARCHAR(190) NOT NULL,
                    county_norm VARCHAR(120) NOT NULL,
                    locality_norm VARCHAR(190) NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_fan_localities (county_norm, locality_norm),
                    KEY idx_fan_localities_county (county_norm),
                    KEY idx_fan_localities_locality (locality_norm)
                )'
            );
        } catch (Throwable) {
        }
    }

    private function normalizeFanLocalityToken(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'ă' => 'a',
            'â' => 'a',
            'î' => 'i',
            'ș' => 's',
            'ş' => 's',
            'ț' => 't',
            'ţ' => 't',
        ]);
        $value = preg_replace('/[^a-z0-9\s\-]/', '', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return trim($value);
    }

    private function resolveFanAddressForApi(?PDO $db, string $county, string $locality): array
    {
        $county = trim($county);
        $locality = trim($locality);
        if ($county === '' || $locality === '' || !$db instanceof PDO) {
            return ['county' => $county, 'locality' => $locality];
        }

        $this->ensureFanLocalitiesSchema($db);
        $countyNorm = $this->normalizeFanCountyLookupToken($county);
        $localityCandidates = $this->fanLocalityLookupCandidates($locality, $countyNorm);
        if ($countyNorm === '' || $localityCandidates === []) {
            return ['county' => $county, 'locality' => $locality];
        }

        $stmt = $db->prepare(
            'SELECT county, locality
             FROM fan_localities
             WHERE county_norm = :county_norm AND locality_norm = :locality_norm
             LIMIT 1'
        );
        foreach ($localityCandidates as $candidateNorm) {
            $stmt->execute([
                'county_norm' => $countyNorm,
                'locality_norm' => $candidateNorm,
            ]);
            $row = $stmt->fetch() ?: null;
            if (!is_array($row)) {
                continue;
            }
            $rowCounty = trim((string) ($row['county'] ?? ''));
            $rowLocality = trim((string) ($row['locality'] ?? ''));
            if ($rowCounty !== '' && $rowLocality !== '') {
                return ['county' => $rowCounty, 'locality' => $rowLocality];
            }
        }

        return ['county' => $county, 'locality' => $locality];
    }

    private function normalizeFanCountyLookupToken(string $county): string
    {
        $token = $this->normalizeFanLocalityToken($county);
        if ($token === '') {
            return '';
        }
        $token = preg_replace('/^(judet(ul)?|municipiul)\s+/', '', $token) ?? $token;
        if (str_contains($token, 'bucuresti')) {
            return 'bucuresti';
        }
        return trim($token);
    }

    private function fanLocalityLookupCandidates(string $locality, string $countyNorm): array
    {
        $base = $this->normalizeFanLocalityToken($locality);
        if ($base === '') {
            return [];
        }
        $candidates = [$base];

        $trimmed = preg_replace('/^(municipiul|oras(ul)?|comuna|sat(ul)?)\s+/', '', $base) ?? $base;
        $trimmed = trim($trimmed);
        if ($trimmed !== '' && $trimmed !== $base) {
            $candidates[] = $trimmed;
        }

        // FAN typically expects locality "bucuresti", not sector variants.
        if ($countyNorm === 'bucuresti' && (str_contains($base, 'sector') || str_contains($base, 'bucuresti'))) {
            $candidates[] = 'bucuresti';
        }

        $withoutSector = preg_replace('/\bsector\b\s*[0-9ivx]+\b/u', '', $base) ?? $base;
        $withoutSector = trim(preg_replace('/\s+/', ' ', $withoutSector) ?? $withoutSector);
        if ($withoutSector !== '' && $withoutSector !== $base) {
            $candidates[] = $withoutSector;
        }

        $filtered = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                $filtered[] = $candidate;
            }
        }

        return array_values(array_unique($filtered));
    }

    private function customerRegistrationFields(array $settings): array
    {
        $passwordEnabled = (string) ($settings['customer_registration_field_password'] ?? '1') !== '0';
        // Implicit cerem minimul: email + parolă. Restul se activează din admin.
        return [
            'first_name' => (string) ($settings['customer_registration_field_first_name'] ?? '0') === '1',
            'last_name' => (string) ($settings['customer_registration_field_last_name'] ?? '0') === '1',
            'email' => (string) ($settings['customer_registration_field_email'] ?? '1') !== '0',
            'phone' => (string) ($settings['customer_registration_field_phone'] ?? '0') === '1',
            'birth_date' => (string) ($settings['customer_registration_field_birth_date'] ?? '0') === '1',
            'gender' => (string) ($settings['customer_registration_field_gender'] ?? '0') === '1',
            'password' => $passwordEnabled,
            'password_confirm' => $passwordEnabled
                && (string) ($settings['customer_registration_field_password_confirm'] ?? '1') !== '0',
        ];
    }

    private function loadCustomerAddresses(PDO $db, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $db->prepare(
            'SELECT id, label, full_name, phone, street, street_no, address_line1, address_line2, city, county, postcode, is_default, created_at
             FROM user_addresses
             WHERE user_id = :user_id
             ORDER BY is_default DESC, id DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    private function ensureCustomerSchema(PDO $db): void
    {
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
            $db->exec('ALTER TABLE users ADD COLUMN birth_date DATE DEFAULT NULL AFTER phone');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE users ADD COLUMN gender VARCHAR(20) DEFAULT NULL AFTER birth_date');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE users ADD COLUMN google_id VARCHAR(120) DEFAULT NULL AFTER password_hash');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE users ADD UNIQUE KEY uniq_users_google_id (google_id)');
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
                    street VARCHAR(190) DEFAULT NULL,
                    street_no VARCHAR(40) DEFAULT NULL,
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
            $db->exec('ALTER TABLE user_addresses ADD COLUMN street VARCHAR(190) DEFAULT NULL AFTER phone');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE user_addresses ADD COLUMN street_no VARCHAR(40) DEFAULT NULL AFTER street');
        } catch (Throwable) {
        }
        LoyaltyService::ensureSchema($db);
    }

    private function safeRedirectPath(string $path, string $default = '/'): string
    {
        $path = trim($path);
        if ($path === '') {
            return $default;
        }
        if (!str_starts_with($path, '/')) {
            return $default;
        }
        if (str_starts_with($path, '//') || str_contains($path, '://')) {
            return $default;
        }

        return $path;
    }

    private function normalizeFormDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        [$year, $month, $day] = array_map(static fn (string $part): int => (int) $part, explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function issuePasswordResetToken(PDO $db, string $email): ?array
    {
        $stmt = $db->prepare(
            'SELECT first_name, last_name, email
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if (!is_array($user)) {
            return null;
        }

        $db->prepare('UPDATE customer_password_resets SET used_at = NOW() WHERE email = :email AND used_at IS NULL')
            ->execute(['email' => $email]);

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        $insert = $db->prepare(
            'INSERT INTO customer_password_resets (email, token_hash, expires_at)
             VALUES (:email, :token_hash, :expires_at)'
        );
        $insert->execute([
            'email' => $email,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);

        return [
            'email' => (string) ($user['email'] ?? $email),
            'name' => trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')),
            'token' => $token,
        ];
    }

    private function findValidPasswordReset(PDO $db, string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $tokenHash = hash('sha256', $token);
        $stmt = $db->prepare(
            'SELECT id, email, expires_at, used_at
             FROM customer_password_resets
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        if (!empty($row['used_at'])) {
            return null;
        }
        $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < time()) {
            return null;
        }

        return $row;
    }

    private function sendPasswordResetEmail(PDO $db, string $email, string $name, string $token): void
    {
        if ($email === '' || $token === '') {
            return;
        }

        $settings = Settings::all($db);
        $displayName = trim($name);
        if ($displayName === '') {
            $displayName = 'client';
        }
        $link = $this->appUrl() . '/contul-meu/resetare-parola/' . urlencode($token);
        $subject = 'Resetare parolă cont';
        $html = '<p>Bună ' . htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>'
            . '<p>Am primit o cerere de resetare a parolei pentru contul tău.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' style="display:inline-block;padding:10px 16px;background:#0f8f7a;color:#fff;text-decoration:none;border-radius:8px;">'
            . 'Resetează parola</a></p>'
            . '<p>Dacă butonul nu funcționează, folosește acest link:</p>'
            . '<p>' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p>Link-ul expiră în 60 de minute.</p>';

        try {
            OrderMailer::sendCustom($email, $subject, $html, $settings, $db, [
                'email_type' => 'password_reset',
                'source' => 'customer_account',
                'trigger' => 'password_reset_request',
            ]);
        } catch (Throwable) {
            // Keep UX neutral; user receives same response regardless of email transport status.
        }
    }

    private function respondsWithJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($accept, 'application/json')
            || ((string) ($_POST['response'] ?? '') === 'json');
    }

    private function respondOptInSuccess(string $message): void
    {
        if ($this->respondsWithJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
            return;
        }
        Flash::set('success', $message);
        $redirect = (string) ($_SERVER['HTTP_REFERER'] ?? '/');
        if (!str_starts_with($redirect, '/')) {
            $host = parse_url($redirect, PHP_URL_HOST);
            $currentHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if (!is_string($host) || $host !== $currentHost) {
                $redirect = '/';
            }
        }
        header('Location: ' . ($redirect !== '' ? $redirect : '/'));
    }

    private function respondOptInError(string $message): void
    {
        if ($this->respondsWithJson()) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
            return;
        }
        Flash::set('error', $message);
        $redirect = (string) ($_SERVER['HTTP_REFERER'] ?? '/');
        if (!str_starts_with($redirect, '/')) {
            $host = parse_url($redirect, PHP_URL_HOST);
            $currentHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if (!is_string($host) || $host !== $currentHost) {
                $redirect = '/';
            }
        }
        header('Location: ' . ($redirect !== '' ? $redirect : '/'));
    }

    private function validateCheckout(): ?array
    {
        $required = [
            'billing_first_name' => 'Numele este obligatoriu.',
            'billing_last_name' => 'Prenumele este obligatoriu.',
            'billing_street' => 'Strada este obligatorie.',
            'billing_street_no' => 'Numărul este obligatoriu.',
            'billing_city' => 'Localitatea este obligatorie.',
            'billing_county' => 'Județul este obligatoriu.',
            'billing_postcode' => 'Codul poștal este obligatoriu.',
            'billing_phone' => 'Telefonul este obligatoriu.',
            'billing_email' => 'Emailul este obligatoriu.',
        ];

        $street = trim((string) ($_POST['billing_street'] ?? ''));
        $streetNo = trim((string) ($_POST['billing_street_no'] ?? ''));
        $addressLine1 = trim(($street . ' ' . $streetNo));
        if ($addressLine1 === '') {
            $addressLine1 = trim((string) ($_POST['billing_address_line1'] ?? ''));
        }

        $isCompany = isset($_POST['billing_is_company']) && (string) ($_POST['billing_is_company'] ?? '') === '1';
        $companyName = trim((string) ($_POST['billing_company_name'] ?? ''));
        $companyTaxId = trim((string) ($_POST['billing_company_tax_id'] ?? ''));
        $companyRegNo = trim((string) ($_POST['billing_company_registration_no'] ?? ''));

        $data = [
            'billing_first_name' => trim($_POST['billing_first_name'] ?? ''),
            'billing_last_name' => trim($_POST['billing_last_name'] ?? ''),
            'billing_street' => $street,
            'billing_street_no' => $streetNo,
            'billing_address_line1' => $addressLine1,
            'billing_address_line2' => trim($_POST['billing_address_line2'] ?? ''),
            'billing_city' => trim($_POST['billing_city'] ?? ''),
            'billing_county' => trim($_POST['billing_county'] ?? ''),
            'billing_postcode' => trim($_POST['billing_postcode'] ?? ''),
            'billing_phone' => trim($_POST['billing_phone'] ?? ''),
            'billing_email' => trim($_POST['billing_email'] ?? ''),
            'billing_is_company' => $isCompany ? 1 : 0,
            'billing_company_name' => $isCompany ? $companyName : '',
            'billing_company_tax_id' => $isCompany ? $companyTaxId : '',
            'billing_company_registration_no' => $isCompany ? $companyRegNo : '',
            'notes' => trim($_POST['notes'] ?? ''),
            'payment_method' => trim($_POST['payment_method'] ?? ''),
        ];

        // Metodele de card disponibile depind de ce e configurat în admin;
        // dacă niciuna nu e activă, rămâne rambursul. OP-ul intră doar dacă
        // e pornit din Setări plăți.
        $metodePermise = array_merge($this->metodeCardActive(), ['cod']);
        if ($this->opActiv()) {
            $metodePermise[] = 'bank_transfer';
        }
        if (!in_array($data['payment_method'], $metodePermise, true)) {
            $data['payment_method'] = $metodePermise[0] ?? 'cod';
        }

        // Adresă de livrare: implicit aceeași cu facturarea; dacă e diferită, se
        // completează câmpurile de livrare.
        $hasShippingToggle = (string) ($_POST['has_shipping_toggle'] ?? '') === '1';
        $shippingSame = $hasShippingToggle ? isset($_POST['shipping_same_as_billing']) : true;
        $data['shipping_same_as_billing'] = $shippingSame ? 1 : 0;
        if ($shippingSame) {
            $data['shipping_first_name'] = $data['billing_first_name'];
            $data['shipping_last_name'] = $data['billing_last_name'];
            $data['shipping_phone'] = $data['billing_phone'];
            $data['shipping_address_line1'] = $data['billing_address_line1'];
            $data['shipping_address_line2'] = $data['billing_address_line2'];
            $data['shipping_city'] = $data['billing_city'];
            $data['shipping_county'] = $data['billing_county'];
            $data['shipping_postcode'] = $data['billing_postcode'];
            $data['shipping_street'] = $data['billing_street'];
            $data['shipping_street_no'] = $data['billing_street_no'];
        } else {
            $shStreet = trim((string) ($_POST['shipping_street'] ?? ''));
            $shStreetNo = trim((string) ($_POST['shipping_street_no'] ?? ''));
            $data['shipping_first_name'] = trim((string) ($_POST['shipping_first_name'] ?? ''));
            $data['shipping_last_name'] = trim((string) ($_POST['shipping_last_name'] ?? ''));
            $data['shipping_phone'] = trim((string) ($_POST['shipping_phone'] ?? ''));
            $data['shipping_street'] = $shStreet;
            $data['shipping_street_no'] = $shStreetNo;
            $data['shipping_address_line1'] = trim($shStreet . ' ' . $shStreetNo);
            $data['shipping_address_line2'] = trim((string) ($_POST['shipping_address_line2'] ?? ''));
            $data['shipping_city'] = trim((string) ($_POST['shipping_city'] ?? ''));
            $data['shipping_county'] = trim((string) ($_POST['shipping_county'] ?? ''));
            $data['shipping_postcode'] = trim((string) ($_POST['shipping_postcode'] ?? ''));
        }

        // Consimțământul se verifică și pe server: bifa din pagină se poate
        // ocoli, iar dovada că a fost dat trebuie să fie a noastră, nu a
        // browserului. Momentul se scrie pe comandă.
        $data['terms_accepted_at'] = ((string) ($_POST['accept_terms'] ?? '')) === '1'
            ? date('Y-m-d H:i:s')
            : null;

        $_SESSION['checkout_form'] = $data;

        if ($data['terms_accepted_at'] === null) {
            Flash::set(
                'error',
                'Pentru a continua cumpărarea, trebuie să accepți termenii și condițiile și Politica de confidențialitate.',
            );
            return null;
        }

        foreach ($required as $field => $message) {
            if ($data[$field] === '') {
                Flash::set('error', $message);
                return null;
            }
        }

        if (!filter_var($data['billing_email'], FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Email invalid.');
            return null;
        }

        if (!preg_match('/^\d{6}$/', $data['billing_postcode'])) {
            Flash::set('error', 'Codul poștal trebuie să conțină exact 6 cifre.');
            return null;
        }

        if ($isCompany) {
            if ($companyName === '') {
                Flash::set('error', 'Completează denumirea completă a firmei.');
                return null;
            }
            if ($companyTaxId === '') {
                Flash::set('error', 'Completează CUI / codul fiscal.');
                return null;
            }
            if ($companyRegNo === '') {
                Flash::set('error', 'Completează numărul de ordine în Registrul Comerțului.');
                return null;
            }
        }

        if (($data['shipping_same_as_billing'] ?? 1) === 0) {
            $shippingRequired = [
                'shipping_first_name' => 'Numele pentru livrare este obligatoriu.',
                'shipping_last_name' => 'Prenumele pentru livrare este obligatoriu.',
                'shipping_phone' => 'Telefonul pentru livrare este obligatoriu.',
                'shipping_street' => 'Strada de livrare este obligatorie.',
                'shipping_street_no' => 'Numărul pentru livrare este obligatoriu.',
                'shipping_city' => 'Localitatea de livrare este obligatorie.',
                'shipping_county' => 'Județul de livrare este obligatoriu.',
                'shipping_postcode' => 'Codul poștal de livrare este obligatoriu.',
            ];
            foreach ($shippingRequired as $field => $message) {
                if (trim((string) ($data[$field] ?? '')) === '') {
                    Flash::set('error', $message);
                    return null;
                }
            }
            if (!preg_match('/^\d{6}$/', (string) $data['shipping_postcode'])) {
                Flash::set('error', 'Codul poștal de livrare trebuie să conțină exact 6 cifre.');
                return null;
            }
        }

        return $data;
    }

    private function generateOrderNumber(?PDO $db = null, array $settings = []): string
    {
        return OrderNumber::urmatorul($db, $settings);
    }

    private function normalizeProduct(array $product): array
    {
        $basePrice = max(0.0, (float) ($product['price'] ?? 0.0));
        $product['vat_percent'] = max(0.0, min(100.0, (float) ($product['vat_percent'] ?? 19.0)));
        $product['vat_included'] = ((int) ($product['vat_included'] ?? 1)) === 1 ? 1 : 0;
        $pricing = $this->resolveProductPricing(
            $basePrice,
            $product['sale_price'] ?? null,
            $product['sale_price_periods_json'] ?? '[]'
        );
        $product['base_price'] = $basePrice;
        $product['sale_price'] = $pricing['sale_price'] ?? null;
        $product['has_sale_price'] = (bool) ($pricing['has_sale_price'] ?? false);
        $product['price'] = (float) ($pricing['effective_price'] ?? $basePrice);
        $product['sale_price_periods'] = $pricing['periods'] ?? [];
        $product['bbd_enabled'] = (int) ($product['bbd_enabled'] ?? 0) === 1 ? 1 : 0;
        $product['bbd_entries'] = $this->normalizeProductBbdEntries($product['bbd_entries_json'] ?? '[]');
        $product['requires_bbd_selection'] = $this->productRequiresBbdSelection($product);
        $product['post_cart_note_enabled'] = (int) ($product['post_cart_note_enabled'] ?? 0) === 1 ? 1 : 0;
        $product['post_cart_note_text'] = trim((string) ($product['post_cart_note_text'] ?? ''));
        $product['out_of_stock'] = (int) ($product['out_of_stock'] ?? 0) === 1 ? 1 : 0;
        $product['discount_badge_mode'] = (string) ($product['discount_badge_mode'] ?? 'percent') === 'value' ? 'value' : 'percent';

        $imageUrl = trim((string) ($product['image_url'] ?? ''));
        if ($imageUrl === '' || str_contains($imageUrl, 'via.placeholder.com')) {
            $product['image_url'] = '/assets/img/product-placeholder.svg';
        }
        $galleryRaw = (string) (($product['gallery_images_json'] ?? $product['gallery_json']) ?? '');
        $gallery = [];
        if (trim($galleryRaw) !== '') {
            $decoded = json_decode($galleryRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $url = trim((string) $item);
                    if ($url === '' || in_array($url, $gallery, true)) {
                        continue;
                    }
                    $gallery[] = $url;
                    if (count($gallery) >= 12) {
                        break;
                    }
                }
            }
        }
        if ($gallery === []) {
            $gallery[] = (string) ($product['image_url'] ?? '/assets/img/product-placeholder.svg');
        }
        $product['gallery_images'] = $gallery;
        $badges = $this->buildProductBadgeList($product);
        $product['badge_html'] = $this->buildProductBadgesHtml($badges);

        return $product;
    }

    private function normalizeProductSalePeriods(mixed $raw): array
    {
        $decoded = [];
        if (is_string($raw) && trim($raw) !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $decoded = $json;
            }
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }

        $periods = [];
        $seen = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $start = $this->normalizeSalePeriodDate((string) ($item['start_date'] ?? $item['start'] ?? ''), false);
            $end = $this->normalizeSalePeriodDate((string) ($item['end_date'] ?? $item['end'] ?? ''), true);
            if ($start === '' || $end === '') {
                continue;
            }
            $startTs = strtotime($start);
            $endTs = strtotime($end);
            if ($startTs === false || $endTs === false || $startTs > $endTs) {
                continue;
            }
            $priceRaw = str_replace(',', '.', trim((string) ($item['sale_price'] ?? $item['price'] ?? '')));
            if ($priceRaw === '' || !is_numeric($priceRaw)) {
                continue;
            }
            $price = max(0.0, (float) $priceRaw);
            if ($price <= 0.0) {
                continue;
            }
            $key = $start . '|' . $end . '|' . number_format($price, 4, '.', '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $periods[] = [
                'start_date' => $start,
                'end_date' => $end,
                'sale_price' => (float) number_format($price, 2, '.', ''),
            ];
            if (count($periods) >= 24) {
                break;
            }
        }
        usort($periods, static function (array $left, array $right): int {
            return strcmp((string) ($left['start_date'] ?? ''), (string) ($right['start_date'] ?? ''));
        });
        return $periods;
    }

    private function normalizeSalePeriodDate(string $raw, bool $endOfDay): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return '';
        }
        return date($endOfDay ? 'Y-m-d 23:59:59' : 'Y-m-d 00:00:00', $timestamp);
    }

    private function resolveProductPricing(float $basePrice, mixed $salePriceRaw, mixed $salePeriodsRaw): array
    {
        $basePrice = max(0.0, $basePrice);
        $salePrice = null;
        if ($salePriceRaw !== null && $salePriceRaw !== '') {
            $candidate = max(0.0, (float) $salePriceRaw);
            if ($candidate > 0.0) {
                $salePrice = $candidate;
            }
        }

        $periods = $this->normalizeProductSalePeriods($salePeriodsRaw);
        $now = new DateTimeImmutable('now');
        $activePeriodSale = null;
        foreach ($periods as $period) {
            if (!is_array($period)) {
                continue;
            }
            $startRaw = (string) ($period['start_date'] ?? '');
            $endRaw = (string) ($period['end_date'] ?? '');
            $periodSale = max(0.0, (float) ($period['sale_price'] ?? 0.0));
            if ($startRaw === '' || $endRaw === '' || $periodSale <= 0.0) {
                continue;
            }
            try {
                $start = new DateTimeImmutable($startRaw);
                $end = new DateTimeImmutable($endRaw);
            } catch (Throwable) {
                continue;
            }
            if ($now < $start || $now > $end) {
                continue;
            }
            if ($activePeriodSale === null || $periodSale < $activePeriodSale) {
                $activePeriodSale = $periodSale;
            }
        }
        if ($activePeriodSale !== null) {
            $salePrice = $activePeriodSale;
        }

        $effectivePrice = $basePrice;
        $hasSalePrice = false;
        if ($salePrice !== null) {
            if ($basePrice <= 0.0) {
                $effectivePrice = $salePrice;
                $hasSalePrice = true;
            } elseif ($salePrice < $basePrice) {
                $effectivePrice = $salePrice;
                $hasSalePrice = true;
            }
        }

        return [
            'effective_price' => $effectivePrice,
            'sale_price' => $hasSalePrice ? $salePrice : null,
            'has_sale_price' => $hasSalePrice,
            'periods' => $periods,
        ];
    }

    private function normalizeProductBbdEntries(mixed $raw): array
    {
        $decoded = [];
        if (is_string($raw) && trim($raw) !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $decoded = $json;
            }
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }

        $entries = [];
        $seen = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $dateRaw = trim((string) ($item['date'] ?? $item['bbd_date'] ?? ''));
            $timestamp = strtotime($dateRaw);
            if ($dateRaw === '' || $timestamp === false) {
                continue;
            }
            $date = date('Y-m-d', $timestamp);

            $priceRaw = str_replace(',', '.', trim((string) ($item['reduced_price'] ?? $item['price'] ?? '')));
            $reducedPrice = null;
            if ($priceRaw !== '' && is_numeric($priceRaw)) {
                $candidate = max(0.0, (float) $priceRaw);
                if ($candidate > 0.0) {
                    $reducedPrice = (float) number_format($candidate, 2, '.', '');
                }
            }

            $lot = strtoupper(trim((string) ($item['lot'] ?? $item['bbd_lot'] ?? '')));
            if ($lot !== '') {
                $lot = substr((string) (preg_replace('/[^A-Z0-9\-_.\/]/', '', $lot) ?? ''), 0, 40);
            }

            $stockRaw = trim((string) ($item['stock'] ?? $item['bbd_stock'] ?? ''));
            $stock = null;
            if ($stockRaw !== '') {
                $stockNumeric = str_replace(',', '.', $stockRaw);
                if (is_numeric($stockNumeric)) {
                    $stock = max(0, (int) floor((float) $stockNumeric));
                }
            }

            $key = $this->normalizeProductBbdKey((string) ($item['key'] ?? ''));
            if ($key === '') {
                $key = substr(sha1($date . '|' . $lot . '|' . ($reducedPrice === null ? '-' : number_format($reducedPrice, 2, '.', ''))), 0, 20);
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $label = 'Expiră la data de: ' . date('d.m.Y', $timestamp);
            $entries[] = [
                'key' => $key,
                'date' => $date,
                'lot' => $lot,
                'label' => $label,
                'reduced_price' => $reducedPrice,
                'stock' => $stock,
                'is_available' => $stock === null ? true : $stock > 0,
            ];
            if (count($entries) >= 60) {
                break;
            }
        }

        usort($entries, static function (array $left, array $right): int {
            return strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
        });

        return $entries;
    }

    private function normalizeProductBbdKey(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^[a-z0-9]{1,64}$/', $raw) !== 1) {
            return '';
        }
        return $raw;
    }

    private function productRequiresBbdSelection(array $product): bool
    {
        if ((int) ($product['bbd_enabled'] ?? 0) !== 1) {
            return false;
        }
        $entries = is_array($product['bbd_entries'] ?? null)
            ? $product['bbd_entries']
            : $this->normalizeProductBbdEntries($product['bbd_entries_json'] ?? '[]');
        return $entries !== [];
    }

    private function resolveRequestedBbdSelection(array $product, string $requestedKey): array
    {
        if (!$this->productRequiresBbdSelection($product)) {
            return [];
        }
        $safeKey = $this->normalizeProductBbdKey($requestedKey);
        if ($safeKey === '') {
            return [];
        }
        $entries = is_array($product['bbd_entries'] ?? null)
            ? $product['bbd_entries']
            : $this->normalizeProductBbdEntries($product['bbd_entries_json'] ?? '[]');
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryKey = $this->normalizeProductBbdKey((string) ($entry['key'] ?? ''));
            if ($entryKey === '' || $entryKey !== $safeKey) {
                continue;
            }
            return $entry;
        }
        return [];
    }

    /**
     * Câte bucăți se pot vinde din produs. null = nelimitat (stocul nu e
     * urmărit prin ERP; fișa de pe site nu e destul de de încredere ca să
     * blocheze vânzări).
     */
    private function limitaStocProdus(array $product): ?int
    {
        if ((int) ($product['stock_from_erp'] ?? 0) !== 1) {
            return null;
        }

        return max(0, (int) ($product['stock'] ?? 0));
    }

    /** Cantitatea totală din coș pentru un produs, cu toate variantele lui. */
    private function cantitateInCos(int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }
        $total = 0;
        foreach (Cart::items() as $itemKey => $quantity) {
            $parsed = Cart::parseItemKey((string) $itemKey);
            if ((int) ($parsed['product_id'] ?? 0) === $productId) {
                $total += max(0, (int) $quantity);
            }
        }

        return $total;
    }

    private function mesajStocInsuficient(int $limita): string
    {
        if ($limita <= 0) {
            return 'Produsul este epuizat momentan.';
        }

        return $limita === 1
            ? 'Mai este o singură bucată în stoc.'
            : "Mai sunt doar {$limita} bucăți în stoc.";
    }

    private function cartQuantityForProductBbd(int $productId, string $bbdKey): int
    {
        if ($productId <= 0) {
            return 0;
        }
        $safeBbdKey = $this->normalizeProductBbdKey($bbdKey);
        if ($safeBbdKey === '') {
            return 0;
        }
        $total = 0;
        foreach (Cart::items() as $itemKey => $quantity) {
            $parsed = Cart::parseItemKey((string) $itemKey);
            $itemProductId = (int) ($parsed['product_id'] ?? 0);
            $itemBbdKey = $this->normalizeProductBbdKey((string) ($parsed['bbd_key'] ?? ''));
            if ($itemProductId !== $productId || $itemBbdKey !== $safeBbdKey) {
                continue;
            }
            $total += max(0, (int) $quantity);
        }
        return $total;
    }

    private function bbdSelectionAvailableStock(PDO $db, int $productId, string $bbdKey): ?int
    {
        $safeKey = $this->normalizeProductBbdKey($bbdKey);
        if ($productId <= 0 || $safeKey === '') {
            return null;
        }
        try {
            $stmt = $db->prepare(
                // Count only orders that genuinely reserve stock. Unpaid/abandoned
                // Stripe checkouts stay 'pending_payment' and must NOT consume stock,
                // otherwise abandoned or test checkouts permanently zero-out the offer.
                "SELECT COALESCE(SUM(oi.quantity), 0)
                 FROM order_items oi
                 INNER JOIN orders o ON o.id = oi.order_id
                 WHERE oi.product_id = :product_id
                   AND oi.bbd_key = :bbd_key
                   AND o.deleted_at IS NULL
                   AND o.status NOT IN ('cancelled', 'failed', 'refunded', 'pending_payment')"
            );
            $stmt->execute([
                'product_id' => $productId,
                'bbd_key' => $safeKey,
            ]);
            return max(0, (int) $stmt->fetchColumn());
        } catch (Throwable) {
            return null;
        }
    }

    private function decorateProductBbdEntriesWithAvailability(array $product): array
    {
        $entries = is_array($product['bbd_entries'] ?? null)
            ? $product['bbd_entries']
            : $this->normalizeProductBbdEntries($product['bbd_entries_json'] ?? '[]');
        if ($entries === []) {
            return $entries;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            foreach ($entries as &$entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entry['is_available'] = true;
                $entry['stock_remaining'] = null;
            }
            unset($entry);
            return $entries;
        }

        $productId = (int) ($product['id'] ?? 0);
        foreach ($entries as &$entry) {
            if (!is_array($entry)) {
                continue;
            }
            $stockRaw = $entry['stock'] ?? null;
            if ($stockRaw === null || $stockRaw === '') {
                $entry['is_available'] = true;
                $entry['stock_remaining'] = null;
                continue;
            }
            $stock = max(0, (int) $stockRaw);
            $entryKey = $this->normalizeProductBbdKey((string) ($entry['key'] ?? ''));
            if ($entryKey === '') {
                $entry['is_available'] = true;
                $entry['stock_remaining'] = null;
                continue;
            }
            $used = $this->bbdSelectionAvailableStock($db, $productId, $entryKey);
            $remaining = max(0, $stock - max(0, (int) ($used ?? 0)));
            $entry['stock'] = $stock;
            $entry['stock_remaining'] = $remaining;
            $entry['is_available'] = $remaining > 0;
        }
        unset($entry);

        return $entries;
    }

    private function ensureProductCustomSchema(PDO $db): void
    {
        CheckoutCalculator::ensureProductVatSchema($db);
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS product_extra_fields (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    field_key VARCHAR(120) NOT NULL UNIQUE,
                    field_type VARCHAR(30) NOT NULL DEFAULT "textarea",
                    placeholder VARCHAR(255) DEFAULT NULL,
                    default_value LONGTEXT DEFAULT NULL,
                    is_required TINYINT(1) NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    sort_order INT NOT NULL DEFAULT 0,
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
                    value LONGTEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_product_field (product_id, field_id),
                    KEY idx_product_field_product (product_id),
                    KEY idx_product_field_field (field_id)
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
                    KEY idx_product_reviews_product (product_id),
                    KEY idx_product_reviews_approved (is_approved)
                )'
            );
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE product_reviews ADD COLUMN source VARCHAR(50) DEFAULT NULL AFTER is_approved');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE product_reviews SET source = "product_page" WHERE source IS NULL OR source = ""');
        } catch (Throwable) {
        }
        try {
            $db->exec(
                'INSERT INTO product_reviews (product_id, user_name, rating, review_text, is_approved, created_at)
                 SELECT r.product_id, r.user_name, r.rating, r.review_text, r.is_approved, r.created_at
                 FROM reviews r
                 LEFT JOIN product_reviews pr
                    ON pr.product_id = r.product_id
                    AND pr.user_name = r.user_name
                    AND pr.rating = r.rating
                    AND ((pr.review_text IS NULL AND r.review_text IS NULL) OR pr.review_text = r.review_text)
                    AND pr.created_at = r.created_at
                 WHERE pr.id IS NULL'
            );
        } catch (Throwable) {
        }
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS product_templates (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    slug VARCHAR(190) NOT NULL UNIQUE,
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
            $db->exec('ALTER TABLE products ADD COLUMN product_template_id INT UNSIGNED DEFAULT NULL AFTER category_id');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE products ADD COLUMN vat_percent DECIMAL(5,2) NOT NULL DEFAULT 19.00 AFTER price');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE products ADD COLUMN vat_included TINYINT(1) NOT NULL DEFAULT 1 AFTER vat_percent');
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
            $db->exec('ALTER TABLE products ADD COLUMN out_of_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER stock');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE products ADD COLUMN gallery_images_json LONGTEXT DEFAULT NULL AFTER image_url');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE products ADD COLUMN similar_products_json LONGTEXT DEFAULT NULL AFTER gallery_images_json');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE products ADD COLUMN product_highlights TEXT DEFAULT NULL AFTER description');
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
            $db->exec('UPDATE products SET gallery_images_json = gallery_json WHERE (gallery_images_json IS NULL OR gallery_images_json = "") AND gallery_json IS NOT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE products SET gallery_images_json = image_gallery_json WHERE (gallery_images_json IS NULL OR gallery_images_json = "") AND image_gallery_json IS NOT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE products SET badge_popular = label_popular WHERE badge_popular = 0 AND label_popular = 1');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE products SET badge_best_seller = label_best_seller WHERE badge_best_seller = 0 AND label_best_seller = 1');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE products SET badge_seasonal = label_seasonal WHERE badge_seasonal = 0 AND label_seasonal = 1');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE product_extra_fields ADD COLUMN name VARCHAR(190) DEFAULT NULL AFTER id');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE product_extra_fields ADD COLUMN field_type VARCHAR(30) NOT NULL DEFAULT "textarea" AFTER field_key');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE product_extra_fields ADD COLUMN default_value LONGTEXT DEFAULT NULL AFTER placeholder');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE product_extra_fields ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER default_value');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE product_extra_fields SET name = label WHERE (name IS NULL OR name = "") AND label IS NOT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE product_extra_fields SET field_type = input_type WHERE (field_type IS NULL OR field_type = "") AND input_type IS NOT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE product_templates ADD COLUMN description VARCHAR(255) DEFAULT NULL AFTER slug');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE product_templates ADD COLUMN html_content LONGTEXT NULL AFTER description');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE product_templates ADD COLUMN css_content LONGTEXT NULL AFTER html_content');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE product_templates ADD COLUMN js_content LONGTEXT NULL AFTER css_content');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE product_templates SET html_content = html_template WHERE (html_content IS NULL OR html_content = "") AND html_template IS NOT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE product_templates SET css_content = css_template WHERE css_content IS NULL AND css_template IS NOT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE product_templates SET js_content = js_template WHERE js_content IS NULL AND js_template IS NOT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE product_extra_field_values ADD COLUMN `value` LONGTEXT DEFAULT NULL AFTER field_id');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE product_extra_field_values SET `value` = value_text WHERE (`value` IS NULL OR `value` = "") AND value_text IS NOT NULL');
        } catch (Throwable) {
        }
    }

    private function loadProductExtraFieldsForSite(PDO $db, int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }
        $stmt = $db->prepare(
            'SELECT f.id, f.name, f.field_key, f.field_type, f.placeholder, f.sort_order,
                    v.`value` AS field_value
             FROM product_extra_fields f
             LEFT JOIN product_extra_field_values v ON v.field_id = f.id AND v.product_id = :product_id
             WHERE f.is_active = 1
             ORDER BY f.sort_order ASC, f.id ASC'
        );
        $stmt->execute(['product_id' => $productId]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }
        $fields = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['field_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $fields[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'key' => $key,
                'type' => (string) ($row['field_type'] ?? 'text'),
                'placeholder' => (string) ($row['placeholder'] ?? ''),
                'value' => (string) ($row['field_value'] ?? ''),
            ];
        }
        return $fields;
    }

    private function loadProductTemplateForSite(PDO $db, int $templateId): ?array
    {
        if ($templateId <= 0) {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT id, name, slug, html_content, css_content, js_content, is_active
             FROM product_templates
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $templateId]);
        $row = $stmt->fetch() ?: null;
        if (!is_array($row) || (int) ($row['is_active'] ?? 0) !== 1) {
            return null;
        }
        return $row;
    }

    private function buildProductTemplateRender(
        array $template,
        array $product,
        array $extraFields,
        array $settings = [],
        array $productReviews = [],
        array $similarProducts = []
    ): array
    {
        $shortDescription = (string) ($product['short_description'] ?? '');
        $fullDescription = trim((string) ($product['description'] ?? ''));
        $productHighlights = trim((string) ($product['product_highlights'] ?? ''));
        $productHighlightsHtml = $this->buildProductHighlightsHtml($productHighlights);
        if ($fullDescription === '') {
            $fullDescription = $shortDescription;
        }
        // Descrierile pot conține HTML editorial (inclusiv cele importate din
        // WooCommerce), deci sunt curățate și randate ca markup, nu escapate.
        $shortDescription = \App\Support\RichText::render($shortDescription);
        $fullDescription = \App\Support\RichText::render($fullDescription);
        $productId = (int) ($product['id'] ?? 0);
        $quantityControlStyle = (string) ($settings['store_quantity_control_style'] ?? 'default');
        $applyQuantityOnProductTemplate = (string) ($settings['store_quantity_apply_product_template'] ?? '0') === '1';
        $productName = (string) ($product['name'] ?? '');
        $productSlug = (string) ($product['slug'] ?? '');
        $productImages = $this->extractProductImageUrls($product, true);
        $mainImage = $productImages[0] ?? '/assets/img/product-placeholder.svg';
        // Caruselul este singura galerie din pagina produsului, deci trebuie
        // să înceapă cu imaginea principală (cea din listări). Duplicatele
        // sunt eliminate, dacă imaginea principală apare și în galerie.
        $carouselImages = $this->extractProductImageUrls($product, true);
        $galleryHtml = $this->buildProductImageGalleryHtml($productImages, $productName);
        $badges = $this->buildProductBadgeList($product);
        $badgesHtml = $this->buildProductBadgesHtml($badges);
        $galleryCarouselHtml = $this->buildProductImageCarouselHtml($carouselImages, $productName, $badgesHtml);
        $reviewSectionHtml = (string) ($productReviews['section_html'] ?? '');
        $reviewsListHtml = (string) ($productReviews['list_html'] ?? '');
        $reviewFormHtml = (string) ($productReviews['form_html'] ?? '');
        $reviewsCount = (int) ($productReviews['count'] ?? 0);
        $reviewsAverage = (float) ($productReviews['average'] ?? 0.0);
        $reviewsAverageLabel = (string) ($productReviews['average_label'] ?? number_format($reviewsAverage, 1, '.', ''));
        $reviewsStarsHtml = (string) ($productReviews['stars_html'] ?? '');
        $tabsSectionHtml = $this->buildProductTabsSectionHtml(
            $extraFields,
            $reviewsListHtml,
            $reviewFormHtml,
            $reviewsCount,
            $reviewsAverageLabel,
            $reviewsStarsHtml,
            $similarProducts
        );
        $similarProductsSectionHtml = $this->buildSimilarProductsSectionHtml($similarProducts);
        $productPrice = (float) ($product['price'] ?? 0);
        $productBasePrice = (float) ($product['base_price'] ?? $productPrice);
        $productSalePrice = (float) (($product['sale_price'] ?? 0) ?: 0);
        $productCategory = (string) (($product['category_name'] ?? $product['category']) ?? '');
        $productCategoryUrl = '/magazin';
        if (trim($productCategory) !== '') {
            $productCategoryUrl .= '?category=' . rawurlencode(trim($productCategory));
        }
        $hasSaleForTemplate = $productSalePrice > 0
            && $productBasePrice > 0
            && $productPrice > 0
            && $productSalePrice < $productBasePrice
            && abs($productPrice - $productSalePrice) < 0.0001;
        $requiresBbdSelection = $this->productRequiresBbdSelection($product);
        $productBbdSelectorHtml = $this->buildProductBbdSelectorHtml($product);
        $templateHtmlRaw = (string) ($template['html_content'] ?? '');
        $templateHasBbdPlaceholder = str_contains($templateHtmlRaw, '{{product_bbd_selector}}')
            || str_contains($templateHtmlRaw, '{{product:bbd_selector}}');
        $productQuantityInputHtml = $this->buildProductQuantityInputHtml(
            $quantityControlStyle,
            $applyQuantityOnProductTemplate,
            (int) ($product['out_of_stock'] ?? 0) === 1,
            $requiresBbdSelection,
            $this->limitaStocProdus($product)
        );
        if ($requiresBbdSelection && !$templateHasBbdPlaceholder && $productBbdSelectorHtml !== '') {
            // Keep BBD selector close to quantity/add-to-cart controls, not at top of template.
            $productQuantityInputHtml = $productBbdSelectorHtml . $productQuantityInputHtml;
        }
        $productPostCartNoteHtml = $this->buildProductPostCartNoteHtml($product);
        $map = [
            'product_name' => $productName,
            'product_slug' => $productSlug,
            'product_sku' => (string) ($product['sku'] ?? ''),
            'product_short_description' => $shortDescription,
            'product_description' => $fullDescription,
            'product_highlights' => $productHighlightsHtml,
            'product_price' => number_format($productPrice, 2, '.', ''),
            'product_price_label' => number_format($productPrice, 2) . ' lei',
            'product_price_display' => number_format($productPrice, 2) . ' lei',
            'product_sale_price' => $productSalePrice > 0 ? number_format($productSalePrice, 2, '.', '') : '',
            'product_regular_price' => number_format($productBasePrice, 2, '.', ''),
            'product_regular_price_display' => number_format($productBasePrice, 2) . ' lei',
            'product_old_price' => $hasSaleForTemplate ? number_format($productBasePrice, 2, '.', '') : '',
            'product_old_price_display' => $hasSaleForTemplate ? number_format($productBasePrice, 2) . ' lei' : '',
            'product_has_sale_price' => $hasSaleForTemplate ? '1' : '0',
            'product_image_url' => $mainImage,
            'product_image_gallery' => $galleryHtml,
            'product_image_carousel' => $galleryCarouselHtml,
            'product_category' => $productCategory,
            'product_category_url' => $productCategoryUrl,
            'product_reviews_count' => (string) $reviewsCount,
            'product_reviews_average' => $reviewsAverageLabel,
            'product_reviews_average_raw' => number_format($reviewsAverage, 2, '.', ''),
            'product_reviews_stars' => $reviewsStarsHtml,
            'product_reviews_list' => $reviewsListHtml,
            'product_review_form' => $reviewFormHtml,
            'product_reviews_section' => $reviewSectionHtml,
            'product_reviews' => $reviewSectionHtml,
            'product_reviews_block' => $reviewSectionHtml,
            'product_tabs_section' => $tabsSectionHtml,
            'product_similar_products_section' => $similarProductsSectionHtml,
            'product_icon_leaf' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.1 5.1c-6 .2-10.4 3.9-10.4 6.5 0 2.9 2 4.9 4.5 4.9 4.2 0 7.7-4 8.2-9.6.1-.9-.6-1.8-2.3-1.8Z" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.6 20.4c2.1-4.7 6.1-8 11.3-10.2" fill="none" stroke="currentColor" stroke-width="1.45" stroke-linecap="round"/></svg>',
            'product_icon_truck' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.8 7.8h9.8v6.6H3.8Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M13.6 10.3h3.6l2.7 2.7v1.4h-6.3Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M5.8 16.9h1" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M16.2 16.9h1.1" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><circle cx="8" cy="18" r="1.75" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.5" cy="18" r="1.75" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>',
            'product_icon_shield' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v6c0 5 3.5 8.6 7 10 3.5-1.4 7-5 7-10V6l-7-3Z"/><path d="m9.4 12.3 1.8 1.8 3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'product_icon_star' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.9 5.88 6.5.95-4.7 4.58 1.1 6.49L12 17.8 6.2 20.9l1.1-6.49L2.6 9.83l6.5-.95L12 3z"/></svg>',
            'product_icon_camera' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5h6l1.2 2H20a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h3.8L9 5zm3 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg>',
            'product_icon_sparkles' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l1.8 4.2L18 8l-4.2 1.8L12 14l-1.8-4.2L6 8l4.2-1.8L12 2zm7 9l1 2.3L22 14l-2 .7L19 17l-1-2.3L16 14l2-.7L19 11zM5 14l1.2 2.8L9 18l-2.8 1.2L5 22l-1.2-2.8L1 18l2.8-1.2L5 14z"/></svg>',
            'product_icon_calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v3H7V2zm8 0h2v3h-2V2zM4 5h16a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zm0 5v10h16V10H4z"/></svg>',
            'product_icon_user' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-5 0-9 2.5-9 5.5V22h18v-2.5c0-3-4-5.5-9-5.5z"/></svg>',
            'product_bbd_selector' => $productBbdSelectorHtml,
            'product_requires_bbd' => $requiresBbdSelection ? '1' : '0',
            'product_quantity_input' => $productQuantityInputHtml,
            'product_add_to_cart_button' => $this->buildProductAddToCartButtonHtml(
                $productId,
                $productPrice,
                (int) ($product['out_of_stock'] ?? 0) === 1,
                $requiresBbdSelection
            ) . $productPostCartNoteHtml,
            'product_post_cart_note' => $productPostCartNoteHtml,
        ];
        foreach ($map as $key => $value) {
            if (strpos($key, 'product_') === 0) {
                $map['product:' . substr($key, 8)] = $value;
            }
        }
        foreach ($extraFields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $map['field_' . $key] = (string) ($field['value'] ?? '');
            $map['field:' . $key] = (string) ($field['value'] ?? '');
        }

        $html = $templateHtmlRaw;
        $css = (string) ($template['css_content'] ?? '');
        $js = (string) ($template['js_content'] ?? '');
        foreach ($map as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            if (in_array($key, [
                'product_image_gallery',
                'product_image_carousel',
                'product_reviews_stars',
                'product_reviews_list',
                'product_review_form',
                'product_reviews_section',
                'product_reviews',
                'product_reviews_block',
                'product_tabs_section',
                'product_similar_products_section',
                'product_icon_leaf',
                'product_icon_truck',
                'product_icon_shield',
                'product_icon_star',
                'product_icon_camera',
                'product_icon_sparkles',
                'product_icon_calendar',
                'product_icon_user',
                'product_bbd_selector',
                'product_highlights',
                'product_description',
                'product_short_description',
                'product_quantity_input',
                'product_add_to_cart_button',
                'product_post_cart_note',
            ], true)) {
                $html = str_replace($placeholder, $value, $html);
            } else {
                $html = str_replace($placeholder, htmlspecialchars($value, ENT_QUOTES), $html);
            }
            $css = str_replace($placeholder, $value, $css);
            $js = str_replace($placeholder, $value, $js);
        }

        return [
            'html' => $html,
            'css' => $css,
            'js' => $js,
        ];
    }

    private function buildProductQuantityInputHtml(
        string $controlStyle,
        bool $enabled,
        bool $isOutOfStock = false,
        bool $requiresBbdSelection = false,
        ?int $stocMaxim = null
    ): string {
        if ($isOutOfStock) {
            return '';
        }
        $disabledAttr = $requiresBbdSelection ? ' disabled data-requires-bbd="1"' : '';
        // Limita de stoc merge în HTML, ca butonul „+" și tastarea să se
        // oprească la ea fără să mai întrebe serverul.
        $limitaAttr = $stocMaxim !== null && $stocMaxim > 0
            ? ' max="' . $stocMaxim . '" data-stoc-maxim="' . $stocMaxim . '"'
            : '';
        $noteHtml = $stocMaxim !== null && $stocMaxim > 0
            ? '<p class="qty-stoc-note" data-stoc-note hidden>Cantitate maximă în stoc</p>'
            : '';
        if (!$enabled || $controlStyle !== 'stepper') {
            return $noteHtml
                . '<input id="quantity" name="quantity" type="number" min="1" value="1" data-product-main-quantity="1"' . $limitaAttr . $disabledAttr . ' style="width:90px;padding:8px;border:1px solid #d1d5db;border-radius:8px;">';
        }

        // Grilă fixă (buton | câmp | buton): rămâne aliniat indiferent de
        // lățimea coloanei în care este pus și de stilurile temei.
        return $noteHtml
            . '<div class="qty-stepper" style="display:grid;grid-template-columns:40px minmax(0,1fr) 40px;align-items:center;gap:0;width:100%;max-width:170px;box-sizing:border-box;border:1px solid #d1d9e3;border-radius:12px;background:#fff;padding:3px;height:48px;vertical-align:middle;">'
            . '<button type="button" class="qty-stepper__btn" data-role="qty-minus" aria-label="Scade cantitatea"' . ($requiresBbdSelection ? ' disabled data-requires-bbd="1"' : '') . ' style="border:0;background:#eef4f4;border-radius:9px;width:100%;height:100%;line-height:1;font-size:20px;color:#0f3f46;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;">−</button>'
            . '<input id="quantity" name="quantity" type="number" min="1" value="1" class="qty-stepper__input" data-product-main-quantity="1"' . $limitaAttr . ($requiresBbdSelection ? ' disabled data-requires-bbd="1"' : '') . ' style="width:100%;min-width:0;border:0;background:transparent;text-align:center;font-size:16px;font-weight:700;color:#0f172a;padding:0;margin:0;outline:none;line-height:1;height:100%;display:block;box-sizing:border-box;-moz-appearance:textfield;">'
            . '<button type="button" class="qty-stepper__btn" data-role="qty-plus" aria-label="Crește cantitatea"' . ($requiresBbdSelection ? ' disabled data-requires-bbd="1"' : '') . ' style="border:0;background:#eef4f4;border-radius:9px;width:100%;height:100%;line-height:1;font-size:20px;color:#0f3f46;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;">+</button>'
            . '</div>';
    }

    private function buildProductAddToCartButtonHtml(int $productId, float $unitPrice, bool $isOutOfStock = false, bool $requiresBbdSelection = false): string
    {
        if ($isOutOfStock) {
            return '<span class="product-out-of-stock-label" style="display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 18px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:15px;font-weight:700;border-radius:999px;">Stoc epuizat</span>';
        }
        $safeProductId = max(0, $productId);
        $safeUnitPrice = max(0.0, $unitPrice);
        $priceLabel = number_format($safeUnitPrice, 2, '.', '') . ' lei';
        $disabledAttr = $requiresBbdSelection ? ' disabled data-product-requires-bbd="1"' : '';

        return '<button class="btn" type="button" data-product-cart-button="1" data-product-id="' . $safeProductId . '" data-cart-url="/cos/adauga/' . $safeProductId . '" data-unit-price="' . htmlspecialchars((string) $safeUnitPrice, ENT_QUOTES) . '"' . $disabledAttr . ' style="display:inline-flex;align-items:center;gap:8px;height:48px;padding:0 24px;border:1px solid #107a4d;background:#107a4d;color:#fff;font-size:16px;font-weight:600;border-radius:999px;">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true" style="width:18px;height:18px;flex:0 0 auto;"><path d="M3 4h2l1.7 8.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 1.9-1.4l1.5-5.2H7.4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.7" cy="19" r="1.4" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.5" cy="19" r="1.4" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>'
            . '<span style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">'
            . '<span>Adaugă în coș -</span>'
            . '<span data-cart-button-total>' . htmlspecialchars($priceLabel, ENT_QUOTES) . '</span>'
            . '</span>'
            . '</button>';
    }

    private function buildProductPostCartNoteHtml(array $product): string
    {
        if ((int) ($product['post_cart_note_enabled'] ?? 0) !== 1) {
            return '';
        }
        $text = trim((string) ($product['post_cart_note_text'] ?? ''));
        if ($text === '') {
            return '';
        }

        return '<div class="product-post-cart-note" style="margin-top:10px;font:500 13px/1.4 \'DM Sans\',Arial,sans-serif;color:#475569;">'
            . nl2br(htmlspecialchars($text, ENT_QUOTES))
            . '</div>';
    }

    private function buildProductBbdSelectorHtml(array $product): string
    {
        if (!$this->productRequiresBbdSelection($product)) {
            return '';
        }
        $entries = is_array($product['bbd_entries'] ?? null)
            ? $product['bbd_entries']
            : $this->normalizeProductBbdEntries($product['bbd_entries_json'] ?? '[]');
        if ($entries === []) {
            return '';
        }

        $defaultUnitPrice = max(0.0, (float) ($product['price'] ?? 0.0));
        $defaultUnitPriceAttr = htmlspecialchars(number_format($defaultUnitPrice, 2, '.', ''), ENT_QUOTES);
        $buttons = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = $this->normalizeProductBbdKey((string) ($entry['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $availableStock = $this->resolveBbdEntryAvailableStock($entry);
            $dateLabel = trim((string) ($entry['date'] ?? ''));
            $timestamp = $dateLabel !== '' ? strtotime($dateLabel) : false;
            if ($timestamp !== false) {
                $dateLabel = 'Expiră: ' . date('d.m.Y', $timestamp);
            } else {
                $dateLabel = trim((string) ($entry['label'] ?? ''));
            }
            if ($dateLabel === '') {
                $dateLabel = $key;
            }
            $price = $entry['reduced_price'] ?? null;
            $entryUnitPrice = $defaultUnitPrice;
            if ($price !== null && is_numeric((string) $price) && (float) $price > 0.0) {
                $entryUnitPrice = max(0.0, (float) $price);
            }
            $disabledAttr = ($availableStock !== null && $availableStock <= 0) ? ' disabled data-bbd-out-of-stock="1"' : '';
            if ($availableStock !== null && $availableStock <= 0) {
                $stockHtml = '<em>Stoc epuizat</em>';
            } elseif ($availableStock !== null && $availableStock < 10) {
                $stockHtml = '<em class="product-bbd-pill__low">Doar ' . $availableStock . ' în stoc</em>';
            } else {
                $stockHtml = '';
            }
            $buttons[] = '<button type="button" class="product-bbd-pill" data-bbd-option="1" data-bbd-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-unit-price="' . htmlspecialchars(number_format($entryUnitPrice, 2, '.', ''), ENT_QUOTES) . '"' . $disabledAttr . '>'
                . '<span>' . htmlspecialchars($dateLabel, ENT_QUOTES) . '</span>'
                . '<strong>' . htmlspecialchars(number_format($entryUnitPrice, 2) . ' lei', ENT_QUOTES) . '</strong>'
                . $stockHtml
                . '</button>';
        }
        if ($buttons === []) {
            return '';
        }

        return '<div class="product-bbd-selector" style="display:grid;gap:6px;margin:0 0 12px;">'
            . '<span style="font-weight:600;color:#274136;">Alege oferta</span>'
            . '<input id="product-bbd-select" name="bbd_key" type="hidden" value="" data-product-bbd-select="1" data-bbd-select="1" data-unit-price="' . $defaultUnitPriceAttr . '">'
            . '<div class="product-bbd-choice-group" data-bbd-choice-group="1">'
            . implode('', $buttons)
            . '</div>'
            . '</div>';
    }

    private function resolveBbdEntryAvailableStock(array $entry): ?int
    {
        $stockRaw = $entry['stock_remaining'] ?? $entry['stock'] ?? null;
        if ($stockRaw === null || $stockRaw === '') {
            return null;
        }
        if (!is_numeric((string) $stockRaw)) {
            return null;
        }
        return max(0, (int) $stockRaw);
    }

    private function bbdSelectionHasAvailableStock(array $bbdSelection, int $requestedQuantity): bool
    {
        $available = $this->resolveBbdEntryAvailableStock($bbdSelection);
        if ($available === null) {
            return true;
        }
        return $available >= max(1, $requestedQuantity);
    }

    private function buildProductHighlightsHtml(string $rawHighlights): string
    {
        $rawHighlights = trim($rawHighlights);
        if ($rawHighlights === '') {
            return '';
        }

        $tokens = preg_split('/[\r\n;|]+/', $rawHighlights) ?: [];
        $items = [];
        $leaf = '<svg class="product-highlights__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M19.1 5.1c-6 .2-10.4 3.9-10.4 6.5 0 2.9 2 4.9 4.5 4.9 4.2 0 7.7-4 8.2-9.6.1-.9-.6-1.8-2.3-1.8Z" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.6 20.4c2.1-4.7 6.1-8 11.3-10.2" fill="none" stroke="currentColor" stroke-width="1.45" stroke-linecap="round"/></svg>';
        foreach ($tokens as $token) {
            $text = trim((string) $token);
            if ($text === '') {
                continue;
            }
            $items[] = '<li class="product-highlights__item">' . $leaf . '<span>' . htmlspecialchars($text, ENT_QUOTES) . '</span></li>';
        }

        if ($items === []) {
            return '';
        }

        return '<ul class="product-highlights" aria-label="Puncte forte">' . implode('', $items) . '</ul>';
    }

    private function extractProductImageUrls(array $product, bool $includeMain = true): array
    {
        $urls = [];
        $main = trim((string) ($product['image_url'] ?? ''));
        if ($includeMain && $main !== '') {
            $urls[] = $main;
        }

        $galleryCandidates = [];
        if (isset($product['gallery_images']) && is_array($product['gallery_images'])) {
            $galleryCandidates = $product['gallery_images'];
        } else {
            $rawGallery = trim((string) (($product['gallery_images_json'] ?? $product['gallery_json']) ?? ''));
            if ($rawGallery !== '') {
                $decoded = json_decode($rawGallery, true);
                if (is_array($decoded)) {
                    $galleryCandidates = $decoded;
                }
            }
        }

        foreach ($galleryCandidates as $item) {
            $url = trim((string) $item);
            if ($url === '' || in_array($url, $urls, true)) {
                continue;
            }
            if (!$includeMain && $main !== '' && $url === $main) {
                continue;
            }
            $urls[] = $url;
            if (count($urls) >= 12) {
                break;
            }
        }

        if ($urls === []) {
            $urls[] = $main !== '' ? $main : '/assets/img/product-placeholder.svg';
        }
        return $urls;
    }

    private function buildProductImageGalleryHtml(array $urls, string $productName): string
    {
        $items = [];
        foreach ($urls as $url) {
            $safeUrl = htmlspecialchars((string) $url, ENT_QUOTES);
            $safeAlt = htmlspecialchars($productName, ENT_QUOTES);
            $items[] = '<img src="' . $safeUrl . '" alt="' . $safeAlt . '" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';">';
        }
        return '<div class="product-image-gallery">' . implode('', $items) . '</div>';
    }

    private function buildProductImageCarouselHtml(array $urls, string $productName, string $badgesHtml = ''): string
    {
        $slides = [];
        $thumbs = [];
        foreach ($urls as $index => $url) {
            $safeUrl = htmlspecialchars((string) $url, ENT_QUOTES);
            $safeAlt = htmlspecialchars($productName, ENT_QUOTES);
            $slides[] = '<figure class="product-carousel__slide" data-slide="' . $index . '"><img src="' . $safeUrl . '" alt="' . $safeAlt . '" loading="lazy" decoding="async" draggable="false" data-carousel-image="1" data-target="' . $index . '" onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';"></figure>';
            $thumbs[] = '<button type="button" class="product-carousel__thumb" data-target="' . $index . '" aria-label="Imagine ' . ($index + 1) . '"><img src="' . $safeUrl . '" alt="' . $safeAlt . '" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';"></button>';
        }
        $overlayBadges = trim($badgesHtml) !== '' ? $badgesHtml : '';

        return '<div class="product-carousel" data-product-carousel data-current="0">'
            . '<div class="product-carousel__viewport" data-carousel-viewport>'
            . $overlayBadges
            . '<button type="button" class="product-carousel__fullscreen" data-action="fullscreen" aria-label="Afișează imaginea pe tot ecranul">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H4a1 1 0 0 0-1 1v4h2V5h3V3zm13 0h-4v2h3v3h2V4a1 1 0 0 0-1-1zM3 16v4a1 1 0 0 0 1 1h4v-2H5v-3H3zm17 0v3h-3v2h4a1 1 0 0 0 1-1v-4h-2z"/></svg>'
            . '</button>'
            . '<div class="product-carousel__track">' . implode('', $slides) . '</div></div>'
            . '<div class="product-carousel__controls">'
            . '<button type="button" class="product-carousel__nav" data-action="prev" aria-label="Imagine anterioară">‹</button>'
            . '<div class="product-carousel__thumbs">' . implode('', $thumbs) . '</div>'
            . '<button type="button" class="product-carousel__nav" data-action="next" aria-label="Imagine următoare">›</button>'
            . '</div>'
            . '</div>';
    }

    private function buildProductBadgeList(array $product): array
    {
        $badges = [];
        if ((int) ($product['badge_popular'] ?? 0) === 1 || (int) ($product['label_popular'] ?? 0) === 1) {
            $badges[] = ['slug' => 'popular', 'label' => 'Popular'];
        }
        if ((int) ($product['badge_best_seller'] ?? 0) === 1 || (int) ($product['label_best_seller'] ?? 0) === 1) {
            $badges[] = ['slug' => 'best-seller', 'label' => 'Cel mai bine vândut'];
        }
        if ((int) ($product['badge_seasonal'] ?? 0) === 1 || (int) ($product['label_seasonal'] ?? 0) === 1) {
            $badges[] = ['slug' => 'seasonal', 'label' => 'De sezon'];
        }
        return $badges;
    }

    private function buildProductBadgesHtml(array $badges): string
    {
        if ($badges === []) {
            return '';
        }
        $chunks = [];
        foreach ($badges as $badge) {
            if (!is_array($badge)) {
                continue;
            }
            $slug = htmlspecialchars((string) ($badge['slug'] ?? ''), ENT_QUOTES);
            $label = htmlspecialchars((string) ($badge['label'] ?? ''), ENT_QUOTES);
            if ($slug === '' || $label === '') {
                continue;
            }
            $chunks[] = '<span class="product-badge product-badge--' . $slug . '">' . $label . '</span>';
        }
        if ($chunks === []) {
            return '';
        }
        return '<div class="product-badges">' . implode('', $chunks) . '</div>';
    }

    private function loadProductReviewsForSite(PDO $db, array $product): array
    {
        $productId = (int) ($product['id'] ?? 0);
        if ($productId <= 0) {
            return [
                'count' => 0,
                'average' => 0.0,
                'average_label' => '0.0',
                'stars_html' => $this->buildReviewStarsHtml(0.0),
                'list_html' => '<p class="product-reviews-empty">Nu există review-uri încă.</p>',
                'form_html' => $this->buildReviewFormHtml((string) ($product['slug'] ?? ''), ''),
                'section_html' => $this->buildReviewSectionHtml('0.0', 0, $this->buildReviewStarsHtml(0.0), '<p class="product-reviews-empty">Nu există review-uri încă.</p>', $this->buildReviewFormHtml((string) ($product['slug'] ?? ''), ''), false),
                'items' => [],
            ];
        }

        try {
            $stmt = $db->prepare(
                'SELECT user_name, rating, review_text, created_at
                 FROM product_reviews
                 WHERE product_id = :product_id AND is_approved = 1
                 ORDER BY created_at DESC
                 LIMIT 50'
            );
            $stmt->execute(['product_id' => $productId]);
            $rows = $stmt->fetchAll();
        } catch (Throwable) {
            $stmt = $db->prepare(
                'SELECT user_name, rating, review_text, created_at
                 FROM reviews
                 WHERE product_id = :product_id AND is_approved = 1
                 ORDER BY created_at DESC
                 LIMIT 50'
            );
            $stmt->execute(['product_id' => $productId]);
            $rows = $stmt->fetchAll();
        }
        $items = is_array($rows) ? array_values(array_filter($rows, static fn ($row): bool => is_array($row))) : [];

        $count = count($items);
        $sum = 0.0;
        foreach ($items as $item) {
            $sum += max(1, min(5, (int) ($item['rating'] ?? 5)));
        }
        $average = $count > 0 ? ($sum / $count) : 0.0;
        $averageLabel = number_format($average, 1, '.', '');
        $starsHtml = $this->buildReviewStarsHtml($average);
        $listHtml = $this->renderProductReviewsListHtml($items);

        $defaultName = '';
        $customer = CustomerAuth::user($db);
        if (is_array($customer)) {
            $defaultName = trim((string) (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')));
        }
        $slug = (string) ($product['slug'] ?? '');
        $formHtml = $this->buildReviewFormHtml($slug, $defaultName);
        $sectionHtml = $this->buildReviewSectionHtml($averageLabel, $count, $starsHtml, $listHtml, $formHtml, false);

        return [
            'count' => $count,
            'average' => $average,
            'average_label' => $averageLabel,
            'stars_html' => $starsHtml,
            'list_html' => $listHtml,
            'form_html' => $formHtml,
            'section_html' => $sectionHtml,
            'items' => $items,
        ];
    }

    private function buildReviewStarsHtml(float $average): string
    {
        $value = max(0.0, min(5.0, $average));
        $full = (int) floor($value);
        $html = '<span class="product-reviews-stars" aria-label="' . htmlspecialchars(number_format($value, 1, '.', '') . ' din 5', ENT_QUOTES) . '">';
        for ($i = 0; $i < 5; $i++) {
            $active = $i < $full ? ' is-active' : '';
            $html .= '<span class="star' . $active . '">★</span>';
        }
        $html .= '</span>';
        return $html;
    }

    private function renderProductReviewsListHtml(array $items): string
    {
        if ($items === []) {
            return '<p class="product-reviews-empty">Nu există review-uri încă.</p>';
        }
        $chunks = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = htmlspecialchars((string) ($item['user_name'] ?? 'Client'), ENT_QUOTES);
            $rating = max(1, min(5, (int) ($item['rating'] ?? 5)));
            $text = nl2br(htmlspecialchars((string) ($item['review_text'] ?? ''), ENT_QUOTES));
            $dateRaw = (string) ($item['created_at'] ?? '');
            $date = '';
            if ($dateRaw !== '') {
                try {
                    $date = (new DateTimeImmutable($dateRaw))->format('d.m.Y');
                } catch (Throwable) {
                    $date = '';
                }
            }
            $stars = '';
            for ($i = 0; $i < 5; $i++) {
                $stars .= '<span class="star' . ($i < $rating ? ' is-active' : '') . '">★</span>';
            }
            $chunks[] = '<article class="product-review-item"><header><strong>' . $name . '</strong><div class="product-review-stars">' . $stars . '</div></header>' . ($date !== '' ? '<small>' . htmlspecialchars($date, ENT_QUOTES) . '</small>' : '') . '<p>' . $text . '</p></article>';
        }
        if ($chunks === []) {
            return '<p class="product-reviews-empty">Nu există review-uri încă.</p>';
        }
        return '<div class="product-reviews-list">' . implode('', $chunks) . '</div>';
    }

    private function buildReviewFormHtml(string $slug, string $defaultName): string
    {
        $safeSlug = rawurlencode($slug);
        $safeDefaultName = htmlspecialchars($defaultName, ENT_QUOTES);
        $defaultRating = 5;
        $ratingControls = '';
        for ($value = 1; $value <= 5; $value++) {
            $isActive = $value <= $defaultRating;
            $ratingControls .= '<button type="button" class="product-review-rating__star' . ($isActive ? ' is-active' : '') . '" data-rating-value="' . $value . '" aria-label="' . $value . ' stele" aria-pressed="' . ($isActive ? 'true' : 'false') . '">★</button>';
        }
        return '<form method="post" action="/produs/' . $safeSlug . '/review#product-reviews" class="product-review-form">'
            . '<h4>Lasă un review</h4>'
            . '<div class="field"><label>Nume</label><input type="text" name="review_name" required value="' . $safeDefaultName . '"></div>'
            . '<div class="field"><label>Rating</label><div class="product-review-rating" data-review-rating="1"><input type="hidden" name="review_rating" value="' . $defaultRating . '" data-review-rating-input="1">' . $ratingControls . '</div></div>'
            . '<div class="field"><label>Review</label><textarea name="review_text" rows="4" required></textarea></div>'
            . '<button class="btn" type="submit">Trimite review</button>'
            . '</form>';
    }

    private function buildReviewSectionHtml(
        string $averageLabel,
        int $count,
        string $starsHtml,
        string $listHtml,
        string $formHtml,
        bool $insideTabs = true
    ): string
    {
        $summaryText = htmlspecialchars($averageLabel, ENT_QUOTES) . ' din 5 · ' . (int) $count . ' review-uri';
        $headClass = $insideTabs ? 'product-reviews-head product-reviews-head--inside' : 'product-reviews-head';
        $label = $insideTabs ? '<span class="product-reviews-head__label">Recenzii</span>' : '';
        $headHtml = '<div class="' . $headClass . '"><div class="product-reviews-head__inline">' . $label . $starsHtml . '<p>' . $summaryText . '</p></div></div>';
        return '<section id="product-reviews" class="product-reviews">'
            . $headHtml
            . $listHtml
            . $formHtml
            . '</section>';
    }

    private function buildProductTabsSectionHtml(
        array $extraFields,
        string $reviewsListHtml,
        string $reviewFormHtml,
        int $reviewsCount,
        string $reviewsAverageLabel,
        string $reviewsStarsHtml,
        array $similarProducts = []
    ): string
    {
        $tabs = [];
        $panes = [];
        foreach ($extraFields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = trim((string) ($field['name'] ?? ''));
            if ($label === '') {
                $label = 'Detalii';
            }
            $rawValue = (string) ($field['value'] ?? '');
            $value = trim($rawValue);
            if ($value === '') {
                continue;
            }
            $fieldType = strtolower(trim((string) ($field['type'] ?? 'text')));
            $isHtmlField = $fieldType === 'html';
            $safeKey = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($key)) ?: 'field-' . count($tabs);
            $tabs[] = [
                'id' => 'field-' . $safeKey,
                'label' => $label,
                'anchor' => $safeKey,
            ];
            $panes[] = [
                'id' => 'field-' . $safeKey,
                'title' => $label,
                'label' => $label,
                'content_html' => $isHtmlField
                    ? $rawValue
                    : '<p>' . nl2br(htmlspecialchars($value, ENT_QUOTES)) . '</p>',
            ];
        }

        $reviewsSummaryText = htmlspecialchars($reviewsAverageLabel, ENT_QUOTES)
            . ' din 5 · '
            . (int) $reviewsCount
            . ' review-uri';
        $tabs[] = [
            'id' => 'reviews',
            'label' => 'Recenzii',
            'label_html' => '<span class="product-tabs__reviews-title">Recenzii</span>',
            'anchor' => 'recenzii',
        ];
        $tabs[] = [
            'id' => 'write-review',
            'label' => 'Lasă o recenzie',
            'label_html' => '<span class="product-tabs__reviews-title">Lasă o recenzie</span>',
        ];
        $panes[] = [
            'id' => 'reviews',
            'title' => 'Recenzii',
            'hide_title' => true,
            'content_html' => '<section id="product-reviews" class="product-reviews">'
                . '<div class="product-reviews-head product-reviews-head--inside">'
                . '<div class="product-reviews-head__inline">'
                . $reviewsStarsHtml
                . '<p>' . $reviewsSummaryText . '</p>'
                . '</div>'
                . '</div>'
                . ($reviewsListHtml !== '' ? $reviewsListHtml : '<p class="product-reviews-empty">Nu există review-uri încă.</p>')
                . '</section>',
        ];
        $panes[] = [
            'id' => 'write-review',
            'title' => 'Lasă o recenzie',
            'hide_title' => true,
            'content_html' => '<section id="product-review-form" class="product-reviews product-reviews--write">'
                . '<div class="product-reviews-head product-reviews-head--inside">'
                . '<div class="product-reviews-head__inline">'
                . '<span class="product-reviews-head__label">Scrie o recenzie</span>'
                . '<p>Spune-ne experiența ta cu acest produs.</p>'
                . '</div>'
                . '</div>'
                . ($reviewFormHtml !== '' ? $reviewFormHtml : '<p class="product-reviews-empty">Formular indisponibil.</p>')
                . '</section>',
        ];

        if ($tabs === [] || $panes === []) {
            return '';
        }

        $tabsHtml = [];
        foreach ($tabs as $index => $tab) {
            $labelHtml = isset($tab['label_html'])
                ? (string) $tab['label_html']
                : htmlspecialchars((string) ($tab['label'] ?? ''), ENT_QUOTES);
            $anchorAttr = isset($tab['anchor']) && (string) $tab['anchor'] !== ''
                ? ' data-anchor="' . htmlspecialchars((string) $tab['anchor'], ENT_QUOTES) . '"'
                : '';
            $tabsHtml[] = '<button type="button" class="product-tabs__tab' . ($index === 0 ? ' is-active' : '') . '" data-tab="' . htmlspecialchars((string) $tab['id'], ENT_QUOTES) . '"' . $anchorAttr . '>'
                . $labelHtml
                . '</button>';
        }

        $panesHtml = [];
        foreach ($panes as $index => $pane) {
            $panesHtml[] = '<article class="product-tabs__pane' . ($index === 0 ? ' is-active' : '') . '" data-pane="' . htmlspecialchars((string) $pane['id'], ENT_QUOTES) . '">'
                . (!((bool) ($pane['hide_title'] ?? false))
                    ? '<h3>' . htmlspecialchars((string) ($pane['label'] ?? $pane['title']), ENT_QUOTES) . '</h3>'
                    : '')
                . (string) $pane['content_html']
                . '</article>';
        }

        return '<section class="product-tabs" data-tabs-root="1">'
            . '<div class="product-tabs__nav" data-tabs-nav="1">' . implode('', $tabsHtml) . '</div>'
            . '<div class="product-tabs__content">' . implode('', $panesHtml) . '</div>'
            . '</section>';
    }

    /**
     * Pagina 404 a magazinului, cu design, căutare și câteva produse.
     * Înlocuiește pagina albă pe care o vedea clientul venit pe un link vechi.
     */
    private function renderPagina404(string $titlu, string $mesaj, ?PDO $db = null): void
    {
        http_response_code(404);
        $sugestii = [];
        if ($db instanceof PDO) {
            try {
                $sugestii = $db->query(
                    'SELECT slug, name FROM products
                     WHERE deleted_at IS NULL AND is_active = 1 AND slug <> ""
                     ORDER BY created_at DESC LIMIT 4'
                )->fetchAll() ?: [];
            } catch (Throwable) {
                $sugestii = [];
            }
        }
        View::render('site/not-found', [
            'titlu404' => $titlu,
            'mesaj404' => $mesaj,
            'produseSugerate' => $sugestii,
            'title' => $titlu,
        ]);
    }

    /**
     * Forma „de comparat" a unui slug: fără diacritice, doar litere și cifre.
     * „Kit-Vacanță_2024" și „kit vacanta 2024" ajung la același rezultat.
     */
    private function slugComparabil(string $value): string
    {
        $value = mb_strtolower(trim(rawurldecode($value)));
        $inlocuiri = [
            'ă' => 'a', 'â' => 'a', 'á' => 'a', 'à' => 'a', 'ä' => 'a',
            'î' => 'i', 'í' => 'i', 'ì' => 'i',
            'ș' => 's', 'ş' => 's', 'š' => 's',
            'ț' => 't', 'ţ' => 't',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss',
        ];
        $value = strtr($value, $inlocuiri);
        return (string) preg_replace('/[^a-z0-9]/', '', $value);
    }

    /**
     * Produsul potrivit pentru un slug care nu s-a găsit exact — pentru
     * linkurile rămase din site-ul vechi, din Google sau din bookmark-uri.
     * Întoarce produsul doar dacă potrivirea e lipsită de ambiguitate.
     *
     * @return array<string, mixed>|null
     */
    private function gasesteProdusDupaSlugAproximativ(?PDO $db, string $slug): ?array
    {
        $cautat = $this->slugComparabil($slug);
        if (!$db instanceof PDO || $cautat === '' || strlen($cautat) < 4) {
            return null;
        }

        try {
            $randuri = $db->query(
                'SELECT id, slug, name FROM products WHERE deleted_at IS NULL AND is_active = 1'
            )->fetchAll() ?: [];
        } catch (Throwable) {
            return null;
        }

        $potriviriExacte = [];
        $potriviriPartiale = [];
        foreach ($randuri as $rand) {
            $slugProdus = $this->slugComparabil((string) ($rand['slug'] ?? ''));
            $numeProdus = $this->slugComparabil((string) ($rand['name'] ?? ''));
            if ($slugProdus === '' && $numeProdus === '') {
                continue;
            }
            if ($slugProdus === $cautat || $numeProdus === $cautat) {
                $potriviriExacte[] = $rand;
                continue;
            }
            // Linkuri vechi cu sufixe/prefixe („...-2", „produs-...").
            $contineSlug = $slugProdus !== '' && (str_contains($slugProdus, $cautat) || str_contains($cautat, $slugProdus));
            $contineNume = $numeProdus !== '' && (str_contains($numeProdus, $cautat) || str_contains($cautat, $numeProdus));
            if ($contineSlug || $contineNume) {
                $potriviriPartiale[] = $rand;
            }
        }

        if (count($potriviriExacte) === 1) {
            return $potriviriExacte[0];
        }
        if ($potriviriExacte !== []) {
            // Mai multe potriviri exacte = date duble; nu ghicim.
            return null;
        }
        if (count($potriviriPartiale) === 1) {
            return $potriviriPartiale[0];
        }
        // „harmony" se potrivește și cu „cmo-harmony", și cu „cmo-harmony-xl".
        // Alegem produsul cu numele cel mai apropiat ca lungime — pentru un
        // link scurt, produsul de bază, nu varianta cu sufix. Dacă două
        // produse sunt la fel de apropiate, rămâne 404 (nu ghicim).
        if ($potriviriPartiale !== []) {
            $celMaiApropiat = null;
            $diferentaMinima = PHP_INT_MAX;
            $laEgalitate = 0;
            foreach ($potriviriPartiale as $candidat) {
                $lungimeSlug = strlen($this->slugComparabil((string) ($candidat['slug'] ?? '')));
                $diferenta = abs($lungimeSlug - strlen($cautat));
                if ($diferenta < $diferentaMinima) {
                    $diferentaMinima = $diferenta;
                    $celMaiApropiat = $candidat;
                    $laEgalitate = 1;
                } elseif ($diferenta === $diferentaMinima) {
                    $laEgalitate++;
                }
            }
            if ($laEgalitate === 1 && $celMaiApropiat !== null) {
                return $celMaiApropiat;
            }
        }
        return null;
    }

    private function buildSimilarProductsForSite(array $products, array $currentProduct): array
    {
        $items = [];
        $currentId = (int) ($currentProduct['id'] ?? 0);
        $selectedIds = [];
        $rawSelected = $currentProduct['similar_products_json'] ?? [];
        if (is_string($rawSelected) && trim($rawSelected) !== '') {
            $decoded = json_decode($rawSelected, true);
            if (is_array($decoded)) {
                $rawSelected = $decoded;
            } else {
                $rawSelected = [];
            }
        }
        if (is_array($rawSelected)) {
            foreach ($rawSelected as $value) {
                $id = (int) $value;
                if ($id <= 0 || $id === $currentId || in_array($id, $selectedIds, true)) {
                    continue;
                }
                $selectedIds[] = $id;
                if (count($selectedIds) >= 12) {
                    break;
                }
            }
        }

        $itemById = [];
        foreach ($products as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            // Products from loadProducts() are already normalized. Re-normalizing would
            // recompute base_price from the already-discounted price, collapsing
            // base_price to price and hiding the discount badge + old price.
            if (!isset($candidate['base_price'])) {
                $candidate = $this->normalizeProduct($candidate);
            }
            $id = (int) ($candidate['id'] ?? 0);
            $name = trim((string) ($candidate['name'] ?? ''));
            $slug = trim((string) ($candidate['slug'] ?? ''));
            if ($id <= 0 || $name === '' || $slug === '' || $id === $currentId) {
                continue;
            }
            $currentPrice = (float) ($candidate['price'] ?? 0);
            $regularPrice = (float) ($candidate['base_price'] ?? $currentPrice);
            // Use the normalized has_sale_price (accounts for scheduled sale periods),
            // not just the raw sale_price field — otherwise a period-based discount shows
            // the reduced price but no discount badge.
            $hasSalePrice = (bool) ($candidate['has_sale_price'] ?? false)
                && $regularPrice > $currentPrice
                && $currentPrice > 0;
            $item = [
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'url' => '/produs/' . rawurlencode($slug),
                'category' => (string) (($candidate['category_name'] ?? $candidate['category']) ?? ''),
                'image_url' => (string) ($candidate['image_url'] ?? '/assets/img/product-placeholder.svg'),
                'price' => $currentPrice,
                'regular_price' => $regularPrice,
                'has_sale_price' => $hasSalePrice ? 1 : 0,
                'discount_badge_mode' => (string) ($candidate['discount_badge_mode'] ?? 'percent') === 'value' ? 'value' : 'percent',
                'discount_value_label' => $hasSalePrice ? rtrim(rtrim(number_format($regularPrice - $currentPrice, 2, '.', ''), '0'), '.') : '',
                'price_label' => number_format($currentPrice, 2) . ' lei',
                'regular_price_label' => $hasSalePrice ? number_format($regularPrice, 2) . ' lei' : '',
                'short_description' => (string) ($candidate['short_description'] ?? ''),
                'reviews_count' => max(0, (int) ($candidate['reviews_count'] ?? 0)),
                'reviews_average' => max(0.0, min(5.0, (float) ($candidate['reviews_average'] ?? 0))),
                'out_of_stock' => (int) ($candidate['out_of_stock'] ?? 0) === 1 ? 1 : 0,
            ];
            $items[] = $item;
            $itemById[$id] = $item;
            if (count($items) >= 40) {
                break;
            }
        }

        $similarIds = array_values(array_filter(
            array_map('intval', array_keys($itemById)),
            static fn (int $id): bool => $id > 0
        ));
        if ($similarIds !== []) {
            $reviewMap = $this->loadShopCatalogReviewMap($this->db(), $similarIds);
            foreach ($itemById as $id => &$item) {
                if (!is_array($item)) {
                    continue;
                }
                $stats = $reviewMap[(int) $id] ?? null;
                if (!is_array($stats)) {
                    continue;
                }
                $item['reviews_count'] = max(0, (int) ($stats['count'] ?? 0));
                $item['reviews_average'] = max(0.0, min(5.0, (float) ($stats['average'] ?? 0.0)));
            }
            unset($item);
        }

        if ($selectedIds === []) {
            // Show only manually selected similar products from admin.
            return [];
        }

        $ordered = [];
        foreach ($selectedIds as $id) {
            if (!isset($itemById[$id])) {
                continue;
            }
            $ordered[] = $itemById[$id];
        }
        return array_slice($ordered, 0, 24);
    }

    private function buildSimilarProductsSectionHtml(array $similarProducts): string
    {
        if ($similarProducts === []) {
            return '';
        }
        $json = (string) json_encode(
            array_values($similarProducts),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        return '<section class="similar-products" data-similar-products="1">'
            . '<div class="similar-products__head"><h3>Produse similare</h3></div>'
            . '<div class="similar-products__carousel">'
            . '<button type="button" class="similar-products__nav" data-action="prev" aria-label="Produse anterioare">‹</button>'
            . '<div class="similar-products__viewport"><div class="similar-products__track" data-similar-track="1"></div></div>'
            . '<button type="button" class="similar-products__nav" data-action="next" aria-label="Produse următoare">›</button>'
            . '</div>'
            . '<div class="similar-products__dots" data-similar-dots="1" aria-label="Paginare produse similare"></div>'
            . '<script type="application/json" class="similar-products__data">' . $json . '</script>'
            . '</section>';
    }

    private function fanCredentialsFromSettings(array $settings): ?array
    {
        $clientId = (int) ($settings['fan_client_id'] ?? 0);
        $username = trim((string) ($settings['fan_api_username'] ?? ''));
        $password = trim((string) ($settings['fan_api_password'] ?? ''));
        if ($clientId <= 0 || $username === '' || $password === '') {
            return null;
        }

        return [
            'client_id' => $clientId,
            'username' => $username,
            'password' => $password,
        ];
    }

    private function autoGenerateFanAwbForPaidOrder(PDO $db, string $orderNumber, int $orderId): void
    {
        $settings = Settings::all($db);
        if ((string) ($settings['fan_awb_auto'] ?? '0') !== '1') {
            return;
        }

        $credentials = $this->fanCredentialsFromSettings($settings);
        if ($credentials === null) {
            return;
        }

        // `paid_amount` și `fan_locker_id` se adaugă prin migrare, nu sunt în
        // schema de bază: fără asigurarea lor, interogarea de mai jos crapă.
        \App\Support\ErpSync::ensureSchema($db);
        CheckoutCalculator::ensureOrderShippingSchema($db);

        $order = $this->loadOrderForFanAwb($db, $orderNumber, $orderId);
        if (!is_array($order)) {
            return;
        }

        if (trim((string) ($order['fan_awb'] ?? '')) !== '') {
            return;
        }

        // Emiterea automată nu știe de lockere: ar trimite coletul la adresa de
        // acasă a clientului, pe serviciul de livrare la adresă. Comenzile la
        // FANbox rămân pe butonul din administrare, care le duce unde trebuie.
        if ((int) ($order['fan_locker_id'] ?? 0) > 0) {
            return;
        }

        $payload = $this->buildFanShipmentPayloadFromOrder($order, $settings, $credentials['client_id']);

        try {
            $result = FanCourierGateway::createInternalAwb($credentials, $payload);
            $awb = trim((string) ($result['awb'] ?? ''));
            if ($awb === '') {
                return;
            }

            $stmt = $db->prepare(
                'UPDATE orders
                 SET fan_awb = :fan_awb,
                     fan_tracking_url = :fan_tracking_url,
                     fan_tracking_status = :fan_tracking_status,
                     fan_tracking_synced_at = NOW()
                 WHERE id = :id AND deleted_at IS NULL'
            );
            $stmt->execute([
                'fan_awb' => $awb,
                'fan_tracking_url' => FanCourierGateway::trackingUrl($awb),
                'fan_tracking_status' => 'AWB generat automat (plata confirmata)',
                'id' => (int) $order['id'],
            ]);

            EmailAutomation::sendOrderTemplateById($db, $settings, (int) $order['id'], 'shipped');
        } catch (RuntimeException) {
            // Avoid failing webhook response when FAN API is unavailable.
        }
    }

    private function loadOrderForFanAwb(PDO $db, string $orderNumber, int $orderId): ?array
    {
        if ($orderNumber === '' && $orderId <= 0) {
            return null;
        }

        if ($orderNumber !== '') {
            $stmt = $db->prepare(
                'SELECT id, order_number, payment_method, payment_status, paid_amount,
                        total, fan_awb, fan_locker_id,
                        billing_first_name, billing_last_name, billing_phone, billing_email,
                        billing_address_line1, billing_city, billing_county, billing_postcode,
                        shipping_same_as_billing, shipping_first_name, shipping_last_name, shipping_phone,
                        shipping_address_line1, shipping_city, shipping_county, shipping_postcode
                 FROM orders
                 WHERE order_number = :order_number AND deleted_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute(['order_number' => $orderNumber]);
        } else {
            $stmt = $db->prepare(
                'SELECT id, order_number, payment_method, payment_status, paid_amount,
                        total, fan_awb, fan_locker_id,
                        billing_first_name, billing_last_name, billing_phone, billing_email,
                        billing_address_line1, billing_city, billing_county, billing_postcode,
                        shipping_same_as_billing, shipping_first_name, shipping_last_name, shipping_phone,
                        shipping_address_line1, shipping_city, shipping_county, shipping_postcode
                 FROM orders
                 WHERE id = :id AND deleted_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute(['id' => $orderId]);
        }

        $order = $stmt->fetch() ?: null;
        if (!is_array($order)) {
            return null;
        }

        $itemsStmt = $db->prepare(
            'SELECT oi.quantity, p.weight_grams
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :order_id'
        );
        $itemsStmt->execute(['order_id' => (int) $order['id']]);
        $order['items'] = $itemsStmt->fetchAll();

        return $order;
    }

    private function buildFanShipmentPayloadFromOrder(array $order, array $settings, int $clientId): array
    {
        $service = trim((string) ($settings['fan_service_type'] ?? 'Standard'));
        if ($service === '') {
            $service = 'Standard';
        }
        $shippingPayer = trim((string) ($settings['fan_shipping_payer'] ?? 'recipient'));
        if (!in_array($shippingPayer, ['recipient', 'sender', 'third_party'], true)) {
            $shippingPayer = 'recipient';
        }
        $shipmentType = trim((string) ($settings['fan_shipment_type'] ?? 'parcel'));
        if (!in_array($shipmentType, ['parcel', 'envelope'], true)) {
            $shipmentType = 'parcel';
        }
        $parcelCount = $shipmentType === 'parcel' ? max(1, (int) ($settings['fan_parcel_count'] ?? 1)) : 0;
        $envelopeCount = $shipmentType === 'envelope' ? max(1, (int) ($settings['fan_envelope_count'] ?? 1)) : 0;
        $codPayer = trim((string) ($settings['fan_cod_payer'] ?? 'sender'));
        if (!in_array($codPayer, ['recipient', 'sender'], true)) {
            $codPayer = 'sender';
        }

        $defaultWeight = (float) ($settings['fan_default_weight_kg'] ?? 1);
        if ($defaultWeight <= 0) {
            $defaultWeight = 1;
        }

        $weight = $this->orderWeightKgFromItems((array) ($order['items'] ?? []), $defaultWeight);
        $dimensions = $this->fanDimensionsFromSettings($settings);

        // AWB-ul se emite către adresa de LIVRARE dacă aceasta diferă de facturare.
        $sameAsBilling = (int) ($order['shipping_same_as_billing'] ?? 1) === 1;
        $shipStreet = trim((string) ($order['shipping_address_line1'] ?? ''));
        $useShipping = !$sameAsBilling && $shipStreet !== '';

        if ($useShipping) {
            $recipientName = trim((string) ($order['shipping_first_name'] ?? '') . ' ' . (string) ($order['shipping_last_name'] ?? ''));
            if ($recipientName === '') {
                $recipientName = trim((string) ($order['billing_first_name'] ?? '') . ' ' . (string) ($order['billing_last_name'] ?? ''));
            }
            $recipientPhone = trim((string) ($order['shipping_phone'] ?? '')) !== ''
                ? trim((string) ($order['shipping_phone'] ?? ''))
                : trim((string) ($order['billing_phone'] ?? ''));
            $recipientCounty = trim((string) ($order['shipping_county'] ?? ''));
            $recipientLocality = trim((string) ($order['shipping_city'] ?? ''));
            $recipientStreet = $shipStreet;
            $recipientZip = trim((string) ($order['shipping_postcode'] ?? ''));
        } else {
            $recipientName = trim((string) ($order['billing_first_name'] ?? '') . ' ' . (string) ($order['billing_last_name'] ?? ''));
            $recipientPhone = trim((string) ($order['billing_phone'] ?? ''));
            $recipientCounty = trim((string) ($order['billing_county'] ?? ''));
            $recipientLocality = trim((string) ($order['billing_city'] ?? ''));
            $recipientStreet = trim((string) ($order['billing_address_line1'] ?? ''));
            $recipientZip = trim((string) ($order['billing_postcode'] ?? ''));
        }
        $recipientEmail = trim((string) ($order['billing_email'] ?? ''));

        // Rambursul e ce a MAI RĂMAS de încasat, nu totalul: o comandă cu plata
        // la livrare poate fi achitată între timp printr-un link de plată, iar
        // cu totalul pus orbește curierul ar încasa banii a doua oară.
        $cod = 0.0;
        if ((string) ($order['payment_method'] ?? '') === 'cod') {
            $totalComanda = round((float) ($order['total'] ?? 0), 2);
            if (strtolower((string) ($order['payment_status'] ?? '')) === 'paid') {
                $incasat = $order['paid_amount'] ?? null;
                $incasat = ($incasat === null || $incasat === '')
                    ? $totalComanda
                    : round((float) $incasat, 2);
                $cod = max(0.0, round($totalComanda - $incasat, 2));
            } else {
                $cod = $totalComanda;
            }
        }

        // Coletul cu ramburs merge pe serviciul de „Cont Colector": doar pe el
        // FAN virează încasarea în contul din setări.
        if ($cod > 0) {
            $serviciuRamburs = trim((string) ($settings['fan_service_type_ramburs'] ?? ''));
            if ($serviciuRamburs !== '') {
                $service = $serviciuRamburs;
            }
        }

        // Contul în care FAN virează rambursul. Trimis gol, FAN alege singur cum
        // întoarce banii și îi poate aduce cash, prin curier.
        $codBank = $cod > 0 ? trim((string) ($settings['fan_cod_bank'] ?? '')) : '';
        $codIban = $cod > 0
            ? strtoupper(preg_replace('/\s+/', '', trim((string) ($settings['fan_cod_iban'] ?? ''))) ?? '')
            : '';

        $db = $this->db();
        $resolvedRecipient = $this->resolveFanAddressForApi(
            $db instanceof PDO ? $db : null,
            $recipientCounty,
            $recipientLocality
        );

        $declaredValueMode = trim((string) ($settings['fan_declared_value_mode'] ?? 'order_total'));
        $shipment = [
            'info' => [
                'service' => $service,
                'bank' => $codBank,
                'bankAccount' => $codIban,
                'packages' => [
                    'parcel' => $parcelCount,
                    'envelope' => $envelopeCount,
                ],
                'weight' => $weight,
                'cod' => $cod > 0 ? round($cod, 2) : 0,
                'payment' => $shippingPayer,
                'refund' => null,
                'returnPayment' => $cod > 0 ? $codPayer : null,
                'observation' => 'Comanda ' . (string) ($order['order_number'] ?? ''),
                'content' => 'Comanda ' . (string) ($order['order_number'] ?? ''),
                'costCenter' => null,
                'options' => $this->fanOptionCodesFromSettings($settings),
            ],
            'recipient' => [
                'name' => $recipientName !== '' ? $recipientName : 'Client',
                'phone' => $recipientPhone,
                'email' => $recipientEmail,
                'address' => [
                    'county' => $resolvedRecipient['county'],
                    'locality' => $resolvedRecipient['locality'],
                    'street' => $recipientStreet,
                    // Adresa completă (inclusiv numărul) e deja în `street`; nu adăugăm un
                    // număr fictiv, altfel FAN tipărește un „Nr. 1" greșit pe AWB.
                    'streetNo' => '',
                    'zipCode' => $recipientZip,
                ],
            ],
        ];
        if ($declaredValueMode !== 'none') {
            $shipment['info']['declaredValue'] = $declaredValueMode === 'zero'
                ? 0.0
                : round((float) ($order['total'] ?? 0), 2);
        }
        if (($dimensions['length'] ?? 0) > 0 && ($dimensions['width'] ?? 0) > 0 && ($dimensions['height'] ?? 0) > 0) {
            $shipment['info']['dimensions'] = [
                'length' => (float) $dimensions['length'],
                'width' => (float) $dimensions['width'],
                'height' => (float) $dimensions['height'],
            ];
        }

        $senderName = trim((string) ($settings['fan_sender_name'] ?? ''));
        $senderPhone = trim((string) ($settings['fan_sender_phone'] ?? ''));
        $senderEmail = trim((string) ($settings['fan_sender_email'] ?? ''));
        // Gestiuni pe județe: expeditorul devine depozitul care deservește
        // județul clientului. Dacă ERP-ul nu răspunde sau județul nu e mapat,
        // rămâne expeditorul global.
        $senderOverride = \App\Support\ErpShipping::senderForCounty(
            $db instanceof PDO ? $db : null,
            $settings,
            $recipientCounty
        );
        if ($senderOverride !== null) {
            $settings = array_merge($settings, $senderOverride);
        }
        $senderCounty = trim((string) ($settings['fan_sender_county'] ?? ''));
        $senderLocality = trim((string) ($settings['fan_sender_locality'] ?? ''));
        $resolvedSender = $this->resolveFanAddressForApi(
            $db instanceof PDO ? $db : null,
            $senderCounty,
            $senderLocality
        );
        $senderCounty = $resolvedSender['county'];
        $senderLocality = $resolvedSender['locality'];
        $senderStreet = trim((string) ($settings['fan_sender_street'] ?? ''));
        $senderStreetNo = trim((string) ($settings['fan_sender_street_no'] ?? ''));
        $senderZipCode = trim((string) ($settings['fan_sender_zip_code'] ?? ''));

        if (
            $senderName !== ''
            && $senderPhone !== ''
            && $senderEmail !== ''
            && $senderCounty !== ''
            && $senderLocality !== ''
            && $senderStreet !== ''
        ) {
            $shipment['sender'] = [
                'name' => $senderName,
                'phone' => $senderPhone,
                'contactPerson' => $senderName,
                'email' => $senderEmail,
                'address' => [
                    'county' => $senderCounty,
                    'locality' => $senderLocality,
                    'street' => $senderStreet,
                    'streetNo' => $senderStreetNo !== '' ? $senderStreetNo : '1',
                    'zipCode' => $senderZipCode,
                ],
            ];
        }

        return [
            'clientId' => $clientId,
            'shipments' => [$shipment],
        ];
    }

    private function orderWeightKgFromItems(array $items, float $defaultWeight): float
    {
        $grams = 0;
        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $weight = max(0, (int) ($item['weight_grams'] ?? 0));
            $grams += ($quantity * $weight);
        }

        return $grams > 0 ? max(0.1, $grams / 1000) : $defaultWeight;
    }

    private function estimateShipmentWeightKg(array $summary, array $settings): float
    {
        $totalWeightKg = max(0, (float) ($summary['total_weight_kg'] ?? 0));
        $fallback = (float) ($settings['fan_default_weight_kg'] ?? 1);
        if ($fallback <= 0) {
            $fallback = 1;
        }

        return $totalWeightKg > 0 ? $totalWeightKg : $fallback;
    }

    private function fanDimensionsFromSettings(array $settings): array
    {
        $length = (float) ($settings['fan_parcel_length_cm'] ?? 0);
        $width = (float) ($settings['fan_parcel_width_cm'] ?? 0);
        $height = (float) ($settings['fan_parcel_height_cm'] ?? 0);

        return [
            'length' => $length > 0 ? $length : 0,
            'width' => $width > 0 ? $width : 0,
            'height' => $height > 0 ? $height : 0,
        ];
    }

    private function fanOptionCodesFromSettings(array $settings): array
    {
        $raw = trim((string) ($settings['fan_option_codes'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $pieces = preg_split('/[\s,;]+/', mb_strtoupper($raw)) ?: [];
        $allowed = ['A', 'B', 'C', 'D', 'E', 'F', 'M', 'O', 'P', 'S', 'V', 'W', 'X', 'Y'];
        $codes = [];
        foreach ($pieces as $piece) {
            $code = trim((string) $piece);
            if ($code === '' || !in_array($code, $allowed, true)) {
                continue;
            }
            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }

    private function cachedSettings(?PDO $db): array
    {
        if ($this->cachedSettings !== []) {
            return $this->cachedSettings;
        }
        $this->cachedSettings = Settings::all($db);
        return $this->cachedSettings;
    }

    private function renderMannequinSection(?PDO $db, array $settings): string
    {
        if (!$db instanceof PDO) {
            return '';
        }
        if ((string) ($settings['mannequin_enabled'] ?? '1') !== '1') {
            return '';
        }

        $points = $this->decodeMannequinPoints((string) ($settings['mannequin_points_json'] ?? '[]'));
        if ($points === []) {
            return '';
        }

        $productMap = $this->loadMannequinProductMap($db);
        if ($productMap === []) {
            return '';
        }

        $normalizedPoints = [];
        foreach ($points as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $pointId = trim((string) ($entry['id'] ?? ''));
            if ($pointId === '') {
                continue;
            }
            $label = trim((string) ($entry['label'] ?? 'Punct'));
            $x = max(0, min(100, (float) ($entry['x'] ?? 50)));
            $y = max(0, min(100, (float) ($entry['y'] ?? 50)));
            $products = [];
            $rawIds = $entry['product_ids'] ?? [];
            if (is_array($rawIds)) {
                foreach ($rawIds as $value) {
                    $productId = (int) $value;
                    if ($productId <= 0 || !isset($productMap[$productId])) {
                        continue;
                    }
                    $products[] = $productMap[$productId];
                }
            }
            $normalizedPoints[] = [
                'id' => $pointId,
                'label' => $label,
                'x' => round($x, 2),
                'y' => round($y, 2),
                'products' => $products,
            ];
        }
        if ($normalizedPoints === []) {
            return '';
        }

        $payload = [
            'title' => trim((string) ($settings['mannequin_title'] ?? 'Recomandări pe zone')),
            'subtitle' => trim((string) ($settings['mannequin_subtitle'] ?? 'Alege un punct de pe manechin pentru a vedea produsele recomandate.')),
            'emptyText' => trim((string) ($settings['mannequin_empty_text'] ?? 'Nu sunt produse pentru această categorie.')),
            'points' => $normalizedPoints,
        ];
        if (trim((string) $payload['emptyText']) === '') {
            $payload['emptyText'] = 'Nu sunt produse pentru această categorie.';
        }

        return $this->renderPhpView('site/components/mannequin-widget', [
            'widget' => $payload,
        ]);
    }

    private function decodeMannequinPoints(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $points = [];
        $index = 0;
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = trim((string) ($entry['id'] ?? ''));
            if ($id === '') {
                $id = 'point-' . ($index + 1);
            }
            $id = strtolower($this->slugify(str_replace('_', '-', $id)));
            if ($id === '') {
                $id = 'point-' . ($index + 1);
            }
            $label = trim((string) ($entry['label'] ?? 'Punct'));
            if ($label === '') {
                $label = 'Punct';
            }
            $x = max(0, min(100, (float) ($entry['x'] ?? 50)));
            $y = max(0, min(100, (float) ($entry['y'] ?? 50)));

            $productIds = [];
            $rawIds = $entry['product_ids'] ?? [];
            if (is_array($rawIds)) {
                foreach ($rawIds as $value) {
                    $productId = (int) $value;
                    if ($productId <= 0 || in_array($productId, $productIds, true)) {
                        continue;
                    }
                    $productIds[] = $productId;
                }
            }

            $points[] = [
                'id' => $id,
                'label' => $label,
                'x' => round($x, 2),
                'y' => round($y, 2),
                'product_ids' => $productIds,
            ];
            $index++;
            if (count($points) >= 80) {
                break;
            }
        }

        return $points;
    }

    private function loadMannequinProductMap(PDO $db): array
    {
        try {
            $stmt = $db->query(
                'SELECT p.id, p.name, p.slug, p.short_description, p.price, p.sale_price, p.sale_price_periods_json, p.out_of_stock, p.image_url,
                        COUNT(pr.id) AS reviews_count,
                        AVG(pr.rating) AS reviews_average
                 FROM products p
                 LEFT JOIN product_reviews pr ON pr.product_id = p.id AND pr.is_approved = 1
                 WHERE p.deleted_at IS NULL
                 GROUP BY p.id, p.name, p.slug, p.short_description, p.price, p.sale_price, p.sale_price_periods_json, p.out_of_stock, p.image_url
                 ORDER BY p.id DESC'
            );
        } catch (Throwable) {
            $stmt = $db->query(
                'SELECT p.id, p.name, p.slug, p.short_description, p.price, NULL AS sale_price, NULL AS sale_price_periods_json, 0 AS out_of_stock, p.image_url,
                        COUNT(pr.id) AS reviews_count,
                        AVG(pr.rating) AS reviews_average
                 FROM products p
                 LEFT JOIN product_reviews pr ON pr.product_id = p.id AND pr.is_approved = 1
                 WHERE p.deleted_at IS NULL
                 GROUP BY p.id, p.name, p.slug, p.short_description, p.price, p.image_url
                 ORDER BY p.id DESC'
            );
        }
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (int) ($row['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            $effectivePrice = max(0.0, (float) ($row['price'] ?? 0.0));
            $pricing = $this->resolveProductPricing(
                $effectivePrice,
                $row['sale_price'] ?? null,
                $row['sale_price_periods_json'] ?? '[]'
            );
            $effectivePrice = (float) ($pricing['effective_price'] ?? $effectivePrice);
            $isOutOfStock = (int) ($row['out_of_stock'] ?? 0) === 1;
            $map[$productId] = [
                'id' => $productId,
                'name' => trim((string) ($row['name'] ?? 'Produs')),
                'short_description' => trim((string) ($row['short_description'] ?? '')),
                'price' => $effectivePrice,
                'regular_price' => max(0.0, (float) ($row['price'] ?? 0.0)),
                'image_url' => trim((string) ($row['image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg',
                'url' => $slug !== '' ? ('/produs/' . rawurlencode($slug)) : '/magazin',
                'reviews_count' => max(0, (int) ($row['reviews_count'] ?? 0)),
                'reviews_average' => max(0, min(5, (float) ($row['reviews_average'] ?? 0))),
                'out_of_stock' => $isOutOfStock ? 1 : 0,
                'cart_add_url' => '/cos/adauga/' . $productId,
            ];
        }
        return $map;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\- ]/i', '', $value) ?? '';
        $value = preg_replace('/[\s\-]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function renderPhpView(string $template, array $data = []): string
    {
        $templateFile = __DIR__ . '/../../../views/' . $template . '.php';
        if (!is_file($templateFile)) {
            return '';
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $templateFile;
        $output = ob_get_clean();
        return is_string($output) ? $output : '';
    }
}
