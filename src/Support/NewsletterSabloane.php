<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Șabloanele de pornire pentru un newsletter nou, cu înfățișarea magazinului:
 * logo, culorile site-ului (verdele-petrol din antet), carduri de produs și un
 * subsol cu datele magazinului. Sunt făcute DOAR din blocurile existente ale
 * builderului (imagine, titlu, text, buton, coloane, separator, spațiu), deci
 * după alegere se modifică exact ca orice alt conținut.
 *
 * Produsele din carduri sunt cele mai noi produse active, cu poză; omul le
 * poate schimba sau șterge. Linkul de dezabonare NU e în șablon: se adaugă
 * automat, personal, la trimitere (NewsletterService::withNewsletterFooter).
 *
 * La portarea în CMS: logo-ul și culorile vin din pachetul site-ului.
 */
final class NewsletterSabloane
{
    /** Culorile din docs/templates/header.css (--bs-teal, --bs-teal-dark, --bs-teal-soft, --bs-text, --bs-muted, --bs-line). */
    private const CULOARE = '#075d61';
    private const CULOARE_INCHISA = '#0b3f52';
    private const FUNDAL_DESCHIS = '#eaf6f5';
    private const TEXT = '#18353a';
    private const TEXT_SECUNDAR = '#6b7d80';
    private const LINIE = '#e3ecec';

    /** Logo-ul din antetul site-ului (PNG: pozele WebP nu apar în Outlook). */
    private const LOGO = '/uploads/gallery/bioscem-logo-2.png';

    /**
     * @return array<string, array{titlu:string, descriere:string, blocks:list<array>}>
     */
    public static function lista(PDO $db, array $settings): array
    {
        $produse = self::produse($db, 4);
        $numeMagazin = trim((string) ($settings['order_email_from_name'] ?? '')) ?: 'Bioscem';

        return [
            'noutati' => [
                'titlu' => 'Noutăți și produse',
                'descriere' => 'Logo, un mesaj scurt, 4 produse și buton spre magazin.',
                'blocks' => array_merge(
                    self::antet(),
                    [
                        self::titlu('Noutățile lunii de la ' . $numeMagazin),
                        self::text("Bună,\n\nÎți scriem câteva rânduri despre ce e nou la noi luna aceasta. Scrie aici mesajul tău: o veste, un sfat, o recomandare.", 'left'),
                    ],
                    self::carduriProduse($produse, 'Produse recomandate'),
                    [self::buton('Vezi tot magazinul', '/magazin')],
                    self::subsol($numeMagazin)
                ),
            ],
            'oferta' => [
                'titlu' => 'Ofertă / reducere',
                'descriere' => 'Titlu mare cu reducerea, codul de cupon, produse în ofertă.',
                'blocks' => array_merge(
                    self::antet(),
                    [
                        self::titlu('-20% la produsele selectate', 34, 26),
                        self::text("Doar până duminică: folosește codul de mai jos în coș și primești reducerea pe loc.", 'center'),
                        [
                            'type' => 'text', 'content' => '<b>COD: TOAMNA20</b>', 'align' => 'center',
                            'background' => self::FUNDAL_DESCHIS, 'text_color' => self::CULOARE_INCHISA,
                            'font_size' => 24, 'font_size_mobile' => 20,
                            'padding_top' => 14, 'padding_bottom' => 14, 'padding_left' => 24, 'padding_right' => 24,
                        ],
                    ],
                    self::carduriProduse(array_slice($produse, 0, 2), 'În ofertă'),
                    [self::buton('Cumpără acum', '/magazin')],
                    self::subsol($numeMagazin)
                ),
            ],
            'sfat' => [
                'titlu' => 'Sfat / articol',
                'descriere' => 'Un sfat util, un citat și link spre blog sau spre un produs.',
                'blocks' => array_merge(
                    self::antet(),
                    [
                        self::titlu('Un sfat pentru săptămâna aceasta'),
                        self::text("Scrie aici sfatul tău, în 3–4 rânduri. Oamenii citesc pe telefon: fraze scurte, un singur mesaj.", 'left'),
                        [
                            'type' => 'quote', 'content' => 'Un citat scurt sau părerea unui client.', 'author' => 'Numele clientului',
                            'align' => 'left', 'background' => self::FUNDAL_DESCHIS, 'text_color' => self::CULOARE_INCHISA,
                            'border_color' => self::CULOARE, 'font_size' => 16,
                            'padding_top' => 14, 'padding_bottom' => 14, 'padding_left' => 20, 'padding_right' => 24,
                        ],
                        self::buton('Citește tot articolul', '/blog'),
                    ],
                    self::subsol($numeMagazin)
                ),
            ],
            'anunt' => [
                'titlu' => 'Anunț scurt',
                'descriere' => 'Logo, titlu, două rânduri și un buton. Pentru vești rapide.',
                'blocks' => array_merge(
                    self::antet(),
                    [
                        self::titlu('Titlul anunțului'),
                        self::text("Scrie aici anunțul (program de sărbători, produs nou, livrare gratuită).", 'center'),
                        self::buton('Află mai multe', '/'),
                    ],
                    self::subsol($numeMagazin)
                ),
            ],
        ];
    }

