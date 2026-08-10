<?php
$pageId = (int) ($page['id'] ?? 0);
$pageTitle = (string) ($page['title'] ?? '');
$pageSlug = (string) ($page['slug'] ?? '');
$pageHtml = (string) ($page['html_content'] ?? '');
$pageCss = (string) ($page['css_content'] ?? '');
$pageJs = (string) ($page['js_content'] ?? '');
$isPublished = (int) ($page['is_published'] ?? 1) === 1;
$pageSeo = is_array($pageSeo ?? null) ? $pageSeo : [];
$pageSeoTitle = (string) ($pageSeo['title'] ?? '');
$pageSeoDescription = (string) ($pageSeo['description'] ?? '');
$pageSeoCanonicalUrl = (string) ($pageSeo['canonical_url'] ?? '');
$pageSeoImageUrl = (string) ($pageSeo['image_url'] ?? '');
$mannequinPreviewHtml = (string) ($mannequinPreviewHtml ?? '');
$shopCatalogCodeToken = (string) ($shopCatalogCode ?? '{{shop_catalog}}');
$shopCatalogPreviewHtml = (string) ($shopCatalogPreviewHtml ?? '');
$blogPostsCodeToken = (string) ($blogPostsCode ?? '{{blog_posts}}');
$blogPostsPreviewHtml = (string) ($blogPostsPreviewHtml ?? '');
$cartFormCodeToken = (string) ($cartFormCode ?? '{{cart_form}}');
$cartFormPreviewHtml = (string) ($cartFormPreviewHtml ?? '');
$checkoutFormCodeToken = (string) ($checkoutFormCode ?? '{{checkout_form}}');
$checkoutFormPreviewHtml = (string) ($checkoutFormPreviewHtml ?? '');
$accountSectionCodeToken = (string) ($accountSectionCode ?? '{{account_section}}');
$accountSectionPreviewHtml = (string) ($accountSectionPreviewHtml ?? '');
$authGoogleButtonCodeToken = (string) ($authGoogleButtonCode ?? '{{auth_google_button}}');
$authGoogleButtonPreviewHtml = (string) ($authGoogleButtonPreviewHtml ?? '');
$productReviewFormCodeToken = (string) ($productReviewFormCode ?? '{{product_review_form}}');
$productReviewFormPreviewHtml = (string) ($productReviewFormPreviewHtml ?? '');
$gdprAgreementsFormCodeToken = (string) ($gdprAgreementsFormCode ?? '{{gdpr_agreements_form}}');
$gdprAgreementsFormPreviewHtml = (string) ($gdprAgreementsFormPreviewHtml ?? '');
$checkoutSuccessOrderInfoCodeToken = (string) ($checkoutSuccessOrderInfoCode ?? '{{checkout_success_order_info}}');
$checkoutSuccessOrderInfoPreviewHtml = (string) ($checkoutSuccessOrderInfoPreviewHtml ?? '');
$previewAppCssVersion = @filemtime(__DIR__ . '/../../public/assets/css/app.css') ?: time();
$previewAppCssHref = '/assets/css/app.css?v=' . $previewAppCssVersion;
$previewAppCssContent = @file_get_contents(__DIR__ . '/../../public/assets/css/app.css');
if (!is_string($previewAppCssContent)) {
    $previewAppCssContent = '';
}
?>

