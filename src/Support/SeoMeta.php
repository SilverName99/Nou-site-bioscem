<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Titlurile și descrierile pentru Google, după aceleași reguli pe tot site-ul.
 *
 * Titlul unui produs (o singură regulă, vezi titluProdus()):
 *  - titlul scris pentru produs (Admin → Produse → SEO) se folosește așa cum
 *    e, doar curățat: fără „|”, „–” sau „-” rămase la final, fără „ - BIOSCEM”
 *    la final (rest din importul WordPress) și fără marca scrisă de două ori;
 *  - un titlu salvat care doar repetă numele produsului și/sau marca (titlurile
 *    generate automat sau importate din WordPress, unele tăiate la jumătate de
 *    cuvânt) nu e un titlu scris: se construiește din nume, „{nume} – {marcă} |
 *    {magazin}”. Marca se pune doar dacă nu e deja în nume, iar marca și numele
 *    magazinului se adaugă doar cât titlul rămâne de cel mult 60 de caractere.
 *    Numele produsului nu se taie niciodată (Google scurtează singur, cu „…”).
 *  Se schimbă doar titlul din pagină; numele, codul și adresa produsului rămân.
 *
 * Descrierile automate (când nu e scrisă una): din textul real al paginii
 * (descrierea scurtă a produsului, primul paragraf al unei pagini) sau, pentru
 * liste (categorie, marcă, etichetă, magazin), o frază din datele listei:
 * numărul de produse, mărcile, categoriile și prețul de pornire. Fără nicio
 * afirmație despre efectele produselor.
 *
 * Oprire de urgență: SEO_TITLURI=0 în .env lasă titlurile și descrierile
 * exact ca înainte de livrarea 2 (titlul salvat neatins, fără descrieri automate).
 */
final class SeoMeta
{
    /** Lungimea până la care se adaugă marca și numele magazinului. */
    public const LUNGIME_TITLU = 60;

    /** Lungimea maximă a unei descrieri generate (Google arată în jur de 155). */
    public const LUNGIME_DESCRIERE = 155;

    /** Numele magazinului când nu e setat (cel folosit în titluri de la lansare). */
    public const NUME_MAGAZIN_IMPLICIT = 'Bioscem Shop';

    /**
     * Cuvinte din numele mărcilor care nu deosebesc marca („Laboratories
     * Quinton” e deja în „QUINTON HIPERTONIC” prin „Quinton”, nu prin „Laboratories”).
     */
    private const CUVINTE_GENERICE_MARCA = [
        'laboratories', 'laboratoires', 'laboratorios', 'laboratory', 'labs', 'lab',
        'nature', 'natural', 'health', 'scientific', 'sport', 'sports', 'nutrition',
        'harrison', 'air', 'bio', 'shop', 'pharma', 'srl', 'gmbh', 'ltd', 'the', 'and',
    ];

    /** Titlurile și descrierile după regulile livrării 2? (oprire: SEO_TITLURI=0) */
    public static function activ(): bool
    {
        $valoare = strtolower(trim((string) Env::get('SEO_TITLURI', '1')));
        return !in_array($valoare, ['0', 'false', 'off', 'nu'], true);
    }

    /** Numele magazinului din titluri (Admin → Setări magazin → SEO Google). */
    public static function numeMagazin(array $setari): string
    {
        $nume = trim((string) ($setari['store_name'] ?? ''));
        return $nume !== '' ? $nume : self::NUME_MAGAZIN_IMPLICIT;
    }

    // ───────────────────────────────────────────────────────────
    // Titluri
    // ───────────────────────────────────────────────────────────

    /** Titlul Google al produsului (vezi regula de sus). */
    public static function titluProdus(string $nume, string $marca, string $titluSalvat, string $magazin): string
    {
        return self::titluProdusDetaliat($nume, $marca, $titluSalvat, $magazin)['titlu'];
    }

    /**
     * Ca titluProdus(), plus de unde vine titlul: „regula” (construit din nume)
     * sau „scris” (titlul salvat, curățat). Pentru lista „vechi → nou” și teste.
     *
     * @return array{titlu: string, sursa: string}
     */
    public static function titluProdusDetaliat(string $nume, string $marca, string $titluSalvat, string $magazin): array
    {
        $nume = self::curataTitlu(self::text($nume));
        $marca = self::curataTitlu(self::text($marca));
        $magazin = trim($magazin);
        $salvat = self::curataTitlu(self::text($titluSalvat));
        if ($salvat !== '' && !self::doarNumeSiMarca($salvat, $nume, $marca, $magazin)) {
            return ['titlu' => self::faraMarcaDublata($salvat, $marca), 'sursa' => 'scris'];
        }
        if ($nume === '') {
            return ['titlu' => $salvat !== '' ? $salvat : $magazin, 'sursa' => 'regula'];
        }
        return ['titlu' => self::titluDinNume($nume, $marca, $magazin), 'sursa' => 'regula'];
    }

