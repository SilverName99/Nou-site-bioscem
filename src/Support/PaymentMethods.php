<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Metodele de plată ale comenzilor, într-un singur loc.
 *
 * Lista metodelor „cu cardul" era scrisă de mână în vreo cincisprezece locuri
 * (checkout, ERP, filtrele și etichetele din admin). O metodă nouă uitată într-
 * unul dintre ele însemna, de pildă, o comandă cu cardul trimisă în ERP înainte
 * de plată sau o fereastră de comandă care schimba singură metoda în „ramburs"
 * la salvare. De acum toate citesc de aici.
 */
final class PaymentMethods
{
    /** Plata online cu cardul: comanda se confirmă abia după încasare (sau autorizare, la BT). */
    public const CARD = ['stripe', 'euplatesc', 'btipay'];

    /** Banca Transilvania iPay (cu rate și puncte STAR, pe pagina băncii). */
    public const BT_IPAY = 'btipay';

    /** Metoda e una dintre plățile online cu cardul? */
    public static function esteCard(string $metoda): bool
    {
        return in_array(strtolower(trim($metoda)), self::CARD, true);
    }

    /**
     * Lista de mai sus, gata de pus într-un `IN (...)` SQL. Valorile sunt
     * constante din cod (doar litere mici și liniuță jos), nu vin de la utilizator.
     */
    public static function cardSql(): string
    {
        $bucati = [];
        foreach (self::CARD as $metoda) {
            if (preg_match('/^[a-z_]+$/', $metoda) === 1) {
                $bucati[] = "'" . $metoda . "'";
            }
        }
        return implode(', ', $bucati);
    }
}
