<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lățimea și înălțimea pozelor din site, pentru atributele width/height ale
 * etichetelor <img>: browserul rezervă locul pozei înainte să o descarce, deci
 * pagina nu mai „sare” când apare poza (CLS).
 *
 * Dimensiunile se citesc din fișier (getimagesize) o singură dată și se țin
 * într-un cache mic, storage/cache/image-dimensions.json, recitit doar dacă
 * fișierul s-a schimbat (alt moment al modificării sau altă mărime). Pozele de
 * pe alte site-uri și SVG-urile nu primesc dimensiuni (nu le putem citi sigur).
 */
final class ImageDimensions
{
    /** @var array<string, array{0: int, 1: int, 2: int, 3: int}>|null */
    private static ?array $cache = null;
    private static bool $modificat = false;
    private static bool $salvarePrograma = false;

    /** @return array{0: int, 1: int}|null lățimea și înălțimea, în pixeli */
    public static function pentru(string $url): ?array
    {
        try {
            $relativ = self::caleRelativa($url);
            if ($relativ === null) {
                return null;
            }
            $radacinaPublica = dirname(__DIR__, 2) . '/public';
            $fisier = $radacinaPublica . $relativ;
            $real = realpath($fisier);
            $realRadacina = realpath($radacinaPublica);
            if ($real === false || $realRadacina === false || !str_starts_with($real, $realRadacina . DIRECTORY_SEPARATOR) || !is_file($real)) {
                return null;
            }
            $mtime = (int) @filemtime($real);
            $marime = (int) @filesize($real);

            self::incarca();
            $din = self::$cache[$relativ] ?? null;
            if (is_array($din) && (int) ($din[2] ?? -1) === $mtime && (int) ($din[3] ?? -1) === $marime) {
                return ((int) $din[0] > 0 && (int) $din[1] > 0) ? [(int) $din[0], (int) $din[1]] : null;
            }

            $info = @getimagesize($real);
            $latime = is_array($info) ? (int) ($info[0] ?? 0) : 0;
            $inaltime = is_array($info) ? (int) ($info[1] ?? 0) : 0;
            self::$cache[$relativ] = [$latime, $inaltime, $mtime, $marime];
            self::$modificat = true;
            self::programeazaSalvarea();

            return ($latime > 0 && $inaltime > 0) ? [$latime, $inaltime] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** ` width="800" height="800"` sau gol, gata de pus într-o etichetă <img>. */
    public static function atribute(string $url): string
    {
        $dim = self::pentru($url);
        if ($dim === null) {
            return '';
        }
        return ' width="' . $dim[0] . '" height="' . $dim[1] . '"';
    }

    /** Calea din public/ pentru o poză de pe acest site, sau null. */
    private static function caleRelativa(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return null;
        }
        if (preg_match('#^(https?:)?//([^/]+)(/.*)?$#i', $url, $m) === 1) {
            $gazda = strtolower((string) preg_replace('/:\d+$/', '', $m[2]));
            $gazdaSite = strtolower((string) parse_url((string) (Env::get('APP_URL', '') ?? ''), PHP_URL_HOST));
            $gazdaCerere = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
            $gazde = array_filter([$gazdaSite, $gazdaCerere, $gazdaSite !== '' ? 'www.' . $gazdaSite : '']);
            if (!in_array($gazda, $gazde, true)) {
                return null;
            }
            $url = $m[3] ?? '/';
        }
        $cale = rawurldecode(explode('#', explode('?', $url, 2)[0], 2)[0]);
        if (!str_starts_with($cale, '/uploads/') && !str_starts_with($cale, '/assets/')) {
            return null;
        }
        if (str_contains($cale, "\0") || str_contains($cale, '..')) {
            return null;
        }
        $extensie = strtolower(pathinfo($cale, PATHINFO_EXTENSION));
        if (!in_array($extensie, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'], true)) {
            return null;
        }
        return $cale;
    }

    private static function fisierCache(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/image-dimensions.json';
    }

    private static function incarca(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        $fisier = self::fisierCache();
        if (!is_file($fisier)) {
            return;
        }
        $date = json_decode((string) @file_get_contents($fisier), true);
        if (is_array($date)) {
            foreach ($date as $cale => $valori) {
                if (is_string($cale) && is_array($valori) && count($valori) === 4) {
                    self::$cache[$cale] = array_map('intval', array_values($valori));
                }
            }
        }
    }

    private static function programeazaSalvarea(): void
    {
        if (self::$salvarePrograma) {
            return;
        }
        self::$salvarePrograma = true;
        register_shutdown_function([self::class, 'salveaza']);
    }

    /** Scrie cache-ul (fișier temporar + rename); o eroare aici nu strică pagina. */
    public static function salveaza(): void
    {
        if (!self::$modificat || self::$cache === null) {
            return;
        }
        try {
            $fisier = self::fisierCache();
            $dosar = dirname($fisier);
            if (!is_dir($dosar) && !@mkdir($dosar, 0775, true) && !is_dir($dosar)) {
                return;
            }
            // Peste 5.000 de intrări ceva e greșit (poze generate la nesfârșit): o luăm de la capăt.
            $date = count(self::$cache) > 5000 ? [] : self::$cache;
            $temporar = $fisier . '.' . getmypid() . '.tmp';
            if (@file_put_contents($temporar, (string) json_encode($date, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
                @rename($temporar, $fisier);
            }
            @unlink($temporar);
            self::$modificat = false;
        } catch (\Throwable) {
        }
    }
}
