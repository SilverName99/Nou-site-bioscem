<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pozele din pagini, partea din livrarea 4 SEO (A8 din plan):
 *
 *  - width/height pe pozele afișate de șabloane (pe lângă cele din livrările 1
 *    și 2), ca browserul să le rezerve locul înainte să le descarce; doar unde
 *    stilul șablonului dă deja pozei lățimea și înălțimea (sau înălțimea
 *    proporțională), deci pagina arată la fel. Dimensiunile vin din
 *    ImageDimensions (getimagesize, cu cache în storage/cache);
 *  - cele 10 poze mari din plan (PNG de 0,6–2,4 MB) se afișează din fișiere noi,
 *    WebP sub 150 KB (config/poze-optimizate.php). Pozele vechi rămân pe server
 *    și în baza de date: datele structurate, fluxul Merchant Center și poza de
 *    distribuire le folosesc în continuare.
 *
 * Coșul, comanda, plata și contul nu se schimbă: șabloanele lor nu folosesc
 * clasa, iar în paginile din constructor se înlocuiește doar textul paginii,
 * înainte să intre formularele de coș sau de comandă.
 *
 * Oprire de urgență: SEO_POZE=0 în .env (fără dimensiunile noi și fără pozele
 * noi; dimensiunile din livrările 1 și 2 rămân).
 */
final class PozeOptimizate
{
    /** @var array<string, array{0: string, 1: int}>|null poza veche => [poza nouă, octeții pozei vechi] */
    private static ?array $harta = null;
    /** @var array<string, string> poza veche => poza afișată (memorat pe cerere) */
    private static array $alese = [];

    /**
     * Pornit (SEO_POZE nu e 0) și pagina nu e de coș, comandă, plată, cont sau
     * admin (SeoRedirects::CAI_EXCLUSE): acolo antetul și pozele rămân la octet
     * ca înainte.
     */
    public static function activ(): bool
    {
        $valoare = strtolower(trim((string) Env::get('SEO_POZE', '1')));
        if (in_array($valoare, ['0', 'false', 'off', 'nu'], true)) {
            return false;
        }
        $cale = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
        return !SeoRedirects::eExclusa($cale === '' ? '/' : $cale);
    }

    /**
     * Adresa de afișat pentru o poză: varianta nouă, dacă poza e una dintre cele
     * refăcute și fișierul vechi e tot cel refăcut (aceeași mărime); altfel
     * adresa primită, neschimbată.
     */
    public static function src(string $url): string
    {
        if ($url === '' || !self::activ()) {
            return $url;
        }
        try {
            $cale = self::caleLocala($url);
            if ($cale === null) {
                return $url;
            }
            if (!array_key_exists($cale, self::$alese)) {
                self::$alese[$cale] = self::alege($cale);
            }
            return self::$alese[$cale] !== '' ? self::$alese[$cale] : $url;
        } catch (\Throwable) {
            return $url;
        }
    }

    /**
     * ` width="…" height="…"` pentru o etichetă <img> nouă din livrarea 4 (gol
     * cu SEO_POZE=0, pentru SVG, pentru pozele altor site-uri sau dacă fișierul
     * nu se poate citi).
     */
    public static function dimensiuni(string $url): string
    {
        if ($url === '' || !self::activ()) {
            return '';
        }
        return ImageDimensions::atribute($url);
    }

    /**
     * Textul unei pagini din constructor (HTML din baza de date), cu pozele
     * refăcute luate din fișierele noi: se schimbă doar src-ul etichetelor <img>
     * care arată exact una dintre pozele din listă. Restul rămâne la octet.
     */
    public static function inHtml(string $html): string
    {
        if ($html === '' || !self::activ() || stripos($html, '<img') === false) {
            return $html;
        }
        try {
            $harta = self::harta();
            $areVreuna = false;
            foreach (array_keys($harta) as $veche) {
                if (str_contains($html, $veche)) {
                    $areVreuna = true;
                    break;
                }
            }
            if (!$areVreuna) {
                return $html;
            }
            $rezultat = preg_replace_callback(
                '/<img\b[^>]*>/i',
                static function (array $m): string {
                    return (string) preg_replace_callback(
                        '/(\ssrc\s*=\s*)(["\'])([^"\']*)\2/i',
                        static function (array $a): string {
                            $brut = html_entity_decode($a[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $nou = self::src($brut);
                            if ($nou === $brut) {
                                return $a[0];
                            }
                            return $a[1] . $a[2] . htmlspecialchars($nou, ENT_QUOTES, 'UTF-8') . $a[2];
                        },
                        $m[0],
                        1
                    );
                },
                $html
            );
            return is_string($rezultat) ? $rezultat : $html;
        } catch (\Throwable) {
            return $html;
        }
    }

    /** Poza nouă pentru o cale din listă, sau '' dacă nu se folosește. */
    private static function alege(string $cale): string
    {
        $harta = self::harta();
        if (!isset($harta[$cale])) {
            return '';
        }
        [$noua, $octeti] = $harta[$cale];
        $public = dirname(__DIR__, 2) . '/public';
        $veche = $public . $cale;
        if (!is_file($public . $noua) || !is_file($veche) || (int) @filesize($veche) !== (int) $octeti) {
            return '';
        }
        return $noua;
    }

    /** @return array<string, array{0: string, 1: int}> */
    private static function harta(): array
    {
        if (self::$harta !== null) {
            return self::$harta;
        }
        self::$harta = [];
        $fisier = dirname(__DIR__, 2) . '/config/poze-optimizate.php';
        $date = is_file($fisier) ? require $fisier : [];
        foreach ((array) $date as $veche => $rand) {
            if (is_string($veche) && is_array($rand) && isset($rand[0], $rand[1])) {
                self::$harta[$veche] = [(string) $rand[0], (int) $rand[1]];
            }
        }
        return self::$harta;
    }

    /**
     * Calea unei poze de pe acest site (relativă sau cu adresa site-ului, cu sau
     * fără www), fără parametri; null pentru orice altceva.
     */
    private static function caleLocala(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('#^(https?:)?//([^/?\#]+)(/[^?\#]*)?#i', $url, $m) === 1) {
            $gazda = strtolower((string) preg_replace('/:\d+$/', '', $m[2]));
            $gazdaSite = strtolower((string) parse_url((string) (Env::get('APP_URL', '') ?? ''), PHP_URL_HOST));
            $gazdaCerere = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
            $gazde = array_filter([$gazdaSite, $gazdaCerere, $gazdaSite !== '' ? 'www.' . $gazdaSite : '']);
            if (!in_array($gazda, $gazde, true)) {
                return null;
            }
            return $m[3] ?? null;
        }
        if (!str_starts_with($url, '/')) {
            return null;
        }
        return explode('#', explode('?', $url, 2)[0], 2)[0];
    }
}
