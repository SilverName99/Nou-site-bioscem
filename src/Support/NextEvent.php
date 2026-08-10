<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Urmatorul eveniment publicat: postarea din categoria "Evenimente" cu
 * event_start_date >= azi, cea mai apropiata. Expune token-uri de pagina
 * pentru a fi folosite in paginile custom (ex: sectiunea 2 de pe Acasa).
 */
final class NextEvent
{
    private static ?array $cache = null;

    /**
     * @return array<string,string> token => valoare
     */
    public static function tokens(?PDO $db): array
    {
        $empty = [
            '{{next_event_image}}' => '',
            '{{next_event_url}}' => '',
            '{{next_event_title}}' => '',
            '{{next_event_excerpt}}' => '',
            '{{next_event_period}}' => '',
            '{{next_event_video}}' => '',
        ];

        if (!$db instanceof PDO) {
            return $empty;
        }

        $row = self::load($db);
        if ($row === null) {
            return $empty;
        }

        $slug = trim((string) ($row['slug'] ?? ''));
        $image = trim((string) ($row['featured_image_url'] ?? ''));
        $start = trim((string) ($row['event_start_date'] ?? ''));
        $end = trim((string) ($row['event_end_date'] ?? ''));
        $fmt = static function (string $d): string {
            return $d !== '' ? date('d.m.Y', strtotime($d) ?: time()) : '';
        };
        $startLabel = $fmt($start);
        $endLabel = $fmt($end);
        if ($startLabel !== '' && $endLabel !== '' && $startLabel !== $endLabel) {
            $period = $startLabel . ' – ' . $endLabel;
        } else {
            $period = $startLabel;
        }

        $video = trim((string) ($row['video_url'] ?? ''));
        $videoEmbed = $video !== ''
            ? '<video controls preload="metadata" style="width:100%;height:auto;border-radius:14px;display:block;"><source src="' . htmlspecialchars($video, ENT_QUOTES) . '" type="video/mp4">Browserul tău nu suportă redarea video.</video>'
            : '';

        return [
            '{{next_event_image}}' => $image !== '' ? $image : '/assets/img/product-placeholder.svg',
            '{{next_event_url}}' => $slug !== '' ? '/blog/' . rawurlencode($slug) : '/blog',
            '{{next_event_title}}' => trim((string) ($row['title'] ?? '')),
            '{{next_event_excerpt}}' => trim((string) ($row['excerpt'] ?? '')),
            '{{next_event_period}}' => $period,
            '{{next_event_video}}' => $videoEmbed,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function load(PDO $db): ?array
    {
        if (self::$cache !== null) {
            return self::$cache['row'] ?? null;
        }
        try {
            $stmt = $db->query(
                "SELECT p.title, p.slug, p.excerpt, p.featured_image_url, p.video_url, p.event_start_date, p.event_end_date
                 FROM blog_posts p
                 LEFT JOIN blog_categories c ON c.id = p.category_id
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.event_start_date IS NOT NULL
                   AND p.event_start_date >= CURDATE()
                   AND (p.category = 'Evenimente' OR c.slug = 'evenimente'
                        OR EXISTS (SELECT 1 FROM blog_post_categories pc
                                   JOIN blog_categories bc ON bc.id = pc.category_id
                                   WHERE pc.post_id = p.id AND bc.slug = 'evenimente'))
                 ORDER BY p.event_start_date ASC
                 LIMIT 1"
            );
            $row = $stmt ? ($stmt->fetch() ?: null) : null;
            self::$cache = ['row' => is_array($row) ? $row : null];
            return self::$cache['row'];
        } catch (Throwable) {
            self::$cache = ['row' => null];
            return null;
        }
    }
}