    /** „{nume} – {marcă} | {magazin}”, fiecare adaos doar dacă încape în 60 de caractere. */
    public static function titluDinNume(string $nume, string $marca, string $magazin): string
    {
        $titlu = self::curataTitlu($nume);
        $marca = trim($marca);
        if ($marca !== '' && !self::marcaInNume($titlu, $marca)) {
            $cuMarca = $titlu . ' – ' . $marca;
            if (mb_strlen($cuMarca) <= self::LUNGIME_TITLU) {
                $titlu = $cuMarca;
            }
        }
        $magazin = trim($magazin);
        if ($magazin !== '' && !self::contineCuvintele($titlu, $magazin)) {
            $cuMagazin = $titlu . ' | ' . $magazin;
            if (mb_strlen($cuMagazin) <= self::LUNGIME_TITLU) {
                $titlu = $cuMagazin;
            }
        }
        return $titlu;
    }

    /**
     * Titlul unei pagini oarecare: titlul scris (SEO) dacă există, altfel
     * „{titlul paginii} | {magazin}”, ca până acum. În ambele cazuri fără
     * separatori rămași la final și fără spații în plus.
     */
    public static function titluPagina(string $titluScris, string $titluPagina, string $magazin): string
    {
        $titluScris = self::curataTitlu($titluScris);
        if ($titluScris !== '') {
            return $titluScris;
        }
        $titluPagina = self::curataTitlu($titluPagina);
        if ($titluPagina === '' || strcasecmp($titluPagina, $magazin) === 0) {
            return $magazin;
        }
        return $titluPagina . ' | ' . $magazin;
    }

    /** Fără spații în plus și fără „|”, „–”, „-”, „:” sau „,” rămase la final. */
    public static function curataTitlu(string $titlu): string
    {
        $titlu = trim((string) preg_replace('/\s+/u', ' ', $titlu));
        do {
            $inainte = $titlu;
            // „ - BIOSCEM” la final: sufixul vechi din importul WordPress (Yoast).
            $titlu = (string) preg_replace('/\s*[-–—|]\s*BIOSCEM\s*$/u', '', $titlu);
            $titlu = rtrim($titlu, " \t|–—-:;,/·•");
        } while ($titlu !== $inainte);
        return $titlu;
    }

    /**
     * Titlul salvat doar repetă numele produsului și/sau marca (eventual tăiat,
     * eventual cu numele magazinului la final)? Atunci nu e un titlu scris.
     */
    public static function doarNumeSiMarca(string $titlu, string $nume, string $marca, string $magazin): bool
    {
        $nucleu = self::curataTitlu($titlu);
        // Doar numele magazinului din titluri (cel setat și cel implicit); un
        // „| Bioscem” scris de mână rămâne parte din titlul scris.
        foreach (array_unique([$magazin, self::NUME_MAGAZIN_IMPLICIT]) as $sufix) {
            $sufix = trim($sufix);
            if ($sufix === '') {
                continue;
            }
            $nucleu = self::curataTitlu((string) preg_replace('/\s*[|–—-]\s*' . preg_quote($sufix, '/') . '\s*$/iu', '', $nucleu));
        }
        $n = self::normalizat($nucleu);
        if ($n === '') {
            return true;
        }
        $variante = [self::normalizat($nume)];
        if (trim($marca) !== '') {
            $variante[] = self::normalizat($nume . ' ' . $marca);
            $variante[] = self::normalizat($marca . ' ' . $nume);
            $scurta = self::marcaScurta($marca);
            if ($scurta !== '') {
                $variante[] = self::normalizat($nume . ' ' . $scurta);
            }
        }
        foreach ($variante as $varianta) {
            if ($varianta !== '' && str_starts_with($varianta, $n)) {
                return true;
            }
        }
        return false;
    }

    /** Marca apare deja în nume (întreagă, scurtă sau prin cuvântul ei distinctiv)? */
    public static function marcaInNume(string $nume, string $marca): bool
    {
        $marcaNorm = self::normalizat($marca);
        if ($marcaNorm === '') {
            return true;
        }
        if (self::contineCuvintele($nume, $marca)) {
            return true;
        }
        $scurta = self::marcaScurta($marca);
        if ($scurta !== '' && self::contineCuvintele($nume, $scurta)) {
            return true;
        }
        $numeNorm = ' ' . self::normalizat($nume) . ' ';
        foreach (explode(' ', $marcaNorm) as $cuvant) {
            if (strlen($cuvant) >= 3 && !in_array($cuvant, self::CUVINTE_GENERICE_MARCA, true)
                && str_contains($numeNorm, ' ' . $cuvant . ' ')) {
                return true;
            }
        }
        return false;
    }

