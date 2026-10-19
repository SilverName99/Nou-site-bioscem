<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Regulile din robots.txt, într-un singur loc: le folosesc și robots.txt, și
 * sitemap-ul (care nu are voie să trimită lui Google adrese blocate).
 *
 * Regulile sunt exacte, nu prefixe largi: „Disallow: /cos” ar fi blocat și o
 * viitoare pagină /cosmetice. Coșul e blocat prin căile lui reale: /cos (doar
 * ea, cu „$”, pentru Google și Bing), /cos? (cu parametri), /cos/ (acțiunile
 * coșului) și /cos-cumparaturi (pagina de coș din constructor). Un robot care
 * nu înțelege „$” o citește ca pe o cale care nu există, deci nu blochează nimic
 * în plus.
 */
final class Robots
{
    /** @var list<string> */
    public const DISALLOW = [
        '/admin',
        '/api/',
        '/auth/',
        '/webhook/',
        '/cos$',
        '/cos?',
        '/cos/',
        '/cos-cumparaturi',
        '/checkout',
        '/contul-meu',
        '/login',
        '/register',
        '/plata/',
        '/newsletter/',
        '/gdpr-agreements',
    ];

    /** Textul complet al robots.txt. Același și în modul mentenanță. */
    public static function text(string $baseUrl): string
    {
        $linii = ['User-agent: *'];
        foreach (self::DISALLOW as $regula) {
            $linii[] = 'Disallow: ' . $regula;
        }
        $linii[] = '';
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl !== '') {
            $linii[] = 'Sitemap: ' . $baseUrl . '/sitemap.xml';
        }

        return implode("\n", $linii) . "\n";
    }

    /**
     * Calea (cu query, dacă are) e blocată de una dintre reguli? Potrivire ca
     * la Google: prefix, „$” = sfârșitul adresei.
     */
    public static function blocheaza(string $caleCuQuery): bool
    {
        if ($caleCuQuery === '' || $caleCuQuery[0] !== '/') {
            $caleCuQuery = '/' . $caleCuQuery;
        }
        foreach (self::DISALLOW as $regula) {
            if (str_ends_with($regula, '$')) {
                if ($caleCuQuery === substr($regula, 0, -1)) {
                    return true;
                }
                continue;
            }
            if (str_starts_with($caleCuQuery, $regula)) {
                return true;
            }
        }
        return false;
    }
}
