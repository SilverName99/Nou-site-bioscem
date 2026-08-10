<?php
$productTemplate = is_array($productTemplate ?? null) ? $productTemplate : [];
$templateId = (int) ($productTemplate['id'] ?? 0);
$templateName = (string) ($productTemplate['name'] ?? '');
$templateSlug = (string) ($productTemplate['slug'] ?? '');
$templateDescription = (string) ($productTemplate['description'] ?? '');
$templateHtml = (string) (($productTemplate['html_content'] ?? $productTemplate['html_template']) ?? '');
$templateCss = (string) (($productTemplate['css_content'] ?? $productTemplate['css_template']) ?? '');
$templateJs = (string) (($productTemplate['js_content'] ?? $productTemplate['js_template']) ?? '');
$isActive = (int) ($productTemplate['is_active'] ?? 1) === 1;
?>

<section class="panel">
    <form method="post" action="/admin/products/templates" id="product-template-editor-form">
        <input type="hidden" name="id" value="<?= $templateId ?>">
        <input type="hidden" name="action" value="save_builder">
        <?php
        $generalProductTokens = [
            ['label' => 'Nume produs', 'token' => '{{product_name}}'],
            ['label' => 'Slug produs', 'token' => '{{product_slug}}'],
            ['label' => 'SKU produs', 'token' => '{{product_sku}}'],
            ['label' => 'Preț (RON)', 'token' => '{{product_price}}'],
            ['label' => 'Preț formatat', 'token' => '{{product_price_display}}'],
            ['label' => 'Descriere scurtă', 'token' => '{{product_short_description}}'],
            ['label' => 'Descriere completă', 'token' => '{{product_description}}'],
            ['label' => 'Puncte forte (text, separat prin punct și virgulă)', 'token' => '{{product_highlights}}'],
            ['label' => 'Imagine produs', 'token' => '{{product_image_url}}'],
            ['label' => 'Galerie imagini produs', 'token' => '{{product_image_gallery}}'],
            ['label' => 'Carusel imagini produs', 'token' => '{{product_image_carousel}}'],
            ['label' => 'Categorie produs', 'token' => '{{product_category}}'],
            ['label' => 'URL categorie produs (filtru magazin)', 'token' => '{{product_category_url}}'],
            ['label' => 'Secțiune tab-uri (câmpuri + recenzii)', 'token' => '{{product_tabs_section}}'],
            ['label' => 'Secțiune produse similare', 'token' => '{{product_similar_products_section}}'],
            ['label' => 'Input cantitate (după setări)', 'token' => '{{product_quantity_input}}'],
            ['label' => 'Buton adaugă în coș', 'token' => '{{product_add_to_cart_button}}'],
            ['label' => 'Text sub buton adaugă în coș', 'token' => '{{product_post_cart_note}}'],
        ];
        $reviewTokens = [
            ['label' => 'Număr review-uri', 'token' => '{{product_reviews_count}}'],
            ['label' => 'Rating mediu', 'token' => '{{product_reviews_average}}'],
            ['label' => 'Rating mediu (raw)', 'token' => '{{product_reviews_average_raw}}'],
            ['label' => 'Stele review-uri (HTML)', 'token' => '{{product_reviews_stars}}'],
            ['label' => 'Listă review-uri', 'token' => '{{product_reviews_list}}'],
            ['label' => 'Form review', 'token' => '{{product_review_form}}'],
            ['label' => 'Secțiune review completă', 'token' => '{{product_reviews_section}}'],
        ];
        $iconTokens = [
            ['label' => 'Iconiță SVG: leaf', 'token' => '{{product_icon_leaf}}'],
            ['label' => 'Iconiță SVG: truck', 'token' => '{{product_icon_truck}}'],
            ['label' => 'Iconiță SVG: shield', 'token' => '{{product_icon_shield}}'],
            ['label' => 'Iconiță SVG: star', 'token' => '{{product_icon_star}}'],
            ['label' => 'Iconiță SVG: camera', 'token' => '{{product_icon_camera}}'],
            ['label' => 'Iconiță SVG: sparkles', 'token' => '{{product_icon_sparkles}}'],
            ['label' => 'Iconiță SVG: calendar', 'token' => '{{product_icon_calendar}}'],
            ['label' => 'Iconiță SVG: user', 'token' => '{{product_icon_user}}'],
        ];
        $extraFieldTokens = [];
        foreach (($extraFields ?? []) as $field) {
            if (!is_array($field) || (int) ($field['is_active'] ?? 1) !== 1) {
                continue;
            }
            $key = trim((string) ($field['field_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $extraFieldTokens[] = [
                'label' => (string) ($field['name'] ?? ('Câmp ' . $key)),
                'token' => '{{field_' . $key . '}}',
            ];
        }
        $allTokens = [];
        foreach ($generalProductTokens as $item) {
            if (!is_array($item)) {
                continue;
            }
            $allTokens[] = ['group' => 'General', 'label' => (string) ($item['label'] ?? ''), 'token' => (string) ($item['token'] ?? '')];
        }
        foreach ($extraFieldTokens as $item) {
            if (!is_array($item)) {
                continue;
            }
            $allTokens[] = ['group' => 'Câmpuri', 'label' => (string) ($item['label'] ?? ''), 'token' => (string) ($item['token'] ?? '')];
        }
        foreach ($reviewTokens as $item) {
            if (!is_array($item)) {
                continue;
            }
            $allTokens[] = ['group' => 'Recenzii', 'label' => (string) ($item['label'] ?? ''), 'token' => (string) ($item['token'] ?? '')];
        }
        foreach ($iconTokens as $item) {
            if (!is_array($item)) {
                continue;
            }
            $allTokens[] = ['group' => 'Iconițe', 'label' => (string) ($item['label'] ?? ''), 'token' => (string) ($item['token'] ?? '')];
        }
        ?>

        <div class="page-toolbar">
            <a href="/admin/products/templates" class="btn btn-secondary">&larr; Înapoi la template-uri</a>
            <button type="button" class="btn btn-secondary code-toggle-arrow" id="toggle-code-pane" title="Ascunde/arată cod">⟵</button>
            <div class="field" style="min-width:260px;flex:1;">
                <input type="text" id="template-name" name="name" value="<?= htmlspecialchars($templateName, ENT_QUOTES) ?>" placeholder="Nume template produs" required>
            </div>
            <div class="field" style="width:220px;">
                <input type="text" id="template-slug" name="slug" value="<?= htmlspecialchars($templateSlug, ENT_QUOTES) ?>" placeholder="slug-template" required>
            </div>
            <label style="display:flex;align-items:center;gap:8px;white-space:nowrap;">
                <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                Activ
            </label>
            <button class="btn" type="submit">Salvează</button>
        </div>

        <div class="field" style="margin-top:12px;">
            <label for="template-description">Descriere</label>
            <input type="text" id="template-description" name="description" value="<?= htmlspecialchars($templateDescription, ENT_QUOTES) ?>" placeholder="Ex: layout cu bloc informații extinse">
        </div>

        <article class="panel" style="margin-top:12px;background:#f8fafc;border-color:#cbd5e1;">
            <h4 style="margin-top:0;">Coduri disponibile pentru template</h4>
            <p style="margin:0 0 10px;color:#64748b;">Poți copia aceste coduri și le poți insera în HTML/CSS/JS.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                <button type="button" class="btn btn-secondary template-token-tab is-active" data-token-tab="general">
                    Coduri generale produse
                </button>
                <button type="button" class="btn btn-secondary template-token-tab" data-token-tab="all">
                    Toate codurile
                </button>
                <button type="button" class="btn btn-secondary template-token-tab" data-token-tab="extra">
                    Coduri câmpuri suplimentare
                </button>
                <button type="button" class="btn btn-secondary template-token-tab" data-token-tab="reviews">
                    Coduri recenzii
                </button>
                <button type="button" class="btn btn-secondary template-token-tab" data-token-tab="icons">
                    Iconițe
                </button>
            </div>

            <div class="template-token-panel is-active" data-token-panel="general" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                <?php foreach ($generalProductTokens as $item): ?>
                    <div style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                        <strong style="display:block;font-size:13px;"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES) ?></strong>
                        <code><?= htmlspecialchars((string) $item['token'], ENT_QUOTES) ?></code>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="template-token-panel" data-token-panel="all" style="display:none;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                <?php foreach ($allTokens as $item): ?>
                    <div style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                        <small style="display:block;color:#64748b;font-weight:700;"><?= htmlspecialchars((string) $item['group'], ENT_QUOTES) ?></small>
                        <strong style="display:block;font-size:13px;"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES) ?></strong>
                        <code><?= htmlspecialchars((string) $item['token'], ENT_QUOTES) ?></code>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="template-token-panel" data-token-panel="extra" style="display:none;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                <?php if ($extraFieldTokens === []): ?>
                    <p class="muted" style="margin:0;">Nu există câmpuri suplimentare active.</p>
                <?php else: ?>
                    <?php foreach ($extraFieldTokens as $item): ?>
                        <div style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                            <strong style="display:block;font-size:13px;"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES) ?></strong>
                            <code><?= htmlspecialchars((string) $item['token'], ENT_QUOTES) ?></code>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="template-token-panel" data-token-panel="reviews" style="display:none;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                <?php foreach ($reviewTokens as $item): ?>
                    <div style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                        <strong style="display:block;font-size:13px;"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES) ?></strong>
                        <code><?= htmlspecialchars((string) $item['token'], ENT_QUOTES) ?></code>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="template-token-panel" data-token-panel="icons" style="display:none;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                <?php foreach ($iconTokens as $item): ?>
                    <div style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                        <strong style="display:block;font-size:13px;"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES) ?></strong>
                        <code><?= htmlspecialchars((string) $item['token'], ENT_QUOTES) ?></code>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <div class="editor-grid" id="template-editor-grid" style="margin-top:14px;">
            <div class="code-column" id="code-column">
                <div class="code-type-tabs">
                    <button type="button" class="code-type-tab active" data-code-type="html">HTML</button>
                    <button type="button" class="code-type-tab" data-code-type="js">Java Script</button>
                    <button type="button" class="code-type-tab" data-code-type="css">CSS</button>
                </div>
                <div class="code-editor-toolbar">
                    <div class="code-editor-toolbar-right">
                        <button type="button" class="btn btn-secondary" id="code-search-btn">Search/Replace</button>
                        <button type="button" class="btn btn-secondary" id="code-beautify-btn">Beautify</button>
                        <button type="button" class="btn btn-secondary" id="code-fullscreen-btn">Fullscreen</button>
                    </div>
                </div>
                <textarea id="html-code" name="html_content" class="code-editor code-editor-pane" data-code-pane="html" required><?= htmlspecialchars($templateHtml, ENT_NOQUOTES) ?></textarea>
                <textarea id="js-code" name="js_content" class="code-editor code-editor-pane is-hidden" data-code-pane="js"><?= htmlspecialchars($templateJs, ENT_NOQUOTES) ?></textarea>
                <textarea id="css-code" name="css_content" class="code-editor code-editor-pane is-hidden" data-code-pane="css"><?= htmlspecialchars($templateCss, ENT_NOQUOTES) ?></textarea>
            </div>
            <div class="preview-column">
                <h3 style="margin-top:0;">Preview — <span id="preview-mode-label">Desktop</span></h3>
                <div class="preview-shell desktop" id="preview-shell">
                    <iframe id="template-preview" title="Preview template produs"></iframe>
                </div>
                <small style="display:block;margin-top:10px;color:#64748b;">
                    Preview demo pentru codurile disponibile (inclusiv câmpuri extra active).
                </small>
            </div>
        </div>
    </form>
