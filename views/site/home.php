<section class="hero">
    <h1>Magazin Bioscem - variantă custom</h1>
    <p>
        Acesta este punctul de pornire pentru noul site: mai rapid, mai curat și cu control complet în admin.
    </p>
    <p>
        În etapele următoare clonăm paginile 1:1 și conectăm toate funcționalitățile (Stripe, FAN, email-uri, fidelizare).
    </p>
    <a href="/magazin" class="btn">Vezi produsele</a>
</section>

<section class="panel">
    <h2>Produse recomandate</h2>
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
                    <?php if ($badges !== []): ?>
                        <div class="product-card-badges">
                            <?php foreach ($badges as $badge): ?>
                                <span class="product-badge product-badge--<?= htmlspecialchars((string) $badge['slug'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars((string) $badge['label'], ENT_QUOTES) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <img
                        src="<?= htmlspecialchars((string) ($product['image_url'] ?? '/assets/img/product-placeholder.svg'), ENT_QUOTES) ?>"
                        alt="<?= htmlspecialchars((string) ($product['name'] ?? 'Produs Bioscem'), ENT_QUOTES) ?>"
                        loading="lazy"
                        decoding="async"
                        onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';"
                    >
                </div>
                <div class="card-body">
                    <h3><?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES) ?></h3>
                    <p><?= htmlspecialchars((string) ($product['short_description'] ?? ''), ENT_QUOTES) ?></p>
                    <p class="price">
                        <?php if ((bool) ($product['has_sale_price'] ?? false) && (float) ($product['base_price'] ?? 0) > (float) ($product['price'] ?? 0)): ?>
                            <span class="price-old"><?= number_format((float) ($product['base_price'] ?? 0), 2) ?> lei</span>
                        <?php endif; ?>
                        <span><?= number_format((float) ($product['price'] ?? 0), 2) ?> lei</span>
                    </p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="btn btn-secondary" href="/produs/<?= urlencode((string) ($product['slug'] ?? '')) ?>">Detalii</a>
                        <form method="post" action="/cos/adauga/<?= (int) ($product['id'] ?? 0) ?>">
                            <button class="btn" type="submit">Adaugă</button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
