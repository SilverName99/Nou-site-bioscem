<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Logo-ul din antet ca WebP mic, cu PNG-ul original ca rezervă.
 *
 * Antetul e HTML salvat în baza de date și cere /uploads/gallery/bioscem-logo-2.png
 * (977×240, circa 154 KB), afișat la 52 px înălțime. La afișare, <img>-ul lui
 * e învelit într-un <picture> cu două variante WebP făcute o singură dată din
 * acel PNG și puse în cod (public/assets/img/: 212×52 pentru ecrane obișnuite,
 * 423×104 pentru ecrane dense, câțiva KB fiecare). Browserele fără WebP și
 * roboții care nu citesc <picture> primesc PNG-ul de până acum, care rămâne
 * neatins pe server. Nimic nu se generează la cerere.
 *
 * Dacă PNG-ul din galerie e înlocuit cu alt logo (alte dimensiuni decât
 * 977×240), variantele WebP nu-i mai corespund: atunci antetul rămâne exact cum e.
 */
final class LogoAntet
{
    /** Logo-ul din antet, așa cum apare în HTML-ul salvat. */
    private const PNG = '/uploads/gallery/bioscem-logo-2.png';

    /** Dimensiunile PNG-ului din care s-au făcut variantele WebP (lățime, înălțime). */
    private const PNG_DIMENSIUNI = [977, 240];

    private const WEBP_1X = '/assets/img/bioscem-logo-2-212.webp';
    private const WEBP_2X = '/assets/img/bioscem-logo-2-423.webp';

    public static function webp(string $html, ?string $radacinaPublic = null): string
    {
        if ($html === '' || !str_contains($html, self::PNG) || stripos($html, '<picture') !== false) {
            return $html;
        }
        $radacinaPublic ??= dirname(__DIR__, 2) . '/public';
        $png = $radacinaPublic . self::PNG;
        if (!is_file($radacinaPublic . self::WEBP_1X) || !is_file($radacinaPublic . self::WEBP_2X) || !is_file($png)) {
            return $html;
        }
        $dimensiuni = @getimagesize($png);
        if (!is_array($dimensiuni) || [(int) $dimensiuni[0], (int) $dimensiuni[1]] !== self::PNG_DIMENSIUNI) {
            return $html;
        }
        $versiune = (string) @filemtime($radacinaPublic . self::WEBP_2X);
        $sursa = '<source type="image/webp" srcset="'
            . self::WEBP_1X . '?v=' . $versiune . ' 1x, '
            . self::WEBP_2X . '?v=' . $versiune . ' 2x">';
        return (string) preg_replace_callback(
            '#<img\b[^>]*\bsrc\s*=\s*(["\'])' . preg_quote(self::PNG, '#') . '\1[^>]*>#i',
            // display:contents: <picture> nu face o cutie proprie, deci <img>-ul se
            // așază exact ca înainte (aceleași reguli CSS din antet îl prind).
            static fn (array $m): string => '<picture style="display:contents">' . $sursa . $m[0] . '</picture>',
            $html,
            1
        );
    }
}
