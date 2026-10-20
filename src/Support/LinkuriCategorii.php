<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Adresele categoriilor: /categorie/{slug}, nu /magazin?category={nume}.
 *
 * Google tratează /magazin?category=… ca pe o variantă a lui /magazin și nu o
 * indexează separat; paginile /categorie/… sunt cele din Google și din sitemap.
 * Aici stau, într-un singur loc:
 *  - slug-ul la care /categorie/{slug} afișează o categorie dată (aceeași regulă
 *    ca în firimituri și în sitemap: slug-ul salvat, altfel cel generat din nume,
 *    doar dacă duce chiar la acea categorie);
 *  - rescrierea linkurilor vechi din HTML-ul salvat în baza de date (antet,
 *    meniu, subsol, paginile din constructor, șablonul de produs), la afișare,
 *    fără să se schimbe ceva în baza de date;
 *  - ținta redirecționării 301 pentru /magazin?category=… (în SiteController::shop()).
 *
 * Un link sau o cerere se mută pe /categorie/… doar când categoria e TOT ce
 * cere adresa: ceilalți parametri pot fi doar sortarea și parametrii de
 * reclamă/urmărire (gclid, utm_* etc.), care se păstrează. Orice alt parametru
 * (de exemplu căutarea q=) lasă adresa neatinsă.
 */
final class LinkuriCategorii
{
    /** Parametrii pe care categoria îi primește ca atare (/categorie/{slug}?sort=…). */
    private const PARAMETRI_PASTRATI = ['sort'];

    /** Parametrii de reclamă și urmărire: se păstrează la mutare, nu schimbă pagina. */
    private const PARAMETRI_URMARIRE = '/^(utm_[a-z0-9_]+|gclid|gbraid|wbraid|gad_source|gad_campaignid|fbclid|msclkid|ttclid|twclid|li_fat_id|srsltid|_ga|_gl|mc_cid|mc_eid|yclid|dclid|igshid)$/i';

    /** @var array{dupaSlug: array<string, string>, slugSalvat: array<string, string>, nume: list<string>}|null */
    private static ?array $date = null;

    /** @var array<string, string> cache: cheia numelui → slug-ul paginii lui ('' = fără pagină) */
    private static array $slugPentru = [];

    /** Pentru teste: uită datele citite (altă bază, alte categorii). */
    public static function reseteaza(): void
    {
        self::$date = null;
        self::$slugPentru = [];
    }

    /**
     * Slug-ul la care /categorie/{slug} afișează categoria cu numele dat, sau
     * gol dacă nu există o astfel de pagină.
     */
    public static function slugPentruNume(?PDO $db, string $nume): string
    {
        $nume = trim($nume);
        if ($nume === '' || !$db instanceof PDO) {
            return '';
        }
        $cheie = self::cheieExacta($nume);
        if (isset(self::$slugPentru[$cheie])) {
            return self::$slugPentru[$cheie];
        }
        $date = self::date($db);
        $slug = '';
        // Slug-ul salvat (căutat ca în MySQL, fără diacritice), doar dacă duce
        // înapoi chiar la această categorie; altfel cel generat din nume.
        $salvat = $date['slugSalvat'][self::cheie($nume)] ?? '';
        if ($salvat !== '' && self::cheieExacta(self::numePentruSlug($db, $salvat)) === $cheie) {
            $slug = $salvat;
        } else {
            $generat = Slug::categorie($nume);
            if ($generat !== '' && self::cheieExacta(self::numePentruSlug($db, $generat)) === $cheie) {
                $slug = $generat;
            }
        }
        return self::$slugPentru[$cheie] = $slug;
    }

    /**
     * Numele categoriei pe care o afișează /categorie/{slug} (gol = niciuna):
     * întâi slug-ul salvat în product_categories, apoi slug-ul generat din numele
     * categoriilor din magazin.
     */
    public static function numePentruSlug(?PDO $db, string $slug): string
    {
        $slug = mb_strtolower(trim($slug));
        if ($slug === '' || !$db instanceof PDO) {
            return '';
        }
        $date = self::date($db);
        if (isset($date['dupaSlug'][$slug])) {
            return $date['dupaSlug'][$slug];
        }
        foreach ($date['nume'] as $nume) {
            if (Slug::categorie($nume) === $slug) {
                return $nume;
            }
        }
        return '';
    }

