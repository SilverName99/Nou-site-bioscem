<?php
$products = is_array($products ?? null) ? $products : [];
$categories = is_array($categories ?? null) ? $categories : [];
$blogPosts = is_array($blogPosts ?? null) ? $blogPosts : [];

$categoryUrl = static function (string $name): string {
    $name = trim($name);
    return $name === '' ? '/magazin' : '/magazin?category=' . rawurlencode($name);
};

/**
 * Iconițe pentru secțiunea „Cu ce te putem ajuta?”.
 * Sunt alese după numele categoriei; „default” acoperă restul,
 * deci secțiunea funcționează oricâte categorii ar exista.
 */
$needIcons = [
    'digestie' => '<path d="M8 3v6a4 4 0 0 0 8 0V3"/><path d="M12 13v8"/><path d="M9 21h6"/>',
    'probiotice' => '<path d="M8 3v6a4 4 0 0 0 8 0V3"/><path d="M12 13v8"/><path d="M9 21h6"/>',
    'imunitate' => '<path d="M12 3 5 6v6c0 4.4 2.8 7.8 7 9 4.2-1.2 7-4.6 7-9V6l-7-3z"/><path d="m9.4 12.3 1.8 1.8 3.5-3.5"/>',
    'sport' => '<path d="M13 2 4 14h7l-1 8 10-13h-7z"/>',
    'vitamine' => '<path d="M13 2 4 14h7l-1 8 10-13h-7z"/>',
    'minerale' => '<path d="m12 2 4 6-4 14-4-14z"/><path d="M8 8h8"/>',
    'siliciu' => '<path d="m12 2 4 6-4 14-4-14z"/><path d="M8 8h8"/>',
    'detoxifiere' => '<path d="M12 2C8 7 5 11 5 15a7 7 0 0 0 14 0c0-4-3-8-7-13z"/>',
    'alge' => '<path d="M12 21c0-6 2-11 6-14"/><path d="M12 21c0-6-2-11-6-14"/><path d="M12 21v-9"/>',
    'neuro' => '<path d="M9 4a3 3 0 0 0-3 3 3 3 0 0 0-1 5 3 3 0 0 0 2 5 3 3 0 0 0 5 1"/><path d="M15 4a3 3 0 0 1 3 3 3 3 0 0 1 1 5 3 3 0 0 1-2 5 3 3 0 0 1-5 1"/><path d="M12 4v16"/>',
    'tiroida' => '<path d="M7 4c-1 4-1 8 1 11 1.5 2.2 4.5 2.2 6 0 2-3 2-7 1-11"/><path d="M12 15v6"/>',
    'menopauza' => '<path d="M7 4c-1 4-1 8 1 11 1.5 2.2 4.5 2.2 6 0 2-3 2-7 1-11"/><path d="M12 15v6"/>',
    'dermato' => '<path d="M12 21s8-4.5 8-11a4.5 4.5 0 0 0-8-3 4.5 4.5 0 0 0-8 3c0 6.5 8 11 8 11z"/>',
    'beauty' => '<path d="M12 21s8-4.5 8-11a4.5 4.5 0 0 0-8-3 4.5 4.5 0 0 0-8 3c0 6.5 8 11 8 11z"/>',
    'respiro' => '<path d="M12 4v8"/><path d="M8 8a4 4 0 0 0-4 4c0 3 2 6 4 8"/><path d="M16 8a4 4 0 0 1 4 4c0 3-2 6-4 8"/>',
    'hrana' => '<path d="M6 3v8a3 3 0 0 0 6 0V3"/><path d="M9 11v10"/><path d="M17 3c-1.5 2-2 4-2 6v3h4V9c0-2-.5-4-2-6z"/><path d="M17 12v9"/>',
    'quinton' => '<path d="M12 3c3 4 6 7 6 10a6 6 0 0 1-12 0c0-3 3-6 6-10z"/>',
    'cmo' => '<path d="M12 3a9 9 0 1 0 9 9"/><path d="M12 7a5 5 0 1 0 5 5"/><circle cx="12" cy="12" r="1.5"/>',
    'default' => '<path d="M19.1 5.1c-6 .2-10.4 3.9-10.4 6.5 0 2.9 2 4.9 4.5 4.9 4.2 0 7.7-4 8.2-9.6.1-.9-.6-1.8-2.3-1.8Z"/><path d="M6.6 20.4c2.1-4.7 6.1-8 11.3-10.2"/>',
];

