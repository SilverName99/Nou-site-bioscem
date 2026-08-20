<section class="panel">
    <h1>Magazin</h1>
    <p>Listă produse (v1). Urmează filtre/categorii exact ca în site-ul actual.</p>
    <div class="grid">
        <?php foreach ($products as $product): ?>
            <?php
            $badges = [];
            if ((int) (($product['badge_popular'] ?? $product['label_popular']) ?? 0) === 1) {
                $badges[] = ['slug' => 'popular', 'label' => 'Popular'];
            }
            if ((int) (($product['badge_best_seller'] ?? $product['label_best_seller']) ?? 0) === 1) {
                $badges[] = ['slug' => 'best-seller', 'label' => 'Cel mai bine vândut'];
            }
            if ((int) (($product['badge_seasonal'] ?? $product['label_seasonal']) ?? 0) === 1) {
                $badges[] = ['slug' => 'seasonal', 'label' => 'De sezon'];
            }
            ?>
            <article class="card">
                <div class="card-media">
                    <img
                        src="<?= htmlspecialchars((string) ($product['image_url'] ?? '/assets/img/product-placeholder.svg'), ENT_QUOTES) ?>"
                        alt="<?= htmlspecialchars((string) ($product['name'] ?? 'Produs Bioscem'), ENT_QUOTES) ?>"
                        loading="lazy"
                        decoding="async"
                        onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';"
                    >
                    <?php if ($badges !== []): ?>
                        <div class="product-card-badges">
                            <?php foreach ($badges as $badge): ?>
                                <span class="product-badge product-badge--<?= htmlspecialchars((string) ($badge['slug'] ?? ''), ENT_QUOTES) ?>">
                                    <?= htmlspecialchars((string) ($badge['label'] ?? ''), ENT_QUOTES) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <h3><?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES) ?></h3>
                    <p><?= htmlspecialchars((string) ($product['short_description'] ?? ''), ENT_QUOTES) ?></p>
                    <p class="price">
                        <?php if ((bool) ($product['has_sale_price'] ?? false)): ?>
                            <span class="price-old"><?= number_format((float) ($product['base_price'] ?? $product['price'] ?? 0), 2) ?> lei</span>
                        <?php endif; ?>
                        <span><?= number_format((float) ($product['price'] ?? 0), 2) ?> lei</span>
                    </p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="btn btn-secondary" href="/produs/<?= urlencode((string) ($product['slug'] ?? '')) ?>">Vezi produs</a>
                        <?php
                            // Cu oferte pe dată de expirare, adăugarea directă e
                            // refuzată: alegerea se face pe pagina produsului.
                            $cereOferta = (bool) ($product['requires_bbd_selection'] ?? false)
                                || ((int) ($product['bbd_enabled'] ?? 0) === 1
                                    && trim((string) ($product['bbd_entries_json'] ?? '')) !== '');
                        ?>
                        <?php if ((int) ($product['out_of_stock'] ?? 0) === 1): ?>
                            <span class="btn btn-secondary" style="opacity:.9;cursor:default;pointer-events:none;">Stoc epuizat</span>
                        <?php elseif ($cereOferta): ?>
                            <a class="btn btn-primary" href="/produs/<?= rawurlencode((string) ($product['slug'] ?? '')) ?>">Alege oferta</a>
                        <?php else: ?>
                            <form method="post" action="/cos/adauga/<?= (int) ($product['id'] ?? 0) ?>">
                                <button class="btn" type="submit">Adaugă în coș</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