    /** Calea categoriei („/categorie/plasma-quinton”) sau gol dacă nu are pagină. */
    public static function cale(?PDO $db, string $nume): string
    {
        $slug = self::slugPentruNume($db, $nume);
        return $slug !== '' ? '/categorie/' . rawurlencode($slug) : '';
    }

    /**
     * Linkul unei categorii pentru meniuri și liste: /categorie/{slug}; dacă
     * categoria n-are pagină proprie, linkul de până acum spre magazin filtrat.
     */
    public static function url(?PDO $db, string $nume): string
    {
        $nume = trim($nume);
        if ($nume === '') {
            return '/magazin';
        }
        $cale = self::cale($db, $nume);
        return $cale !== '' ? $cale : '/magazin?category=' . rawurlencode($nume);
    }

    /**
     * Unde trebuie să ducă /magazin?{query}: ['cale' => '/categorie/…',
     * 'query' => restul parametrilor], sau null dacă adresa rămâne cum e
     * (fără categorie, categorie necunoscută, alți parametri decât sortarea și
     * cei de urmărire, sau două categorii diferite).
     *
     * @return array{cale: string, query: string}|null
     */
    public static function tintaPentruMagazin(?PDO $db, string $query): ?array
    {
        if (!$db instanceof PDO || trim($query) === '') {
            return null;
        }
        $valori = [];
        $rest = [];
        foreach (explode('&', $query) as $bucata) {
            if ($bucata === '') {
                continue;
            }
            $parti = explode('=', $bucata, 2);
            $nume = urldecode($parti[0]);
            if ($nume === 'category' || $nume === 'categorie') {
                $valori[] = trim(urldecode($parti[1] ?? ''));
                continue;
            }
            if (!in_array($nume, self::PARAMETRI_PASTRATI, true) && preg_match(self::PARAMETRI_URMARIRE, $nume) !== 1) {
                return null;
            }
            $rest[] = $bucata;
        }
        if ($valori === []) {
            return null;
        }
        $valoare = $valori[0];
        foreach ($valori as $alta) {
            if (self::cheie($alta) !== self::cheie($valoare)) {
                return null;
            }
        }
        $cale = self::caleDinValoare($db, $valoare);
        if ($cale === '') {
            return null;
        }
        return ['cale' => $cale, 'query' => implode('&', $rest)];
    }

    /**
     * Calea categoriei după valoarea din ?category=: numele categoriei (oricum
     * scris, ca filtrul magazinului) sau, pentru linkurile vechi, slug-ul ei.
     */
    public static function caleDinValoare(?PDO $db, string $valoare): string
    {
        $valoare = trim($valoare);
        if ($valoare === '' || !$db instanceof PDO) {
            return '';
        }
        $cheie = self::cheieExacta($valoare);
        foreach (self::date($db)['nume'] as $nume) {
            if (self::cheieExacta($nume) === $cheie) {
                return self::cale($db, $nume);
            }
        }
        $dupaSlug = self::numePentruSlug($db, $valoare);
        return $dupaSlug !== '' ? self::cale($db, $dupaSlug) : '';
    }

