<?php
$catalogPayload = is_array($catalog ?? null) ? $catalog : [];
$categories = is_array($catalogPayload['categories'] ?? null)
    ? $catalogPayload['categories']
    : (is_array($categories ?? null) ? $categories : []);
$activeCategory = trim((string) ($catalogPayload['activeCategory'] ?? ($activeCategory ?? '')));
$sortKey = trim((string) ($catalogPayload['sort'] ?? ($sort ?? 'alpha_asc')));
$sortOptions = is_array($catalogPayload['sortOptions'] ?? null)
    ? $catalogPayload['sortOptions']
    : (is_array($sortOptions ?? null) ? $sortOptions : []);
$products = is_array($catalogPayload['products'] ?? null)
    ? $catalogPayload['products']
    : (is_array($products ?? null) ? $products : []);
$baseUrl = trim((string) ($catalogPayload['baseUrl'] ?? ($baseUrl ?? '/magazin')));
if ($baseUrl === '') {
    $baseUrl = '/magazin';
}
if ($sortOptions === []) {
    $sortOptions = [
        'alpha_asc' => 'Alfabetică',
        'featured' => 'Popularitate',
        'price_asc' => 'Preț: mic → mare',
        'price_desc' => 'Preț: mare → mic',
    ];
}
?>
<section class="shop-catalog-v2" data-shop-catalog-token="1">
  <div class="shop-catalog-v2__toolbar">
    <button type="button" class="shop-catalog-v2__filter-toggle" data-shop-filters-toggle aria-expanded="false" aria-controls="shop-catalog-filters-panel">
      <span class="shop-catalog-v2__filter-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 5h18"></path>
          <path d="M7 12h10"></path>
          <path d="M10 19h4"></path>
        </svg>
      </span>
      FILTRE
    </button>

    <div class="shop-catalog-v2__sort-wrap">
      <button type="button" class="shop-catalog-v2__sort-btn" data-shop-sort-toggle>
        <?= htmlspecialchars((string) ($sortOptions[$sortKey] ?? 'Alfabetică'), ENT_QUOTES) ?>
        <span class="shop-catalog-v2__sort-chevron" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 10l6 6 6-6"></path>
          </svg>
        </span>
      </button>
      <div class="shop-catalog-v2__sort-menu" data-shop-sort-menu>
        <?php foreach ($sortOptions as $key => $label): ?>
          <a class="shop-catalog-v2__sort-option <?= (string) $key === (string) $sortKey ? 'is-active' : '' ?>" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>?sort=<?= rawurlencode((string) $key) ?><?= $activeCategory !== '' ? '&categorie=' . rawurlencode($activeCategory) : '' ?>">
            <?= htmlspecialchars((string) $label, ENT_QUOTES) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="shop-catalog-v2__filters-overlay" data-shop-filters-overlay></div>
  <div class="shop-catalog-v2__filters" data-shop-filters id="shop-catalog-filters-panel" aria-hidden="true">
    <div class="shop-catalog-v2__filters-head">
      <strong>FILTRE</strong>
      <button type="button" class="shop-catalog-v2__filters-close" data-shop-filters-close aria-label="Închide filtrele">×</button>
    </div>
    <div class="shop-catalog-v2__filters-grid">
      <div class="shop-catalog-v2__filter-col">
        <h4>CATEGORII</h4>
        <label><input type="radio" name="category" data-filter-value="" <?= $activeCategory === '' ? 'checked' : '' ?>> Toate</label>
        <?php foreach ($categories as $category): ?>
          <?php
          $value = trim((string) ($category['value'] ?? ''));
          $label = trim((string) ($category['label'] ?? $value));
          $count = max(0, (int) ($category['count'] ?? 0));
          if ($value === '') {
              continue;
          }
          ?>
          <label>
            <input type="radio" name="category" data-filter-value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $activeCategory === $value ? 'checked' : '' ?>>
            <?= htmlspecialchars($label, ENT_QUOTES) ?><?= $count > 0 ? ' (' . $count . ')' : '' ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if ($products === []): ?>
    <div class="shop-catalog-v2__empty">Nu există produse pentru filtrele selectate.</div>
  <?php else: ?>
    <div class="bv-popular__grid shop-catalog-v2__grid">
      <?php foreach ($products as $product): ?>
        <?php
        $name = trim((string) ($product['name'] ?? 'Produs'));
        $slug = trim((string) ($product['slug'] ?? ''));
        $desc = trim((string) ($product['short_description'] ?? ''));
        $price = (float) ($product['price'] ?? 0);
        $basePrice = (float) ($product['base_price'] ?? $price);
        $hasSale = (bool) ($product['has_sale_price'] ?? false);
        $image = trim((string) ($product['image_url'] ?? ''));
        if ($image === '') {
            $image = '/assets/img/product-placeholder.svg';
        }
        $reviewsCount = max(0, (int) ($product['reviews_count'] ?? 0));
        $reviewsAverage = max(0.0, min(5.0, (float) ($product['reviews_average'] ?? 0)));
        $roundedStars = (int) round($reviewsAverage);
        $outOfStock = (int) ($product['out_of_stock'] ?? 0) === 1;
        $hasBbdOffers = (bool) ($product['requires_bbd_selection'] ?? false)
            || (
                (int) ($product['bbd_enabled'] ?? 0) === 1
                && trim((string) ($product['bbd_entries_json'] ?? '')) !== ''
            );
        $discountMode = (string) ($product['discount_badge_mode'] ?? 'percent') === 'value' ? 'value' : 'percent';
        $discount = 0;
        $discountValue = 0.0;
        if ($basePrice > 0 && $price < $basePrice) {
            $discount = (int) round((($basePrice - $price) / $basePrice) * 100);
            $discountValue = $basePrice - $price;
        }
        $discountValueLabel = rtrim(rtrim(number_format($discountValue, 2, '.', ''), '0'), '.');
        ?>
        <article class="bv-popular-card shop-catalog-v2__card" data-product-category="<?= htmlspecialchars((string) ($product['category'] ?? ''), ENT_QUOTES) ?>">
          <div class="bv-popular-card__media-wrap">
            <a class="bv-popular-card__media" href="/produs/<?= rawurlencode($slug) ?>">
              <img class="bv-popular-card__img" src="<?= htmlspecialchars($image, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES) ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
              <?php if ($discount > 0): ?>
                <?php if ($discountMode === 'value'): ?>
                  <span class="bv-popular-card__bubble bv-popular-card__bubble--value">-<?= $discountValueLabel ?> lei</span>
                <?php else: ?>
                  <span class="bv-popular-card__bubble">-<?= $discount ?>%</span>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($hasBbdOffers): ?>
                <span class="bv-popular-card__bubble bv-popular-card__bubble--offer">OFERTE PREȚ</span>
              <?php endif; ?>
            </a>
          </div>

          <div class="bv-popular-card__body">
            <p class="bv-popular-card__desc"><?= htmlspecialchars($desc !== '' ? $desc : 'Supliment natural', ENT_QUOTES) ?></p>
            <h3 class="bv-popular-card__name"><a href="/produs/<?= rawurlencode($slug) ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></a></h3>

            <div class="bv-popular-card__rating">
              <span class="bv-popular-card__stars" aria-label="Rating <?= number_format($reviewsAverage, 1) ?> din 5">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <svg class="bv-popular-card__star" viewBox="0 0 24 24" fill="<?= $i <= $roundedStars ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3.8l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 17l-5.2 2.8 1-5.8-4.2-4.1 5.8-.8L12 3.8z"/></svg>
                <?php endfor; ?>
              </span>
              <span class="bv-popular-card__reviews">(<?= $reviewsCount ?>)</span>
            </div>

            <div class="bv-popular-card__bottom">
              <p class="bv-popular-card__price">
                <?= number_format($price, 2) ?> lei
                <?php if ($hasSale && $basePrice > $price): ?>
                  <span class="bv-popular-card__old"><?= number_format($basePrice, 2) ?> lei</span>
                <?php endif; ?>
              </p>
              <?php if ($outOfStock): ?>
                <span class="bv-popular-card__stock-out">Stoc epuizat</span>
              <?php elseif ($hasBbdOffers): ?>
                <?php /* Produsul are oferte cu dată de expirare, deci nu poate fi
                        adăugat fără să fie aleasă una. Butonul de coș trimitea
                        formularul fără alegere: vizitatorul era întors la pagina
                        produsului, iar el credea că l-a pus în coș. Aici îl ducem
                        direct acolo unde alege. */ ?>
                <a class="bv-popular-card__cart-btn bv-popular-card__cart-btn--choose"
                   href="/produs/<?= rawurlencode($slug) ?>" aria-label="Alege oferta">
                  Alege oferta
                </a>
              <?php else: ?>
                <form method="post" action="/cos/adauga/<?= (int) ($product['id'] ?? 0) ?>">
                  <button type="submit" class="bv-popular-card__cart-btn" aria-label="Adaugă în coș">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 7H7"/></svg>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<style>
