<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Livrarea și returul, așa cum le spunem lui Google: în datele structurate ale
 * produsului (Offer.shippingDetails, Offer.hasMerchantReturnPolicy), la firmă
 * (Organization.hasMerchantReturnPolicy) și în fluxul pentru Merchant Center
 * (/feed/google-merchant.xml). Un singur loc, ca pagina și fluxul să nu se
 * contrazică.
 *
 * Livrarea vine din Setări livrare → prețuri fixe, adică exact din ce încasează
 * checkout-ul (azi, după /livrare-transport: 19 lei la adresă, 17 lei la
 * FANbox). Fără prețuri fixe active nu spunem nimic (tariful curierului se
 * calculează abia la comandă).
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
 * Taxa pentru kilometri suplimentari (în afara ariei FAN, până la 30 lei) nu
 * intră: e o excepție pe localitate, pe care Google n-o poate primi pentru RO.
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

    /** Serviciile, cu nume fără diacritice (Merchant Center le cere ASCII). */
    public const SERVICIU_ADRESA = 'FAN Courier';
    public const SERVICIU_FANBOX = 'FANbox';

    /** @var array<string, bool> FANbox are puncte active? (o singură întrebare pe cerere) */
    private static array $areFanbox = [];

    /** Livrarea și returul apar în datele pentru Google? (oprire: SEO_LIVRARE_RETUR=0) */
    public static function activ(): bool
    {
        $valoare = strtolower(trim((string) Env::get('SEO_LIVRARE_RETUR', '1')));
        return !in_array($valoare, ['0', 'false', 'off', 'nu'], true);
    }

    /**
     * Opțiunile de livrare pentru un produs comandat singur, la prețul afișat.
     * Listă goală când nu putem spune sigur cât costă transportul.
     *
     * @param float $pretAfisat prețul din pagină (cu reducerea, dacă e)
     * @param bool  $redus      produsul e la reducere (preț redus, perioadă de reducere)
     * @return list<array{serviciu: string, pret: float}>
     */
    public static function optiuni(array $setari, ?PDO $db, float $pretAfisat, bool $redus): array
    {
        if (!self::activ() || !ShippingPricing::esteActiv($setari)) {
            return [];
        }
        $adresa = ShippingPricing::pretDeBaza($setari, false);
        if ($adresa === null || $adresa <= 0.0) {
            // Un preț fix de 0 lei e mai degrabă o setare necompletată decât o promisiune.
            return [];
        }
        $fanbox = null;
        if (ShippingPricing::ofertaFanbox($setari) && self::fanboxAreLocuri($db)) {
            $pret = ShippingPricing::pretDeBaza($setari, true);
            if ($pret !== null && $pret > 0.0) {
                $fanbox = $pret;
            }
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

        if ($gratuitPeste) {
            // Gratuit și la adresă, și la FANbox: o singură opțiune, la 0 lei.
            return [['serviciu' => self::SERVICIU_ADRESA, 'pret' => 0.0]];
        }
        $optiuni = [['serviciu' => self::SERVICIU_ADRESA, 'pret' => round($adresa, 2)]];
        if ($fanbox !== null) {
            $optiuni[] = ['serviciu' => self::SERVICIU_FANBOX, 'pret' => round($fanbox, 2)];
        }
        return $optiuni;
    }

    /** Linkul absolut spre condițiile de retur. */
    public static function linkRetur(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . self::PAGINA_RETUR;
    }

    /** Există cel puțin un punct FANbox activ (checkout-ul arată opțiunea doar atunci)? */
    private static function fanboxAreLocuri(?PDO $db): bool
    {
        if (!$db instanceof PDO) {
            return false;
        }
        $cheie = spl_object_hash($db);
        if (!array_key_exists($cheie, self::$areFanbox)) {
            try {
                $rand = $db->query('SELECT 1 FROM fan_lockers WHERE active = 1 LIMIT 1');
                self::$areFanbox[$cheie] = $rand !== false && $rand->fetchColumn() !== false;
            } catch (Throwable) {
                self::$areFanbox[$cheie] = false;
            }
        }
        return self::$areFanbox[$cheie];
    }
}
