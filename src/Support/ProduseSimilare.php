<?php

declare(strict_types=1);

namespace App\Support;

/**
 * „Produse similare” de pe pagina produsului (livrarea 4 SEO, A14 din plan).
 *
 * Până acum secțiunea arăta doar produsele alese de mână în admin (la multe
 * produse unul singur, uneori din altă categorie) și le desena din JavaScript,
 * deci în HTML-ul paginii nu era niciun link spre ele. Acum:
 *  - se aleg 4–8 produse din aceeași categorie (categoria principală),
 *    întâi cele care se pot cumpăra (în stoc sau în precomandă), apoi cele
 *    epuizate. Cele alese în admin din aceeași categorie vin primele; restul,
 *    pe rând, după produsul curent (fiecare produs al categoriei primește
 *    astfel linkuri de la vecinii lui, nu doar primele 8 de la toți);
 *  - produsele la care clientul trebuie să aleagă o ofertă (lot cu dată de
 *    expirare) intră doar dacă sunt alese în admin: butonul de coș din card nu
 *    știe să aleagă oferta;
 *  - dacă din categorie ies mai puțin de 4, se completează până la 4 cu cele
 *    alese în admin din alte categorii (ca înainte);
 *  - cardurile sunt scrise direct în HTML (linkuri reale, cu poza, numele și
 *    prețul), cu exact marcajul pe care îl face JavaScript-ul caruselului, care
 *    le rearanjează pe lățimea ecranului ca până acum. Aspectul rămâne același.
 *
 * Adminul nu se schimbă: alegerea manuală se salvează și se folosește ca înainte.
 *
 * Oprire de urgență: SEO_PRODUSE_SIMILARE=0 în .env (secțiunea de dinainte:
 * doar produsele alese în admin, desenate din JavaScript).
 */
final class ProduseSimilare
{
    public const MINIM = 4;
    public const MAXIM = 8;

    /** Câte carduri pe un „slide” în HTML-ul de pe server (lățimea de calculator). */
    private const PE_SLIDE = 4;

    public static function activ(): bool
    {
        $valoare = strtolower(trim((string) Env::get('SEO_PRODUSE_SIMILARE', '1')));
        return !in_array($valoare, ['0', 'false', 'off', 'nu'], true);
    }

    /**
     * Produsele de arătat, în ordine (id-uri).
     *
     * @param array{categorie_id: int, categorie: string} $curent categoria produsului curent
     * @param list<int> $alesiAdmin produsele alese în admin, în ordinea lor
     * @param array<int, array{categorie_id: int, categorie: string, disponibil: bool, alegere: bool}> $candidati
     *        produsele care se pot arăta (active, nesterse, cu adresă), fără cel curent
     * @return list<int>
     */
    public static function ordine(int $idCurent, array $curent, array $alesiAdmin, array $candidati): array
    {
        unset($candidati[$idCurent]);
        $rangAdmin = [];
        foreach ($alesiAdmin as $i => $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $idCurent && !isset($rangAdmin[$id])) {
                $rangAdmin[$id] = count($rangAdmin);
            }
        }
        $cheie = static fn (array $p): array => [
            !empty($p['disponibil']) ? 0 : 1,
        ];

        $dinCategorie = [];
        if (self::areCategorie($curent)) {
            foreach ($candidati as $id => $p) {
                if (self::aceeasiCategorie($curent, $p)) {
                    $dinCategorie[] = (int) $id;
                }
            }
        }

        // Rotația: produsele categoriei, după id, pornind de la cel de după produsul curent.
        $toate = $dinCategorie;
        $toate[] = $idCurent;
        sort($toate);
        $toate = array_values(array_unique($toate));
        $pozitie = array_flip($toate);
        $n = count($toate);
        $rotatie = static fn (int $id): int => (($pozitie[$id] ?? 0) - ($pozitie[$idCurent] ?? 0) + $n) % max(1, $n);

