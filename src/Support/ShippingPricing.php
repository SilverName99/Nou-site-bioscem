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

    /** Localitatea e în lista importată de localități cu km suplimentari? */
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
            // Județul poate lipsi din adresă; atunci ne bazăm doar pe localitate.
            if ($judetNorm !== '') {
                $stmt = $db->prepare(
                    'SELECT 1 FROM fan_localities_extra_km
                     WHERE county_norm = :county AND locality_norm = :locality
                     LIMIT 1'
                );
                $stmt->execute(['county' => $judetNorm, 'locality' => $localitateNorm]);
                if ($stmt->fetchColumn() !== false) {
                    return true;
                }
            }
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

    /** Aceeași normalizare ca la importul listei FAN (fără diacritice, litere mici). */
    private static function normalizeaza(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'ă' => 'a',
            'â' => 'a',
            'î' => 'i',
            'ș' => 's',
            'ş' => 's',
            'ț' => 't',
            'ţ' => 't',
        ]);
        $value = preg_replace('/[^a-z0-9\s\-]/', '', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return trim($value);
    }

    private static function normalizeazaJudet(string $judet): string
    {
        $norm = self::normalizeaza($judet);
        $norm = (string) preg_replace('/^(judetul|judet|municipiul)\s+/', '', $norm);
        if (str_contains($norm, 'bucuresti') || $norm === 'b') {
            return 'bucuresti';
        }
        return trim($norm);
    }
}
