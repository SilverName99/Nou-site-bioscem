<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Slug-urile din adresele de marcă, etichetă și categorie, într-un singur loc,
 * ca paginile, linkurile și sitemap-ul să ajungă la aceeași formă.
 */
final class Slug
{
    /** Marca și eticheta: „Vitamina D” → „vitamina-d” (ca pe site, de la început). */
    public static function simplu(string $valoare): string
    {
        $valoare = mb_strtolower(trim($valoare));
        $valoare = strtr($valoare, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $valoare = (string) preg_replace('/[^a-z0-9]+/', '-', $valoare);
        return trim($valoare, '-');
    }

    /** Categoria, când nu are slug salvat: „Tiroidă și Menopauză” → „tiroida-si-menopauza”. */
    public static function categorie(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($value, '-');
    }
}
