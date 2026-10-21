<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Fluxul de produse pentru Google Merchant Center: /feed/google-merchant.xml
 * (RSS 2.0 cu atributele g:, după specificația Merchant Center).
 *
 * Conține doar produsele active, cu preț și cu poză: id (codul SKU), nume,
 * descriere, adresa produsului (cea canonică), poza principală și celelalte,
 * prețul de bază și, când e o reducere, prețul redus cu perioada lui,
 * disponibilitatea (aceeași ca în datele structurate ale paginii), marca,
 * starea „nou”, codul EAN când există și livrarea la adresă, cu termenul de
 * livrare (aceleași reguli ca în pagină, vezi PoliticaLivrare): în g:shipping
 * pregătirea (g:min_handling_time / g:max_handling_time) și transportul
 * (g:min_transit_time / g:max_transit_time), iar zilele lucrătoare luni–vineri
 * în g:shipping_handling_business_days și g:shipping_transit_business_days
 * („M;T;W;R;F”, forma din specificația Merchant Center; fără ele, Merchant Center
 * presupune luni–vineri la pregătire, dar poate presupune luni–sâmbătă la transport).
 *
 * Ce lipsește intenționat:
 *  - identifier_exists=no: Merchant Center îl permite doar dacă produsul chiar
 *    n-are cod de bare de la producător („altfel produsul poate fi respins”), iar
 *    aproape toate produsele de aici sunt de marcă și au EAN. Fără atribut,
 *    Merchant Center doar avertizează „lipsește GTIN” până se completează codurile;
 *  - produsele în precomandă: Merchant Center cere data de disponibilitate
 *    (availability_date), pe care site-ul n-o are încă. Fără ea produsul ar fi
 *    respins, deci nu-l trimitem; intră singur când apare câmpul pentru dată.
 *
 * Fluxul se construiește din baza de date și se ține 15 minute în
 * storage/cache/, deci o preluare nu încarcă baza de fiecare dată. Stocul vine
 * prin ErpStock, la fel ca în lista din /magazin: o singură cerere pentru toate
 * produsele, din cache-ul de stoc, niciodată una pe produs.
 */
final class FluxGoogleMerchant
{
    /** Cât stă fluxul în cache (secunde). */
    public const DURATA_CACHE = 900;

    /**
     * Disponibilitatea din pagină → valoarea din flux. Precomanda („preorder”)
     * lipsește până avem data de disponibilitate (vezi sus): fără ea, produsul
     * nu intră în flux.
     */
    private const DISPONIBILITATE = [
        'InStock' => 'in_stock',
        'OutOfStock' => 'out_of_stock',
    ];

    public static function fisierCache(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/feed-google-merchant.xml';
    }

    /** Fluxul din cache, dacă e mai nou de 15 minute; altfel null. */
    public static function dinCache(): ?string
    {
        $fisier = self::fisierCache();
        if (!is_file($fisier) || time() - (int) @filemtime($fisier) >= self::DURATA_CACHE) {
            return null;
        }
        $xml = @file_get_contents($fisier);
        return is_string($xml) && $xml !== '' ? $xml : null;
    }

    /** Scrie fluxul în cache (fișier temporar + redenumire; o scriere eșuată nu strică nimic). */
    public static function salveaza(string $xml): void
    {
        try {
            $fisier = self::fisierCache();
            $dosar = dirname($fisier);
            if (!is_dir($dosar)) {
                @mkdir($dosar, 0775, true);
            }
            $temporar = $fisier . '.' . getmypid() . '.tmp';
            if (@file_put_contents($temporar, $xml) === strlen($xml)) {
                @rename($temporar, $fisier);
            } else {
                @unlink($temporar);
            }
        } catch (Throwable) {
        }
    }

