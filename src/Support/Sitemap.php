<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Sitemap-ul, construit din baza de date la fiecare cerere a lui /sitemap.xml.
 *
 * Conține doar pagini care răspund 200 și pot intra în Google: prima pagină,
 * paginile publicate (fără cele de sistem: coș, checkout, cont, login,
 * înregistrare, 404, acorduri GDPR), /magazin, categoriile și mărcile care au
 * cel puțin un produs activ, etichetele care rămân în Google după deciziile D2
 * și D3 din planul SEO (vezi ETICHETE_*), produsele active și articolele
 * publicate. Nimic din ce blochează robots.txt. <lastmod> vine din data ultimei
 * modificări, unde există.
 */
final class Sitemap
{
    /** D2: o etichetă intră în sitemap de la atâtea produse active în sus. */
    public const ETICHETA_MINIM_PRODUSE = 3;

    /**
     * D2: etichetele mici (sub 3 produse) care aduc totuși trafic din Google
     * (cel puțin 5 clicuri în exportul Search Console) și rămân în sitemap.
     */
    public const ETICHETE_MICI_PASTRATE = [
        'vitamina-d', 'lacto-fermentate', 'concentrare', 'lithothamne', 'antidepresiv',
        'sistem-imunitar', 'tulburari-de-somn', 'cupru', 'ochi', 'renofix',
        'proteine-vegetale', 'alge',
    ];

    /**
     * D3: etichete cu nume de boli. Nu se trimit lui Google, oricâte produse au
     * (suplimentele nu au voie să pretindă că previn sau tratează o boală);
     * proprietarul decide redenumirea sau ștergerea lor (C9).
     */
    public const ETICHETE_BOLI = [
        'alzheimer', 'parkinson', 'scleroza-multipla', 'osteoporoza', 'antidepresiv',
        'tulburari-de-somn', 'depresie', 'anxietate', 'insomnie', 'diabet', 'eczeme',
        'dermatita-atopica', 'psoriasis', 'psoriazis', 'acnee', 'herpes', 'paradontoza',
        'viroze', 'racei',
    ];

    /** Eticheta merge în sitemap? (după numărul de produse active și deciziile D2/D3) */
    public static function etichetaInSitemap(string $slug, int $produseActive): bool
    {
        if ($slug === '' || in_array($slug, self::ETICHETE_BOLI, true)) {
            return false;
        }
        return $produseActive >= self::ETICHETA_MINIM_PRODUSE
            || ($produseActive > 0 && in_array($slug, self::ETICHETE_MICI_PASTRATE, true));
    }

    /** Pagini de sistem: există ca rânduri în `pages`, dar n-au ce căuta în Google. */
    public const PAGINI_SISTEM = [
        '', 'acasa', 'home', '404', 'cos', 'cos-cumparaturi', 'checkout', 'contul-meu',
        'login', 'register', 'acorduri-gdpr',
    ];

