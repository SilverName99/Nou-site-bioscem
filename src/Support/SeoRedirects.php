<?php

declare(strict_types=1);

namespace App\Support;

/**
 * O singură adresă pentru fiecare pagină, plus redirecționările adreselor vechi.
 *
 * Rulează la începutul lui public/index.php, înainte de bootstrap (deci înainte
 * de sesiune și de autoloader), și se ocupă DOAR de cererile GET/HEAD care nu
 * țin de coș, plată, cont, admin, API sau confirmările de plată (vezi
 * CAI_EXCLUSE). Orice altă cerere trece exact ca înainte, fără nicio atingere.
 *
 * Trei lucruri:
 *  1. gazda și schema: www.bioscem.ro sau http → https://bioscem.ro (gazda din
 *     APP_URL; nu face nimic dacă APP_URL nu e https, deci nici local);
 *  2. calea: „/x/" și „/x//y" → „/x" și „/x/y" (prima pagină rămâne „/");
 *  3. harta din config/redirecturi.php: adrese vechi (WordPress, produse
 *     șterse) → pagina nouă, cu 301, sau 410 pentru resturi tehnice WordPress.
 *
 * Cum: când o cerere are ceva de reparat, aplicația rulează normal pe calea
 * curată, cu răspunsul ținut în buffer. La final (funcție de oprire, deci și
 * după un exit()) se hotărăște:
 *  - harta se aplică doar dacă aplicația ar fi răspuns 404/410 sau cu o
 *    redirecționare ghicită (potrivirea aproximativă a produselor); o pagină
 *    care există nu e acoperită niciodată de hartă;
 *  - o redirecționare a aplicației devine un singur salt, direct la ținta finală;
 *  - o pagină care există primește 301 la adresa curată;
 *  - o pagină inexistentă pe gazda corectă își păstrează 404-ul (fără 301 spre 404).
 * Rezultatul: un singur 301, fără lanțuri și fără bucle; parametrii din adresă
 * (gclid, utm_*…) se păstrează.
 *
 * Oprire de urgență: SEO_REDIRECTURI=0 în .env dezactivează tot stratul.
 */
final class SeoRedirects
{
    /**
     * Căi care nu se redirecționează niciodată (prefixe, fără diferență între
     * litere mari și mici): coș, plată, cont, admin, API, confirmări de plată
     * și de ERP, autentificarea Google, newsletter, fișiere, sănătate.
     * Un prefix care se termină în „/" prinde doar subcăile; unul fără „/"
     * prinde calea exactă și subcăile ei („/cos" prinde „/cos" și „/cos/…",
     * dar nu „/cosmetice").
     */
    public const CAI_EXCLUSE = [
        '/admin', '/api', '/auth', '/webhook', '/plata', '/checkout',
        '/cos', '/cos-cumparaturi', '/contul-meu', '/login', '/register',
        '/newsletter', '/health', '/review-form', '/gdpr-agreements',
        '/contact/send', '/uploads', '/assets', '/storage', '/feed', '/.well-known',
        '/cdn-cgi', '/robots.txt', '/sitemap.xml', '/favicon.ico',
    ];

    /** Codurile la care aplicația „n-a găsit" pagina, deci harta poate răspunde. */
    private const CODURI_NEGASIT = [404, 410];

    private static bool $activ = false;
    private static bool $redirectCanonic = false;
    private static int $nivelBuffer = 0;
    private static string $caleCurata = '/';
    private static string $query = '';
    private static ?string $bazaCanonica = null;
    private static bool $gazdaGresita = false;
    private static bool $caleGresita = false;
    /** @var array{0: ?string, 1: int}|null ținta din hartă și codul */
    private static ?array $regula = null;

    /** @var array<string, array{0: ?string, 1: int}>|null */
    private static ?array $harta = null;
    /** @var list<array{0: string, 1: ?string, 2: int}>|null */
    private static ?array $prefixe = null;

    /**
     * Pornește verificarea cererii curente. Nu trimite nimic și nu oprește
     * nimic: cel mult rescrie REQUEST_URI la calea curată și pune un buffer.
     */
    public static function incepe(string $radacina): void
    {
        try {
            self::incepeIntern($radacina);
        } catch (\Throwable) {
            // Stratul SEO nu are voie să strice vreo cerere.
            self::$activ = false;
        }
    }

