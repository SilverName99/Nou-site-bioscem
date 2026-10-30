<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;
use Throwable;

final class NewsletterService
{
    private const UNSUBSCRIBE_FOOTER_START = '<!-- newsletter-unsubscribe-footer:start -->';
    private const UNSUBSCRIBE_FOOTER_END = '<!-- newsletter-unsubscribe-footer:end -->';

    /** De câte ori se încearcă, cel mult, o adresă care a fost respinsă. */
    public const MAX_INCERCARI = 3;

    /**
     * După atâtea respingeri la rând, serverul de email are o problemă (parolă
     * schimbată, limită atinsă), nu adresele: campania se oprește și așteaptă
     * un om, în loc să marcheze toată lista drept „eșuată”.
     */
    public const ESECURI_LA_RAND_PAUZA = 5;

    /** Stările unei campanii, cu numele arătat în admin. */
    public const STARI = [
        'draft' => 'Ciornă',
        'scheduled' => 'Programat',
        'sending' => 'Se trimite',
        'paused' => 'Oprit',
        'sent' => 'Trimis',
    ];

    public static function ensureSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS newsletter_lists (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL UNIQUE,
                    description VARCHAR(255) DEFAULT NULL,
                    is_default TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )'
            );
        } catch (Throwable) {
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS newsletter_subscribers (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(190) NOT NULL UNIQUE,
                    name VARCHAR(190) DEFAULT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT "active",
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )'
            );
        } catch (Throwable) {
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS newsletter_list_subscribers (
                    list_id INT UNSIGNED NOT NULL,
                    subscriber_id INT UNSIGNED NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (list_id, subscriber_id)
                )'
            );
        } catch (Throwable) {
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS newsletter_campaigns (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    template_type VARCHAR(20) NOT NULL DEFAULT "newsletter",
                    template_ref VARCHAR(190) DEFAULT NULL,
                    subscriber_list_id INT UNSIGNED DEFAULT NULL,
                    subject VARCHAR(255) NOT NULL,
                    blocks_json LONGTEXT DEFAULT NULL,
                    html_content LONGTEXT NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT "draft",
                    scheduled_at DATETIME DEFAULT NULL,
                    sent_at DATETIME DEFAULT NULL,
                    total_recipients INT NOT NULL DEFAULT 0,
                    total_sent INT NOT NULL DEFAULT 0,
                    total_failed INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )'
            );
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE newsletter_campaigns ADD COLUMN blocks_json LONGTEXT DEFAULT NULL AFTER subject');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE newsletter_campaigns ADD COLUMN subscriber_list_ids TEXT DEFAULT NULL AFTER subscriber_list_id');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE newsletter_campaigns ADD COLUMN total_opens INT UNSIGNED DEFAULT 0');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE newsletter_campaigns ADD COLUMN total_clicks INT UNSIGNED DEFAULT 0');
        } catch (Throwable) {
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS newsletter_campaign_clicks (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    campaign_id INT UNSIGNED NOT NULL,
                    subscriber_id INT UNSIGNED DEFAULT NULL,
                    email VARCHAR(190) NOT NULL,
                    url VARCHAR(2048) NOT NULL,
                    clicked_at DATETIME NOT NULL,
                    ip VARCHAR(64) DEFAULT NULL,
                    INDEX idx_campaign_id (campaign_id)
                )'
            );
        } catch (Throwable) {
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS newsletter_campaign_opens (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    campaign_id INT UNSIGNED NOT NULL,
                    send_id INT UNSIGNED DEFAULT NULL,
                    opened_at DATETIME NOT NULL,
                    ip VARCHAR(64) DEFAULT NULL,
                    INDEX idx_campaign_id (campaign_id)
                )'
            );
        } catch (Throwable) {
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS newsletter_campaign_sends (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    campaign_id INT UNSIGNED NOT NULL,
                    subscriber_id INT UNSIGNED DEFAULT NULL,
                    email VARCHAR(190) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT "sent",
                    error_message TEXT DEFAULT NULL,
                    sent_at DATETIME DEFAULT NULL,
                    opened_at DATETIME DEFAULT NULL,
                    open_count INT UNSIGNED DEFAULT 0,
                    clicked_at DATETIME DEFAULT NULL,
                    click_count INT UNSIGNED DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_campaign_subscriber (campaign_id, subscriber_id)
                )'
            );
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE newsletter_campaign_sends ADD COLUMN opened_at DATETIME DEFAULT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE newsletter_campaign_sends ADD COLUMN open_count INT UNSIGNED DEFAULT 0');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE newsletter_campaign_sends ADD COLUMN clicked_at DATETIME DEFAULT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE newsletter_campaign_sends ADD COLUMN click_count INT UNSIGNED DEFAULT 0');
        } catch (Throwable) {
        }
        // Câte încercări a avut adresa: un mesaj respins se reîncearcă de cel
        // mult MAX_INCERCARI ori, nu la fiecare trecere a cronului, la nesfârșit.
        try {
            $db->exec('ALTER TABLE newsletter_campaign_sends ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0');
        } catch (Throwable) {
        }
        // Plasa de siguranță a evidenței: o adresă are un singur rând per
        // campanie. Există deja în schema.sql; aici doar pentru bazele vechi.
        try {
            $db->exec('ALTER TABLE newsletter_campaign_sends ADD UNIQUE KEY uniq_campaign_subscriber (campaign_id, subscriber_id)');
        } catch (Throwable) {
        }
        // Dubla confirmare (double opt-in): când a cerut omul abonarea și când a
        // confirmat-o din email. Un abonat nou din formular stă „pending” până
        // la confirmare; un dezabonat care cere reabonarea rămâne „unsubscribed”,
        // cu pending_at completat, tot până la confirmare.
        try {
            $db->exec('ALTER TABLE newsletter_subscribers ADD COLUMN pending_at DATETIME DEFAULT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE newsletter_subscribers ADD COLUMN confirmed_at DATETIME DEFAULT NULL');
        } catch (Throwable) {
        }
        // Lista „nu trimite”: doar amprenta (SHA-256) adresei unui abonat șters,
        // ca un import sau o adăugare din admin să nu-l readucă.
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS newsletter_suprimate (
                    email_hash CHAR(64) NOT NULL PRIMARY KEY,
                    motiv VARCHAR(40) NOT NULL DEFAULT "stergere_admin",
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )'
            );
        } catch (Throwable) {
        }
        // Cererile de confirmare trimise, pentru limita pe adresă și pe IP
        // (formularul nu poate fi folosit ca să umple inboxul cuiva).
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS newsletter_cereri_confirmare (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    email_hash CHAR(64) NOT NULL,
                    ip VARCHAR(64) DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_email_creat (email_hash, created_at),
                    INDEX idx_ip_creat (ip, created_at)
                )'
            );
        } catch (Throwable) {
        }
        // Același jurnal ține și emailurile „linkul tău de dezabonare” (pentru
        // linkurile vechi), cu limita lor.
        try {
            $db->exec('ALTER TABLE newsletter_cereri_confirmare ADD COLUMN tip VARCHAR(20) NOT NULL DEFAULT "abonare"');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE newsletter_cereri_confirmare ADD INDEX idx_creat (created_at)');
        } catch (Throwable) {
        }
        // De ce s-a oprit o campanie (server de email care refuză, listă
        // ștearsă), ca omul din admin să vadă motivul, nu doar „oprit”.
        try {
            $db->exec('ALTER TABLE newsletter_campaigns ADD COLUMN last_error TEXT DEFAULT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE orders ADD COLUMN nl_campaign_id INT UNSIGNED DEFAULT NULL');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE orders ADD INDEX idx_nl_campaign_id (nl_campaign_id)');
        } catch (Throwable) {
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS newsletter_optin_forms (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    slug VARCHAR(190) NOT NULL UNIQUE,
                    list_id INT UNSIGNED NOT NULL,
                    button_label VARCHAR(120) NOT NULL DEFAULT "Ma abonez",
                    success_message VARCHAR(255) NOT NULL DEFAULT "Te-ai abonat cu succes.",
                    fields_json LONGTEXT NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )'
            );
        } catch (Throwable) {
        }

        try {
            $db->exec(
                'INSERT INTO newsletter_lists (name, description, is_default)
                 SELECT "Toți abonații", "Listă implicită", 1
                 WHERE NOT EXISTS (SELECT 1 FROM newsletter_lists WHERE is_default = 1)'
            );
        } catch (Throwable) {
        }
        // Pragul pentru linkurile semnate cu cheia veche se fixează la prima
        // rulare după actualizare, înainte să plece ceva semnat cu cheia nouă.
        self::limitaCheieVeche($db);
    }

    public static function defaultListId(PDO $db): int
    {
        if (!$db->inTransaction()) {
            self::ensureSchema($db);
        }
        $id = (int) $db->query('SELECT id FROM newsletter_lists WHERE is_default = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        $stmt = $db->prepare('INSERT INTO newsletter_lists (name, description, is_default) VALUES (:name, :description, 1)');
        $stmt->execute([
            'name' => 'Toți abonații',
            'description' => 'Listă implicită',
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Adaugă adresa în listă (și în lista implicită).
     *
     * @param bool $reactiveaza Doar când omul se abonează SINGUR (formularul
     *   opt-in de pe site). Un import, adăugarea din admin sau importul din
     *   WordPress nu au voie să-l reaboneze pe cine s-a dezabonat: retragerea
     *   consimțământului rămâne în picioare până o schimbă chiar persoana.
     *   Înainte, orice import punea „active” peste „unsubscribed”.
     */
    public static function subscribeToList(PDO $db, int $listId, string $email, string $name = '', bool $reactiveaza = false): int
    {
        if (!$db->inTransaction()) {
            self::ensureSchema($db);
        }
        $email = strtolower(trim($email));
        $name = trim($name);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email invalid.');
        }

        // Lista „nu trimite”: o adresă ștearsă la cererea omului nu revine prin
        // import sau adăugare din admin (doar el, prin dubla confirmare).
        if (!$reactiveaza && self::esteSuprimat($db, $email)) {
            throw new AdresaSuprimata('Adresa ' . $email . ' e pe lista „nu trimite”.');
        }

        if ($listId <= 0) {
            $listId = self::defaultListId($db);
        }

        $subscriberId = 0;
        $find = $db->prepare('SELECT id FROM newsletter_subscribers WHERE email = :email LIMIT 1');
        $find->execute(['email' => $email]);
        $existingId = (int) ($find->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $subscriberId = $existingId;
            // Un nume gol (importurile fără coloană de nume) nu șterge numele
            // știut deja.
            $update = $db->prepare(
                'UPDATE newsletter_subscribers
                 SET name = CASE WHEN :name_gol = 1 THEN name ELSE :name END'
                 . ($reactiveaza ? ', status = "active"' : '') . '
                 WHERE id = :id'
            );
            $update->execute([
                'name_gol' => $name === '' ? 1 : 0,
                'name' => $name,
                'id' => $subscriberId,
            ]);
        } else {
            $insert = $db->prepare(
                'INSERT INTO newsletter_subscribers (email, name, status)
                 VALUES (:email, :name, "active")'
            );
            $insert->execute([
                'email' => $email,
                'name' => $name,
            ]);
            $subscriberId = (int) $db->lastInsertId();
        }

        $defaultListId = self::defaultListId($db);
        $link = $db->prepare(
            'INSERT IGNORE INTO newsletter_list_subscribers (list_id, subscriber_id)
             VALUES (:list_id, :subscriber_id)'
        );
        $link->execute([
            'list_id' => $defaultListId,
            'subscriber_id' => $subscriberId,
        ]);
        if ($listId !== $defaultListId) {
            $link->execute([
                'list_id' => $listId,
                'subscriber_id' => $subscriberId,
            ]);
        }

        return $subscriberId;
    }

    /** Adresa există și s-a dezabonat (singură sau din admin). */
    public static function esteDezabonat(PDO $db, string $email): bool
    {
        $stmt = $db->prepare('SELECT status FROM newsletter_subscribers WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        return (string) ($stmt->fetchColumn() ?: '') === 'unsubscribed';
    }

    /** Cât e valabil linkul de confirmare (și cât stă o cerere neconfirmată). */
    public const ZILE_CONFIRMARE = 30;

    /**
     * Amprenta unei adrese pentru lista „nu trimite” și pentru limite: SHA-256
     * al adresei normalizate (fără spații, litere mici). Ajunge pentru căutare;
     * adresa însăși nu se mai păstrează.
     */
    public static function amprentaAdresa(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    public static function esteSuprimat(PDO $db, string $email): bool
    {
        try {
            $stmt = $db->prepare('SELECT 1 FROM newsletter_suprimate WHERE email_hash = :h LIMIT 1');
            $stmt->execute(['h' => self::amprentaAdresa($email)]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /** Pune adresa pe lista „nu trimite” (doar amprenta). */
    public static function suprima(PDO $db, string $email, string $motiv = 'stergere_admin'): void
    {
        $db->prepare('INSERT IGNORE INTO newsletter_suprimate (email_hash, motiv) VALUES (:h, :m)')
            ->execute(['h' => self::amprentaAdresa($email), 'm' => substr($motiv, 0, 40)]);
    }

    /**
     * Câte emailuri de confirmare a abonării pleacă pe oră, cel mult, pe tot
     * site-ul. Emailurile „linkul tău de dezabonare” NU intră aici: altfel
     * cineva cu câteva IP-uri ar umple plafonul și un abonat real n-ar mai
     * putea primi linkul de dezabonare (problemă GDPR). Pe ele le limitează
     * „unul pe abonat la 24 de ore”.
     */
    public const LIMITA_GLOBALA_ORA = 300;

    /** Câte cereri pe oră se primesc de la aceeași adresă IP (IPv4) sau rețea /64 (IPv6). */
    public const LIMITA_IP_ORA = 20;

    /**
     * Cheia după care se numără limita pe IP: adresa IPv4 ca atare, iar la IPv6
     * prefixul /64 (un singur client primește de obicei o rețea /64 întreagă,
     * deci altfel ar avea miliarde de „IP-uri”).
     */
    public static function cheieIp(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binar = inet_pton($ip);
            if ($binar !== false) {
                $prefix = inet_ntop(substr($binar, 0, 8) . str_repeat("\0", 8));
                return $prefix !== false ? $prefix . '/64' : $ip;
            }
        }
        return substr($ip, 0, 64);
    }

    /**
     * Rezervă dreptul de a trimite un email declanșat din pagina publică
     * (confirmarea abonării, linkul de dezabonare). Numărarea și înregistrarea
     * se fac sub același lacăt (GET_LOCK), deci zeci de cereri în paralel nu mai
     * pot trece toate de limite înainte să fie scrise. Înainte, limitele se
     * numărau întâi și cererea se scria abia la final: 40 de cereri simultane
     * pentru aceeași adresă au trimis 17 emailuri.
     *
     * Limite: pe IP (REMOTE_ADDR; IPv6 pe /64) LIMITA_IP_ORA pe oră; pe adresă:
     * la abonare un email la 5 minute și 3 pe zi, la linkul de dezabonare unul
     * pe zi; doar la abonare, și LIMITA_GLOBALA_ORA pe oră pe tot site-ul.
     *
     * @return string ok | limita_adresa | limita_ip | limita_globala | ocupat
     */
    private static int $ultimaRezervare = 0;

    /** Emailul n-a plecat: rezervarea nu consumă limita, omul poate reîncerca. */
    private static function anuleazaRezervarea(PDO $db): void
    {
        if (self::$ultimaRezervare > 0) {
            $db->prepare('DELETE FROM newsletter_cereri_confirmare WHERE id = :id')->execute(['id' => self::$ultimaRezervare]);
            self::$ultimaRezervare = 0;
        }
    }

    private static function rezervaTrimitere(PDO $db, string $tip, string $amprenta, string $ip): string
    {
        $ip = $ip !== '' ? self::cheieIp($ip) : '';
        $lacat = 'nl_optin_' . substr(sha1((string) ($db->query('SELECT DATABASE()')->fetchColumn() ?: '')), 0, 16);
        try {
            $ia = $db->prepare('SELECT GET_LOCK(:n, 5)');
            $ia->execute(['n' => $lacat]);
            if ((int) $ia->fetchColumn() !== 1) {
                return 'ocupat';
            }
        } catch (Throwable) {
            return 'ocupat';
        }
        try {
            if ($ip !== '') {
                $peIp = $db->prepare('SELECT COUNT(*) FROM newsletter_cereri_confirmare WHERE ip = :ip AND created_at > NOW() - INTERVAL 1 HOUR');
                $peIp->execute(['ip' => $ip]);
                if ((int) $peIp->fetchColumn() >= self::LIMITA_IP_ORA) {
                    return 'limita_ip';
                }
            }
            $peAdresa = $db->prepare(
                'SELECT COUNT(*) AS pe_zi, COALESCE(SUM(created_at > NOW() - INTERVAL 5 MINUTE), 0) AS recent
                 FROM newsletter_cereri_confirmare
                 WHERE email_hash = :h AND tip = :tip AND created_at > NOW() - INTERVAL 1 DAY'
            );
            $peAdresa->execute(['h' => $amprenta, 'tip' => $tip]);
            $limite = $peAdresa->fetch() ?: [];
            $maxPeZi = $tip === 'abonare' ? 3 : 1;
            if ((int) ($limite['recent'] ?? 0) > 0 || (int) ($limite['pe_zi'] ?? 0) >= $maxPeZi) {
                return 'limita_adresa';
            }
            $global = $tip === 'abonare'
                ? (int) $db->query('SELECT COUNT(*) FROM newsletter_cereri_confirmare WHERE tip = "abonare" AND created_at > NOW() - INTERVAL 1 HOUR')->fetchColumn()
                : 0;
            if ($global >= self::LIMITA_GLOBALA_ORA) {
                // Se vede în admin (Liste abonați, Statistici): poate fi un atac.
                Settings::save($db, ['newsletter_limita_globala_atinsa' => date('Y-m-d H:i:s')]);
                return 'limita_globala';
            }
            $db->prepare('INSERT INTO newsletter_cereri_confirmare (email_hash, ip, tip, created_at) VALUES (:h, :ip, :tip, NOW())')
                ->execute(['h' => $amprenta, 'ip' => $ip !== '' ? $ip : null, 'tip' => $tip]);
            self::$ultimaRezervare = (int) $db->lastInsertId();
            return 'ok';
        } finally {
            try {
                $db->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $lacat]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * Cererea de abonare din formularul public (dubla confirmare).
     *
     * Nu abonează pe nimeni direct: o adresă nouă devine „pending”, una
     * dezabonată rămâne dezabonată (cu pending_at), iar omul primește un email
     * cu linkul de confirmare. Doar clicul lui din email (și butonul de pe
     * pagină) îl face abonat activ. Un abonat activ nu se atinge.
     *
     * `$ip` trebuie să fie REMOTE_ADDR (pe Hostinger/LiteSpeed e deja IP-ul
     * real al clientului); X-Forwarded-For se poate falsifica.
     *
     * @return array{stare: string, email: string} stare: trimis | deja_activ | limita_adresa | limita_ip | limita_globala | ocupat
     */
    public static function cerereAbonare(PDO $db, int $listId, string $email, string $name, string $ip, array $settings): array
    {
        self::ensureSchema($db);
        $email = strtolower(trim($email));
        $name = trim($name);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresa de email este invalidă.');
        }
        $ip = substr(trim($ip), 0, 64);

        $rand = $db->prepare('SELECT id, status FROM newsletter_subscribers WHERE email = :email LIMIT 1');
        $rand->execute(['email' => $email]);
        $existent = $rand->fetch();
        if ($listId <= 0) {
            $listId = self::defaultListId($db);
        }
        $leaga = function (int $id) use ($db, $listId): void {
            $link = $db->prepare('INSERT IGNORE INTO newsletter_list_subscribers (list_id, subscriber_id) VALUES (:l, :s)');
            $link->execute(['l' => self::defaultListId($db), 's' => $id]);
            $link->execute(['l' => $listId, 's' => $id]);
        };
        if (is_array($existent) && (string) $existent['status'] === 'active') {
            // Deja abonat (și deci confirmat): doar lista din formular, fără email.
            $leaga((int) $existent['id']);
            return ['stare' => 'deja_activ', 'email' => $email];
        }

        $rezervare = self::rezervaTrimitere($db, 'abonare', self::amprentaAdresa($email), $ip);
        if ($rezervare !== 'ok') {
            return ['stare' => $rezervare, 'email' => $email];
        }

        $acum = date('Y-m-d H:i:s');
        $rand->execute(['email' => $email]);
        $existent = $rand->fetch();
        if (!is_array($existent)) {
            try {
                $db->prepare('INSERT INTO newsletter_subscribers (email, name, status, pending_at) VALUES (:e, :n, "pending", :p)')
                    ->execute(['e' => $email, 'n' => $name, 'p' => $acum]);
                $id = (int) $db->lastInsertId();
            } catch (Throwable) {
                // Rândul a apărut între timp (altă cerere pentru aceeași adresă).
                $rand->execute(['email' => $email]);
                $id = (int) (($rand->fetch() ?: [])['id'] ?? 0);
            }
        } else {
            $id = (int) $existent['id'];
            if ((string) $existent['status'] === 'active') {
                $leaga($id);
                return ['stare' => 'deja_activ', 'email' => $email];
            }
            // „pending” rămâne „pending”; „unsubscribed” rămâne dezabonat până la clic.
            $db->prepare('UPDATE newsletter_subscribers SET pending_at = :p, name = CASE WHEN :gol = 1 THEN name ELSE :n END WHERE id = :id')
                ->execute(['p' => $acum, 'gol' => $name === '' ? 1 : 0, 'n' => $name, 'id' => $id]);
        }
        if ($id <= 0) {
            throw new RuntimeException('Nu am putut înregistra cererea de abonare.');
        }
        $leaga($id);

        $url = self::urlConfirmare($id, $email, (int) strtotime($acum));
        $numeMagazin = trim((string) ($settings['order_email_from_name'] ?? '')) ?: 'Bioscem';
        $subiect = 'Confirmă abonarea la newsletterul ' . $numeMagazin;
        $html = self::renderHtmlFromBlocks(NewsletterSabloane::emailConfirmare($url, $numeMagazin), $subiect, false);
        try {
            OrderMailer::sendCustom($email, $subiect, $html, $settings, $db, [
                'email_type' => 'newsletter_confirmare',
                'source' => 'newsletter_optin',
                'meta' => ['subscriber_id' => $id],
            ]);
        } catch (Throwable $exception) {
            self::anuleazaRezervarea($db);
            throw $exception instanceof RuntimeException ? $exception : new RuntimeException($exception->getMessage());
        }
        return ['stare' => 'trimis', 'email' => $email];
    }

    /**
     * Linkurile vechi de dezabonare (semnate doar cu cheia veche, ghicibilă):
     * butonul de pe pagină cere serverului să trimită, pe adresa abonatului,
     * un link nou (cheia secretă). Doar cine are acces la inbox se poate apoi
     * dezabona.
     *
     * Rezultat: „gata” (trimis, sau nimic de trimis: abonat inexistent, deja
     * dezabonat ori link primit deja azi; răspuns identic, ca pagina să nu spună
     * cine e abonat), „limita_ip”, „ocupat” sau „eroare” (emailul chiar n-a
     * plecat; pagina o spune, ca nimeni să nu aștepte degeaba un email).
     * Limite: un email pe abonat la 24 de ore și limita pe IP; plafonul global
     * nu se aplică (n-are voie să blocheze o dezabonare).
     */
    public static function trimiteLinkDezabonare(PDO $db, string $token, string $ip, array $settings): string
    {
        $parsed = self::parseUnsubscribeToken($token);
        if (!is_array($parsed) || empty($parsed['vechi'])) {
            return 'gata';
        }
        self::ensureSchema($db);
        $rand = $db->prepare('SELECT id, email, status FROM newsletter_subscribers WHERE id = :id AND email = :email LIMIT 1');
        $rand->execute(['id' => (int) $parsed['id'], 'email' => (string) $parsed['email']]);
        $abonat = $rand->fetch();
        if (!is_array($abonat) || (string) $abonat['status'] === 'unsubscribed') {
            return 'gata';
        }
        $rezervare = self::rezervaTrimitere($db, 'dezabonare', hash('sha256', 'dez|' . (int) $abonat['id']), trim($ip));
        if ($rezervare === 'limita_adresa') {
            return 'gata';
        }
        if ($rezervare !== 'ok') {
            return $rezervare;
        }
        $numeMagazin = trim((string) ($settings['order_email_from_name'] ?? '')) ?: 'Bioscem';
        $subiect = 'Linkul tău de dezabonare de la newsletterul ' . $numeMagazin;
        $html = self::renderHtmlFromBlocks(
            NewsletterSabloane::emailLinkDezabonare(self::unsubscribeUrlForSubscriber($abonat), $numeMagazin),
            $subiect,
            false
        );
        try {
            OrderMailer::sendCustom((string) $abonat['email'], $subiect, $html, $settings, $db, [
                'email_type' => 'newsletter_link_dezabonare',
                'source' => 'newsletter_unsubscribe',
                'meta' => ['subscriber_id' => (int) $abonat['id']],
            ]);
        } catch (Throwable) {
            // Eroarea rămâne și în istoricul trimiterilor. Omul poate reîncerca.
            self::anuleazaRezervarea($db);
            return 'eroare';
        }
        return 'gata';
    }

    /** Linkul de confirmare, semnat cu cheia nouă (niciodată cu cea veche). */
    public static function urlConfirmare(int $subscriberId, string $email, int $moment): string
    {
        $plata = self::base64UrlEncode('c|' . $subscriberId . '|' . strtolower(trim($email)) . '|' . $moment);
        $token = $plata . '.' . self::base64UrlEncode(hash_hmac('sha256', $plata, self::cheieSemnare(), true));
        return self::appBaseUrl() . '/newsletter/confirmare/' . rawurlencode($token);
    }

    /**
     * Verifică linkul de confirmare, fără să schimbe nimic.
     *
     * @return array{ok: bool, motiv: string, email: string, id: int} motiv: invalid | expirat | deja_activ
     */
    public static function verificaConfirmare(PDO $db, string $token): array
    {
        $nu = fn(string $motiv, string $email = '') => ['ok' => false, 'motiv' => $motiv, 'email' => $email, 'id' => 0];
        $token = trim($token);
        if (!str_contains($token, '.')) {
            return $nu('invalid');
        }
        [$plata, $semnatura] = explode('.', $token, 2);
        $potrivire = false;
        foreach (self::cheiNoi() as $cheie) {
            if (hash_equals(self::base64UrlEncode(hash_hmac('sha256', $plata, $cheie, true)), $semnatura)) {
                $potrivire = true;
                break;
            }
        }
        $brut = $potrivire ? self::base64UrlDecode($plata) : null;
        $parti = $brut !== null ? explode('|', $brut) : [];
        if (count($parti) !== 4 || $parti[0] !== 'c') {
            return $nu('invalid');
        }
        $id = (int) $parti[1];
        $email = strtolower(trim($parti[2]));
        $moment = (int) $parti[3];
        if ($moment < time() - self::ZILE_CONFIRMARE * 86400) {
            return $nu('expirat', $email);
        }
        self::ensureSchema($db);
        $stmt = $db->prepare('SELECT status, pending_at FROM newsletter_subscribers WHERE id = :id AND email = :email LIMIT 1');
        $stmt->execute(['id' => $id, 'email' => $email]);
        $rand = $stmt->fetch();
        if (!is_array($rand)) {
            return $nu('expirat', $email);
        }
        if ((string) $rand['status'] === 'active') {
            return ['ok' => false, 'motiv' => 'deja_activ', 'email' => $email, 'id' => $id];
        }
        if (empty($rand['pending_at'])) {
            return $nu('expirat', $email);
        }
        return ['ok' => true, 'motiv' => '', 'email' => $email, 'id' => $id];
    }

    /**
     * Clicul pe „Confirm abonarea”: abonatul devine activ, cu ora confirmării,
     * iar adresa iese de pe lista „nu trimite” (consimțământ nou, al lui).
     */
    public static function confirmaAbonare(PDO $db, string $token): array
    {
        $rezultat = self::verificaConfirmare($db, $token);
        if (!$rezultat['ok']) {
            return $rezultat;
        }
        $db->prepare(
            'UPDATE newsletter_subscribers
             SET status = "active", confirmed_at = NOW(), pending_at = NULL
             WHERE id = :id AND status IN ("pending", "unsubscribed") AND pending_at IS NOT NULL'
        )->execute(['id' => $rezultat['id']]);
        $db->prepare('DELETE FROM newsletter_suprimate WHERE email_hash = :h')
            ->execute(['h' => self::amprentaAdresa($rezultat['email'])]);
        return $rezultat;
    }

    /**
     * Curățenie (din cron): cererile neconfirmate în ZILE_CONFIRMARE zile.
     * Adresele noi neconfirmate se șterg de tot (n-au dat niciun acord); la un
     * dezabonat se șterge doar cererea. Jurnalul limitelor se păstrează 2 zile.
     */
    public static function curataCereriExpirate(PDO $db): int
    {
        $prag = date('Y-m-d H:i:s', time() - self::ZILE_CONFIRMARE * 86400);
        $db->prepare(
            'DELETE ls FROM newsletter_list_subscribers ls
             INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
             WHERE s.status = "pending" AND (s.pending_at IS NULL OR s.pending_at < :p)'
        )->execute(['p' => $prag]);
        $sterse = $db->prepare('DELETE FROM newsletter_subscribers WHERE status = "pending" AND (pending_at IS NULL OR pending_at < :p)');
        $sterse->execute(['p' => $prag]);
        $db->prepare('UPDATE newsletter_subscribers SET pending_at = NULL WHERE status <> "pending" AND pending_at < :p')
            ->execute(['p' => $prag]);
        $db->exec('DELETE FROM newsletter_cereri_confirmare WHERE created_at < NOW() - INTERVAL 2 DAY');
        return $sterse->rowCount();
    }

    /**
     * @param bool $cuFooterDezabonare Emailurile tranzacționale (confirmare de
     *   comandă, expediere) nu sunt newsletter: nu primesc subsol de
     *   dezabonare. Fără el, subsolul ar afișa „Email de test", pentru că nu
     *   există un link de dezabonare real.
     */
    public static function renderHtmlFromBlocks(
        array $blocks,
        string $subject,
        bool $cuFooterDezabonare = true
    ): string {
        $normalized = self::normalizeBlocks($blocks);
        $parts = [];
        foreach ($normalized as $block) {
            $parts[] = self::wrapDeviceHide($block, self::renderBlock($block));
        }
        $subjectSafe = self::esc($subject);

        // Mobile-first defaults (work even when the client strips <head> media queries,
        // e.g. Gmail Android with non-Google accounts). Desktop chrome is added via a
        // min-width media query, which desktop clients honor reliably.
        $html = '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
            . 'body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}'
            . 'body{margin:0!important;padding:0!important;width:100%!important;}'
            . 'table,td{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;}'
            . '@media only screen and (min-width:601px){'
            . '.nl-body{padding:24px 0!important;}'
            . '.nl-card{border:1px solid #e5e7eb!important;border-radius:12px!important;overflow:hidden!important;}'
            . '}'
            . '@media only screen and (max-width:640px){'
            . '.nl-text-desktop{display:none!important;}'
            . '.nl-text-mobile{display:block!important;}'
            . '}'
            . '@media only screen and (max-width:600px){.nl-hide-mobile{display:none!important;max-height:0!important;overflow:hidden!important;mso-hide:all;}}'
            . '@media only screen and (min-width:601px) and (max-width:900px){.nl-hide-tablet{display:none!important;max-height:0!important;overflow:hidden!important;}}'
            . '@media only screen and (min-width:901px){.nl-hide-desktop{display:none!important;max-height:0!important;overflow:hidden!important;}}'
            . '</style><title>'
            . $subjectSafe
            . '</title></head>'
            . '<body class="nl-body" style="margin:0;padding:0;Margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#1f2937;">'
            . '<div class="nl-card" style="max-width:680px;margin:0 auto;background:#ffffff;">'
            . implode('', $parts)
            . '</div>'
            . '</body></html>';

        return $cuFooterDezabonare ? self::withNewsletterFooter($html) : $html;
    }

    /**
     * Scoate subsolul de dezabonare dintr-un email deja salvat. Folosit pentru
     * șabloanele de comandă salvate înainte de separarea de mai sus.
     */
    public static function faraFooterDezabonare(string $html): string
    {
        $pattern = '/'
            . preg_quote(self::UNSUBSCRIBE_FOOTER_START, '/')
            . '.*?'
            . preg_quote(self::UNSUBSCRIBE_FOOTER_END, '/')
            . '/si';
        return preg_replace($pattern, '', $html) ?? $html;
    }

    public static function withNewsletterFooter(string $htmlContent, ?string $unsubscribeUrl = null, bool $isTest = false): string
    {
        $html = trim($htmlContent);
        if ($html === '') {
            return $html;
        }

        $pattern = '/'
            . preg_quote(self::UNSUBSCRIBE_FOOTER_START, '/')
            . '.*?'
            . preg_quote(self::UNSUBSCRIBE_FOOTER_END, '/')
            . '/si';
        $html = preg_replace($pattern, '', $html) ?? $html;
        $footer = self::buildUnsubscribeFooterHtml($unsubscribeUrl, $isTest);

        $closingBodyPos = strripos($html, '</body>');
        if ($closingBodyPos !== false) {
            return substr($html, 0, $closingBodyPos) . $footer . substr($html, $closingBodyPos);
        }

        return $html . $footer;
    }

    /**
     * Dezabonează după linkul semnat. Un link semnat DOAR cu cheia veche
     * (ghicibilă) nu dezabonează direct: întoarce `confirmare` = true, iar
     * pagina îi cere omului să apese „Confirm dezabonarea” (formular cu un
     * token nou, semnat cu cheia nouă). Altfel oricine ar fi putut dezabona
     * toată lista cu id-uri la rând.
     */
    public static function unsubscribeByToken(PDO $db, string $token): array
    {
        $parsed = self::parseUnsubscribeToken($token);
        if (!is_array($parsed)) {
            return ['ok' => false, 'email' => ''];
        }
        // Cheia veche: niciodată dezabonare directă, și același răspuns fie că
        // abonatul există, fie că nu (verificarea existenței vine abia după).
        if (!empty($parsed['vechi'])) {
            return ['ok' => false, 'confirmare' => true, 'email' => ''];
        }

        $subscriberId = (int) ($parsed['id'] ?? 0);
        $email = strtolower(trim((string) ($parsed['email'] ?? '')));
        if ($subscriberId <= 0 || $email === '') {
            return ['ok' => false, 'email' => ''];
        }

        self::ensureSchema($db);
        $find = $db->prepare(
            'SELECT id
             FROM newsletter_subscribers
             WHERE id = :id AND email = :email
             LIMIT 1'
        );
        $find->execute([
            'id' => $subscriberId,
            'email' => $email,
        ]);
        $exists = (int) ($find->fetchColumn() ?: 0);
        if ($exists <= 0) {
            return ['ok' => false, 'email' => ''];
        }

        $update = $db->prepare(
            'UPDATE newsletter_subscribers
             SET status = "unsubscribed"
             WHERE id = :id'
        );
        $update->execute(['id' => $subscriberId]);

        return [
            'ok' => true,
            'email' => $email,
        ];
    }

    /**
     * Pentru pagina de la linkul din email (GET): nu schimbă nimic, doar spune
     * pe cine privește și dă tokenul (semnat cu cheia nouă) pentru butonul
     * „Confirm dezabonarea”. Dezabonarea o face doar POST-ul acelui buton, sau
     * POST-ul „cu un clic” al clientului de email.
     *
     * @return array{ok: bool, email: string, token_nou: string, deja: bool}
     */
    public static function verificaDezabonare(PDO $db, string $token): array
    {
        $parsed = self::parseUnsubscribeToken($token);
        if (!is_array($parsed)) {
            return ['ok' => false, 'email' => '', 'token_nou' => '', 'deja' => false, 'vechi' => false];
        }
        if (!empty($parsed['vechi'])) {
            // Cheia veche se poate falsifica: pagina NU dă un token nou și nu
            // spune dacă abonatul există; butonul ei trimite linkul pe email.
            return ['ok' => true, 'email' => '', 'token_nou' => '', 'deja' => false, 'vechi' => true];
        }
        self::ensureSchema($db);
        $stmt = $db->prepare('SELECT status FROM newsletter_subscribers WHERE id = :id AND email = :email LIMIT 1');
        $stmt->execute(['id' => (int) $parsed['id'], 'email' => (string) $parsed['email']]);
        $stare = $stmt->fetchColumn();
        if ($stare === false) {
            return ['ok' => false, 'email' => '', 'token_nou' => '', 'deja' => false, 'vechi' => false];
        }
        return [
            'ok' => true,
            'email' => (string) $parsed['email'],
            'token_nou' => $token,
            'deja' => (string) $stare === 'unsubscribed',
            'vechi' => false,
        ];
    }

    public static function unsubscribeUrlForSubscriber(array $subscriber): string
    {
        $subscriberId = (int) ($subscriber['id'] ?? 0);
        $email = strtolower(trim((string) ($subscriber['email'] ?? '')));
        if ($subscriberId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '#';
        }

        $token = self::buildUnsubscribeToken($subscriberId, $email);
        $path = '/newsletter/unsubscribe/' . rawurlencode($token);
        $baseUrl = self::appBaseUrl();
        if ($baseUrl !== '') {
            return $baseUrl . $path;
        }

        return $path;
    }

    public static function normalizeBlocks(array $blocks): array
    {
        $normalized = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $normalized[] = self::normalizeBlock($block, true, 'Text');
        }
        if ($normalized === []) {
            $normalized[] = [
                'type' => 'text',
                'content' => 'Conținut newsletter',
                'align' => 'left',
                'background' => '#ffffff',
                'text_color' => '#1f2937',
                'font_size' => 15,
            ];
        }

        return $normalized;
    }

    public static function sendCampaignTest(PDO $db, int $campaignId, string $to, array $settings): void
    {
        $campaign = self::loadCampaign($db, $campaignId);
        if (!is_array($campaign)) {
            throw new RuntimeException('Campanie invalidă.');
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email test invalid.');
        }

        $blocksJsonRaw = trim((string) ($campaign['blocks_json'] ?? ''));
        if ($blocksJsonRaw !== '' && $blocksJsonRaw !== '[]') {
            $parsedBlocks = json_decode($blocksJsonRaw, true);
            if (is_array($parsedBlocks) && count($parsedBlocks) > 0) {
                $campaign['html_content'] = self::renderHtmlFromBlocks($parsedBlocks, (string) ($campaign['subject'] ?? ''));
            }
        }

        $testFooterUrl = self::appBaseUrl() !== '' ? self::appBaseUrl() . '#test-email' : '#test-email';
        OrderMailer::sendCustom(
            $to,
            (string) ($campaign['subject'] ?? 'Newsletter test'),
            self::withNewsletterFooter((string) ($campaign['html_content'] ?? '<p>Test.</p>'), $testFooterUrl, true),
            $settings,
            $db,
            [
                'email_type' => 'newsletter_campaign_test',
                'source' => 'newsletter_campaign_test',
                'campaign_id' => $campaignId,
                'trigger' => 'manual_test',
                'meta' => ['campaign_id' => $campaignId],
            ]
        );
        // Testul NU intră în evidența trimiterilor campaniei: acolo se numără
        // abonații serviți. Înainte, fiecare test urca „Trimise” și strica
        // procentele de deschidere; testul rămâne în „Istoric trimiteri”.
    }

    /**
     * Trimite o bucată din campanie și se oprește.
     *
     * Ce nu a apucat să plece rămâne pentru rulările următoare: campania stă în
     * starea „sending" până când nu mai are pe nimeni de servit. `$deadline` (un
     * timestamp) oprește lotul mai devreme, ca o rulare din cron să nu fie tăiată
     * la mijloc de limita de execuție a serverului.
     *
     * Nimeni nu primește aceeași campanie de două ori:
     *  - o campanie e trimisă de un singur proces odată (lacăt GET_LOCK pe
     *    conexiune, eliberat singur dacă procesul moare). Înainte, „Trimite
     *    acum” apăsat de două ori, sau apăsat cât lucra cronul, alegea aceiași
     *    destinatari în ambele procese și le trimitea de două ori;
     *  - fiecare adresă e trecută „pending” ÎNAINTE de trimitere. Dacă procesul
     *    moare între trimitere și „sent”, adresa rămâne „pending” și NU se
     *    retrimite: mai bine un mesaj lipsă decât unul dublu;
     *  - abonarea se verifică din nou chiar înainte de fiecare mesaj, nu doar la
     *    alegerea lotului: cine se dezabonează în timpul trimiterii nu-l primește.
     *
     * `$pornire` spune din ce stare are voie să pornească trimiterea, verificat
     * după lacăt, în baza de date:
     *  - „cron”: doar o campanie deja „sending” (continuarea);
     *  - „programat”: doar „scheduled” cu ora ajunsă;
     *  - „admin” (Trimite acum / Reia trimiterea): ciornă, programat, oprit, în curs.
     * Înainte, starea se trecea pe „sending” fără nicio condiție: o campanie
     * oprită („Oprește trimiterea”) sau cu programarea anulată cât timp cronul
     * lucra la alta în aceeași trecere era pornită oricum.
     */
    public static function sendCampaignNow(
        PDO $db,
        int $campaignId,
        array $settings,
        int $limit = 5000,
        ?int $deadline = null,
        string $pornire = 'admin'
    ): array {
        $campaign = self::loadCampaign($db, $campaignId);
        if (!is_array($campaign)) {
            throw new RuntimeException('Campanie invalidă.');
        }
        if ((string) ($campaign['status'] ?? '') === 'sent') {
            throw new RuntimeException('Newsletterul a fost deja trimis. Ca să-l trimiți din nou, fă o copie (Duplică).');
        }

        $listIds = self::campaignListIds($campaign);
        if (empty($listIds)) {
            throw new RuntimeException('Campania nu are listă de abonați.');
        }
        // Fără adresa site-ului, linkul de dezabonare ar ieși „/newsletter/…”,
        // adică un link mort (din cron nu există HTTP_HOST). Un newsletter fără
        // dezabonare funcțională nu pleacă.
        if (self::appBaseUrl() === '') {
            throw new RuntimeException('Adresa site-ului (APP_URL) lipsește din .env, deci linkul de dezabonare n-ar funcționa. Nu trimit.');
        }

        $lacat = self::numeLacat($db, $campaignId);
        if (!self::iaLacat($db, $lacat)) {
            // Altcineva (cronul sau altă fereastră) trimite deja campania asta.
            return [
                'campaign_id' => $campaignId,
                'total' => self::totalRecipients($db, $listIds),
                'sent' => 0,
                'failed' => 0,
                'total_sent' => (int) ($campaign['total_sent'] ?? 0),
                'total_failed' => (int) ($campaign['total_failed'] ?? 0),
                'remaining' => self::remainingCount($db, $campaignId, $listIds),
                'stopped_early' => true,
                'finished' => false,
                'busy' => true,
                'paused' => false,
                'error' => '',
            ];
        }

        try {
            // Starea se schimbă la început, nu la sfârșit: cât timp trimite, în
            // admin se vede „Se trimite” și conținutul nu se mai poate modifica.
            // Doar din stările permise pentru cine a pornit trimiterea.
            $permise = match ($pornire) {
                'cron' => ['sending'],
                'programat' => ['scheduled'],
                default => ['draft', 'scheduled', 'paused', 'sending'],
            };
            $semne = implode(',', array_fill(0, count($permise), '?'));
            $db->prepare(
                'UPDATE newsletter_campaigns
                 SET status = "sending", sent_at = COALESCE(sent_at, NOW()), last_error = NULL
                 WHERE id = ? AND status IN (' . $semne . ')'
                 . ($pornire === 'programat' ? ' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()' : '')
            )->execute(array_merge([$campaignId], $permise));
            // rowCount() nu ajută aici (0 și când starea era deja „sending”),
            // deci se citește starea înapoi: dacă nu e „sending”, nu trimitem.
            if (!self::esteInCurs($db, $campaignId)) {
                return [
                    'campaign_id' => $campaignId,
                    'total' => 0,
                    'sent' => 0,
                    'failed' => 0,
                    'total_sent' => (int) ($campaign['total_sent'] ?? 0),
                    'total_failed' => (int) ($campaign['total_failed'] ?? 0),
                    'remaining' => self::remainingCount($db, $campaignId, $listIds),
                    'stopped_early' => true,
                    'finished' => false,
                    'busy' => false,
                    'nepornit' => true,
                    'paused' => false,
                    'error' => '',
                ];
            }

            $blocksJsonRaw = trim((string) ($campaign['blocks_json'] ?? ''));
            if ($blocksJsonRaw !== '' && $blocksJsonRaw !== '[]') {
                $parsedBlocks = json_decode($blocksJsonRaw, true);
                if (is_array($parsedBlocks) && count($parsedBlocks) > 0) {
                    $campaign['html_content'] = self::renderHtmlFromBlocks($parsedBlocks, (string) ($campaign['subject'] ?? ''));
                }
            }

            $recipients = self::remainingRecipients($db, $campaignId, $listIds, $limit);
            $sent = 0;
            $failed = 0;
            $oprit = false;
            $esecuriLaRand = 0;
            $motivPauza = '';
            $marcheazaTrimis = $db->prepare(
                'UPDATE newsletter_campaign_sends
                 SET status = "sent", error_message = NULL, sent_at = NOW(), email = :email
                 WHERE campaign_id = :campaign_id AND subscriber_id = :subscriber_id'
            );
            $marcheazaEsuat = $db->prepare(
                'UPDATE newsletter_campaign_sends
                 SET status = "failed", error_message = :error_message, sent_at = NOW()
                 WHERE campaign_id = :campaign_id AND subscriber_id = :subscriber_id'
            );
            $marcheazaNeconfirmat = $db->prepare(
                'UPDATE newsletter_campaign_sends
                 SET status = "unknown", error_message = :error_message, sent_at = NOW()
                 WHERE campaign_id = :campaign_id AND subscriber_id = :subscriber_id'
            );

            $opritDeOm = false;
            foreach ($recipients as $pozitie => $recipient) {
                if ($deadline !== null && time() >= $deadline) {
                    $oprit = true;
                    break;
                }
                // „Oprește trimiterea” (sau ștergerea) din admin se vede aici,
                // la cel mult 20 de mesaje distanță, nu abia la lotul următor.
                if ($pozitie > 0 && $pozitie % 20 === 0 && !self::esteInCurs($db, $campaignId)) {
                    $opritDeOm = true;
                    break;
                }
                $subscriberId = (int) ($recipient['id'] ?? 0);
                $email = trim((string) ($recipient['email'] ?? ''));
                if ($subscriberId <= 0 || $email === '') {
                    continue;
                }
                if (!self::revendicaDestinatar($db, $campaignId, $subscriberId, $email)) {
                    continue;
                }

                try {
                    $unsubscribeUrl = self::unsubscribeUrlForSubscriber($recipient);
                    $baseUrl = self::appBaseUrl();
                    $emailHtml = self::withNewsletterFooter((string) ($campaign['html_content'] ?? '<p>Newsletter</p>'), $unsubscribeUrl);
                    if ($baseUrl !== '') {
                        $emailHtml = self::injectTrackingPixel($emailHtml, $campaignId, $subscriberId, $baseUrl);
                        $emailHtml = self::injectClickTracking($emailHtml, $campaignId, $subscriberId, $baseUrl);
                    }
                    OrderMailer::sendCustom(
                        $email,
                        (string) ($campaign['subject'] ?? 'Newsletter'),
                        $emailHtml,
                        $settings,
                        $db,
                        [
                            'email_type' => 'newsletter_campaign',
                            'source' => 'newsletter_campaign_send',
                            'campaign_id' => $campaignId,
                            'subscriber_id' => $subscriberId,
                            'trigger' => 'campaign_send_now',
                            'meta' => ['campaign_id' => $campaignId],
                            'headers' => self::anteteDezabonare($unsubscribeUrl),
                        ]
                    );

                    $marcheazaTrimis->execute([
                        'email' => $email,
                        'campaign_id' => $campaignId,
                        'subscriber_id' => $subscriberId,
                    ]);
                    $sent++;
                    $esecuriLaRand = 0;
                } catch (Throwable $exception) {
                    $mesaj = substr(trim($exception->getMessage()), 0, 1000);
                    if ($exception instanceof TrimitereNeconfirmata) {
                        // Poate a ajuns: nu se retrimite (starea „unknown” nu mai
                        // e aleasă de nicio trecere), dar se vede la statistici.
                        $marcheazaNeconfirmat->execute([
                            'error_message' => $mesaj,
                            'campaign_id' => $campaignId,
                            'subscriber_id' => $subscriberId,
                        ]);
                    } else {
                        $marcheazaEsuat->execute([
                            'error_message' => $mesaj,
                            'campaign_id' => $campaignId,
                            'subscriber_id' => $subscriberId,
                        ]);
                    }
                    $failed++;
                    $esecuriLaRand++;
                    if ($esecuriLaRand >= self::ESECURI_LA_RAND_PAUZA) {
                        $motivPauza = 'Serverul de email a refuzat ' . $esecuriLaRand
                            . ' mesaje la rând. Ultimul răspuns: ' . $mesaj;
                        break;
                    }
                }
            }

            // Totalurile se citesc din evidența trimiterilor, nu din lotul curent:
            // altfel a doua rulare ar raporta mai puțin decât prima și ar șterge
            // istoricul campaniei.
            $totalRecipients = self::totalRecipients($db, $listIds);
            $ramase = self::remainingCount($db, $campaignId, $listIds);
            $totaluri = self::totaluriTrimiteri($db, $campaignId);
            $totalSent = $totaluri['trimise'];
            $totalFailed = $totaluri['esuate'];

            // Cât timp mai are cui trimite, campania rămâne „în curs": cronul o
            // reia singur, iar în listă se vede că nu s-a terminat. După prea
            // multe respingeri la rând se oprește și așteaptă un om. Dacă a
            // oprit-o chiar omul, starea și motivul lui rămân neatinse.
            $status = $motivPauza !== '' ? 'paused' : ($ramase > 0 ? 'sending' : 'sent');
            if ($opritDeOm || !self::esteInCurs($db, $campaignId)) {
                $opritDeOm = true;
                $status = $ramase > 0 ? 'paused' : 'sent';
                $motivPauza = '';
            }
            $stmt = $db->prepare(
                'UPDATE newsletter_campaigns
                 SET status = :status,
                     sent_at = COALESCE(sent_at, NOW()),
                     total_recipients = :total_recipients,
                     total_sent = :total_sent,
                     total_failed = :total_failed,
                     last_error = ' . ($opritDeOm ? 'last_error' : ':last_error') . '
                 WHERE id = :id'
            );
            $parametri = [
                'status' => $status,
                'total_recipients' => $totalRecipients,
                'total_sent' => $totalSent,
                'total_failed' => $totalFailed,
                'id' => $campaignId,
            ];
            if (!$opritDeOm) {
                $parametri['last_error'] = $motivPauza !== '' ? $motivPauza : null;
            }
            $stmt->execute($parametri);
        } finally {
            self::elibereazaLacat($db, $lacat);
        }

        return [
            'campaign_id' => $campaignId,
            'total' => $totalRecipients,
            'sent' => $sent,
            'failed' => $failed,
            'total_sent' => $totalSent,
            'total_failed' => $totalFailed,
            'remaining' => $ramase,
            'stopped_early' => $oprit || $motivPauza !== '' || $opritDeOm,
            'finished' => $status === 'sent',
            'busy' => false,
            'nepornit' => false,
            'paused' => $status === 'paused',
            'error' => $motivPauza,
        ];
    }

    /** Campania mai e „în curs”? (Nu, dacă a fost oprită sau ștearsă din admin.) */
    private static function esteInCurs(PDO $db, int $campaignId): bool
    {
        $stmt = $db->prepare('SELECT status FROM newsletter_campaigns WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $campaignId]);
        return (string) ($stmt->fetchColumn() ?: '') === 'sending';
    }

    /**
     * Rezervă adresa pentru trimitere: rândul ei din evidență devine „pending”.
     * Întoarce false dacă nu trebuie trimis (s-a dezabonat între timp, a primit
     * deja, e în lucru sau a epuizat încercările).
     */
    private static function revendicaDestinatar(PDO $db, int $campaignId, int $subscriberId, string $email): bool
    {
        $activ = $db->prepare('SELECT 1 FROM newsletter_subscribers WHERE id = :id AND status = "active" LIMIT 1');
        $activ->execute(['id' => $subscriberId]);
        if (!(bool) $activ->fetchColumn()) {
            return false;
        }

        $rand = $db->prepare(
            'SELECT id, status, attempts
             FROM newsletter_campaign_sends
             WHERE campaign_id = :campaign_id AND subscriber_id = :subscriber_id
             LIMIT 1'
        );
        $rand->execute(['campaign_id' => $campaignId, 'subscriber_id' => $subscriberId]);
        $existent = $rand->fetch();
        if (is_array($existent)) {
            if ((string) ($existent['status'] ?? '') !== 'failed' || (int) ($existent['attempts'] ?? 0) >= self::MAX_INCERCARI) {
                return false;
            }
            $reia = $db->prepare(
                'UPDATE newsletter_campaign_sends
                 SET status = "pending", attempts = attempts + 1, error_message = NULL, sent_at = NOW()
                 WHERE id = :id AND status = "failed"'
            );
            $reia->execute(['id' => (int) $existent['id']]);
            return $reia->rowCount() === 1;
        }

        try {
            $db->prepare(
                'INSERT INTO newsletter_campaign_sends (campaign_id, subscriber_id, email, status, attempts, sent_at)
                 VALUES (:campaign_id, :subscriber_id, :email, "pending", 1, NOW())'
            )->execute([
                'campaign_id' => $campaignId,
                'subscriber_id' => $subscriberId,
                'email' => $email,
            ]);
        } catch (Throwable) {
            // Rândul a apărut între timp (cheia unică): nu e al nostru.
            return false;
        }
        return true;
    }

    /** Listele unei campanii: `subscriber_list_ids` (JSON), altfel lista unică. */
    public static function campaignListIds(array $campaign): array
    {
        $listIdsRaw = trim((string) ($campaign['subscriber_list_ids'] ?? ''));
        $decodat = $listIdsRaw !== '' ? json_decode($listIdsRaw, true) : null;
        $listIds = is_array($decodat)
            ? array_values(array_filter(array_map('intval', $decodat), fn($v) => $v > 0))
            : [];
        if (empty($listIds)) {
            $single = (int) ($campaign['subscriber_list_id'] ?? 0);
            if ($single > 0) {
                $listIds = [$single];
            }
        }
        return array_values(array_unique($listIds));
    }

    /**
     * Trimise / eșuate / neconfirmate, din evidență. Testele (fără abonat) nu
     * se numără. „Neconfirmate” = rezervate dar fără răspuns (proces oprit la
     * mijloc): nu se retrimit, ca să nu primească nimeni de două ori.
     */
    public static function totaluriTrimiteri(PDO $db, int $campaignId): array
    {
        $stmt = $db->prepare(
            'SELECT
                SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) AS trimise,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) AS esuate,
                SUM(CASE WHEN status IN ("pending", "unknown") THEN 1 ELSE 0 END) AS neconfirmate
             FROM newsletter_campaign_sends
             WHERE campaign_id = :campaign_id AND subscriber_id IS NOT NULL'
        );
        $stmt->execute(['campaign_id' => $campaignId]);
        $rand = $stmt->fetch() ?: [];
        return [
            'trimise' => (int) ($rand['trimise'] ?? 0),
            'esuate' => (int) ($rand['esuate'] ?? 0),
            'neconfirmate' => (int) ($rand['neconfirmate'] ?? 0),
        ];
    }

    /**
     * Conținutul și destinatarii se pot schimba doar înainte să plece ceva:
     * ciornă, programat, sau oprit înainte de primul mesaj trimis.
     */
    public static function poateFiModificata(PDO $db, int $campaignId): bool
    {
        $stmt = $db->prepare('SELECT status FROM newsletter_campaigns WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $campaignId]);
        $status = (string) ($stmt->fetchColumn() ?: '');
        if (in_array($status, ['draft', 'scheduled'], true)) {
            return true;
        }
        if ($status === 'paused') {
            $totaluri = self::totaluriTrimiteri($db, $campaignId);
            return $totaluri['trimise'] === 0 && $totaluri['neconfirmate'] === 0;
        }
        return false;
    }

    /**
     * Antetele de dezabonare cerute de Gmail/Yahoo pentru trimiterile în masă
     * (din 2024): linkul personal și dezabonarea „cu un clic” (RFC 8058), pe
     * care o face chiar clientul de email printr-un POST la același link.
     */
    private static function anteteDezabonare(string $unsubscribeUrl): array
    {
        if (!preg_match('#^https?://#i', $unsubscribeUrl)) {
            return [];
        }
        return [
            'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /** Numele lacătului: pe serverul comun, alte baze pot avea campania cu același id. */
    private static function numeLacat(PDO $db, int $campaignId): string
    {
        $baza = '';
        try {
            $baza = (string) ($db->query('SELECT DATABASE()')->fetchColumn() ?: '');
        } catch (Throwable) {
        }
        return 'nl_' . substr(sha1($baza), 0, 16) . '_' . $campaignId;
    }

    private static function iaLacat(PDO $db, string $nume): bool
    {
        try {
            $stmt = $db->prepare('SELECT GET_LOCK(:nume, 0)');
            $stmt->execute(['nume' => $nume]);
            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable) {
            // Fără GET_LOCK pe server: rămâne rezervarea „pending” pe adresă.
            return true;
        }
    }

    private static function elibereazaLacat(PDO $db, string $nume): void
    {
        try {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(:nume)');
            $stmt->execute(['nume' => $nume]);
        } catch (Throwable) {
        }
    }

    /**
     * Oprește o campanie cu motivul scris, ca să-l vadă omul din admin. Doar
     * dacă e încă în starea în care a găsit-o cronul: o programare anulată
     * între timp rămâne ciornă, nu devine „Oprit”.
     */
    private static function opresteCampania(PDO $db, int $campaignId, string $motiv, string $dinStarea): void
    {
        try {
            $db->prepare('UPDATE newsletter_campaigns SET status = "paused", last_error = :motiv WHERE id = :id AND status = :stare')
                ->execute(['motiv' => substr($motiv, 0, 1000), 'id' => $campaignId, 'stare' => $dinStarea]);
        } catch (Throwable) {
        }
    }

    /**
     * Reia campaniile rămase în curs (status „sending"), în ordinea începerii.
     * Se apelează din cron: o campanie mare se termină singură, în mai multe
     * treceri, fără ca cineva să apese butonul din nou. Campaniile oprite
     * („paused”) NU se reiau singure: le pornește un om, după ce vede motivul.
     */
    public static function continueRunningCampaigns(
        PDO $db,
        array $settings,
        int $perRun = 5000,
        ?int $deadline = null
    ): array {
        $stmt = $db->query(
            'SELECT id FROM newsletter_campaigns WHERE status = "sending" ORDER BY sent_at ASC, id ASC'
        );
        $rows = $stmt !== false ? (array) $stmt->fetchAll() : [];

        $reluate = 0;
        $trimise = 0;
        $esuate = 0;
        $terminate = 0;
        foreach ($rows as $row) {
            if ($deadline !== null && time() >= $deadline) {
                break;
            }
            $campaignId = (int) ($row['id'] ?? 0);
            if ($campaignId <= 0) {
                continue;
            }
            try {
                $rezultat = self::sendCampaignNow($db, $campaignId, $settings, $perRun, $deadline, 'cron');
                if (!empty($rezultat['nepornit']) || !empty($rezultat['busy'])) {
                    continue;
                }
                $reluate++;
                $trimise += (int) ($rezultat['sent'] ?? 0);
                $esuate += (int) ($rezultat['failed'] ?? 0);
                if (!empty($rezultat['finished'])) {
                    $terminate++;
                }
            } catch (Throwable $exception) {
                // O campanie stricată (listă ștearsă, de exemplu) nu trebuie să
                // blocheze restul și nici să fie reîncercată la nesfârșit: se
                // oprește, cu motivul, și se vede în listă.
                self::opresteCampania($db, $campaignId, $exception->getMessage(), 'sending');
                $esuate++;
            }
        }

        return [
            'resumed_campaigns' => $reluate,
            'finished_campaigns' => $terminate,
            'sent' => $trimise,
            'failed' => $esuate,
        ];
    }

    public static function sendDueScheduledCampaigns(
        PDO $db,
        array $settings,
        int $limit = 20,
        ?int $deadline = null,
        int $perRun = 10000
    ): array {
        $stmt = $db->prepare(
            'SELECT id
             FROM newsletter_campaigns
             WHERE status = "scheduled" AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()
             ORDER BY scheduled_at ASC, id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $processed = 0;
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if ($deadline !== null && time() >= $deadline) {
                break;
            }
            $campaignId = (int) ($row['id'] ?? 0);
            if ($campaignId <= 0) {
                continue;
            }
            try {
                $result = self::sendCampaignNow($db, $campaignId, $settings, $perRun, $deadline, 'programat');
                if (!empty($result['nepornit']) || !empty($result['busy'])) {
                    continue;
                }
                $processed++;
                $sent += (int) ($result['sent'] ?? 0);
                $failed += (int) ($result['failed'] ?? 0);
            } catch (Throwable $exception) {
                $failed++;
                // Înainte se întorcea tăcut la „ciornă”, iar programarea dispărea
                // fără nicio explicație. Acum rămâne oprită, cu motivul.
                self::opresteCampania($db, $campaignId, $exception->getMessage(), 'scheduled');
            }
        }

        return [
            'processed_campaigns' => $processed,
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    public static function listRecipients(PDO $db, int $listId, int $limit = 5000): array
    {
        if ($listId <= 0) {
            return [];
        }
        $stmt = $db->prepare(
            'SELECT s.id, s.email, s.name, s.status
             FROM newsletter_list_subscribers ls
             INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
             WHERE ls.list_id = :list_id AND s.status = "active"
             ORDER BY s.id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(10000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Destinatarii care NU au primit încă această campanie.
     *
     * Selecția pe listă, fără să știe ce s-a trimis deja, întorcea mereu aceiași
     * primi N abonați: la a doua rulare erau toți marcați „sent", se săreau toți,
     * iar restul listei nu primea niciodată nimic. De aici, trimiterea se face
     * pe bucăți și fiecare rulare continuă de unde a rămas precedenta.
     *
     * Rămân de servit doar cei fără niciun rând în evidență și cei respinși
     * care mai au încercări. Un rând „pending” (proces oprit la mijloc) nu se
     * mai alege: s-ar putea ca mesajul să fi plecat deja.
     */
    public static function remainingRecipients(PDO $db, int $campaignId, array $listIds, int $limit = 5000): array
    {
        $listIds = array_values(array_filter(array_map('intval', $listIds), fn($v) => $v > 0));
        if ($listIds === [] || $campaignId <= 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($listIds), '?'));
        $stmt = $db->prepare(
            "SELECT DISTINCT s.id, s.email, s.name, s.status
             FROM newsletter_list_subscribers ls
             INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
             LEFT JOIN newsletter_campaign_sends cs
                    ON cs.campaign_id = ? AND cs.subscriber_id = s.id
             WHERE ls.list_id IN ($placeholders)
               AND s.status = 'active'
               AND (cs.id IS NULL OR (cs.status = 'failed' AND cs.attempts < " . self::MAX_INCERCARI . "))
             ORDER BY s.id ASC
             LIMIT " . max(1, min(50000, $limit))
        );
        $stmt->execute(array_merge([$campaignId], $listIds));
        return $stmt->fetchAll();
    }

    /** Câți abonați activi are campania în total, pe toate listele ei. */
    public static function totalRecipients(PDO $db, array $listIds): int
    {
        $listIds = array_values(array_filter(array_map('intval', $listIds), fn($v) => $v > 0));
        if ($listIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($listIds), '?'));
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT s.id)
             FROM newsletter_list_subscribers ls
             INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
             WHERE ls.list_id IN ($placeholders) AND s.status = 'active'"
        );
        $stmt->execute($listIds);
        return (int) $stmt->fetchColumn();
    }

    /** Câți mai au de primit campania (0 = gata). */
    public static function remainingCount(PDO $db, int $campaignId, array $listIds): int
    {
        $listIds = array_values(array_filter(array_map('intval', $listIds), fn($v) => $v > 0));
        if ($listIds === [] || $campaignId <= 0) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($listIds), '?'));
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT s.id)
             FROM newsletter_list_subscribers ls
             INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
             LEFT JOIN newsletter_campaign_sends cs
                    ON cs.campaign_id = ? AND cs.subscriber_id = s.id
             WHERE ls.list_id IN ($placeholders)
               AND s.status = 'active'
               AND (cs.id IS NULL OR (cs.status = 'failed' AND cs.attempts < " . self::MAX_INCERCARI . "))"
        );
        $stmt->execute(array_merge([$campaignId], $listIds));
        return (int) $stmt->fetchColumn();
    }

    public static function multiListRecipients(PDO $db, array $listIds, int $limit = 5000): array
    {
        $listIds = array_values(array_filter(array_map('intval', $listIds), fn($v) => $v > 0));
        if (empty($listIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($listIds), '?'));
        $stmt = $db->prepare(
            "SELECT DISTINCT s.id, s.email, s.name, s.status
             FROM newsletter_list_subscribers ls
             INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
             WHERE ls.list_id IN ($placeholders) AND s.status = 'active'
             ORDER BY s.id ASC
             LIMIT " . max(1, min(50000, $limit))
        );
        $stmt->execute($listIds);
        return $stmt->fetchAll();
    }

    private static function loadCampaign(PDO $db, int $campaignId): ?array
    {
        $stmt = $db->prepare(
            'SELECT id, name, subject, html_content, blocks_json, subscriber_list_id, subscriber_list_ids, status,
                    total_sent, total_failed
             FROM newsletter_campaigns
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $campaignId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private static function buildUnsubscribeFooterHtml(?string $unsubscribeUrl = null, bool $isTest = false): string
    {
        $safeUrl = trim((string) $unsubscribeUrl);
        if ($safeUrl === '') {
            $safeUrl = '#';
        }

        // For test emails or when the URL is non-functional, render plain-text notice
        if ($isTest || $safeUrl === '#') {
            return self::UNSUBSCRIBE_FOOTER_START
                . '<div style="max-width:680px;margin:16px auto 0;padding:14px 16px;text-align:center;color:#64748b;font-size:12px;line-height:1.55;">'
                . '<p style="margin:0;">Email de test – linkul de dezabonare este inactiv.</p>'
                . '</div>'
                . self::UNSUBSCRIBE_FOOTER_END;
        }

        $safeUrl = self::esc($safeUrl);
        $safeButtonLabel = self::esc('Dezabonează-mă');
        $safeText = self::esc('Dacă nu mai vrei să primești email-uri de la noi, te poți dezabona aici ↓');

        return self::UNSUBSCRIBE_FOOTER_START
            . '<div style="max-width:680px;margin:16px auto 0;padding:14px 16px;text-align:center;color:#64748b;font-size:12px;line-height:1.55;">'
            . '<p style="margin:0 0 8px;">' . $safeText . '</p>'
            . '<a href="' . $safeUrl . '" style="display:inline-block;padding:8px 14px;border:1px solid #cbd5e1;border-radius:999px;background:#ffffff;color:#334155;font-weight:600;text-decoration:none;">'
            . $safeButtonLabel
            . '</a>'
            . '</div>'
            . self::UNSUBSCRIBE_FOOTER_END;
    }

    public static function injectTrackingPixel(string $html, int $campaignId, int $subscriberId, string $baseUrl): string
    {
        $token = self::base64UrlEncode(
            hash_hmac('sha256', "open|{$campaignId}|{$subscriberId}", self::cheieSemnare(), true)
        );
        $src = rtrim($baseUrl, '/') . '/newsletter/track/open/' . $campaignId . '/' . $subscriberId . '/' . $token;
        $pixel = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" style="display:block;width:1px;height:1px;border:none;opacity:0;" alt="">';
        $closeBody = strripos($html, '</body>');
        if ($closeBody !== false) {
            return substr($html, 0, $closeBody) . $pixel . substr($html, $closeBody);
        }
        return $html . $pixel;
    }

    public static function injectClickTracking(string $html, int $campaignId, int $subscriberId, string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        return preg_replace_callback(
            '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/si',
            static function (array $m) use ($campaignId, $subscriberId, $baseUrl): string {
                $before = $m[1];
                // În HTML, „&” din adresă e scris „&amp;”. Fără decodare,
                // redirecționarea ducea la „?a=1&amp;b=2”, adică parametrul
                // „amp;b”: linkurile cu UTM (utm_source=…&utm_medium=…)
                // ajungeau stricate.
                $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $after = $m[3];
                // Skip: anchors, mailto, tel, already-tracked URLs, unsubscribe links
                if (
                    str_starts_with($url, '#')
                    || str_starts_with($url, 'mailto:')
                    || str_starts_with($url, 'tel:')
                    || str_contains($url, '/newsletter/track/')
                    || str_contains($url, '/newsletter/unsubscribe/')
                ) {
                    return $m[0];
                }
                $token = self::base64UrlEncode(
                    hash_hmac('sha256', "click|{$campaignId}|{$subscriberId}|{$url}", self::cheieSemnare(), true)
                );
                $tracked = $baseUrl . '/newsletter/track/click/' . $campaignId . '/' . $subscriberId . '/' . $token
                    . '?url=' . rawurlencode($url);
                return '<a ' . $before . 'href="' . htmlspecialchars($tracked, ENT_QUOTES, 'UTF-8') . '"' . $after . '>';
            },
            $html
        ) ?? $html;
    }

    public static function handleOpenTrack(PDO $db, int $campaignId, int $subscriberId, string $token): string
    {
        // Și cu cheia veche se numără deschiderea: pixelul nu pune cookie și nu
        // redirecționează nicăieri.
        if (self::semnaturaBuna("open|{$campaignId}|{$subscriberId}", $token) === null) {
            return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        }

        try {
            $sendStmt = $db->prepare(
                'SELECT id FROM newsletter_campaign_sends WHERE campaign_id = :cid AND subscriber_id = :sid LIMIT 1'
            );
            $sendStmt->execute(['cid' => $campaignId, 'sid' => $subscriberId]);
            $sendId = (int) ($sendStmt->fetchColumn() ?: 0);

            if ($sendId > 0) {
                $db->prepare(
                    'UPDATE newsletter_campaign_sends
                     SET opened_at = COALESCE(opened_at, NOW()), open_count = open_count + 1
                     WHERE id = :id'
                )->execute(['id' => $sendId]);
            }

            $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $db->prepare(
                'INSERT INTO newsletter_campaign_opens (campaign_id, send_id, opened_at, ip)
                 VALUES (:cid, :sid, NOW(), :ip)'
            )->execute(['cid' => $campaignId, 'sid' => $sendId ?: null, 'ip' => $ip !== '' ? $ip : null]);

            $db->prepare(
                'UPDATE newsletter_campaigns SET total_opens = total_opens + 1 WHERE id = :id'
            )->execute(['id' => $campaignId]);
        } catch (Throwable) {
        }

        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    /**
     * Înregistrează clicul și întoarce adresa la care se merge mai departe, sau
     * null dacă semnătura nu se potrivește. Înainte, o semnătură greșită
     * redirecționa oricum la adresa din `?url=`: oricine putea folosi domeniul
     * magazinului ca trambulină spre un site de phishing.
     */
    public static function handleClickTrack(PDO $db, int $campaignId, int $subscriberId, string $token, string $destUrl): ?string
    {
        $semnatura = self::semnaturaBuna("click|{$campaignId}|{$subscriberId}|{$destUrl}", $token);
        if ($semnatura === null) {
            return null;
        }
        // Cheia veche se poate ghici, deci un token doar cu ea e crezut numai
        // pentru o campanie trimisă înainte de cheia nouă și numai dacă adresa
        // chiar apare în acea campanie. Altfel: prima pagină, fără cookie.
        if ($semnatura === 'vechi') {
            $limita = self::limitaCheieVeche();
            if ($campaignId > $limita['campanie'] || $subscriberId > $limita['abonat']
                || !self::adresaEInCampanie($db, $campaignId, $destUrl)) {
                return null;
            }
        }
        // Emailurile trimise înainte de reparația de mai sus au semnat adresa
        // cu „&amp;” în ea; semnătura lor rămâne bună, iar adresa se curăță aici.
        $destUrl = html_entity_decode($destUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        try {
            $sendStmt = $db->prepare(
                'SELECT id, email FROM newsletter_campaign_sends WHERE campaign_id = :cid AND subscriber_id = :sid LIMIT 1'
            );
            $sendStmt->execute(['cid' => $campaignId, 'sid' => $subscriberId]);
            $sendRow = $sendStmt->fetch();
            $sendId = (int) ($sendRow['id'] ?? 0);
            $email = (string) ($sendRow['email'] ?? '');

            if ($sendId > 0) {
                $db->prepare(
                    'UPDATE newsletter_campaign_sends
                     SET clicked_at = COALESCE(clicked_at, NOW()), click_count = click_count + 1
                     WHERE id = :id'
                )->execute(['id' => $sendId]);
            }

            $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $db->prepare(
                'INSERT INTO newsletter_campaign_clicks (campaign_id, subscriber_id, email, url, clicked_at, ip)
                 VALUES (:cid, :sub, :email, :url, NOW(), :ip)'
            )->execute([
                'cid' => $campaignId,
                'sub' => $subscriberId ?: null,
                'email' => $email,
                'url' => substr($destUrl, 0, 2048),
                'ip' => $ip !== '' ? $ip : null,
            ]);

            $db->prepare(
                'UPDATE newsletter_campaigns SET total_clicks = total_clicks + 1 WHERE id = :id'
            )->execute(['id' => $campaignId]);
        } catch (Throwable) {
        }

        return $destUrl;
    }

    private static function buildUnsubscribeToken(int $subscriberId, string $email): string
    {
        $payload = $subscriberId . '|' . strtolower(trim($email));
        $payloadEncoded = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $payloadEncoded, self::cheieSemnare(), true);
        return $payloadEncoded . '.' . self::base64UrlEncode($signature);
    }

    private static function parseUnsubscribeToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !str_contains($token, '.')) {
            return null;
        }

        [$payloadEncoded, $signatureEncoded] = explode('.', $token, 2);
        if ($payloadEncoded === '' || $signatureEncoded === '') {
            return null;
        }

        $providedSignature = self::base64UrlDecode($signatureEncoded);
        if ($providedSignature === null) {
            return null;
        }
        $potrivire = null;
        foreach (self::cheiNoi() as $cheie) {
            if (hash_equals(hash_hmac('sha256', $payloadEncoded, $cheie, true), $providedSignature)) {
                $potrivire = 'nou';
                break;
            }
        }
        if ($potrivire === null && hash_equals(hash_hmac('sha256', $payloadEncoded, self::cheieVeche(), true), $providedSignature)) {
            $potrivire = 'vechi';
        }
        if ($potrivire === null) {
            return null;
        }

        $payloadRaw = self::base64UrlDecode($payloadEncoded);
        if ($payloadRaw === null || !str_contains($payloadRaw, '|')) {
            return null;
        }

        [$idRaw, $emailRaw] = explode('|', $payloadRaw, 2);
        $id = (int) $idRaw;
        $email = strtolower(trim($emailRaw));
        if ($id <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        // Cheia veche contează doar pentru abonații care existau când a apărut
        // cheia nouă (doar ei pot avea un email vechi).
        if ($potrivire === 'vechi' && $id > self::limitaCheieVeche()['abonat']) {
            return null;
        }

        return [
            'id' => $id,
            'email' => $email,
            'vechi' => $potrivire === 'vechi',
        ];
    }

    public static function appBaseUrl(): string
    {
        $config = require __DIR__ . '/../../config/app.php';
        $configured = rtrim((string) ($config['url'] ?? ''), '/');
        if ($configured !== '') {
            return $configured;
        }

        $https = (string) ($_SERVER['HTTPS'] ?? '');
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    /** Cheia secretă din tabelul settings (nu iese niciodată prin Settings::all). */
    public const CHEIE_SETARE = 'newsletter_cheie_semnare';

    private static ?string $cheieSemnare = null;

    /**
     * Cheia cu care se semnează linkurile noi (dezabonare, pixel, clic).
     *
     * Cheia veche era calculată din adresa site-ului, numele bazei și cuvântul
     * „bioscem”, iar codul e public: cine ghicea numele bazei putea dezabona
     * toată lista sau falsifica linkuri. Acum e un secret aleator:
     * NEWSLETTER_SECRET din .env dacă există, altfel 32 de octeți aleatori
     * generați o singură dată și păstrați în baza de date (nimic de configurat).
     */
    private static function cheieSemnare(): string
    {
        if (self::$cheieSemnare !== null) {
            return self::$cheieSemnare;
        }
        self::limitaCheieVeche();
        $dinEnv = trim((string) Env::get('NEWSLETTER_SECRET', ''));
        if ($dinEnv !== '') {
            return self::$cheieSemnare = $dinEnv;
        }
        $dinBaza = self::cheieDinBaza(true);
        if ($dinBaza === '') {
            throw new RuntimeException('Nu am putut citi cheia de semnare a newsletterului.');
        }
        return self::$cheieSemnare = $dinBaza;
    }

    /** Cheia din baza de date; la nevoie o creează (o singură dată, fără cursă). */
    private static function cheieDinBaza(bool $creeaza): string
    {
        try {
            $config = require __DIR__ . '/../../config/app.php';
            $db = Database::connection($config['db']);
            if (!$db instanceof PDO) {
                return '';
            }
            $citeste = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
            $citeste->execute(['k' => self::CHEIE_SETARE]);
            $cheie = (string) ($citeste->fetchColumn() ?: '');
            if ($cheie === '' && $creeaza) {
                // INSERT IGNORE: două cereri simultane nu pot crea două chei diferite.
                $db->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (:k, :v)')
                    ->execute(['k' => self::CHEIE_SETARE, 'v' => bin2hex(random_bytes(32))]);
                $citeste->execute(['k' => self::CHEIE_SETARE]);
                $cheie = (string) ($citeste->fetchColumn() ?: '');
            }
            return strlen($cheie) >= 32 ? $cheie : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Cheile noi (secrete) acceptate la verificare: cea curentă și cea din bază
     * (dacă între timp s-a pus NEWSLETTER_SECRET în .env).
     */
    private static function cheiNoi(): array
    {
        $chei = [];
        try {
            $chei[] = self::cheieSemnare();
        } catch (Throwable) {
        }
        $dinBaza = self::cheieDinBaza(false);
        if ($dinBaza !== '') {
            $chei[] = $dinBaza;
        }
        return array_values(array_unique($chei));
    }

    /**
     * Ce cheie a semnat tokenul: „nou” (secretă), „vechi” (cea ghicibilă, doar
     * pentru emailurile trimise înainte, cu restricțiile de la fiecare folosire)
     * sau null.
     */
    private static function semnaturaBuna(string $mesaj, string $token): ?string
    {
        foreach (self::cheiNoi() as $cheie) {
            if (hash_equals(self::base64UrlEncode(hash_hmac('sha256', $mesaj, $cheie, true)), $token)) {
                return 'nou';
            }
        }
        if (hash_equals(self::base64UrlEncode(hash_hmac('sha256', $mesaj, self::cheieVeche(), true)), $token)) {
            return 'vechi';
        }
        return null;
    }

    /** Pragul pentru cheia veche, lângă cheie în settings (ascuns la fel). */
    public const LIMITA_SETARE = 'newsletter_cheie_limita';

    private static ?array $limitaVeche = null;

    /**
     * Cea mai mare campanie și cel mai mare abonat de când a apărut cheia nouă:
     * doar ei pot avea linkuri semnate cu cheia veche. Se scrie o singură dată
     * (INSERT IGNORE), la prima folosire după actualizare (ensureSchema, cron,
     * prima semnare).
     *
     * @return array{campanie:int, abonat:int}
     */
    public static function limitaCheieVeche(?PDO $db = null): array
    {
        if (self::$limitaVeche !== null) {
            return self::$limitaVeche;
        }
        $nimic = ['campanie' => 0, 'abonat' => 0];
        try {
            if (!$db instanceof PDO) {
                $config = require __DIR__ . '/../../config/app.php';
                $db = Database::connection($config['db']);
            }
            if (!$db instanceof PDO) {
                return $nimic;
            }
            $citeste = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
            $citeste->execute(['k' => self::LIMITA_SETARE]);
            $brut = (string) ($citeste->fetchColumn() ?: '');
            if ($brut === '') {
                $campanie = (int) ($db->query('SELECT COALESCE(MAX(id), 0) FROM newsletter_campaigns')->fetchColumn() ?: 0);
                $abonat = (int) ($db->query('SELECT COALESCE(MAX(id), 0) FROM newsletter_subscribers')->fetchColumn() ?: 0);
                $db->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (:k, :v)')->execute([
                    'k' => self::LIMITA_SETARE,
                    'v' => json_encode(['campanie' => $campanie, 'abonat' => $abonat]),
                ]);
                $citeste->execute(['k' => self::LIMITA_SETARE]);
                $brut = (string) ($citeste->fetchColumn() ?: '');
            }
            $date = json_decode($brut, true);
            if (!is_array($date)) {
                return $nimic;
            }
            return self::$limitaVeche = [
                'campanie' => max(0, (int) ($date['campanie'] ?? 0)),
                'abonat' => max(0, (int) ($date['abonat'] ?? 0)),
            ];
        } catch (Throwable) {
            return $nimic;
        }
    }

    /**
     * Adresa apare în campanie (în HTML-ul salvat sau în blocurile ei),
     * normalizată ca la semnare: „&amp;” decodat și spațiile de la capete scoase.
     */
    private static function adresaEInCampanie(PDO $db, int $campaignId, string $destUrl): bool
    {
        $stmt = $db->prepare('SELECT subject, html_content, blocks_json FROM newsletter_campaigns WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $campaignId]);
        $rand = $stmt->fetch();
        if (!is_array($rand)) {
            return false;
        }
        $surse = [(string) ($rand['html_content'] ?? '')];
        $blocuri = json_decode((string) ($rand['blocks_json'] ?? ''), true);
        if (is_array($blocuri) && $blocuri !== []) {
            try {
                $surse[] = self::renderHtmlFromBlocks($blocuri, (string) ($rand['subject'] ?? ''));
            } catch (Throwable) {
            }
        }
        $norm = static fn(string $u): string => trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $cautat = $norm($destUrl);
        if ($cautat === '') {
            return false;
        }
        foreach ($surse as $html) {
            if (preg_match_all('/<a\s[^>]*?href=["\']([^"\']+)["\']/si', $html, $m)) {
                foreach ($m[1] as $href) {
                    if ($norm($href) === $cautat) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Cheia de dinainte (ghicibilă): doar pentru verificarea linkurilor vechi. */
    private static function cheieVeche(): string
    {
        $config = require __DIR__ . '/../../config/app.php';
        $url = (string) ($config['url'] ?? '');
        $dbName = (string) ($config['db']['database'] ?? '');
        return hash('sha256', 'newsletter-unsubscribe|' . $url . '|' . $dbName . '|bioscem');
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($normalized, true);
        return $decoded === false ? null : $decoded;
    }

    private static function wrapDeviceHide(array $block, string $html): string
    {
        $classes = [];
        if (!empty($block['hide_desktop'])) { $classes[] = 'nl-hide-desktop'; }
        if (!empty($block['hide_tablet']))  { $classes[] = 'nl-hide-tablet'; }
        if (!empty($block['hide_mobile']))  { $classes[] = 'nl-hide-mobile'; }
        if ($classes === []) {
            return $html;
        }
        return '<div class="' . implode(' ', $classes) . '">' . $html . '</div>';
    }

    private static function renderBlock(array $block): string
    {
        $type = (string) ($block['type'] ?? 'text');
        $pl = max(0, (int) ($block['padding_left'] ?? 0));
        $pr = max(0, (int) ($block['padding_right'] ?? 0));
        $pt = max(0, (int) ($block['padding_top'] ?? 0));
        $pb = max(0, (int) ($block['padding_bottom'] ?? 0));
        $bgImg = trim((string) ($block['bg_image'] ?? ''));
        $bgSize = in_array($block['bg_size'] ?? '', ['cover','contain','auto']) ? $block['bg_size'] : 'cover';
        // Padding is now applied directly to each block's outer div (not a wrapper) so background fills full width
        $hPad = $pl || $pr ? 'padding-left:' . $pl . 'px;padding-right:' . $pr . 'px;' : '';
        // Vertical spacing is fully user-controlled (no automatic gaps between sections).
        $vPad = 'padding-top:' . $pt . 'px;padding-bottom:' . $pb . 'px;';
        $fontStack = self::fontStack((string) ($block['font_family'] ?? ''));
        $fontStyle = $fontStack !== '' ? 'font-family:' . $fontStack . ';' : '';
        // bg_image: injected into the inner block's own style
        $bgStyle = '';
        if ($bgImg !== '') {
            $bgImgUrl = self::absoluteMediaUrl($bgImg);
            $bgStyle = "background-image:url('" . self::esc($bgImgUrl) . "');background-size:{$bgSize};background-repeat:no-repeat;background-position:center;";;
        }
        if ($type === 'header') {
            $desktopSize = self::normalizeFontSize((int) ($block['font_size'] ?? 0), 34);
            $mobileSize = self::normalizeFontSize((int) ($block['font_size_mobile'] ?? $desktopSize), $desktopSize);
            $contentHtml = self::allowRichTags(self::esc((string) ($block['content'] ?? '')));
            $hdrBg = self::esc((string) ($block['background'] ?? '#1a7a5e'));
            return '<div class="nl-text-desktop" style="display:block;text-align:' . self::esc((string) ($block['align'] ?? 'center')) . ';background:' . $hdrBg . ';' . $bgStyle . $hPad . $vPad . $fontStyle . 'color:' . self::esc((string) ($block['text_color'] ?? '#ffffff')) . ';font-size:' . $desktopSize . 'px;font-weight:700;">'
                . $contentHtml
                . '</div>'
                . '<div class="nl-text-mobile" style="display:none;text-align:' . self::esc((string) ($block['align'] ?? 'center')) . ';background:' . $hdrBg . ';' . $bgStyle . $hPad . $vPad . $fontStyle . 'color:' . self::esc((string) ($block['text_color'] ?? '#ffffff')) . ';font-size:' . $mobileSize . 'px;font-weight:700;">'
                . $contentHtml
                . '</div>';
        }
        if ($type === 'text') {
            $desktopSize = self::normalizeFontSize((int) ($block['font_size'] ?? 0), 15);
            $mobileSize = self::normalizeFontSize((int) ($block['font_size_mobile'] ?? $desktopSize), $desktopSize);
            $contentHtml = self::renderTextWithLinks((string) ($block['content'] ?? ''));
            $txtBg = self::esc((string) ($block['background'] ?? '#ffffff'));
            return '<div class="nl-text-desktop" style="display:block;text-align:' . self::esc((string) ($block['align'] ?? 'left')) . ';background:' . $txtBg . ';' . $bgStyle . $hPad . $vPad . $fontStyle . 'color:' . self::esc((string) ($block['text_color'] ?? '#1f2937')) . ';font-size:' . $desktopSize . 'px;line-height:1.6;">'
                . $contentHtml
                . '</div>'
                . '<div class="nl-text-mobile" style="display:none;text-align:' . self::esc((string) ($block['align'] ?? 'left')) . ';background:' . $txtBg . ';' . $bgStyle . $hPad . $vPad . $fontStyle . 'color:' . self::esc((string) ($block['text_color'] ?? '#1f2937')) . ';font-size:' . $mobileSize . 'px;line-height:1.6;">'
                . $contentHtml
                . '</div>';
        }
        if ($type === 'image') {
            $url = self::absoluteMediaUrl((string) ($block['image_url'] ?? ''));
            if ($url === '') {
                return '';
            }
            $imgWidth  = min(100, max(10, (int) ($block['width'] ?? 100)));
            $imgAlign  = self::esc((string) ($block['align'] ?? 'center'));
            $imgRadius = max(0, (int) ($block['border_radius'] ?? 0));
            $imgTag = '<img src="' . self::esc($url) . '" alt="' . self::esc((string) ($block['alt'] ?? '')) . '" style="width:' . $imgWidth . '%;max-width:100%;height:auto;border-radius:' . $imgRadius . 'px;border:none;display:inline-block;vertical-align:top;">';
            $imgLink = self::normalizeLinkUrl((string) ($block['link_url'] ?? ''));
            if ($imgLink !== '') {
                $imgTag = '<a href="' . self::esc($imgLink) . '" target="_blank" style="text-decoration:none;border:none;">' . $imgTag . '</a>';
            }
            return '<div style="' . $vPad . $hPad . 'text-align:' . $imgAlign . ';background:' . self::esc((string) ($block['background'] ?? '#ffffff')) . ';' . $bgStyle . '">' . $imgTag . '</div>';
        }
        if ($type === 'button') {
            $fontSize = self::normalizeFontSize((int) ($block['font_size'] ?? 0), 16);
            $padV = min(40, max(2, (int) ($block['btn_pad_v'] ?? 11)));
            $padH = min(80, max(4, (int) ($block['btn_pad_h'] ?? 22)));
            return '<div style="text-align:' . self::esc((string) ($block['align'] ?? 'center')) . ';' . $vPad . $hPad . 'background:' . self::esc((string) ($block['background'] ?? '#ffffff')) . ';' . $bgStyle . '"><a href="' . self::esc(self::linkButon((string) ($block['url'] ?? '#'))) . '" style="display:inline-block;background:' . self::esc((string) ($block['button_color'] ?? '#2d7d5f')) . ';color:' . self::esc((string) ($block['text_color'] ?? '#ffffff')) . ';padding:' . $padV . 'px ' . $padH . 'px;border-radius:' . max(0, (int) ($block['border_radius'] ?? $block['radius'] ?? 6)) . 'px;text-decoration:none;font-weight:700;font-size:' . $fontSize . 'px;">' . self::esc((string) ($block['label'] ?? 'Buton')) . '</a></div>';
        }
        if ($type === 'divider') {
            return '<div style="background:' . self::esc((string) ($block['background'] ?? '#ffffff')) . ';' . $bgStyle . $hPad . $vPad . '"><hr style="border:none;border-top:' . max(1,(int)($block['thickness']??1)) . 'px solid ' . self::esc((string) ($block['color'] ?? $block['line_color'] ?? '#dbe2ea')) . ';margin:0 auto;width:' . min(100,max(10,(int)($block['width']??80))) . '%;"></div>';
        }
        if ($type === 'quote') {
            $qColor = self::esc((string) ($block['text_color'] ?? '#166534'));
            $autor = trim((string) ($block['author'] ?? ''));
            return '<div style="background:' . self::esc((string) ($block['background'] ?? '#f0fdf4')) . ';' . $bgStyle
                . 'border-left:4px solid ' . self::esc((string) ($block['border_color'] ?? '#2d7d5f')) . ';'
                . $vPad . 'padding-left:' . ($pl > 0 ? $pl : 16) . 'px;padding-right:' . $pr . 'px;' . $fontStyle
                . 'text-align:' . self::esc((string) ($block['align'] ?? 'left')) . ';">'
                . '<div style="color:' . $qColor . ';font-style:italic;font-size:' . self::normalizeFontSize((int) ($block['font_size'] ?? 0), 16) . 'px;line-height:1.6;">&bdquo;'
                . self::allowRichTags(self::newlineToBr(self::esc((string) ($block['content'] ?? '')))) . '&rdquo;</div>'
                . ($autor !== '' ? '<div style="font-size:12px;color:' . $qColor . ';opacity:.7;margin-top:5px;">— ' . self::esc($autor) . '</div>' : '')
                . '</div>';
        }
        if ($type === 'social') {
            $links  = is_array($block['links'] ?? null) ? $block['links'] : [];
            $size   = max(20, min(80, (int) ($block['icon_size'] ?? 32)));
            $align  = self::esc((string) ($block['align'] ?? 'center'));
            $bg     = self::esc((string) ($block['background'] ?? '#ffffff'));
            $half   = (int) round($size * 0.5);
            $svgPaths = [
                'facebook'  => ['#1877f2','M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z'],
                'instagram' => ['#e1306c','M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z'],
                'twitter'   => ['#000000','M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z'],
                'linkedin'  => ['#0a66c2','M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 .774 0 .774 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z'],
                'youtube'   => ['#ff0000','M23.495 6.205a3.007 3.007 0 00-2.088-2.088c-1.87-.501-9.396-.501-9.396-.501s-7.507-.01-9.396.501A3.007 3.007 0 00.527 6.205a31.247 31.247 0 00-.522 5.805 31.247 31.247 0 00.522 5.783 3.007 3.007 0 002.088 2.088c1.868.502 9.396.502 9.396.502s7.506 0 9.396-.502a3.007 3.007 0 002.088-2.088 31.247 31.247 0 00.5-5.783 31.247 31.247 0 00-.5-5.805zM9.609 15.601V8.408l6.264 3.602z'],
                'tiktok'    => ['#010101','M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V9.02a8.19 8.19 0 004.77 1.52V7.1a4.85 4.85 0 01-1-.41z'],
                'pinterest' => ['#e60023','M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.632-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.987C24.007 5.367 18.641.001 12.017.001z'],
            ];
            $icons = '';
            foreach ($links as $link) {
                $platform = strtolower((string) ($link['platform'] ?? 'facebook'));
                $known    = isset($svgPaths[$platform]) ? $platform : 'facebook';
                $url      = (string) ($link['url'] ?? '#');
                [$color, $path] = $svgPaths[$known];
                // Gmail (and most webmail clients) strip inline <svg>, leaving only the
                // colored circle. Use a hosted PNG via <img> instead; keep the circle as
                // the link background so it still looks right when images are blocked.
                $iconUrl = self::absoluteMediaUrl('/assets/img/social/' . $known . '.png');
                $icons .= '<a href="' . self::esc($url ?: '#') . '" style="display:inline-block;width:' . $size . 'px;height:' . $size . 'px;background:' . $color . ';border-radius:50%;margin:0 4px;text-decoration:none;" target="_blank">'
                    . '<img src="' . self::esc($iconUrl) . '" alt="' . self::esc(ucfirst($known)) . '" width="' . $size . '" height="' . $size . '" style="display:block;width:' . $size . 'px;height:' . $size . 'px;border:0;border-radius:50%;">'
                    . '</a>';
            }
            return '<div style="background:' . $bg . ';' . $bgStyle . $hPad . $vPad . 'text-align:' . $align . ';">' . ($icons ?: '') . '</div>';
        }
        if (in_array($type, ['columns_2','columns_2_6040','columns_2_4060','columns_2_7030','columns_2_3070','columns_3'])) {
            // Prefer 'columns' (JS key, always updated) over 'columns_content' (normalized legacy)
            $colsRaw = $block['columns'] ?? null;
            $contents = (is_array($colsRaw) && isset($colsRaw[0]) && is_array($colsRaw[0]))
                ? $colsRaw
                : (is_array($block['columns_content'] ?? null) ? $block['columns_content'] : []);
            $colWidthsMap2 = ['columns_3'=>[33,34,33],'columns_2_6040'=>[60,40],'columns_2_4060'=>[40,60],'columns_2_7030'=>[70,30],'columns_2_3070'=>[30,70]];
            $widths2 = is_array($block['col_widths'] ?? null) ? $block['col_widths'] : ($colWidthsMap2[$type] ?? [50,50]);
            $columns = count($widths2);
            // Fluid hybrid (spongy) layout: each column is inline-block with width:100%
            // capped at a proportional max-width. On screens narrower than the sum of the
            // max-widths the columns wrap and stack automatically — no media query needed,
            // so it works even in clients that strip <head> styles (Gmail Android, etc.).
            $reference = 600; // desktop content width the columns add up to
            $colBg = self::esc((string) ($block['background'] ?? '#ffffff'));
            $colText = self::esc((string) ($block['text_color'] ?? '#1f2937'));
            $cells = [];
            for ($index = 0; $index < $columns; $index++) {
                $defaultText = 'Coloana ' . ($index + 1);
                $columnBlocks = self::normalizeColumnBlocks($contents[$index] ?? null, $defaultText);
                $childHtml = '';
                foreach ($columnBlocks as $columnBlock) {
                    $childHtml .= self::wrapDeviceHide($columnBlock, self::renderBlock($columnBlock));
                }
                $w = $widths2[$index];
                $maxW = max(120, (int) round($reference * $w / 100));
                $inner = '<div style="background:' . $colBg . ';color:' . $colText . ';padding:10px;line-height:1.55;">'
                    . $childHtml
                    . '</div>';
                $cells[] = '<!--[if mso]><td width="' . $w . '%" valign="top"><![endif]-->'
                    . '<div class="nl-col-div" style="display:inline-block;vertical-align:top;width:100%;max-width:' . $maxW . 'px;font-size:14px;text-align:left;">'
                    . $inner
                    . '</div>'
                    . '<!--[if mso]></td><![endif]-->';
            }
            return '<div style="' . $vPad . $hPad . 'background:' . self::esc((string) ($block['background'] ?? $block['block_background'] ?? '#ffffff')) . ';' . $bgStyle . 'font-size:0;text-align:center;">'
                . '<!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;"><tr><![endif]-->'
                . implode('', $cells)
                . '<!--[if mso]></tr></table><![endif]-->'
                . '</div>';
        }

        return '<div style="height:' . max(4, (int) ($block['height'] ?? 24)) . 'px;' . $hPad . 'background:' . self::esc((string)($block['background']??'#ffffff')) . ';' . $bgStyle . '"></div>';
    }

    private static function normalizeFontSize(int $fontSize, int $fallback): int
    {
        if ($fontSize <= 0) {
            $fontSize = $fallback;
        }

        return max(10, min(96, $fontSize));
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Maps a font key to an email-safe font stack. Empty/unknown => '' (inherit).
     */
    private static function fontStack(string $key): string
    {
        $map = [
            'arial'       => "Arial, Helvetica, sans-serif",
            'helvetica'   => "Helvetica, Arial, sans-serif",
            'verdana'     => "Verdana, Geneva, sans-serif",
            'tahoma'      => "Tahoma, Geneva, sans-serif",
            'trebuchet'   => "'Trebuchet MS', Helvetica, sans-serif",
            'georgia'     => "Georgia, 'Times New Roman', serif",
            'times'       => "'Times New Roman', Times, serif",
            'palatino'    => "'Palatino Linotype', 'Book Antiqua', Palatino, serif",
            'courier'     => "'Courier New', Courier, monospace",
            'lucida'      => "'Lucida Sans Unicode', 'Lucida Grande', sans-serif",
        ];
        $key = strtolower(trim($key));
        return $map[$key] ?? '';
    }

    private static function normalizeBlock(array $block, bool $allowColumns = true, string $fallbackText = 'Text'): array
    {
        $type = (string) ($block['type'] ?? 'text');
        if (!$allowColumns && ($type === 'columns_2' || $type === 'columns_3')) {
            $type = 'text';
        }

        $row = [
            'type' => $type,
            'align' => (string) ($block['align'] ?? 'left'),
            'background' => (string) ($block['background'] ?? '#ffffff'),
            'text_color' => (string) ($block['text_color'] ?? '#1f2937'),
            'block_background' => (string) ($block['block_background'] ?? '#ffffff'),
            'padding_left'  => max(0, (int) ($block['padding_left'] ?? 0)),
            'padding_right' => max(0, (int) ($block['padding_right'] ?? 0)),
            'padding_top'    => max(0, (int) ($block['padding_top'] ?? 0)),
            'padding_bottom' => max(0, (int) ($block['padding_bottom'] ?? 0)),
            'font_family' => (string) ($block['font_family'] ?? ''),
            'hide_desktop' => !empty($block['hide_desktop']) ? 1 : 0,
            'hide_tablet'  => !empty($block['hide_tablet']) ? 1 : 0,
            'hide_mobile'  => !empty($block['hide_mobile']) ? 1 : 0,
            'bg_image' => (string) ($block['bg_image'] ?? ''),
            'bg_size'  => in_array($block['bg_size'] ?? '', ['cover','contain','auto']) ? $block['bg_size'] : 'cover',
        ];
        if ($type === 'header') {
            $row['content'] = (string) ($block['content'] ?? 'Titlu');
            $row['align'] = (string) ($block['align'] ?? 'center');
            $desktopSize = self::normalizeFontSize((int) ($block['font_size'] ?? 0), 34);
            $row['font_size'] = $desktopSize;
            $row['font_size_mobile'] = self::normalizeFontSize((int) ($block['font_size_mobile'] ?? $desktopSize), $desktopSize);
            return $row;
        }
        if ($type === 'text') {
            $row['content'] = (string) ($block['content'] ?? $fallbackText);
            $desktopSize = self::normalizeFontSize((int) ($block['font_size'] ?? 0), 15);
            $row['font_size'] = $desktopSize;
            $row['font_size_mobile'] = self::normalizeFontSize((int) ($block['font_size_mobile'] ?? $desktopSize), $desktopSize);
            return $row;
        }
        if ($type === 'image') {
            $row['image_url'] = (string) ($block['image_url'] ?? '');
            $row['alt']       = (string) ($block['alt'] ?? '');
            $row['width']     = min(100, max(10, (int) ($block['width'] ?? 100)));
            $row['align']     = (string) ($block['align'] ?? 'center');
            $row['border_radius'] = max(0, (int) ($block['border_radius'] ?? 0));
            $row['link_url']  = (string) ($block['link_url'] ?? '');
            return $row;
        }
        if ($type === 'button') {
            $row['label'] = (string) ($block['label'] ?? 'Află mai multe');
            $row['url'] = (string) ($block['url'] ?? '#');
            $row['button_color'] = (string) ($block['button_color'] ?? '#2d7d5f');
            $row['border_radius'] = max(0, (int) ($block['border_radius'] ?? $block['radius'] ?? 6));
            $row['radius'] = $row['border_radius'];
            $row['font_size'] = self::normalizeFontSize((int) ($block['font_size'] ?? 0), 16);
            $row['align'] = (string) ($block['align'] ?? 'center');
            $row['background'] = (string) ($block['background'] ?? '#ffffff');
            $row['text_color'] = (string) ($block['text_color'] ?? '#ffffff');
            $row['btn_pad_v'] = min(40, max(2, (int) ($block['btn_pad_v'] ?? 11)));
            $row['btn_pad_h'] = min(80, max(4, (int) ($block['btn_pad_h'] ?? 22)));
            return $row;
        }
        if ($type === 'divider') {
            $row['line_color'] = (string) ($block['line_color'] ?? $block['color'] ?? '#dbe2ea');
            $row['color']     = (string) ($block['color'] ?? $block['line_color'] ?? '#dbe2ea');
            $row['width']     = min(100, max(10, (int) ($block['width'] ?? 80)));
            $row['thickness'] = max(1, (int) ($block['thickness'] ?? 1));
            return $row;
        }
        if ($type === 'social') {
            $row['links']      = is_array($block['links'] ?? null) ? $block['links'] : [];
            $row['icon_size']  = (int) ($block['icon_size'] ?? 32);
            $row['align']      = (string) ($block['align'] ?? 'center');
            $row['background'] = (string) ($block['background'] ?? '#ffffff');
            return $row;
        }
        if ($type === 'spacer') {
            $row['height'] = (int) ($block['height'] ?? 24);
            return $row;
        }
        // Citatul există în paleta builderului, dar aici nu era cunoscut: la
        // salvare devenea text simplu (autorul și bordura se pierdeau), iar
        // emailul nu semăna cu ce se vedea în editor.
        if ($type === 'quote') {
            $row['content'] = (string) ($block['content'] ?? '');
            $row['author'] = (string) ($block['author'] ?? '');
            $row['background'] = (string) ($block['background'] ?? '#f0fdf4');
            $row['text_color'] = (string) ($block['text_color'] ?? '#166534');
            $row['border_color'] = (string) ($block['border_color'] ?? '#2d7d5f');
            $row['font_size'] = self::normalizeFontSize((int) ($block['font_size'] ?? 0), 16);
            return $row;
        }
        $colTypes = ['columns_2','columns_2_6040','columns_2_4060','columns_2_7030','columns_2_3070','columns_3'];
        if ($allowColumns && in_array($type, $colTypes)) {
            $colWidthsMap = ['columns_3'=>[33,34,33],'columns_2_6040'=>[60,40],'columns_2_4060'=>[40,60],'columns_2_7030'=>[70,30],'columns_2_3070'=>[30,70]];
            $widths = $colWidthsMap[$type] ?? [50,50];
            $columns = count($widths);
            $contentArray = [];
            // 'columns' (JS key) takes priority — it's always up-to-date.
            // 'columns_content' is legacy / normalized output from a previous save.
            if (isset($block['columns']) && is_array($block['columns']) && isset($block['columns'][0]) && is_array($block['columns'][0])) {
                $contentArray = $block['columns'];
            } elseif (isset($block['columns_content']) && is_array($block['columns_content'])) {
                $contentArray = $block['columns_content'];
            } elseif (isset($block['content_columns']) && is_array($block['content_columns'])) {
                $contentArray = $block['content_columns'];
            }
            $normalizedColumns = [];
            for ($index = 0; $index < $columns; $index++) {
                $defaultText = 'Coloana ' . ($index + 1);
                $normalizedColumns[] = self::normalizeColumnBlocks($contentArray[$index] ?? null, $defaultText);
            }
            $row['columns'] = $normalizedColumns; // JS reads this as array-of-arrays
            $row['columns_content'] = $normalizedColumns; // PHP renderBlock reads this
            $row['col_widths'] = $widths;
            return $row;
        }

        return self::normalizeBlock([
            'type' => 'text',
            'content' => (string) ($block['content'] ?? $fallbackText),
            'align' => (string) ($block['align'] ?? 'left'),
            'background' => (string) ($block['background'] ?? '#ffffff'),
            'text_color' => (string) ($block['text_color'] ?? '#1f2937'),
            'block_background' => (string) ($block['block_background'] ?? '#ffffff'),
            'font_size' => (int) ($block['font_size'] ?? 0),
        ], false, $fallbackText);
    }

    private static function normalizeColumnBlocks(mixed $entry, string $defaultText): array
    {
        $items = [];
        if (is_array($entry)) {
            $looksLikeSingleBlock = isset($entry['type']) || isset($entry['content']) || isset($entry['image_url']) || isset($entry['label']);
            if ($looksLikeSingleBlock) {
                $items[] = self::normalizeBlock($entry, false, $defaultText);
            } else {
                $allScalars = $entry !== [];
                foreach ($entry as $value) {
                    if (is_array($value) || is_object($value)) {
                        $allScalars = false;
                        break;
                    }
                }
                if ($allScalars) {
                    $parts = [];
                    foreach ($entry as $value) {
                        $part = trim((string) $value);
                        if ($part !== '') {
                            $parts[] = $part;
                        }
                    }
                    if ($parts !== []) {
                        $items[] = self::normalizeBlock([
                            'type' => 'text',
                            'content' => implode("\n", $parts),
                        ], false, $defaultText);
                    }
                } else {
                    foreach ($entry as $value) {
                        if (is_array($value)) {
                            $items[] = self::normalizeBlock($value, false, $defaultText);
                        }
                    }
                }
            }
        } elseif (is_string($entry) || is_numeric($entry)) {
            $text = trim((string) $entry);
            if ($text !== '') {
                $items[] = self::normalizeBlock([
                    'type' => 'text',
                    'content' => $text,
                ], false, $defaultText);
            }
        }

        return $items;
    }

    private static function allowRichTags(string $escaped): string
    {
        // Un-escape only safe formatting tags that the editor inserts
        // Also allow <a href="..."> — unescape &lt;a href=&quot;...&quot;&gt; pattern
        $escaped = preg_replace('/&lt;(\/?(b|i|u|s|strike|del|strong|em))&gt;/i', '<$1>', $escaped);
        $escaped = preg_replace('/&lt;a\s+href=&quot;([^&]*)&quot;&gt;/i', '<a href="$1">', $escaped);
        $escaped = preg_replace('/&lt;\/a&gt;/i', '</a>', $escaped);
        return $escaped;
    }

    private static function renderTextWithLinks(string $value): string
    {
        $pattern = '/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+|tel:[^\s)]+)\)|((?:https?:\/\/|www\.)[^\s<]+)/iu';
        $matches = [];
        if (!preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE)) {
            return self::allowRichTags(self::newlineToBr(self::esc($value)));
        }

        $result = '';
        $cursor = 0;
        $total = count($matches[0]);
        for ($index = 0; $index < $total; $index++) {
            $full = (string) ($matches[0][$index][0] ?? '');
            $offset = (int) ($matches[0][$index][1] ?? 0);
            if ($offset > $cursor) {
                $result .= self::esc(substr($value, $cursor, $offset - $cursor));
            }

            $label = trim((string) ($matches[1][$index][0] ?? ''));
            $markdownUrl = trim((string) ($matches[2][$index][0] ?? ''));
            $autoUrl = trim((string) ($matches[3][$index][0] ?? ''));
            $url = $markdownUrl !== '' ? $markdownUrl : $autoUrl;
            $labelText = $label !== '' ? $label : $autoUrl;
            $href = self::normalizeLinkUrl($url);

            if ($href !== '') {
                $result .= '<a href="' . self::esc($href) . '" style="color:inherit;text-decoration:underline;">'
                    . self::esc($labelText !== '' ? $labelText : $href)
                    . '</a>';
            } else {
                $result .= self::esc($full);
            }
            $cursor = $offset + strlen($full);
        }

        if ($cursor < strlen($value)) {
            $result .= self::esc(substr($value, $cursor));
        }

        return self::allowRichTags(self::newlineToBr($result));
    }

    private static function normalizeLinkUrl(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(https?:\/\/|mailto:|tel:)/i', $raw)) {
            return $raw;
        }
        if (preg_match('/^www\./i', $raw)) {
            return 'https://' . $raw;
        }
        // O cale din site („/magazin”) devine adresă completă: în email nu
        // există „site curent”, iar o cale goală nu duce nicăieri.
        if (preg_match('#^/(?!/)#', $raw)) {
            $baseUrl = self::appBaseUrl();
            return $baseUrl !== '' ? $baseUrl . $raw : '';
        }

        return '';
    }

    /**
     * Adresa unui buton: căile din site devin adrese complete, restul rămân
     * cum le-a scris omul (inclusiv „#” sau {{token}}-urile din emailurile de
     * comandă).
     */
    private static function linkButon(string $value): string
    {
        $raw = trim($value);
        if (preg_match('#^/(?!/)#', $raw)) {
            $baseUrl = self::appBaseUrl();
            return $baseUrl !== '' ? $baseUrl . $raw : $raw;
        }
        return $raw === '' ? '#' : $raw;
    }

    private static function newlineToBr(string $value): string
    {
        return str_replace(["\r\n", "\n", "\r"], '<br>', $value);
    }

    private static function absoluteMediaUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }
        if (preg_match('/^(https?:)?\/\//i', $trimmed)) {
            return $trimmed;
        }

        $baseUrl = self::appBaseUrl();
        if ($baseUrl === '') {
            return $trimmed;
        }
        if ($trimmed[0] === '/') {
            return $baseUrl . $trimmed;
        }

        return $baseUrl . '/' . ltrim($trimmed, '/');
    }
}
