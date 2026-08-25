<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Chatul live (tawk.to) — bulina din colț.
 *
 * Widgetul e găzduit de tawk.to, firmă din Statele Unite, iar conversația
 * pleacă acolo. Prin urmare nu e un script oarecare: intră sub consimțământ
 * ca oricare alt cod de terță parte, iar decizia se ia pe server, ca la
 * scripturile de urmărire — dacă vizitatorul n-a acceptat, codul nici nu
 * ajunge în pagină.
 *
 * Excepția e deliberată și se alege din admin: un magazin poate considera
 * chatul „strict necesar", fiindcă e un serviciu pe care clientul îl cere
 * el însuși. Implicit stă pe varianta prudentă.
 */
final class ChatLive
{
    /** Chatul pornește doar după „Accept toate". */
    public const CONSIMTAMANT_NECESAR = '1';

    /** Chatul pornește oricum (considerat strict necesar). */
    public const CONSIMTAMANT_OPTIONAL = '0';

    /** Pozițiile acceptate de tawk.to pentru bulină. */
    public const POZITII = ['br', 'bl'];

    /** Distanța implicită de la marginea de jos, în pixeli. */
    public const OFFSET_IMPLICIT = 18;

    /**
     * De la ce înălțime bulina trece deja peste coșul plutitor. Coșul e un
     * buton de ~50 px, așezat la 18 px de marginea de jos; peste pragul ăsta
     * bulina e deasupra lui și n-are rost să mai mutăm nimic.
     */
    public const PRAG_DEGAJARE_COS = 80;

    /**
     * Identificatorii tawk.to sunt hexazecimali (24 de caractere pentru
     * proprietate). Curățăm orice altceva: dacă cineva lipește tot linkul
     * de instalare, luăm doar ce e folosibil, fără să injectăm în pagină
     * un text pe care nu l-am verificat.
     */
    public static function curataId(string $brut, int $maxim = 40): string
    {
        $curat = preg_replace('/[^A-Za-z0-9_]/', '', trim($brut)) ?? '';
        return substr($curat, 0, $maxim);
    }

    /** Poziția bulinei, normalizată. */
    public static function pozitie(array $settings): string
    {
        $valoare = trim((string) ($settings['tawk_position'] ?? 'br'));
        return in_array($valoare, self::POZITII, true) ? $valoare : 'br';
    }

    /**
     * Cât de sus stă bulina față de marginea de jos, în pixeli. Se limitează
     * la ceva rezonabil: o valoare uriașă ar trimite bulina în afara
     * ecranului pe telefon, unde n-ar mai fi de găsit.
     */
    public static function offsetY(array $settings): int
    {
        $valoare = (int) ($settings['tawk_offset_y'] ?? self::OFFSET_IMPLICIT);
        return max(0, min(400, $valoare));
    }

    /** Chatul e pornit din admin și are identificatorii completați? */
    public static function configurat(array $settings): bool
    {
        return (string) ($settings['tawk_enabled'] ?? '0') === '1'
            && self::curataId((string) ($settings['tawk_property_id'] ?? '')) !== '';
    }

    /**
     * Se încarcă widgetul pentru vizitatorul curent?
     *
     * Două condiții: să fie configurat și, dacă cere consimțământ, acesta
     * să fi fost dat. Verificarea consimțământului se face pe alegerea
     * deja luată, nu pe una presupusă.
     */
    public static function activ(array $settings): bool
    {
        if (!self::configurat($settings)) {
            return false;
        }
        $cereConsimtamant = (string) ($settings['tawk_requires_consent'] ?? '1') !== self::CONSIMTAMANT_OPTIONAL;
        return !$cereConsimtamant || CookieConsent::permiteUrmarire();
    }

    /**
     * Bulina de chat și coșul plutitor se bat pe același colț. Când cad în
     * aceeași parte, coșul urcă deasupra chatului — mutăm coșul, care e al
     * nostru, nu widgetul, care e al lor.
     *
     * Excepția: dacă bulina a fost ridicată manual destul cât să treacă peste
     * coș, nu mai mutăm nimic. Altfel s-ar depărta amândouă și ar rămâne o
     * gaură între ele.
     */
    public static function suprapunePestecos(array $settings): bool
    {
        if (!self::activ($settings)) {
            return false;
        }
        $parteCos = (string) ($settings['floating_cart_position'] ?? 'right') === 'left' ? 'bl' : 'br';
        if ($parteCos !== self::pozitie($settings)) {
            return false;
        }
        return self::offsetY($settings) < self::PRAG_DEGAJARE_COS;
    }

    /**
     * Semnătura care dovedește lui tawk.to că emailul chiar e al clientului
     * logat la noi. Fără ea, oricine poate deschide chatul pretinzând orice
     * adresă. Cheia se ia din contul tawk.to (Administration → Channels →
     * Chat Widget → Secure Mode) și e opțională: fără cheie, trimitem
     * numele și emailul nesemnate, ca orice widget obișnuit.
     */
    public static function semnatura(array $settings, string $email): string
    {
        $cheie = trim((string) ($settings['tawk_api_key'] ?? ''));
        if ($cheie === '' || $email === '') {
            return '';
        }
        return hash_hmac('sha256', $email, $cheie);
    }
}
