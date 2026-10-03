<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Starea modulului „Magazin online” din ERP-ul ANDAXI, așa cum a aflat-o
 * site-ul ultima oară.
 *
 * Modulul e cel care lasă comenzile de pe site să intre în ERP. Cât e oprit
 * sau se oprește, ERP-ul refuză comenzile NOI cu 403 (cu modulul oprit de tot,
 * și modificările celor trimise); anulările, retururile, notificările și
 * AWB-urile merg mai departe. Site-ul află starea din două
 * locuri: din refuzul unei comenzi și din ping-ul pe care îl face cron-ul. O
 * ține în setări, ca adminul să arate bannerul fără să întrebe ERP-ul la
 * fiecare pagină.
 */
final class ErpModul
{
    /** Cheia modulului în ERP, așa cum vine în refuz (câmpul `module`). */
    public const CHEIE = 'magazin_online';

    public const PORNIT = 'pornit';
    public const SE_OPRESTE = 'se_opreste';
    public const OPRIT = 'oprit';

    /**
     * Începutul mesajului pus pe comenzile ținute pe site din cauza modulului.
     * După el le găsește repornirea, ca să le scoată din pauză.
     */
    private const PREFIX = 'Modulul „Magazin online” ';

    private const SETARE_STARE = 'erp_modul_magazin';
    private const SETARE_LA = 'erp_modul_magazin_la';

    /**
     * Refuzul pe scurt, purtat de excepție: îl vede operatorul și la butoanele
     * din Setări → ERP ANDAXI (greutăți, test), nu doar pe comenzi.
     */
    public static function mesajScurt(string $stare): string
    {
        return $stare === self::SE_OPRESTE
            ? self::PREFIX . 'se oprește în ERP: nu mai primește comenzi noi de pe site.'
            : self::PREFIX . 'e oprit în ERP. Pornirea se cere furnizorului programului ERP.';
    }

    /**
     * Mesajul pus pe comanda ținută aici. După începutul lui o găsește repornirea.
     *
     * `$modificare`: comanda ajunsese deja în ERP, iar refuzul e al corecturii
     * ei. Cu modulul oprit, ERP-ul nu mai primește nici modificări (rescrierea
     * ar reface rezervări pe un modul stins); „comenzile noi” n-ar spune ce
     * s-a întâmplat.
     */
    public static function mesaj(string $stare, bool $modificare = false): string
    {
        if ($modificare && $stare === self::OPRIT) {
            return self::PREFIX . 'e oprit în ERP: modificarea comenzii n-a intrat acolo. '
                . 'Pleacă singură după ce modulul e pornit din nou.';
        }
        return $stare === self::SE_OPRESTE
            ? self::PREFIX . 'se oprește în ERP: comenzile noi nu mai intră acolo, cele trimise deja se lucrează normal. '
                . 'Comanda rămâne aici, pe site, și pleacă singură dacă modulul e pornit din nou.'
            : self::PREFIX . 'e oprit în ERP: comenzile noi nu mai intră acolo. '
                . 'Comanda rămâne aici, pe site, și pleacă singură după ce modulul e pornit din nou. '
                . 'Pornirea se cere furnizorului programului ERP.';
    }

    /**
     * Starea din răspunsul ping-ului, sau null când ERP-ul nu o trimite (o
     * versiune dinaintea modulului).
     */
    public static function dinPing(array $ping): ?string
    {
        $stare = (string) ($ping['modulMagazin'] ?? '');
        return self::valida($stare) ? $stare : null;
    }

    /** Ultima stare aflată, sau '' dacă n-a aflat-o niciodată. */
    public static function stare(array $settings): string
    {
        $stare = (string) ($settings[self::SETARE_STARE] ?? '');
        return self::valida($stare) ? $stare : '';
    }

    /**
     * Reține starea aflată din ERP. La pornire scoate din pauză comenzile
     * ținute aici cât modulul a fost oprit, ca să plece chiar la rularea asta
     * a cron-ului, nu peste o oră.
     */
    public static function noteaza(PDO $db, string $stare): void
    {
        if (!self::valida($stare)) {
            return;
        }
        try {
            Settings::save($db, [
                self::SETARE_STARE => $stare,
                self::SETARE_LA => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // Starea e informativă; nu are voie să oprească trimiterea.
        }
        if ($stare !== self::PORNIT) {
            return;
        }
        try {
            $db->prepare(
                "UPDATE orders SET erp_next_retry_at = NULL
                  WHERE erp_status = 'pending' AND erp_last_error LIKE :prefix"
            )->execute(['prefix' => self::PREFIX . '%']);
        } catch (Throwable) {
            // Fără ea, comenzile pleacă tot singure, doar la ora lor.
        }
    }

    /**
     * Textul bannerului din admin, sau null când n-are ce spune: modul pornit,
     * stare necunoscută sau integrare oprită pe site.
     */
    public static function banner(?PDO $db, array $settings): ?string
    {
        if ((string) ($settings['erp_enabled'] ?? '0') !== '1') {
            return null;
        }
        $stare = self::stare($settings);
        if ($stare !== self::SE_OPRESTE && $stare !== self::OPRIT) {
            return null;
        }

        $tinute = 0;
        if ($db instanceof PDO) {
            try {
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM orders
                      WHERE deleted_at IS NULL AND erp_status = 'pending' AND erp_last_error LIKE :prefix"
                );
                $stmt->execute(['prefix' => self::PREFIX . '%']);
                $tinute = (int) $stmt->fetchColumn();
            } catch (Throwable) {
                // Numărătoarea e doar de informare.
            }
        }

        $text = $stare === self::SE_OPRESTE
            ? 'Modulul „Magazin online” se oprește în ERP: comenzile noi nu mai intră acolo. '
                . 'Cele trimise deja se aprobă, se anulează și se returnează normal.'
            : 'Modulul „Magazin online” e oprit în ERP: comenzile noi nu mai intră acolo. '
                . 'Anulările și retururile comenzilor trimise deja ajung mai departe în ERP.';

        if ($tinute === 1) {
            $text .= ' O comandă așteaptă aici și pleacă singură când modulul e pornit din nou.';
        } elseif ($tinute > 1) {
            $rest = $tinute % 100;
            $de = ($rest === 0 || $rest >= 20) ? 'de ' : '';
            $text .= ' ' . $tinute . ' ' . $de . 'comenzi așteaptă aici și pleacă singure când modulul e pornit din nou.';
        } else {
            $text .= ' Comenzile noi rămân aici și pleacă singure când modulul e pornit din nou.';
        }
        if ($stare === self::OPRIT) {
            $text .= ' Pornirea se cere furnizorului programului ERP.';
        }

        $la = trim((string) ($settings[self::SETARE_LA] ?? ''));
        $cand = $la !== '' ? strtotime($la) : false;
        if ($cand !== false) {
            $text .= ' (verificat la ' . date('d.m.Y H:i', $cand) . ')';
        }
        return $text;
    }

    private static function valida(string $stare): bool
    {
        return in_array($stare, [self::PORNIT, self::SE_OPRESTE, self::OPRIT], true);
    }
}
