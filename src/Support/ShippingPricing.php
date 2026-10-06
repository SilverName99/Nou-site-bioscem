<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Prețuri fixe de transport, configurate în Setări livrare.
 *
 * Când sunt active, ele înlocuiesc tariful calculat de curier:
 *  - o comandă obișnuită costă „prețul de bază";
 *  - o comandă către o localitate din lista cu km suplimentari costă
 *    „prețul de bază + taxa de km suplimentari";
 *  - o livrare la FANbox costă prețul propriu de FANbox.
 */
final class ShippingPricing
{
    public static function esteActiv(array $settings): bool
    {
        return (string) ($settings['shipping_fixed_enabled'] ?? '0') === '1';
    }

    /**
     * Magazinul oferă livrare la FANbox? E o decizie de magazin, luată explicit
     * dintr-o bifă proprie — NU dedusă din „Opțiuni FAN", care e o setare
     * tehnică pentru AWB și n-ar trebui să schimbe prețul pe tăcute.
     *
     * Atenție: asta spune doar că opțiunea e disponibilă în checkout. Dacă o
     * comandă chiar merge la FANbox decide clientul, la fiecare comandă.
     */
    public static function ofertaFanbox(array $settings): bool
    {
        return (string) ($settings['shipping_fixed_fanbox_enabled'] ?? '0') === '1';
    }

    /** Denumirea veche, păstrată pentru apelurile existente. */
    public static function livrareLaFanbox(array $settings): bool
    {
        return self::ofertaFanbox($settings);
    }

    /**
     * Prețul de transport, când prețurile fixe sunt active. Întoarce null dacă
     * setarea e oprită (atunci se folosește tariful curierului, ca până acum).
     *
     * `$db` e necesar doar pentru lista de localități cu km suplimentari; fără
     * el se întoarce prețul de bază, fără taxa suplimentară.
     */
    public static function pret(
        ?PDO $db,
        array $settings,
        string $judet,
        string $localitate,
        bool $laFanbox = false
    ): ?float {
        if (!self::esteActiv($settings)) {
            return null;
        }
        // Prețul de FANbox se aplică doar comenzilor livrate acolo, nu tuturor
        // comenzilor dintr-un magazin care oferă și această variantă.
        if ($laFanbox && self::ofertaFanbox($settings)) {
            return round(max(0.0, (float) ($settings['shipping_fixed_fanbox'] ?? 0)), 2);
        }

        $pret = max(0.0, (float) ($settings['shipping_fixed_base'] ?? 0));
        if (self::areKmSuplimentari($db, $judet, $localitate)) {
            $pret += max(0.0, (float) ($settings['shipping_fixed_extra_km'] ?? 0));
        }
        return round($pret, 2);
    }

    /** Prețul de bază, pentru sumarele în care nu știm încă localitatea. */
    public static function pretDeBaza(array $settings, bool $laFanbox = false): ?float
    {
        if (!self::esteActiv($settings)) {
            return null;
        }
        if ($laFanbox && self::ofertaFanbox($settings)) {
            return round(max(0.0, (float) ($settings['shipping_fixed_fanbox'] ?? 0)), 2);
        }
        return round(max(0.0, (float) ($settings['shipping_fixed_base'] ?? 0)), 2);
    }

    /** Localitatea e în lista FAN de localități cu km suplimentari? */
    public static function areKmSuplimentari(
        ?PDO $db,
        string $judet,
        string $localitate
    ): bool {
        if (!$db instanceof PDO) {
            return false;
        }
        $localitateNorm = self::normalizeaza($localitate);
        if ($localitateNorm === '') {
            return false;
        }
        $judetNorm = self::normalizeazaJudet($judet);

        try {
            // Cu județul știut, se caută doar în județul lui. Înainte, dacă nu
            // se găsea acolo, se căuta și numai după nume, în toată țara: orașul
            // Galați lua taxa unui sat cu același nume din alt județ. Fiecare
            // rând din listă are județ (importul le sare pe cele fără).
            if ($judetNorm !== '') {
                $stmt = $db->prepare(
                    'SELECT 1 FROM fan_localities_extra_km
                     WHERE county_norm = :county AND locality_norm = :locality
                     LIMIT 1'
                );
                $stmt->execute(['county' => $judetNorm, 'locality' => $localitateNorm]);
                return $stmt->fetchColumn() !== false;
            }
            // Județul lipsește din adresă: ne bazăm doar pe localitate.
            $stmt = $db->prepare(
                'SELECT 1 FROM fan_localities_extra_km WHERE locality_norm = :locality LIMIT 1'
            );
            $stmt->execute(['locality' => $localitateNorm]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            // Lista nu a fost încă importată — nu blocăm comanda pentru atât.
            return false;
        }
    }

    /**
     * Aceeași normalizare ca la scrierea listei (import din fișier și
     * sincronizarea din FAN). Înainte, punctuația se ștergea aici, dar devenea
     * spațiu la import: „Sat.Deleni" dădea două chei diferite și nu se potrivea.
     */
    private static function normalizeaza(string $value): string
    {
        return FanNomenclator::normalizeaza($value);
    }

    private static function normalizeazaJudet(string $judet): string
    {
        return FanNomenclator::normalizeazaJudet($judet);
    }
}