.shop-catalog-v2{background:#fff;padding:20px 0 34px;}
.shop-catalog-v2__toolbar{width:min(1200px,92%);margin:0 auto 14px;display:flex;justify-content:space-between;align-items:center;gap:12px;}
.shop-catalog-v2__filter-toggle{border:0;background:none;font:700 12px/1 "DM Sans",Arial,sans-serif;letter-spacing:.06em;color:#111827;display:inline-flex;align-items:center;gap:8px;cursor:pointer;}
.shop-catalog-v2__filter-icon{width:18px;height:18px;display:inline-grid;place-items:center;color:#0f7b53;}
.shop-catalog-v2__filter-icon svg{width:18px;height:18px;display:block;}
.shop-catalog-v2__sort-wrap{position:relative;}
.shop-catalog-v2__sort-btn{border:1.5px solid #8ab69f;background:#f8fbf9;padding:8px 14px;border-radius:999px;font:700 14px/1.1 "DM Sans",Arial,sans-serif;color:#0f7b53;cursor:pointer;display:inline-flex;align-items:center;gap:8px;}
.shop-catalog-v2__sort-chevron{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;transition:transform .2s ease;}
.shop-catalog-v2__sort-chevron svg{width:15px;height:15px;display:block;}
.shop-catalog-v2__sort-btn.is-open .shop-catalog-v2__sort-chevron{transform:rotate(180deg);}
.shop-catalog-v2__sort-menu{position:absolute;right:0;top:calc(100% + 10px);min-width:220px;background:#f7f8f7;border:1px solid #d4ddd7;border-radius:14px;box-shadow:0 14px 30px rgba(15,23,42,.14);padding:8px;display:none;z-index:30;}
.shop-catalog-v2__sort-menu.is-open{display:block;}
.shop-catalog-v2__sort-option{display:block;padding:10px 12px;border-radius:10px;color:#0f172a;text-decoration:none;font:500 14px/1.25 "DM Sans",Arial,sans-serif;}
.shop-catalog-v2__sort-option:hover{background:#ecefec;}
.shop-catalog-v2__sort-option.is-active{background:#dce7de;color:#0f7b53;font-weight:700;}

.shop-catalog-v2__filters-overlay{
  display:none;position:fixed;inset:0;background:rgba(15,23,42,.34);opacity:0;visibility:hidden;pointer-events:none;
  transition:opacity .25s ease, visibility .25s ease;z-index:1250;
}
.shop-catalog-v2__filters-overlay.is-open{opacity:1;visibility:visible;pointer-events:auto;}
.shop-catalog-v2__filters{
  width:min(1200px,92%);margin:0 auto 18px;border:1px solid transparent;border-radius:4px;background:#fff;
  padding:0 18px;max-height:0;overflow:hidden;pointer-events:none;
  transition:max-height .34s cubic-bezier(.25,.8,.25,1), padding-top .34s cubic-bezier(.25,.8,.25,1), padding-bottom .34s cubic-bezier(.25,.8,.25,1), margin-bottom .34s cubic-bezier(.25,.8,.25,1), border-color .2s ease;
}
.shop-catalog-v2__filters.is-open{
  max-height:720px;pointer-events:auto;border-color:#1118274d;
  padding-top:18px;padding-bottom:18px;
}
.shop-catalog-v2__filters-head{display:none;}
.shop-catalog-v2__filters-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;}
.shop-catalog-v2__filter-col{position:relative;padding-right:14px;}
.shop-catalog-v2__filter-col:not(:last-child){border-right:1px solid #d1d5db;}
.shop-catalog-v2__filter-col h4{margin:0 0 10px;font:700 11px/1 "DM Sans",Arial,sans-serif;letter-spacing:.04em;color:#111827;}
.shop-catalog-v2__filter-col label{display:flex;align-items:center;gap:8px;margin:8px 0;font:500 12px/1.2 "DM Sans",Arial,sans-serif;color:#111827;cursor:pointer;}

.shop-catalog-v2__grid{width:min(1200px,92%);margin:0 auto;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;}

/* exact popular cards */
.bv-popular-card{
  background:#fff;border:1px solid #dbe7df;border-radius:22px;overflow:hidden;box-shadow:0 1px 0 rgba(0,0,0,.02);
  transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease;
}
.bv-popular-card:hover{transform:translateY(-2px);border-color:#cfe0d7;box-shadow:0 10px 24px rgba(22,62,45,.08);}
.bv-popular-card__media-wrap{margin:0;padding:14px;background:#edf4ef;border-radius:22px 22px 0 0;}
.bv-popular-card__media{position:relative;display:block;aspect-ratio:1/1;border-radius:14px;overflow:hidden;background:#f5f1eb;}
.bv-popular-card__img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease, filter .35s ease;}
.bv-popular-card:hover .bv-popular-card__img{transform:scale(1.045);filter:saturate(1.03);}
.bv-popular-card__bubble{position:absolute;top:8px;right:8px;z-index:2;min-width:56px;height:56px;padding:0 8px;border-radius:999px;background:#e31c23;color:#fff;font:800 18px/1 "DM Sans",Arial,sans-serif;letter-spacing:-0.02em;white-space:nowrap;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 8px 16px rgba(227,28,35,.28);}
.bv-popular-card__bubble--value{width:auto;padding:0 14px;font-size:15px;}
.bv-popular-card__bubble--offer{
  left:8px;
  right:auto;
  min-width:0;
  height:auto;
  padding:8px 10px;
  border-radius:999px;
  background:#107a4d;
  color:#fff;
  font:800 11px/1 "DM Sans",Arial,sans-serif;
  letter-spacing:.04em;
  box-shadow:0 8px 18px rgba(16,122,77,.25);
}
.bv-popular-card__body{padding:12px 14px 14px;}
.bv-popular-card__desc{margin:0 0 8px;font-family:"DM Sans",Arial,sans-serif;font-size:12px;font-weight:400;line-height:1.35;letter-spacing:.06em;text-transform:uppercase;color:#6f8277;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bv-popular-card__name{margin:0 0 8px;font-family:"Playfair Display",Georgia,serif;font-size:26px;font-weight:600;line-height:1.08;color:#1f2a25;}
.bv-popular-card__name a{color:inherit;text-decoration:none;}
.bv-popular-card__rating{margin:0 0 12px;display:flex;align-items:center;gap:6px;}
.bv-popular-card__stars{display:inline-flex;align-items:center;gap:2px;color:#f4b425;}
.bv-popular-card__star{width:14px;height:14px;display:block;}
.bv-popular-card__reviews{font:400 12px/1 "DM Sans",Arial,sans-serif;color:#6f8277;}
.bv-popular-card__bottom{display:flex;align-items:center;justify-content:space-between;gap:10px;}
.bv-popular-card__price{margin:0;font:700 22px/1.1 "DM Sans",Arial,sans-serif;color:#1f2a25;}
.bv-popular-card__old{margin-left:6px;font:500 12px/1 "DM Sans",Arial,sans-serif;color:#8ea196;text-decoration:line-through;}
.bv-popular-card__cart-btn{width:40px;height:40px;border:0;border-radius:999px;background:#1f8b57;color:#fff;display:grid;place-items:center;cursor:pointer;}
.bv-popular-card__cart-btn svg{width:16px;height:16px;}
.bv-popular-card__stock-out{
  display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 12px;border-radius:999px;
  border:1px solid #fecaca;background:#fff5f5;color:#b91c1c;font:700 12px/1 "DM Sans",Arial,sans-serif;white-space:nowrap;
}

.bv-popular .bv-popular__grid > .bv-popular-card,
.shop-catalog-v2 .bv-popular__grid > .bv-popular-card{border-radius:22px !important;overflow:hidden !important;-webkit-mask-image:-webkit-radial-gradient(white, black);clip-path: inset(0 round 22px);}
.shop-catalog-v2 .bv-popular__grid > .bv-popular-card .bv-popular-card__media-wrap{border-radius:22px 22px 0 0 !important;overflow:hidden !important;}
.shop-catalog-v2 .bv-popular__grid > .bv-popular-card .bv-popular-card__media{border-radius:14px !important;overflow:hidden !important;}

.shop-catalog-v2__empty{width:min(1200px,92%);margin:0 auto;background:#fff;border:1px dashed #c8d9cf;border-radius:12px;padding:14px;color:#5f756a;font:600 14px/1.4 "DM Sans",Arial,sans-serif;}

@media (max-width:1100px){
  .shop-catalog-v2__grid{grid-template-columns:repeat(2,minmax(0,1fr));}
  .shop-catalog-v2__filters-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
  .shop-catalog-v2__filter-col:nth-child(2n){border-right:0;padding-right:0;}
}
@media (min-width:681px){
  .shop-catalog-v2__filters{margin-bottom:0;}
  .shop-catalog-v2__filters.is-open{margin-bottom:18px;}
}
@media (max-width:680px){
  .shop-catalog-v2__filters-overlay{display:block;}
  .shop-catalog-v2__toolbar{align-items:flex-start;}
  .shop-catalog-v2__filters{
    position:fixed;left:0;top:0;bottom:0;width:min(88vw,360px);margin:0;z-index:1260;background:#fff;border:0;
    border-radius:0 18px 18px 0;max-height:none;opacity:1;transform:translateX(-106%);pointer-events:none;
    padding:18px 16px 22px;overflow-y:auto;transition:transform .28s cubic-bezier(.2,.8,.2,1);
  }
  .shop-catalog-v2__filters.is-open{transform:translateX(0);pointer-events:auto;padding:18px 16px 22px;}
  .shop-catalog-v2__filters-head{
    display:flex;align-items:center;justify-content:space-between;padding-bottom:12px;margin-bottom:14px;border-bottom:1px solid #e5e7eb;
  }
  .shop-catalog-v2__filters-head strong{font:700 19px/1 "DM Sans",Arial,sans-serif;letter-spacing:.04em;color:#111827;}
  .shop-catalog-v2__filters-close{
    border:0;background:none;color:#111827;font:400 30px/1 Arial,sans-serif;line-height:1;cursor:pointer;padding:2px 4px;
  }
  .shop-catalog-v2__filters-grid{grid-template-columns:1fr;}
  .shop-catalog-v2__filter-col{border-right:0;padding-right:0;}
  .shop-catalog-v2__sort-btn{font-size:13px;border:1px solid #9bd1b7;padding:8px 14px;border-radius:999px;color:#0f7b53;}
  .shop-catalog-v2__sort-menu{min-width:190px;padding:6px;}
  .shop-catalog-v2__sort-option{font-size:12px;line-height:1.3;padding:8px 10px;}
  .shop-catalog-v2__grid{
    grid-template-columns:repeat(2,minmax(0,1fr));
    grid-auto-flow:row;
    gap:12px;
    overflow:visible;
    padding-bottom:0;
  }
  .bv-popular-card__bubble{min-width:44px;height:44px;padding:0 6px;font-size:16px;}
  .bv-popular-card__bubble--offer{padding:6px 8px;height:auto;font-size:9px;}
  .bv-popular-card__bottom{flex-wrap:nowrap;align-items:center;gap:8px;}
  .bv-popular-card__bottom > .bv-popular-card__price{min-width:0;flex:1 1 auto;}
  .bv-popular-card__stock-out{
    width:34px;
    height:34px;
    min-height:34px;
    flex:0 0 34px;
    position:relative;
    margin-left:0;
    padding:0;
    border-radius:999px;
    white-space:normal;
    line-height:1;
    text-align:center;
    font-size:0;
    display:grid;
    place-items:center;
  }
  .bv-popular-card__stock-out::before{
    content:"Stoc\A epuizat";
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    white-space:pre;
    font:700 7px/1.05 "DM Sans",Arial,sans-serif;
    color:#b91c1c;
  }
  .bv-popular-card__price{font-size:17px !important;line-height:1.1;}
  .bv-popular-card__old{font-size:10px !important;margin-left:4px;}
  .bv-popular-card__cart-btn{width:34px;height:34px;}
  .bv-popular-card__cart-btn svg{width:14px;height:14px;}
  .bv-popular-card__name{font-size:22px;}
}
</style>

<script>
(() => {
  const root = document.querySelector('[data-shop-catalog-token="1"]');
  if (!root) return;

  const filters = root.querySelector('[data-shop-filters]');
  const toggleFilters = root.querySelector('[data-shop-filters-toggle]');
  const closeFilters = root.querySelector('[data-shop-filters-close]');
  const filtersOverlay = root.querySelector('[data-shop-filters-overlay]');
  const sortToggle = root.querySelector('[data-shop-sort-toggle]');
  const sortMenu = root.querySelector('[data-shop-sort-menu]');

  const setFiltersOpen = (isOpen) => {
    filters?.classList.toggle('is-open', isOpen);
    filtersOverlay?.classList.toggle('is-open', isOpen);
    if (toggleFilters) {
      toggleFilters.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
    if (filters) {
      filters.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    }
  };

  toggleFilters?.addEventListener('click', () => {
    const isOpen = filters?.classList.contains('is-open') ?? false;
    setFiltersOpen(!isOpen);
  });
  closeFilters?.addEventListener('click', () => setFiltersOpen(false));
  filtersOverlay?.addEventListener('click', () => setFiltersOpen(false));

  sortToggle?.addEventListener('click', (event) => {
    event.preventDefault();
    sortMenu?.classList.toggle('is-open');
    sortToggle.classList.toggle('is-open');
  });

  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Node)) return;
    if (!root.contains(event.target)) {
      sortMenu?.classList.remove('is-open');
      sortToggle?.classList.remove('is-open');
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setFiltersOpen(false);
      sortMenu?.classList.remove('is-open');
      sortToggle?.classList.remove('is-open');
    }
  });

  root.querySelectorAll('input[data-filter-value]').forEach((input) => {
    input.addEventListener('change', () => {
      const value = String(input.getAttribute('data-filter-value') || '');
      const url = new URL(window.location.href);
      if (value === '') {
        url.searchParams.delete('categorie');
        url.searchParams.delete('category');
      } else {
        url.searchParams.set('categorie', value);
      }
      window.location.href = url.toString();
    });
  });
})();
</script>
