<?php

declare(strict_types=1);

/**
 * Redirecționările adreselor vechi (WordPress / WooCommerce și produse șterse).
 *
 * Un rând = o regulă: [adresa veche, adresa nouă, cod, de ce].
 *  - adresa veche: doar calea, cu litere mici, fără „/” la final și fără
 *    parametri; „/x/”, „/X” și „/x?utm=…” se potrivesc toate cu „/x”.
 *  - cod 301: mutare permanentă la adresa nouă (parametrii din adresă se păstrează).
 *  - cod 410: pagina a dispărut definitiv (adresa nouă = null).
 *
 * Regulile se aplică DOAR când site-ul ar răspunde „pagina nu există” (sau cu
 * o potrivire aproximativă de produs): o pagină care există nu e acoperită
 * niciodată de o regulă de aici. Fiecare țintă a fost verificată pe site pe
 * 19 octombrie 2026 (200, fără noindex, canonical = ținta); nicio țintă nu
 * este la rândul ei o adresă veche din listă (fără lanțuri). Dacă totuși apare
 * un lanț (A -> B, B -> C), site-ul îl urmează într-un singur salt; o buclă
 * (A -> B, B -> A) nu redirecționează deloc.
 *
 * Ținte de evitat (planul SEO, §8.2): prima pagină, coșul și paginile de cont,
 * etichetele care vor ieși din Google (sub 3 produse și sub 5 clicuri, D2) și
 * etichetele cu nume de boli (D3). Unde nu există o categorie sau un produs
 * potrivit, adresa veche rămâne 404.
 *
 * Cum adaugi un rând: copiezi un rând existent, schimbi adresele și motivul.
 * Cum scoți unul: îl ștergi. Regulile rămân pe site cel puțin un an.
 *
 * Sursa: Search Console (cele 336 de adrese 404 și cele 130 din Performanță),
 * verificate live; lista completă și deciziile rămase la proprietar sunt în
 * notele livrării 1 (redirecturi-aplicate.csv, redirecturi-decizie.csv).
 */

