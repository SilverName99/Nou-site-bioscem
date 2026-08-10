<section class="panel">
    <div class="section-head">
        <div>
            <h1>Produse</h1>
            <p>Gestionează produsele magazinului tău.</p>
        </div>
        <div style="display:flex;gap:8px;">
            <a class="btn btn-secondary" href="/admin/products/export" title="Descarcă lista de produse (CSV) pentru import în ERP">⭳ Export produse</a>
            <a class="btn btn-secondary" href="/admin/products/trash">Coș produse</a>
            <button class="btn" type="button" id="add-product-btn">+ Adaugă produs</button>
        </div>
    </div>

    <div class="product-table-head">
        <span>Imagine</span>
        <span>Produs</span>
        <span>Categorie</span>
        <span>Preț</span>
        <span>Stoc</span>
        <span>Status</span>
        <span>Acțiuni</span>
    </div>

    <div class="product-list">
        <?php foreach ($products as $product): ?>
            <article class="product-row">
                <div class="col-image">
                    <div class="product-image-thumb-wrap">
                        <?php if ((int) ($product['badge_popular'] ?? 0) === 1): ?>
                            <span class="product-badge-chip product-badge-chip--popular">Popular</span>
                        <?php endif; ?>
                        <?php if ((int) ($product['badge_best_seller'] ?? 0) === 1): ?>
                            <span class="product-badge-chip product-badge-chip--best">Cel mai bine vândut</span>
                        <?php endif; ?>
                        <?php if ((int) ($product['badge_seasonal'] ?? 0) === 1): ?>
                            <span class="product-badge-chip product-badge-chip--season">De sezon</span>
                        <?php endif; ?>
                        <img src="<?= htmlspecialchars((string) ($product['image_url'] ?: '/assets/img/product-placeholder.svg'), ENT_QUOTES) ?>"
                             alt="<?= htmlspecialchars((string) $product['name'], ENT_QUOTES) ?>"
                             onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                    </div>
                </div>
                <div class="col-product">
                    <strong><?= htmlspecialchars((string) $product['name'], ENT_QUOTES) ?></strong>
                    <p>SKU: <?= htmlspecialchars((string) ($product['sku'] ?? 'N/A'), ENT_QUOTES) ?></p>
                </div>
                <div class="col-category">
                    <?= htmlspecialchars((string) ($product['category_name'] ?: $product['category'] ?: 'Fără categorie'), ENT_QUOTES) ?>
                </div>
                <div class="col-price">
                    <?php
                    $basePrice = (float) ($product['base_price'] ?? $product['price'] ?? 0);
                    $currentPrice = (float) ($product['price'] ?? 0);
                    $hasSalePrice = (bool) ($product['has_sale_price'] ?? false) && $basePrice > 0 && $currentPrice < $basePrice;
                    ?>
                    <strong><?= number_format($currentPrice, 2) ?> RON</strong>
                    <?php if ($hasSalePrice): ?>
                        <small class="old-price"><?= number_format($basePrice, 2) ?> RON</small>
                    <?php endif; ?>
                </div>
                <div class="col-stock"><?= (int) $product['stock'] ?></div>
                <div class="col-status">
                    <span class="status-pill <?= ((int) $product['is_active'] === 1) ? 'ok' : 'off' ?>">
                        <?= ((int) $product['is_active'] === 1) ? 'activ' : 'inactiv' ?>
                    </span>
                    <?php if ((int) ($product['out_of_stock'] ?? 0) === 1): ?>
                        <span class="status-pill off">fără stoc</span>
                    <?php endif; ?>
                </div>
                <div class="col-actions">
                    <button
                        type="button"
                        class="icon-btn edit-product-btn"
                        data-product='<?= htmlspecialchars(json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'
                        title="Editează"
                    >✎</button>
                    <form method="post" action="/admin/products/<?= (int) $product['id'] ?>/delete" onsubmit="return confirm('Ștergi produsul?');">
                        <button type="submit" class="icon-btn danger" title="Șterge">🗑</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="modal-overlay" id="product-modal">
    <div class="modal-card">
        <div class="modal-head product-modal-head">
            <h3 id="product-modal-title">Adaugă produs</h3>
            <button type="button" class="icon-btn" id="close-product-modal" aria-label="Închide">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                    <path fill="currentColor" d="M18.3 5.71 12 12l6.3 6.29-1.41 1.42L10.59 13.4 4.29 19.7 2.88 18.29 9.17 12 2.88 5.71 4.29 4.29l6.3 6.3 6.29-6.3z"/>
                </svg>
            </button>
        </div>
        <form method="post" action="/admin/products" id="product-form" class="product-editor-form">
            <div class="product-editor-columns">
                <div class="product-editor-main">
                    <section class="panel product-editor-card">
                        <div class="product-editor-card__head">
                            <h4>Informații de bază</h4>
                            <p>Detalii esențiale despre produs.</p>
                        </div>
                        <div class="product-editor-grid">
                            <div class="field">
                                <label>Nume produs *</label>
                                <input type="text" name="name" id="p_name" required>
                            </div>
                            <div class="field">
                                <label>SKU</label>
                                <input type="text" name="sku" id="p_sku">
                            </div>
                            <div class="field">
                                <label>Slug *</label>
                                <input type="text" name="slug" id="p_slug" required>
                            </div>
                            <div class="field">
                                <label>Categorie</label>
                                <select name="category_id" id="p_category_id">
                                    <option value="">Fără categorie</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars((string) $category['name'], ENT_QUOTES) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Template produs</label>
                                <select name="product_template_id" id="p_product_template_id">
                                    <option value="">Template implicit</option>
                                    <?php foreach (($productTemplates ?? []) as $template): ?>
                                        <option value="<?= (int) ($template['id'] ?? 0) ?>">
                                            <?= htmlspecialchars((string) (($template['name'] ?? 'Template') . ' (' . ($template['slug'] ?? '') . ')'), ENT_QUOTES) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>TVA (%)</label>
                                <input type="number" min="0" max="100" step="0.01" name="vat_percent" id="p_vat_percent" value="19.00">
                            </div>
                            <div class="field">
                                <label>TVA în preț</label>
                                <select name="vat_included" id="p_vat_included">
                                    <option value="1" selected>Da (inclus în preț)</option>
                                    <option value="0">Nu (se adaugă peste preț)</option>
                                </select>
                            </div>
                        </div>
                    </section>

                    <section class="panel product-editor-card">
                        <div class="product-editor-card__head">
                            <h4>Prețuri & stoc</h4>
                            <p>Gestionează prețul, promoțiile și disponibilitatea.</p>
                        </div>
                        <div class="product-editor-grid">
                            <div class="field">
                                <label>Preț (RON) *</label>
                                <input type="number" step="0.01" name="price" id="p_price" required>
                            </div>
                            <div class="field">
                                <label>Preț redus (RON)</label>
                                <input type="number" step="0.01" name="sale_price" id="p_sale_price">
                            </div>
                            <div class="field">
                                <label>Bulină reducere</label>
                                <select name="discount_badge_mode" id="p_discount_badge_mode">
                                    <option value="percent">Procentual (ex: -23%)</option>
                                    <option value="value">Valoric (ex: -20 lei)</option>
                                </select>
                                <small class="muted">Cum apare reducerea în bulina roșie de pe cardul produsului.</small>
                            </div>
                            <div class="field full">
                                <label>Programare preț redus (mai multe perioade)</label>
                                <input type="hidden" name="sale_price_periods_json" id="p_sale_price_periods_json" value="[]">
                                <div id="p_sale_periods_list" style="display:grid;gap:8px;"></div>
                                <button class="btn btn-secondary" type="button" id="p_add_sale_period" style="margin-top:8px;">+ Adaugă perioadă</button>
                                <small class="muted">Ex: 12.06.2026 - 20.06.2026 (preț X), apoi 05.09.2026 - 22.10.2026 (preț Y).</small>
                            </div>
                            <div class="field full">
                                <label style="display:inline-flex;align-items:center;gap:8px;">
                                    <input type="checkbox" name="bbd_enabled" id="p_bbd_enabled" value="1">
                                    Activează BBD-uri
                                </label>
                                <input type="hidden" name="bbd_entries_json" id="p_bbd_entries_json" value="[]">
                                <div id="p_bbd_list" style="display:grid;gap:8px;margin-top:8px;"></div>
                                <button class="btn btn-secondary" type="button" id="p_add_bbd_entry" style="margin-top:8px;">+ Adaugă ofertă</button>
                                <small class="muted">Setează data de expirare, opțional un preț redus și opțional stoc separat per ofertă.</small>
                            </div>
                            <div class="field">
                                <label>Stoc</label>
                                <input type="number" name="stock" id="p_stock">
                            </div>
                            <div class="field">
                                <label>Greutate (g)</label>
                                <input type="number" name="weight_grams" id="p_weight_grams">
                            </div>
                        </div>
                    </section>

                    <section class="panel product-editor-card">
                        <div class="product-editor-card__head">
                            <h4>Descriere & marketing</h4>
                            <p>Textele afișate pe pagina produsului.</p>
                        </div>
                        <div class="product-editor-grid">
                            <div class="field full">
                                <label>Descriere scurtă</label>
                                <textarea name="short_description" id="p_short_description" rows="3"></textarea>
                            </div>
                            <div class="field full">
                                <label>Descriere</label>
                                <textarea name="description" id="p_description" rows="5"></textarea>
                            </div>
                            <div class="field full">
                                <label>Puncte forte (separate prin punct și virgulă)</label>
                                <input type="text" name="product_highlights" id="p_product_highlights" placeholder="Ex: Absorbție rapidă; Formula premium; Fără zahăr">
                            </div>
                            <div class="field full">
                                <label style="display:inline-flex;align-items:center;gap:8px;">
                                    <input type="checkbox" name="post_cart_note_enabled" id="p_post_cart_note_enabled" value="1">
                                    Afișează text sub butonul „Adaugă în coș”
                                </label>
                                <textarea
                                    name="post_cart_note_text"
                                    id="p_post_cart_note_text"
                                    rows="3"
                                    placeholder="Ex: Livrare în 24h. Stoc limitat pentru lotul promoțional."
                                    style="margin-top:8px;display:none;"
                                ></textarea>
                            </div>
                        </div>
                    </section>

                    <section class="panel product-editor-card">
                        <div class="product-editor-card__head">
                            <h4>Conținut produs</h4>
                            <p>Câmpuri suplimentare pentru template-ul produsului.</p>
                        </div>
                        <div class="product-editor-grid">
                            <?php foreach (($extraFields ?? []) as $field): ?>
                                <?php
                                if (!is_array($field)) {
                                    continue;
                                }
                                if ((int) ($field['is_active'] ?? 1) !== 1) {
                                    continue;
                                }
                                $fieldId = (int) ($field['id'] ?? 0);
                                if ($fieldId <= 0) {
                                    continue;
                                }
                                $fieldName = (string) ($field['name'] ?? '');
                                $fieldType = (string) ($field['field_type'] ?? 'text');
                                $fieldPlaceholder = (string) ($field['placeholder'] ?? '');
                                $fieldDefault = (string) ($field['default_value'] ?? '');
                                ?>
                                <div class="field full">
                                    <label><?= htmlspecialchars($fieldName !== '' ? $fieldName : ('Câmp #' . $fieldId), ENT_QUOTES) ?></label>
                                    <?php if ($fieldType === 'textarea' || $fieldType === 'html'): ?>
                                        <textarea
                                            name="extra_fields[<?= $fieldId ?>]"
                                            id="p_extra_<?= $fieldId ?>"
                                            rows="<?= $fieldType === 'html' ? 6 : 3 ?>"
                                            placeholder="<?= htmlspecialchars($fieldPlaceholder, ENT_QUOTES) ?>"
                                            data-default="<?= htmlspecialchars($fieldDefault, ENT_QUOTES) ?>"
                                        ></textarea>
                                    <?php else: ?>
                                        <input
                                            type="text"
                                            name="extra_fields[<?= $fieldId ?>]"
                                            id="p_extra_<?= $fieldId ?>"
                                            placeholder="<?= htmlspecialchars($fieldPlaceholder, ENT_QUOTES) ?>"
                                            data-default="<?= htmlspecialchars($fieldDefault, ENT_QUOTES) ?>"
                                        >
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>

                <aside class="product-editor-side">
                    <section class="panel product-editor-card">
                        <div class="product-editor-card__head">
                            <h4>Imagini</h4>
                            <p>Imagine principală și galerie produs.</p>
                        </div>
                        <div class="product-editor-grid product-editor-grid--single">
                            <div class="field full">
                                <label>Imagine produs</label>
                                <input type="hidden" name="image_url" id="p_image_url">
                                <div class="product-image-picker-inline">
                                    <img id="p_image_preview" src="/assets/img/product-placeholder.svg" alt="Preview imagine produs" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                                    <div>
                                        <button class="btn btn-secondary" type="button" id="open-image-picker">Selectează imagine</button>
                                        <p id="p_image_label" class="muted" style="margin:8px 0 0;font-size:12px;">/assets/img/product-placeholder.svg</p>
                                    </div>
                                </div>
                            </div>
                            <div class="field full">
                                <label>Imagini secundare (carusel)</label>
                                <input type="hidden" name="gallery_images_json" id="p_gallery_images_json" value="[]">
                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
                                    <button class="btn btn-secondary" type="button" id="open-gallery-picker">+ Adaugă imagine secundară</button>
                                    <button class="btn btn-secondary" type="button" id="clear-gallery-btn">Golește lista</button>
                                </div>
                                <div id="product-gallery-selected" class="product-gallery-selected"></div>
                            </div>
                        </div>
                    </section>

                    <section class="panel product-editor-card">
                        <div class="product-editor-card__head">
                            <h4>SEO</h4>
                            <p>Setări individuale SEO pentru produs.</p>
                        </div>
                        <div class="product-editor-grid product-editor-grid--single">
                            <div class="field full">
                                <label for="p_seo_title">Meta title</label>
                                <input type="text" name="seo_title" id="p_seo_title" placeholder="Titlu SEO produs">
                            </div>
                            <div class="field full">
                                <label for="p_seo_description">Meta description</label>
                                <textarea name="seo_description" id="p_seo_description" rows="3" placeholder="Descriere scurtă pentru Google"></textarea>
                            </div>
                            <div class="field full">
                                <label for="p_seo_canonical_url">Canonical URL (opțional)</label>
                                <input type="url" name="seo_canonical_url" id="p_seo_canonical_url" placeholder="https://exemplu.ro/produs/slug">
                            </div>
                            <div class="field full">
                                <label>Imagine social preview (OG/Twitter)</label>
                                <input type="hidden" name="seo_image_url" id="p_seo_image_url">
                                <div class="product-image-picker-inline">
                                    <img id="p_seo_image_preview" src="/assets/img/product-placeholder.svg" alt="Preview imagine SEO" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                                    <div>
                                        <button class="btn btn-secondary" type="button" id="open-seo-image-picker">Selectează imagine</button>
                                        <p id="p_seo_image_label" class="muted" style="margin:8px 0 0;font-size:12px;">/assets/img/product-placeholder.svg</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel product-editor-card">
                        <div class="product-editor-card__head">
                            <h4>Produse similare & setări</h4>
                            <p>Asocieri, badge-uri și stare produs.</p>
                        </div>
                        <div class="product-editor-grid product-editor-grid--single">
                            <div class="field full">
                                <label>Produse similare (carusel)</label>
                                <input type="hidden" name="similar_products_json" id="p_similar_products_json" value="[]">
                                <div class="similar-products-admin-picker" id="similar-products-admin-picker">
                                    <?php foreach ($products as $candidate): ?>
                                        <?php
                                        if (!is_array($candidate)) {
                                            continue;
                                        }
                                        $candidateId = (int) ($candidate['id'] ?? 0);
                                        if ($candidateId <= 0) {
                                            continue;
                                        }
                                        ?>
                                        <label class="similar-products-admin-picker__item">
                                            <input type="checkbox" value="<?= $candidateId ?>" data-similar-product-id="1">
                                            <span><?= htmlspecialchars((string) ($candidate['name'] ?? ('Produs #' . $candidateId)), ENT_QUOTES) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <small class="muted">Selectează până la 12 produse similare.</small>
                            </div>
                            <div class="field full">
                                <label>Badge-uri produs</label>
                                <div class="product-badge-flags">
                                    <label>
                                        <input type="checkbox" name="badge_popular" id="p_badge_popular" value="1">
                                        Popular
                                    </label>
                                    <label>
                                        <input type="checkbox" name="badge_best_seller" id="p_badge_best_seller" value="1">
                                        Cel mai bine vândut
                                    </label>
                                    <label>
                                        <input type="checkbox" name="badge_seasonal" id="p_badge_seasonal" value="1">
                                        De sezon
                                    </label>
                                </div>
                            </div>
                            <div class="field full">
                                <label>Status produs</label>
                                <div class="product-status-flags">
                                    <label>
                                        <input type="checkbox" name="is_active" id="p_is_active" checked>
                                        Activ
                                    </label>
                                    <label>
                                        <input type="checkbox" name="out_of_stock" id="p_out_of_stock" value="1">
                                        Fără stoc
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="product-editor-save">
                        <button class="btn" type="submit">Salvează produsul</button>
                    </div>
                </aside>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="product-image-modal">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-head">
            <h3>Selectează imagine din Galerie</h3>
            <button type="button" class="icon-btn" id="close-image-picker" aria-label="Închide">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                    <path fill="currentColor" d="M18.3 5.71 12 12l6.3 6.29-1.41 1.42L10.59 13.4 4.29 19.7 2.88 18.29 9.17 12 2.88 5.71 4.29 4.29l6.3 6.3 6.29-6.3z"/>
                </svg>
            </button>
        </div>
        <div class="field" style="margin-bottom:10px;">
            <input type="text" id="product-image-search" placeholder="Caută imagine după titlu...">
        </div>
        <form method="post" action="/admin/products/image-upload" id="product-image-upload-form" enctype="multipart/form-data" class="field" style="margin-bottom:10px;">
            <label style="margin-bottom:6px;">Încarcă imagine nouă</label>
            <div class="product-image-upload-inline">
                <input type="file" name="image_file" id="product-image-upload-input" accept=".jpg,.jpeg,.png,.webp,.gif" required>
                <input type="hidden" name="picker_mode" id="product-image-upload-mode" value="main">
                <button type="submit" class="btn">Încarcă</button>
            </div>
            <small class="muted">Imaginea se adaugă în galerie și se poate selecta imediat.</small>
        </form>
        <div class="product-picker-grid" id="product-picker-grid">
            <?php foreach (($galleryImages ?? []) as $image): ?>
                <button
                    type="button"
                    class="product-picker-item"
                    data-image-url="<?= htmlspecialchars((string) $image['image_url'], ENT_QUOTES) ?>"
                    data-search="<?= htmlspecialchars(strtolower((string) (($image['title'] ?? '') . ' ' . ($image['alt_text'] ?? '') . ' ' . ($image['image_url'] ?? ''))), ENT_QUOTES) ?>"
                >
                    <img src="<?= htmlspecialchars((string) $image['image_url'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) ($image['alt_text'] ?? $image['title'] ?? ''), ENT_QUOTES) ?>" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                    <strong><?= htmlspecialchars((string) ($image['title'] ?: 'Imagine fără titlu'), ENT_QUOTES) ?></strong>
                </button>
            <?php endforeach; ?>
        </div>
        <div style="text-align:center;padding:12px 0;" id="picker-load-more-wrap">
            <button type="button" class="btn btn-secondary" id="picker-load-more">Arată mai multe</button>
            <span id="picker-count-label" style="margin-left:10px;font-size:13px;color:#6b7280;"></span>
        </div>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('product-modal');
    const imageModal = document.getElementById('product-image-modal');
    const addBtn = document.getElementById('add-product-btn');
    const closeBtn = document.getElementById('close-product-modal');
    const openImagePickerBtn = document.getElementById('open-image-picker');
    const closeImagePickerBtn = document.getElementById('close-image-picker');
    const imageSearchInput = document.getElementById('product-image-search');
    const pickerItems = Array.from(document.querySelectorAll('.product-picker-item'));
    const form = document.getElementById('product-form');
    const title = document.getElementById('product-modal-title');
    const editButtons = document.querySelectorAll('.edit-product-btn');
    const slugInput = document.getElementById('p_slug');
    const nameInput = document.getElementById('p_name');
    const imageInput = document.getElementById('p_image_url');
    const imagePreview = document.getElementById('p_image_preview');
    const imageLabel = document.getElementById('p_image_label');
    const seoTitleInput = document.getElementById('p_seo_title');
    const seoDescriptionInput = document.getElementById('p_seo_description');
    const seoCanonicalInput = document.getElementById('p_seo_canonical_url');
    const seoImageInput = document.getElementById('p_seo_image_url');
    const seoImagePreview = document.getElementById('p_seo_image_preview');
    const seoImageLabel = document.getElementById('p_seo_image_label');
    const openSeoImagePickerBtn = document.getElementById('open-seo-image-picker');
    const galleryInput = document.getElementById('p_gallery_images_json');
    const salePricePeriodsInput = document.getElementById('p_sale_price_periods_json');
    const salePeriodsList = document.getElementById('p_sale_periods_list');
    const addSalePeriodBtn = document.getElementById('p_add_sale_period');
    const bbdEnabledInput = document.getElementById('p_bbd_enabled');
    const bbdEntriesInput = document.getElementById('p_bbd_entries_json');
    const bbdList = document.getElementById('p_bbd_list');
    const addBbdEntryBtn = document.getElementById('p_add_bbd_entry');
    const postCartNoteEnabledInput = document.getElementById('p_post_cart_note_enabled');
    const postCartNoteTextInput = document.getElementById('p_post_cart_note_text');
    const similarProductsInput = document.getElementById('p_similar_products_json');
    const similarProductsPicker = document.getElementById('similar-products-admin-picker');
    const gallerySelected = document.getElementById('product-gallery-selected');
    const openGalleryPickerBtn = document.getElementById('open-gallery-picker');
    const clearGalleryBtn = document.getElementById('clear-gallery-btn');
    const badgePopular = document.getElementById('p_badge_popular');
    const badgeBest = document.getElementById('p_badge_best_seller');
    const badgeSeason = document.getElementById('p_badge_seasonal');
    const outOfStockInput = document.getElementById('p_out_of_stock');
    const imageUploadForm = document.getElementById('product-image-upload-form');
    const imageUploadInput = document.getElementById('product-image-upload-input');
    const extraFieldInputs = Array.from(document.querySelectorAll('[id^="p_extra_"]'));
    if (
        !(modal instanceof HTMLElement)
        || !(imageModal instanceof HTMLElement)
        || !(form instanceof HTMLFormElement)
        || !(title instanceof HTMLElement)
        || !(addBtn instanceof HTMLButtonElement)
        || !(closeBtn instanceof HTMLElement)
        || !(slugInput instanceof HTMLInputElement)
        || !(nameInput instanceof HTMLInputElement)
        || !(imageInput instanceof HTMLInputElement)
        || !(imagePreview instanceof HTMLImageElement)
        || !(imageLabel instanceof HTMLElement)
        || !(seoTitleInput instanceof HTMLInputElement)
        || !(seoDescriptionInput instanceof HTMLTextAreaElement)
        || !(seoCanonicalInput instanceof HTMLInputElement)
        || !(seoImageInput instanceof HTMLInputElement)
        || !(seoImagePreview instanceof HTMLImageElement)
        || !(seoImageLabel instanceof HTMLElement)
        || !(galleryInput instanceof HTMLInputElement)
        || !(similarProductsInput instanceof HTMLInputElement)
        || !(gallerySelected instanceof HTMLElement)
        || !(similarProductsPicker instanceof HTMLElement)
    ) {
        return;
    }
    let selectedGalleryImages = [];
    let slugTouched = false;
    let selectedSimilarProductIds = [];
    let salePricePeriods = [];
    let bbdEntries = [];
    let bbdStockUsage = {};

    let pickerMode = 'main';
    const open = () => modal.classList.add('open');
    const close = () => modal.classList.remove('open');
    const refreshPickerSelection = () => {
        pickerItems.forEach((item) => {
            const url = String(item.dataset.imageUrl || '').trim();
            item.classList.toggle('is-selected', selectedGalleryImages.includes(url));
        });
    };
    const bindPickerItem = (item) => {
        if (!(item instanceof HTMLElement)) {
            return;
        }
        item.addEventListener('click', () => {
            const url = String(item.dataset.imageUrl || '').trim();
            if (pickerMode === 'gallery') {
                if (url === '') {
                    return;
                }
                if (selectedGalleryImages.includes(url)) {
                    selectedGalleryImages = selectedGalleryImages.filter((entry) => entry !== url);
                } else {
                    selectedGalleryImages.push(url);
                    selectedGalleryImages = selectedGalleryImages.slice(0, 12);
                }
                syncGalleryInput();
                renderGallerySelected();
                refreshPickerSelection();
                return;
            }
            if (pickerMode === 'seo') {
                syncSeoImagePreview(url || '/assets/img/product-placeholder.svg');
                closeImagePicker();
                return;
            }
            syncImagePreview(url || '/assets/img/product-placeholder.svg');
            closeImagePicker();
        });
    };
    const openImagePicker = () => {
        refreshPickerSelection();
        imageModal.classList.add('open');
    };
    const closeImagePicker = () => imageModal.classList.remove('open');

    const slugify = (value) => value.toLowerCase().replace(/[^a-z0-9\\s-]/g, '').trim().replace(/[\\s-]+/g, '-');
    const syncImagePreview = (url) => {
        const safe = (url || '/assets/img/product-placeholder.svg').trim();
        imageInput.value = safe;
        imagePreview.src = safe;
        imageLabel.textContent = safe;
    };
    const syncSeoImagePreview = (url) => {
        const safe = (url || '/assets/img/product-placeholder.svg').trim();
        seoImageInput.value = safe === '/assets/img/product-placeholder.svg' ? '' : safe;
        seoImagePreview.src = safe;
        seoImageLabel.textContent = safe;
    };
    const readGalleryFromInput = () => {
        try {
            const raw = JSON.parse(galleryInput?.value || '[]');
            if (!Array.isArray(raw)) return [];
            const unique = [];
            raw.forEach((item) => {
                const value = String(item || '').trim();
                if (!value || unique.includes(value)) return;
                unique.push(value);
            });
            return unique.slice(0, 12);
        } catch (_) {
            return [];
        }
    };
    const readSimilarFromInput = () => {
        try {
            const raw = JSON.parse(similarProductsInput?.value || '[]');
            if (!Array.isArray(raw)) return [];
            const unique = [];
            raw.forEach((item) => {
                const id = Number(item || 0);
                if (!Number.isFinite(id) || id <= 0 || unique.includes(id)) return;
                unique.push(id);
            });
            return unique.slice(0, 12);
        } catch (_) {
            return [];
        }
    };
    const syncGalleryInput = () => {
        if (galleryInput) {
            galleryInput.value = JSON.stringify(selectedGalleryImages);
        }
    };
    const toDateInputValue = (value) => {
        const raw = String(value || '').trim();
        if (raw === '') return '';
        const datePart = raw.includes(' ') ? raw.split(' ')[0] : raw;
        if (/^\d{4}-\d{2}-\d{2}$/.test(datePart)) {
            return datePart;
        }
        const parsed = new Date(raw);
        if (Number.isNaN(parsed.getTime())) return '';
        const yyyy = String(parsed.getFullYear());
        const mm = String(parsed.getMonth() + 1).padStart(2, '0');
        const dd = String(parsed.getDate()).padStart(2, '0');
        return `${yyyy}-${mm}-${dd}`;
    };
    const toDateTimeValue = (value, endOfDay) => {
        const dateOnly = toDateInputValue(value);
        if (dateOnly === '') return '';
        return `${dateOnly} ${endOfDay ? '23:59:59' : '00:00:00'}`;
    };
    const normalizeSalePeriods = (raw) => {
        let decoded = [];
        if (Array.isArray(raw)) {
            decoded = raw;
        } else if (typeof raw === 'string' && raw.trim() !== '') {
            try {
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) decoded = parsed;
            } catch (_error) {
                decoded = [];
            }
        }
        const seen = new Set();
        return decoded
            .map((item) => {
                if (!item || typeof item !== 'object') return null;
                const startDate = toDateTimeValue(item.start_date || item.start || '', false);
                const endDate = toDateTimeValue(item.end_date || item.end || '', true);
                const salePriceRaw = String(item.sale_price ?? item.price ?? '').replace(',', '.').trim();
                const salePrice = Number.parseFloat(salePriceRaw);
                if (!startDate || !endDate || !Number.isFinite(salePrice) || salePrice <= 0) {
                    return null;
                }
                const key = `${startDate}|${endDate}|${salePrice.toFixed(2)}`;
                if (seen.has(key)) return null;
                seen.add(key);
                return {
                    start_date: startDate,
                    end_date: endDate,
                    sale_price: Number(salePrice.toFixed(2)),
                };
            })
            .filter(Boolean)
            .slice(0, 24);
    };
    const syncSalePeriodsInput = () => {
        if (salePricePeriodsInput instanceof HTMLInputElement) {
            salePricePeriodsInput.value = JSON.stringify(salePricePeriods);
        }
    };
    const normalizeBbdLot = (value) => {
        const raw = String(value || '').trim().toUpperCase();
        if (raw === '') return '';
        return raw.replace(/[^A-Z0-9\-_.\/]/g, '').slice(0, 40);
    };
    const buildBbdLabel = (dateValue) => {
        if (!dateValue) return '';
        return `Expiră la data de: ${dateValue.split('-').reverse().join('.')}`;
    };
    const normalizeBbdEntries = (raw) => {
        let decoded = [];
        if (Array.isArray(raw)) {
            decoded = raw;
        } else if (typeof raw === 'string' && raw.trim() !== '') {
            try {
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) decoded = parsed;
            } catch (_error) {
                decoded = [];
            }
        }
        const seen = new Set();
        return decoded
            .map((item) => {
                if (!item || typeof item !== 'object') return null;
                const dateRaw = String(item.date || item.bbd_date || '').trim();
                const dateValue = toDateInputValue(dateRaw);
                if (!dateValue) return null;
                const priceRaw = String(item.reduced_price ?? item.price ?? '').replace(',', '.').trim();
                let reducedPrice = null;
                if (priceRaw !== '') {
                    const parsedPrice = Number.parseFloat(priceRaw);
                    if (Number.isFinite(parsedPrice) && parsedPrice > 0) {
                        reducedPrice = Number(parsedPrice.toFixed(2));
                    }
                }
                const lot = normalizeBbdLot(item.lot || item.bbd_lot || '');
                let stock = null;
                const stockRaw = String(item.stock ?? item.bbd_stock ?? '').replace(',', '.').trim();
                if (stockRaw !== '') {
                    const parsedStock = Number.parseFloat(stockRaw);
                    if (Number.isFinite(parsedStock)) {
                        stock = Math.max(0, Math.floor(parsedStock));
                    }
                }
                const key = `${dateValue}|${lot}|${reducedPrice === null ? '' : reducedPrice.toFixed(2)}`;
                if (seen.has(key)) return null;
                seen.add(key);
                return {
                    key: String(item.key || '').trim(),
                    date: dateValue,
                    lot,
                    label: buildBbdLabel(dateValue),
                    reduced_price: reducedPrice,
                    stock,
                };
            })
            .filter(Boolean)
            .slice(0, 60);
    };
    const syncBbdEntriesInput = () => {
        if (bbdEntriesInput instanceof HTMLInputElement) {
            bbdEntriesInput.value = JSON.stringify(bbdEntries);
        }
    };
    const updatePostCartNoteVisibility = () => {
        if (!(postCartNoteEnabledInput instanceof HTMLInputElement) || !(postCartNoteTextInput instanceof HTMLTextAreaElement)) {
            return;
        }
        const enabled = postCartNoteEnabledInput.checked;
        postCartNoteTextInput.style.display = enabled ? 'block' : 'none';
    };
    const updateBbdControlsVisibility = () => {
        const enabled = bbdEnabledInput instanceof HTMLInputElement && bbdEnabledInput.checked;
        if (bbdList instanceof HTMLElement) {
            bbdList.style.display = enabled ? 'grid' : 'none';
        }
        if (addBbdEntryBtn instanceof HTMLButtonElement) {
            addBbdEntryBtn.style.display = enabled ? 'inline-flex' : 'none';
        }
    };
    const renderBbdEntries = () => {
        if (!(bbdList instanceof HTMLElement)) {
            return;
        }
        if (bbdEntries.length === 0) {
            bbdList.innerHTML = '<p class="muted" style="margin:0;">Nu ai adăugat oferte.</p>';
            return;
        }
        bbdList.innerHTML = bbdEntries.map((entry, idx) => {
            const dateValue = toDateInputValue(entry.date || '');
            const reducedPrice = Number(entry.reduced_price || 0);
            const safePrice = Number.isFinite(reducedPrice) && reducedPrice > 0 ? reducedPrice.toFixed(2) : '';
            const safeLot = String(entry.lot || '').trim();
            const stock = Number(entry.stock ?? NaN);
            const safeStock = Number.isFinite(stock) ? String(Math.max(0, Math.floor(stock))) : '';
            const reserved = Math.max(0, Math.floor(Number(bbdStockUsage[String(entry.key || '')] || 0)));
            let usageHtml = '';
            if (Number.isFinite(stock)) {
                const remaining = Math.max(0, Math.floor(stock) - reserved);
                const color = remaining <= 0 ? '#b91c1c' : (remaining <= 2 ? '#b45309' : '#15803d');
                usageHtml = `<div style="grid-column:1/-1;font-size:12px;color:${color};margin-top:-2px;">`
                    + `Rezervat de comenzi: <strong>${reserved}</strong> · Rămas: <strong>${remaining}</strong>`
                    + (remaining <= 0 ? ' · <strong>STOC EPUIZAT pe site</strong>' : '')
                    + `</div>`;
            } else if (reserved > 0) {
                usageHtml = `<div style="grid-column:1/-1;font-size:12px;color:#64748b;margin-top:-2px;">`
                    + `Comandat până acum: <strong>${reserved}</strong> (stoc nelimitat)`
                    + `</div>`;
            }
            return ''
                + `<div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:8px 8px;align-items:end;" data-bbd-row="${idx}">`
                + `<label style="display:grid;gap:4px;"><span>Data expirării</span><input type="date" data-bbd-field="date" data-bbd-idx="${idx}" value="${dateValue}"></label>`
                + `<label style="display:grid;gap:4px;"><span>LOT (opțional)</span><input type="text" data-bbd-field="lot" data-bbd-idx="${idx}" value="${safeLot}" placeholder="Ex: L2406A"></label>`
                + `<label style="display:grid;gap:4px;"><span>Preț redus (RON, opțional)</span><input type="number" step="0.01" min="0.01" data-bbd-field="price" data-bbd-idx="${idx}" value="${safePrice}" placeholder="Folosește prețul normal"></label>`
                + `<label style="display:grid;gap:4px;"><span>Stoc ofertă (opțional)</span><input type="number" step="1" min="0" data-bbd-field="stock" data-bbd-idx="${idx}" value="${safeStock}" placeholder="Nelimitat dacă lași gol"></label>`
                + `<button type="button" class="icon-btn danger" data-remove-bbd-entry="${idx}" title="Elimină oferta">🗑</button>`
                + usageHtml
                + '</div>';
        }).join('');
    };
    const renderSalePeriods = () => {
        if (!(salePeriodsList instanceof HTMLElement)) {
            return;
        }
        if (salePricePeriods.length === 0) {
            salePeriodsList.innerHTML = '<p class="muted" style="margin:0;">Nu ai adăugat perioade programate.</p>';
            return;
        }
        salePeriodsList.innerHTML = salePricePeriods.map((period, idx) => {
            const startDate = toDateInputValue(period.start_date || '');
            const endDate = toDateInputValue(period.end_date || '');
            const salePrice = Number(period.sale_price || 0);
            const safePrice = Number.isFinite(salePrice) && salePrice > 0 ? salePrice.toFixed(2) : '';
            return ''
                + `<div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;align-items:end;" data-sale-period-row="${idx}">`
                + `<label style="display:grid;gap:4px;"><span>De la</span><input type="date" data-sale-period-field="start" data-sale-period-idx="${idx}" value="${startDate}"></label>`
                + `<label style="display:grid;gap:4px;"><span>Până la</span><input type="date" data-sale-period-field="end" data-sale-period-idx="${idx}" value="${endDate}"></label>`
                + `<label style="display:grid;gap:4px;"><span>Preț redus (RON)</span><input type="number" step="0.01" min="0.01" data-sale-period-field="price" data-sale-period-idx="${idx}" value="${safePrice}"></label>`
                + `<button type="button" class="icon-btn danger" data-remove-sale-period="${idx}" title="Elimină perioada">🗑</button>`
                + '</div>';
        }).join('');
    };
    const syncSimilarProductsInput = () => {
        if (similarProductsInput instanceof HTMLInputElement) {
            similarProductsInput.value = JSON.stringify(selectedSimilarProductIds);
        }
    };
    const renderSimilarProductsSelection = () => {
        const selectedSet = new Set(selectedSimilarProductIds);
        similarProductsPicker.querySelectorAll('[data-similar-product-id]').forEach((inputNode) => {
            if (!(inputNode instanceof HTMLInputElement)) {
                return;
            }
            const id = Number(inputNode.value || '0');
            inputNode.checked = Number.isFinite(id) && selectedSet.has(id);
        });
    };
    const renderGallerySelected = () => {
        if (!(gallerySelected instanceof HTMLElement)) return;
        if (selectedGalleryImages.length === 0) {
            gallerySelected.innerHTML = '<p class="muted" style="margin:0;">Nu ai selectat imagini secundare.</p>';
            return;
        }
        gallerySelected.innerHTML = selectedGalleryImages.map((url, idx) => `
            <div class="product-gallery-selected-item" data-idx="${idx}" title="Trage pentru a reordona">
                <span class="drag-handle" style="position:absolute;top:4px;left:4px;font-size:14px;opacity:.5;pointer-events:none;">⠿</span>
                <img src="${url}" alt="Imagine secundară" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                <button type="button" class="icon-btn danger" data-remove-gallery="${idx}" title="Elimină">🗑</button>
            </div>
        `).join('');
        bindGalleryDragDrop();
    };

    const resetForm = () => {
        form.reset();
        form.action = '/admin/products';
        title.textContent = 'Adaugă produs';
        document.getElementById('p_is_active').checked = true;
        document.getElementById('p_product_template_id').value = '';
        document.getElementById('p_vat_percent').value = '19.00';
        document.getElementById('p_vat_included').value = '1';
        if (outOfStockInput instanceof HTMLInputElement) {
            outOfStockInput.checked = false;
        }
        if (badgePopular) badgePopular.checked = false;
        if (badgeBest) badgeBest.checked = false;
        if (badgeSeason) badgeSeason.checked = false;
        syncImagePreview('/assets/img/product-placeholder.svg');
        seoTitleInput.value = '';
        seoDescriptionInput.value = '';
        seoCanonicalInput.value = '';
        syncSeoImagePreview('/assets/img/product-placeholder.svg');
        selectedGalleryImages = [];
        syncGalleryInput();
        renderGallerySelected();
        selectedSimilarProductIds = [];
        syncSimilarProductsInput();
        renderSimilarProductsSelection();
        salePricePeriods = [];
        syncSalePeriodsInput();
        renderSalePeriods();
        if (bbdEnabledInput instanceof HTMLInputElement) {
            bbdEnabledInput.checked = false;
        }
        if (postCartNoteEnabledInput instanceof HTMLInputElement) {
            postCartNoteEnabledInput.checked = false;
        }
        if (postCartNoteTextInput instanceof HTMLTextAreaElement) {
            postCartNoteTextInput.value = '';
        }
        bbdEntries = [];
        bbdStockUsage = {};
        syncBbdEntriesInput();
        renderBbdEntries();
        updateBbdControlsVisibility();
        updatePostCartNoteVisibility();
        extraFieldInputs.forEach((input) => {
            const defaultValue = input.dataset.default || '';
            input.value = defaultValue;
        });
        slugTouched = false;
    };

    addBtn.addEventListener('click', () => {
        resetForm();
        open();
    });

    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    openImagePickerBtn?.addEventListener('click', () => {
        pickerMode = 'main';
        openImagePicker();
    });
    openSeoImagePickerBtn?.addEventListener('click', () => {
        pickerMode = 'seo';
        openImagePicker();
    });
    closeImagePickerBtn?.addEventListener('click', closeImagePicker);
    imageModal?.addEventListener('click', (e) => { if (e.target === imageModal) closeImagePicker(); });
    slugInput.addEventListener('input', () => { slugTouched = true; });
    nameInput.addEventListener('input', () => { if (!slugTouched) slugInput.value = slugify(nameInput.value); });

    editButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            resetForm();
            const product = JSON.parse(btn.dataset.product || '{}');
            title.textContent = 'Editează produs';
            form.action = '/admin/products/' + product.id + '/update';
            document.getElementById('p_name').value = product.name || '';
            document.getElementById('p_sku').value = product.sku || '';
            document.getElementById('p_slug').value = product.slug || '';
            document.getElementById('p_category_id').value = product.category_id || '';
            const templateSelect = document.getElementById('p_product_template_id');
            if (templateSelect instanceof HTMLSelectElement) {
                const rawTemplateId = product.product_template_id;
                templateSelect.value = (rawTemplateId === null || rawTemplateId === undefined || String(rawTemplateId).trim() === '')
                    ? ''
                    : String(rawTemplateId);
            }
            const basePriceValue = (product.base_price ?? product.price ?? '');
            document.getElementById('p_price').value = basePriceValue === null ? '' : String(basePriceValue);
            const vatInput = document.getElementById('p_vat_percent');
            if (vatInput instanceof HTMLInputElement) {
                const vatRaw = Number(product.vat_percent ?? 19);
                const vatValue = Number.isFinite(vatRaw) ? vatRaw : 19;
                vatInput.value = String(vatValue.toFixed(2));
            }
            const vatIncludedInput = document.getElementById('p_vat_included');
            if (vatIncludedInput instanceof HTMLSelectElement) {
                vatIncludedInput.value = Number(product.vat_included ?? 1) === 0 ? '0' : '1';
            }
            document.getElementById('p_sale_price').value = product.sale_price || '';
            const badgeModeInput = document.getElementById('p_discount_badge_mode');
            if (badgeModeInput instanceof HTMLSelectElement) {
                badgeModeInput.value = String(product.discount_badge_mode || 'percent') === 'value' ? 'value' : 'percent';
            }
            document.getElementById('p_stock').value = product.stock || '';
            document.getElementById('p_weight_grams').value = product.weight_grams || '';
            document.getElementById('p_short_description').value = product.short_description || '';
            document.getElementById('p_description').value = product.description || '';
            document.getElementById('p_product_highlights').value = product.product_highlights || '';
            seoTitleInput.value = product.seo_title || '';
            seoDescriptionInput.value = product.seo_description || '';
            seoCanonicalInput.value = product.seo_canonical_url || '';
            syncSeoImagePreview(product.seo_image_url || '/assets/img/product-placeholder.svg');
            syncImagePreview(product.image_url || '/assets/img/product-placeholder.svg');
            selectedGalleryImages = Array.isArray(product.image_gallery) ? product.image_gallery.map((item) => String(item || '').trim()).filter(Boolean) : [];
            syncGalleryInput();
            renderGallerySelected();
            selectedSimilarProductIds = Array.isArray(product.similar_products)
                ? product.similar_products
                    .map((item) => Number(item || 0))
                    .filter((item, idx, arr) => Number.isFinite(item) && item > 0 && arr.indexOf(item) === idx)
                    .slice(0, 12)
                : [];
            syncSimilarProductsInput();
            renderSimilarProductsSelection();
            if (badgePopular) badgePopular.checked = Number(product.badge_popular || 0) === 1;
            if (badgeBest) badgeBest.checked = Number(product.badge_best_seller || 0) === 1;
            if (badgeSeason) badgeSeason.checked = Number(product.badge_seasonal || 0) === 1;
            if (outOfStockInput instanceof HTMLInputElement) {
                outOfStockInput.checked = Number(product.out_of_stock || 0) === 1;
            }
            document.getElementById('p_is_active').checked = Number(product.is_active) === 1;
            salePricePeriods = normalizeSalePeriods(
                Array.isArray(product.sale_price_periods)
                    ? product.sale_price_periods
                    : (product.sale_price_periods_json || '[]')
            );
            syncSalePeriodsInput();
            renderSalePeriods();
            if (bbdEnabledInput instanceof HTMLInputElement) {
                bbdEnabledInput.checked = Number(product.bbd_enabled || 0) === 1;
            }
            if (postCartNoteEnabledInput instanceof HTMLInputElement) {
                postCartNoteEnabledInput.checked = Number(product.post_cart_note_enabled || 0) === 1;
            }
            if (postCartNoteTextInput instanceof HTMLTextAreaElement) {
                postCartNoteTextInput.value = String(product.post_cart_note_text || '');
            }
            bbdStockUsage = (product.bbd_stock_usage && typeof product.bbd_stock_usage === 'object')
                ? product.bbd_stock_usage
                : {};
            bbdEntries = normalizeBbdEntries(
                Array.isArray(product.bbd_entries)
                    ? product.bbd_entries
                    : (product.bbd_entries_json || '[]')
            );
            syncBbdEntriesInput();
            renderBbdEntries();
            updateBbdControlsVisibility();
            updatePostCartNoteVisibility();
            const values = (product.extra_fields && typeof product.extra_fields === 'object') ? product.extra_fields : {};
            extraFieldInputs.forEach((input) => {
                const fieldId = input.id.replace('p_extra_', '');
                const hasValue = Object.prototype.hasOwnProperty.call(values, fieldId);
                const raw = hasValue ? values[fieldId] : (input.dataset.default || '');
                input.value = raw == null ? '' : String(raw);
            });
            slugTouched = true;
            open();
        });
    });

    pickerItems.forEach((item) => bindPickerItem(item));
    openGalleryPickerBtn?.addEventListener('click', () => {
        pickerMode = 'gallery';
        openImagePicker();
    });
    clearGalleryBtn?.addEventListener('click', () => {
        selectedGalleryImages = [];
        syncGalleryInput();
        renderGallerySelected();
    });
    similarProductsPicker.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || !target.matches('[data-similar-product-id]')) {
            return;
        }
        const id = Number(target.value || '0');
        if (!Number.isFinite(id) || id <= 0) {
            target.checked = false;
            return;
        }
        if (target.checked) {
            if (!selectedSimilarProductIds.includes(id)) {
                selectedSimilarProductIds.push(id);
            }
        } else {
            selectedSimilarProductIds = selectedSimilarProductIds.filter((value) => value !== id);
        }
        if (selectedSimilarProductIds.length > 12) {
            selectedSimilarProductIds = selectedSimilarProductIds.slice(0, 12);
        }
        syncSimilarProductsInput();
        renderSimilarProductsSelection();
    });
    addSalePeriodBtn?.addEventListener('click', () => {
        salePricePeriods.push({
            start_date: toDateTimeValue(new Date().toISOString(), false),
            end_date: toDateTimeValue(new Date().toISOString(), true),
            sale_price: 0,
        });
        salePricePeriods = salePricePeriods.slice(0, 24);
        syncSalePeriodsInput();
        renderSalePeriods();
    });
    bbdEnabledInput?.addEventListener('change', () => {
        updateBbdControlsVisibility();
    });
    postCartNoteEnabledInput?.addEventListener('change', () => {
        updatePostCartNoteVisibility();
    });
    addBbdEntryBtn?.addEventListener('click', () => {
        const now = new Date();
        const yyyy = String(now.getFullYear());
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        bbdEntries.push({
            key: '',
            date: `${yyyy}-${mm}-${dd}`,
            lot: '',
            label: `Expiră la data de: ${dd}.${mm}.${yyyy}`,
            reduced_price: null,
            stock: null,
        });
        bbdEntries = bbdEntries.slice(0, 60);
        syncBbdEntriesInput();
        renderBbdEntries();
        updateBbdControlsVisibility();
    });
    const updateSalePeriodFromInput = (target) => {
        if (!(target instanceof HTMLInputElement)) {
            return false;
        }
        const idx = Number(target.getAttribute('data-sale-period-idx') || '-1');
        if (!Number.isFinite(idx) || idx < 0 || idx >= salePricePeriods.length) {
            return false;
        }
        const field = String(target.getAttribute('data-sale-period-field') || '');
        if (field === 'start') {
            salePricePeriods[idx].start_date = toDateTimeValue(target.value, false);
            return true;
        }
        if (field === 'end') {
            salePricePeriods[idx].end_date = toDateTimeValue(target.value, true);
            return true;
        }
        if (field === 'price') {
            const value = Number.parseFloat(String(target.value || '').replace(',', '.'));
            salePricePeriods[idx].sale_price = Number.isFinite(value) && value > 0 ? Number(value.toFixed(2)) : 0;
            return true;
        }
        return false;
    };
    const updateBbdEntryFromInput = (target) => {
        if (!(target instanceof HTMLInputElement)) {
            return false;
        }
        const idx = Number(target.getAttribute('data-bbd-idx') || '-1');
        if (!Number.isFinite(idx) || idx < 0 || idx >= bbdEntries.length) {
            return false;
        }
        const field = String(target.getAttribute('data-bbd-field') || '');
        if (field === 'date') {
            const normalizedDate = toDateInputValue(target.value);
            if (normalizedDate === '') {
                return false;
            }
            bbdEntries[idx].date = normalizedDate;
            bbdEntries[idx].label = buildBbdLabel(normalizedDate);
            return true;
        }
        if (field === 'lot') {
            bbdEntries[idx].lot = normalizeBbdLot(target.value);
            bbdEntries[idx].label = buildBbdLabel(bbdEntries[idx].date || '');
            return true;
        }
        if (field === 'price') {
            const raw = String(target.value || '').replace(',', '.').trim();
            if (raw === '') {
                bbdEntries[idx].reduced_price = null;
                return true;
            }
            const value = Number.parseFloat(raw);
            bbdEntries[idx].reduced_price = Number.isFinite(value) && value > 0 ? Number(value.toFixed(2)) : null;
            return true;
        }
        if (field === 'stock') {
            const raw = String(target.value || '').replace(',', '.').trim();
            if (raw === '') {
                bbdEntries[idx].stock = null;
                return true;
            }
            const value = Number.parseFloat(raw);
            bbdEntries[idx].stock = Number.isFinite(value) ? Math.max(0, Math.floor(value)) : null;
            return true;
        }
        return false;
    };
    salePeriodsList?.addEventListener('input', (event) => {
        if (updateSalePeriodFromInput(event.target)) {
            // Keep typing smooth: update hidden JSON without re-render.
            syncSalePeriodsInput();
        }
    });
    bbdList?.addEventListener('input', (event) => {
        if (updateBbdEntryFromInput(event.target)) {
            syncBbdEntriesInput();
        }
    });
    salePeriodsList?.addEventListener('change', (event) => {
        if (!updateSalePeriodFromInput(event.target)) {
            return;
        }
        // Normalize and redraw only after field commit (blur/change).
        salePricePeriods = normalizeSalePeriods(salePricePeriods);
        syncSalePeriodsInput();
        renderSalePeriods();
    });
    bbdList?.addEventListener('change', (event) => {
        if (!updateBbdEntryFromInput(event.target)) {
            return;
        }
        bbdEntries = normalizeBbdEntries(bbdEntries);
        syncBbdEntriesInput();
        renderBbdEntries();
        updateBbdControlsVisibility();
    });
    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => {
        if (char === '&') return '&amp;';
        if (char === '<') return '&lt;';
        if (char === '>') return '&gt;';
        if (char === '"') return '&quot;';
        return '&#39;';
    });
    imageUploadForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!(imageUploadInput instanceof HTMLInputElement) || !imageUploadInput.files || imageUploadInput.files.length === 0) {
            return;
        }
        const fd = new FormData(imageUploadForm);
        try {
            const response = await fetch('/admin/products/image-upload', {
                method: 'POST',
                body: fd,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'xmlhttprequest',
                },
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || payload.ok !== true || typeof payload.url !== 'string') {
                throw new Error((payload && payload.message) ? String(payload.message) : 'Upload eșuat.');
            }
            const safeUrl = String(payload.url || '').trim();
            if (safeUrl === '') {
                throw new Error('URL invalid după upload.');
            }
            const titleValue = String(payload.title || 'Imagine nouă');
            const searchValue = (titleValue + ' ' + safeUrl).toLowerCase();
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-picker-item';
            button.dataset.imageUrl = safeUrl;
            button.dataset.search = searchValue;
            const safeUrlHtml = escapeHtml(safeUrl);
            const safeTitleHtml = escapeHtml(titleValue);
            button.innerHTML = ''
                + '<img src="' + safeUrlHtml + '" alt="' + safeTitleHtml + '" onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';">'
                + '<strong>' + safeTitleHtml + '</strong>';
            const grid = document.getElementById('product-picker-grid');
            if (grid instanceof HTMLElement) {
                grid.prepend(button);
            }
            pickerItems.unshift(button);
            refreshPickerSelection();
            button.addEventListener('click', () => {
                const url = String(button.dataset.imageUrl || '').trim();
                if (pickerMode === 'gallery') {
                    if (url === '') {
                        return;
                    }
                    if (selectedGalleryImages.includes(url)) {
                        selectedGalleryImages = selectedGalleryImages.filter((entry) => entry !== url);
                    } else {
                        selectedGalleryImages.push(url);
                        selectedGalleryImages = selectedGalleryImages.slice(0, 12);
                    }
                    syncGalleryInput();
                    renderGallerySelected();
                    refreshPickerSelection();
                    return;
                }
                if (pickerMode === 'seo') {
                    syncSeoImagePreview(url || '/assets/img/product-placeholder.svg');
                    closeImagePicker();
                    return;
                }
                syncImagePreview(url || '/assets/img/product-placeholder.svg');
                closeImagePicker();
            });
            imageUploadInput.value = '';
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Nu am putut încărca imaginea.';
            window.alert(message);
        }
    });
    const resolveDataAttr = (target, attrName) => {
        if (!(target instanceof HTMLElement)) {
            return null;
        }
        const direct = target.getAttribute(attrName);
        if (direct !== null) {
            return direct;
        }
        const owner = target.closest(`[${attrName}]`);
        if (!(owner instanceof HTMLElement)) {
            return null;
        }
        return owner.getAttribute(attrName);
    };
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const removeSaleIdx = resolveDataAttr(target, 'data-remove-sale-period');
        if (removeSaleIdx !== null) {
            const idx = Number(removeSaleIdx);
            if (Number.isFinite(idx) && idx >= 0 && idx < salePricePeriods.length) {
                salePricePeriods.splice(idx, 1);
                syncSalePeriodsInput();
                renderSalePeriods();
            }
            return;
        }
        const removeBbdIdx = resolveDataAttr(target, 'data-remove-bbd-entry');
        if (removeBbdIdx !== null) {
            const idx = Number(removeBbdIdx);
            if (Number.isFinite(idx) && idx >= 0 && idx < bbdEntries.length) {
                bbdEntries.splice(idx, 1);
                bbdEntries = normalizeBbdEntries(bbdEntries);
                syncBbdEntriesInput();
                renderBbdEntries();
                updateBbdControlsVisibility();
            }
            return;
        }
        const removeIdx = resolveDataAttr(target, 'data-remove-gallery');
        if (removeIdx === null) return;
        const idx = Number(removeIdx);
        if (!Number.isFinite(idx) || idx < 0) return;
        selectedGalleryImages.splice(idx, 1);
        syncGalleryInput();
        renderGallerySelected();
        refreshPickerSelection();
    });
    // Gallery picker pagination
    const PICKER_PAGE_SIZE = 40;
    let pickerVisibleCount = PICKER_PAGE_SIZE;
    let pickerFilteredItems = [...pickerItems];
    const pickerLoadMoreBtn = document.getElementById('picker-load-more');
    const pickerLoadMoreWrap = document.getElementById('picker-load-more-wrap');
    const pickerCountLabel = document.getElementById('picker-count-label');

    const applyPickerPagination = () => {
        pickerFilteredItems.forEach((item, i) => {
            item.style.display = i < pickerVisibleCount ? '' : 'none';
        });
        const total = pickerFilteredItems.length;
        const shown = Math.min(pickerVisibleCount, total);
        if (pickerCountLabel) pickerCountLabel.textContent = `${shown} / ${total}`;
        if (pickerLoadMoreWrap) pickerLoadMoreWrap.style.display = shown >= total ? 'none' : '';
    };

    pickerLoadMoreBtn?.addEventListener('click', () => {
        pickerVisibleCount += PICKER_PAGE_SIZE;
        applyPickerPagination();
    });

    imageSearchInput?.addEventListener('input', () => {
        const q = (imageSearchInput.value || '').trim().toLowerCase();
        pickerFilteredItems = pickerItems.filter((item) => {
            const match = q === '' || (item.dataset.search || '').includes(q);
            item.style.display = 'none';
            return match;
        });
        pickerVisibleCount = PICKER_PAGE_SIZE;
        applyPickerPagination();
    });

    applyPickerPagination();

    // Drag-and-drop reorder for secondary images
    const bindGalleryDragDrop = () => {
        const items = gallerySelected.querySelectorAll('.product-gallery-selected-item');
        let dragSrc = null;
        items.forEach((item) => {
            item.setAttribute('draggable', 'true');
            item.style.cursor = 'grab';
            item.addEventListener('dragstart', (e) => {
                dragSrc = item;
                item.style.opacity = '0.4';
                e.dataTransfer.effectAllowed = 'move';
            });
            item.addEventListener('dragend', () => { item.style.opacity = ''; });
            item.addEventListener('dragover', (e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
            item.addEventListener('dragenter', () => { item.style.outline = '2px dashed #2d7d5f'; });
            item.addEventListener('dragleave', () => { item.style.outline = ''; });
            item.addEventListener('drop', (e) => {
                e.preventDefault();
                item.style.outline = '';
                if (!dragSrc || dragSrc === item) return;
                const srcIdx = parseInt(dragSrc.dataset.idx, 10);
                const dstIdx = parseInt(item.dataset.idx, 10);
                const moved = selectedGalleryImages.splice(srcIdx, 1)[0];
                selectedGalleryImages.splice(dstIdx, 0, moved);
                syncGalleryInput();
                renderGallerySelected();
            });
        });
    };
    selectedGalleryImages = readGalleryFromInput();
    syncGalleryInput();
    renderGallerySelected();
    selectedSimilarProductIds = [];
    syncSimilarProductsInput();
    renderSimilarProductsSelection();
    salePricePeriods = normalizeSalePeriods(salePricePeriodsInput?.value || '[]');
    syncSalePeriodsInput();
    renderSalePeriods();
    bbdEntries = normalizeBbdEntries(bbdEntriesInput?.value || '[]');
    syncBbdEntriesInput();
    renderBbdEntries();
    updateBbdControlsVisibility();
    updatePostCartNoteVisibility();
})();
</script>
