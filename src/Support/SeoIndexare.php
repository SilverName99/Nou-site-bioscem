<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ce îi spune pagina lui Google despre ea însăși, în afară de titlu și
 * descriere (livrarea 4 SEO, punctele A10 și A11 din plan):
 *
 *  - noindex,follow („nu pune pagina în Google, dar urmează linkurile”) pe:
 *      - căutarea internă (/magazin?q=…): fiecare căutare ar fi altă pagină,
 *        fără conținut propriu; fără canonical acolo, ca Google să nu ia
 *        noindex-ul drept semnal pentru /magazin sau pentru categorie;
 *      - /blog cât timp n-are niciun articol publicat (decizia D7); primul
 *        articol publicat îl readuce singur în Google (și în sitemap);
 *  - pagina „nu există” (404/410) nu are canonical: o adresă care nu există nu
 *    e varianta altei pagini (în afară de căile de coș, comandă, plată și cont,
 *    de exemplu un link de plată greșit, care rămân exact ca înainte);
 *  - un articol de blog sau o categorie care nu există (/blog/x, /categorie/x)
 *    primesc pagina 404 obișnuită a magazinului (not-found.php), nu un text
 *    simplu de 27 de octeți, respectiv tot magazinul (în afară de căile excluse
 *    scrise cu „//”, de exemplu „//api/blog/posts”, care rămân ca înainte);
 *  - H1: o pagină de conținut din constructor (termeni, cookies, date
 *    personale, acorduri GDPR, program de sărbători) fără niciun <h1> în text
 *    primește titlul ei ca H1, din șablon; textul din baza de date rămâne
 *    neatins. Pe /blog, titlul listei de articole („Blog & Resurse”, deja vizibil)
 *    devine H1 în loc de H2, cu același aspect, ca să nu apară două titluri unul
 *    sub altul. Paginile de coș, comandă, plată, cont și login rămân exact ca
 *    înainte.
 *
 * Etichetele mici și cele cu nume de boli rămân indexate (deciziile D2 și D3).
 *
 * Opriri de urgență, în .env:
 *  - SEO_NOINDEX=0: fără noindex pe căutare și pe /blog, canonical la loc pe 404,
 *    /blog/x și /categorie/x inexistente răspund ca înainte;
 *  - SEO_H1=0: fără H1 adăugat de șablon, titlul de pe /blog rămâne H2.
 */
final class SeoIndexare
{
    public const NOINDEX = 'noindex,follow';

    /** Paginile din constructor care nu primesc H1 din șablon (prima pagină, pagina 404, magazinul). */
    private const FARA_H1 = ['', 'acasa', 'home', '404', 'magazin'];

    /** noindex pe căutare și /blog gol, fără canonical pe 404? (oprire: SEO_NOINDEX=0) */
    public static function activ(): bool
    {
        return self::pornit('SEO_NOINDEX');
    }

    /** H1 din titlul paginii, când textul n-are? (oprire: SEO_H1=0) */
    public static function h1Activ(): bool
    {
        return self::pornit('SEO_H1');
    }

    /** E o căutare internă: parametrul q are text. */
    public static function esteCautare(array $query): bool
    {
        $q = $query['q'] ?? '';
        return is_string($q) && trim($q) !== '';
    }

    /**
     * Ce primește /magazin: [meta robots, fără canonical]. O căutare (q cu text)
     * → noindex,follow și fără canonical; altfel nimic nou.
     *
     * @return array{metaRobots?: string, faraCanonical?: bool}
     */
    public static function pentruMagazin(array $query): array
    {
        if (!self::activ() || !self::esteCautare($query)) {
            return [];
        }
        return ['metaRobots' => self::NOINDEX, 'faraCanonical' => true];
    }

    /**
     * Ce primește /blog: noindex,follow cât n-are niciun articol publicat.
     *
     * @return array{metaRobots?: string}
     */
    public static function pentruBlog(int $articolePublicate): array
    {
        if (!self::activ() || $articolePublicate > 0) {
            return [];
        }
        return ['metaRobots' => self::NOINDEX];
    }

    /**
     * Pagina răspunde „nu există” (404/410): fără canonical (și fără og:url), în
     * afară de căile de coș, comandă, plată și cont (SeoRedirects::CAI_EXCLUSE),
     * care rămân exact ca înainte. Calea se ia brută din adresă, fără parametri:
     * parse_url ar citi „//plata/x” drept gazdă, iar eExclusa știe de „//”.
     */
    public static function faraCanonicalPentruCod(int $cod, string $uri = '/'): bool
    {
        if (!self::activ() || !in_array($cod, [404, 410], true)) {
            return false;
        }
        $cale = explode('?', $uri, 2)[0];
        return !SeoRedirects::eExclusa($cale === '' ? '/' : $cale);
    }

    /**
     * /blog/x sau /categorie/x inexistente primesc pagina 404 a magazinului? Da, în
     * afară de adresele care încep cu o cale exclusă scrisă cu „//” (de exemplu
     * „//api/blog/posts”, pe care routerul o citește ca /blog/posts): acolo răspunsul
     * rămâne exact ca înainte, ca la canonical (vezi faraCanonicalPentruCod).
     */
    public static function pagina404Magazin(string $uri = '/'): bool
    {
        if (!self::activ()) {
            return false;
        }
        $cale = explode('?', $uri, 2)[0];
        return !SeoRedirects::eExclusa($cale === '' ? '/' : $cale);
    }

    /**
     * Titlul pentru H1-ul din șablon al unei pagini din constructor; gol când
     * pagina nu-l primește (oprit, pagină de sistem, pagină cu formularul de coș,
     * comandă sau cont). Dacă textul paginii are deja un <h1>, șablonul nu
     * adaugă nimic (vezi adaugaH1).
     */
    public static function h1PentruPagina(string $titlu, string $slug, string $html = ''): string
    {
        $titlu = trim($titlu);
        $slugMic = mb_strtolower(trim($slug, '/'));
        if (!self::h1Activ() || $titlu === '' || in_array($slugMic, self::FARA_H1, true)
            || SeoRedirects::eExclusa('/' . $slugMic)
            || preg_match('/\{\{\s*(cart_form|checkout_form|account_section|checkout_success_order_info)\s*\}\}/', $html) === 1) {
            return '';
        }
        return $titlu;
    }

    /**
     * Titlul listei de articole de pe /blog („Blog & Resurse”) e H1? Da, când H1-ul
     * e pornit și textul paginii „blog” din constructor n-are deja un <h1>. Atunci
     * lista îl scrie ca <h1> (cu aceleași stiluri ca H2-ul de până acum), iar
     * șablonul nu mai adaugă H1-ul „Blog” deasupra (adaugaH1 găsește un <h1>).
     */
    public static function titluBlogCaH1(string $htmlPagina): bool
    {
        return self::h1Activ() && preg_match('/<h1[\s>]/i', $htmlPagina) !== 1;
    }

    /**
     * HTML-ul paginii, cu <h1>{titlu}</h1> pus în față dacă textul n-are niciun
     * <h1> (nici unul venit din secțiunile înlocuite). Titlul se scapă ca text.
     */
    public static function adaugaH1(string $html, string $titlu): string
    {
        $titlu = trim($titlu);
        if ($titlu === '' || preg_match('/<h1[\s>]/i', $html) === 1) {
            return $html;
        }
        return '<h1 class="custom-page-h1">' . htmlspecialchars($titlu, ENT_QUOTES, 'UTF-8') . "</h1>\n" . $html;
    }

    private static function pornit(string $cheie): bool
    {
        $valoare = strtolower(trim((string) Env::get($cheie, '1')));
        return !in_array($valoare, ['0', 'false', 'off', 'nu'], true);
    }
}