    private static function incepeIntern(string $radacina): void
    {
        if (!class_exists(Env::class, false)) {
            require_once __DIR__ . '/Env.php';
        }
        Env::load($radacina . '/.env');
        if (in_array(strtolower(trim((string) Env::get('SEO_REDIRECTURI', '1'))), ['0', 'false', 'off', 'nu'], true)) {
            return;
        }

        $metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($metoda !== 'GET' && $metoda !== 'HEAD') {
            return;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        [$caleBruta, $query] = self::imparteUri($uri);
        if (self::eExclusa($caleBruta)) {
            return;
        }

        $caleCurata = self::curataCale($caleBruta);
        if (self::eExclusa($caleCurata)) {
            return;
        }

        self::$bazaCanonica = self::bazaCanonica();
        $gazdaGresita = false;
        if (self::$bazaCanonica !== null) {
            $gazdaCanonica = (string) parse_url(self::$bazaCanonica, PHP_URL_HOST);
            $gazda = self::gazdaCerere();
            if ($gazda === $gazdaCanonica) {
                $gazdaGresita = !self::eHttps();
            } elseif (in_array($gazda, self::aliasuriGazda($gazdaCanonica), true)) {
                $gazdaGresita = true;
            }
            // Orice altă gazdă (adresa tehnică a serverului, un IP) nu se
            // atinge: nu știm sigur că e același site, iar o regulă greșită
            // aici ar face buclă.
        }
        $caleGresita = $caleCurata !== $caleBruta;
        $regula = self::regulaPentru($caleCurata);

        if (!$gazdaGresita && !$caleGresita && $regula === null) {
            return;
        }

        self::$activ = true;
        self::$caleCurata = $caleCurata;
        self::$query = $query;
        self::$gazdaGresita = $gazdaGresita;
        self::$caleGresita = $caleGresita;
        self::$regula = $regula;

        // Aplicația vede cererea pe calea curată: routerul, canonicalul și
        // cache-ul de pagini lucrează exact ca pentru adresa corectă.
        $_SERVER['SEO_REQUEST_URI_ORIGINAL'] = $uri;
        $_SERVER['REQUEST_URI'] = $caleCurata . ($query !== '' ? '?' . $query : '');
        if ($metoda === 'HEAD') {
            // Routerul n-are rute HEAD; ca să știm dacă pagina există, o cerem
            // ca GET. Serverul web nu trimite oricum corpul la HEAD.
            $_SERVER['SEO_REQUEST_METHOD_ORIGINAL'] = 'HEAD';
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        ob_start();
        self::$nivelBuffer = ob_get_level();
        register_shutdown_function([self::class, 'termina']);
    }

    /** Rulează la oprirea scriptului: hotărăște ce pleacă spre browser. */
    public static function termina(): void
    {
        if (!self::$activ) {
            return;
        }
        self::$activ = false;
        try {
            self::terminaIntern();
        } catch (\Throwable) {
            // Lăsăm răspunsul aplicației să plece neatins.
        }
    }

    private static function terminaIntern(): void
    {
        if (headers_sent() || ob_get_level() < self::$nivelBuffer) {
            return;
        }

        $cod = (int) (http_response_code() ?: 200);
        $locatieAplicatie = self::antet('Location');

        // 1. Harta, doar unde aplicația n-a găsit pagina sau a ghicit-o. Un 301
        //    spre forma corectă a unei pagini existente (litere mari → mici)
        //    nu e ghicit: acolo pagina există și harta nu are ce căuta.
        $aplicatiaAGhicit = $cod >= 300 && $cod < 400 && !self::$redirectCanonic;
        if (self::$regula !== null && (in_array($cod, self::CODURI_NEGASIT, true) || $aplicatiaAGhicit)) {
            [$tinta, $codRegula] = self::$regula;
            if ($codRegula === 410) {
                if (in_array($cod, self::CODURI_NEGASIT, true)) {
                    // Pagina „nu există" a aplicației rămâne, doar codul spune „dispărut definitiv".
                    http_response_code(410);
                    return;
                }
            } elseif ($tinta !== null && self::cheie($tinta) !== self::cheie(self::$caleCurata)) {
                self::redirectioneaza(self::adresaCompleta($tinta, self::$query), 301);
                return;
            }
        }

        // 2. Redirecționarea aplicației (de exemplu produs găsit după un slug
        //    vechi), făcută dintr-un singur salt până la ținta finală.
        if ($cod >= 300 && $cod < 400 && $locatieAplicatie !== '') {
            if (self::$gazdaGresita || self::$caleGresita) {
                $tinta = self::locatieCanonica($locatieAplicatie);
                if ($tinta !== null) {
                    self::redirectioneaza($tinta, in_array($cod, [301, 308], true) ? 301 : $cod);
                }
            }
            return;
        }

        // 3. Pagina există: 301 la adresa curată, pe gazda corectă.
        if ($cod >= 200 && $cod < 300) {
            if (self::$gazdaGresita || self::$caleGresita) {
                self::redirectioneaza(self::adresaCompleta(self::$caleCurata, self::$query), 301);
            }
            return;
        }

        // 4. Altceva (404 fără regulă, 503 mentenanță, eroare): pe gazda greșită
        //    tot mutăm vizitatorul pe gazda corectă; pe gazda corectă lăsăm
        //    răspunsul așa cum e (fără 301 spre o pagină care nu există).
        if (self::$gazdaGresita) {
            self::redirectioneaza(self::adresaCompleta(self::$caleCurata, self::$query), 301);
        }
    }

    private static function redirectioneaza(string $locatie, int $cod): void
    {
        while (ob_get_level() >= self::$nivelBuffer && ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($cod);
        header('Location: ' . $locatie, true);
        header_remove('Content-Length');
        header_remove('ETag');
        header_remove('Last-Modified');
        header_remove('X-Page-Cache');
        // Primele săptămâni: browserele nu țin minte redirecționarea, ca o
        // întoarcere la versiunea anterioară să se vadă imediat.
        header('Cache-Control: ' . self::cacheControlRedirect(), true);
        header('Content-Type: text/html; charset=utf-8', true);
    }

    // ───────────────────────────────────────────────────────────
    // Reguli, publice pentru controller și pentru teste
    // ───────────────────────────────────────────────────────────

    /**
     * 301 de la o variantă a adresei (litere mari, alt fel de scriere) la
     * adresa corectă a unei pagini care există. Doar la GET/HEAD și doar în
     * afara căilor excluse. Întoarce true dacă a trimis redirecționarea.
     */
    public static function redirectCanonic(string $caleCorecta): bool
    {
        $metoda = strtoupper((string) ($_SERVER['SEO_REQUEST_METHOD_ORIGINAL'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($metoda !== 'GET' && $metoda !== 'HEAD') {
            return false;
        }
        if (in_array(strtolower(trim((string) (class_exists(Env::class) ? Env::get('SEO_REDIRECTURI', '1') : '1'))), ['0', 'false', 'off', 'nu'], true)) {
            return false;
        }
        if ($caleCorecta === '' || self::eExclusa($caleCorecta)) {
            return false;
        }
        [, $query] = self::imparteUri((string) ($_SERVER['REQUEST_URI'] ?? '/'));
        self::$redirectCanonic = true;
        header('Location: ' . $caleCorecta . ($query !== '' ? '?' . $query : ''), true, 301);
        header('Cache-Control: ' . self::cacheControlRedirect(), true);
        return true;
    }

    /** Calea e una dintre cele care nu se redirecționează niciodată? */
    public static function eExclusa(string $cale): bool
    {
        $cale = strtolower($cale);
        if ($cale === '' || $cale[0] !== '/') {
            $cale = '/' . $cale;
        }
        foreach (self::CAI_EXCLUSE as $prefix) {
            if (str_ends_with($prefix, '/')) {
                if (str_starts_with($cale, $prefix)) {
                    return true;
                }
                continue;
            }
            if ($cale === $prefix || str_starts_with($cale, $prefix . '/')) {
                return true;
            }
        }
        // „//cos" sau „/./cos" ajung la router tot ca „/cos".
        $faraDuble = (string) preg_replace('#/{2,}#', '/', $cale);
        return $faraDuble !== $cale && self::eExclusa($faraDuble);
    }

    /** „/x//y/" → „/x/y"; prima pagină rămâne „/". Codarea literelor rămâne neatinsă. */
    public static function curataCale(string $cale): string
    {
        $cale = (string) preg_replace('#/{2,}#', '/', $cale === '' ? '/' : $cale);
        if ($cale === '' || $cale[0] !== '/') {
            $cale = '/' . $cale;
        }
        if ($cale !== '/') {
            $cale = rtrim($cale, '/');
            if ($cale === '') {
                $cale = '/';
            }
        }
        return $cale;
    }

    /** Cheia din hartă: decodată, cu litere mici, fără bare duble sau finale. */
    public static function cheie(string $cale): string
    {
        $cale = rawurldecode(explode('?', $cale, 2)[0]);
        $cale = function_exists('mb_strtolower') ? mb_strtolower($cale, 'UTF-8') : strtolower($cale);
        return self::curataCale($cale);
    }

    /**
     * Regula din hartă pentru o cale (exactă sau după prefix), sau null.
     *
     * @return array{0: ?string, 1: int}|null
     */
    public static function regulaPentru(string $cale): ?array
    {
        self::incarcaHarta();
        $cheie = self::cheie($cale);
        if (isset(self::$harta[$cheie])) {
            return self::$harta[$cheie];
        }
        foreach (self::$prefixe ?? [] as [$prefix, $tinta, $cod]) {
            if (str_starts_with($cheie, $prefix)) {
                return [$tinta, $cod];
            }
        }
        return null;
    }

    /**
     * Toate regulile, cum sunt în config/redirecturi.php (pentru teste și verificări).
     *
     * @return array{exacte: list<array{0: string, 1: ?string, 2: int, 3: string}>, prefixe: list<array{0: string, 1: ?string, 2: int, 3: string}>}
     */
    public static function reguli(): array
    {
        $fisier = dirname(__DIR__, 2) . '/config/redirecturi.php';
        $date = is_file($fisier) ? require $fisier : [];
        return [
            'exacte' => array_values((array) ($date['exacte'] ?? [])),
            'prefixe' => array_values((array) ($date['prefixe'] ?? [])),
        ];
    }

    private static function incarcaHarta(): void
    {
        if (self::$harta !== null) {
            return;
        }
        self::$harta = [];
        self::$prefixe = [];
        $reguli = self::reguli();
        foreach ($reguli['exacte'] as $rand) {
            if (!is_array($rand) || !isset($rand[0])) {
                continue;
            }
            $cod = (int) ($rand[2] ?? 301);
            $tinta = isset($rand[1]) && $rand[1] !== null && $rand[1] !== '' ? (string) $rand[1] : null;
            if ($cod !== 410 && $tinta === null) {
                continue;
            }
            self::$harta[self::cheie((string) $rand[0])] = [$tinta, $cod === 410 ? 410 : 301];
        }
        foreach ($reguli['prefixe'] as $rand) {
            if (!is_array($rand) || !isset($rand[0])) {
                continue;
            }
            $cod = (int) ($rand[2] ?? 410);
            $tinta = isset($rand[1]) && $rand[1] !== null && $rand[1] !== '' ? (string) $rand[1] : null;
            if ($cod !== 410 && $tinta === null) {
                continue;
            }
            self::$prefixe[] = [strtolower((string) $rand[0]), $tinta, $cod === 410 ? 410 : 301];
        }
    }

    // ───────────────────────────────────────────────────────────
    // Gazda, schema și adresele
    // ───────────────────────────────────────────────────────────

    /**
     * „https://bioscem.ro" din APP_URL, sau null când stratul de gazdă nu are
     * ce face (APP_URL gol, pe http, localhost sau IP — adică în dezvoltare).
     */
    public static function bazaCanonica(): ?string
    {
        $appUrl = trim((string) Env::get('APP_URL', ''));
        if ($appUrl === '') {
            return null;
        }
        $parti = parse_url($appUrl);
        if (!is_array($parti) || strtolower((string) ($parti['scheme'] ?? '')) !== 'https') {
            return null;
        }
        $gazda = strtolower(rtrim((string) ($parti['host'] ?? ''), '.'));
        if ($gazda === '' || $gazda === 'localhost' || !str_contains($gazda, '.')
            || filter_var($gazda, FILTER_VALIDATE_IP) !== false || str_ends_with($gazda, '.localhost')) {
            return null;
        }
        $port = isset($parti['port']) && (int) $parti['port'] !== 443 ? ':' . (int) $parti['port'] : '';
        return 'https://' . $gazda . $port;
    }

    /**
     * Gazdele care se mută pe cea canonică: varianta cu sau fără www, plus ce
     * e trecut în SEO_GAZDE_ALIAS (separate prin virgulă). Orice altă gazdă
     * (adresa tehnică de la găzduire, un IP) rămâne neatinsă.
     *
     * @return list<string>
     */
    private static function aliasuriGazda(string $gazdaCanonica): array
    {
        $alias = str_starts_with($gazdaCanonica, 'www.')
            ? [substr($gazdaCanonica, 4)]
            : ['www.' . $gazdaCanonica];
        foreach (explode(',', (string) Env::get('SEO_GAZDE_ALIAS', '')) as $extra) {
            $extra = strtolower(trim($extra));
            if ($extra !== '' && $extra !== $gazdaCanonica) {
                $alias[] = $extra;
            }
        }
        return $alias;
    }

    private static function gazdaCerere(): string
    {
        $gazda = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
        $gazda = (string) preg_replace('/:\d+$/', '', $gazda);
        return rtrim($gazda, '.');
    }

    /** La fel ca bootstrap.php (cookie-ul „secure"), plus antetele obișnuite ale proxy-urilor. */
    private static function eHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
            || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || str_contains(strtolower((string) ($_SERVER['HTTP_CF_VISITOR'] ?? '')), 'https')) {
            return true;
        }
        return false;
    }

    /** Adresa absolută (pe gazda canonică, dacă există) pentru o cale + query. */
    private static function adresaCompleta(string $cale, string $query): string
    {
        $separator = str_contains($cale, '?') ? '&' : '?';
        $adresa = $cale . ($query !== '' ? $separator . $query : '');
        return (self::$bazaCanonica ?? '') . $adresa;
    }

    /**
     * Locația dată de aplicație, mutată pe gazda canonică. Null dacă duce în
     * afara site-ului (atunci o lăsăm cum e).
     */
    private static function locatieCanonica(string $locatie): ?string
    {
        if (str_starts_with($locatie, '/') && !str_starts_with($locatie, '//')) {
            [$cale, $query] = self::imparteUri($locatie);
            return self::adresaCompleta(self::curataCale($cale), $query !== '' ? $query : self::$query);
        }
        $parti = parse_url($locatie);
        if (!is_array($parti) || !isset($parti['host'])) {
            return null;
        }
        $gazda = strtolower(rtrim((string) $parti['host'], '.'));
        $gazdaCanonica = self::$bazaCanonica !== null ? (string) parse_url(self::$bazaCanonica, PHP_URL_HOST) : self::gazdaCerere();
        if ($gazda !== $gazdaCanonica && !in_array($gazda, self::aliasuriGazda($gazdaCanonica), true)) {
            return null;
        }
        $query = (string) ($parti['query'] ?? '');
        return self::adresaCompleta(self::curataCale((string) ($parti['path'] ?? '/')), $query !== '' ? $query : self::$query);
    }

    /** @return array{0: string, 1: string} calea brută și query-ul, fără parse_url („//x" nu e o gazdă aici) */
    private static function imparteUri(string $uri): array
    {
        if (preg_match('#^https?://[^/]+(/.*)?$#i', $uri, $m) === 1) {
            $uri = $m[1] ?? '/';
        }
        $parti = explode('?', $uri, 2);
        $cale = (string) ($parti[0] === '' ? '/' : $parti[0]);
        $cale = (string) preg_replace('/#.*$/', '', $cale);
        return [$cale, (string) ($parti[1] ?? '')];
    }

    private static function antet(string $nume): string
    {
        $prefix = strtolower($nume) . ':';
        $valoare = '';
        foreach (headers_list() as $linie) {
            if (str_starts_with(strtolower($linie), $prefix)) {
                $valoare = trim(substr($linie, strlen($prefix)));
            }
        }
        return $valoare;
    }

    private static function cacheControlRedirect(): string
    {
        $valoare = trim((string) Env::get('SEO_REDIRECT_CACHE', ''));
        return $valoare !== '' ? $valoare : 'no-store';
    }
}