<section class="panel">
    <?php $mannequinCodeToken = (string) ($mannequinCode ?? '{{mannequin_section}}'); ?>
    <form method="post" action="/admin/pages/save" id="page-editor-form">
        <input type="hidden" name="id" value="<?= $pageId ?>">

        <div class="page-toolbar">
            <a href="/admin/pages" class="btn btn-secondary">&larr; Înapoi</a>
            <button type="button" class="btn btn-secondary code-toggle-arrow" id="toggle-code-pane" title="Ascunde/arată cod">⟵</button>
            <div class="field" style="min-width:260px;flex:1;">
                <input type="text" id="page-title" name="title" value="<?= htmlspecialchars($pageTitle, ENT_QUOTES) ?>" placeholder="Titlu paginii" required>
            </div>
            <div class="field" style="width:220px;">
                <input type="text" id="page-slug" name="slug" value="<?= htmlspecialchars($pageSlug, ENT_QUOTES) ?>" placeholder="slug-url (gol pentru pagina /)">
            </div>
            <label style="display:flex;align-items:center;gap:8px;white-space:nowrap;">
                <input type="checkbox" name="is_published" value="1" <?= $isPublished ? 'checked' : '' ?>>
                Publicată
            </label>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" class="btn btn-secondary device-switch active" data-device="desktop" title="Desktop">
                    <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/></svg>
                </button>
                <button type="button" class="btn btn-secondary device-switch" data-device="tablet" title="Tabletă">
                    <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="3" width="12" height="18" rx="2"/><circle cx="12" cy="17.5" r="0.8"/></svg>
                </button>
                <button type="button" class="btn btn-secondary device-switch" data-device="mobile" title="Telefon">
                    <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="2" width="8" height="20" rx="2"/><circle cx="12" cy="18.5" r="0.8"/></svg>
                </button>
            </div>
            <button class="btn" type="submit">Salvează</button>
        </div>

        <details class="panel" style="margin-top:12px;background:#f8fafc;border-color:#cbd5e1;">
            <summary style="cursor:pointer;font-weight:700;color:#334155;">Coduri disponibile pentru pagină <span style="color:#64748b;font-weight:400;font-size:.9em;">(click pentru arată/ascunde)</span></summary>
            <p style="margin:10px 0;color:#64748b;">
                Pentru secțiuni dinamice, poți folosi token-urile de mai jos în HTML:
            </p>
            <div style="display:grid;gap:6px;">
                <div><code><?= htmlspecialchars($mannequinCodeToken, ENT_QUOTES) ?></code> <span style="color:#64748b;">→ secțiune manechin</span></div>
                <div><code><?= htmlspecialchars($shopCatalogCodeToken, ENT_QUOTES) ?></code> <span style="color:#64748b;">→ catalog magazin (filtre + produse)</span></div>
                <div><code><?= htmlspecialchars($blogPostsCodeToken, ENT_QUOTES) ?></code> <span style="color:#64748b;">→ listare postări blog</span></div>
                <div><code><?= htmlspecialchars($cartFormCodeToken, ENT_QUOTES) ?></code> <span style="color:#64748b;">→ coș de cumpărături (produse + rezumat)</span></div>
                <div><code><?= htmlspecialchars($checkoutFormCodeToken, ENT_QUOTES) ?></code> <span style="color:#64748b;">→ formular checkout modern (sumar + plată)</span></div>
                <div><code><?= htmlspecialchars($checkoutSuccessOrderInfoCodeToken, ENT_QUOTES) ?></code> <span style="color:#64748b;">→ sumar comandă pentru pagina checkout/succes (număr + status)</span></div>
                <div><code><?= htmlspecialchars($accountSectionCodeToken, ENT_QUOTES) ?></code> <span style="color:#64748b;">→ secțiune cont client (profil, comenzi, adrese, puncte)</span></div>
                <div><code><?= htmlspecialchars($authGoogleButtonCodeToken, ENT_QUOTES) ?></code> <span style="color:#64748b;">→ buton Google Login/Register (manual în builder)</span></div>
                <div><code><?= htmlspecialchars($productReviewFormCodeToken, ENT_QUOTES) ?></code> <span style="color:#64748b;">→ formular recenzii produse (util pentru pagini QR)</span></div>
                <div><code><?= htmlspecialchars($gdprAgreementsFormCodeToken, ENT_QUOTES) ?></code> <span style="color:#64748b;">→ formular acorduri GDPR (semnătură tactilă + salvare backend)</span></div>
                <div><code>{{next_event_image}}</code> <span style="color:#64748b;">→ imaginea principală a următorului eveniment</span></div>
                <div><code>{{next_event_url}}</code> <span style="color:#64748b;">→ link către pagina următorului eveniment</span></div>
                <div><code>{{next_event_title}}</code> <span style="color:#64748b;">→ titlul următorului eveniment</span></div>
                <div><code>{{next_event_excerpt}}</code> <span style="color:#64748b;">→ descrierea succintă a următorului eveniment</span></div>
                <div><code>{{next_event_period}}</code> <span style="color:#64748b;">→ perioada următorului eveniment</span></div>
                <div><code>{{next_event_video}}</code> <span style="color:#64748b;">→ playerul video al următorului eveniment</span></div>
                <div><code>{{category_posts:slug-categorie}}</code> <span style="color:#64748b;">→ slider cu postările dintr-o categorie (ex: <code>{{category_posts:noutati}}</code>)</span></div>
                <div><code>{{category_grid:slug-categorie}}</code> <span style="color:#64748b;">→ grilă cu postările dintr-o categorie; pentru evenimente, viitoarele primele, apoi cele trecute (ex: <code>{{category_grid:evenimente}}</code>)</span></div>
                <div><code>{{category_events:slug-categorie}}</code> <span style="color:#64748b;">→ pagină de evenimente: hero cu următorul eveniment + secțiunile „viitoare" și „trecute" (ex: <code>{{category_events:evenimente}}</code>)</span></div>
            </div>
        </details>

        <div class="editor-grid" id="page-editor-grid" style="margin-top:14px;">
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
                <textarea id="html-code" name="html_content" class="code-editor code-editor-pane" data-code-pane="html" required><?= htmlspecialchars($pageHtml, ENT_NOQUOTES) ?></textarea>
                <textarea id="js-code" name="js_content" class="code-editor code-editor-pane is-hidden" data-code-pane="js"><?= htmlspecialchars($pageJs, ENT_NOQUOTES) ?></textarea>
                <textarea id="css-code" name="css_content" class="code-editor code-editor-pane is-hidden" data-code-pane="css"><?= htmlspecialchars($pageCss, ENT_NOQUOTES) ?></textarea>
            </div>
            <div class="preview-column">
                <h3 style="margin-top:0;">Preview — <span id="preview-mode-label">Desktop</span></h3>
                <div class="preview-shell desktop" id="preview-shell">
                    <iframe id="page-preview" title="Preview pagină"></iframe>
                </div>
            </div>
        </div>

        <article class="panel" style="margin-top:12px;">
            <h3 style="margin-top:0;">SEO de bază pentru această pagină</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Aceste valori suprascriu setările SEO globale doar pentru pagina curentă.
            </p>
            <div class="field" style="max-width:720px;">
                <label for="seo_title">Meta title</label>
                <input id="seo_title" type="text" name="seo_title" value="<?= htmlspecialchars($pageSeoTitle, ENT_QUOTES) ?>" maxlength="120" placeholder="Ex: Bioscem - Titlu pagină">
            </div>
            <div class="field" style="max-width:720px;">
                <label for="seo_description">Meta description</label>
                <textarea id="seo_description" name="seo_description" rows="4" maxlength="320" placeholder="Descriere scurtă care apare în Google..."><?= htmlspecialchars($pageSeoDescription, ENT_QUOTES) ?></textarea>
            </div>
            <div class="field" style="max-width:720px;">
                <label for="seo_canonical_url">Canonical URL (opțional)</label>
                <input id="seo_canonical_url" type="url" name="seo_canonical_url" value="<?= htmlspecialchars($pageSeoCanonicalUrl, ENT_QUOTES) ?>" placeholder="https://domeniu.ro/pagina">
            </div>
            <div class="field" style="max-width:720px;">
                <label for="seo_image_url">Imagine social preview (OG/Twitter)</label>
                <input id="seo_image_url" type="text" name="seo_image_url" value="<?= htmlspecialchars($pageSeoImageUrl, ENT_QUOTES) ?>" placeholder="/uploads/gallery/imagine-seo.jpg sau https://...">
            </div>
        </article>
    </form>

    <div style="margin-top:12px;">
        URL public: <strong id="page-url-preview"><?= $pageSlug !== '' ? ('/' . htmlspecialchars($pageSlug, ENT_QUOTES)) : '/' ?></strong>
    </div>