    /**
     * @return list<array{loc: string, lastmod: string}>
     */
    public static function urls(PDO $db, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $urls = [];
        $vazute = [];
        $adauga = static function (string $cale, string $lastmod = '') use (&$urls, &$vazute, $baseUrl): void {
            if ($cale !== '/' && Robots::blocheaza($cale)) {
                return;
            }
            $loc = $baseUrl . $cale;
            if (isset($vazute[$loc])) {
                return;
            }
            $vazute[$loc] = true;
            $urls[] = ['loc' => $loc, 'lastmod' => $lastmod];
        };

        $articole = self::articolePublicate($db);
        $produse = self::produseActive($db);

        // Prima pagină, cu data paginii „Acasă” din constructor.
        $acasa = self::interogare($db,
            "SELECT updated_at, created_at FROM pages
             WHERE (slug = '' OR LOWER(slug) IN ('acasa', 'home')) AND is_published = 1 AND deleted_at IS NULL
             ORDER BY CASE WHEN slug = '' THEN 0 WHEN LOWER(slug) = 'acasa' THEN 1 ELSE 2 END, id DESC LIMIT 1");
        $adauga('/', self::lastmod($acasa[0] ?? []));

        // Paginile din constructor.
        foreach (self::interogare($db, 'SELECT slug, updated_at, created_at FROM pages WHERE is_published = 1 AND deleted_at IS NULL ORDER BY id ASC') as $pagina) {
            $slug = trim(trim((string) ($pagina['slug'] ?? '')), '/');
            $slugMic = mb_strtolower($slug);
            if (in_array($slugMic, self::PAGINI_SISTEM, true)
                || str_starts_with($slugMic, 'checkout/') || str_starts_with($slugMic, 'contul-meu/')
                || preg_match('/[\s{}<>"]/', $slug) === 1) {
                continue;
            }
            // Blogul gol nu se trimite lui Google (o pagină fără conținut).
            if ($slugMic === 'blog' && $articole === []) {
                continue;
            }
            $lastmod = self::lastmod($pagina);
            if ($slugMic === 'magazin') {
                $lastmod = self::celMaiNou([$lastmod, ...array_column($produse, 'lastmod')]);
            }
            $adauga('/' . self::codeazaCale($slug), $lastmod);
        }
        // Rutele fixe ale magazinului (există și fără pagină în constructor).
        $adauga('/magazin', self::celMaiNou(array_column($produse, 'lastmod')));
        $adauga('/contact');

        // Categoriile cu cel puțin un produs activ (principală sau suplimentară).
        foreach (self::categorii($db, $produse) as $categorie) {
            $adauga('/categorie/' . rawurlencode($categorie['slug']), $categorie['lastmod']);
        }

        // Mărcile cu cel puțin un produs activ; etichetele după D2/D3 (sus).
        $marci = [];
        $etichete = [];
        $produsePeEticheta = [];
        foreach ($produse as $produs) {
            $marca = Slug::simplu((string) ($produs['brand'] ?? ''));
            if ($marca !== '') {
                $marci[$marca] = self::celMaiNou([$marci[$marca] ?? '', $produs['lastmod']]);
            }
            foreach (self::etichete($produs['tags_json'] ?? null) as $eticheta) {
                $slugEticheta = Slug::simplu($eticheta);
                if ($slugEticheta !== '') {
                    $etichete[$slugEticheta] = self::celMaiNou([$etichete[$slugEticheta] ?? '', $produs['lastmod']]);
                    // Un produs se numără o singură dată, chiar dacă are eticheta scrisă de două ori.
                    $produsePeEticheta[$slugEticheta][$produs['id'] . '|' . $produs['slug']] = true;
                }
            }
        }
        ksort($marci);
        ksort($etichete);
        foreach ($marci as $slug => $lastmod) {
            $adauga('/marca/' . rawurlencode((string) $slug), $lastmod);
        }
        foreach ($etichete as $slug => $lastmod) {
            if (self::etichetaInSitemap((string) $slug, count($produsePeEticheta[$slug] ?? []))) {
                $adauga('/eticheta/' . rawurlencode((string) $slug), $lastmod);
            }
        }

        foreach ($produse as $produs) {
            $adauga('/produs/' . rawurlencode((string) $produs['slug']), $produs['lastmod']);
        }

        if ($articole !== []) {
            $adauga('/blog', self::celMaiNou(array_column($articole, 'lastmod')));
        }
        foreach ($articole as $articol) {
            $adauga('/blog/' . rawurlencode((string) $articol['slug']), $articol['lastmod']);
        }

        return $urls;
    }

    /** @param list<array{loc: string, lastmod: string}> $urls */
    public static function xml(array $urls): string
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($urls as $intrare) {
            $loc = trim((string) ($intrare['loc'] ?? ''));
            if ($loc === '') {
                continue;
            }
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars($loc, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>';
            $lastmod = trim((string) ($intrare['lastmod'] ?? ''));
            if ($lastmod !== '') {
                $xml[] = '    <lastmod>' . htmlspecialchars($lastmod, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</lastmod>';
            }
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';

        return implode("\n", $xml) . "\n";
    }

    // ───────────────────────────────────────────────────────────

    /** @return list<array{id: int, slug: string, brand: string, category: string, tags_json: mixed, lastmod: string}> */
    private static function produseActive(PDO $db): array
    {
        $randuri = self::interogare($db,
            'SELECT id, slug, brand, category, tags_json, updated_at, created_at FROM products
             WHERE deleted_at IS NULL AND is_active = 1 AND TRIM(COALESCE(slug, "")) <> "" ORDER BY id DESC');
        if ($randuri === []) {
            // Schema veche, fără mărci și etichete.
            $randuri = self::interogare($db,
                'SELECT id, slug, category, updated_at, created_at FROM products
                 WHERE deleted_at IS NULL AND is_active = 1 AND TRIM(COALESCE(slug, "")) <> "" ORDER BY id DESC');
        }
        $produse = [];
        foreach ($randuri as $rand) {
            $produse[] = [
                'id' => (int) ($rand['id'] ?? 0),
                'slug' => trim((string) ($rand['slug'] ?? '')),
                'brand' => trim((string) ($rand['brand'] ?? '')),
                'category' => trim((string) ($rand['category'] ?? '')),
                'tags_json' => $rand['tags_json'] ?? null,
                'lastmod' => self::lastmod($rand),
            ];
        }
        return $produse;
    }

    /** @return list<array{slug: string, lastmod: string}> */
    private static function articolePublicate(PDO $db): array
    {
        $articole = [];
        foreach (self::interogare($db,
            'SELECT slug, updated_at, created_at, published_at FROM blog_posts
             WHERE deleted_at IS NULL AND is_published = 1 AND published_at <= NOW() AND TRIM(COALESCE(slug, "")) <> ""
             ORDER BY published_at DESC, id DESC') as $rand) {
            $articole[] = ['slug' => trim((string) $rand['slug']), 'lastmod' => self::lastmod($rand)];
        }
        return $articole;
    }

    /**
     * Categoriile cu produse active și slug-ul pe care /categorie/{slug} îl
     * recunoaște (cel salvat; altfel cel generat din nume, ca în magazin).
     *
     * @param list<array{id: int, category: string, lastmod: string}> $produse
     * @return list<array{slug: string, lastmod: string}>
     */
    private static function categorii(PDO $db, array $produse): array
    {
        $dupaNume = [];
        foreach ($produse as $produs) {
            $nume = mb_strtolower(trim($produs['category']));
            if ($nume !== '') {
                $dupaNume[$nume] = self::celMaiNou([$dupaNume[$nume] ?? '', $produs['lastmod']]);
            }
        }
        $lastmodProdus = [];
        foreach ($produse as $produs) {
            $lastmodProdus[$produs['id']] = $produs['lastmod'];
        }
        foreach (self::interogare($db,
            'SELECT l.product_id, pc.name FROM product_category_links l INNER JOIN product_categories pc ON pc.id = l.category_id') as $legatura) {
            $id = (int) ($legatura['product_id'] ?? 0);
            $nume = mb_strtolower(trim((string) ($legatura['name'] ?? '')));
            if ($nume !== '' && isset($lastmodProdus[$id])) {
                $dupaNume[$nume] = self::celMaiNou([$dupaNume[$nume] ?? '', $lastmodProdus[$id]]);
            }
        }

        $slugSalvat = [];
        foreach (self::interogare($db, 'SELECT name, slug FROM product_categories ORDER BY id ASC') as $rand) {
            $nume = mb_strtolower(trim((string) ($rand['name'] ?? '')));
            $slug = trim((string) ($rand['slug'] ?? ''));
            if ($nume !== '' && $slug !== '' && !isset($slugSalvat[$nume])) {
                // Exact cum e salvat: e forma la care /categorie/{slug} nu mai redirecționează.
                $slugSalvat[$nume] = $slug;
            }
        }

        $rezultat = [];
        $slugVazut = [];
        ksort($dupaNume);
        foreach ($dupaNume as $nume => $lastmod) {
            $slug = $slugSalvat[$nume] ?? Slug::categorie((string) $nume);
            if ($slug === '' || isset($slugVazut[mb_strtolower($slug)])) {
                continue;
            }
            $slugVazut[mb_strtolower($slug)] = true;
            $rezultat[] = ['slug' => $slug, 'lastmod' => $lastmod];
        }
        return $rezultat;
    }

    /** @return list<string> */
    private static function etichete(mixed $brut): array
    {
        if (is_array($brut)) {
            $lista = $brut;
        } else {
            $lista = json_decode((string) $brut, true);
            if (!is_array($lista)) {
                return [];
            }
        }
        return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $lista), static fn (string $v): bool => $v !== ''));
    }

    /** @return list<array<string, mixed>> */
    private static function interogare(PDO $db, string $sql): array
    {
        try {
            $stmt = $db->query($sql);
            $randuri = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            return array_values(array_filter($randuri, 'is_array'));
        } catch (Throwable) {
            return [];
        }
    }

    private static function lastmod(array $rand): string
    {
        $brut = trim((string) ($rand['updated_at'] ?? ''));
        if ($brut === '' || str_starts_with($brut, '0000')) {
            $brut = trim((string) ($rand['created_at'] ?? ''));
        }
        if ($brut === '' || str_starts_with($brut, '0000')) {
            return '';
        }
        $ts = strtotime($brut);
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** @param list<string> $valori */
    private static function celMaiNou(array $valori): string
    {
        $valori = array_filter($valori, static fn ($v): bool => is_string($v) && $v !== '');
        if ($valori === []) {
            return '';
        }
        rsort($valori);
        return (string) $valori[0];
    }

    private static function codeazaCale(string $slug): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $slug)));
    }
}
