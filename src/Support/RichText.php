<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Redare sigură pentru conținut HTML introdus din admin sau importat
 * (descrieri produse migrate din WordPress/WooCommerce).
 *
 * Conținutul este de încredere „administrativă", dar poate proveni dintr-un
 * import extern, deci eliminăm vectorii activi (script/iframe/handlere inline)
 * și păstrăm formatarea editorială (paragrafe, liste, linkuri, tabele).
 */
final class RichText
{
    private const BLOCKED_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base',
    ];

    /**
     * Decide dacă textul conține markup HTML real (nu doar caractere < izolate).
     */
    public static function looksLikeHtml(string $value): bool
    {
        return (bool) preg_match('/<(' . implode('|', [
            'p', 'br', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li',
            'h[1-6]', 'a', 'img', 'table', 'tr', 'td', 'th', 'tbody', 'thead',
            'blockquote', 'hr', 'figure', 'figcaption', 'small', 'sup', 'sub',
        ]) . ')(\s[^>]*)?\/?>/i', $value);
    }

    /**
     * Elimină elementele active dintr-un fragment HTML.
     */
    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Elimină complet tag-urile periculoase, împreună cu conținutul lor.
        foreach (self::BLOCKED_TAGS as $tag) {
            $html = (string) preg_replace(
                '#<' . $tag . '\b[^>]*>.*?</' . $tag . '\s*>#is',
                '',
                $html
            );
            // Variante auto-închise sau neînchise.
            $html = (string) preg_replace('#</?' . $tag . '\b[^>]*>#i', '', $html);
        }

        // Elimină handlerele inline (onclick, onerror, onload etc.).
        $html = (string) preg_replace('#\son[a-z-]+\s*=\s*"[^"]*"#i', '', $html);
        $html = (string) preg_replace("#\son[a-z-]+\s*=\s*'[^']*'#i", '', $html);
        $html = (string) preg_replace('#\son[a-z-]+\s*=\s*[^\s>]+#i', '', $html);

        // Elimină URL-urile executabile din href/src.
        $html = (string) preg_replace(
            '#\s(href|src|xlink:href)\s*=\s*("|\')\s*(javascript|vbscript|data:text/html)[^"\']*\2#i',
            '',
            $html
        );

        return trim($html);
    }

    /**
     * Randează conținut editorial: HTML curățat dacă e markup,
     * altfel text simplu escapat cu păstrarea rândurilor noi.
     */
    public static function render(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (self::looksLikeHtml($value)) {
            return self::sanitize($value);
        }

        return nl2br(htmlspecialchars($value, ENT_QUOTES));
    }
}