return [
    'exacte' => [
        // Pagini și articole WordPress, liste vechi
        ['/cart',                                            '/cos',                                             301, 'coșul WooCommerce -> coșul nou (pentru vizitatori; 0 clicuri; /cos e închis pentru Google, deci regula nu aduce nimic în Google, dar nici nu strică)'],
        ['/index.php',                                       '/',                                                301, 'prima pagină WordPress'],
        ['/plasma-quinton-sanatatea-nazala',                 '/categorie/plasma-quinton',                        301, 'articol WordPress Quinton nazal -> categoria (3 spray-uri nazale)'],
        ['/plasma-quinton-sanatatea-ochilor',                '/produs/quinton-ocular',                           301, 'articol WordPress Quinton pentru ochi -> produsul Quinton Ocular'],
        ['/plasma-quinton-sanatatea-pielii',                 '/produs/quinton-dermo-action',                     301, 'articol WordPress Quinton pentru piele -> produsul Quinton Dermo Action'],
        ['/produse',                                         '/magazin',                                         301, 'lista veche de produse WordPress'],
        ['/protocolul',                                      '/categorie/plasma-quinton',                        301, 'adresă trunchiată a articolului /protocolul-quinton/'],
        ['/protocolul-quinton',                              '/categorie/plasma-quinton',                        301, 'articol WordPress protocolul Quinton -> categoria Plasma Quinton'],
        ['/siliciul-',                                       '/categorie/siliciu-organic',                       301, 'adresă trunchiată a articolului /siliciul-organic/'],
        ['/siliciul-organic',                                '/categorie/siliciu-organic',                       301, 'articol WordPress despre siliciul organic -> categoria Siliciu Organic'],
        ['/terapia-marina-plasma-',                          '/categorie/plasma-quinton',                        301, 'adresă trunchiată a articolului /terapia-marina-plasma-quinton/'],
        ['/terapia-marina-plasma-quinton',                   '/categorie/plasma-quinton',                        301, 'articol WordPress despre plasma Quinton -> categoria Plasma Quinton'],
        // Categorii și etichete vechi (blog, WooCommerce, redenumiri)
        ['/cat/dispozitive-cmo',                             '/categorie/dispozitive-cmo',                       301, 'prefix greșit /cat/'],
        ['/categorie-produs/dispozitive-cmo',                '/categorie/dispozitive-cmo',                       301, 'baza WooCommerce /categorie-produs/ -> /categorie/'],
        ['/categorie-produs/plasma-quinton',                 '/categorie/plasma-quinton',                        301, 'baza WooCommerce /categorie-produs/ -> /categorie/'],
        ['/categorie-produs/probiotice',                     '/categorie/probiotice',                            301, 'baza WooCommerce /categorie-produs/ -> /categorie/'],
        ['/categorie/alge/feed',                             '/categorie/alge',                                  301, 'categoria (fără /feed sau /page/N) (flux RSS)'],
        ['/categorie/dermato',                               '/categorie/dermato-ingrijire',                     301, 'categorie redenumită (Dermato -> Dermato și Îngrijire)'],
        ['/categorie/detox/feed',                            '/categorie/detox',                                 301, 'categoria (fără /feed sau /page/N) (flux RSS)'],
        ['/categorie/detox/page/1',                          '/categorie/detox',                                 301, 'categoria (fără /feed sau /page/N) (paginare)'],
        ['/categorie/dispozitive-',                          '/categorie/dispozitive-cmo',                       301, 'adresă trunchiată'],
        ['/categorie/hrana-bio',                             '/categorie/hrana',                                 301, 'categorie redenumită (Hrană bio -> Hrană)'],
        ['/categorie/luvv',                                  '/marca/luvv-air',                                  301, 'categoria veche LUVV -> pagina mărcii LUVV Air (aceleași produse)'],
        ['/categorie/oferte',                                '/categorie/promotii',                              301, 'categoria Oferte -> PROMOTII (/categorie/promotii)'],
        ['/categorie/siliciu-',                              '/categorie/siliciu-organic',                       301, 'adresă trunchiată'],
        ['/categorie/siliciu-organic/page/1',                '/categorie/siliciu-organic',                       301, 'categoria (fără /feed sau /page/N) (paginare)'],
        ['/categorie/tiroida',                               '/categorie/tiroida-menopauza',                     301, 'categorie redenumită (Tiroidă -> Tiroidă și Menopauză)'],
        ['/categorie/vitamine-minerale/page/1',              '/categorie/vitamine-minerale',                     301, 'categoria (fără /feed sau /page/N) (paginare)'],
        ['/category/plasma-quinton',                         '/categorie/plasma-quinton',                        301, 'categorie de blog -> categoria de produse'],
        ['/category/plasma-quinton/page/2',                  '/categorie/plasma-quinton',                        301, 'pagina 2 a categoriei de blog'],
        ['/category/siliciul-organic',                       '/categorie/siliciu-organic',                       301, 'categorie de blog -> categoria de produse'],
        ['/eticheta-produs/magneziu',                        '/eticheta/magneziu',                               301, 'baza WooCommerce /eticheta-produs/ -> /eticheta/'],
        ['/eticheta-produs/vitamina-c',                      '/eticheta/vitamina-c',                             301, 'baza WooCommerce /eticheta-produs/ -> /eticheta/'],
        ['/eticheta-produs/zeolit',                           '/categorie/detox',                                 301, 'eticheta zeolit (2 produse, iese din Google la D2) -> categoria Detox, unde sunt ambele produse Zeolit'],
        ['/eticheta/biofarmland',                            '/marca/biofarmland',                               301, 'eticheta cu numele mărcii -> pagina mărcii'],
        ['/eticheta/concentrare/feed',                       '/eticheta/concentrare',                            301, 'eticheta (fără /feed) (flux RSS)'],
        ['/eticheta/fara-gluten/feed',                       '/eticheta/fara-gluten',                            301, 'eticheta (fără /feed) (flux RSS)'],
        ['/eticheta/glutathione',                             '/produs/glutathione-liposomal-quicksilver',        301, 'glutation (eticheta glutation are 1 produs și iese din Google la D2) -> produsul cu glutation'],
        ['/eticheta/herbagetica',                            '/marca/herbagetica',                               301, 'eticheta cu numele mărcii -> pagina mărcii'],
        ['/eticheta/metilsilanol',                           '/categorie/siliciu-organic',                       301, 'metilsilanol = siliciu organic'],
        ['/eticheta/mucolid/feed',                           '/eticheta/mucolid',                                301, 'eticheta (fără /feed) (flux RSS)'],
        ['/eticheta/muschi/feed',                            '/eticheta/muschi',                                 301, 'eticheta (fără /feed) (flux RSS)'],
        ['/eticheta/mustar-salbatic/feed',                   '/eticheta/mustar-salbatic',                        301, 'eticheta (fără /feed) (flux RSS)'],
        ['/eticheta/shilajit',                               '/produs/super-shilajit-mumio-herbagetica-120caps', 301, 'singurul produs cu shilajit'],
        ['/eticheta/sport',                                  '/categorie/sport',                                 301, 'eticheta Sport -> categoria Sport'],
        ['/eticheta/sport-',                                 '/categorie/sport',                                 301, 'adresă trunchiată'],
        ['/eticheta/stevie/feed',                            '/eticheta/stevie',                                 301, 'eticheta (fără /feed) (flux RSS)'],
        ['/eticheta/synerj-health',                          '/marca/synerj-health',                             301, 'eticheta cu numele mărcii -> pagina mărcii'],
        ['/eticheta/vitamina-b9',                            '/produs/vitamina-b9-lipozomala-novo-biomedics',    301, 'singurul produs cu vitamina B9'],
        ['/eticheta/vivomixx',                               '/marca/vivomixx',                                  301, 'eticheta cu numele mărcii -> pagina mărcii'],
        ['/tag/detoxifiere',                                 '/eticheta/detoxifiere',                            301, 'etichetă de blog -> eticheta de produse cu același nume'],
        ['/tag/electroliti',                                  '/categorie/plasma-quinton',                        301, 'etichetă de blog (articolele Quinton) -> categoria Plasma Quinton, unde sunt ambele produse cu eticheta electroliti'],
        ['/tag/imunitate',                                   '/eticheta/imunitate',                              301, 'etichetă de blog -> eticheta de produse cu același nume'],
        ['/tag/ingrijirea-pielii',                           '/eticheta/ingrijirea-pielii',                      301, 'etichetă de blog -> eticheta de produse cu același nume'],
        ['/tag/izotonic',                                    '/eticheta/izotonic',                               301, 'etichetă de blog -> eticheta de produse cu același nume'],
        ['/tag/minerale',                                    '/eticheta/minerale',                               301, 'etichetă de blog -> eticheta de produse cu același nume'],
        ['/tag/monometilsilanetriol',                        '/categorie/siliciu-organic',                       301, 'monometilsilanetriol = siliciu organic'],
        ['/tag/ochi',                                        '/eticheta/ochi',                                   301, 'etichetă de blog -> eticheta de produse cu același nume'],
        ['/tag/ocular',                                       '/eticheta/ochi',                                   301, 'etichetă de blog -> eticheta ochi (aceleași produse ca eticheta ocular; ochi rămâne în Google la D2)'],
        ['/tag/oligoelemente',                               '/eticheta/oligoelemente',                          301, 'etichetă de blog -> eticheta de produse cu același nume'],
        ['/tag/quinton',                                      '/categorie/plasma-quinton',                        301, 'etichetă de blog Quinton -> categoria Plasma Quinton (eticheta quinton are 2 produse și iese din Google la D2)'],
        ['/tag/quinton-nazal',                               '/eticheta/quinton-nazal',                          301, 'etichetă de blog -> eticheta de produse cu același nume'],
        ['/tag/silicium',                                    '/marca/silicium-laboratories',                     301, 'eticheta de blog Silicium -> pagina mărcii Silicium Laboratories'],
        // Mărci: /brand/ (WordPress) -> /marca/
        ['/brand/actinovo',                                  '/marca/actinovo',                                  301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/biofarmland',                               '/marca/biofarmland',                               301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/canah',                                     '/marca/canah',                                     301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/comosystems',                               '/marca/comosystems',                               301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/farabella',                                 '/marca/farabella',                                 301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/farabella/feed',                            '/marca/farabella',                                 301, '/brand/ (WordPress) -> /marca/ (flux RSS)'],
        ['/brand/farmaderbe',                                '/marca/farmaderbe',                                301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/herbagetica',                               '/marca/herbagetica',                               301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/hsn-harrison-sport-nutrition',              '/marca/hsn-harrison-sport-nutrition',              301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/inovera',                                   '/marca/inovera',                                   301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/laboratories-quinton',                      '/marca/laboratories-quinton',                      301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/laboratories-quinton/feed',                 '/marca/laboratories-quinton',                      301, '/brand/ (WordPress) -> /marca/ (flux RSS)'],
        ['/brand/luvv-air',                                  '/marca/luvv-air',                                  301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/marzotto',                                  '/marca/marzotto',                                  301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/mgd-nature',                                '/marca/mgd-nature',                                301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/novo-biomedics',                            '/marca/novo-biomedics',                            301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/plantextrakt',                              '/marca/plantextrakt',                              301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/quicksilver-',                              '/marca/quicksilver-scientific',                    301, 'adresă trunchiată'],
        ['/brand/quicksilver-scientific',                    '/marca/quicksilver-scientific',                    301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/silicium-laboratories',                     '/marca/silicium-laboratories',                     301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/synerj-health',                             '/marca/synerj-health',                             301, '/brand/ (WordPress) -> /marca/'],
        ['/brand/vivomixx',                                  '/marca/vivomixx',                                  301, '/brand/ (WordPress) -> /marca/'],
        // Produse: același produs cu alt slug, alt gramaj sau succesorul evident
        ['/produs/alphaone-alga-90caps',                     '/produs/alphaone-alga-afa-90caps',                 301, 'același produs, slug schimbat'],
        ['/produs/alphaone-alga-caps',                       '/produs/alphaone-alga-afa-90caps',                 301, 'singura variantă AlphaOne în capsule'],
        ['/produs/bioferment-150g',                          '/produs/bioferment-250g',                          301, 'același produs, alt gramaj (succesorul)'],
        ['/produs/biozym-1kg',                               '/produs/biozym-500g',                              301, 'același produs, alt gramaj (succesorul)'],
        ['/produs/cmo-',                                     '/categorie/dispozitive-cmo',                       301, 'adresă trunchiată; toate produsele CMO sunt în categoria Dispozitive CMO'],
        ['/produs/immunity-lipozomal-liporom',               '/categorie/imunitate',                             301, 'produs șters -> categoria lui evidentă (Imunitate)'],
        ['/produs/orgono-supliment-pentru-sportivi-1-litru', '/produs/orgono-g7-sport-recovery',                 301, 'același produs (Orgono pentru sportivi, 1 L), redenumit'],
        ['/produs/sarea-gema-neiodata-sarea-dacilor',        '/produs/sare-gema-neiodata-sarea-dacilor',         301, 'același produs, slug corectat'],
        ['/produs/siliciu-g7-siliplant-dinamizat',           '/produs/silicium-g7-siliplant-dinamizat',          301, 'același produs, slug corectat'],
        ['/produs/silicium-g5-gel-50ml',                     '/produs/silicium-g7-gel-50ml',                     301, 'Silicium G5 a devenit G7 (aceeași gamă, aceeași mărime)'],
        ['/produs/silicium-g5-original',                     '/produs/silicium-g7-original-dinamizat',           301, 'Silicium G5 Original a devenit G7 Original'],
        ['/produs/totum-quinton-sport',                      '/produs/quinton-totum-sport',                      301, 'același produs, slug schimbat'],
        ['/produs/totum-sport-set-6-cutii',                  '/produs/quinton-totum-sport',                      301, 'setul de 6 cutii -> produsul'],
        ['/produs/ulei-in-canepa-canah-omega-3-30-capsule',  '/produs/ulei-in-canepa-omega-3-canah',             301, 'același produs (30 capsule), slug schimbat'],
        ['/produs/ulei-in-canepa-canaha-omega-3-canah',      '/produs/ulei-in-canepa-omega-3-canah',             301, 'același produs, slug corectat'],
        ['/produs/vitamina',                                 '/categorie/vitamine-minerale',                     301, 'adresă trunchiată; azi potrivirea automată o trimitea greșit la un gel cu siliciu'],
        ['/produs/vitamina-c-actinovo-250',                  '/produs/actinovo-vitamina-c-lipozomala',           301, 'același produs (ActiNovo 250 ml), slug schimbat'],
        ['/produs/vivomixx-neo',                             '/produs/vivomixx-neo-460',                         301, 'același produs, slug schimbat (rând „da” în lista din plan)'],
        // Resturi tehnice WordPress: 410
        ['/*',                                               null,                                               410, 'rest tehnic WordPress'],
        ['/tag/icons',                                       null,                                               410, 'rest tehnic WordPress'],
        ['/tag/js',                                          null,                                               410, 'rest tehnic WordPress'],
        ['/tag/js/feed',                                     null,                                               410, 'rest tehnic WordPress'],
        ['/tag/themes',                                      null,                                               410, 'rest tehnic WordPress'],
        ['/wp-admin/*',                                      null,                                               410, 'rest tehnic WordPress'],
        ['/wp-content/*',                                    null,                                               410, 'rest tehnic WordPress'],
        ['/wp-content/plugins/*',                            null,                                               410, 'rest tehnic WordPress'],
        ['/wp-content/themes/yootheme/*',                    null,                                               410, 'rest tehnic WordPress'],
        ['/wp-content/uploads/*',                            null,                                               410, 'rest tehnic WordPress'],
    ],
    // Pe prefix: orice adresă care începe așa (după aceleași reguli de potrivire).
    'prefixe' => [
        ['/wp-admin/',                     null, 410, 'rest tehnic WordPress (panoul vechi de administrare)'],
        ['/wp-includes/',                  null, 410, 'rest tehnic WordPress (scripturi)'],
        ['/wp-json/',                      null, 410, 'rest tehnic WordPress (API-ul vechi)'],
        ['/wp-content/plugins/',           null, 410, 'rest tehnic WordPress (module)'],
        ['/wp-content/themes/',            null, 410, 'rest tehnic WordPress (tema veche)'],
        ['/wp-content/uploads/complianz/', null, 410, 'rest tehnic WordPress (bannerul vechi de cookie-uri)'],
    ],
];
