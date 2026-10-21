<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ce îi spune pagina lui Google despre ea însăși, în afară de titlu și
 * descriere (livrarea 4 SEO, punctul A10 din plan):
 *
 *  - noindex,follow („nu pune pagina în Google, dar urmează linkurile”) pe:
 *      - căutarea internă (/magazin?q=…): fiecare căutare ar fi altă pagină,
 *        fără conținut propriu; fără canonical acolo, ca Google să nu ia
 *        noindex-ul drept semnal pentru /magazin sau pentru categorie;
 *      - /blog cât timp n-are niciun articol publicat (decizia D7); primul
 *        articol publicat îl readuce singur în Google (și în sitemap);
 *  - pagina „nu există” (404/410) nu are canonical: o adresă care nu există nu
 *    e varianta altei pagini.
 *
 * Etichetele mici și cele cu nume de boli rămân indexate (deciziile D2 și D3).
 *
 * Oprire de urgență, în .env: SEO_NOINDEX=0 (fără noindex pe căutare și pe
 * /blog, canonical la loc pe 404).
 */
final class SeoIndexare
{
    public const NOINDEX = 'noindex,follow';

    /** noindex pe căutare și /blog gol, fără canonical pe 404? (oprire: SEO_NOINDEX=0) */
    public static function activ(): bool
    {
        return self::pornit('SEO_NOINDEX');
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

    /** Pagina răspunde „nu există” (404/410): fără canonical (și fără og:url). */
    public static function faraCanonicalPentruCod(int $cod): bool
    {
        return self::activ() && in_array($cod, [404, 410], true);
    }

    private static function pornit(string $cheie): bool
    {
        $valoare = strtolower(trim((string) Env::get($cheie, '1')));
        return !in_array($valoare, ['0', 'false', 'off', 'nu'], true);
    }
}