    /**
     * Rescrie în HTML linkurile spre /magazin?category=… (relative sau pe
     * domeniul site-ului) în /categorie/{slug}, când categoria e toată cererea.
     * Restul HTML-ului rămâne neatins, octet cu octet.
     */
    public static function rescrie(string $html, ?PDO $db): string
    {
        if ($html === '' || !$db instanceof PDO || stripos($html, 'magazin') === false
            || (stripos($html, 'category=') === false && stripos($html, 'categorie=') === false)) {
            return $html;
        }
        $gazde = self::gazdeSite();
        try {
            return (string) preg_replace_callback(
                '/(\bhref\s*=\s*)(["\'])([^"\'<>]*?\/magazin\/?\?[^"\'<>]*)\2/i',
                static function (array $m) use ($db, $gazde): string {
                    $original = $m[3];
                    $adresa = html_entity_decode($original, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (preg_match('#^((?:https?:)?//([^/]+))?/magazin/?\?([^\#]*)(\#.*)?$#i', $adresa, $p) !== 1) {
                        return $m[0];
                    }
                    $prefix = (string) ($p[1] ?? '');
                    if ($prefix !== '' && !in_array(strtolower((string) $p[2]), $gazde, true)) {
                        return $m[0];
                    }
                    $tinta = self::tintaPentruMagazin($db, (string) $p[3]);
                    if ($tinta === null) {
                        return $m[0];
                    }
                    $nou = $prefix . $tinta['cale'] . ($tinta['query'] !== '' ? '?' . $tinta['query'] : '') . (string) ($p[4] ?? '');
                    return $m[1] . $m[2] . htmlspecialchars($nou, ENT_QUOTES, 'UTF-8', false) . $m[2];
                },
                $html
            );
        } catch (Throwable) {
            return $html;
        }
    }

    /**
     * Pagina cerută acum e una de coș, comandă, plată, cont, login sau admin
     * (SeoRedirects::CAI_EXCLUSE)? Acolo HTML-ul rămâne exact ca înainte:
     * paginile sunt închise pentru Google, iar un link vechi din meniu duce
     * oricum, printr-un 301, la pagina categoriei.
     */
    public static function paginaCurentaExclusa(): bool
    {
        $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        return SeoRedirects::eExclusa($cale === '' ? '/' : $cale);
    }

    /** Ca rescrie(), dar nu pe paginile de coș, comandă, plată și cont. */
    public static function rescrieInPagina(string $html, ?PDO $db): string
    {
        return self::paginaCurentaExclusa() ? $html : self::rescrie($html, $db);
    }

    /** Domeniul din APP_URL, cu și fără www (linkurile absolute spre alt site rămân). */
    private static function gazdeSite(): array
    {
        $gazda = strtolower((string) parse_url((string) Env::get('APP_URL', ''), PHP_URL_HOST));
        $gazda = (string) preg_replace('/^www\./', '', $gazda);
        $port = parse_url((string) Env::get('APP_URL', ''), PHP_URL_PORT);
        $lista = [];
        if ($gazda !== '') {
            $lista[] = $gazda;
            $lista[] = 'www.' . $gazda;
            if ($port !== null && $port !== false) {
                $lista[] = $gazda . ':' . $port;
            }
        }
        return $lista;
    }

    /**
     * Categoriile, citite o singură dată pe cerere.
     *
     * @return array{dupaSlug: array<string, string>, slugSalvat: array<string, string>, nume: list<string>}
     */
    private static function date(PDO $db): array
    {
        if (self::$date !== null) {
            return self::$date;
        }
        $dupaSlug = [];
        $slugSalvat = [];
        $nume = [];
        try {
            // Ca SiteController::loadShopCatalogCategories(): numele din produse,
            // apoi cele din product_categories; aceeași ordine alfabetică.
            $stmt = $db->query('SELECT DISTINCT TRIM(category) AS nume FROM products WHERE deleted_at IS NULL AND TRIM(COALESCE(category, "")) <> ""');
            foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : []) ?: [] as $n) {
                $n = trim((string) $n);
                if ($n !== '') {
                    $nume[mb_strtolower($n)] ??= $n;
                }
            }
        } catch (Throwable) {
        }
        try {
            $stmt = $db->query('SELECT name, slug FROM product_categories ORDER BY id ASC');
            foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $rand) {
                $n = trim((string) ($rand['name'] ?? ''));
                $s = trim((string) ($rand['slug'] ?? ''));
                if ($n === '') {
                    continue;
                }
                $nume[mb_strtolower($n)] ??= $n;
                if ($s !== '') {
                    $dupaSlug[mb_strtolower($s)] ??= $n;
                    $slugSalvat[self::cheie($n)] ??= $s;
                }
            }
        } catch (Throwable) {
        }
        $lista = array_values($nume);
        usort($lista, static fn (string $a, string $b): int => strcasecmp($a, $b));
        return self::$date = ['dupaSlug' => $dupaSlug, 'slugSalvat' => $slugSalvat, 'nume' => $lista];
    }

    /** Cheia unui nume, ca la compararea din MySQL: fără litere mari și fără diacritice. */
    private static function cheie(string $nume): string
    {
        return strtr(mb_strtolower(trim($nume)), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
    }

    /** Cheia folosită de filtrul magazinului: doar fără litere mari (normalizeShopCategoryFilter). */
    private static function cheieExacta(string $nume): string
    {
        return mb_strtolower(trim($nume));
    }
}
