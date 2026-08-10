<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detectează secțiunile dintr-o descriere de produs migrată din WordPress.
 *
 * Descrierile au forma tipică WooCommerce:
 *   <p><strong>Caracteristici</strong>:<br />• Disc de 4,5 cm...</p>
 *   <p><strong>Mod de utilizare</strong>:<br />• discul se poartă...</p>
 *
 * Un titlu de secțiune este recunoscut când:
 *   - paragraful începe cu <strong>/<b> urmat imediat de „:”; sau
 *   - paragraful conține DOAR text îngroșat; sau
 *   - blocul este un <h1>-<h6>.
 *
 * Regula cu „:” este importantă: elimină cazurile în care îngroșarea este
 * doar accent în frază (ex. „<strong>Compensează</strong> efectele...”),
 * care nu sunt titluri de secțiune.
 */
final class ProductSections
{
    /** Blocuri HTML după care se face segmentarea descrierii. */
    private const BLOCK_TAGS = 'p|h[1-6]|ul|ol|div|hr|figure|table|blockquote|pre';

    /**
     * Împarte descrierea în introducere + secțiuni.
     *
     * @return array{intro:string, sections:list<array{label:string, content_html:string}>}
     */
    public static function parse(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return ['intro' => '', 'sections' => []];
        }

