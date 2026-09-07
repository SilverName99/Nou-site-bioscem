<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decide dacă un produs cere alegerea unei oferte cu dată de expirare (BBD)
 * înainte de a intra în coș.
 *
 * Cardurile din magazin verificau doar dacă `bbd_entries_json` e text gol. Un
 * produs cu bifa pusă, dar fără nicio ofertă adăugată, are acolo „[]" — text
 * care nu e gol, deci cardul arăta „Alege oferta" pentru un produs care se
 * putea adăuga direct în coș. Aici se numără efectiv ofertele.
 */
final class BbdOferte
{
    /** @param array<string, mixed> $product */
    public static function cereAlegere(array $product): bool
    {
        // Controllerul o calculează deja pentru produsele pe care le pregătește;
        // când e prezentă, ea decide.
        if (array_key_exists('requires_bbd_selection', $product)) {
            return (bool) $product['requires_bbd_selection'];
        }
        if ((int) ($product['bbd_enabled'] ?? 0) !== 1) {
            return false;
        }
        return self::areOferte($product);
    }

    /** @param array<string, mixed> $product */
    private static function areOferte(array $product): bool
    {
        $entries = $product['bbd_entries'] ?? null;
        if (is_array($entries)) {
            return $entries !== [];
        }
        $raw = trim((string) ($product['bbd_entries_json'] ?? ''));
        if ($raw === '') {
            return false;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && $decoded !== [];
    }
}
