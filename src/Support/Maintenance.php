<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Mod mentenanță: vizitatorii văd o pagină „Revenim în curând", iar cei care
 * testează văd site-ul normal.
 *
 * Accesul se obține în două feluri: fiind autentificat în administrare, sau
 * deschizând site-ul cu cheia de previzualizare în adresă
 * (`https://site.ro/?preview=CHEIE`), care lasă un cookie valabil 30 de zile.
 *
 * Rutele tehnice rămân mereu deschise — webhook-urile de plată, notificările
 * din ERP și fișierele statice trebuie să funcționeze și în mentenanță,
 * altfel se pierd plăți și AWB-uri.
 */
final class Maintenance
{
    /** Cookie-ul care ține minte că vizitatorul are drept de previzualizare. */
    public const COOKIE = 'bv_preview';

    private const DURATA_COOKIE = 30 * 24 * 60 * 60;

    /** Prefixe de cale care nu sunt niciodată blocate. */
    private const CAI_PERMISE = [
        '/admin',
        '/health',
        '/webhook/',
        '/api/erp/',
        '/assets/',
        '/uploads/',
        '/storage/',
        '/favicon',
        '/robots.txt',
        '/sitemap',
    ];

    /**
     * Dacă e cazul, afișează pagina de mentenanță și oprește cererea.
     * Întoarce true dacă cererea a fost tratată aici.
     */
    public static function intercepteaza(array $settings, string $metoda, string $uri): bool
    {
        if ((string) ($settings['maintenance_enabled'] ?? '0') !== '1') {
            return false;
        }

        $cale = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $cheie = trim((string) ($settings['maintenance_key'] ?? ''));

        // Cheia din adresă deschide accesul și rămâne în cookie.
        $dinAdresa = trim((string) ($_GET['preview'] ?? ''));
        if ($cheie !== '' && $dinAdresa !== '' && hash_equals($cheie, $dinAdresa)) {
            setcookie(self::COOKIE, $cheie, [
                'expires' => time() + self::DURATA_COOKIE,
                'path' => '/',
                'samesite' => 'Lax',
                'secure' => self::eHttps(),
                'httponly' => true,
            ]);
            $_COOKIE[self::COOKIE] = $cheie;
            // Curățăm adresa, ca linkul cu cheia să nu ajungă din greșeală public.
            $curat = self::faraParametruPreview($uri);
            header('Location: ' . ($curat === '' ? '/' : $curat), true, 302);
            return true;
        }

        if (self::arePermisiune($settings, $cale)) {
            return false;
        }

        self::afiseaza($settings, $metoda);
        return true;
    }

    /** Vizitatorul poate vedea site-ul normal? */
    public static function arePermisiune(array $settings, string $cale): bool
    {
        foreach (self::CAI_PERMISE as $prefix) {
            if (str_starts_with($cale, $prefix)) {
                return true;
            }
        }

        // Administratorii logați testează fără să facă nimic special.
        if (Auth::check()) {
            return true;
        }

        $cheie = trim((string) ($settings['maintenance_key'] ?? ''));
        $dinCookie = trim((string) ($_COOKIE[self::COOKIE] ?? ''));
        if ($cheie !== '' && $dinCookie !== '' && hash_equals($cheie, $dinCookie)) {
            return true;
        }

        return self::ipPermis((string) ($settings['maintenance_allowed_ips'] ?? ''));
    }

    /** Generează o cheie nouă de previzualizare. */
    public static function cheieNoua(): string
    {
        return bin2hex(random_bytes(12));
    }

    /** Linkul complet pe care îl dai celor care trebuie să vadă site-ul. */
    public static function linkPreview(string $appUrl, string $cheie): string
    {
        $cheie = trim($cheie);
        if ($cheie === '') {
            return '';
        }
        return rtrim($appUrl, '/') . '/?preview=' . rawurlencode($cheie);
    }

    // ───────────────────────────────────────────────────────────
    // Intern
    // ───────────────────────────────────────────────────────────

    private static function afiseaza(array $settings, string $metoda): void
    {
        // 503 + Retry-After: Google înțelege că e temporar și nu scoate
        // paginile din index.
        http_response_code(503);
        header('Retry-After: 3600');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Type: text/html; charset=utf-8');

        if (strtoupper($metoda) === 'HEAD') {
            return;
        }

        $titlu = trim((string) ($settings['maintenance_title'] ?? ''));
        if ($titlu === '') {
            $titlu = 'Revenim în curând';
        }
        $mesaj = trim((string) ($settings['maintenance_message'] ?? ''));
        if ($mesaj === '') {
            $mesaj = 'Lucrăm la magazin chiar acum. Ne întoarcem cât de repede putem.';
        }

        $fisier = dirname(__DIR__, 2) . '/views/site/maintenance.php';
        if (!is_file($fisier)) {
            echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($titlu, ENT_QUOTES)
                . '</title><p>' . htmlspecialchars($mesaj, ENT_QUOTES) . '</p>';
            return;
        }

        $date = [
            'titlu' => $titlu,
            'mesaj' => $mesaj,
            'email' => trim((string) ($settings['contact_email'] ?? $settings['order_email_from_email'] ?? '')),
            'telefon' => trim((string) ($settings['contact_phone'] ?? '')),
            'logo' => trim((string) ($settings['store_favicon_url'] ?? '')),
        ];
        extract($date, EXTR_SKIP);
        require $fisier;
    }

    private static function ipPermis(string $lista): bool
    {
        $lista = trim($lista);
        if ($lista === '') {
            return false;
        }
        $ipCurent = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ipCurent === '') {
            return false;
        }
        foreach (preg_split('/[\s,;]+/', $lista) ?: [] as $ip) {
            if (trim($ip) !== '' && trim($ip) === $ipCurent) {
                return true;
            }
        }
        return false;
    }

    private static function faraParametruPreview(string $uri): string
    {
        $cale = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return $cale;
        }
        parse_str($query, $parametri);
        unset($parametri['preview']);
        $ramas = http_build_query($parametri);
        return $ramas === '' ? $cale : $cale . '?' . $ramas;
    }

    private static function eHttps(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
