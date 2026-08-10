<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catalog central token -> camp pentru editorul de postari "template-driven".
 *
 * In Pasul 2 al editorului, formularul afiseaza doar campurile ale caror
 * token-uri apar in HTML-ul template-ului ales. Aceeasi sursa de adevar e
 * folosita la salvare si (informativ) in builder-ul de template.
 */
final class BlogTemplateFields
{
    /**
     * key => [label, type, tokens[]]
     * type: text | textarea | rich | image | author | number | date_range | url
     */
    public static function catalog(): array
    {
        return [
            'title' => [
                'label' => 'Titlu',
                'type' => 'text',
                'tokens' => ['{{blog_title}}'],
            ],
            'content' => [
                'label' => 'Conținut',
                'type' => 'rich',
                'tokens' => ['{{blog_content_html}}', '{{blog_content}}'],
            ],
            'excerpt' => [
                'label' => 'Excerpt (rezumat scurt)',
                'type' => 'textarea',
                'tokens' => ['{{blog_excerpt}}'],
            ],
            'featured_image' => [
                'label' => 'Imagine principală',
                'type' => 'image',
                'tokens' => ['{{blog_image_url}}'],
            ],
            'video' => [
                'label' => 'Video (MP4)',
                'type' => 'video_url',
                'tokens' => ['{{blog_video}}', '{{blog_video_url}}'],
            ],
            'author' => [
                'label' => 'Autor',
                'type' => 'author',
                'tokens' => ['{{blog_author_name}}', '{{blog_author_bio}}', '{{blog_author_avatar}}', '{{blog_author_url}}', '{{blog_author_initials}}'],
            ],
            'reading_minutes' => [
                'label' => 'Timp citire (minute)',
                'type' => 'number',
                'tokens' => ['{{blog_reading_minutes}}', '{{blog_reading_label}}'],
            ],
            'event_dates' => [
                'label' => 'Perioadă eveniment',
                'type' => 'date_range',
                'tokens' => ['{{blog_event_period}}', '{{blog_event_start_date}}', '{{blog_event_end_date}}'],
            ],
            'event_price' => [
                'label' => 'Preț eveniment',
                'type' => 'text',
                'tokens' => ['{{blog_event_price}}'],
            ],
            'event_location' => [
                'label' => 'Locație eveniment',
                'type' => 'text',
                'tokens' => ['{{blog_event_location}}'],
            ],
            'event_ticket_url' => [
                'label' => 'Link bilete / înscriere (extern)',
                'type' => 'url',
                'tokens' => ['{{blog_event_ticket_url}}'],
            ],
        ];
    }

    /**
     * Cheile de camp ale caror token-uri apar in HTML-ul dat.
     *
     * @return string[]
     */
    public static function fieldKeysForHtml(string $html): array
    {
        $keys = [];
        foreach (self::catalog() as $key => $def) {
            foreach ($def['tokens'] as $token) {
                if (str_contains($html, $token)) {
                    $keys[] = $key;
                    break;
                }
            }
        }
        return $keys;
    }
}
