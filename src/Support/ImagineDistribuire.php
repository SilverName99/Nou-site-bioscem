<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Poza care apare când cineva trimite un link pe Facebook, WhatsApp, Messenger
 * sau X (og:image / twitter:image).
 *
 * Pozele produselor sunt de obicei pătrate și mari (unele PNG de 1–2 MB), iar
 * WhatsApp nu arată deloc o poză de peste circa 300 KB, iar Facebook o taie la
 * 1,91:1. De aceea, pentru fiecare poză se face o variantă 1200×630, JPEG sub
 * 300 KB: poza întreagă, micșorată, pe fond alb (nu tăiată — un ambalaj tăiat
 * la jumătate nu mai spune nimic). Dacă poza originală are deja forma și
 * mărimea potrivită, se folosește direct.
 *
 * Varianta se face o singură dată, la prima cerere a adresei /og/{versiune}/uploads/…
 * (de obicei robotul Facebook sau WhatsApp, nu un client), și se ține în
 * storage/cache/og/. Versiunea din adresă se schimbă când se schimbă poza, ca
 * rețelele să nu arate una veche. Răspunsul nu pornește sesiunea (rulează din
 * public/index.php, înaintea ei) și nu trece prin redirecționări.
 *
 * Fără GD pe server, sau dacă poza nu se poate citi, se folosește poza
 * originală. Fișierele din galerie nu se ating niciodată.
 */
final class ImagineDistribuire
{
    public const LATIME = 1200;
    public const INALTIME = 630;

    /** Se schimbă când se schimbă felul în care se face varianta (refacere pentru toate). */
    private const VERSIUNE_ALGORITM = '1';

    private const PREFIX = '/og/';

    /** Doar pozele încărcate în site (produse și galerie). */
    private const DOSARE = ['/uploads/products/', '/uploads/gallery/'];

    private const EXTENSII = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private const MAX_OCTETI_SURSA = 20 * 1024 * 1024;
    private const MAX_PIXELI_SURSA = 25_000_000;
    /** WhatsApp nu arată poze mai mari (în jur de 300 KB). */
    private const MAX_OCTETI = 300 * 1024;

    /** Logo-ul folosit când pagina n-are poză și nu e setată una implicită. */
    private const LOGO_IMPLICIT = '/uploads/gallery/bioscem-logo-2.png';

    // ───────────────────────────────────────────────────────────
    // În pagină (layout): adresa pozei
    // ───────────────────────────────────────────────────────────