</section>

<script>
(() => {
    const htmlCode = document.getElementById('html-code');
    const jsCode = document.getElementById('js-code');
    const cssCode = document.getElementById('css-code');
    const codeTypeTabs = document.querySelectorAll('.code-type-tab');
    const codePanes = document.querySelectorAll('.code-editor-pane');
    const iframe = document.getElementById('page-preview');
    const switches = document.querySelectorAll('.device-switch');
    const previewShell = document.getElementById('preview-shell');
    const editorGrid = document.getElementById('page-editor-grid');
    const codeColumn = document.getElementById('code-column');
    const toggleCodePane = document.getElementById('toggle-code-pane');
    const codeSearchBtn = document.getElementById('code-search-btn');
    const codeBeautifyBtn = document.getElementById('code-beautify-btn');
    const codeFullscreenBtn = document.getElementById('code-fullscreen-btn');
    const slugInput = document.getElementById('page-slug');
    const titleInput = document.getElementById('page-title');
    const pageUrlPreview = document.getElementById('page-url-preview');
    const previewModeLabel = document.getElementById('preview-mode-label');
    const mannequinCodeToken = <?= json_encode((string) ($mannequinCodeToken ?? '{{mannequin_section}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const mannequinSectionHtml = <?= json_encode($mannequinPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const shopCatalogCodeToken = <?= json_encode((string) ($shopCatalogCodeToken ?? '{{shop_catalog}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const shopCatalogSectionHtml = <?= json_encode($shopCatalogPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const blogPostsCodeToken = <?= json_encode((string) ($blogPostsCodeToken ?? '{{blog_posts}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const blogPostsSectionHtml = <?= json_encode($blogPostsPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const cartFormCodeToken = <?= json_encode((string) ($cartFormCodeToken ?? '{{cart_form}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const cartFormSectionHtml = <?= json_encode($cartFormPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const checkoutFormCodeToken = <?= json_encode((string) ($checkoutFormCodeToken ?? '{{checkout_form}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const checkoutFormSectionHtml = <?= json_encode($checkoutFormPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const checkoutSuccessOrderInfoCodeToken = <?= json_encode((string) ($checkoutSuccessOrderInfoCodeToken ?? '{{checkout_success_order_info}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const checkoutSuccessOrderInfoPreviewHtml = <?= json_encode($checkoutSuccessOrderInfoPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const accountSectionCodeToken = <?= json_encode((string) ($accountSectionCodeToken ?? '{{account_section}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const accountSectionPreviewHtml = <?= json_encode($accountSectionPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const authGoogleButtonCodeToken = <?= json_encode((string) ($authGoogleButtonCodeToken ?? '{{auth_google_button}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const authGoogleButtonPreviewHtml = <?= json_encode($authGoogleButtonPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const productReviewFormCodeToken = <?= json_encode((string) ($productReviewFormCodeToken ?? '{{product_review_form}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const productReviewFormPreviewHtml = <?= json_encode($productReviewFormPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const gdprAgreementsFormCodeToken = <?= json_encode((string) ($gdprAgreementsFormCodeToken ?? '{{gdpr_agreements_form}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const gdprAgreementsFormPreviewHtml = <?= json_encode($gdprAgreementsFormPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const previewAppCssHref = <?= json_encode($previewAppCssHref, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const previewAppCssContent = <?= json_encode($previewAppCssContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let slugTouched = slugInput.value.trim() !== '';
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
                getSelection: () => {
                    const start = textarea.selectionStart ?? 0;
                    const end = textarea.selectionEnd ?? start;
                    return textarea.value.slice(start, end);
                },
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
            getSelection: () => cm.getSelection(),
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

    const applyPreviewTokens = (rawHtml) => {
        let html = String(rawHtml || '');
        if (mannequinCodeToken !== '') {
            html = html.split(mannequinCodeToken).join(mannequinSectionHtml);
        }
        if (shopCatalogCodeToken !== '') {
            html = html.split(shopCatalogCodeToken).join(shopCatalogSectionHtml);
        }
        if (blogPostsCodeToken !== '') {
            html = html.split(blogPostsCodeToken).join(blogPostsSectionHtml);
        }
        if (cartFormCodeToken !== '') {
            html = html.split(cartFormCodeToken).join(cartFormSectionHtml);
        }
        if (checkoutFormCodeToken !== '') {
            html = html.split(checkoutFormCodeToken).join(checkoutFormSectionHtml);
        }
        if (checkoutSuccessOrderInfoCodeToken !== '') {
            html = html.split(checkoutSuccessOrderInfoCodeToken).join(checkoutSuccessOrderInfoPreviewHtml);
        }
        if (accountSectionCodeToken !== '') {
            html = html.split(accountSectionCodeToken).join(accountSectionPreviewHtml);
        }
        if (authGoogleButtonCodeToken !== '') {
            html = html.split(authGoogleButtonCodeToken).join(authGoogleButtonPreviewHtml);
        }
        if (productReviewFormCodeToken !== '') {
            html = html.split(productReviewFormCodeToken).join(productReviewFormPreviewHtml);
        }
        if (gdprAgreementsFormCodeToken !== '') {
            html = html.split(gdprAgreementsFormCodeToken).join(gdprAgreementsFormPreviewHtml);
        }
        return html;
    };

    const renderPreview = () => {
        const bodyRaw = getEditorValue('html') || '<p>Scrie codul paginii pentru preview...</p>';
        const body = applyPreviewTokens(bodyRaw);
        const css = getEditorValue('css') || '';
        const js = (getEditorValue('js') || '').replace(/<\/script>/gi, '<\\/script>');
        iframe.srcdoc = '<!doctype html>'
            + '<html lang="ro"><head>'
            + '<meta charset="utf-8" />'
            + '<meta name="viewport" content="width=device-width, initial-scale=1" />'
            + '<link rel="stylesheet" href="' + previewAppCssHref + '">'
            + '<style>' + previewAppCssContent + '</style>'
            + '<style>body{margin:0;padding:24px;line-height:1.6;background:#fff;}img{max-width:100%;height:auto;}</style>'
            + '<style>' + css + '</style>'
            + '</head><body>' + body + '<script>' + js + '<\/script></body></html>';
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

    switches.forEach((button) => {
        button.addEventListener('click', () => {
            switches.forEach((item) => item.classList.remove('active'));
            button.classList.add('active');
            previewShell.classList.remove('desktop', 'tablet', 'mobile');
            previewShell.classList.add(button.dataset.device);
            const labelMap = { desktop: 'Desktop', tablet: 'Tabletă', mobile: 'Telefon' };
            previewModeLabel.textContent = labelMap[button.dataset.device] || 'Desktop';
        });
    });

    toggleCodePane.addEventListener('click', () => {
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

    slugInput.addEventListener('input', () => {
        slugTouched = true;
        const safe = slugInput.value.trim();
        pageUrlPreview.textContent = safe !== '' ? '/' + safe : '/';
    });

    const slugify = (value) => value
        .toLowerCase()
        .replace(/[^a-z0-9\\s-]/g, '')
        .trim()
        .replace(/[\\s-]+/g, '-');

    titleInput.addEventListener('input', () => {
        if (!slugTouched) {
            const generated = slugify(titleInput.value);
            slugInput.value = generated;
            pageUrlPreview.textContent = generated !== '' ? '/' + generated : '/';
        }
    });

    const encodeBase64Utf8 = (value) => {
        try {
            if (typeof TextEncoder !== 'undefined') {
                const bytes = new TextEncoder().encode(String(value ?? ''));
                let binary = '';
                bytes.forEach((byte) => {
                    binary += String.fromCharCode(byte);
                });
                return btoa(binary);
            }
        } catch (_) {
            // Fallback below.
        }
        return btoa(unescape(encodeURIComponent(String(value ?? ''))));
    };

    const addHidden = (form, name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    document.getElementById('page-editor-form')?.addEventListener('submit', (event) => {
        const form = event.currentTarget;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        Object.values(editors).forEach((editor) => editor?.save?.());

        addHidden(form, 'html_content_b64', encodeBase64Utf8(getEditorValue('html')));
        addHidden(form, 'css_content_b64', encodeBase64Utf8(getEditorValue('css')));
        addHidden(form, 'js_content_b64', encodeBase64Utf8(getEditorValue('js')));

        // Avoid sending raw HTML/CSS/JS fields that can trigger strict WAF rules.
        [htmlCode, cssCode, jsCode].forEach((textarea) => {
            if (textarea instanceof HTMLTextAreaElement) {
                textarea.disabled = true;
            }
        });
    });
})();
</script>
