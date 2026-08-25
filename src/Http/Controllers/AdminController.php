<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminActivityLog;
use App\Support\Auth;
use App\Support\Database;
use App\Support\EmailAutomation;
use App\Support\FanCourierGateway;
use App\Support\Flash;
use App\Support\LoyaltyService;
use App\Support\NewsletterService;
use App\Support\OrderMailer;
use App\Support\ResponseCache;
use App\Support\Settings;
use App\Support\View;
use App\Support\WordPressDumpReader;
use Throwable;
use PDO;
use RuntimeException;

final class AdminController
{
    /** Metodele de plată acceptate la corectarea unei comenzi din administrare. */
    private const METODE_PLATA = ['cod', 'euplatesc', 'stripe', 'card', 'bank_transfer'];

    private const CART_FORM_TOKEN = '{{cart_form}}';
    private const SHOP_CATALOG_TOKEN = '{{shop_catalog}}';
    private const BLOG_POSTS_TOKEN = '{{blog_posts}}';
    private const CHECKOUT_FORM_TOKEN = '{{checkout_form}}';
    private const CHECKOUT_SUCCESS_ORDER_INFO_TOKEN = '{{checkout_success_order_info}}';
    private const ACCOUNT_SECTION_TOKEN = '{{account_section}}';
    private const PRODUCT_REVIEW_FORM_TOKEN = '{{product_review_form}}';
    private const GDPR_AGREEMENTS_FORM_TOKEN = '{{gdpr_agreements_form}}';
    private const GOOGLE_AUTH_BUTTON_TOKEN = '{{auth_google_button}}';
    private const FAN_LOCALITIES_UPLOAD_MAX_SIZE = 12_000_000;
    private const USERS_IMPORT_UPLOAD_MAX_SIZE = 12_000_000;

    /** Unde se pun arhivele de backup WordPress, urcate prin FTP/File Manager. */
    private const WP_DUMP_DIR = '/storage/import';
    private const LOYALTY_POINTS_IMPORT_UPLOAD_MAX_SIZE = 12_000_000;
    private const BLOG_POSTS_IMPORT_UPLOAD_MAX_SIZE = 12_000_000;
    private const PRODUCT_REVIEWS_IMPORT_UPLOAD_MAX_SIZE = 12_000_000;
    private const NEWSLETTER_SUBSCRIBERS_IMPORT_UPLOAD_MAX_SIZE = 12_000_000;
    private const ORDER_ALLOWED_STATUSES = ['pending', 'pending_payment', 'processing', 'completed', 'cancelled', 'refunded', 'failed'];

    public function dashboard(): void
    {
        // Conturile limitate (ex. administrator de magazin) nu au dashboard:
        // le ducem direct în secțiunea lor, fără mesaj de eroare — „/admin" e
        // adresa de bază a panoului, se ajunge aici și dintr-un simplu click pe logo.
        if (Auth::check() && !Auth::canAccessPath('/admin')) {
            header('Location: ' . Auth::defaultPathForRole());
            return;
        }
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();

        $metrics = [
            'products' => 0,
            'orders' => 0,
            'users' => 0,
        ];
        $recentOrder = null;
        $recentUsers = [];

        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $metrics['products'] = (int) $db->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL')->fetchColumn();
            $metrics['orders'] = (int) $db->query('SELECT COUNT(*) FROM orders WHERE deleted_at IS NULL')->fetchColumn();
            $metrics['users'] = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

            $recentOrder = $db->query(
                'SELECT order_number, billing_first_name, billing_last_name, total, status
                 FROM orders
                 WHERE deleted_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1'
            )->fetch() ?: null;

            $recentUsers = $db->query(
                'SELECT u.id, u.first_name, u.last_name, u.email, u.created_at,
                        COUNT(o.id) AS orders_count
                 FROM users u
                 LEFT JOIN orders o
                    ON (o.user_id = u.id OR (o.user_id IS NULL AND o.billing_email = u.email))
                   AND o.deleted_at IS NULL
                 GROUP BY u.id
                 ORDER BY u.id DESC
                 LIMIT 6'
            )->fetchAll();
        }

        View::render('admin/dashboard', [
            'title' => 'Dashboard',
            'metrics' => $metrics,
            'recentOrder' => $recentOrder,
            'recentUsers' => $recentUsers,
        ], 'admin/layout');
    }

    public function users(): void
    {
        if (!$this->guard()) {
            return;
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = $this->usersListSortKey((string) ($_GET['sort'] ?? ''));
        $dir = $this->usersListSortDir((string) ($_GET['dir'] ?? ''));
        $selectedId = (int) ($_GET['user'] ?? 0);
        $page = $this->normalizeAdminPage((int) ($_GET['page'] ?? 1));
        $perPage = $this->normalizeAdminPerPage((int) ($_GET['per_page'] ?? 25));
        $panel = $this->normalizeAdminListPanel((string) ($_GET['panel'] ?? 'list'));
        $this->renderUsersPage($selectedId, $search, $sort, $dir, $page, $perPage, $panel);
    }

    public function usersImport(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users');
            return;
        }
        $this->ensureOptionalSchema($db);
        $settings = Settings::all($db);

        $result = $this->importWordPressUsersFromUploadedFile($db, $_FILES['users_file'] ?? null);
        Flash::set($result['ok'] ? 'success' : 'error', (string) ($result['message'] ?? 'Import invalid.'));
        header('Location: /admin/users?panel=import');
    }

    /**
     * Import direct din backup-ul WordPress (`*-db.gz` de la UpdraftPlus).
     *
     * Fișierul se citește în flux, comprimat: dump-ul dezarhivat poate trece de
     * 4 GB, deci nu se dezarhivează și nu se încarcă în memorie. Rulează întâi
     * în modul „doar verifică", ca să se vadă ce s-ar întâmpla înainte să se
     * scrie ceva.
     */
    public function usersImportDump(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users?panel=import');
            return;
        }

        $nume = basename(trim((string) ($_POST['dump_file'] ?? '')));
        $disponibile = array_column($this->wordpressDumpFiles(), 'nume');
        if ($nume === '' || !in_array($nume, $disponibile, true)) {
            Flash::set('error', 'Alege un fișier de backup din lista de mai jos.');
            header('Location: /admin/users?panel=import');
            return;
        }

        $scrie = (string) ($_POST['mod'] ?? 'verifica') === 'importa';
        $listaId = (int) ($_POST['newsletter_list_id'] ?? 0);

        // Citirea unui dump de sute de MB durează minute, nu secunde.
        @set_time_limit(0);
        ignore_user_abort(true);

        try {
            $date = WordPressDumpReader::citeste(dirname(__DIR__, 3) . self::WP_DUMP_DIR . '/' . $nume);
        } catch (Throwable $e) {
            Flash::set('error', 'Nu am putut citi arhiva: ' . $e->getMessage());
            header('Location: /admin/users?panel=import');
            return;
        }

        if (!($date['ok'] ?? false)) {
            Flash::set('error', (string) ($date['message'] ?? 'Fișierul nu conține datele căutate.'));
            header('Location: /admin/users?panel=import');
            return;
        }

        $raport = $this->analizeazaDumpWordPress($db, $date);
        if ($scrie) {
            $raport = array_merge($raport, $this->scrieDumpWordPress($db, $date, $listaId));
            AdminActivityLog::log($db, 'import_utilizatori_wordpress', [
                'fisier' => $nume,
                'conturi_noi' => $raport['conturi_noi'] ?? 0,
                'abonati_noi' => $raport['abonati_noi'] ?? 0,
            ]);
        }

        Flash::set('wp_dump_report', (string) json_encode(array_merge($raport, [
            'fisier' => $nume,
            'prefix' => (string) ($date['prefix'] ?? ''),
            'scris' => $scrie,
            'tabele' => $date['tabele'] ?? [],
        ]), JSON_UNESCAPED_UNICODE));
        Flash::set(
            'success',
            $scrie
                ? 'Import finalizat din ' . $nume . '.'
                : 'Verificare finalizată — nu s-a scris nimic încă. Vezi raportul de mai jos.'
        );
        header('Location: /admin/users?panel=import');
    }

    /** Ce ar intra și ce ar fi sărit, fără să modifice nimic. */
    private function analizeazaDumpWordPress(PDO $db, array $date): array
    {
        $existent = $db->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $conturiNoi = 0;
        $conturiExistente = 0;
        $cuAdresa = 0;
        $faraParola = 0;
        foreach ((array) ($date['utilizatori'] ?? []) as $u) {
            $existent->execute(['email' => $u['email']]);
            if ((bool) $existent->fetchColumn()) {
                $conturiExistente++;
                continue;
            }
            $conturiNoi++;
            if (($u['adresa'] ?? null) !== null) {
                $cuAdresa++;
            }
            if (trim((string) ($u['password_hash'] ?? '')) === '') {
                $faraParola++;
            }
        }

        $abonatiNoi = 0;
        $abonatiExistenti = 0;
        try {
            NewsletterService::ensureSchema($db);
            $cauta = $db->prepare('SELECT 1 FROM newsletter_subscribers WHERE email = :email LIMIT 1');
            foreach ((array) ($date['abonati'] ?? []) as $a) {
                $cauta->execute(['email' => $a['email']]);
                if ((bool) $cauta->fetchColumn()) {
                    $abonatiExistenti++;
                } else {
                    $abonatiNoi++;
                }
            }
        } catch (Throwable) {
            $abonatiNoi = count((array) ($date['abonati'] ?? []));
        }

        $stat = (array) ($date['statistici'] ?? []);
        return [
            'gasite_conturi' => count((array) ($date['utilizatori'] ?? [])),
            'gasite_abonati' => count((array) ($date['abonati'] ?? [])),
            'conturi_noi' => $conturiNoi,
            'conturi_existente' => $conturiExistente,
            'conturi_cu_adresa' => $cuAdresa,
            'conturi_fara_parola' => $faraParola,
            'abonati_noi' => $abonatiNoi,
            'abonati_existenti' => $abonatiExistenti,
            'admini_sariti' => (int) ($stat['admini_sariti'] ?? 0),
            'linii_citite' => (int) ($stat['linii'] ?? 0),
        ];
    }

    /**
     * Scrie efectiv. Conturile care există deja NU se ating: cine s-a
     * înregistrat pe site-ul nou după mutare are parola pe care o știe acum,
     * iar suprascrierea cu hash-ul vechi l-ar bloca afară.
     */
    private function scrieDumpWordPress(PDO $db, array $date, int $listaId): array
    {
        $existent = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $inserare = $db->prepare(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, created_at)
             VALUES (:first_name, :last_name, :email, :phone, :password_hash, :created_at)'
        );
        $adresaStmt = $db->prepare(
            'INSERT INTO user_addresses (
                user_id, label, full_name, phone, address_line1, address_line2, city, county, postcode, is_default
             ) VALUES (
                :user_id, :label, :full_name, :phone, :address_line1, :address_line2, :city, :county, :postcode, 1
             )'
        );

        $noi = 0;
        $adrese = 0;
        $esuate = 0;
        // O singură tranzacție pentru toate conturile: mii de inserări separate
        // ar dura minute, iar o cădere la jumătate ar lăsa importul pe jumătate
        // făcut, fără să se știe unde a rămas.
        $inTranzactie = false;
        try {
            $db->beginTransaction();
            $inTranzactie = true;
        } catch (Throwable) {
            $inTranzactie = false;
        }
        foreach ((array) ($date['utilizatori'] ?? []) as $u) {
            $email = (string) $u['email'];
            $existent->execute(['email' => $email]);
            if ((bool) $existent->fetchColumn()) {
                continue;
            }

            $hash = trim((string) ($u['password_hash'] ?? ''));
            if ($hash === '') {
                // Fără parolă în dump: punem una imposibil de ghicit, iar omul
                // intră prin „Am uitat parola".
                $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            }
            $creatLa = date('Y-m-d H:i:s');
            $creatBrut = trim((string) ($u['created_at'] ?? ''));
            if ($creatBrut !== '' && ($ts = strtotime($creatBrut)) !== false) {
                $creatLa = date('Y-m-d H:i:s', $ts);
            }

            try {
                $inserare->execute([
                    'first_name' => trim((string) $u['first_name']) !== '' ? $u['first_name'] : 'Client',
                    'last_name' => trim((string) $u['last_name']) !== '' ? $u['last_name'] : '',
                    'email' => $email,
                    'phone' => trim((string) $u['phone']) !== '' ? $u['phone'] : null,
                    'password_hash' => $hash,
                    'created_at' => $creatLa,
                ]);
            } catch (Throwable) {
                $esuate++;
                continue;
            }
            $noi++;

            $adresa = $u['adresa'] ?? null;
            if (!is_array($adresa)) {
                continue;
            }
            try {
                $adresaStmt->execute([
                    'user_id' => (int) $db->lastInsertId(),
                    'label' => $adresa['label'],
                    'full_name' => $adresa['full_name'] !== '' ? $adresa['full_name'] : $email,
                    'phone' => $adresa['phone'] !== '' ? $adresa['phone'] : null,
                    'address_line1' => $adresa['address_line1'],
                    'address_line2' => $adresa['address_line2'] !== '' ? $adresa['address_line2'] : null,
                    'city' => $adresa['city'],
                    'county' => $adresa['county'],
                    'postcode' => $adresa['postcode'] !== '' ? $adresa['postcode'] : null,
                ]);
                $adrese++;
            } catch (Throwable) {
                // Adresa e un bonus: dacă schema diferă, contul rămâne valid.
            }
        }
        if ($inTranzactie) {
            try {
                $db->commit();
            } catch (Throwable) {
                $db->rollBack();
                return ['conturi_scrise' => 0, 'adrese_scrise' => 0, 'abonati_scrisi' => 0, 'esuate' => $noi + $esuate];
            }
        }

        $abonatiNoi = 0;
        $abonati = (array) ($date['abonati'] ?? []);
        if ($abonati !== []) {
            try {
                NewsletterService::ensureSchema($db);
                if ($listaId <= 0) {
                    $listaId = NewsletterService::defaultListId($db);
                }
                foreach ($abonati as $a) {
                    try {
                        NewsletterService::subscribeToList($db, $listaId, (string) $a['email'], (string) $a['name']);
                        $abonatiNoi++;
                    } catch (Throwable) {
                        $esuate++;
                    }
                }
            } catch (Throwable) {
                // Fără liste de newsletter, conturile tot au intrat.
            }
        }

        return [
            'conturi_scrise' => $noi,
            'adrese_scrise' => $adrese,
            'abonati_scrisi' => $abonatiNoi,
            'esuate' => $esuate,
        ];
    }

    public function usersSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users');
            return;
        }
        $this->ensureOptionalSchema($db);

        $id = (int) ($_POST['id'] ?? 0);
        $search = trim((string) ($_POST['q'] ?? ''));
        $sort = $this->usersListSortKey((string) ($_POST['sort'] ?? 'id'));
        $dir = $this->usersListSortDir((string) ($_POST['dir'] ?? 'desc'));
        $page = $this->normalizeAdminPage((int) ($_POST['page'] ?? 1));
        $perPage = $this->normalizeAdminPerPage((int) ($_POST['per_page'] ?? 25));
        $redirect = $this->adminUsersUrl($id, $search, $sort, $dir, $page, $perPage);

        if ($id <= 0) {
            Flash::set('error', 'Utilizator invalid pentru editare.');
            header('Location: ' . $redirect);
            return;
        }

        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $birthDateRaw = trim((string) ($_POST['birth_date'] ?? ''));
        $gender = trim((string) ($_POST['gender'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Adresa de email este invalidă.');
            header('Location: ' . $redirect);
            return;
        }

        $birthDate = null;
        if ($birthDateRaw !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDateRaw) !== 1 || strtotime($birthDateRaw) === false) {
                Flash::set('error', 'Data nașterii este invalidă.');
                header('Location: ' . $redirect);
                return;
            }
            $birthDate = $birthDateRaw;
        }

        if (!in_array($gender, ['', 'feminin', 'masculin'], true)) {
            $gender = '';
        }

        $existsStmt = $db->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $existsStmt->execute([
            'email' => $email,
            'id' => $id,
        ]);
        if ($existsStmt->fetchColumn()) {
            Flash::set('error', 'Există deja alt utilizator cu această adresă de email.');
            header('Location: ' . $redirect);
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
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'birth_date' => $birthDate,
            'gender' => $gender !== '' ? $gender : null,
            'id' => $id,
        ]);

        Flash::set('success', 'Datele utilizatorului au fost actualizate.');
        header('Location: ' . $redirect);
    }

    public function usersDeleteSelected(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users');
            return;
        }
        $this->ensureOptionalSchema($db);

        $search = trim((string) ($_POST['q'] ?? ''));
        $sort = $this->usersListSortKey((string) ($_POST['sort'] ?? 'id'));
        $dir = $this->usersListSortDir((string) ($_POST['dir'] ?? 'desc'));
        $page = $this->normalizeAdminPage((int) ($_POST['page'] ?? 1));
        $perPage = $this->normalizeAdminPerPage((int) ($_POST['per_page'] ?? 25));
        $selectedId = (int) ($_POST['selected_user'] ?? 0);
        $ids = $this->normalizePositiveIds($_POST['user_ids'] ?? []);
        if ($ids === []) {
            Flash::set('error', 'Selectează cel puțin un utilizator pentru ștergere.');
            header('Location: ' . $this->adminUsersUrl($selectedId, $search, $sort, $dir, $page, $perPage));
            return;
        }

        if ($selectedId > 0 && in_array($selectedId, $ids, true)) {
            $selectedId = 0;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $db->beginTransaction();

            $emailsStmt = $db->prepare('SELECT email FROM users WHERE id IN (' . $in . ')');
            $emailsStmt->execute($ids);
            $emails = [];
            foreach ($emailsStmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $safeEmail = strtolower(trim((string) $email));
                if ($safeEmail !== '') {
                    $emails[$safeEmail] = $safeEmail;
                }
            }
            $emails = array_values($emails);

            $db->prepare('UPDATE orders SET user_id = NULL WHERE user_id IN (' . $in . ')')
                ->execute($ids);
            $db->prepare('DELETE FROM user_addresses WHERE user_id IN (' . $in . ')')
                ->execute($ids);
            $db->prepare('DELETE FROM loyalty_points_transactions WHERE user_id IN (' . $in . ')')
                ->execute($ids);

            if ($emails !== []) {
                $emailIn = implode(',', array_fill(0, count($emails), '?'));
                try {
                    $db->prepare('DELETE FROM customer_password_resets WHERE email IN (' . $emailIn . ')')
                        ->execute($emails);
                } catch (Throwable) {
                    // Optional table on partially upgraded installs.
                }
            }

            $deleteStmt = $db->prepare('DELETE FROM users WHERE id IN (' . $in . ')');
            $deleteStmt->execute($ids);
            $deleted = (int) $deleteStmt->rowCount();

            $db->commit();
            Flash::set('success', 'Au fost șterși ' . $deleted . ' utilizatori.');
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Flash::set('error', 'Nu am putut șterge utilizatorii selectați.');
        }

        header('Location: ' . $this->adminUsersUrl($selectedId, $search, $sort, $dir, $page, $perPage));
    }

    public function usersSettings(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $settings = [
            'customer_registration_field_first_name' => '0',
            'customer_registration_field_last_name' => '0',
            'customer_registration_field_email' => '1',
            'customer_registration_field_phone' => '0',
            'customer_registration_field_birth_date' => '0',
            'customer_registration_field_gender' => '0',
            'customer_registration_field_password' => '1',
            'customer_registration_field_password_confirm' => '1',
            'customer_google_auth_enabled' => '0',
            'customer_google_client_id' => '',
            'customer_google_client_secret' => '',
        ];
        if ($db instanceof PDO) {
            $settings = array_merge($settings, Settings::all($db));
        }

        View::render('admin/users-settings', [
            'title' => 'Setări utilizatori',
            'settings' => $settings,
            'googleCallbackUrl' => $this->appUrl() . '/auth/google/callback',
            'tab' => $this->usersSettingsTab(),
        ], 'admin/layout');
    }

    public function usersSettingsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users/settings?tab=settings');
            return;
        }
        $this->ensureOptionalSchema($db);
        $this->ensureFanLocalitiesSchema($db);

        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'generate_pages') {
            [$created, $restored] = $this->generateCustomerPages($db);
            Flash::set('success', 'Pagini generate: ' . $created . ' noi, ' . $restored . ' restaurate.');
            header('Location: /admin/users/settings?tab=settings');
            return;
        }
        if ($action === 'save_google_auth') {
            $enabled = isset($_POST['google_enabled']) ? '1' : '0';
            $clientId = trim((string) ($_POST['google_client_id'] ?? ''));
            $clientSecret = trim((string) ($_POST['google_client_secret'] ?? ''));
            if ($enabled === '1' && ($clientId === '' || $clientSecret === '')) {
                Flash::set('error', 'Pentru activarea login-ului Google completează Client ID și Client Secret.');
                header('Location: /admin/users/settings?tab=settings');
                return;
            }

            Settings::save($db, [
                'customer_google_auth_enabled' => $enabled,
                'customer_google_client_id' => $clientId,
                'customer_google_client_secret' => $clientSecret,
            ]);
            Flash::set('success', 'Setările Google au fost salvate.');
            header('Location: /admin/users/settings?tab=settings');
            return;
        }

        Settings::save($db, [
            'customer_registration_field_first_name' => isset($_POST['field_first_name']) ? '1' : '0',
            'customer_registration_field_last_name' => isset($_POST['field_last_name']) ? '1' : '0',
            'customer_registration_field_email' => isset($_POST['field_email']) ? '1' : '0',
            'customer_registration_field_phone' => isset($_POST['field_phone']) ? '1' : '0',
            'customer_registration_field_birth_date' => isset($_POST['field_birth_date']) ? '1' : '0',
            'customer_registration_field_gender' => isset($_POST['field_gender']) ? '1' : '0',
            'customer_registration_field_password' => isset($_POST['field_password']) ? '1' : '0',
            'customer_registration_field_password_confirm' => isset($_POST['field_password_confirm']) ? '1' : '0',
        ]);
        Flash::set('success', 'Setările utilizatorilor au fost salvate.');
        header('Location: /admin/users/settings?tab=settings');
    }

    public function usersPoints(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users/settings?tab=points');
            return;
        }
        $this->ensureOptionalSchema($db);
        $settings = Settings::all($db);
        $loyalty = LoyaltyService::config($settings);
        $transactions = LoyaltyService::recentTransactions($db, 200);
        $promoRules = LoyaltyService::promoRules($db);
        $users = $this->loadUsersWithStats($db, trim((string) ($_GET['q'] ?? '')));
        $pointsSearch = trim((string) ($_GET['points_q'] ?? ''));
        try {
            $claimedPointsAccounts = $this->loadClaimedLoyaltyAccounts($db, $pointsSearch);
        } catch (Throwable) {
            $claimedPointsAccounts = [];
            Flash::set('error', 'Nu am putut încărca lista de puncte revendicate.');
        }
        $unclaimedPointsAccounts = $this->loadUnclaimedLoyaltyEmailPointsSafe(
            $db,
            $pointsSearch,
            (float) ($loyalty['earn_rate'] ?? 1.0)
        );
        $pointsImportMissingEmails = [];
        if (isset($_SESSION['users_points_import_missing_emails']) && is_array($_SESSION['users_points_import_missing_emails'])) {
            $pointsImportMissingEmails = array_values(array_filter(
                array_map(static fn (mixed $value): string => trim((string) $value), $_SESSION['users_points_import_missing_emails']),
                static fn (string $email): bool => $email !== ''
            ));
            unset($_SESSION['users_points_import_missing_emails']);
        }
        $defaultLoyaltyPanel = trim((string) ($_GET['panel'] ?? ''));
        if ($defaultLoyaltyPanel === '') {
            $defaultLoyaltyPanel = $pointsImportMissingEmails !== [] ? 'import' : 'settings';
        }
        if (!in_array($defaultLoyaltyPanel, ['settings', 'adjust', 'promo', 'history', 'import', 'claimed', 'unclaimed'], true)) {
            $defaultLoyaltyPanel = 'settings';
        }

        View::render('admin/users-points', [
            'title' => 'Puncte fidelitate',
            'settings' => $settings,
            'loyalty' => $loyalty,
            'transactions' => $transactions,
            'promoRules' => $promoRules,
            'users' => $users,
            'claimedPointsAccounts' => $claimedPointsAccounts,
            'unclaimedPointsAccounts' => $unclaimedPointsAccounts,
            'pointsSearch' => $pointsSearch,
            'pointsImportMissingEmails' => $pointsImportMissingEmails,
            'defaultLoyaltyPanel' => $defaultLoyaltyPanel,
            'tab' => 'points',
        ], 'admin/layout');
    }

    public function usersPointsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users/settings?tab=points');
            return;
        }
        $this->ensureOptionalSchema($db);
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'save_settings') {
            $enabled = isset($_POST['loyalty_points_enabled']) ? '1' : '0';
            $earnRate = trim((string) ($_POST['loyalty_points_earn_rate'] ?? '1'));
            $redeemValue = trim((string) ($_POST['loyalty_points_redeem_value'] ?? '0.10'));
            $minRedeem = trim((string) ($_POST['loyalty_points_min_redeem'] ?? '100'));
            $maxPercent = trim((string) ($_POST['loyalty_points_max_redeem_percent'] ?? '50'));
            $promoActive = isset($_POST['loyalty_points_promo_active']) ? '1' : '0';
            $promoMultiplier = trim((string) ($_POST['loyalty_points_promo_multiplier'] ?? '1'));
            $promoWeekendMultiplier = trim((string) ($_POST['loyalty_points_weekend_multiplier'] ?? '1'));

            $oldSettings = Settings::all($db);
            $newValues = [
                'loyalty_points_enabled' => $enabled,
                'loyalty_points_earn_rate' => $earnRate !== '' ? $earnRate : '1',
                'loyalty_points_redeem_value' => $redeemValue !== '' ? $redeemValue : '0.10',
                'loyalty_points_min_redeem' => $minRedeem !== '' ? $minRedeem : '100',
                'loyalty_points_max_redeem_percent' => $maxPercent !== '' ? $maxPercent : '50',
                'loyalty_points_promo_active' => $promoActive,
                'loyalty_points_promo_multiplier' => $promoMultiplier !== '' ? $promoMultiplier : '1',
                'loyalty_points_weekend_multiplier' => $promoWeekendMultiplier !== '' ? $promoWeekendMultiplier : '1',
            ];
            Settings::save($db, $newValues);

            $changes = [];
            foreach ($newValues as $key => $newVal) {
                $oldVal = (string) ($oldSettings[$key] ?? '');
                if ($oldVal !== $newVal) {
                    $changes[$key] = ['from' => $oldVal, 'to' => $newVal];
                }
            }
            AdminActivityLog::log($db, 'loyalty_settings_save', [
                'changes' => $changes,
                'all'     => $newValues,
            ]);

            Flash::set('success', 'Setările de puncte au fost salvate.');
            header('Location: /admin/users/settings?tab=points');
            return;
        }

        if ($action === 'adjust_points') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $delta = (int) ($_POST['points_delta'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($userId <= 0 || $delta === 0) {
                Flash::set('error', 'Selectează utilizatorul și o ajustare diferită de 0.');
                header('Location: /admin/users/settings?tab=points');
                return;
            }

            $ok = LoyaltyService::adjustUserPoints($db, $userId, $delta, $reason, Auth::id());
            if ($ok) {
                Flash::set('success', 'Punctele utilizatorului au fost actualizate.');
            } else {
                Flash::set('error', 'Nu am putut ajusta punctele utilizatorului.');
            }
            header('Location: /admin/users/settings?tab=points');
            return;
        }

        if ($action === 'save_promo_rule') {
            $payload = [
                'id' => (int) ($_POST['rule_id'] ?? 0),
                'name' => trim((string) ($_POST['rule_name'] ?? '')),
                'description' => trim((string) ($_POST['rule_description'] ?? '')),
                'multiplier' => trim((string) ($_POST['rule_multiplier'] ?? '1')),
                'min_order_total' => trim((string) ($_POST['rule_min_order_total'] ?? '')),
                'category_id' => (int) ($_POST['rule_category_id'] ?? 0),
                'starts_at' => trim((string) ($_POST['rule_starts_at'] ?? '')),
                'ends_at' => trim((string) ($_POST['rule_ends_at'] ?? '')),
                'is_active' => isset($_POST['rule_is_active']) ? 1 : 0,
            ];
            $ok = LoyaltyService::savePromoRule($db, $payload);
            Flash::set($ok ? 'success' : 'error', $ok ? 'Regula promo a fost salvată.' : 'Nu am putut salva regula promo.');
            header('Location: /admin/users/settings?tab=points');
            return;
        }

        if ($action === 'delete_promo_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $ok = LoyaltyService::deletePromoRule($db, $ruleId);
            Flash::set($ok ? 'success' : 'error', $ok ? 'Regula promo a fost ștearsă.' : 'Nu am putut șterge regula promo.');
            header('Location: /admin/users/settings?tab=points');
            return;
        }

        if ($action === 'adjust_unclaimed') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $newTotal = max(0, (int) ($_POST['new_total'] ?? 0));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Flash::set('error', 'Email invalid.');
                header('Location: /admin/users/points?panel=unclaimed');
                return;
            }
            // Load all orders with pending points for this email
            $hasPendingClaim = $this->tableHasColumn($db, 'orders', 'loyalty_points_pending_claim');
            if ($hasPendingClaim) {
                $stmt = $db->prepare(
                    'SELECT id, loyalty_points_pending_claim
                     FROM orders
                     WHERE deleted_at IS NULL AND user_id IS NULL
                       AND LOWER(TRIM(billing_email)) = :email
                       AND loyalty_points_pending_claim > 0
                     ORDER BY id ASC'
                );
                $stmt->execute(['email' => $email]);
                $rows = $stmt->fetchAll() ?: [];
                $currentTotal = array_sum(array_column($rows, 'loyalty_points_pending_claim'));
                if ($newTotal >= $currentTotal) {
                    Flash::set('info', 'Nicio modificare — totalul nou (' . $newTotal . ') ≥ totalul curent (' . $currentTotal . ').');
                    header('Location: /admin/users/points?panel=unclaimed');
                    return;
                }
                $toRemove = $currentTotal - $newTotal;
                $upd = $db->prepare('UPDATE orders SET loyalty_points_pending_claim = :pts WHERE id = :id');
                foreach ($rows as $row) {
                    $pts = max(0, (int) ($row['loyalty_points_pending_claim'] ?? 0));
                    if ($pts <= 0 || $toRemove <= 0) {
                        break;
                    }
                    $cut = min($pts, $toRemove);
                    $upd->execute(['pts' => $pts - $cut, 'id' => $row['id']]);
                    $toRemove -= $cut;
                }
            }
            Flash::set('success', 'Punctele nerevendicate pentru ' . htmlspecialchars($email, ENT_QUOTES) . ' au fost actualizate la ' . $newTotal . ' pct.');
            header('Location: /admin/users/points?panel=unclaimed');
            return;
        }

        header('Location: /admin/users/settings?tab=points');
    }

    public function usersPointsImport(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users/settings?tab=points');
            return;
        }
        $this->ensureOptionalSchema($db);

        $result = $this->importLoyaltyPointsFromUploadedFile($db, $_FILES['points_file'] ?? null);
        if (isset($result['not_found_emails']) && is_array($result['not_found_emails'])) {
            $_SESSION['users_points_import_missing_emails'] = $result['not_found_emails'];
        } else {
            unset($_SESSION['users_points_import_missing_emails']);
        }
        Flash::set($result['ok'] ? 'success' : 'error', (string) ($result['message'] ?? 'Import invalid.'));
        header('Location: /admin/users/settings?tab=points#import');
    }

    public function usersPointsHistory(): void
    {
        if (!$this->guard()) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Conexiunea DB nu este disponibilă.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        $this->ensureOptionalSchema($db);

        $userId = max(0, (int) ($_GET['user_id'] ?? 0));
        if ($userId <= 0) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Utilizator invalid.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $user = $this->loadAdminUserById($db, $userId);
        if (!is_array($user)) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'Utilizatorul nu a fost găsit.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $history = $this->loadUserLoyaltyHistory($db, $userId, 800);
        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        echo json_encode([
            'ok' => true,
            'user' => [
                'id' => $userId,
                'name' => $name !== '' ? $name : 'Utilizator',
                'email' => (string) ($user['email'] ?? ''),
                'loyalty_points' => (int) ($user['loyalty_points'] ?? 0),
            ],
            'history' => $history,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Adresele care primesc mesajele din formularul de contact. */
    public function contactRecipientsSave(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/emails/newsletters?tab=contact_forms');
            return;
        }

        $brut = (string) ($_POST['contact_form_recipients'] ?? '');
        $bucati = preg_split('/[\s,;]+/', trim($brut)) ?: [];
        $valide = [];
        $invalide = [];
        foreach ($bucati as $adresa) {
            $adresa = trim((string) $adresa);
            if ($adresa === '') {
                continue;
            }
            if (filter_var($adresa, FILTER_VALIDATE_EMAIL)) {
                $valide[strtolower($adresa)] = $adresa;
            } else {
                $invalide[] = $adresa;
            }
        }
        if ($invalide !== []) {
            Flash::set('error', 'Adrese invalide: ' . implode(', ', $invalide) . '. Nu am salvat nimic.');
            header('Location: /admin/emails/newsletters?tab=contact_forms');
            return;
        }
        if ($valide === []) {
            Flash::set('error', 'Lasă cel puțin o adresă; altfel mesajele n-ar ajunge nicăieri.');
            header('Location: /admin/emails/newsletters?tab=contact_forms');
            return;
        }

        Settings::save($db, ['contact_form_recipients' => implode(', ', array_values($valide))]);
        AdminActivityLog::log($db, 'contact_recipients_save', ['adrese' => array_values($valide)]);
        Flash::set('success', 'Destinatarii formularului de contact au fost salvați (' . count($valide) . ').');
        header('Location: /admin/emails/newsletters?tab=contact_forms');
    }

    public function usersFormSecurity(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users');
            return;
        }

        $this->ensureOptionalSchema($db);
        $this->ensureFormSecurityLogsSchema($db);
        $search = trim((string) ($_GET['q'] ?? ''));
        $source = trim((string) ($_GET['source'] ?? 'all'));
        if (!in_array($source, ['all', 'register', 'checkout'], true)) {
            $source = 'all';
        }
        $status = trim((string) ($_GET['status'] ?? 'all'));
        $allowedStatuses = ['all', 'allowed', 'bot_honeypot', 'honeypot_legacy', 'invalid_payload', 'token_missing', 'too_fast', 'expired', 'timestamp_mismatch', 'rate_limited'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'all';
        }
        $page = $this->normalizeAdminPage((int) ($_GET['page'] ?? 1));
        $perPage = $this->normalizeAdminPerPage((int) ($_GET['per_page'] ?? 25));

        $logs = [];
        $total = 0;
        $totalPages = 1;
        try {
            $subqueries = [];
            if ($source === 'register') {
                $subqueries[] = 'SELECT "register" AS source, id, ip_address, user_agent, status, created_at FROM register_submit_logs';
            } elseif ($source === 'checkout') {
                $subqueries[] = 'SELECT "checkout" AS source, id, ip_address, user_agent, status, created_at FROM checkout_submit_logs';
            } else {
                $subqueries[] = 'SELECT "register" AS source, id, ip_address, user_agent, status, created_at FROM register_submit_logs';
                $subqueries[] = 'SELECT "checkout" AS source, id, ip_address, user_agent, status, created_at FROM checkout_submit_logs';
            }
            $unionSql = implode(' UNION ALL ', $subqueries);

            $outerFilters = [];
            $params = [];
            if ($status !== 'all') {
                $outerFilters[] = 'x.status = :status';
                $params['status'] = $status;
            }
            if ($search !== '') {
                $outerFilters[] = '(x.ip_address LIKE :search OR x.user_agent LIKE :search)';
                $params['search'] = '%' . $search . '%';
            }
            $outerWhere = $outerFilters !== [] ? (' WHERE ' . implode(' AND ', $outerFilters)) : '';
            $countSql = 'SELECT COUNT(*) FROM (' . $unionSql . ') x' . $outerWhere;
            $countStmt = $db->prepare($countSql);
            foreach ($params as $key => $value) {
                $countStmt->bindValue(':' . $key, $value);
            }
            $countStmt->execute();
            $total = max(0, (int) $countStmt->fetchColumn());
            $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            $listSql = 'SELECT *
                        FROM (' . $unionSql . ') x
                        ' . $outerWhere . '
                        ORDER BY x.created_at DESC, x.id DESC
                        LIMIT :limit OFFSET :offset';
            $listStmt = $db->prepare($listSql);
            foreach ($params as $key => $value) {
                $listStmt->bindValue(':' . $key, $value);
            }
            $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $listStmt->execute();
            $logs = $listStmt->fetchAll() ?: [];
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut încărca logurile de securitate formulare.');
        }

        View::render('admin/users-security', [
            'title' => 'Securitate formulare',
            'logs' => is_array($logs) ? $logs : [],
            'search' => $search,
            'source' => $source,
            'status' => $status,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'perPageOptions' => $this->adminPerPageOptions(),
        ], 'admin/layout');
    }

    public function userDetails(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = $this->usersListSortKey((string) ($_GET['sort'] ?? ''));
        $dir = $this->usersListSortDir((string) ($_GET['dir'] ?? ''));
        $page = $this->normalizeAdminPage((int) ($_GET['page'] ?? 1));
        $perPage = $this->normalizeAdminPerPage((int) ($_GET['per_page'] ?? 25));
        $panel = $this->normalizeAdminListPanel((string) ($_GET['panel'] ?? 'list'));
        $selectedId = (int) ($params['id'] ?? 0);
        $this->renderUsersPage($selectedId, $search, $sort, $dir, $page, $perPage, $panel);
    }

    private function renderUsersPage(
        int $selectedId,
        string $search = '',
        string $sort = 'id',
        string $dir = 'desc',
        int $page = 1,
        int $perPage = 25,
        string $panel = 'list'
    ): void
    {
        $db = $this->db();
        $users = [];
        $usersTotal = 0;
        $usersTotalPages = 1;
        $selectedUser = null;
        $selectedUserOrders = [];
        $selectedBilling = null;

        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $pagination = $this->loadUsersWithStatsPaginated($db, $search, $sort, $dir, $page, $perPage);
            $users = is_array($pagination['items'] ?? null) ? $pagination['items'] : [];
            $page = (int) ($pagination['page'] ?? $page);
            $perPage = (int) ($pagination['per_page'] ?? $perPage);
            $usersTotal = (int) ($pagination['total'] ?? 0);
            $usersTotalPages = (int) ($pagination['total_pages'] ?? 1);

            if ($selectedId > 0) {
                $selectedUser = $this->loadAdminUserById($db, $selectedId);
                if (is_array($selectedUser)) {
                    $selectedUserOrders = $this->loadAdminUserOrders(
                        $db,
                        (int) ($selectedUser['id'] ?? 0),
                        (string) ($selectedUser['email'] ?? '')
                    );
                    if (!empty($selectedUserOrders) && is_array($selectedUserOrders[0])) {
                        $selectedBilling = $selectedUserOrders[0];
                    }
                }
            }
        }

        View::render('admin/users', [
            'title' => 'Utilizatori',
            'users' => $users,
            'selectedUser' => $selectedUser,
            'editingUser' => $selectedUser,
            'selectedUserOrders' => $selectedUserOrders,
            'selectedBilling' => $selectedBilling,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
            'totalUsers' => $usersTotal,
            'totalPages' => $usersTotalPages,
            'perPageOptions' => $this->adminPerPageOptions(),
            'panel' => $panel,
            'wpDumpFiles' => $this->wordpressDumpFiles(),
            'wpDumpDir' => self::WP_DUMP_DIR,
            'wpDumpReport' => Flash::get('wp_dump_report'),
            'newsletterLists' => $db instanceof PDO ? $this->newsletterListsForImport($db) : [],
        ], 'admin/layout');
    }

    /**
     * Arhivele de backup WordPress puse pe server (prin FTP / File Manager).
     * Nu se încarcă prin formular: dump-ul are sute de MB, iar limitele PHP de
     * upload l-ar refuza oricum.
     *
     * @return array<int,array{nume:string,marime:int,data:string}>
     */
    private function wordpressDumpFiles(): array
    {
        $dir = dirname(__DIR__, 3) . self::WP_DUMP_DIR;
        if (!is_dir($dir)) {
            return [];
        }
        $fisiere = [];
        foreach ((array) scandir($dir) as $nume) {
            $nume = (string) $nume;
            if ($nume === '' || $nume[0] === '.') {
                continue;
            }
            $cale = $dir . '/' . $nume;
            if (!is_file($cale)) {
                continue;
            }
            $ext = strtolower(pathinfo($nume, PATHINFO_EXTENSION));
            if (!in_array($ext, ['gz', 'sql'], true)) {
                continue;
            }
            $fisiere[] = [
                'nume' => $nume,
                'marime' => (int) filesize($cale),
                'data' => date('d.m.Y H:i', (int) filemtime($cale)),
            ];
        }
        usort($fisiere, static fn (array $a, array $b): int => strcmp($b['nume'], $a['nume']));
        return $fisiere;
    }

    /**
     * Pagina și căutarea din lista de abonați, ca revenirea după o acțiune să
     * cadă unde erai. Cu zeci de mii de adrese, întoarcerea la pagina 1 după
     * fiecare ștergere ar face lista nefolosibilă.
     */
    private function subscribersReturnQuery(): string
    {
        $bucati = [];
        $pagina = (int) ($_POST['sub_page'] ?? 0);
        if ($pagina > 1) {
            $bucati['sub_page'] = $pagina;
        }
        $cautare = trim((string) ($_POST['sub_q'] ?? ''));
        if ($cautare !== '') {
            $bucati['sub_q'] = $cautare;
        }
        return $bucati === [] ? '' : '&' . http_build_query($bucati);
    }

    /** Listele de newsletter, pentru alegerea destinației la import. */
    private function newsletterListsForImport(PDO $db): array
    {
        try {
            NewsletterService::ensureSchema($db);
            $stmt = $db->query('SELECT id, name FROM newsletter_lists ORDER BY is_default DESC, name ASC');
            return $stmt !== false ? (array) $stmt->fetchAll() : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function products(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $products = [];
        $galleryImages = [];
        $extraFields = [];
        $productTemplates = [];

        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            \App\Support\CheckoutCalculator::ensureProductVatSchema($db);
            try {
                $rows = $db->query(
                    'SELECT p.id, p.name, p.sku, p.category, p.category_id, c.name AS category_name, p.slug, p.price, p.vat_percent, p.vat_included, p.sale_price, p.sale_price_periods_json, p.discount_badge_mode, p.bbd_enabled, p.bbd_entries_json, p.post_cart_note_enabled, p.post_cart_note_text, p.stock, p.out_of_stock, p.weight_grams, p.image_url, p.is_active,
                            p.product_template_id, pt.name AS product_template_name,
                            p.short_description, p.description, p.product_highlights, p.similar_products_json,
                            p.gallery_images_json, p.badge_popular, p.badge_best_seller, p.badge_seasonal
                     FROM products p
                     LEFT JOIN product_categories c ON c.id = p.category_id
                     LEFT JOIN product_templates pt ON pt.id = p.product_template_id
                     WHERE p.deleted_at IS NULL
                     ORDER BY p.id DESC'
                )->fetchAll();
                $products = is_array($rows) ? $rows : [];
            } catch (Throwable) {
                // Fallback for partial schema upgrades (missing template columns/table).
                try {
                    $rows = $db->query(
                        'SELECT p.id, p.name, p.sku, p.category, p.category_id, c.name AS category_name, p.slug, p.price, 19.00 AS vat_percent, 1 AS vat_included, p.sale_price, NULL AS sale_price_periods_json, 0 AS bbd_enabled, NULL AS bbd_entries_json, 0 AS post_cart_note_enabled, NULL AS post_cart_note_text, p.stock, 0 AS out_of_stock, p.weight_grams, p.image_url, p.is_active,
                                p.product_template_id, NULL AS product_template_name,
                                p.short_description, p.description, NULL AS product_highlights, p.similar_products_json,
                                p.gallery_json AS gallery_images_json, 0 AS badge_popular, 0 AS badge_best_seller, 0 AS badge_seasonal
                         FROM products p
                         LEFT JOIN product_categories c ON c.id = p.category_id
                         WHERE p.deleted_at IS NULL
                         ORDER BY p.id DESC'
                    )->fetchAll();
                    $products = is_array($rows) ? $rows : [];
                } catch (Throwable) {
                    try {
                        $rows = $db->query(
                            'SELECT p.id, p.name, p.sku, p.category, p.category_id, c.name AS category_name, p.slug, p.price, 19.00 AS vat_percent, 1 AS vat_included, p.sale_price, NULL AS sale_price_periods_json, 0 AS bbd_enabled, NULL AS bbd_entries_json, 0 AS post_cart_note_enabled, NULL AS post_cart_note_text, p.stock, 0 AS out_of_stock, p.weight_grams, p.image_url, p.is_active,
                                    p.product_template_id, NULL AS product_template_name,
                                    p.short_description, p.description, NULL AS product_highlights, NULL AS similar_products_json,
                                    p.image_gallery_json AS gallery_images_json, p.label_popular AS badge_popular, p.label_best_seller AS badge_best_seller, p.label_seasonal AS badge_seasonal
                             FROM products p
                             LEFT JOIN product_categories c ON c.id = p.category_id
                             WHERE p.deleted_at IS NULL
                             ORDER BY p.id DESC'
                        )->fetchAll();
                        $products = is_array($rows) ? $rows : [];
                    } catch (Throwable) {
                        $products = [];
                    }
                }
            }
            // Cu ERP-ul conectat, stocul afișat în admin e cel din gestiune —
            // ca operatorul să vadă aceeași cifră ca site-ul și ca ERP-ul.
            \App\Support\ErpStock::applyToProducts($db, $products);

            foreach ($products as &$product) {
                if (!is_array($product)) {
                    continue;
                }
                if (!array_key_exists('product_template_id', $product)) {
                    $product['product_template_id'] = null;
                }
                if (!array_key_exists('product_template_name', $product)) {
                    $product['product_template_name'] = null;
                }
                $galleryRaw = (string) ($product['gallery_images_json'] ?? '');
                $gallery = [];
                if ($galleryRaw !== '') {
                    $decoded = json_decode($galleryRaw, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $image) {
                            $url = trim((string) $image);
                            if ($url !== '') {
                                $gallery[] = $url;
                            }
                        }
                    }
                }
                if ($gallery === []) {
                    $mainImage = trim((string) ($product['image_url'] ?? ''));
                    if ($mainImage !== '') {
                        $gallery[] = $mainImage;
                    }
                }
                if ($gallery === []) {
                    $gallery[] = '/assets/img/product-placeholder.svg';
                }
                $product['image_gallery'] = $gallery;
                $product['similar_products'] = $this->normalizeProductSimilarIds($product['similar_products_json'] ?? '[]');
                $product['badge_popular'] = (int) ($product['badge_popular'] ?? 0) === 1 ? 1 : 0;
                $product['badge_best_seller'] = (int) ($product['badge_best_seller'] ?? 0) === 1 ? 1 : 0;
                $product['badge_seasonal'] = (int) ($product['badge_seasonal'] ?? 0) === 1 ? 1 : 0;
                $product['out_of_stock'] = (int) ($product['out_of_stock'] ?? 0) === 1 ? 1 : 0;
                $pricing = $this->resolveProductPricing(
                    (float) ($product['price'] ?? 0.0),
                    $product['sale_price'] ?? null,
                    $product['sale_price_periods_json'] ?? '[]'
                );
                $product['base_price'] = (float) ($product['price'] ?? 0.0);
                $product['price'] = (float) ($pricing['effective_price'] ?? 0.0);
                $product['sale_price'] = $pricing['sale_price'] ?? null;
                $product['has_sale_price'] = (bool) ($pricing['has_sale_price'] ?? false);
                $product['sale_price_periods'] = $pricing['periods'] ?? [];
                $product['bbd_enabled'] = (int) ($product['bbd_enabled'] ?? 0) === 1 ? 1 : 0;
                $product['bbd_entries'] = $this->normalizeProductBbdEntries($product['bbd_entries_json'] ?? '[]');
                $product['bbd_stock_usage'] = ($product['bbd_enabled'] === 1 && $db instanceof PDO)
                    ? $this->computeBbdReservedMap($db, (int) ($product['id'] ?? 0))
                    : (object) [];
                $product['post_cart_note_enabled'] = (int) ($product['post_cart_note_enabled'] ?? 0) === 1 ? 1 : 0;
                $product['post_cart_note_text'] = trim((string) ($product['post_cart_note_text'] ?? ''));
            }
            unset($product);

            try {
                $extraFields = $this->loadProductExtraFields($db);
            } catch (Throwable) {
                $extraFields = [];
            }
            try {
                $productTemplates = $this->loadProductTemplates($db);
            } catch (Throwable) {
                $productTemplates = [];
            }

            if ($products !== [] && $extraFields !== []) {
                $productIds = array_values(array_filter(
                    array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $products),
                    static fn (int $value): bool => $value > 0
                ));
                $fieldIds = array_values(array_filter(
                    array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $extraFields),
                    static fn (int $value): bool => $value > 0
                ));
                if ($productIds !== [] && $fieldIds !== []) {
                    $productPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
                    $fieldPlaceholders = implode(',', array_fill(0, count($fieldIds), '?'));
                    $valueMap = [];
                    try {
                        $stmt = $db->prepare(
                            "SELECT product_id, field_id, `value`
                             FROM product_extra_field_values
                             WHERE product_id IN ($productPlaceholders)
                               AND field_id IN ($fieldPlaceholders)"
                        );
                        $stmt->execute(array_merge($productIds, $fieldIds));
                        foreach ($stmt->fetchAll() as $row) {
                            if (!is_array($row)) {
                                continue;
                            }
                            $pid = (int) ($row['product_id'] ?? 0);
                            $fid = (int) ($row['field_id'] ?? 0);
                            if ($pid <= 0 || $fid <= 0) {
                                continue;
                            }
                            if (!isset($valueMap[$pid])) {
                                $valueMap[$pid] = [];
                            }
                            $valueMap[$pid][$fid] = (string) ($row['value'] ?? '');
                        }
                    } catch (Throwable) {
                        // Legacy fallback for old draft schema that used value_text.
                        try {
                            $stmt = $db->prepare(
                                "SELECT product_id, field_id, value_text
                                 FROM product_extra_field_values
                                 WHERE product_id IN ($productPlaceholders)
                                   AND field_id IN ($fieldPlaceholders)"
                            );
                            $stmt->execute(array_merge($productIds, $fieldIds));
                            foreach ($stmt->fetchAll() as $row) {
                                if (!is_array($row)) {
                                    continue;
                                }
                                $pid = (int) ($row['product_id'] ?? 0);
                                $fid = (int) ($row['field_id'] ?? 0);
                                if ($pid <= 0 || $fid <= 0) {
                                    continue;
                                }
                                if (!isset($valueMap[$pid])) {
                                    $valueMap[$pid] = [];
                                }
                                $valueMap[$pid][$fid] = (string) ($row['value_text'] ?? '');
                            }
                        } catch (Throwable) {
                            $valueMap = [];
                        }
                    }
                    foreach ($products as &$product) {
                        $pid = (int) ($product['id'] ?? 0);
                        $product['extra_fields'] = $valueMap[$pid] ?? [];
                    }
                    unset($product);
                }
            }
            // Categoriile suplimentare, pentru bifele din formularul de editare.
            $extraCategoryMap = \App\Support\ProductCategories::idsForProducts(
                $db,
                array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), array_filter($products, 'is_array'))
            );
            foreach ($products as &$product) {
                $product['extra_category_ids'] = $extraCategoryMap[(int) ($product['id'] ?? 0)] ?? [];
            }
            unset($product);

            $productSeoMap = [];
            if ($products !== []) {
                $productSeoRefs = [];
                foreach ($products as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $productId = (int) ($row['id'] ?? 0);
                    if ($productId <= 0) {
                        continue;
                    }
                    $productSeoRefs[] = (string) $productId;
                }
                if ($productSeoRefs !== []) {
                    $productSeoMap = $this->loadSeoSettingsMap($db, 'product', $productSeoRefs);
                }
            }
            foreach ($products as &$product) {
                if (!isset($product['extra_fields']) || !is_array($product['extra_fields'])) {
                    $product['extra_fields'] = [];
                }
                $product['similar_products'] = $this->normalizeProductSimilarIds($product['similar_products_json'] ?? '[]');
                $productId = (int) ($product['id'] ?? 0);
                $seo = $productSeoMap[(string) $productId] ?? $this->defaultSeoSettings();
                $product['seo_title'] = (string) ($seo['title'] ?? '');
                $product['seo_description'] = (string) ($seo['description'] ?? '');
                $product['seo_canonical_url'] = (string) ($seo['canonical_url'] ?? '');
                $product['seo_image_url'] = (string) ($seo['image_url'] ?? '');
            }
            unset($product);

            try {
                $galleryRows = $db->query(
                    "SELECT id, title, image_url, alt_text
                     FROM gallery_images
                     WHERE media_type = 'image'
                     ORDER BY id DESC
                     LIMIT 300"
                )->fetchAll();
                $galleryImages = is_array($galleryRows) ? $galleryRows : [];
            } catch (Throwable) {
                $galleryImages = [];
            }
        }

        $categories = $this->categoriesList();
        $defaultVat = $db instanceof PDO
            ? (string) (Settings::all($db)['default_vat_percent'] ?? '19')
            : '19';
        View::render('admin/products', [
            'title' => 'Produse',
            'products' => $products,
            'categories' => $categories,
            'galleryImages' => $galleryImages,
            'extraFields' => $extraFields,
            'productTemplates' => $productTemplates,
            'defaultVat' => $defaultVat,
        ], 'admin/layout');
    }

    public function productFields(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products');
            return;
        }
        $this->ensureOptionalSchema($db);

        $fields = $this->loadProductExtraFields($db);
        $fieldParam = trim((string) ($_GET['field'] ?? ''));
        $selectedFieldId = ctype_digit($fieldParam) ? (int) $fieldParam : 0;
        $newRequested = $fieldParam === 'new';
        $editingField = $selectedFieldId > 0 ? $this->loadProductExtraFieldById($db, $selectedFieldId) : null;

        View::render('admin/product-fields', [
            'title' => 'Câmpuri suplimentare',
            'fields' => $fields,
            'editingField' => $editingField,
            'selectedFieldId' => $selectedFieldId,
            'newRequested' => $newRequested,
        ], 'admin/layout');
    }

    public function productFieldsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products/fields');
            return;
        }
        $this->ensureOptionalSchema($db);

        $action = trim((string) ($_POST['action'] ?? 'save'));
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->beginTransaction();
                try {
                    $stmtValues = $db->prepare('DELETE FROM product_extra_field_values WHERE field_id = :id');
                    $stmtValues->execute(['id' => $id]);
                    $stmt = $db->prepare('DELETE FROM product_extra_fields WHERE id = :id');
                    $stmt->execute(['id' => $id]);
                    $db->commit();
                    Flash::set('success', 'Câmpul a fost șters.');
                } catch (Throwable) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    Flash::set('error', 'Nu am putut șterge câmpul.');
                }
            }
            header('Location: /admin/products/fields');
            return;
        }

        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? $_POST['label'] ?? ''));
        $fieldKey = $this->normalizeProductFieldKey((string) ($_POST['field_key'] ?? $_POST['code'] ?? ''));
        $fieldType = trim((string) ($_POST['field_type'] ?? $_POST['type'] ?? 'textarea'));
        if (!in_array($fieldType, ['text', 'textarea', 'html'], true)) {
            $fieldType = 'textarea';
        }
        $placeholder = trim((string) ($_POST['placeholder'] ?? ''));
        $defaultValue = trim((string) ($_POST['default_value'] ?? ''));
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $fieldKey === '') {
            Flash::set('error', 'Numele și codul câmpului sunt obligatorii.');
            header('Location: /admin/products/fields' . ($id > 0 ? '?field=' . $id : '?field=new'));
            return;
        }

        try {
            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE product_extra_fields
                     SET name = :name,
                         field_key = :field_key,
                         field_type = :field_type,
                         placeholder = :placeholder,
                         default_value = :default_value,
                         is_required = :is_required,
                         sort_order = :sort_order,
                         is_active = :is_active
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                    'field_key' => $fieldKey,
                    'field_type' => $fieldType,
                    'placeholder' => $placeholder !== '' ? $placeholder : null,
                    'default_value' => $defaultValue !== '' ? $defaultValue : null,
                    'is_required' => $isRequired,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                ]);
                Flash::set('success', 'Câmpul a fost actualizat.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO product_extra_fields (name, field_key, field_type, placeholder, default_value, is_required, sort_order, is_active)
                     VALUES (:name, :field_key, :field_type, :placeholder, :default_value, :is_required, :sort_order, :is_active)'
                );
                $stmt->execute([
                    'name' => $name,
                    'field_key' => $fieldKey,
                    'field_type' => $fieldType,
                    'placeholder' => $placeholder !== '' ? $placeholder : null,
                    'default_value' => $defaultValue !== '' ? $defaultValue : null,
                    'is_required' => $isRequired,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                ]);
                Flash::set('success', 'Câmpul a fost adăugat.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut salva câmpul. Verifică dacă acest cod există deja.');
        }

        header('Location: /admin/products/fields');
    }

    public function productTemplates(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products');
            return;
        }
        $this->ensureOptionalSchema($db);

        try {
            $templates = $this->loadProductTemplates($db);
        } catch (Throwable) {
            $templates = [];
        }

        // Câte produse folosesc fiecare template, plus totalul disponibil,
        // ca butonul „Aplică la toate produsele" să arate impactul real.
        $templateUsage = [];
        $assignableProducts = 0;
        try {
            $usageRows = $db->query(
                'SELECT product_template_id AS template_id, COUNT(*) AS total
                 FROM products
                 WHERE deleted_at IS NULL AND is_active = 1 AND product_template_id IS NOT NULL
                 GROUP BY product_template_id'
            )->fetchAll() ?: [];
            foreach ($usageRows as $usageRow) {
                if (!is_array($usageRow)) {
                    continue;
                }
                $templateUsage[(int) ($usageRow['template_id'] ?? 0)] = (int) ($usageRow['total'] ?? 0);
            }
            $assignableProducts = (int) $db->query(
                'SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND is_active = 1'
            )->fetchColumn();
        } catch (Throwable) {
            $templateUsage = [];
            $assignableProducts = 0;
        }

        View::render('admin/product-templates', [
            'title' => 'Template-uri produse',
            'templates' => $templates,
            'templateUsage' => $templateUsage,
            'assignableProducts' => $assignableProducts,
        ], 'admin/layout');
    }

    public function productTemplateBuilder(array $params = []): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? ($_GET['id'] ?? 0));
        $isProbe = (string) ($_GET['probe'] ?? '') === '1';

        if ($isProbe) {
            $db = $this->db();
            $debug = [
                'ok' => false,
                'probe' => 'productTemplateBuilder',
                'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
                'path' => (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''),
                'id' => $id,
                'db_connected' => $db instanceof PDO,
                'template_view_exists' => is_file(__DIR__ . '/../../../views/admin/product-template-editor.php'),
                'layout_view_exists' => is_file(__DIR__ . '/../../../views/admin/layout.php'),
            ];
            if ($db instanceof PDO && $id > 0) {
                try {
                    $this->ensureOptionalSchema($db);
                    $template = $this->loadProductTemplateById($db, $id);
                    $debug['template_found'] = is_array($template);
                    if (is_array($template)) {
                        $debug['template_name'] = (string) ($template['name'] ?? '');
                        $debug['template_slug'] = (string) ($template['slug'] ?? '');
                    }
                } catch (Throwable $exception) {
                    $debug['exception'] = $exception->getMessage();
                }
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Template-Builder-Probe: hit');
            echo json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        if ($id <= 0) {
            Flash::set('error', 'Template invalid.');
            header('Location: /admin/products/templates');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products/templates');
            return;
        }
        $this->ensureOptionalSchema($db);

        $template = $this->loadProductTemplateById($db, $id);
        if (!is_array($template)) {
            Flash::set('error', 'Template-ul nu a fost găsit.');
            header('Location: /admin/products/templates');
            return;
        }

        $availablePlaceholders = [
            ['code' => '{{product_name}}', 'label' => 'Nume produs'],
            ['code' => '{{product_slug}}', 'label' => 'Slug produs'],
            ['code' => '{{product_sku}}', 'label' => 'SKU'],
            ['code' => '{{product_category}}', 'label' => 'Categorie produs'],
            ['code' => '{{product_category_url}}', 'label' => 'URL categorie produs (filtru magazin)'],
            ['code' => '{{product_price}}', 'label' => 'Preț (RON)'],
            ['code' => '{{product_price_display}}', 'label' => 'Preț formatat'],
            ['code' => '{{product_sale_price}}', 'label' => 'Preț redus (RON)'],
            ['code' => '{{product_stock}}', 'label' => 'Stoc'],
            ['code' => '{{product_weight_grams}}', 'label' => 'Greutate (g)'],
            ['code' => '{{product_short_description}}', 'label' => 'Descriere scurtă'],
            ['code' => '{{product_description}}', 'label' => 'Descriere'],
            ['code' => '{{product_highlights}}', 'label' => 'Puncte forte (text)'],
            ['code' => '{{product_image_url}}', 'label' => 'URL imagine produs'],
            ['code' => '{{product_image_gallery}}', 'label' => 'Galerie imagini produs'],
            ['code' => '{{product_image_carousel}}', 'label' => 'Carusel imagini produs'],
            ['code' => '{{product_tabs_section}}', 'label' => 'Secțiune tab-uri (câmpuri + recenzii)'],
            ['code' => '{{product_reviews_count}}', 'label' => 'Număr review-uri'],
            ['code' => '{{product_reviews_average}}', 'label' => 'Rating mediu'],
            ['code' => '{{product_reviews_average_raw}}', 'label' => 'Rating mediu brut'],
            ['code' => '{{product_reviews_stars}}', 'label' => 'Stele review-uri'],
            ['code' => '{{product_reviews_list}}', 'label' => 'Listă review-uri'],
            ['code' => '{{product_review_form}}', 'label' => 'Form review'],
            ['code' => '{{product_reviews_section}}', 'label' => 'Secțiune review completă'],
            ['code' => '{{product_similar_products_section}}', 'label' => 'Secțiune produse similare'],
            ['code' => '{{product_icon_truck}}', 'label' => 'Iconiță SVG: truck'],
            ['code' => '{{product_icon_shield}}', 'label' => 'Iconiță SVG: shield'],
            ['code' => '{{product_icon_leaf}}', 'label' => 'Iconiță SVG: leaf'],
            ['code' => '{{product_icon_star}}', 'label' => 'Iconiță SVG: star'],
            ['code' => '{{product_icon_camera}}', 'label' => 'Iconiță SVG: camera'],
            ['code' => '{{product_icon_sparkles}}', 'label' => 'Iconiță SVG: sparkles'],
            ['code' => '{{product_icon_calendar}}', 'label' => 'Iconiță SVG: calendar'],
            ['code' => '{{product_icon_user}}', 'label' => 'Iconiță SVG: user'],
            ['code' => '{{product_quantity_input}}', 'label' => 'Input cantitate'],
            ['code' => '{{product_add_to_cart_button}}', 'label' => 'Buton adaugă în coș'],
        ];
        foreach ($this->loadProductExtraFields($db) as $field) {
            if (!is_array($field) || (int) ($field['is_active'] ?? 1) !== 1) {
                continue;
            }
            $key = trim((string) ($field['field_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = trim((string) ($field['name'] ?? $key));
            $availablePlaceholders[] = [
                'code' => '{{field_' . $key . '}}',
                'label' => 'Câmp suplimentar: ' . $label,
            ];
        }

        $extraFields = [];
        try {
            $extraFields = $this->loadProductExtraFields($db);
        } catch (Throwable) {
            $extraFields = [];
        }

        try {
            View::render('admin/product-template-editor', [
                'title' => 'Builder template produs',
                'productTemplate' => $template,
                'extraFields' => $extraFields,
                'availablePlaceholders' => $availablePlaceholders,
            ], 'admin/layout');
        } catch (Throwable $exception) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'probe' => 'productTemplateBuilderRender',
                'id' => $id,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    public function productTemplateBuilderEntry(): void
    {
        $this->productTemplateBuilder([]);
    }

    public function productTemplatesNew(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products');
            return;
        }
        $this->ensureOptionalSchema($db);

        $templates = $this->loadProductTemplates($db);
        View::render('admin/product-templates', [
            'title' => 'Template-uri produse',
            'templates' => $templates,
            'selectedTemplate' => null,
            'newMode' => true,
            'isNewMode' => true,
            'showCreateModal' => true,
        ], 'admin/layout');
    }

    public function productTemplatesSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products/templates');
            return;
        }
        $this->ensureOptionalSchema($db);

        $action = trim((string) ($_POST['action'] ?? 'save'));
        $isBuilderSave = $action === 'save_builder';
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $db->beginTransaction();
                    $stmtDetach = $db->prepare('UPDATE products SET product_template_id = NULL WHERE product_template_id = :id');
                    $stmtDetach->execute(['id' => $id]);
                    $stmtDelete = $db->prepare('DELETE FROM product_templates WHERE id = :id');
                    $stmtDelete->execute(['id' => $id]);
                    $db->commit();
                    Flash::set('success', 'Template-ul a fost șters.');
                } catch (Throwable) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    Flash::set('error', 'Nu am putut șterge template-ul.');
                }
            }
            header('Location: /admin/products/templates');
            return;
        }

        if ($action === 'apply_all') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                Flash::set('error', 'Template invalid.');
                header('Location: /admin/products/templates');
                return;
            }

            $exists = $db->prepare('SELECT name FROM product_templates WHERE id = :id LIMIT 1');
            $exists->execute(['id' => $id]);
            $templateName = $exists->fetchColumn();
            if ($templateName === false) {
                Flash::set('error', 'Template-ul nu mai există.');
                header('Location: /admin/products/templates');
                return;
            }

            try {
                $stmt = $db->prepare(
                    'UPDATE products
                     SET product_template_id = :id
                     WHERE deleted_at IS NULL
                       AND is_active = 1
                       AND (product_template_id IS NULL OR product_template_id <> :current_id)'
                );
                $stmt->execute(['id' => $id, 'current_id' => $id]);
                $updated = $stmt->rowCount();

                AdminActivityLog::log($db, 'product_template_apply_all', [
                    'id' => $id,
                    'name' => (string) $templateName,
                    'updated' => $updated,
                ]);

                if ($updated > 0) {
                    Flash::set('success', 'Template-ul „' . (string) $templateName . '" a fost aplicat la ' . $updated . ' produse.');
                } else {
                    Flash::set('success', 'Toate produsele active foloseau deja acest template.');
                }
            } catch (Throwable) {
                Flash::set('error', 'Nu am putut aplica template-ul la toate produsele.');
            }

            header('Location: /admin/products/templates');
            return;
        }

        $id = (int) ($_POST['id'] ?? 0);
        $isQuickCreate = trim((string) ($_POST['quick_create'] ?? '')) === '1';
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = $this->slugify($slugInput !== '' ? $slugInput : $name);
        $description = trim((string) ($_POST['description'] ?? ''));
        $html = (string) ($_POST['html_content'] ?? $_POST['html_template'] ?? '');
        $css = (string) ($_POST['css_content'] ?? $_POST['css_template'] ?? '');
        $js = (string) ($_POST['js_content'] ?? $_POST['js_template'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $slug === '') {
            Flash::set('error', 'Numele și slug-ul template-ului sunt obligatorii.');
            if ($isBuilderSave && $id > 0) {
                header('Location: /admin/products/templates/builder?id=' . $id);
                return;
            }
            header('Location: /admin/products/templates' . ($id > 0 ? '?template=' . $id : ''));
            return;
        }
        if ($isQuickCreate || trim($html) === '') {
            $html = '<div class="product-template-default">{{product_description}}</div>';
        }

        try {
            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE product_templates
                     SET name = :name,
                         slug = :slug,
                         description = :description,
                         html_content = :html_content,
                         css_content = :css_content,
                         js_content = :js_content,
                         is_active = :is_active
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description !== '' ? $description : null,
                    'html_content' => $html,
                    'css_content' => trim($css) !== '' ? $css : null,
                    'js_content' => trim($js) !== '' ? $js : null,
                    'is_active' => $isActive,
                ]);
                Flash::set('success', 'Template-ul a fost actualizat.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO product_templates (name, slug, description, html_content, css_content, js_content, is_active)
                     VALUES (:name, :slug, :description, :html_content, :css_content, :js_content, :is_active)'
                );
                $stmt->execute([
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description !== '' ? $description : null,
                    'html_content' => $html,
                    'css_content' => trim($css) !== '' ? $css : null,
                    'js_content' => trim($js) !== '' ? $js : null,
                    'is_active' => $isActive,
                ]);
                $id = (int) $db->lastInsertId();
                Flash::set('success', 'Template-ul a fost adăugat.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut salva template-ul. Verifică dacă slug-ul există deja.');
            if ($isBuilderSave && $id > 0) {
                header('Location: /admin/products/templates/builder?id=' . $id);
                return;
            }
        }

        if ($isQuickCreate && $id > 0) {
            header('Location: /admin/products/templates/builder?id=' . $id);
            return;
        }
        if ($isBuilderSave && $id > 0) {
            header('Location: /admin/products/templates/builder?id=' . $id);
            return;
        }

        header('Location: /admin/products/templates' . ($id > 0 ? '?template=' . $id : ''));
    }

    public function categories(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $categories = [];
        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $categories = $db->query(
                'SELECT c.id, c.name, c.slug, COUNT(p.id) AS products_count
                 FROM product_categories c
                 LEFT JOIN products p ON p.category_id = c.id AND p.deleted_at IS NULL
                 GROUP BY c.id
                 ORDER BY c.name ASC'
            )->fetchAll();
        }

        View::render('admin/categories', [
            'title' => 'Categorii produse',
            'categories' => $categories,
        ], 'admin/layout');
    }

    public function blogAuthors(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin');
            return;
        }
        $this->ensureOptionalSchema($db);

        $galleryImages = [];
        try {
            $galleryRows = $db->query(
                "SELECT id, title, alt_text, image_url
                 FROM gallery_images
                 WHERE media_type = 'image' OR media_type IS NULL
                 ORDER BY id DESC
                 LIMIT 500"
            )->fetchAll();
            $galleryImages = is_array($galleryRows) ? $galleryRows : [];
        } catch (Throwable) {
            $galleryImages = [];
        }

        $authors = [];
        try {
            $authors = $db->query(
                'SELECT a.id, a.name, a.slug, a.bio, a.avatar_url, a.is_active, COUNT(p.id) AS posts_count
                 FROM blog_authors a
                 LEFT JOIN blog_posts p ON p.author_id = a.id AND p.deleted_at IS NULL
                 GROUP BY a.id
                 ORDER BY a.name ASC, a.id DESC'
            )->fetchAll() ?: [];
        } catch (Throwable) {
            $authors = [];
        }

        $authorParam = trim((string) ($_GET['author'] ?? ''));
        $selectedAuthorId = ctype_digit($authorParam) ? (int) $authorParam : 0;
        $newRequested = $authorParam === 'new';
        $editingAuthor = null;
        if ($selectedAuthorId > 0) {
            $editingAuthor = $this->loadBlogAuthorById($db, $selectedAuthorId);
        }

        View::render('admin/blog-authors', [
            'title' => 'Autori blog',
            'authors' => is_array($authors) ? $authors : [],
            'selectedAuthorId' => $selectedAuthorId,
            'newRequested' => $newRequested,
            'editingAuthor' => $editingAuthor,
            'galleryImages' => $galleryImages,
        ], 'admin/layout');
    }

    public function blogAuthorsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/blog/authors');
            return;
        }
        $this->ensureOptionalSchema($db);

        $action = trim((string) ($_POST['action'] ?? 'save'));
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $db->beginTransaction();
                    $db->prepare('UPDATE blog_posts SET author_id = NULL WHERE author_id = :id')->execute(['id' => $id]);
                    $db->prepare('DELETE FROM blog_authors WHERE id = :id')->execute(['id' => $id]);
                    $db->commit();
                    Flash::set('success', 'Autorul a fost șters.');
                } catch (Throwable) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    Flash::set('error', 'Nu am putut șterge autorul.');
                }
            }
            header('Location: /admin/blog/authors');
            return;
        }

        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = $this->slugify($slugInput !== '' ? $slugInput : $name);
        $bio = trim((string) ($_POST['bio'] ?? ''));
        $avatarUrl = trim((string) ($_POST['avatar_url'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $slug === '') {
            Flash::set('error', 'Numele și slug-ul autorului sunt obligatorii.');
            header('Location: /admin/blog/authors' . ($id > 0 ? '?author=' . $id : '?author=new'));
            return;
        }

        try {
            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE blog_authors
                     SET name = :name, slug = :slug, bio = :bio, avatar_url = :avatar_url, is_active = :is_active
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                    'bio' => $bio !== '' ? $bio : null,
                    'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
                    'is_active' => $isActive,
                ]);
                Flash::set('success', 'Autorul a fost actualizat.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO blog_authors (name, slug, bio, avatar_url, is_active)
                     VALUES (:name, :slug, :bio, :avatar_url, :is_active)'
                );
                $stmt->execute([
                    'name' => $name,
                    'slug' => $slug,
                    'bio' => $bio !== '' ? $bio : null,
                    'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
                    'is_active' => $isActive,
                ]);
                Flash::set('success', 'Autorul a fost adăugat.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut salva autorul. Verifică dacă slug-ul există deja.');
        }

        header('Location: /admin/blog/authors');
    }

    public function blogTemplates(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin');
            return;
        }
        $this->ensureOptionalSchema($db);

        $newRequested = trim((string) ($_GET['new'] ?? '')) === '1';
        View::render('admin/blog-templates', [
            'title' => 'Template blog',
            'templates' => $this->loadBlogTemplates($db),
            'newRequested' => $newRequested,
        ], 'admin/layout');
    }

    public function blogTemplateBuilder(array $params = []): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? ($_GET['id'] ?? 0));
        if ($id <= 0) {
            Flash::set('error', 'Template invalid.');
            header('Location: /admin/blog/templates');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/blog/templates');
            return;
        }
        $this->ensureOptionalSchema($db);

        $template = $this->loadBlogTemplateById($db, $id);
        if (!is_array($template)) {
            Flash::set('error', 'Template-ul nu a fost găsit.');
            header('Location: /admin/blog/templates');
            return;
        }

        View::render('admin/blog-template-editor', [
            'title' => 'Builder template blog',
            'blogTemplate' => $template,
        ], 'admin/layout');
    }

    public function blogTemplateBuilderEntry(): void
    {
        $this->blogTemplateBuilder([]);
    }

    public function blogTemplatesSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/blog/templates');
            return;
        }
        $this->ensureOptionalSchema($db);

        $action = trim((string) ($_POST['action'] ?? 'save'));
        $isBuilderSave = $action === 'save_builder';
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $db->beginTransaction();
                    $db->prepare('UPDATE blog_posts SET template_id = NULL WHERE template_id = :id')->execute(['id' => $id]);
                    $db->prepare('DELETE FROM blog_templates WHERE id = :id')->execute(['id' => $id]);
                    $db->commit();
                    Flash::set('success', 'Template-ul de blog a fost șters.');
                } catch (Throwable) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    Flash::set('error', 'Nu am putut șterge template-ul.');
                }
            }
            header('Location: /admin/blog/templates');
            return;
        }

        $id = (int) ($_POST['id'] ?? 0);
        $isQuickCreate = trim((string) ($_POST['quick_create'] ?? '')) === '1';
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = $this->slugify($slugInput !== '' ? $slugInput : $name);
        $description = trim((string) ($_POST['description'] ?? ''));
        $html = (string) ($_POST['html_content'] ?? '');
        $css = (string) ($_POST['css_content'] ?? '');
        $js = (string) ($_POST['js_content'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $slug === '') {
            Flash::set('error', 'Numele și slug-ul template-ului sunt obligatorii.');
            if ($isBuilderSave && $id > 0) {
                header('Location: /admin/blog/templates/builder?id=' . $id);
                return;
            }
            header('Location: /admin/blog/templates');
            return;
        }
        if ($isQuickCreate || trim($html) === '') {
            $html = $this->blogTemplateDefaultHtml();
        }

        try {
            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE blog_templates
                     SET name = :name, slug = :slug, description = :description,
                         html_content = :html_content, css_content = :css_content, js_content = :js_content,
                         is_active = :is_active
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description !== '' ? $description : null,
                    'html_content' => $html,
                    'css_content' => trim($css) !== '' ? $css : null,
                    'js_content' => trim($js) !== '' ? $js : null,
                    'is_active' => $isActive,
                ]);
                Flash::set('success', 'Template-ul de blog a fost actualizat.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO blog_templates (name, slug, description, html_content, css_content, js_content, is_active)
                     VALUES (:name, :slug, :description, :html_content, :css_content, :js_content, :is_active)'
                );
                $stmt->execute([
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description !== '' ? $description : null,
                    'html_content' => $html,
                    'css_content' => trim($css) !== '' ? $css : null,
                    'js_content' => trim($js) !== '' ? $js : null,
                    'is_active' => $isActive,
                ]);
                $id = (int) $db->lastInsertId();
                Flash::set('success', 'Template-ul de blog a fost adăugat.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut salva template-ul de blog. Verifică dacă slug-ul există deja.');
        }

        if (($isQuickCreate || $isBuilderSave) && $id > 0) {
            header('Location: /admin/blog/templates/builder?id=' . $id);
            return;
        }
        header('Location: /admin/blog/templates');
    }

    public function blogPosts(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin');
            return;
        }
        $this->ensureOptionalSchema($db);
        $galleryImages = [];
        try {
            $galleryRows = $db->query(
                "SELECT id, title, alt_text, image_url
                 FROM gallery_images
                 WHERE media_type = 'image' OR media_type IS NULL
                 ORDER BY id DESC
                 LIMIT 500"
            )->fetchAll();
            $galleryImages = is_array($galleryRows) ? $galleryRows : [];
        } catch (Throwable) {
            $galleryImages = [];
        }

        $status = trim((string) ($_GET['status'] ?? 'all'));
        if (!in_array($status, ['all', 'published', 'draft', 'scheduled'], true)) {
            $status = 'all';
        }
        $search = trim((string) ($_GET['q'] ?? ''));
        $page = $this->normalizeAdminPage((int) ($_GET['page'] ?? 1));
        $perPage = $this->normalizeAdminPerPage((int) ($_GET['per_page'] ?? 25));
        $panel = $this->normalizeAdminListPanel((string) ($_GET['panel'] ?? 'list'));

        $postsPagination = $this->loadBlogPostsAdminPaginated($db, $status, $search, $page, $perPage);
        $posts = is_array($postsPagination['items'] ?? null) ? $postsPagination['items'] : [];
        $page = (int) ($postsPagination['page'] ?? $page);
        $perPage = (int) ($postsPagination['per_page'] ?? $perPage);
        $totalPosts = (int) ($postsPagination['total'] ?? 0);
        $totalPages = (int) ($postsPagination['total_pages'] ?? 1);
        $templates = $this->loadBlogTemplates($db, true);
        $authors = $this->loadBlogAuthors($db, true);
        $postParam = trim((string) ($_GET['post'] ?? ''));
        $selectedPostId = ctype_digit($postParam) ? (int) $postParam : 0;
        $newRequested = $postParam === 'new';
        $editingPost = null;
        $editingPostSeo = $this->defaultSeoSettings();
        if ($selectedPostId > 0) {
            $editingPost = $this->loadBlogPostById($db, $selectedPostId, true);
            if (is_array($editingPost)) {
                $editingPostSeo = $this->loadSeoSettings($db, 'blog_post', (string) ((int) ($editingPost['id'] ?? 0)));
            }
        }

        View::render('admin/blog-posts', [
            'title' => 'Postări blog',
            'status' => $status,
            'posts' => $posts,
            'search' => $search,
            'page' => $page,
            'perPage' => $perPage,
            'totalPosts' => $totalPosts,
            'totalPages' => $totalPages,
            'perPageOptions' => $this->adminPerPageOptions(),
            'panel' => $panel,
            'templates' => $templates,
            'authors' => $authors,
            'selectedPostId' => $selectedPostId,
            'newRequested' => $newRequested,
            'editingPost' => $editingPost,
            'editingPostSeo' => $editingPostSeo,
            'galleryImages' => $galleryImages,
        ], 'admin/layout');
    }

    public function blogPostsTrash(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin');
            return;
        }
        $this->ensureOptionalSchema($db);

        View::render('admin/blog-posts-trash', [
            'title' => 'Coș postări blog',
            'posts' => $this->loadBlogPostsAdmin($db, 'all', true),
        ], 'admin/layout');
    }

    public function blogPostsImport(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/blog/posts');
            return;
        }
        $this->ensureOptionalSchema($db);

        $result = $this->importBlogPostsFromUploadedFile($db, $_FILES['blog_posts_file'] ?? null);
        if (($result['ok'] ?? false) === true) {
            $this->refreshCacheAfterPublicContentChange($db);
        }
        Flash::set($result['ok'] ? 'success' : 'error', (string) ($result['message'] ?? 'Import invalid.'));
        header('Location: /admin/blog/posts?panel=import');
    }

    public function blogPostsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/blog/posts');
            return;
        }
        $this->ensureOptionalSchema($db);

        $action = trim((string) ($_POST['action'] ?? 'save'));
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE blog_posts SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
                $stmt->execute(['id' => $id]);
                $this->refreshCacheAfterPublicContentChange($db);
                Flash::set('success', 'Postarea a fost mutată în coș.');
            }
            header('Location: /admin/blog/posts');
            return;
        }

        if ($action === 'restore') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE blog_posts SET deleted_at = NULL WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $this->refreshCacheAfterPublicContentChange($db);
                Flash::set('success', 'Postarea a fost restaurată.');
            }
            header('Location: /admin/blog/posts?status=all');
            return;
        }

        if ($action === 'force_delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM blog_posts WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $this->saveSeoSettings($db, 'blog_post', (string) $id, []);
                $this->refreshCacheAfterPublicContentChange($db);
                Flash::set('success', 'Postarea a fost ștearsă definitiv.');
            }
            header('Location: /admin/blog/posts?status=all');
            return;
        }

        if ($action === 'duplicate') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                Flash::set('error', 'Postarea selectată nu poate fi duplicată.');
                header('Location: /admin/blog/posts');
                return;
            }

            $source = $this->loadBlogPostById($db, $id, true);
            if (!is_array($source)) {
                Flash::set('error', 'Postarea selectată nu a fost găsită.');
                header('Location: /admin/blog/posts');
                return;
            }

            $baseTitle = trim((string) ($source['title'] ?? 'Postare blog'));
            $copyTitle = $baseTitle !== '' ? ($baseTitle . ' (copie)') : 'Postare blog (copie)';
            $sourceSlug = trim((string) ($source['slug'] ?? ''));
            $slugSeed = $this->slugify(($sourceSlug !== '' ? $sourceSlug : $copyTitle) . '-copy');
            $copySlug = $this->nextAvailableBlogSlug($db, $slugSeed);

            try {
                $stmt = $db->prepare(
                    'INSERT INTO blog_posts (title, slug, excerpt, content, reading_minutes, published_at, is_published, template_id, author_id, featured_image_url)
                     VALUES (:title, :slug, :excerpt, :content, :reading_minutes, NOW(), :is_published, :template_id, :author_id, :featured_image_url)'
                );
                $stmt->execute([
                    'title' => $copyTitle,
                    'slug' => $copySlug,
                    'excerpt' => trim((string) ($source['excerpt'] ?? '')) !== '' ? (string) $source['excerpt'] : null,
                    'content' => (string) ($source['content'] ?? ''),
                    'reading_minutes' => max(1, (int) ($source['reading_minutes'] ?? 1)),
                    'is_published' => 0,
                    'template_id' => ((int) ($source['template_id'] ?? 0)) > 0 ? (int) $source['template_id'] : null,
                    'author_id' => ((int) ($source['author_id'] ?? 0)) > 0 ? (int) $source['author_id'] : null,
                    'featured_image_url' => trim((string) ($source['featured_image_url'] ?? '')) !== '' ? (string) $source['featured_image_url'] : null,
                ]);
                $newId = (int) $db->lastInsertId();
                if ($newId > 0) {
                    $sourceSeo = $this->loadSeoSettings($db, 'blog_post', (string) $id);
                    $this->saveSeoSettings($db, 'blog_post', (string) $newId, $sourceSeo);
                }
                $this->refreshCacheAfterPublicContentChange($db);
                Flash::set('success', 'Postarea a fost duplicată. Editează copia înainte de publicare.');
                header('Location: /admin/blog/posts?post=' . $newId);
                return;
            } catch (Throwable) {
                Flash::set('error', 'Nu am putut duplica postarea.');
                header('Location: /admin/blog/posts');
                return;
            }
        }

        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = $this->slugify($slugInput !== '' ? $slugInput : $title);
        $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');
        $readingMinutes = $this->normalizeBlogReadingMinutes((string) ($_POST['reading_minutes'] ?? ''));
        $timezoneOffsetRaw = trim((string) ($_POST['timezone_offset_minutes'] ?? ''));
        $timezoneOffset = ($timezoneOffsetRaw !== '' && preg_match('/^-?\d+$/', $timezoneOffsetRaw) === 1)
            ? (int) $timezoneOffsetRaw
            : null;
        $publishedDate = $this->normalizeBlogDate((string) ($_POST['published_at'] ?? ''), $timezoneOffset);
        $isPublished = isset($_POST['is_published']) ? 1 : 0;
        $templateId = (int) ($_POST['template_id'] ?? ($_POST['blog_template_id'] ?? 0));
        $authorId = (int) ($_POST['author_id'] ?? 0);
        $featuredImage = trim((string) ($_POST['featured_image_url'] ?? ''));
        $seoTitle = trim((string) ($_POST['seo_title'] ?? ''));
        $seoDescription = trim((string) ($_POST['seo_description'] ?? ''));
        $seoCanonicalUrl = trim((string) ($_POST['seo_canonical_url'] ?? ''));
        $seoImageUrl = trim((string) ($_POST['seo_image_url'] ?? ''));
        // O postare poate fi în mai multe categorii. Prima validă rămâne „principală"
        // (category_id + numele denormalizat), restul se salvează în blog_post_categories.
        $rawCategoryIds = [];
        if (isset($_POST['category_ids']) && is_array($_POST['category_ids'])) {
            foreach ($_POST['category_ids'] as $cidRaw) {
                $cid = (int) $cidRaw;
                if ($cid > 0) {
                    $rawCategoryIds[] = $cid;
                }
            }
        }
        $singleCategoryId = (int) ($_POST['category_id'] ?? 0);
        if ($singleCategoryId > 0) {
            array_unshift($rawCategoryIds, $singleCategoryId);
        }
        $rawCategoryIds = array_values(array_unique($rawCategoryIds));

        $category = trim((string) ($_POST['category'] ?? ''));
        $categoryIds = [];
        if ($rawCategoryIds !== []) {
            $place = implode(',', array_fill(0, count($rawCategoryIds), '?'));
            $vstmt = $db->prepare("SELECT id, name FROM blog_categories WHERE id IN ($place)");
            $vstmt->execute($rawCategoryIds);
            $validNames = [];
            foreach ($vstmt->fetchAll() as $vr) {
                $validNames[(int) ($vr['id'] ?? 0)] = (string) ($vr['name'] ?? '');
            }
            foreach ($rawCategoryIds as $cid) {
                if (isset($validNames[$cid])) {
                    $categoryIds[] = $cid;
                }
            }
            if ($categoryIds !== [] && $validNames[$categoryIds[0]] !== '') {
                $category = $validNames[$categoryIds[0]];
            }
        }
        $categoryId = $categoryIds[0] ?? 0;
        $eventStartDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) ($_POST['event_start_date'] ?? ''))) === 1
            ? trim((string) $_POST['event_start_date']) : null;
        $eventEndDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) ($_POST['event_end_date'] ?? ''))) === 1
            ? trim((string) $_POST['event_end_date']) : null;
        $eventPrice = trim((string) ($_POST['event_price'] ?? ''));
        $eventTicketUrl = trim((string) ($_POST['event_ticket_url'] ?? ''));
        $eventLocation = trim((string) ($_POST['event_location'] ?? ''));
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));

        if ($title === '' || $slug === '' || trim($content) === '') {
            Flash::set('error', 'Titlul, slug-ul și conținutul sunt obligatorii.');
            $errorReturn = $id > 0
                ? '/admin/blog/posts/editor?post=' . $id
                : '/admin/blog/posts/editor?template_id=' . $templateId . '&category_id=' . $categoryId;
            header('Location: ' . $errorReturn);
            return;
        }

        if ($templateId > 0 && !is_array($this->loadBlogTemplateById($db, $templateId))) {
            $templateId = 0;
        }
        if ($authorId > 0 && !is_array($this->loadBlogAuthorById($db, $authorId))) {
            $authorId = 0;
        }

        $savedPost = false;
        try {
            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE blog_posts
                     SET title = :title, slug = :slug, excerpt = :excerpt, content = :content,
                         reading_minutes = :reading_minutes, published_at = :published_at,
                         is_published = :is_published, template_id = :template_id, author_id = :author_id,
                         featured_image_url = :featured_image_url, category = :category, category_id = :category_id,
                         event_start_date = :event_start_date, event_end_date = :event_end_date,
                         event_price = :event_price, event_ticket_url = :event_ticket_url,
                         event_location = :event_location, video_url = :video_url
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $id,
                    'title' => $title,
                    'slug' => $slug,
                    'excerpt' => $excerpt !== '' ? $excerpt : null,
                    'content' => $content,
                    'reading_minutes' => $readingMinutes,
                    'published_at' => $publishedDate,
                    'is_published' => $isPublished,
                    'template_id' => $templateId > 0 ? $templateId : null,
                    'author_id' => $authorId > 0 ? $authorId : null,
                    'featured_image_url' => $featuredImage !== '' ? $featuredImage : null,
                    'category' => $category !== '' ? $category : null,
                    'category_id' => $categoryId > 0 ? $categoryId : null,
                    'event_start_date' => $eventStartDate,
                    'event_end_date' => $eventEndDate,
                    'event_price' => $eventPrice !== '' ? $eventPrice : null,
                    'event_ticket_url' => $eventTicketUrl !== '' ? $eventTicketUrl : null,
                    'event_location' => $eventLocation !== '' ? $eventLocation : null,
                    'video_url' => $videoUrl !== '' ? $videoUrl : null,
                ]);
                $savedPost = true;
                Flash::set('success', 'Postarea de blog a fost actualizată.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO blog_posts (title, slug, excerpt, content, reading_minutes, published_at, is_published, template_id, author_id, featured_image_url, category, category_id, event_start_date, event_end_date, event_price, event_ticket_url, event_location, video_url)
                     VALUES (:title, :slug, :excerpt, :content, :reading_minutes, :published_at, :is_published, :template_id, :author_id, :featured_image_url, :category, :category_id, :event_start_date, :event_end_date, :event_price, :event_ticket_url, :event_location, :video_url)'
                );
                $stmt->execute([
                    'title' => $title,
                    'slug' => $slug,
                    'excerpt' => $excerpt !== '' ? $excerpt : null,
                    'content' => $content,
                    'reading_minutes' => $readingMinutes,
                    'published_at' => $publishedDate,
                    'is_published' => $isPublished,
                    'template_id' => $templateId > 0 ? $templateId : null,
                    'author_id' => $authorId > 0 ? $authorId : null,
                    'featured_image_url' => $featuredImage !== '' ? $featuredImage : null,
                    'category' => $category !== '' ? $category : null,
                    'category_id' => $categoryId > 0 ? $categoryId : null,
                    'event_start_date' => $eventStartDate,
                    'event_end_date' => $eventEndDate,
                    'event_price' => $eventPrice !== '' ? $eventPrice : null,
                    'event_ticket_url' => $eventTicketUrl !== '' ? $eventTicketUrl : null,
                    'event_location' => $eventLocation !== '' ? $eventLocation : null,
                    'video_url' => $videoUrl !== '' ? $videoUrl : null,
                ]);
                $id = (int) $db->lastInsertId();
                $savedPost = true;
                Flash::set('success', 'Postarea de blog a fost adăugată.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut salva postarea. Verifică dacă slug-ul există deja.');
        }
        if ($id > 0) {
            $this->saveSeoSettings($db, 'blog_post', (string) $id, [
                'title' => $seoTitle,
                'description' => $seoDescription,
                'canonical_url' => $seoCanonicalUrl,
                'image_url' => $seoImageUrl,
            ]);
        }
        if ($savedPost && $id > 0) {
            // Sincronizează apartenența la categorii (many-to-many).
            try {
                $delCats = $db->prepare('DELETE FROM blog_post_categories WHERE post_id = :pid');
                $delCats->execute(['pid' => $id]);
                if ($categoryIds !== []) {
                    $insCat = $db->prepare('INSERT IGNORE INTO blog_post_categories (post_id, category_id) VALUES (:pid, :cid)');
                    foreach ($categoryIds as $cid) {
                        $insCat->execute(['pid' => $id, 'cid' => $cid]);
                    }
                }
            } catch (Throwable) {
            }
        }
        if ($savedPost) {
            $this->refreshCacheAfterPublicContentChange($db);
        }

        if ($id > 0) {
            header('Location: /admin/blog/posts/editor?post=' . $id . '&saved=1');
            return;
        }
        header('Location: /admin/blog/posts');
    }

    private function loadBlogTemplatesList(PDO $db): array
    {
        try {
            $rows = $db->query('SELECT id, name, slug, html_content FROM blog_templates WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function loadBlogCategoriesList(PDO $db): array
    {
        try {
            $rows = $db->query('SELECT id, name, slug FROM blog_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function loadBlogAuthorsOptions(PDO $db): array
    {
        try {
            $rows = $db->query('SELECT id, name FROM blog_authors ORDER BY name ASC')->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function blogCategories(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $categories = [];
        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            try {
                $categories = $db->query(
                    'SELECT c.id, c.name, c.slug, c.sort_order, c.is_active,
                            (SELECT COUNT(DISTINCT p.id) FROM blog_posts p
                             LEFT JOIN blog_post_categories pc ON pc.post_id = p.id
                             WHERE (p.category_id = c.id OR pc.category_id = c.id) AND p.deleted_at IS NULL) AS posts_count
                     FROM blog_categories c
                     ORDER BY c.sort_order ASC, c.name ASC'
                )->fetchAll();
            } catch (Throwable) {
                $categories = [];
            }
        }
        View::render('admin/blog-categories', [
            'title' => 'Categorii blog',
            'categories' => is_array($categories) ? $categories : [],
        ], 'admin/layout');
    }

    public function blogCategoryCreate(): void
    {
        if (!$this->guard()) {
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = $slugInput !== '' ? $this->slugify($slugInput) : $this->slugify($name);
        if ($name === '' || $slug === '') {
            Flash::set('error', 'Numele categoriei este obligatoriu.');
            header('Location: /admin/blog/categories');
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/blog/categories');
            return;
        }
        $this->ensureOptionalSchema($db);
        try {
            $stmt = $db->prepare('INSERT INTO blog_categories (name, slug, sort_order) VALUES (:name, :slug, :sort_order)');
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            ]);
            Flash::set('success', 'Categoria a fost adăugată.');
        } catch (Throwable) {
            Flash::set('error', 'Categoria există deja.');
        }
        header('Location: /admin/blog/categories');
    }

    public function blogCategoryUpdate(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Flash::set('error', 'Categorie invalidă.');
            header('Location: /admin/blog/categories');
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = $slugInput !== '' ? $this->slugify($slugInput) : $this->slugify($name);
        if ($name === '' || $slug === '') {
            Flash::set('error', 'Numele categoriei este obligatoriu.');
            header('Location: /admin/blog/categories');
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/blog/categories');
            return;
        }
        $this->ensureOptionalSchema($db);
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE blog_categories SET name = :name, slug = :slug, sort_order = :sort_order WHERE id = :id');
            $stmt->execute([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            ]);
            // Pastram numele denormalizat pe postari pentru token-ul {{blog_category}}.
            $stmtPosts = $db->prepare('UPDATE blog_posts SET category = :category WHERE category_id = :id');
            $stmtPosts->execute(['id' => $id, 'category' => $name]);
            $db->commit();
            Flash::set('success', 'Categoria a fost actualizată.');
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Flash::set('error', 'Nu am putut actualiza categoria. Verifică dacă slug-ul există deja.');
        }
        header('Location: /admin/blog/categories');
    }

    public function blogCategoryDelete(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Flash::set('error', 'Categorie invalidă.');
            header('Location: /admin/blog/categories');
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/blog/categories');
            return;
        }
        $this->ensureOptionalSchema($db);
        try {
            $db->beginTransaction();
            $stmtPosts = $db->prepare('UPDATE blog_posts SET category_id = NULL WHERE category_id = :id');
            $stmtPosts->execute(['id' => $id]);
            try {
                $stmtJunction = $db->prepare('DELETE FROM blog_post_categories WHERE category_id = :id');
                $stmtJunction->execute(['id' => $id]);
            } catch (Throwable) {
            }
            $stmtDelete = $db->prepare('DELETE FROM blog_categories WHERE id = :id');
            $stmtDelete->execute(['id' => $id]);
            $db->commit();
            Flash::set('success', 'Categoria a fost ștearsă.');
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Flash::set('error', 'Categoria nu a putut fi ștearsă.');
        }
        header('Location: /admin/blog/categories');
    }

    public function blogPostNew(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/blog/posts');
            return;
        }
        $this->ensureOptionalSchema($db);
        View::render('admin/blog-post-step1', [
            'title' => 'Postare nouă',
            'templates' => $this->loadBlogTemplatesList($db),
            'categories' => $this->loadBlogCategoriesList($db),
        ], 'admin/layout');
    }

    public function blogPostEditor(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/blog/posts');
            return;
        }
        $this->ensureOptionalSchema($db);

        $postId = (int) ($_GET['post'] ?? 0);
        $editingPost = $postId > 0 ? $this->loadBlogPostById($db, $postId, true) : null;
        $editingPostSeo = $this->defaultSeoSettings();
        if (is_array($editingPost)) {
            $editingPostSeo = $this->loadSeoSettings($db, 'blog_post', (string) ((int) ($editingPost['id'] ?? 0)));
        }

        $templates = $this->loadBlogTemplatesList($db);
        $categories = $this->loadBlogCategoriesList($db);
        $authors = $this->loadBlogAuthorsOptions($db);

        $galleryImages = [];
        try {
            $galleryRows = $db->query(
                "SELECT id, title, alt_text, image_url
                 FROM gallery_images
                 WHERE media_type = 'image' OR media_type IS NULL
                 ORDER BY id DESC
                 LIMIT 500"
            )->fetchAll();
            $galleryImages = is_array($galleryRows) ? $galleryRows : [];
        } catch (Throwable) {
            $galleryImages = [];
        }

        $selectedTemplateId = is_array($editingPost)
            ? (int) ($editingPost['template_id'] ?? 0)
            : (int) ($_GET['template_id'] ?? 0);
        $selectedCategoryId = is_array($editingPost)
            ? (int) ($editingPost['category_id'] ?? 0)
            : (int) ($_GET['category_id'] ?? 0);
        // Toate categoriile selectate (many-to-many).
        $selectedCategoryIds = [];
        if (is_array($editingPost) && is_array($editingPost['category_ids'] ?? null)) {
            foreach ($editingPost['category_ids'] as $cid) {
                $cid = (int) $cid;
                if ($cid > 0) {
                    $selectedCategoryIds[] = $cid;
                }
            }
        }
        if ($selectedCategoryIds === []) {
            // creare nouă: poate veni mai multe din Pasul 1 (category_ids[]) sau una singură
            if (isset($_GET['category_ids']) && is_array($_GET['category_ids'])) {
                foreach ($_GET['category_ids'] as $cid) {
                    $cid = (int) $cid;
                    if ($cid > 0) {
                        $selectedCategoryIds[] = $cid;
                    }
                }
            }
            if ($selectedCategoryIds === [] && $selectedCategoryId > 0) {
                $selectedCategoryIds[] = $selectedCategoryId;
            }
        }

        // Map templateId => chei de camp vizibile (0 = template implicit).
        $fieldMap = ['0' => \App\Support\BlogTemplateFields::fieldKeysForHtml($this->blogTemplateDefaultHtml())];
        foreach ($templates as $tpl) {
            $tid = (int) ($tpl['id'] ?? 0);
            $fieldMap[(string) $tid] = \App\Support\BlogTemplateFields::fieldKeysForHtml((string) ($tpl['html_content'] ?? ''));
        }

        View::render('admin/blog-post-editor', [
            'title' => is_array($editingPost) ? 'Editează postare' : 'Postare nouă',
            'editingPost' => $editingPost,
            'editingPostSeo' => $editingPostSeo,
            'templates' => $templates,
            'categories' => $categories,
            'authors' => $authors,
            'galleryImages' => $galleryImages,
            'selectedTemplateId' => $selectedTemplateId,
            'selectedCategoryId' => $selectedCategoryId,
            'selectedCategoryIds' => $selectedCategoryIds,
            'fieldCatalog' => \App\Support\BlogTemplateFields::catalog(),
            'fieldMap' => $fieldMap,
        ], 'admin/layout');
    }

    public function productReviews(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products');
            return;
        }
        $this->ensureOptionalSchema($db);

        $status = trim((string) ($_GET['status'] ?? 'pending'));
        if (!in_array($status, ['pending', 'approved', 'all'], true)) {
            $status = 'pending';
        }
        $search = trim((string) ($_GET['q'] ?? ''));
        $page = $this->normalizeAdminPage((int) ($_GET['page'] ?? 1));
        $perPage = $this->normalizeAdminPerPage((int) ($_GET['per_page'] ?? 25));
        $panel = $this->normalizeAdminListPanel((string) ($_GET['panel'] ?? 'list'));

        $where = ['1=1'];
        $params = [];
        if ($status === 'pending') {
            $where[] = 'r.is_approved = 0';
        } elseif ($status === 'approved') {
            $where[] = 'r.is_approved = 1';
        }
        if ($search !== '') {
            $where[] = '(p.name LIKE :search OR r.user_name LIKE :search OR r.user_email LIKE :search OR r.review_text LIKE :search OR COALESCE(r.source, "") LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $reviews = [];
        $total = 0;
        $totalPages = 1;
        try {
            $countStmt = $db->prepare(
                'SELECT COUNT(*)
                 FROM product_reviews r
                 LEFT JOIN products p ON p.id = r.product_id
                 WHERE ' . implode(' AND ', $where)
            );
            $countStmt->execute($params);
            $total = (int) ($countStmt->fetchColumn() ?: 0);
            $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            $stmt = $db->prepare(
                'SELECT r.id, r.product_id, r.user_name, r.user_email, r.rating, r.review_text, r.is_approved, r.source, r.created_at,
                        p.name AS product_name, p.slug AS product_slug
                 FROM product_reviews r
                 LEFT JOIN products p ON p.id = r.product_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY r.created_at DESC, r.id DESC
                 LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset)
            );
            $stmt->execute($params);
            $reviews = $stmt->fetchAll();
        } catch (Throwable) {
            $legacyWhere = ['1=1'];
            $legacyParams = [];
            if ($status === 'pending') {
                $legacyWhere[] = 'r.is_approved = 0';
            } elseif ($status === 'approved') {
                $legacyWhere[] = 'r.is_approved = 1';
            }
            if ($search !== '') {
                $legacyWhere[] = '(p.name LIKE :search OR r.user_name LIKE :search OR r.user_email LIKE :search OR r.review_text LIKE :search)';
                $legacyParams['search'] = '%' . $search . '%';
            }
            $countStmt = $db->prepare(
                'SELECT COUNT(*)
                 FROM product_reviews r
                 LEFT JOIN products p ON p.id = r.product_id
                 WHERE ' . implode(' AND ', $legacyWhere)
            );
            $countStmt->execute($legacyParams);
            $total = (int) ($countStmt->fetchColumn() ?: 0);
            $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;
            $stmt = $db->prepare(
                'SELECT r.id, r.product_id, r.user_name, r.user_email, r.rating, r.review_text, r.is_approved, r.created_at,
                        p.name AS product_name, p.slug AS product_slug
                 FROM product_reviews r
                 LEFT JOIN products p ON p.id = r.product_id
                 WHERE ' . implode(' AND ', $legacyWhere) . '
                 ORDER BY r.created_at DESC, r.id DESC
                 LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset)
            );
            $stmt->execute($legacyParams);
            $rows = $stmt->fetchAll();
            $reviews = [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $row['source'] = '';
                    $reviews[] = $row;
                }
            }
        }
        if (!is_array($reviews)) {
            $reviews = [];
        }

        $counts = [
            'pending' => 0,
            'approved' => 0,
            'all' => 0,
        ];
        try {
            $countRows = $db->query(
                'SELECT
                    SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) AS approved_count,
                    COUNT(*) AS total_count
                 FROM product_reviews'
            )->fetch();
            if (is_array($countRows)) {
                $counts['pending'] = (int) ($countRows['pending_count'] ?? 0);
                $counts['approved'] = (int) ($countRows['approved_count'] ?? 0);
                $counts['all'] = (int) ($countRows['total_count'] ?? 0);
            }
        } catch (Throwable) {
        }

        View::render('admin/product-reviews', [
            'title' => 'Recenzii produse',
            'reviews' => $reviews,
            'status' => $status,
            'search' => $search,
            'page' => $page,
            'perPage' => $perPage,
            'totalReviews' => $total,
            'totalPages' => $totalPages,
            'perPageOptions' => $this->adminPerPageOptions(),
            'panel' => $panel,
            'counts' => $counts,
        ], 'admin/layout');
    }

    public function productReviewsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            Flash::set('error', 'Recenzie invalidă.');
            header('Location: /admin/products/reviews');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products/reviews?status=all&panel=import');
            return;
        }
        $this->ensureOptionalSchema($db);
        $settings = Settings::all($db);

        $action = trim((string) ($_POST['action'] ?? ''));
        $redirectStatus = trim((string) ($_POST['status'] ?? 'pending'));
        $redirectQuery = trim((string) ($_POST['q'] ?? ''));
        $redirectPage = $this->normalizeAdminPage((int) ($_POST['page'] ?? 1));
        $redirectPerPage = $this->normalizeAdminPerPage((int) ($_POST['per_page'] ?? 25));
        $redirectPanel = $this->normalizeAdminListPanel((string) ($_POST['panel'] ?? 'list'));
        if (!in_array($redirectStatus, ['pending', 'approved', 'all'], true)) {
            $redirectStatus = 'pending';
        }
        $backUrl = '/admin/products/reviews?status=' . rawurlencode($redirectStatus)
            . '&page=' . $redirectPage
            . '&per_page=' . $redirectPerPage
            . '&panel=' . rawurlencode($redirectPanel);
        if ($redirectQuery !== '') {
            $backUrl .= '&q=' . rawurlencode($redirectQuery);
        }

        try {
            if ($action === 'approve') {
                $stmt = $db->prepare('UPDATE product_reviews SET is_approved = 1 WHERE id = :id');
                $stmt->execute(['id' => $id]);
                Flash::set('success', 'Recenzia a fost aprobată.');
            } elseif ($action === 'pending') {
                $stmt = $db->prepare('UPDATE product_reviews SET is_approved = 0 WHERE id = :id');
                $stmt->execute(['id' => $id]);
                Flash::set('success', 'Recenzia a fost trecută în pending.');
            } elseif ($action === 'delete') {
                $stmt = $db->prepare('DELETE FROM product_reviews WHERE id = :id');
                $stmt->execute(['id' => $id]);
                Flash::set('success', 'Recenzia a fost ștearsă.');
            } else {
                Flash::set('error', 'Acțiune invalidă pentru recenzie.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut actualiza recenzia.');
        }

        header('Location: ' . $backUrl);
    }

    public function productReviewsImport(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products/reviews');
            return;
        }
        $this->ensureOptionalSchema($db);

        $result = $this->importProductReviewsFromUploadedFile($db, $_FILES['product_reviews_file'] ?? null);
        Flash::set($result['ok'] ? 'success' : 'error', (string) ($result['message'] ?? 'Import invalid.'));
        header('Location: /admin/products/reviews?status=all&panel=import');
    }

    public function gdprAgreements(): void
    {
        if (!$this->guard()) {
            return;
        }
        $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        if ($requestPath === '/admin/pages/gdpr-agreements') {
            $queryString = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? '');
            header('Location: /admin/users/gdpr-agreements' . ($queryString !== '' ? ('?' . $queryString) : ''));
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users');
            return;
        }
        $this->ensureOptionalSchema($db);

        $search = trim((string) ($_GET['q'] ?? ''));
        $page = $this->normalizeAdminPage((int) ($_GET['page'] ?? 1));
        $perPage = $this->normalizeAdminPerPage((int) ($_GET['per_page'] ?? 25));

        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(
                subiect_nume_complet LIKE :search
                OR nume LIKE :search
                OR prenume LIKE :search
                OR email LIKE :search
                OR telefon LIKE :search
                OR cnp LIKE :search
                OR cuim LIKE :search
                OR institutie_medicala LIKE :search
                OR specializare LIKE :search
            )';
            $params['search'] = '%' . $search . '%';
        }

        $countStmt = $db->prepare(
            'SELECT COUNT(*)
             FROM gdpr_agreements
             WHERE ' . implode(' AND ', $where)
        );
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);
        $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare(
            'SELECT id, subiect_nume_complet, ci_serie, ci_numar, ci_emitent, ci_data_eliberare,
                    nume, prenume, cnp, cuim, telefon, email, adresa_corespondenta,
                    institutie_medicala, institutie_activitate, institutie_adresa, institutie_activitate_adresa,
                    tip_medic, specializare, data_semnare, nume_semnatura, signature_data_url, created_at
             FROM gdpr_agreements
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        if (!is_array($items)) {
            $items = [];
        }

        View::render('admin/gdpr-agreements', [
            'title' => 'Acorduri GDPR',
            'items' => $items,
            'search' => $search,
            'page' => $page,
            'perPage' => $perPage,
            'perPageOptions' => $this->adminPerPageOptions(),
            'totalItems' => $total,
            'totalPages' => $totalPages,
        ], 'admin/layout');
    }

    public function gdprAgreementsExport(): void
    {
        if (!$this->guard()) {
            return;
        }
        $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        if ($requestPath === '/admin/pages/gdpr-agreements/export') {
            $queryString = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? '');
            header('Location: /admin/users/gdpr-agreements/export' . ($queryString !== '' ? ('?' . $queryString) : ''));
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/users/gdpr-agreements');
            return;
        }
        $this->ensureOptionalSchema($db);

        $search = trim((string) ($_GET['q'] ?? ''));
        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(
                subiect_nume_complet LIKE :search
                OR nume LIKE :search
                OR prenume LIKE :search
                OR email LIKE :search
                OR telefon LIKE :search
                OR cnp LIKE :search
                OR cuim LIKE :search
                OR institutie_medicala LIKE :search
                OR specializare LIKE :search
            )';
            $params['search'] = '%' . $search . '%';
        }

        $stmt = $db->prepare(
            'SELECT id, subiect_nume_complet, ci_serie, ci_numar, ci_emitent, ci_data_eliberare,
                    nume, prenume, cnp, cuim, telefon, email, adresa_corespondenta,
                    institutie_medicala, institutie_activitate, institutie_adresa, institutie_activitate_adresa,
                    tip_medic, specializare, data_semnare, nume_semnatura, signature_data_url,
                    source_url, ip_address, user_agent, created_at
             FROM gdpr_agreements
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            $rows = [];
        }

        $binary = $this->buildGdprAgreementsXlsxBinary($rows);
        if (!is_string($binary) || $binary === '') {
            Flash::set('error', 'Nu am putut genera exportul Excel cu semnături.');
            header('Location: /admin/users/gdpr-agreements');
            return;
        }

        $filename = 'acorduri-gdpr-' . date('Y-m-d-His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($filename), false);
        header('Content-Length: ' . (string) strlen($binary));
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Expires: 0');
        header('Pragma: no-cache');

        echo $binary;
    }

    private function buildGdprAgreementsXlsxBinary(array $rows): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }

        $columns = $this->gdprAgreementsExportColumns();
        $signatureColumnIndex = 1;
        foreach ($columns as $index => $column) {
            if (($column['key'] ?? '') === '__signature_image') {
                $signatureColumnIndex = $index + 1;
                break;
            }
        }

        $sheetRows = [];
        $rowHeights = [];
        $drawings = [];
        $mediaFiles = [];
        $imageMimes = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sheetRowValues = [];
            foreach ($columns as $column) {
                $key = (string) ($column['key'] ?? '');
                if ($key === '__signature_image') {
                    $sheetRowValues[] = '';
                    continue;
                }
                $sheetRowValues[] = (string) ($row[$key] ?? '');
            }
            $sheetRows[] = $sheetRowValues;

            $signatureImage = $this->gdprParseSignatureImageDataUrl((string) ($row['signature_data_url'] ?? ''));
            if ($signatureImage === null) {
                continue;
            }

            $imageNumber = count($mediaFiles) + 1;
            $mediaPath = 'xl/media/gdpr-signature-' . $imageNumber . '.' . $signatureImage['extension'];
            $mediaFiles[] = [
                'path' => $mediaPath,
                'binary' => $signatureImage['binary'],
            ];
            $imageMimes[$signatureImage['extension']] = $signatureImage['mime'];

            $sheetRowNumber = count($sheetRows) + 1; // +1 for header row
            $rowHeights[$sheetRowNumber] = max((float) ($rowHeights[$sheetRowNumber] ?? 0.0), 68.0);

            $drawings[] = [
                'relationship_id' => 'rId' . $imageNumber,
                'target' => '../media/gdpr-signature-' . $imageNumber . '.' . $signatureImage['extension'],
                'name' => 'Semnatura ' . $imageNumber,
                'col_zero' => $signatureColumnIndex - 1,
                'row_zero' => $sheetRowNumber - 1,
                'cx' => $signatureImage['width_px'] * 9525,
                'cy' => $signatureImage['height_px'] * 9525,
            ];
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'gdpr-xlsx-');
        if (!is_string($tempPath) || $tempPath === '') {
            return null;
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($tempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            @unlink($tempPath);
            return null;
        }

        $hasDrawings = $drawings !== [];
        $ok = true;
        $ok = $ok && $zip->addFromString('[Content_Types].xml', $this->gdprBuildExcelContentTypesXml($imageMimes, $hasDrawings));
        $ok = $ok && $zip->addFromString('_rels/.rels', $this->gdprBuildExcelRootRelsXml());
        $ok = $ok && $zip->addFromString('xl/workbook.xml', $this->gdprBuildExcelWorkbookXml());
        $ok = $ok && $zip->addFromString('xl/_rels/workbook.xml.rels', $this->gdprBuildExcelWorkbookRelsXml());
        $ok = $ok && $zip->addFromString('xl/styles.xml', $this->gdprBuildExcelStylesXml());
        $ok = $ok && $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            $this->gdprBuildExcelSheetXml($columns, $sheetRows, $rowHeights, $hasDrawings)
        );

        if ($hasDrawings) {
            $ok = $ok && $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $this->gdprBuildExcelSheetRelsXml());
            $ok = $ok && $zip->addFromString('xl/drawings/drawing1.xml', $this->gdprBuildExcelDrawingXml($drawings));
            $ok = $ok && $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $this->gdprBuildExcelDrawingRelsXml($drawings));
            foreach ($mediaFiles as $mediaFile) {
                $ok = $ok && $zip->addFromString((string) ($mediaFile['path'] ?? ''), (string) ($mediaFile['binary'] ?? ''));
            }
        }

        $closed = $zip->close();
        if (!$ok || $closed !== true) {
            @unlink($tempPath);
            return null;
        }

        $binary = file_get_contents($tempPath);
        @unlink($tempPath);
        return is_string($binary) && $binary !== '' ? $binary : null;
    }

    /**
     * @return list<array{key:string,label:string,width:float}>
     */
    private function gdprAgreementsExportColumns(): array
    {
        return [
            ['key' => 'id', 'label' => 'ID', 'width' => 8.0],
            ['key' => 'subiect_nume_complet', 'label' => 'Subsemnatul', 'width' => 28.0],
            ['key' => 'ci_serie', 'label' => 'CI seria', 'width' => 10.0],
            ['key' => 'ci_numar', 'label' => 'CI numar', 'width' => 12.0],
            ['key' => 'ci_emitent', 'label' => 'CI emitent', 'width' => 22.0],
            ['key' => 'ci_data_eliberare', 'label' => 'CI data eliberare', 'width' => 15.0],
            ['key' => 'nume', 'label' => 'Nume', 'width' => 18.0],
            ['key' => 'prenume', 'label' => 'Prenume', 'width' => 18.0],
            ['key' => 'cnp', 'label' => 'CNP', 'width' => 18.0],
            ['key' => 'cuim', 'label' => 'CUIM', 'width' => 16.0],
            ['key' => 'telefon', 'label' => 'Telefon', 'width' => 16.0],
            ['key' => 'email', 'label' => 'Email', 'width' => 24.0],
            ['key' => 'adresa_corespondenta', 'label' => 'Adresa corespondenta', 'width' => 26.0],
            ['key' => 'institutie_medicala', 'label' => 'Denumire institutie medicala', 'width' => 26.0],
            ['key' => 'institutie_activitate', 'label' => 'Desfasurare activitate (institutie)', 'width' => 27.0],
            ['key' => 'institutie_adresa', 'label' => 'Adresa institutie medicala', 'width' => 28.0],
            ['key' => 'institutie_activitate_adresa', 'label' => 'Desfasurare activitate (adresa)', 'width' => 27.0],
            ['key' => 'tip_medic', 'label' => 'Tip medic', 'width' => 16.0],
            ['key' => 'specializare', 'label' => 'Specializare', 'width' => 18.0],
            ['key' => 'data_semnare', 'label' => 'Data', 'width' => 14.0],
            ['key' => 'nume_semnatura', 'label' => 'Nume semnatura', 'width' => 24.0],
            ['key' => '__signature_image', 'label' => 'Semnatura (imagine)', 'width' => 34.0],
            ['key' => 'source_url', 'label' => 'URL sursa', 'width' => 32.0],
            ['key' => 'ip_address', 'label' => 'IP', 'width' => 15.0],
            ['key' => 'user_agent', 'label' => 'User agent', 'width' => 30.0],
            ['key' => 'created_at', 'label' => 'Creat la', 'width' => 20.0],
        ];
    }

    /**
     * @return array{extension:string,mime:string,binary:string,width_px:int,height_px:int}|null
     */
    private function gdprParseSignatureImageDataUrl(string $signatureDataUrl): ?array
    {
        $signatureDataUrl = trim($signatureDataUrl);
        if ($signatureDataUrl === '') {
            return null;
        }

        if (!preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $signatureDataUrl, $matches)) {
            return null;
        }

        $extension = strtolower((string) ($matches[1] ?? ''));
        if ($extension === 'jpg') {
            $extension = 'jpeg';
        }
        $mimeByExt = [
            'png' => 'image/png',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
        ];
        $mime = $mimeByExt[$extension] ?? null;
        if (!is_string($mime)) {
            return null;
        }

        $payload = preg_replace('/\s+/', '', (string) ($matches[2] ?? ''));
        $binary = base64_decode((string) $payload, true);
        if (!is_string($binary) || $binary === '') {
            return null;
        }

        $size = @getimagesizefromstring($binary);
        if (!is_array($size) || count($size) < 2) {
            return null;
        }

        $width = max(1, (int) ($size[0] ?? 1));
        $height = max(1, (int) ($size[1] ?? 1));
        $maxWidth = 220.0;
        $maxHeight = 80.0;
        $scale = min(1.0, min($maxWidth / $width, $maxHeight / $height));

        return [
            'extension' => $extension,
            'mime' => $mime,
            'binary' => $binary,
            'width_px' => max(1, (int) round($width * $scale)),
            'height_px' => max(1, (int) round($height * $scale)),
        ];
    }

    /**
     * @param list<array{key:string,label:string,width:float}> $columns
     * @param list<array<int,string>> $rows
     * @param array<int,float> $rowHeights
     */
    private function gdprBuildExcelSheetXml(array $columns, array $rows, array $rowHeights, bool $hasDrawings): string
    {
        $lastRow = max(1, count($rows) + 1);
        $lastColumn = $this->gdprExcelColumnName(max(1, count($columns)));

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml[] = '  <dimension ref="A1:' . $lastColumn . $lastRow . '"/>';
        $xml[] = '  <sheetViews><sheetView workbookViewId="0"/></sheetViews>';
        $xml[] = '  <sheetFormatPr defaultRowHeight="16"/>';
        $xml[] = '  <cols>';
        foreach ($columns as $index => $column) {
            $col = $index + 1;
            $width = number_format((float) ($column['width'] ?? 16.0), 2, '.', '');
            $xml[] = '    <col min="' . $col . '" max="' . $col . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml[] = '  </cols>';
        $xml[] = '  <sheetData>';

        $xml[] = '    <row r="1" ht="24" customHeight="1">';
        foreach ($columns as $index => $column) {
            $xml[] = $this->gdprExcelInlineStringCell($index + 1, 1, (string) ($column['label'] ?? ''));
        }
        $xml[] = '    </row>';

        foreach ($rows as $rowIndex => $values) {
            $sheetRow = $rowIndex + 2;
            $height = (float) ($rowHeights[$sheetRow] ?? 0.0);
            if ($height > 0.0) {
                $xml[] = '    <row r="' . $sheetRow . '" ht="' . number_format($height, 2, '.', '') . '" customHeight="1">';
            } else {
                $xml[] = '    <row r="' . $sheetRow . '">';
            }
            foreach ($columns as $colIndex => $column) {
                $value = (string) ($values[$colIndex] ?? '');
                $xml[] = $this->gdprExcelInlineStringCell($colIndex + 1, $sheetRow, $value);
            }
            $xml[] = '    </row>';
        }

        $xml[] = '  </sheetData>';
        if ($hasDrawings) {
            $xml[] = '  <drawing r:id="rId1"/>';
        }
        $xml[] = '</worksheet>';

        return implode("\n", $xml);
    }

    private function gdprBuildExcelWorkbookXml(): string
    {
        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',
            '  <sheets>',
            '    <sheet name="Acorduri GDPR" sheetId="1" r:id="rId1"/>',
            '  </sheets>',
            '</workbook>',
        ]);
    }

    private function gdprBuildExcelWorkbookRelsXml(): string
    {
        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            '  <Relationship Id="rId1" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet1.xml"/>',
            '  <Relationship Id="rId2" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
                . 'Target="styles.xml"/>',
            '</Relationships>',
        ]);
    }

    private function gdprBuildExcelStylesXml(): string
    {
        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">',
            '  <fonts count="1">',
            '    <font><sz val="11"/><name val="Calibri"/><family val="2"/></font>',
            '  </fonts>',
            '  <fills count="2">',
            '    <fill><patternFill patternType="none"/></fill>',
            '    <fill><patternFill patternType="gray125"/></fill>',
            '  </fills>',
            '  <borders count="1">',
            '    <border><left/><right/><top/><bottom/><diagonal/></border>',
            '  </borders>',
            '  <cellStyleXfs count="1">',
            '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>',
            '  </cellStyleXfs>',
            '  <cellXfs count="1">',
            '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
            '  </cellXfs>',
            '  <cellStyles count="1">',
            '    <cellStyle name="Normal" xfId="0" builtinId="0"/>',
            '  </cellStyles>',
            '</styleSheet>',
        ]);
    }

    private function gdprBuildExcelSheetRelsXml(): string
    {
        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            '  <Relationship Id="rId1" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" '
                . 'Target="../drawings/drawing1.xml"/>',
            '</Relationships>',
        ]);
    }

    /**
     * @param list<array{relationship_id:string,target:string,name:string,col_zero:int,row_zero:int,cx:int,cy:int}> $drawings
     */
    private function gdprBuildExcelDrawingXml(array $drawings): string
    {
        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml[] = '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        foreach ($drawings as $index => $drawing) {
            $shapeId = $index + 1;
            $name = $this->xmlEscape((string) ($drawing['name'] ?? ('Semnatura ' . $shapeId)));
            $relationshipId = $this->xmlEscape((string) ($drawing['relationship_id'] ?? ('rId' . $shapeId)));
            $colZero = max(0, (int) ($drawing['col_zero'] ?? 0));
            $rowZero = max(0, (int) ($drawing['row_zero'] ?? 0));
            $cx = max(1, (int) ($drawing['cx'] ?? 1));
            $cy = max(1, (int) ($drawing['cy'] ?? 1));

            $xml[] = '  <xdr:oneCellAnchor>';
            $xml[] = '    <xdr:from>';
            $xml[] = '      <xdr:col>' . $colZero . '</xdr:col>';
            $xml[] = '      <xdr:colOff>19050</xdr:colOff>';
            $xml[] = '      <xdr:row>' . $rowZero . '</xdr:row>';
            $xml[] = '      <xdr:rowOff>19050</xdr:rowOff>';
            $xml[] = '    </xdr:from>';
            $xml[] = '    <xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>';
            $xml[] = '    <xdr:pic>';
            $xml[] = '      <xdr:nvPicPr>';
            $xml[] = '        <xdr:cNvPr id="' . $shapeId . '" name="' . $name . '"/>';
            $xml[] = '        <xdr:cNvPicPr/>';
            $xml[] = '      </xdr:nvPicPr>';
            $xml[] = '      <xdr:blipFill>';
            $xml[] = '        <a:blip r:embed="' . $relationshipId . '"/>';
            $xml[] = '        <a:stretch><a:fillRect/></a:stretch>';
            $xml[] = '      </xdr:blipFill>';
            $xml[] = '      <xdr:spPr>';
            $xml[] = '        <a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>';
            $xml[] = '        <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>';
            $xml[] = '      </xdr:spPr>';
            $xml[] = '    </xdr:pic>';
            $xml[] = '    <xdr:clientData/>';
            $xml[] = '  </xdr:oneCellAnchor>';
        }

        $xml[] = '</xdr:wsDr>';
        return implode("\n", $xml);
    }

    /**
     * @param list<array{relationship_id:string,target:string,name:string,col_zero:int,row_zero:int,cx:int,cy:int}> $drawings
     */
    private function gdprBuildExcelDrawingRelsXml(array $drawings): string
    {
        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml[] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($drawings as $drawing) {
            $relationshipId = $this->xmlEscape((string) ($drawing['relationship_id'] ?? ''));
            $target = $this->xmlEscape((string) ($drawing['target'] ?? ''));
            if ($relationshipId === '' || $target === '') {
                continue;
            }
            $xml[] = '  <Relationship Id="' . $relationshipId . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
                . 'Target="' . $target . '"/>';
        }
        $xml[] = '</Relationships>';
        return implode("\n", $xml);
    }

    /**
     * @param array<string,string> $imageMimes
     */
    private function gdprBuildExcelContentTypesXml(array $imageMimes, bool $hasDrawings): string
    {
        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml[] = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml[] = '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml[] = '  <Default Extension="xml" ContentType="application/xml"/>';
        foreach ($imageMimes as $extension => $mime) {
            $ext = strtolower(trim((string) $extension));
            $type = trim((string) $mime);
            if ($ext === '' || $type === '') {
                continue;
            }
            $xml[] = '  <Default Extension="' . $this->xmlEscape($ext) . '" ContentType="' . $this->xmlEscape($type) . '"/>';
        }
        $xml[] = '  <Override PartName="/xl/workbook.xml" '
            . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $xml[] = '  <Override PartName="/xl/worksheets/sheet1.xml" '
            . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $xml[] = '  <Override PartName="/xl/styles.xml" '
            . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        if ($hasDrawings) {
            $xml[] = '  <Override PartName="/xl/drawings/drawing1.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }
        $xml[] = '</Types>';
        return implode("\n", $xml);
    }

    private function gdprBuildExcelRootRelsXml(): string
    {
        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            '  <Relationship Id="rId1" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
                . 'Target="xl/workbook.xml"/>',
            '</Relationships>',
        ]);
    }

    private function gdprExcelInlineStringCell(int $columnIndex, int $rowIndex, string $value): string
    {
        $cellRef = $this->gdprExcelColumnName($columnIndex) . $rowIndex;
        return '      <c r="' . $cellRef . '" t="inlineStr"><is><t>' . $this->xmlEscape($value) . '</t></is></c>';
    }

    private function gdprExcelColumnName(int $columnIndex): string
    {
        $columnIndex = max(1, $columnIndex);
        $name = '';
        while ($columnIndex > 0) {
            $remainder = ($columnIndex - 1) % 26;
            $name = chr(65 + $remainder) . $name;
            $columnIndex = intdiv($columnIndex - 1, 26);
        }
        return $name;
    }

    public function createCategory(): void
    {
        if (!$this->guard()) {
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = $slugInput !== '' ? $this->slugify($slugInput) : $this->slugify($name);

        if ($name === '' || $slug === '') {
            Flash::set('error', 'Numele categoriei este obligatoriu.');
            header('Location: /admin/categories');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/categories');
            return;
        }
        $this->ensureOptionalSchema($db);

        try {
            $stmt = $db->prepare('INSERT INTO product_categories (name, slug) VALUES (:name, :slug)');
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
            ]);
            Flash::set('success', 'Categoria a fost adăugată.');
        } catch (Throwable) {
            Flash::set('error', 'Categoria există deja.');
        }

        header('Location: /admin/categories');
    }

    public function updateCategory(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Flash::set('error', 'Categorie invalidă.');
            header('Location: /admin/categories');
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = $slugInput !== '' ? $this->slugify($slugInput) : $this->slugify($name);
        if ($name === '' || $slug === '') {
            Flash::set('error', 'Numele categoriei este obligatoriu.');
            header('Location: /admin/categories');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/categories');
            return;
        }
        $this->ensureOptionalSchema($db);

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE product_categories SET name = :name, slug = :slug WHERE id = :id');
            $stmt->execute([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
            ]);

            // Keep denormalized category text in sync for legacy/fallback reads.
            $stmtProducts = $db->prepare('UPDATE products SET category = :category WHERE category_id = :id');
            $stmtProducts->execute([
                'id' => $id,
                'category' => $name,
            ]);

            $db->commit();
            Flash::set('success', 'Categoria a fost actualizată.');
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Flash::set('error', 'Nu am putut actualiza categoria. Verifică dacă slug-ul există deja.');
        }

        header('Location: /admin/categories');
    }

    public function deleteCategory(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Flash::set('error', 'Categorie invalidă.');
            header('Location: /admin/categories');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/categories');
            return;
        }
        $this->ensureOptionalSchema($db);

        try {
            $db->beginTransaction();
            $stmtProducts = $db->prepare('UPDATE products SET category_id = NULL, category = NULL WHERE category_id = :id');
            $stmtProducts->execute(['id' => $id]);

            $stmtDelete = $db->prepare('DELETE FROM product_categories WHERE id = :id');
            $stmtDelete->execute(['id' => $id]);
            $db->commit();
            Flash::set('success', 'Categoria a fost ștearsă.');
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Flash::set('error', 'Categoria nu a putut fi ștearsă.');
        }

        header('Location: /admin/categories');
    }

    public function createProductForm(): void
    {
        if (!$this->guard()) {
            return;
        }

        View::render('admin/products-create', [
            'title' => 'Produs nou',
            'categories' => $this->categoriesList(),
        ], 'admin/layout');
    }

    public function createProduct(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();

        if (!$db instanceof PDO) {
            header('Location: /admin/products');
            return;
        }
        $this->ensureOptionalSchema($db);
        \App\Support\CheckoutCalculator::ensureProductVatSchema($db);

        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $category = $this->categoryNameById($categoryId);
        if ($category === null) {
            $fallbackCategory = trim((string) ($_POST['category'] ?? ''));
            $category = $fallbackCategory !== '' ? $fallbackCategory : null;
        }
        $price = (float) ($_POST['price'] ?? 0);
        $vatImplicit = (string) (Settings::all($db)['default_vat_percent'] ?? '19');
        $vatPercentRaw = str_replace(',', '.', trim((string) ($_POST['vat_percent'] ?? $vatImplicit)));
        $vatPercent = (float) $vatPercentRaw;
        if (!is_finite($vatPercent) || $vatPercent < 0) {
            $vatPercent = 0.0;
        } elseif ($vatPercent > 100) {
            $vatPercent = 100.0;
        }
        $vatIncluded = ((string) ($_POST['vat_included'] ?? '1')) === '0' ? 0 : 1;
        $salePriceRaw = trim((string) ($_POST['sale_price'] ?? ''));
        $salePrice = $salePriceRaw !== '' ? (float) $salePriceRaw : null;
        $salePricePeriodsJson = $this->normalizeProductSalePeriodsJson($_POST['sale_price_periods_json'] ?? '[]');
        $discountBadgeMode = ((string) ($_POST['discount_badge_mode'] ?? 'percent')) === 'value' ? 'value' : 'percent';
        $bbdEnabled = isset($_POST['bbd_enabled']) ? 1 : 0;
        $bbdEntriesJson = $this->normalizeProductBbdEntriesJson($_POST['bbd_entries_json'] ?? '[]');
        $postCartNoteEnabled = isset($_POST['post_cart_note_enabled']) ? 1 : 0;
        $postCartNoteText = trim((string) ($_POST['post_cart_note_text'] ?? ''));
        $stock = (int) ($_POST['stock'] ?? 0);
        $outOfStock = isset($_POST['out_of_stock']) ? 1 : 0;
        $weight = trim((string) ($_POST['weight_grams'] ?? ''));
        $weightGrams = $weight !== '' ? (int) $weight : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $templateId = (int) ($_POST['product_template_id'] ?? 0);
        if ($templateId > 0) {
            $template = $this->loadProductTemplateById($db, $templateId);
            if (!is_array($template)) {
                $templateId = 0;
            }
        }
        $seoTitle = trim((string) ($_POST['seo_title'] ?? ''));
        $seoDescription = trim((string) ($_POST['seo_description'] ?? ''));
        $seoCanonicalUrl = trim((string) ($_POST['seo_canonical_url'] ?? ''));
        $seoImageUrl = trim((string) ($_POST['seo_image_url'] ?? ''));
        $extraValues = is_array($_POST['extra_fields'] ?? null) ? (array) $_POST['extra_fields'] : [];

        if ($name === '' || $slug === '') {
            header('Location: /admin/products/new');
            return;
        }

        $galleryJson = $this->normalizeProductGalleryJson($_POST['gallery_images_json'] ?? '[]');
        $similarProductsJson = $this->normalizeProductSimilarJson($_POST['similar_products_json'] ?? '[]');
        $badgePopular = isset($_POST['badge_popular']) ? 1 : 0;
        $badgeBest = isset($_POST['badge_best_seller']) ? 1 : 0;
        $badgeSeason = isset($_POST['badge_seasonal']) ? 1 : 0;
        $stmt = $db->prepare(
            'INSERT INTO products (
                name, sku, category, category_id, product_template_id, slug, short_description, description, product_highlights, price, vat_percent, vat_included, sale_price, sale_price_periods_json, discount_badge_mode, bbd_enabled, bbd_entries_json, post_cart_note_enabled, post_cart_note_text, stock, out_of_stock, weight_grams, image_url, gallery_images_json, similar_products_json, badge_popular, badge_best_seller, badge_seasonal, is_active
             ) VALUES (
                :name, :sku, :category, :category_id, :product_template_id, :slug, :short_description, :description, :product_highlights, :price, :vat_percent, :vat_included, :sale_price, :sale_price_periods_json, :discount_badge_mode, :bbd_enabled, :bbd_entries_json, :post_cart_note_enabled, :post_cart_note_text, :stock, :out_of_stock, :weight_grams, :image_url, :gallery_images_json, :similar_products_json, :badge_popular, :badge_best_seller, :badge_seasonal, :is_active
             )'
        );
        $stmt->execute([
            'name' => $name,
            'sku' => $sku !== '' ? $sku : null,
            'category' => $category,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'product_template_id' => $templateId > 0 ? $templateId : null,
            'slug' => $slug,
            'short_description' => trim($_POST['short_description'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'product_highlights' => trim((string) ($_POST['product_highlights'] ?? '')),
            'price' => $price,
            'vat_percent' => $vatPercent,
            'vat_included' => $vatIncluded,
            'sale_price' => $salePrice,
            'sale_price_periods_json' => $salePricePeriodsJson,
            'discount_badge_mode' => $discountBadgeMode,
            'bbd_enabled' => $bbdEnabled,
            'bbd_entries_json' => $bbdEntriesJson,
            'post_cart_note_enabled' => $postCartNoteEnabled,
            'post_cart_note_text' => $postCartNoteText !== '' ? $postCartNoteText : null,
            'stock' => $stock,
            'out_of_stock' => $outOfStock,
            'weight_grams' => $weightGrams,
            'image_url' => trim($_POST['image_url'] ?? ''),
            'gallery_images_json' => $galleryJson,
            'similar_products_json' => $similarProductsJson,
            'badge_popular' => $badgePopular,
            'badge_best_seller' => $badgeBest,
            'badge_seasonal' => $badgeSeason,
            'is_active' => $isActive,
        ]);
        $productId = (int) $db->lastInsertId();
        \App\Support\ProductCategories::sync(
            $db,
            $productId,
            is_array($_POST['extra_category_ids'] ?? null) ? (array) $_POST['extra_category_ids'] : [],
            $categoryId
        );
        $this->saveProductExtraFieldValues($db, $productId, $extraValues);
        if ($productId > 0) {
            $this->saveSeoSettings($db, 'product', (string) $productId, [
                'title' => $seoTitle,
                'description' => $seoDescription,
                'canonical_url' => $seoCanonicalUrl,
                'image_url' => $seoImageUrl,
            ]);
        }
        $this->refreshCacheAfterPublicContentChange($db);

        Flash::set('success', 'Produsul a fost adăugat.');
        header('Location: /admin/products');
    }

    public function updateProduct(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Flash::set('error', 'Produs invalid.');
            header('Location: /admin/products');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products');
            return;
        }
        $this->ensureOptionalSchema($db);
        \App\Support\CheckoutCalculator::ensureProductVatSchema($db);

        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        if ($name === '' || $slug === '') {
            Flash::set('error', 'Numele și slug-ul sunt obligatorii.');
            header('Location: /admin/products');
            return;
        }

        $salePriceRaw = trim((string) ($_POST['sale_price'] ?? ''));
        $salePrice = $salePriceRaw !== '' ? (float) $salePriceRaw : null;
        $salePricePeriodsJson = $this->normalizeProductSalePeriodsJson($_POST['sale_price_periods_json'] ?? '[]');
        $discountBadgeMode = ((string) ($_POST['discount_badge_mode'] ?? 'percent')) === 'value' ? 'value' : 'percent';
        $bbdEnabled = isset($_POST['bbd_enabled']) ? 1 : 0;
        $bbdEntriesJson = $this->normalizeProductBbdEntriesJson($_POST['bbd_entries_json'] ?? '[]');
        $postCartNoteEnabled = isset($_POST['post_cart_note_enabled']) ? 1 : 0;
        $postCartNoteText = trim((string) ($_POST['post_cart_note_text'] ?? ''));
        $vatImplicit = (string) (Settings::all($db)['default_vat_percent'] ?? '19');
        $vatPercentRaw = str_replace(',', '.', trim((string) ($_POST['vat_percent'] ?? $vatImplicit)));
        $vatPercent = (float) $vatPercentRaw;
        if (!is_finite($vatPercent) || $vatPercent < 0) {
            $vatPercent = 0.0;
        } elseif ($vatPercent > 100) {
            $vatPercent = 100.0;
        }
        $vatIncluded = ((string) ($_POST['vat_included'] ?? '1')) === '0' ? 0 : 1;
        $weight = trim((string) ($_POST['weight_grams'] ?? ''));
        $weightGrams = $weight !== '' ? (int) $weight : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $templateId = (int) ($_POST['product_template_id'] ?? 0);
        if ($templateId > 0) {
            $template = $this->loadProductTemplateById($db, $templateId);
            if (!is_array($template)) {
                $templateId = 0;
            }
        }
        $seoTitle = trim((string) ($_POST['seo_title'] ?? ''));
        $seoDescription = trim((string) ($_POST['seo_description'] ?? ''));
        $seoCanonicalUrl = trim((string) ($_POST['seo_canonical_url'] ?? ''));
        $seoImageUrl = trim((string) ($_POST['seo_image_url'] ?? ''));
        $extraValues = is_array($_POST['extra_fields'] ?? null) ? (array) $_POST['extra_fields'] : [];

        $galleryJson = $this->normalizeProductGalleryJson($_POST['gallery_images_json'] ?? '[]');
        $similarProductsJson = $this->normalizeProductSimilarJson($_POST['similar_products_json'] ?? '[]');
        $badgePopular = isset($_POST['badge_popular']) ? 1 : 0;
        $badgeBest = isset($_POST['badge_best_seller']) ? 1 : 0;
        $badgeSeason = isset($_POST['badge_seasonal']) ? 1 : 0;
        $stmt = $db->prepare(
            'UPDATE products
             SET name = :name,
                 sku = :sku,
                 category = :category,
                 category_id = :category_id,
                 product_template_id = :product_template_id,
                 slug = :slug,
                 short_description = :short_description,
                 description = :description,
                 product_highlights = :product_highlights,
                 price = :price,
                 vat_percent = :vat_percent,
                vat_included = :vat_included,
                 sale_price = :sale_price,
                 sale_price_periods_json = :sale_price_periods_json,
                 discount_badge_mode = :discount_badge_mode,
                 bbd_enabled = :bbd_enabled,
                 bbd_entries_json = :bbd_entries_json,
                 post_cart_note_enabled = :post_cart_note_enabled,
                 post_cart_note_text = :post_cart_note_text,
                 stock = :stock,
                 out_of_stock = :out_of_stock,
                 weight_grams = :weight_grams,
                 image_url = :image_url,
                 gallery_images_json = :gallery_images_json,
                 similar_products_json = :similar_products_json,
                 badge_popular = :badge_popular,
                 badge_best_seller = :badge_best_seller,
                 badge_seasonal = :badge_seasonal,
                 is_active = :is_active
             WHERE id = :id AND deleted_at IS NULL'
        );
        $categoryIdFromPost = (int) ($_POST['category_id'] ?? 0);
        $categoryName = $this->categoryNameById($categoryIdFromPost);
        if ($categoryName === null) {
            $fallbackCategory = trim((string) ($_POST['category'] ?? ''));
            $categoryName = $fallbackCategory !== '' ? $fallbackCategory : null;
        }

        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'sku' => trim((string) ($_POST['sku'] ?? '')) ?: null,
            'category' => $categoryName,
            'category_id' => $categoryIdFromPost > 0 ? $categoryIdFromPost : null,
            'product_template_id' => $templateId > 0 ? $templateId : null,
            'slug' => $slug,
            'short_description' => trim((string) ($_POST['short_description'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'product_highlights' => trim((string) ($_POST['product_highlights'] ?? '')),
            'price' => (float) ($_POST['price'] ?? 0),
            'vat_percent' => $vatPercent,
            'vat_included' => $vatIncluded,
            'sale_price' => $salePrice,
            'sale_price_periods_json' => $salePricePeriodsJson,
            'discount_badge_mode' => $discountBadgeMode,
            'bbd_enabled' => $bbdEnabled,
            'bbd_entries_json' => $bbdEntriesJson,
            'post_cart_note_enabled' => $postCartNoteEnabled,
            'post_cart_note_text' => $postCartNoteText !== '' ? $postCartNoteText : null,
            'stock' => (int) ($_POST['stock'] ?? 0),
            'out_of_stock' => isset($_POST['out_of_stock']) ? 1 : 0,
            'weight_grams' => $weightGrams,
            'image_url' => trim((string) ($_POST['image_url'] ?? '')),
            'gallery_images_json' => $galleryJson,
            'similar_products_json' => $similarProductsJson,
            'badge_popular' => $badgePopular,
            'badge_best_seller' => $badgeBest,
            'badge_seasonal' => $badgeSeason,
            'is_active' => $isActive,
        ]);
        \App\Support\ProductCategories::sync(
            $db,
            $id,
            is_array($_POST['extra_category_ids'] ?? null) ? (array) $_POST['extra_category_ids'] : [],
            $categoryIdFromPost
        );
        $this->saveProductExtraFieldValues($db, $id, $extraValues);
        $this->saveSeoSettings($db, 'product', (string) $id, [
            'title' => $seoTitle,
            'description' => $seoDescription,
            'canonical_url' => $seoCanonicalUrl,
            'image_url' => $seoImageUrl,
        ]);
        $this->refreshCacheAfterPublicContentChange($db);

        Flash::set('success', 'Produsul a fost actualizat.');
        header('Location: /admin/products');
    }

    public function productImageUpload(): void
    {
        if (!$this->guard()) {
            return;
        }

        $redirectUrl = trim((string) ($_POST['redirect_url'] ?? ''));
        if ($redirectUrl === '' || !str_starts_with($redirectUrl, '/admin/')) {
            $redirectUrl = '/admin/products';
        }

        $db = $this->db();
        $isAjax = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if (!$db instanceof PDO) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(503);
                echo json_encode(['ok' => false, 'message' => 'Conexiunea DB nu este disponibilă.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: ' . $redirectUrl);
            return;
        }
        $this->ensureOptionalSchema($db);

        $uploadBaseName = trim((string) ($_POST['title'] ?? ''));
        if ($uploadBaseName === '' && is_array($_FILES['image_file'] ?? null)) {
            $uploadBaseName = (string) pathinfo((string) (($_FILES['image_file']['name'] ?? '')), PATHINFO_FILENAME);
        }
        $uploaded = $this->handleMediaUpload($_FILES['image_file'] ?? null, 'gallery', $uploadBaseName);
        if (!is_array($uploaded)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Upload eșuat. Verifică tipul fișierului.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            Flash::set('error', 'Upload eșuat. Verifică tipul fișierului.');
            header('Location: ' . $redirectUrl);
            return;
        }

        $imageUrl = trim((string) ($uploaded['url'] ?? ''));
        if ($imageUrl === '') {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Upload eșuat.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            Flash::set('error', 'Upload eșuat.');
            header('Location: ' . $redirectUrl);
            return;
        }
        $mediaType = trim((string) ($uploaded['media_type'] ?? 'image'));
        if ($mediaType === '') {
            $mediaType = 'image';
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' && is_array($_FILES['image_file'] ?? null)) {
            $title = (string) pathinfo((string) (($_FILES['image_file']['name'] ?? '')), PATHINFO_FILENAME);
        }
        if ($title === '') {
            $filePath = (string) (parse_url($imageUrl, PHP_URL_PATH) ?? $imageUrl);
            $title = (string) pathinfo($filePath, PATHINFO_FILENAME);
        }
        $title = trim((string) (preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $title)) ?? ''));
        if ($title === '') {
            $title = 'Imagine produs';
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO gallery_images (title, media_type, image_url, folder_id, alt_text, sort_order, is_active)
                 VALUES (:title, :media_type, :image_url, NULL, :alt_text, 0, 1)'
            );
            $stmt->execute([
                'title' => $title,
                'media_type' => $mediaType,
                'image_url' => $imageUrl,
                'alt_text' => $title,
            ]);
        } catch (Throwable) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode(['ok' => false, 'message' => 'Imaginea a fost încărcată, dar nu a putut fi adăugată în galerie.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            Flash::set('error', 'Imaginea a fost încărcată, dar nu a putut fi adăugată în galerie.');
            header('Location: ' . $redirectUrl);
            return;
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'url' => $imageUrl,
                'title' => $title,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        Flash::set('success', 'Imagine încărcată cu succes.');
        header('Location: ' . $redirectUrl);
    }

    public function deleteProduct(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            header('Location: /admin/products');
            return;
        }

        $db = $this->db();
        if ($db instanceof PDO) {
            $stmt = $db->prepare('UPDATE products SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $id]);
            $this->refreshCacheAfterPublicContentChange($db);
        }

        Flash::set('success', 'Produsul a fost mutat în coșul de gunoi.');
        header('Location: /admin/products');
    }

    public function productsTrash(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $products = [];
        if ($db instanceof PDO) {
            $products = $db->query(
                'SELECT id, name, sku, category, slug, price, stock, image_url, deleted_at
                 FROM products
                 WHERE deleted_at IS NOT NULL
                 ORDER BY deleted_at DESC'
            )->fetchAll();
        }

        View::render('admin/products-trash', [
            'title' => 'Coș produse',
            'products' => $products,
        ], 'admin/layout');
    }

    public function restoreProduct(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $db = $this->db();
        if ($db instanceof PDO && $id > 0) {
            $stmt = $db->prepare('UPDATE products SET deleted_at = NULL WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $this->refreshCacheAfterPublicContentChange($db);
            Flash::set('success', 'Produs restaurat.');
        }

        header('Location: /admin/products/trash');
    }

    public function forceDeleteProduct(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $db = $this->db();
        if ($db instanceof PDO && $id > 0) {
            $stmt = $db->prepare('DELETE FROM products WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $this->saveSeoSettings($db, 'product', (string) $id, []);
            $this->refreshCacheAfterPublicContentChange($db);
            Flash::set('success', 'Produs șters definitiv.');
        }

        header('Location: /admin/products/trash');
    }

    public function orders(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $orders = [];
        $promoProducts = [];
        $orderProducts = [];
        $filters = $this->ordersFiltersFromInput($_GET);
        $ordersBackUrl = $this->buildOrdersBackUrl($filters);
        $ordersSummary = [
            'total_count' => 0,
            'completed_count' => 0,
            'cancelled_count' => 0,
            'pending_like_count' => 0,
            'total_value' => 0.0,
        ];
        $orderStatusLabels = [
            'pending' => 'În așteptare',
            'pending_payment' => 'Plată în așteptare',
            'processing' => 'În procesare',
            'completed' => 'Finalizată',
            'cancelled' => 'Anulată',
            'refunded' => 'Rambursată',
            'failed' => 'Eșuată',
        ];
        $orderPaymentStatusLabels = [
            'paid' => 'Plătit',
            'unpaid' => 'Neplătit',
            'failed' => 'Eșuat',
            'pending' => 'În așteptare',
        ];
        $orderPaymentMethodLabels = [
            'cod' => 'Ramburs',
            'euplatesc' => 'Card',
            'stripe' => 'Card',
            'card' => 'Card',
            'bank_transfer' => 'Card',
        ];

        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            \App\Support\ErpSync::ensureSchema($db);
            $this->ensurePromoSchema($db);
            \App\Support\CheckoutCalculator::ensureOrderShippingSchema($db);
            $promoProducts = $db->query('SELECT id, name FROM promotional_products WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll() ?: [];
            try {
                $orderProducts = $db->query('SELECT id, name, price, sale_price FROM products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC LIMIT 3000')->fetchAll() ?: [];
            } catch (Throwable) {
                $orderProducts = [];
            }
            $where = ['deleted_at IS NULL'];
            $params = [];
            if ($filters['q'] !== '') {
                $q = trim((string) $filters['q']);
                // Potriviri pe textul întreg: nr. comandă, email, telefon și numele
                // complet (prenume+nume ȘI nume+prenume, ca să meargă în orice ordine).
                $fullConds = [
                    'order_number LIKE :q',
                    'billing_email LIKE :q',
                    'billing_phone LIKE :q',
                    'CONCAT(COALESCE(billing_first_name, ""), " ", COALESCE(billing_last_name, "")) LIKE :q',
                    'CONCAT(COALESCE(billing_last_name, ""), " ", COALESCE(billing_first_name, "")) LIKE :q',
                ];
                if (ctype_digit($q)) {
                    $fullConds[] = 'id = :q_order_id';
                    $params['q_order_id'] = (int) $q;
                }
                $params['q'] = '%' . $q . '%';

                // Potrivire pe cuvinte: fiecare cuvânt trebuie să apară în vreun câmp
                // (prenume/nume/email/telefon). Acoperă „Prenume Nume" indiferent cum
                // e împărțit numele în cele două coloane.
                $tokenConds = [];
                $tokens = preg_split('/\s+/', $q) ?: [];
                $tokenIndex = 0;
                foreach ($tokens as $token) {
                    if ($token === '') {
                        continue;
                    }
                    $ph = ':qt' . $tokenIndex;
                    $tokenConds[] = '(billing_first_name LIKE ' . $ph
                        . ' OR billing_last_name LIKE ' . $ph
                        . ' OR billing_email LIKE ' . $ph
                        . ' OR billing_phone LIKE ' . $ph . ')';
                    $params['qt' . $tokenIndex] = '%' . $token . '%';
                    $tokenIndex++;
                }

                $searchExpr = '(' . implode(' OR ', $fullConds) . ')';
                if ($tokenConds !== []) {
                    $searchExpr = '(' . $searchExpr . ' OR (' . implode(' AND ', $tokenConds) . '))';
                }
                $where[] = $searchExpr;
            }
            if ($filters['status'] !== '') {
                $where[] = 'status = :status';
                $params['status'] = $filters['status'];
            }
            if ($filters['payment_method'] !== '') {
                $paymentMethodFilter = strtolower($filters['payment_method']);
                if ($paymentMethodFilter === 'card') {
                    $where[] = 'LOWER(payment_method) IN ("card", "euplatesc", "stripe", "bank_transfer")';
                } elseif ($paymentMethodFilter === 'cod') {
                    $where[] = 'LOWER(payment_method) = "cod"';
                } else {
                    $where[] = 'LOWER(payment_method) = :payment_method';
                    $params['payment_method'] = $paymentMethodFilter;
                }
            }
            if ($filters['payment_status'] !== '') {
                $where[] = 'LOWER(payment_status) = :payment_status';
                $params['payment_status'] = strtolower($filters['payment_status']);
            }
            if (!empty($filters['rest_incasat'])) {
                // Plătită cu cardul, dar totalul a crescut după încasare.
                $where[] = 'LOWER(payment_status) = "paid" AND paid_amount IS NOT NULL'
                    . ' AND ROUND(total - paid_amount, 2) > 0';
            }
            if ($filters['from_date'] !== '') {
                $where[] = 'created_at >= :from_date';
                $params['from_date'] = $filters['from_date'] . ' 00:00:00';
            }
            if ($filters['to_date'] !== '') {
                $where[] = 'created_at <= :to_date';
                $params['to_date'] = $filters['to_date'] . ' 23:59:59';
            }

            $orderBySql = $this->ordersSortSql($filters['sort_by'], $filters['sort_dir']);
            // Coloanele de plată sunt adăugate prin ALTER la nevoie; le cerem
            // doar dacă există, ca lista să nu crape pe o bază mai veche.
            $coloanePlata = [];
            foreach (['payment_error', 'euplatesc_ep_id', 'paid_at'] as $coloanaPlata) {
                if ($this->tableHasColumn($db, 'orders', $coloanaPlata)) {
                    $coloanePlata[] = $coloanaPlata;
                }
            }
            $selectPlata = $coloanePlata === [] ? '' : ', ' . implode(', ', $coloanePlata);
            $stmt = $db->prepare(
                'SELECT id, order_number, status, payment_method, payment_status, total, shipping_cost, subtotal, discount_total,
                        coupon_code,
                        loyalty_points_used, loyalty_points_discount, loyalty_points_awarded, created_at,
                        deleted_at, ad_source, ad_click_id,
                        fan_awb, fan_awb_list, fan_tracking_url, fan_tracking_status, fan_tracking_last_event_at, fan_tracking_synced_at,
                        fan_locker_id, fan_locker_name, fan_locker_address, fan_locker_city, fan_locker_county,
                        completed_awb_email_sent_at, completed_awb_email_error,
                        billing_first_name, billing_last_name, billing_email, billing_phone,
                        billing_address_line1, billing_address_line2, billing_city, billing_county, billing_postcode,
                        billing_is_company, billing_company_name, billing_company_tax_id, billing_company_registration_no,
                        shipping_same_as_billing, shipping_first_name, shipping_last_name, shipping_phone,
                        shipping_address_line1, shipping_city, shipping_county, shipping_postcode,
                        notes,
                        erp_status, erp_order_id, erp_attempts, erp_last_error, erp_problems, erp_synced_at,
                        erp_factura_numar, paid_amount,
                        manual_discount, manual_discount_percent, manual_discount_reason' . $selectPlata . '
                 FROM orders
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY ' . $orderBySql . '
                 LIMIT 400'
            );
            $stmt->execute($params);
            $orders = $stmt->fetchAll() ?: [];
            $ordersSummary['total_count'] = count($orders);
            foreach ($orders as $orderRow) {
                $status = strtolower(trim((string) ($orderRow['status'] ?? '')));
                if ($status === 'completed') {
                    $ordersSummary['completed_count']++;
                }
                if ($status === 'cancelled') {
                    $ordersSummary['cancelled_count']++;
                }
                if (in_array($status, ['pending', 'pending_payment', 'processing'], true)) {
                    $ordersSummary['pending_like_count']++;
                }
                $ordersSummary['total_value'] += (float) ($orderRow['total'] ?? 0.0);
            }
            $ordersSummary['total_value'] = round((float) $ordersSummary['total_value'], 2);

            if ($orders !== []) {
                $ids = array_map(static fn (array $order): int => (int) $order['id'], $orders);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $itemsStmt = $db->prepare(
                    "SELECT oi.order_id, oi.product_id, oi.product_name, oi.quantity, oi.unit_price, oi.line_total,
                            COALESCE(p.vat_percent, 19.00) AS vat_percent,
                            COALESCE(p.vat_included, 1) AS vat_included
                     FROM order_items oi
                     LEFT JOIN products p ON p.id = oi.product_id
                     WHERE oi.order_id IN ($placeholders)
                     ORDER BY oi.id ASC"
                );
                $itemsStmt->execute($ids);
                $itemsRows = $itemsStmt->fetchAll();
                $itemsMap = [];
                $vatByOrder = [];
                $subtotalWithoutVatByOrder = [];
                foreach ($itemsRows as $row) {
                    $orderKey = (int) ($row['order_id'] ?? 0);
                    if ($orderKey <= 0) {
                        continue;
                    }
                    $qty = max(1, (int) ($row['quantity'] ?? 1));
                    $lineTotal = (float) ($row['line_total'] ?? 0);
                    $unitPrice = (float) ($row['unit_price'] ?? 0);
                    if ($unitPrice <= 0.0 && $qty > 0) {
                        $unitPrice = $lineTotal / $qty;
                    }
                    $vatPercent = max(0.0, min(100.0, (float) ($row['vat_percent'] ?? 19.0)));
                    $vatIncluded = ((int) ($row['vat_included'] ?? 1)) === 1;
                    $lineVat = $vatPercent > 0.0
                        ? ($vatIncluded
                            ? ($lineTotal - ($lineTotal / (1.0 + ($vatPercent / 100.0))))
                            : ($lineTotal * ($vatPercent / 100.0)))
                        : 0.0;
                    $lineSubtotalWithoutVat = $vatIncluded ? max(0.0, $lineTotal - $lineVat) : $lineTotal;

                    $row['unit_price'] = $unitPrice;
                    $row['vat_percent'] = $vatPercent;
                    $row['vat_included'] = $vatIncluded ? 1 : 0;
                    $row['vat_value'] = $lineVat;
                    $row['subtotal_without_vat'] = $lineSubtotalWithoutVat;

                    $itemsMap[$orderKey][] = $row;
                    $vatByOrder[$orderKey] = ($vatByOrder[$orderKey] ?? 0.0) + $lineVat;
                    $subtotalWithoutVatByOrder[$orderKey] = ($subtotalWithoutVatByOrder[$orderKey] ?? 0.0) + $lineSubtotalWithoutVat;
                }
                foreach ($orders as &$order) {
                    $orderKey = (int) ($order['id'] ?? 0);
                    $order['items'] = $itemsMap[$orderKey] ?? [];
                    $order['vat_total'] = round((float) ($vatByOrder[$orderKey] ?? 0.0), 2);
                    $order['subtotal_without_vat'] = round(
                        (float) ($subtotalWithoutVatByOrder[$orderKey] ?? (float) ($order['subtotal'] ?? 0.0)),
                        2
                    );
                }
                unset($order);
            }

            // Produse promoționale atașate fiecărei comenzi (batch)
            $orderIds = [];
            foreach ($orders as $o) {
                $oid = (int) ($o['id'] ?? 0);
                if ($oid > 0) {
                    $orderIds[] = $oid;
                }
            }
            $promoMap = [];
            if ($orderIds !== []) {
                try {
                    $in = implode(',', array_map('intval', $orderIds));
                    $pStmt = $db->query('SELECT order_id, promo_product_id, name, quantity FROM order_promo_items WHERE order_id IN (' . $in . ') ORDER BY id ASC');
                    foreach ($pStmt->fetchAll() ?: [] as $pr) {
                        $promoMap[(int) ($pr['order_id'] ?? 0)][] = [
                            'promo_product_id' => (int) ($pr['promo_product_id'] ?? 0),
                            'name' => (string) ($pr['name'] ?? ''),
                            'quantity' => (int) ($pr['quantity'] ?? 1),
                        ];
                    }
                } catch (Throwable) {
                }
            }
            foreach ($orders as &$order) {
                $order['promo_items'] = $promoMap[(int) ($order['id'] ?? 0)] ?? [];
            }
            unset($order);
        }

        View::render('admin/orders', [
            'title' => 'Comenzi',
            'orders' => $orders,
            'promoProducts' => is_array($promoProducts) ? $promoProducts : [],
            'orderProducts' => is_array($orderProducts) ? $orderProducts : [],
            'filters' => $filters,
            'ordersBackUrl' => $ordersBackUrl,
            'allowedOrderStatuses' => self::ORDER_ALLOWED_STATUSES,
            'ordersSummary' => $ordersSummary,
            'orderStatusLabels' => $orderStatusLabels,
            'orderPaymentStatusLabels' => $orderPaymentStatusLabels,
            'orderPaymentMethodLabels' => $orderPaymentMethodLabels,
            'settings' => Settings::all($db),
        ], 'admin/layout');
    }

    public function ordersTrash(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $orders = [];

        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $orders = $db->query(
                'SELECT id, order_number, status, payment_method, payment_status, total, shipping_cost, subtotal, discount_total,
                        loyalty_points_used, loyalty_points_discount, loyalty_points_awarded, created_at, deleted_at,
                        fan_awb, fan_tracking_url, fan_tracking_status, fan_tracking_last_event_at, fan_tracking_synced_at,
                        completed_awb_email_sent_at, completed_awb_email_error,
                        billing_first_name, billing_last_name, billing_email, billing_phone,
                        billing_address_line1, billing_address_line2, billing_city, billing_county, billing_postcode,
                        billing_is_company, billing_company_name, billing_company_tax_id, billing_company_registration_no,
                        notes
                 FROM orders
                 WHERE deleted_at IS NOT NULL
                 ORDER BY deleted_at DESC
                 LIMIT 200'
            )->fetchAll();

            if ($orders !== []) {
                $ids = array_map(static fn (array $order): int => (int) $order['id'], $orders);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $itemsStmt = $db->prepare(
                    "SELECT order_id, product_name, quantity, line_total
                     FROM order_items
                     WHERE order_id IN ($placeholders)
                     ORDER BY id ASC"
                );
                $itemsStmt->execute($ids);
                $itemsRows = $itemsStmt->fetchAll();
                $itemsMap = [];
                foreach ($itemsRows as $row) {
                    $itemsMap[(int) $row['order_id']][] = $row;
                }
                foreach ($orders as &$order) {
                    $order['items'] = $itemsMap[(int) $order['id']] ?? [];
                }
                unset($order);
            }
        }

        View::render('admin/orders-trash', ['title' => 'Coș comenzi', 'orders' => $orders], 'admin/layout');
    }

    /**
     * Scoate codul fiscal dintr-un text scris de client.
     *
     * În câmpul de CUI ajunge des și denumirea firmei („BIOSCEM SRL RO12345678").
     * Dacă apare un cod precedat de „RO", el câștigă — e forma completă. Altfel
     * luăm cel mai lung grup de cifre, ca „MERIDIAN 2000 SRL 45678901" să dea
     * 45678901, nu 2000. Fără nicio cifră, lăsăm textul cum e: nu inventăm un
     * cod, iar operatorul vede ce a scris clientul.
     */
    private static function curataCui(string $brut): string
    {
        $text = trim($brut);
        if ($text === '') {
            return '';
        }
        $majuscule = mb_strtoupper($text, 'UTF-8');
        if (preg_match_all('/\bRO[\s.\-]?(\d{2,10})\b/u', $majuscule, $m) && $m[1] !== []) {
            return 'RO' . end($m[1]);
        }
        if (preg_match_all('/\d{2,10}/u', $majuscule, $m) && $m[0] !== []) {
            $cel = '';
            foreach ($m[0] as $grup) {
                if (strlen($grup) >= strlen($cel)) {
                    $cel = $grup;
                }
            }
            return $cel;
        }
        return mb_substr(preg_replace('/\s+/u', ' ', $majuscule) ?? '', 0, 20, 'UTF-8');
    }

    /** Valoarea curentă a unei coloane de pe comandă, pentru verificări. */
    private function orderColumn(PDO $db, int $orderId, string $column): ?string
    {
        $stmt = $db->prepare('SELECT ' . $column . ' FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (string) $val;
    }

    public function updateOrderAddress(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $orderId = max(0, (int) ($params['id'] ?? 0));
        if ($orderId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'ID comandă invalid.']);
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'DB indisponibil.']);
            return;
        }

        $allowed = [
            'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone',
            'billing_address_line1', 'billing_address_line2',
            'billing_city', 'billing_county', 'billing_postcode',
            // Datele de firmă: clienții le greșesc des la checkout (scriu
            // denumirea în câmpul de CUI), iar comanda nu mai intra în ERP.
            'billing_is_company', 'billing_company_name',
            'billing_company_tax_id', 'billing_company_registration_no',
            'payment_method',
        ];
        header('Content-Type: application/json');
        $sets = [];
        $binds = ['id' => $orderId];
        foreach ($allowed as $col) {
            if (isset($_POST[$col])) {
                $val = trim((string) $_POST[$col]);
                if ($col === 'billing_postcode' && $val !== '' && !preg_match('/^\d{6}$/', $val)) {
                    echo json_encode(['ok' => false, 'error' => 'Codul poștal trebuie să conțină exact 6 cifre.']);
                    return;
                }
                if ($col === 'billing_email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['ok' => false, 'error' => 'Email invalid.']);
                    return;
                }
                if (in_array($col, ['billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone'], true) && $val === '') {
                    echo json_encode(['ok' => false, 'error' => 'Numele, emailul și telefonul nu pot fi goale.']);
                    return;
                }
                if ($col === 'billing_is_company') {
                    $val = ($val === '1' || $val === 'on' || $val === 'true') ? '1' : '0';
                }
                if ($col === 'payment_method' && !in_array($val, self::METODE_PLATA, true)) {
                    echo json_encode(['ok' => false, 'error' => 'Metodă de plată necunoscută.']);
                    return;
                }
                // CUI-ul se curăță aici, nu doar în ERP: dacă în câmp a ajuns și
                // denumirea firmei, păstrăm codul și lăsăm denumirea acolo unde
                // îi e locul.
                if ($col === 'billing_company_tax_id' && $val !== '') {
                    $val = self::curataCui($val);
                }
                $sets[] = $col . ' = :' . $col;
                $binds[$col] = $val;
            }
        }

        // Dacă rămâne firmă, datele minime trebuie să existe — altfel factura
        // din ERP iese pe persoană fizică fără să bage nimeni de seamă.
        if (isset($_POST['billing_is_company']) && ($binds['billing_is_company'] ?? '0') === '1') {
            $denumire = isset($binds['billing_company_name'])
                ? $binds['billing_company_name']
                : trim((string) ($this->orderColumn($db, $orderId, 'billing_company_name') ?? ''));
            $cui = isset($binds['billing_company_tax_id'])
                ? $binds['billing_company_tax_id']
                : trim((string) ($this->orderColumn($db, $orderId, 'billing_company_tax_id') ?? ''));
            if ($denumire === '' || $cui === '') {
                echo json_encode(['ok' => false, 'error' => 'La comandă pe firmă, denumirea și CUI-ul sunt obligatorii.']);
                return;
            }
        }
        if ($sets === []) {
            echo json_encode(['ok' => false, 'error' => 'Niciun câmp de actualizat.']);
            return;
        }

        $db->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :id AND deleted_at IS NULL')
           ->execute($binds);

        // Adresa nouă pleacă și spre ERP, cât timp comanda mai e deschisă acolo.
        $erpSync = null;
        try {
            $settings = Settings::all($db);
            if ((string) ($settings['erp_enabled'] ?? '0') === '1') {
                $erpSync = \App\Support\ErpSync::push($db, $orderId, true);
            }
        } catch (Throwable $e) {
            $erpSync = ['ok' => false, 'message' => $e->getMessage()];
        }

        // Return updated order row so the UI can refresh
        $stmt = $db->prepare(
            'SELECT billing_first_name, billing_last_name, billing_email, billing_phone,
                    billing_address_line1, billing_address_line2,
                    billing_city, billing_county, billing_postcode,
                    billing_is_company, billing_company_name,
                    billing_company_tax_id, billing_company_registration_no,
                    payment_method
             FROM orders WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch() ?: [];

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'address' => $row, 'erp_sync' => $erpSync]);
    }

    /** Creează idempotent tabelele pentru produsele promoționale (nomenclator + per comandă). */
    private function ensurePromoSchema(PDO $db): void
    {
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS promotional_products (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                notes VARCHAR(500) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )');
        } catch (Throwable) {
        }
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS order_promo_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                promo_product_id INT UNSIGNED DEFAULT NULL,
                name VARCHAR(190) NOT NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_opi_order (order_id),
                INDEX idx_opi_promo (promo_product_id)
            )');
        } catch (Throwable) {
        }
    }

    /** Nomenclator produse promoționale (listă + formular) + raport de totaluri. */
    public function promoProducts(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $items = [];
        $editing = null;
        $report = [];
        $clientQuery = trim((string) ($_GET['client'] ?? ''));
        $clientResults = [];
        $from = trim((string) ($_GET['from'] ?? ''));
        $to = trim((string) ($_GET['to'] ?? ''));

        if ($db instanceof PDO) {
            $this->ensurePromoSchema($db);
            $items = $db->query('SELECT id, name, notes, is_active, sort_order, created_at FROM promotional_products ORDER BY sort_order ASC, name ASC')->fetchAll() ?: [];

            $editId = (int) ($_GET['edit'] ?? 0);
            if ($editId > 0) {
                foreach ($items as $it) {
                    if ((int) ($it['id'] ?? 0) === $editId) {
                        $editing = $it;
                        break;
                    }
                }
            }

            // Raport totaluri: câte bucăți din fiecare produs promoțional (opțional pe interval)
            try {
                $rWhere = ['o.deleted_at IS NULL'];
                $rParams = [];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                    $rWhere[] = 'o.created_at >= :from';
                    $rParams['from'] = $from . ' 00:00:00';
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                    $rWhere[] = 'o.created_at <= :to';
                    $rParams['to'] = $to . ' 23:59:59';
                }
                $rSql = 'SELECT pi.name AS name, SUM(pi.quantity) AS total, COUNT(DISTINCT pi.order_id) AS orders_count
                         FROM order_promo_items pi
                         JOIN orders o ON o.id = pi.order_id
                         WHERE ' . implode(' AND ', $rWhere) . '
                         GROUP BY pi.name
                         ORDER BY total DESC';
                $rStmt = $db->prepare($rSql);
                $rStmt->execute($rParams);
                $report = $rStmt->fetchAll() ?: [];
            } catch (Throwable) {
                $report = [];
            }

            // Promoționale per client: caută după nume/email/nr. comandă
            if ($clientQuery !== '') {
                try {
                    $cLike = '%' . $clientQuery . '%';
                    $cStmt = $db->prepare(
                        "SELECT o.id, o.order_number, o.created_at,
                                o.billing_first_name, o.billing_last_name, o.billing_email,
                                pi.name AS promo_name, pi.quantity AS promo_qty
                         FROM order_promo_items pi
                         JOIN orders o ON o.id = pi.order_id
                         WHERE o.deleted_at IS NULL
                           AND (CONCAT(o.billing_first_name, ' ', o.billing_last_name) LIKE :c
                                OR o.billing_email LIKE :c
                                OR o.order_number LIKE :c)
                         ORDER BY o.created_at DESC, pi.id ASC
                         LIMIT 500"
                    );
                    $cStmt->execute(['c' => $cLike]);
                    $grouped = [];
                    foreach ($cStmt->fetchAll() ?: [] as $row) {
                        $oid = (int) ($row['id'] ?? 0);
                        if (!isset($grouped[$oid])) {
                            $name = trim((string) ($row['billing_first_name'] ?? '') . ' ' . (string) ($row['billing_last_name'] ?? ''));
                            $grouped[$oid] = [
                                'id' => $oid,
                                'order_number' => (string) ($row['order_number'] ?? ''),
                                'created_at' => (string) ($row['created_at'] ?? ''),
                                'client_name' => $name !== '' ? $name : 'Client',
                                'email' => (string) ($row['billing_email'] ?? ''),
                                'items' => [],
                            ];
                        }
                        $grouped[$oid]['items'][] = [
                            'name' => (string) ($row['promo_name'] ?? ''),
                            'quantity' => (int) ($row['promo_qty'] ?? 1),
                        ];
                    }
                    $clientResults = array_values($grouped);
                } catch (Throwable) {
                    $clientResults = [];
                }
            }
        }

        View::render('admin/promo-products', [
            'title' => 'Produse promoționale',
            'items' => is_array($items) ? $items : [],
            'editing' => is_array($editing) ? $editing : null,
            'report' => is_array($report) ? $report : [],
            'clientQuery' => $clientQuery,
            'clientResults' => is_array($clientResults) ? $clientResults : [],
            'from' => $from,
            'to' => $to,
        ], 'admin/layout');
    }

    public function promoProductSave(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/promo-products');
            return;
        }
        $this->ensurePromoSchema($db);

        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        if ($name === '' || mb_strlen($name) > 190) {
            Flash::set('error', 'Numele produsului promoțional este obligatoriu (maxim 190 caractere).');
            header('Location: /admin/promo-products' . ($id > 0 ? ('?edit=' . $id) : ''));
            return;
        }
        $notes = mb_substr($notes, 0, 500);

        try {
            if ($id > 0) {
                $db->prepare('UPDATE promotional_products SET name = :name, notes = :notes, is_active = :is_active, sort_order = :sort_order WHERE id = :id LIMIT 1')
                   ->execute(['name' => $name, 'notes' => $notes !== '' ? $notes : null, 'is_active' => $isActive, 'sort_order' => $sortOrder, 'id' => $id]);
                // Propagă redenumirea la produsele deja atașate comenzilor.
                try {
                    $db->prepare('UPDATE order_promo_items SET name = :name WHERE promo_product_id = :id')
                       ->execute(['name' => $name, 'id' => $id]);
                } catch (Throwable) {
                }
                Flash::set('success', 'Produs promoțional actualizat.');
            } else {
                $db->prepare('INSERT INTO promotional_products (name, notes, is_active, sort_order) VALUES (:name, :notes, :is_active, :sort_order)')
                   ->execute(['name' => $name, 'notes' => $notes !== '' ? $notes : null, 'is_active' => $isActive, 'sort_order' => $sortOrder]);
                Flash::set('success', 'Produs promoțional adăugat.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut salva produsul promoțional.');
        }
        header('Location: /admin/promo-products');
    }

    public function promoProductDelete(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            header('Location: /admin/promo-products');
            return;
        }
        $this->ensurePromoSchema($db);
        $id = max(0, (int) ($params['id'] ?? 0));
        if ($id > 0) {
            try {
                $db->prepare('DELETE FROM promotional_products WHERE id = :id LIMIT 1')->execute(['id' => $id]);
                Flash::set('success', 'Produs promoțional șters.');
            } catch (Throwable) {
                Flash::set('error', 'Nu am putut șterge produsul.');
            }
        }
        header('Location: /admin/promo-products');
    }

    /** Salvează (înlocuiește) produsele promoționale atașate unei comenzi. Răspuns JSON. */
    public function orderPromoSave(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        header('Content-Type: application/json');
        $orderId = max(0, (int) ($params['id'] ?? 0));
        if ($orderId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'ID comandă invalid.']);
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'DB indisponibil.']);
            return;
        }
        $this->ensurePromoSchema($db);

        // Nume valide din nomenclator, pentru snapshot
        $names = [];
        foreach ($db->query('SELECT id, name FROM promotional_products')->fetchAll() ?: [] as $r) {
            $names[(int) ($r['id'] ?? 0)] = (string) ($r['name'] ?? '');
        }

        $productIds = $_POST['promo_product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        if (!is_array($productIds)) {
            $productIds = [];
        }
        if (!is_array($quantities)) {
            $quantities = [];
        }

        $rows = [];
        foreach ($productIds as $i => $pidRaw) {
            $pid = (int) $pidRaw;
            if ($pid <= 0 || !isset($names[$pid])) {
                continue;
            }
            $qty = max(1, (int) ($quantities[$i] ?? 1));
            $rows[] = ['pid' => $pid, 'name' => $names[$pid], 'qty' => $qty];
        }

        try {
            $db->beginTransaction();
            $db->prepare('DELETE FROM order_promo_items WHERE order_id = :oid')->execute(['oid' => $orderId]);
            if ($rows !== []) {
                $ins = $db->prepare('INSERT INTO order_promo_items (order_id, promo_product_id, name, quantity) VALUES (:oid, :pid, :name, :qty)');
                foreach ($rows as $r) {
                    $ins->execute(['oid' => $orderId, 'pid' => $r['pid'], 'name' => $r['name'], 'qty' => $r['qty']]);
                }
            }
            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => 'Nu am putut salva produsele promoționale.']);
            return;
        }

        echo json_encode(['ok' => true, 'items' => array_map(static fn(array $r): array => ['promo_product_id' => $r['pid'], 'name' => $r['name'], 'quantity' => $r['qty']], $rows)]);
    }

    /**
     * Editare manuală a produselor comenzii din admin: cantități + adăugare produse noi.
     * Recalculează subtotal, transport (gratuit dacă atinge pragul) și total.
     */
    /**
     * Schimbă destinația unei comenzi: livrare la un punct FANbox sau înapoi la
     * adresa clientului. Prețul transportului se recalculează, fiindcă FANbox
     * are tarif propriu.
     */
    public function orderFanboxSave(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        header('Content-Type: application/json');
        $orderId = max(0, (int) ($params['id'] ?? 0));
        $db = $this->db();
        if ($orderId <= 0 || !$db instanceof PDO) {
            echo json_encode(['ok' => false, 'error' => 'Comandă invalidă.']);
            return;
        }
        \App\Support\CheckoutCalculator::ensureOrderShippingSchema($db);

        $stmt = $db->prepare(
            'SELECT id, status, payment_status, subtotal, discount_total, loyalty_points_discount,
                    shipping_cost, billing_county, billing_city, fan_awb
             FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!is_array($order)) {
            echo json_encode(['ok' => false, 'error' => 'Comanda nu a fost găsită.']);
            return;
        }
        // Cu AWB emis, destinația e deja la curier: o schimbare aici ar rămâne
        // doar în site, iar coletul ar pleca tot unde scrie pe AWB.
        if (trim((string) ($order['fan_awb'] ?? '')) !== '') {
            echo json_encode([
                'ok' => false,
                'error' => 'Comanda are deja AWB emis. Anulează AWB-ul la FAN înainte de a schimba destinația.',
            ]);
            return;
        }

        $lockerId = max(0, (int) ($_POST['fan_locker_id'] ?? 0));
        $locker = null;
        if ($lockerId > 0) {
            $locker = \App\Support\FanLockers::dupaId($db, $lockerId);
            if ($locker === null) {
                echo json_encode(['ok' => false, 'error' => 'Punctul FANbox ales nu este disponibil.']);
                return;
            }
        }

        $settings = Settings::all($db);
        $subtotal = round((float) ($order['subtotal'] ?? 0), 2);
        $discountRef = round((float) ($order['discount_total'] ?? 0), 2)
            + round((float) ($order['loyalty_points_discount'] ?? 0), 2);

        // Transportul se ia de la zero: prețul vechi era pentru cealaltă
        // destinație, deci nu are ce să conteze aici.
        $transport = \App\Support\ShippingPricing::pret(
            $db,
            $settings,
            (string) ($order['billing_county'] ?? ''),
            (string) ($order['billing_city'] ?? ''),
            $locker !== null
        );
        if ($transport === null) {
            $transport = \App\Support\CheckoutCalculator::adminRecalcShipping(
                $settings,
                (string) ($order['billing_county'] ?? ''),
                $subtotal,
                $discountRef,
                0.0
            );
        } elseif (\App\Support\CheckoutCalculator::adminRecalcShipping(
            $settings,
            (string) ($order['billing_county'] ?? ''),
            $subtotal,
            $discountRef,
            0.0
        ) === 0.0) {
            // Pragul de transport gratuit rămâne valabil și la FANbox.
            $transport = 0.0;
        }

        $effDiscount = min($discountRef, $subtotal);
        $total = round(max(0.0, $subtotal - $effDiscount + $transport), 2);

        try {
            $db->prepare(
                'UPDATE orders SET
                    fan_locker_id = :lid, fan_locker_name = :lname, fan_locker_address = :laddr,
                    fan_locker_city = :lcity, fan_locker_county = :lcounty, fan_locker_postcode = :lzip,
                    shipping_method = :method, shipping_cost = :ship, total = :tot
                 WHERE id = :id'
            )->execute([
                'lid' => $locker !== null ? $locker['id'] : null,
                'lname' => $locker !== null ? $locker['name'] : null,
                'laddr' => $locker !== null ? $locker['address'] : null,
                'lcity' => $locker !== null ? $locker['locality'] : null,
                'lcounty' => $locker !== null ? $locker['county'] : null,
                'lzip' => $locker !== null ? ($locker['postcode'] ?? null) : null,
                'method' => $locker !== null ? 'fan_box' : 'fan_courier',
                'ship' => $transport,
                'tot' => $total,
                'id' => $orderId,
            ]);
        } catch (Throwable) {
            echo json_encode(['ok' => false, 'error' => 'Nu am putut salva destinația comenzii.']);
            return;
        }

        AdminActivityLog::log($db, 'order_delivery_change', [
            'order_id' => $orderId,
            'destinatie' => $locker !== null ? ('FANbox: ' . $locker['name']) : 'adresa clientului',
            'transport' => $transport,
        ]);

        $erpSync = null;
        if ((string) ($settings['erp_enabled'] ?? '0') === '1') {
            try {
                $erpSync = \App\Support\ErpSync::push($db, $orderId, true);
            } catch (Throwable $e) {
                $erpSync = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        echo json_encode([
            'ok' => true,
            'shipping_cost' => $transport,
            'total' => $total,
            'locker' => $locker,
            'plata_diferenta' => (string) ($order['payment_status'] ?? '') === 'paid',
            'erp' => $erpSync,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Reducerea comercială acordată manual pe o comandă venită din site
     * (negociată cu clientul după plasare). Se aplică doar la produse, nu și la
     * transport: un procent aplicat peste transport ar strica pragul de livrare
     * gratuită. Comanda deja aprobată în ERP nu se mai atinge — acolo există
     * factură, iar corecția se face prin storno.
     */
    public function orderDiscountSave(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        header('Content-Type: application/json');
        $orderId = max(0, (int) ($params['id'] ?? 0));
        $db = $this->db();
        if (!$db instanceof PDO || $orderId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Date invalide.']);
            return;
        }
        $this->ensureOptionalSchema($db);

        $stmt = $db->prepare(
            'SELECT id, order_number, status, subtotal, discount_total, loyalty_points_discount,
                    manual_discount, shipping_cost, billing_county
             FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch() ?: null;
        if (!is_array($order)) {
            echo json_encode(['ok' => false, 'error' => 'Comanda nu a fost găsită.']);
            return;
        }
        if (in_array(strtolower((string) ($order['status'] ?? '')), ['processing', 'completed', 'cancelled', 'refunded'], true)) {
            echo json_encode([
                'ok' => false,
                'error' => 'Comanda e procesată — factura există deja în ERP. Corecția se face prin storno.',
            ]);
            return;
        }

        $mod = (string) ($_POST['mode'] ?? 'procent') === 'suma' ? 'suma' : 'procent';
        $valoare = (float) str_replace(',', '.', (string) ($_POST['value'] ?? '0'));
        $motiv = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($motiv) > 190) {
            $motiv = mb_substr($motiv, 0, 190);
        }

        $subtotal = round((float) ($order['subtotal'] ?? 0), 2);
        $procent = null;
        if ($valoare <= 0) {
            $reducere = 0.0;
            $motiv = '';
        } elseif ($mod === 'procent') {
            if ($valoare > 100) {
                echo json_encode(['ok' => false, 'error' => 'Procentul nu poate depăși 100%.']);
                return;
            }
            $procent = round($valoare, 2);
            $reducere = round($subtotal * ($procent / 100), 2);
        } else {
            $reducere = round($valoare, 2);
            if ($reducere > $subtotal) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Reducerea nu poate depăși valoarea produselor (' . number_format($subtotal, 2) . ' lei).',
                ]);
                return;
            }
        }

        // Transportul se recalculează: reducerea poate coborî comanda sub pragul
        // de livrare gratuită, iar clientul trebuie să vadă suma reală.
        $settings = Settings::all($db);
        $discountRef = round((float) ($order['discount_total'] ?? 0), 2)
            + round((float) ($order['loyalty_points_discount'] ?? 0), 2)
            + $reducere;
        $shipping = \App\Support\CheckoutCalculator::adminRecalcShipping(
            $settings,
            (string) ($order['billing_county'] ?? ''),
            $subtotal,
            $discountRef,
            (float) ($order['shipping_cost'] ?? 0)
        );
        $total = round(max(0.0, $subtotal - min($discountRef, $subtotal) + $shipping), 2);

        try {
            $db->prepare(
                'UPDATE orders
                 SET manual_discount = :suma,
                     manual_discount_percent = :procent,
                     manual_discount_reason = :motiv,
                     shipping_cost = :ship,
                     total = :total
                 WHERE id = :id'
            )->execute([
                'suma' => $reducere,
                'procent' => $procent,
                'motiv' => $motiv !== '' ? $motiv : null,
                'ship' => $shipping,
                'total' => $total,
                'id' => $orderId,
            ]);
        } catch (Throwable) {
            echo json_encode(['ok' => false, 'error' => 'Nu am putut salva reducerea.']);
            return;
        }

        AdminActivityLog::log($db, $reducere > 0 ? 'comanda_reducere_comerciala' : 'comanda_reducere_anulata', [
            'comanda' => (string) ($order['order_number'] ?? $orderId),
            'reducere' => number_format($reducere, 2, '.', ''),
            'procent' => $procent !== null ? number_format($procent, 2, '.', '') : '',
            'motiv' => $motiv,
        ]);

        $erpSync = null;
        if ((string) ($settings['erp_enabled'] ?? '0') === '1') {
            try {
                $erpSync = \App\Support\ErpSync::push($db, $orderId, true);
            } catch (Throwable $e) {
                $erpSync = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        echo json_encode([
            'ok' => true,
            'manual_discount' => $reducere,
            'manual_discount_percent' => $procent,
            'manual_discount_reason' => $motiv,
            'shipping' => $shipping,
            'total' => $total,
            'erp_sync' => $erpSync,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }


    /**
     * Scrie transportul pe o comandă, la o valoare dată de operator.
     *
     * Transportul se calcula până acum doar automat (praguri, preț fix, tarif
     * FAN). Când valoarea ieșită la plasarea comenzii e greșită — comenzi vechi,
     * un tarif prins aiurea — nu exista niciun loc din care să fie corectată:
     * singura pârghie era reducerea comercială, care schimbă altceva.
     *
     * Totalul se reface după aceeași regulă ca la reducerea comercială, ca cele
     * două să nu ajungă la rezultate diferite pentru aceeași comandă.
     */
    private function seteazaTransportComanda(
        PDO $db,
        int $orderId,
        float $transport,
        array $settings
    ): array {
        $stmt = $db->prepare(
            'SELECT id, order_number, status, payment_status, paid_amount, subtotal,
                    discount_total, loyalty_points_discount, manual_discount,
                    shipping_cost, total, fan_awb
             FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch() ?: null;
        if (!is_array($order)) {
            return ['ok' => false, 'error' => 'Comanda nu a fost găsită.'];
        }

        $status = strtolower(trim((string) ($order['status'] ?? '')));
        if (in_array($status, ['cancelled', 'refunded'], true)) {
            return ['ok' => false, 'error' => 'Comanda e anulată/returnată — nu se mai atinge.'];
        }

        $transport = round(max(0.0, $transport), 2);
        $transportVechi = round((float) ($order['shipping_cost'] ?? 0), 2);
        $subtotal = round((float) ($order['subtotal'] ?? 0), 2);
        $reduceri = round((float) ($order['discount_total'] ?? 0), 2)
            + round((float) ($order['loyalty_points_discount'] ?? 0), 2)
            + round((float) ($order['manual_discount'] ?? 0), 2);
        $total = round(max(0.0, $subtotal - min($reduceri, $subtotal) + $transport), 2);
        $totalVechi = round((float) ($order['total'] ?? 0), 2);

        try {
            $db->prepare('UPDATE orders SET shipping_cost = :ship, total = :total WHERE id = :id')
               ->execute(['ship' => $transport, 'total' => $total, 'id' => $orderId]);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Nu am putut salva transportul.'];
        }

        AdminActivityLog::log($db, 'comanda_transport_corectat', [
            'comanda' => (string) ($order['order_number'] ?? $orderId),
            'transport_vechi' => number_format($transportVechi, 2, '.', ''),
            'transport_nou' => number_format($transport, 2, '.', ''),
            'total_vechi' => number_format($totalVechi, 2, '.', ''),
            'total_nou' => number_format($total, 2, '.', ''),
        ]);

        // Comanda aprobată în ERP are deja factură: ERP-ul refuză modificarea,
        // așa că nici nu o mai încercăm — dar spunem limpede ce a rămas de făcut.
        $erpSync = null;
        $erpNota = '';
        if ((string) ($settings['erp_enabled'] ?? '0') === '1') {
            if (in_array($status, ['processing', 'completed'], true)) {
                $erpNota = 'Comanda e deja procesată în ERP: acolo corectează separat, pe factură.';
            } else {
                try {
                    $erpSync = \App\Support\ErpSync::push($db, $orderId, true);
                } catch (Throwable $e) {
                    $erpSync = ['ok' => false, 'message' => $e->getMessage()];
                }
            }
        }

        // AWB-ul deja emis păstrează rambursul vechi: curierul ar încasa suma
        // dinainte de corecție, deci diferența trebuie rezolvată la FAN.
        $awb = trim((string) ($order['fan_awb'] ?? ''));
        $avertismente = [];
        if ($awb !== '' && abs($total - $totalVechi) > 0.009) {
            $avertismente[] = 'AWB-ul ' . $awb . ' a fost deja generat cu totalul vechi ('
                . number_format($totalVechi, 2) . ' lei). Dacă plata e la livrare, rambursul de pe AWB'
                . ' NU se schimbă singur — anulează și regenerează AWB-ul sau anunță FAN.';
        }
        $incasat = $order['paid_amount'] ?? null;
        $incasat = ($incasat === null || $incasat === '') ? null : round((float) $incasat, 2);
        if (strtolower((string) ($order['payment_status'] ?? '')) === 'paid'
            && $incasat !== null
            && $incasat - $total > 0.009
        ) {
            $avertismente[] = 'Clientul a plătit ' . number_format($incasat, 2)
                . ' lei, iar totalul nou e ' . number_format($total, 2)
                . ' lei. Diferența de ' . number_format($incasat - $total, 2) . ' lei i se cuvine înapoi.';
        }

        return [
            'ok' => true,
            'shipping' => $transport,
            'shipping_old' => $transportVechi,
            'total' => $total,
            'total_old' => $totalVechi,
            'erp_sync' => $erpSync,
            'erp_note' => $erpNota,
            'warnings' => $avertismente,
        ];
    }

    /** Corectarea transportului pe o singură comandă (din fereastra comenzii). */
    public function orderShippingSave(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        header('Content-Type: application/json');
        $orderId = max(0, (int) ($params['id'] ?? 0));
        $db = $this->db();
        if (!$db instanceof PDO || $orderId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Date invalide.']);
            return;
        }
        $this->ensureOptionalSchema($db);

        $brut = trim((string) ($_POST['value'] ?? ''));
        if ($brut === '' || !is_numeric(str_replace(',', '.', $brut))) {
            echo json_encode(['ok' => false, 'error' => 'Scrie o valoare numerică pentru transport.']);
            return;
        }
        $transport = (float) str_replace(',', '.', $brut);
        if ($transport < 0) {
            echo json_encode(['ok' => false, 'error' => 'Transportul nu poate fi negativ.']);
            return;
        }
        if ($transport > 10000) {
            echo json_encode(['ok' => false, 'error' => 'Valoare prea mare pentru transport.']);
            return;
        }

        $rezultat = $this->seteazaTransportComanda($db, $orderId, $transport, Settings::all($db));
        echo json_encode($rezultat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function orderItemsSave(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        header('Content-Type: application/json');
        $orderId = max(0, (int) ($params['id'] ?? 0));
        $db = $this->db();
        if (!$db instanceof PDO || $orderId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Date invalide.']);
            return;
        }
        \App\Support\CheckoutCalculator::ensureOrderShippingSchema($db);

        \App\Support\ErpSync::ensureSchema($db);
        $os = $db->prepare('SELECT id, status, payment_status, paid_amount, total, subtotal, discount_total, loyalty_points_discount, manual_discount, manual_discount_percent, shipping_cost, billing_county FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $os->execute(['id' => $orderId]);
        $order = $os->fetch();
        if (!is_array($order)) {
            echo json_encode(['ok' => false, 'error' => 'Comanda nu există.']);
            return;
        }
        // După aprobare/facturare legătura cu ERP-ul se rupe: comanda nu se mai
        // modifică nici pe site (ERP-ul ar refuza oricum propagarea).
        $statusComanda = strtolower(trim((string) ($order['status'] ?? '')));
        if (in_array($statusComanda, ['processing', 'completed', 'cancelled', 'refunded'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Comanda procesată nu mai poate fi modificată. Factura există deja în ERP.']);
            return;
        }

        $pids = is_array($_POST['product_id'] ?? null) ? $_POST['product_id'] : [];
        $qtys = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];

        // Prețuri unitare existente (păstrăm prețul istoric pentru produsele deja din comandă).
        $existing = [];
        $es = $db->prepare('SELECT product_id, unit_price FROM order_items WHERE order_id = :id');
        $es->execute(['id' => $orderId]);
        foreach ($es->fetchAll() ?: [] as $r) {
            $existing[(int) ($r['product_id'] ?? 0)] = (float) ($r['unit_price'] ?? 0);
        }

        $wantIds = [];
        foreach ($pids as $p) {
            $pid = (int) $p;
            if ($pid > 0) {
                $wantIds[$pid] = true;
            }
        }
        $products = [];
        if ($wantIds !== []) {
            $in = implode(',', array_map('intval', array_keys($wantIds)));
            foreach ($db->query('SELECT id, name, price, sale_price FROM products WHERE id IN (' . $in . ')')->fetchAll() ?: [] as $r) {
                $products[(int) ($r['id'] ?? 0)] = $r;
            }
        }

        $lines = [];
        $subtotal = 0.0;
        foreach ($pids as $i => $pRaw) {
            $pid = (int) $pRaw;
            $qty = max(0, (int) ($qtys[$i] ?? 0));
            if ($pid <= 0 || $qty <= 0 || !isset($products[$pid])) {
                continue;
            }
            $prod = $products[$pid];
            $unit = $existing[$pid] ?? 0.0;
            if ($unit <= 0.0) {
                $price = (float) ($prod['price'] ?? 0);
                $sale = (float) ($prod['sale_price'] ?? 0);
                $unit = ($sale > 0 && $sale < $price) ? $sale : $price;
            }
            $lineTotal = round($unit * $qty, 2);
            $subtotal += $lineTotal;
            $lines[] = ['pid' => $pid, 'name' => (string) ($prod['name'] ?? ''), 'qty' => $qty, 'unit' => round($unit, 2), 'total' => $lineTotal];
        }

        if ($lines === []) {
            echo json_encode(['ok' => false, 'error' => 'Comanda trebuie să conțină cel puțin un produs.']);
            return;
        }
        $subtotal = round($subtotal, 2);

        $settings = Settings::all($db);
        $discountTotal = round((float) ($order['discount_total'] ?? 0), 2);
        $pointsDiscount = round((float) ($order['loyalty_points_discount'] ?? 0), 2);
        // Reducerea comercială dată în procent se recalculează pe noul subtotal:
        // altfel, după ce se adaugă un produs, procentul promis clientului n-ar
        // mai corespunde sumei scăzute.
        $manualPercent = $order['manual_discount_percent'] ?? null;
        $manualDiscount = round((float) ($order['manual_discount'] ?? 0), 2);
        if ($manualPercent !== null && $manualPercent !== '' && (float) $manualPercent > 0) {
            $manualDiscount = round($subtotal * ((float) $manualPercent / 100), 2);
        }
        $manualDiscount = min($manualDiscount, $subtotal);
        $discountRef = $discountTotal + $pointsDiscount + $manualDiscount;
        $shipping = \App\Support\CheckoutCalculator::adminRecalcShipping(
            $settings,
            (string) ($order['billing_county'] ?? ''),
            $subtotal,
            $discountRef,
            (float) ($order['shipping_cost'] ?? 0)
        );
        $effDiscount = min($discountRef, $subtotal);
        $total = round(max(0.0, $subtotal - $effDiscount + $shipping), 2);

        try {
            $db->beginTransaction();
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $orderId]);
            $ins = $db->prepare('INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, line_total) VALUES (:oid, :pid, :name, :qty, :unit, :total)');
            foreach ($lines as $ln) {
                $ins->execute(['oid' => $orderId, 'pid' => $ln['pid'], 'name' => $ln['name'], 'qty' => $ln['qty'], 'unit' => $ln['unit'], 'total' => $ln['total']]);
            }
            $db->prepare('UPDATE orders SET subtotal = :sub, shipping_cost = :ship, total = :tot, manual_discount = :man WHERE id = :id')
               ->execute(['sub' => $subtotal, 'ship' => $shipping, 'tot' => $total, 'man' => $manualDiscount, 'id' => $orderId]);
            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => 'Nu am putut salva produsele comenzii.']);
            return;
        }

        // Modificarea se propagă imediat în ERP; dacă ERP-ul nu răspunde acum,
        // comanda rămâne marcată pentru reîncercare și o preia cronul.
        $erpSync = null;
        if ((string) ($settings['erp_enabled'] ?? '0') === '1') {
            try {
                $erpSync = \App\Support\ErpSync::push($db, $orderId, true);
            } catch (Throwable $e) {
                $erpSync = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        // Comandă plătită cu cardul, al cărei total a crescut: diferența rămâne
        // de încasat. O spunem explicit, ca să nu pară achitată integral.
        $diferentaDeIncasat = 0.0;
        if (strtolower((string) ($order['payment_status'] ?? '')) === 'paid') {
            $incasat = $order['paid_amount'] !== null && $order['paid_amount'] !== ''
                ? round((float) $order['paid_amount'], 2)
                : round((float) ($order['total'] ?? 0), 2);
            $diferentaDeIncasat = round(max(0.0, $total - $incasat), 2);
        }

        echo json_encode([
            'ok' => true,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'total' => $total,
            'discount_total' => $discountTotal,
            'loyalty_points_discount' => $pointsDiscount,
            'manual_discount' => $manualDiscount,
            'erp_sync' => $erpSync,
            'rest_de_incasat' => $diferentaDeIncasat,
            'items' => array_map(static fn(array $l): array => [
                'product_id' => $l['pid'],
                'product_name' => $l['name'],
                'quantity' => $l['qty'],
                'unit_price' => $l['unit'],
                'line_total' => $l['total'],
            ], $lines),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Trimite clientului un link de plată pentru diferența rămasă pe comandă
     * (produse adăugate după ce a plătit deja cu cardul).
     */
    public function orderPaymentLinkSend(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        header('Content-Type: application/json');
        $orderId = max(0, (int) ($params['id'] ?? 0));
        $db = $this->db();
        if (!$db instanceof PDO || $orderId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Date invalide.']);
            return;
        }
        \App\Support\ErpSync::ensureSchema($db);

        $stmt = $db->prepare(
            'SELECT id, order_number, total, paid_amount, payment_status, status,
                    billing_first_name, billing_last_name, billing_email
             FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!is_array($order)) {
            echo json_encode(['ok' => false, 'error' => 'Comanda nu există.']);
            return;
        }

        $rest = \App\Support\PaymentLink::restDeIncasat($order);
        if ($rest <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Comanda nu are diferență de încasat.']);
            return;
        }

        $settings = Settings::all($db);
        if ((string) ($settings['euplatesc_enabled'] ?? '0') !== '1') {
            echo json_encode(['ok' => false, 'error' => 'Plata cu cardul (EuPlătesc) nu este activată în Setări plăți.']);
            return;
        }

        $email = trim((string) ($order['billing_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Comanda nu are o adresă de email validă.']);
            return;
        }

        $link = \App\Support\PaymentLink::creeaza(
            $db,
            $orderId,
            (string) ($order['order_number'] ?? ''),
            $rest
        );
        if ($link === null) {
            echo json_encode(['ok' => false, 'error' => 'Nu am putut genera linkul de plată.']);
            return;
        }

        $url = \App\Support\AppUrl::absolut('/plata/' . rawurlencode((string) $link['token']));
        $numeClient = trim((string) ($order['billing_first_name'] ?? '') . ' ' . (string) ($order['billing_last_name'] ?? ''));
        $numarComanda = (string) ($order['order_number'] ?? '');
        $magazin = trim((string) ($settings['order_email_from_name'] ?? 'Bioscem')) ?: 'Bioscem';

        $html = '<p>Bună' . ($numeClient !== '' ? ' ' . htmlspecialchars($numeClient, ENT_QUOTES) : '') . ',</p>'
            . '<p>Pentru comanda <strong>' . htmlspecialchars($numarComanda, ENT_QUOTES) . '</strong> a rămas de achitat suma de <strong>'
            . number_format($rest, 2, ',', '.') . ' lei</strong>, în urma produselor adăugate la cererea ta.</p>'
            . '<p style="margin:22px 0;"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '"'
            . ' style="display:inline-block;padding:13px 24px;border-radius:10px;background:#2f8d5b;color:#ffffff;text-decoration:none;font-weight:700;">'
            . 'Plătește ' . number_format($rest, 2, ',', '.') . ' lei</a></p>'
            . '<p style="color:#64748b;font-size:14px;">Dacă butonul nu funcționează, deschide acest link:<br>'
            . htmlspecialchars($url, ENT_QUOTES) . '</p>'
            . '<p style="color:#64748b;font-size:14px;">Plata se face securizat, cu cardul. Linkul este valabil 30 de zile.</p>'
            . '<p>Mulțumim,<br>Echipa ' . htmlspecialchars($magazin, ENT_QUOTES) . '</p>';

        try {
            OrderMailer::sendCustom(
                $email,
                'Diferență de plată pentru comanda ' . $numarComanda,
                $html,
                $settings,
                $db,
                [
                    'email_type' => 'payment_link',
                    'source' => 'admin_orders',
                    'trigger' => 'payment_link_send',
                    'order_id' => $orderId,
                ]
            );
        } catch (Throwable $e) {
            // Linkul rămâne valid; îl poate copia manual din răspuns.
            echo json_encode([
                'ok' => false,
                'error' => 'Linkul a fost creat, dar emailul nu a plecat: ' . $e->getMessage(),
                'url' => $url,
                'suma' => $rest,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        echo json_encode([
            'ok' => true,
            'url' => $url,
            'suma' => $rest,
            'email' => $email,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function promoFmtDate(string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return '-';
        }
        $ts = strtotime($v);
        return $ts !== false ? date('d.m.Y H:i', $ts) : $v;
    }

    /** Căutare AJAX „Promoționale per client" — întoarce comenzile clientului cu produsele primite. */
    public function promoClientSearchApi(): void
    {
        if (!$this->guard()) {
            return;
        }
        header('Content-Type: application/json');
        $db = $this->db();
        if (!$db instanceof PDO) {
            echo json_encode(['ok' => false, 'error' => 'DB indisponibil.']);
            return;
        }
        $this->ensurePromoSchema($db);
        $q = trim((string) ($_GET['client'] ?? ''));
        if ($q === '') {
            echo json_encode(['ok' => true, 'results' => []]);
            return;
        }
        try {
            $stmt = $db->prepare(
                "SELECT o.id, o.order_number, o.created_at, o.billing_first_name, o.billing_last_name, o.billing_email,
                        pi.name AS promo_name, pi.quantity AS promo_qty
                 FROM order_promo_items pi
                 JOIN orders o ON o.id = pi.order_id
                 WHERE o.deleted_at IS NULL
                   AND (CONCAT(COALESCE(o.billing_first_name, ''), ' ', COALESCE(o.billing_last_name, '')) LIKE :c
                        OR CONCAT(COALESCE(o.billing_last_name, ''), ' ', COALESCE(o.billing_first_name, '')) LIKE :c
                        OR o.billing_email LIKE :c OR o.billing_phone LIKE :c OR o.order_number LIKE :c)
                 ORDER BY o.created_at DESC, pi.id ASC
                 LIMIT 500"
            );
            $stmt->execute(['c' => '%' . $q . '%']);
            $grouped = [];
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $oid = (int) ($row['id'] ?? 0);
                if (!isset($grouped[$oid])) {
                    $name = trim((string) ($row['billing_first_name'] ?? '') . ' ' . (string) ($row['billing_last_name'] ?? ''));
                    $grouped[$oid] = [
                        'order_number' => (string) ($row['order_number'] ?? ''),
                        'created_at' => $this->promoFmtDate((string) ($row['created_at'] ?? '')),
                        'client_name' => $name !== '' ? $name : 'Client',
                        'email' => (string) ($row['billing_email'] ?? ''),
                        'items' => [],
                    ];
                }
                $grouped[$oid]['items'][] = ['name' => (string) ($row['promo_name'] ?? ''), 'quantity' => (int) ($row['promo_qty'] ?? 1)];
            }
            echo json_encode(['ok' => true, 'results' => array_values($grouped)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            echo json_encode(['ok' => false, 'error' => 'Eroare la căutare.']);
        }
    }

    /** Toate produsele promoționale primite de clientul unei comenzi (agregat pe toate comenzile lui). */
    public function orderClientPromo(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        header('Content-Type: application/json');
        $orderId = max(0, (int) ($params['id'] ?? 0));
        $db = $this->db();
        if (!$db instanceof PDO || $orderId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Date invalide.']);
            return;
        }
        $this->ensurePromoSchema($db);

        $os = $db->prepare('SELECT billing_first_name, billing_last_name, billing_email FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $os->execute(['id' => $orderId]);
        $o = $os->fetch();
        if (!is_array($o)) {
            echo json_encode(['ok' => false, 'error' => 'Comandă negăsită.']);
            return;
        }
        $email = trim((string) ($o['billing_email'] ?? ''));
        $name = trim((string) ($o['billing_first_name'] ?? '') . ' ' . (string) ($o['billing_last_name'] ?? ''));
        if ($email !== '') {
            $cond = 'o.billing_email = :m';
            $m = $email;
        } else {
            $cond = "CONCAT(o.billing_first_name, ' ', o.billing_last_name) = :m";
            $m = $name;
        }
        if (trim((string) $m) === '') {
            echo json_encode(['ok' => false, 'error' => 'Client necunoscut pentru această comandă.']);
            return;
        }

        try {
            $ag = $db->prepare("SELECT pi.name AS name, SUM(pi.quantity) AS total
                                FROM order_promo_items pi JOIN orders o ON o.id = pi.order_id
                                WHERE o.deleted_at IS NULL AND $cond
                                GROUP BY pi.name ORDER BY total DESC");
            $ag->execute(['m' => $m]);
            $items = array_map(static fn(array $r): array => ['name' => (string) ($r['name'] ?? ''), 'quantity' => (int) ($r['total'] ?? 0)], $ag->fetchAll() ?: []);

            $br = $db->prepare("SELECT o.order_number, o.created_at, pi.name, pi.quantity
                                FROM order_promo_items pi JOIN orders o ON o.id = pi.order_id
                                WHERE o.deleted_at IS NULL AND $cond
                                ORDER BY o.created_at DESC, pi.id ASC LIMIT 500");
            $br->execute(['m' => $m]);
            $ordersMap = [];
            foreach ($br->fetchAll() ?: [] as $r) {
                $on = (string) ($r['order_number'] ?? '');
                if (!isset($ordersMap[$on])) {
                    $ordersMap[$on] = ['order_number' => $on, 'created_at' => $this->promoFmtDate((string) ($r['created_at'] ?? '')), 'items' => []];
                }
                $ordersMap[$on]['items'][] = ['name' => (string) ($r['name'] ?? ''), 'quantity' => (int) ($r['quantity'] ?? 1)];
            }

            echo json_encode([
                'ok' => true,
                'client_name' => $name !== '' ? $name : 'Client',
                'email' => $email,
                'items' => $items,
                'orders' => array_values($ordersMap),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            echo json_encode(['ok' => false, 'error' => 'Eroare la interogare.']);
        }
    }

    /** Cui a fost dat un produs promoțional — comenzile/clienții care l-au primit. */
    public function promoProductRecipientsApi(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        header('Content-Type: application/json');
        $id = max(0, (int) ($params['id'] ?? 0));
        $db = $this->db();
        if (!$db instanceof PDO || $id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Date invalide.']);
            return;
        }
        $this->ensurePromoSchema($db);

        $ps = $db->prepare('SELECT name FROM promotional_products WHERE id = :id LIMIT 1');
        $ps->execute(['id' => $id]);
        $name = (string) ($ps->fetchColumn() ?: '');

        try {
            $stmt = $db->prepare(
                "SELECT o.order_number, o.created_at, o.billing_first_name, o.billing_last_name, o.billing_email, pi.quantity
                 FROM order_promo_items pi
                 JOIN orders o ON o.id = pi.order_id
                 WHERE o.deleted_at IS NULL AND pi.promo_product_id = :id
                 ORDER BY o.created_at DESC, pi.id ASC
                 LIMIT 500"
            );
            $stmt->execute(['id' => $id]);
            $recipients = [];
            $total = 0;
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $cn = trim((string) ($r['billing_first_name'] ?? '') . ' ' . (string) ($r['billing_last_name'] ?? ''));
                $qty = (int) ($r['quantity'] ?? 1);
                $total += $qty;
                $recipients[] = [
                    'order_number' => (string) ($r['order_number'] ?? ''),
                    'created_at' => $this->promoFmtDate((string) ($r['created_at'] ?? '')),
                    'client_name' => $cn !== '' ? $cn : 'Client',
                    'email' => (string) ($r['billing_email'] ?? ''),
                    'quantity' => $qty,
                ];
            }
            echo json_encode(['ok' => true, 'name' => $name, 'total' => $total, 'recipients' => $recipients], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            echo json_encode(['ok' => false, 'error' => 'Eroare la interogare.']);
        }
    }

    public function ordersBulkAction(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/orders');
            return;
        }
        $this->ensureOptionalSchema($db);

        $backUrl = $this->safeOrdersBackUrl((string) ($_POST['back_url'] ?? ''), array_merge($_GET, $_POST));
        $action = trim((string) ($_POST['bulk_action'] ?? ''));

        $rawIds = $_POST['order_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $orderIds = [];
        foreach ($rawIds as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0 || isset($orderIds[$id])) {
                continue;
            }
            $orderIds[$id] = $id;
        }
        $orderIds = array_values($orderIds);

        if ($orderIds === []) {
            Flash::set('error', 'Selectează cel puțin o comandă.');
            header('Location: ' . $backUrl);
            return;
        }

        if ($action === 'delete') {
            $stmt = $db->prepare('UPDATE orders SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $deleted = 0;
            $sterse = [];
            foreach ($orderIds as $orderId) {
                $rezumat = $this->rezumatComandaPentruJurnal($db, $orderId);
                $stmt->execute(['id' => $orderId]);
                if ($stmt->rowCount() > 0) {
                    $deleted++;
                    $sterse[] = ($rezumat['comanda'] ?? (string) $orderId)
                        . (isset($rezumat['client']) ? ' (' . $rezumat['client'] . ')' : '');
                }
            }
            if ($deleted > 0) {
                AdminActivityLog::log($db, 'comenzi_sterse_in_masa', [
                    'numar' => (string) $deleted,
                    'comenzi' => $sterse,
                ]);
                Flash::set('success', 'Comenzi mutate în coș: ' . $deleted . '.');
            } else {
                Flash::set('error', 'Nu s-au putut muta comenzile selectate în coș.');
            }
            header('Location: ' . $backUrl);
            return;
        }

        if ($action === 'status') {
            $status = trim((string) ($_POST['bulk_status'] ?? ''));
            if (!in_array($status, self::ORDER_ALLOWED_STATUSES, true)) {
                Flash::set('error', 'Selectează un status valid pentru actualizarea în masă.');
                header('Location: ' . $backUrl);
                return;
            }
            $updated = 0;
            $failed = 0;
            foreach ($orderIds as $orderId) {
                $result = $this->updateOrderStatusInternal($db, $orderId, $status);
                if (($result['ok'] ?? false) === true) {
                    $updated++;
                } else {
                    $failed++;
                }
            }
            $message = 'Status actualizat pentru ' . $updated . ' comenzi.';
            if ($failed > 0) {
                $message .= ' Eșecuri: ' . $failed . '.';
            }
            Flash::set($updated > 0 ? 'success' : 'error', $message);
            header('Location: ' . $backUrl);
            return;
        }

        if ($action === 'awb') {
            $generated = 0;
            $failed = 0;
            $errors = [];
            foreach ($orderIds as $orderId) {
                $result = $this->createFanAwbInternal($db, $orderId);
                if (($result['ok'] ?? false) === true) {
                    $generated++;
                    continue;
                }
                $failed++;
                $message = trim((string) ($result['message'] ?? ''));
                if ($message !== '' && count($errors) < 5) {
                    $errors[] = '#' . $orderId . ': ' . $message;
                }
            }
            if ($generated > 0) {
                $message = 'AWB generate: ' . $generated . '.';
                if ($failed > 0) {
                    $message .= ' Eșecuri: ' . $failed . '.';
                }
                if ($errors !== []) {
                    $message .= ' Detalii: ' . implode(' | ', $errors);
                }
                Flash::set('success', $message);
            } else {
                $message = 'Nu s-a generat niciun AWB.';
                if ($errors !== []) {
                    $message .= ' ' . implode(' | ', $errors);
                }
                Flash::set('error', $message);
            }
            header('Location: ' . $backUrl);
            return;
        }

        if ($action === 'shipping') {
            // Corecția de transport vine, de regulă, pe un pachet de comenzi
            // vechi cu același tarif greșit; una câte una ar fi o seară pierdută.
            $brut = trim((string) ($_POST['bulk_shipping_value'] ?? ''));
            if ($brut === '' || !is_numeric(str_replace(',', '.', $brut))) {
                Flash::set('error', 'Scrie valoarea transportului (ex. 30).');
                header('Location: ' . $backUrl);
                return;
            }
            $transport = (float) str_replace(',', '.', $brut);
            if ($transport < 0 || $transport > 10000) {
                Flash::set('error', 'Valoare de transport în afara limitelor.');
                header('Location: ' . $backUrl);
                return;
            }

            $settings = Settings::all($db);
            $actualizate = 0;
            $erori = [];
            $atentionari = [];
            foreach ($orderIds as $orderId) {
                $rezultat = $this->seteazaTransportComanda($db, $orderId, $transport, $settings);
                if (!empty($rezultat['ok'])) {
                    $actualizate++;
                    foreach ((array) ($rezultat['warnings'] ?? []) as $avertisment) {
                        $atentionari[] = (string) $avertisment;
                    }
                    if (trim((string) ($rezultat['erp_note'] ?? '')) !== '') {
                        $atentionari[] = (string) $rezultat['erp_note'];
                    }
                } else {
                    $erori[] = '#' . $orderId . ': ' . (string) ($rezultat['error'] ?? 'eroare');
                }
            }

            if ($actualizate > 0) {
                $mesaj = 'Transport pus pe ' . number_format($transport, 2) . ' lei la '
                    . $actualizate . ' ' . ($actualizate === 1 ? 'comandă' : 'comenzi') . '.';
                if ($erori !== []) {
                    $mesaj .= ' Nemodificate: ' . implode('; ', array_slice($erori, 0, 5)) . '.';
                }
                // Rambursul de pe AWB și banii de dat înapoi nu se rezolvă
                // singuri: dacă nu sunt spuse aici, se pierd.
                if ($atentionari !== []) {
                    $atentionari = array_values(array_unique($atentionari));
                    $mesaj .= ' De verificat: ' . implode(' ', array_slice($atentionari, 0, 5));
                    if (count($atentionari) > 5) {
                        $mesaj .= ' (și încă ' . (count($atentionari) - 5) . ')';
                    }
                }
                Flash::set('success', $mesaj);
            } else {
                Flash::set('error', 'Nicio comandă modificată. ' . implode('; ', array_slice($erori, 0, 5)));
            }
            header('Location: ' . $backUrl);
            return;
        }

        Flash::set('error', 'Acțiune bulk invalidă.');
        header('Location: ' . $backUrl);
    }

    public function ordersExport(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/orders');
            return;
        }
        $this->ensureOptionalSchema($db);

        $filters = $this->ordersFiltersFromInput($_GET);
        $where = ['deleted_at IS NULL'];
        $params = [];
        if ($filters['q'] !== '') {
            $where[] = '(order_number LIKE :q OR billing_first_name LIKE :q OR billing_last_name LIKE :q OR billing_email LIKE :q OR billing_phone LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if ($filters['status'] !== '') {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if ($filters['payment_method'] === 'card') {
            $where[] = "payment_method IN ('euplatesc', 'stripe', 'card', 'bank_transfer')";
        } elseif ($filters['payment_method'] === 'cod') {
            $where[] = "payment_method = 'cod'";
        }
        if ($filters['payment_status'] !== '') {
            $where[] = 'payment_status = :payment_status';
            $params['payment_status'] = $filters['payment_status'];
        }
        if ($filters['from_date'] !== '') {
            $where[] = 'created_at >= :from_date';
            $params['from_date'] = $filters['from_date'] . ' 00:00:00';
        }
        if ($filters['to_date'] !== '') {
            $where[] = 'created_at <= :to_date';
            $params['to_date'] = $filters['to_date'] . ' 23:59:59';
        }

        $orderBySql = $this->ordersSortSql($filters['sort_by'], $filters['sort_dir']);
        $stmt = $db->prepare(
            'SELECT id, order_number, status, payment_method, payment_status, total, subtotal, shipping_cost, discount_total,
                    coupon_code, ad_source, ad_click_id, fan_awb, created_at,
                    billing_first_name, billing_last_name, billing_email, billing_phone,
                    billing_address_line1, billing_address_line2, billing_city, billing_county, billing_postcode,
                    billing_is_company, billing_company_name, billing_company_tax_id, notes
             FROM orders
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $orderBySql . '
             LIMIT 5000'
        );
        $stmt->execute($params);
        $orders = $stmt->fetchAll() ?: [];

        // Product list per order.
        $itemsMap = [];
        if ($orders !== []) {
            $ids = array_map(static fn (array $o): int => (int) $o['id'], $orders);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $itemsStmt = $db->prepare(
                "SELECT order_id, product_name, quantity, unit_price FROM order_items WHERE order_id IN ($ph) ORDER BY id ASC"
            );
            $itemsStmt->execute($ids);
            foreach (($itemsStmt->fetchAll() ?: []) as $it) {
                $oid = (int) ($it['order_id'] ?? 0);
                $itemsMap[$oid][] = sprintf(
                    '%s x%d (%s lei)',
                    (string) ($it['product_name'] ?? ''),
                    max(1, (int) ($it['quantity'] ?? 1)),
                    number_format((float) ($it['unit_price'] ?? 0), 2, '.', '')
                );
            }
        }

        $statusLabels = [
            'pending' => 'În așteptare', 'pending_payment' => 'Plată în așteptare',
            'processing' => 'În procesare', 'completed' => 'Finalizată',
            'cancelled' => 'Anulată', 'refunded' => 'Rambursată', 'failed' => 'Eșuată',
        ];
        $paymentMethodLabels = ['cod' => 'Ramburs', 'euplatesc' => 'Card', 'stripe' => 'Card', 'card' => 'Card', 'bank_transfer' => 'Card'];
        $paymentStatusLabels = ['paid' => 'Plătit', 'unpaid' => 'Neplătit', 'failed' => 'Eșuat', 'pending' => 'În așteptare'];

        $filename = 'comenzi-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel shows Romanian characters correctly. We do NOT emit a
        // "sep=" line because it makes Excel ignore the BOM and break diacritics;
        // instead we use the comma delimiter that Excel splits on by default.
        fwrite($out, "\xEF\xBB\xBF");

        $headerRow = [
            'Nr. comandă', 'Data', 'Client', 'Email', 'Telefon',
            'Adresă', 'Oraș', 'Județ', 'Cod poștal', 'Firmă', 'CUI',
            'Produse', 'Subtotal', 'Livrare', 'Discount', 'Total (RON)',
            'Cupon', 'Status', 'Status plată', 'Metodă plată', 'AWB', 'Sursă', 'Note',
        ];
        fputcsv($out, $headerRow, ',');

        foreach ($orders as $o) {
            $oid = (int) ($o['id'] ?? 0);
            $method = strtolower((string) ($o['payment_method'] ?? ''));
            $pstatus = strtolower((string) ($o['payment_status'] ?? ''));
            $adSource = trim((string) ($o['ad_source'] ?? '')) !== '' ? 'Google Ads' : '';
            $address = trim((string) ($o['billing_address_line1'] ?? '') . ' ' . (string) ($o['billing_address_line2'] ?? ''));
            fputcsv($out, [
                (string) ($o['order_number'] ?? ''),
                (string) ($o['created_at'] ?? ''),
                trim((string) ($o['billing_first_name'] ?? '') . ' ' . (string) ($o['billing_last_name'] ?? '')),
                (string) ($o['billing_email'] ?? ''),
                (string) ($o['billing_phone'] ?? ''),
                $address,
                (string) ($o['billing_city'] ?? ''),
                (string) ($o['billing_county'] ?? ''),
                (string) ($o['billing_postcode'] ?? ''),
                (int) ($o['billing_is_company'] ?? 0) === 1 ? (string) ($o['billing_company_name'] ?? '') : '',
                (int) ($o['billing_is_company'] ?? 0) === 1 ? (string) ($o['billing_company_tax_id'] ?? '') : '',
                implode(' | ', $itemsMap[$oid] ?? []),
                number_format((float) ($o['subtotal'] ?? 0), 2, '.', ''),
                number_format((float) ($o['shipping_cost'] ?? 0), 2, '.', ''),
                number_format((float) ($o['discount_total'] ?? 0), 2, '.', ''),
                number_format((float) ($o['total'] ?? 0), 2, '.', ''),
                (string) ($o['coupon_code'] ?? ''),
                (string) ($statusLabels[strtolower((string) ($o['status'] ?? ''))] ?? (string) ($o['status'] ?? '')),
                (string) ($paymentStatusLabels[$pstatus] ?? $pstatus),
                (string) ($paymentMethodLabels[$method] ?? $method),
                (string) ($o['fan_awb'] ?? ''),
                $adSource,
                trim((string) ($o['notes'] ?? '')),
            ], ',');
        }
        fclose($out);
    }

    /**
     * Export CSV cu produsele magazinului, pentru maparea pe SKU în ERP
     * (ANDAXI — „Produse → Import din site").
     *
     * Antetul folosește chei ASCII stabile: importatorul din ERP se leagă de
     * numele coloanelor, nu de poziția lor, deci ordinea poate fi schimbată
     * fără să strice importul.
     */
    public function productsExport(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/products');
            return;
        }
        $this->ensureOptionalSchema($db);
        \App\Support\CheckoutCalculator::ensureProductVatSchema($db);

        $sqlBase = 'SELECT p.id, p.sku, p.name, p.slug, p.price, %s, p.stock, p.out_of_stock,
                           p.weight_grams, p.is_active, p.category, c.name AS category_name
                    FROM products p
                    LEFT JOIN product_categories c ON c.id = p.category_id
                    WHERE p.deleted_at IS NULL
                    ORDER BY p.name ASC';

        $products = [];
        try {
            $rows = $db->query(sprintf($sqlBase, 'p.vat_percent, p.vat_included'))->fetchAll();
            $products = is_array($rows) ? $rows : [];
        } catch (Throwable) {
            // Schema mai veche, fără coloanele de TVA pe produs.
            try {
                $rows = $db->query(sprintf($sqlBase, '19.00 AS vat_percent, 1 AS vat_included'))->fetchAll();
                $products = is_array($rows) ? $rows : [];
            } catch (Throwable) {
                $products = [];
            }
        }

        AdminActivityLog::log($db, 'products_export', ['count' => count($products)]);

        $filename = 'produse-site-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // BOM UTF-8 ca Excel să afișeze corect diacriticele.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'sku', 'denumire', 'pret_fara_tva', 'pret_site', 'tva_inclus', 'cota_tva',
            'um', 'categorie', 'stoc', 'greutate_g', 'activ', 'slug', 'id_site',
        ], ',');

        foreach ($products as $p) {
            $vatPercent = (float) ($p['vat_percent'] ?? 19);
            $vatIncluded = ((int) ($p['vat_included'] ?? 1)) === 1;
            $priceSite = (float) ($p['price'] ?? 0);
            // ERP ține prețul de nomenclator fără TVA (intră direct în „preț unitar"
            // pe linia de factură, care se impozitează după aceea).
            $priceNet = ($vatIncluded && $vatPercent > -100)
                ? $priceSite / (1 + ($vatPercent / 100))
                : $priceSite;

            fputcsv($out, [
                trim((string) ($p['sku'] ?? '')),
                trim((string) ($p['name'] ?? '')),
                number_format($priceNet, 4, '.', ''),
                number_format($priceSite, 2, '.', ''),
                $vatIncluded ? '1' : '0',
                number_format($vatPercent, 2, '.', ''),
                'buc',
                trim((string) ($p['category_name'] ?: $p['category'] ?: '')),
                (string) (int) ($p['stock'] ?? 0),
                ($p['weight_grams'] === null || $p['weight_grams'] === '') ? '' : (string) (int) $p['weight_grams'],
                ((int) ($p['is_active'] ?? 1)) === 1 ? '1' : '0',
                trim((string) ($p['slug'] ?? '')),
                (string) (int) ($p['id'] ?? 0),
            ], ',');
        }
        fclose($out);
    }

    private function ordersFiltersFromInput(array $input): array
    {
        $q = trim((string) ($input['q'] ?? ''));
        $q = trim((string) (preg_replace('/\s+/', ' ', $q) ?? ''));
        if (mb_strlen($q) > 120) {
            $q = mb_substr($q, 0, 120);
        }

        $status = trim((string) ($input['status'] ?? ''));
        if (!in_array($status, self::ORDER_ALLOWED_STATUSES, true)) {
            $status = '';
        }

        $paymentMethod = trim((string) ($input['payment_method'] ?? ''));
        $paymentMethod = strtolower($paymentMethod);
        if (in_array($paymentMethod, ['euplatesc', 'stripe', 'bank_transfer'], true)) {
            $paymentMethod = 'card';
        }
        if ($paymentMethod !== '' && !in_array($paymentMethod, ['card', 'cod'], true)) {
            $paymentMethod = '';
        }
        $paymentStatus = trim((string) ($input['payment_status'] ?? ''));
        if ($paymentStatus !== '' && !preg_match('/^[a-z0-9_\-]{1,40}$/i', $paymentStatus)) {
            $paymentStatus = '';
        }

        $fromDateRaw = trim((string) ($input['date_from'] ?? ($input['from_date'] ?? '')));
        $toDateRaw = trim((string) ($input['date_to'] ?? ($input['to_date'] ?? '')));
        $fromDate = $this->isValidIsoDate($fromDateRaw) ? $fromDateRaw : '';
        $toDate = $this->isValidIsoDate($toDateRaw) ? $toDateRaw : '';
        if ($fromDate !== '' && $toDate !== '' && strcmp($fromDate, $toDate) > 0) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $sortBy = trim(strtolower((string) ($input['sort'] ?? ($input['sort_by'] ?? 'date'))));
        if (!in_array($sortBy, ['date', 'status', 'total'], true)) {
            $sortBy = 'date';
        }

        $sortDir = trim(strtolower((string) ($input['dir'] ?? ($input['sort_dir'] ?? 'desc'))));
        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        return [
            'q' => $q,
            'status' => $status,
            'payment_method' => strtolower($paymentMethod),
            'payment_status' => strtolower($paymentStatus),
            // Comenzi încasate cu cardul cărora le-a crescut totalul după plată.
            'rest_incasat' => trim((string) ($input['rest_incasat'] ?? '')) !== '',
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
        ];
    }

    private function ordersSortSql(string $sortBy, string $sortDir): string
    {
        $sortBy = trim(strtolower($sortBy));
        $sortDir = trim(strtolower($sortDir)) === 'asc' ? 'ASC' : 'DESC';

        if ($sortBy === 'status') {
            return 'status ' . $sortDir . ', created_at DESC, id DESC';
        }
        if ($sortBy === 'total') {
            return 'total ' . $sortDir . ', id DESC';
        }
        return 'created_at ' . $sortDir . ', id DESC';
    }

    private function buildOrdersBackUrl(array $filters): string
    {
        $query = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query['q'] = $q;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::ORDER_ALLOWED_STATUSES, true)) {
            $query['status'] = $status;
        }

        $paymentMethod = trim((string) ($filters['payment_method'] ?? ''));
        if ($paymentMethod !== '' && in_array($paymentMethod, ['card', 'cod'], true)) {
            $query['payment_method'] = strtolower($paymentMethod);
        }
        $paymentStatus = trim((string) ($filters['payment_status'] ?? ''));
        if ($paymentStatus !== '' && preg_match('/^[a-z0-9_\-]{1,40}$/i', $paymentStatus)) {
            $query['payment_status'] = strtolower($paymentStatus);
        }

        $fromDate = trim((string) ($filters['from_date'] ?? ''));
        if ($this->isValidIsoDate($fromDate)) {
            $query['date_from'] = $fromDate;
        }

        $toDate = trim((string) ($filters['to_date'] ?? ''));
        if ($this->isValidIsoDate($toDate)) {
            $query['date_to'] = $toDate;
        }

        $sortBy = trim(strtolower((string) ($filters['sort_by'] ?? 'date')));
        if (in_array($sortBy, ['date', 'status', 'total'], true) && $sortBy !== 'date') {
            $query['sort'] = $sortBy;
        }

        $sortDir = trim(strtolower((string) ($filters['sort_dir'] ?? 'desc')));
        if ($sortDir === 'asc') {
            $query['dir'] = 'asc';
        }

        return '/admin/orders' . ($query !== [] ? ('?' . http_build_query($query)) : '');
    }

    private function safeOrdersBackUrl(string $url, array $params): string
    {
        $fallback = $this->buildOrdersBackUrl($this->ordersFiltersFromInput($params));
        $url = trim($url);
        if ($url === '') {
            return $fallback;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if ($path !== '/admin/orders') {
            return $fallback;
        }

        $queryRaw = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        $query = [];
        if ($queryRaw !== '') {
            parse_str($queryRaw, $query);
            if (!is_array($query)) {
                $query = [];
            }
        }

        return $this->buildOrdersBackUrl($this->ordersFiltersFromInput(array_merge($query, $params)));
    }

    private function updateOrderStatusInternal(PDO $db, int $orderId, string $status): array
    {
        if ($orderId <= 0) {
            return ['ok' => false, 'message' => 'Comanda nu a fost găsită sau este în coș.'];
        }
        if (!in_array($status, self::ORDER_ALLOWED_STATUSES, true)) {
            return ['ok' => false, 'message' => 'Status invalid.'];
        }

        $this->ensureOptionalSchema($db);
        $previousStatusStmt = $db->prepare('SELECT status FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $previousStatusStmt->execute(['id' => $orderId]);
        $previousStatus = trim((string) ($previousStatusStmt->fetchColumn() ?: ''));
        if ($previousStatus === '') {
            return ['ok' => false, 'message' => 'Comanda nu a fost găsită sau este în coș.'];
        }

        try {
            $stmt = $db->prepare('UPDATE orders SET status = :status WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute([
                'status' => $status,
                'id' => $orderId,
            ]);

            if ($status === 'processing') {
                $settings = Settings::all($db);
                EmailAutomation::sendOrderTemplateById($db, $settings, $orderId, 'processing');
            } elseif ($status === 'completed') {
                $settings = Settings::all($db);
                EmailAutomation::sendOrderTemplateById($db, $settings, $orderId, 'shipped');
            } elseif ($status === 'cancelled') {
                $settings = Settings::all($db);
                EmailAutomation::sendOrderTemplateById($db, $settings, $orderId, 'cancelled');
            }

            if (in_array($status, ['cancelled', 'refunded', 'failed'], true)) {
                // Dacă nu plecase încă, o scoatem din coada de reîncercări; dacă
                // ERP-ul o are deja, îi cerem să o anuleze acolo, ca să dispară
                // din lista „Comenzi site".
                \App\Support\ErpSync::anuleaza(
                    $db,
                    $orderId,
                    'Comandă ' . $status . ' pe site.'
                );
            }

            // Comandă scoasă dintr-o stare de anulare: în ERP a rămas anulată,
            // fiindcă acolo a fost trimisă anularea. O readucem, altfel comanda
            // trăiește pe site și e moartă în ERP, iar nimic nu arată diferența.
            $inactive = ['cancelled', 'refunded', 'failed'];
            $erpMesaj = '';
            if (in_array($previousStatus, $inactive, true) && !in_array($status, $inactive, true)) {
                $settings = Settings::all($db);
                if ((string) ($settings['erp_enabled'] ?? '0') === '1') {
                    try {
                        $rezultat = \App\Support\ErpSync::push($db, $orderId, true);
                        $erpMesaj = !empty($rezultat['ok'])
                            ? ' Comanda a fost retrimisă în ERP.'
                            : ' ERP: ' . trim((string) ($rezultat['message'] ?? 'retrimiterea a eșuat'))
                                . ' — poți reîncerca din butonul de resincronizare.';
                    } catch (Throwable $e) {
                        $erpMesaj = ' ERP: ' . $e->getMessage()
                            . ' — poți reîncerca din butonul de resincronizare.';
                    }
                }
            }

            $this->applyOrderLoyaltyTransitions($db, $orderId, $previousStatus, $status);
            return ['ok' => true, 'message' => 'Statusul comenzii a fost actualizat.' . $erpMesaj];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => 'Statusul nu a putut fi actualizat: ' . $exception->getMessage()];
        }
    }

    /**
     * Notează în jurnal că o comandă din mai multe depozite a plecat pe un
     * singur AWB. Fără urma asta, nimeni n-ar mai ști de ce o comandă cu marfă
     * din două locuri are un singur cod de urmărire — și cine împachetează n-ar
     * ști că trebuie adusă marfa la un loc.
     *
     * @param array<int, array<string, mixed>> $colete
     */
    private function logComasareColete(PDO $db, int $orderId, array $colete): void
    {
        $depozite = [];
        foreach ($colete as $colet) {
            if (!is_array($colet)) {
                continue;
            }
            $nume = trim((string) ($colet['gestiuneName'] ?? $colet['gestiuneId'] ?? ''));
            if ($nume !== '' && !in_array($nume, $depozite, true)) {
                $depozite[] = $nume;
            }
        }
        try {
            AdminActivityLog::log($db, 'awb_colete_comasate', [
                'comanda_id' => $orderId,
                'depozite' => implode(', ', $depozite),
                'colete_primite' => count($colete),
            ]);
        } catch (Throwable) {
            // Jurnalul nu trebuie să oprească generarea AWB-ului.
        }
    }

    private function createFanAwbInternal(
        PDO $db,
        int $orderId,
        bool $trimiteEmailTracking = true,
    ): array
    {
        if ($orderId <= 0) {
            return ['ok' => false, 'message' => 'Comanda nu a fost gasita.'];
        }
        $this->ensureOptionalSchema($db);

        $order = $this->loadOrderForFan($db, $orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'Comanda nu a fost gasita.'];
        }
        // Un AWB emis greșit (serviciu nepotrivit, adresă corectată după) nu se
        // poate repara la FAN: singura cale e altul nou. Refuzul de aici lăsa
        // comanda blocată, așa că reemiterea e permisă — dar AWB-ul vechi se
        // păstrează, fiindcă la FAN el continuă să existe până e anulat.
        $awbInlocuit = trim((string) ($order['fan_awb'] ?? ''));

        $settings = Settings::all($db);
        $credentials = $this->fanCredentialsFromSettings($settings);
        if ($credentials === null) {
            return ['ok' => false, 'message' => 'Completeaza in Setari Livrare: FAN client id, username si parola API.'];
        }

        $payload = $this->buildFanShipmentPayload($order, $settings, $credentials['client_id']);
        $awbServiceFallbackNote = '';

        try {
            $result = $this->creeazaAwbFanCuReincercari($credentials, $payload, $order, $awbServiceFallbackNote);
            $awb = trim((string) ($result['awb'] ?? ''));
            if ($awb === '') {
                throw new RuntimeException('FAN nu a returnat numarul AWB.');
            }

            $previousStatus = trim((string) ($order['status'] ?? ''));

            $istoricAwb = null;
            if ($awbInlocuit !== '' && $awbInlocuit !== $awb) {
                $inlocuite = json_decode((string) ($order['fan_awb_inlocuite'] ?? ''), true);
                if (!is_array($inlocuite)) {
                    $inlocuite = [];
                }
                // Cine a reemis se vede în jurnalul de activitate; aici rămâne
                // doar numărul și momentul, ca să nu ținem aceeași informație
                // în două locuri care pot ajunge să se contrazică.
                $inlocuite[] = [
                    'awb' => $awbInlocuit,
                    'inlocuit_la' => date('Y-m-d H:i:s'),
                ];
                $istoricAwb = json_encode(
                    array_slice($inlocuite, -20),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            $stmt = $db->prepare(
                'UPDATE orders
                 SET fan_awb = :fan_awb,
                     fan_tracking_url = :fan_tracking_url,
                     fan_tracking_status = :fan_tracking_status,
                     fan_tracking_synced_at = NOW(),
                     fan_tracking_last_event_at = NULL,
                     fan_awb_inlocuite = COALESCE(:inlocuite, fan_awb_inlocuite),
                     status = :status
                 WHERE id = :id'
            );
            $stmt->execute([
                'fan_awb' => $awb,
                'fan_tracking_url' => FanCourierGateway::trackingUrl($awb),
                'fan_tracking_status' => 'AWB generat',
                'inlocuite' => $istoricAwb,
                'status' => 'completed',
                'id' => $orderId,
            ]);

            if ($awbInlocuit !== '' && $awbInlocuit !== $awb) {
                AdminActivityLog::log($db, 'comanda_awb_reemis', [
                    'comanda' => (string) ($order['order_number'] ?? $orderId),
                    'awb_vechi' => $awbInlocuit,
                    'awb_nou' => $awb,
                ]);
            }
            if ($previousStatus !== 'completed') {
                $this->applyOrderLoyaltyTransitions($db, $orderId, $previousStatus, 'completed');
            }
            // Emailul cu tracking pleacă doar dacă a rămas bifat la confirmare.
            if ($trimiteEmailTracking) {
                EmailAutomation::sendOrderTemplateById($db, $settings, $orderId, 'shipped');
            }

            if ($awbInlocuit !== '' && $awbInlocuit !== $awb) {
                // FAN nu află din reemitere că vechiul AWB nu mai e bun: coletul
                // lui rămâne activ și poate pleca în paralel cu cel nou.
                return ['ok' => true, 'message' => 'AWB FAN nou: ' . $awb
                    . '. AWB-ul anterior (' . $awbInlocuit . ') NU a fost anulat la FAN — anulează-l în SelfAWB,'
                    . ' altfel rămân două expedieri pe aceeași comandă. Clientul a primit pe email noul număr.'
                    . $awbServiceFallbackNote];
            }

            return ['ok' => true, 'message' => 'AWB FAN generat: ' . $awb . '. Status comandă: completed.' . $awbServiceFallbackNote];
        } catch (RuntimeException $exception) {
            return ['ok' => false, 'message' => 'Nu am putut genera AWB-ul FAN: ' . $exception->getMessage()];
        }
    }

    /**
     * Câte un AWB per depozit, pentru comenzile care pleacă în mai multe
     * colete (ERP-ul trimite coletele la aprobare: depozit + produse).
     *
     * Rambursul întreg merge pe primul colet (depozitul județului de livrare);
     * dacă acel AWB nu se poate genera, ne oprim înainte de a crea ceva, ca
     * încercarea următoare să pornească curat. Un eșec la un colet ulterior
     * păstrează AWB-urile deja create (sunt expedieri reale la FAN) și
     * întoarce eroarea, ca operatorul să rezolve manual restul.
     *
     * @param array<int, array{gestiuneId?: string, gestiuneName?: string, produse?: array<int, array{sku?: string, denumire?: string, cantitate?: string}>}> $colete
     * @return array{ok: bool, message: string}
     */
    private function createFanAwbMulti(
        PDO $db,
        int $orderId,
        array $colete,
        bool $trimiteEmailTracking = true,
    ): array
    {
        if ($orderId <= 0 || $colete === []) {
            return ['ok' => false, 'message' => 'Comanda nu a fost gasita.'];
        }
        $this->ensureOptionalSchema($db);
        \App\Support\ErpSync::ensureSchema($db);

        $order = $this->loadOrderForFan($db, $orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'Comanda nu a fost gasita.'];
        }
        if (trim((string) ($order['fan_awb'] ?? '')) !== '') {
            return ['ok' => false, 'message' => 'Comanda are deja un AWB FAN generat.'];
        }

        $settings = Settings::all($db);
        $credentials = $this->fanCredentialsFromSettings($settings);
        if ($credentials === null) {
            return ['ok' => false, 'message' => 'Completeaza in Setari Livrare: FAN client id, username si parola API.'];
        }

        $itemsBySku = $this->orderItemsBySku($db, $orderId);
        $totalColete = count($colete);
        $generated = [];
        $errors = [];
        $note = [];

        foreach (array_values($colete) as $index => $colet) {
            if (!is_array($colet)) {
                continue;
            }
            $gestiuneId = trim((string) ($colet['gestiuneId'] ?? ''));
            $gestiuneName = trim((string) ($colet['gestiuneName'] ?? ''));
            $eticheta = 'colet ' . ($index + 1) . '/' . $totalColete . ($gestiuneName !== '' ? ' (' . $gestiuneName . ')' : '');

            // Produsele coletului, potrivite pe SKU cu order_items (greutate + valoare).
            $items = [];
            $valoare = 0.0;
            foreach ((array) ($colet['produse'] ?? []) as $produs) {
                if (!is_array($produs)) {
                    continue;
                }
                $sku = strtoupper(trim((string) ($produs['sku'] ?? '')));
                $cantitate = max(0.0, (float) ($produs['cantitate'] ?? 0));
                $item = $itemsBySku[$sku] ?? null;
                $items[] = [
                    'quantity' => max(1, (int) ceil($cantitate)),
                    'weight_grams' => (int) ($item['weight_grams'] ?? 0),
                ];
                $valoare += $cantitate * (float) ($item['unit_price'] ?? 0);
            }

            $payload = $this->buildFanShipmentPayload($order, $settings, $credentials['client_id'], [
                'items' => $items,
                // Rambursul întreg pe primul colet; restul pleacă fără ramburs.
                'cod' => $index === 0 ? null : 0.0,
                'declared_value' => $valoare > 0 ? round($valoare, 2) : null,
                'content_suffix' => '— ' . $eticheta,
                'sender_gestiune_id' => $gestiuneId,
            ]);

            try {
                $notaColet = '';
                $result = $this->creeazaAwbFanCuReincercari($credentials, $payload, $order, $notaColet);
                if (trim($notaColet) !== '') {
                    $note[] = trim($notaColet);
                }
                $awb = trim((string) ($result['awb'] ?? ''));
                if ($awb === '') {
                    throw new RuntimeException('FAN nu a returnat numarul AWB.');
                }
                $generated[] = [
                    'awb' => $awb,
                    'tracking_url' => FanCourierGateway::trackingUrl($awb),
                    'gestiune' => $gestiuneName !== '' ? $gestiuneName : $gestiuneId,
                    'colet' => $index + 1,
                ];
            } catch (RuntimeException $exception) {
                $errors[] = ucfirst($eticheta) . ': ' . $exception->getMessage();
                if ($index === 0) {
                    // Primul colet poartă rambursul: fără el nu creăm nimic.
                    return ['ok' => false, 'message' => 'Nu am putut genera AWB-urile FAN: ' . implode(' ', $errors)];
                }
            }
        }

        if ($generated === []) {
            return ['ok' => false, 'message' => 'Nu am putut genera AWB-urile FAN: ' . implode(' ', $errors)];
        }

        $previousStatus = trim((string) ($order['status'] ?? ''));
        $stmt = $db->prepare(
            'UPDATE orders
             SET fan_awb = :fan_awb,
                 fan_awb_list = :fan_awb_list,
                 fan_tracking_url = :fan_tracking_url,
                 fan_tracking_status = :fan_tracking_status,
                 fan_tracking_synced_at = NOW(),
                 status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            'fan_awb' => $generated[0]['awb'],
            'fan_awb_list' => json_encode($generated, JSON_UNESCAPED_UNICODE),
            'fan_tracking_url' => $generated[0]['tracking_url'],
            'fan_tracking_status' => 'AWB generat (' . count($generated) . '/' . $totalColete . ' colete)',
            'status' => 'completed',
            'id' => $orderId,
        ]);
        if ($previousStatus !== 'completed') {
            $this->applyOrderLoyaltyTransitions($db, $orderId, $previousStatus, 'completed');
        }
        // Emailul cu tracking pleacă doar dacă a rămas bifat la confirmare.
        if ($trimiteEmailTracking) {
            EmailAutomation::sendOrderTemplateById($db, $settings, $orderId, 'shipped');
        }

        $listaAwb = implode(', ', array_column($generated, 'awb'));
        if ($errors !== []) {
            return [
                'ok' => false,
                'message' => 'AWB generat doar pentru o parte din colete (' . $listaAwb . '). ' . implode(' ', $errors),
            ];
        }
        return [
            'ok' => true,
            'message' => 'AWB-uri FAN generate: ' . $listaAwb . ' (' . $totalColete . ' colete). Status comandă: completed.'
                . ($note !== [] ? ' ' . implode(' ', array_unique($note)) : ''),
        ];
    }

    /**
     * Produsele comenzii indexate pe SKU (majuscule), pentru împărțirea pe
     * colete: greutatea și valoarea fiecărui colet se calculează din ele.
     *
     * @return array<string, array{weight_grams: int, unit_price: float}>
     */
    private function orderItemsBySku(PDO $db, int $orderId): array
    {
        $stmt = $db->prepare(
            'SELECT oi.quantity, oi.unit_price, p.weight_grams, p.sku
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }
            $out[$sku] = [
                'weight_grams' => (int) ($row['weight_grams'] ?? 0),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
            ];
        }
        return $out;
    }

    private function shouldRetryFanAwbWithStandardService(array $order, array $payload, string $errorMessage): bool
    {
        // Trecerea pe „Standard" ar pierde încasarea rambursului, deci nu se
        // face când AWB-ul chiar are ramburs. Un colet secundar (ramburs 0)
        // al unei comenzi COD poate totuși cădea pe Standard.
        $paymentMethod = strtolower(trim((string) ($order['payment_method'] ?? '')));
        $payloadCod = (float) ($payload['shipments'][0]['info']['cod'] ?? 0);
        if ($paymentMethod === 'cod' && $payloadCod > 0) {
            return false;
        }

        $normalizedError = strtolower(trim($errorMessage));
        $codRequired = str_contains($normalizedError, 'cod')
            && (
                str_contains($normalizedError, 'required')
                || str_contains($normalizedError, 'obligator')
                || str_contains($normalizedError, 'cash on delivery')
            );
        if (!$codRequired) {
            return false;
        }

        $currentService = strtolower(trim((string) ($payload['shipments'][0]['info']['service'] ?? '')));
        return $currentService !== '' && $currentService !== 'standard';
    }

    /**
     * FAN refuză o opțiune care nu e disponibilă pentru serviciul ales
     * („Option not available for this service type"). Cel mai des e o opțiune
     * bifată global în setări, care nu se potrivește comenzii curente.
     */
    private function esteEroareDeOptiuniFan(string $errorMessage): bool
    {
        $mesaj = strtolower(trim($errorMessage));
        return str_contains($mesaj, 'option')
            && (str_contains($mesaj, 'not available') || str_contains($mesaj, 'invalid'));
    }

    /** Același payload, fără opțiunile FAN. */
    private function fanPayloadFaraOptiuni(array $payload): array
    {
        if (isset($payload['shipments'][0]['info'])) {
            $payload['shipments'][0]['info']['options'] = [];
        }
        return $payload;
    }

    /**
     * Comanda pleacă spre un punct FANbox? Se citește de pe comandă, nu din
     * opțiunile AWB-ului: opțiunea „V" poate veni din setările globale, iar
     * atunci o livrare obișnuită părea o livrare în locker.
     */
    private function comandaLaFanbox(array $order): bool
    {
        return (int) ($order['fan_locker_id'] ?? 0) > 0
            && trim((string) ($order['fan_locker_address'] ?? '')) !== '';
    }

    /**
     * Creează AWB-ul, cu reîncercările care au sens:
     *  1. serviciul cere ramburs → se trece pe „Standard";
     *  2. o opțiune nu e valabilă pentru serviciu → se reia fără opțiuni.
     *
     * A doua reîncercare NU se face la livrarea în FANbox: acolo opțiunea „V"
     * e chiar destinația, iar fără ea coletul ar pleca greșit. Acolo e mai bine
     * să eșueze vizibil, cu un mesaj care spune ce trebuie schimbat.
     */
    private function creeazaAwbFanCuReincercari(
        array $credentials,
        array &$payload,
        array $order,
        string &$nota
    ): array {
        try {
            return FanCourierGateway::createInternalAwb($credentials, $payload);
        } catch (RuntimeException $exception) {
            $mesaj = $exception->getMessage();

            if ($this->shouldRetryFanAwbWithStandardService($order, $payload, $mesaj)) {
                $serviciuVechi = trim((string) ($payload['shipments'][0]['info']['service'] ?? ''));
                $payload = $this->fanPayloadWithService($payload, 'Standard');
                $rezultat = FanCourierGateway::createInternalAwb($credentials, $payload);
                if ($serviciuVechi !== '' && strtolower($serviciuVechi) !== 'standard') {
                    $nota .= ' Serviciul FAN "' . $serviciuVechi . '" a cerut COD; AWB-ul a fost trimis automat cu serviciul "Standard".';
                }
                return $rezultat;
            }

            if ($this->esteEroareDeOptiuniFan($mesaj)) {
                if ($this->comandaLaFanbox($order)) {
                    throw new RuntimeException(
                        'FAN nu acceptă livrarea la FANbox cu serviciul "'
                        . trim((string) ($payload['shipments'][0]['info']['service'] ?? '')) . '". '
                        . 'Verifică „Tip serviciu FAN pentru FANbox" din Setări livrare (ar trebui „FANbox"); '
                        . 'dacă serviciul e corect, cere consilierului FAN să-l activeze pe cont. Răspuns FAN: ' . $mesaj
                    );
                }
                $optiuni = (array) ($payload['shipments'][0]['info']['options'] ?? []);
                $payload = $this->fanPayloadFaraOptiuni($payload);
                $rezultat = FanCourierGateway::createInternalAwb($credentials, $payload);
                if ($optiuni !== []) {
                    $nota .= ' Opțiunile FAN (' . implode(', ', $optiuni) . ') nu sunt disponibile pentru serviciul ales;'
                        . ' AWB-ul a fost emis fără ele. Verifică „Opțiuni FAN" din Setări livrare.';
                }
                return $rezultat;
            }

            throw $exception;
        }
    }

    private function fanPayloadWithService(array $payload, string $service): array
    {
        $service = trim($service);
        if ($service === '') {
            return $payload;
        }
        if (!isset($payload['shipments']) || !is_array($payload['shipments'])) {
            return $payload;
        }
        if (!isset($payload['shipments'][0]) || !is_array($payload['shipments'][0])) {
            return $payload;
        }
        if (!isset($payload['shipments'][0]['info']) || !is_array($payload['shipments'][0]['info'])) {
            return $payload;
        }

        $payload['shipments'][0]['info']['service'] = $service;
        return $payload;
    }

    private function isValidIsoDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }

    /**
     * Datele cu care o comandă rămâne recognoscibilă în jurnal după ce nu mai
     * există. Se citesc ÎNAINTE de ștergere: după un `DELETE` nu mai are de
     * unde afla nimeni ce anume s-a șters.
     *
     * @return array<string, string>
     */
    private function rezumatComandaPentruJurnal(PDO $db, int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        try {
            $stmt = $db->prepare(
                'SELECT order_number, billing_first_name, billing_last_name, billing_email,
                        total, status, payment_method, fan_awb
                 FROM orders WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $r = $stmt->fetch();
        } catch (Throwable) {
            return [];
        }
        if (!is_array($r)) {
            return [];
        }

        $client = trim((string) ($r['billing_first_name'] ?? '') . ' ' . (string) ($r['billing_last_name'] ?? ''));
        $rezumat = [
            'comanda_id' => (string) $id,
            'comanda' => (string) ($r['order_number'] ?? ''),
            'client' => $client,
            'email' => (string) ($r['billing_email'] ?? ''),
            'total' => number_format((float) ($r['total'] ?? 0), 2, '.', '') . ' RON',
            'status' => (string) ($r['status'] ?? ''),
            'plata' => (string) ($r['payment_method'] ?? ''),
        ];
        $awb = trim((string) ($r['fan_awb'] ?? ''));
        if ($awb !== '') {
            $rezumat['awb'] = $awb;
        }

        return array_filter($rezumat, static fn($v) => $v !== '');
    }

    public function deleteOrder(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $backUrl = $this->safeOrdersBackUrl((string) ($_POST['back_url'] ?? ''), array_merge($_GET, $_POST));
        $db = $this->db();
        if ($db instanceof PDO && $id > 0) {
            $this->ensureOptionalSchema($db);
            $rezumat = $this->rezumatComandaPentruJurnal($db, $id);
            $stmt = $db->prepare('UPDATE orders SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() > 0) {
                AdminActivityLog::log($db, 'comanda_stearsa', $rezumat);
            }
            Flash::set('success', 'Comanda a fost mutată în coș.');
        }

        header('Location: ' . $backUrl);
    }

    public function restoreOrder(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $db = $this->db();
        if ($db instanceof PDO && $id > 0) {
            $this->ensureOptionalSchema($db);
            $rezumat = $this->rezumatComandaPentruJurnal($db, $id);
            $stmt = $db->prepare('UPDATE orders SET deleted_at = NULL WHERE id = :id AND deleted_at IS NOT NULL');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() > 0) {
                AdminActivityLog::log($db, 'comanda_restaurata', $rezumat);
            }
            Flash::set('success', 'Comanda a fost restaurată.');
        }

        header('Location: /admin/orders/trash');
    }

    public function forceDeleteOrder(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $db = $this->db();
        if ($db instanceof PDO && $id > 0) {
            $this->ensureOptionalSchema($db);
            $rezumat = $this->rezumatComandaPentruJurnal($db, $id);
            $stmt = $db->prepare('DELETE FROM orders WHERE id = :id AND deleted_at IS NOT NULL');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() > 0) {
                AdminActivityLog::log($db, 'comanda_stearsa_definitiv', $rezumat);
            }
            Flash::set('success', 'Comanda a fost ștearsă definitiv.');
        }

        header('Location: /admin/orders/trash');
    }

    public function updateOrderStatus(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? 'pending'));
        if (!in_array($status, self::ORDER_ALLOWED_STATUSES, true)) {
            $status = 'pending';
        }
        $backParams = array_merge($_GET, $_POST);
        // The "status" field from this form is the target order status, not an orders-list filter.
        unset($backParams['status']);
        $backUrl = $this->safeOrdersBackUrl((string) ($_POST['back_url'] ?? ''), $backParams);

        $db = $this->db();
        if ($db instanceof PDO) {
            $result = $this->updateOrderStatusInternal($db, $id, $status);
            Flash::set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Actualizare invalidă.'));
        } else {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
        }
        header('Location: ' . $backUrl);
    }

    private function applyOrderLoyaltyTransitions(PDO $db, int $orderId, string $fromStatus, string $toStatus): void
    {
        if ($orderId <= 0) {
            return;
        }

        $fromStatus = trim($fromStatus);
        $toStatus = trim($toStatus);
        if ($toStatus === 'completed') {
            $settings = Settings::all($db);
            LoyaltyService::awardPointsForCompletedOrder($db, $settings, $orderId, Auth::id());
            return;
        }

        if (in_array($toStatus, ['cancelled', 'refunded', 'failed'], true)) {
            LoyaltyService::refundRedeemedPointsForOrder($db, $orderId, Auth::id());
            if ($fromStatus === 'completed' || $toStatus !== $fromStatus) {
                LoyaltyService::reverseAwardedPointsForOrder($db, $orderId, Auth::id());
            }
        }
    }

    /**
     * Endpoint apelat de ERP când o comandă e aprobată sau anulată.
     *
     * Autentificarea folosește aceeași cheie ca sensul invers (site → ERP),
     * trimisă în antetul `X-Andaxi-Site-Key`. Răspunsul întoarce AWB-ul
     * generat, ca ERP-ul să-l poată afișa pe comandă.
     */
    public function erpNotification(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'message' => 'Baza de date nu este disponibilă.']);
            return;
        }

        $settings = Settings::all($db);
        $expected = trim((string) ($settings['erp_api_key'] ?? ''));
        $provided = trim((string) ($_SERVER['HTTP_X_ANDAXI_SITE_KEY'] ?? ''));

        if ((string) ($settings['erp_enabled'] ?? '0') !== '1') {
            http_response_code(401);
            echo json_encode(['ok' => false, 'message' => 'Integrarea cu ERP-ul este dezactivată pe site.']);
            return;
        }
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'message' => 'Cheie de integrare invalidă.']);
            return;
        }

        $raw = (string) file_get_contents('php://input');
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Payload invalid.']);
            return;
        }

        $result = $this->handleErpEvent($event);
        if (($result['ok'] ?? false) !== true) {
            http_response_code(422);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Aplică pe site un eveniment venit din ERP. Folosit atât de endpoint-ul
     * de notificare, cât și de cron-ul care recuperează notificările nelivrate.
     *
     * @return array{ok: bool, message: string, awb?: string, trackingUrl?: string}
     */
    public function handleErpEvent(array $event): array
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            return ['ok' => false, 'message' => 'Baza de date nu este disponibilă.'];
        }

        \App\Support\ErpSync::ensureSchema($db);

        $eveniment = trim((string) ($event['eveniment'] ?? ''));
        $numarSite = trim((string) ($event['numarSite'] ?? ''));
        if ($eveniment === '' || $numarSite === '') {
            return ['ok' => false, 'message' => 'Evenimentul nu are „eveniment" sau „numarSite".'];
        }

        $stmt = $db->prepare('SELECT id, status, fan_awb, fan_tracking_url FROM orders WHERE order_number = :nr AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['nr' => $numarSite]);
        $order = $stmt->fetch() ?: null;
        if (!is_array($order)) {
            return ['ok' => false, 'message' => 'Comanda ' . $numarSite . ' nu există pe site.'];
        }
        $orderId = (int) $order['id'];

        return match ($eveniment) {
            'comanda_aprobata' => $this->applyErpApproval($db, $orderId, $order, $event),
            'comanda_anulata' => $this->applyErpCancellation($db, $orderId, $order),
            'comanda_modificata' => $this->applyErpOrderChange($db, $orderId, $event),
            default => ['ok' => false, 'message' => 'Eveniment necunoscut: ' . $eveniment],
        };
    }

    /**
     * Comanda a fost corectată în ERP: rescriem liniile pe site, ca magazinul
     * să nu arate altceva decât se livrează.
     *
     * Liniile vin întregi, nu ca diferență: ștergem ce nu mai e, actualizăm ce
     * a rămas, adăugăm ce e nou. Potrivirea se face pe SKU — id-ul de produs
     * din ERP nu înseamnă nimic aici, iar denumirea se poate schimba.
     *
     * Prețurile vin în două variante, cu și fără TVA. Le folosim pe cele cu
     * TVA: aici prețul afișat clientului e cel plătit, nu baza de impozitare.
     */
    private function applyErpOrderChange(PDO $db, int $orderId, array $event): array
    {
        $linii = is_array($event['linii'] ?? null) ? array_values($event['linii']) : [];
        if ($linii === []) {
            return ['ok' => false, 'message' => 'Evenimentul nu conține linii.'];
        }

        // SKU → id de produs, ca liniile să rămână legate de fișele de pe site.
        $skuuri = array_values(array_filter(array_map(
            static fn ($l): string => is_array($l) ? trim((string) ($l['sku'] ?? '')) : '',
            $linii
        ), static fn (string $v): bool => $v !== ''));
        $produsePeSku = [];
        if ($skuuri !== []) {
            $semne = implode(',', array_fill(0, count($skuuri), '?'));
            $stmt = $db->prepare("SELECT id, sku FROM products WHERE sku IN ({$semne})");
            $stmt->execute($skuuri);
            foreach ($stmt->fetchAll() ?: [] as $rand) {
                $produsePeSku[trim((string) $rand['sku'])] = (int) $rand['id'];
            }
        }

        $existente = [];
        $stmt = $db->prepare(
            'SELECT oi.id, oi.product_id, p.sku
             FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :id ORDER BY oi.id ASC'
        );
        $stmt->execute(['id' => $orderId]);
        foreach ($stmt->fetchAll() ?: [] as $rand) {
            $sku = trim((string) ($rand['sku'] ?? ''));
            if ($sku !== '' && !isset($existente[$sku])) {
                $existente[$sku] = (int) $rand['id'];
            }
        }

        $db->beginTransaction();
        try {
            $pastrate = [];
            $update = $db->prepare(
                'UPDATE order_items
                 SET product_name = :nume, quantity = :cant, unit_price = :pret, line_total = :total
                 WHERE id = :id'
            );
            $insert = $db->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, line_total)
                 VALUES (:order_id, :product_id, :nume, :cant, :pret, :total)'
            );

            foreach ($linii as $linie) {
                if (!is_array($linie)) {
                    continue;
                }
                $sku = trim((string) ($linie['sku'] ?? ''));
                $nume = trim((string) ($linie['denumire'] ?? '')) ?: 'Produs';
                // Cantitățile de pe site sunt întregi; ERP-ul le ține cu zecimale.
                $cant = max(1, (int) round((float) ($linie['cantitate'] ?? 1)));
                $pret = round((float) ($linie['pretUnitarCuTva'] ?? 0), 2);
                $totalLinie = round((float) ($linie['valoareCuTva'] ?? $pret * $cant), 2);

                if ($sku !== '' && isset($existente[$sku])) {
                    $update->execute([
                        'nume' => $nume, 'cant' => $cant, 'pret' => $pret,
                        'total' => $totalLinie, 'id' => $existente[$sku],
                    ]);
                    $pastrate[] = $existente[$sku];
                    continue;
                }
                $insert->execute([
                    'order_id' => $orderId,
                    'product_id' => $produsePeSku[$sku] ?? null,
                    'nume' => $nume, 'cant' => $cant, 'pret' => $pret, 'total' => $totalLinie,
                ]);
                $pastrate[] = (int) $db->lastInsertId();
            }

            // Ce n-a fost nici actualizat, nici adăugat, a fost scos din comandă.
            if ($pastrate !== []) {
                $semne = implode(',', array_fill(0, count($pastrate), '?'));
                $sterge = $db->prepare(
                    "DELETE FROM order_items WHERE order_id = ? AND id NOT IN ({$semne})"
                );
                $sterge->execute(array_merge([$orderId], $pastrate));
            }

            $transport = round((float) ($event['transport'] ?? 0), 2);
            $total = round((float) ($event['total'] ?? 0), 2);
            $db->prepare('UPDATE orders SET total = :total, shipping_cost = :transport WHERE id = :id')
                ->execute(['total' => $total, 'transport' => $transport, 'id' => $orderId]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            return ['ok' => false, 'message' => 'Actualizarea liniilor a eșuat: ' . $e->getMessage()];
        }

        return [
            'ok' => true,
            'message' => 'Comanda a fost actualizată din ERP (' . count($linii) . ' linii).',
        ];
    }

    /**
     * Comandă aprobată în ERP: reținem numărul facturii, trecem comanda în
     * procesare, generăm AWB-ul (credențialele FAN sunt aici, pe site) și
     * trimitem clientului emailul cu tracking.
     */
    private function applyErpApproval(PDO $db, int $orderId, array $order, array $event): array
    {
        $facturaNumar = trim((string) ($event['facturaNumar'] ?? ''));

        try {
            $db->prepare(
                'UPDATE orders
                 SET erp_factura_numar = :factura, erp_status = :erp_status, erp_last_error = NULL
                 WHERE id = :id'
            )->execute([
                'factura' => $facturaNumar !== '' ? $facturaNumar : null,
                'erp_status' => \App\Support\ErpSync::STATUS_SENT,
                'id' => $orderId,
            ]);
        } catch (Throwable) {
            // Coloanele lipsă nu blochează restul fluxului.
        }

        // Statusul se schimbă direct, fără emailul de „în procesare": clientul
        // primește un singur mesaj, cel cu AWB-ul, imediat după generare.
        $previousStatus = trim((string) ($order['status'] ?? ''));
        if (!in_array($previousStatus, ['completed', 'cancelled', 'refunded'], true)) {
            try {
                $db->prepare('UPDATE orders SET status = :status WHERE id = :id')
                    ->execute(['status' => 'processing', 'id' => $orderId]);
                $this->applyOrderLoyaltyTransitions($db, $orderId, $previousStatus, 'processing');
            } catch (Throwable) {
            }
        }

        $awb = trim((string) ($order['fan_awb'] ?? ''));
        $awbMessage = '';
        if ($awb === '') {
            // ERP-ul trimite coletele (depozit + produse). Implicit, comanda
            // pleacă într-un singur AWB chiar dacă marfa vine din două
            // depozite: coletele se adună înainte de expediere, iar clientul
            // primește un singur colet și un singur cod de urmărire. Cine are
            // nevoie de expediere separată per depozit dezactivează setarea.
            $colete = is_array($event['colete'] ?? null) ? array_values($event['colete']) : [];
            $unSingurAwb = (string) (Settings::all($db)['fan_awb_single'] ?? '1') === '1';
            if ($unSingurAwb && count($colete) >= 2) {
                $this->logComasareColete($db, $orderId, $colete);
            }
            try {
                $rezultat = (!$unSingurAwb && count($colete) >= 2)
                    ? $this->createFanAwbMulti($db, $orderId, $colete)
                    : $this->createFanAwbInternal($db, $orderId);
                if (($rezultat['ok'] ?? false) !== true) {
                    $awbMessage = (string) ($rezultat['message'] ?? '');
                }
            } catch (Throwable $exception) {
                $awbMessage = $exception->getMessage();
            }
        }

        $reload = $db->prepare('SELECT fan_awb, fan_awb_list, fan_tracking_url FROM orders WHERE id = :id LIMIT 1');
        $reload->execute(['id' => $orderId]);
        $fresh = $reload->fetch() ?: [];
        $awb = trim((string) ($fresh['fan_awb'] ?? ''));
        $order['fan_tracking_url'] = (string) ($fresh['fan_tracking_url'] ?? '');

        // Către ERP se întorc toate AWB-urile comenzii, separate prin virgulă.
        $listaAwb = json_decode((string) ($fresh['fan_awb_list'] ?? ''), true);
        if (is_array($listaAwb) && count($listaAwb) > 1) {
            $toate = array_filter(array_map(
                static fn ($intrare): string => is_array($intrare) ? trim((string) ($intrare['awb'] ?? '')) : '',
                $listaAwb
            ), static fn (string $v): bool => $v !== '');
            if ($toate !== []) {
                $awb = implode(', ', $toate);
            }
        }

        if ($awb !== '') {
            $settings = Settings::all($db);
            EmailAutomation::sendOrderTemplateById($db, $settings, $orderId, 'shipped');
        }

        return [
            'ok' => true,
            'message' => $awb !== ''
                ? 'Comandă aprobată, AWB generat.'
                : ('Comandă aprobată, dar AWB-ul nu a putut fi generat. ' . $awbMessage),
            'awb' => $awb,
            'trackingUrl' => trim((string) ($order['fan_tracking_url'] ?? '')),
        ];
    }

    /** Comandă anulată în ERP: o anulăm și pe site (punctele se întorc). */
    private function applyErpCancellation(PDO $db, int $orderId, array $order): array
    {
        if (trim((string) ($order['status'] ?? '')) === 'cancelled') {
            return ['ok' => true, 'message' => 'Comanda era deja anulată pe site.'];
        }

        $rezultat = $this->updateOrderStatusInternal($db, $orderId, 'cancelled');
        return [
            'ok' => (bool) ($rezultat['ok'] ?? false),
            'message' => (string) ($rezultat['message'] ?? ''),
        ];
    }

    public function createFanAwb(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $backUrl = $this->safeOrdersBackUrl((string) ($_POST['back_url'] ?? ''), array_merge($_GET, $_POST));
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: ' . $backUrl);
            return;
        }
        $this->ensureOptionalSchema($db);

        // Un buton dezactivat în pagină nu oprește o cerere trimisă direct, iar
        // aici se emit AWB-uri reale, cu costuri la curier.
        $stareAwb = $db->prepare(
            'SELECT fan_awb, fan_tracking_status FROM orders WHERE id = :id LIMIT 1'
        );
        $stareAwb->execute(['id' => $id]);
        $randAwb = $stareAwb->fetch() ?: [];
        if (!FanCourierGateway::poateReemiteAwb(
            (string) ($randAwb['fan_awb'] ?? ''),
            (string) ($randAwb['fan_tracking_status'] ?? '')
        )) {
            Flash::set(
                'error',
                'Coletul e deja la curier (' . trim((string) ($randAwb['fan_tracking_status'] ?? '')) . ') — '
                . 'nu se mai poate emite alt AWB pentru comanda asta.'
            );
            header('Location: ' . $backUrl);
            return;
        }

        // Emailul cu tracking pleacă din start; se poate scoate din fereastra
        // de confirmare, când coletul pleacă mai târziu sau clientul e deja
        // anunțat pe altă cale.
        $trimiteEmail = (string) ($_POST['trimite_email_tracking'] ?? '1') !== '0';

        try {
            $result = $this->createFanAwbInternal($db, $id, $trimiteEmail);
            Flash::set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Nu am putut genera AWB.'));
        } catch (Throwable $exception) {
            Flash::set('error', 'Nu am putut genera AWB-ul FAN: ' . $exception->getMessage());
        }
        header('Location: ' . $backUrl);
    }

    public function refreshFanTracking(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $backUrl = $this->safeOrdersBackUrl((string) ($_POST['back_url'] ?? ''), array_merge($_GET, $_POST));
        $db = $this->db();
        if (!$db instanceof PDO || $id <= 0) {
            Flash::set('error', 'Comanda nu a fost gasita.');
            header('Location: ' . $backUrl);
            return;
        }
        $this->ensureOptionalSchema($db);

        $stmt = $db->prepare('SELECT id, fan_awb FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $id]);
        $order = $stmt->fetch() ?: null;
        $awb = trim((string) ($order['fan_awb'] ?? ''));
        if ($awb === '') {
            Flash::set('error', 'Comanda nu are AWB FAN generat.');
            header('Location: ' . $backUrl);
            return;
        }

        $settings = Settings::all($db);
        $credentials = $this->fanCredentialsFromSettings($settings);
        if ($credentials === null) {
            Flash::set('error', 'Completeaza in Setari Livrare: FAN client id, username si parola API.');
            header('Location: ' . $backUrl);
            return;
        }

        try {
            $tracking = FanCourierGateway::trackAwb($credentials, $credentials['client_id'], $awb, 'ro');
            $latestStatus = trim((string) ($tracking['latest_event_name'] ?? '')) ?: 'Status indisponibil';
            $latestAt = $this->parseFanDate((string) ($tracking['latest_event_at'] ?? ''));
            $trackingUrl = trim((string) ($tracking['tracking_url'] ?? ''));
            if ($trackingUrl === '') {
                $trackingUrl = FanCourierGateway::trackingUrl($awb);
            }

            $update = $db->prepare(
                'UPDATE orders
                 SET fan_tracking_status = :status,
                     fan_tracking_last_event_at = :last_event_at,
                     fan_tracking_synced_at = NOW(),
                     fan_tracking_url = :tracking_url
                 WHERE id = :id'
            );
            $update->execute([
                'status' => $latestStatus,
                'last_event_at' => $latestAt,
                'tracking_url' => $trackingUrl,
                'id' => $id,
            ]);

            Flash::set('success', 'Tracking FAN actualizat: ' . $latestStatus);
        } catch (RuntimeException $exception) {
            Flash::set('error', 'Nu am putut actualiza tracking FAN: ' . $exception->getMessage());
        } catch (Throwable $exception) {
            Flash::set('error', 'Nu am putut actualiza tracking FAN: ' . $exception->getMessage());
        }

        header('Location: ' . $backUrl);
    }

    public function storeSettingsForm(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $settings = Settings::all($db);
        $sitemapFilename = $this->normalizeSitemapFilename((string) ($settings['store_sitemap_filename'] ?? 'sitemap.xml'));
        $appUrl = $this->appUrl();
        $galleryImages = [];
        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            try {
                $galleryRows = $db->query(
                    "SELECT id, title, image_url, alt_text
                     FROM gallery_images
                     WHERE media_type = 'image'
                     ORDER BY id DESC
                     LIMIT 300"
                )->fetchAll();
                $galleryImages = is_array($galleryRows) ? $galleryRows : [];
            } catch (Throwable) {
                $galleryImages = [];
            }
        }

        View::render('admin/settings-store', [
            'title' => 'Setări magazin',
            'settings' => $settings,
            'galleryImages' => $galleryImages,
            'sitemapFilename' => $sitemapFilename,
            'sitemapUrl' => rtrim($this->appUrl(), '/') . '/' . $sitemapFilename,
            'appUrl' => $appUrl,
            'storeSettingsTab' => trim((string) ($_GET['tab'] ?? 'pages')),
            'cacheStats' => ResponseCache::pageCacheStats(),
            'vatSumar' => $db instanceof PDO ? $this->tvaProduseSumar($db) : [],
        ], 'admin/layout');
    }

    public function floatingCartSettingsForm(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $settings = Settings::all($db);
        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
        }

        View::render('admin/settings-floating-cart', [
            'title' => 'Coș flotant',
            'settings' => $settings,
        ], 'admin/layout');
    }

    public function floatingCartSettingsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/floating-cart');
            return;
        }
        $this->ensureOptionalSchema($db);
        $position = in_array((string) ($_POST['floating_cart_position'] ?? 'right'), ['left', 'right'], true)
            ? (string) ($_POST['floating_cart_position'] ?? 'right')
            : 'right';
        $pointsPosition = in_array((string) ($_POST['floating_cart_points_position'] ?? 'before_total'), ['before_total', 'after_total'], true)
            ? (string) ($_POST['floating_cart_points_position'] ?? 'before_total')
            : 'before_total';
        $panelWidth = max(320, min(640, (int) ($_POST['floating_cart_panel_width'] ?? 420)));
        $excludedUrls = $this->normalizeFloatingCartExcludedUrls((string) ($_POST['floating_cart_excluded_urls'] ?? ''));

        Settings::save($db, [
            'floating_cart_enabled' => isset($_POST['floating_cart_enabled']) ? '1' : '0',
            'floating_cart_show_desktop' => (isset($_POST['floating_cart_show_desktop']) || isset($_POST['floating_cart_show_on_desktop'])) ? '1' : '0',
            'floating_cart_show_mobile' => (isset($_POST['floating_cart_show_mobile']) || isset($_POST['floating_cart_show_on_mobile'])) ? '1' : '0',
            'floating_cart_auto_open_on_add' => (isset($_POST['floating_cart_auto_open_on_add']) || isset($_POST['floating_cart_open_on_add'])) ? '1' : '0',
            'floating_cart_position' => $position,
            'floating_cart_trigger_label' => trim((string) ($_POST['floating_cart_trigger_label'] ?? 'Coș')),
            'floating_cart_trigger_icon' => trim((string) ($_POST['floating_cart_trigger_icon'] ?? '🛒')),
            'floating_cart_title' => trim((string) ($_POST['floating_cart_title'] ?? 'Coșul tău')),
            'floating_cart_accent_color' => $this->normalizeHexColor((string) ($_POST['floating_cart_accent_color'] ?? $_POST['floating_cart_trigger_color'] ?? '#0f766e'), '#0f766e'),
            'floating_cart_badge_bg' => $this->normalizeHexColor((string) ($_POST['floating_cart_badge_bg'] ?? '#ffffff'), '#ffffff'),
            'floating_cart_badge_text' => $this->normalizeHexColor((string) ($_POST['floating_cart_badge_text'] ?? '#0f766e'), '#0f766e'),
            'floating_cart_panel_width' => (string) $panelWidth,
            'floating_cart_offset_x' => (string) max(0, (int) ($_POST['floating_cart_offset_x'] ?? 18)),
            'floating_cart_offset_y' => (string) max(0, (int) ($_POST['floating_cart_offset_y'] ?? 18)),
            'floating_cart_show_product_images' => isset($_POST['floating_cart_show_product_images']) ? '1' : '0',
            'floating_cart_show_view_cart_button' => isset($_POST['floating_cart_show_view_cart_button']) ? '1' : '0',
            'floating_cart_show_checkout_button' => isset($_POST['floating_cart_show_checkout_button']) ? '1' : '0',
            'floating_cart_view_cart_label' => trim((string) ($_POST['floating_cart_view_cart_label'] ?? 'Vezi coșul')),
            'floating_cart_checkout_label' => trim((string) ($_POST['floating_cart_checkout_label'] ?? 'Checkout')),
            'floating_cart_show_subtotal' => isset($_POST['floating_cart_show_subtotal_line']) ? '1' : '0',
            'floating_cart_show_discount' => isset($_POST['floating_cart_show_discount_line']) ? '1' : '0',
            'floating_cart_show_points_discount' => isset($_POST['floating_cart_show_points_discount_line']) ? '1' : '0',
            'floating_cart_show_shipping' => isset($_POST['floating_cart_show_shipping_line']) ? '1' : '0',
            'floating_cart_show_vat' => isset($_POST['floating_cart_show_vat_line']) ? '1' : '0',
            'floating_cart_show_points_earned' => (isset($_POST['floating_cart_show_points_earned']) || isset($_POST['floating_cart_show_points_earn'])) ? '1' : '0',
            'floating_cart_points_position' => $pointsPosition,
            'floating_cart_points_text' => trim((string) ($_POST['floating_cart_points_text'] ?? 'Primești {points} puncte la această comandă.')),
            'floating_cart_free_shipping_threshold' => (string) max(0, (float) str_replace(',', '.', trim((string) ($_POST['floating_cart_free_shipping_threshold'] ?? '200')))),
            'floating_cart_points_label_prefix' => trim((string) ($_POST['floating_cart_points_label_prefix'] ?? 'Primești')),
            'floating_cart_points_label_suffix' => trim((string) ($_POST['floating_cart_points_label_suffix'] ?? 'puncte la această comandă')),
            'floating_cart_excluded_urls' => $excludedUrls,
        ]);

        Flash::set('success', 'Setările pentru Coș flotant au fost salvate.');
        header('Location: /admin/settings/floating-cart');
    }

    /**
     * Câte produse are fiecare cotă de TVA, ca operatorul să vadă înainte de
     * schimbare pe ce anume apasă.
     *
     * @return array<int, array{cota: float, total: int}>
     */
    private function tvaProduseSumar(PDO $db): array
    {
        try {
            $rows = $db->query(
                'SELECT vat_percent AS cota, COUNT(*) AS total
                   FROM products
                  WHERE deleted_at IS NULL
                  GROUP BY vat_percent
                  ORDER BY vat_percent ASC'
            )->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        $sumar = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sumar[] = [
                'cota' => round((float) ($row['cota'] ?? 0), 2),
                'total' => (int) ($row['total'] ?? 0),
            ];
        }

        return $sumar;
    }

    /**
     * Schimbă cota de TVA pe toate produsele care o au pe cea veche.
     *
     * „pastreaza_pret": prețul de raft rămâne neatins, se schimbă doar
     * împărțirea lui între net și TVA — magazinul absoarbe diferența.
     * „pastreaza_net": prețurile cu TVA cresc, ca suma rămasă magazinului
     * după TVA să fie aceeași ca înainte.
     *
     * @return array{produse: int, preturi: int}
     */
    private function schimbaTvaProduse(PDO $db, float $deLa, float $la, string $mod): array
    {
        $rezultat = ['produse' => 0, 'preturi' => 0];

        if ($mod !== 'pastreaza_net') {
            $stmt = $db->prepare(
                'UPDATE products SET vat_percent = :la
                  WHERE vat_percent = :de_la AND deleted_at IS NULL'
            );
            $stmt->execute(['la' => $la, 'de_la' => $deLa]);
            $rezultat['produse'] = $stmt->rowCount();

            return $rezultat;
        }

        $factor = (1.0 + ($la / 100.0)) / (1.0 + ($deLa / 100.0));

        $citire = $db->prepare(
            'SELECT id, price, sale_price, sale_price_periods_json, bbd_entries_json, vat_included
               FROM products
              WHERE vat_percent = :de_la AND deleted_at IS NULL'
        );
        $citire->execute(['de_la' => $deLa]);
        $produse = $citire->fetchAll() ?: [];

        $scriere = $db->prepare(
            'UPDATE products
                SET vat_percent = :vat_percent,
                    price = :price,
                    sale_price = :sale_price,
                    sale_price_periods_json = :periods,
                    bbd_entries_json = :bbd
              WHERE id = :id'
        );

        $db->beginTransaction();
        try {
            foreach ($produse as $produs) {
                if (!is_array($produs)) {
                    continue;
                }
                $id = (int) ($produs['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                // La prețurile fără TVA, valoarea stocată e deja cea netă:
                // se schimbă doar cota, nu și prețul.
                $areTva = ((int) ($produs['vat_included'] ?? 1)) === 1;

                $pret = max(0.0, (float) ($produs['price'] ?? 0.0));
                $pretNou = $areTva ? round($pret * $factor, 2) : $pret;

                $saleRaw = $produs['sale_price'] ?? null;
                $saleNou = null;
                if ($saleRaw !== null && $saleRaw !== '') {
                    $sale = max(0.0, (float) $saleRaw);
                    $saleNou = $areTva ? round($sale * $factor, 2) : $sale;
                }

                $periods = $areTva
                    ? $this->recalculeazaPreturiJson((string) ($produs['sale_price_periods_json'] ?? ''), 'sale_price', $factor)
                    : ($produs['sale_price_periods_json'] ?? null);
                $bbd = $areTva
                    ? $this->recalculeazaPreturiJson((string) ($produs['bbd_entries_json'] ?? ''), 'reduced_price', $factor)
                    : ($produs['bbd_entries_json'] ?? null);

                $scriere->execute([
                    'vat_percent' => $la,
                    'price' => $pretNou,
                    'sale_price' => $saleNou,
                    'periods' => $periods,
                    'bbd' => $bbd,
                    'id' => $id,
                ]);
                $rezultat['produse']++;
                if ($areTva) {
                    $rezultat['preturi']++;
                }
            }
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }

        return $rezultat;
    }

    /**
     * Înmulțește cu un factor prețurile dintr-o listă JSON de produs
     * (perioade de reducere, intrări BBD). Un JSON pe care nu-l putem citi
     * rămâne neatins — mai bine nemodificat decât stricat.
     */
    private function recalculeazaPreturiJson(string $json, string $cheiePret, float $factor): ?string
    {
        $curat = trim($json);
        if ($curat === '') {
            return $json === '' ? null : $json;
        }

        $decodat = json_decode($curat, true);
        if (!is_array($decodat)) {
            return $json;
        }

        $modificat = false;
        foreach ($decodat as $index => $intrare) {
            if (!is_array($intrare) || !isset($intrare[$cheiePret])) {
                continue;
            }
            $valoare = $intrare[$cheiePret];
            if ($valoare === null || $valoare === '' || !is_numeric((string) $valoare)) {
                continue;
            }
            $decodat[$index][$cheiePret] = round(((float) $valoare) * $factor, 2);
            $modificat = true;
        }

        if (!$modificat) {
            return $json;
        }

        $reincodat = json_encode($decodat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $reincodat === false ? $json : $reincodat;
    }

    public function storeSettingsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/store');
            return;
        }
        $this->ensureOptionalSchema($db);
        $settings = Settings::all($db);

        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'generate_pages') {
            [$created, $restored] = $this->generateStorePages($db);
            $this->refreshCacheAfterPublicContentChange();
            Flash::set('success', 'Pagini magazin generate: ' . $created . ' noi, ' . $restored . ' restaurate.');
            header('Location: /admin/settings/store');
            return;
        }
        if ($action === 'generate_sitemap') {
            $sitemapFilename = $this->normalizeSitemapFilename((string) ($_POST['sitemap_filename'] ?? ''));
            Settings::save($db, [
                'store_sitemap_filename' => $sitemapFilename,
            ]);
            $result = $this->generateSitemap($db, $sitemapFilename);
            $this->refreshCacheAfterPublicContentChange();
            Flash::set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Nu am putut genera sitemap-ul.'));
            header('Location: /admin/settings/store');
            return;
        }
        if ($action === 'save_favicon') {
            $faviconUrl = trim((string) ($_POST['store_favicon_url'] ?? ''));
            if ($faviconUrl !== '' && preg_match('/^https?:\/\//i', $faviconUrl) !== 1 && !str_starts_with($faviconUrl, '/')) {
                $faviconUrl = '/' . ltrim($faviconUrl, '/');
            }
            Settings::save($db, [
                'store_favicon_url' => $faviconUrl,
            ]);
            Flash::set('success', 'Favicon-ul magazinului a fost salvat.');
            header('Location: /admin/settings/store');
            return;
        }
        if ($action === 'save_seo_defaults') {
            $seoImageUrl = trim((string) ($_POST['seo_default_image_url'] ?? ''));
            if ($seoImageUrl !== '' && preg_match('/^https?:\/\//i', $seoImageUrl) !== 1 && !str_starts_with($seoImageUrl, '/')) {
                $seoImageUrl = '/' . ltrim($seoImageUrl, '/');
            }
            Settings::save($db, [
                'store_seo_home_title' => trim((string) ($_POST['seo_home_title'] ?? '')),
                'store_seo_home_description' => trim((string) ($_POST['seo_home_description'] ?? '')),
                'store_seo_default_description' => trim((string) ($_POST['seo_default_description'] ?? '')),
                'store_seo_default_image_url' => $seoImageUrl,
            ]);
            Flash::set('success', 'Setările SEO globale au fost salvate.');
            header('Location: /admin/settings/store');
            return;
        }
        if ($action === 'save_maintenance') {
            $activ = isset($_POST['maintenance_enabled']);
            $cheie = trim((string) ($settings['maintenance_key'] ?? ''));
            if ($cheie === '') {
                // Fără cheie nu se poate previzualiza; o generăm la prima salvare.
                $cheie = \App\Support\Maintenance::cheieNoua();
            }
            Settings::save($db, [
                'maintenance_enabled' => $activ ? '1' : '0',
                'maintenance_title' => trim((string) ($_POST['maintenance_title'] ?? '')),
                'maintenance_message' => trim((string) ($_POST['maintenance_message'] ?? '')),
                'maintenance_allowed_ips' => trim((string) ($_POST['maintenance_allowed_ips'] ?? '')),
                'maintenance_key' => $cheie,
            ]);
            // Paginile salvate dinainte nu mai sunt valabile în niciun sens.
            $this->refreshCacheAfterPublicContentChange();
            Flash::set(
                'success',
                $activ
                    ? 'Modul mentenanță este activ. Vizitatorii văd pagina „Revenim în curând".'
                    : 'Modul mentenanță a fost dezactivat. Site-ul este public.'
            );
            header('Location: /admin/settings/store?tab=maintenance');
            return;
        }
        if ($action === 'save_vat_bulk') {
            $deLa = round((float) str_replace(',', '.', trim((string) ($_POST['vat_from'] ?? ''))), 2);
            $la = round((float) str_replace(',', '.', trim((string) ($_POST['vat_to'] ?? ''))), 2);
            $mod = (string) ($_POST['vat_mode'] ?? 'pastreaza_pret') === 'pastreaza_net'
                ? 'pastreaza_net'
                : 'pastreaza_pret';

            if ($deLa < 0 || $deLa > 100 || $la < 0 || $la > 100) {
                Flash::set('error', 'Cotele de TVA trebuie să fie între 0 și 100.');
                header('Location: /admin/settings/store?tab=tva');
                return;
            }
            if (abs($deLa - $la) < 0.001) {
                Flash::set('error', 'Cota nouă este identică cu cea veche; nu am schimbat nimic.');
                header('Location: /admin/settings/store?tab=tva');
                return;
            }

            try {
                $rezultat = $this->schimbaTvaProduse($db, $deLa, $la, $mod);
            } catch (Throwable) {
                Flash::set('error', 'Schimbarea TVA a eșuat; nu s-a modificat niciun produs.');
                header('Location: /admin/settings/store?tab=tva');
                return;
            }

            if (isset($_POST['vat_set_default'])) {
                Settings::save($db, ['default_vat_percent' => (string) $la]);
            }

            $this->refreshCacheAfterPublicContentChange();

            $mesaj = 'Am trecut ' . $rezultat['produse'] . ' produse de la '
                . rtrim(rtrim(number_format($deLa, 2, '.', ''), '0'), '.') . '% la '
                . rtrim(rtrim(number_format($la, 2, '.', ''), '0'), '.') . '%.';
            $mesaj .= $mod === 'pastreaza_net'
                ? ' Prețurile afișate au fost recalculate pentru ' . $rezultat['preturi'] . ' produse.'
                : ' Prețurile afișate au rămas neschimbate.';
            Flash::set($rezultat['produse'] > 0 ? 'success' : 'error', $rezultat['produse'] > 0
                ? $mesaj
                : 'Niciun produs nu avea cota ' . rtrim(rtrim(number_format($deLa, 2, '.', ''), '0'), '.') . '%.');
            header('Location: /admin/settings/store?tab=tva');
            return;
        }
        if ($action === 'save_order_numbering') {
            $mod = trim((string) ($_POST['order_number_mode'] ?? \App\Support\OrderNumber::MOD_SECVENTIAL));
            if (!in_array($mod, [\App\Support\OrderNumber::MOD_SECVENTIAL, \App\Support\OrderNumber::MOD_VECHI], true)) {
                $mod = \App\Support\OrderNumber::MOD_SECVENTIAL;
            }
            $urmator = \App\Support\OrderNumber::normalizeazaStart($_POST['order_number_next'] ?? null);
            Settings::save($db, [
                'order_number_mode' => $mod,
                'order_number_next' => (string) $urmator,
            ]);
            Flash::set(
                'success',
                $mod === \App\Support\OrderNumber::MOD_SECVENTIAL
                    ? 'Numerotare salvată. Următoarea comandă va primi numărul ' . $urmator . '.'
                    : 'Numerotare salvată. Comenzile revin la formatul vechi (BV + dată).'
            );
            header('Location: /admin/settings/store?tab=numerotare');
            return;
        }
        if ($action === 'save_cookie_banner') {
            $linkPolitica = trim((string) ($_POST['cookie_banner_policy_url'] ?? ''));
            if ($linkPolitica !== '' && !preg_match('#^(https?://|/)#i', $linkPolitica)) {
                $linkPolitica = '/' . ltrim($linkPolitica, '/');
            }
            Settings::save($db, [
                'cookie_banner_enabled' => isset($_POST['cookie_banner_enabled']) ? '1' : '0',
                'cookie_banner_text' => trim((string) ($_POST['cookie_banner_text'] ?? '')),
                'cookie_banner_policy_url' => $linkPolitica,
            ]);
            // Paginile din cache conțin varianta veche a bannerului.
            ResponseCache::purgePageCache();
            Flash::set('success', 'Bannerul de cookies a fost salvat.');
            header('Location: /admin/settings/store?tab=cookies');
            return;
        }
        if ($action === 'save_welcome_popup') {
            $titluNou = trim((string) ($_POST['welcome_popup_title'] ?? ''));
            $textNou = trim(str_replace("\r\n", "\n", (string) ($_POST['welcome_popup_body'] ?? '')));
            $curente = Settings::all($db);
            $versiune = max(1, (int) ($curente['welcome_popup_version'] ?? 1));
            // Mesaj schimbat → versiune nouă, ca popup-ul să reapară și la
            // vizitatorii care l-au închis pe cel vechi.
            if ($titluNou !== trim((string) ($curente['welcome_popup_title'] ?? ''))
                || $textNou !== trim(str_replace("\r\n", "\n", (string) ($curente['welcome_popup_body'] ?? '')))) {
                $versiune++;
            }
            Settings::save($db, [
                'welcome_popup_enabled' => isset($_POST['welcome_popup_enabled']) ? '1' : '0',
                'welcome_popup_title' => $titluNou,
                'welcome_popup_body' => $textNou,
                'welcome_popup_version' => (string) $versiune,
            ]);
            Flash::set('success', 'Popup-ul de anunț a fost salvat.');
            header('Location: /admin/settings/store?tab=popup');
            return;
        }
        if ($action === 'regenerate_maintenance_key') {
            Settings::save($db, ['maintenance_key' => \App\Support\Maintenance::cheieNoua()]);
            Flash::set('success', 'Cheie nouă generată. Linkurile trimise anterior nu mai funcționează.');
            header('Location: /admin/settings/store?tab=maintenance');
            return;
        }
        if ($action === 'save_chat_settings') {
            // Identificatorii se curăță prin acelasi filtru folosit la randare:
            // dacă cineva lipeste tot linkul de instalare, luăm doar ce e
            // folosibil, iar în pagină nu ajunge text neverificat.
            $chatProperty = \App\Support\ChatLive::curataId((string) ($_POST['tawk_property_id'] ?? ''));
            $chatWidget = \App\Support\ChatLive::curataId((string) ($_POST['tawk_widget_id'] ?? 'default'));
            $chatPozitie = in_array((string) ($_POST['tawk_position'] ?? 'br'), \App\Support\ChatLive::POZITII, true)
                ? (string) ($_POST['tawk_position'] ?? 'br')
                : 'br';
            $chatApiKey = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) ($_POST['tawk_api_key'] ?? ''))) ?? '', 0, 128);
            // Trecem prin acelasi limitator ca la randare, ca valoarea salvată
            // să fie exact cea folosită — fără surprize între ce vezi și ce iese.
            $chatOffsetY = \App\Support\ChatLive::offsetY(['tawk_offset_y' => $_POST['tawk_offset_y'] ?? \App\Support\ChatLive::OFFSET_IMPLICIT]);
            $chatPornit = isset($_POST['tawk_enabled']) && $chatProperty !== '';
            Settings::save($db, [
                'tawk_enabled' => $chatPornit ? '1' : '0',
                'tawk_property_id' => $chatProperty,
                'tawk_widget_id' => $chatWidget !== '' ? $chatWidget : 'default',
                'tawk_position' => $chatPozitie,
                'tawk_offset_y' => (string) $chatOffsetY,
                'tawk_requires_consent' => (string) ($_POST['tawk_requires_consent'] ?? '1') === '0' ? '0' : '1',
                'tawk_api_key' => $chatApiKey,
            ]);
            // Paginile salvate în cache încă poartă varianta veche a chatului.
            ResponseCache::purgePageCache();
            if (isset($_POST['tawk_enabled']) && $chatProperty === '') {
                Flash::set('error', 'Chatul nu a fost activat: completează întâi Property ID-ul din tawk.to.');
            } else {
                Flash::set('success', 'Setările de chat live au fost salvate.');
            }
            header('Location: /admin/settings/store?tab=chat');
            return;
        }
        if ($action === 'save_clarity_settings') {
            $clarityProjectId = preg_replace('/[^a-zA-Z0-9]/', '', trim((string) ($_POST['microsoft_clarity_project_id'] ?? ''))) ?? '';
            if (strlen($clarityProjectId) > 64) {
                $clarityProjectId = substr($clarityProjectId, 0, 64);
            }
            Settings::save($db, [
                'microsoft_clarity_enabled' => isset($_POST['microsoft_clarity_enabled']) ? '1' : '0',
                'microsoft_clarity_project_id' => $clarityProjectId,
            ]);
            Flash::set('success', 'Setările Microsoft Clarity au fost salvate.');
            header('Location: /admin/settings/store?tab=clarity');
            return;
        }
        if ($action === 'save_caching_settings') {
            $paths = is_array($_POST['cache_page_paths'] ?? null) ? (array) $_POST['cache_page_paths'] : [];
            $ttls = is_array($_POST['cache_page_ttls'] ?? null) ? (array) $_POST['cache_page_ttls'] : [];
            $rules = $this->normalizeCachePageRulesFromRequest($paths, $ttls);
            $assetsTtl = ResponseCache::normalizeTtlSeconds($_POST['cache_assets_ttl_seconds'] ?? 86400, 86400);
            $uploadsTtl = ResponseCache::normalizeTtlSeconds($_POST['cache_uploads_ttl_seconds'] ?? 604800, 604800);
            $versioningMode = ResponseCache::normalizeAssetVersioningMode((string) ($_POST['cache_assets_versioning_mode'] ?? 'none'));
            if ($versioningMode === 'none' && isset($_POST['cache_assets_versioning'])) {
                $versioningMode = 'query';
            }

            $savePayload = [
                'cache_pages_enabled' => isset($_POST['cache_pages_enabled']) ? '1' : '0',
                'cache_pages_rules_json' => json_encode(array_values($rules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                'cache_exclusions_custom' => ResponseCache::normalizeCustomExclusions((string) ($_POST['cache_exclusions_custom'] ?? '')),
                'cache_assets_enabled' => isset($_POST['cache_assets_enabled']) ? '1' : '0',
                'cache_assets_ttl_seconds' => (string) $assetsTtl,
                'cache_uploads_ttl_seconds' => (string) $uploadsTtl,
                'cache_assets_versioning_mode' => $versioningMode,
                'cache_assets_etag_enabled' => isset($_POST['cache_assets_etag_enabled']) ? '1' : '0',
            ];

            $currentVersionToken = trim((string) ($settings['cache_assets_version_token'] ?? ''));
            if ($versioningMode !== 'none' && ($currentVersionToken === '' || isset($_POST['regenerate_assets_version_token']))) {
                $savePayload['cache_assets_version_token'] = ResponseCache::generateAssetVersionToken();
            }
            if ($versioningMode === 'none') {
                $savePayload['cache_assets_version_token'] = '';
            }

            Settings::save($db, $savePayload);
            $settings = array_merge($settings, $savePayload);
            $this->syncAssetCacheHtaccess($settings);

            Flash::set('success', 'Setările de caching au fost salvate.');
            header('Location: /admin/settings/store?tab=caching');
            return;
        }
        if ($action === 'purge_cache_page') {
            $pattern = ResponseCache::normalizePathPattern((string) ($_POST['purge_cache_path'] ?? ''));
            if ($pattern === '') {
                Flash::set('error', 'Introdu un URL/pattern valid pentru invalidare.');
                header('Location: /admin/settings/store?tab=caching');
                return;
            }
            $purged = ResponseCache::purgePageCache($pattern);
            Flash::set('success', 'Cache invalidat pentru ' . $pattern . ' (' . $purged . ' intrări).');
            header('Location: /admin/settings/store?tab=caching');
            return;
        }
        if ($action === 'purge_cache_pages') {
            $purged = ResponseCache::purgePageCache();
            Flash::set('success', 'Cache pagini golit (' . $purged . ' intrări).');
            header('Location: /admin/settings/store?tab=caching');
            return;
        }
        if ($action === 'purge_cache_assets') {
            $removed = ResponseCache::purgeAssetCache();
            $versioningMode = ResponseCache::normalizeAssetVersioningMode((string) ($settings['cache_assets_versioning_mode'] ?? 'none'));
            if ($versioningMode !== 'none') {
                Settings::save($db, [
                    'cache_assets_version_token' => ResponseCache::generateAssetVersionToken(),
                ]);
            }
            Flash::set('success', 'Cache assets golit (' . $removed . ' fișiere).');
            header('Location: /admin/settings/store?tab=caching');
            return;
        }
        if ($action === 'purge_cache_all') {
            $purgedPages = ResponseCache::purgePageCache();
            $purgedAssets = ResponseCache::purgeAssetCache();
            $versioningMode = ResponseCache::normalizeAssetVersioningMode((string) ($settings['cache_assets_versioning_mode'] ?? 'none'));
            if ($versioningMode !== 'none') {
                Settings::save($db, [
                    'cache_assets_version_token' => ResponseCache::generateAssetVersionToken(),
                ]);
            }
            Flash::set('success', 'Tot cache-ul a fost golit (pagini: ' . $purgedPages . ', assets: ' . $purgedAssets . ').');
            header('Location: /admin/settings/store?tab=caching');
            return;
        }

        if ($action === 'save_bbd_sidebar') {
            Settings::save($db, [
                'store_bbd_sidebar_enabled' => isset($_POST['store_bbd_sidebar_enabled']) ? '1' : '0',
            ]);
            $this->refreshCacheAfterPublicContentChange();
            Flash::set(
                'success',
                isset($_POST['store_bbd_sidebar_enabled'])
                    ? 'Sertarul „Oferte” este activ pe site.'
                    : 'Sertarul „Oferte” a fost dezactivat.'
            );
            header('Location: /admin/settings/store?tab=quantity');
            return;
        }

        $quantityStyle = in_array((string) ($_POST['store_quantity_control_style'] ?? 'default'), ['default', 'stepper'], true)
            ? (string) ($_POST['store_quantity_control_style'] ?? 'default')
            : 'default';
        Settings::save($db, [
            'store_quantity_control_style' => $quantityStyle,
            'store_quantity_apply_product_template' => isset($_POST['store_quantity_apply_product_template']) ? '1' : '0',
            'store_quantity_apply_floating_cart' => isset($_POST['store_quantity_apply_floating_cart']) ? '1' : '0',
            'store_quantity_apply_cart_page' => isset($_POST['store_quantity_apply_cart_page']) ? '1' : '0',
        ]);
        Flash::set('success', 'Setările magazinului au fost salvate.');

        header('Location: /admin/settings/store');
    }

    public function adminsSettingsForm(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!Auth::isGeneralAdmin()) {
            Flash::set('error', 'Doar Administratorul General poate gestiona conturile de admin.');
            header('Location: ' . Auth::defaultPathForRole());
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin');
            return;
        }
        $this->ensureOptionalSchema($db);

        $admins = $db->query(
            'SELECT id, email, role, roles_json, created_at
             FROM admins
             ORDER BY id ASC'
        )->fetchAll() ?: [];
        foreach ($admins as &$admin) {
            if (!is_array($admin)) {
                continue;
            }
            $admin['roles'] = Auth::rolesFromStorage(
                (string) ($admin['role'] ?? Auth::ROLE_GENERAL),
                $admin['roles_json'] ?? null
            );
        }
        unset($admin);

        View::render('admin/settings-admins', [
            'title' => 'Administratori',
            'admins' => $admins,
            'roles' => Auth::roles(),
            'currentAdminId' => Auth::id(),
        ], 'admin/layout');
    }

    public function adminsSettingsSave(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!Auth::isGeneralAdmin()) {
            Flash::set('error', 'Doar Administratorul General poate modifica rolurile de admin.');
            header('Location: ' . Auth::defaultPathForRole());
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/admins');
            return;
        }
        $this->ensureOptionalSchema($db);

        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'create_admin') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $roles = $this->adminRolesFromInput($_POST, Auth::ROLE_STORE);
            $role = Auth::primaryRole($roles);
            $rolesJson = json_encode($roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($rolesJson) || $rolesJson === '') {
                $rolesJson = json_encode([Auth::ROLE_STORE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Flash::set('error', 'Email invalid pentru contul de admin.');
                header('Location: /admin/settings/admins');
                return;
            }
            if (strlen($password) < 8) {
                Flash::set('error', 'Parola trebuie să aibă minimum 8 caractere.');
                header('Location: /admin/settings/admins');
                return;
            }

            try {
                $stmt = $db->prepare(
                    'INSERT INTO admins (email, password_hash, role, roles_json)
                     VALUES (:email, :password_hash, :role, :roles_json)'
                );
                $stmt->execute([
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'role' => $role,
                    'roles_json' => $rolesJson,
                ]);
                Flash::set('success', 'Contul de admin a fost creat.');
            } catch (Throwable) {
                Flash::set('error', 'Nu am putut crea contul de admin (email duplicat sau date invalide).');
            }
            header('Location: /admin/settings/admins');
            return;
        }

        if ($action === 'update_role') {
            $adminId = max(0, (int) ($_POST['admin_id'] ?? 0));
            $roles = $this->adminRolesFromInput($_POST, Auth::ROLE_STORE);
            $role = Auth::primaryRole($roles);
            $rolesJson = json_encode($roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($rolesJson) || $rolesJson === '') {
                $rolesJson = json_encode([Auth::ROLE_STORE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($adminId <= 0) {
                Flash::set('error', 'Administrator invalid.');
                header('Location: /admin/settings/admins');
                return;
            }

            $currentRoleStmt = $db->prepare('SELECT role, roles_json FROM admins WHERE id = :id LIMIT 1');
            $currentRoleStmt->execute(['id' => $adminId]);
            $currentRow = $currentRoleStmt->fetch() ?: [];
            $currentRoles = Auth::rolesFromStorage(
                (string) ($currentRow['role'] ?? Auth::ROLE_GENERAL),
                $currentRow['roles_json'] ?? null
            );
            if (
                in_array(Auth::ROLE_GENERAL, $currentRoles, true)
                && !in_array(Auth::ROLE_GENERAL, $roles, true)
                && $this->countGeneralAdmins($db) <= 1
            ) {
                Flash::set('error', 'Trebuie să existe cel puțin un Administrator General.');
                header('Location: /admin/settings/admins');
                return;
            }

            $update = $db->prepare('UPDATE admins SET role = :role, roles_json = :roles_json WHERE id = :id');
            $update->execute([
                'role' => $role,
                'roles_json' => $rolesJson,
                'id' => $adminId,
            ]);

            if ($adminId === Auth::id()) {
                $_SESSION['admin_roles'] = $roles;
                $_SESSION['admin_role'] = $role;
            }
            Flash::set('success', 'Rolurile administratorului au fost actualizate.');
            header('Location: /admin/settings/admins');
            return;
        }

        if ($action === 'update_password') {
            $adminId = max(0, (int) ($_POST['admin_id'] ?? 0));
            $password = (string) ($_POST['password'] ?? '');
            if ($adminId <= 0) {
                Flash::set('error', 'Administrator invalid.');
                header('Location: /admin/settings/admins');
                return;
            }
            if (strlen($password) < 8) {
                Flash::set('error', 'Parola nouă trebuie să aibă minimum 8 caractere.');
                header('Location: /admin/settings/admins');
                return;
            }

            $update = $db->prepare('UPDATE admins SET password_hash = :password_hash WHERE id = :id');
            $update->execute([
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'id' => $adminId,
            ]);
            Flash::set('success', 'Parola administratorului a fost actualizată.');
            header('Location: /admin/settings/admins');
            return;
        }

        if ($action === 'delete_admin') {
            $adminId = max(0, (int) ($_POST['admin_id'] ?? 0));
            if ($adminId <= 0) {
                Flash::set('error', 'Administrator invalid.');
                header('Location: /admin/settings/admins');
                return;
            }
            if ($adminId === Auth::id()) {
                Flash::set('error', 'Nu îți poți șterge propriul cont din această sesiune.');
                header('Location: /admin/settings/admins');
                return;
            }

            $roleStmt = $db->prepare('SELECT role, roles_json FROM admins WHERE id = :id LIMIT 1');
            $roleStmt->execute(['id' => $adminId]);
            $roleRow = $roleStmt->fetch() ?: [];
            $currentRoles = Auth::rolesFromStorage(
                (string) ($roleRow['role'] ?? Auth::ROLE_GENERAL),
                $roleRow['roles_json'] ?? null
            );
            if (in_array(Auth::ROLE_GENERAL, $currentRoles, true) && $this->countGeneralAdmins($db) <= 1) {
                Flash::set('error', 'Trebuie să existe cel puțin un Administrator General.');
                header('Location: /admin/settings/admins');
                return;
            }

            $delete = $db->prepare('DELETE FROM admins WHERE id = :id');
            $delete->execute(['id' => $adminId]);
            Flash::set('success', 'Contul de admin a fost șters.');
            header('Location: /admin/settings/admins');
            return;
        }

        Flash::set('error', 'Acțiune invalidă.');
        header('Location: /admin/settings/admins');
    }

    public function mannequinSettingsForm(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/store');
            return;
        }

        $this->ensureOptionalSchema($db);
        $settings = Settings::all($db);
        $points = $this->decodeMannequinPoints((string) ($settings['mannequin_points_json'] ?? '[]'));
        $products = $this->loadMannequinProducts($db);

        View::render('admin/settings-mannequin', [
            'title' => 'Configurare manechin',
            'settings' => $settings,
            'points' => $points,
            'products' => $products,
        ], 'admin/layout');
    }

    public function mannequinSettingsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/mannequin');
            return;
        }

        $this->ensureOptionalSchema($db);
        $settings = Settings::all($db);
        $points = $this->decodeMannequinPoints((string) ($_POST['mannequin_points_json'] ?? '[]'));

        Settings::save($db, [
            'mannequin_enabled' => isset($_POST['mannequin_enabled']) ? '1' : '0',
            'mannequin_title' => trim((string) ($_POST['mannequin_title'] ?? ($settings['mannequin_title'] ?? 'Recomandări pe zone'))),
            'mannequin_subtitle' => trim((string) ($_POST['mannequin_subtitle'] ?? ($settings['mannequin_subtitle'] ?? 'Alege un punct de pe manechin pentru a vedea produsele recomandate.'))),
            'mannequin_empty_text' => trim((string) ($_POST['mannequin_empty_text'] ?? ($settings['mannequin_empty_text'] ?? 'Nu sunt produse pentru această categorie.'))),
            'mannequin_code' => '{{mannequin_section}}',
            'mannequin_points_json' => json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        Flash::set('success', 'Configurarea manechinului a fost salvată.');
        header('Location: /admin/settings/mannequin');
    }

    public function shippingSettingsForm(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
        }
        $settings = Settings::all($db);
        $localitiesCount = $this->fanLocalitiesCount($db);
        $streetsCount = $this->fanStreetsCount($db);
        $kmLocalitiesCount = $this->fanLocalitiesExtraKmCount($db);

        // Depozitele (gestiunile) legate de site în ERP, pentru blocurile de
        // adresă per depozit. Dacă ERP-ul nu răspunde, secțiunea nu apare.
        $erpDepozite = [];
        $erpGestiuniPeJudete = false;
        if ($db instanceof PDO) {
            $mapare = \App\Support\ErpShipping::gestiuni($db);
            if (is_array($mapare)) {
                $erpDepozite = $mapare['gestiuni'];
                $erpGestiuniPeJudete = (bool) ($mapare['gestiuniPeJudete'] ?? false);
            }
        }

        View::render('admin/settings-shipping', [
            'title' => 'Setări livrare',
            'settings' => $settings,
            'fanLocalitiesCount' => $localitiesCount,
            'fanStreetsCount' => $streetsCount,
            'fanExtraKmCount' => $kmLocalitiesCount,
            'fanLockersCount' => \App\Support\FanLockers::numar($db),
            'fanLockersCuCoordonate' => \App\Support\FanLockers::numarCuCoordonate($db),
            'fanLockersCuIdFan' => \App\Support\FanLockers::numarCuIdFan($db),
            'fanLockersJudete' => \App\Support\FanLockers::peJudete($db),
            'fanJudete' => $this->fanCountyList($db),
            'erpDepozite' => $erpDepozite,
            'erpGestiuniPeJudete' => $erpGestiuniPeJudete,
            'shippingTab' => $this->normalizeShippingSettingsTab((string) ($_GET['tab'] ?? 'fan-localities')),
        ], 'admin/layout');
    }

    /**
     * Județele din nomenclatorul FAN importat pe site, pentru dropdown-urile
     * de adrese (expeditor global și depozite).
     *
     * @return string[]
     */
    private function fanCountyList(?PDO $db): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        try {
            $rows = $db->query('SELECT county FROM fan_localities GROUP BY county ORDER BY county ASC')->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $county = trim((string) ($row['county'] ?? ''));
            if ($county !== '') {
                $out[] = $county;
            }
        }
        return $out;
    }

    /**
     * Localitățile FAN dintr-un județ, pentru câmpurile de adresă din admin.
     *
     * Spre deosebire de ruta publică folosită la checkout, aici NU se adaugă
     * variantele de sector: adresa expeditorului merge la FAN exact cum e
     * salvată, deci se pot alege doar denumiri care există în nomenclator.
     */
    public function shippingLocalitiesSearch(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        // Verificare fără redirect: e o rută de date, apelată din JS.
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/admin/settings/shipping';
        if (!Auth::check() || !Auth::canAccessPath($path)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'items' => []]);
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'items' => []]);
            return;
        }
        $this->ensureOptionalSchema($db);

        $county = trim((string) ($_GET['county'] ?? ''));
        $query = trim((string) ($_GET['q'] ?? ''));
        $items = [];

        // Căutarea merge pe coloanele normalizate, deci diacriticele și
        // majusculele scrise de operator nu contează.
        $countyNorm = $this->normalizeFanLocalityToken($county);
        $countyNorm = (string) preg_replace('/^(judetul|judet|municipiul)\s+/', '', $countyNorm);
        if (str_contains($countyNorm, 'bucuresti')) {
            $countyNorm = 'bucuresti';
        }
        $queryNorm = $this->normalizeFanLocalityToken($query);

        try {
            $stmt = $db->prepare(
                'SELECT locality
                 FROM fan_localities
                 WHERE county_norm = :county_norm
                   AND (:has_query = 0 OR locality_norm LIKE :like)
                 ORDER BY locality ASC
                 LIMIT 50'
            );
            $stmt->bindValue(':county_norm', $countyNorm, PDO::PARAM_STR);
            $stmt->bindValue(':has_query', $queryNorm === '' ? 0 : 1, PDO::PARAM_INT);
            $stmt->bindValue(':like', '%' . $queryNorm . '%', PDO::PARAM_STR);
            $stmt->execute();
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $locality = trim((string) ($row['locality'] ?? ''));
                if ($locality !== '') {
                    $items[] = $locality;
                }
            }
        } catch (Throwable) {
            $items = [];
        }

        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Exportă localitățile din nomenclatorul FAN al site-ului, pentru importul
     * din ERP (Liste de referință → Localități). Două coloane: județ și
     * localitate, în grafia FAN — aceeași pe care o trimite site-ul la comenzi.
     */
    public function shippingLocalitatiExport(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/shipping');
            return;
        }
        $this->ensureOptionalSchema($db);

        try {
            $stmt = $db->query(
                'SELECT county, locality FROM fan_localities ORDER BY county ASC, locality ASC'
            );
        } catch (Throwable) {
            $stmt = null;
        }
        if ($stmt === null || $stmt === false) {
            Flash::set('error', 'Nu există localități FAN importate. Importă întâi localitățile FAN.');
            header('Location: /admin/settings/shipping');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="localitati-fan-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['judet', 'localitate'], ',');
        $scrise = 0;
        while (($row = $stmt->fetch()) !== false) {
            $county = trim((string) ($row['county'] ?? ''));
            $locality = trim((string) ($row['locality'] ?? ''));
            if ($county === '' || $locality === '') {
                continue;
            }
            fputcsv($out, [$county, $locality], ',');
            $scrise++;
        }
        fclose($out);
        if ($scrise === 0) {
            // Antetul a plecat deja; nu putem redirecta, dar fișierul e gol
            // și operatorul vede imediat că trebuie să importe localitățile.
            return;
        }
    }

    /**
     * Exportă județele din nomenclatorul FAN al site-ului, pentru importul din
     * ERP (Liste de referință → Județe → Import Județe). O coloană: numele.
     */
    public function shippingJudeteExport(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/shipping');
            return;
        }
        $this->ensureOptionalSchema($db);

        try {
            $rows = $db->query('SELECT county FROM fan_localities GROUP BY county ORDER BY county ASC')->fetchAll() ?: [];
        } catch (Throwable) {
            $rows = [];
        }
        if ($rows === []) {
            Flash::set('error', 'Nu există județe FAN importate. Importă întâi localitățile FAN.');
            header('Location: /admin/settings/shipping');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="judete-fan-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['nume'], ',');
        foreach ($rows as $row) {
            $county = trim((string) ($row['county'] ?? ''));
            if ($county !== '') {
                fputcsv($out, [$county], ',');
            }
        }
        fclose($out);
    }

    public function shippingSettingsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $shippingTab = $this->normalizeShippingSettingsTab((string) ($_POST['tab'] ?? $_GET['tab'] ?? 'delivery-settings'));
        $shippingPayer = trim((string) ($_POST['fan_shipping_payer'] ?? 'recipient'));
        if (!in_array($shippingPayer, ['recipient', 'sender', 'third_party'], true)) {
            $shippingPayer = 'recipient';
        }
        $packageType = trim((string) ($_POST['fan_shipment_type'] ?? 'parcel'));
        if (!in_array($packageType, ['parcel', 'envelope'], true)) {
            $packageType = 'parcel';
        }
        $parcelCount = max(0, (int) ($_POST['fan_parcel_count'] ?? 1));
        $envelopeCount = max(0, (int) ($_POST['fan_envelope_count'] ?? 0));
        if ($packageType === 'parcel' && $parcelCount <= 0) {
            $parcelCount = 1;
        }
        if ($packageType === 'envelope' && $envelopeCount <= 0) {
            $envelopeCount = 1;
        }
        // Adresa expeditorului merge la FAN exact cum e salvată, deci o aducem
        // la grafia din nomenclatorul FAN importat pe site.
        [$senderCounty, $senderLocality] = $this->fanCanonicalAddress(
            $db,
            trim((string) ($_POST['fan_sender_county'] ?? '')),
            trim((string) ($_POST['fan_sender_locality'] ?? ''))
        );
        Settings::save($db, [
            'shipping_include_coupons' => isset($_POST['shipping_include_coupons']) ? '1' : '0',
            'shipping_free_bucharest' => trim((string) ($_POST['shipping_free_bucharest'] ?? '200')),
            'shipping_free_province' => trim((string) ($_POST['shipping_free_province'] ?? '200')),
            // Manual tariffs are disabled; force FAN live mode.
            'shipping_cost_bucharest' => '0',
            'shipping_cost_province' => '0',
            'shipping_max_cost' => '0',
            // Prețuri fixe de transport (au prioritate față de tariful FAN).
            'shipping_fixed_enabled' => isset($_POST['shipping_fixed_enabled']) ? '1' : '0',
            'shipping_fixed_base' => $this->sumaPozitiva($_POST['shipping_fixed_base'] ?? null),
            'shipping_fixed_extra_km' => $this->sumaPozitiva($_POST['shipping_fixed_extra_km'] ?? null),
            'shipping_fixed_fanbox' => $this->sumaPozitiva($_POST['shipping_fixed_fanbox'] ?? null),
            'shipping_fixed_fanbox_enabled' => isset($_POST['shipping_fixed_fanbox_enabled']) ? '1' : '0',
            'fan_live_tariff_enabled' => isset($_POST['fan_live_tariff_enabled']) ? '1' : '0',
            'fan_awb_auto' => isset($_POST['fan_awb_auto']) ? '1' : '0',
            'fan_awb_single' => isset($_POST['fan_awb_single']) ? '1' : '0',
            'fan_service_type' => trim((string) ($_POST['fan_service_type'] ?? 'Standard')),
            'fan_service_type_ramburs' => trim((string) ($_POST['fan_service_type_ramburs'] ?? '')),
            'fan_service_type_fanbox_ramburs' => trim((string) ($_POST['fan_service_type_fanbox_ramburs'] ?? '')),
            'fan_service_type_fanbox' => trim((string) ($_POST['fan_service_type_fanbox'] ?? 'FANbox')),
            'fan_shipping_payer' => $shippingPayer,
            'fan_shipment_type' => $packageType,
            'fan_parcel_count' => (string) $parcelCount,
            'fan_envelope_count' => (string) $envelopeCount,
            'fan_option_codes' => $this->fanNormalizeSelectedOptions((array) ($_POST['fan_option_codes'] ?? [])),
            'fan_cod_bank' => mb_substr(trim((string) ($_POST['fan_cod_bank'] ?? '')), 0, 120),
            'fan_cod_iban' => strtoupper(
                preg_replace('/\s+/', '', trim((string) ($_POST['fan_cod_iban'] ?? ''))) ?? ''
            ),
            'fan_cod_payer' => in_array(
                trim((string) ($_POST['fan_cod_payer'] ?? 'sender')),
                ['sender', 'recipient', 'third_party'],
                true
            ) ? trim((string) ($_POST['fan_cod_payer'] ?? 'sender')) : 'sender',
            'fan_declared_value_mode' => in_array(
                trim((string) ($_POST['fan_declared_value_mode'] ?? 'order_total')),
                ['order_total', 'zero', 'none'],
                true
            ) ? trim((string) ($_POST['fan_declared_value_mode'] ?? 'order_total')) : 'order_total',
            'fan_pickup_point' => trim((string) ($_POST['fan_pickup_point'] ?? '')),
            'fan_client_id' => trim((string) ($_POST['fan_client_id'] ?? '')),
            'fan_api_username' => trim((string) ($_POST['fan_api_username'] ?? '')),
            'fan_api_password' => trim((string) ($_POST['fan_api_password'] ?? '')),
            'fan_sender_name' => trim((string) ($_POST['fan_sender_name'] ?? '')),
            'fan_sender_phone' => trim((string) ($_POST['fan_sender_phone'] ?? '')),
            'fan_sender_email' => trim((string) ($_POST['fan_sender_email'] ?? '')),
            'fan_sender_county' => $senderCounty,
            'fan_sender_locality' => $senderLocality,
            'fan_sender_street' => trim((string) ($_POST['fan_sender_street'] ?? '')),
            'fan_sender_street_no' => trim((string) ($_POST['fan_sender_street_no'] ?? '')),
            'fan_sender_zip_code' => trim((string) ($_POST['fan_sender_zip_code'] ?? '')),
            'fan_default_weight_kg' => trim((string) ($_POST['fan_default_weight_kg'] ?? '1')),
            'fan_parcel_length_cm' => trim((string) ($_POST['fan_parcel_length_cm'] ?? '')),
            'fan_parcel_width_cm' => trim((string) ($_POST['fan_parcel_width_cm'] ?? '')),
            'fan_parcel_height_cm' => trim((string) ($_POST['fan_parcel_height_cm'] ?? '')),
            'fan_depozite_json' => $this->fanDepoziteJsonFromInput($db, $_POST['fan_depozit'] ?? null),
        ]);

        Flash::set('success', 'Setările de livrare au fost salvate.');
        header('Location: /admin/settings/shipping?tab=' . rawurlencode($shippingTab));
    }

    /**
     * Adresele FAN per depozit, din formularul de livrare, ca JSON compact.
     * Depozitele fără niciun câmp completat nu se salvează.
     */
    private function fanDepoziteJsonFromInput(?PDO $db, mixed $input): string
    {
        if (!is_array($input)) {
            return '';
        }
        $out = [];
        foreach ($input as $gestiuneId => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $adresa = [];
            foreach (['name', 'phone', 'email', 'county', 'locality', 'street', 'street_no', 'zip_code'] as $key) {
                $value = trim((string) ($fields[$key] ?? ''));
                if ($value !== '') {
                    $adresa[$key] = $value;
                }
            }
            if ($adresa !== []) {
                [$adresa['county'], $adresa['locality']] = $this->fanCanonicalAddress(
                    $db,
                    (string) ($adresa['county'] ?? ''),
                    (string) ($adresa['locality'] ?? '')
                );
                $out[(string) $gestiuneId] = array_filter(
                    $adresa,
                    static fn (string $v): bool => trim($v) !== ''
                );
            }
        }
        return $out === [] ? '' : (string) json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Aduce perechea județ + localitate la grafia exactă din nomenclatorul FAN
     * importat pe site (diacritice, majuscule, cratime). Ce nu se potrivește
     * rămâne așa cum a fost scris — nu blocăm salvarea, dar FAN va reclama la
     * AWB, iar operatorul vede în formular exact ce a introdus.
     *
     * @return array{0: string, 1: string}
     */
    private function fanCanonicalAddress(?PDO $db, string $county, string $locality): array
    {
        $county = trim($county);
        $locality = trim($locality);
        if ($county === '' || !$db instanceof PDO) {
            return [$county, $locality];
        }

        $countyNorm = $this->normalizeFanLocalityToken($county);
        $countyNorm = (string) preg_replace('/^(judetul|judet|municipiul)\s+/', '', $countyNorm);
        if (str_contains($countyNorm, 'bucuresti')) {
            $countyNorm = 'bucuresti';
        }
        if ($countyNorm === '') {
            return [$county, $locality];
        }

        try {
            // Grafia oficială a județului, chiar dacă localitatea nu se potrivește.
            $stmtJudet = $db->prepare(
                'SELECT county FROM fan_localities WHERE county_norm = :county_norm LIMIT 1'
            );
            $stmtJudet->execute(['county_norm' => $countyNorm]);
            $randJudet = $stmtJudet->fetch() ?: null;
            $countyCanonic = is_array($randJudet) ? trim((string) ($randJudet['county'] ?? '')) : '';
            if ($countyCanonic === '') {
                return [$county, $locality];
            }
            if ($locality === '') {
                return [$countyCanonic, $locality];
            }

            $stmtLoc = $db->prepare(
                'SELECT locality FROM fan_localities
                 WHERE county_norm = :county_norm AND locality_norm = :locality_norm
                 LIMIT 1'
            );
            foreach ($this->fanLocalityCandidates($locality, $countyNorm) as $candidat) {
                $stmtLoc->execute([
                    'county_norm' => $countyNorm,
                    'locality_norm' => $candidat,
                ]);
                $rand = $stmtLoc->fetch() ?: null;
                $gasit = is_array($rand) ? trim((string) ($rand['locality'] ?? '')) : '';
                if ($gasit !== '') {
                    return [$countyCanonic, $gasit];
                }
            }

            return [$countyCanonic, $locality];
        } catch (Throwable) {
            return [$county, $locality];
        }
    }

    /**
     * Variantele normalizate sub care poate fi căutată o localitate: cum a fost
     * scrisă, fără prefix („Municipiul", „Comuna"), cu cratimă ↔ spațiu și,
     * pentru București, sectoarele reduse la „bucuresti" (FAN nu are sectoare
     * ca localități).
     *
     * @return string[]
     */
    private function fanLocalityCandidates(string $locality, string $countyNorm): array
    {
        $base = $this->normalizeFanLocalityToken($locality);
        if ($base === '') {
            return [];
        }

        $candidati = [$base];
        $faraPrefix = trim((string) preg_replace('/^(municipiul|orasul|oras|comuna|satul|sat)\s+/', '', $base));
        if ($faraPrefix !== '' && $faraPrefix !== $base) {
            $candidati[] = $faraPrefix;
        }
        foreach ($candidati as $candidat) {
            $candidati[] = str_replace(' ', '-', $candidat);
            $candidati[] = str_replace('-', ' ', $candidat);
        }
        if ($countyNorm === 'bucuresti'
            && (str_contains($base, 'sector') || preg_match('/^[1-6]$/', $base) === 1)
        ) {
            $candidati[] = 'bucuresti';
        }

        return array_values(array_unique(array_filter($candidati, static fn (string $v): bool => $v !== '')));
    }

    /** O sumă din formular, normalizată la un număr pozitiv cu 2 zecimale. */
    private function sumaPozitiva(mixed $raw): string
    {
        $text = str_replace(',', '.', trim((string) $raw));
        $valoare = is_numeric($text) ? (float) $text : 0.0;
        return number_format(max(0.0, $valoare), 2, '.', '');
    }

    private function fanNormalizeSelectedOptions(array $selected): string
    {
        $allowed = ['A', 'B', 'C', 'D', 'E', 'F', 'M', 'O', 'P', 'S', 'V', 'W', 'X', 'Y'];
        $result = [];
        foreach ($selected as $value) {
            $code = mb_strtoupper(trim((string) $value));
            if ($code === '' || !in_array($code, $allowed, true)) {
                continue;
            }
            $result[] = $code;
        }

        return implode(',', array_values(array_unique($result)));
    }

    public function shippingLocalitiesImport(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/shipping?tab=fan-localities');
            return;
        }
        $this->ensureOptionalSchema($db);
        $result = $this->importFanLocalitiesFromUploadedFile($db, $_FILES['fan_localities_file'] ?? null);
        Flash::set(
            ($result['ok'] ?? false) ? 'success' : 'error',
            (string) ($result['message'] ?? 'Import localități FAN finalizat.')
        );
        header('Location: /admin/settings/shipping?tab=fan-localities');
    }

    public function shippingStreetsImport(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/shipping?tab=fan-streets');
            return;
        }
        $this->ensureOptionalSchema($db);
        $result = $this->importFanStreetsFromUploadedFile($db, $_FILES['fan_streets_file'] ?? null);
        Flash::set(
            ($result['ok'] ?? false) ? 'success' : 'error',
            (string) ($result['message'] ?? 'Import lista străzi finalizat.')
        );
        header('Location: /admin/settings/shipping?tab=fan-streets');
    }

    public function shippingLocalitiesKmImport(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/shipping?tab=fan-extra-km');
            return;
        }
        $this->ensureOptionalSchema($db);
        $result = $this->importFanLocalitiesExtraKmFromUploadedFile($db, $_FILES['fan_localities_km_file'] ?? null);
        Flash::set(
            ($result['ok'] ?? false) ? 'success' : 'error',
            (string) ($result['message'] ?? 'Import listă localități km suplimentari finalizat.')
        );
        header('Location: /admin/settings/shipping?tab=fan-extra-km');
    }

    /** Import nomenclator puncte FANbox (CSV/XLSX primit de la FAN). */
    public function shippingFanboxImport(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }

        $file = $_FILES['fan_lockers_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Flash::set('error', 'Selectează fișierul CSV/XLSX cu punctele FANbox.');
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            Flash::set('error', 'Upload eșuat. Încearcă din nou.');
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }
        $marime = (int) ($file['size'] ?? 0);
        if ($marime <= 0 || $marime > self::FAN_LOCALITIES_UPLOAD_MAX_SIZE) {
            Flash::set('error', 'Fișier invalid sau prea mare (max 12MB).');
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Flash::set('error', 'Fișier invalid.');
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            Flash::set('error', 'Format neacceptat. Folosește CSV sau XLSX.');
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }

        $randuri = \App\Support\FanLockers::randuriDinFisier($tmp, $ext);
        if ($randuri === []) {
            Flash::set(
                'error',
                'Nu am găsit puncte valide. Fișierul trebuie să aibă un rând de antet cu cel puțin coloanele „judet" și „localitate" (opțional „cod", „denumire", „adresa").'
            );
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }

        $rezultat = \App\Support\FanLockers::inlocuieste($db, $randuri);
        $mesaj = 'Puncte FANbox importate: ' . $rezultat['importate'] . '.';
        if ($rezultat['dezactivate'] > 0) {
            $mesaj .= ' ' . $rezultat['dezactivate'] . ' puncte care nu mai apar în fișier au fost dezactivate.';
        }
        Flash::set('success', $mesaj);
        ResponseCache::purgePageCache();
        header('Location: /admin/settings/shipping?tab=fanbox');
    }

    /**
     * Aduce punctele FANbox direct din API-ul FAN.
     *
     * Fișierul primit de la ei nu conține id-ul punctului, iar fără id AWB-ul
     * pentru un colet la locker e respins („fanBoxIsInvalid"). Lista din API e
     * singura care are id-urile, așa că sincronizarea de aici e calea corectă,
     * iar importul din fișier rămâne doar pentru completat adrese.
     */
    public function fanLockersSync(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }

        $settings = Settings::all($db);
        $credentials = $this->fanCredentialsFromSettings($settings);
        if ($credentials === null) {
            Flash::set('error', 'Completează întâi datele de acces FAN (client ID, utilizator, parolă) în Setări livrare.');
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }

        try {
            $puncte = FanCourierGateway::pickupPoints($credentials, 'fanbox');
        } catch (Throwable $exception) {
            Flash::set('error', 'FAN nu a returnat lista de puncte: ' . $exception->getMessage());
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }

        if ($puncte === []) {
            Flash::set('error', 'FAN a returnat o listă goală de puncte FANbox. Verifică dacă contul are serviciul activ.');
            header('Location: /admin/settings/shipping?tab=fanbox');
            return;
        }

        $rezultat = \App\Support\FanLockers::sincronizeazaDinApi($db, $puncte);
        $mesaj = 'Puncte FANbox sincronizate din FAN: ' . $rezultat['importate']
            . ' (' . $rezultat['adaugate'] . ' noi, ' . $rezultat['actualizate'] . ' actualizate).';
        if ($rezultat['dezactivate'] > 0) {
            $mesaj .= ' ' . $rezultat['dezactivate'] . ' puncte care nu mai sunt la FAN au fost dezactivate.';
        }

        AdminActivityLog::log($db, 'fan_lockers_sync', [
            'importate' => $rezultat['importate'],
            'adaugate' => $rezultat['adaugate'],
            'actualizate' => $rezultat['actualizate'],
            'dezactivate' => $rezultat['dezactivate'],
        ]);
        Flash::set('success', $mesaj);
        ResponseCache::purgePageCache();
        header('Location: /admin/settings/shipping?tab=fanbox');
    }

    public function shippingExtraKmImport(): void
    {
        $this->shippingLocalitiesKmImport();
    }

    private function fanLocalitiesCount(?PDO $db): int
    {
        if (!$db instanceof PDO) {
            return 0;
        }
        $this->ensureFanLocalitiesSchema($db);
        try {
            return (int) ($db->query('SELECT COUNT(*) FROM fan_localities')->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function fanStreetsCount(?PDO $db): int
    {
        if (!$db instanceof PDO) {
            return 0;
        }
        $this->ensureFanStreetsSchema($db);
        try {
            return (int) ($db->query('SELECT COUNT(*) FROM fan_streets')->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function fanLocalitiesExtraKmCount(?PDO $db): int
    {
        if (!$db instanceof PDO) {
            return 0;
        }
        $this->ensureFanLocalitiesExtraKmSchema($db);
        try {
            return (int) ($db->query('SELECT COUNT(*) FROM fan_localities_extra_km')->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public function paymentSettingsForm(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $settings = Settings::all($db);

        View::render('admin/settings-payments', [
            'title' => 'Setări plăți',
            'settings' => $settings,
            'appUrl' => $this->appUrl(),
        ], 'admin/layout');
    }

    public function paymentSettingsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $euCurrency = strtoupper(trim((string) ($_POST['euplatesc_currency'] ?? 'RON')));
        if (preg_match('/^[A-Z]{3}$/', $euCurrency) !== 1) {
            $euCurrency = 'RON';
        }
        // Cheia secretă EuPlătesc e hexazecimală; spațiile lipite la copiere
        // ar strica semnătura, deci le curățăm aici.
        $euSecret = preg_replace('/\s+/', '', (string) ($_POST['euplatesc_secret_key'] ?? '')) ?? '';

        Settings::save($db, [
            'euplatesc_enabled' => isset($_POST['euplatesc_enabled']) ? '1' : '0',
            'euplatesc_merchant_id' => trim((string) ($_POST['euplatesc_merchant_id'] ?? '')),
            'euplatesc_secret_key' => $euSecret,
            'euplatesc_currency' => $euCurrency,
            'stripe_enabled' => isset($_POST['stripe_enabled']) ? '1' : '0',
            'stripe_publishable_key' => trim((string) ($_POST['stripe_publishable_key'] ?? '')),
            'stripe_secret_key' => trim((string) ($_POST['stripe_secret_key'] ?? '')),
            'stripe_webhook_secret' => trim((string) ($_POST['stripe_webhook_secret'] ?? '')),
            'stripe_currency' => strtolower(trim((string) ($_POST['stripe_currency'] ?? 'ron'))),
        ]);

        $avertisment = '';
        if ($euSecret !== '' && preg_match('/^[0-9a-fA-F]+$/', $euSecret) !== 1) {
            $avertisment = ' Atenție: cheia secretă EuPlătesc nu pare hexazecimală — plățile vor fi respinse.';
        }
        Flash::set($avertisment === '' ? 'success' : 'error', 'Setările de plată au fost salvate.' . $avertisment);
        header('Location: /admin/settings/payments');
    }

    public function erpSettingsForm(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $settings = Settings::all($db);
        $queue = ['pending' => 0, 'failed' => 0, 'sent' => 0];

        if ($db instanceof PDO) {
            \App\Support\ErpSync::ensureSchema($db);
            try {
                $rows = $db->query(
                    "SELECT erp_status, COUNT(*) AS total
                     FROM orders WHERE deleted_at IS NULL
                     GROUP BY erp_status"
                )->fetchAll() ?: [];
                foreach ($rows as $row) {
                    $status = (string) ($row['erp_status'] ?? '');
                    if (array_key_exists($status, $queue)) {
                        $queue[$status] = (int) ($row['total'] ?? 0);
                    }
                }
            } catch (Throwable) {
                // Contoarele sunt informative; pagina se afișează oricum.
            }
        }

        View::render('admin/settings-erp', [
            'title' => 'ERP ANDAXI',
            'settings' => $settings,
            'queue' => $queue,
        ], 'admin/layout');
    }

    public function erpSettingsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/settings/erp');
            return;
        }

        $action = (string) ($_POST['action'] ?? 'save');
        $existing = Settings::all($db);

        // Cheia se rescrie doar când operatorul chiar a tastat una nouă —
        // altfel un „Salvează" fără să o retasteze ar șterge integrarea.
        $apiKey = trim((string) ($_POST['erp_api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = (string) ($existing['erp_api_key'] ?? '');
        }

        $url = rtrim(trim((string) ($_POST['erp_url'] ?? '')), '/');
        // Adresa se dă fără „/api"; îl tăiem dacă a fost lipit din browser.
        if (str_ends_with($url, '/api')) {
            $url = substr($url, 0, -4);
        }

        Settings::save($db, [
            'erp_enabled' => isset($_POST['erp_enabled']) ? '1' : '0',
            'erp_url' => $url,
            'erp_api_key' => $apiKey,
            'erp_timeout' => (string) max(5, min(60, (int) ($_POST['erp_timeout'] ?? 20))),
            'erp_stock_enabled' => isset($_POST['erp_stock_enabled']) ? '1' : '0',
        ]);

        // Setările s-au schimbat: disponibilitatea în cache nu mai e de încredere.
        \App\Support\ErpStock::flush();
        \App\Support\ErpShipping::flush();
        AdminActivityLog::log($db, 'erp_settings_save', ['action' => $action]);

        if ($action === 'test') {
            $client = \App\Support\ErpClient::fromSettings(Settings::all($db));
            if ($client === null) {
                Flash::set('error', 'Completează adresa ERP-ului și cheia de integrare.');
            } else {
                try {
                    $ping = $client->ping();
                    $gestiune = (bool) ($ping['gestiuneConfigurata'] ?? false);
                    if ($gestiune) {
                        Flash::set('success', 'Conexiune reușită. ERP-ul răspunde și are gestiunea configurată.');
                    } else {
                        Flash::set('error', 'Conexiune reușită, dar în ERP nu e aleasă gestiunea implicită (Setări → Setări site).');
                    }
                } catch (Throwable $exception) {
                    Flash::set('error', $exception->getMessage());
                }
            }
            header('Location: /admin/settings/erp');
            return;
        }

        if ($action === 'retry') {
            $rezultat = \App\Support\ErpSync::retryPending($db, 50);
            if ($rezultat['incercate'] === 0) {
                Flash::set('success', 'Nu există comenzi de retrimis.');
            } else {
                Flash::set(
                    $rezultat['esuate'] > 0 ? 'error' : 'success',
                    sprintf(
                        'Reîncercate %d comenzi: %d trimise, %d încă eșuate.',
                        $rezultat['incercate'],
                        $rezultat['reusite'],
                        $rezultat['esuate']
                    )
                );
            }
            header('Location: /admin/settings/erp');
            return;
        }

        Flash::set('success', 'Setările ERP au fost salvate.');
        header('Location: /admin/settings/erp');
    }

    /** Retrimite manual o comandă în ERP, din lista de comenzi. */
    public function orderErpRetry(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $orderId = (int) ($params['id'] ?? 0);
        $db = $this->db();
        if (!$db instanceof PDO || $orderId <= 0) {
            Flash::set('error', 'Comandă invalidă.');
            header('Location: /admin/orders');
            return;
        }

        $rezultat = \App\Support\ErpSync::push($db, $orderId, true);
        Flash::set($rezultat['ok'] ? 'success' : 'error', $rezultat['message']);
        AdminActivityLog::log($db, 'erp_order_retry', [
            'order_id' => $orderId,
            'ok' => $rezultat['ok'],
        ]);

        header('Location: /admin/orders');
    }

    public function googleSettingsForm(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $settings = Settings::all($db);

        View::render('admin/settings-google', [
            'title' => 'Google',
            'settings' => $settings,
        ], 'admin/layout');
    }

    public function googleSettingsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();

        // Code textareas are base64-encoded client-side to bypass the WAF; decode them here.
        $codeIsB64 = (string) ($_POST['code_fields_b64'] ?? '') === '1';
        $decodeCode = static function (string $name) use ($codeIsB64): string {
            $raw = trim((string) ($_POST[$name] ?? ''));
            if ($raw === '') {
                return '';
            }
            if ($codeIsB64) {
                $decoded = base64_decode($raw, true);
                if ($decoded !== false) {
                    return trim($decoded);
                }
            }
            return $raw;
        };

        Settings::save($db, [
            'google_site_verification' => $this->extractGoogleVerificationToken((string) ($_POST['google_site_verification'] ?? '')),
            'google_analytics_enabled' => isset($_POST['google_analytics_enabled']) ? '1' : '0',
            'google_analytics_id' => $this->sanitizeGoogleId((string) ($_POST['google_analytics_id'] ?? ''), 'G-'),
            'google_tag_manager_enabled' => isset($_POST['google_tag_manager_enabled']) ? '1' : '0',
            'google_tag_manager_id' => $this->sanitizeGoogleId((string) ($_POST['google_tag_manager_id'] ?? ''), 'GTM-'),
            'google_tag_manager_head_code' => $decodeCode('google_tag_manager_head_code'),
            'google_tag_manager_body_code' => $decodeCode('google_tag_manager_body_code'),
            'google_analytics_code' => $decodeCode('google_analytics_code'),
            'google_ads_enabled' => isset($_POST['google_ads_enabled']) ? '1' : '0',
            'google_ads_conversion_id' => $this->sanitizeGoogleId((string) ($_POST['google_ads_conversion_id'] ?? ''), 'AW-'),
            'google_ads_conversion_label' => preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) ($_POST['google_ads_conversion_label'] ?? ''))) ?? '',
        ]);

        Flash::set('success', 'Setările Google au fost salvate.');
        header('Location: /admin/settings/google');
    }

    /**
     * Accepts either the full <meta> tag or just the token and returns the bare verification token.
     */
    private function extractGoogleVerificationToken(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $raw, $m) === 1) {
            $raw = $m[1];
        }
        $token = preg_replace('/[^A-Za-z0-9_\-]/', '', $raw) ?? '';
        return substr($token, 0, 128);
    }

    /**
     * Normalizes a Google ID (GA4 "G-", GTM "GTM-", Ads "AW-"). Accepts value with or without prefix.
     */
    private function sanitizeGoogleId(string $raw, string $prefix): string
    {
        $raw = strtoupper(trim($raw));
        if ($raw === '') {
            return '';
        }
        $raw = preg_replace('/[^A-Z0-9\-]/', '', $raw) ?? '';
        if ($raw === '') {
            return '';
        }
        if (!str_starts_with($raw, $prefix)) {
            $raw = $prefix . ltrim($raw, '-');
        }
        return substr($raw, 0, 32);
    }

    public function emails(): void
    {
        if (!$this->guard()) {
            return;
        }

        header('Location: /admin/emails/sender');
    }

    public function emailsBuilder(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/emails/newsletters');
            return;
        }
        $this->ensureOptionalSchema($db);
        NewsletterService::ensureSchema($db);

        $type     = trim((string) ($_GET['type'] ?? 'template'));
        $settings = Settings::all($db);
        $builder  = null;

        if ($type === 'template') {
            $id = (int) ($_GET['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('SELECT id, name, subject, blocks_json, is_active FROM newsletter_templates WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $id]);
                $row = $stmt->fetch();
                if (!is_array($row)) {
                    Flash::set('error', 'Template negăsit.');
                    header('Location: /admin/emails/newsletters?tab=templates');
                    return;
                }
                $blocks  = json_decode((string) ($row['blocks_json'] ?? '[]'), true) ?: [];
                $builder = [
                    'type'      => 'template',
                    'id'        => $id,
                    'ref'       => '',
                    'name'      => (string) ($row['name'] ?? ''),
                    'subject'   => (string) ($row['subject'] ?? ''),
                    'blocks'    => $blocks,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'back_url'  => '/admin/emails/newsletters?tab=templates',
                ];
            } else {
                $builder = [
                    'type'      => 'template',
                    'id'        => 0,
                    'ref'       => '',
                    'name'      => trim((string) ($_GET['name'] ?? 'Template nou')),
                    'subject'   => trim((string) ($_GET['subject'] ?? '')),
                    'blocks'    => [],
                    'is_active' => true,
                    'back_url'  => '/admin/emails/newsletters?tab=templates',
                ];
            }
        } elseif ($type === 'ecommerce') {
            $ref         = trim((string) ($_GET['ref'] ?? ''));
            $definitions = OrderMailer::templateDefinitions();
            $meta        = $definitions[$ref] ?? null;
            if (!is_array($meta)) {
                Flash::set('error', 'Tip ecommerce invalid.');
                header('Location: /admin/emails/newsletters?tab=ecommerce');
                return;
            }
            $blocksKey = $this->ecommerceBlocksKey($ref);
            $subjectKey = (string) ($meta['subject_key'] ?? '');
            $bodyKey    = (string) ($meta['body_key'] ?? '');
            $activeKey  = (string) ($meta['active_key'] ?? '');
            $blocks     = json_decode((string) ($settings[$blocksKey] ?? '[]'), true) ?: [];
            if (empty($blocks)) {
                $htmlBody = (string) ($settings[$bodyKey] ?? $meta['default_body'] ?? '');
                $blocks   = $this->defaultBlocksFromText(strip_tags($htmlBody));
            }
            $tokens = array_keys($meta['tokens'] ?? []);
            if (empty($tokens)) {
                $tokens = ['{{customer_name}}','{{order_number}}','{{order_total}}','{{order_items_html}}','{{order_action_url}}','{{tracking_url}}','{{year}}'];
            }
            $builder = [
                'type'             => 'ecommerce',
                'id'               => 0,
                'ref'              => $ref,
                'name'             => (string) ($meta['name'] ?? $ref),
                'subject'          => (string) ($settings[$subjectKey] ?? $meta['default_subject'] ?? ''),
                'blocks'           => $blocks,
                'is_active'        => (string) ($settings[$activeKey] ?? '1') === '1',
                'back_url'         => '/admin/emails/newsletters?tab=ecommerce&etype=' . urlencode($ref),
                'ecommerce_meta'   => $meta,
                'recipient_mode'   => OrderMailer::templateRecipientMode($ref, $settings),
                'admin_recipients' => implode(', ', OrderMailer::templateAdminRecipients($ref, $settings)),
                'available_tokens' => $tokens,
            ];
        } elseif ($type === 'campaign') {
            $id   = (int) ($_GET['id'] ?? 0);
            $stmt = $db->prepare('SELECT id, name, subject, subscriber_list_id, subscriber_list_ids, status, scheduled_at, blocks_json FROM newsletter_campaigns WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row  = $stmt->fetch();
            if (!is_array($row)) {
                Flash::set('error', 'Campanie negăsită.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            $blocks  = json_decode((string) ($row['blocks_json'] ?? '[]'), true) ?: [];
            $newsletterLists = $db->query(
                'SELECT l.id, l.name, COUNT(ls.subscriber_id) AS subscribers_count
                 FROM newsletter_lists l
                 LEFT JOIN newsletter_list_subscribers ls ON ls.list_id = l.id
                 GROUP BY l.id ORDER BY l.is_default DESC, l.name ASC'
            )->fetchAll() ?: [];
            $builder = [
                'type'             => 'campaign',
                'id'               => $id,
                'ref'              => '',
                'name'             => (string) ($row['name'] ?? ''),
                'subject'          => (string) ($row['subject'] ?? ''),
                'blocks'           => $blocks,
                'is_active'        => true,
                'back_url'         => '/admin/emails/newsletters?tab=campaigns',
                'newsletter_lists'  => $newsletterLists,
                'list_id'           => (int) ($row['subscriber_list_id'] ?? 0),
                'list_ids'          => array_values(array_filter(array_map('intval',
                    json_decode((string) ($row['subscriber_list_ids'] ?? ''), true) ?: []), fn($v) => $v > 0)),
                'status'            => (string) ($row['status'] ?? 'draft'),
                'scheduled_at'      => (string) ($row['scheduled_at'] ?? ''),
            ];
        } else {
            Flash::set('error', 'Tip builder necunoscut.');
            header('Location: /admin/emails/newsletters');
            return;
        }

        $galleryImages = $db->query(
            "SELECT id, title, alt_text, image_url
             FROM gallery_images
             WHERE media_type = 'image' OR media_type IS NULL
             ORDER BY id DESC
             LIMIT 500"
        )->fetchAll() ?: [];

        $builder['gallery_images'] = $galleryImages;

        View::render('admin/emails/builder', [
            'title'   => 'Builder email — ' . ($builder['name'] ?? ''),
            'builder' => $builder,
        ], 'admin/layout');
    }

    public function emailsBuilderSave(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/emails/newsletters');
            return;
        }
        $this->ensureOptionalSchema($db);

        $type       = trim((string) ($_POST['builder_type'] ?? 'template'));
        $blocksRaw  = trim((string) ($_POST['builder_blocks_json'] ?? '[]'));
        $rawBlocks  = json_decode($blocksRaw, true);
        if (!is_array($rawBlocks)) {
            $rawBlocks = [];
        }
        $normalized  = NewsletterService::normalizeBlocks($rawBlocks);
        $blocksJson  = (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $subject     = trim((string) ($_POST['builder_subject'] ?? ''));
        // Emailurile de comandă nu sunt newsletter: fără subsol de dezabonare.
        $htmlContent = NewsletterService::renderHtmlFromBlocks($normalized, $subject, $type !== 'ecommerce');

        if ($type === 'template') {
            $id       = (int) ($_POST['builder_id'] ?? 0);
            $name     = trim((string) ($_POST['builder_name'] ?? ''));
            $isActive = isset($_POST['builder_is_active']) && (string) ($_POST['builder_is_active'] ?? '0') === '1' ? 1 : 0;
            if ($name === '') {
                $name = 'Template fără nume';
            }
            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE newsletter_templates
                     SET name = :name, subject = :subject, blocks_json = :bj, html_content = :html, is_active = :active, updated_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute(['name' => $name, 'subject' => $subject, 'bj' => $blocksJson, 'html' => $htmlContent, 'active' => $isActive, 'id' => $id]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO newsletter_templates (name, subject, blocks_json, html_content, is_active)
                     VALUES (:name, :subject, :bj, :html, :active)'
                );
                $stmt->execute(['name' => $name, 'subject' => $subject, 'bj' => $blocksJson, 'html' => $htmlContent, 'active' => $isActive]);
                $id = (int) $db->lastInsertId();
            }
            AdminActivityLog::log($db, 'email_template_save', ['id' => $id, 'name' => $name]);
            Flash::set('success', 'Template salvat.');
            header('Location: /admin/emails/builder?type=template&id=' . $id);
            return;
        }

        if ($type === 'ecommerce') {
            $ref         = trim((string) ($_POST['builder_ref'] ?? ''));
            $definitions = OrderMailer::templateDefinitions();
            $meta        = $definitions[$ref] ?? null;
            if (!is_array($meta)) {
                Flash::set('error', 'Tip ecommerce invalid.');
                header('Location: /admin/emails/newsletters?tab=ecommerce');
                return;
            }
            $recipientMode   = trim((string) ($_POST['builder_recipient_mode'] ?? 'client'));
            if (!in_array($recipientMode, ['client', 'admin', 'client_admin'], true)) {
                $recipientMode = 'client';
            }
            $adminRecipients = OrderMailer::parseRecipientEmails((string) ($_POST['builder_admin_recipients'] ?? ''));
            if (in_array($recipientMode, ['admin', 'client_admin'], true) && $adminRecipients === []) {
                Flash::set('error', 'Completează cel puțin un email valid pentru trimiterea către admin.');
                header('Location: /admin/emails/builder?type=ecommerce&ref=' . urlencode($ref));
                return;
            }
            $isActive   = isset($_POST['builder_is_active']) && (string) ($_POST['builder_is_active'] ?? '0') === '1' ? '1' : '0';
            $activeKey  = (string) ($meta['active_key'] ?? '');
            $payload    = [
                (string) ($meta['subject_key'] ?? '') => $subject,
                (string) ($meta['body_key'] ?? '')    => $htmlContent,
                $this->ecommerceBlocksKey($ref)        => $blocksJson,
                OrderMailer::templateRecipientModeKey($ref)     => $recipientMode,
                OrderMailer::templateAdminRecipientsKey($ref)   => implode(', ', $adminRecipients),
            ];
            if ($activeKey !== '') {
                $payload[$activeKey] = $isActive;
            }
            Settings::save($db, $payload);
            AdminActivityLog::log($db, 'email_ecommerce_save', ['ref' => $ref]);
            Flash::set('success', 'Template ecommerce salvat.');
            header('Location: /admin/emails/builder?type=ecommerce&ref=' . urlencode($ref));
            return;
        }

        if ($type === 'campaign') {
            $id      = (int) ($_POST['builder_id'] ?? 0);
            $name    = trim((string) ($_POST['builder_name'] ?? ''));
            $listIdsRaw = trim((string) ($_POST['builder_list_ids'] ?? ''));
            $listIds = array_values(array_filter(array_map('intval', $listIdsRaw !== '' ? explode(',', $listIdsRaw) : []), fn($v) => $v > 0));
            $listIdsJson = $listIds !== [] ? json_encode($listIds) : null;
            $primaryListId = $listIds[0] ?? null;
            if ($name === '') {
                $name = 'Newsletter';
            }
            $stmt = $db->prepare(
                'UPDATE newsletter_campaigns
                 SET name = :name, subject = :subject, subscriber_list_id = :list_id,
                     subscriber_list_ids = :list_ids, blocks_json = :bj, html_content = :html
                 WHERE id = :id'
            );
            $stmt->execute(['name' => $name, 'subject' => $subject, 'list_id' => $primaryListId,
                'list_ids' => $listIdsJson, 'bj' => $blocksJson, 'html' => $htmlContent, 'id' => $id]);
            Flash::set('success', 'Campanie salvată.');
            header('Location: /admin/emails/builder?type=campaign&id=' . $id);
            return;
        }

        Flash::set('error', 'Tip builder necunoscut.');
        header('Location: /admin/emails/newsletters');
    }

    public function emailsBuilderPreview(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->guard()) {
            echo json_encode(['ok' => false, 'html' => '']);
            return;
        }
        $blocksRaw = trim((string) ($_POST['blocks_json'] ?? '[]'));
        $rawBlocks = json_decode($blocksRaw, true);
        if (!is_array($rawBlocks)) {
            $rawBlocks = [];
        }
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $normalized = NewsletterService::normalizeBlocks($rawBlocks);
        $html = NewsletterService::renderHtmlFromBlocks($normalized, $subject);
        echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function emailsSection(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $section = trim((string) ($params['section'] ?? 'sender'));
        if (!in_array($section, $this->emailSections(), true)) {
            $section = 'sender';
        }

        $db = $this->db();
        $settings = Settings::all($db);
        $newsletterTemplates = [];
        $selectedNewsletter = null;
        $newsletterTab = 'templates';
        $ecommerceTemplates = [];
        $selectedEcommerceTemplateType = '';
        $selectedEcommerceTemplate = null;
        $newsletterCampaigns = [];
        $selectedCampaign = null;
        $newsletterLists = [];
        $selectedListId = 0;
        $listSubscribers = [];
        $subscribersTotal = 0;
        $subscribersPage = 1;
        $subscribersPages = 1;
        $subscribersPerPage = 50;
        $subscribersQuery = '';
        $optInForms = [];
        $selectedOptInForm = null;
        $selectedOptInFields = [];
        $contactMessages = [];
        $campaignHourlyOpens = [];
        $galleryImages = [];
        $newsletterStats = [
            'forms_total' => 0,
            'subscribers_active' => 0,
            'subscribers_unsubscribed' => 0,
        ];
        $emailSendHistory = [];
        $emailSendHistoryTotal = 0;
        $emailSendHistoryPage = 1;
        $emailSendHistoryPerPage = 50;
        $emailSendHistoryTotalPages = 1;
        $emailSendHistoryFilters = [
            'q' => '',
            'status' => 'all',
            'type' => '',
        ];
        if ($section === 'newsletters' && $db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            NewsletterService::ensureSchema($db);
            OrderMailer::ensureEmailSendHistorySchema($db);
            $newsletterTab = trim((string) ($_GET['tab'] ?? 'templates'));
            if (!in_array($newsletterTab, $this->newsletterTabs(), true)) {
                $newsletterTab = 'templates';
            }

            $newsletterTemplates = $db->query(
                'SELECT id, name, subject, blocks_json, html_content, is_active, updated_at
                 FROM newsletter_templates
                 ORDER BY updated_at DESC, id DESC'
            )->fetchAll();
            $newsletterLists = $db->query(
                'SELECT l.id, l.name, l.description, l.is_default, COUNT(ls.subscriber_id) AS subscribers_count
                 FROM newsletter_lists l
                 LEFT JOIN newsletter_list_subscribers ls ON ls.list_id = l.id
                 GROUP BY l.id
                 ORDER BY l.is_default DESC, l.name ASC'
            )->fetchAll();
            $galleryImages = $db->query(
                "SELECT id, title, alt_text, image_url
                 FROM gallery_images
                 WHERE media_type = 'image' OR media_type IS NULL
                 ORDER BY id DESC
                 LIMIT 500"
            )->fetchAll();
            $optInForms = $db->query(
                'SELECT id, name, slug, list_id, button_label, success_message, fields_json, is_active, updated_at
                 FROM newsletter_optin_forms
                 ORDER BY updated_at DESC, id DESC'
            )->fetchAll();
            $ecommerceTemplates = $this->ecommerceTemplatesFromSettings($settings);

            if ($newsletterTab === 'templates') {
                $selectedId = (int) ($_GET['template'] ?? 0);
                foreach ($newsletterTemplates as $tpl) {
                    if ((int) ($tpl['id'] ?? 0) === $selectedId) {
                        $selectedNewsletter = $tpl;
                        break;
                    }
                }
            } elseif ($newsletterTab === 'ecommerce') {
                $selectedEcommerceTemplateType = trim((string) ($_GET['etype'] ?? ''));
                $selectedEcommerceTemplate = ($selectedEcommerceTemplateType !== '' && isset($ecommerceTemplates[$selectedEcommerceTemplateType]))
                    ? $ecommerceTemplates[$selectedEcommerceTemplateType]
                    : null;
            } elseif ($newsletterTab === 'campaigns') {
                $newsletterCampaigns = $db->query(
                    'SELECT c.id, c.name, c.status, c.template_type, c.template_ref, c.subscriber_list_id,
                            c.subject, c.blocks_json, c.html_content, c.scheduled_at, c.sent_at, c.total_recipients, c.total_sent, c.total_failed,
                            COALESCE(c.total_opens, 0) AS total_opens, COALESCE(c.total_clicks, 0) AS total_clicks,
                            l.name AS list_name
                     FROM newsletter_campaigns c
                     LEFT JOIN newsletter_lists l ON l.id = c.subscriber_list_id
                     ORDER BY c.id DESC'
                )->fetchAll();
                $selectedCampaignId = (int) ($_GET['campaign'] ?? 0);
                $campaignHourlyOpens = [];
                if ($selectedCampaignId > 0) {
                    foreach ($newsletterCampaigns as $campaign) {
                        if ((int) ($campaign['id'] ?? 0) === $selectedCampaignId) {
                            $selectedCampaign = $campaign;
                            break;
                        }
                    }
                    try {
                        $hourlyStmt = $db->prepare(
                            'SELECT HOUR(opened_at) AS hr, COUNT(*) AS cnt
                             FROM newsletter_campaign_opens
                             WHERE campaign_id = :cid AND opened_at IS NOT NULL
                             GROUP BY hr ORDER BY hr'
                        );
                        $hourlyStmt->execute(['cid' => $selectedCampaignId]);
                        $campaignHourlyOpens = $hourlyStmt->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
                    } catch (\Throwable) {
                        $campaignHourlyOpens = [];
                    }
                }
            } elseif ($newsletterTab === 'subscribers') {
                $selectedListId = (int) ($_GET['list'] ?? 0);
                if ($selectedListId <= 0 && isset($newsletterLists[0]['id'])) {
                    $selectedListId = (int) $newsletterLists[0]['id'];
                }
                if ($selectedListId > 0) {
                    // Lista poate avea zeci de mii de adrese: se aduce doar
                    // pagina cerută, altfel pagina de admin ar încerca să
                    // deseneze tot nomenclatorul dintr-o dată.
                    $subscribersQuery = trim((string) ($_GET['sub_q'] ?? ''));
                    $subscribersPerPage = 50;
                    $filtru = '';
                    $parametri = ['list_id' => $selectedListId];
                    if ($subscribersQuery !== '') {
                        $filtru = ' AND (s.email LIKE :cauta OR s.name LIKE :cauta)';
                        $parametri['cauta'] = '%' . $subscribersQuery . '%';
                    }

                    $stmtCount = $db->prepare(
                        'SELECT COUNT(*)
                         FROM newsletter_list_subscribers ls
                         INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
                         WHERE ls.list_id = :list_id' . $filtru
                    );
                    $stmtCount->execute($parametri);
                    $subscribersTotal = (int) $stmtCount->fetchColumn();
                    $subscribersPages = max(1, (int) ceil($subscribersTotal / $subscribersPerPage));
                    $subscribersPage = max(1, (int) ($_GET['sub_page'] ?? 1));
                    if ($subscribersPage > $subscribersPages) {
                        $subscribersPage = $subscribersPages;
                    }

                    $stmtSubscribers = $db->prepare(
                        'SELECT s.id, s.email, s.name, s.status
                         FROM newsletter_list_subscribers ls
                         INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
                         WHERE ls.list_id = :list_id' . $filtru . '
                         ORDER BY s.id DESC
                         LIMIT :lim OFFSET :dec'
                    );
                    foreach ($parametri as $cheie => $valoare) {
                        $stmtSubscribers->bindValue(':' . $cheie, $valoare);
                    }
                    $stmtSubscribers->bindValue(':lim', $subscribersPerPage, PDO::PARAM_INT);
                    $stmtSubscribers->bindValue(':dec', ($subscribersPage - 1) * $subscribersPerPage, PDO::PARAM_INT);
                    $stmtSubscribers->execute();
                    $listSubscribers = $stmtSubscribers->fetchAll();
                }
            } elseif ($newsletterTab === 'optin') {
                $selectedFormId = (int) ($_GET['form'] ?? 0);
                foreach ($optInForms as $form) {
                    if ((int) ($form['id'] ?? 0) === $selectedFormId) {
                        $selectedOptInForm = $form;
                        break;
                    }
                }
                if (is_array($selectedOptInForm)) {
                    $decodedFields = json_decode((string) ($selectedOptInForm['fields_json'] ?? '[]'), true);
                    if (is_array($decodedFields)) {
                        $selectedOptInFields = $decodedFields;
                    }
                }
            } elseif ($newsletterTab === 'contact_forms') {
                $stmt = $db->query(
                    'SELECT id, name, email, phone, subject, message, status, source_url, ip_address, user_agent, created_at
                     FROM contact_form_messages
                     ORDER BY created_at DESC, id DESC
                     LIMIT 500'
                );
                $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
                $contactMessages = is_array($rows) ? $rows : [];
            } elseif ($newsletterTab === 'stats') {
                $newsletterStats['forms_total'] = (int) $db->query('SELECT COUNT(*) FROM newsletter_optin_forms')->fetchColumn();
                $newsletterStats['subscribers_active'] = (int) $db->query('SELECT COUNT(*) FROM newsletter_subscribers WHERE status = "active"')->fetchColumn();
                $newsletterStats['subscribers_unsubscribed'] = (int) $db->query('SELECT COUNT(*) FROM newsletter_subscribers WHERE status = "unsubscribed"')->fetchColumn();

                // Load all sent campaigns with aggregated stats
                try {
                    $statsCampaigns = $db->query(
                        'SELECT c.id, c.name, c.subject, c.sent_at, c.total_recipients, c.total_sent,
                                COALESCE(c.total_opens,0) AS total_opens,
                                COALESCE(c.total_clicks,0) AS total_clicks,
                                l.name AS list_name
                         FROM newsletter_campaigns c
                         LEFT JOIN newsletter_lists l ON l.id = c.subscriber_list_id
                         WHERE c.status IN ("sent","sending")
                         ORDER BY c.sent_at DESC
                         LIMIT 50'
                    )->fetchAll();
                } catch (\Throwable) {
                    $statsCampaigns = [];
                }

                // Load detailed stats for selected campaign
                $statsCampaignId = (int) ($_GET['campaign_id'] ?? 0);
                $statsCampaignDetail = null;
                $statsHourlyOpens = [];
                $statsTopLinks = [];
                $statsRecipients = [];
                $statsRevenue = ['orders_count' => 0, 'revenue' => 0];
                if ($statsCampaignId > 0) {
                    try {
                        $stmt = $db->prepare('SELECT c.*, l.name AS list_name FROM newsletter_campaigns c LEFT JOIN newsletter_lists l ON l.id=c.subscriber_list_id WHERE c.id=:id LIMIT 1');
                        $stmt->execute(['id' => $statsCampaignId]);
                        $statsCampaignDetail = $stmt->fetch() ?: null;
                    } catch (\Throwable) {
                        $statsCampaignDetail = null;
                    }

                    if ($statsCampaignDetail) {
                        try {
                            $h = $db->prepare('SELECT HOUR(opened_at) AS hr, COUNT(*) AS cnt FROM newsletter_campaign_opens WHERE campaign_id=:id AND opened_at IS NOT NULL GROUP BY hr ORDER BY hr');
                            $h->execute(['id' => $statsCampaignId]);
                            $statsHourlyOpens = $h->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
                        } catch (\Throwable) {
                            $statsHourlyOpens = [];
                        }

                        try {
                            $cl = $db->prepare('SELECT url, COUNT(*) AS cnt FROM newsletter_campaign_clicks WHERE campaign_id=:id GROUP BY url ORDER BY cnt DESC LIMIT 10');
                            $cl->execute(['id' => $statsCampaignId]);
                            $statsTopLinks = $cl->fetchAll() ?: [];
                        } catch (\Throwable) {
                            $statsTopLinks = [];
                        }

                        try {
                            $r = $db->prepare('SELECT email, status, opened_at, open_count, clicked_at, click_count FROM newsletter_campaign_sends WHERE campaign_id=:id ORDER BY id DESC LIMIT 100');
                            $r->execute(['id' => $statsCampaignId]);
                            $statsRecipients = $r->fetchAll() ?: [];
                        } catch (\Throwable) {
                            $statsRecipients = [];
                        }

                        try {
                            $rev = $db->prepare('SELECT COUNT(*) AS orders_count, COALESCE(SUM(o.total),0) AS revenue FROM orders o WHERE o.nl_campaign_id=:id AND o.status NOT IN ("cancelled","refunded")');
                            $rev->execute(['id' => $statsCampaignId]);
                            $statsRevenue = $rev->fetch() ?: ['orders_count' => 0, 'revenue' => 0];
                        } catch (\Throwable) {
                            $statsRevenue = ['orders_count' => 0, 'revenue' => 0];
                        }
                    }
                }
            } elseif ($newsletterTab === 'history') {
                $emailSendHistoryFilters['q'] = trim((string) ($_GET['history_q'] ?? ''));
                $historyStatus = strtolower(trim((string) ($_GET['history_status'] ?? 'all')));
                if ($historyStatus === 'error') {
                    $historyStatus = 'failed';
                }
                $emailSendHistoryFilters['status'] = in_array($historyStatus, ['all', 'sent', 'failed'], true) ? $historyStatus : 'all';
                $emailSendHistoryFilters['type'] = trim((string) ($_GET['history_type'] ?? ''));
                if (strlen($emailSendHistoryFilters['type']) > 80) {
                    $emailSendHistoryFilters['type'] = substr($emailSendHistoryFilters['type'], 0, 80);
                }

                $emailSendHistoryPage = max(1, (int) ($_GET['history_page'] ?? 1));
                $historyPerPageRequested = (int) ($_GET['history_per_page'] ?? 50);
                $allowedHistoryPerPage = [25, 50, 100, 200];
                $emailSendHistoryPerPage = in_array($historyPerPageRequested, $allowedHistoryPerPage, true) ? $historyPerPageRequested : 50;

                $where = ['1=1'];
                $historyParams = [];
                if ($emailSendHistoryFilters['q'] !== '') {
                    $where[] = '(h.recipient LIKE :term OR h.subject LIKE :term OR COALESCE(h.error_message, "") LIKE :term OR COALESCE(o.order_number, "") LIKE :term)';
                    $historyParams['term'] = '%' . $emailSendHistoryFilters['q'] . '%';
                }
                if ($emailSendHistoryFilters['status'] === 'sent') {
                    $where[] = 'h.status = :status_sent';
                    $historyParams['status_sent'] = 'sent';
                } elseif ($emailSendHistoryFilters['status'] === 'failed') {
                    $where[] = 'h.status IN ("failed", "error")';
                }
                if ($emailSendHistoryFilters['type'] !== '') {
                    $where[] = 'h.email_type = :email_type';
                    $historyParams['email_type'] = $emailSendHistoryFilters['type'];
                }
                $whereSql = implode(' AND ', $where);

                $countStmt = $db->prepare(
                    'SELECT COUNT(*)
                     FROM email_send_history h
                     LEFT JOIN orders o ON o.id = h.order_id
                     WHERE ' . $whereSql
                );
                $countStmt->execute($historyParams);
                $emailSendHistoryTotal = (int) ($countStmt->fetchColumn() ?: 0);
                $emailSendHistoryTotalPages = max(1, (int) ceil($emailSendHistoryTotal / max(1, $emailSendHistoryPerPage)));
                $emailSendHistoryPage = min($emailSendHistoryPage, $emailSendHistoryTotalPages);
                $historyOffset = ($emailSendHistoryPage - 1) * $emailSendHistoryPerPage;

                $rowsStmt = $db->prepare(
                    'SELECT h.id, h.order_id, h.email_type, h.source, h.recipient, h.subject, h.status, h.error_message, h.provider, h.meta_json, h.sent_at, h.created_at,
                            o.order_number
                     FROM email_send_history h
                     LEFT JOIN orders o ON o.id = h.order_id
                     WHERE ' . $whereSql . '
                     ORDER BY h.id DESC
                     LIMIT ' . max(1, $emailSendHistoryPerPage) . ' OFFSET ' . max(0, $historyOffset)
                );
                $rowsStmt->execute($historyParams);
                $historyRows = $rowsStmt->fetchAll() ?: [];
                $emailSendHistory = is_array($historyRows) ? $historyRows : [];
            }
        }

        View::render('admin/emails', [
            'title' => 'Setări email',
            'settings' => $settings,
            'templateKeys' => OrderMailer::templateDefinitions(),
            'section' => $section,
            'newsletterTab' => $newsletterTab,
            'newsletterTemplates' => $newsletterTemplates,
            'selectedNewsletter' => $selectedNewsletter,
            'ecommerceTemplates' => $ecommerceTemplates,
            'selectedEcommerceTemplateType' => $selectedEcommerceTemplateType,
            'selectedEcommerceTemplate' => $selectedEcommerceTemplate,
            'newsletterCampaigns' => $newsletterCampaigns,
            'selectedCampaign' => $selectedCampaign,
            'newsletterLists' => $newsletterLists,
            'selectedListId' => $selectedListId,
            'listSubscribers' => $listSubscribers,
            'subscribersTotal' => $subscribersTotal,
            'subscribersPage' => $subscribersPage,
            'subscribersPages' => $subscribersPages,
            'subscribersPerPage' => $subscribersPerPage,
            'subscribersQuery' => $subscribersQuery,
            'optInForms' => $optInForms,
            'selectedOptInForm' => $selectedOptInForm,
            'selectedOptInFields' => $selectedOptInFields,
            'contactMessages' => $contactMessages,
            'galleryImages' => $galleryImages,
            'newsletterStats' => $newsletterStats,
            'emailSendHistory' => $emailSendHistory,
            'emailSendHistoryTotal' => $emailSendHistoryTotal,
            'emailSendHistoryPage' => $emailSendHistoryPage,
            'emailSendHistoryPerPage' => $emailSendHistoryPerPage,
            'emailSendHistoryTotalPages' => $emailSendHistoryTotalPages,
            'emailSendHistoryFilters' => $emailSendHistoryFilters,
            'campaignHourlyOpens' => $campaignHourlyOpens,
            'statsCampaigns' => $statsCampaigns ?? [],
            'statsCampaignId' => $statsCampaignId ?? 0,
            'statsCampaignDetail' => $statsCampaignDetail ?? null,
            'statsHourlyOpens' => $statsHourlyOpens ?? [],
            'statsTopLinks' => $statsTopLinks ?? [],
            'statsRecipients' => $statsRecipients ?? [],
            'statsRevenue' => $statsRevenue ?? ['orders_count' => 0, 'revenue' => 0],
        ], 'admin/layout');
    }

    public function emailsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $settings = Settings::all($db);
        $section = trim((string) ($_POST['section'] ?? 'sender'));

        if ($section === 'template-single') {
            $templateType = trim((string) ($_POST['template_type'] ?? ''));
            $definitions = OrderMailer::templateDefinitions();
            $meta = $definitions[$templateType] ?? null;
            if (!is_array($meta)) {
                Flash::set('error', 'Template invalid.');
                header('Location: /admin/emails/templates');
                return;
            }

            $subjectKey = (string) $meta['subject_key'];
            $bodyKey = (string) $meta['body_key'];
            $activeKey = (string) ($meta['active_key'] ?? '');
            $payload = [
                $subjectKey => trim((string) ($_POST['subject'] ?? '')),
                $bodyKey => (string) ($_POST['body'] ?? ''),
            ];
            if ($activeKey !== '') {
                $payload[$activeKey] = isset($_POST['is_active']) ? '1' : '0';
            }

            Settings::save($db, $payload);
            Flash::set('success', 'Template salvat.');
            header('Location: /admin/emails/templates');
            return;
        }

        if ($section === 'template-toggle') {
            $templateType = trim((string) ($_POST['template_type'] ?? ''));
            $definitions = OrderMailer::templateDefinitions();
            $meta = $definitions[$templateType] ?? null;
            if (!is_array($meta)) {
                Flash::set('error', 'Template invalid.');
                header('Location: /admin/emails/templates');
                return;
            }

            $activeKey = (string) ($meta['active_key'] ?? '');
            if ($activeKey !== '') {
                $active = ((string) ($_POST['active'] ?? '0')) === '1' ? '1' : '0';
                Settings::save($db, [$activeKey => $active]);
                Flash::set('success', $active === '1' ? 'Template activat.' : 'Template dezactivat.');
            }
            header('Location: /admin/emails/templates');
            return;
        }

        if ($section === 'automation') {
            Settings::save($db, [
                'email_abandoned_after_minutes' => trim((string) ($_POST['email_abandoned_after_minutes'] ?? '60')),
            ]);

            Flash::set('success', 'Setările de automatizare email au fost salvate.');
            header('Location: /admin/emails/automation');
            return;
        }

        if ($section === 'newsletter-template-create-quick') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=templates');
                return;
            }
            NewsletterService::ensureSchema($db);

            $name = trim((string) ($_POST['newsletter_name'] ?? ''));
            $subject = trim((string) ($_POST['newsletter_subject'] ?? ''));
            if ($name === '' || $subject === '') {
                Flash::set('error', 'Numele și subiectul template-ului sunt obligatorii.');
                header('Location: /admin/emails/newsletters?tab=templates');
                return;
            }

            $blocks = [
                ['type' => 'header', 'content' => 'Titlul Newsletter-ului', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff'],
                ['type' => 'text', 'content' => 'Scrie conținutul tău aici.', 'align' => 'left', 'background' => '#ffffff', 'text_color' => '#1f2937'],
                ['type' => 'button', 'label' => 'Află mai multe', 'url' => '#', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff', 'radius' => 6],
            ];

            $stmt = $db->prepare(
                'INSERT INTO newsletter_templates (name, subject, blocks_json, html_content, is_active)
                 VALUES (:name, :subject, :blocks_json, :html_content, 1)'
            );
            $stmt->execute([
                'name' => $name,
                'subject' => $subject,
                'blocks_json' => (string) json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'html_content' => NewsletterService::renderHtmlFromBlocks($blocks, $subject),
            ]);
            $templateId = (int) $db->lastInsertId();

            Flash::set('success', 'Template nou creat.');
            header('Location: /admin/emails/newsletters?tab=templates&template=' . $templateId);
            return;
        }

        if ($section === 'newsletter-save') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=templates');
                return;
            }
            $this->ensureOptionalSchema($db);

            $id = (int) ($_POST['newsletter_id'] ?? 0);
            $name = trim((string) ($_POST['newsletter_name'] ?? ''));
            $subject = trim((string) ($_POST['newsletter_subject'] ?? ''));
            $blocksJsonRaw = trim((string) ($_POST['newsletter_blocks_json'] ?? '[]'));
            $htmlContent = trim((string) ($_POST['newsletter_html'] ?? ''));
            $isActive = isset($_POST['newsletter_is_active']) ? 1 : 0;

            if ($name === '' || $subject === '') {
                Flash::set('error', 'Numele și subiectul template-ului sunt obligatorii.');
                header('Location: /admin/emails/newsletters?tab=templates');
                return;
            }

            $blocksDecoded = json_decode($blocksJsonRaw, true);
            if (!is_array($blocksDecoded)) {
                $blocksDecoded = [];
            }
            $blocksJson = (string) json_encode($blocksDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($blocksJson === '') {
                $blocksJson = '[]';
            }

            if ($htmlContent === '') {
                $htmlContent = '<p>Newsletter fără conținut.</p>';
            }

            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE newsletter_templates
                     SET name = :name,
                         subject = :subject,
                         blocks_json = :blocks_json,
                         html_content = :html_content,
                         is_active = :is_active
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                    'subject' => $subject,
                    'blocks_json' => $blocksJson,
                    'html_content' => $htmlContent,
                    'is_active' => $isActive,
                ]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO newsletter_templates (name, subject, blocks_json, html_content, is_active)
                     VALUES (:name, :subject, :blocks_json, :html_content, :is_active)'
                );
                $stmt->execute([
                    'name' => $name,
                    'subject' => $subject,
                    'blocks_json' => $blocksJson,
                    'html_content' => $htmlContent,
                    'is_active' => $isActive,
                ]);
                $id = (int) $db->lastInsertId();
            }

            Flash::set('success', 'Template newsletter salvat.');
            header('Location: /admin/emails/newsletters?tab=templates&template=' . $id);
            return;
        }

        if ($section === 'newsletter-delete') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=templates');
                return;
            }
            $this->ensureOptionalSchema($db);

            $id = (int) ($_POST['newsletter_id'] ?? 0);
            if ($id <= 0) {
                Flash::set('error', 'Template newsletter invalid.');
                header('Location: /admin/emails/newsletters?tab=templates');
                return;
            }

            $stmt = $db->prepare('DELETE FROM newsletter_templates WHERE id = :id');
            $stmt->execute(['id' => $id]);

            Flash::set('success', 'Template newsletter șters.');
            header('Location: /admin/emails/newsletters?tab=templates');
            return;
        }

        if ($section === 'newsletter-ecommerce-save') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=ecommerce');
                return;
            }

            $templateType = trim((string) ($_POST['ecommerce_template_type'] ?? ''));
            $definitions = OrderMailer::templateDefinitions();
            $meta = $definitions[$templateType] ?? null;
            if (!is_array($meta)) {
                Flash::set('error', 'Template ecommerce invalid.');
                header('Location: /admin/emails/newsletters?tab=ecommerce');
                return;
            }

            $subject = trim((string) ($_POST['ecommerce_template_subject'] ?? ''));
            if ($subject === '') {
                $subject = (string) ($meta['default_subject'] ?? 'Notificare comandă');
            }

            $htmlContent = trim((string) ($_POST['ecommerce_template_html'] ?? ''));
            if ($htmlContent === '') {
                $htmlContent = (string) ($meta['default_body'] ?? '<p>Conținut.</p>');
            }

            $blocksDecoded = json_decode((string) ($_POST['ecommerce_template_blocks_json'] ?? '[]'), true);
            if (!is_array($blocksDecoded)) {
                $blocksDecoded = $this->defaultBlocksFromText(strip_tags($htmlContent));
            }
            $blocksJson = (string) json_encode($blocksDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($blocksJson === '') {
                $blocksJson = '[]';
            }

            $subjectKey = (string) ($meta['subject_key'] ?? '');
            $bodyKey = (string) ($meta['body_key'] ?? '');
            $activeKey = (string) ($meta['active_key'] ?? '');
            $recipientMode = strtolower(trim((string) ($_POST['ecommerce_template_recipient_mode'] ?? 'client')));
            if ($recipientMode === 'both') {
                $recipientMode = 'client_admin';
            }
            if (!in_array($recipientMode, ['client', 'admin', 'client_admin'], true)) {
                $recipientMode = 'client';
            }
            $adminRecipients = OrderMailer::parseRecipientEmails((string) ($_POST['ecommerce_template_admin_recipients'] ?? ''));
            if (in_array($recipientMode, ['admin', 'client_admin'], true) && $adminRecipients === []) {
                Flash::set('error', 'Completează cel puțin un email valid pentru trimiterea către admin.');
                header('Location: /admin/emails/newsletters?tab=ecommerce&etype=' . urlencode($templateType));
                return;
            }

            $payload = [
                $subjectKey => $subject,
                $bodyKey => $htmlContent,
                $this->ecommerceBlocksKey($templateType) => $blocksJson,
                OrderMailer::templateRecipientModeKey($templateType) => $recipientMode,
                OrderMailer::templateAdminRecipientsKey($templateType) => implode(', ', $adminRecipients),
            ];
            if ($activeKey !== '') {
                $payload[$activeKey] = isset($_POST['ecommerce_template_is_active']) ? '1' : '0';
            }

            Settings::save($db, $payload);
            Flash::set('success', 'Template ecommerce salvat.');
            header('Location: /admin/emails/newsletters?tab=ecommerce&etype=' . urlencode($templateType));
            return;
        }

        if ($section === 'newsletter-ecommerce-send-test') {
            $templateType = trim((string) ($_POST['ecommerce_template_type'] ?? ''));
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=ecommerce' . ($templateType !== '' ? '&etype=' . urlencode($templateType) : ''));
                return;
            }

            $definitions = OrderMailer::templateDefinitions();
            $meta = $definitions[$templateType] ?? null;
            if (!is_array($meta)) {
                Flash::set('error', 'Template ecommerce invalid.');
                header('Location: /admin/emails/newsletters?tab=ecommerce');
                return;
            }

            $to = trim((string) ($_POST['test_email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                Flash::set('error', 'Adresa de email pentru test este invalidă.');
                header('Location: /admin/emails/newsletters?tab=ecommerce&etype=' . urlencode($templateType));
                return;
            }

            $subjectDraft = trim((string) ($_POST['ecommerce_template_subject'] ?? ''));
            $htmlDraft = trim((string) ($_POST['ecommerce_template_html'] ?? ''));
            $subjectKey = (string) ($meta['subject_key'] ?? '');
            $bodyKey = (string) ($meta['body_key'] ?? '');
            $testSettings = Settings::all($db);
            if ($subjectKey !== '' && $subjectDraft !== '') {
                $testSettings[$subjectKey] = $subjectDraft;
            }
            if ($bodyKey !== '' && $htmlDraft !== '') {
                $testSettings[$bodyKey] = $htmlDraft;
            }

            $awb = '1111111111111';
            if ($templateType === 'new_order') {
                $testOrderActionUrl = '/admin/orders';
            } elseif ($templateType === 'cancelled') {
                $testOrderActionUrl = '/contact';
            } elseif ($templateType === 'abandoned_cart') {
                $testOrderActionUrl = '/cos';
            } else {
                $testOrderActionUrl = '/contul-meu?section=orders';
            }
            try {
                OrderMailer::sendTemplate($templateType, [
                    'to' => $to,
                    'customer_name' => 'Client Test',
                    'order_number' => 'BV-TEST-001',
                    'awb' => $awb,
                    'courier_name' => 'FAN Courier',
                    'tracking_url' => FanCourierGateway::trackingUrl($awb),
                    'cart_summary' => 'Produs demo x1 - 99.00 RON',
                    'cart_items_html' => '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f4f7f4;border-radius:12px;padding:14px 16px;margin:0 0 10px;"><div style="display:flex;align-items:center;gap:12px;"><div style="width:54px;height:54px;border-radius:14px;background:#e7efe8;color:#2f8d5b;font-size:28px;line-height:1;display:flex;align-items:center;justify-content:center;">📦</div><div><p style="margin:0;color:#0f2532;font-size:22px;line-height:1.25;font-weight:700;">Ulei Esențial de Lavandă</p><p style="margin:4px 0 0;color:#5f7680;font-size:14px;line-height:1.35;">Cantitate: 1</p></div></div><div style="color:#0f2532;font-size:18px;line-height:1.2;font-weight:700;white-space:nowrap;">89,00 lei</div></div><div style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f4f7f4;border-radius:12px;padding:14px 16px;margin:0 0 10px;"><div style="display:flex;align-items:center;gap:12px;"><div style="width:54px;height:54px;border-radius:14px;background:#e7efe8;color:#2f8d5b;font-size:28px;line-height:1;display:flex;align-items:center;justify-content:center;">📦</div><div><p style="margin:0;color:#0f2532;font-size:22px;line-height:1.25;font-weight:700;">Cremă Hidratantă cu Aloe</p><p style="margin:4px 0 0;color:#5f7680;font-size:14px;line-height:1.35;">Cantitate: 1</p></div></div><div style="color:#0f2532;font-size:18px;line-height:1.2;font-weight:700;white-space:nowrap;">125,00 lei</div></div>',
                    'cart_total' => '214,00 lei',
                    'cart_action_url' => $testOrderActionUrl,
                    'order_status' => 'În procesare',
                    'estimated_delivery' => '8-9 Aprilie 2026',
                    'customer_email' => $to,
                    'store_name' => (string) ($testSettings['order_email_from_name'] ?? 'Bioscem'),
                    'order_date' => '6 Aprilie 2026',
                    'order_total' => '303,00 lei',
                    'order_summary' => "Ulei Esențial de Lavandă x2 - 89,00 lei\nCremă Hidratantă cu Aloe x1 - 125,00 lei",
                    'order_items_html' => '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f4f7f4;border-radius:12px;padding:14px 16px;margin:0 0 10px;"><div><p style="margin:0;color:#0f2532;font-size:22px;line-height:1.25;font-weight:700;">Ulei Esențial de Lavandă</p><p style="margin:4px 0 0;color:#5f7680;font-size:14px;line-height:1.35;">Cantitate: 2</p></div><div style="color:#0f2532;font-size:18px;line-height:1.2;font-weight:700;white-space:nowrap;">89,00 lei</div></div><div style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f4f7f4;border-radius:12px;padding:14px 16px;margin:0 0 10px;"><div><p style="margin:0;color:#0f2532;font-size:22px;line-height:1.25;font-weight:700;">Cremă Hidratantă cu Aloe</p><p style="margin:4px 0 0;color:#5f7680;font-size:14px;line-height:1.35;">Cantitate: 1</p></div><div style="color:#0f2532;font-size:18px;line-height:1.2;font-weight:700;white-space:nowrap;">125,00 lei</div></div>',
                    'order_action_url' => $testOrderActionUrl,
                    'year' => date('Y'),
                ], $testSettings, $db, [
                    'email_type' => 'ecommerce_test',
                    'source' => 'admin_emails_ecommerce_test',
                    'trigger' => 'manual_test',
                    'template_type' => $templateType,
                ]);
                $label = trim((string) ($meta['label'] ?? $templateType));
                Flash::set('success', 'Email test trimis pentru "' . $label . '" către ' . $to . '.');
            } catch (RuntimeException $exception) {
                Flash::set('error', 'Nu am putut trimite emailul test: ' . $exception->getMessage());
            }

            header('Location: /admin/emails/newsletters?tab=ecommerce&etype=' . urlencode($templateType));
            return;
        }

        if ($section === 'newsletter-campaign-save') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            NewsletterService::ensureSchema($db);

            $id = (int) ($_POST['campaign_id'] ?? 0);
            $name = trim((string) ($_POST['campaign_name'] ?? ''));
            $subject = trim((string) ($_POST['campaign_subject'] ?? ($_POST['campaign_subject_override'] ?? 'Newsletter')));
            if ($subject === '') {
                $subject = 'Newsletter';
            }
            $templateType = trim((string) ($_POST['campaign_template_type'] ?? 'newsletter'));
            if (!in_array($templateType, ['newsletter', 'ecommerce'], true)) {
                $templateType = 'newsletter';
            }
            $templateRef = trim((string) ($_POST['campaign_template_ref'] ?? ''));
            $listId = (int) ($_POST['campaign_list_id'] ?? 0);
            $status = trim((string) ($_POST['campaign_status'] ?? 'draft'));
            if (!in_array($status, ['draft', 'scheduled', 'sent'], true)) {
                $status = 'draft';
            }
            $blocksDecoded = json_decode((string) ($_POST['campaign_blocks_json'] ?? '[]'), true);
            if (!is_array($blocksDecoded)) {
                $blocksDecoded = [];
            }
            $blocksNormalized = NewsletterService::normalizeBlocks($blocksDecoded);
            $blocksJson = (string) json_encode($blocksNormalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($blocksJson === '') {
                $blocksJson = '[]';
            }
            $htmlContentOverride = trim((string) ($_POST['campaign_html_content'] ?? ''));
            $scheduledAtRaw = trim((string) ($_POST['campaign_scheduled_at'] ?? ''));
            $scheduledAt = null;
            if ($scheduledAtRaw !== '') {
                $timestamp = strtotime($scheduledAtRaw);
                if ($timestamp !== false) {
                    $scheduledAt = date('Y-m-d H:i:s', $timestamp);
                }
            }

            if ($name === '' || $listId <= 0) {
                Flash::set('error', 'Numele newsletter-ului și lista de abonați sunt obligatorii.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            if ($status === 'scheduled' && $scheduledAt === null) {
                Flash::set('error', 'Pentru programare trebuie setată data/ora.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            $finalHtmlContent = $htmlContentOverride;
            if ($finalHtmlContent === '') {
                $finalHtmlContent = NewsletterService::renderHtmlFromBlocks($blocksNormalized, $subject);
            }
            if ($finalHtmlContent === '' && $templateRef !== '') {
                $content = $this->campaignContentForTemplate($db, $settings, $templateType, $templateRef);
                if (is_array($content)) {
                    $subject = trim((string) ($content['subject'] ?? $subject));
                    if ($subject === '') {
                        $subject = 'Newsletter';
                    }
                    $finalHtmlContent = (string) ($content['html_content'] ?? '');
                }
            }
            if ($finalHtmlContent === '') {
                $fallbackBlocks = $this->defaultBlocksFromText('Conținut newsletter');
                $finalHtmlContent = NewsletterService::renderHtmlFromBlocks($fallbackBlocks, $subject);
                $blocksJson = (string) json_encode($fallbackBlocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE newsletter_campaigns
                     SET name = :name,
                         template_type = :template_type,
                         template_ref = :template_ref,
                         subscriber_list_id = :subscriber_list_id,
                         subject = :subject,
                         blocks_json = :blocks_json,
                         html_content = :html_content,
                         status = :status,
                         scheduled_at = :scheduled_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                    'template_type' => $templateType,
                    'template_ref' => $templateRef,
                    'subscriber_list_id' => $listId,
                    'subject' => $subject,
                    'blocks_json' => $blocksJson,
                    'html_content' => $finalHtmlContent,
                    'status' => $status,
                    'scheduled_at' => $status === 'scheduled' ? $scheduledAt : null,
                ]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO newsletter_campaigns (
                        name, template_type, template_ref, subscriber_list_id, subject, blocks_json, html_content, status, scheduled_at
                     ) VALUES (
                        :name, :template_type, :template_ref, :subscriber_list_id, :subject, :blocks_json, :html_content, :status, :scheduled_at
                     )'
                );
                $stmt->execute([
                    'name' => $name,
                    'template_type' => $templateType,
                    'template_ref' => $templateRef,
                    'subscriber_list_id' => $listId,
                    'subject' => $subject,
                    'blocks_json' => $blocksJson,
                    'html_content' => $finalHtmlContent,
                    'status' => $status,
                    'scheduled_at' => $status === 'scheduled' ? $scheduledAt : null,
                ]);
                $id = (int) $db->lastInsertId();
            }

            Flash::set('success', $status === 'scheduled' ? 'Newsletter programat.' : 'Newsletter salvat.');
            header('Location: /admin/emails/newsletters?tab=campaigns&campaign=' . $id);
            return;
        }

        if ($section === 'newsletter-campaign-create-quick') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            NewsletterService::ensureSchema($db);

            $name = trim((string) ($_POST['campaign_name'] ?? ''));
            $subject = trim((string) ($_POST['campaign_subject'] ?? 'Newsletter'));
            if ($subject === '') {
                $subject = 'Newsletter';
            }
            $listId = (int) ($_POST['campaign_list_id'] ?? 0);
            if ($listId <= 0) {
                $listId = NewsletterService::defaultListId($db);
            }
            if ($name === '') {
                Flash::set('error', 'Numele newsletter-ului este obligatoriu.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }

            $blocks = [
                ['type' => 'header', 'content' => 'Titlul Newsletter-ului', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff'],
                ['type' => 'text', 'content' => 'Scrie conținutul newsletter-ului aici.', 'align' => 'left', 'background' => '#ffffff', 'text_color' => '#1f2937'],
                ['type' => 'button', 'label' => 'Află mai multe', 'url' => '#', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff', 'radius' => 6],
            ];
            $blocks = NewsletterService::normalizeBlocks($blocks);
            $blocksJson = (string) json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($blocksJson === '') {
                $blocksJson = '[]';
            }
            $htmlContent = NewsletterService::renderHtmlFromBlocks($blocks, $subject);

            $stmt = $db->prepare(
                'INSERT INTO newsletter_campaigns (
                    name, template_type, template_ref, subscriber_list_id, subject, blocks_json, html_content, status, scheduled_at
                 ) VALUES (
                    :name, :template_type, :template_ref, :subscriber_list_id, :subject, :blocks_json, :html_content, "draft", NULL
                 )'
            );
            $stmt->execute([
                'name' => $name,
                'template_type' => 'newsletter',
                'template_ref' => '',
                'subscriber_list_id' => $listId,
                'subject' => $subject,
                'blocks_json' => $blocksJson,
                'html_content' => $htmlContent,
            ]);
            $id = (int) $db->lastInsertId();

            Flash::set('success', 'Newsletter nou creat. Editează conținutul mai jos.');
            header('Location: /admin/emails/builder?type=campaign&id=' . $id);
            return;
        }

        if ($section === 'newsletter-campaign-delete') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            NewsletterService::ensureSchema($db);
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            if ($campaignId <= 0) {
                Flash::set('error', 'Newsletter invalid.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            $db->prepare('DELETE FROM newsletter_campaign_sends WHERE campaign_id = :id')->execute(['id' => $campaignId]);
            $db->prepare('DELETE FROM newsletter_campaigns WHERE id = :id')->execute(['id' => $campaignId]);
            Flash::set('success', 'Newsletter șters.');
            header('Location: /admin/emails/newsletters?tab=campaigns');
            return;
        }

        if ($section === 'newsletter-campaign-duplicate') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            NewsletterService::ensureSchema($db);
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            if ($campaignId <= 0) {
                Flash::set('error', 'Newsletter invalid.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }

            $stmt = $db->prepare(
                'SELECT name, template_type, template_ref, subscriber_list_id, subscriber_list_ids, subject, blocks_json, html_content
                 FROM newsletter_campaigns
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $campaignId]);
            $source = $stmt->fetch() ?: null;
            if (!is_array($source)) {
                Flash::set('error', 'Newsletterul sursă nu a fost găsit.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }

            $copyName = trim((string) ($source['name'] ?? 'Newsletter')) . ' (copie)';
            $insert = $db->prepare(
                'INSERT INTO newsletter_campaigns (
                    name, template_type, template_ref, subscriber_list_id, subscriber_list_ids, subject, blocks_json, html_content, status, scheduled_at
                 ) VALUES (
                    :name, :template_type, :template_ref, :subscriber_list_id, :subscriber_list_ids, :subject, :blocks_json, :html_content, "draft", NULL
                 )'
            );
            $insert->execute([
                'name' => $copyName,
                'template_type' => trim((string) ($source['template_type'] ?? 'newsletter')),
                'template_ref' => trim((string) ($source['template_ref'] ?? '')),
                'subscriber_list_id' => (int) ($source['subscriber_list_id'] ?? 0),
                'subscriber_list_ids' => $source['subscriber_list_ids'] ?? null,
                'subject' => trim((string) ($source['subject'] ?? 'Newsletter')),
                'blocks_json' => (string) ($source['blocks_json'] ?? '[]'),
                'html_content' => (string) ($source['html_content'] ?? '<p>Newsletter</p>'),
            ]);
            $newId = (int) $db->lastInsertId();

            Flash::set('success', 'Newsletter duplicat.');
            header('Location: /admin/emails/builder?type=campaign&id=' . $newId);
            return;
        }

        if ($section === 'newsletter-campaign-send-test') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            NewsletterService::ensureSchema($db);
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            $to = trim((string) ($_POST['test_email'] ?? ''));
            try {
                NewsletterService::sendCampaignTest($db, $campaignId, $to, Settings::all($db));
                Flash::set('success', 'Email test trimis pentru newsletter.');
            } catch (RuntimeException $exception) {
                Flash::set('error', 'Email test eșuat: ' . $exception->getMessage());
            }
            $fromBuilder = (string) ($_POST['from_builder'] ?? '') === '1';
            header('Location: ' . ($fromBuilder ? '/admin/emails/builder?type=campaign&id=' . $campaignId : '/admin/emails/newsletters?tab=campaigns&campaign=' . $campaignId));
            return;
        }

        if ($section === 'newsletter-campaign-send-now') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            NewsletterService::ensureSchema($db);
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            try {
                // Din browser trimitem doar cât încape sigur într-o cerere web;
                // restul îl duce cronul, ca nimeni să nu stea cu pagina deschisă
                // și să apese de mai multe ori pentru o listă mare.
                $result = NewsletterService::sendCampaignNow(
                    $db,
                    $campaignId,
                    Settings::all($db),
                    2000,
                    time() + 45
                );
                $ramase = (int) ($result['remaining'] ?? 0);
                Flash::set(
                    'success',
                    $ramase > 0
                        ? 'Trimitere pornită: ' . (int) ($result['sent'] ?? 0) . ' emailuri au plecat acum, '
                            . $ramase . ' mai sunt la rând. Restul pleacă automat, nu mai trebuie să apeși nimic.'
                        : 'Newsletter trimis integral: ' . (int) ($result['total_sent'] ?? 0) . ' trimise, '
                            . (int) ($result['total_failed'] ?? 0) . ' eșuate.'
                );
            } catch (RuntimeException $exception) {
                Flash::set('error', 'Trimiterea newsletter-ului a eșuat: ' . $exception->getMessage());
            }
            $fromBuilder = (string) ($_POST['from_builder'] ?? '') === '1';
            header('Location: ' . ($fromBuilder ? '/admin/emails/builder?type=campaign&id=' . $campaignId : '/admin/emails/newsletters?tab=campaigns&campaign=' . $campaignId));
            return;
        }

        if ($section === 'newsletter-campaign-schedule') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=campaigns');
                return;
            }
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            $scheduledAtRaw = trim((string) ($_POST['scheduled_at'] ?? ''));
            $timestamp = strtotime($scheduledAtRaw);
            if ($campaignId <= 0 || $timestamp === false) {
                Flash::set('error', 'Data de programare este invalidă.');
                header('Location: /admin/emails/newsletters?tab=campaigns&campaign=' . $campaignId);
                return;
            }
            $scheduledAt = date('Y-m-d H:i:s', $timestamp);
            $stmt = $db->prepare(
                'UPDATE newsletter_campaigns
                 SET status = "scheduled", scheduled_at = :scheduled_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'scheduled_at' => $scheduledAt,
                'id' => $campaignId,
            ]);
            Flash::set('success', 'Newsletter programat pentru ' . $scheduledAt . '.');
            $fromBuilder = (string) ($_POST['from_builder'] ?? '') === '1';
            header('Location: ' . ($fromBuilder ? '/admin/emails/builder?type=campaign&id=' . $campaignId : '/admin/emails/newsletters?tab=campaigns&campaign=' . $campaignId));
            return;
        }

        if ($section === 'newsletter-list-save') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=subscribers');
                return;
            }
            NewsletterService::ensureSchema($db);
            $name = trim((string) ($_POST['list_name'] ?? ''));
            $description = trim((string) ($_POST['list_description'] ?? ''));
            if ($name === '') {
                Flash::set('error', 'Numele listei este obligatoriu.');
                header('Location: /admin/emails/newsletters?tab=subscribers');
                return;
            }
            try {
                $stmt = $db->prepare('INSERT INTO newsletter_lists (name, description, is_default) VALUES (:name, :description, 0)');
                $stmt->execute([
                    'name' => $name,
                    'description' => $description,
                ]);
                $listId = (int) $db->lastInsertId();
                Flash::set('success', 'Lista a fost creată.');
                header('Location: /admin/emails/newsletters?tab=subscribers&list=' . $listId . $this->subscribersReturnQuery());
                return;
            } catch (Throwable) {
                Flash::set('error', 'Lista există deja.');
                header('Location: /admin/emails/newsletters?tab=subscribers');
                return;
            }
        }

        if ($section === 'newsletter-list-delete') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=subscribers');
                return;
            }
            NewsletterService::ensureSchema($db);
            $listId = (int) ($_POST['list_id'] ?? 0);
            if ($listId <= 0) {
                Flash::set('error', 'Listă invalidă.');
                header('Location: /admin/emails/newsletters?tab=subscribers');
                return;
            }
            $check = $db->prepare('SELECT is_default FROM newsletter_lists WHERE id = :id LIMIT 1');
            $check->execute(['id' => $listId]);
            $isDefault = (int) ($check->fetchColumn() ?: 0);
            if ($isDefault === 1) {
                Flash::set('error', 'Lista implicită nu poate fi ștearsă.');
                header('Location: /admin/emails/newsletters?tab=subscribers&list=' . $listId . $this->subscribersReturnQuery());
                return;
            }
            $db->prepare('DELETE FROM newsletter_list_subscribers WHERE list_id = :id')->execute(['id' => $listId]);
            $db->prepare('DELETE FROM newsletter_lists WHERE id = :id')->execute(['id' => $listId]);
            Flash::set('success', 'Lista a fost ștearsă.');
            header('Location: /admin/emails/newsletters?tab=subscribers');
            return;
        }

        if ($section === 'newsletter-subscribers-import') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=subscribers');
                return;
            }
            NewsletterService::ensureSchema($db);
            $result = $this->importNewsletterSubscribersFromUploadedFile($db, $_FILES['newsletter_subscribers_file'] ?? null);
            Flash::set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Import invalid.'));
            header('Location: /admin/emails/newsletters?tab=subscribers');
            return;
        }

        if ($section === 'newsletter-mailchimp-import') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=subscribers');
                return;
            }
            NewsletterService::ensureSchema($db);
            $result = $this->importMailchimpSubscribers(
                $db,
                $_FILES['mailchimp_file'] ?? null,
                (int) ($_POST['subscriber_list_id'] ?? 0)
            );
            Flash::set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Import invalid.'));
            header('Location: /admin/emails/newsletters?tab=subscribers&list=' . (int) ($_POST['subscriber_list_id'] ?? 0));
            return;
        }

        if ($section === 'newsletter-subscriber-save') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=subscribers');
                return;
            }
            NewsletterService::ensureSchema($db);
            $email = strtolower(trim((string) ($_POST['subscriber_email'] ?? '')));
            $name = trim((string) ($_POST['subscriber_name'] ?? ''));
            $listId = (int) ($_POST['subscriber_list_id'] ?? 0);
            if ($listId <= 0) {
                $listId = NewsletterService::defaultListId($db);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Flash::set('error', 'Email abonat invalid.');
                header('Location: /admin/emails/newsletters?tab=subscribers&list=' . $listId . $this->subscribersReturnQuery());
                return;
            }
            NewsletterService::subscribeToList($db, $listId, $email, $name);

            Flash::set('success', 'Abonat salvat.');
            header('Location: /admin/emails/newsletters?tab=subscribers&list=' . $listId . $this->subscribersReturnQuery());
            return;
        }

        if ($section === 'newsletter-subscriber-delete') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=subscribers');
                return;
            }
            NewsletterService::ensureSchema($db);
            $subscriberId = (int) ($_POST['subscriber_id'] ?? 0);
            $listId = (int) ($_POST['list_id'] ?? 0);
            if ($subscriberId <= 0) {
                Flash::set('error', 'Abonat invalid.');
                header('Location: /admin/emails/newsletters?tab=subscribers&list=' . $listId . $this->subscribersReturnQuery());
                return;
            }
            $db->prepare('DELETE FROM newsletter_list_subscribers WHERE subscriber_id = :id')->execute(['id' => $subscriberId]);
            $db->prepare('DELETE FROM newsletter_subscribers WHERE id = :id')->execute(['id' => $subscriberId]);
            Flash::set('success', 'Abonatul a fost șters.');
            header('Location: /admin/emails/newsletters?tab=subscribers&list=' . $listId . $this->subscribersReturnQuery());
            return;
        }

        if ($section === 'newsletter-subscriber-toggle') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=subscribers');
                return;
            }
            $subscriberId = (int) ($_POST['subscriber_id'] ?? 0);
            $listId = (int) ($_POST['list_id'] ?? 0);
            if ($subscriberId <= 0) {
                Flash::set('error', 'Abonat invalid.');
                header('Location: /admin/emails/newsletters?tab=subscribers&list=' . $listId . $this->subscribersReturnQuery());
                return;
            }
            $current = $db->prepare('SELECT status FROM newsletter_subscribers WHERE id = :id LIMIT 1');
            $current->execute(['id' => $subscriberId]);
            $status = (string) ($current->fetchColumn() ?: 'active');
            $next = $status === 'active' ? 'unsubscribed' : 'active';
            $db->prepare('UPDATE newsletter_subscribers SET status = :status WHERE id = :id')->execute([
                'status' => $next,
                'id' => $subscriberId,
            ]);
            Flash::set('success', $next === 'active' ? 'Abonat activat.' : 'Abonat dezabonat.');
            header('Location: /admin/emails/newsletters?tab=subscribers&list=' . $listId . $this->subscribersReturnQuery());
            return;
        }

        if ($section === 'newsletter-optin-create') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=optin');
                return;
            }
            NewsletterService::ensureSchema($db);

            $name = trim((string) ($_POST['optin_name'] ?? ''));
            if ($name === '') {
                Flash::set('error', 'Numele formularului este obligatoriu.');
                header('Location: /admin/emails/newsletters?tab=optin');
                return;
            }

            $slug = $this->slugify($name);
            if ($slug === '') {
                $slug = 'formular-optin';
            }
            $baseSlug = $slug;
            $counter = 2;
            while (true) {
                $check = $db->prepare('SELECT id FROM newsletter_optin_forms WHERE slug = :slug LIMIT 1');
                $check->execute(['slug' => $slug]);
                if (!$check->fetch()) {
                    break;
                }
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }

            $listId = (int) ($_POST['optin_list_id'] ?? 0);
            if ($listId <= 0) {
                $listId = NewsletterService::defaultListId($db);
            }

            $defaultFields = [
                [
                    'type' => 'email',
                    'name' => 'email',
                    'label' => 'Email',
                    'placeholder' => 'email@exemplu.ro',
                    'required' => 1,
                    'width' => 'full',
                    'offset_y' => 0,
                    'label_color' => '#334155',
                    'input_text_color' => '#0f172a',
                    'input_bg_color' => '#f8fafc',
                    'input_border_color' => '#cbd5e1',
                ],
                ['type' => '__meta', 'layout_columns' => 1],
                [
                    'type' => '__submit',
                    'label' => 'Ma abonez',
                    'style' => 'primary',
                    'align' => 'left',
                    'width' => 'full',
                    'offset_y' => 0,
                    'bg_color' => '#0f8f7a',
                    'text_color' => '#ffffff',
                    'border_color' => '#0f8f7a',
                    'required' => 0,
                ],
            ];
            $insert = $db->prepare(
                'INSERT INTO newsletter_optin_forms (name, slug, list_id, button_label, success_message, fields_json, is_active)
                 VALUES (:name, :slug, :list_id, :button_label, :success_message, :fields_json, 1)'
            );
            $insert->execute([
                'name' => $name,
                'slug' => $slug,
                'list_id' => $listId,
                'button_label' => 'Ma abonez',
                'success_message' => 'Te-ai abonat cu succes.',
                'fields_json' => (string) json_encode($defaultFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $formId = (int) $db->lastInsertId();

            Flash::set('success', 'Formular opt-in creat.');
            header('Location: /admin/emails/newsletters?tab=optin&form=' . $formId);
            return;
        }

        if ($section === 'newsletter-optin-save') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=optin');
                return;
            }
            NewsletterService::ensureSchema($db);

            $formId = (int) ($_POST['optin_form_id'] ?? 0);
            $name = trim((string) ($_POST['optin_name'] ?? ''));
            $slug = $this->slugify((string) ($_POST['optin_slug'] ?? ''));
            $listId = (int) ($_POST['optin_list_id'] ?? 0);
            $buttonLabel = trim((string) ($_POST['optin_button_label'] ?? 'Ma abonez'));
            $successMessage = trim((string) ($_POST['optin_success_message'] ?? 'Te-ai abonat cu succes.'));
            $isActive = isset($_POST['optin_is_active']) ? 1 : 0;
            $canvasColumns = (int) ($_POST['optin_canvas_columns'] ?? 1);
            $canvasColumns = max(1, min(2, $canvasColumns));
            if ($name === '' || $slug === '' || $listId <= 0) {
                Flash::set('error', 'Numele, slug-ul și lista sunt obligatorii.');
                header('Location: /admin/emails/newsletters?tab=optin&form=' . $formId);
                return;
            }
            if ($buttonLabel === '') {
                $buttonLabel = 'Ma abonez';
            }
            if ($successMessage === '') {
                $successMessage = 'Te-ai abonat cu succes.';
            }

            $fieldsRaw = trim((string) ($_POST['optin_fields_json'] ?? '[]'));
            $decoded = json_decode($fieldsRaw, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $normalizedFields = [];
            $submitConfig = null;
            foreach ($decoded as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (trim((string) ($field['type'] ?? '')) === '__submit') {
                    $style = in_array((string) ($field['style'] ?? 'primary'), ['primary', 'outline'], true)
                        ? (string) ($field['style'] ?? 'primary')
                        : 'primary';
                    $defaultSubmitBg = $style === 'outline' ? '#ffffff' : '#0f8f7a';
                    $defaultSubmitText = $style === 'outline' ? '#0f8f7a' : '#ffffff';
                    $defaultSubmitBorder = '#0f8f7a';
                    $submitOffset = (int) ($field['offset_y'] ?? 0);
                    $submitOffset = max(-40, min(40, $submitOffset));
                    $submitConfig = [
                        'type' => '__submit',
                        'label' => trim((string) ($field['label'] ?? '')),
                        'style' => $style,
                        'align' => in_array((string) ($field['align'] ?? 'left'), ['left', 'center', 'right'], true)
                            ? (string) ($field['align'] ?? 'left')
                            : 'left',
                        'width' => ((string) ($field['width'] ?? 'full') === 'half') ? 'half' : 'full',
                        'offset_y' => $submitOffset,
                        'bg_color' => $this->normalizeHexColor((string) ($field['bg_color'] ?? ''), $defaultSubmitBg),
                        'text_color' => $this->normalizeHexColor((string) ($field['text_color'] ?? ''), $defaultSubmitText),
                        'border_color' => $this->normalizeHexColor((string) ($field['border_color'] ?? ''), $defaultSubmitBorder),
                        'required' => 0,
                    ];
                    continue;
                }
                $type = trim((string) ($field['type'] ?? 'text'));
                if (!in_array($type, ['email', 'text', 'textarea', 'tel', 'checkbox'], true)) {
                    $type = 'text';
                }
                $fieldName = $this->slugify((string) ($field['name'] ?? ''));
                if ($fieldName === '') {
                    continue;
                }
                $normalizedFields[] = [
                    'type' => $type,
                    'name' => $fieldName,
                    'label' => trim((string) ($field['label'] ?? ucfirst($fieldName))),
                    'placeholder' => trim((string) ($field['placeholder'] ?? '')),
                    'required' => ((int) ($field['required'] ?? 0) === 1) ? 1 : 0,
                    'width' => ((string) ($field['width'] ?? 'full') === 'half') ? 'half' : 'full',
                    'offset_y' => max(-40, min(40, (int) ($field['offset_y'] ?? 0))),
                    'label_color' => $this->normalizeHexColor((string) ($field['label_color'] ?? ''), '#334155'),
                    'input_text_color' => $this->normalizeHexColor((string) ($field['input_text_color'] ?? ''), '#0f172a'),
                    'input_bg_color' => $this->normalizeHexColor((string) ($field['input_bg_color'] ?? ''), '#f8fafc'),
                    'input_border_color' => $this->normalizeHexColor((string) ($field['input_border_color'] ?? ''), '#cbd5e1'),
                ];
            }
            if ($normalizedFields === []) {
                $normalizedFields[] = [
                    'type' => 'email',
                    'name' => 'email',
                    'label' => 'Email',
                    'placeholder' => 'email@exemplu.ro',
                    'required' => 1,
                    'width' => 'full',
                    'offset_y' => 0,
                    'label_color' => '#334155',
                    'input_text_color' => '#0f172a',
                    'input_bg_color' => '#f8fafc',
                    'input_border_color' => '#cbd5e1',
                ];
            }
            $hasEmailField = false;
            foreach ($normalizedFields as $row) {
                if (($row['name'] ?? '') === 'email') {
                    $hasEmailField = true;
                    break;
                }
            }
            if (!$hasEmailField) {
                array_unshift($normalizedFields, [
                    'type' => 'email',
                    'name' => 'email',
                    'label' => 'Email',
                    'placeholder' => 'email@exemplu.ro',
                    'required' => 1,
                    'width' => 'full',
                    'offset_y' => 0,
                    'label_color' => '#334155',
                    'input_text_color' => '#0f172a',
                    'input_bg_color' => '#f8fafc',
                    'input_border_color' => '#cbd5e1',
                ]);
            }
            $normalizedFields[] = ['type' => '__meta', 'layout_columns' => $canvasColumns];
            if (is_array($submitConfig)) {
                if (trim((string) ($submitConfig['label'] ?? '')) === '') {
                    $submitConfig['label'] = $buttonLabel;
                }
                $normalizedFields[] = $submitConfig;
            }

            $duplicate = $db->prepare(
                'SELECT id FROM newsletter_optin_forms WHERE slug = :slug AND id != :id LIMIT 1'
            );
            $duplicate->execute([
                'slug' => $slug,
                'id' => $formId,
            ]);
            if ($duplicate->fetch()) {
                Flash::set('error', 'Slug-ul formularului există deja.');
                header('Location: /admin/emails/newsletters?tab=optin&form=' . $formId);
                return;
            }

            $stmt = $db->prepare(
                'UPDATE newsletter_optin_forms
                 SET name = :name,
                     slug = :slug,
                     list_id = :list_id,
                     button_label = :button_label,
                     success_message = :success_message,
                     fields_json = :fields_json,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $formId,
                'name' => $name,
                'slug' => $slug,
                'list_id' => $listId,
                'button_label' => $buttonLabel,
                'success_message' => $successMessage,
                'fields_json' => (string) json_encode($normalizedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active' => $isActive,
            ]);

            Flash::set('success', 'Formularul opt-in a fost salvat.');
            header('Location: /admin/emails/newsletters?tab=optin&form=' . $formId);
            return;
        }

        if ($section === 'newsletter-optin-delete') {
            if (!$db instanceof PDO) {
                Flash::set('error', 'Conexiunea DB nu este disponibilă.');
                header('Location: /admin/emails/newsletters?tab=optin');
                return;
            }
            NewsletterService::ensureSchema($db);
            $formId = (int) ($_POST['optin_form_id'] ?? 0);
            if ($formId <= 0) {
                Flash::set('error', 'Formular invalid.');
                header('Location: /admin/emails/newsletters?tab=optin');
                return;
            }
            $db->prepare('DELETE FROM newsletter_optin_forms WHERE id = :id')->execute(['id' => $formId]);
            Flash::set('success', 'Formularul opt-in a fost șters.');
            header('Location: /admin/emails/newsletters?tab=optin');
            return;
        }

        $deliveryMethod = strtolower(trim((string) ($_POST['email_delivery_method'] ?? 'smtp')));
        if (!in_array($deliveryMethod, ['smtp', 'sendgrid'], true)) {
            $deliveryMethod = 'smtp';
        }

        $payload = [
            'email_delivery_method' => $deliveryMethod,
            'order_email_from_name' => trim((string) ($_POST['order_email_from_name'] ?? 'Bioscem')),
            'order_email_from_address' => trim((string) ($_POST['order_email_from_address'] ?? 'no-reply@localhost')),
        ];

        foreach ([
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
            'smtp_password',
            'sendgrid_api_key',
        ] as $key) {
            if (array_key_exists($key, $_POST)) {
                $payload[$key] = trim((string) $_POST[$key]);
            }
        }

        Settings::save($db, $payload);

        Flash::set('success', 'Setările generale de email au fost salvate.');
        header('Location: /admin/emails/sender');
    }

    public function emailsSendTest(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/emails/test');
            return;
        }

        $settings = Settings::all($db);
        $type = trim((string) ($_POST['template_type'] ?? 'delivered'));
        $to = trim((string) ($_POST['test_email'] ?? ''));

        try {
            if ($type === '') {
                OrderMailer::sendCustom(
                    $to,
                    'Email test Bioscem',
                    '<p>Salut! Acesta este un email de test trimis din modulul Email-uri.</p>',
                    $settings,
                    $db,
                    [
                        'email_type' => 'admin_test_email',
                        'source' => 'admin_emails_test',
                        'trigger' => 'manual_test',
                    ]
                );
            } else {
                $awb = '1111111111111';
                OrderMailer::sendTemplate($type, [
                    'to' => $to,
                    'customer_name' => 'Client Test',
                    'order_number' => 'BV-TEST-001',
                    'awb' => $awb,
                    'tracking_url' => FanCourierGateway::trackingUrl($awb),
                    'cart_summary' => 'Produs demo x1 - 99.00 RON',
                ], $settings, $db, [
                    'email_type' => 'admin_template_test',
                    'source' => 'admin_emails_test',
                    'trigger' => 'manual_test',
                    'template_type' => $type,
                ]);
            }

            Flash::set('success', 'Email test trimis cu succes.');
        } catch (RuntimeException $exception) {
            Flash::set('error', 'Nu am putut trimite emailul test: ' . $exception->getMessage());
        }

        header('Location: /admin/emails/test');
    }

    public function pages(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $pages = [];

        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $pages = $db->query(
                'SELECT id, title, slug, is_published, updated_at
                 FROM pages
                 WHERE deleted_at IS NULL
                 ORDER BY updated_at DESC, id DESC'
            )->fetchAll();
        }

        View::render('admin/pages', [
            'title' => 'Pagini',
            'pages' => $pages,
        ], 'admin/layout');
    }

    public function pageCreateForm(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $mannequinPreviewHtml = $this->mannequinSectionPreviewHtml($db instanceof PDO ? $db : null);
        $shopCatalogPreviewHtml = $this->shopCatalogPreviewHtml($db instanceof PDO ? $db : null);
        $blogPostsPreviewHtml = $this->blogPostsPreviewHtml($db instanceof PDO ? $db : null);
        $cartFormPreviewHtml = $this->cartFormPreviewHtml($db instanceof PDO ? $db : null);
        $checkoutFormPreviewHtml = $this->checkoutFormPreviewHtml($db instanceof PDO ? $db : null);
        $accountSectionPreviewHtml = $this->accountSectionPreviewHtml();
        $productReviewFormPreviewHtml = $this->reviewFormPreviewHtml($db instanceof PDO ? $db : null);
        $gdprAgreementsFormPreviewHtml = $this->gdprAgreementsFormPreviewHtml();
        $checkoutSuccessOrderInfoPreviewHtml = $this->checkoutSuccessOrderInfoPreviewHtml();

        View::render('admin/page-editor', [
            'title' => 'Pagină nouă',
            'page' => [
                'id' => 0,
                'title' => '',
                'slug' => '',
                'html_content' => "<h1>Titlu pagină</h1>\n<p>Conținutul paginii tale...</p>",
                'css_content' => "/* CSS pagină */\n",
                'js_content' => "// JavaScript pagină\n",
                'is_published' => 1,
            ],
            'pageSeo' => [
                'title' => '',
                'description' => '',
                'canonical_url' => '',
                'image_url' => '',
            ],
            'mannequinCode' => $this->mannequinCodeToken(),
            'mannequinPreviewHtml' => $mannequinPreviewHtml,
            'shopCatalogCode' => self::SHOP_CATALOG_TOKEN,
            'shopCatalogPreviewHtml' => $shopCatalogPreviewHtml,
            'blogPostsCode' => self::BLOG_POSTS_TOKEN,
            'blogPostsPreviewHtml' => $blogPostsPreviewHtml,
            'cartFormCode' => self::CART_FORM_TOKEN,
            'cartFormPreviewHtml' => $cartFormPreviewHtml,
            'checkoutFormCode' => self::CHECKOUT_FORM_TOKEN,
            'checkoutFormPreviewHtml' => $checkoutFormPreviewHtml,
            'accountSectionCode' => self::ACCOUNT_SECTION_TOKEN,
            'accountSectionPreviewHtml' => $accountSectionPreviewHtml,
            'authGoogleButtonCode' => self::GOOGLE_AUTH_BUTTON_TOKEN,
            'authGoogleButtonPreviewHtml' => $this->googleAuthButtonPreviewHtml(),
            'productReviewFormCode' => self::PRODUCT_REVIEW_FORM_TOKEN,
            'productReviewFormPreviewHtml' => $productReviewFormPreviewHtml,
            'gdprAgreementsFormCode' => self::GDPR_AGREEMENTS_FORM_TOKEN,
            'gdprAgreementsFormPreviewHtml' => $gdprAgreementsFormPreviewHtml,
            'checkoutSuccessOrderInfoCode' => self::CHECKOUT_SUCCESS_ORDER_INFO_TOKEN,
            'checkoutSuccessOrderInfoPreviewHtml' => $checkoutSuccessOrderInfoPreviewHtml,
        ], 'admin/layout');
    }

    public function pageEditForm(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Flash::set('error', 'Pagină invalidă.');
            header('Location: /admin/pages');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/pages');
            return;
        }
        $this->ensureOptionalSchema($db);

        $stmt = $db->prepare(
            'SELECT id, title, slug, html_content, css_content, js_content, is_published
             FROM pages
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $page = $stmt->fetch() ?: null;

        if ($page === null) {
            Flash::set('error', 'Pagina nu a fost găsită.');
            header('Location: /admin/pages');
            return;
        }
        $pageSeo = $this->loadCustomPageSeoSettings($db, (int) ($page['id'] ?? 0));

        View::render('admin/page-editor', [
            'title' => 'Editează pagină',
            'page' => $page,
            'pageSeo' => $pageSeo,
            'mannequinCode' => $this->mannequinCodeToken(),
            'mannequinPreviewHtml' => $this->mannequinSectionPreviewHtml($db),
            'shopCatalogCode' => self::SHOP_CATALOG_TOKEN,
            'shopCatalogPreviewHtml' => $this->shopCatalogPreviewHtml($db),
            'blogPostsCode' => self::BLOG_POSTS_TOKEN,
            'blogPostsPreviewHtml' => $this->blogPostsPreviewHtml($db),
            'cartFormCode' => self::CART_FORM_TOKEN,
            'cartFormPreviewHtml' => $this->cartFormPreviewHtml($db),
            'checkoutFormCode' => self::CHECKOUT_FORM_TOKEN,
            'checkoutFormPreviewHtml' => $this->checkoutFormPreviewHtml($db),
            'accountSectionCode' => self::ACCOUNT_SECTION_TOKEN,
            'accountSectionPreviewHtml' => $this->accountSectionPreviewHtml(),
            'authGoogleButtonCode' => self::GOOGLE_AUTH_BUTTON_TOKEN,
            'authGoogleButtonPreviewHtml' => $this->googleAuthButtonPreviewHtml(),
            'productReviewFormCode' => self::PRODUCT_REVIEW_FORM_TOKEN,
            'productReviewFormPreviewHtml' => $this->reviewFormPreviewHtml($db),
            'gdprAgreementsFormCode' => self::GDPR_AGREEMENTS_FORM_TOKEN,
            'gdprAgreementsFormPreviewHtml' => $this->gdprAgreementsFormPreviewHtml(),
            'checkoutSuccessOrderInfoCode' => self::CHECKOUT_SUCCESS_ORDER_INFO_TOKEN,
            'checkoutSuccessOrderInfoPreviewHtml' => $this->checkoutSuccessOrderInfoPreviewHtml(),
        ], 'admin/layout');
    }

    public function pageSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/pages');
            return;
        }
        $this->ensureOptionalSchema($db);

        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $existingSlug = null;
        if ($id > 0) {
            $stmtExisting = $db->prepare('SELECT slug FROM pages WHERE id = :id LIMIT 1');
            $stmtExisting->execute(['id' => $id]);
            $existingPage = $stmtExisting->fetch() ?: null;
            if (is_array($existingPage)) {
                $existingSlug = trim((string) ($existingPage['slug'] ?? ''));
            }
        }
        if ($slugInput === '/' || strtolower($slugInput) === 'acasa' || strtolower($slugInput) === 'home') {
            $slug = '';
        } elseif ($slugInput !== '') {
            $slug = $this->normalizePageSlug($slugInput);
        } elseif ($existingSlug === '') {
            // Keep the homepage mapped to the root domain.
            $slug = '';
        } else {
            $slug = $this->slugify($title);
        }
        if ($slug === '') {
            $stmtRootConflict = $db->prepare(
                'SELECT id
                 FROM pages
                 WHERE slug = :slug
                   AND deleted_at IS NULL
                   AND id != :id
                 LIMIT 1'
            );
            $stmtRootConflict->execute([
                'slug' => '',
                'id' => $id,
            ]);
            if ($stmtRootConflict->fetch()) {
                Flash::set('error', 'Există deja o pagină setată ca Home (URL /).');
                header('Location: /admin/pages');
                return;
            }
        }
        $htmlContent = $this->postedEditorContent('html_content');
        $cssContent = $this->postedEditorContent('css_content');
        $jsContent = $this->postedEditorContent('js_content');
        $isPublished = isset($_POST['is_published']) ? 1 : 0;
        $seoTitle = trim((string) ($_POST['seo_title'] ?? ''));
        $seoDescription = trim((string) ($_POST['seo_description'] ?? ''));
        $seoCanonicalUrl = trim((string) ($_POST['seo_canonical_url'] ?? ''));
        $seoImageUrl = trim((string) ($_POST['seo_image_url'] ?? ''));

        if ($title === '' || trim($htmlContent) === '') {
            Flash::set('error', 'Titlul și codul paginii sunt obligatorii.');
            header('Location: /admin/pages');
            return;
        }

        try {
            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE pages
                     SET title = :title, slug = :slug, html_content = :html_content,
                         css_content = :css_content, js_content = :js_content, is_published = :is_published
                     WHERE id = :id AND deleted_at IS NULL'
                );
                $stmt->execute([
                    'id' => $id,
                    'title' => $title,
                    'slug' => $slug,
                    'html_content' => $htmlContent,
                    'css_content' => $cssContent,
                    'js_content' => $jsContent,
                    'is_published' => $isPublished,
                ]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published)
                     VALUES (:title, :slug, :html_content, :css_content, :js_content, :is_published)'
                );
                $stmt->execute([
                    'title' => $title,
                    'slug' => $slug,
                    'html_content' => $htmlContent,
                    'css_content' => $cssContent,
                    'js_content' => $jsContent,
                    'is_published' => $isPublished,
                ]);
                $id = (int) $db->lastInsertId();
            }
        } catch (Throwable) {
            Flash::set('error', 'Slug-ul există deja sau datele sunt invalide.');
            header('Location: /admin/pages');
            return;
        }
        $this->saveCustomPageSeoSettings($db, $id, [
            'title' => $seoTitle,
            'description' => $seoDescription,
            'canonical_url' => $seoCanonicalUrl,
            'image_url' => $seoImageUrl,
        ]);
        $this->refreshCacheAfterPublicContentChange($db);

        Flash::set('success', 'Pagina a fost salvată.');
        header('Location: /admin/pages/' . $id . '/edit');
    }

    private function defaultSeoSettings(): array
    {
        return [
            'title' => '',
            'description' => '',
            'canonical_url' => '',
            'image_url' => '',
        ];
    }

    private function normalizeSeoPageType(string $pageType): string
    {
        $pageType = trim($pageType);
        if (!in_array($pageType, ['custom_page', 'product', 'blog_post'], true)) {
            return '';
        }
        return $pageType;
    }

    private function normalizeSeoSettings(array $seo): array
    {
        $normalized = $this->defaultSeoSettings();
        $normalized['title'] = trim((string) ($seo['title'] ?? ''));
        $normalized['description'] = trim((string) ($seo['description'] ?? ''));
        $normalized['canonical_url'] = trim((string) ($seo['canonical_url'] ?? ''));
        $normalized['image_url'] = trim((string) ($seo['image_url'] ?? ''));

        if ($normalized['canonical_url'] !== '' && !preg_match('/^https?:\/\//i', $normalized['canonical_url'])) {
            $normalized['canonical_url'] = '';
        }
        if (
            $normalized['image_url'] !== ''
            && preg_match('/^https?:\/\//i', $normalized['image_url']) !== 1
            && !str_starts_with($normalized['image_url'], '/')
        ) {
            $normalized['image_url'] = '/' . ltrim($normalized['image_url'], '/');
        }

        return $normalized;
    }

    private function loadSeoSettings(PDO $db, string $pageType, string $pageRef): array
    {
        $pageType = $this->normalizeSeoPageType($pageType);
        $pageRef = trim($pageRef);
        if ($pageType === '' || $pageRef === '') {
            return $this->defaultSeoSettings();
        }
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
            return is_array($row) ? $this->normalizeSeoSettings($row) : $this->defaultSeoSettings();
        } catch (Throwable) {
            return $this->defaultSeoSettings();
        }
    }

    private function loadSeoSettingsMap(PDO $db, string $pageType, array $pageRefs): array
    {
        $pageType = $this->normalizeSeoPageType($pageType);
        if ($pageType === '') {
            return [];
        }
        $normalizedRefs = [];
        foreach ($pageRefs as $ref) {
            $value = trim((string) $ref);
            if ($value === '' || in_array($value, $normalizedRefs, true)) {
                continue;
            }
            $normalizedRefs[] = $value;
        }
        if ($normalizedRefs === []) {
            return [];
        }

        $result = [];
        $placeholders = implode(',', array_fill(0, count($normalizedRefs), '?'));
        try {
            $stmt = $db->prepare(
                'SELECT page_ref, title, description, canonical_url, image_url
                 FROM seo_pages
                 WHERE page_type = ?
                   AND page_ref IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$pageType], $normalizedRefs));
            $rows = $stmt->fetchAll();
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $ref = trim((string) ($row['page_ref'] ?? ''));
                    if ($ref === '') {
                        continue;
                    }
                    $result[$ref] = $this->normalizeSeoSettings($row);
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $result;
    }

    private function saveSeoSettings(PDO $db, string $pageType, string $pageRef, array $seo): void
    {
        $pageType = $this->normalizeSeoPageType($pageType);
        $pageRef = trim($pageRef);
        if ($pageType === '' || $pageRef === '') {
            return;
        }
        $normalized = $this->normalizeSeoSettings($seo);

        if (
            $normalized['title'] === ''
            && $normalized['description'] === ''
            && $normalized['canonical_url'] === ''
            && $normalized['image_url'] === ''
        ) {
            try {
                $delete = $db->prepare(
                    'DELETE FROM seo_pages
                     WHERE page_type = :page_type
                       AND page_ref = :page_ref'
                );
                $delete->execute([
                    'page_type' => $pageType,
                    'page_ref' => $pageRef,
                ]);
            } catch (Throwable) {
            }
            return;
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO seo_pages (page_type, page_ref, title, description, canonical_url, image_url)
                 VALUES (:page_type, :page_ref, :title, :description, :canonical_url, :image_url)
                 ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    description = VALUES(description),
                    canonical_url = VALUES(canonical_url),
                    image_url = VALUES(image_url)'
            );
            $stmt->execute([
                'page_type' => $pageType,
                'page_ref' => $pageRef,
                'title' => $normalized['title'] !== '' ? $normalized['title'] : null,
                'description' => $normalized['description'] !== '' ? $normalized['description'] : null,
                'canonical_url' => $normalized['canonical_url'] !== '' ? $normalized['canonical_url'] : null,
                'image_url' => $normalized['image_url'] !== '' ? $normalized['image_url'] : null,
            ]);
        } catch (Throwable) {
        }
    }

    private function loadCustomPageSeoSettings(PDO $db, int $pageId): array
    {
        if ($pageId <= 0) {
            return $this->defaultSeoSettings();
        }

        return $this->loadSeoSettings($db, 'custom_page', (string) $pageId);
    }

    private function saveCustomPageSeoSettings(PDO $db, int $pageId, array $seo): void
    {
        if ($pageId <= 0) {
            return;
        }

        $this->saveSeoSettings($db, 'custom_page', (string) $pageId, $seo);
    }

    private function postedEditorContent(string $field): string
    {
        $raw = (string) ($_POST[$field] ?? '');
        $encoded = trim((string) ($_POST[$field . '_b64'] ?? ''));
        if ($encoded === '') {
            return $raw;
        }

        $decoded = base64_decode(str_replace(' ', '+', $encoded), true);
        if (!is_string($decoded)) {
            return $raw;
        }

        return $decoded;
    }

    public function deletePage(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $db = $this->db();
        if ($db instanceof PDO && $id > 0) {
            $stmt = $db->prepare('UPDATE pages SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $id]);
            $this->refreshCacheAfterPublicContentChange($db);
            Flash::set('success', 'Pagina a fost mutată în coș.');
        }

        header('Location: /admin/pages');
    }

    public function pagesTrash(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $pages = [];
        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $pages = $db->query(
                'SELECT id, title, slug, is_published, deleted_at
                 FROM pages
                 WHERE deleted_at IS NOT NULL
                 ORDER BY deleted_at DESC'
            )->fetchAll();
        }

        View::render('admin/pages-trash', [
            'title' => 'Coș pagini',
            'pages' => $pages,
        ], 'admin/layout');
    }

    public function restorePage(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $db = $this->db();
        if ($db instanceof PDO && $id > 0) {
            $stmt = $db->prepare('UPDATE pages SET deleted_at = NULL WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $this->refreshCacheAfterPublicContentChange($db);
            Flash::set('success', 'Pagina a fost restaurată.');
        }

        header('Location: /admin/pages/trash');
    }

    public function forceDeletePage(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $db = $this->db();
        if ($db instanceof PDO && $id > 0) {
            try {
                $seoDelete = $db->prepare(
                    'DELETE FROM seo_pages
                     WHERE page_type = :page_type
                       AND page_ref = :page_ref'
                );
                $seoDelete->execute([
                    'page_type' => 'custom_page',
                    'page_ref' => (string) $id,
                ]);
            } catch (Throwable) {
            }
            $stmt = $db->prepare('DELETE FROM pages WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $this->refreshCacheAfterPublicContentChange($db);
            Flash::set('success', 'Pagina a fost ștearsă definitiv.');
        }

        header('Location: /admin/pages/trash');
    }

    public function gallery(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $images = [];
        $folders = [];
        $folderFilterRaw = trim((string) ($_GET['folder'] ?? ''));
        $showUnassignedOnly = $folderFilterRaw === 'unassigned' || ((string) ($_GET['unassigned'] ?? '0')) === '1';
        $selectedFolderId = ctype_digit($folderFilterRaw) ? (int) $folderFilterRaw : 0;
        $viewMode = trim((string) ($_GET['view'] ?? 'all'));
        $searchQuery = trim((string) ($_GET['q'] ?? ''));
        if (!in_array($viewMode, ['all', 'folders'], true)) {
            $viewMode = 'all';
        }
        $unassignedCount = 0;
        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $folders = $db->query(
                'SELECT f.id, f.name, f.slug,
                        COUNT(g.id) AS items_count,
                        (
                            SELECT gi.image_url
                            FROM gallery_images gi
                            WHERE gi.folder_id = f.id
                            ORDER BY gi.id DESC
                            LIMIT 1
                        ) AS cover_url,
                        (
                            SELECT gi.media_type
                            FROM gallery_images gi
                            WHERE gi.folder_id = f.id
                            ORDER BY gi.id DESC
                            LIMIT 1
                        ) AS cover_media_type
                 FROM gallery_folders f
                 LEFT JOIN gallery_images g ON g.folder_id = f.id
                 GROUP BY f.id
                 ORDER BY f.name ASC'
            )->fetchAll();

            $unassignedCount = (int) $db->query('SELECT COUNT(*) FROM gallery_images WHERE folder_id IS NULL')->fetchColumn();

            if ($showUnassignedOnly) {
                $sql = 'SELECT g.id, g.title, g.media_type, g.image_url, g.folder_id, g.alt_text, g.sort_order, g.is_active, g.created_at, f.name AS folder_name
                        FROM gallery_images g
                        LEFT JOIN gallery_folders f ON f.id = g.folder_id
                        WHERE g.folder_id IS NULL';
                $params = [];
                if ($searchQuery !== '') {
                    $sql .= ' AND (g.title LIKE :q OR g.alt_text LIKE :q OR g.image_url LIKE :q)';
                    $params['q'] = '%' . $searchQuery . '%';
                }
                $sql .= ' ORDER BY g.id DESC';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $images = $stmt->fetchAll();
            } elseif ($selectedFolderId > 0) {
                $sql = 'SELECT g.id, g.title, g.media_type, g.image_url, g.folder_id, g.alt_text, g.sort_order, g.is_active, g.created_at, f.name AS folder_name
                        FROM gallery_images g
                        LEFT JOIN gallery_folders f ON f.id = g.folder_id
                        WHERE g.folder_id = :folder_id';
                $params = ['folder_id' => $selectedFolderId];
                if ($searchQuery !== '') {
                    $sql .= ' AND (g.title LIKE :q OR g.alt_text LIKE :q OR g.image_url LIKE :q)';
                    $params['q'] = '%' . $searchQuery . '%';
                }
                $sql .= ' ORDER BY g.id DESC';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $images = $stmt->fetchAll();
            } else {
                $sql = 'SELECT g.id, g.title, g.media_type, g.image_url, g.folder_id, g.alt_text, g.sort_order, g.is_active, g.created_at, f.name AS folder_name
                        FROM gallery_images g
                        LEFT JOIN gallery_folders f ON f.id = g.folder_id
                        WHERE 1=1';
                $params = [];
                if ($searchQuery !== '') {
                    $sql .= ' AND (g.title LIKE :q OR g.alt_text LIKE :q OR g.image_url LIKE :q)';
                    $params['q'] = '%' . $searchQuery . '%';
                }
                $sql .= ' ORDER BY g.id DESC';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $images = $stmt->fetchAll();
            }
        }

        $publicRoot = __DIR__ . '/../../../public';
        $images = array_map(static function (array $img) use ($publicRoot): array {
            $url = (string) ($img['image_url'] ?? '');
            if (str_starts_with($url, '/') && !str_contains($url, '://')) {
                $img['file_missing'] = !is_file($publicRoot . $url);
            } else {
                $img['file_missing'] = false;
            }
            return $img;
        }, is_array($images) ? $images : []);

        View::render('admin/gallery', [
            'title' => 'Galerie',
            'images' => $images,
            'folders' => $folders,
            'selectedFolderId' => $selectedFolderId,
            'showUnassignedOnly' => $showUnassignedOnly,
            'viewMode' => $viewMode,
            'unassignedCount' => $unassignedCount,
            'searchQuery' => $searchQuery,
        ], 'admin/layout');
    }

    public function galleryCreate(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/gallery');
            return;
        }
        $this->ensureOptionalSchema($db);

        $title = trim((string) ($_POST['title'] ?? ''));
        $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $altText = trim((string) ($_POST['alt_text'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $uploadedFileBaseName = '';
        if (is_array($_FILES['image_file'] ?? null)) {
            $uploadedFileBaseName = trim((string) pathinfo((string) (($_FILES['image_file']['name'] ?? '')), PATHINFO_FILENAME));
            if ($title === '' && $uploadedFileBaseName !== '') {
                $title = trim((string) (preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $uploadedFileBaseName)) ?? ''));
            }
        }

        $uploaded = $this->handleMediaUpload($_FILES['image_file'] ?? null, 'gallery', $title !== '' ? $title : $uploadedFileBaseName);
        $mediaType = 'image';
        if (is_array($uploaded)) {
            $imageUrl = (string) $uploaded['url'];
            $mediaType = (string) $uploaded['media_type'];
        }

        if ($title === '' || $imageUrl === '') {
            Flash::set('error', 'Titlul și imaginea (URL sau upload) sunt obligatorii.');
            header('Location: /admin/gallery');
            return;
        }

        if (!is_array($uploaded)) {
            $mediaType = $this->detectMediaType($imageUrl);
        }

        $stmt = $db->prepare(
            'INSERT INTO gallery_images (title, media_type, image_url, folder_id, alt_text, sort_order, is_active)
             VALUES (:title, :media_type, :image_url, :folder_id, :alt_text, :sort_order, :is_active)'
        );
        $stmt->execute([
            'title' => $title,
            'media_type' => $mediaType,
            'image_url' => $imageUrl,
            'folder_id' => $folderId > 0 ? $folderId : null,
            'alt_text' => $altText,
            'sort_order' => 0,
            'is_active' => $isActive,
        ]);

        Flash::set('success', 'Media adăugată în galerie.');
        header('Location: /admin/gallery');
    }

    public function createGalleryFolder(): void
    {
        if (!$this->guard()) {
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = $slugInput !== '' ? $this->slugify($slugInput) : $this->slugify($name);

        if ($name === '' || $slug === '') {
            Flash::set('error', 'Numele folderului este obligatoriu.');
            header('Location: /admin/gallery');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/gallery');
            return;
        }
        $this->ensureOptionalSchema($db);

        try {
            $stmt = $db->prepare('INSERT INTO gallery_folders (name, slug) VALUES (:name, :slug)');
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
            ]);
            Flash::set('success', 'Folder creat.');
        } catch (Throwable) {
            Flash::set('error', 'Folderul există deja.');
        }

        header('Location: /admin/gallery');
    }

    public function deleteGalleryFolder(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Flash::set('error', 'Folder invalid.');
            header('Location: /admin/gallery');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/gallery');
            return;
        }
        $this->ensureOptionalSchema($db);

        try {
            $db->beginTransaction();
            $clear = $db->prepare('UPDATE gallery_images SET folder_id = NULL WHERE folder_id = :id');
            $clear->execute(['id' => $id]);

            $delete = $db->prepare('DELETE FROM gallery_folders WHERE id = :id');
            $delete->execute(['id' => $id]);
            $db->commit();
            Flash::set('success', 'Folderul a fost șters.');
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Flash::set('error', 'Folderul nu a putut fi șters.');
        }

        header('Location: /admin/gallery?view=all');
    }

    public function moveGalleryItemToFolder(): void
    {
        if (!$this->guard()) {
            return;
        }

        $mediaId = (int) ($_POST['media_id'] ?? 0);
        $folderId = (int) ($_POST['folder_id'] ?? 0);

        if ($mediaId <= 0) {
            $this->galleryMoveResponse(false, 'Fișier invalid.');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->galleryMoveResponse(false, 'Conexiunea DB nu este disponibilă.');
            return;
        }
        $this->ensureOptionalSchema($db);

        if ($folderId > 0) {
            $check = $db->prepare('SELECT id FROM gallery_folders WHERE id = :id LIMIT 1');
            $check->execute(['id' => $folderId]);
            if ($check->fetchColumn() === false) {
                $this->galleryMoveResponse(false, 'Folder inexistent.');
                return;
            }
        }

        $stmt = $db->prepare('UPDATE gallery_images SET folder_id = :folder_id WHERE id = :id');
        if ($folderId > 0) {
            $stmt->bindValue(':folder_id', $folderId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':folder_id', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':id', $mediaId, PDO::PARAM_INT);
        $stmt->execute();

        $this->galleryMoveResponse(true, 'Fișier mutat în folder.');
    }

    public function galleryUpdate(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        $backUrl = $this->safeGalleryBackUrl((string) ($_POST['back_url'] ?? ''));
        if ($id <= 0) {
            Flash::set('error', 'Fișier invalid.');
            header('Location: ' . $backUrl);
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: ' . $backUrl);
            return;
        }
        $this->ensureOptionalSchema($db);

        $title = trim((string) ($_POST['title'] ?? ''));
        $altText = trim((string) ($_POST['alt_text'] ?? ''));
        $isActive = isset($_POST['is_active']) && ((string) ($_POST['is_active'] ?? '0')) === '1' ? 1 : 0;

        if ($title === '') {
            Flash::set('error', 'Titlul este obligatoriu.');
            header('Location: ' . $backUrl);
            return;
        }

        $stmt = $db->prepare(
            'UPDATE gallery_images
             SET title = :title,
                 alt_text = :alt_text,
                 is_active = :is_active
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([
            'title' => $title,
            'alt_text' => $altText,
            'is_active' => $isActive,
            'id' => $id,
        ]);

        Flash::set('success', 'Fișierul a fost actualizat.');
        header('Location: ' . $backUrl);
    }

    public function galleryDelete(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            header('Location: /admin/gallery');
            return;
        }

        $db = $this->db();
        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $imageUrl = null;
            $find = $db->prepare('SELECT image_url FROM gallery_images WHERE id = :id LIMIT 1');
            $find->execute(['id' => $id]);
            $imageUrl = $find->fetchColumn();

            $stmt = $db->prepare('DELETE FROM gallery_images WHERE id = :id');
            $stmt->execute(['id' => $id]);
            if ($imageUrl !== false && is_string($imageUrl)) {
                $this->deleteLocalUploadedFile($imageUrl);
            }
        }

        Flash::set('success', 'Fișier eliminat.');
        header('Location: /admin/gallery');
    }

    public function galleryBulkDelete(): void
    {
        if (!$this->guard()) {
            return;
        }

        $ids = $_POST['image_ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            Flash::set('error', 'Nu ai selectat imagini.');
            header('Location: /admin/gallery');
            return;
        }

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            Flash::set('error', 'Selecție invalidă.');
            header('Location: /admin/gallery');
            return;
        }

        $db = $this->db();
        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $loadStmt = $db->prepare("SELECT image_url FROM gallery_images WHERE id IN ($placeholders)");
            $loadStmt->execute($ids);
            $urls = $loadStmt->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $db->prepare("DELETE FROM gallery_images WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            foreach (array_unique(array_filter(array_map(static fn (mixed $url): string => (string) $url, $urls))) as $url) {
                $this->deleteLocalUploadedFile($url);
            }
            Flash::set('success', 'Fișierele selectate au fost șterse.');
        }

        header('Location: /admin/gallery');
    }

    public function coupons(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $coupons = [];
        $products = [];
        $categories = [];
        $users = [];
        $editingCoupon = null;
        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $this->ensureCouponsSchema($db);

            // Lista din tabul standard exclude cupoanele unice (au tab-ul lor).
            $stmt = $db->prepare(
                'SELECT c.id, c.code, c.name, c.type, c.value, c.is_active, c.starts_at, c.ends_at,
                        c.min_items_count, c.max_items_count, c.max_uses_total,
                        c.product_ids_json, c.category_ids_json, c.allowed_user_ids_json, c.apply_only_selected_products, c.stacks_with_discounts, c.created_at
                 FROM coupons c
                 WHERE COALESCE(c.is_unique, 0) = 0
                 ORDER BY c.id DESC
                 LIMIT 400'
            );
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['product_ids'] = $this->couponIdsFromJson((string) ($row['product_ids_json'] ?? ''));
                $row['category_ids'] = $this->couponIdsFromJson((string) ($row['category_ids_json'] ?? ''));
                $row['allowed_user_ids'] = $this->couponIdsFromJson((string) ($row['allowed_user_ids_json'] ?? ''));
                $row['starts_at_local'] = $this->couponToLocalDateTime((string) ($row['starts_at'] ?? ''));
                $row['ends_at_local'] = $this->couponToLocalDateTime((string) ($row['ends_at'] ?? ''));
                $coupons[] = $row;
            }

            $products = $db->query(
                'SELECT id, name
                 FROM products
                 WHERE deleted_at IS NULL AND is_active = 1
                 ORDER BY name ASC
                 LIMIT 2000'
            )->fetchAll() ?: [];
            $categories = $db->query(
                'SELECT id, name
                 FROM product_categories
                 ORDER BY name ASC
                 LIMIT 1000'
            )->fetchAll() ?: [];
            $users = $db->query(
                'SELECT id, first_name, last_name, email
                 FROM users
                 ORDER BY email ASC
                 LIMIT 3000'
            )->fetchAll() ?: [];

            $editId = (int) ($_GET['coupon'] ?? 0);
            if ($editId > 0) {
                foreach ($coupons as $coupon) {
                    if ((int) ($coupon['id'] ?? 0) === $editId) {
                        $editingCoupon = $coupon;
                        break;
                    }
                }
                // Cupon unic (nu apare în listă) — încarcă-l direct pentru editare.
                if ($editingCoupon === null) {
                    $es = $db->prepare(
                        'SELECT c.id, c.code, c.name, c.type, c.value, c.is_active, c.starts_at, c.ends_at,
                                c.min_items_count, c.max_items_count, c.max_uses_total,
                                c.product_ids_json, c.category_ids_json, c.allowed_user_ids_json,
                                c.apply_only_selected_products, c.stacks_with_discounts, c.is_unique, c.created_at
                         FROM coupons c WHERE c.id = :id LIMIT 1'
                    );
                    $es->execute(['id' => $editId]);
                    $erow = $es->fetch();
                    if (is_array($erow)) {
                        $erow['product_ids'] = $this->couponIdsFromJson((string) ($erow['product_ids_json'] ?? ''));
                        $erow['category_ids'] = $this->couponIdsFromJson((string) ($erow['category_ids_json'] ?? ''));
                        $erow['allowed_user_ids'] = $this->couponIdsFromJson((string) ($erow['allowed_user_ids_json'] ?? ''));
                        $erow['starts_at_local'] = $this->couponToLocalDateTime((string) ($erow['starts_at'] ?? ''));
                        $erow['ends_at_local'] = $this->couponToLocalDateTime((string) ($erow['ends_at'] ?? ''));
                        $editingCoupon = $erow;
                    }
                }
            }
        }

        View::render('admin/coupons', [
            'title' => 'Cupoane',
            'coupons' => $coupons,
            'products' => is_array($products) ? $products : [],
            'categories' => is_array($categories) ? $categories : [],
            'users' => is_array($users) ? $users : [],
            'editingCoupon' => is_array($editingCoupon) ? $editingCoupon : null,
        ], 'admin/layout');
    }

    public function couponsSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/coupons');
            return;
        }
        $this->ensureOptionalSchema($db);
        $this->ensureCouponsSchema($db);

        $action = trim((string) ($_POST['action'] ?? 'save'));
        $id = (int) ($_POST['id'] ?? 0);

        if ($action === 'delete') {
            if ($id <= 0) {
                Flash::set('error', 'Cupon invalid.');
                header('Location: /admin/coupons');
                return;
            }
            $stmt = $db->prepare('DELETE FROM coupons WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            Flash::set('success', 'Cupon șters.');
            header('Location: /admin/coupons');
            return;
        }

        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = trim((string) ($_POST['type'] ?? 'fixed'));
        $value = max(0.0, (float) ($_POST['value'] ?? 0));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $startsAt = $this->couponNormalizeDateTimeNullable((string) ($_POST['starts_at'] ?? ''));
        $endsAt = $this->couponNormalizeDateTimeNullable((string) ($_POST['ends_at'] ?? ''));
        $minItems = max(0, (int) ($_POST['min_items_count'] ?? 0));
        $maxItemsRaw = (int) ($_POST['max_items_count'] ?? 0);
        $maxItems = $maxItemsRaw > 0 ? $maxItemsRaw : 0;
        $maxUsesTotalRaw = (int) ($_POST['max_uses_total'] ?? 0);
        $maxUsesTotal = $maxUsesTotalRaw > 0 ? $maxUsesTotalRaw : 0;
        $productIds = $this->normalizePositiveIds($_POST['product_ids'] ?? []);
        $categoryIds = $this->normalizePositiveIds($_POST['category_ids'] ?? []);
        $allowedUserIds = $this->normalizePositiveIds($_POST['allowed_user_ids'] ?? []);
        $applyOnlySelectedProducts = isset($_POST['apply_only_selected_products']) ? 1 : 0;
        $stacksWithDiscounts = isset($_POST['stacks_with_discounts']) ? 1 : 0;

        if ($code === '' || preg_match('/^[A-Z0-9\-_]{3,100}$/', $code) !== 1) {
            Flash::set('error', 'Codul cuponului este invalid (minim 3 caractere, doar litere/cifre/-/_).');
            header('Location: /admin/coupons' . ($id > 0 ? ('?coupon=' . $id) : ''));
            return;
        }
        if ($name === '' || mb_strlen($name) > 190) {
            Flash::set('error', 'Denumirea cuponului este obligatorie (maxim 190 caractere).');
            header('Location: /admin/coupons' . ($id > 0 ? ('?coupon=' . $id) : ''));
            return;
        }
        if (!in_array($type, ['fixed', 'percent'], true)) {
            $type = 'fixed';
        }
        if ($type === 'percent' && $value > 100.0) {
            $value = 100.0;
        }
        if ($value <= 0.0) {
            Flash::set('error', 'Valoarea cuponului trebuie să fie mai mare decât 0.');
            header('Location: /admin/coupons' . ($id > 0 ? ('?coupon=' . $id) : ''));
            return;
        }
        if ($maxItems > 0 && $maxItems < $minItems) {
            Flash::set('error', 'Maximul de produse din coș nu poate fi mai mic decât minimul.');
            header('Location: /admin/coupons' . ($id > 0 ? ('?coupon=' . $id) : ''));
            return;
        }
        if ($startsAt !== null && $endsAt !== null && strcmp($startsAt, $endsAt) > 0) {
            Flash::set('error', 'Data de început trebuie să fie mai mică decât data de final.');
            header('Location: /admin/coupons' . ($id > 0 ? ('?coupon=' . $id) : ''));
            return;
        }

        try {
            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE coupons
                     SET code = :code,
                         name = :name,
                         type = :type,
                         value = :value,
                         is_active = :is_active,
                         starts_at = :starts_at,
                         ends_at = :ends_at,
                         min_items_count = :min_items_count,
                         max_items_count = :max_items_count,
                        product_ids_json = :product_ids_json,
                        category_ids_json = :category_ids_json,
                        max_uses_total = :max_uses_total,
                        allowed_user_ids_json = :allowed_user_ids_json,
                        apply_only_selected_products = :apply_only_selected_products,
                        stacks_with_discounts = :stacks_with_discounts
                     WHERE id = :id
                     LIMIT 1'
                );
                $stmt->execute([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'value' => $value,
                    'is_active' => $isActive,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'min_items_count' => $minItems,
                    'max_items_count' => $maxItems > 0 ? $maxItems : null,
                    'product_ids_json' => $this->couponIdsToJson($productIds),
                    'category_ids_json' => $this->couponIdsToJson($categoryIds),
                    'max_uses_total' => $maxUsesTotal > 0 ? $maxUsesTotal : null,
                    'allowed_user_ids_json' => $this->couponIdsToJson($allowedUserIds),
                    'apply_only_selected_products' => $applyOnlySelectedProducts,
                    'stacks_with_discounts' => $stacksWithDiscounts,
                    'id' => $id,
                ]);
                Flash::set('success', 'Cupon actualizat.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO coupons (code, name, type, value, is_active, starts_at, ends_at, min_items_count, max_items_count, product_ids_json, category_ids_json, max_uses_total, allowed_user_ids_json, apply_only_selected_products, stacks_with_discounts)
                     VALUES (:code, :name, :type, :value, :is_active, :starts_at, :ends_at, :min_items_count, :max_items_count, :product_ids_json, :category_ids_json, :max_uses_total, :allowed_user_ids_json, :apply_only_selected_products, :stacks_with_discounts)'
                );
                $stmt->execute([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'value' => $value,
                    'is_active' => $isActive,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'min_items_count' => $minItems,
                    'max_items_count' => $maxItems > 0 ? $maxItems : null,
                    'product_ids_json' => $this->couponIdsToJson($productIds),
                    'category_ids_json' => $this->couponIdsToJson($categoryIds),
                    'max_uses_total' => $maxUsesTotal > 0 ? $maxUsesTotal : null,
                    'allowed_user_ids_json' => $this->couponIdsToJson($allowedUserIds),
                    'apply_only_selected_products' => $applyOnlySelectedProducts,
                    'stacks_with_discounts' => $stacksWithDiscounts,
                ]);
                $id = (int) $db->lastInsertId();
                Flash::set('success', 'Cupon creat.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Codul cuponului există deja sau datele sunt invalide.');
            header('Location: /admin/coupons' . ($id > 0 ? ('?coupon=' . $id) : ''));
            return;
        }

        // Dacă am editat un cupon unic, revino la tabul lui.
        $isUnique = false;
        if ($id > 0) {
            try {
                $uc = $db->prepare('SELECT is_unique FROM coupons WHERE id = :id LIMIT 1');
                $uc->execute(['id' => $id]);
                $isUnique = ((int) $uc->fetchColumn()) === 1;
            } catch (Throwable) {
            }
        }
        header('Location: ' . ($isUnique ? '/admin/coupons/unique' : '/admin/coupons'));
    }

    /**
     * Tab dedicat pentru cupoanele unice (importate în lot). Are două vederi:
     * „active" (used_at IS NULL) și „folosite" (used_at IS NOT NULL).
     */
    public function couponsUnique(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $coupons = [];
        $products = [];
        $categories = [];
        $users = [];
        $activeCount = 0;
        $usedCount = 0;
        $totalFiltered = 0;

        $view = ($_GET['view'] ?? 'active') === 'used' ? 'used' : 'active';
        $q = trim((string) ($_GET['q'] ?? ''));
        $batch = trim((string) ($_GET['batch'] ?? ''));
        $perPage = (int) ($_GET['per'] ?? 50);
        if (!in_array($perPage, [50, 100, 250, 500], true)) {
            $perPage = 50;
        }
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $batches = [];

        if ($db instanceof PDO) {
            $this->ensureOptionalSchema($db);
            $this->ensureCouponsSchema($db);

            try {
                $activeCount = (int) $db->query('SELECT COUNT(*) FROM coupons WHERE is_unique = 1 AND used_at IS NULL')->fetchColumn();
                $usedCount = (int) $db->query('SELECT COUNT(*) FROM coupons WHERE is_unique = 1 AND used_at IS NOT NULL')->fetchColumn();
            } catch (Throwable) {
            }

            $usedCond = $view === 'used' ? 'used_at IS NOT NULL' : 'used_at IS NULL';
            $params = [];
            $where = 'is_unique = 1 AND ' . $usedCond;
            if ($q !== '') {
                $where .= ' AND (code LIKE :q OR name LIKE :q)';
                $params['q'] = '%' . $q . '%';
            }
            if ($batch !== '') {
                $where .= ' AND import_batch = :batch';
                $params['batch'] = $batch;
            }

            try {
                $bs = $db->query("SELECT DISTINCT import_batch FROM coupons WHERE is_unique = 1 AND import_batch IS NOT NULL AND import_batch <> '' ORDER BY import_batch ASC");
                foreach ($bs->fetchAll() ?: [] as $br) {
                    $lbl = trim((string) ($br['import_batch'] ?? ''));
                    if ($lbl !== '') {
                        $batches[] = $lbl;
                    }
                }
            } catch (Throwable) {
            }

            try {
                $cs = $db->prepare("SELECT COUNT(*) FROM coupons WHERE $where");
                $cs->execute($params);
                $totalFiltered = (int) $cs->fetchColumn();
            } catch (Throwable) {
            }

            $offset = ($page - 1) * $perPage;
            // Doar în tabul „folosite" atașăm comanda în care s-a consumat cuponul.
            $orderCols = $view === 'used'
                ? ", (SELECT o.id FROM orders o WHERE o.coupon_code = c.code ORDER BY o.id DESC LIMIT 1) AS order_id,
                     (SELECT o.order_number FROM orders o WHERE o.coupon_code = c.code ORDER BY o.id DESC LIMIT 1) AS order_number"
                : '';
            try {
                $stmt = $db->prepare(
                    "SELECT c.id, c.code, c.name, c.type, c.value, c.is_active, c.starts_at, c.ends_at,
                            c.min_items_count, c.max_items_count, c.used_at, c.import_batch, c.created_at$orderCols
                     FROM coupons c
                     WHERE $where
                     ORDER BY " . ($view === 'used' ? 'c.used_at DESC' : 'c.id ASC') . "
                     LIMIT $perPage OFFSET $offset"
                );
                $stmt->execute($params);
                $coupons = $stmt->fetchAll() ?: [];
                foreach ($coupons as &$c) {
                    $c['used_at_local'] = $this->couponToLocalDateTime((string) ($c['used_at'] ?? ''));
                }
                unset($c);
            } catch (Throwable) {
                $coupons = [];
            }

            $products = $db->query('SELECT id, name FROM products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC LIMIT 2000')->fetchAll() ?: [];
            $categories = $db->query('SELECT id, name FROM product_categories ORDER BY name ASC LIMIT 1000')->fetchAll() ?: [];
            $users = $db->query('SELECT id, first_name, last_name, email FROM users ORDER BY email ASC LIMIT 3000')->fetchAll() ?: [];
        }

        View::render('admin/coupons-unique', [
            'title' => 'Cupoane unice',
            'coupons' => is_array($coupons) ? $coupons : [],
            'products' => is_array($products) ? $products : [],
            'categories' => is_array($categories) ? $categories : [],
            'users' => is_array($users) ? $users : [],
            'view' => $view,
            'q' => $q,
            'batch' => $batch,
            'batches' => $batches,
            'page' => $page,
            'perPage' => $perPage,
            'totalFiltered' => $totalFiltered,
            'activeCount' => $activeCount,
            'usedCount' => $usedCount,
        ], 'admin/layout');
    }

    /**
     * Import în lot al cupoanelor unice din fișier (.xlsx/.csv/.txt) sau text lipit,
     * cu setări comune aplicate tuturor codurilor.
     */
    public function couponsUniqueImport(): void
    {
        if (!$this->guard()) {
            return;
        }

        // Un import mare nu trebuie lăsat pe jumătate dacă browserul se deconectează
        // (ex: firewall-ul hostului resetează conexiunea la un upload .xlsx).
        @ignore_user_abort(true);
        @set_time_limit(120);

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/coupons/unique');
            return;
        }
        $this->ensureOptionalSchema($db);
        $this->ensureCouponsSchema($db);

        // Coduri: din fișier și/sau din textarea
        $raw = [];
        if (isset($_FILES['codes_file']) && is_array($_FILES['codes_file']) && (int) ($_FILES['codes_file']['error'] ?? 4) === 0) {
            $raw = array_merge($raw, $this->extractCodesFromUpload($_FILES['codes_file']));
        }
        $pasted = trim((string) ($_POST['codes'] ?? ''));
        if ($pasted !== '') {
            $raw = array_merge($raw, preg_split('/[\s,;]+/', $pasted) ?: []);
        }
        if ($raw === []) {
            Flash::set('error', 'Nu am găsit niciun cod de importat (încarcă un fișier sau lipește codurile).');
            header('Location: /admin/coupons/unique');
            return;
        }

        // Setări comune (aceleași câmpuri ca la cupoanele obișnuite)
        $type = in_array(($_POST['type'] ?? 'fixed'), ['fixed', 'percent'], true) ? $_POST['type'] : 'fixed';
        $value = max(0.0, (float) ($_POST['value'] ?? 0));
        if ($type === 'percent' && $value > 100.0) {
            $value = 100.0;
        }
        if ($value <= 0.0) {
            Flash::set('error', 'Valoarea reducerii trebuie să fie mai mare decât 0.');
            header('Location: /admin/coupons/unique');
            return;
        }
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $startsAt = $this->couponNormalizeDateTimeNullable((string) ($_POST['starts_at'] ?? ''));
        $endsAt = $this->couponNormalizeDateTimeNullable((string) ($_POST['ends_at'] ?? ''));
        $minItems = max(0, (int) ($_POST['min_items_count'] ?? 0));
        $maxItemsRaw = (int) ($_POST['max_items_count'] ?? 0);
        $maxItems = $maxItemsRaw > 0 ? $maxItemsRaw : 0;
        if ($maxItems > 0 && $maxItems < $minItems) {
            Flash::set('error', 'Maximul de produse nu poate fi mai mic decât minimul.');
            header('Location: /admin/coupons/unique');
            return;
        }
        $productIds = $this->normalizePositiveIds($_POST['product_ids'] ?? []);
        $categoryIds = $this->normalizePositiveIds($_POST['category_ids'] ?? []);
        $allowedUserIds = $this->normalizePositiveIds($_POST['allowed_user_ids'] ?? []);
        $applyOnlySelectedProducts = isset($_POST['apply_only_selected_products']) ? 1 : 0;
        $stacksWithDiscounts = isset($_POST['stacks_with_discounts']) ? 1 : 0;
        $namePrefix = trim((string) ($_POST['name_prefix'] ?? ''));
        if (mb_strlen($namePrefix) > 120) {
            $namePrefix = mb_substr($namePrefix, 0, 120);
        }
        $batchLabel = trim((string) ($_POST['batch_label'] ?? ''));
        if ($batchLabel === '') {
            $batchLabel = 'Import ' . date('Y-m-d H:i');
        }
        $batchLabel = mb_substr($batchLabel, 0, 190);

        $productJson = $this->couponIdsToJson($productIds);
        $categoryJson = $this->couponIdsToJson($categoryIds);
        $allowedJson = $this->couponIdsToJson($allowedUserIds);

        $inserted = 0;
        $skipped = 0;
        $invalid = 0;
        $seen = [];

        try {
            $ins = $db->prepare(
                'INSERT IGNORE INTO coupons
                    (code, name, type, value, is_active, starts_at, ends_at, min_items_count, max_items_count,
                     product_ids_json, category_ids_json, max_uses_total, allowed_user_ids_json,
                     apply_only_selected_products, stacks_with_discounts, is_unique, used_at, import_batch)
                 VALUES
                    (:code, :name, :type, :value, :is_active, :starts_at, :ends_at, :min_items_count, :max_items_count,
                     :product_ids_json, :category_ids_json, NULL, :allowed_user_ids_json,
                     :apply_only_selected_products, :stacks_with_discounts, 1, NULL, :import_batch)'
            );
            $db->beginTransaction();
            foreach ($raw as $rc) {
                $code = $this->normalizeCouponCode((string) $rc);
                if ($code === null) {
                    $invalid++;
                    continue;
                }
                if (isset($seen[$code])) {
                    $skipped++;
                    continue;
                }
                $seen[$code] = true;
                $name = $namePrefix !== '' ? ($namePrefix . ' ' . $code) : $code;
                $ins->execute([
                    'code' => $code,
                    'name' => mb_substr($name, 0, 190),
                    'type' => $type,
                    'value' => $value,
                    'is_active' => $isActive,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'min_items_count' => $minItems,
                    'max_items_count' => $maxItems > 0 ? $maxItems : null,
                    'product_ids_json' => $productJson,
                    'category_ids_json' => $categoryJson,
                    'allowed_user_ids_json' => $allowedJson,
                    'apply_only_selected_products' => $applyOnlySelectedProducts,
                    'stacks_with_discounts' => $stacksWithDiscounts,
                    'import_batch' => $batchLabel,
                ]);
                if ($ins->rowCount() > 0) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Flash::set('error', 'Importul a eșuat. Verifică formatul fișierului și încearcă din nou.');
            header('Location: /admin/coupons/unique');
            return;
        }

        $msg = "Import finalizat: $inserted adăugate";
        if ($skipped > 0) {
            $msg .= ", $skipped ignorate (deja existau/duplicate)";
        }
        if ($invalid > 0) {
            $msg .= ", $invalid invalide";
        }
        Flash::set('success', $msg . '.');
        header('Location: /admin/coupons/unique');
    }

    /**
     * Acțiuni pe cupoanele unice: reactivare (individual/tot), ștergere (individual/tot).
     */
    public function couponsUniqueAction(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            header('Location: /admin/coupons/unique');
            return;
        }
        $this->ensureCouponsSchema($db);

        $action = trim((string) ($_POST['action'] ?? ''));
        $id = (int) ($_POST['id'] ?? 0);
        $backView = ($_POST['view'] ?? 'active') === 'used' ? 'used' : 'active';
        $backBatch = trim((string) ($_POST['batch'] ?? ''));
        $redirect = '/admin/coupons/unique?view=' . $backView . ($backBatch !== '' ? '&batch=' . rawurlencode($backBatch) : '');

        // ID-uri selectate pentru acțiunile în masă.
        $bulkIds = [];
        foreach ((array) ($_POST['ids'] ?? []) as $rawId) {
            $iid = (int) $rawId;
            if ($iid > 0) {
                $bulkIds[] = $iid;
            }
        }

        try {
            if ($action === 'reactivate' && $id > 0) {
                $db->prepare('UPDATE coupons SET used_at = NULL WHERE id = :id AND is_unique = 1 LIMIT 1')->execute(['id' => $id]);
                Flash::set('success', 'Cupon reactivat.');
            } elseif ($action === 'reactivate_all') {
                $n = $db->exec('UPDATE coupons SET used_at = NULL WHERE is_unique = 1 AND used_at IS NOT NULL');
                Flash::set('success', 'Am reactivat ' . (int) $n . ' cupoane.');
                $redirect = '/admin/coupons/unique?view=active';
            } elseif ($action === 'delete' && $id > 0) {
                $db->prepare('DELETE FROM coupons WHERE id = :id AND is_unique = 1 LIMIT 1')->execute(['id' => $id]);
                Flash::set('success', 'Cupon șters.');
            } elseif ($action === 'delete_all_used') {
                $n = $db->exec('DELETE FROM coupons WHERE is_unique = 1 AND used_at IS NOT NULL');
                Flash::set('success', 'Am șters ' . (int) $n . ' cupoane folosite.');
            } elseif (in_array($action, ['bulk_delete', 'bulk_reactivate', 'bulk_edit'], true)) {
                // Scope: 'batch' = tot lotul selectat în filtru; altfel doar bifele.
                $scope = ((string) ($_POST['scope'] ?? 'selected')) === 'batch' ? 'batch' : 'selected';
                $targetSql = '';
                $targetParams = [];
                if ($scope === 'batch') {
                    if ($backBatch === '') {
                        Flash::set('error', 'Selectează un lot din filtru mai întâi.');
                        header('Location: ' . $redirect);
                        return;
                    }
                    $targetSql = 'is_unique = 1 AND import_batch = ?';
                    $targetParams = [$backBatch];
                } elseif ($bulkIds === []) {
                    Flash::set('error', 'Nu ai selectat niciun cupon.');
                    header('Location: ' . $redirect);
                    return;
                } else {
                    $ph = implode(',', array_fill(0, count($bulkIds), '?'));
                    $targetSql = "is_unique = 1 AND id IN ($ph)";
                    $targetParams = $bulkIds;
                }

                {
                    if ($action === 'bulk_delete') {
                        $st = $db->prepare("DELETE FROM coupons WHERE $targetSql");
                        $st->execute($targetParams);
                        Flash::set('success', 'Am șters ' . $st->rowCount() . ' cupoane.');
                    } elseif ($action === 'bulk_reactivate') {
                        $st = $db->prepare("UPDATE coupons SET used_at = NULL WHERE $targetSql");
                        $st->execute($targetParams);
                        Flash::set('success', 'Am reactivat ' . $st->rowCount() . ' cupoane.');
                    } else { // bulk_edit
                        $sets = [];
                        $vals = [];
                        if (isset($_POST['edit_value'])) {
                            $type = ((string) ($_POST['type'] ?? 'fixed')) === 'percent' ? 'percent' : 'fixed';
                            $value = (float) str_replace(',', '.', (string) ($_POST['value'] ?? '0'));
                            if ($value <= 0) {
                                Flash::set('error', 'Valoarea reducerii trebuie să fie mai mare decât 0.');
                                header('Location: ' . $redirect);
                                return;
                            }
                            if ($type === 'percent' && $value > 100) {
                                $value = 100.0;
                            }
                            $sets[] = 'type = ?';
                            $vals[] = $type;
                            $sets[] = 'value = ?';
                            $vals[] = $value;
                        }
                        if (isset($_POST['edit_period'])) {
                            $startsRaw = trim((string) ($_POST['starts_at'] ?? ''));
                            $endsRaw = trim((string) ($_POST['ends_at'] ?? ''));
                            $sets[] = 'starts_at = ?';
                            $vals[] = $startsRaw !== '' ? date('Y-m-d H:i:s', strtotime($startsRaw) ?: time()) : null;
                            $sets[] = 'ends_at = ?';
                            $vals[] = $endsRaw !== '' ? date('Y-m-d H:i:s', strtotime($endsRaw) ?: time()) : null;
                        }
                        if (isset($_POST['edit_active'])) {
                            $sets[] = 'is_active = ?';
                            $vals[] = ((string) ($_POST['is_active_value'] ?? '1')) === '1' ? 1 : 0;
                        }
                        if (isset($_POST['edit_stacks'])) {
                            $sets[] = 'stacks_with_discounts = ?';
                            $vals[] = ((string) ($_POST['stacks_value'] ?? '1')) === '1' ? 1 : 0;
                        }
                        if ($sets === []) {
                            Flash::set('error', 'Nu ai bifat niciun câmp de modificat.');
                        } else {
                            $sql = 'UPDATE coupons SET ' . implode(', ', $sets) . " WHERE $targetSql";
                            $st = $db->prepare($sql);
                            $st->execute(array_merge($vals, $targetParams));
                            Flash::set('success', 'Am actualizat ' . $st->rowCount() . ' cupoane.');
                        }
                    }
                }
            } else {
                Flash::set('error', 'Acțiune invalidă.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Acțiunea a eșuat.');
        }

        header('Location: ' . $redirect);
    }

    /**
     * Normalizează un cod de cupon; întoarce null dacă e invalid.
     */
    private function normalizeCouponCode(string $raw): ?string
    {
        $code = strtoupper(trim($raw));
        if ($code === '' || preg_match('/^[A-Z0-9\-_]{3,100}$/', $code) !== 1) {
            return null;
        }
        return $code;
    }

    /**
     * Extrage codurile dintr-un fișier încărcat (.xlsx/.csv/.txt).
     * @param array<string,mixed> $file
     * @return array<int,string>
     */
    private function extractCodesFromUpload(array $file): array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return [];
        }
        $name = strtolower((string) ($file['name'] ?? ''));
        if (str_ends_with($name, '.xlsx')) {
            return $this->parseXlsxCodes($tmp);
        }
        $content = (string) file_get_contents($tmp);
        return preg_split('/[\s,;]+/', $content) ?: [];
    }

    /**
     * Citește valorile text din prima foaie a unui .xlsx (fără librării externe).
     * @return array<int,string>
     */
    private function parseXlsxCodes(string $path): array
    {
        $out = [];
        if (!class_exists('ZipArchive')) {
            return $out;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return $out;
        }

        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($ss) && $ss !== '' && preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $ss, $m)) {
            foreach ($m[1] as $si) {
                $txt = '';
                if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                    $txt = implode('', $tm[1]);
                }
                $shared[] = html_entity_decode(strip_tags($txt), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!is_string($sheet) || $sheet === '') {
            return $out;
        }

        if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $sheet, $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                $attrs = $c[1] ?? '';
                $inner = $c[2] ?? '';
                $t = '';
                if (preg_match('/\st="([^"]*)"/', $attrs, $tm2)) {
                    $t = $tm2[1];
                }
                $val = '';
                if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                    if ($t === 's') {
                        $val = $shared[(int) $vm[1]] ?? '';
                    } else {
                        $val = $vm[1];
                    }
                } elseif ($t === 'inlineStr' && preg_match('/<t\b[^>]*>(.*?)<\/t>/s', $inner, $im)) {
                    $val = $im[1];
                }
                $val = trim(html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($val !== '') {
                    $out[] = $val;
                }
            }
        }

        return $out;
    }

    private function ensureCouponsSchema(PDO $db): void
    {
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS coupons (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                code VARCHAR(100) NOT NULL UNIQUE,
                type ENUM("fixed", "percent") NOT NULL DEFAULT "fixed",
                value DECIMAL(10,2) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                starts_at DATETIME DEFAULT NULL,
                ends_at DATETIME DEFAULT NULL,
                min_items_count INT UNSIGNED NOT NULL DEFAULT 0,
                max_items_count INT UNSIGNED DEFAULT NULL,
                product_ids_json LONGTEXT DEFAULT NULL,
                category_ids_json LONGTEXT DEFAULT NULL,
                max_uses_total INT UNSIGNED DEFAULT NULL,
                allowed_user_ids_json LONGTEXT DEFAULT NULL,
                apply_only_selected_products TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN name VARCHAR(190) DEFAULT NULL AFTER code');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE coupons SET name = code WHERE name IS NULL OR name = ""');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN min_items_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER ends_at');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN max_items_count INT UNSIGNED DEFAULT NULL AFTER min_items_count');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN product_ids_json LONGTEXT DEFAULT NULL AFTER max_items_count');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN category_ids_json LONGTEXT DEFAULT NULL AFTER product_ids_json');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN max_uses_total INT UNSIGNED DEFAULT NULL AFTER max_items_count');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN allowed_user_ids_json LONGTEXT DEFAULT NULL AFTER max_uses_total');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN apply_only_selected_products TINYINT(1) NOT NULL DEFAULT 0 AFTER allowed_user_ids_json');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN stacks_with_discounts TINYINT(1) NOT NULL DEFAULT 1 AFTER apply_only_selected_products');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN is_unique TINYINT(1) NOT NULL DEFAULT 0 AFTER apply_only_selected_products');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN used_at DATETIME DEFAULT NULL AFTER is_unique');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE coupons ADD COLUMN import_batch VARCHAR(190) DEFAULT NULL AFTER used_at');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE coupons SET min_items_count = COALESCE(min_items, 0) WHERE min_items_count = 0 AND min_items IS NOT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE coupons SET max_items_count = max_items WHERE max_items_count IS NULL AND max_items IS NOT NULL');
        } catch (Throwable) {
        }
    }

    private function couponIdsToJson(array $ids): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return '[]';
        }

        return (string) json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function couponIdsFromJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $decoded), static fn (int $id): bool => $id > 0)));
    }

    private function couponNormalizeDateTimeNullable(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $normalized = str_replace('T', ' ', $raw);
        if (preg_match('/^\d{4}\-\d{2}\-\d{2}\s+\d{2}\:\d{2}$/', $normalized) === 1) {
            $normalized .= ':00';
        }
        $ts = strtotime($normalized);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function couponToLocalDateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return '';
        }

        return date('Y-m-d\TH:i', $ts);
    }

    public function designSite(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $settings = Settings::all($db);
        $availableMenuPages = [];
        $menuItems = [];
        $section = trim((string) ($_GET['section'] ?? 'header'));
        $allowed = ['header', 'footer', 'menu'];
        if (!in_array($section, $allowed, true)) {
            $section = 'header';
        }

        if ($db instanceof PDO && $section === 'menu') {
            $this->ensureOptionalSchema($db);
            $availableMenuPages = $this->availableMenuPages($db);
            $menuItems = $this->menuItemsFromSettings($settings, $availableMenuPages);
        }

        View::render('admin/design-site', [
            'title' => 'Design Site',
            'settings' => $settings,
            'section' => $section,
            'availableMenuPages' => $availableMenuPages,
            'menuItems' => $menuItems,
        ], 'admin/layout');
    }

    public function designSiteSave(): void
    {
        if (!$this->guard()) {
            return;
        }

        $section = trim((string) ($_POST['section'] ?? 'header'));
        $allowed = ['header', 'footer', 'menu'];
        if (!in_array($section, $allowed, true)) {
            $section = 'header';
        }

        $contentHtml = (string) ($_POST['html_content'] ?? '');
        $contentCss = (string) ($_POST['css_content'] ?? '');
        $contentJs = (string) ($_POST['js_content'] ?? '');
        $savePayload = [
            'design_' . $section . '_html' => $contentHtml,
            'design_' . $section . '_css' => $contentCss,
            'design_' . $section . '_js' => $contentJs,
        ];

        if ($section === 'menu') {
            $menuItems = json_decode((string) ($_POST['menu_items_json'] ?? '[]'), true);
            if (!is_array($menuItems)) {
                $menuItems = [];
            }
            $menuItems = $this->normalizeMenuItems($menuItems);
            $contentHtml = $this->menuHtmlFromItems($menuItems);
            $savePayload['design_menu_items_json'] = json_encode($menuItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $savePayload['design_menu_html'] = $contentHtml;
        }

        $db = $this->db();
        Settings::save($db, $savePayload);

        Flash::set('success', 'Secțiunea a fost salvată.');
        header('Location: /admin/design?section=' . urlencode($section));
    }

    private function db(): ?PDO
    {
        $config = require __DIR__ . '/../../../config/app.php';
        return Database::connection($config['db']);
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

    private function guard(): bool
    {
        if (!Auth::check()) {
            header('Location: /admin/login');
            return false;
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH) ?: '/admin';
        if (!Auth::canAccessPath($path)) {
            Flash::set('error', 'Nu ai permisiunea necesară pentru această secțiune.');
            header('Location: ' . Auth::defaultPathForRole());
            return false;
        }

        return true;
    }

    private function categoriesList(): array
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            return [];
        }
        $this->ensureOptionalSchema($db);

        return $db->query('SELECT id, name, slug FROM product_categories ORDER BY name ASC')->fetchAll();
    }

    private function categoryNameById(int $categoryId): ?string
    {
        if ($categoryId <= 0) {
            return null;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            return null;
        }
        $this->ensureOptionalSchema($db);

        $stmt = $db->prepare('SELECT name FROM product_categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $categoryId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? (string) $name : null;
    }

    private function normalizeProductFieldKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    private function normalizeProductGalleryJson(mixed $raw): string
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
        $urls = $this->normalizeProductGalleryUrls($decoded);
        return json_encode($urls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function normalizeProductGalleryUrls(array $rawUrls): array
    {
        $urls = [];
        foreach ($rawUrls as $raw) {
            $value = trim((string) $raw);
            if ($value === '') {
                continue;
            }
            if (!in_array($value, $urls, true)) {
                $urls[] = $value;
            }
            if (count($urls) >= 12) {
                break;
            }
        }
        return $urls;
    }

    private function normalizeProductSimilarJson(mixed $raw): string
    {
        $ids = $this->normalizeProductSimilarIds($raw);
        return json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function normalizeProductSalePeriodsJson(mixed $raw): string
    {
        $periods = $this->normalizeProductSalePeriods($raw);
        return json_encode($periods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function normalizeProductBbdEntriesJson(mixed $raw): string
    {
        $entries = $this->normalizeProductBbdEntries($raw);
        return json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * Returns a map of BBD entry key => quantity reserved by active orders
     * (same rule the storefront uses to compute remaining offer stock).
     *
     * @return array<string, int>
     */
    private function computeBbdReservedMap(PDO $db, int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }
        try {
            $stmt = $db->prepare(
                "SELECT oi.bbd_key AS bkey, COALESCE(SUM(oi.quantity), 0) AS qty
                 FROM order_items oi
                 INNER JOIN orders o ON o.id = oi.order_id
                 WHERE oi.product_id = :pid
                   AND oi.bbd_key IS NOT NULL AND oi.bbd_key <> ''
                   AND o.deleted_at IS NULL
                   AND o.status NOT IN ('cancelled', 'failed', 'refunded', 'pending_payment')
                 GROUP BY oi.bbd_key"
            );
            $stmt->execute(['pid' => $productId]);
            $map = [];
            foreach (($stmt->fetchAll() ?: []) as $row) {
                $map[(string) ($row['bkey'] ?? '')] = max(0, (int) ($row['qty'] ?? 0));
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
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
            $normalizedDate = date('Y-m-d', $timestamp);
            $lot = $this->normalizeProductBbdLot((string) ($item['lot'] ?? $item['bbd_lot'] ?? ''));

            $priceRaw = trim((string) ($item['reduced_price'] ?? $item['price'] ?? ''));
            $reducedPrice = null;
            if ($priceRaw !== '') {
                $numeric = str_replace(',', '.', $priceRaw);
                if (is_numeric($numeric)) {
                    $candidate = max(0.0, (float) $numeric);
                    if ($candidate > 0.0) {
                        $reducedPrice = (float) number_format($candidate, 2, '.', '');
                    }
                }
            }
            $stockRaw = trim((string) ($item['stock'] ?? $item['bbd_stock'] ?? ''));
            $stock = null;
            if ($stockRaw !== '') {
                $numericStock = str_replace(',', '.', $stockRaw);
                if (is_numeric($numericStock)) {
                    $stock = max(0, (int) floor((float) $numericStock));
                }
            }

            $key = $normalizedDate . '|' . $lot . '|' . ($reducedPrice === null ? '' : number_format($reducedPrice, 4, '.', ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $label = 'Expiră la data de: ' . date('d.m.Y', $timestamp);
            $entries[] = [
                'key' => substr(sha1($normalizedDate . '|' . $lot . '|' . ($reducedPrice === null ? '-' : number_format($reducedPrice, 2, '.', ''))), 0, 20),
                'date' => $normalizedDate,
                'lot' => $lot,
                'label' => $label,
                'reduced_price' => $reducedPrice,
                'stock' => $stock,
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

    private function normalizeProductBbdLot(string $raw): string
    {
        $upper = strtoupper(trim($raw));
        if ($upper === '') {
            return '';
        }
        $safe = preg_replace('/[^A-Z0-9\-_.\/]/', '', $upper) ?? '';
        if ($safe === '' || strlen($safe) > 40) {
            return '';
        }
        return $safe;
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
        $now = time();
        $activePeriodSale = null;
        foreach ($periods as $period) {
            if (!is_array($period)) {
                continue;
            }
            $startTs = strtotime((string) ($period['start_date'] ?? ''));
            $endTs = strtotime((string) ($period['end_date'] ?? ''));
            $periodSale = max(0.0, (float) ($period['sale_price'] ?? 0.0));
            if ($startTs === false || $endTs === false || $periodSale <= 0.0) {
                continue;
            }
            if ($now < $startTs || $now > $endTs) {
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

    private function normalizeProductSimilarIds(mixed $raw): array
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

        $ids = [];
        foreach ($decoded as $value) {
            $id = (int) $value;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            if (count($ids) >= 12) {
                break;
            }
        }
        return $ids;
    }

    private function loadProductExtraFields(PDO $db): array
    {
        try {
            $rows = $db->query(
                'SELECT id, name, field_key, field_type, placeholder, default_value, is_required, sort_order, is_active
                 FROM product_extra_fields
                 ORDER BY sort_order ASC, id ASC'
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            // Legacy fallback for old draft schema fields.
            $rows = $db->query(
                'SELECT id, label AS name, field_key, input_type AS field_type, placeholder, NULL AS default_value, 0 AS is_required, sort_order, is_active
                 FROM product_extra_fields
                 ORDER BY sort_order ASC, id ASC'
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        }
    }

    private function loadProductExtraFieldById(PDO $db, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT id, name, field_key, field_type, placeholder, default_value, is_required, sort_order, is_active
                 FROM product_extra_fields
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch() ?: null;
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            $stmt = $db->prepare(
                'SELECT id, label AS name, field_key, input_type AS field_type, placeholder, NULL AS default_value, 0 AS is_required, sort_order, is_active
                 FROM product_extra_fields
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch() ?: null;
            return is_array($row) ? $row : null;
        }
    }

    private function loadProductTemplates(PDO $db): array
    {
        try {
            $rows = $db->query(
                'SELECT id, name, slug, description, html_content, css_content, js_content, is_active
                 FROM product_templates
                 ORDER BY id DESC'
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            $rows = $db->query(
                'SELECT id, name, slug, NULL AS description, html_content, css_content, js_content, is_active
                 FROM product_templates
                 ORDER BY id DESC'
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        }
    }

    private function loadProductTemplateById(PDO $db, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT id, name, slug, description, html_content, css_content, js_content, is_active
                 FROM product_templates
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch() ?: null;
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            $stmt = $db->prepare(
                'SELECT id, name, slug, NULL AS description, html_content, css_content, js_content, is_active
                 FROM product_templates
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch() ?: null;
            return is_array($row) ? $row : null;
        }
    }

    private function loadBlogAuthors(PDO $db, bool $onlyActive = false): array
    {
        $where = $onlyActive ? 'WHERE a.is_active = 1' : '';
        try {
            $stmt = $db->query(
                'SELECT a.id, a.name, a.slug, a.bio, a.avatar_url, a.is_active,
                        COUNT(p.id) AS posts_count
                 FROM blog_authors a
                 LEFT JOIN blog_posts p ON p.author_id = a.id AND p.deleted_at IS NULL
                 ' . $where . '
                 GROUP BY a.id
                 ORDER BY a.name ASC, a.id DESC'
            );
            $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function loadBlogAuthorById(PDO $db, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT id, name, slug, bio, avatar_url, is_active
                 FROM blog_authors
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch() ?: null;
            if (!is_array($row)) {
                return null;
            }
            // Categoriile (many-to-many) selectate pentru această postare.
            $catIds = [];
            try {
                $catStmt = $db->prepare('SELECT category_id FROM blog_post_categories WHERE post_id = :id');
                $catStmt->execute(['id' => $id]);
                foreach ($catStmt->fetchAll() as $cr) {
                    $cid = (int) ($cr['category_id'] ?? 0);
                    if ($cid > 0) {
                        $catIds[] = $cid;
                    }
                }
            } catch (Throwable) {
            }
            if ($catIds === [] && (int) ($row['category_id'] ?? 0) > 0) {
                $catIds[] = (int) $row['category_id'];
            }
            $row['category_ids'] = $catIds;
            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    private function loadBlogTemplates(PDO $db, bool $onlyActive = false): array
    {
        $where = $onlyActive ? 'WHERE is_active = 1' : '';
        try {
            $stmt = $db->query(
                'SELECT id, name, slug, description, html_content, css_content, js_content, is_active
                 FROM blog_templates
                 ' . $where . '
                 ORDER BY id DESC'
            );
            $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function loadBlogTemplateById(PDO $db, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT id, name, slug, description, html_content, css_content, js_content, is_active
                 FROM blog_templates
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch() ?: null;
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function loadBlogPostsAdmin(PDO $db, string $status = 'all', bool $onlyDeleted = false): array
    {
        $where = [];
        if ($onlyDeleted) {
            $where[] = 'p.deleted_at IS NOT NULL';
        } else {
            $where[] = 'p.deleted_at IS NULL';
        }

        if ($status === 'published') {
            $where[] = 'p.is_published = 1';
            $where[] = 'p.published_at <= NOW()';
        } elseif ($status === 'draft') {
            $where[] = 'p.is_published = 0';
        } elseif ($status === 'scheduled') {
            $where[] = 'p.is_published = 1';
            $where[] = 'p.published_at > NOW()';
        }

        try {
            $stmt = $db->query(
                'SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.reading_minutes, p.published_at, p.is_published,
                        p.template_id, p.author_id, p.featured_image_url, p.deleted_at,
                        p.template_id AS blog_template_id,
                        COALESCE(a.name, "") AS author_name,
                        COALESCE(t.name, "") AS template_name
                 FROM blog_posts p
                 LEFT JOIN blog_authors a ON a.id = p.author_id
                 LEFT JOIN blog_templates t ON t.id = p.template_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY p.published_at DESC, p.id DESC'
            );
            $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function loadBlogPostsAdminPaginated(
        PDO $db,
        string $status = 'all',
        string $search = '',
        int $page = 1,
        int $perPage = 25,
        bool $onlyDeleted = false
    ): array {
        $page = $this->normalizeAdminPage($page);
        $perPage = $this->normalizeAdminPerPage($perPage);
        $where = [];
        $params = [];

        if ($onlyDeleted) {
            $where[] = 'p.deleted_at IS NOT NULL';
        } else {
            $where[] = 'p.deleted_at IS NULL';
        }
        if ($status === 'published') {
            $where[] = 'p.is_published = 1';
            $where[] = 'p.published_at <= NOW()';
        } elseif ($status === 'draft') {
            $where[] = 'p.is_published = 0';
        } elseif ($status === 'scheduled') {
            $where[] = 'p.is_published = 1';
            $where[] = 'p.published_at > NOW()';
        }
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(p.title LIKE :term OR p.slug LIKE :term OR p.excerpt LIKE :term OR COALESCE(a.name, "") LIKE :term)';
            $params['term'] = '%' . $search . '%';
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $db->prepare(
            'SELECT COUNT(*)
             FROM blog_posts p
             LEFT JOIN blog_authors a ON a.id = p.author_id
             WHERE ' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);
        $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare(
            'SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.reading_minutes, p.published_at, p.is_published,
                    p.template_id, p.author_id, p.featured_image_url, p.deleted_at, p.category, p.category_id,
                    p.template_id AS blog_template_id,
                    COALESCE(a.name, "") AS author_name,
                    COALESCE(t.name, "") AS template_name,
                    COALESCE((
                        SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order, c.name SEPARATOR ", ")
                        FROM blog_post_categories pc
                        JOIN blog_categories c ON c.id = pc.category_id
                        WHERE pc.post_id = p.id
                    ), p.category, "") AS categories_label
             FROM blog_posts p
             LEFT JOIN blog_authors a ON a.id = p.author_id
             LEFT JOIN blog_templates t ON t.id = p.template_id
             WHERE ' . $whereSql . '
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll() ?: [];

        return [
            'items' => is_array($items) ? $items : [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    private function loadBlogPostById(PDO $db, int $id, bool $includeDeleted = false): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $where = $includeDeleted ? 'p.id = :id' : 'p.id = :id AND p.deleted_at IS NULL';
        try {
            $stmt = $db->prepare(
                'SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.reading_minutes, p.published_at, p.is_published,
                        p.template_id, p.author_id, p.featured_image_url, p.deleted_at,
                        p.category, p.category_id, p.event_start_date, p.event_end_date, p.event_price, p.event_ticket_url, p.event_location, p.video_url,
                        p.template_id AS blog_template_id
                 FROM blog_posts p
                 WHERE ' . $where . '
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch() ?: null;
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function blogTemplateDefaultHtml(): string
    {
        return <<<HTML
<article class="blog-post-template">
    <figure class="blog-post-template__cover">
        <img src="{{blog_image_url}}" alt="{{blog_title}}">
    </figure>
    <header class="blog-post-template__head">
        <h1>{{blog_title}}</h1>
        <div class="blog-post-template__meta">
            <span>{{blog_author_name}}</span>
            <span>•</span>
            <span>{{blog_published_date}}</span>
            <span>•</span>
            <span>{{blog_reading_label}}</span>
        </div>
    </header>
    <section class="blog-post-template__content">
        {{blog_content_html}}
    </section>
</article>
HTML;
    }

    private function normalizeBlogDate(string $value, ?int $timezoneOffsetMinutes = null): string
    {
        $value = trim($value);
        if ($value === '') {
            return date('Y-m-d H:i:s');
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}\-\d{2}\-\d{2}\s+\d{2}\:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return date('Y-m-d H:i:s');
        }
        if ($timezoneOffsetMinutes !== null && $timezoneOffsetMinutes >= -840 && $timezoneOffsetMinutes <= 840) {
            // datetime-local is browser local time (no timezone). Shift with client offset to UTC-like server storage.
            $timestamp += ($timezoneOffsetMinutes * 60);
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeBlogReadingMinutes(string $value): int
    {
        $minutes = (int) trim($value);
        if ($minutes <= 0) {
            $minutes = 1;
        }
        if ($minutes > 600) {
            $minutes = 600;
        }
        return $minutes;
    }

    private function nextAvailableBlogSlug(PDO $db, string $seed): string
    {
        $base = $this->slugify($seed);
        if ($base === '') {
            $base = 'postare-blog';
        }

        $candidate = $base;
        $index = 2;
        while ($index <= 300) {
            $stmt = $db->prepare('SELECT id FROM blog_posts WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $candidate]);
            $exists = $stmt->fetch() ?: null;
            if (!is_array($exists)) {
                return $candidate;
            }
            $candidate = $base . '-' . $index;
            $index++;
        }

        return $base . '-' . time();
    }

    private function saveProductExtraFieldValues(PDO $db, int $productId, array $extraValues): void
    {
        if ($productId <= 0) {
            return;
        }

        $fields = $this->loadProductExtraFields($db);
        $byId = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fieldId = (int) ($field['id'] ?? 0);
            if ($fieldId <= 0) {
                continue;
            }
            $byId[$fieldId] = $field;
        }
        if ($byId === []) {
            return;
        }

        $deleteStmt = $db->prepare('DELETE FROM product_extra_field_values WHERE product_id = :product_id AND field_id = :field_id');
        try {
            $upsertStmt = $db->prepare(
                'INSERT INTO product_extra_field_values (product_id, field_id, `value`)
                 VALUES (:product_id, :field_id, :value)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            );
        } catch (Throwable) {
            // Legacy fallback for old draft schema that used value_text.
            $upsertStmt = $db->prepare(
                'INSERT INTO product_extra_field_values (product_id, field_id, value_text)
                 VALUES (:product_id, :field_id, :value)
                 ON DUPLICATE KEY UPDATE value_text = VALUES(value_text)'
            );
        }

        foreach ($byId as $fieldId => $field) {
            $raw = $extraValues[(string) $fieldId] ?? $extraValues[$fieldId] ?? null;
            $value = trim((string) ($raw ?? ''));
            if ($value === '') {
                $deleteStmt->execute([
                    'product_id' => $productId,
                    'field_id' => $fieldId,
                ]);
                continue;
            }
            $upsertStmt->execute([
                'product_id' => $productId,
                'field_id' => $fieldId,
                'value' => $value,
            ]);
        }
    }

    private function loadOrderForFan(PDO $db, int $orderId): ?array
    {
        // `paid_amount` e adăugată de sincronizarea cu ERP-ul; fără apelul ăsta,
        // interogarea de mai jos ar pica pe o instanță care n-a rulat-o încă.
        \App\Support\ErpSync::ensureSchema($db);
        // Coloanele de locker vin din migrarea de livrare, nu din schema de bază.
        \App\Support\CheckoutCalculator::ensureOrderShippingSchema($db);
        $this->ensureOptionalSchema($db);
        $stmt = $db->prepare(
            // `payment_status` și `paid_amount` decid rambursul: o comandă cu
            // plata la livrare, achitată între timp, nu mai are ce încasa.
            //
            // Câmpurile de locker sunt citite de payload ca să trimită coletul
            // la punctul FANbox ales. Lipsind din interogare, livrarea în locker
            // ieșea tăcut ca livrare la adresa de acasă a clientului.
            'SELECT id, order_number, status, payment_method, payment_status, paid_amount, total,
                    billing_first_name, billing_last_name, billing_phone, billing_email,
                    billing_address_line1, billing_city, billing_county, billing_postcode,
                    shipping_same_as_billing, shipping_first_name, shipping_last_name, shipping_phone,
                    shipping_address_line1, shipping_city, shipping_county, shipping_postcode,
                    fan_locker_id, fan_locker_name, fan_locker_address, fan_locker_city,
                    fan_locker_county, fan_locker_postcode,
                    fan_awb, fan_awb_inlocuite
             FROM orders
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch() ?: null;
        if (!is_array($order)) {
            return null;
        }

        $itemsStmt = $db->prepare(
            'SELECT oi.product_name, oi.quantity, p.weight_grams
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC'
        );
        $itemsStmt->execute(['order_id' => $orderId]);
        $order['items'] = $itemsStmt->fetchAll();

        return $order;
    }

    /**
     * Payload-ul FAN pentru un AWB. `$override` e folosit la comenzile care
     * pleacă în mai multe colete (câte unul per depozit):
     *   - items: doar produsele coletului (greutatea se calculează din ele);
     *   - cod: rambursul coletului (0 la toate în afară de primul);
     *   - declared_value: valoarea produselor din colet;
     *   - content_suffix: " — colet 1/2 (Depozit X)" pe conținut/observații;
     *   - sender_gestiune_id: depozitul de ridicare (adresa lui din
     *     Setări livrare), în locul căutării după județul de livrare.
     */
    /**
     * Cât mai are de încasat curierul: totalul minus ce s-a plătit deja.
     *
     * `paid_amount` se scrie la confirmarea plății și rămâne acolo; pentru
     * comenzile plătite înainte de introducerea coloanei cădem pe total, ca la
     * trimiterea în ERP.
     */
    private function restDeIncasat(array $order): float
    {
        $total = round((float) ($order['total'] ?? 0), 2);
        if (strtolower(trim((string) ($order['payment_status'] ?? ''))) !== 'paid') {
            return $total;
        }
        $incasat = $order['paid_amount'] ?? null;
        $incasat = ($incasat === null || $incasat === '')
            ? $total
            : round((float) $incasat, 2);
        return max(0.0, round($total - $incasat, 2));
    }

    private function buildFanShipmentPayload(array $order, array $settings, int $clientId, array $override = []): array
    {
        $service = trim((string) ($settings['fan_service_type'] ?? 'Standard'));
        if ($service === '') {
            $service = 'Standard';
        }
        $shippingPayer = trim((string) ($settings['fan_shipping_payer'] ?? 'recipient'));
        if (!in_array($shippingPayer, ['recipient', 'sender'], true)) {
            $shippingPayer = 'recipient';
        }
        $shipmentType = trim((string) ($settings['fan_shipment_type'] ?? 'parcel'));
        if (!in_array($shipmentType, ['parcel', 'envelope'], true)) {
            $shipmentType = 'parcel';
        }
        $parcelCount = $shipmentType === 'parcel' ? max(1, (int) ($settings['fan_parcel_count'] ?? 1)) : 0;
        $envelopeCount = $shipmentType === 'envelope' ? max(1, (int) ($settings['fan_envelope_count'] ?? 1)) : 0;

        $defaultWeight = (float) ($settings['fan_default_weight_kg'] ?? 1);
        if ($defaultWeight <= 0) {
            $defaultWeight = 1;
        }
        $weightItems = isset($override['items']) && is_array($override['items'])
            ? $override['items']
            : (array) ($order['items'] ?? []);
        $weight = $this->fanOrderWeightKg($weightItems, $defaultWeight);
        $dimensions = $this->fanDimensionsFromSettings($settings);

        // AWB-ul se emite către adresa de LIVRARE. Dacă livrarea diferă de facturare
        // și are o adresă completată, folosim câmpurile de livrare; altfel cădem pe facturare.
        $sameAsBilling = (int) ($order['shipping_same_as_billing'] ?? 1) === 1;
        $shipStreet = trim((string) ($order['shipping_address_line1'] ?? ''));
        $useShipping = !$sameAsBilling && $shipStreet !== '';

        $shipFirst = trim((string) ($order['shipping_first_name'] ?? ''));
        $shipLast = trim((string) ($order['shipping_last_name'] ?? ''));
        if ($useShipping) {
            $recipientName = trim($shipFirst . ' ' . $shipLast);
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
        // Livrare la FANbox: destinatarul rămâne clientul (nume, telefon — el
        // ridică coletul), dar adresa devine a lockerului ales. Opțiunea „V"
        // spune curierului că destinația e un punct FANbox.
        $lockerAddress = trim((string) ($order['fan_locker_address'] ?? ''));
        $laFanbox = $lockerAddress !== '' && (int) ($order['fan_locker_id'] ?? 0) > 0;
        if ($laFanbox) {
            $recipientCounty = trim((string) ($order['fan_locker_county'] ?? '')) ?: $recipientCounty;
            $recipientLocality = trim((string) ($order['fan_locker_city'] ?? '')) ?: $recipientLocality;
            $recipientStreet = $lockerAddress;
            $recipientZip = trim((string) ($order['fan_locker_postcode'] ?? '')) ?: $recipientZip;
        }

        // FAN nu acceptă opțiunea de FANbox pe orice serviciu: livrarea în locker
        // are propriul tip de serviciu. Se schimbă doar pentru aceste comenzi,
        // ca livrările obișnuite să rămână pe serviciul normal.
        $codLocker = '';
        if ($laFanbox) {
            $serviciuFanbox = trim((string) ($settings['fan_service_type_fanbox'] ?? 'FANbox'));
            if ($serviciuFanbox !== '') {
                $service = $serviciuFanbox;
            }

            // FAN identifică punctul de ridicare după ID-ul LUI, din nomenclatorul
            // lui (`/reports/pickup-points`) — nu după adresă și nu după un cod
            // pe care îl inventăm noi la importul dintr-un fișier. Orice altceva
            // e respins cu „fanBoxIsInvalid" pe `recipient.address.pickupLocation`.
            $dbLocker = $this->db();
            if ($dbLocker instanceof PDO) {
                $punct = \App\Support\FanLockers::dupaId(
                    $dbLocker,
                    (int) ($order['fan_locker_id'] ?? 0)
                );
                $codLocker = trim((string) ($punct['fan_id'] ?? ''));
                if ($codLocker === '') {
                    // Rândul la care trimite comanda poate fi dezactivat după o
                    // sincronizare, ori poate fi un import vechi fără id FAN.
                    // Punctul e însă salvat pe comandă, deci îl regăsim după nume.
                    $altul = \App\Support\FanLockers::potrivestePunct(
                        $dbLocker,
                        (string) ($order['fan_locker_name'] ?? ''),
                        (string) ($order['fan_locker_city'] ?? ''),
                        (string) ($order['fan_locker_county'] ?? '')
                    );
                    $codLocker = trim((string) ($altul['fan_id'] ?? ''));
                }
            }
            if ($codLocker === '') {
                // Mai bine oprim aici decât să trimitem un colet fără punct:
                // FAN l-ar refuza oricum, iar mesajul lui nu spune ce e de făcut.
                throw new RuntimeException(
                    'Punctul FANbox al comenzii („' . (string) ($order['fan_locker_name'] ?? '')
                    . '") nu are id-ul de la FAN în nomenclator. Intră în Setări livrare și apasă '
                    . '„Sincronizează punctele FANbox din FAN", apoi reîncearcă. Dacă nici așa nu apare, '
                    . 'schimbă destinația comenzii pe livrare la adresă.'
                );
            }
        }

        // Emailul de notificare rămâne cel al clientului (nu există câmp separat la livrare).
        $recipientEmail = trim((string) ($order['billing_email'] ?? ''));

        // Rambursul e ce a MAI RĂMAS de încasat, nu totalul comenzii. O comandă
        // cu plata la livrare poate fi achitată între timp printr-un link de
        // plată — care lasă metoda „ramburs", dar marchează comanda plătită.
        // Cu totalul pus orbește, curierul ar fi încasat banii a doua oară.
        $cod = ((string) ($order['payment_method'] ?? '') === 'cod')
            ? $this->restDeIncasat($order)
            : 0.0;
        if (array_key_exists('cod', $override) && $override['cod'] !== null) {
            $cod = (float) $override['cod'];
        }
        // Coletul cu ramburs merge pe serviciul de „Cont Colector": doar pe el
        // FAN virează încasarea în contul din setări. Pe serviciul obișnuit
        // AWB-ul se emite fără să crâcnească, dar banii rămân fără drum spre
        // bancă — de aceea alegerea nu poate fi o singură setare pentru toate
        // coletele, ci depinde de cum plătește clientul.
        if ($cod > 0) {
            $cheieServiciu = $laFanbox ? 'fan_service_type_fanbox_ramburs' : 'fan_service_type_ramburs';
            $serviciuRamburs = trim((string) ($settings[$cheieServiciu] ?? ''));
            if ($serviciuRamburs !== '') {
                $service = $serviciuRamburs;
            }
        }

        $declaredValue = round((float) ($order['total'] ?? 0), 2);
        if (isset($override['declared_value']) && (float) $override['declared_value'] > 0) {
            $declaredValue = round((float) $override['declared_value'], 2);
        }
        $contentSuffix = trim((string) ($override['content_suffix'] ?? ''));
        $contentLabel = 'Comanda ' . (string) ($order['order_number'] ?? '') . ($contentSuffix !== '' ? ' ' . $contentSuffix : '');

        // Contul în care FAN virează rambursul. Trimis gol, FAN alege singur cum
        // întoarce banii și îi poate aduce cash, prin curier — de aceea se
        // completează explicit din Setări livrare.
        $codBank = trim((string) ($settings['fan_cod_bank'] ?? ''));
        $codIban = strtoupper(preg_replace('/\s+/', '', trim((string) ($settings['fan_cod_iban'] ?? ''))) ?? '');

        $shipment = [
            'info' => [
                'service' => $service,
                'bank' => $cod > 0 ? $codBank : '',
                'bankAccount' => $cod > 0 ? $codIban : '',
                'packages' => [
                    'parcel' => $parcelCount,
                    'envelope' => $envelopeCount,
                ],
                'weight' => $weight,
                'cod' => $cod > 0 ? round($cod, 2) : 0,
                'declaredValue' => $declaredValue,
                'payment' => $shippingPayer,
                'refund' => null,
                'returnPayment' => $cod > 0
                    ? (in_array(trim((string) ($settings['fan_cod_payer'] ?? 'sender')), ['sender', 'recipient', 'third_party'], true)
                        ? trim((string) ($settings['fan_cod_payer'] ?? 'sender'))
                        : 'sender')
                    : null,
                'observation' => $contentLabel,
                'content' => $contentLabel,
                'costCenter' => null,
                'options' => $this->fanOptionCodes($settings, $laFanbox),
            ],
            'recipient' => [
                'name' => $recipientName !== '' ? $recipientName : 'Client',
                'phone' => $recipientPhone,
                'email' => $recipientEmail,
                'address' => [
                    'county' => $recipientCounty,
                    'locality' => $recipientLocality,
                    'street' => $recipientStreet,
                    // Adresa completă (inclusiv numărul) e deja în `street`; nu adăugăm un
                    // număr fictiv, altfel FAN tipărește un „Nr. 1" greșit pe AWB.
                    'streetNo' => '',
                    'zipCode' => $recipientZip,
                    // Punctul FANbox se trimite DOAR pe `pickupLocationId`.
                    // Trimis și pe `pickupLocation`, FAN vede două destinații
                    // pentru același colet și respinge cererea cu
                    // „locationNotAllowedWithLocationId" — chiar dacă valoarea
                    // e aceeași pe ambele chei.
                ] + ($codLocker !== '' ? ['pickupLocationId' => $codLocker] : []),
            ],
        ];
        if (($dimensions['length'] ?? 0) > 0 && ($dimensions['width'] ?? 0) > 0 && ($dimensions['height'] ?? 0) > 0) {
            $shipment['info']['dimensions'] = [
                'length' => (float) $dimensions['length'],
                'width' => (float) $dimensions['width'],
                'height' => (float) $dimensions['height'],
            ];
        }

        // Gestiuni pe județe: AWB-ul se ridică de la depozitul care deservește
        // județul de livrare al comenzii (adresa din Setări livrare, per depozit).
        // La comenzile în mai multe colete, depozitul vine explicit din ERP.
        $senderGestiuneId = trim((string) ($override['sender_gestiune_id'] ?? ''));
        if ($senderGestiuneId !== '') {
            $senderOverride = \App\Support\ErpShipping::senderForGestiune($settings, $senderGestiuneId);
        } else {
            $awbCounty = ((int) ($order['shipping_same_as_billing'] ?? 1)) === 1
                ? trim((string) ($order['billing_county'] ?? ''))
                : trim((string) ($order['shipping_county'] ?? ''));
            $awbDb = $this->db();
            $senderOverride = \App\Support\ErpShipping::senderForCounty(
                $awbDb instanceof PDO ? $awbDb : null,
                $settings,
                $awbCounty
            );
        }
        if ($senderOverride !== null) {
            $settings = array_merge($settings, $senderOverride);
        }

        $senderName = trim((string) ($settings['fan_sender_name'] ?? ''));
        $senderPhone = trim((string) ($settings['fan_sender_phone'] ?? ''));
        $senderEmail = trim((string) ($settings['fan_sender_email'] ?? ''));
        $senderCounty = trim((string) ($settings['fan_sender_county'] ?? ''));
        $senderLocality = trim((string) ($settings['fan_sender_locality'] ?? ''));
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

    private function fanOrderWeightKg(array $items, float $defaultWeight): float
    {
        $grams = 0;
        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $weight = max(0, (int) ($item['weight_grams'] ?? 0));
            $grams += ($quantity * $weight);
        }

        return $grams > 0 ? max(0.1, $grams / 1000) : $defaultWeight;
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

    /**
     * Opțiunile FAN pentru un AWB. La livrarea în FANbox se adaugă „V", chiar
     * dacă magazinul n-a bifat-o în setări: fără ea coletul ar pleca spre
     * adresa lockerului ca spre o adresă obișnuită.
     */
    private function fanOptionCodes(array $settings, bool $laFanbox): array
    {
        $codes = $this->fanOptionCodesFromSettings($settings);
        if ($laFanbox && !in_array('V', $codes, true)) {
            $codes[] = 'V';
        }
        return $codes;
    }

    private function fanOptionCodesFromSettings(array $settings): array
    {
        $raw = trim((string) ($settings['fan_option_codes'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $upper = function_exists('mb_strtoupper')
            ? (string) mb_strtoupper($raw)
            : strtoupper($raw);
        $pieces = preg_split('/[\s,;]+/', $upper) ?: [];
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

    private function parseFanDate(string $fanDate): ?string
    {
        $fanDate = trim($fanDate);
        if ($fanDate === '') {
            return null;
        }

        $timestamp = strtotime($fanDate);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function availableMenuPages(PDO $db): array
    {
        $items = [
            ['title' => 'Acasă', 'url' => '/', 'source' => 'sistem'],
            ['title' => 'Magazin', 'url' => '/magazin', 'source' => 'sistem'],
            ['title' => 'Contact', 'url' => '/contact', 'source' => 'sistem'],
            ['title' => 'Contul meu', 'url' => '/contul-meu', 'source' => 'sistem'],
        ];

        $rows = $db->query(
            'SELECT title, slug
             FROM pages
             WHERE is_published = 1 AND deleted_at IS NULL
             ORDER BY id DESC
             LIMIT 500'
        )->fetchAll();

        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $url = '/' . ltrim($slug, '/');
            $exists = false;
            foreach ($items as $item) {
                if ((string) ($item['url'] ?? '') === $url) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) {
                continue;
            }
            $items[] = [
                'title' => $title !== '' ? $title : $slug,
                'url' => $url,
                'source' => 'pagină',
            ];
        }

        return $items;
    }

    private function menuItemsFromSettings(array $settings, array $availablePages): array
    {
        $json = trim((string) ($settings['design_menu_items_json'] ?? ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $this->normalizeMenuItems($decoded);
            }
        }

        $fromHtml = $this->menuItemsFromHtml((string) ($settings['design_menu_html'] ?? ''));
        if ($fromHtml !== []) {
            return $this->normalizeMenuItems($fromHtml);
        }

        $defaults = [];
        foreach ($availablePages as $page) {
            $url = trim((string) ($page['url'] ?? ''));
            if (!in_array($url, ['/', '/magazin', '/contact'], true)) {
                continue;
            }
            $defaults[] = [
                'label' => (string) ($page['title'] ?? $url),
                'url' => $url,
                'level' => 0,
            ];
        }

        return $this->normalizeMenuItems($defaults);
    }

    private function menuItemsFromHtml(string $html): array
    {
        $items = [];
        if (trim($html) === '') {
            return $items;
        }

        if (preg_match_all('/<a[^>]*href\s*=\s*"([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = trim((string) ($match[1] ?? ''));
                $label = trim(strip_tags((string) ($match[2] ?? '')));
                if ($url === '' || $label === '') {
                    continue;
                }
                $items[] = [
                    'label' => $label,
                    'url' => $url,
                    'level' => 0,
                ];
            }
        }

        return $items;
    }

    private function normalizeMenuItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            $level = (int) ($item['level'] ?? 0);
            if ($level < 0) {
                $level = 0;
            }
            if ($level > 1) {
                $level = 1;
            }
            if ($level === 1) {
                $prev = $normalized[count($normalized) - 1] ?? null;
                if (!is_array($prev) || (int) ($prev['level'] ?? 0) !== 0) {
                    $level = 0;
                }
            }
            $normalized[] = [
                'label' => $label,
                'url' => $url,
                'level' => $level,
            ];
        }

        return $normalized;
    }

    private function menuHtmlFromItems(array $items): string
    {
        $safe = $this->normalizeMenuItems($items);
        if ($safe === []) {
            return '';
        }

        $html = '<ul class="menu-root">';
        $subOpen = false;
        $total = count($safe);

        for ($i = 0; $i < $total; $i++) {
            $item = $safe[$i];
            $nextLevel = $i < ($total - 1) ? (int) ($safe[$i + 1]['level'] ?? 0) : 0;
            $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES);
            $url = htmlspecialchars((string) ($item['url'] ?? '#'), ENT_QUOTES);
            $level = (int) ($item['level'] ?? 0);

            if ($level === 0) {
                if ($subOpen) {
                    $html .= '</ul></li>';
                    $subOpen = false;
                }
                $html .= '<li><a href="' . $url . '">' . $label . '</a>';
                if ($nextLevel === 1) {
                    $html .= '<ul class="submenu">';
                    $subOpen = true;
                } else {
                    $html .= '</li>';
                }
            } else {
                if (!$subOpen) {
                    continue;
                }
                $html .= '<li><a href="' . $url . '">' . $label . '</a></li>';
                if ($nextLevel === 0) {
                    $html .= '</ul></li>';
                    $subOpen = false;
                }
            }
        }

        if ($subOpen) {
            $html .= '</ul></li>';
        }
        $html .= '</ul>';

        return $html;
    }

    private function loadUsersWithStatsPaginated(
        PDO $db,
        string $search = '',
        string $sort = 'id',
        string $dir = 'desc',
        int $page = 1,
        int $perPage = 25
    ): array {
        $page = $this->normalizeAdminPage($page);
        $perPage = $this->normalizeAdminPerPage($perPage);
        $offset = ($page - 1) * $perPage;

        $where = '';
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $where = ' WHERE (
                u.first_name LIKE :term
                OR u.last_name LIKE :term
                OR u.email LIKE :term
                OR CONCAT(u.first_name, " ", u.last_name) LIKE :term
            )';
            $params['term'] = '%' . $search . '%';
        }

        $countStmt = $db->prepare('SELECT COUNT(*) FROM users u' . $where);
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);
        $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sortColumn = match ($this->usersListSortKey($sort)) {
            'points' => 'u.loyalty_points',
            'orders' => 'orders_count',
            'total' => 'total_spent',
            default => 'u.id',
        };
        $direction = strtoupper($this->usersListSortDir($dir)) === 'ASC' ? 'ASC' : 'DESC';
        $sql = 'SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.birth_date, u.gender, u.loyalty_points, u.created_at,
                       COUNT(o.id) AS orders_count,
                       COALESCE(SUM(o.total), 0) AS total_spent,
                       MAX(o.created_at) AS last_order_at
                FROM users u
                LEFT JOIN orders o
                    ON (o.user_id = u.id OR (o.user_id IS NULL AND o.billing_email = u.email))
                   AND o.deleted_at IS NULL'
            . $where
            . ' GROUP BY u.id
                ORDER BY ' . $sortColumn . ' ' . $direction . ', u.id DESC
                LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll() ?: [];

        return [
            'items' => is_array($items) ? $items : [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    private function loadUsersWithStats(PDO $db, string $search = '', string $sort = 'id', string $dir = 'desc'): array
    {
        $sql = 'SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.birth_date, u.gender, u.loyalty_points, u.created_at,
                       COUNT(o.id) AS orders_count,
                       COALESCE(SUM(o.total), 0) AS total_spent,
                       MAX(o.created_at) AS last_order_at
                FROM users u
                LEFT JOIN orders o
                    ON (o.user_id = u.id OR (o.user_id IS NULL AND o.billing_email = u.email))
                   AND o.deleted_at IS NULL';

        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $sql .= ' WHERE (
                u.first_name LIKE :term
                OR u.last_name LIKE :term
                OR u.email LIKE :term
                OR CONCAT(u.first_name, " ", u.last_name) LIKE :term
            )';
            $params['term'] = '%' . $search . '%';
        }

        $sortColumn = match ($this->usersListSortKey($sort)) {
            'points' => 'u.loyalty_points',
            'orders' => 'orders_count',
            'total' => 'total_spent',
            default => 'u.id',
        };
        $direction = strtoupper($this->usersListSortDir($dir)) === 'ASC' ? 'ASC' : 'DESC';
        $sql .= ' GROUP BY u.id ORDER BY ' . $sortColumn . ' ' . $direction . ', u.id DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function usersListSortKey(string $value): string
    {
        $value = trim(strtolower($value));
        return in_array($value, ['points', 'orders', 'total', 'id'], true) ? $value : 'id';
    }

    private function usersListSortDir(string $value): string
    {
        $value = trim(strtolower($value));
        return $value === 'asc' ? 'asc' : 'desc';
    }

    private function adminUsersUrl(
        int $selectedId,
        string $search,
        string $sort,
        string $dir,
        int $page = 1,
        int $perPage = 25,
        string $panel = 'list'
    ): string
    {
        $query = [
            'sort' => $this->usersListSortKey($sort),
            'dir' => $this->usersListSortDir($dir),
            'page' => $this->normalizeAdminPage($page),
            'per_page' => $this->normalizeAdminPerPage($perPage),
            'panel' => $this->normalizeAdminListPanel($panel),
        ];
        $search = trim($search);
        if ($search !== '') {
            $query['q'] = $search;
        }
        if ($selectedId > 0) {
            $query['user'] = $selectedId;
        }

        return '/admin/users' . ($query !== [] ? ('?' . http_build_query($query)) : '');
    }

    private function adminPerPageOptions(): array
    {
        return [10, 25, 50, 100, 200, 500];
    }

    private function normalizeAdminPerPage(int $value): int
    {
        return in_array($value, $this->adminPerPageOptions(), true) ? $value : 25;
    }

    private function normalizeAdminPage(int $value): int
    {
        return max(1, $value);
    }

    private function normalizeAdminListPanel(string $value): string
    {
        $value = trim($value);
        return in_array($value, ['list', 'import'], true) ? $value : 'list';
    }

    private function normalizeShippingSettingsTab(string $value): string
    {
        $value = trim($value);
        if ($value === 'store-rules') {
            // Backward compatibility for older bookmarked tab.
            $value = 'delivery-settings';
        }

        return in_array($value, ['fan-localities', 'fan-streets', 'fan-extra-km', 'fan-api', 'delivery-settings'], true)
            ? $value
            : 'fan-localities';
    }

    private function paginationWindow(int $page, int $totalPages, int $radius = 2): array
    {
        $page = max(1, $page);
        $totalPages = max(1, $totalPages);
        $radius = max(1, $radius);
        $start = max(1, $page - $radius);
        $end = min($totalPages, $page + $radius);
        $window = [];
        for ($i = $start; $i <= $end; $i++) {
            $window[] = $i;
        }

        return $window;
    }

    private function loadClaimedLoyaltyAccounts(PDO $db, string $search = ''): array
    {
        $where = 'u.loyalty_points > 0';
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND (
                u.email LIKE :term
                OR u.first_name LIKE :term
                OR u.last_name LIKE :term
                OR CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, "")) LIKE :term
            )';
            $params['term'] = '%' . $search . '%';
        }

        $stmt = $db->prepare(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.loyalty_points,
                    COUNT(o.id) AS orders_count,
                    COALESCE(SUM(o.total), 0) AS total_spent,
                    MAX(o.created_at) AS last_order_at
             FROM users u
             LEFT JOIN orders o
                ON (o.user_id = u.id OR (o.user_id IS NULL AND o.billing_email = u.email))
               AND o.deleted_at IS NULL
             WHERE ' . $where . '
             GROUP BY u.id
             ORDER BY u.loyalty_points DESC, u.id DESC
             LIMIT 1000'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    private function loadUnclaimedLoyaltyEmailPoints(PDO $db, string $search = '', float $earnRate = 1.0): array
    {
        $earnRate = max(0.0, min(1000.0, $earnRate));
        $search = trim($search);
        $hasUserId = $this->tableHasColumn($db, 'orders', 'user_id');
        $hasBillingEmail = $this->tableHasColumn($db, 'orders', 'billing_email');
        if (!$hasUserId || !$hasBillingEmail) {
            return [];
        }

        try {
            $hasDeletedAt = $this->tableHasColumn($db, 'orders', 'deleted_at');
            $hasStatus = $this->tableHasColumn($db, 'orders', 'status');
            $hasSubtotal = $this->tableHasColumn($db, 'orders', 'subtotal');
            $hasTotal = $this->tableHasColumn($db, 'orders', 'total');
            $hasDiscountTotal = $this->tableHasColumn($db, 'orders', 'discount_total');
            $hasPointsDiscount = $this->tableHasColumn($db, 'orders', 'loyalty_points_discount');
            $hasPendingClaim = $this->tableHasColumn($db, 'orders', 'loyalty_points_pending_claim');
            $hasCreatedAt = $this->tableHasColumn($db, 'orders', 'created_at');

            $whereParts = [];
            if ($hasDeletedAt) {
                $whereParts[] = 'o.deleted_at IS NULL';
            }
            $whereParts[] = 'o.user_id IS NULL';
            $whereParts[] = 'o.billing_email <> ""';
            $where = implode(' AND ', $whereParts);
            $params = [];
            if ($search !== '') {
                $where .= ' AND o.billing_email LIKE :term';
                $params['term'] = '%' . $search . '%';
            }

            $earnRateSql = number_format($earnRate, 6, '.', '');
            $baseSubtotalExpr = $hasSubtotal
                ? (
                    $hasTotal
                        ? 'COALESCE(o.subtotal, COALESCE(o.total, 0))'
                        : 'COALESCE(o.subtotal, 0)'
                )
                : ($hasTotal ? 'COALESCE(o.total, 0)' : '0');
            $baseDiscountExpr = $hasDiscountTotal ? 'COALESCE(o.discount_total, 0)' : '0';
            $basePointsDiscountExpr = $hasPointsDiscount ? 'COALESCE(o.loyalty_points_discount, 0)' : '0';
            $pendingClaimExpr = $hasPendingClaim ? 'COALESCE(o.loyalty_points_pending_claim, 0)' : '0';
            $pendingEligibleStatusExpr = $hasStatus
                ? 'o.status NOT IN (\'completed\', \'cancelled\', \'refunded\', \'failed\')'
                : '0 = 1';
            $completedStatusExpr = $hasStatus ? 'o.status = \'completed\'' : '0 = 1';
            $lastOrderExpr = $hasCreatedAt ? 'MAX(o.created_at)' : "''";

            $pendingEstimateExprForPoints = 'FLOOR(
                GREATEST(
                    0,
                    ' . $baseSubtotalExpr . ' - ' . $baseDiscountExpr . ' - ' . $basePointsDiscountExpr . '
                ) * ' . $earnRateSql . '
            )';
            $pendingEstimateExprForCount = 'FLOOR(
                GREATEST(
                    0,
                    ' . $baseSubtotalExpr . ' - ' . $baseDiscountExpr . ' - ' . $basePointsDiscountExpr . '
                ) * ' . $earnRateSql . '
            )';
            $confirmedPointsCase = $hasPendingClaim
                ? ('WHEN ' . $pendingClaimExpr . ' > 0 THEN ' . $pendingClaimExpr . '
                            ELSE 0')
                : ('WHEN ' . $completedStatusExpr . ' THEN ' . $pendingEstimateExprForPoints . '
                            ELSE 0');
            $pendingPointsCase = $hasPendingClaim
                ? ('WHEN ' . $pendingClaimExpr . ' <= 0
                                 AND ' . $pendingEligibleStatusExpr . '
                            THEN ' . $pendingEstimateExprForPoints . '
                            ELSE 0')
                : ('WHEN ' . $pendingEligibleStatusExpr . '
                            THEN ' . $pendingEstimateExprForPoints . '
                            ELSE 0');
            $confirmedOrdersCase = $hasPendingClaim
                ? ('WHEN ' . $pendingClaimExpr . ' > 0 THEN 1
                            ELSE 0')
                : ('WHEN ' . $completedStatusExpr . '
                                 AND ' . $pendingEstimateExprForCount . ' > 0
                            THEN 1
                            ELSE 0');
            $pendingOrdersCase = $hasPendingClaim
                ? ('WHEN ' . $pendingClaimExpr . ' <= 0
                                 AND ' . $pendingEligibleStatusExpr . '
                                 AND ' . $pendingEstimateExprForCount . ' > 0
                            THEN 1
                            ELSE 0')
                : ('WHEN ' . $pendingEligibleStatusExpr . '
                                 AND ' . $pendingEstimateExprForCount . ' > 0
                            THEN 1
                            ELSE 0');

            $stmt = $db->prepare(
                'SELECT LOWER(TRIM(o.billing_email)) AS email,
                        COALESCE(SUM(CASE
                            ' . $confirmedPointsCase . '
                        END), 0) AS confirmed_points,
                        COALESCE(SUM(CASE
                            ' . $pendingPointsCase . '
                        END), 0) AS pending_points,
                        COALESCE(SUM(CASE
                            ' . $confirmedOrdersCase . '
                        END), 0) AS confirmed_orders_count,
                        COALESCE(SUM(CASE
                            ' . $pendingOrdersCase . '
                        END), 0) AS pending_orders_count,
                        ' . $lastOrderExpr . ' AS last_order_at
                 FROM orders o
                 WHERE ' . $where . '
                 GROUP BY LOWER(TRIM(o.billing_email))
                 HAVING confirmed_points > 0 OR pending_points > 0
                 ORDER BY (confirmed_points + pending_points) DESC, last_order_at DESC
                 LIMIT 2000'
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            if (!is_array($rows)) {
                return [];
            }

            $results = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $confirmedPoints = max(0, (int) ($row['confirmed_points'] ?? 0));
                $pendingPoints = max(0, (int) ($row['pending_points'] ?? 0));
                $confirmedOrdersCount = max(0, (int) ($row['confirmed_orders_count'] ?? 0));
                $pendingOrdersCount = max(0, (int) ($row['pending_orders_count'] ?? 0));
                if ($confirmedPoints <= 0 && $pendingPoints <= 0) {
                    continue;
                }
                $results[] = [
                    'email' => $email,
                    'confirmed_points' => $confirmedPoints,
                    'pending_points' => $pendingPoints,
                    'orders_count' => $confirmedOrdersCount + $pendingOrdersCount,
                    'confirmed_orders_count' => $confirmedOrdersCount,
                    'pending_orders_count' => $pendingOrdersCount,
                    'last_order_at' => (string) ($row['last_order_at'] ?? ''),
                ];
            }
            return $results;
        } catch (Throwable) {
            return $this->loadUnclaimedLoyaltyEmailPointsFallback($db, $search, $earnRate);
        }
    }

    private function loadUnclaimedLoyaltyEmailPointsSafe(PDO $db, string $search = '', float $earnRate = 1.0): array
    {
        try {
            return $this->loadUnclaimedLoyaltyEmailPoints($db, $search, $earnRate);
        } catch (Throwable) {
            try {
                return $this->loadUnclaimedLoyaltyEmailPointsFallback($db, $search, $earnRate);
            } catch (Throwable) {
                return [];
            }
        }
    }

    private function tableHasColumn(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = strtolower(trim($table)) . '.' . strtolower(trim($column));
        if (array_key_exists($key, $cache)) {
            return (bool) $cache[$key];
        }

        try {
            $stmt = $db->prepare(
                'SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name
                 LIMIT 1'
            );
            $stmt->execute([
                'table_name' => trim($table),
                'column_name' => trim($column),
            ]);
            $cache[$key] = $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $cache[$key] = false;
        }

        return (bool) $cache[$key];
    }

    private function loadUnclaimedLoyaltyEmailPointsFallback(PDO $db, string $search = '', float $earnRate = 1.0): array
    {
        $hasDeletedAt = $this->tableHasColumn($db, 'orders', 'deleted_at');
        $hasStatus = $this->tableHasColumn($db, 'orders', 'status');
        $hasSubtotal = $this->tableHasColumn($db, 'orders', 'subtotal');
        $hasTotal = $this->tableHasColumn($db, 'orders', 'total');
        $hasDiscountTotal = $this->tableHasColumn($db, 'orders', 'discount_total');
        $hasPointsDiscount = $this->tableHasColumn($db, 'orders', 'loyalty_points_discount');
        $hasPendingClaim = $this->tableHasColumn($db, 'orders', 'loyalty_points_pending_claim');
        $hasCreatedAt = $this->tableHasColumn($db, 'orders', 'created_at');
        $hasUserId = $this->tableHasColumn($db, 'orders', 'user_id');
        $hasBillingEmail = $this->tableHasColumn($db, 'orders', 'billing_email');
        if (!$hasUserId || !$hasBillingEmail) {
            return [];
        }

        $selectColumns = [
            'o.user_id',
            'o.billing_email',
            ($hasStatus ? 'o.status' : "'' AS status"),
            ($hasSubtotal ? 'o.subtotal' : 'NULL AS subtotal'),
            ($hasTotal ? 'o.total' : 'NULL AS total'),
            ($hasDiscountTotal ? 'o.discount_total' : 'NULL AS discount_total'),
            ($hasPointsDiscount ? 'o.loyalty_points_discount' : 'NULL AS loyalty_points_discount'),
            ($hasPendingClaim ? 'o.loyalty_points_pending_claim' : 'NULL AS loyalty_points_pending_claim'),
            ($hasCreatedAt ? 'o.created_at' : "'' AS created_at"),
        ];
        $where = ['o.user_id IS NULL', 'o.billing_email <> ""'];
        if ($hasDeletedAt) {
            $where[] = 'o.deleted_at IS NULL';
        }
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $where[] = 'o.billing_email LIKE :term';
            $params['term'] = '%' . $search . '%';
        }

        try {
            $stmt = $db->prepare(
                'SELECT ' . implode(', ', $selectColumns) . '
                 FROM orders o
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY o.id DESC
                 LIMIT 12000'
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $aggregated = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string) ($row['billing_email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!isset($aggregated[$email])) {
                $aggregated[$email] = [
                    'email' => $email,
                    'confirmed_points' => 0,
                    'pending_points' => 0,
                    'confirmed_orders_count' => 0,
                    'pending_orders_count' => 0,
                    'last_order_at' => '',
                ];
            }

            $baseAmount = max(
                0.0,
                (float) ($row['subtotal'] ?? $row['total'] ?? 0.0)
                    - (float) ($row['discount_total'] ?? 0.0)
                    - (float) ($row['loyalty_points_discount'] ?? 0.0)
            );
            $estimatedPoints = max(0, (int) floor($baseAmount * $earnRate));
            $pendingClaim = max(0, (int) ($row['loyalty_points_pending_claim'] ?? 0));
            $status = trim((string) ($row['status'] ?? ''));
            $isCompleted = $hasStatus && $status === 'completed';
            $isPendingStatus = $hasStatus && !in_array($status, ['completed', 'cancelled', 'refunded', 'failed'], true);

            if ($hasPendingClaim) {
                if ($pendingClaim > 0) {
                    $aggregated[$email]['confirmed_points'] += $pendingClaim;
                    $aggregated[$email]['confirmed_orders_count']++;
                } elseif ($isPendingStatus && $estimatedPoints > 0) {
                    $aggregated[$email]['pending_points'] += $estimatedPoints;
                    $aggregated[$email]['pending_orders_count']++;
                }
            } else {
                if ($isCompleted && $estimatedPoints > 0) {
                    $aggregated[$email]['confirmed_points'] += $estimatedPoints;
                    $aggregated[$email]['confirmed_orders_count']++;
                } elseif ($isPendingStatus && $estimatedPoints > 0) {
                    $aggregated[$email]['pending_points'] += $estimatedPoints;
                    $aggregated[$email]['pending_orders_count']++;
                }
            }

            $createdAt = trim((string) ($row['created_at'] ?? ''));
            if ($createdAt !== '' && ($aggregated[$email]['last_order_at'] === '' || strcmp($createdAt, $aggregated[$email]['last_order_at']) > 0)) {
                $aggregated[$email]['last_order_at'] = $createdAt;
            }
        }

        $results = [];
        foreach ($aggregated as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $confirmedPoints = max(0, (int) ($entry['confirmed_points'] ?? 0));
            $pendingPoints = max(0, (int) ($entry['pending_points'] ?? 0));
            if ($confirmedPoints <= 0 && $pendingPoints <= 0) {
                continue;
            }
            $confirmedOrdersCount = max(0, (int) ($entry['confirmed_orders_count'] ?? 0));
            $pendingOrdersCount = max(0, (int) ($entry['pending_orders_count'] ?? 0));
            $results[] = [
                'email' => (string) ($entry['email'] ?? ''),
                'confirmed_points' => $confirmedPoints,
                'pending_points' => $pendingPoints,
                'orders_count' => $confirmedOrdersCount + $pendingOrdersCount,
                'confirmed_orders_count' => $confirmedOrdersCount,
                'pending_orders_count' => $pendingOrdersCount,
                'last_order_at' => (string) ($entry['last_order_at'] ?? ''),
            ];
        }
        usort($results, static function (array $a, array $b): int {
            $pointsA = max(0, (int) ($a['confirmed_points'] ?? 0)) + max(0, (int) ($a['pending_points'] ?? 0));
            $pointsB = max(0, (int) ($b['confirmed_points'] ?? 0)) + max(0, (int) ($b['pending_points'] ?? 0));
            if ($pointsA !== $pointsB) {
                return $pointsB <=> $pointsA;
            }
            return strcmp((string) ($b['last_order_at'] ?? ''), (string) ($a['last_order_at'] ?? ''));
        });

        return array_slice($results, 0, 2000);
    }

    private function loadUserLoyaltyHistory(PDO $db, int $userId, int $limit = 300): array
    {
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(1500, $limit));
        $stmt = $db->prepare(
            'SELECT t.id, t.user_id, t.order_id, t.admin_id, t.tx_type, t.points_delta, t.balance_after, t.reason, t.meta_json, t.created_at,
                    o.order_number,
                    a.email AS admin_email
             FROM loyalty_points_transactions t
             LEFT JOIN orders o ON o.id = t.order_id
             LEFT JOIN admins a ON a.id = t.admin_id
             WHERE t.user_id = :user_id
             ORDER BY t.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    private function normalizePositiveIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private function loadAdminUserById(PDO $db, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT id, first_name, last_name, email, phone, birth_date, gender, loyalty_points, created_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch() ?: null;
        return is_array($user) ? $user : null;
    }

    private function loadAdminUserOrders(PDO $db, int $userId, string $email): array
    {
        if ($userId <= 0 && trim($email) === '') {
            return [];
        }

        $stmt = $db->prepare(
            'SELECT id, order_number, status, payment_status, total, created_at,
                    billing_first_name, billing_last_name, billing_phone, billing_email,
                    billing_address_line1, billing_address_line2, billing_city, billing_county, billing_postcode
             FROM orders
             WHERE deleted_at IS NULL
               AND (user_id = :user_id OR (user_id IS NULL AND billing_email = :email))
             ORDER BY id DESC
             LIMIT 300'
        );
        $stmt->execute([
            'user_id' => $userId,
            'email' => $email,
        ]);
        return $stmt->fetchAll();
    }

    private function generateCustomerPages(PDO $db): array
    {
        $pages = [
            [
                'title' => 'Login',
                'slug' => 'login',
                'html' => '<h1>Login</h1><p>Autentifică-te în contul tău de aici: <a href="/login">/login</a>.</p>',
            ],
            [
                'title' => 'Register',
                'slug' => 'register',
                'html' => '<h1>Register</h1><p>Creează cont nou din pagina: <a href="/register">/register</a>.</p>',
            ],
            [
                'title' => 'Contul meu',
                'slug' => 'contul-meu',
                'html' => '<section class="account-page-token">{{account_section}}</section>',
            ],
            [
                'title' => 'Resetare parolă',
                'slug' => 'resetare-parola',
                'html' => '<h1>Resetare parolă</h1><p>Pentru resetare folosește: <a href="/contul-meu/resetare-parola">/contul-meu/resetare-parola</a>.</p>',
            ],
        ];

        return $this->generatePagesByDefinitions($db, $pages);
    }

    private function generateStorePages(PDO $db): array
    {
        $pages = [
            [
                'title' => 'Acasă',
                'slug' => '',
                'html' => '<h1>Acasă</h1><p>Pagina principală a site-ului este disponibilă la <a href="/">/</a>.</p>',
            ],
            [
                'title' => 'Magazin',
                'slug' => 'magazin',
                'html' => '<h1>Magazin</h1><p>Catalogul magazinului este disponibil la <a href="/magazin">/magazin</a>.</p>',
            ],
            [
                'title' => 'Coș',
                'slug' => 'cos',
                'html' => '<h1>Coș</h1><p>Pagina de coș este disponibilă la <a href="/cos">/cos</a>.</p>',
            ],
            [
                'title' => 'Checkout',
                'slug' => 'checkout',
                'html' => '<div class="checkout-page-dynamic">{{checkout_form}}</div>',
            ],
            [
                'title' => 'Thank you',
                'slug' => 'checkout/succes',
                'html' => '<h1>Comandă plasată cu succes</h1><p>Această pagină este folosită în fluxul de checkout și se deschide cu URL-ul dinamic <code>/checkout/succes/&lt;numar-comanda&gt;</code>.</p>',
            ],
            [
                'title' => 'Blog',
                'slug' => 'blog',
                'html' => '<h1>Blog</h1><p>{{blog_posts}}</p>',
            ],
            [
                'title' => 'Contul meu',
                'slug' => 'contul-meu',
                'html' => '<div class="account-page-dynamic">{{account_section}}</div>',
            ],
            [
                'title' => 'Contact',
                'slug' => 'contact',
                'html' => '<h1>Contact</h1><p>Completează această pagină din admin cu informațiile de contact, hartă, program și formular.</p>',
            ],
            [
                'title' => 'Acorduri GDPR',
                'slug' => 'acorduri-gdpr',
                'html' => '<section class="gdpr-agreements-page">{{gdpr_agreements_form}}</section>',
            ],
            [
                'title' => 'Pagina 404',
                'slug' => '404',
                'html' => '<section style="text-align:center;padding:40px 20px;"><h1 style="font-size:54px;line-height:1.1;margin:0 0 12px;">404</h1><p style="margin:0 0 16px;font-size:20px;">Pagina pe care o cauți nu există sau a fost mutată.</p><p style="margin:0;"><a href="/" style="display:inline-block;padding:10px 18px;border-radius:10px;background:#0f7b53;color:#ffffff;text-decoration:none;font-weight:600;">Înapoi la Acasă</a></p></section>',
            ],
        ];

        return $this->generatePagesByDefinitions($db, $pages);
    }

    private function generatePagesByDefinitions(PDO $db, array $pages): array
    {
        $created = 0;
        $restored = 0;
        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            if ($slug === '') {
                // Home page is represented by the root URL (/), stored with empty slug.
                $lookup = $db->query(
                    "SELECT id, slug, deleted_at
                     FROM pages
                     WHERE slug = '' OR LOWER(slug) IN ('acasa', 'home')
                     ORDER BY CASE WHEN slug = '' THEN 0 ELSE 1 END, id ASC
                     LIMIT 1"
                );
                $existing = $lookup ? ($lookup->fetch() ?: null) : null;
            } else {
                $lookup = $db->prepare('SELECT id, slug, deleted_at FROM pages WHERE slug = :slug LIMIT 1');
                $lookup->execute(['slug' => $slug]);
                $existing = $lookup->fetch() ?: null;
            }

            if (is_array($existing)) {
                $existingSlug = trim((string) ($existing['slug'] ?? ''));
                if (!empty($existing['deleted_at'])) {
                    $restore = $db->prepare(
                        'UPDATE pages
                         SET deleted_at = NULL, is_published = 1, slug = :slug
                         WHERE id = :id'
                    );
                    $restore->execute([
                        'id' => (int) ($existing['id'] ?? 0),
                        'slug' => $slug,
                    ]);
                    $restored++;
                } elseif ($slug === '' && $existingSlug !== '') {
                    $migrateToRoot = $db->prepare('UPDATE pages SET slug = :slug WHERE id = :id');
                    $migrateToRoot->execute([
                        'id' => (int) ($existing['id'] ?? 0),
                        'slug' => '',
                    ]);
                }
                continue;
            }

            $insert = $db->prepare(
                'INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published)
                 VALUES (:title, :slug, :html_content, :css_content, :js_content, 1)'
            );
            $insert->execute([
                'title' => (string) ($page['title'] ?? ''),
                'slug' => $slug,
                'html_content' => (string) ($page['html'] ?? ''),
                'css_content' => '',
                'js_content' => '',
            ]);
            $created++;
        }

        return [$created, $restored];
    }

    private function generateSitemap(PDO $db, string $sitemapFilename = 'sitemap.xml'): array
    {
        $sitemapFilename = $this->normalizeSitemapFilename($sitemapFilename);
        $publicPath = __DIR__ . '/../../../public/' . $sitemapFilename;
        $baseUrl = rtrim($this->appUrl(), '/');
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'URL-ul aplicației nu este configurat.'];
        }

        $urls = $this->sitemapUrls($db, $baseUrl);
        if ($urls === []) {
            return ['ok' => false, 'message' => 'Nu am găsit URL-uri publicabile pentru sitemap.'];
        }

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $loc = trim((string) ($entry['loc'] ?? ''));
            if ($loc === '') {
                continue;
            }
            $lastmod = trim((string) ($entry['lastmod'] ?? ''));
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . $this->xmlEscape($loc) . '</loc>';
            if ($lastmod !== '') {
                $xml[] = '    <lastmod>' . $this->xmlEscape($lastmod) . '</lastmod>';
            }
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';

        $written = @file_put_contents($publicPath, implode("\n", $xml) . "\n");
        if ($written === false) {
            return ['ok' => false, 'message' => 'Nu am putut scrie fișierul ' . $sitemapFilename . ' în directorul public.'];
        }

        return ['ok' => true, 'message' => 'Sitemap generat cu succes: ' . $baseUrl . '/' . $sitemapFilename];
    }

    private function normalizeSitemapFilename(string $value): string
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
        if (strlen($value) > 120) {
            $value = substr($value, 0, 120);
            if (!str_ends_with($value, '.xml')) {
                $value = rtrim(substr($value, 0, 116), '.-_') . '.xml';
            }
        }

        return $value;
    }

    private function sitemapUrls(PDO $db, string $baseUrl): array
    {
        $urls = [];
        $seen = [];
        $add = function (string $path, string $lastmod = '') use (&$urls, &$seen, $baseUrl): void {
            $path = '/' . ltrim(trim($path), '/');
            if ($path === '//') {
                $path = '/';
            }
            $loc = $path === '/' ? $baseUrl . '/' : $baseUrl . $path;
            if (isset($seen[$loc])) {
                return;
            }
            $seen[$loc] = true;
            $urls[] = [
                'loc' => $loc,
                'lastmod' => $lastmod,
            ];
        };
        $toLastmod = static function (mixed $raw): string {
            $ts = strtotime((string) $raw);
            if ($ts === false) {
                return '';
            }
            return gmdate('Y-m-d\TH:i:s\Z', $ts);
        };

        $add('/');
        try {
            $pages = $db->query(
                'SELECT slug, updated_at, created_at
                 FROM pages
                 WHERE is_published = 1
                   AND deleted_at IS NULL
                 ORDER BY id DESC'
            )->fetchAll() ?: [];
            foreach ($pages as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $slug = trim((string) ($page['slug'] ?? ''));
                $lastmod = $toLastmod((string) ($page['updated_at'] ?? $page['created_at'] ?? ''));
                $add($slug === '' ? '/' : ('/' . ltrim($slug, '/')), $lastmod);
            }
        } catch (Throwable) {
            // Keep sitemap generation resilient on partial schema issues.
        }

        try {
            $products = $db->query(
                'SELECT slug, updated_at, created_at
                 FROM products
                 WHERE deleted_at IS NULL
                   AND is_active = 1
                 ORDER BY id DESC'
            )->fetchAll() ?: [];
            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $slug = trim((string) ($product['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $lastmod = $toLastmod((string) ($product['updated_at'] ?? $product['created_at'] ?? ''));
                $add('/produs/' . rawurlencode($slug), $lastmod);
            }
        } catch (Throwable) {
        }

        try {
            $posts = $db->query(
                'SELECT slug, updated_at, created_at
                 FROM blog_posts
                 WHERE deleted_at IS NULL
                 ORDER BY id DESC'
            )->fetchAll() ?: [];
            foreach ($posts as $post) {
                if (!is_array($post)) {
                    continue;
                }
                $slug = trim((string) ($post['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $lastmod = $toLastmod((string) ($post['updated_at'] ?? $post['created_at'] ?? ''));
                $add('/blog/' . rawurlencode($slug), $lastmod);
            }
        } catch (Throwable) {
        }

        return $urls;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function countGeneralAdmins(PDO $db): int
    {
        try {
            $rows = $db->query('SELECT role, roles_json FROM admins')->fetchAll() ?: [];
            $count = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $roles = Auth::rolesFromStorage(
                    (string) ($row['role'] ?? Auth::ROLE_GENERAL),
                    $row['roles_json'] ?? null
                );
                if (in_array(Auth::ROLE_GENERAL, $roles, true)) {
                    $count++;
                }
            }
            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    private function adminRolesFromInput(array $input, string $fallbackRole = Auth::ROLE_STORE): array
    {
        $rawRoles = $input['roles'] ?? [];
        if (!is_array($rawRoles)) {
            $rawRoles = [];
        }
        $legacyRole = trim((string) ($input['role'] ?? ''));
        if ($legacyRole === '') {
            $legacyRole = $fallbackRole;
        }
        return Auth::normalizeRoles($rawRoles, $legacyRole);
    }

    /**
     * Import din exportul Mailchimp (CSV sau ZIP-ul descărcat ca atare).
     *
     * Mailchimp nu are coloană de listă, deci destinația e cea aleasă în
     * pagină. Se importă DOAR abonații activi: exportul conține și dezabonați
     * și adrese curățate, iar cine și-a retras consimțământul rămâne retras.
     * Statusul se citește din coloană dacă există, altfel din numele fișierului
     * — exportul clasic pune fiecare status în câte un CSV separat.
     */
    private function importMailchimpSubscribers(PDO $db, mixed $file, int $listId): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'Selectează fișierul exportat din Mailchimp (CSV sau ZIP).'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload eșuat. Încearcă din nou.'];
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Fișier invalid.'];
        }
        $numeFisier = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($numeFisier, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'zip'], true)) {
            return ['ok' => false, 'message' => 'Format neacceptat. Încarcă CSV-ul sau ZIP-ul descărcat din Mailchimp.'];
        }

        if ($listId <= 0) {
            $listId = NewsletterService::defaultListId($db);
        }
        if ($listId <= 0) {
            return ['ok' => false, 'message' => 'Nu există nicio listă de abonați. Creează una întâi.'];
        }

        // fișier (nume afișat) => rânduri brute
        $bucati = [];
        if ($ext === 'csv') {
            $bucati[$numeFisier] = @file_get_contents($tmpPath);
        } else {
            $zip = new \ZipArchive();
            if ($zip->open($tmpPath) !== true) {
                return ['ok' => false, 'message' => 'Arhiva ZIP nu a putut fi deschisă.'];
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $intrare = (string) $zip->getNameIndex($i);
                if (strtolower(pathinfo($intrare, PATHINFO_EXTENSION)) !== 'csv') {
                    continue;
                }
                $bucati[$intrare] = $zip->getFromIndex($i);
            }
            $zip->close();
            if ($bucati === []) {
                return ['ok' => false, 'message' => 'Arhiva nu conține niciun fișier CSV.'];
            }
        }

        $adaugati = 0;
        $existenti = 0;
        $sariti = 0;
        $invalizi = 0;
        $fisiereSarite = [];
        $vazute = [];

        $cauta = $db->prepare('SELECT 1 FROM newsletter_subscribers WHERE email = :email LIMIT 1');

        // Zeci de mii de abonați: fără tranzacție, fiecare inserare ar fi un
        // commit separat, iar cererea web ar expira la jumătatea listei.
        @set_time_limit(0);
        $inTranzactie = false;
        try {
            $db->beginTransaction();
            $inTranzactie = true;
        } catch (Throwable) {
            $inTranzactie = false;
        }

        foreach ($bucati as $nume => $continut) {
            if (!is_string($continut) || $continut === '') {
                continue;
            }
            // Exportul clasic împarte pe status: „unsubscribed_members_...csv",
            // „cleaned_members_...csv". Alea nu se ating deloc.
            $numeMic = mb_strtolower($nume);
            if (
                str_contains($numeMic, 'unsubscribed')
                || str_contains($numeMic, 'cleaned')
                || str_contains($numeMic, 'nonsubscribed')
                || str_contains($numeMic, 'non-subscribed')
                || str_contains($numeMic, 'transactional')
            ) {
                $fisiereSarite[] = basename($nume);
                continue;
            }

            foreach ($this->mailchimpRows($continut) as $rand) {
                $email = mb_strtolower(trim((string) ($rand['email'] ?? '')));
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $invalizi++;
                    continue;
                }
                // Dacă fișierul are coloană de status, ea decide.
                $status = mb_strtolower(trim((string) ($rand['status'] ?? '')));
                if ($status !== '' && $status !== 'subscribed') {
                    $sariti++;
                    continue;
                }
                if (isset($vazute[$email])) {
                    continue;
                }
                $vazute[$email] = true;

                $cauta->execute(['email' => $email]);
                if ((bool) $cauta->fetchColumn()) {
                    $existenti++;
                    continue;
                }
                try {
                    NewsletterService::subscribeToList($db, $listId, $email, trim((string) ($rand['name'] ?? '')));
                    $adaugati++;
                } catch (Throwable) {
                    $invalizi++;
                }
            }
        }

        if ($inTranzactie) {
            try {
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                return [
                    'ok' => false,
                    'message' => 'Importul a eșuat și a fost anulat complet: ' . $e->getMessage(),
                ];
            }
        }

        if ($adaugati === 0 && $existenti === 0 && $invalizi === 0 && $sariti === 0) {
            return [
                'ok' => false,
                'message' => 'Nu am găsit nicio adresă în fișier. Verifică să fie exportul din Mailchimp '
                    . '(trebuie să aibă o coloană „Email Address").',
            ];
        }

        $mesaj = 'Import Mailchimp finalizat: ' . $adaugati . ' abonați noi, '
            . $existenti . ' existau deja, ' . $sariti . ' săriți (nu erau activi), '
            . $invalizi . ' adrese invalide.';
        if ($fisiereSarite !== []) {
            $mesaj .= ' Fișiere ignorate (dezabonați / adrese curățate): ' . implode(', ', $fisiereSarite) . '.';
        }

        return ['ok' => true, 'message' => $mesaj];
    }

    /**
     * Rândurile dintr-un CSV Mailchimp, reduse la ce ne trebuie.
     *
     * @return array<int,array{email:string,name:string,status:string}>
     */
    private function mailchimpRows(string $continut): array
    {
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, $continut);
        rewind($stream);

        $antet = fgetcsv($stream, 0, ',');
        if (!is_array($antet) || $antet === []) {
            fclose($stream);
            return [];
        }
        // Mailchimp exportă cu virgulă; dacă nu, ghicim separatorul din antet.
        if (count($antet) === 1) {
            $prima = (string) ($antet[0] ?? '');
            $separator = substr_count($prima, ';') > substr_count($prima, "\t") ? ';' : "\t";
            rewind($stream);
            $antet = fgetcsv($stream, 0, $separator);
            if (!is_array($antet)) {
                fclose($stream);
                return [];
            }
        } else {
            $separator = ',';
        }

        $indice = [];
        foreach ($antet as $i => $nume) {
            $cheie = mb_strtolower(trim((string) $nume));
            if ((int) $i === 0) {
                $cheie = ltrim($cheie, "\xEF\xBB\xBF");
            }
            $indice[$cheie] = (int) $i;
        }
        $ia = static function (array $rand, array $indice, array $variante): string {
            foreach ($variante as $v) {
                if (array_key_exists($v, $indice)) {
                    return trim((string) ($rand[$indice[$v]] ?? ''));
                }
            }
            return '';
        };

        $rezultat = [];
        while (($rand = fgetcsv($stream, 0, $separator)) !== false) {
            if (!is_array($rand)) {
                continue;
            }
            $email = $ia($rand, $indice, ['email address', 'email_address', 'email']);
            if ($email === '') {
                continue;
            }
            $nume = trim(
                $ia($rand, $indice, ['first name', 'first_name', 'fname'])
                . ' '
                . $ia($rand, $indice, ['last name', 'last_name', 'lname'])
            );
            $rezultat[] = [
                'email' => $email,
                'name' => $nume,
                'status' => $ia($rand, $indice, ['subscription status', 'status', 'member_status']),
            ];
        }
        fclose($stream);

        return $rezultat;
    }

    private function importNewsletterSubscribersFromUploadedFile(PDO $db, mixed $file): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'Selectează fișierul CSV/XLSX cu abonați newsletter.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload eșuat. Încearcă din nou.'];
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::NEWSLETTER_SUBSCRIBERS_IMPORT_UPLOAD_MAX_SIZE) {
            return ['ok' => false, 'message' => 'Fișier invalid sau prea mare (max 12MB).'];
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Fișier invalid.'];
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            return ['ok' => false, 'message' => 'Format neacceptat. Folosește CSV sau XLSX.'];
        }

        $rows = $ext === 'csv'
            ? $this->newsletterSubscribersRowsFromCsv($tmpPath)
            : $this->newsletterSubscribersRowsFromXlsx($tmpPath);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Nu am găsit rânduri valide în fișier (coloane Email + Listă).'];
        }

        $listByName = [];
        $createdLists = 0;
        $imported = 0;
        $duplicates = 0;
        $invalid = 0;
        $processedPairs = 0;

        $db->beginTransaction();
        try {
            $listFind = $db->prepare('SELECT id FROM newsletter_lists WHERE LOWER(name) = :name LIMIT 1');
            $listCreate = $db->prepare('INSERT INTO newsletter_lists (name, description, is_default) VALUES (:name, :description, 0)');
            $subscriberFind = $db->prepare('SELECT id FROM newsletter_subscribers WHERE email = :email LIMIT 1');
            $membershipFind = $db->prepare(
                'SELECT 1
                 FROM newsletter_list_subscribers
                 WHERE list_id = :list_id AND subscriber_id = :subscriber_id
                 LIMIT 1'
            );
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $entries = $row['entries'] ?? [];
                if (!is_array($entries) || $entries === []) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $email = strtolower(trim((string) ($entry['email'] ?? '')));
                    $listName = trim((string) ($entry['list_name'] ?? ''));
                    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        $invalid++;
                        continue;
                    }
                    if ($listName === '') {
                        $listName = 'Toți abonații';
                    }
                    $listKey = mb_strtolower($listName);
                    $listId = (int) ($listByName[$listKey] ?? 0);
                    if ($listId <= 0) {
                        $listFind->execute(['name' => $listKey]);
                        $existing = (int) ($listFind->fetchColumn() ?: 0);
                        if ($existing > 0) {
                            $listId = $existing;
                        } else {
                            $listCreate->execute([
                                'name' => $listName,
                                'description' => 'Creat automat din import abonați',
                            ]);
                            $listId = (int) $db->lastInsertId();
                            $createdLists++;
                        }
                        $listByName[$listKey] = $listId;
                    }
                    if ($listId <= 0) {
                        $invalid++;
                        continue;
                    }

                    $subscriberFind->execute(['email' => $email]);
                    $existingSubscriberId = (int) ($subscriberFind->fetchColumn() ?: 0);
                    $alreadyInList = false;
                    if ($existingSubscriberId > 0) {
                        $membershipFind->execute([
                            'list_id' => $listId,
                            'subscriber_id' => $existingSubscriberId,
                        ]);
                        $alreadyInList = ((int) $membershipFind->fetchColumn()) === 1;
                    }

                    NewsletterService::subscribeToList($db, $listId, $email, '');
                    if ($alreadyInList) {
                        $duplicates++;
                    } else {
                        $imported++;
                    }
                    $processedPairs++;
                }
            }
            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'message' => 'Importul abonaților a eșuat. Verifică structura fișierului și încearcă din nou.'];
        }

        if ($processedPairs <= 0) {
            return ['ok' => false, 'message' => 'Nu am găsit perechi valide Email/Listă în fișier.'];
        }

        if ($imported <= 0 && $duplicates <= 0) {
            return ['ok' => false, 'message' => 'Nu s-au importat abonați. Verifică structura fișierului (perechi Email/Listă).'];
        }

        return [
            'ok' => true,
            'message' => 'Import abonați finalizat: ' . $imported . ' adăugați, ' . $duplicates . ' existenți, '
                . $createdLists . ' liste noi create, ' . $invalid . ' intrări invalide.',
        ];
    }

    private function newsletterSubscribersRowsFromCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $headerLine = fgetcsv($handle, 0, $delimiter);
        if (!is_array($headerLine) || $headerLine === []) {
            fclose($handle);
            return [];
        }
        $headerPairs = $this->newsletterSubscribersHeaderPairs($headerLine);
        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = $this->newsletterSubscribersMapRow($line, $headerPairs);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function newsletterSubscribersRowsFromXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    $parts = [];
                    if (isset($si->t)) {
                        $parts[] = (string) $si->t;
                    }
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $parts[] = (string) ($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = trim(implode('', $parts));
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!is_string($sheetXml) || $sheetXml === '') {
            $zip->close();
            return [];
        }
        $sx = @simplexml_load_string($sheetXml);
        $zip->close();
        if ($sx === false || !isset($sx->sheetData->row)) {
            return [];
        }

        $rows = [];
        foreach ($sx->sheetData->row as $rowNode) {
            $indexedRow = [];
            foreach ($rowNode->c as $cell) {
                $ref = strtoupper((string) ($cell['r'] ?? ''));
                $colLetters = preg_replace('/[^A-Z]/', '', $ref) ?: '';
                if ($colLetters === '') {
                    continue;
                }
                $index = $this->excelColumnLettersToIndex($colLetters);
                $type = (string) ($cell['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $value = (string) ($sharedStrings[$idx] ?? '');
                } elseif (isset($cell->is->t)) {
                    $value = (string) $cell->is->t;
                } else {
                    $value = (string) ($cell->v ?? '');
                }
                $indexedRow[$index] = trim($value);
            }
            if ($indexedRow === []) {
                continue;
            }
            ksort($indexedRow);
            $maxIndex = max(array_keys($indexedRow));
            $denseRow = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $denseRow[] = (string) ($indexedRow[$i] ?? '');
            }
            $rows[] = $denseRow;
        }

        if ($rows === []) {
            return [];
        }

        $header = array_shift($rows);
        if (!is_array($header) || $header === []) {
            return [];
        }
        $headerPairs = $this->newsletterSubscribersHeaderPairs($header);
        $result = [];
        foreach ($rows as $line) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = $this->newsletterSubscribersMapRow($line, $headerPairs);
            if ($mapped !== null) {
                $result[] = $mapped;
            }
        }

        return $result;
    }

    private function newsletterSubscribersHeaderPairs(array $header): array
    {
        $normalized = [];
        foreach ($header as $index => $rawName) {
            $name = mb_strtolower(trim((string) $rawName));
            if ((int) $index === 0) {
                $name = ltrim($name, "\xEF\xBB\xBF");
            }
            $normalized[(int) $index] = $name;
        }

        $pairs = [];
        $count = count($normalized);
        for ($i = 0; $i < $count; $i++) {
            $label = (string) ($normalized[$i] ?? '');
            if (!$this->isNewsletterEmailHeaderLabel($label)) {
                continue;
            }
            $listIndex = $this->findNewsletterListHeaderIndex($normalized, $i + 1);
            if ($listIndex === null) {
                continue;
            }
            $pairs[] = ['email' => $i, 'list' => $listIndex];
            $i = $listIndex;
        }

        return $pairs;
    }

    private function findNewsletterListHeaderIndex(array $normalizedHeader, int $start): ?int
    {
        $count = count($normalizedHeader);
        for ($index = max(0, $start); $index < $count; $index++) {
            $label = (string) ($normalizedHeader[$index] ?? '');
            if ($this->isNewsletterListHeaderLabel($label)) {
                return $index;
            }
            if ($this->isNewsletterEmailHeaderLabel($label)) {
                return null;
            }
        }

        return null;
    }

    private function isNewsletterEmailHeaderLabel(string $label): bool
    {
        $label = trim($label);
        return $label === 'email' || str_starts_with($label, 'email ');
    }

    private function isNewsletterListHeaderLabel(string $label): bool
    {
        $label = trim($label);
        return $label === 'lista' || $label === 'listă' || str_starts_with($label, 'lista ') || str_starts_with($label, 'listă ');
    }

    private function newsletterSubscribersMapRow(array $row, array $headerPairs): ?array
    {
        if ($headerPairs === []) {
            return null;
        }

        $entries = [];
        foreach ($headerPairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $emailIndex = (int) ($pair['email'] ?? -1);
            $listIndex = (int) ($pair['list'] ?? -1);
            if ($emailIndex < 0 || $listIndex < 0) {
                continue;
            }
            $email = strtolower(trim((string) ($row[$emailIndex] ?? '')));
            $listName = trim((string) ($row[$listIndex] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            if ($listName === '') {
                $listName = 'Toți abonații';
            }
            $entries[] = [
                'email' => $email,
                'list_name' => $listName,
            ];
        }

        if ($entries === []) {
            return null;
        }

        return ['entries' => $entries];
    }

    private function emailSections(): array
    {
        return ['sender', 'test', 'automation', 'newsletters'];
    }

    private function newsletterTabs(): array
    {
        return ['templates', 'campaigns', 'ecommerce', 'subscribers', 'optin', 'contact_forms', 'stats', 'history'];
    }

    private function loadMannequinProducts(PDO $db): array
    {
        $stmt = $db->query(
            'SELECT id, name, slug, short_description, price, image_url
             FROM products
             WHERE deleted_at IS NULL
             ORDER BY name ASC, id DESC'
        );
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        $products = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (int) ($row['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $products[] = [
                'id' => $productId,
                'name' => trim((string) ($row['name'] ?? 'Produs')),
                'slug' => trim((string) ($row['slug'] ?? '')),
                'short_description' => trim((string) ($row['short_description'] ?? '')),
                'price' => (float) ($row['price'] ?? 0),
                'image_url' => trim((string) ($row['image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg',
            ];
        }

        return $products;
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

            $x = (float) ($entry['x'] ?? 50);
            $y = (float) ($entry['y'] ?? 50);
            $x = max(0, min(100, $x));
            $y = max(0, min(100, $y));

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
            if (count($points) >= 50) {
                break;
            }
        }

        return $points;
    }

    private function mannequinCodeToken(): string
    {
        return '{{mannequin_section}}';
    }

    private function shopCatalogPreviewHtml(?PDO $db): string
    {
        $activeCategory = trim((string) ($_GET['categorie'] ?? $_GET['category'] ?? ''));
        $sort = $this->shopCatalogSortPreview();
        [$products] = $this->loadShopPreviewProducts($db, $activeCategory, $sort);
        $categories = $this->loadShopPreviewCategories($db);
        if ($categories === []) {
            [$fallbackProducts] = $this->loadShopPreviewProducts($db, '', 'featured');
            $categories = $this->buildShopPreviewCategoriesFromProducts($fallbackProducts);
        }
        return $this->renderPartialPhpView('site/components/shop-catalog', [
            'products' => $products,
            'categories' => $categories,
            'activeCategory' => $activeCategory,
            'sort' => $sort,
            'baseUrl' => '/magazin',
        ]);
    }

    private function blogPostsPreviewHtml(?PDO $db): string
    {
        $posts = $this->loadBlogPostsPreview($db, 6);
        return $this->renderPartialPhpView('site/components/blog-posts', [
            'posts' => $posts,
            'baseUrl' => '/blog',
        ]);
    }

    private function accountSectionPreviewHtml(): string
    {
        return '<section style="border:1px solid #dbe7df;border-radius:14px;background:#f8fffb;padding:14px;">'
            . '<div style="display:grid;grid-template-columns:220px minmax(0,1fr);gap:12px;align-items:start;">'
            . '<aside style="border:1px solid #d7e6dc;border-radius:12px;background:#fff;overflow:hidden;">'
            . '<div style="padding:14px;background:linear-gradient(135deg,#2d975f,#2b8456);color:#fff;">'
            . '<div style="display:flex;gap:10px;align-items:center;">'
            . '<span style="width:44px;height:44px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:rgba(255,255,255,.2);font-weight:700;">MI</span>'
            . '<div><strong style="display:block;">Maria Ionescu</strong><small style="opacity:.9;">Membru din ianuarie 2024</small></div>'
            . '</div></div>'
            . '<div style="padding:10px;display:grid;gap:6px;">'
            . '<div style="padding:8px 10px;border-radius:9px;background:#e9f5ee;color:#1f6a47;font-weight:700;">Profilul meu</div>'
            . '<div style="padding:8px 10px;border-radius:9px;color:#475569;">Comenzile mele</div>'
            . '<div style="padding:8px 10px;border-radius:9px;color:#475569;">Punctele mele</div>'
            . '</div></aside>'
            . '<div style="display:grid;gap:10px;">'
            . '<div style="border:1px solid #d7e6dc;border-radius:12px;background:#fff;padding:12px;">'
            . '<h3 style="margin:0 0 8px;">Profilul meu</h3>'
            . '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;color:#334155;font-size:13px;">'
            . '<div><strong style="display:block;color:#64748b;font-size:11px;">NUME COMPLET</strong>Maria Ionescu</div>'
            . '<div><strong style="display:block;color:#64748b;font-size:11px;">EMAIL</strong>maria.ionescu@email.com</div>'
            . '<div><strong style="display:block;color:#64748b;font-size:11px;">TELEFON</strong>+40 721 234 567</div>'
            . '<div><strong style="display:block;color:#64748b;font-size:11px;">MEMBRU DIN</strong>Ianuarie 2024</div>'
            . '</div></div>'
            . '<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;">'
            . '<div style="border:1px solid #d7e6dc;border-radius:10px;background:#fff;padding:10px;text-align:center;"><strong style="display:block;font-size:20px;">12</strong><small>Comenzi</small></div>'
            . '<div style="border:1px solid #d7e6dc;border-radius:10px;background:#fff;padding:10px;text-align:center;"><strong style="display:block;font-size:20px;">3</strong><small>Adrese</small></div>'
            . '<div style="border:1px solid #d7e6dc;border-radius:10px;background:#fff;padding:10px;text-align:center;"><strong style="display:block;font-size:20px;">1240</strong><small>Puncte</small></div>'
            . '<div style="border:1px solid #d7e6dc;border-radius:10px;background:#fff;padding:10px;text-align:center;"><strong style="display:block;font-size:20px;">28 Mar</strong><small>Ultima comandă</small></div>'
            . '</div></div></div>'
            . '</section>';
    }

    private function googleAuthButtonPreviewHtml(): string
    {
        return '<div class="bv-google-auth-inline" style="margin-top:14px;">'
            . '<a href="/auth/google"'
            . ' style="display:flex;align-items:center;justify-content:center;gap:10px;'
            . 'padding:12px 16px;border:1px solid #d1d5db;border-radius:14px;background:#fff;'
            . 'color:#111827;text-decoration:none;font-weight:600;font-size:16px;line-height:1.2;">'
            . '<span aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;">'
            . '<svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">'
            . '<path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.3-1.5 3.9-5.5 3.9-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.5 14.6 2.6 12 2.6 6.9 2.6 2.8 6.7 2.8 11.8S6.9 21 12 21c6.9 0 9.2-4.8 9.2-7.3 0-.5-.1-.8-.1-1.2H12z"/>'
            . '<path fill="#34A853" d="M3.8 7.2l3.2 2.3C7.8 7.8 9.7 6 12 6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.5 14.6 2.6 12 2.6 8.3 2.6 5.1 4.7 3.8 7.2z"/>'
            . '<path fill="#FBBC05" d="M12 21c2.5 0 4.7-.8 6.3-2.3l-2.9-2.3c-.8.6-1.9 1.1-3.4 1.1-2.6 0-4.8-1.8-5.6-4.1l-3.3 2.6C4.4 19 8 21 12 21z"/>'
            . '<path fill="#4285F4" d="M21.2 13.7c0-.7-.1-1.2-.2-1.8H12v3.9h5.5c-.3 1.3-1.1 2.4-2.3 3.1l2.9 2.3c1.7-1.6 2.7-4 2.7-7.5z"/>'
            . '</svg>'
            . '</span>'
            . '<span>Continuă cu Google</span>'
            . '</a>'
            . '</div>';
    }

    private function checkoutSuccessOrderInfoPreviewHtml(): string
    {
        return '<div class="checkout-success-order-info" data-checkout-success-order-info="1">'
            . '<p><strong>Număr comandă:</strong> BV20260407140852662</p>'
            . '<p><strong>Status comandă:</strong> pending</p>'
            . '</div>';
    }

    private function reviewFormPreviewHtml(?PDO $db): string
    {
        $products = $this->reviewFormProductsPreview($db);
        $options = [];
        $selectedSet = false;
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $id = (int) ($product['id'] ?? 0);
            $name = trim((string) ($product['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $isSelected = !$selectedSet;
            $selectedSet = true;
            $options[] = '<option value="' . $id . '"' . ($isSelected ? ' selected' : '') . '>' . htmlspecialchars($name, ENT_QUOTES) . '</option>';
        }
        if ($options === []) {
            $options[] = '<option value="1" selected>Produs demo</option>';
        }
        $rating = '';
        for ($value = 1; $value <= 5; $value++) {
            $rating .= '<button type="button" class="product-review-rating__star is-active" data-rating-value="' . $value . '" aria-label="' . $value . ' stele" aria-pressed="true">★</button>';
        }

        return '<section id="qr-product-review-form" class="product-reviews product-reviews--public">'
            . '<div class="product-reviews-head"><div class="product-reviews-head__inline"><span class="product-reviews-head__label">Recenzie produs</span></div></div>'
            . '<form method="post" action="/review-form/submit" class="product-review-form">'
            . '<input type="hidden" name="redirect_to" value="/preview#qr-product-review-form">'
            . '<div class="field"><label>Produs</label><select name="product_id" required>' . implode('', $options) . '</select></div>'
            . '<div class="field"><label>Nume</label><input type="text" name="review_name" value="" required></div>'
            . '<div class="field"><label>Email (opțional)</label><input type="email" name="review_email" value="" placeholder="nume@email.com"></div>'
            . '<div class="field"><label>Rating</label><div class="product-review-rating" data-review-rating="1"><input type="hidden" name="review_rating" value="5" data-review-rating-input="1">' . $rating . '</div></div>'
            . '<div class="field"><label>Recenzie</label><textarea name="review_text" rows="4" required></textarea></div>'
            . '<button class="btn" type="submit">Trimite recenzia</button>'
            . '</form>'
            . '</section>';
    }

    private function gdprAgreementsFormPreviewHtml(): string
    {
        return <<<HTML
<section class="gdpr-agreement-preview" style="max-width:980px;margin:0 auto;border:1px solid #d7dee7;background:#fff;padding:22px;border-radius:14px;color:#0f172a;">
    <h2 style="margin:0 0 14px;font-size:30px;line-height:1.2;text-align:center;">Acord privind utilizarea și procesarea datelor cu caracter personal</h2>
    <p style="margin:0 0 14px;line-height:1.78;">
        Subsemnatul/a <input type="text" placeholder="Nume complet" style="width:180px;" disabled>,
        legitimat/ă cu CI seria <input type="text" placeholder="Serie" style="width:120px;" disabled> nr
        <input type="text" placeholder="Număr" style="width:120px;" disabled>, eliberată de către
        <input type="text" placeholder="Emitent" style="width:160px;" disabled> la data de
        <input type="text" placeholder="zz.ll.aaaa" style="width:120px;" disabled>, denumit în continuare <strong>subiect</strong>, îmi exprim acordul...
    </p>

    <h3 style="margin:18px 0 10px;font-size:24px;line-height:1.2;">I. Date procesate</h3>
    <table style="width:100%;border-collapse:collapse;margin:0 0 12px;">
        <tbody>
            <tr><th style="border:1px solid #9aa6b2;padding:8px;text-align:left;width:32%;background:#f8fafc;">NUME</th><td style="border:1px solid #9aa6b2;padding:8px;"><input type="text" placeholder="Nume" disabled></td></tr>
            <tr><th style="border:1px solid #9aa6b2;padding:8px;text-align:left;background:#f8fafc;">PRENUME</th><td style="border:1px solid #9aa6b2;padding:8px;"><input type="text" placeholder="Prenume" disabled></td></tr>
            <tr><th style="border:1px solid #9aa6b2;padding:8px;text-align:left;background:#f8fafc;">CNP</th><td style="border:1px solid #9aa6b2;padding:8px;"><input type="text" placeholder="CNP" disabled></td></tr>
            <tr><th style="border:1px solid #9aa6b2;padding:8px;text-align:left;background:#f8fafc;">CUIM</th><td style="border:1px solid #9aa6b2;padding:8px;"><input type="text" placeholder="CUIM" disabled></td></tr>
            <tr><th style="border:1px solid #9aa6b2;padding:8px;text-align:left;background:#f8fafc;">TELEFON</th><td style="border:1px solid #9aa6b2;padding:8px;"><input type="text" placeholder="Telefon" disabled></td></tr>
            <tr><th style="border:1px solid #9aa6b2;padding:8px;text-align:left;background:#f8fafc;">EMAIL</th><td style="border:1px solid #9aa6b2;padding:8px;"><input type="text" placeholder="Email" disabled></td></tr>
        </tbody>
    </table>

    <h3 style="margin:18px 0 10px;font-size:24px;line-height:1.2;">V. Declarație</h3>
    <p style="margin:0 0 14px;line-height:1.78;">
        Subiectul își exprimă consimțământul în favoarea beneficiarului cu privire la utilizarea datelor cu caracter personal descrise mai sus.
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div>
            <label style="display:block;font-weight:700;margin-bottom:6px;">Data</label>
            <input type="text" placeholder="Data" disabled>
        </div>
        <div>
            <label style="display:block;font-weight:700;margin-bottom:6px;">Numele în clar și semnătura subiectului</label>
            <input type="text" placeholder="Nume semnătură" disabled>
            <div style="margin-top:8px;border:1px dashed #94a3b8;border-radius:10px;background:#f8fafc;padding:10px;color:#475569;">
                Zonă semnătură tactilă (canvas) + buton „Salvează”
            </div>
        </div>
    </div>
</section>
HTML;
    }

    private function reviewFormProductsPreview(?PDO $db): array
    {
        if (!$db instanceof PDO) {
            return [
                ['id' => 1, 'name' => 'Produs demo'],
                ['id' => 2, 'name' => 'Produs demo 2'],
            ];
        }
        try {
            $stmt = $db->query(
                'SELECT id, name
                 FROM products
                 WHERE deleted_at IS NULL AND is_active = 1
                 ORDER BY name ASC, id DESC
                 LIMIT 80'
            );
            $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
            if (is_array($rows) && $rows !== []) {
                return $rows;
            }
        } catch (Throwable) {
        }
        try {
            $stmt = $db->query(
                'SELECT id, name
                 FROM products
                 WHERE deleted_at IS NULL
                 ORDER BY name ASC, id DESC
                 LIMIT 80'
            );
            $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
            if (is_array($rows) && $rows !== []) {
                return $rows;
            }
        } catch (Throwable) {
        }
        return [
            ['id' => 1, 'name' => 'Produs demo'],
            ['id' => 2, 'name' => 'Produs demo 2'],
        ];
    }

    private function checkoutFormPreviewHtml(?PDO $db): string
    {
        $summary = [
            'lines' => [
                [
                    'name' => 'Colagen marin premium',
                    'quantity' => 1,
                    'line_total' => 119.00,
                ],
                [
                    'name' => 'Magneziu + B6',
                    'quantity' => 1,
                    'line_total' => 49.00,
                ],
            ],
            'subtotal' => 168.00,
            'discount' => 15.00,
            'points_discount' => 0.00,
            'shipping' => 15.00,
            'total' => 168.00,
            'county' => 'Bucuresti',
        ];
        $values = [
            'billing_first_name' => 'Alexandru',
            'billing_last_name' => 'Popa',
            'billing_email' => 'alexandru@example.com',
            'billing_phone' => '0712345678',
            'billing_address_line1' => 'Str. Exemplu 10',
            'billing_address_line2' => 'Ap. 5',
            'billing_city' => 'Bucuresti',
            'billing_county' => 'Bucuresti',
            'billing_postcode' => '010101',
            'notes' => '',
            'payment_method' => 'euplatesc',
        ];

        return $this->renderPartialPhpView('site/components/checkout-form', [
            'summary' => $summary,
            'values' => $values,
            // În preview arătăm ambele metode de card, ca designul să fie vizibil.
            'cardMethods' => ['euplatesc', 'stripe'],
            'isLoggedIn' => true,
            'antiBot' => ['token' => '', 'rendered_at' => 0],
            'previewMode' => true,
            'checkoutInstanceId' => 'admin-preview-checkout',
        ]);
    }

    private function cartFormPreviewHtml(?PDO $db): string
    {
        $summary = [
            'lines' => [
                [
                    'id' => 101,
                    'name' => 'Colagen marin premium',
                    'price' => 119.00,
                    'quantity' => 1,
                    'line_total' => 119.00,
                ],
                [
                    'id' => 102,
                    'name' => 'Magneziu + B6',
                    'price' => 49.00,
                    'quantity' => 1,
                    'line_total' => 49.00,
                ],
            ],
            'subtotal' => 168.00,
            'subtotal_without_vat' => 141.18,
            'vat' => 26.82,
            'discount' => 10.00,
            'points_discount' => 0.00,
            'shipping' => 15.00,
            'total' => 173.00,
            'county' => 'Bucuresti',
            'coupon' => [
                'code' => 'BUNVENIT10',
            ],
            'points' => [
                'enabled' => true,
                'available' => 240,
                'requested' => 140,
                'applied' => 140,
                'discount' => 14.00,
                'error' => null,
                'min_redeem' => 100,
                'max_points' => 220,
            ],
        ];
        $quantityUi = [
            'style' => 'stepper',
            'apply_cart_page' => true,
        ];
        return $this->renderPartialPhpView('site/cart', [
            'summary' => $summary,
            'quantityUi' => $quantityUi,
            'isLoggedIn' => true,
            'previewMode' => true,
        ]);
    }

    private function loadBlogPostsPreview(?PDO $db, int $limit = 6): array
    {
        if (!$db instanceof PDO) {
            return [[
                'id' => 1,
                'title' => 'Articol demo despre sănătate',
                'slug' => 'articol-demo-sanatate',
                'excerpt' => 'Acesta este un articol demo pentru preview-ul din editorul de pagini.',
                'reading_minutes' => 5,
                'published_at' => date('Y-m-d H:i:s'),
                'cover_image_url' => '/assets/img/product-placeholder.svg',
                'author_name' => 'Echipa Bioscem',
            ]];
        }

        $safeLimit = max(1, min(24, $limit));
        try {
            $stmt = $db->prepare(
                'SELECT p.id, p.title, p.slug, p.excerpt, p.reading_minutes, p.published_at, p.cover_image_url,
                        COALESCE(a.name, "") AS author_name
                 FROM blog_posts p
                 LEFT JOIN blog_authors a ON a.id = p.author_id
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()
                 ORDER BY p.published_at DESC, p.id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [[
                'id' => 1,
                'title' => 'Articol demo despre sănătate',
                'slug' => 'articol-demo-sanatate',
                'excerpt' => 'Acesta este un articol demo pentru preview-ul din editorul de pagini.',
                'reading_minutes' => 5,
                'published_at' => date('Y-m-d H:i:s'),
                'cover_image_url' => '/assets/img/product-placeholder.svg',
                'author_name' => 'Echipa Bioscem',
            ]];
        }
    }

    private function loadShopPreviewProducts(?PDO $db, string $categoryFilter = '', string $sort = 'featured'): array
    {
        $categoryFilter = trim($categoryFilter);
        $sort = $this->normalizeShopCatalogSortPreview($sort);
        $orderBy = $this->shopCatalogSortSqlPreview($sort);
        if (!$db instanceof PDO) {
            $fallback = [[
                'id' => 1,
                'name' => 'Colagen Premium',
                'slug' => 'colagen-premium',
                'short_description' => 'Supliment pentru articulații și piele.',
                'category' => 'Suplimente',
                'price' => 149.00,
                'base_price' => 149.00,
                'sale_price' => null,
                'has_sale_price' => false,
                'image_url' => '/assets/img/product-placeholder.svg',
                'badge_popular' => 0,
                'badge_best_seller' => 0,
                'badge_seasonal' => 0,
            ]];
            if ($categoryFilter !== '') {
                $filter = mb_strtolower($categoryFilter);
                $fallback = array_values(array_filter($fallback, static function (array $item) use ($filter): bool {
                    return mb_strtolower(trim((string) ($item['category'] ?? ''))) === $filter;
                }));
            }
            usort($fallback, $this->shopCatalogSortComparatorPreview($sort));
            return $fallback;
        }

        $whereSql = 'p.deleted_at IS NULL';
        $params = [];
        if ($categoryFilter !== '') {
            $whereSql .= ' AND LOWER(TRIM(COALESCE(p.category, ""))) = LOWER(TRIM(:category_filter))';
            $params['category_filter'] = $categoryFilter;
        }

        $rows = [];
        try {
            $sql = 'SELECT p.id, p.name, p.slug, p.short_description, p.category, p.price, p.sale_price, p.sale_price_periods_json, p.out_of_stock, p.image_url, p.badge_popular, p.badge_best_seller, p.badge_seasonal
                    FROM products p
                    WHERE ' . $whereSql . '
                    ORDER BY ' . $orderBy;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            try {
                $sql = 'SELECT p.id, p.name, p.slug, p.short_description, p.category, p.price, p.sale_price, NULL AS sale_price_periods_json, 0 AS out_of_stock, p.image_url, p.label_popular AS badge_popular, p.label_best_seller AS badge_best_seller, p.label_seasonal AS badge_seasonal
                        FROM products p
                        WHERE ' . $whereSql . '
                        ORDER BY ' . $orderBy;
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll() ?: [];
            } catch (Throwable) {
                $rows = [];
            }
        }

        $products = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $basePrice = max(0.0, (float) ($row['price'] ?? 0.0));
            $pricing = $this->resolveProductPricing(
                $basePrice,
                $row['sale_price'] ?? null,
                $row['sale_price_periods_json'] ?? '[]'
            );

            $imageUrl = trim((string) ($row['image_url'] ?? ''));
            if ($imageUrl === '' || str_contains($imageUrl, 'via.placeholder.com')) {
                $imageUrl = '/assets/img/product-placeholder.svg';
            }
            $row['image_url'] = $imageUrl;
            $row['base_price'] = $basePrice;
            $row['sale_price'] = $pricing['sale_price'] ?? null;
            $row['has_sale_price'] = (bool) ($pricing['has_sale_price'] ?? false);
            $row['price'] = (float) ($pricing['effective_price'] ?? $basePrice);
            $row['out_of_stock'] = (int) ($row['out_of_stock'] ?? 0) === 1 ? 1 : 0;
            $products[] = $row;
        }

        usort($products, $this->shopCatalogSortComparatorPreview($sort));

        return $products;
    }

    private function shopCatalogSortPreview(): string
    {
        return $this->normalizeShopCatalogSortPreview((string) ($_GET['sort'] ?? 'featured'));
    }

    private function normalizeShopCatalogSortPreview(string $sort): string
    {
        $sort = trim(strtolower($sort));
        return match ($sort) {
            'price_asc', 'price_desc', 'rating', 'newest' => $sort,
            default => 'featured',
        };
    }

    private function shopCatalogSortSqlPreview(string $sort): string
    {
        return match ($sort) {
            'price_asc' => 'p.id DESC',
            'price_desc' => 'p.id DESC',
            'rating' => 'p.id DESC',
            'newest' => 'p.id DESC',
            default => '(p.badge_best_seller = 1) DESC, (p.badge_popular = 1) DESC, p.id DESC',
        };
    }

    private function shopCatalogSortComparatorPreview(string $sort): callable
    {
        return static function (array $left, array $right) use ($sort): int {
            $leftPrice = (float) ($left['price'] ?? 0);
            $rightPrice = (float) ($right['price'] ?? 0);
            if ($sort === 'price_asc') {
                $cmp = $leftPrice <=> $rightPrice;
                return $cmp !== 0 ? $cmp : (((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0)));
            }
            if ($sort === 'price_desc') {
                $cmp = $rightPrice <=> $leftPrice;
                return $cmp !== 0 ? $cmp : (((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0)));
            }
            return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
        };
    }

    private function loadShopPreviewCategories(?PDO $db): array
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

    private function buildShopPreviewCategoriesFromProducts(array $products): array
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

    private function mannequinSectionPreviewHtml(?PDO $db): string
    {
        if (!$db instanceof PDO) {
            return '';
        }

        $settings = Settings::all($db);
        if ((string) ($settings['mannequin_enabled'] ?? '1') !== '1') {
            return '';
        }

        $points = $this->decodeMannequinPoints((string) ($settings['mannequin_points_json'] ?? '[]'));
        if ($points === []) {
            return '';
        }

        $productMap = $this->loadMannequinPreviewProductMap($db);
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

        return $this->renderPartialPhpView('site/components/mannequin-widget', [
            'widget' => $payload,
        ]);
    }

    private function loadMannequinPreviewProductMap(PDO $db): array
    {
        try {
            $stmt = $db->query(
                'SELECT p.id, p.name, p.slug, p.short_description, p.price, p.sale_price, p.sale_price_periods_json, p.image_url,
                        COUNT(pr.id) AS reviews_count,
                        AVG(pr.rating) AS reviews_average
                 FROM products p
                 LEFT JOIN product_reviews pr ON pr.product_id = p.id AND pr.is_approved = 1
                 WHERE p.deleted_at IS NULL
                 GROUP BY p.id, p.name, p.slug, p.short_description, p.price, p.sale_price, p.sale_price_periods_json, p.image_url
                 ORDER BY p.id DESC'
            );
        } catch (Throwable) {
            $stmt = $db->query(
                'SELECT p.id, p.name, p.slug, p.short_description, p.price, NULL AS sale_price, NULL AS sale_price_periods_json, p.image_url,
                        COUNT(pr.id) AS reviews_count,
                        AVG(pr.rating) AS reviews_average
                 FROM products p
                 LEFT JOIN product_reviews pr ON pr.product_id = p.id AND pr.is_approved = 1
                 WHERE p.deleted_at IS NULL
                 GROUP BY p.id, p.name, p.slug, p.short_description, p.price, p.image_url
                 ORDER BY p.id DESC'
            );
        }

        if (!$stmt) {
            return [];
        }

        $rows = $stmt->fetchAll() ?: [];
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
            $price = max(0.0, (float) ($row['price'] ?? 0.0));
            $pricing = $this->resolveProductPricing(
                $price,
                $row['sale_price'] ?? null,
                $row['sale_price_periods_json'] ?? '[]'
            );
            $effectivePrice = (float) ($pricing['effective_price'] ?? $price);

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
                'cart_add_url' => '/cos/adauga/' . $productId,
            ];
        }

        return $map;
    }

    private function renderPartialPhpView(string $template, array $data = []): string
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

    private function ecommerceTemplatesFromSettings(array $settings): array
    {
        $rows = [];
        foreach (OrderMailer::templateDefinitions() as $type => $meta) {
            $subjectKey = (string) ($meta['subject_key'] ?? '');
            $bodyKey = (string) ($meta['body_key'] ?? '');
            $activeKey = (string) ($meta['active_key'] ?? '');
            $blocksKey = $this->ecommerceBlocksKey((string) $type);
            $recipientMode = OrderMailer::templateRecipientMode((string) $type, $settings);
            $adminRecipients = OrderMailer::templateAdminRecipients((string) $type, $settings);

            $subject = (string) ($settings[$subjectKey] ?? $meta['default_subject'] ?? '');
            $body = (string) ($settings[$bodyKey] ?? $meta['default_body'] ?? '');
            if ((string) $type === 'new_order') {
                if ($this->isLegacyNewOrderTemplateSubject($subject)) {
                    $subject = 'Comandă nouă {{order_number}}';
                }
                if ($this->isLegacyNewOrderTemplateBody($body)) {
                    $body = (string) ($meta['default_body'] ?? $body);
                }
            } elseif ((string) $type === 'processing') {
                if ($this->isLegacyProcessingTemplateSubject($subject)) {
                    $subject = 'Comandă {{order_number}} în procesare';
                }
                if ($this->isLegacyProcessingTemplateBody($body)) {
                    $body = (string) ($meta['default_body'] ?? $body);
                }
            } elseif ((string) $type === 'shipped') {
                if ($this->isLegacyShippedTemplateSubject($subject)) {
                    $subject = 'Comandă {{order_number}} expediată';
                }
                if ($this->isLegacyShippedTemplateBody($body)) {
                    $body = (string) ($meta['default_body'] ?? $body);
                }
            } elseif ((string) $type === 'delivered') {
                if ($this->isLegacyDeliveredTemplateSubject($subject)) {
                    $subject = 'Comandă livrată {{order_number}}';
                }
                if ($this->isLegacyDeliveredTemplateBody($body)) {
                    $body = (string) ($meta['default_body'] ?? $body);
                }
            } elseif ((string) $type === 'cancelled') {
                if ($this->isLegacyCancelledTemplateSubject($subject)) {
                    $subject = 'Comandă anulată {{order_number}}';
                }
                if ($this->isLegacyCancelledTemplateBody($body)) {
                    $body = (string) ($meta['default_body'] ?? $body);
                }
            } elseif ((string) $type === 'abandoned_cart') {
                if ($this->isLegacyAbandonedCartTemplateSubject($subject)) {
                    $subject = 'Ai uitat produse în coș';
                }
                if ($this->isLegacyAbandonedCartTemplateBody($body)) {
                    $body = (string) ($meta['default_body'] ?? $body);
                }
            }
            $decodedBlocks = json_decode((string) ($settings[$blocksKey] ?? ''), true);
            if (!is_array($decodedBlocks) || $decodedBlocks === []) {
                if ((string) $type === 'new_order') {
                    $decodedBlocks = $this->newOrderEcommerceDefaultBlocks();
                } elseif ((string) $type === 'processing') {
                    $decodedBlocks = $this->processingEcommerceDefaultBlocks();
                } elseif ((string) $type === 'shipped') {
                    $decodedBlocks = $this->shippedEcommerceDefaultBlocks();
                } elseif ((string) $type === 'delivered') {
                    $decodedBlocks = $this->deliveredEcommerceDefaultBlocks();
                } elseif ((string) $type === 'cancelled') {
                    $decodedBlocks = $this->cancelledEcommerceDefaultBlocks();
                } elseif ((string) $type === 'abandoned_cart') {
                    $decodedBlocks = $this->abandonedCartEcommerceDefaultBlocks();
                } else {
                    $decodedBlocks = $this->defaultBlocksFromText(strip_tags($body));
                }
            } elseif ((string) $type === 'new_order' && $this->isLegacyNewOrderTemplateBlocks($decodedBlocks)) {
                $decodedBlocks = $this->newOrderEcommerceDefaultBlocks();
            } elseif (
                (string) $type === 'processing'
                && (
                    $this->isLegacyProcessingTemplateBlocks($decodedBlocks)
                    || $this->isProcessingTemplateBlocksV1($decodedBlocks)
                    || $this->isProcessingTemplateBlocksV2($decodedBlocks)
                )
            ) {
                $decodedBlocks = $this->processingEcommerceDefaultBlocks();
            } elseif (
                (string) $type === 'shipped'
                && ($this->isLegacyShippedTemplateBlocks($decodedBlocks) || $this->isShippedTemplateBlocksV1($decodedBlocks))
            ) {
                $decodedBlocks = $this->shippedEcommerceDefaultBlocks();
            } elseif ((string) $type === 'delivered' && $this->isLegacyDeliveredTemplateBlocks($decodedBlocks)) {
                $decodedBlocks = $this->deliveredEcommerceDefaultBlocks();
            } elseif ((string) $type === 'cancelled' && $this->isLegacyCancelledTemplateBlocks($decodedBlocks)) {
                $decodedBlocks = $this->cancelledEcommerceDefaultBlocks();
            } elseif ((string) $type === 'abandoned_cart' && $this->isLegacyAbandonedCartTemplateBlocks($decodedBlocks)) {
                $decodedBlocks = $this->abandonedCartEcommerceDefaultBlocks();
            }
            if (!is_array($decodedBlocks) || $decodedBlocks === []) {
                $decodedBlocks = $this->defaultBlocksFromText(strip_tags($body));
            }

            $rows[(string) $type] = [
                'type' => (string) $type,
                'label' => (string) ($meta['label'] ?? $type),
                'subject' => $subject,
                'html_content' => $body,
                'is_active' => $activeKey === '' ? true : ((string) ($settings[$activeKey] ?? '1') === '1'),
                'blocks' => $decodedBlocks,
                'recipient_mode' => $recipientMode,
                'admin_recipients' => $adminRecipients,
                'admin_recipients_raw' => implode(', ', $adminRecipients),
            ];
        }

        return $rows;
    }

    private function campaignContentForTemplate(PDO $db, array $settings, string $templateType, string $templateRef): ?array
    {
        if ($templateType === 'newsletter') {
            $templateId = (int) $templateRef;
            if ($templateId <= 0) {
                return null;
            }
            $stmt = $db->prepare(
                'SELECT id, subject, html_content
                 FROM newsletter_templates
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $templateId]);
            $row = $stmt->fetch() ?: null;
            if (!is_array($row)) {
                return null;
            }
            return [
                'subject' => (string) ($row['subject'] ?? 'Newsletter'),
                'html_content' => (string) ($row['html_content'] ?? '<p>Newsletter</p>'),
            ];
        }

        if ($templateType === 'ecommerce') {
            $templates = $this->ecommerceTemplatesFromSettings($settings);
            $row = $templates[$templateRef] ?? null;
            if (!is_array($row)) {
                return null;
            }
            return [
                'subject' => (string) ($row['subject'] ?? 'Notificare'),
                'html_content' => (string) ($row['html_content'] ?? '<p>Notificare</p>'),
            ];
        }

        return null;
    }

    private function ecommerceBlocksKey(string $templateType): string
    {
        return 'email_template_' . $templateType . '_blocks_json';
    }

    private function isLegacyProcessingTemplateSubject(string $subject): bool
    {
        $subject = $this->compactTemplateText($subject);
        return in_array($subject, [
            $this->compactTemplateText('Comanda {{order_number}} este in procesare'),
            $this->compactTemplateText('Comandă {{order_number}} este în procesare'),
        ], true);
    }

    private function isLegacyShippedTemplateSubject(string $subject): bool
    {
        $subject = $this->compactTemplateText($subject);
        return in_array($subject, [
            $this->compactTemplateText('Comanda {{order_number}} a fost expediata'),
            $this->compactTemplateText('Comandă {{order_number}} a fost expediată'),
        ], true);
    }

    private function isLegacyDeliveredTemplateSubject(string $subject): bool
    {
        $subject = $this->compactTemplateText($subject);
        return in_array($subject, [
            $this->compactTemplateText('Comanda finalizata {{order_number}}'),
            $this->compactTemplateText('Comandă finalizată {{order_number}}'),
            $this->compactTemplateText('Comandă livrată {{order_number}}'),
        ], true);
    }

    private function isLegacyCancelledTemplateSubject(string $subject): bool
    {
        $subject = $this->compactTemplateText($subject);
        return in_array($subject, [
            $this->compactTemplateText('Comanda {{order_number}} a fost anulata'),
            $this->compactTemplateText('Comandă {{order_number}} a fost anulată'),
            $this->compactTemplateText('Comandă anulată {{order_number}}'),
        ], true);
    }

    private function isLegacyAbandonedCartTemplateSubject(string $subject): bool
    {
        $subject = $this->compactTemplateText($subject);
        return in_array($subject, [
            $this->compactTemplateText('Ai uitat produse in cos'),
            $this->compactTemplateText('Ai uitat produse în coș'),
            $this->compactTemplateText('Ai uitat produse în coșul tău'),
        ], true);
    }

    private function isLegacyNewOrderTemplateSubject(string $subject): bool
    {
        $subject = $this->compactTemplateText($subject);
        return in_array($subject, [
            $this->compactTemplateText('Comanda noua {{order_number}}'),
            $this->compactTemplateText('Comandă nouă {{order_number}}'),
        ], true);
    }

    private function isLegacyProcessingTemplateBody(string $body): bool
    {
        $body = $this->compactTemplateText($body);
        return in_array($body, [
            $this->compactTemplateText('<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} este in procesare.</p>'),
            $this->compactTemplateText('<p>Bună {{customer_name}},</p><p>Comanda ta {{order_number}} este în procesare.</p>'),
        ], true);
    }

    private function isLegacyShippedTemplateBody(string $body): bool
    {
        $body = $this->compactTemplateText($body);
        if (in_array($body, [
            $this->compactTemplateText('<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} a fost expediata.</p><p>AWB: <strong>{{awb}}</strong></p><p>{{tracking_link}}</p>'),
            $this->compactTemplateText('<p>Bună {{customer_name}},</p><p>Comanda ta {{order_number}} a fost expediată.</p><p>AWB: <strong>{{awb}}</strong></p><p>{{tracking_link}}</p>'),
        ], true)) {
            return true;
        }

        // First advanced shipped template used oversized order number text (34px).
        return str_contains($body, '{{order_number}}')
            && str_contains($body, '{{awb}}')
            && str_contains($body, '{{tracking_url}}')
            && str_contains($body, 'nr.comanda')
            && str_contains($body, 'font-size:34px');
    }

    private function isLegacyDeliveredTemplateBody(string $body): bool
    {
        $body = $this->compactTemplateText($body);
        return in_array($body, [
            $this->compactTemplateText('<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} este finalizata, iar coletul a fost predat catre FAN Courier.</p><p>Cod urmarire (AWB): <strong>{{awb}}</strong></p><p>{{tracking_link}}</p><p>Daca butonul nu functioneaza: {{tracking_url}}</p>'),
            $this->compactTemplateText('<p>Bună {{customer_name}},</p><p>Comanda ta {{order_number}} este finalizată, iar coletul a fost predat către FAN Courier.</p><p>Cod urmărire (AWB): <strong>{{awb}}</strong></p><p>{{tracking_link}}</p><p>Dacă butonul nu funcționează: {{tracking_url}}</p>'),
        ], true);
    }

    private function isLegacyCancelledTemplateBody(string $body): bool
    {
        $body = $this->compactTemplateText($body);
        return in_array($body, [
            $this->compactTemplateText('<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} a fost anulata.</p>'),
            $this->compactTemplateText('<p>Bună {{customer_name}},</p><p>Comanda ta {{order_number}} a fost anulată.</p>'),
        ], true);
    }

    private function isLegacyAbandonedCartTemplateBody(string $body): bool
    {
        $body = $this->compactTemplateText($body);
        return in_array($body, [
            $this->compactTemplateText('<p>Buna {{customer_name}},</p><p>Se pare ca ai ramas cu produse in cos.</p><p>{{cart_summary}}</p>'),
            $this->compactTemplateText('<p>Buna {{customer_name}},</p><p>Se pare ca ai ramas cu produse in cos.</p><p>{{cart_summary}}</p><p>Te asteptam sa finalizezi comanda.</p>'),
            $this->compactTemplateText('<p>Bună {{customer_name}},</p><p>Se pare că ai rămas cu produse în coș.</p><p>{{cart_summary}}</p>'),
            $this->compactTemplateText('<p>Bună {{customer_name}},</p><p>Se pare că ai rămas cu produse în coș.</p><p>{{cart_summary}}</p><p>Te așteptăm să finalizezi comanda.</p>'),
        ], true);
    }

    private function isLegacyNewOrderTemplateBody(string $body): bool
    {
        $body = $this->compactTemplateText($body);
        return in_array($body, [
            $this->compactTemplateText('<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} a fost inregistrata cu succes.</p>'),
            $this->compactTemplateText('<p>Bună {{customer_name}},</p><p>Comanda ta {{order_number}} a fost înregistrată cu succes.</p>'),
        ], true);
    }

    private function isLegacyProcessingTemplateBlocks(array $blocks): bool
    {
        if (count($blocks) !== 1 || !is_array($blocks[0] ?? null)) {
            return false;
        }

        $block = $blocks[0];
        if ((string) ($block['type'] ?? '') !== 'text') {
            return false;
        }

        $content = $this->compactTemplateText((string) ($block['content'] ?? ''));
        return str_contains($content, '{{customer_name}}')
            && str_contains($content, '{{order_number}}')
            && str_contains($content, 'comandata')
            && str_contains($content, 'procesare');
    }

    private function isLegacyShippedTemplateBlocks(array $blocks): bool
    {
        if (count($blocks) !== 1 || !is_array($blocks[0] ?? null)) {
            return false;
        }

        $block = $blocks[0];
        if ((string) ($block['type'] ?? '') !== 'text') {
            return false;
        }

        $content = $this->compactTemplateText((string) ($block['content'] ?? ''));
        return str_contains($content, '{{order_number}}')
            && str_contains($content, '{{awb}}')
            && str_contains($content, '{{tracking_link}}')
            && str_contains($content, 'expediata');
    }

    private function isLegacyDeliveredTemplateBlocks(array $blocks): bool
    {
        if (count($blocks) !== 1 || !is_array($blocks[0] ?? null)) {
            return false;
        }

        $block = $blocks[0];
        if ((string) ($block['type'] ?? '') !== 'text') {
            return false;
        }

        $content = $this->compactTemplateText((string) ($block['content'] ?? ''));
        return str_contains($content, '{{order_number}}')
            && str_contains($content, '{{awb}}')
            && str_contains($content, '{{tracking_link}}')
            && str_contains($content, 'finalizata');
    }

    private function isLegacyCancelledTemplateBlocks(array $blocks): bool
    {
        if (count($blocks) !== 1 || !is_array($blocks[0] ?? null)) {
            return false;
        }

        $block = $blocks[0];
        if ((string) ($block['type'] ?? '') !== 'text') {
            return false;
        }

        $content = $this->compactTemplateText((string) ($block['content'] ?? ''));
        return str_contains($content, '{{customer_name}}')
            && str_contains($content, '{{order_number}}')
            && str_contains($content, 'anulata');
    }

    private function isLegacyAbandonedCartTemplateBlocks(array $blocks): bool
    {
        if (count($blocks) !== 1 || !is_array($blocks[0] ?? null)) {
            return false;
        }

        $block = $blocks[0];
        if ((string) ($block['type'] ?? '') !== 'text') {
            return false;
        }

        $content = $this->compactTemplateText((string) ($block['content'] ?? ''));
        return str_contains($content, '{{customer_name}}')
            && str_contains($content, '{{cart_summary}}')
            && str_contains($content, 'cos');
    }

    private function isLegacyNewOrderTemplateBlocks(array $blocks): bool
    {
        if (count($blocks) !== 1 || !is_array($blocks[0] ?? null)) {
            return false;
        }

        $block = $blocks[0];
        if ((string) ($block['type'] ?? '') !== 'text') {
            return false;
        }

        $content = $this->compactTemplateText((string) ($block['content'] ?? ''));
        return str_contains($content, '{{customer_name}}')
            && str_contains($content, '{{order_number}}')
            && str_contains($content, 'inregistrata')
            && str_contains($content, 'succes');
    }

    private function isProcessingTemplateBlocksV1(array $blocks): bool
    {
        if (count($blocks) < 6) {
            return false;
        }

        $hasOrderStatusSummary = false;
        $hasEstimatedDelivery = false;
        $hasTrackingButton = false;

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            if ($type === 'text') {
                $content = $this->compactTemplateText((string) ($block['content'] ?? ''));
                if (str_contains($content, 'nr.comanda') && str_contains($content, 'status') && str_contains($content, '{{order_status}}')) {
                    $hasOrderStatusSummary = true;
                }
                if (str_contains($content, 'livrareestimata') && str_contains($content, '{{estimated_delivery}}')) {
                    $hasEstimatedDelivery = true;
                }
            }

            if ($type === 'button') {
                $label = $this->compactTemplateText((string) ($block['label'] ?? ''));
                if (str_contains($label, 'urmarestecomanda')) {
                    $hasTrackingButton = true;
                }
            }
        }

        return $hasOrderStatusSummary && $hasEstimatedDelivery && $hasTrackingButton;
    }

    private function isProcessingTemplateBlocksV2(array $blocks): bool
    {
        $hasClockHeader = false;
        $hasStoreHeader = false;
        $hasOrderStatusSummary = false;
        $hasFooter = false;
        $hasSummarySection = false;

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            $content = $this->compactTemplateText((string) ($block['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if ($type === 'header') {
                if (str_contains($content, '⏱')) {
                    $hasClockHeader = true;
                }
                if (str_contains($content, '{{store_name}}')) {
                    $hasStoreHeader = true;
                }
            }
            if ($type === 'text') {
                if (str_contains($content, '{{order_number}}') && str_contains($content, '{{order_status}}')) {
                    $hasOrderStatusSummary = true;
                }
                if (str_contains($content, '{{customer_email}}')) {
                    $hasFooter = true;
                }
                if (str_contains($content, 'rezumatcomanda') || str_contains($content, '{{order_summary}}')) {
                    $hasSummarySection = true;
                }
            }
        }

        return $hasClockHeader && $hasStoreHeader && $hasOrderStatusSummary && $hasFooter && !$hasSummarySection;
    }

    private function processingEcommerceDefaultBlocks(): array
    {
        return [
            [
                'type' => 'header',
                'content' => '⏱',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#ea9c18',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'header',
                'content' => '{{store_name}}',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Bună, {{customer_name}}!\n\nVești bune! Comanda ta este acum în curs de procesare. Echipa noastră pregătește cu grijă produsele tale.",
                'align' => 'left',
                'background' => '#ffffff',
                'text_color' => '#44606b',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Nr. comandă: #{{order_number}}\nStatus: {{order_status}}",
                'align' => 'left',
                'background' => '#f4f7f4',
                'text_color' => '#16323f',
                'block_background' => '#f4f7f4',
            ],
            [
                'type' => 'text',
                'content' => "Rezumat Comandă:\n{{order_summary}}",
                'align' => 'left',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'divider',
                'line_color' => '#eceff1',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "© {{year}} {{store_name}}. Toate drepturile rezervate.\nAcest email a fost trimis la {{customer_email}}",
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#5f7680',
                'block_background' => '#ffffff',
            ],
        ];
    }

    private function newOrderEcommerceDefaultBlocks(): array
    {
        return [
            [
                'type' => 'header',
                'content' => '📦',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#2f8d5b',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'header',
                'content' => '{{store_name}}',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Bună, {{customer_name}}! 🌿\n\nÎți mulțumim că ai ales {{store_name}}! Comanda ta a fost înregistrată și va fi procesată în curând.",
                'align' => 'left',
                'background' => '#ffffff',
                'text_color' => '#44606b',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Nr. comandă: #{{order_number}}\nData: {{order_date}}",
                'align' => 'left',
                'background' => '#f4f7f4',
                'text_color' => '#16323f',
                'block_background' => '#f4f7f4',
            ],
            [
                'type' => 'text',
                'content' => "Rezumat Comandă:\n{{order_summary}}",
                'align' => 'left',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Total: {{order_total}}",
                'align' => 'right',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'divider',
                'line_color' => '#eceff1',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "© {{year}} {{store_name}}. Toate drepturile rezervate.\nAcest email a fost trimis la {{customer_email}}",
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#5f7680',
                'block_background' => '#ffffff',
            ],
        ];
    }

    private function shippedEcommerceDefaultBlocks(): array
    {
        return [
            [
                'type' => 'header',
                'content' => '🚚',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#2f80ed',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'header',
                'content' => '{{store_name}}',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Bună, {{customer_name}}! 🚚\n\nComanda ta a fost expediată și este pe drum spre tine! Poți urmări coletul folosind linkul de mai jos.",
                'align' => 'left',
                'background' => '#ffffff',
                'text_color' => '#44606b',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Nr. comandă: #{{order_number}}\nAWB: {{awb}}\nCurier: {{courier_name}}\nLivrare estimată: {{estimated_delivery}}",
                'align' => 'left',
                'background' => '#f4f7f4',
                'text_color' => '#16323f',
                'block_background' => '#f4f7f4',
            ],
            [
                'type' => 'text',
                'content' => "Rezumat Comandă:\n{{order_summary}}",
                'align' => 'left',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'button',
                'label' => 'Urmărește coletul',
                'url' => '{{tracking_url}}',
                'align' => 'center',
                'block_background' => '#ffffff',
                'background' => '#2f80ed',
                'text_color' => '#ffffff',
                'radius' => 14,
            ],
            [
                'type' => 'divider',
                'line_color' => '#eceff1',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "© {{year}} {{store_name}}. Toate drepturile rezervate.\nAcest email a fost trimis la {{customer_email}}",
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#5f7680',
                'block_background' => '#ffffff',
            ],
        ];
    }

    private function deliveredEcommerceDefaultBlocks(): array
    {
        return [
            [
                'type' => 'header',
                'content' => '✅',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#2f8d5b',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'header',
                'content' => '{{store_name}}',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Bună, {{customer_name}}! 🎉\n\nComanda ta a fost livrată cu succes! Sperăm că produsele noastre naturale îți vor aduce bucurie.",
                'align' => 'left',
                'background' => '#ffffff',
                'text_color' => '#44606b',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Nr. comandă: #{{order_number}}\nLivrat pe: {{order_date}}",
                'align' => 'left',
                'background' => '#f4f7f4',
                'text_color' => '#16323f',
                'block_background' => '#f4f7f4',
            ],
            [
                'type' => 'button',
                'label' => 'Lasă o recenzie',
                'url' => '{{order_action_url}}',
                'align' => 'center',
                'block_background' => '#ffffff',
                'background' => '#2f8d5b',
                'text_color' => '#ffffff',
                'radius' => 14,
            ],
            [
                'type' => 'divider',
                'line_color' => '#eceff1',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "© {{year}} {{store_name}}. Toate drepturile rezervate.\nAcest email a fost trimis la {{customer_email}}",
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#5f7680',
                'block_background' => '#ffffff',
            ],
        ];
    }

    private function cancelledEcommerceDefaultBlocks(): array
    {
        return [
            [
                'type' => 'header',
                'content' => '✕',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#ef4444',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'header',
                'content' => '{{store_name}}',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Bună, {{customer_name}},\n\nComanda ta a fost anulată conform solicitării. Dacă ai plătit online, suma va fi returnată în 3-5 zile lucrătoare.",
                'align' => 'left',
                'background' => '#ffffff',
                'text_color' => '#44606b',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Nr. comandă: #{{order_number}}\nSumă returnată: {{order_total}}",
                'align' => 'left',
                'background' => '#f4f7f4',
                'text_color' => '#16323f',
                'block_background' => '#f4f7f4',
            ],
            [
                'type' => 'button',
                'label' => 'Contactează-ne',
                'url' => '{{order_action_url}}',
                'align' => 'center',
                'block_background' => '#ffffff',
                'background' => '#ef4444',
                'text_color' => '#ffffff',
                'radius' => 14,
            ],
            [
                'type' => 'divider',
                'line_color' => '#eceff1',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "© {{year}} {{store_name}}. Toate drepturile rezervate.\nAcest email a fost trimis la {{customer_email}}",
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#5f7680',
                'block_background' => '#ffffff',
            ],
        ];
    }

    private function abandonedCartEcommerceDefaultBlocks(): array
    {
        return [
            [
                'type' => 'header',
                'content' => '🛒',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#eaa325',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'header',
                'content' => '{{store_name}}',
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Bună, {{customer_name}}! 👋\n\nAm observat că ai lăsat câteva produse în coș. Le păstrăm pentru tine, dar nu pentru mult timp!",
                'align' => 'left',
                'background' => '#ffffff',
                'text_color' => '#44606b',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "Produse:\n{{cart_summary}}",
                'align' => 'left',
                'background' => '#f4f7f4',
                'text_color' => '#16323f',
                'block_background' => '#f4f7f4',
            ],
            [
                'type' => 'text',
                'content' => "Total: {{cart_total}}",
                'align' => 'right',
                'background' => '#ffffff',
                'text_color' => '#0f2532',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'button',
                'label' => 'Finalizează comanda',
                'url' => '{{cart_action_url}}',
                'align' => 'center',
                'block_background' => '#ffffff',
                'background' => '#eaa325',
                'text_color' => '#ffffff',
                'radius' => 14,
            ],
            [
                'type' => 'divider',
                'line_color' => '#eceff1',
                'block_background' => '#ffffff',
            ],
            [
                'type' => 'text',
                'content' => "© {{year}} {{store_name}}. Toate drepturile rezervate.\nAcest email a fost trimis la {{customer_email}}",
                'align' => 'center',
                'background' => '#ffffff',
                'text_color' => '#5f7680',
                'block_background' => '#ffffff',
            ],
        ];
    }

    private function isShippedTemplateBlocksV1(array $blocks): bool
    {
        $hasTruckHeader = false;
        $hasStoreHeader = false;
        $hasShippedSummary = false;
        $hasTrackingButton = false;
        $hasFooter = false;
        $hasSummarySection = false;

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            $content = $this->compactTemplateText((string) ($block['content'] ?? ''));
            if ($type === 'header') {
                if (str_contains($content, '🚚')) {
                    $hasTruckHeader = true;
                }
                if (str_contains($content, '{{store_name}}')) {
                    $hasStoreHeader = true;
                }
            }
            if ($type === 'text') {
                if (
                    str_contains($content, '{{order_number}}')
                    && str_contains($content, '{{awb}}')
                    && str_contains($content, '{{courier_name}}')
                ) {
                    $hasShippedSummary = true;
                }
                if (str_contains($content, '{{customer_email}}')) {
                    $hasFooter = true;
                }
                if (str_contains($content, 'rezumatcomanda') || str_contains($content, '{{order_summary}}')) {
                    $hasSummarySection = true;
                }
            }
            if ($type === 'button') {
                $label = $this->compactTemplateText((string) ($block['label'] ?? ''));
                if (str_contains($label, 'urmarestecoletul')) {
                    $hasTrackingButton = true;
                }
            }
        }

        return $hasTruckHeader
            && $hasStoreHeader
            && $hasShippedSummary
            && $hasTrackingButton
            && $hasFooter
            && !$hasSummarySection;
    }

    private function compactTemplateText(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = strtr($value, [
            'ă' => 'a',
            'â' => 'a',
            'î' => 'i',
            'ș' => 's',
            'ş' => 's',
            'ț' => 't',
            'ţ' => 't',
        ]);
        $value = preg_replace('/\s+/', '', $value) ?? '';
        return trim($value);
    }

    private function defaultBlocksFromText(string $text): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            $text = 'Conținut template.';
        }

        return [[
            'type' => 'text',
            'content' => $text,
            'align' => 'left',
            'background' => '#ffffff',
            'text_color' => '#1f2937',
        ]];
    }

    private function maybeSendCompletedAwbEmail(PDO $db, int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $this->ensureOptionalSchema($db);

        $stmt = $db->prepare(
            'SELECT id, order_number, status, billing_first_name, billing_last_name, billing_email,
                    fan_awb, fan_tracking_url, completed_awb_email_sent_at
             FROM orders
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch() ?: null;
        if (!is_array($order)) {
            return;
        }

        if ((string) ($order['status'] ?? '') !== 'completed') {
            return;
        }

        if (trim((string) ($order['fan_awb'] ?? '')) === '') {
            return;
        }

        if (trim((string) ($order['billing_email'] ?? '')) === '') {
            return;
        }

        if (!empty($order['completed_awb_email_sent_at'])) {
            return;
        }

        if (trim((string) ($order['fan_tracking_url'] ?? '')) === '') {
            $order['fan_tracking_url'] = FanCourierGateway::trackingUrl((string) $order['fan_awb']);
        }

        $settings = Settings::all($db);

        try {
            OrderMailer::sendCompletedAwb($order, $settings, $db);

            $ok = $db->prepare(
                'UPDATE orders
                 SET completed_awb_email_sent_at = NOW(),
                     completed_awb_email_error = NULL
                 WHERE id = :id'
            );
            $ok->execute(['id' => $orderId]);
        } catch (RuntimeException $exception) {
            $fail = $db->prepare(
                'UPDATE orders
                 SET completed_awb_email_error = :error
                 WHERE id = :id'
            );
            $fail->execute([
                'error' => substr($exception->getMessage(), 0, 1000),
                'id' => $orderId,
            ]);
        }
    }

    private function autoGenerateFanAwbIfEligible(PDO $db, int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $settings = Settings::all($db);
        if ((string) ($settings['fan_awb_auto'] ?? '0') !== '1') {
            return;
        }

        $credentials = $this->fanCredentialsFromSettings($settings);
        if ($credentials === null) {
            return;
        }

        $order = $this->loadOrderForFan($db, $orderId);
        if (!is_array($order)) {
            return;
        }

        if (trim((string) ($order['fan_awb'] ?? '')) !== '') {
            return;
        }

        $payload = $this->buildFanShipmentPayload($order, $settings, $credentials['client_id']);

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
                 WHERE id = :id'
            );
            $stmt->execute([
                'fan_awb' => $awb,
                'fan_tracking_url' => FanCourierGateway::trackingUrl($awb),
                'fan_tracking_status' => 'AWB generat automat',
                'id' => $orderId,
            ]);

            EmailAutomation::sendOrderTemplateById($db, $settings, $orderId, 'shipped');
        } catch (RuntimeException) {
            // Keep status update successful even if FAN API is temporarily unavailable.
        }
    }

    private function usersSettingsTab(): string
    {
        $tab = trim((string) ($_GET['tab'] ?? 'settings'));
        if (!in_array($tab, ['settings', 'points'], true)) {
            return 'settings';
        }
        return $tab;
    }

    private function ensureFormSecurityLogsSchema(PDO $db): void
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

    private function ensureOptionalSchema(PDO $db): void
    {
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN ad_source VARCHAR(50) DEFAULT NULL');
        } catch (Throwable) {
        }
        // AWB-urile înlocuite, cu momentul și operatorul. Un AWB emis greșit se
        // reemite, iar `fan_awb` ține doar ultimul; fără istoricul ăsta numărul
        // vechi s-ar pierde, deși coletul lui există în continuare la FAN.
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN fan_awb_inlocuite TEXT DEFAULT NULL');
        } catch (Throwable) {
        }
        // Reducere comercială acordată manual pe o comandă deja plasată. Se ține
        // separat de cupon și de puncte: un client poate avea și cupon de pe
        // site, și o reducere negociată la telefon, iar pe factură apar distinct.
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN manual_discount DECIMAL(10,2) NOT NULL DEFAULT 0');
        } catch (Throwable) {
        }
        try {
            // Procentul se reține doar ca să se poată reafișa cum a fost dat;
            // valoarea care contează la calcul e suma.
            $db->exec('ALTER TABLE orders ADD COLUMN manual_discount_percent DECIMAL(5,2) DEFAULT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN manual_discount_reason VARCHAR(190) DEFAULT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN ad_click_id VARCHAR(255) DEFAULT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE admins ADD COLUMN role VARCHAR(50) NOT NULL DEFAULT "administrator_general" AFTER password_hash');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE admins SET role = "administrator_general" WHERE role IS NULL OR role = ""');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE admins ADD COLUMN roles_json LONGTEXT DEFAULT NULL AFTER role');
        } catch (Throwable) {
        }
        try {
            $rows = $db->query('SELECT id, role, roles_json FROM admins')->fetchAll() ?: [];
            $update = $db->prepare('UPDATE admins SET role = :role, roles_json = :roles_json WHERE id = :id');
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $roles = Auth::rolesFromStorage(
                    (string) ($row['role'] ?? Auth::ROLE_GENERAL),
                    $row['roles_json'] ?? null
                );
                $role = Auth::primaryRole($roles);
                $rolesJson = json_encode($roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($rolesJson) || $rolesJson === '') {
                    continue;
                }
                $update->execute([
                    'id' => (int) ($row['id'] ?? 0),
                    'role' => $role,
                    'roles_json' => $rolesJson,
                ]);
            }
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

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS product_categories (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL UNIQUE,
                    slug VARCHAR(140) NOT NULL UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )'
            );
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
            $db->exec('UPDATE products SET gallery_images_json = gallery_json WHERE (gallery_images_json IS NULL OR gallery_images_json = "") AND gallery_json IS NOT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE products SET gallery_images_json = image_gallery_json WHERE (gallery_images_json IS NULL OR gallery_images_json = "") AND image_gallery_json IS NOT NULL');
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
            $db->exec(
                'CREATE TABLE IF NOT EXISTS product_extra_fields (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    field_key VARCHAR(120) NOT NULL UNIQUE,
                    field_type VARCHAR(30) NOT NULL DEFAULT "textarea",
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
                'CREATE TABLE IF NOT EXISTS blog_authors (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    slug VARCHAR(190) NOT NULL UNIQUE,
                    bio TEXT DEFAULT NULL,
                    avatar_url VARCHAR(255) DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )'
            );
        } catch (Throwable) {
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS blog_templates (
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
                'CREATE TABLE IF NOT EXISTS blog_posts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL UNIQUE,
                    excerpt TEXT DEFAULT NULL,
                    category VARCHAR(190) DEFAULT NULL,
                    content LONGTEXT NOT NULL,
                    reading_minutes INT UNSIGNED NOT NULL DEFAULT 1,
                    published_at DATETIME NOT NULL,
                    is_published TINYINT(1) NOT NULL DEFAULT 0,
                    template_id INT UNSIGNED DEFAULT NULL,
                    author_id INT UNSIGNED DEFAULT NULL,
                    featured_image_url VARCHAR(255) DEFAULT NULL,
                    deleted_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_blog_posts_published (is_published, published_at),
                    KEY idx_blog_posts_deleted (deleted_at)
                )'
            );
        } catch (Throwable) {
        }
        foreach ([
            'ALTER TABLE blog_posts ADD COLUMN event_start_date DATE DEFAULT NULL AFTER category',
            'ALTER TABLE blog_posts ADD COLUMN event_end_date DATE DEFAULT NULL AFTER event_start_date',
            'ALTER TABLE blog_posts ADD COLUMN event_price VARCHAR(120) DEFAULT NULL AFTER event_end_date',
            'ALTER TABLE blog_posts ADD COLUMN event_ticket_url VARCHAR(500) DEFAULT NULL AFTER event_price',
            'ALTER TABLE blog_posts ADD COLUMN event_location VARCHAR(190) DEFAULT NULL AFTER event_ticket_url',
            'ALTER TABLE blog_posts ADD COLUMN category_id INT UNSIGNED DEFAULT NULL AFTER category',
            'ALTER TABLE blog_posts ADD COLUMN video_url VARCHAR(500) DEFAULT NULL AFTER featured_image_url',
        ] as $blogPostsAlter) {
            try {
                $db->exec($blogPostsAlter);
            } catch (Throwable) {
            }
        }
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS blog_categories (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(150) NOT NULL UNIQUE,
                    slug VARCHAR(170) NOT NULL UNIQUE,
                    sort_order INT NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )'
            );
            // Seed doar la prima creare (tabelă goală); altfel categoriile șterse ar reapărea.
            $blogCategoriesCount = (int) ($db->query('SELECT COUNT(*) FROM blog_categories')->fetchColumn() ?: 0);
            if ($blogCategoriesCount === 0) {
                $blogCategorySeed = $db->prepare('INSERT IGNORE INTO blog_categories (name, slug, sort_order) VALUES (:name, :slug, :sort_order)');
                foreach ([
                    ['Noutăți', 'noutati', 1],
                    ['Evenimente', 'evenimente', 2],
                    ['Info pacienți', 'info-pacienti', 3],
                    ['Info medici', 'info-medici', 4],
                ] as $blogCategorySeedRow) {
                    $blogCategorySeed->execute([
                        'name' => $blogCategorySeedRow[0],
                        'slug' => $blogCategorySeedRow[1],
                        'sort_order' => $blogCategorySeedRow[2],
                    ]);
                }
            }
        } catch (Throwable) {
        }
        // Legătură many-to-many: o postare poate fi în mai multe categorii.
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS blog_post_categories (
                    post_id INT UNSIGNED NOT NULL,
                    category_id INT UNSIGNED NOT NULL,
                    PRIMARY KEY (post_id, category_id),
                    KEY idx_bpc_category (category_id)
                )'
            );
            // Backfill o singură dată din coloana legacy category_id, pentru postările existente.
            $db->exec(
                'INSERT IGNORE INTO blog_post_categories (post_id, category_id)
                 SELECT id, category_id FROM blog_posts
                 WHERE category_id IS NOT NULL AND category_id > 0'
            );
        } catch (Throwable) {
        }
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
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS email_send_history (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id INT UNSIGNED DEFAULT NULL,
                    email_type VARCHAR(80) NOT NULL,
                    source VARCHAR(80) NOT NULL DEFAULT "system",
                    recipient VARCHAR(190) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT "sent",
                    error_message TEXT DEFAULT NULL,
                    provider VARCHAR(30) DEFAULT NULL,
                    meta_json LONGTEXT DEFAULT NULL,
                    sent_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_email_send_history_created (created_at),
                    KEY idx_email_send_history_type (email_type),
                    KEY idx_email_send_history_status (status),
                    KEY idx_email_send_history_order (order_id)
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

        // Backward-compatible migrations from older draft schema names.
        try {
            $db->exec('ALTER TABLE blog_posts ADD COLUMN category VARCHAR(190) DEFAULT NULL AFTER excerpt');
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
            $db->exec('ALTER TABLE product_extra_fields ADD COLUMN placeholder VARCHAR(255) DEFAULT NULL AFTER field_type');
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
                'CREATE TABLE IF NOT EXISTS gallery_folders (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL UNIQUE,
                    slug VARCHAR(140) NOT NULL UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )'
            );
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
            $db->exec('ALTER TABLE pages ADD COLUMN css_content LONGTEXT NULL AFTER html_content');
        } catch (Throwable) {
        }

        try {
            $db->exec('ALTER TABLE pages ADD COLUMN js_content LONGTEXT NULL AFTER css_content');
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
        LoyaltyService::ensureSchema($db);

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
            $db->exec('ALTER TABLE orders ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER completed_awb_email_error');
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
                "UPDATE pages
                 SET deleted_at = NULL, is_published = 1
                 WHERE slug = 'acorduri-gdpr' AND deleted_at IS NOT NULL"
            );
        } catch (Throwable) {
        }
        try {
            $db->exec(
                "INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published)
                 SELECT 'Acorduri GDPR', 'acorduri-gdpr', '<section class=\"gdpr-agreements-page\">{{gdpr_agreements_form}}</section>', '', '', 1
                 FROM DUAL
                 WHERE NOT EXISTS (
                    SELECT 1 FROM pages WHERE slug = 'acorduri-gdpr'
                 )"
            );
        } catch (Throwable) {
        }

        NewsletterService::ensureSchema($db);
        EmailAutomation::ensureSchema($db);
        AdminActivityLog::ensureSchema($db);
    }

    public function activityLog(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!Auth::isGeneralAdmin()) {
            http_response_code(403);
            echo 'Acces interzis.';
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin');
            return;
        }
        $this->ensureOptionalSchema($db);
        $actionFilter = trim((string) ($_GET['action'] ?? ''));
        $entries = AdminActivityLog::recent($db, 500, $actionFilter !== '' ? $actionFilter : null);

        View::render('admin/activity-log', [
            'title'        => 'Jurnal activitate admin',
            'entries'      => $entries,
            'actionFilter' => $actionFilter,
        ], 'admin/layout');
    }

    private function normalizeHexColor(string $value, string $fallback): string
    {
        $value = trim($value);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
            return strtolower($value);
        }
        return strtolower($fallback);
    }

    private function refreshCacheAfterPublicContentChange(?PDO $db = null): void
    {
        ResponseCache::purgePageCache();
        $db = $db instanceof PDO ? $db : $this->db();
        if (!$db instanceof PDO) {
            return;
        }

        $settings = Settings::all($db);
        $versioningMode = ResponseCache::normalizeAssetVersioningMode((string) ($settings['cache_assets_versioning_mode'] ?? 'none'));
        if ($versioningMode !== 'none') {
            $newToken = ResponseCache::generateAssetVersionToken();
            Settings::save($db, [
                'cache_assets_version_token' => $newToken,
            ]);
            $settings['cache_assets_version_token'] = $newToken;
        }
        $this->syncAssetCacheHtaccess($settings);
    }

    /**
     * @param array<int, mixed> $paths
     * @param array<int, mixed> $ttls
     */
    private function normalizeCachePageRulesFromRequest(array $paths, array $ttls): array
    {
        $rawRules = [];
        foreach ($paths as $idx => $path) {
            $rawRules[] = [
                'path_pattern' => (string) $path,
                'ttl_seconds' => $ttls[$idx] ?? 1800,
            ];
        }

        return ResponseCache::normalizeRules($rawRules);
    }

    private function syncAssetCacheHtaccess(array $settings): void
    {
        $assetsDir = __DIR__ . '/../../../public/assets';
        $uploadsDir = __DIR__ . '/../../../public/uploads';
        $assetsTtl = ResponseCache::normalizeTtlSeconds($settings['cache_assets_ttl_seconds'] ?? 86400, 86400);
        $uploadsTtl = ResponseCache::normalizeTtlSeconds($settings['cache_uploads_ttl_seconds'] ?? 604800, 604800);
        $versioningMode = ResponseCache::normalizeAssetVersioningMode((string) ($settings['cache_assets_versioning_mode'] ?? 'none'));
        $etagEnabled = (string) ($settings['cache_assets_etag_enabled'] ?? '1') === '1';
        $enabled = (string) ($settings['cache_assets_enabled'] ?? '0') === '1';

        $assetsContent = $this->renderAssetCacheHtaccess($enabled, $assetsTtl, $versioningMode, $etagEnabled);
        $uploadsContent = $this->renderAssetCacheHtaccess($enabled, $uploadsTtl, $versioningMode, $etagEnabled);

        $targets = [
            $assetsDir . '/.htaccess' => $assetsContent,
            $uploadsDir . '/.htaccess' => $uploadsContent,
        ];
        foreach ($targets as $path => $content) {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                continue;
            }
            if ($content === '') {
                if (is_file($path)) {
                    @unlink($path);
                }
                continue;
            }
            @file_put_contents($path, $content);
        }
    }

    private function renderAssetCacheHtaccess(bool $enabled, int $ttlSeconds, string $versioningMode, bool $etagEnabled): string
    {
        if (!$enabled) {
            return '';
        }

        $cacheControl = 'public, max-age=' . max(60, $ttlSeconds);
        if ($versioningMode !== 'none') {
            $cacheControl .= ', immutable';
        }

        $lines = [
            '# Auto-generated by Admin > Setari magazin > Caching',
            '<IfModule mod_headers.c>',
            '    Header set Cache-Control "' . $cacheControl . '"',
        ];
        if (!$etagEnabled) {
            $lines[] = '    Header unset ETag';
            $lines[] = '    FileETag None';
        }
        $lines[] = '</IfModule>';
        $lines[] = '<IfModule mod_expires.c>';
        $lines[] = '    ExpiresActive On';
        $lines[] = '    ExpiresDefault "access plus ' . max(60, $ttlSeconds) . ' seconds"';
        $lines[] = '</IfModule>';

        return implode("\n", $lines) . "\n";
    }

    private function normalizeFloatingCartExcludedUrls(string $raw): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $normalized = [];

        foreach ($lines as $line) {
            $candidate = trim((string) $line);
            if ($candidate === '') {
                continue;
            }

            if (preg_match('/^https?:\/\//i', $candidate) === 1) {
                $urlPath = parse_url($candidate, PHP_URL_PATH);
                if (!is_string($urlPath) || trim($urlPath) === '') {
                    continue;
                }
                $candidate = trim($urlPath);
            }

            if (!str_starts_with($candidate, '/')) {
                $candidate = '/' . ltrim($candidate, '/');
            }

            $candidate = rtrim($candidate, '/');
            if ($candidate === '') {
                $candidate = '/';
            }

            $normalized[] = $candidate;
        }

        return implode("\n", array_values(array_unique($normalized)));
    }

    private function importFanLocalitiesFromUploadedFile(PDO $db, mixed $file): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'Selectează fișierul CSV/XLSX cu localități FAN.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload eșuat. Încearcă din nou.'];
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::FAN_LOCALITIES_UPLOAD_MAX_SIZE) {
            return ['ok' => false, 'message' => 'Fișier invalid sau prea mare (max 12MB).'];
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Fișier invalid.'];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            return ['ok' => false, 'message' => 'Format neacceptat. Folosește CSV sau XLSX.'];
        }

        $rows = $ext === 'csv'
            ? $this->fanLocalitiesRowsFromCsv($tmpPath)
            : $this->fanLocalitiesRowsFromXlsx($tmpPath);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Nu am găsit rânduri valide în fișier (coloane localitate + județ).'];
        }

        $this->ensureFanLocalitiesSchema($db);

        $now = date('Y-m-d H:i:s');
        $inserted = 0;
        $updated = 0;
        $seen = [];

        $stmt = $db->prepare(
            'INSERT INTO fan_localities (county, locality, county_norm, locality_norm, created_at, updated_at)
             VALUES (:county, :locality, :county_norm, :locality_norm, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                county = VALUES(county),
                locality = VALUES(locality),
                updated_at = VALUES(updated_at)'
        );
        $existsStmt = $db->prepare(
            'SELECT id FROM fan_localities WHERE county_norm = :county_norm AND locality_norm = :locality_norm LIMIT 1'
        );

        foreach ($rows as $row) {
            $county = trim((string) ($row['county'] ?? ''));
            $locality = trim((string) ($row['locality'] ?? ''));
            if ($county === '' || $locality === '') {
                continue;
            }
            $countyNorm = $this->normalizeFanLocalityToken($county);
            $localityNorm = $this->normalizeFanLocalityToken($locality);
            if ($countyNorm === '' || $localityNorm === '') {
                continue;
            }
            $pair = $countyNorm . '|' . $localityNorm;
            if (isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;

            $existsStmt->execute([
                'county_norm' => $countyNorm,
                'locality_norm' => $localityNorm,
            ]);
            $exists = (bool) $existsStmt->fetchColumn();

            $stmt->execute([
                'county' => $county,
                'locality' => $locality,
                'county_norm' => $countyNorm,
                'locality_norm' => $localityNorm,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($exists) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        if (($inserted + $updated) <= 0) {
            return ['ok' => false, 'message' => 'Import fără rezultate. Verifică antetul și conținutul fișierului.'];
        }

        return [
            'ok' => true,
            'message' => 'Localități FAN importate: ' . ($inserted + $updated) . ' (noi: ' . $inserted . ', actualizate: ' . $updated . ').',
        ];
    }


    private function importWordPressUsersFromUploadedFile(PDO $db, mixed $file): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'Selectează fișierul CSV/XLSX cu utilizatori WordPress.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload eșuat. Încearcă din nou.'];
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::USERS_IMPORT_UPLOAD_MAX_SIZE) {
            return ['ok' => false, 'message' => 'Fișier invalid sau prea mare (max 12MB).'];
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Fișier invalid.'];
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            return ['ok' => false, 'message' => 'Format neacceptat. Folosește CSV sau XLSX.'];
        }

        $rows = $ext === 'csv'
            ? $this->wordpressUsersRowsFromCsv($tmpPath)
            : $this->wordpressUsersRowsFromXlsx($tmpPath);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Nu am găsit rânduri valide în fișier (coloane email + hash parolă recomandat).'];
        }

        $existsStmt = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $upsertStmt = $db->prepare(
            'INSERT INTO users (first_name, last_name, email, phone, birth_date, gender, password_hash, created_at)
             VALUES (:first_name, :last_name, :email, :phone, :birth_date, :gender, :password_hash, :created_at)
             ON DUPLICATE KEY UPDATE
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                phone = VALUES(phone),
                birth_date = VALUES(birth_date),
                gender = VALUES(gender),
                password_hash = CASE
                    WHEN VALUES(password_hash) IS NOT NULL AND VALUES(password_hash) <> "" THEN VALUES(password_hash)
                    ELSE password_hash
                END'
        );

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $skipped++;
                continue;
            }

            $passwordHash = trim((string) ($row['password_hash'] ?? ''));
            if ($passwordHash === '') {
                try {
                    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                } catch (Throwable) {
                    $passwordHash = password_hash(uniqid('import_', true), PASSWORD_BCRYPT);
                }
            }

            $firstName = trim((string) ($row['first_name'] ?? ''));
            $lastName = trim((string) ($row['last_name'] ?? ''));
            if ($firstName === '') {
                $firstName = 'Client';
            }
            if ($lastName === '') {
                $lastName = 'WordPress';
            }

            $rawGender = strtolower(trim((string) ($row['gender'] ?? '')));
            $gender = match ($rawGender) {
                'f', 'female', 'feminin', 'femeie' => 'feminin',
                'm', 'male', 'masculin', 'barbat' => 'masculin',
                default => null,
            };
            $rawBirthDate = trim((string) ($row['birth_date'] ?? ''));
            $birthDate = null;
            if ($rawBirthDate !== '') {
                $birthDateTs = strtotime($rawBirthDate);
                if ($birthDateTs !== false) {
                    $birthDate = date('Y-m-d', $birthDateTs);
                }
            }
            $createdAtRaw = trim((string) ($row['created_at'] ?? ''));
            $createdAt = date('Y-m-d H:i:s');
            if ($createdAtRaw !== '') {
                $createdAtTs = strtotime($createdAtRaw);
                if ($createdAtTs !== false) {
                    $createdAt = date('Y-m-d H:i:s', $createdAtTs);
                }
            }

            $existsStmt->execute(['email' => $email]);
            $exists = (bool) $existsStmt->fetchColumn();

            $phone = trim((string) ($row['phone'] ?? ''));
            $upsertStmt->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'birth_date' => $birthDate,
                'gender' => $gender,
                'password_hash' => $passwordHash,
                'created_at' => $createdAt,
            ]);

            if ($exists) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        return [
            'ok' => true,
            'message' => 'Import utilizatori finalizat: ' . $inserted . ' noi, ' . $updated . ' actualizați, ' . $skipped . ' săriți.',
        ];
    }

    private function importLoyaltyPointsFromUploadedFile(PDO $db, mixed $file): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'Selectează fișierul CSV/XLSX cu puncte loialitate.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload eșuat. Încearcă din nou.'];
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::LOYALTY_POINTS_IMPORT_UPLOAD_MAX_SIZE) {
            return ['ok' => false, 'message' => 'Fișier invalid sau prea mare (max 12MB).'];
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Fișier invalid.'];
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            return ['ok' => false, 'message' => 'Format neacceptat. Folosește CSV sau XLSX.'];
        }

        $rows = $ext === 'csv'
            ? $this->loyaltyPointsRowsFromCsv($tmpPath)
            : $this->loyaltyPointsRowsFromXlsx($tmpPath);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Nu am găsit rânduri valide în fișier (email + puncte).'];
        }

        $selectStmt = $db->prepare('SELECT id, loyalty_points FROM users WHERE LOWER(email) = :email LIMIT 1');
        $updateStmt = $db->prepare('UPDATE users SET loyalty_points = :points WHERE id = :id');
        $txStmt = $db->prepare(
            'INSERT INTO loyalty_points_transactions (user_id, order_id, admin_id, tx_type, points_delta, balance_after, reason, meta_json)
             VALUES (:user_id, NULL, :admin_id, :tx_type, :points_delta, :balance_after, :reason, :meta_json)'
        );

        $updated = 0;
        $skipped = 0;
        $notFound = 0;
        $notFoundEmails = [];
        $seenEmails = [];
        $adminId = Auth::id();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $skipped++;
                continue;
            }
            // On duplicate emails in the import file, keep only first occurrence.
            if (isset($seenEmails[$email])) {
                continue;
            }
            $seenEmails[$email] = true;

            $points = (int) round((float) ($row['points'] ?? 0));
            if ($points < 0) {
                $points = 0;
            }

            $selectStmt->execute(['email' => $email]);
            $user = $selectStmt->fetch() ?: null;
            if (!is_array($user)) {
                $notFound++;
                $notFoundEmails[$email] = $email;
                continue;
            }

            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                $skipped++;
                continue;
            }
            $currentPoints = (int) ($user['loyalty_points'] ?? 0);
            $delta = $points - $currentPoints;

            $updateStmt->execute([
                'points' => $points,
                'id' => $userId,
            ]);
            $updated++;

            if ($delta !== 0) {
                $meta = json_encode([
                    'source' => 'import_loyalty_points',
                    'email' => $email,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $txStmt->execute([
                    'user_id' => $userId,
                    'admin_id' => $adminId,
                    'tx_type' => LoyaltyService::TX_ADMIN_ADJUST,
                    'points_delta' => $delta,
                    'balance_after' => $points,
                    'reason' => 'Import puncte loialitate',
                    'meta_json' => $meta !== false ? $meta : null,
                ]);
            }
        }

        if ($updated <= 0) {
            return [
                'ok' => false,
                'message' => 'Nu s-au actualizat utilizatori. Verifică email-urile și coloanele din fișier.',
                'not_found_emails' => array_values($notFoundEmails),
            ];
        }

        return [
            'ok' => true,
            'message' => 'Import puncte finalizat: ' . $updated . ' actualizați, ' . $notFound . ' email-uri negăsite, ' . $skipped . ' rânduri invalide.',
            'not_found_emails' => array_values($notFoundEmails),
        ];
    }

    private function importBlogPostsFromUploadedFile(PDO $db, mixed $file): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'Selectează fișierul CSV/XLSX cu articole blog.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload eșuat. Încearcă din nou.'];
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::BLOG_POSTS_IMPORT_UPLOAD_MAX_SIZE) {
            return ['ok' => false, 'message' => 'Fișier invalid sau prea mare (max 12MB).'];
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Fișier invalid.'];
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            return ['ok' => false, 'message' => 'Format neacceptat. Folosește CSV sau XLSX.'];
        }

        $rows = $ext === 'csv'
            ? $this->blogPostsRowsFromCsv($tmpPath)
            : $this->blogPostsRowsFromXlsx($tmpPath);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Nu am găsit rânduri valide în fișier (Title + Content/Slug).'];
        }

        $selectBySlugStmt = $db->prepare('SELECT id FROM blog_posts WHERE slug = :slug LIMIT 1');
        $supportsCategory = true;
        try {
            $insertStmt = $db->prepare(
                'INSERT INTO blog_posts (title, slug, excerpt, content, category, reading_minutes, published_at, is_published, featured_image_url, deleted_at)
                 VALUES (:title, :slug, :excerpt, :content, :category, :reading_minutes, :published_at, :is_published, :featured_image_url, NULL)'
            );
            $updateStmt = $db->prepare(
                'UPDATE blog_posts
                 SET title = :title,
                     excerpt = :excerpt,
                     content = :content,
                     category = :category,
                     reading_minutes = :reading_minutes,
                     published_at = :published_at,
                     is_published = :is_published,
                     featured_image_url = :featured_image_url,
                     deleted_at = NULL
                 WHERE id = :id'
            );
        } catch (Throwable) {
            $supportsCategory = false;
            $insertStmt = $db->prepare(
                'INSERT INTO blog_posts (title, slug, excerpt, content, reading_minutes, published_at, is_published, featured_image_url, deleted_at)
                 VALUES (:title, :slug, :excerpt, :content, :reading_minutes, :published_at, :is_published, :featured_image_url, NULL)'
            );
            $updateStmt = $db->prepare(
                'UPDATE blog_posts
                 SET title = :title,
                     excerpt = :excerpt,
                     content = :content,
                     reading_minutes = :reading_minutes,
                     published_at = :published_at,
                     is_published = :is_published,
                     featured_image_url = :featured_image_url,
                     deleted_at = NULL
                 WHERE id = :id'
            );
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $categoryDetected = 0;
        $seenInFile = [];

        try {
            $db->beginTransaction();

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    $skipped++;
                    continue;
                }

                $title = trim((string) ($row['title'] ?? ''));
                $slugSeed = trim((string) ($row['slug'] ?? ''));
                $content = (string) ($row['content'] ?? '');
                $excerpt = trim((string) ($row['excerpt'] ?? ''));
                $dateRaw = (string) ($row['date'] ?? '');
                $statusRaw = (string) ($row['status'] ?? '');
                $categoryRaw = trim((string) ($row['categories'] ?? ''));
                $featuredImage = trim((string) ($row['featured_image_url'] ?? ''));

                if ($title === '' && $slugSeed === '' && trim($content) === '' && $excerpt === '') {
                    $skipped++;
                    continue;
                }

                if (trim($content) === '' && $excerpt !== '') {
                    $content = '<p>' . nl2br(htmlspecialchars($excerpt, ENT_QUOTES)) . '</p>';
                }
                if (trim($content) === '' && $title !== '') {
                    $content = '<p>' . htmlspecialchars($title, ENT_QUOTES) . '</p>';
                }
                if (trim($content) === '') {
                    $skipped++;
                    continue;
                }

                if ($title === '') {
                    $contentText = trim(strip_tags($content));
                    $title = $contentText !== '' ? mb_substr($contentText, 0, 120) : 'Articol importat';
                }

                if ($slugSeed === '') {
                    $slugSeed = $title;
                }
                $slug = $this->slugify($slugSeed);
                if ($slug === '') {
                    $legacyId = trim((string) ($row['legacy_id'] ?? ''));
                    $slug = $this->slugify('articol-import-' . ($legacyId !== '' ? $legacyId : uniqid('', true)));
                }
                if ($slug === '') {
                    $slug = 'articol-import-' . time();
                }
                if (isset($seenInFile[$slug])) {
                    $slug = $this->nextAvailableBlogSlug($db, $slug);
                }
                $seenInFile[$slug] = true;

                if ($excerpt === '') {
                    $contentText = trim(strip_tags($content));
                    if ($contentText !== '') {
                        $excerpt = mb_substr($contentText, 0, 240) . (mb_strlen($contentText) > 240 ? '…' : '');
                    }
                }

                if ($featuredImage === '') {
                    $featuredImage = $this->extractFirstImageUrlFromHtml($content);
                }
                if ($featuredImage !== '' && mb_strlen($featuredImage) > 255) {
                    $featuredImage = mb_substr($featuredImage, 0, 255);
                }

                $publishedAt = $this->normalizeBlogImportDate($dateRaw);
                $isPublished = $this->normalizeBlogImportStatus($statusRaw);
                $readingMinutes = $this->estimateBlogReadingMinutes($content);
                $category = $this->normalizeBlogImportCategory($categoryRaw);
                if ($categoryRaw !== '') {
                    $categoryDetected++;
                }

                $selectBySlugStmt->execute(['slug' => $slug]);
                $existingId = (int) $selectBySlugStmt->fetchColumn();

                if ($existingId > 0) {
                    $params = [
                        'id' => $existingId,
                        'title' => $title,
                        'excerpt' => $excerpt !== '' ? $excerpt : null,
                        'content' => $content,
                        'reading_minutes' => $readingMinutes,
                        'published_at' => $publishedAt,
                        'is_published' => $isPublished,
                        'featured_image_url' => $featuredImage !== '' ? $featuredImage : null,
                    ];
                    if ($supportsCategory) {
                        $params['category'] = $category;
                    }
                    $updateStmt->execute($params);
                    $updated++;
                    continue;
                }

                $params = [
                    'title' => $title,
                    'slug' => $slug,
                    'excerpt' => $excerpt !== '' ? $excerpt : null,
                    'content' => $content,
                    'reading_minutes' => $readingMinutes,
                    'published_at' => $publishedAt,
                    'is_published' => $isPublished,
                    'featured_image_url' => $featuredImage !== '' ? $featuredImage : null,
                ];
                if ($supportsCategory) {
                    $params['category'] = $category;
                }
                $insertStmt->execute($params);
                $inserted++;
            }

            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'message' => 'Importul articolelor a eșuat. Verifică structura fișierului și încearcă din nou.'];
        }

        if (($inserted + $updated) <= 0) {
            return ['ok' => false, 'message' => 'Nu s-au importat articole. Verifică antetul și conținutul fișierului.'];
        }

        $categorySuffix = $supportsCategory
            ? ', categorii detectate: ' . $categoryDetected
            : ', categorii detectate: ' . $categoryDetected . ' (schema veche fără coloană categorie)';
        return [
            'ok' => true,
            'message' => 'Import blog finalizat: ' . $inserted . ' noi, ' . $updated . ' actualizate, ' . $skipped . ' sărite' . $categorySuffix . '.',
        ];
    }

    private function blogPostsRowsFromCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $headerLine = fgetcsv($handle, 0, $delimiter);
        if (!is_array($headerLine) || $headerLine === []) {
            fclose($handle);
            return [];
        }
        $headerMap = $this->blogPostsHeaderMap($headerLine);
        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = $this->blogPostsMapRow($line, $headerMap);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function blogPostsRowsFromXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    $parts = [];
                    if (isset($si->t)) {
                        $parts[] = (string) $si->t;
                    }
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $parts[] = (string) ($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = trim(implode('', $parts));
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!is_string($sheetXml) || $sheetXml === '') {
            $zip->close();
            return [];
        }
        $sx = @simplexml_load_string($sheetXml);
        $zip->close();
        if ($sx === false || !isset($sx->sheetData->row)) {
            return [];
        }

        $rows = [];
        foreach ($sx->sheetData->row as $rowNode) {
            $indexedRow = [];
            foreach ($rowNode->c as $cell) {
                $ref = strtoupper((string) ($cell['r'] ?? ''));
                $colLetters = preg_replace('/[^A-Z]/', '', $ref) ?: '';
                if ($colLetters === '') {
                    continue;
                }
                $index = $this->excelColumnLettersToIndex($colLetters);
                $type = (string) ($cell['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $value = (string) ($sharedStrings[$idx] ?? '');
                } elseif (isset($cell->is->t)) {
                    $value = (string) $cell->is->t;
                } else {
                    $value = (string) ($cell->v ?? '');
                }
                $indexedRow[$index] = trim($value);
            }
            if ($indexedRow === []) {
                continue;
            }
            ksort($indexedRow);
            $maxIndex = max(array_keys($indexedRow));
            $denseRow = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $denseRow[] = (string) ($indexedRow[$i] ?? '');
            }
            $rows[] = $denseRow;
        }

        if ($rows === []) {
            return [];
        }

        $header = array_shift($rows);
        if (!is_array($header) || $header === []) {
            return [];
        }
        $headerMap = $this->blogPostsHeaderMap($header);
        $result = [];
        foreach ($rows as $line) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = $this->blogPostsMapRow($line, $headerMap);
            if ($mapped !== null) {
                $result[] = $mapped;
            }
        }

        return $result;
    }

    private function blogPostsHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $rawName) {
            $name = strtolower(trim((string) $rawName));
            if ($idx === 0) {
                $name = ltrim($name, "\xEF\xBB\xBF");
            }
            if ($name === '') {
                continue;
            }
            $map[$name] = (int) $idx;
        }
        return $map;
    }

    private function blogPostsMapRow(array $row, array $headerMap): ?array
    {
        $pick = static function (array $line, array $map, array $aliases): string {
            foreach ($aliases as $alias) {
                $key = strtolower(trim((string) $alias));
                if (!array_key_exists($key, $map)) {
                    continue;
                }
                $index = (int) $map[$key];
                return (string) ($line[$index] ?? '');
            }
            return '';
        };

        $legacyId = trim($pick($row, $headerMap, ['id', 'post_id', 'wp_id']));
        $title = trim($pick($row, $headerMap, ['title', 'post_title', 'post title']));
        $content = $pick($row, $headerMap, ['content', 'post_content', 'post content', 'article_content']);
        $slug = trim($pick($row, $headerMap, ['slug', 'post_name', 'post_slug', 'url_slug']));
        $date = trim($pick($row, $headerMap, ['date', 'post_date', 'published_at', 'publish_date', 'publication_date']));
        $status = trim($pick($row, $headerMap, ['status', 'post_status', 'publication_status']));
        $excerpt = trim($pick($row, $headerMap, ['excerpt', 'post_excerpt', 'summary', 'description']));
        $categories = trim($pick($row, $headerMap, ['categories', 'category', 'post_category', 'taxonomy_category']));
        $featuredImage = trim($pick($row, $headerMap, [
            'featured_image_url',
            'featured_image',
            'cover_image',
            'image',
            'thumbnail',
            'thumbnail_url',
        ]));

        if ($title === '' && trim($content) === '' && $slug === '' && $excerpt === '') {
            return null;
        }

        return [
            'legacy_id' => $legacyId,
            'title' => $title,
            'content' => $content,
            'slug' => $slug,
            'date' => $date,
            'status' => $status,
            'excerpt' => $excerpt,
            'categories' => $categories,
            'featured_image_url' => $featuredImage,
        ];
    }

    private function normalizeBlogImportDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return date('Y-m-d H:i:s');
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $raw) === 1) {
            $serial = (float) $raw;
            if ($serial >= 20_000 && $serial <= 90_000) {
                $timestamp = (int) round(($serial - 25569) * 86400);
                if ($timestamp > 0) {
                    return gmdate('Y-m-d H:i:s', $timestamp);
                }
            }
        }

        $value = str_replace('T', ' ', $raw);
        if (preg_match('/^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{2,4})(?:\s+(\d{1,2})\:(\d{2})(?:\:(\d{2}))?)?$/', $value, $m) === 1) {
            $first = (int) ($m[1] ?? 0);
            $second = (int) ($m[2] ?? 0);
            $year = (int) ($m[3] ?? 0);
            if ($year < 100) {
                $year += 2000;
            }

            // Prefer format zi/lună/an for Romanian exports.
            $day = $first;
            $month = $second;
            if ($first <= 12 && $second > 12) {
                $month = $first;
                $day = $second;
            }

            $hour = (int) ($m[4] ?? 0);
            $minute = (int) ($m[5] ?? 0);
            $secondTime = (int) ($m[6] ?? 0);
            if (checkdate($month, $day, $year) && $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 && $secondTime >= 0 && $secondTime <= 59) {
                return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $secondTime);
            }
        }

        if (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $value) === 1) {
            $value .= ' 00:00:00';
        } elseif (preg_match('/^\d{4}\-\d{2}\-\d{2}\s+\d{2}\:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return date('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeBlogImportStatus(string $status): int
    {
        $status = mb_strtolower(trim($status));
        $status = strtr($status, [
            'ă' => 'a',
            'â' => 'a',
            'î' => 'i',
            'ș' => 's',
            'ş' => 's',
            'ț' => 't',
            'ţ' => 't',
        ]);
        if ($status === '') {
            return 1;
        }

        if (in_array($status, ['publish', 'published', 'publicat', 'live', 'future', 'scheduled', 'programat'], true)) {
            return 1;
        }
        if (in_array($status, ['draft', 'pending', 'private', 'trash', 'auto-draft'], true)) {
            return 0;
        }
        return 1;
    }

    private function normalizeBlogImportCategory(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $parts = preg_split('/\s*(?:\||,|;|\/)\s*/u', $raw) ?: [];
        $first = trim((string) ($parts[0] ?? ''));
        if ($first === '') {
            $first = $raw;
        }
        if (mb_strlen($first) > 190) {
            $first = mb_substr($first, 0, 190);
        }
        return $first !== '' ? $first : null;
    }

    private function importProductReviewsFromUploadedFile(PDO $db, mixed $file): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'Selectează fișierul CSV/XLSX cu recenzii produse.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload eșuat. Încearcă din nou.'];
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::PRODUCT_REVIEWS_IMPORT_UPLOAD_MAX_SIZE) {
            return ['ok' => false, 'message' => 'Fișier invalid sau prea mare (max 12MB).'];
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Fișier invalid.'];
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            return ['ok' => false, 'message' => 'Format neacceptat. Folosește CSV sau XLSX.'];
        }

        $rows = $ext === 'csv'
            ? $this->productReviewRowsFromCsv($tmpPath)
            : $this->productReviewRowsFromXlsx($tmpPath);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Nu am găsit rânduri valide în fișier (coloana product_slug este obligatorie).'];
        }

        $findProductStmt = $db->prepare('SELECT id FROM products WHERE LOWER(slug) = :slug AND deleted_at IS NULL LIMIT 1');
        $existsStmt = $db->prepare(
            'SELECT id
             FROM product_reviews
             WHERE product_id = :product_id
               AND user_name = :user_name
               AND rating = :rating
               AND COALESCE(user_email, "") = COALESCE(:user_email, "")
               AND COALESCE(review_text, "") = COALESCE(:review_text, "")
               AND created_at = :created_at
             LIMIT 1'
        );
        $insertWithoutSource = false;
        try {
            $insertStmt = $db->prepare(
                'INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, source, is_approved, created_at)
                 VALUES (:product_id, :user_name, :user_email, :rating, :review_text, :source, :is_approved, :created_at)'
            );
        } catch (Throwable) {
            $insertStmt = $db->prepare(
                'INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, is_approved, created_at)
                 VALUES (:product_id, :user_name, :user_email, :rating, :review_text, :is_approved, :created_at)'
            );
            $insertWithoutSource = true;
        }

        $inserted = 0;
        $duplicates = 0;
        $invalid = 0;
        $missingProduct = 0;
        $missingSlugs = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $invalid++;
                continue;
            }

            $slug = strtolower(trim((string) ($row['product_slug'] ?? '')));
            if ($slug === '') {
                $invalid++;
                continue;
            }

            $findProductStmt->execute(['slug' => $slug]);
            $productId = (int) $findProductStmt->fetchColumn();
            if ($productId <= 0) {
                $missingProduct++;
                $missingSlugs[$slug] = $slug;
                continue;
            }

            $createdAt = $this->normalizeProductReviewImportDate((string) ($row['created_at'] ?? ''));
            $params = [
                'product_id' => $productId,
                'user_name' => (string) ($row['user_name'] ?? 'Client'),
                'user_email' => $row['user_email'] ?? null,
                'rating' => max(1, min(5, (int) ($row['rating'] ?? 5))),
                'review_text' => $row['review_text'] ?? null,
                'source' => (string) ($row['source'] ?? 'import'),
                'is_approved' => (int) ($row['is_approved'] ?? 0) === 1 ? 1 : 0,
                'created_at' => $createdAt,
            ];

            $existsStmt->execute([
                'product_id' => $params['product_id'],
                'user_name' => $params['user_name'],
                'rating' => $params['rating'],
                'user_email' => $params['user_email'],
                'review_text' => $params['review_text'],
                'created_at' => $params['created_at'],
            ]);
            if ((int) $existsStmt->fetchColumn() > 0) {
                $duplicates++;
                continue;
            }

            if ($insertWithoutSource) {
                unset($params['source']);
            }
            $insertStmt->execute($params);
            $inserted++;
        }

        if ($inserted <= 0) {
            $details = [];
            if ($duplicates > 0) {
                $details[] = 'duplicate: ' . $duplicates;
            }
            if ($missingProduct > 0) {
                $details[] = 'slug negăsit: ' . $missingProduct;
            }
            if ($invalid > 0) {
                $details[] = 'rânduri invalide: ' . $invalid;
            }
            $suffix = $details === [] ? '' : ' (' . implode(', ', $details) . ')';
            return ['ok' => false, 'message' => 'Nu s-au importat recenzii.' . $suffix];
        }

        $message = 'Import recenzii finalizat: '
            . $inserted . ' importate, '
            . $duplicates . ' duplicate, '
            . $missingProduct . ' slug-uri negăsite, '
            . $invalid . ' rânduri invalide.';
        if ($missingSlugs !== []) {
            $preview = implode(', ', array_slice(array_values($missingSlugs), 0, 8));
            if ($preview !== '') {
                $message .= ' Slug-uri negăsite (exemple): ' . $preview . (count($missingSlugs) > 8 ? ', ...' : '') . '.';
            }
        }

        return [
            'ok' => true,
            'message' => $message,
        ];
    }

    private function productReviewRowsFromCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $headerLine = fgetcsv($handle, 0, $delimiter);
        if (!is_array($headerLine) || $headerLine === []) {
            fclose($handle);
            return [];
        }
        $headerMap = $this->productReviewHeaderMap($headerLine);
        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = $this->productReviewMapRow($line, $headerMap);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
        fclose($handle);
        return $rows;
    }

    private function productReviewRowsFromXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    $parts = [];
                    if (isset($si->t)) {
                        $parts[] = (string) $si->t;
                    }
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $parts[] = (string) ($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = trim(implode('', $parts));
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!is_string($sheetXml) || $sheetXml === '') {
            $zip->close();
            return [];
        }
        $sx = @simplexml_load_string($sheetXml);
        $zip->close();
        if ($sx === false || !isset($sx->sheetData->row)) {
            return [];
        }

        $rows = [];
        foreach ($sx->sheetData->row as $rowNode) {
            $indexedRow = [];
            foreach ($rowNode->c as $cell) {
                $ref = strtoupper((string) ($cell['r'] ?? ''));
                $colLetters = preg_replace('/[^A-Z]/', '', $ref) ?: '';
                if ($colLetters === '') {
                    continue;
                }
                $index = $this->excelColumnLettersToIndex($colLetters);
                $type = (string) ($cell['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $value = (string) ($sharedStrings[$idx] ?? '');
                } elseif (isset($cell->is->t)) {
                    $value = (string) $cell->is->t;
                } else {
                    $value = (string) ($cell->v ?? '');
                }
                $indexedRow[$index] = trim($value);
            }
            if ($indexedRow === []) {
                continue;
            }
            ksort($indexedRow);
            $maxIndex = max(array_keys($indexedRow));
            $denseRow = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $denseRow[] = (string) ($indexedRow[$i] ?? '');
            }
            $rows[] = $denseRow;
        }

        if ($rows === []) {
            return [];
        }

        $header = array_shift($rows);
        if (!is_array($header) || $header === []) {
            return [];
        }
        $headerMap = $this->productReviewHeaderMap($header);
        $result = [];
        foreach ($rows as $line) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = $this->productReviewMapRow($line, $headerMap);
            if ($mapped !== null) {
                $result[] = $mapped;
            }
        }

        return $result;
    }

    private function productReviewHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $rawName) {
            $name = strtolower(trim((string) $rawName));
            if ($idx === 0) {
                $name = ltrim($name, "\xEF\xBB\xBF");
            }
            if ($name === '') {
                continue;
            }
            $map[$name] = (int) $idx;
        }
        return $map;
    }

    private function productReviewMapRow(array $row, array $headerMap): ?array
    {
        $pick = static function (array $line, array $map, array $aliases): string {
            foreach ($aliases as $alias) {
                $key = strtolower(trim((string) $alias));
                if (!array_key_exists($key, $map)) {
                    continue;
                }
                $index = (int) $map[$key];
                return (string) ($line[$index] ?? '');
            }
            return '';
        };

        $slug = trim($pick($row, $headerMap, ['product_slug', 'slug', 'produs_slug', 'post_name']));
        $ratingRaw = trim($pick($row, $headerMap, ['rating', 'evaluare', 'stars', 'stele']));
        $reviewText = trim($pick($row, $headerMap, ['review_text', 'review', 'recenzie', 'comment_content', 'content', 'text']));
        $reviewerName = trim($pick($row, $headerMap, ['reviewer_name', 'user_name', 'author', 'comment_author', 'nume']));
        $reviewerEmail = strtolower(trim($pick($row, $headerMap, ['reviewer_email', 'user_email', 'email', 'comment_author_email'])));
        $createdAtRaw = trim($pick($row, $headerMap, ['created_at', 'comment_date', 'date', 'data']));
        $isApprovedRaw = trim($pick($row, $headerMap, ['is_approved', 'approved', 'comment_approved', 'status']));
        $sourceRaw = trim($pick($row, $headerMap, ['source', 'review_source', 'origine', 'origin']));

        if ($slug === '' || ($reviewText === '' && $ratingRaw === '' && $reviewerName === '' && $reviewerEmail === '')) {
            return null;
        }
        if ($reviewerName === '') {
            $reviewerName = 'Client';
        }

        $rating = (int) round((float) str_replace(',', '.', $ratingRaw !== '' ? $ratingRaw : '5'));
        if ($rating < 1) {
            $rating = 1;
        }
        if ($rating > 5) {
            $rating = 5;
        }

        $isApprovedNorm = 0;
        $approvedToken = strtolower($isApprovedRaw);
        if ($approvedToken !== '' && in_array($approvedToken, ['1', 'true', 'yes', 'da', 'approved', 'aprobat'], true)) {
            $isApprovedNorm = 1;
        }

        return [
            'product_slug' => $slug,
            'rating' => $rating,
            'review_text' => $reviewText !== '' ? $reviewText : null,
            'user_name' => $reviewerName,
            'user_email' => ($reviewerEmail !== '' && filter_var($reviewerEmail, FILTER_VALIDATE_EMAIL)) ? $reviewerEmail : null,
            'source' => $sourceRaw !== '' ? $sourceRaw : 'import',
            'created_at' => $createdAtRaw,
            'is_approved' => $isApprovedNorm,
        ];
    }

    private function normalizeProductReviewImportDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return date('Y-m-d H:i:s');
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $raw) === 1) {
            $serial = (float) $raw;
            if ($serial >= 20_000 && $serial <= 90_000) {
                $timestamp = (int) round(($serial - 25569) * 86400);
                if ($timestamp > 0) {
                    return gmdate('Y-m-d H:i:s', $timestamp);
                }
            }
        }

        $value = str_replace('T', ' ', $raw);
        if (preg_match('/^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{2,4})(?:\s+(\d{1,2})\:(\d{2})(?:\:(\d{2}))?)?$/', $value, $m) === 1) {
            $first = (int) ($m[1] ?? 0);
            $second = (int) ($m[2] ?? 0);
            $year = (int) ($m[3] ?? 0);
            if ($year < 100) {
                $year += 2000;
            }

            $day = $first;
            $month = $second;
            if ($first <= 12 && $second > 12) {
                $month = $first;
                $day = $second;
            }

            $hour = (int) ($m[4] ?? 0);
            $minute = (int) ($m[5] ?? 0);
            $secondTime = (int) ($m[6] ?? 0);
            if (checkdate($month, $day, $year) && $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 && $secondTime >= 0 && $secondTime <= 59) {
                return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $secondTime);
            }
        }

        if (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $value) === 1) {
            $value .= ' 00:00:00';
        } elseif (preg_match('/^\d{4}\-\d{2}\-\d{2}\s+\d{2}\:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return date('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function estimateBlogReadingMinutes(string $content): int
    {
        $text = trim(strip_tags($content));
        if ($text === '') {
            return 1;
        }
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($words);
        $minutes = (int) ceil($count / 220);
        return $this->normalizeBlogReadingMinutes((string) $minutes);
    }

    private function extractFirstImageUrlFromHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $match) !== 1) {
            return '';
        }
        return trim((string) ($match[1] ?? ''));
    }

    private function wordpressUsersRowsFromCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $headerLine = fgetcsv($handle, 0, $delimiter);
        if (!is_array($headerLine) || $headerLine === []) {
            fclose($handle);
            return [];
        }
        $headerMap = $this->wordpressUsersHeaderMap($headerLine);
        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = $this->wordpressUsersMapRow($line, $headerMap);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function loyaltyPointsRowsFromCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $headerLine = fgetcsv($handle, 0, $delimiter);
        if (!is_array($headerLine) || $headerLine === []) {
            fclose($handle);
            return [];
        }
        $headerMap = $this->loyaltyPointsHeaderMap($headerLine);
        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = $this->loyaltyPointsMapRow($line, $headerMap);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function loyaltyPointsRowsFromXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    $parts = [];
                    if (isset($si->t)) {
                        $parts[] = (string) $si->t;
                    }
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $parts[] = (string) ($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = trim(implode('', $parts));
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!is_string($sheetXml) || $sheetXml === '') {
            $zip->close();
            return [];
        }
        $sx = @simplexml_load_string($sheetXml);
        $zip->close();
        if ($sx === false || !isset($sx->sheetData->row)) {
            return [];
        }

        $rows = [];
        foreach ($sx->sheetData->row as $rowNode) {
            $indexedRow = [];
            foreach ($rowNode->c as $cell) {
                $ref = strtoupper((string) ($cell['r'] ?? ''));
                $colLetters = preg_replace('/[^A-Z]/', '', $ref) ?: '';
                if ($colLetters === '') {
                    continue;
                }
                $index = $this->excelColumnLettersToIndex($colLetters);
                $type = (string) ($cell['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $value = (string) ($sharedStrings[$idx] ?? '');
                } elseif (isset($cell->is->t)) {
                    $value = (string) $cell->is->t;
                } else {
                    $value = (string) ($cell->v ?? '');
                }
                $indexedRow[$index] = trim($value);
            }
            if ($indexedRow === []) {
                continue;
            }
            ksort($indexedRow);
            $maxIndex = max(array_keys($indexedRow));
            $denseRow = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $denseRow[] = (string) ($indexedRow[$i] ?? '');
            }
            $rows[] = $denseRow;
        }

        if ($rows === []) {
            return [];
        }

        $header = array_shift($rows);
        if (!is_array($header) || $header === []) {
            return [];
        }
        $headerMap = $this->loyaltyPointsHeaderMap($header);
        $result = [];
        foreach ($rows as $line) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = $this->loyaltyPointsMapRow($line, $headerMap);
            if ($mapped !== null) {
                $result[] = $mapped;
            }
        }

        return $result;
    }

    private function loyaltyPointsHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $rawName) {
            $name = strtolower(trim((string) $rawName));
            if ($idx === 0) {
                $name = ltrim($name, "\xEF\xBB\xBF");
            }
            if ($name === '') {
                continue;
            }
            $map[$name] = (int) $idx;
        }
        return $map;
    }

    private function loyaltyPointsMapRow(array $row, array $headerMap): ?array
    {
        $pick = static function (array $line, array $map, array $aliases): string {
            foreach ($aliases as $alias) {
                $key = strtolower(trim((string) $alias));
                if (!array_key_exists($key, $map)) {
                    continue;
                }
                $index = (int) $map[$key];
                return trim((string) ($line[$index] ?? ''));
            }
            return '';
        };

        $email = strtolower($pick($row, $headerMap, ['email', 'user_email', 'user']));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $pointsRaw = $pick($row, $headerMap, [
            'current_balance',
            'received_points',
            'points',
            'loyalty_points',
            'balance',
        ]);
        if ($pointsRaw === '') {
            return null;
        }
        $normalizedPoints = str_replace(',', '.', preg_replace('/[^0-9,\.\-]/', '', $pointsRaw) ?? '');
        if ($normalizedPoints === '' || !is_numeric($normalizedPoints)) {
            return null;
        }

        return [
            'email' => $email,
            'points' => (int) round((float) $normalizedPoints),
        ];
    }

    private function wordpressUsersRowsFromXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    $parts = [];
                    if (isset($si->t)) {
                        $parts[] = (string) $si->t;
                    }
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $parts[] = (string) ($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = trim(implode('', $parts));
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!is_string($sheetXml) || $sheetXml === '') {
            $zip->close();
            return [];
        }
        $sx = @simplexml_load_string($sheetXml);
        $zip->close();
        if ($sx === false || !isset($sx->sheetData->row)) {
            return [];
        }

        $rows = [];
        foreach ($sx->sheetData->row as $rowNode) {
            $indexedRow = [];
            foreach ($rowNode->c as $cell) {
                $ref = strtoupper((string) ($cell['r'] ?? ''));
                $colLetters = preg_replace('/[^A-Z]/', '', $ref) ?: '';
                if ($colLetters === '') {
                    continue;
                }
                $index = $this->excelColumnLettersToIndex($colLetters);
                $type = (string) ($cell['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $value = (string) ($sharedStrings[$idx] ?? '');
                } elseif (isset($cell->is->t)) {
                    $value = (string) $cell->is->t;
                } else {
                    $value = (string) ($cell->v ?? '');
                }
                $indexedRow[$index] = trim($value);
            }
            if ($indexedRow === []) {
                continue;
            }
            ksort($indexedRow);
            $maxIndex = max(array_keys($indexedRow));
            $denseRow = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $denseRow[] = (string) ($indexedRow[$i] ?? '');
            }
            $rows[] = $denseRow;
        }

        if ($rows === []) {
            return [];
        }

        $header = array_shift($rows);
        if (!is_array($header) || $header === []) {
            return [];
        }
        $headerMap = $this->wordpressUsersHeaderMap($header);
        $result = [];
        foreach ($rows as $line) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = $this->wordpressUsersMapRow($line, $headerMap);
            if ($mapped !== null) {
                $result[] = $mapped;
            }
        }

        return $result;
    }

    private function wordpressUsersHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $rawName) {
            $name = strtolower(trim((string) $rawName));
            if ($idx === 0) {
                $name = ltrim($name, "\xEF\xBB\xBF");
            }
            if ($name === '') {
                continue;
            }
            $map[$name] = (int) $idx;
        }
        return $map;
    }

    private function wordpressUsersMapRow(array $row, array $headerMap): ?array
    {
        $pick = static function (array $line, array $map, array $aliases): string {
            foreach ($aliases as $alias) {
                $key = strtolower(trim((string) $alias));
                if (!array_key_exists($key, $map)) {
                    continue;
                }
                $index = (int) $map[$key];
                return trim((string) ($line[$index] ?? ''));
            }
            return '';
        };

        $email = strtolower($pick($row, $headerMap, ['user_email', 'email']));
        if ($email === '') {
            return null;
        }

        $displayName = $pick($row, $headerMap, ['display_name', 'user_nicename', 'user_login']);
        $firstName = $pick($row, $headerMap, ['first_name', 'billing_first_name']);
        $lastName = $pick($row, $headerMap, ['last_name', 'billing_last_name']);
        if ($firstName === '' && $displayName !== '') {
            $parts = preg_split('/\s+/', $displayName) ?: [];
            $firstName = trim((string) ($parts[0] ?? ''));
            $lastName = trim(implode(' ', array_slice($parts, 1)));
        }

        return [
            'email' => $email,
            'password_hash' => $pick($row, $headerMap, ['user_pass', 'password_hash']),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $pick($row, $headerMap, ['phone', 'billing_phone']),
            'gender' => $pick($row, $headerMap, ['gender', 'sex']),
            'birth_date' => $pick($row, $headerMap, ['birth_date', 'birthday', 'date_of_birth']),
            'created_at' => $pick($row, $headerMap, ['user_registered', 'created_at', 'registered_at']),
        ];
    }

    private function excelColumnLettersToIndex(string $letters): int
    {
        $letters = strtoupper(trim($letters));
        if ($letters === '') {
            return 0;
        }
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($letters[$i]);
            if ($ord < 65 || $ord > 90) {
                continue;
            }
            $index = ($index * 26) + ($ord - 64);
        }
        return max(0, $index - 1);
    }

    private function fanLocalitiesRowsFromCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        $header = null;
        while (($line = fgetcsv($handle, 0, ',')) !== false) {
            if (!is_array($line)) {
                continue;
            }
            if ($header === null) {
                $header = $this->fanHeaderMap($line);
                continue;
            }
            $mapped = $this->fanMapRowFromColumns($line, $header);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function fanLocalitiesRowsFromXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    $parts = [];
                    if (isset($si->t)) {
                        $parts[] = (string) $si->t;
                    }
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $parts[] = (string) ($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = trim(implode('', $parts));
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!is_string($sheetXml) || $sheetXml === '') {
            $zip->close();
            return [];
        }
        $sx = @simplexml_load_string($sheetXml);
        $zip->close();
        if ($sx === false || !isset($sx->sheetData->row)) {
            return [];
        }

        $rowsByIndex = [];
        foreach ($sx->sheetData->row as $row) {
            $rowValues = [];
            foreach ($row->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $col = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?: '';
                if ($col === '') {
                    continue;
                }
                $type = (string) ($cell['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $value = (string) ($sharedStrings[$idx] ?? '');
                } elseif (isset($cell->is->t)) {
                    $value = (string) $cell->is->t;
                } else {
                    $value = (string) ($cell->v ?? '');
                }
                $rowValues[$col] = trim($value);
            }
            if ($rowValues !== []) {
                $rowsByIndex[] = $rowValues;
            }
        }
        if ($rowsByIndex === []) {
            return [];
        }

        $first = array_shift($rowsByIndex);
        $headerCells = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
            if (array_key_exists($col, $first)) {
                $headerCells[] = $first[$col];
            }
        }
        $header = $this->fanHeaderMap($headerCells);
        $rows = [];
        foreach ($rowsByIndex as $rowValues) {
            $line = [];
            foreach (array_keys($header['columns']) as $col) {
                $line[] = (string) ($rowValues[$col] ?? '');
            }
            $mapped = $this->fanMapRowFromColumns($line, $header);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }

    private function fanHeaderMap(array $headerColumns): array
    {
        $columns = [];
        $index = 0;
        foreach ($headerColumns as $raw) {
            $name = $this->normalizeFanLocalityToken((string) $raw);
            $column = chr(ord('A') + $index);
            $columns[$column] = $name;
            $index++;
            if ($index > 25) {
                break;
            }
        }

        $localityKey = null;
        $countyKey = null;
        foreach ($columns as $column => $name) {
            if ($localityKey === null && (str_contains($name, 'localitate') || str_contains($name, 'oras'))) {
                $localityKey = $column;
            }
            if ($countyKey === null && (str_contains($name, 'judet') || str_contains($name, 'county'))) {
                $countyKey = $column;
            }
        }

        if ($localityKey === null) {
            $localityKey = 'A';
        }
        if ($countyKey === null) {
            $countyKey = 'B';
        }

        return [
            'columns' => $columns,
            'locality_col' => $localityKey,
            'county_col' => $countyKey,
        ];
    }

    private function fanMapRowFromColumns(array $line, array $header): ?array
    {
        $columns = $header['columns'] ?? [];
        $localityCol = (string) ($header['locality_col'] ?? 'A');
        $countyCol = (string) ($header['county_col'] ?? 'B');
        $lineMap = [];
        $position = 0;
        foreach (array_keys($columns) as $column) {
            $lineMap[$column] = trim((string) ($line[$position] ?? ''));
            $position++;
        }
        $locality = trim((string) ($lineMap[$localityCol] ?? ''));
        $county = trim((string) ($lineMap[$countyCol] ?? ''));
        if ($locality === '' || $county === '') {
            return null;
        }
        return [
            'locality' => $locality,
            'county' => $county,
        ];
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

    private function ensureFanStreetsSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS fan_streets (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    county VARCHAR(120) NOT NULL,
                    locality VARCHAR(190) NOT NULL,
                    street VARCHAR(255) NOT NULL,
                    street_id VARCHAR(64) NOT NULL DEFAULT "",
                    range_from VARCHAR(40) NOT NULL DEFAULT "",
                    range_to VARCHAR(40) NOT NULL DEFAULT "",
                    parity VARCHAR(32) NOT NULL DEFAULT "",
                    postal_code VARCHAR(32) NOT NULL DEFAULT "",
                    street_type VARCHAR(80) NOT NULL DEFAULT "",
                    agency VARCHAR(160) NOT NULL DEFAULT "",
                    county_norm VARCHAR(120) NOT NULL,
                    locality_norm VARCHAR(190) NOT NULL,
                    street_norm VARCHAR(255) NOT NULL,
                    row_key CHAR(40) NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_fan_streets_row (row_key),
                    KEY idx_fan_streets_county (county_norm),
                    KEY idx_fan_streets_locality (locality_norm),
                    KEY idx_fan_streets_street (street_norm)
                )'
            );
        } catch (Throwable) {
        }

        try {
            $db->exec('ALTER TABLE fan_streets ADD COLUMN street VARCHAR(255) NOT NULL AFTER locality');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets ADD COLUMN street_norm VARCHAR(255) NOT NULL AFTER locality_norm');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets ADD COLUMN street_id VARCHAR(64) NOT NULL DEFAULT "" AFTER street');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets ADD COLUMN range_from VARCHAR(40) NOT NULL DEFAULT "" AFTER street_id');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets ADD COLUMN range_to VARCHAR(40) NOT NULL DEFAULT "" AFTER range_from');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets ADD COLUMN parity VARCHAR(32) NOT NULL DEFAULT "" AFTER range_to');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets ADD COLUMN postal_code VARCHAR(32) NOT NULL DEFAULT "" AFTER parity');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets ADD COLUMN street_type VARCHAR(80) NOT NULL DEFAULT "" AFTER postal_code');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets ADD COLUMN agency VARCHAR(160) NOT NULL DEFAULT "" AFTER street_type');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets ADD COLUMN row_key CHAR(40) NOT NULL DEFAULT "" AFTER street_norm');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets DROP INDEX uniq_fan_streets');
        } catch (Throwable) {
        }
        try {
            $db->exec(
                'UPDATE fan_streets
                 SET row_key = SHA1(CONCAT_WS("|",
                    county_norm,
                    locality_norm,
                    street_norm,
                    COALESCE(street_id, ""),
                    COALESCE(range_from, ""),
                    COALESCE(range_to, ""),
                    COALESCE(parity, ""),
                    COALESCE(postal_code, ""),
                    COALESCE(street_type, ""),
                    COALESCE(agency, ""),
                    CAST(id AS CHAR)
                 ))
                 WHERE row_key = ""'
            );
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE fan_streets ADD UNIQUE KEY uniq_fan_streets_row (row_key)');
        } catch (Throwable) {
        }
    }

    private function ensureFanLocalitiesExtraKmSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS fan_localities_extra_km (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    county VARCHAR(120) NOT NULL,
                    locality VARCHAR(190) NOT NULL,
                    county_norm VARCHAR(120) NOT NULL,
                    locality_norm VARCHAR(190) NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_fan_localities_extra_km (county_norm, locality_norm),
                    KEY idx_fan_localities_extra_km_county (county_norm),
                    KEY idx_fan_localities_extra_km_locality (locality_norm)
                )'
            );
        } catch (Throwable) {
        }
    }

    private function importFanStreetsFromUploadedFile(PDO $db, mixed $file): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'Selectează fișierul CSV/XLSX cu lista de străzi FAN.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload eșuat. Încearcă din nou.'];
        }
        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::FAN_LOCALITIES_UPLOAD_MAX_SIZE) {
            return ['ok' => false, 'message' => 'Fișier invalid sau prea mare (max 12MB).'];
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Fișier invalid.'];
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            return ['ok' => false, 'message' => 'Format neacceptat. Folosește CSV sau XLSX.'];
        }

        $rows = $ext === 'csv'
            ? $this->fanStreetsRowsFromCsv($tmpPath)
            : $this->fanStreetsRowsFromXlsx($tmpPath);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Nu am găsit rânduri valide în fișier (coloane stradă/localitate + județ).'];
        }

        $this->ensureFanStreetsSchema($db);
        $now = date('Y-m-d H:i:s');
        $inserted = 0;
        $updated = 0;
        $seen = [];
        $stmt = $db->prepare(
            'INSERT INTO fan_streets (
                county, locality, street, street_id, range_from, range_to, parity, postal_code, street_type, agency,
                county_norm, locality_norm, street_norm, row_key, created_at, updated_at
             )
             VALUES (
                :county, :locality, :street, :street_id, :range_from, :range_to, :parity, :postal_code, :street_type, :agency,
                :county_norm, :locality_norm, :street_norm, :row_key, :created_at, :updated_at
             )
             ON DUPLICATE KEY UPDATE
                county = VALUES(county),
                locality = VALUES(locality),
                street = VALUES(street),
                street_id = VALUES(street_id),
                range_from = VALUES(range_from),
                range_to = VALUES(range_to),
                parity = VALUES(parity),
                postal_code = VALUES(postal_code),
                street_type = VALUES(street_type),
                agency = VALUES(agency),
                updated_at = VALUES(updated_at)'
        );
        $existsStmt = $db->prepare(
            'SELECT id FROM fan_streets WHERE row_key = :row_key LIMIT 1'
        );

        foreach ($rows as $row) {
            $county = trim((string) ($row['county'] ?? ''));
            $locality = trim((string) ($row['locality'] ?? ''));
            $street = trim((string) ($row['street'] ?? ''));
            if ($county === '' || $locality === '' || $street === '') {
                continue;
            }
            $streetId = trim((string) ($row['street_id'] ?? ''));
            $rangeFrom = trim((string) ($row['range_from'] ?? ''));
            $rangeTo = trim((string) ($row['range_to'] ?? ''));
            $parity = trim((string) ($row['parity'] ?? ''));
            $postalCode = trim((string) ($row['postal_code'] ?? ''));
            $streetType = trim((string) ($row['street_type'] ?? ''));
            $agency = trim((string) ($row['agency'] ?? ''));
            $countyNorm = $this->normalizeFanLocalityToken($county);
            $localityNorm = $this->normalizeFanLocalityToken($locality);
            $streetNorm = $this->normalizeFanLocalityToken($street);
            if ($countyNorm === '' || $localityNorm === '' || $streetNorm === '') {
                continue;
            }
            $streetIdNorm = $this->normalizeFanLocalityToken($streetId);
            $rangeFromNorm = $this->normalizeFanLocalityToken($rangeFrom);
            $rangeToNorm = $this->normalizeFanLocalityToken($rangeTo);
            $parityNorm = $this->normalizeFanLocalityToken($parity);
            $postalCodeNorm = $this->normalizeFanLocalityToken($postalCode);
            $streetTypeNorm = $this->normalizeFanLocalityToken($streetType);
            $agencyNorm = $this->normalizeFanLocalityToken($agency);
            $rowKey = sha1(implode('|', [
                $countyNorm,
                $localityNorm,
                $streetNorm,
                $streetIdNorm,
                $rangeFromNorm,
                $rangeToNorm,
                $parityNorm,
                $postalCodeNorm,
                $streetTypeNorm,
                $agencyNorm,
            ]));
            $pair = $rowKey;
            if (isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;

            $existsStmt->execute([
                'row_key' => $rowKey,
            ]);
            $exists = (bool) $existsStmt->fetchColumn();

            $stmt->execute([
                'county' => $county,
                'locality' => $locality,
                'street' => $street,
                'street_id' => $streetId,
                'range_from' => $rangeFrom,
                'range_to' => $rangeTo,
                'parity' => $parity,
                'postal_code' => $postalCode,
                'street_type' => $streetType,
                'agency' => $agency,
                'county_norm' => $countyNorm,
                'locality_norm' => $localityNorm,
                'street_norm' => $streetNorm,
                'row_key' => $rowKey,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($exists) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        if (($inserted + $updated) <= 0) {
            return ['ok' => false, 'message' => 'Import fără rezultate. Verifică antetul și conținutul fișierului.'];
        }

        return [
            'ok' => true,
            'message' => 'Străzi importate: ' . ($inserted + $updated) . ' (noi: ' . $inserted . ', actualizate: ' . $updated . ').',
        ];
    }

    private function importFanLocalitiesExtraKmFromUploadedFile(PDO $db, mixed $file): array
    {
        return $this->importFanSimpleListFromUploadedFile(
            $db,
            $file,
            'fan_localities_extra_km',
            'Selectează fișierul CSV/XLSX cu localități FAN (km suplimentari).',
            'Nu am găsit rânduri valide în fișier (coloane localitate + județ).',
            'Localități km suplimentari importate: '
        );
    }

    private function importFanSimpleListFromUploadedFile(
        PDO $db,
        mixed $file,
        string $table,
        string $missingFileMessage,
        string $emptyRowsMessage,
        string $successPrefix
    ): array {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => $missingFileMessage];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload eșuat. Încearcă din nou.'];
        }
        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::FAN_LOCALITIES_UPLOAD_MAX_SIZE) {
            return ['ok' => false, 'message' => 'Fișier invalid sau prea mare (max 12MB).'];
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Fișier invalid.'];
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            return ['ok' => false, 'message' => 'Format neacceptat. Folosește CSV sau XLSX.'];
        }

        $rows = $ext === 'csv'
            ? $this->fanSimpleListRowsFromCsv($tmpPath)
            : $this->fanSimpleListRowsFromXlsx($tmpPath);
        if ($rows === []) {
            return ['ok' => false, 'message' => $emptyRowsMessage];
        }

        if ($table === 'fan_streets') {
            $this->ensureFanStreetsSchema($db);
        } else {
            $this->ensureFanLocalitiesExtraKmSchema($db);
        }

        $now = date('Y-m-d H:i:s');
        $inserted = 0;
        $updated = 0;
        $seen = [];
        $stmt = $db->prepare(
            "INSERT INTO {$table} (county, locality, county_norm, locality_norm, created_at, updated_at)
             VALUES (:county, :locality, :county_norm, :locality_norm, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE county = VALUES(county), locality = VALUES(locality), updated_at = VALUES(updated_at)"
        );
        $existsStmt = $db->prepare(
            "SELECT id FROM {$table} WHERE county_norm = :county_norm AND locality_norm = :locality_norm LIMIT 1"
        );

        foreach ($rows as $row) {
            $county = trim((string) ($row['county'] ?? ''));
            $locality = trim((string) ($row['locality'] ?? ''));
            if ($county === '' || $locality === '') {
                continue;
            }
            $countyNorm = $this->normalizeFanLocalityToken($county);
            $localityNorm = $this->normalizeFanLocalityToken($locality);
            if ($countyNorm === '' || $localityNorm === '') {
                continue;
            }
            $pair = $countyNorm . '|' . $localityNorm;
            if (isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;

            $existsStmt->execute([
                'county_norm' => $countyNorm,
                'locality_norm' => $localityNorm,
            ]);
            $exists = (bool) $existsStmt->fetchColumn();
            $stmt->execute([
                'county' => $county,
                'locality' => $locality,
                'county_norm' => $countyNorm,
                'locality_norm' => $localityNorm,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($exists) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        if (($inserted + $updated) <= 0) {
            return ['ok' => false, 'message' => 'Import fără rezultate. Verifică antetul și conținutul fișierului.'];
        }

        return [
            'ok' => true,
            'message' => $successPrefix . ($inserted + $updated) . ' (noi: ' . $inserted . ', actualizate: ' . $updated . ').',
        ];
    }

    private function fanStreetsRowsFromCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $rows = [];
        $header = null;
        while (($line = fgetcsv($handle, 0, ',')) !== false) {
            if (!is_array($line)) {
                continue;
            }
            if ($header === null) {
                $header = $this->fanStreetsHeaderMap($line);
                continue;
            }
            $mapped = $this->fanStreetsMapRowFromColumns($line, $header);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
        fclose($handle);
        return $rows;
    }

    private function fanStreetsRowsFromXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    $parts = [];
                    if (isset($si->t)) {
                        $parts[] = (string) $si->t;
                    }
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $parts[] = (string) ($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = trim(implode('', $parts));
                }
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!is_string($sheetXml) || $sheetXml === '') {
            $zip->close();
            return [];
        }
        $sx = @simplexml_load_string($sheetXml);
        $zip->close();
        if ($sx === false || !isset($sx->sheetData->row)) {
            return [];
        }

        $rowsByIndex = [];
        foreach ($sx->sheetData->row as $row) {
            $rowValues = [];
            foreach ($row->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $col = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?: '';
                if ($col === '') {
                    continue;
                }
                $type = (string) ($cell['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $value = (string) ($sharedStrings[$idx] ?? '');
                } elseif (isset($cell->is->t)) {
                    $value = (string) $cell->is->t;
                } else {
                    $value = (string) ($cell->v ?? '');
                }
                $rowValues[$col] = trim($value);
            }
            if ($rowValues !== []) {
                $rowsByIndex[] = $rowValues;
            }
        }
        if ($rowsByIndex === []) {
            return [];
        }

        $first = array_shift($rowsByIndex);
        $headerCells = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $col) {
            if (array_key_exists($col, $first)) {
                $headerCells[] = $first[$col];
            }
        }
        $header = $this->fanStreetsHeaderMap($headerCells);
        $rows = [];
        foreach ($rowsByIndex as $rowValues) {
            $line = [];
            foreach (array_keys($header['columns']) as $col) {
                $line[] = (string) ($rowValues[$col] ?? '');
            }
            $mapped = $this->fanStreetsMapRowFromColumns($line, $header);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
        return $rows;
    }

    private function fanStreetsHeaderMap(array $headerColumns): array
    {
        $columns = [];
        $index = 0;
        foreach ($headerColumns as $raw) {
            $name = $this->normalizeFanLocalityToken((string) $raw);
            $column = chr(ord('A') + $index);
            $columns[$column] = $name;
            $index++;
            if ($index > 25) {
                break;
            }
        }

        $streetCol = null;
        $localityCol = null;
        $countyCol = null;
        $streetIdCol = null;
        $rangeFromCol = null;
        $rangeToCol = null;
        $parityCol = null;
        $postalCodeCol = null;
        $streetTypeCol = null;
        $agencyCol = null;
        foreach ($columns as $column => $name) {
            if ($streetCol === null && str_contains($name, 'strada')) {
                $streetCol = $column;
            }
            if ($localityCol === null && str_contains($name, 'localitate')) {
                $localityCol = $column;
            }
            if ($countyCol === null && (str_contains($name, 'judet') || str_contains($name, 'county'))) {
                $countyCol = $column;
            }
            if ($streetIdCol === null && str_contains($name, 'strada id')) {
                $streetIdCol = $column;
            }
            if ($rangeFromCol === null && str_contains($name, 'de la')) {
                $rangeFromCol = $column;
            }
            if ($rangeToCol === null && (str_contains($name, 'pana la') || str_contains($name, 'pana'))) {
                $rangeToCol = $column;
            }
            if ($parityCol === null && str_contains($name, 'paritate')) {
                $parityCol = $column;
            }
            if ($postalCodeCol === null && str_contains($name, 'cod postal')) {
                $postalCodeCol = $column;
            }
            if ($streetTypeCol === null && $name === 'tip') {
                $streetTypeCol = $column;
            }
            if ($agencyCol === null && str_contains($name, 'agentie')) {
                $agencyCol = $column;
            }
        }
        return [
            'columns' => $columns,
            'street_id_col' => $streetIdCol ?? 'A',
            'street_col' => $streetCol ?? 'D',
            'locality_col' => $localityCol ?? 'C',
            'county_col' => $countyCol ?? 'B',
            'range_from_col' => $rangeFromCol ?? 'E',
            'range_to_col' => $rangeToCol ?? 'F',
            'parity_col' => $parityCol ?? 'G',
            'postal_code_col' => $postalCodeCol ?? 'H',
            'street_type_col' => $streetTypeCol ?? 'I',
            'agency_col' => $agencyCol ?? 'J',
        ];
    }

    private function fanStreetsMapRowFromColumns(array $line, array $header): ?array
    {
        $columns = $header['columns'] ?? [];
        $streetIdCol = (string) ($header['street_id_col'] ?? 'A');
        $streetCol = (string) ($header['street_col'] ?? 'D');
        $localityCol = (string) ($header['locality_col'] ?? 'C');
        $countyCol = (string) ($header['county_col'] ?? 'B');
        $rangeFromCol = (string) ($header['range_from_col'] ?? 'E');
        $rangeToCol = (string) ($header['range_to_col'] ?? 'F');
        $parityCol = (string) ($header['parity_col'] ?? 'G');
        $postalCodeCol = (string) ($header['postal_code_col'] ?? 'H');
        $streetTypeCol = (string) ($header['street_type_col'] ?? 'I');
        $agencyCol = (string) ($header['agency_col'] ?? 'J');
        $lineMap = [];
        $position = 0;
        foreach (array_keys($columns) as $column) {
            $lineMap[$column] = trim((string) ($line[$position] ?? ''));
            $position++;
        }
        $streetId = trim((string) ($lineMap[$streetIdCol] ?? ''));
        $street = trim((string) ($lineMap[$streetCol] ?? ''));
        $locality = trim((string) ($lineMap[$localityCol] ?? ''));
        $county = trim((string) ($lineMap[$countyCol] ?? ''));
        $rangeFrom = trim((string) ($lineMap[$rangeFromCol] ?? ''));
        $rangeTo = trim((string) ($lineMap[$rangeToCol] ?? ''));
        $parity = trim((string) ($lineMap[$parityCol] ?? ''));
        $postalCode = trim((string) ($lineMap[$postalCodeCol] ?? ''));
        $streetType = trim((string) ($lineMap[$streetTypeCol] ?? ''));
        $agency = trim((string) ($lineMap[$agencyCol] ?? ''));
        if ($street === '' || $locality === '' || $county === '') {
            return null;
        }
        return [
            'street_id' => $streetId,
            'street' => $street,
            'locality' => $locality,
            'county' => $county,
            'range_from' => $rangeFrom,
            'range_to' => $rangeTo,
            'parity' => $parity,
            'postal_code' => $postalCode,
            'street_type' => $streetType,
            'agency' => $agency,
        ];
    }

    private function fanSimpleListRowsFromCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $rows = [];
        $header = null;
        while (($line = fgetcsv($handle, 0, ',')) !== false) {
            if (!is_array($line)) {
                continue;
            }
            if ($header === null) {
                $header = $this->fanSimpleHeaderMap($line);
                continue;
            }
            $mapped = $this->fanSimpleMapRowFromColumns($line, $header);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
        fclose($handle);
        return $rows;
    }

    private function fanSimpleListRowsFromXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    $parts = [];
                    if (isset($si->t)) {
                        $parts[] = (string) $si->t;
                    }
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $parts[] = (string) ($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = trim(implode('', $parts));
                }
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!is_string($sheetXml) || $sheetXml === '') {
            $zip->close();
            return [];
        }
        $sx = @simplexml_load_string($sheetXml);
        $zip->close();
        if ($sx === false || !isset($sx->sheetData->row)) {
            return [];
        }

        $rowsByIndex = [];
        foreach ($sx->sheetData->row as $row) {
            $rowValues = [];
            foreach ($row->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $col = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?: '';
                if ($col === '') {
                    continue;
                }
                $type = (string) ($cell['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $value = (string) ($sharedStrings[$idx] ?? '');
                } elseif (isset($cell->is->t)) {
                    $value = (string) $cell->is->t;
                } else {
                    $value = (string) ($cell->v ?? '');
                }
                $rowValues[$col] = trim($value);
            }
            if ($rowValues !== []) {
                $rowsByIndex[] = $rowValues;
            }
        }
        if ($rowsByIndex === []) {
            return [];
        }

        $first = array_shift($rowsByIndex);
        $headerCells = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
            if (array_key_exists($col, $first)) {
                $headerCells[] = $first[$col];
            }
        }
        $header = $this->fanSimpleHeaderMap($headerCells);
        $rows = [];
        foreach ($rowsByIndex as $rowValues) {
            $line = [];
            foreach (array_keys($header['columns']) as $col) {
                $line[] = (string) ($rowValues[$col] ?? '');
            }
            $mapped = $this->fanSimpleMapRowFromColumns($line, $header);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }

    private function fanSimpleHeaderMap(array $headerColumns): array
    {
        return $this->fanHeaderMap($headerColumns);
    }

    private function fanSimpleMapRowFromColumns(array $line, array $header): ?array
    {
        return $this->fanMapRowFromColumns($line, $header);
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


    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\- ]/i', '', $value) ?? '';
        $value = preg_replace('/[\s\-]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function normalizePageSlug(string $value): string
    {
        $value = trim($value);
        $value = trim($value, '/');
        if ($value === '') {
            return '';
        }

        $segments = array_filter(
            array_map(static fn (string $segment): string => trim($segment), explode('/', $value)),
            static fn (string $segment): bool => $segment !== ''
        );
        if ($segments === []) {
            return '';
        }

        $normalized = [];
        foreach ($segments as $segment) {
            $part = $this->slugify($segment);
            if ($part !== '') {
                $normalized[] = $part;
            }
        }

        return implode('/', $normalized);
    }

    private function handleMediaUpload(mixed $file, string $namespace, string $preferredName = ''): ?array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return null;
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return null;
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4'];
        if (!in_array($ext, $allowed, true)) {
            return null;
        }

        $publicDir = __DIR__ . '/../../../public/uploads/' . $namespace;
        if (!is_dir($publicDir)) {
            @mkdir($publicDir, 0775, true);
        }

        $originalName = trim((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME));
        $baseFromTitle = $this->slugify($preferredName);
        $baseFromOriginal = $this->slugify($originalName);
        $baseName = $baseFromTitle !== ''
            ? $baseFromTitle
            : ($baseFromOriginal !== '' ? $baseFromOriginal : 'media');

        $filename = $baseName . '.' . $ext;
        $targetPath = $publicDir . '/' . $filename;
        if (is_file($targetPath)) {
            $index = 1;
            do {
                $filename = $baseName . '-' . $index . '.' . $ext;
                $targetPath = $publicDir . '/' . $filename;
                $index++;
            } while (is_file($targetPath));
        }

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            return null;
        }

        return [
            'url' => '/uploads/' . $namespace . '/' . $filename,
            'media_type' => $this->detectMediaType($filename),
        ];
    }

    private function detectMediaType(string $path): string
    {
        $urlPath = (string) (parse_url($path, PHP_URL_PATH) ?? $path);
        $ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        return $ext === 'mp4' ? 'video' : 'image';
    }

    private function galleryMoveResponse(bool $ok, string $message): void
    {
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($ok ? 200 : 422);
            echo json_encode([
                'ok' => $ok,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        Flash::set($ok ? 'success' : 'error', $message);
        header('Location: /admin/gallery');
    }

    private function safeGalleryBackUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/admin/gallery';
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if ($path !== '/admin/gallery') {
            return '/admin/gallery';
        }

        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return '/admin/gallery';
        }

        return '/admin/gallery?' . $query;
    }

    private function deleteLocalUploadedFile(string $url): void
    {
        $urlPath = (string) (parse_url($url, PHP_URL_PATH) ?? $url);
        if ($urlPath === '') {
            return;
        }

        if (!str_starts_with($urlPath, '/uploads/')) {
            return;
        }

        $relativePath = ltrim($urlPath, '/');
        if (str_contains($relativePath, '..')) {
            return;
        }

        $absolute = __DIR__ . '/../../../public/' . $relativePath;
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