    /** „X | Marca” sau „X – Marca”, cu marca deja în X → „X”. */
    private static function faraMarcaDublata(string $titlu, string $marca): string
    {
        $marca = trim($marca);
        if ($marca === '') {
            return $titlu;
        }
        $fara = (string) preg_replace('/\s*[|–—-]\s*' . preg_quote($marca, '/') . '\s*$/iu', '', $titlu);
        if ($fara !== $titlu && $fara !== '' && self::marcaInNume($fara, $marca)) {
            return self::curataTitlu($fara);
        }
        return $titlu;
    }

    /** „HSN - Harrison Sport Nutrition” → „HSN”; altfel gol. */
    private static function marcaScurta(string $marca): string
    {
        $parti = preg_split('/\s+[-–—]\s+/u', trim($marca), 2) ?: [];
        return count($parti) === 2 ? trim((string) $parti[0]) : '';
    }

    /** Toate cuvintele lui $cuvinte apar, în ordine și întregi, în $text? */
    private static function contineCuvintele(string $text, string $cuvinte): bool
    {
        $c = self::normalizat($cuvinte);
        return $c !== '' && str_contains(' ' . self::normalizat($text) . ' ', ' ' . $c . ' ');
    }

    /** Litere mici, fără diacritice, doar litere și cifre despărțite de un spațiu. */
    public static function normalizat(string $text): string
    {
        $text = mb_strtolower(self::text($text));
        $text = strtr($text, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ö' => 'o', 'ô' => 'o', 'ú' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    // ───────────────────────────────────────────────────────────
    // Descrieri
    // ───────────────────────────────────────────────────────────

    /** Text simplu dintr-un câmp care poate avea HTML. */
    public static function text(string $html): string
    {
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Cel mult $maxim caractere, tăiat la sfârșit de frază dacă se poate,
     * altfel la sfârșit de cuvânt (cu „…”). Niciodată la mijlocul unui cuvânt.
     */
    public static function taieLaCuvant(string $text, int $maxim = self::LUNGIME_DESCRIERE): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxim) {
            return $text;
        }
        $bucata = mb_substr($text, 0, $maxim);
        // Sfârșit de frază după cel puțin jumătate din lungime.
        if (preg_match_all('/[.!?](?=\s)/u', $bucata . ' ', $m, PREG_OFFSET_CAPTURE) > 0) {
            $ultim = end($m[0]);
            $poz = mb_strlen(substr($bucata . ' ', 0, (int) $ultim[1])) + 1;
            if ($poz >= (int) ($maxim * 0.5)) {
                return trim(mb_substr($bucata, 0, $poz));
            }
        }
        $spatiu = mb_strrpos(mb_substr($bucata, 0, $maxim - 1), ' ');
        if ($spatiu !== false && $spatiu > 0) {
            $bucata = mb_substr($bucata, 0, $spatiu);
        } else {
            $bucata = mb_substr($bucata, 0, $maxim - 1);
        }
        return rtrim($bucata, " ,;:-–—|(") . '…';
    }

    /** Descrierea automată a unui produs: descrierea scurtă, altfel începutul celei lungi. */
    public static function descriereProdus(string $descriereScurta, string $descriere): string
    {
        $text = self::text($descriereScurta);
        if (mb_strlen($text) < 50) {
            $lunga = self::text($descriere);
            if (mb_strlen($lunga) > mb_strlen($text)) {
                $text = $lunga;
            }
        }
        return $text === '' ? '' : self::taieLaCuvant($text);
    }

    /** Descrierea automată a unei pagini: primul paragraf cu text, fără elementele dinamice ({{…}}). */
    public static function descrierePagina(string $html): string
    {
        $html = (string) preg_replace('/\{\{.*?\}\}/s', ' ', $html);
        $html = (string) preg_replace('#<(script|style|form|nav|header|footer)\b[^>]*>.*?</\1>#is', ' ', $html);
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $m) > 0) {
            foreach ($m[1] as $paragraf) {
                $text = self::text((string) $paragraf);
                if (mb_strlen($text) >= 50) {
                    return self::taieLaCuvant($text);
                }
            }
        }
        return '';
    }

    /**
     * Descrierea automată a unei liste de produse (categorie, marcă, etichetă,
     * magazin), din produsele pe care le arată pagina.
     *
     * @param string $tip categorie | marca | eticheta | magazin
     * @param list<array<string, mixed>> $produse
     */
    public static function descriereLista(string $tip, string $nume, array $produse, string $magazin): string
    {
        $n = count($produse);
        if ($n === 0) {
            return '';
        }
        $marci = self::celeMaiDese($produse, static fn (array $p): string => trim((string) ($p['brand'] ?? '')));
        $categorii = self::celeMaiDese($produse, static fn (array $p): string => trim((string) ($p['category'] ?? '')));
        $preturi = array_values(array_filter(array_map(
            static fn (array $p): float => (float) ($p['price'] ?? 0),
            $produse
        ), static fn (float $pret): bool => $pret > 0));
        $pret = '';
        if ($preturi !== []) {
            $minim = number_format(min($preturi), 2, ',', '.') . ' lei';
            $pret = $n === 1 ? ', la ' . $minim : ', cu prețuri de la ' . $minim;
        }
        $numar = self::numarProduse($n);
        $final = ' Comandă online pe ' . $magazin . '.';

        // De la fraza completă spre una mai scurtă, până încape.
        for ($cate = 3; $cate >= 0; $cate--) {
            switch ($tip) {
                case 'marca':
                    $detaliu = self::lista(array_slice($categorii, 0, $cate), count($categorii) > $cate);
                    $inceput = $nume . ': ' . $numar
                        . ($detaliu !== '' ? (count($categorii) === 1 ? ' din categoria ' : ' din categoriile ') . $detaliu : '');
                    break;
                case 'eticheta':
                    $detaliu = self::lista(array_slice($marci, 0, $cate), count($marci) > $cate);
                    $inceput = 'Produse cu eticheta „' . $nume . '”: ' . $numar . ($detaliu !== '' ? ' de la ' . $detaliu : '');
                    break;
                case 'magazin':
                    $detaliu = self::lista(array_slice($categorii, 0, $cate), count($categorii) > $cate);
                    $inceput = $nume . ': ' . $numar
                        . ($categorii !== [] ? ' în ' . self::numar(count($categorii), 'categorie', 'categorii') : '')
                        . ($detaliu !== '' ? ', printre care ' . $detaliu : '');
                    break;
                default:
                    $detaliu = self::lista(array_slice($marci, 0, $cate), count($marci) > $cate);
                    $inceput = $nume . ': ' . $numar . ($detaliu !== '' ? ' de la ' . $detaliu : '');
            }
            foreach ([$inceput . $pret . '.' . $final, $inceput . $pret . '.'] as $varianta) {
                if (mb_strlen($varianta) <= self::LUNGIME_DESCRIERE) {
                    return $varianta;
                }
            }
        }
        return self::taieLaCuvant($nume . ': ' . $numar . $pret . '.');
    }

    /**
     * Valorile cele mai dese (de exemplu mărcile), de la cea mai folosită.
     *
     * @param list<array<string, mixed>> $produse
     * @return list<string>
     */
    private static function celeMaiDese(array $produse, callable $valoare): array
    {
        $numar = [];
        $forma = [];
        foreach ($produse as $produs) {
            $v = $valoare($produs);
            if ($v === '') {
                continue;
            }
            $cheie = mb_strtolower($v);
            $numar[$cheie] = ($numar[$cheie] ?? 0) + 1;
            $forma[$cheie] ??= $v;
        }
        uksort($numar, static fn (string $a, string $b): int => [$numar[$b], $a] <=> [$numar[$a], $b]);
        return array_values(array_map(static fn (string $cheie): string => $forma[$cheie], array_keys($numar)));
    }

    /** „A”, „A și B”, „A, B și C”, „A, B, C și altele”. */
    private static function lista(array $elemente, bool $maiSunt): string
    {
        $elemente = array_values(array_filter(array_map('trim', $elemente), static fn (string $e): bool => $e !== ''));
        if ($elemente === []) {
            return '';
        }
        if ($maiSunt) {
            return implode(', ', $elemente) . ' și altele';
        }
        if (count($elemente) === 1) {
            return $elemente[0];
        }
        $ultim = array_pop($elemente);
        return implode(', ', $elemente) . ' și ' . $ultim;
    }

    /** „1 produs”, „7 produse”, „37 de produse”. */
    public static function numarProduse(int $n): string
    {
        return self::numar($n, 'produs', 'produse');
    }

    /** Numeralul cu substantivul, cu „de” unde cere româna (20, 21…, 100, 120…). */
    private static function numar(int $n, string $singular, string $plural): string
    {
        if ($n === 1) {
            return '1 ' . $singular;
        }
        $rest = $n % 100;
        $cuDe = $n >= 20 && ($rest === 0 || $rest >= 20);
        return $n . ($cuDe ? ' de ' : ' ') . $plural;
    }
}
