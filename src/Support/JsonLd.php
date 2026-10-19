<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Datele structurate (JSON-LD, schema.org) pe care Google le citește din pagină:
 * produs + ofertă, firimituri, firma și site-ul.
 *
 * Reguli (cerințele Google pentru fragmente de produs și listări de comerciant):
 *  - doar date care se văd în pagină: prețul afișat (cu reducerea), stocul,
 *    marca, codul; adrese absolute;
 *  - fără stele inventate: aggregateRating/review apar doar dacă produsul are
 *    recenzii reale aprobate, afișate pe pagină;
 *  - livrarea și returul (shippingDetails, hasMerchantReturnPolicy) NU sunt
 *    puse până nu le confirmă proprietarul;
 *  - un bloc <script> pentru fiecare tip.
 */
final class JsonLd
{
    public const DISPONIBILITATI = ['InStock', 'OutOfStock', 'PreOrder'];

    /** Eticheta <script> gata de pus în <head>; gol dacă nodul nu se poate scrie. */
    public static function script(array $nod): string
    {
        if ($nod === []) {
            return '';
        }
        try {
            $json = json_encode(
                ['@context' => 'https://schema.org'] + $nod,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            return '';
        }
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * @param array{
     *   url: string, name: string, images: list<string>, description: string, sku: string,
     *   brand: string, price: float, availability: string, priceValidUntil: string,
     *   reviews?: array{count: int, average: float, items: list<array<string, mixed>>}
     * } $p
     */
    public static function produs(array $p): array
    {
        $nume = self::text((string) ($p['name'] ?? ''), 300);
        $pret = (float) ($p['price'] ?? 0);
        if ($nume === '' || $pret <= 0) {
            return [];
        }
        $url = (string) ($p['url'] ?? '');
        $disponibil = in_array($p['availability'] ?? '', self::DISPONIBILITATI, true) ? (string) $p['availability'] : 'InStock';

        $oferta = [
            '@type' => 'Offer',
            'url' => $url,
            'price' => number_format($pret, 2, '.', ''),
            'priceCurrency' => 'RON',
            'availability' => 'https://schema.org/' . $disponibil,
            'itemCondition' => 'https://schema.org/NewCondition',
        ];
        $pana = trim((string) ($p['priceValidUntil'] ?? ''));
        if ($pana !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $pana) === 1) {
            $oferta['priceValidUntil'] = $pana;
        }

        $nod = ['@type' => 'Product', '@id' => $url . '#product', 'name' => $nume, 'url' => $url];
        $imagini = array_values(array_unique(array_filter(array_map('strval', (array) ($p['images'] ?? [])))));
        if ($imagini !== []) {
            $nod['image'] = array_slice($imagini, 0, 10);
        }
        $descriere = self::text((string) ($p['description'] ?? ''), 5000);
        if ($descriere !== '') {
            $nod['description'] = $descriere;
        }
        $sku = trim((string) ($p['sku'] ?? ''));
        if ($sku !== '') {
            $nod['sku'] = $sku;
        }
        $marca = self::text((string) ($p['brand'] ?? ''), 200);
        if ($marca !== '') {
            $nod['brand'] = ['@type' => 'Brand', 'name' => $marca];
        }
        $nod['offers'] = $oferta;

        $recenzii = (array) ($p['reviews'] ?? []);
        $numar = (int) ($recenzii['count'] ?? 0);
        if ($numar > 0) {
            $nod['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format(max(1.0, min(5.0, (float) ($recenzii['average'] ?? 0))), 1, '.', ''),
                'reviewCount' => $numar,
                'bestRating' => 5,
                'worstRating' => 1,
            ];
            $lista = [];
            foreach (array_slice((array) ($recenzii['items'] ?? []), 0, 5) as $r) {
                $autor = self::text((string) ($r['user_name'] ?? ''), 100);
                if ($autor === '') {
                    continue;
                }
                $recenzie = [
                    '@type' => 'Review',
                    'author' => ['@type' => 'Person', 'name' => $autor],
                    'reviewRating' => [
                        '@type' => 'Rating',
                        'ratingValue' => max(1, min(5, (int) ($r['rating'] ?? 5))),
                        'bestRating' => 5,
                        'worstRating' => 1,
                    ],
                ];
                $data = strtotime((string) ($r['created_at'] ?? ''));
                if ($data !== false) {
                    $recenzie['datePublished'] = date('Y-m-d', $data);
                }
                $text = self::text((string) ($r['review_text'] ?? ''), 2000);
                if ($text !== '') {
                    $recenzie['reviewBody'] = $text;
                }
                $lista[] = $recenzie;
            }
            if ($lista !== []) {
                $nod['review'] = $lista;
            }
        }

        return $nod;
    }

    /**
     * Firimiturile: [['name' => 'Acasă', 'url' => 'https://…/'], …]; ultimul
     * element poate fi fără adresă (pagina curentă).
     *
     * @param list<array{name: string, url?: string}> $elemente
     */
    public static function firimituri(array $elemente): array
    {
        $lista = [];
        $pozitie = 1;
        foreach ($elemente as $element) {
            $nume = self::text((string) ($element['name'] ?? ''), 200);
            if ($nume === '') {
                continue;
            }
            $item = ['@type' => 'ListItem', 'position' => $pozitie++, 'name' => $nume];
            $url = trim((string) ($element['url'] ?? ''));
            if ($url !== '') {
                $item['item'] = $url;
            }
            $lista[] = $item;
        }
        if (count($lista) < 2) {
            return [];
        }
        return ['@type' => 'BreadcrumbList', 'itemListElement' => $lista];
    }

    /**
     * Firma, pe prima pagină. Doar ce e completat în setări; sameAs doar cu
     * profiluri reale (o adresă de rețea socială fără cont după domeniu nu intră).
     *
     * @param array{name: string, url: string, logo?: string, email?: string, telephone?: string, sameAs?: list<string>} $f
     */
    public static function organizatie(array $f): array
    {
        $baza = rtrim((string) ($f['url'] ?? ''), '/');
        $nod = [
            '@type' => 'Organization',
            '@id' => $baza . '/#organization',
            'name' => self::text((string) ($f['name'] ?? ''), 200),
            'url' => $baza . '/',
        ];
        foreach (['logo', 'email', 'telephone'] as $camp) {
            $valoare = trim((string) ($f[$camp] ?? ''));
            if ($valoare !== '') {
                $nod[$camp] = $valoare;
            }
        }
        $profiluri = array_values(array_unique(array_filter(array_map('strval', (array) ($f['sameAs'] ?? [])))));
        if ($profiluri !== []) {
            $nod['sameAs'] = $profiluri;
        }
        return $nod;
    }

    public static function site(string $nume, string $baseUrl): array
    {
        $baza = rtrim($baseUrl, '/');
        return [
            '@type' => 'WebSite',
            '@id' => $baza . '/#website',
            'name' => self::text($nume, 200),
            'url' => $baza . '/',
            'inLanguage' => 'ro-RO',
            'publisher' => ['@id' => $baza . '/#organization'],
        ];
    }

    /**
     * Profilurile sociale reale din HTML-ul antetului și subsolului (linkuri
     * spre Facebook, Instagram etc. cu un cont după domeniu).
     *
     * @return list<string>
     */
    public static function profiluriSociale(string $html): array
    {
        $profiluri = [];
        if (preg_match_all('#href\s*=\s*["\'](https?://[^"\'\s]+)["\']#i', $html, $m) < 1) {
            return [];
        }
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parti = parse_url($href);
            if (!is_array($parti)) {
                continue;
            }
            $gazda = strtolower((string) ($parti['host'] ?? ''));
            $gazda = (string) preg_replace('/^(www\.|m\.)/', '', $gazda);
            if (!in_array($gazda, ['facebook.com', 'instagram.com', 'tiktok.com', 'youtube.com', 'x.com', 'twitter.com', 'linkedin.com', 'pinterest.com'], true)) {
                continue;
            }
            $cale = trim((string) ($parti['path'] ?? ''), '/');
            // „facebook.com/”, „facebook.com/sharer…” sau „x.com/intent…” nu sunt profiluri.
            if ($cale === '' || preg_match('#^(sharer|share|intent|home|login|watch|hashtag|search|explore|plugins|dialog)\b#i', $cale) === 1) {
                continue;
            }
            $profiluri[] = $href;
        }
        return array_values(array_unique($profiluri));
    }

    /** HTML → text simplu: fără etichete, entități decodate, spații strânse, tăiat la cuvânt. */
    public static function text(string $html, int $maxim): string
    {
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > $maxim) {
            $text = mb_substr($text, 0, $maxim);
            $spatiu = mb_strrpos($text, ' ');
            if ($spatiu !== false && $spatiu > $maxim * 0.6) {
                $text = mb_substr($text, 0, $spatiu);
            }
            $text = rtrim($text, " ,;:-–—|") . '…';
        }
        return $text;
    }
}
