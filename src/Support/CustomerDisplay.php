<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Numele afișat al unui client.
 *
 * Conturile create doar cu email + parolă primesc în baza de date numele
 * generic „Client Nou". Peste tot unde arătăm utilizatorul (contul meu,
 * meniul din header, emailuri), un asemenea nume generat se înlocuiește cu
 * adresa de email — identificatorul real al contului.
 */
final class CustomerDisplay
{
    /** Numele generice puse automat la înregistrare, nu alese de client. */
    private const NUME_GENERATE = ['client nou', 'client', 'nou'];

    public static function esteNumeGenerat(string $firstName, string $lastName): bool
    {
        $complet = trim(mb_strtolower(trim($firstName) . ' ' . trim($lastName)));
        return $complet === '' || in_array($complet, self::NUME_GENERATE, true);
    }

    /** Numele de afișat: numele real sau, dacă e generat, adresa de email. */
    public static function nume(string $firstName, string $lastName, string $email): string
    {
        if (self::esteNumeGenerat($firstName, $lastName)) {
            $email = trim($email);
            if ($email !== '' && !str_ends_with(strtolower($email), '@local.invalid')) {
                return $email;
            }
            return 'Client';
        }
        return trim(trim($firstName) . ' ' . trim($lastName));
    }

    /** Inițialele pentru avatar: din nume sau, dacă e generat, din email. */
    public static function initiale(string $firstName, string $lastName, string $email): string
    {
        if (!self::esteNumeGenerat($firstName, $lastName)) {
            $initiale = trim(
                mb_strtoupper(mb_substr(trim($firstName), 0, 1))
                . mb_strtoupper(mb_substr(trim($lastName), 0, 1))
            );
            if ($initiale !== '') {
                return $initiale;
            }
        }
        $localPart = (string) strtok(trim($email), '@');
        $initiale = mb_strtoupper(mb_substr($localPart, 0, 2));
        return $initiale !== '' ? $initiale : 'CL';
    }
}