    /**
     * Fluxul întreg. Fiecare produs vine pregătit de SiteController::produsePentruFlux.
     *
     * @param list<array{
     *   id: string, titlu: string, descriere: string, link: string, imagine: string,
     *   imagini_extra: list<string>, disponibilitate: string, pret: float, pret_redus: ?float,
     *   reducere_de_la: string, reducere_pana_la: string, marca: string, categorie: string,
     *   gtin: string, livrare: list<array{serviciu: string, pret: float, termen?: array<string, array{0: int, 1: int}>}>
     * }> $produse
     */
    public static function xml(array $produse, string $baseUrl, string $numeMagazin): string
    {
        $baza = rtrim($baseUrl, '/');
        $linii = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">',
            '<channel>',
            '<title>' . self::x($numeMagazin) . '</title>',
            '<link>' . self::x($baza . '/') . '</link>',
            '<description>' . self::x('Produsele ' . $numeMagazin) . '</description>',
        ];
        foreach ($produse as $produs) {
            $element = self::element($produs);
            if ($element !== '') {
                $linii[] = $element;
            }
        }
        $linii[] = '</channel>';
        $linii[] = '</rss>';
        return implode("\n", $linii) . "\n";
    }

    /** Un <item>; gol dacă produsului îi lipsește ceva obligatoriu (preț, poză, adresă, data unei precomenzi). */
    public static function element(array $p): string
    {
        $id = mb_substr(trim((string) ($p['id'] ?? '')), 0, 50);
        $titlu = SeoMeta::taieLaCuvant(self::textSimplu((string) ($p['titlu'] ?? '')), 150);
        $link = trim((string) ($p['link'] ?? ''));
        $imagine = trim((string) ($p['imagine'] ?? ''));
        $pret = (float) ($p['pret'] ?? 0);
        $disponibil = self::DISPONIBILITATE[(string) ($p['disponibilitate'] ?? '')] ?? null;
        if ($id === '' || $titlu === '' || $pret <= 0 || $disponibil === null
            || preg_match('#^https?://#i', $link) !== 1 || preg_match('#^https?://#i', $imagine) !== 1) {
            return '';
        }
        $descriere = SeoMeta::taieLaCuvant(SeoMeta::text((string) ($p['descriere'] ?? '')), 5000);
        if ($descriere === '') {
            $descriere = $titlu;
        }

        $c = [];
        $c[] = self::camp('id', $id);
        $c[] = self::camp('title', $titlu);
        $c[] = self::camp('description', $descriere);
        $c[] = self::camp('link', $link);
        $c[] = self::camp('image_link', $imagine);
        foreach (array_slice(array_values(array_unique(array_filter(
            (array) ($p['imagini_extra'] ?? []),
            static fn ($u): bool => is_string($u) && preg_match('#^https?://#i', $u) === 1 && $u !== $imagine
        ))), 0, 10) as $extra) {
            $c[] = self::camp('additional_image_link', $extra);
        }
        $c[] = self::camp('availability', $disponibil);
        $c[] = self::camp('price', self::suma($pret));
        $redus = $p['pret_redus'] ?? null;
        if ($redus !== null && (float) $redus > 0 && (float) $redus < $pret - 0.004) {
            $c[] = self::camp('sale_price', self::suma((float) $redus));
            $perioada = self::perioada((string) ($p['reducere_de_la'] ?? ''), (string) ($p['reducere_pana_la'] ?? ''));
            if ($perioada !== '') {
                $c[] = self::camp('sale_price_effective_date', $perioada);
            }
        }
        $marca = self::textSimplu((string) ($p['marca'] ?? ''));
        if ($marca !== '') {
            $c[] = self::camp('brand', mb_substr($marca, 0, 70));
        }
        $gtin = preg_replace('/\D+/', '', (string) ($p['gtin'] ?? '')) ?? '';
        if (self::gtinValid($gtin)) {
            $c[] = self::camp('gtin', $gtin);
        }
        // Fără EAN încă (câmp nou în ANDAXI-CMS, B4): nu trimitem identifier_exists=no
        // (vezi sus). Când va exista o bifă „produs fără cod de bare” (produse
        // proprii, nemarcate), doar pentru acelea se trimite aici „no”.
        $c[] = self::camp('condition', 'new');
        $categorie = self::textSimplu((string) ($p['categorie'] ?? ''));
        if ($categorie !== '') {
            $c[] = self::camp('product_type', mb_substr($categorie, 0, 750));
        }
        $cuTermen = false;
        foreach ((array) ($p['livrare'] ?? []) as $optiune) {
            if (!is_array($optiune) || !isset($optiune['pret'])) {
                continue;
            }
            $livrare = '<g:country>RO</g:country>'
                . '<g:service>' . self::x((string) ($optiune['serviciu'] ?? '')) . '</g:service>'
                . '<g:price>' . self::x(self::suma((float) $optiune['pret'])) . '</g:price>';
            $termen = JsonLd::termenValid($optiune['termen'] ?? null);
            if ($termen !== null) {
                $cuTermen = true;
                $livrare .= '<g:min_handling_time>' . $termen['pregatire'][0] . '</g:min_handling_time>'
                    . '<g:max_handling_time>' . $termen['pregatire'][1] . '</g:max_handling_time>'
                    . '<g:min_transit_time>' . $termen['transport'][0] . '</g:min_transit_time>'
                    . '<g:max_transit_time>' . $termen['transport'][1] . '</g:max_transit_time>';
            }
            $c[] = '<g:shipping>' . $livrare . '</g:shipping>';
        }
        if ($cuTermen) {
            $zile = self::zileLucratoare();
            $c[] = self::camp('shipping_handling_business_days', $zile);
            $c[] = self::camp('shipping_transit_business_days', $zile);
        }

        return "<item>\n" . implode("\n", $c) . "\n</item>";
    }

    /**
     * Zilele lucrătoare din PoliticaLivrare în forma Merchant Center: inițiale
     * separate prin „;” (M luni, T marți, W miercuri, R joi, F vineri, S sâmbătă,
     * U duminică), de exemplu „M;T;W;R;F”.
     */
    private static function zileLucratoare(): string
    {
        $initiale = [
            'Monday' => 'M', 'Tuesday' => 'T', 'Wednesday' => 'W', 'Thursday' => 'R',
            'Friday' => 'F', 'Saturday' => 'S', 'Sunday' => 'U',
        ];
        $zile = [];
        foreach (PoliticaLivrare::ZILE_LUCRATOARE as $zi) {
            if (isset($initiale[$zi])) {
                $zile[] = $initiale[$zi];
            }
        }
        return implode(';', $zile);
    }

    /** „19.00 RON”: punct zecimal, fără separator de mii. */
    private static function suma(float $valoare): string
    {
        return number_format(max(0.0, $valoare), 2, '.', '') . ' RON';
    }

    /**
     * Perioada reducerii, „2026-10-01T00:00+0300/2026-10-31T23:59+0200” (ora
     * României, cu fusul orar al fiecărei zile); gol dacă una din date lipsește.
     */
    private static function perioada(string $deLa, string $panaLa): string
    {
        if (trim($deLa) === '' || trim($panaLa) === '') {
            return '';
        }
        try {
            $fus = new DateTimeZone(date_default_timezone_get());
            $start = new DateTimeImmutable($deLa, $fus);
            $final = new DateTimeImmutable($panaLa, $fus);
        } catch (Throwable) {
            return '';
        }
        if ($final < $start) {
            return '';
        }
        return $start->format('Y-m-d\TH:iO') . '/' . $final->format('Y-m-d\TH:iO');
    }

    /** GTIN-8/12/13/14 cu cifra de control corectă (regulile GS1). */
    public static function gtinValid(string $gtin): bool
    {
        if (!in_array(strlen($gtin), [8, 12, 13, 14], true) || preg_match('/^\d+$/', $gtin) !== 1 || ltrim($gtin, '0') === '') {
            return false;
        }
        $cifre = array_map('intval', str_split($gtin));
        $control = array_pop($cifre);
        $suma = 0;
        foreach (array_reverse($cifre) as $i => $cifra) {
            $suma += $cifra * ($i % 2 === 0 ? 3 : 1);
        }
        return (10 - $suma % 10) % 10 === $control;
    }

    /**
     * Numele, marca și categoria sunt text simplu (nu HTML): entitățile se
     * decodează, spațiile se strâng, dar un „<” din nume rămâne.
     */
    private static function textSimplu(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function camp(string $nume, string $valoare): string
    {
        return '<g:' . $nume . '>' . self::x($valoare) . '</g:' . $nume . '>';
    }

    /** Text sigur pentru XML: fără caractere de control, cu &, <, > și ghilimele scăpate. */
    private static function x(string $text): string
    {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($text, 'UTF-8'));
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