    /**
     * Poza de distribuire pentru o poză (adresă relativă sau absolută):
     * ['url' => absolută, 'latime' => int|null, 'inaltime' => int|null, 'tip' => string].
     * Null dacă e o poză a site-ului care lipsește sau nu se poate folosi (SVG).
     *
     * @return array{url: string, latime: ?int, inaltime: ?int, tip: string}|null
     */
    public static function pentru(string $imagine, string $baseUrl, ?string $radacina = null): ?array
    {
        try {
            return self::pentruIntern(trim(html_entity_decode($imagine, ENT_QUOTES | ENT_HTML5, 'UTF-8')), rtrim($baseUrl, '/'), $radacina ?? dirname(__DIR__, 2));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Poza pentru paginile fără poză proprie: cea din Admin → Setări magazin →
     * SEO Google, altfel logo-ul magazinului. „sursa” spune care dintre ele
     * („setari” sau „logo”), ca textul alternativ să descrie poza, nu pagina.
     *
     * @return array{url: string, latime: ?int, inaltime: ?int, tip: string, sursa: string}|null
     */
    public static function implicita(array $setari, string $baseUrl, ?string $radacina = null): ?array
    {
        $setata = trim((string) ($setari['store_seo_default_image_url'] ?? ''));
        if ($setata !== '') {
            $poza = self::pentru($setata, $baseUrl, $radacina);
            if ($poza !== null) {
                return $poza + ['sursa' => 'setari'];
            }
        }
        $logo = self::pentru(self::LOGO_IMPLICIT, $baseUrl, $radacina);
        return $logo !== null ? $logo + ['sursa' => 'logo'] : null;
    }

    /**
     * Textul alternativ al pozei implicite: „Logo {magazin}” pentru logo, numele
     * magazinului pentru poza aleasă în setări (o poză a magazinului, nu a paginii).
     */
    public static function altImplicit(array $poza, string $numeMagazin): string
    {
        return ($poza['sursa'] ?? '') === 'logo' ? 'Logo ' . $numeMagazin : $numeMagazin;
    }

    private static function pentruIntern(string $imagine, string $baza, string $radacina): ?array
    {
        if ($imagine === '' || $baza === '') {
            return null;
        }
        $relativ = self::caleLocala($imagine, $baza);
        if ($relativ === null) {
            // Poză de pe alt site: o lăsăm cum e (nu-i știm mărimea).
            if (preg_match('#^https?://#i', $imagine) === 1) {
                return ['url' => $imagine, 'latime' => null, 'inaltime' => null, 'tip' => ''];
            }
            if (str_starts_with($imagine, '//')) {
                return ['url' => 'https:' . $imagine, 'latime' => null, 'inaltime' => null, 'tip' => ''];
            }
            return null;
        }
        $fisier = self::fisierSursa($radacina, $relativ);
        if ($fisier === null) {
            return null;
        }
        $marime = (int) @filesize($fisier);
        $info = @getimagesize($fisier);
        $latime = is_array($info) ? (int) $info[0] : 0;
        $inaltime = is_array($info) ? (int) $info[1] : 0;
        $tipImagine = is_array($info) ? (int) $info[2] : 0;
        if ($latime <= 0 || $inaltime <= 0) {
            return null;
        }
        $original = [
            'url' => $baza . self::codeazaCale($relativ),
            'latime' => $latime,
            'inaltime' => $inaltime,
            'tip' => (string) image_type_to_mime_type($tipImagine),
        ];
        // Originalul are deja forma (1,91:1) și mărimea potrivită: îl folosim direct.
        if (in_array($tipImagine, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            && $marime > 0 && $marime <= self::MAX_OCTETI
            && $latime >= self::LATIME && abs($latime / $inaltime - self::LATIME / self::INALTIME) <= 0.05) {
            return $original;
        }
        if (!self::gdDisponibil($tipImagine)) {
            return $original;
        }
        return [
            'url' => $baza . self::PREFIX . self::versiune($relativ, $fisier) . self::codeazaCale($relativ),
            'latime' => self::LATIME,
            'inaltime' => self::INALTIME,
            'tip' => 'image/jpeg',
        ];
    }

    // ───────────────────────────────────────────────────────────
    // Cererea /og/{versiune}/uploads/… (din public/index.php, înaintea sesiunii)
    // ───────────────────────────────────────────────────────────

    /**
     * Dacă cererea e pentru /og/…, o servește și oprește scriptul. Altfel nu
     * face nimic. Doar GET și HEAD; orice altceva trece mai departe, ca înainte.
     */
    public static function raspunde(string $radacina): void
    {
        $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        if (!str_starts_with($cale, self::PREFIX)) {
            return;
        }
        $metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($metoda !== 'GET' && $metoda !== 'HEAD') {
            return;
        }
        try {
            self::raspundeIntern($radacina, $cale, $metoda === 'HEAD');
        } catch (\Throwable) {
            self::negasit();
        }
        exit;
    }

    private static function raspundeIntern(string $radacina, string $cale, bool $doarAntete): void
    {
        if (preg_match('#^/og/([0-9a-f]{12})(/uploads/[^?\#]+)$#', $cale, $m) !== 1) {
            self::negasit();
            return;
        }
        $relativ = rawurldecode($m[2]);
        if (!self::caleAcceptata($relativ)) {
            self::negasit();
            return;
        }
        $sursa = self::fisierSursa($radacina, $relativ);
        if ($sursa === null) {
            self::negasit();
            return;
        }

        $dosar = $radacina . '/storage/cache/og';
        $cache = $dosar . '/' . sha1(self::VERSIUNE_ALGORITM . '|' . $relativ . '|' . (int) @filemtime($sursa) . '|' . (int) @filesize($sursa)) . '.jpg';
        $date = is_file($cache) ? @file_get_contents($cache) : false;
        if (!is_string($date) || $date === '') {
            $date = self::genereaza($sursa);
            if ($date === null) {
                // Nu s-a putut face varianta: rețelele primesc poza originală.
                header('Location: ' . self::codeazaCale($relativ), true, 302);
                header('Cache-Control: no-store');
                return;
            }
            if (!is_dir($dosar)) {
                @mkdir($dosar, 0775, true);
            }
            $temporar = $cache . '.' . getmypid() . '.tmp';
            if (@file_put_contents($temporar, $date) === strlen($date)) {
                @rename($temporar, $cache);
            } else {
                @unlink($temporar);
            }
        }

        http_response_code(200);
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . strlen($date));
        // Adresa are versiunea în ea, deci poate sta mult în cache.
        header('Cache-Control: public, max-age=2592000');
        // Poza e doar pentru distribuire; în Google Imagini rămân pozele originale.
        header('X-Robots-Tag: noindex');
        if (!$doarAntete) {
            echo $date;
        }
    }

    /** JPEG 1200×630: poza întreagă, micșorată și centrată pe fond alb; null dacă nu se poate. */
    private static function genereaza(string $sursa): ?string
    {
        $info = @getimagesize($sursa);
        if (!is_array($info)) {
            return null;
        }
        [$latime, $inaltime, $tip] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        if ($latime <= 0 || $inaltime <= 0 || $latime * $inaltime > self::MAX_PIXELI_SURSA
            || (int) @filesize($sursa) > self::MAX_OCTETI_SURSA || !self::gdDisponibil($tip)) {
            return null;
        }
        self::memorieMinima(256);
        $imagine = match ($tip) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sursa),
            IMAGETYPE_PNG => @imagecreatefrompng($sursa),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sursa),
            IMAGETYPE_GIF => @imagecreatefromgif($sursa),
            default => false,
        };
        if ($imagine === false) {
            return null;
        }
        // Pozele de pe telefon pot fi „culcate”, cu rotirea scrisă doar în EXIF
        // (browserul o respectă, GD nu).
        if ($tip === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sursa);
            $unghi = match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)) {
                3 => 180, 6 => -90, 8 => 90, default => 0,
            };
            if ($unghi !== 0) {
                $rotita = @imagerotate($imagine, $unghi, 0);
                if ($rotita !== false) {
                    $imagine = $rotita;
                    [$latime, $inaltime] = [imagesx($imagine), imagesy($imagine)];
                }
            }
        }

        $panza = imagecreatetruecolor(self::LATIME, self::INALTIME);
        if ($panza === false) {
            return null;
        }
        imagefill($panza, 0, 0, (int) imagecolorallocate($panza, 255, 255, 255));
        imagealphablending($panza, true);
        // Margini de 40 px stânga-dreapta și 30 px sus-jos; o poză mică se mărește cel mult de două ori.
        $scara = min((self::LATIME - 80) / $latime, (self::INALTIME - 60) / $inaltime, 2.0);
        $l = max(1, (int) round($latime * $scara));
        $i = max(1, (int) round($inaltime * $scara));
        imagecopyresampled($panza, $imagine, intdiv(self::LATIME - $l, 2), intdiv(self::INALTIME - $i, 2), 0, 0, $l, $i, $latime, $inaltime);
        imageinterlace($panza, true);

        $date = null;
        foreach ([85, 78, 70, 60] as $calitate) {
            ob_start();
            $scris = imagejpeg($panza, null, $calitate);
            $bucata = (string) ob_get_clean();
            if ($scris && $bucata !== '') {
                $date = $bucata;
                if (strlen($bucata) <= self::MAX_OCTETI) {
                    break;
                }
            }
        }
        return $date;
    }

    // ───────────────────────────────────────────────────────────
    // Ajutoare
    // ───────────────────────────────────────────────────────────

    /** Calea locală (/uploads/…) pentru o poză a site-ului, sau null dacă e de pe alt site. */
    private static function caleLocala(string $imagine, string $baza): ?string
    {
        if (preg_match('#^(?:https?:)?//([^/]+)(/[^?\#]*)?#i', $imagine, $m) === 1) {
            $gazda = strtolower((string) preg_replace('/^www\./', '', $m[1]));
            $gazdaSite = strtolower((string) preg_replace('/^www\./', '', (string) parse_url($baza, PHP_URL_HOST)));
            $port = parse_url($baza, PHP_URL_PORT);
            if ($port !== null && $port !== false) {
                $gazdaSite .= ':' . $port;
            }
            if ($gazda !== $gazdaSite) {
                return null;
            }
            $imagine = (string) ($m[2] ?? '');
        }
        $cale = rawurldecode((string) (parse_url($imagine, PHP_URL_PATH) ?? ''));
        if ($cale === '' || $cale[0] !== '/') {
            $cale = '/' . ltrim($cale, '/');
        }
        return $cale;
    }

    /** O cale din galerie sau din pozele produselor, cu extensie de poză, fără „..”. */
    private static function caleAcceptata(string $relativ): bool
    {
        if (str_contains($relativ, "\0") || str_contains($relativ, '..') || str_contains($relativ, '\\')) {
            return false;
        }
        $inDosar = false;
        foreach (self::DOSARE as $dosar) {
            if (str_starts_with($relativ, $dosar)) {
                $inDosar = true;
                break;
            }
        }
        return $inDosar && in_array(strtolower(pathinfo($relativ, PATHINFO_EXTENSION)), self::EXTENSII, true);
    }

    /** Fișierul de pe disc pentru o cale acceptată, doar din public/uploads; altfel null. */
    private static function fisierSursa(string $radacina, string $relativ): ?string
    {
        if (!self::caleAcceptata($relativ)) {
            return null;
        }
        $uploads = realpath($radacina . '/public/uploads');
        $fisier = realpath($radacina . '/public' . $relativ);
        if ($uploads === false || $fisier === false || !str_starts_with($fisier, $uploads . DIRECTORY_SEPARATOR) || !is_file($fisier)) {
            return null;
        }
        return $fisier;
    }

    /** 12 caractere care se schimbă odată cu poza (alt fișier, altă mărime sau altă dată). */
    private static function versiune(string $relativ, string $fisier): string
    {
        return substr(sha1(self::VERSIUNE_ALGORITM . '|' . $relativ . '|' . (int) @filemtime($fisier) . '|' . (int) @filesize($fisier)), 0, 12);
    }

    /** Fiecare bucată a căii codată pentru URL („/uploads/gallery/poza mea.png” → „…/poza%20mea.png”). */
    private static function codeazaCale(string $relativ): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $relativ)));
    }

    private static function gdDisponibil(int $tip): bool
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg') || !function_exists('imagecopyresampled')) {
            return false;
        }
        return match ($tip) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg'),
            IMAGETYPE_PNG => function_exists('imagecreatefrompng'),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp'),
            IMAGETYPE_GIF => function_exists('imagecreatefromgif'),
            default => false,
        };
    }

    /** Ridică memory_limit la cel puțin $mb MB, dacă e mai mic (o poză mare decodată ocupă mult). */
    private static function memorieMinima(int $mb): void
    {
        $actual = trim((string) ini_get('memory_limit'));
        if ($actual === '-1') {
            return;
        }
        $valoare = (int) $actual;
        $unitate = strtolower(substr($actual, -1));
        $octeti = match ($unitate) {
            'g' => $valoare * 1024 * 1024 * 1024,
            'm' => $valoare * 1024 * 1024,
            'k' => $valoare * 1024,
            default => $valoare,
        };
        if ($octeti < $mb * 1024 * 1024) {
            @ini_set('memory_limit', $mb . 'M');
        }
    }

    private static function negasit(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Poza nu există.';
    }
}
