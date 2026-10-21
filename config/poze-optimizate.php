<?php

declare(strict_types=1);

/**
 * Pozele mari refăcute ca fișiere noi (planul SEO, A8): poza veche din
 * galerie → varianta WebP din public/assets/img/optimizate/, sub 150 KB.
 *
 * Un rând: 'poza veche' => ['poza nouă', mărimea în octeți a pozei vechi].
 * Mărimea e o siguranță: dacă cineva încarcă în galerie altă poză cu același
 * nume, mărimea diferă și site-ul afișează poza încărcată, nu varianta veche
 * de aici. Pozele vechi rămân pe server (Google Imagini, Merchant Center și
 * linkurile vechi le găsesc în continuare); datele structurate, fluxul pentru
 * Google și poza de distribuire folosesc tot poza veche, la rezoluția ei.
 *
 * Cele 8 poze au aceleași dimensiuni în pixeli ca originalele; cele două
 * pachete (1254×1254) sunt micșorate la 940×940 și 1003×1003, aceeași formă,
 * ca să încapă sub 150 KB.
 *
 * Oprire de urgență: SEO_POZE=0 în .env (vezi App\Support\PozeOptimizate).
 */

return [
    '/uploads/gallery/hero-homepage.png' => ['/assets/img/optimizate/hero-homepage.webp', 622488],
    '/uploads/gallery/plasma-quinton.png' => ['/assets/img/optimizate/plasma-quinton.webp', 636787],
    '/uploads/gallery/vivomixx.png' => ['/assets/img/optimizate/vivomixx.webp', 857587],
    '/uploads/gallery/siliciu-organic.png' => ['/assets/img/optimizate/siliciu-organic.webp', 740767],
    '/uploads/gallery/bunatati-pentru-toata-familia.png' => ['/assets/img/optimizate/bunatati-pentru-toata-familia.webp', 2462031],
    '/uploads/gallery/pachet-batoane-kanne-bio-energie-snack-41-gratuit.png' => ['/assets/img/optimizate/pachet-batoane-kanne-bio-energie-snack-41-gratuit.webp', 2284310],
    '/uploads/gallery/vitamina-c-alcalina-ascorbat-de-sodiu-500-gr.png' => ['/assets/img/optimizate/vitamina-c-alcalina-ascorbat-de-sodiu-500-gr.webp', 2082163],
    '/uploads/gallery/clorura-de-magneziu-sare-nigari-500-gr.png' => ['/assets/img/optimizate/clorura-de-magneziu-sare-nigari-500-gr.webp', 1822934],
    '/uploads/gallery/clorura-de-magneziu-sare-nigari-1-kg.png' => ['/assets/img/optimizate/clorura-de-magneziu-sare-nigari-1-kg.webp', 1635420],
    '/uploads/gallery/vitamina-b12-metilcobalamina-1.png' => ['/assets/img/optimizate/vitamina-b12-metilcobalamina-1.webp', 1099751],
];
