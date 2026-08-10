<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Randeaza un slider responsive cu postarile dintr-o categorie de blog.
 * Folosit prin token-ul de pagina {{category_posts:slug-categorie}}.
 */
final class CategoryPosts
{
    public static function renderSlider(?PDO $db, string $slug, int $limit = 12): string
    {
        if (!$db instanceof PDO) {
            return '';
        }
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        $catId = 0;
        $catName = '';
        try {
            $stmt = $db->prepare('SELECT id, name FROM blog_categories WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            $cat = $stmt->fetch() ?: null;
            if (is_array($cat)) {
                $catId = (int) ($cat['id'] ?? 0);
                $catName = (string) ($cat['name'] ?? '');
            }
        } catch (Throwable) {
        }

        $limit = max(1, min(50, $limit));
        $rows = [];
        try {
            $stmt = $db->prepare(
                "SELECT p.title, p.slug, p.excerpt, p.content, p.featured_image_url,
                        COALESCE(c.name, p.category) AS cat_name
                 FROM blog_posts p
                 LEFT JOIN blog_categories c ON c.id = p.category_id
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()
                   AND (p.category_id = :catId OR p.category = :catName
                        OR EXISTS (SELECT 1 FROM blog_post_categories pc WHERE pc.post_id = p.id AND pc.category_id = :catIdJoin))
                 ORDER BY p.published_at DESC
                 LIMIT " . $limit
            );
            $stmt->execute(['catId' => $catId, 'catName' => $catName, 'catIdJoin' => $catId]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            $rows = [];
        }

        if ($rows === []) {
            return '<div class="vtcs"><p class="vtcs__empty">Momentan nu există articole în această categorie.</p></div>';
        }

        $cards = '';
        foreach ($rows as $p) {
            $postSlug = trim((string) ($p['slug'] ?? ''));
            $url = $postSlug !== '' ? '/blog/' . rawurlencode($postSlug) : '/blog';
            $img = trim((string) ($p['featured_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
            $title = trim((string) ($p['title'] ?? ''));
            $excerpt = trim((string) ($p['excerpt'] ?? ''));
            if ($excerpt === '') {
                $plain = trim(strip_tags((string) ($p['content'] ?? '')));
                $excerpt = mb_substr($plain, 0, 120) . (mb_strlen($plain) > 120 ? '…' : '');
            }
            $catLabel = trim((string) ($p['cat_name'] ?? ''));

            $cards .= '<article class="vtcs__card">'
                . '<a class="vtcs__media" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
                . '<img src="' . htmlspecialchars($img, ENT_QUOTES) . '" alt="' . htmlspecialchars($title, ENT_QUOTES) . '" loading="lazy">'
                . '</a>'
                . '<div class="vtcs__body">'
                . ($catLabel !== '' ? '<span class="vtcs__cat">' . htmlspecialchars($catLabel, ENT_QUOTES) . '</span>' : '')
                . '<h3 class="vtcs__title"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($title, ENT_QUOTES) . '</a></h3>'
                . ($excerpt !== '' ? '<p class="vtcs__excerpt">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p>' : '')
                . '<a class="vtcs__link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">Citește →</a>'
                . '</div>'
                . '</article>';
        }

        return '<div class="vtcs" data-vt-slider>'
            . '<button class="vtcs__nav vtcs__nav--prev" type="button" data-vt-prev aria-label="Anterior">‹</button>'
            . '<div class="vtcs__viewport" data-vt-vp>' . $cards . '</div>'
            . '<button class="vtcs__nav vtcs__nav--next" type="button" data-vt-next aria-label="Următor">›</button>'
            . '<div class="vtcs__dots" data-vt-dots></div>'
            . '</div>';
    }

    /**
     * Grilă responsivă cu postările dintr-o categorie. Pentru evenimente
     * (postări cu event_start_date) ordonează: viitoarele primele (cele mai
     * apropiate sus), apoi cele trecute, marcate discret ca „Trecut".
     * Folosit prin token-ul de pagină {{category_grid:slug-categorie}}.
     */
    public static function renderGrid(?PDO $db, string $slug, int $limit = 60): string
    {
        $rows = self::fetchPosts($db, $slug, $limit);
        if ($rows === null) {
            return '';
        }
        if ($rows === []) {
            return '<div class="vtcg"><p class="vtcg__empty">Momentan nu există articole în această categorie.</p></div>';
        }

        $today = date('Y-m-d');
        $cards = '';
        foreach ($rows as $p) {
            $cards .= self::gridCard($p, $today);
        }

        return '<div class="vtcg">' . $cards . '</div>';
    }

    /**
     * Pagină de evenimente: hero cu următorul eveniment + două secțiuni
     * („Evenimente viitoare" și „Evenimente trecute").
     * Folosit prin token-ul de pagină {{category_events:slug-categorie}}.
     */
    public static function renderEventsPage(?PDO $db, string $slug, int $limit = 200): string
    {
        $rows = self::fetchPosts($db, $slug, $limit);
        if ($rows === null) {
            return '';
        }
        if ($rows === []) {
            return '<div class="vte"><p class="vtcg__empty">Momentan nu există evenimente programate.</p></div>';
        }

        $today = date('Y-m-d');
        $upcoming = [];
        $past = [];
        foreach ($rows as $p) {
            $start = trim((string) ($p['event_start_date'] ?? ''));
            $end = trim((string) ($p['event_end_date'] ?? ''));
            $refDate = $end !== '' ? $end : $start;
            // fără dată → tratăm ca „viitor" (ține-l vizibil sus, nu îl ascunde în „trecut")
            if ($refDate === '' || $refDate >= $today) {
                $upcoming[] = $p;
            } else {
                $past[] = $p;
            }
        }

        $html = '<div class="vte">';

        // Hero = primul eveniment viitor (fetchPosts e deja ordonat cu cele mai apropiate primele)
        $heroRendered = false;
        if ($upcoming !== []) {
            $html .= self::heroCard($upcoming[0], $today);
            $heroRendered = true;
        }

        // Restul evenimentelor viitoare (fără cel din hero)
        $restUpcoming = $heroRendered ? array_slice($upcoming, 1) : $upcoming;
        if ($restUpcoming !== []) {
            $cards = '';
            foreach ($restUpcoming as $p) {
                $cards .= self::gridCard($p, $today);
            }
            $html .= '<section class="vte__section">'
                . '<h2 class="vte__h2">Evenimente viitoare</h2>'
                . '<div class="vtcg">' . $cards . '</div>'
                . '</section>';
        } elseif (!$heroRendered) {
            $html .= '<section class="vte__section"><p class="vtcg__empty">Niciun eveniment viitor programat momentan.</p></section>';
        }

        // Evenimente trecute
        if ($past !== []) {
            $cards = '';
            foreach ($past as $p) {
                $cards .= self::gridCard($p, $today);
            }
            $html .= '<section class="vte__section">'
                . '<h2 class="vte__h2">Evenimente trecute</h2>'
                . '<div class="vtcg">' . $cards . '</div>'
                . '</section>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Aduce postările publicate dintr-o categorie, ordonate cu evenimentele
     * viitoare primele (cele mai apropiate), apoi cele trecute.
     * @return array<int,array<string,mixed>>|null null dacă nu se poate interoga
     */
    private static function fetchPosts(?PDO $db, string $slug, int $limit): ?array
    {
        if (!$db instanceof PDO) {
            return null;
        }
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $catId = 0;
        $catName = '';
        try {
            $stmt = $db->prepare('SELECT id, name FROM blog_categories WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            $cat = $stmt->fetch() ?: null;
            if (is_array($cat)) {
                $catId = (int) ($cat['id'] ?? 0);
                $catName = (string) ($cat['name'] ?? '');
            }
        } catch (Throwable) {
        }

        $limit = max(1, min(200, $limit));
        try {
            $stmt = $db->prepare(
                "SELECT p.title, p.slug, p.excerpt, p.content, p.featured_image_url,
                        p.event_start_date, p.event_end_date, p.event_location,
                        COALESCE(c.name, p.category) AS cat_name
                 FROM blog_posts p
                 LEFT JOIN blog_categories c ON c.id = p.category_id
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()
                   AND (p.category_id = :catId OR p.category = :catName
                        OR EXISTS (SELECT 1 FROM blog_post_categories pc WHERE pc.post_id = p.id AND pc.category_id = :catIdJoin))
                 ORDER BY
                   (p.event_start_date IS NOT NULL AND p.event_start_date >= CURDATE()) DESC,
                   CASE WHEN p.event_start_date >= CURDATE() THEN p.event_start_date END ASC,
                   COALESCE(p.event_start_date, p.published_at) DESC
                 LIMIT " . $limit
            );
            $stmt->execute(['catId' => $catId, 'catName' => $catName, 'catIdJoin' => $catId]);
            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function fmtDate(string $d): string
    {
        $d = trim($d);
        return $d !== '' ? date('d.m.Y', strtotime($d) ?: time()) : '';
    }

    /**
     * @param array<string,mixed> $p
     * @return array{period:string,refDate:string,isPast:bool}
     */
    private static function eventMeta(array $p, string $today): array
    {
        $start = trim((string) ($p['event_start_date'] ?? ''));
        $end = trim((string) ($p['event_end_date'] ?? ''));
        $startLabel = self::fmtDate($start);
        $endLabel = self::fmtDate($end);
        if ($startLabel !== '' && $endLabel !== '' && $startLabel !== $endLabel) {
            $period = $startLabel . ' – ' . $endLabel;
        } else {
            $period = $startLabel;
        }
        $refDate = $end !== '' ? $end : $start;
        return [
            'period' => $period,
            'refDate' => $refDate,
            'isPast' => $refDate !== '' && $refDate < $today,
        ];
    }

    private static function excerptOf(array $p, int $len): string
    {
        $excerpt = trim((string) ($p['excerpt'] ?? ''));
        if ($excerpt === '') {
            $plain = trim(strip_tags((string) ($p['content'] ?? '')));
            $excerpt = mb_substr($plain, 0, $len) . (mb_strlen($plain) > $len ? '…' : '');
        }
        return $excerpt;
    }

    private const CAL_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';

    /**
     * @param array<string,mixed> $p
     */
    private static function gridCard(array $p, string $today): string
    {
        $postSlug = trim((string) ($p['slug'] ?? ''));
        $url = $postSlug !== '' ? '/blog/' . rawurlencode($postSlug) : '/blog';
        $img = trim((string) ($p['featured_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
        $title = trim((string) ($p['title'] ?? ''));
        $excerpt = self::excerptOf($p, 130);
        $meta = self::eventMeta($p, $today);

        $badge = $meta['period'] !== ''
            ? '<span class="vtcg__badge">' . self::CAL_ICON . htmlspecialchars($meta['period'], ENT_QUOTES) . '</span>'
            : '';
        $cardClass = 'vtcg__card' . ($meta['isPast'] ? ' is-past' : '');
        $pastTag = $meta['isPast'] ? '<span class="vtcg__past-tag">Trecut</span>' : '';

        return '<article class="' . $cardClass . '">'
            . '<a class="vtcg__media" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
            . '<img src="' . htmlspecialchars($img, ENT_QUOTES) . '" alt="' . htmlspecialchars($title, ENT_QUOTES) . '" loading="lazy">'
            . $badge . $pastTag
            . '</a>'
            . '<div class="vtcg__body">'
            . '<h3 class="vtcg__title"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($title, ENT_QUOTES) . '</a></h3>'
            . ($excerpt !== '' ? '<p class="vtcg__excerpt">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p>' : '')
            . '<a class="vtcg__link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">» Află mai mult</a>'
            . '</div>'
            . '</article>';
    }

    /**
     * @param array<string,mixed> $p
     */
    private static function heroCard(array $p, string $today): string
    {
        $postSlug = trim((string) ($p['slug'] ?? ''));
        $url = $postSlug !== '' ? '/blog/' . rawurlencode($postSlug) : '/blog';
        $img = trim((string) ($p['featured_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
        $title = trim((string) ($p['title'] ?? ''));
        $excerpt = self::excerptOf($p, 220);
        $meta = self::eventMeta($p, $today);
        $location = trim((string) ($p['event_location'] ?? ''));

        $metaRow = '';
        if ($meta['period'] !== '') {
            $metaRow .= '<span class="vte__hero-meta">' . self::CAL_ICON . htmlspecialchars($meta['period'], ENT_QUOTES) . '</span>';
        }
        if ($location !== '') {
            $pin = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>';
            $metaRow .= '<span class="vte__hero-meta">' . $pin . htmlspecialchars($location, ENT_QUOTES) . '</span>';
        }

        return '<section class="vte__hero">'
            . '<a class="vte__hero-media" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
            . '<img src="' . htmlspecialchars($img, ENT_QUOTES) . '" alt="' . htmlspecialchars($title, ENT_QUOTES) . '">'
            . '</a>'
            . '<div class="vte__hero-body">'
            . '<span class="vte__hero-flag">Următorul eveniment</span>'
            . '<h2 class="vte__hero-title"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($title, ENT_QUOTES) . '</a></h2>'
            . ($metaRow !== '' ? '<div class="vte__hero-metarow">' . $metaRow . '</div>' : '')
            . ($excerpt !== '' ? '<p class="vte__hero-excerpt">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p>' : '')
            . '<a class="vte__hero-btn" href="' . htmlspecialchars($url, ENT_QUOTES) . '">Află mai mult</a>'
            . '</div>'
            . '</section>';
    }
}
