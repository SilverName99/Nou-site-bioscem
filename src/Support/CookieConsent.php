<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Consimțământul pentru cookies (GDPR / ePrivacy).
 *
 * Regula legală: scripturile de urmărire (Analytics, Tag Manager, Ads,
 * Clarity) nu au voie să pornească înainte ca vizitatorul să accepte. De
 * aceea decizia se ia pe server: dacă nu există consimțământ, codurile nici
 * nu ajung în pagină. După click pe „Accept", pagina se reîncarcă și abia
 * atunci sunt trimise.
 */
final class CookieConsent
{
    public const COOKIE = 'bioscem_cookie_consent';

    /** Toate cookie-urile, inclusiv cele de analiză și marketing. */
    public const TOATE = 'all';

    /** Doar cele strict necesare funcționării site-ului. */
    public const NECESARE = 'necessary';

    /** Cât timp ținem minte alegerea (6 luni, recomandarea autorităților). */
    public const DURATA = 180 * 24 * 60 * 60;

    /** Alegerea vizitatorului, sau '' dacă încă nu a ales. */
    public static function alegere(): string
    {
        $valoare = trim((string) ($_COOKIE[self::COOKIE] ?? ''));
        return in_array($valoare, [self::TOATE, self::NECESARE], true) ? $valoare : '';
    }

    /** A ales deja ceva? (dacă nu, se afișează bannerul) */
    public static function aRaspuns(): bool
    {
        return self::alegere() !== '';
    }

    /** Are voie site-ul să încarce scripturi de analiză și marketing? */
    public static function permiteUrmarire(): bool
    {
        return self::alegere() === self::TOATE;
    }

    /**
     * Eticheta pusă în cheia de cache a paginii. Fără ea, o pagină salvată
     * pentru un vizitator care a acceptat ar fi servită mai departe unuia
     * care a refuzat — cu tot cu scripturile de urmărire.
     */
    public static function cheieCache(): string
    {
        $alegere = self::alegere();
        return $alegere === '' ? 'nou' : $alegere;
    }
}
