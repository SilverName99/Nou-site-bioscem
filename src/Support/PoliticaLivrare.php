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
 * Transportul gratuit (pragul din setări, azi 500 lei), exact ca în coș
 * (CheckoutCalculator::transportGratuit, decizia D din 20 oct 2026): comanda e
 * gratuită când valoarea ei atinge pragul județului (București sau provincie),
 * ORICE preț ar avea produsele, deci și la produsele cu reducere. Pentru un
 * produs comandat singur, fără cupon, valoarea comenzii e prețul afișat (cu
 * reducerea, dacă e). Un prag 0 înseamnă „niciodată gratuit” acolo.
 *  - prețul atinge ambele praguri → 0 lei;
 *  - nu atinge niciunul → tariful livrării la adresă (19 lei);
 *  - atinge doar unul (praguri diferite pe județe) → depinde de județ, iar Google
 *    nu primește regiuni pentru România → nu declarăm nimic.
 * Cuponul și punctele de fidelitate le alege clientul în coș; cu setarea
 * „include cupoanele” bifată, un cupon poate coborî comanda sub prag. Google
 * cere costul pentru oferta afișată (fără cupon), deci declarăm costul fără cupon.
 *
 * Taxa pentru kilometri suplimentari (localitățile din afara ariei FAN, total
 * până la 30 lei, plătită și la comenzile cu transport gratuit) NU intră. Regula
 * Merchant Center e „costul declarat egal sau mai mare decât cel plătit”, deci
 * pentru acele localități suntem sub cost. E o decizie a proprietarului (vezi
 * nota livrării 2): fie declarăm cazul cel mai scump, fie lăsăm așa, fie se
 * setează în Merchant Center tarife pe coduri poștale, dacă le acceptă pentru RO.
 *
 * Termenul de livrare (decizia din 20 oct 2026): pregătire 0–1 zile, transport
 * 1–2 zile, zile lucrătoare luni–vineri, adică 1–3 zile lucrătoare în total.
 * Cele patru numere sunt setări (tabelul settings, fără ecran în admin), ca să
 * se poată schimba fără cod: seo_livrare_pregatire_min/_max și
 * seo_livrare_transport_min/_max. O valoare lipsă sau greșită (nu e număr
 * întreg 0–30) ia valoarea implicită de mai jos; dacă minimul iese peste maxim,
 * perechea întreagă revine la valorile implicite.
 * Apar în datele structurate (Offer.shippingDetails.deliveryTime, câmp
 * obligatoriu pentru Google din 8 sep 2026) și în flux (g:min_handling_time…).
 * Pagina /livrare-transport („Termen estimat: minimum 24 de ore”) nu se atinge.
 *
 * Returul: termenii (/termeni-conditii) dau 14 zile de la primire, fără motiv,
 * clientul plătind doar transportul înapoi → ReturnFeesCustomerResponsibility.
 *
 * Oprire de urgență: SEO_LIVRARE_RETUR=0 în .env scoate livrarea (cu termenul
 * ei) și returul din datele structurate și livrarea din flux (restul rămâne ca
 * în livrarea 1).
 */
final class PoliticaLivrare
{
    /**
     * Termenul de livrare implicit, în zile lucrătoare (decizia proprietarului din
     * 20 oct 2026): [minim, maxim] pentru pregătire (de la comandă până la predarea
     * la curier) și pentru transport (de la curier până la client).
     */
    public const TERMEN_IMPLICIT = ['pregatire' => [0, 1], 'transport' => [1, 2]];

    /** Setările care pot schimba termenul (cheile din tabelul settings). */
    public const SETARI_TERMEN = [
        'pregatire' => ['seo_livrare_pregatire_min', 'seo_livrare_pregatire_max'],
        'transport' => ['seo_livrare_transport_min', 'seo_livrare_transport_max'],
    ];

    /** Cel mai mare număr de zile acceptat într-o setare de termen. */
    public const ZILE_MAXIM = 30;

    /** Zilele lucrătoare (pregătire și transport): luni–vineri, ca nume schema.org. */
    public const ZILE_LUCRATOARE = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

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
     * opțiune, livrarea la adresă (vezi sus de ce nu și FANbox), cu termenul de
     * livrare. Listă goală când nu putem spune sigur cât costă transportul.
     *
     * @param float $pretAfisat prețul din pagină (cu reducerea, dacă e)
     * @return list<array{serviciu: string, pret: float, termen: array{pregatire: array{0: int, 1: int}, transport: array{0: int, 1: int}}}>
     */
    public static function optiuni(array $setari, float $pretAfisat): array
    {
        if (!self::activ() || !ShippingPricing::esteActiv($setari)) {
            return [];
        }
        $adresa = ShippingPricing::pretDeBaza($setari, false);
        if ($adresa === null || $adresa <= 0.0) {
            // Un preț fix de 0 lei e mai degrabă o setare necompletată decât o promisiune.
            return [];
        }

        // Pragul de gratuitate e pe județ (București / provincie), ca în coș.
        $gratuitBucuresti = self::gratuit($setari, true, $pretAfisat);
        $gratuitProvincie = self::gratuit($setari, false, $pretAfisat);
        if ($gratuitBucuresti !== $gratuitProvincie) {
            // Gratuit doar în unele județe: Google nu primește regiuni pentru România. Tăcem.
            return [];
        }

        return [[
            'serviciu' => self::SERVICIU_ADRESA,
            'pret' => $gratuitBucuresti ? 0.0 : round($adresa, 2),
            'termen' => self::termen($setari),
        ]];
    }

    /**
     * Comanda de $valoare lei (fără cupon) are transport gratuit în București /
     * în provincie? Aceeași regulă ca CheckoutCalculator::transportGratuit: pragul
     * județului (lipsă din setări = 200, ca în coș), 0 = niciodată gratuit, iar
     * comparația e „valoarea ≥ prag”, fără să conteze dacă produsele sunt la reducere.
     */
    public static function gratuit(array $setari, bool $bucuresti, float $valoare): bool
    {
        $prag = $bucuresti
            ? (float) ($setari['shipping_free_bucharest'] ?? 200)
            : (float) ($setari['shipping_free_province'] ?? 200);
        if ($prag <= 0) {
            return false;
        }
        return $valoare >= $prag;
    }

    /**
     * Termenul de livrare din setări (vezi sus): o valoare lipsă sau greșită ia
     * valoarea implicită; un minim peste maxim readuce perechea la cea implicită.
     *
     * @return array{pregatire: array{0: int, 1: int}, transport: array{0: int, 1: int}}
     */
    public static function termen(array $setari): array
    {
        $termen = self::TERMEN_IMPLICIT;
        foreach (self::SETARI_TERMEN as $parte => [$cheieMin, $cheieMax]) {
            $min = self::zile($setari[$cheieMin] ?? null);
            $max = self::zile($setari[$cheieMax] ?? null);
            if ($min === null && $max === null) {
                continue;
            }
            $min ??= $termen[$parte][0];
            $max ??= $termen[$parte][1];
            if ($min <= $max) {
                $termen[$parte] = [$min, $max];
            }
        }
        return $termen;
    }

    /** O setare de zile: număr întreg 0–30 (fără semn, fără zecimale); altfel null. */
    private static function zile(mixed $valoare): ?int
    {
        $text = trim((string) ($valoare ?? ''));
        if (preg_match('/^\d{1,2}$/', $text) !== 1) {
            return null;
        }
        $zile = (int) $text;
        return $zile <= self::ZILE_MAXIM ? $zile : null;
    }

    /** Linkul absolut spre condițiile de retur. */
    public static function linkRetur(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . self::PAGINA_RETUR;
    }
}