        $chunks = preg_split('/(?=<(?:' . self::BLOCK_TAGS . ')\b)/i', $html, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chunks) || $chunks === []) {
            return ['intro' => $html, 'sections' => []];
        }

        $intro = '';
        $sections = [];
        $currentLabel = null;
        $currentContent = '';

        foreach ($chunks as $chunk) {
            $heading = self::matchHeading($chunk);

            if ($heading === null) {
                if ($currentLabel === null) {
                    $intro .= $chunk;
                } else {
                    $currentContent .= $chunk;
                }
                continue;
            }

            // Închide secțiunea anterioară.
            if ($currentLabel !== null) {
                $sections[] = [
                    'label' => $currentLabel,
                    'content_html' => trim($currentContent),
                ];
            }

            $currentLabel = $heading['label'];
            $currentContent = $heading['remainder'];
        }

        if ($currentLabel !== null) {
            $sections[] = [
                'label' => $currentLabel,
                'content_html' => trim($currentContent),
            ];
        }

        // Secțiunile fără conținut real nu ne interesează.
        $sections = array_values(array_filter($sections, static function (array $section): bool {
            return trim(strip_tags($section['content_html'])) !== '';
        }));

        return ['intro' => trim($intro), 'sections' => $sections];
    }

    /**
     * Verifică dacă un bloc începe o secțiune nouă.
     *
     * @return array{label:string, remainder:string}|null
     */
    private static function matchHeading(string $chunk): ?array
    {
        // <h1>..<h6>
        if (preg_match('#^<(h[1-6])\b[^>]*>(.*?)</\1\s*>(.*)$#is', $chunk, $m)) {
            $label = self::cleanLabel($m[2]);
            return $label === '' ? null : ['label' => $label, 'remainder' => trim($m[3])];
        }

        // <p><strong>Titlu</strong>: restul... sau <p><strong>Titlu:</strong> restul...
        if (preg_match('#^<p\b[^>]*>\s*<(strong|b)\b[^>]*>(.*?)</\1\s*>(.*)$#is', $chunk, $m)) {
            $labelRaw = $m[2];
            $after = $m[3];

            $labelText = trim(html_entity_decode(strip_tags($labelRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $restText = trim((string) preg_replace('#</p>\s*$#i', '', $after));

            // Paragraf format doar din text îngroșat: titlu fără conținut propriu.
            $isBoldOnlyParagraph = trim(strip_tags($restText)) === '' && $restText === '';

            // Două puncte fie la finalul textului îngroșat, fie imediat după el.
            $colonInsideLabel = str_ends_with($labelText, ':');
            $colonAfterLabel = (bool) preg_match('/^\s*:/', $after);

            if (!$colonInsideLabel && !$colonAfterLabel && !$isBoldOnlyParagraph) {
                return null;
            }

            $label = self::cleanLabel($labelRaw);
            if ($label === '') {
                return null;
            }

            // Curăță „:” și <br /> de la începutul conținutului.
            $remainder = (string) preg_replace('/^\s*:\s*/', '', $after);
            $remainder = (string) preg_replace('#^\s*(?:<br\s*/?>\s*)+#i', '', $remainder);
            $remainder = trim($remainder);

            if ($remainder === '' || trim(strip_tags($remainder)) === '') {
                return ['label' => $label, 'remainder' => ''];
            }

            // Restul include deja </p>; îl repunem într-un paragraf propriu.
            return ['label' => $label, 'remainder' => '<p>' . $remainder];
        }

        return null;
    }

    /**
     * Normalizează textul unui titlu detectat.
     */
    public static function cleanLabel(string $raw): string
    {
        $label = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = str_replace("\xC2\xA0", ' ', $label);
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        $label = rtrim($label, " :;.-–—");

        // Titlurile foarte lungi sunt fraze, nu titluri de secțiune.
        if ($label === '' || mb_strlen($label) > 60) {
            return '';
        }

        return $label;
    }

    /**
     * Cheia de grupare (case/diacritice-insensitive) pentru același titlu.
     */
    public static function groupKey(string $label): string
    {
        $key = mb_strtolower($label, 'UTF-8');
        $key = strtr($key, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
        if (is_string($ascii) && $ascii !== '') {
            $key = $ascii;
        }
        $key = (string) preg_replace('/[^a-z0-9]+/', ' ', $key);
        return trim($key);
    }

    /**
     * Cheia tehnică folosită în `product_extra_fields.field_key`
     * (apare în template ca {{field_<cheie>}}).
     */
    public static function fieldKey(string $label): string
    {
        $key = str_replace(' ', '_', self::groupKey($label));
        $key = (string) preg_replace('/_+/', '_', $key);
        $key = trim($key, '_');
        return mb_substr($key, 0, 120);
    }

    /**
     * Titluri identice ca sens, scrise diferit de la produs la produs.
     * Ordinea contează: prima potrivire câștigă.
     */
    private const PREFIX_RULES = [
        // Păstrare - verificat înaintea „utilizare”, ca „mod de păstrare și
        // utilizare” să ajungă la păstrare, nu la utilizare.
        'mod de pastrare' => 'mod_de_pastrare',
        'conditii de pastrare' => 'mod_de_pastrare',
        'mod de depozitare' => 'mod_de_pastrare',
        'conditii de depozitare' => 'mod_de_pastrare',

        // Beneficii - „Beneficiile SILICIUM G7”, „De ce să alegi X”, „Importanța Y”.
        'beneficii' => 'beneficii',
        'beneficiile' => 'beneficii',
        'de ce sa ' => 'beneficii',
        'importanta ' => 'beneficii',

        // Utilizare.
        'mod de utilizare' => 'mod_de_utilizare',
        'mod de administrare' => 'mod_de_utilizare',
        'mod de folosire' => 'mod_de_utilizare',
        'mod de preparare' => 'mod_de_utilizare',
        'instructiuni de utilizare' => 'mod_de_utilizare',
        'instructiuni de folosire' => 'mod_de_utilizare',
        'recomandare de utilizare' => 'mod_de_utilizare',
        'recomandari de utilizare' => 'mod_de_utilizare',
        'recomandari si utilizare' => 'mod_de_utilizare',

        // Doză.
        'doza' => 'doza_recomandata',

        // Ingrediente („Ingrediente active”, „Ingrediente/capsulă”, „Alte ingrediente”).
        'ingrediente' => 'ingrediente',
        'alte ingrediente' => 'ingrediente',

        // Avertismente.
        'atentionari' => 'atentionari',
        'precautii' => 'atentionari',
        'contraindicatii' => 'atentionari',
        'masuri de precautie' => 'atentionari',

        // Valori nutriționale.
        'valori nutritionale' => 'valori_nutritionale',
        'informatii nutritionale' => 'valori_nutritionale',

        // Notă / observații.
        'nota' => 'nota',
        'observatii' => 'observatii',

        // Caracteristici („Caracteristici ale produsului”).
        'caracteristici' => 'caracteristici',
    ];

    /** Titluri care se potrivesc exact (nu ca prefix). */
    private const EXACT_RULES = [
        'produs de' => 'producator',
        'producator' => 'producator',
        'tara de origine' => 'producator',
        'prezentare' => 'ambalare',
        'mod de prezentare' => 'ambalare',
        'ambalare' => 'ambalare',
        'pentru preventie zilnica' => 'doza_recomandata',
        'pentru tratament' => 'doza_recomandata',
        'data expirare' => 'valabilitate',
        'valabilitate' => 'valabilitate',
        'alergeni' => 'nu contine',
    ];

    /** Denumirea afișată pentru fiecare cheie canonică. */
    private const CANONICAL_NAMES = [
        'ingrediente' => 'Ingrediente',
        'compozitie' => 'Compoziție',
        'mod_de_utilizare' => 'Mod de utilizare',
        'doza_recomandata' => 'Doză recomandată',
        'mod_de_pastrare' => 'Mod de păstrare',
        'atentionari' => 'Atenționări',
        'nu_contine' => 'NU conține',
        'ambalare' => 'Ambalare',
        'producator' => 'Producător',
        'beneficii' => 'Beneficii',
        'valori_nutritionale' => 'Valori nutriționale',
        'valabilitate' => 'Valabilitate',
        'nota' => 'Notă',
        'observatii' => 'Observații',
        'caracteristici' => 'Caracteristici',
        'indicatii' => 'Indicații',
        'continut' => 'Conținut',
        'certificare_ecologica' => 'Certificare ecologică',
        'proprietati' => 'Proprietăți',
    ];

    /**
     * Reduce un titlu la câmpul canonic corespunzător.
     *
     * @return array{key:string, name:string}
     */
    public static function canonical(string $label): array
    {
        $group = self::groupKey($label);
        if ($group === '') {
            return ['key' => '', 'name' => ''];
        }

        $key = self::EXACT_RULES[$group] ?? null;

        if ($key === null) {
            foreach (self::PREFIX_RULES as $prefix => $canonicalKey) {
                if (str_starts_with($group, $prefix)) {
                    $key = $canonicalKey;
                    break;
                }
            }
        }

        if ($key === null) {
            $key = self::fieldKey($label);
        }

        $key = str_replace(' ', '_', $key);

        return [
            'key' => $key,
            'name' => self::CANONICAL_NAMES[$key] ?? $label,
        ];
    }
}