$pickIcon = static function (string $label) use ($needIcons): string {
    $normalized = mb_strtolower($label);
    $normalized = strtr($normalized, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    foreach ($needIcons as $key => $path) {
        if ($key !== 'default' && str_contains($normalized, $key)) {
            return $path;
        }
    }
    return $needIcons['default'];
};

// Gamele emblematice: imagini fixe, legate de categoriile reale din catalog.
$ranges = [
    [
        'title' => 'Plasma Quinton',
        'text' => 'Terapia marina pentru revitalizare profunda.',
        'image' => '/uploads/gallery/plasma-quinton.png',
        'category' => 'Plasma Quinton',
    ],
    [
        'title' => 'Vivomixx',
        'text' => 'Echilibru avansat al microbiomului intestinal.',
        'image' => '/uploads/gallery/vivomixx.png',
        'category' => 'Probiotice',
    ],
    [
        'title' => 'Siliciu Organic',
        'text' => 'Frumusete, elasticitate si suport structural.',
        'image' => '/uploads/gallery/siliciu-organic.png',
        'category' => 'Siliciu Organic',
    ],
    [
        'title' => 'CMO',
        'text' => 'Protectie fata de radiatiile electromagnetice.',
        'image' => '/uploads/gallery/cmo.jpeg',
        'category' => 'Dispozitive CMO',
    ],
];

$trustBadges = [
    ['title' => 'Branduri internationale', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.5 3 2.5 15 0 18"/><path d="M12 3c-2.5 3-2.5 15 0 18"/>'],
    ['title' => 'Selectie specializata', 'icon' => '<path d="M19.1 5.1c-6 .2-10.4 3.9-10.4 6.5 0 2.9 2 4.9 4.5 4.9 4.2 0 7.7-4 8.2-9.6.1-.9-.6-1.8-2.3-1.8Z"/><path d="M6.6 20.4c2.1-4.7 6.1-8 11.3-10.2"/>'],
    ['title' => 'Livrare rapida', 'icon' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>'],
    ['title' => 'Expertiza si consultanta', 'icon' => '<circle cx="12" cy="8" r="4"/><path d="M5 21c.5-4 3-6 7-6s6.5 2 7 6"/>'],
];

$whyItems = [
    [
        'title' => 'Selectie premium',
        'text' => 'Produse atent selectate, de la branduri internationale de incredere.',
        'icon' => '<circle cx="12" cy="9" r="5"/><path d="m9 14-1 8 4-2 4 2-1-8"/>',
    ],
    [
        'title' => 'Expertiza & consultanta',
        'text' => 'Echipa noastra de specialisti te ajuta sa faci cele mai bune alegeri.',
        'icon' => '<circle cx="12" cy="8" r="4"/><path d="M5 21c.5-4 3-6 7-6s6.5 2 7 6"/>',
    ],
    [
        'title' => 'Livrare rapida',
        'text' => 'Comenzi livrate rapid, in siguranta, oriunde in Romania.',
        'icon' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
    ],
    [
        'title' => 'Plati sigure',
        'text' => 'Plati 100% securizate si datele tale protejate.',
        'icon' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    ],
];
?>

<style>
/* =========================================================
   BIOSCEM - PAGINA DE ACASA
   ========================================================= */

.bs-home{
  --bs-teal:#075d61;
  --bs-teal-dark:#064b4f;
  --bs-teal-soft:#eaf6f5;
  --bs-ice:#f4faf9;
  --bs-sand:#f7f2ea;
  --bs-text:#18353a;
  --bs-muted:#66787b;
  --bs-line:#dbe6e5;

  --bs-radius-sm:12px;
  --bs-radius:18px;
  --bs-radius-lg:26px;
  --bs-shadow:0 12px 35px rgba(19,75,78,.07);

  max-width:1320px;
  margin:0 auto;
  padding:22px 28px 70px;
  color:var(--bs-text);
  font-family:Inter, Arial, sans-serif;
}

.bs-home,
.bs-home *{
  box-sizing:border-box;
}

.bs-home h1,
.bs-home h2,
.bs-home h3{
  font-family:Georgia, "Times New Roman", serif;
  color:var(--bs-teal-dark);
  font-weight:500;
}

.bs-home a{
  color:inherit;
  text-decoration:none;
}

/* app.css impune border-radius:0 !important pe sectiuni/carduri;
   il anulam doar in pagina de acasa. */
.bs-home .bs-hero,
.bs-home .bs-ranges,
.bs-home .bs-need,
.bs-home .bs-range,
.bs-home .bs-product-card,
.bs-home .bs-post,
.bs-home .bs-why{
  border-radius:var(--bs-radius) !important;
}

.bs-home .bs-hero{
  border-radius:var(--bs-radius-lg) !important;
}


/* ---------------- HERO ---------------- */

.bs-hero{
  overflow:hidden;
  border:1px solid var(--bs-line);
  background:linear-gradient(120deg,#eaf7f7 0%,#dff0f2 55%,#cfe8ec 100%);
  box-shadow:var(--bs-shadow);
}

.bs-hero-main{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(0,1fr);
  align-items:center;
  gap:20px;
}

.bs-hero-copy{
  padding:48px 10px 48px 48px;
}

.bs-hero-copy h1{
  margin:0 0 18px;
  font-size:36px;
  line-height:1.22;
  letter-spacing:-.4px;
}

.bs-hero-copy p{
  margin:0 0 26px;
  max-width:430px;
  color:#4f6569;
  font-size:14px;
  line-height:1.7;
}

.bs-hero-actions{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
}

.bs-btn{
  display:inline-flex;
  align-items:center;
  gap:9px;
  padding:13px 22px;
  border:1px solid var(--bs-teal);
  border-radius:100px;
  background:var(--bs-teal);
  color:#fff;
  font-size:14px;
  font-weight:600;
  transition:.2s ease;
}

.bs-btn:hover{
  background:var(--bs-teal-dark);
  border-color:var(--bs-teal-dark);
}

.bs-btn--ghost{
  background:#fff;
  color:var(--bs-teal-dark);
  border-color:#c6dedd;
}

.bs-btn--ghost:hover{
  background:#f3fbfa;
}

.bs-hero-media{
  align-self:stretch;
  min-height:260px;
}

.bs-hero-media img{
  display:block;
  width:100%;
  height:100%;
  max-height:360px;
  object-fit:cover;
  object-position:center;
}

.bs-hero-badges{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:14px;
  padding:16px 48px;
  background:rgba(255,255,255,.72);
  border-top:1px solid rgba(255,255,255,.9);
}

.bs-hero-badge{
  display:flex;
  align-items:center;
  gap:10px;
  min-width:0;
  color:#3f585b;
  font-size:12px;
  font-weight:500;
}

.bs-hero-badge__icon{
  flex:0 0 34px;
  width:34px;
  height:34px;
  display:flex;
  align-items:center;
  justify-content:center;
  border-radius:50%;
  background:#fff;
  color:var(--bs-teal);
}

.bs-hero-badge__icon svg{
  width:17px;
  height:17px;
  fill:none;
  stroke:currentColor;
  stroke-width:1.6;
  stroke-linecap:round;
  stroke-linejoin:round;
}


/* ---------------- SECTIUNI ---------------- */

.bs-section{
  margin-top:52px;
}

.bs-section-title{
  margin:0 0 24px;
  text-align:center;
  font-size:27px;
}

.bs-section-head{
  position:relative;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:16px;
  margin-bottom:24px;
}

.bs-section-head .bs-section-title{
  margin:0;
}

.bs-section-head .bs-link{
  position:absolute;
  right:0;
}

.bs-link{
  display:inline-flex;
  align-items:center;
  gap:7px;
  color:var(--bs-teal);
  font-size:13px;
  font-weight:600;
}

.bs-link:hover{
  color:var(--bs-teal-dark);
}

.bs-link--small{
  font-size:12px;
}


/* ---------------- CU CE TE PUTEM AJUTA ---------------- */

.bs-needs-grid{
  display:grid;
  grid-template-columns:repeat(6,1fr);
  gap:14px;
}

.bs-need{
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:10px;
  padding:24px 14px 18px;
  border:1px solid var(--bs-line);
  background:#fff;
  text-align:center;
  transition:.2s ease;
}

.bs-need:hover{
  border-color:#b9d9d7;
  box-shadow:var(--bs-shadow);
  transform:translateY(-2px);
}

.bs-need__icon{
  width:56px;
  height:56px;
  display:flex;
  align-items:center;
  justify-content:center;
  border-radius:50%;
  background:var(--bs-teal-soft);
  color:var(--bs-teal);
}

.bs-need__icon svg{
  width:27px;
  height:27px;
  fill:none;
  stroke:currentColor;
  stroke-width:1.5;
  stroke-linecap:round;
  stroke-linejoin:round;
}

.bs-need__title{
  font-family:Georgia, "Times New Roman", serif;
  color:var(--bs-teal-dark);
  font-size:15px;
  line-height:1.3;
}

.bs-need__arrow{
  color:var(--bs-teal);
  font-size:15px;
}


/* ---------------- GAME EMBLEMATICE ---------------- */

.bs-ranges{
  margin-top:52px;
  padding:34px 30px;
  border-radius:var(--bs-radius-lg) !important;
  background:var(--bs-sand);
}

.bs-ranges-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:16px;
}

.bs-range{
  position:relative;
  display:block;
  overflow:hidden;
  min-height:200px;
  background:#e9eef0;
  box-shadow:0 10px 26px rgba(11,70,72,.08);
  transition:.2s ease;
}

.bs-range:hover{
  transform:translateY(-3px);
}

.bs-range__image{
  position:absolute;
  inset:0;
  width:100%;
  height:100%;
  object-fit:cover;
}

.bs-range__body{
  position:relative;
  z-index:2;
  display:block;
  padding:22px 22px 60px;
  background:linear-gradient(180deg,rgba(255,255,255,.86) 0%,rgba(255,255,255,.55) 55%,rgba(255,255,255,0) 100%);
}

.bs-range__title{
  display:block;
  margin-bottom:6px;
  font-family:Georgia, "Times New Roman", serif;
  color:var(--bs-teal-dark);
  font-size:19px;
}

.bs-range__text{
  display:block;
  max-width:80%;
  color:#3f585b;
  font-size:12px;
  line-height:1.5;
}

.bs-range__arrow{
  position:absolute;
  left:18px;
  bottom:16px;
  z-index:2;
  width:34px;
  height:34px;
  display:flex;
  align-items:center;
  justify-content:center;
  border-radius:50%;
  background:#fff;
  color:var(--bs-teal-dark);
  font-size:20px;
  box-shadow:0 4px 12px rgba(11,70,72,.15);
}


/* ---------------- PRODUSE ---------------- */

.bs-products-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:16px;
}

.bs-product-card{
  display:flex;
  flex-direction:column;
  overflow:hidden;
  border:1px solid var(--bs-line);
  background:#fff;
  transition:.2s ease;
}

.bs-product-card:hover{
  box-shadow:var(--bs-shadow);
  transform:translateY(-2px);
}

.bs-product-card__media{
  display:flex;
  align-items:center;
  justify-content:center;
  height:180px;
  padding:16px;
  background:#fff;
}

.bs-product-card__media img{
  max-width:100%;
  max-height:100%;
  object-fit:contain;
}

.bs-product-card__body{
  display:flex;
  flex-direction:column;
  gap:9px;
  padding:0 16px 16px;
}

.bs-product-card__name{
  color:var(--bs-text);
  font-size:14px;
  line-height:1.4;
}

.bs-product-card__name:hover{
  color:var(--bs-teal);
}

.bs-product-card__price{
  display:flex;
  align-items:baseline;
  gap:8px;
  margin:0;
  color:var(--bs-teal-dark);
  font-family:Georgia, "Times New Roman", serif;
  font-size:19px;
  font-weight:600;
}

.bs-product-card__price-old{
  color:#98a7a9;
  font-size:14px;
  font-weight:400;
  text-decoration:line-through;
}

.bs-product-card__cart{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  width:100%;
  min-height:42px;
  border:0;
  border-radius:10px;
  background:var(--bs-teal);
  color:#fff;
  font-family:inherit;
  font-size:13px;
  font-weight:600;
  cursor:pointer;
  transition:.2s ease;
}

.bs-product-card__cart:hover{
  background:var(--bs-teal-dark);
}

.bs-product-card__cart svg{
  width:16px;
  height:16px;
  fill:none;
  stroke:currentColor;
  stroke-width:1.7;
  stroke-linecap:round;
  stroke-linejoin:round;
}

.bs-product-card__out{
  display:flex;
  align-items:center;
  justify-content:center;
  min-height:42px;
  border-radius:10px;
  background:#f4f6f6;
  color:#7c8b8d;
  font-size:13px;
  font-weight:600;
}


/* ---------------- ARTICOLE ---------------- */

.bs-posts-grid{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:18px;
}

.bs-post{
  display:grid;
  grid-template-columns:120px minmax(0,1fr);
  overflow:hidden;
  border:1px solid var(--bs-line);
  background:#fff;
  transition:.2s ease;
}

.bs-post:hover{
  box-shadow:var(--bs-shadow);
  transform:translateY(-2px);
}

.bs-post__media{
  display:block;
  height:100%;
  min-height:150px;
}

.bs-post__media img{
  width:100%;
  height:100%;
  object-fit:cover;
}

.bs-post__body{
  display:flex;
  flex-direction:column;
  gap:7px;
  padding:16px;
}

.bs-post__tag{
  align-self:flex-start;
  padding:4px 10px;
  border-radius:100px;
  background:var(--bs-teal-soft);
  color:var(--bs-teal);
  font-size:10px;
  font-weight:600;
}

.bs-post__title{
  font-family:Georgia, "Times New Roman", serif;
  color:var(--bs-teal-dark);
  font-size:16px;
  line-height:1.3;
}

.bs-post__title:hover{
  color:var(--bs-teal);
}

.bs-post__text{
  margin:0;
  color:var(--bs-muted);
  font-size:12px;
  line-height:1.55;
}

.bs-post .bs-link{
  margin-top:auto;
}


/* ---------------- DE CE BIOSCEM ---------------- */

.bs-why-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:14px;
}

.bs-why{
  display:flex;
  align-items:flex-start;
  gap:14px;
  padding:22px 20px;
  background:var(--bs-sand);
}

.bs-why__icon{
  flex:0 0 42px;
  width:42px;
  height:42px;
  display:flex;
  align-items:center;
  justify-content:center;
  border-radius:50%;
  background:#fff;
  color:var(--bs-teal);
}

.bs-why__icon svg{
  width:21px;
  height:21px;
  fill:none;
  stroke:currentColor;
  stroke-width:1.5;
  stroke-linecap:round;
  stroke-linejoin:round;
}

.bs-why h3{
  margin:0 0 5px;
  font-size:15px;
}

.bs-why p{
  margin:0;
  color:var(--bs-muted);
  font-size:11px;
  line-height:1.5;
}


/* ---------------- RESPONSIVE ---------------- */

@media(max-width:1050px){

  .bs-needs-grid{
    grid-template-columns:repeat(3,1fr);
  }

  .bs-ranges-grid,
  .bs-products-grid,
  .bs-why-grid{
    grid-template-columns:repeat(2,1fr);
  }

  .bs-posts-grid{
    grid-template-columns:1fr;
  }

  .bs-hero-badges{
    grid-template-columns:repeat(2,1fr);
  }

}

@media(max-width:820px){

  .bs-home{
    padding:18px 16px 50px;
  }

  .bs-hero-main{
    grid-template-columns:1fr;
  }

  .bs-hero-copy{
    padding:32px 24px 8px;
  }

  .bs-hero-copy h1{
    font-size:28px;
  }

  .bs-hero-copy h1 br{
    display:none;
  }

  .bs-hero-media{
    min-height:0;
  }

  .bs-hero-media img{
    max-height:220px;
  }

  .bs-hero-badges{
    padding:16px 24px;
  }

  .bs-section-head{
    flex-direction:column;
    gap:8px;
  }

  .bs-section-head .bs-link{
    position:static;
  }

}

@media(max-width:560px){

  .bs-needs-grid,
  .bs-ranges-grid,
  .bs-products-grid,
  .bs-why-grid{
    grid-template-columns:1fr;
  }

  .bs-post{
    grid-template-columns:1fr;
  }

  .bs-post__media{
    min-height:170px;
  }

}
</style>

<div class="bs-home">

  <!-- =====================================================
       HERO
       ===================================================== -->

  <section class="bs-hero">

    <div class="bs-hero-main">

      <div class="bs-hero-copy">
        <h1>Vitalitate si protectie,<br>intr-o selectie premium de<br>produse pentru echilibru<br>si sanatate.</h1>
        <p>Selectie specializata de suplimente si produse naturale, sustinute de stiinta si experienta.</p>
        <div class="bs-hero-actions">
          <a class="bs-btn" href="/magazin">Descopera produsele <span aria-hidden="true">&rarr;</span></a>
          <a class="bs-btn bs-btn--ghost" href="#bs-needs">Cauta dupa nevoie <span aria-hidden="true">&#9776;</span></a>
        </div>
      </div>

      <div class="bs-hero-media">
        <img src="/uploads/gallery/hero-homepage.png" alt="Produse Bioscem" loading="eager" decoding="async">
      </div>

    </div>

    <div class="bs-hero-badges">
      <?php foreach ($trustBadges as $badge): ?>
        <div class="bs-hero-badge">
          <span class="bs-hero-badge__icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $badge['icon'] ?></svg>
          </span>
          <span><?= htmlspecialchars($badge['title'], ENT_QUOTES) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

  </section>


  <!-- =====================================================
       CU CE TE PUTEM AJUTA (categorii reale din catalog)
       ===================================================== -->

  <?php if ($categories !== []): ?>
    <section class="bs-section" id="bs-needs">

      <h2 class="bs-section-title">Cu ce te putem ajuta?</h2>

      <div class="bs-needs-grid">
        <?php foreach (array_slice($categories, 0, 6) as $category): ?>
          <?php $label = trim((string) ($category['label'] ?? $category['value'] ?? '')); ?>
          <?php if ($label === '') { continue; } ?>
          <a class="bs-need" href="<?= htmlspecialchars($categoryUrl((string) ($category['value'] ?? $label)), ENT_QUOTES) ?>">
            <span class="bs-need__icon">
              <svg viewBox="0 0 24 24" aria-hidden="true"><?= $pickIcon($label) ?></svg>
            </span>
            <span class="bs-need__title"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
            <span class="bs-need__arrow" aria-hidden="true">&rarr;</span>
          </a>
        <?php endforeach; ?>
      </div>

    </section>
  <?php endif; ?>


  <!-- =====================================================
       GAME EMBLEMATICE
       ===================================================== -->

  <section class="bs-ranges">

    <h2 class="bs-section-title">Game emblematice BIOSCEM</h2>

    <div class="bs-ranges-grid">
      <?php foreach ($ranges as $range): ?>
        <a class="bs-range" href="<?= htmlspecialchars($categoryUrl($range['category']), ENT_QUOTES) ?>">
          <img
            class="bs-range__image"
            src="<?= htmlspecialchars($range['image'], ENT_QUOTES) ?>"
            alt="<?= htmlspecialchars($range['title'], ENT_QUOTES) ?>"
            loading="lazy"
            decoding="async"
            onerror="this.style.display='none';"
          >
          <span class="bs-range__body">
            <span class="bs-range__title"><?= htmlspecialchars($range['title'], ENT_QUOTES) ?></span>
            <span class="bs-range__text"><?= htmlspecialchars($range['text'], ENT_QUOTES) ?></span>
          </span>
          <span class="bs-range__arrow" aria-hidden="true">&rsaquo;</span>
        </a>
      <?php endforeach; ?>
    </div>

  </section>


  <!-- =====================================================
       PRODUSE RECOMANDATE
       ===================================================== -->

  <?php if ($products !== []): ?>
    <section class="bs-section">

      <div class="bs-section-head">
        <h2 class="bs-section-title">Produse recomandate</h2>
        <a class="bs-link" href="/magazin">Vezi toate produsele <span aria-hidden="true">&rarr;</span></a>
      </div>

      <div class="bs-products-grid">
        <?php foreach ($products as $product): ?>
          <?php
          $slug = trim((string) ($product['slug'] ?? ''));
          $name = (string) ($product['name'] ?? '');
          $price = (float) ($product['price'] ?? 0);
          $basePrice = (float) ($product['base_price'] ?? $price);
          $hasSale = (bool) ($product['has_sale_price'] ?? false) && $basePrice > $price;
          ?>
          <article class="bs-product-card">

            <a class="bs-product-card__media" href="/produs/<?= rawurlencode($slug) ?>">
              <img
                src="<?= htmlspecialchars((string) ($product['image_url'] ?? '/assets/img/product-placeholder.svg'), ENT_QUOTES) ?>"
                alt="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                loading="lazy"
                decoding="async"
                onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';"
              >
            </a>

            <div class="bs-product-card__body">
              <a class="bs-product-card__name" href="/produs/<?= rawurlencode($slug) ?>">
                <?= htmlspecialchars($name, ENT_QUOTES) ?>
              </a>

              <p class="bs-product-card__price">
                <?php if ($hasSale): ?>
                  <span class="bs-product-card__price-old"><?= number_format($basePrice, 2, ',', '.') ?> lei</span>
                <?php endif; ?>
                <span><?= number_format($price, 2, ',', '.') ?> lei</span>
              </p>

              <?php
                  $cereOferta = (bool) ($product['requires_bbd_selection'] ?? false)
                      || ((int) ($product['bbd_enabled'] ?? 0) === 1
                          && trim((string) ($product['bbd_entries_json'] ?? '')) !== '');
              ?>
              <?php if ((int) ($product['out_of_stock'] ?? 0) === 1): ?>
                <span class="bs-product-card__out">Stoc epuizat</span>
              <?php elseif ($cereOferta): ?>
                <a class="bs-product-card__cart" href="/produs/<?= rawurlencode((string) ($product['slug'] ?? '')) ?>">Alege oferta</a>
              <?php else: ?>
                <form method="post" action="/cos/adauga/<?= (int) ($product['id'] ?? 0) ?>">
                  <button class="bs-product-card__cart" type="submit">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M3 4h2l1.7 8.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 1.9-1.4l1.5-5.2H7.4"/>
                      <circle cx="8.7" cy="19" r="1.4"/>
                      <circle cx="17.5" cy="19" r="1.4"/>
                    </svg>
                    Adauga in cos
                  </button>
                </form>
              <?php endif; ?>
            </div>

          </article>
        <?php endforeach; ?>
      </div>

    </section>
  <?php endif; ?>


  <!-- =====================================================
       ARTICOLE
       ===================================================== -->

  <?php if ($blogPosts !== []): ?>
    <section class="bs-section">

      <div class="bs-section-head">
        <h2 class="bs-section-title">Intelege. Alege informat.</h2>
        <a class="bs-link" href="/blog">Vezi toate articolele <span aria-hidden="true">&rarr;</span></a>
      </div>

      <div class="bs-posts-grid">
        <?php foreach ($blogPosts as $post): ?>
          <?php
          $postSlug = trim((string) ($post['slug'] ?? ''));
          $postUrl = $postSlug !== '' ? '/blog/' . rawurlencode($postSlug) : '/blog';
          $excerpt = trim((string) ($post['excerpt'] ?? ''));
          if ($excerpt === '') {
              $excerpt = mb_substr(trim((string) ($post['content_text'] ?? '')), 0, 140);
          }
          ?>
          <article class="bs-post">

            <a class="bs-post__media" href="<?= htmlspecialchars($postUrl, ENT_QUOTES) ?>">
              <img
                src="<?= htmlspecialchars((string) ($post['cover_image_url'] ?? '/assets/img/product-placeholder.svg'), ENT_QUOTES) ?>"
                alt="<?= htmlspecialchars((string) ($post['title'] ?? ''), ENT_QUOTES) ?>"
                loading="lazy"
                decoding="async"
                onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';"
              >
            </a>

            <div class="bs-post__body">
              <?php if (trim((string) ($post['author_name'] ?? '')) !== ''): ?>
                <span class="bs-post__tag"><?= htmlspecialchars((string) $post['author_name'], ENT_QUOTES) ?></span>
              <?php endif; ?>

              <a class="bs-post__title" href="<?= htmlspecialchars($postUrl, ENT_QUOTES) ?>">
                <?= htmlspecialchars((string) ($post['title'] ?? ''), ENT_QUOTES) ?>
              </a>

              <?php if ($excerpt !== ''): ?>
                <p class="bs-post__text"><?= htmlspecialchars($excerpt, ENT_QUOTES) ?></p>
              <?php endif; ?>

              <a class="bs-link bs-link--small" href="<?= htmlspecialchars($postUrl, ENT_QUOTES) ?>">
                Citeste articolul <span aria-hidden="true">&rarr;</span>
              </a>
            </div>

          </article>
        <?php endforeach; ?>
      </div>

    </section>
  <?php endif; ?>


  <!-- =====================================================
       DE CE BIOSCEM
       ===================================================== -->

  <section class="bs-section">

    <h2 class="bs-section-title">De ce BIOSCEM</h2>

    <div class="bs-why-grid">
      <?php foreach ($whyItems as $item): ?>
        <article class="bs-why">
          <span class="bs-why__icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $item['icon'] ?></svg>
          </span>
          <div>
            <h3><?= htmlspecialchars($item['title'], ENT_QUOTES) ?></h3>
            <p><?= htmlspecialchars($item['text'], ENT_QUOTES) ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

  </section>

</div>
