<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Jeton anti-CSRF pentru acțiunile din administrare care mută bani (încasare,
 * eliberare, rambursare BT) și pentru formularele de plăți.
 *
 * Restul panoului se bazează pe cookie-ul de sesiune `SameSite=Lax`, care nu
 * pleacă la un POST venit de pe alt site. Pentru operațiile cu bani adăugăm și
 * un jeton per sesiune, verificat în timp constant.
 */
final class AdminCsrf
{
    private const CHEIE = 'admin_csrf_token';
    public const CAMP = '_csrf';

    public static function token(): string
    {
        $token = $_SESSION[self::CHEIE] ?? '';
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CHEIE] = $token;
        }
        return $token;
    }

    /** Jetonul trimis (câmp de formular sau antet `X-CSRF-Token`) e cel al sesiunii? */
    public static function valid(): bool
    {
        $asteptat = $_SESSION[self::CHEIE] ?? '';
        if (!is_string($asteptat) || strlen($asteptat) !== 64) {
            return false;
        }
        $primit = $_POST[self::CAMP] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return is_string($primit) && $primit !== '' && hash_equals($asteptat, $primit);
    }

    public static function camp(): string
    {
        return '<input type="hidden" name="' . self::CAMP . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }
}
