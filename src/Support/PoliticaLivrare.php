<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Livrarea și returul, așa cum le spunem lui Google: în datele structurate ale
 * produsului (Offer.shippingDetails, Offer.hasMerchantReturnPolicy), la firmă
 * (Organization.hasMerchantReturnPolicy) și în fluxul pentru Merchant Center
 * (/feed/google-merchant.xml). Un singur loc, ca pagina și fluxul să nu se
 * contrazică.
 *
 * Livrarea vine din Setări livrare → prețuri fixe, adică exact din ce încasează
 * checkout-ul. Spunem DOAR tariful livrării la adresă (azi 19 lei, după
 * /livrare-transport). Tariful FANbox (17 lei) nu intră: Merchant Center cere
 * „tariful livrării direct la client, nu alte tarife, de exemplu ridicarea din
 * magazin”, iar pentru România punctele de ridicare (lockere ca FANbox) se
 * declară doar în cont, nu pe produs. Cu ambele tarife, Google ar fi afișat
 * „livrare 17 lei” la toate produsele (alege cel mai mic). FANbox se poate
 * adăuga în Merchant Center → Livrare, ca livrare la punct de ridicare.
 * Fără prețuri fixe active nu spunem nimic (tariful curierului se calculează
 * abia la comandă).
 *
 * Transportul gratuit (pragul din setări, azi 500 lei): pagina /livrare-transport
 * spune că e gratuit doar pentru comenzile FĂRĂ reduceri. Google nu are cum să
 * primească această condiție la nivel de magazin (pragul pe valoarea comenzii nu
 * știe de reduceri), așa că NU declarăm un prag. Îl spunem doar pe produs, unde
 * e sigur: un produs la preț întreg care singur atinge pragul se livrează
 * gratuit. Unde regula nu e sigură, nu declarăm nimic:
 *  - produs la reducere care atinge pragul: termenii spun „plătește transportul”,
 *    dar calculul din coș dă azi gratuit (nu verifică reducerea) → tăcem;
 *  - pragurile pentru București și provincie diferite, iar prețul e între ele
 *    → depinde de județ, iar Google nu primește regiuni pentru România → tăcem.
 *
 * Taxa pentru kilometri suplimentari (localitățile din afara ariei FAN, total
 * până la 30 lei, plătită și la comenzile cu transport gratuit) NU intră. Regula
 * Merchant Center e „costul declarat egal sau mai mare decât cel plătit”, deci
 * pentru acele localități suntem sub cost. E o decizie a proprietarului (vezi
 * nota livrării 2): fie declarăm cazul cel mai scump, fie lăsăm așa, fie se
 * setează în Merchant Center tarife pe coduri poștale, dacă le acceptă pentru RO.
 *
 * Termenul de livrare (deliveryTime / handling_time / transit_time) nu e încă
 * hotărât de proprietar, deci nu apare nicăieri. Google îl trece la „necesar”
 * pentru afișarea livrării în rezultate (documentația din 8 sep 2026): până îl
 * completăm în TERMEN_LIVRARE, costul se citește, dar afișarea completă poate
 * lipsi.
 *
 * Returul: termenii (/termeni-conditii) dau 14 zile de la primire, fără motiv,
 * clientul plătind doar transportul înapoi → ReturnFeesCustomerResponsibility.
 *
 * Oprire de urgență: SEO_LIVRARE_RETUR=0 în .env scoate livrarea și returul din
 * datele structurate și livrarea din flux (restul rămâne ca în livrarea 1).
 */
final class PoliticaLivrare
{
    /**
     * Termenul de livrare, în zile lucrătoare: [pregătire min, max, transport
     * min, max]. NULL până îl confirmă proprietarul (decizia D10). Când e
     * completat, apare în datele structurate și în flux.
     *
     * @var array{0: int, 1: int, 2: int, 3: int}|null
     */
    public const TERMEN_LIVRARE = null;

    /** Zilele de retragere din termeni (OUG 34/2014, de la primirea coletului). */
    public const ZILE_RETUR = 14;

    /** Pagina în care sunt scrise condițiile de retur (linkul „Retur și Garanție” duce aici). */
    public const PAGINA_RETUR = '/termeni-conditii';

    /** Serviciul livrării la adresă, fără diacritice (Merchant Center îl cere ASCII). */
    public const SERVICIU_ADRESA = 'FAN Courier';

    /** Livrarea și returul apar în datele pentru Google? (oprire: SEO_LIVRARE_RETUR=0) */
    public static function activ(): bool
    {
        $valoare = strtolower(trim((string) Env::get('SEO_LIVRARE_RETUR', '1')));
        return !in_array($valoare, ['0', 'false', 'off', 'nu'], true);
    }

    /**
     * Livrarea pentru un produs comandat singur, la prețul afișat: o singură
     * opțiune, livrarea la adresă (vezi sus de ce nu și FANbox). Listă goală
     * când nu putem spune sigur cât costă transportul.
     *
     * @param float $pretAfisat prețul din pagină (cu reducerea, dacă e)
     * @param bool  $redus      produsul e la reducere (preț redus, perioadă de reducere)
     * @return list<array{serviciu: string, pret: float}>
     */
    public static function optiuni(array $setari, float $pretAfisat, bool $redus): array
    {
        if (!self::activ() || !ShippingPricing::esteActiv($setari)) {
            return [];
        }
        $adresa = ShippingPricing::pretDeBaza($setari, false);
        if ($adresa === null || $adresa <= 0.0) {
            // Un preț fix de 0 lei e mai degrabă o setare necompletată decât o promisiune.
            return [];
        }

        // Pragul de gratuitate e pe județ (București / provincie), ca în coș; un
        // prag 0 înseamnă „niciodată gratuit” acolo.
        $atinge = static fn (float $prag): bool => $prag > 0.0 && $pretAfisat + 0.00001 >= $prag;
        $pragBucuresti = (float) ($setari['shipping_free_bucharest'] ?? 0);
        $pragProvincie = (float) ($setari['shipping_free_province'] ?? 0);
        $gratuitUndeva = $atinge($pragBucuresti) || $atinge($pragProvincie);
        $gratuitPeste = $atinge($pragBucuresti) && $atinge($pragProvincie);
        if ($gratuitUndeva && ($redus || !$gratuitPeste)) {
            // La reducere: termenii spun „se plătește”, coșul dă gratuit. Doar în
            // unele județe: Google nu primește regiuni pentru România. Tăcem.
            return [];
        }

        return [['serviciu' => self::SERVICIU_ADRESA, 'pret' => $gratuitPeste ? 0.0 : round($adresa, 2)]];
    }

    /** Linkul absolut spre condițiile de retur. */
    public static function linkRetur(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . self::PAGINA_RETUR;
    }
}