</section>

<script>
(() => {
    const htmlCode = document.getElementById('html-code');
    const jsCode = document.getElementById('js-code');
    const cssCode = document.getElementById('css-code');
    const codeTypeTabs = document.querySelectorAll('.code-type-tab');
    const codePanes = document.querySelectorAll('.code-editor-pane');
    const iframe = document.getElementById('template-preview');
    const switches = document.querySelectorAll('.device-switch');
    const previewShell = document.getElementById('preview-shell');
    const editorGrid = document.getElementById('template-editor-grid');
    const codeColumn = document.getElementById('code-column');
    const toggleCodePane = document.getElementById('toggle-code-pane');
    const codeSearchBtn = document.getElementById('code-search-btn');
    const codeBeautifyBtn = document.getElementById('code-beautify-btn');
    const codeFullscreenBtn = document.getElementById('code-fullscreen-btn');
    const tokenTabButtons = document.querySelectorAll('.template-token-tab');
    const tokenPanels = document.querySelectorAll('.template-token-panel');
    const templateNameInput = document.getElementById('template-name');
    const templateSlugInput = document.getElementById('template-slug');
    let slugTouched = templateSlugInput.value.trim() !== '';
    const editors = {};

    const createEditor = (textarea, mode, type) => {
        if (!textarea) return null;
        if (typeof window.CodeMirror !== 'function') {
            return {
                getValue: () => textarea.value,
                setValue: (value) => { textarea.value = String(value ?? ''); },
                save: () => {},
                setVisible: (visible) => textarea.classList.toggle('is-hidden', !visible),
                refresh: () => {},
                onChange: (handler) => textarea.addEventListener('input', handler),
                openSearch: () => {},
                closeSearch: () => {},
                isSearchOpen: () => false,
                focus: () => textarea.focus(),
                type,
            };
        }

        const cm = window.CodeMirror.fromTextArea(textarea, {
            mode,
            theme: 'material-darker',
            lineNumbers: true,
            lineWrapping: true,
            styleActiveLine: true,
            matchBrackets: true,
            autoCloseBrackets: true,
            indentUnit: 2,
            tabSize: 2,
            viewportMargin: Infinity,
        });
        cm.setSize('100%', 560);
        return {
            getValue: () => cm.getValue(),
            setValue: (value) => cm.setValue(String(value ?? '')),
            save: () => cm.save(),
            setVisible: (visible) => {
                const wrapper = cm.getWrapperElement();
                wrapper.style.display = visible ? '' : 'none';
                textarea.classList.toggle('is-hidden', !visible);
                if (visible) cm.refresh();
            },
            refresh: () => cm.refresh(),
            onChange: (handler) => cm.on('change', handler),
            openSearch: () => {
                cm.execCommand('find');
                cm.execCommand('replace');
            },
            closeSearch: () => cm.execCommand('clearSearch'),
            isSearchOpen: () => cm.getWrapperElement().classList.contains('CodeMirror-dialog-open'),
            focus: () => cm.focus(),
            type,
        };
    };

    editors.html = createEditor(htmlCode, 'text/html', 'html');
    editors.js = createEditor(jsCode, 'javascript', 'js');
    editors.css = createEditor(cssCode, 'css', 'css');

    const getEditorValue = (type) => editors[type]?.getValue?.() ?? '';
    const getActiveCodeType = () => document.querySelector('.code-type-tab.active')?.dataset.codeType || 'html';
    const getActiveEditor = () => editors[getActiveCodeType()] || null;
    const beautifyContent = (editor) => {
        if (!editor) return false;
        const value = editor.getValue();
        try {
            let formatted = value;
            if (editor.type === 'html' && typeof window.html_beautify === 'function') {
                formatted = window.html_beautify(value, { indent_size: 2, wrap_line_length: 120, preserve_newlines: true });
            } else if (editor.type === 'css' && typeof window.css_beautify === 'function') {
                formatted = window.css_beautify(value, { indent_size: 2 });
            } else if (editor.type === 'js' && typeof window.js_beautify === 'function') {
                formatted = window.js_beautify(value, { indent_size: 2, preserve_newlines: true });
            } else {
                return false;
            }
            editor.setValue(formatted);
            return true;
        } catch {
            return false;
        }
    };
    const setActiveCodeType = (type) => {
        codeTypeTabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.codeType === type));
        codePanes.forEach((pane) => {
            const paneType = pane.dataset.codePane;
            const visible = paneType === type;
            pane.classList.toggle('is-hidden', !visible);
            editors[paneType]?.setVisible?.(visible);
        });
    };

    const replacePlaceholders = (content) => {
        const map = {
            '{{product_name}}': 'Produs demo',
            '{{product_price}}': '99.00',
            '{{product_price_display}}': '99.00 lei',
            '{{product_short_description}}': 'Descriere scurtă produs demo',
            '{{product_description}}': 'Descriere detaliată produs demo',
            '{{product_highlights}}': 'Absorbție rapidă; Formula premium; Fără zahăr',
            '{{product_image_url}}': '/assets/img/product-placeholder.svg',
            '{{product_image_gallery}}': '<div style="display:flex;gap:8px;"><img src="/assets/img/product-placeholder.svg" style="width:90px;height:90px;object-fit:cover;border-radius:8px;"><img src="/assets/img/product-placeholder.svg" style="width:90px;height:90px;object-fit:cover;border-radius:8px;"></div>',
            '{{product_image_carousel}}': '<div class="product-carousel" data-product-carousel data-current="0"><div class="product-carousel__viewport" data-carousel-viewport><button type="button" class="product-carousel__fullscreen" data-action="fullscreen" aria-label="Afișează imaginea pe tot ecranul"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H4a1 1 0 0 0-1 1v4h2V5h3V3zm13 0h-4v2h3v3h2V4a1 1 0 0 0-1-1zM3 16v4a1 1 0 0 0 1 1h4v-2H5v-3H3zm17 0v3h-3v2h4a1 1 0 0 0 1-1v-4h-2z"/></svg></button><div class="product-carousel__track"><figure class="product-carousel__slide" data-slide="0"><img src="/assets/img/product-placeholder.svg" alt="Produs demo" data-carousel-image="1" data-target="0"></figure><figure class="product-carousel__slide" data-slide="1"><img src="/assets/img/product-placeholder.svg" alt="Produs demo" data-carousel-image="1" data-target="1"></figure></div></div><div class="product-carousel__controls"><button type="button" class="product-carousel__nav" data-action="prev">‹</button><div class="product-carousel__thumbs"><button type="button" class="product-carousel__thumb" data-target="0"><img src="/assets/img/product-placeholder.svg" alt="Thumb 1"></button><button type="button" class="product-carousel__thumb" data-target="1"><img src="/assets/img/product-placeholder.svg" alt="Thumb 2"></button></div><button type="button" class="product-carousel__nav" data-action="next">›</button></div></div>',
            '{{product_reviews_count}}': '10',
            '{{product_reviews_average}}': '4.8',
            '{{product_reviews_stars}}': '<span style="color:#f59e0b;">★★★★★</span>',
            '{{product_reviews_average_raw}}': '4.80',
            '{{product_reviews_list}}': '<div><article style="padding:8px;border:1px solid #e5e7eb;border-radius:8px;"><strong>Client demo</strong><p style="margin:4px 0 0;">Foarte bun produs.</p></article></div>',
            '{{product_review_form}}': '<form style="display:grid;gap:8px;"><input placeholder="Nume"><textarea placeholder="Review"></textarea><button type="button">Trimite review</button></form>',
            '{{product_reviews_section}}': '<section style="display:grid;gap:8px;"><h3>Review-uri</h3><div><span style="color:#f59e0b;">★★★★★</span> 4.8 (10)</div><article style="padding:8px;border:1px solid #e5e7eb;border-radius:8px;"><strong>Client demo</strong><p style="margin:4px 0 0;">Foarte bun produs.</p></article></section>',
            '{{product_tabs_section}}': '<section class="product-tabs"><div class="product-tabs__nav"><button type="button" class="product-tabs__tab is-active" data-tab="field-mod-administrare">Mod administrare</button><button type="button" class="product-tabs__tab" data-tab="reviews"><span>Recenzii</span></button><button type="button" class="product-tabs__tab" data-tab="write-review"><span>Lasă o recenzie</span></button></div><div class="product-tabs__content"><article class="product-tabs__pane is-active" data-pane="field-mod-administrare"><h3>Mod administrare</h3><p>2 capsule pe zi, după masă, minimum 30 de zile.</p></article><article class="product-tabs__pane" data-pane="reviews"><section id="product-reviews" class="product-reviews"><div class="product-reviews-head"><div class="product-reviews-head__inline"><span style="color:#f59e0b;">★★★★★</span><p>4.8 din 5 · 10 review-uri</p></div></div><div class="product-reviews-list"><article class="product-review-item"><strong>Client demo</strong><p style="margin:6px 0 0;">Foarte bun produs.</p></article></div></section></article><article class="product-tabs__pane" data-pane="write-review"><section id="product-review-form" class="product-reviews"><form style="display:grid;gap:8px;"><input placeholder="Nume"><textarea placeholder="Review"></textarea><button type="button">Trimite review</button></form></section></article></div></section>',
            '{{product_similar_products_section}}': '<section class="similar-products" data-similar-products="1"><div class="similar-products__head"><h3>Produse similare</h3></div><div class="similar-products__carousel"><button type="button" class="similar-products__nav" data-action="prev" aria-label="Produse anterioare">‹</button><div class="similar-products__viewport"><div class="similar-products__track" data-similar-track="1"><article class="similar-product-card"><a class="similar-product-card__image-link" href="#"><img src="/assets/img/product-placeholder.svg" alt="Produs demo"></a><div class="similar-product-card__body"><p class="similar-product-card__category">SUPLIMENTE</p><a class="similar-product-card__name" href="#">Produs demo A</a><p class="similar-product-card__price">89.00 lei</p></div></article><article class="similar-product-card"><a class="similar-product-card__image-link" href="#"><img src="/assets/img/product-placeholder.svg" alt="Produs demo"></a><div class="similar-product-card__body"><p class="similar-product-card__category">SUPLIMENTE</p><a class="similar-product-card__name" href="#">Produs demo B</a><p class="similar-product-card__price">99.00 lei</p></div></article></div></div><button type="button" class="similar-products__nav" data-action="next" aria-label="Produse următoare">›</button></div><div class="similar-products__dots" data-similar-dots="1" aria-label="Paginare produse similare"></div><script type="application/json" class="similar-products__data">[{&quot;id&quot;:1,&quot;name&quot;:&quot;Produs demo A&quot;,&quot;url&quot;:&quot;#&quot;,&quot;category&quot;:&quot;SUPLIMENTE&quot;,&quot;image_url&quot;:&quot;/assets/img/product-placeholder.svg&quot;,&quot;price_label&quot;:&quot;89.00 lei&quot;,&quot;short_description&quot;:&quot;&quot;},{&quot;id&quot;:2,&quot;name&quot;:&quot;Produs demo B&quot;,&quot;url&quot;:&quot;#&quot;,&quot;category&quot;:&quot;SUPLIMENTE&quot;,&quot;image_url&quot;:&quot;/assets/img/product-placeholder.svg&quot;,&quot;price_label&quot;:&quot;99.00 lei&quot;,&quot;short_description&quot;:&quot;&quot;}]<\/script></section>',
            '{{product_icon_leaf}}': '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M19.1 5.1c-6 .2-10.4 3.9-10.4 6.5 0 2.9 2 4.9 4.5 4.9 4.2 0 7.7-4 8.2-9.6.1-.9-.6-1.8-2.3-1.8Z" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.6 20.4c2.1-4.7 6.1-8 11.3-10.2" fill="none" stroke="currentColor" stroke-width="1.45" stroke-linecap="round"/></svg>',
            '{{product_icon_truck}}': '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M3.5 8h9.8v6.2H3.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M13.3 10.2h3.6l2.6 2.7v1.3h-6.2Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="8" cy="18" r="1.8" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.4" cy="18" r="1.8" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M3.5 14.2h2.3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
            '{{product_icon_shield}}': '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 3 5 6v6c0 4.4 2.8 7.8 7 9 4.2-1.2 7-4.6 7-9V6l-7-3z"/><path d="m9.5 12 2 2 3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>',
            '{{product_icon_star}}': '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="m12 3 2.9 5.88 6.5.95-4.7 4.58 1.1 6.49L12 17.8 6.2 20.9l1.1-6.49L2.6 9.83l6.5-.95L12 3z"/></svg>',
            '{{product_icon_camera}}': '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9 5h6l1.2 2H20a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h3.8L9 5zm3 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg>',
            '{{product_icon_sparkles}}': '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 2l1.8 4.2L18 8l-4.2 1.8L12 14l-1.8-4.2L6 8l4.2-1.8L12 2zm7 9l1 2.3L22 14l-2 .7L19 17l-1-2.3L16 14l2-.7L19 11zM5 14l1.2 2.8L9 18l-2.8 1.2L5 22l-1.2-2.8L1 18l2.8-1.2L5 14z"/></svg>',
            '{{product_icon_calendar}}': '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M7 2h2v3H7V2zm8 0h2v3h-2V2zM4 5h16a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zm0 5v10h16V10H4z"/></svg>',
            '{{product_icon_user}}': '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-5 0-9 2.5-9 5.5V22h18v-2.5c0-3-4-5.5-9-5.5z"/></svg>',
            '{{product_category_url}}': '/magazin?category=Suplimente',
            '{{product_quantity_input}}': '<div class="qty-stepper" style="display:inline-flex;align-items:center;justify-content:center;gap:0;flex-wrap:nowrap;border:1px solid #d1d9e3;border-radius:999px;background:#fff;padding:1px 2px;height:44px;vertical-align:middle;"><button type="button" class="qty-stepper__btn" data-role="qty-minus" aria-label="Scade cantitatea" style="border:0;background:transparent;border-radius:999px;width:40px;height:40px;line-height:1;font-size:22px;color:#1e293b;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;">−</button><input id="quantity" name="quantity" type="number" min="1" value="1" class="qty-stepper__input" style="width:58px;border:0;background:transparent;text-align:center;font-size:16px;font-weight:700;color:#0f172a;padding:0;margin:0;outline:none;line-height:1;height:40px;display:block;-moz-appearance:textfield;"><button type="button" class="qty-stepper__btn" data-role="qty-plus" aria-label="Crește cantitatea" style="border:0;background:transparent;border-radius:999px;width:40px;height:40px;line-height:1;font-size:22px;color:#1e293b;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;">+</button></div>',
            '{{product_add_to_cart_button}}': '<button class="btn" type="button" data-product-cart-button="1" data-product-id="1" data-unit-price="99.00" style="display:inline-flex;align-items:center;gap:8px;height:48px;padding:0 24px;border:1px solid #107a4d;background:#107a4d;color:#fff;font-size:16px;font-weight:600;border-radius:999px;"><svg viewBox="0 0 24 24" aria-hidden="true" style="width:18px;height:18px;flex:0 0 auto;"><path d="M3 4h2l1.7 8.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 1.9-1.4l1.5-5.2H7.4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.7" cy="19" r="1.4" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.5" cy="19" r="1.4" fill="none" stroke="currentColor" stroke-width="1.7"/></svg><span style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;"><span>Adaugă în coș -</span><span data-cart-button-total>99.00 lei</span></span></button>',
            '{{product_post_cart_note}}': '<div style="margin-top:10px;font:500 13px/1.4 \'DM Sans\',Arial,sans-serif;color:#475569;">Text promoțional sub butonul de comandă.</div>',
            '{{field_cod_camp}}': 'Valoare demo câmp',
            '{{field_mod_administrare}}': '2 capsule pe zi, după masă, minimum 30 de zile.',
        };
        let output = content;
        Object.entries(map).forEach(([needle, value]) => {
            output = output.split(needle).join(value);
        });
        return output;
    };

    const previewBaseCss = `
body{margin:0;font-family:Arial,sans-serif;color:#111827;padding:24px;line-height:1.6;background:#f8fafc;}
img{max-width:100%;height:auto;}
.product-carousel__viewport{position:relative;min-height:220px;overflow:hidden;border-radius:12px;touch-action:pan-y;user-select:none;}
.product-carousel__track{display:flex;width:100%;transition:transform .35s ease;will-change:transform;}
.product-carousel__slide{flex:0 0 100%;width:100%;margin:0;}
.product-carousel__slide img{width:100%;display:block;border-radius:12px;border:1px solid #e5e7eb;}
.product-carousel__fullscreen{position:absolute;top:10px;right:10px;z-index:4;width:34px;height:34px;border:1px solid rgba(148,163,184,.55);border-radius:10px;background:rgba(15,23,42,.72);color:#fff;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;}
.product-carousel__fullscreen svg{width:17px;height:17px;fill:currentColor;}
.product-carousel__controls{margin-top:8px;display:grid;grid-template-columns:36px minmax(0,1fr) 36px;align-items:center;gap:10px;}
.product-carousel__nav{width:32px;height:32px;border:1px solid #d1d5db;background:#fff;border-radius:999px;cursor:pointer;}
.product-carousel__thumbs{display:flex;gap:6px;overflow-x:auto;padding-bottom:2px;}
.product-carousel__thumb{border:1px solid #d1d5db;background:#fff;border-radius:8px;width:52px;height:52px;padding:0;overflow:hidden;cursor:pointer;opacity:.75;}
.product-carousel__thumb img{width:100%;height:100%;object-fit:cover;display:block;}
.product-carousel__thumb.is-active{border-color:#0f766e;box-shadow:0 0 0 2px rgba(15,118,110,.12);opacity:1;}
.product-carousel.is-dragging .product-carousel__track{transition:none !important;cursor:grabbing;}
.product-carousel-fullscreen{position:fixed;inset:0;background:rgba(2,6,23,.92);display:none;align-items:center;justify-content:center;z-index:9999;padding:18px;}
.product-carousel-fullscreen.is-active{display:flex;}
.product-carousel-fullscreen__image{max-width:min(1200px,92vw);max-height:84vh;object-fit:contain;border-radius:12px;}
.product-carousel-fullscreen__close{position:absolute;top:16px;right:16px;width:40px;height:40px;border:1px solid rgba(148,163,184,.6);border-radius:999px;background:rgba(15,23,42,.75);color:#fff;font-size:24px;line-height:1;cursor:pointer;}
.product-carousel-fullscreen__nav{position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;border:1px solid rgba(148,163,184,.55);border-radius:999px;background:rgba(15,23,42,.75);color:#fff;font-size:24px;cursor:pointer;}
.product-carousel-fullscreen__nav[data-action="prev"]{left:16px;}
.product-carousel-fullscreen__nav[data-action="next"]{right:16px;}
.similar-products{margin-top:16px;}
.similar-products__carousel{margin-top:12px;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:8px;align-items:center;}
.similar-products__nav{width:32px;height:32px;border:1px solid #d1d5db;background:#fff;border-radius:999px;cursor:pointer;}
.similar-products__viewport{overflow-x:auto;scroll-behavior:smooth;scrollbar-width:none;}
.similar-products__viewport::-webkit-scrollbar{display:none;}
.similar-products__track{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;width:100%;}
.similar-product-card{border:1px solid #e2e8f0;border-radius:12px;background:#fff;overflow:hidden;}
.similar-product-card__image-link{display:block;aspect-ratio:4/3;background:#f8fafc;}
.similar-product-card__image-link img{width:100%;height:100%;object-fit:cover;display:block;}
.similar-product-card__body{padding:10px;display:grid;gap:6px;}
.similar-product-card__category{margin:0;font-size:11px;line-height:1.2;letter-spacing:.08em;text-transform:uppercase;color:#64748b;}
.similar-product-card__name{font-size:15px;font-weight:600;color:#0f172a;text-decoration:none;}
.similar-product-card__price{margin:0;font-size:16px;font-weight:700;color:#0f172a;}
@media (max-width: 800px){.similar-products__carousel{grid-template-columns:1fr;}.similar-products__nav{display:none;}.similar-products__track{grid-template-columns:1fr;}}
.product-tabs{margin-top:16px;}
.product-tabs__nav{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;border:1px solid #e2e8f0;border-radius:999px;background:#f8fafc;padding:8px;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.product-tabs__tab{border:0;background:transparent;color:#64748b;font-weight:700;font-size:14px;border-radius:999px;padding:8px 14px;cursor:pointer;}
.product-tabs__tab.is-active{background:#fff;color:#0f172a;box-shadow:0 1px 2px rgba(15,23,42,.08);}
.product-tabs__content{margin-top:14px;border:1px solid #e2e8f0;border-radius:14px;padding:20px 20px 28px;background:#fff;overflow:visible;}
.product-tabs__pane{display:none;opacity:0;transform:translateY(8px);transition:opacity .28s ease, transform .28s ease;}
.product-tabs__pane.is-active{display:block;opacity:1;transform:translateY(0);}
.product-tabs__pane h3{margin:0 0 8px;color:#0f172a;}
.product-tabs__pane p{margin:0 0 12px;color:#0f172a;}
.product-tabs__pane p:last-child{margin-bottom:0;}
`;

    const previewBootstrapJs = `
(() => {
  const initTabs = (scope) => {
    const navs = Array.from(scope.querySelectorAll('.product-tabs__nav, .pdp-v2__tabs, [data-tabs-nav]'));
    navs.forEach((nav) => {
      if (!(nav instanceof HTMLElement) || nav.dataset.tabsInitialized === '1') return;
      const tabs = Array.from(nav.querySelectorAll('[data-tab]'));
      const container = nav.closest('[data-tabs-root], .product-tabs, .pdp-v2') || nav.closest('section, article, div') || scope;
      const panes = Array.from(container.querySelectorAll('[data-pane]'));
      if (!tabs.length || !panes.length) return;
      const content = container.querySelector('.product-tabs__content');
      const clearContentHeight = () => {
        if (content instanceof HTMLElement) {
          content.style.height = '';
        }
      };
      let activeKey = '';
      const activate = (key, animate = false) => {
        const previousKey = activeKey;
        activeKey = key;
        tabs.forEach((tab) => {
          const active = String(tab.getAttribute('data-tab') || '') === key && tab.style.display !== 'none';
          tab.classList.toggle('is-active', active);
        });
        panes.forEach((pane) => {
          const paneKey = String(pane.getAttribute('data-pane') || '');
          const active = paneKey === key && pane.style.display !== 'none';
          const wasActive = paneKey === previousKey;
          pane.classList.toggle('is-active', active);
          pane.setAttribute('aria-hidden', active ? 'false' : 'true');
          if (animate && active && !wasActive) {
            pane.classList.remove('tab-anim-enter');
            pane.classList.remove('tab-anim-leave');
            void pane.offsetWidth;
            pane.classList.add('tab-anim-enter');
          } else if (animate && wasActive && !active) {
            pane.classList.remove('tab-anim-enter');
            pane.classList.remove('tab-anim-leave');
            void pane.offsetWidth;
            pane.classList.add('tab-anim-leave');
          } else {
            pane.classList.remove('tab-anim-enter');
            pane.classList.remove('tab-anim-leave');
          }
        });
        clearContentHeight();
      };
      nav.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const btn = target.closest('[data-tab]');
        if (!(btn instanceof HTMLElement) || !nav.contains(btn)) return;
        event.preventDefault();
        const key = String(btn.getAttribute('data-tab') || '').trim();
        if (key !== '') activate(key, true);
      });
      const first = tabs.find((tab) => tab.style.display !== 'none') || tabs[0];
      const initial = String(first?.getAttribute('data-tab') || '');
      if (initial !== '') activate(initial);
      clearContentHeight();
      window.addEventListener('resize', () => clearContentHeight(), { passive: true });
      nav.dataset.tabsInitialized = '1';
    });
  };

  const createFullscreenCarousel = (root, getCurrentIndex, setActive) => {
    const slides = Array.from(root.querySelectorAll('.product-carousel__slide img'));
    if (!slides.length) return;
    const overlay = document.createElement('div');
    overlay.className = 'product-carousel-fullscreen';
    overlay.innerHTML = ''
      + '<button type="button" class="product-carousel-fullscreen__close" aria-label="Închide">×</button>'
      + '<button type="button" class="product-carousel-fullscreen__nav" data-action="prev" aria-label="Imagine anterioară">‹</button>'
      + '<img class="product-carousel-fullscreen__image" alt="">'
      + '<button type="button" class="product-carousel-fullscreen__nav" data-action="next" aria-label="Imagine următoare">›</button>';
    document.body.appendChild(overlay);
    const image = overlay.querySelector('.product-carousel-fullscreen__image');
    const update = () => {
      if (!(image instanceof HTMLImageElement)) return;
      const source = slides[getCurrentIndex()];
      if (!(source instanceof HTMLImageElement)) return;
      image.src = source.currentSrc || source.src;
      image.alt = source.alt || 'Imagine produs';
    };
    const close = () => overlay.classList.remove('is-active');
    overlay.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      if (target.closest('.product-carousel-fullscreen__close') || target === overlay) {
        close();
        return;
      }
      const navButton = target.closest('[data-action]');
      if (!(navButton instanceof HTMLElement)) return;
      const action = navButton.getAttribute('data-action');
      if (action === 'prev') setActive(getCurrentIndex() - 1);
      if (action === 'next') setActive(getCurrentIndex() + 1);
      update();
    });
    document.addEventListener('keydown', (event) => {
      if (!overlay.classList.contains('is-active')) return;
      if (event.key === 'Escape') {
        close();
      } else if (event.key === 'ArrowLeft') {
        setActive(getCurrentIndex() - 1);
        update();
      } else if (event.key === 'ArrowRight') {
        setActive(getCurrentIndex() + 1);
        update();
      }
    });
    root.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const fullscreenButton = target.closest('.product-carousel__fullscreen,[data-action="fullscreen"]');
      if (fullscreenButton instanceof HTMLElement) {
        event.preventDefault();
        update();
        overlay.classList.add('is-active');
        return;
      }
      const imageCandidate = target.closest('[data-carousel-image="1"]');
      if (!(imageCandidate instanceof HTMLElement)) return;
      const idx = Number(imageCandidate.getAttribute('data-target') || '0');
      if (Number.isFinite(idx)) {
        setActive(idx);
      }
    });
  };

  const initCarousels = (scope) => {
    const roots = Array.from(scope.querySelectorAll('[data-product-carousel], .product-carousel'));
    roots.forEach((root) => {
      if (!(root instanceof HTMLElement) || root.dataset.carouselInitialized === '1') return;
      const viewport = root.querySelector('[data-carousel-viewport], .product-carousel__viewport');
      const track = root.querySelector('.product-carousel__track');
      const slides = Array.from(root.querySelectorAll('.product-carousel__slide'));
      const thumbs = Array.from(root.querySelectorAll('.product-carousel__thumb'));
      if (!(viewport instanceof HTMLElement) || !(track instanceof HTMLElement) || !slides.length) return;
      let current = 0;
      let dragging = false;
      let startX = 0;
      let deltaX = 0;
      let activePointerId = null;
      const setActive = (next, animate = true) => {
        current = (next + slides.length) % slides.length;
        track.style.transition = animate ? 'transform .35s ease' : 'none';
        track.style.transform = 'translateX(' + (-100 * current) + '%)';
        thumbs.forEach((thumb, idx) => thumb.classList.toggle('is-active', idx === current));
        const activeThumb = thumbs[current];
        if (activeThumb instanceof HTMLElement) {
          activeThumb.scrollIntoView({ behavior: animate ? 'smooth' : 'auto', inline: 'center', block: 'nearest' });
        }
      };
      root.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const nav = target.closest('.product-carousel__nav');
        if (nav instanceof HTMLElement) {
          const action = nav.getAttribute('data-action');
          if (action === 'prev') setActive(current - 1);
          if (action === 'next') setActive(current + 1);
          return;
        }
        const thumb = target.closest('.product-carousel__thumb');
        if (thumb instanceof HTMLElement) {
          const idx = Number(thumb.getAttribute('data-target') || '0');
          setActive(Number.isFinite(idx) ? idx : 0);
        }
      });
      viewport.addEventListener('pointerdown', (event) => {
        if (event.pointerType === 'mouse' && event.button !== 0) return;
        const target = event.target;
        if (target instanceof Element && target.closest('.product-carousel__fullscreen')) return;
        dragging = true;
        startX = event.clientX;
        deltaX = 0;
        track.style.willChange = 'transform';
        activePointerId = event.pointerId;
        viewport.setPointerCapture(event.pointerId);
        event.preventDefault();
        track.style.transition = 'none';
        root.classList.add('is-dragging');
      });
      viewport.addEventListener('pointermove', (event) => {
        if (!dragging) return;
        deltaX = event.clientX - startX;
        const width = Math.max(1, viewport.clientWidth || root.clientWidth || 1);
        const percentOffset = (deltaX / width) * 100;
        track.style.transform = 'translateX(' + ((-100 * current) + percentOffset) + '%)';
      });
      const endDrag = () => {
        if (!dragging) return;
        dragging = false;
        if (activePointerId !== null && typeof viewport.releasePointerCapture === 'function') {
          try {
            viewport.releasePointerCapture(activePointerId);
          } catch (_error) {
            // ignore invalid pointer release
          }
        }
        activePointerId = null;
        root.classList.remove('is-dragging');
        track.style.willChange = '';
        const width = Math.max(1, viewport.clientWidth || root.clientWidth || 1);
        const threshold = width * 0.12;
        if (Math.abs(deltaX) >= threshold) {
          setActive(current + (deltaX < 0 ? 1 : -1));
        } else {
          setActive(current);
        }
        deltaX = 0;
      };
      viewport.addEventListener('pointerup', endDrag);
      viewport.addEventListener('pointercancel', endDrag);
      viewport.addEventListener('lostpointercapture', endDrag);
      createFullscreenCarousel(root, () => current, (next) => setActive(next));
      setActive(0);
      root.dataset.carouselInitialized = '1';
    });
  };

  const initTemplateCartButton = (scope) => {
    const qtyInput = scope.querySelector('input[name="quantity"]');
    if (!(qtyInput instanceof HTMLInputElement)) return;
    const buttons = Array.from(scope.querySelectorAll('[data-product-cart-button="1"]'));
    if (!buttons.length) return;
    const syncTotal = () => {
      const quantity = Math.max(1, Number.parseInt(qtyInput.value || '1', 10) || 1);
      buttons.forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) return;
        const unitPrice = Math.max(0, Number.parseFloat(button.dataset.unitPrice || '0') || 0);
        const total = unitPrice * quantity;
        const target = button.querySelector('[data-cart-button-total]');
        if (target instanceof HTMLElement) {
          target.textContent = total.toFixed(2) + ' lei';
        }
      });
    };
    scope.querySelectorAll('.qty-stepper').forEach((wrap) => {
      wrap.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const role = target.getAttribute('data-role');
        if (!role) return;
        const input = wrap.querySelector('input[type="number"]');
        if (!(input instanceof HTMLInputElement)) return;
        const min = Math.max(1, Number(input.min || '1'));
        const current = Number(input.value || min);
        const next = role === 'qty-plus' ? current + 1 : Math.max(min, current - 1);
        input.value = String(next);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
    qtyInput.addEventListener('input', syncTotal);
    qtyInput.addEventListener('change', syncTotal);
    syncTotal();
  };

  initTabs(document);
  initCarousels(document);
  initTemplateCartButton(document);
})();
`;

    const renderPreview = () => {
        const rawHtml = getEditorValue('html') || '<p>Scrie codul template-ului pentru preview...</p>';
        const body = replacePlaceholders(rawHtml);
        const css = replacePlaceholders(getEditorValue('css') || '');
        const js = replacePlaceholders(getEditorValue('js') || '').replace(/<\/script>/gi, '<\\/script>');
        iframe.srcdoc = '<!doctype html>'
            + '<html lang="ro"><head>'
            + '<meta charset="utf-8" />'
            + '<meta name="viewport" content="width=device-width, initial-scale=1" />'
            + '<style>' + previewBaseCss + '</style>'
            + '<style>' + css + '</style>'
            + '</head><body>' + body + '<script>' + js + '<\/script><script>' + previewBootstrapJs.replace(/<\/script>/gi, '<\\/script>') + '<\/script></body></html>';
    };

    editors.html?.onChange?.(renderPreview);
    editors.js?.onChange?.(renderPreview);
    editors.css?.onChange?.(renderPreview);
    renderPreview();

    codeTypeTabs.forEach((button) => {
        button.addEventListener('click', () => {
            const type = button.dataset.codeType || 'html';
            setActiveCodeType(type);
        });
    });
    setActiveCodeType('html');

    toggleCodePane?.addEventListener('click', () => {
        const nowHidden = !codeColumn.classList.contains('is-hidden');
        codeColumn.classList.toggle('is-hidden', nowHidden);
        editorGrid.classList.toggle('code-hidden', nowHidden);
        toggleCodePane.textContent = nowHidden ? '⟶' : '⟵';
        if (!nowHidden) {
            const active = document.querySelector('.code-type-tab.active')?.dataset.codeType || 'html';
            editors[active]?.refresh?.();
        }
    });
    codeSearchBtn?.addEventListener('click', () => {
        const editor = getActiveEditor();
        if (editor?.isSearchOpen?.()) {
            editor?.closeSearch?.();
            return;
        }
        editor?.openSearch?.();
        editor?.focus?.();
    });
    codeBeautifyBtn?.addEventListener('click', () => {
        const editor = getActiveEditor();
        const ok = beautifyContent(editor);
        if (ok) {
            renderPreview();
            codeBeautifyBtn.classList.add('active');
            window.setTimeout(() => codeBeautifyBtn.classList.remove('active'), 700);
        }
    });
    codeFullscreenBtn?.addEventListener('click', () => {
        codeColumn.classList.toggle('is-fullscreen');
        const active = getActiveCodeType();
        editors[active]?.refresh?.();
        codeFullscreenBtn.textContent = codeColumn.classList.contains('is-fullscreen') ? 'Exit fullscreen' : 'Fullscreen';
    });

    tokenTabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const tab = btn.getAttribute('data-token-tab') || 'general';
            tokenTabButtons.forEach((b) => b.classList.toggle('is-active', b === btn));
            tokenPanels.forEach((panel) => {
                const isActive = panel.getAttribute('data-token-panel') === tab;
                panel.classList.toggle('is-active', isActive);
                panel.style.display = isActive ? 'grid' : 'none';
            });
        });
    });

    const slugify = (value) => value
        .toLowerCase()
        .replace(/[^a-z0-9\\s-]/g, '')
        .trim()
        .replace(/[\\s-]+/g, '-');
    templateSlugInput?.addEventListener('input', () => { slugTouched = true; });
    templateNameInput?.addEventListener('input', () => {
        if (!slugTouched && templateSlugInput) {
            templateSlugInput.value = slugify(templateNameInput.value);
        }
    });

    document.getElementById('product-template-editor-form')?.addEventListener('submit', () => {
        Object.values(editors).forEach((editor) => editor?.save?.());
    });
})();
</script>