        $alese = array_values(array_filter(
            $dinCategorie,
            static fn (int $id): bool => isset($rangAdmin[$id]) || empty($candidati[$id]['alegere'])
        ));
        usort($alese, static function (int $a, int $b) use ($candidati, $cheie, $rangAdmin, $rotatie): int {
            return [$cheie($candidati[$a]), $rangAdmin[$a] ?? PHP_INT_MAX, $rotatie($a)]
                <=> [$cheie($candidati[$b]), $rangAdmin[$b] ?? PHP_INT_MAX, $rotatie($b)];
        });
        $lista = array_slice($alese, 0, self::MAXIM);

        // Prea puține în categorie (sau produsul n-are categorie): alegerea din admin.
        $limita = self::areCategorie($curent) ? self::MINIM : self::MAXIM;
        if (count($lista) < $limita) {
            $altele = array_values(array_filter(
                array_keys($rangAdmin),
                static fn (int $id): bool => isset($candidati[$id]) && !in_array($id, $lista, true)
            ));
            usort($altele, static function (int $a, int $b) use ($candidati, $cheie, $rangAdmin): int {
                return [$cheie($candidati[$a]), $rangAdmin[$a]] <=> [$cheie($candidati[$b]), $rangAdmin[$b]];
            });
            foreach ($altele as $id) {
                if (count($lista) >= $limita) {
                    break;
                }
                $lista[] = $id;
            }
        }
        return $lista;
    }

    /**
     * Cardurile scrise pe server, în „slide”-uri de câte 4, cu exact marcajul
     * din JavaScript-ul caruselului (views/site/product.php, initSimilarProducts),
     * plus width/height pe poze.
     *
     * @param list<array<string, mixed>> $produse elementele din SiteController::buildSimilarProductsForSite
     */
    public static function cardurileHtml(array $produse): string
    {
        $carduri = [];
        foreach ($produse as $p) {
            if (is_array($p)) {
                $carduri[] = self::card($p);
            }
        }
        $slideuri = [];
        foreach (array_chunk($carduri, self::PE_SLIDE) as $grup) {
            $slideuri[] = '<div class="similar-products__slide">' . implode('', $grup) . '</div>';
        }
        return implode('', $slideuri);
    }

    /** Un card, ca în JavaScript (inclusiv corpul cardului închis de </article>). */
    private static function card(array $p): string
    {
        $imagineBruta = self::adresaSigura((string) (($p['image_url'] ?? '') ?: '/assets/img/product-placeholder.svg'), '/assets/img/product-placeholder.svg');
        $imagine = self::e($imagineBruta);
        $nume = self::e((string) (($p['name'] ?? '') ?: 'Produs'));
        $url = self::e(self::adresaSigura((string) (($p['url'] ?? '') ?: '#'), '#'));
        $descriere = self::e((string) (($p['short_description'] ?? '') ?: 'Supliment natural'));
        $pret = self::e((string) ($p['price_label'] ?? ''));
        $pretVechi = self::e((string) ($p['regular_price_label'] ?? ''));
        $recenzii = max(0, (int) ($p['reviews_count'] ?? 0));
        $medie = max(0.0, min(5.0, (float) ($p['reviews_average'] ?? 0)));
        $stele = (int) round($medie);
        $steleHtml = '';
        for ($i = 0; $i < 5; $i++) {
            $steleHtml .= '<svg class="similar-product-card__star" viewBox="0 0 24 24" fill="' . ($i < $stele ? 'currentColor' : 'none')
                . '" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3.8l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 17l-5.2 2.8 1-5.8-4.2-4.1 5.8-.8L12 3.8z"/></svg>';
        }
        $valoare = max(0.0, (float) ($p['price'] ?? 0));
        $valoareVeche = max(0.0, (float) ($p['regular_price'] ?? 0));
        $areReducere = (string) ($p['has_sale_price'] ?? '0') === '1';
        $reducere = $areReducere && $valoareVeche > $valoare ? (int) round((($valoareVeche - $valoare) / $valoareVeche) * 100) : 0;
        $modBuline = (string) ($p['discount_badge_mode'] ?? 'percent') === 'value' ? 'value' : 'percent';
        $etichetaReducere = self::e((string) ($p['discount_value_label'] ?? ''));
        $textBulina = $modBuline === 'value' && $etichetaReducere !== '' ? '-' . $etichetaReducere . ' lei' : '-' . $reducere . '%';
        $epuizat = (string) ($p['out_of_stock'] ?? '0') === '1';
        $precomanda = (string) ($p['preorder'] ?? '0') === '1';
        $idProdus = max(0, (int) ($p['id'] ?? 0));

        if ($epuizat) {
            $actiune = '<span class="similar-product-card__stock-out">Stoc epuizat</span>';
        } elseif ($precomanda) {
            $actiune = '<a class="similar-product-card__cart-btn" href="' . $url . '" title="Se livrează după ce marfa ajunge la noi" style="padding:0 14px;width:auto;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;">Precomandă</a>';
        } else {
            $actiune = '<form method="post" action="/cos/adauga/' . $idProdus . '"><button type="submit" class="similar-product-card__cart-btn" aria-label="Adaugă în coș"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 7H7"/></svg></button></form>';
        }

        return '<article class="similar-product-card">'
            . '<div class="similar-product-card__media-wrap">'
            . '<a class="similar-product-card__media" href="' . $url . '"><img class="similar-product-card__img" src="' . $imagine . '" alt="' . $nume . '"'
            . PozeOptimizate::dimensiuni($imagineBruta)
            . ' loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';">'
            . ($reducere > 0 ? '<span class="similar-product-card__bubble' . ($modBuline === 'value' ? ' similar-product-card__bubble--value' : '') . '">' . $textBulina . '</span>' : '')
            . '</a>'
            . '</div>'
            . '<div class="similar-product-card__body">'
            . '<p class="similar-product-card__desc">' . $descriere . '</p>'
            . '<h4 class="similar-product-card__name"><a href="' . $url . '">' . $nume . '</a></h4>'
            . '<div class="similar-product-card__rating"><span class="similar-product-card__stars" aria-label="Rating ' . number_format($medie, 1, '.', '') . ' din 5">' . $steleHtml . '</span><span class="similar-product-card__reviews">(' . $recenzii . ')</span></div>'
            . '<div class="similar-product-card__bottom">'
            . '<p class="similar-product-card__price">' . $pret . ($pretVechi !== '' ? '<span class="similar-product-card__old">' . $pretVechi . '</span>' : '') . '</p>'
            . $actiune
            . '</div>'
            . '</article>';
    }

    private static function areCategorie(array $p): bool
    {
        return (int) ($p['categorie_id'] ?? 0) > 0 || trim((string) ($p['categorie'] ?? '')) !== '';
    }

    /** Aceeași categorie principală: după id când îl au amândouă, altfel după nume. */
    private static function aceeasiCategorie(array $a, array $b): bool
    {
        $idA = (int) ($a['categorie_id'] ?? 0);
        $idB = (int) ($b['categorie_id'] ?? 0);
        if ($idA > 0 && $idB > 0) {
            return $idA === $idB;
        }
        $numeA = mb_strtolower(trim((string) ($a['categorie'] ?? '')));
        return $numeA !== '' && $numeA === mb_strtolower(trim((string) ($b['categorie'] ?? '')));
    }

    /** Ca safeImageUrl/safeLinkUrl din JavaScript: doar „/…” sau http(s)://…, altfel rezerva. */
    private static function adresaSigura(string $adresa, string $rezerva): string
    {
        $adresa = trim($adresa);
        return (str_starts_with($adresa, '/') || preg_match('#^https?://#i', $adresa) === 1) ? $adresa : $rezerva;
    }

    /** Ca escapeHtml din JavaScript. */
    private static function e(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }
}