    /** Cele mai noi produse active, cu poză și preț. */
    private static function produse(PDO $db, int $cate): array
    {
        try {
            $stmt = $db->prepare(
                'SELECT name, slug, price, sale_price, image_url
                 FROM products
                 WHERE is_active = 1 AND deleted_at IS NULL AND image_url IS NOT NULL AND image_url <> ""
                 ORDER BY badge_best_seller DESC, id DESC
                 LIMIT ' . max(1, min(8, $cate))
            );
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function antet(): array
    {
        return [[
            'type' => 'image', 'image_url' => self::LOGO, 'alt' => 'Bioscem', 'width' => 40, 'align' => 'center',
            'link_url' => '/', 'background' => '#ffffff', 'padding_top' => 20, 'padding_bottom' => 16,
        ]];
    }

    private static function titlu(string $text, int $marime = 28, int $marimeMobil = 22): array
    {
        return [
            'type' => 'header', 'content' => $text, 'align' => 'center',
            'background' => self::CULOARE, 'text_color' => '#ffffff',
            'font_size' => $marime, 'font_size_mobile' => $marimeMobil,
            'padding_top' => 26, 'padding_bottom' => 26, 'padding_left' => 20, 'padding_right' => 20,
        ];
    }

    private static function text(string $continut, string $aliniere): array
    {
        return [
            'type' => 'text', 'content' => $continut, 'align' => $aliniere,
            'background' => '#ffffff', 'text_color' => self::TEXT,
            'font_size' => 16, 'font_size_mobile' => 15,
            'padding_top' => 20, 'padding_bottom' => 12, 'padding_left' => 28, 'padding_right' => 28,
        ];
    }

    private static function buton(string $eticheta, string $adresa): array
    {
        return [
            'type' => 'button', 'label' => $eticheta, 'url' => $adresa, 'align' => 'center',
            'background' => '#ffffff', 'button_color' => self::CULOARE, 'text_color' => '#ffffff',
            'border_radius' => 6, 'font_size' => 16, 'btn_pad_v' => 13, 'btn_pad_h' => 28,
            'padding_top' => 16, 'padding_bottom' => 26,
        ];
    }

    /**
     * Cardurile de produs: câte două pe rând (pe telefon unul sub altul), fiecare
     * cu poză (link spre produs), nume, preț și buton.
     */
    private static function carduriProduse(array $produse, string $titluSectiune): array
    {
        if ($produse === []) {
            // Magazin fără produse cu poză: un rând de exemplu, de completat de mână.
            $produse = [
                ['name' => 'Numele produsului', 'slug' => '', 'price' => 0, 'sale_price' => null, 'image_url' => ''],
                ['name' => 'Numele produsului', 'slug' => '', 'price' => 0, 'sale_price' => null, 'image_url' => ''],
            ];
        }
        $blocuri = [[
            'type' => 'text', 'content' => '<b>' . $titluSectiune . '</b>', 'align' => 'center',
            'background' => '#ffffff', 'text_color' => self::CULOARE_INCHISA, 'font_size' => 18, 'font_size_mobile' => 17,
            'padding_top' => 18, 'padding_bottom' => 4,
        ]];
        foreach (array_chunk($produse, 2) as $pereche) {
            $coloane = [];
            foreach ($pereche as $produs) {
                $coloane[] = self::card($produs);
            }
            while (count($coloane) < 2) {
                $coloane[] = [];
            }
            $blocuri[] = ['type' => 'columns_2', 'background' => '#ffffff', 'padding_left' => 12, 'padding_right' => 12, 'columns' => $coloane];
        }
        $blocuri[] = ['type' => 'divider', 'background' => '#ffffff', 'color' => self::LINIE, 'width' => 90, 'thickness' => 1, 'padding_top' => 8, 'padding_bottom' => 0];
        return $blocuri;
    }

    private static function card(array $produs): array
    {
        $slug = trim((string) ($produs['slug'] ?? ''));
        $link = $slug !== '' ? '/produs/' . rawurlencode($slug) : '';
        $pret = (float) ($produs['price'] ?? 0);
        $redus = $produs['sale_price'] !== null && (float) $produs['sale_price'] > 0 && (float) $produs['sale_price'] < $pret
            ? (float) $produs['sale_price']
            : null;
        $textPret = $pret <= 0
            ? 'Preț'
            : ($redus !== null
                ? '<s>' . self::lei($pret) . '</s> <b>' . self::lei($redus) . '</b>'
                : '<b>' . self::lei($pret) . '</b>');
        return [
            [
                'type' => 'image', 'image_url' => (string) ($produs['image_url'] ?? ''), 'alt' => (string) ($produs['name'] ?? ''),
                'width' => 100, 'align' => 'center', 'link_url' => $link, 'border_radius' => 6, 'background' => '#ffffff',
            ],
            [
                'type' => 'text', 'content' => (string) ($produs['name'] ?? '') . "\n" . $textPret, 'align' => 'center',
                'background' => '#ffffff', 'text_color' => self::TEXT, 'font_size' => 15, 'font_size_mobile' => 15,
                'padding_top' => 8, 'padding_bottom' => 6,
            ],
            [
                'type' => 'button', 'label' => 'Vezi produsul', 'url' => $link, 'align' => 'center',
                'background' => '#ffffff', 'button_color' => self::CULOARE, 'text_color' => '#ffffff',
                'border_radius' => 6, 'font_size' => 14, 'btn_pad_v' => 9, 'btn_pad_h' => 16, 'padding_bottom' => 10,
            ],
        ];
    }

    private static function subsol(string $numeMagazin): array
    {
        $site = preg_replace('#^https?://#', '', NewsletterService::appBaseUrl()) ?: 'bioscem.ro';
        return [
            ['type' => 'spacer', 'height' => 12, 'background' => '#ffffff'],
            [
                'type' => 'text',
                'content' => '<b>' . $numeMagazin . '</b>' . "\n" . 'Primești acest email pentru că te-ai abonat la noutățile noastre pe ' . $site . '.',
                'align' => 'center', 'background' => self::FUNDAL_DESCHIS, 'text_color' => self::TEXT_SECUNDAR,
                'font_size' => 13, 'font_size_mobile' => 13,
                'padding_top' => 18, 'padding_bottom' => 18, 'padding_left' => 24, 'padding_right' => 24,
            ],
        ];
    }

    private static function lei(float $suma): string
    {
        return number_format($suma, 2, ',', '.') . ' lei';
    }
}
