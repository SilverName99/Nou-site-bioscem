<?php
$section = (string) $section;
$tabs = [
    'header' => 'Header',
    'footer' => 'Footer',
    'menu' => 'Meniu',
];
$defaultSnippets = [
    'header' => '<header style="background:#2a9d8f;padding:16px 32px;display:flex;align-items:center;justify-content:space-between;">
  <a href="/" style="color:white;font-size:1.4rem;font-weight:700;text-decoration:none;">Logo</a>
  <nav style="display:flex;gap:24px;">
    <a href="/" style="color:white;text-decoration:none;">Acasă</a>
    <a href="/despre-noi" style="color:white;text-decoration:none;">Despre</a>
    <a href="/contact" style="color:white;text-decoration:none;">Contact</a>
  </nav>
</header>',
    'footer' => '<footer style="background:#0f172a;color:#cbd5e1;padding:24px 32px;">
  <p style="margin:0;">&copy; ' . date('Y') . ' Bioscem. Toate drepturile rezervate.</p>
</footer>',
    'menu' => '<a href="/" style="color:white;text-decoration:none;">Acasă</a>
<a href="/magazin" style="color:white;text-decoration:none;">Magazin</a>
<a href="/contact" style="color:white;text-decoration:none;">Contact</a>',
];
$designHeaderHtml = (string) ($settings['design_header_html'] ?? '');
$designHeaderCss = (string) ($settings['design_header_css'] ?? '');
$designHeaderJs = (string) ($settings['design_header_js'] ?? '');
$designFooterHtml = (string) ($settings['design_footer_html'] ?? '');
$designFooterCss = (string) ($settings['design_footer_css'] ?? '');
$designFooterJs = (string) ($settings['design_footer_js'] ?? '');
$designMenuHtml = (string) ($settings['design_menu_html'] ?? '');
$designMenuCss = (string) ($settings['design_menu_css'] ?? '');
$designMenuJs = (string) ($settings['design_menu_js'] ?? '');
$currentHtml = (string) ($settings['design_' . $section . '_html'] ?? '');
$currentCss = (string) ($settings['design_' . $section . '_css'] ?? '');
$currentJs = (string) ($settings['design_' . $section . '_js'] ?? '');
if (trim($currentHtml) === '') {
    $currentHtml = (string) ($defaultSnippets[$section] ?? '');
}
$availableMenuPages = is_array($availableMenuPages ?? null) ? $availableMenuPages : [];
$menuItems = is_array($menuItems ?? null) ? $menuItems : [];
$menuEmbedToken = '{{menu}}';
$mobileMenuEmbedToken = '{{mobile_menu}}';
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Design Site</h1>
            <p>Editează header-ul, footer-ul și meniul site-ului.</p>
        </div>
    </div>

    <div class="design-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="/admin/design?section=<?= urlencode($key) ?>" class="design-tab <?= $section === $key ? 'active' : '' ?>">
                <?= htmlspecialchars($label, ENT_QUOTES) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($section === 'menu'): ?>
        <form method="post" action="/admin/design/save" class="design-editor-wrap" id="design-site-form">
            <input type="hidden" name="section" value="<?= htmlspecialchars($section, ENT_QUOTES) ?>">
            <textarea id="design-html" name="html_content" style="display:none;"><?= htmlspecialchars($currentHtml, ENT_NOQUOTES) ?></textarea>
            <textarea id="design-js" name="js_content" style="display:none;"><?= htmlspecialchars($currentJs, ENT_NOQUOTES) ?></textarea>
            <textarea id="design-css" name="css_content" style="display:none;"><?= htmlspecialchars($currentCss, ENT_NOQUOTES) ?></textarea>
            <input type="hidden" name="menu_items_json" id="menu-items-json" value="<?= htmlspecialchars((string) json_encode($menuItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">

            <div class="menu-builder-wrap" id="menu-builder-wrap">
                <div class="menu-builder-left">
                    <h4 style="margin:0 0 8px;">Pagini existente</h4>
                    <p style="margin:0 0 10px;color:#64748b;font-size:.9rem;">Adaugă pagini în meniu și setează ierarhia principal / subpagină.</p>
                    <div class="field menu-pages-search">
                        <input type="text" id="menu-page-search" placeholder="Caută pagină după titlu sau URL">
                    </div>
                    <div class="menu-pages-pool">
                        <?php foreach ($availableMenuPages as $page): ?>
                            <?php
                                $pageTitle = trim((string) ($page['title'] ?? 'Pagină'));
                                $pageUrl = trim((string) ($page['url'] ?? '#'));
                                $source = trim((string) ($page['source'] ?? ''));
                            ?>
                            <button
                                type="button"
                                class="menu-page-btn"
                                data-menu-add="1"
                                data-menu-label="<?= htmlspecialchars($pageTitle, ENT_QUOTES) ?>"
                                data-menu-url="<?= htmlspecialchars($pageUrl, ENT_QUOTES) ?>"
                            >
                                <span><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></span>
                                <small><?= htmlspecialchars($pageUrl, ENT_QUOTES) ?><?= $source !== '' ? ' · ' . htmlspecialchars($source, ENT_QUOTES) : '' ?></small>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary" id="menu-add-custom">+ Link personalizat</button>
                </div>

                <div class="menu-builder-right">
                    <div class="menu-structure-head">
                        <h4 style="margin:0;">Structură meniu</h4>
                        <div class="menu-structure-tools">
                            <button type="button" class="btn btn-secondary" id="menu-normalize">Normalizează</button>
                            <button type="button" class="btn btn-secondary" id="menu-clear">Golește</button>
                        </div>
                    </div>
                    <div class="menu-items-list" id="menu-items-list"></div>
                    <div class="menu-live-preview">
                        <h5>Preview meniu</h5>
                        <div id="menu-live-preview"></div>
                    </div>
                </div>
            </div>

            <div class="menu-embed-box">
                <label>Embed meniu pentru Header</label>
                <div class="row">
                    <input type="text" id="menu-embed-token" readonly value="<?= htmlspecialchars($menuEmbedToken, ENT_QUOTES) ?>">
                    <button type="button" class="btn btn-secondary" id="copy-menu-embed">Copiază embed</button>
                </div>
                <p>În secțiunea Header, adaugă token-ul în locul unde vrei să apară meniul.</p>
            </div>

            <div class="design-editor-head" style="margin-top:10px;">
                <h3>Meniu</h3>
                <button class="btn" type="submit">Salvează meniul</button>
            </div>
        </form>
    <?php else: ?>
        <form method="post" action="/admin/design/save" class="design-editor-wrap" id="design-site-form">
            <input type="hidden" name="section" value="<?= htmlspecialchars($section, ENT_QUOTES) ?>">
            <div class="design-editor-head">
                <h3><?= htmlspecialchars($tabs[$section], ENT_QUOTES) ?></h3>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="btn btn-secondary code-toggle-arrow" id="toggle-design-code" title="Ascunde/arată cod">⟵</button>
                    <button type="button" class="btn btn-secondary device-switch active" data-device="desktop" title="Desktop">
                        <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/></svg>
                    </button>
                    <button type="button" class="btn btn-secondary device-switch" data-device="tablet" title="Tabletă">
                        <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="3" width="12" height="18" rx="2"/><circle cx="12" cy="17.5" r="0.8"/></svg>
                    </button>
                    <button type="button" class="btn btn-secondary device-switch" data-device="mobile" title="Telefon">
                        <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="2" width="8" height="20" rx="2"/><circle cx="12" cy="18.5" r="0.8"/></svg>
                    </button>
                    <button class="btn" type="submit">Salvează</button>
                </div>
            </div>

            <?php if ($section === 'header'): ?>
                <details class="panel" style="margin-bottom:12px;background:#f8fafc;border-color:#cbd5e1;">
                    <summary style="cursor:pointer;font-weight:700;color:#334155;">Coduri disponibile pentru Header <span style="color:#64748b;font-weight:400;font-size:.9em;">(click pentru arată/ascunde)</span></summary>
                    <p style="margin:10px 0;color:#64748b;">
                        Poți folosi token-urile de mai jos în HTML-ul de header:
                    </p>
                    <div style="display:grid;gap:8px;">
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <code><?= htmlspecialchars($menuEmbedToken, ENT_QUOTES) ?></code>
                            <span style="color:#64748b;">→ meniul desktop (din secțiunea Meniu)</span>
                            <button type="button" class="btn btn-secondary" id="insert-menu-token">Inserează în HTML</button>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <code><?= htmlspecialchars($mobileMenuEmbedToken, ENT_QUOTES) ?></code>
                            <span style="color:#64748b;">→ buton hamburger + sertar mobil</span>
                            <button type="button" class="btn btn-secondary" id="insert-mobile-menu-token">Inserează în HTML</button>
                        </div>
                    </div>
                </details>
            <?php endif; ?>

            <div class="editor-grid" id="design-editor-grid">
                <div class="code-column" id="design-code-column">
                    <div class="code-type-tabs">
                        <button type="button" class="code-type-tab active" data-code-type="html">HTML</button>
                        <button type="button" class="code-type-tab" data-code-type="js">Java Script</button>
                        <button type="button" class="code-type-tab" data-code-type="css">CSS</button>
                    </div>
                    <div class="code-editor-toolbar">
                        <div class="code-editor-toolbar-right">
                            <button type="button" class="btn btn-secondary" id="design-code-search-btn">Search/Replace</button>
                            <button type="button" class="btn btn-secondary" id="design-code-beautify-btn">Beautify</button>
                            <button type="button" class="btn btn-secondary" id="design-code-fullscreen-btn">Fullscreen</button>
                        </div>
                    </div>
                    <textarea class="code-editor code-editor-pane" id="design-html" data-code-pane="html" name="html_content"><?= htmlspecialchars($currentHtml, ENT_NOQUOTES) ?></textarea>
                    <textarea class="code-editor code-editor-pane is-hidden" id="design-js" data-code-pane="js" name="js_content"><?= htmlspecialchars($currentJs, ENT_NOQUOTES) ?></textarea>
                    <textarea class="code-editor code-editor-pane is-hidden" id="design-css" data-code-pane="css" name="css_content"><?= htmlspecialchars($currentCss, ENT_NOQUOTES) ?></textarea>
                </div>
                <div class="preview-column">
                    <h3 style="margin-top:0;">Preview — <span id="design-preview-label">Desktop</span></h3>
                    <div class="preview-shell desktop" id="design-preview-shell">
                        <iframe id="design-preview" title="Preview design"></iframe>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</section>

<script>
(() => {
    const htmlInput = document.getElementById('design-html');
    const jsInput = document.getElementById('design-js');
    const cssInput = document.getElementById('design-css');
    const codeTypeTabs = document.querySelectorAll('.code-type-tab');
    const codePanes = document.querySelectorAll('.code-editor-pane');
    const preview = document.getElementById('design-preview');
    const shell = document.getElementById('design-preview-shell');
    const editorGrid = document.getElementById('design-editor-grid');
    const codeColumn = document.getElementById('design-code-column');
    const toggleCode = document.getElementById('toggle-design-code');
    const switches = document.querySelectorAll('.device-switch');
    const label = document.getElementById('design-preview-label');
    const form = document.getElementById('design-site-form');
    const section = <?= json_encode($section) ?>;
    const menuToken = <?= json_encode($menuEmbedToken) ?>;
    const mobileMenuToken = <?= json_encode($mobileMenuEmbedToken) ?>;
    const menuEnabled = section === 'menu';
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
                insertAtCursor: (token) => {
                    const start = textarea.selectionStart ?? textarea.value.length;
                    const end = textarea.selectionEnd ?? textarea.value.length;
                    textarea.value = textarea.value.slice(0, start) + token + textarea.value.slice(end);
                    textarea.focus();
                    const pos = start + token.length;
                    textarea.setSelectionRange(pos, pos);
                },
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
            insertAtCursor: (token) => cm.replaceSelection(token),
            type,
        };
    };

    if (!menuEnabled) {
        editors.html = createEditor(htmlInput, 'text/html', 'html');
        editors.js = createEditor(jsInput, 'javascript', 'js');
        editors.css = createEditor(cssInput, 'css', 'css');
    } else {
        editors.html = {
            getValue: () => htmlInput?.value ?? '',
            setValue: (value) => { if (htmlInput) htmlInput.value = String(value ?? ''); },
            save: () => {},
            setVisible: () => {},
            refresh: () => {},
            onChange: () => {},
            insertAtCursor: () => {},
        };
        editors.js = {
            getValue: () => jsInput?.value ?? '',
            setValue: (value) => { if (jsInput) jsInput.value = String(value ?? ''); },
            save: () => {},
            setVisible: () => {},
            refresh: () => {},
            onChange: () => {},
            insertAtCursor: () => {},
        };
        editors.css = {
            getValue: () => cssInput?.value ?? '',
            setValue: (value) => { if (cssInput) cssInput.value = String(value ?? ''); },
            save: () => {},
            setVisible: () => {},
            refresh: () => {},
            onChange: () => {},
            insertAtCursor: () => {},
        };
    }

    const getEditorValue = (type) => editors[type]?.getValue?.() ?? '';
    const setActiveCodeType = (type) => {
        codeTypeTabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.codeType === type));
        codePanes.forEach((pane) => {
            const paneType = pane.dataset.codePane;
            const visible = paneType === type;
            pane.classList.toggle('is-hidden', !visible);
            editors[paneType]?.setVisible?.(visible);
        });
    };

    const menuItemsInput = document.getElementById('menu-items-json');
    const menuItemsList = document.getElementById('menu-items-list');
    const menuAddButtons = document.querySelectorAll('[data-menu-add="1"]');
    const menuAddCustom = document.getElementById('menu-add-custom');
    const menuPageSearch = document.getElementById('menu-page-search');
    const menuNormalizeBtn = document.getElementById('menu-normalize');
    const menuClearBtn = document.getElementById('menu-clear');
    const menuLivePreview = document.getElementById('menu-live-preview');
    const copyMenuEmbed = document.getElementById('copy-menu-embed');
    const menuEmbedInput = document.getElementById('menu-embed-token');
    const insertMenuToken = document.getElementById('insert-menu-token');
    const insertMobileMenuToken = document.getElementById('insert-mobile-menu-token');
    const designCodeSearchBtn = document.getElementById('design-code-search-btn');
    const designCodeBeautifyBtn = document.getElementById('design-code-beautify-btn');
    const designCodeFullscreenBtn = document.getElementById('design-code-fullscreen-btn');
    let menuItems = <?= json_encode($menuItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const getActiveCodeType = () => document.querySelector('.code-type-tab.active')?.dataset.codeType || 'html';
    const getActiveEditor = () => editors[getActiveCodeType()] || null;
    const beautifyContent = (editor) => {
        if (!editor) return false;
        const value = editor.getValue?.() ?? '';
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
            editor.setValue?.(formatted);
            return true;
        } catch {
            return false;
        }
    };

    const savedSections = {
        header: {
            html: <?= json_encode($designHeaderHtml) ?>,
            css: <?= json_encode($designHeaderCss) ?>,
            js: <?= json_encode($designHeaderJs) ?>,
        },
        footer: {
            html: <?= json_encode($designFooterHtml) ?>,
            css: <?= json_encode($designFooterCss) ?>,
            js: <?= json_encode($designFooterJs) ?>,
        },
        menu: {
            html: <?= json_encode($designMenuHtml) ?>,
            css: <?= json_encode($designMenuCss) ?>,
            js: <?= json_encode($designMenuJs) ?>,
        },
    };

    const fallbackHeaderWithMenu = (menuHtml) => `<header style="background:#2a9d8f;padding:16px 32px;display:flex;align-items:center;justify-content:space-between;">
  <a href="#" style="color:white;font-size:1.4rem;font-weight:700;text-decoration:none;">Logo</a>
  <nav style="display:flex;gap:24px;">${menuHtml}</nav>
</header>`;

    const esc = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

    const normalizeMenuItems = (items) => {
        if (!Array.isArray(items)) return [];
        const safe = items
            .map((item) => ({
                label: String(item?.label ?? '').trim(),
                url: String(item?.url ?? '').trim(),
                level: Math.max(0, Math.min(1, Number(item?.level ?? 0) || 0)),
            }))
            .filter((item) => item.label !== '' && item.url !== '');
        return safe.map((item, index) => {
            if (item.level === 1) {
                const prev = safe[index - 1];
                if (!prev || prev.level !== 0) {
                    return { ...item, level: 0 };
                }
            }
            return item;
        });
    };

    const menuItemsToHtml = (items) => {
        const safe = normalizeMenuItems(items);
        if (!safe.length) return '';

        let html = '<ul class="menu-root">';
        let subOpen = false;
        safe.forEach((item, idx) => {
            const nextLevel = idx < safe.length - 1 ? safe[idx + 1].level : 0;
            const label = esc(item.label);
            const url = esc(item.url);

            if (item.level === 0) {
                if (subOpen) {
                    html += '</ul></li>';
                    subOpen = false;
                }
                html += `<li><a href="${url}">${label}</a>`;
                if (nextLevel === 1) {
                    html += '<ul class="submenu">';
                    subOpen = true;
                } else {
                    html += '</li>';
                }
                return;
            }
            if (!subOpen) return;
            html += `<li><a href="${url}">${label}</a></li>`;
            if (nextLevel === 0) {
                html += '</ul></li>';
                subOpen = false;
            }
        });
        if (subOpen) html += '</ul></li>';
        html += '</ul>';
        return html;
    };

    const syncMenuBuilder = () => {
        if (!menuEnabled || !menuItemsInput || !htmlInput) return;
        const safe = normalizeMenuItems(menuItems);
        menuItems = safe;
        menuItemsInput.value = JSON.stringify(safe);
        const html = menuItemsToHtml(safe);
        htmlInput.value = html;
        editors.html?.setValue?.(html);
    };

    const renderMenuLivePreview = () => {
        if (!menuLivePreview) return;
        const safe = normalizeMenuItems(menuItems);
        if (!safe.length) {
            menuLivePreview.innerHTML = '<p class="menu-empty">Preview indisponibil: adaugă cel puțin un item.</p>';
            return;
        }
        menuLivePreview.innerHTML = `<nav class="menu-live-preview-nav">${menuItemsToHtml(safe)}</nav>`;
    };

    const renderMenuBuilder = () => {
        if (!menuEnabled || !menuItemsList) return;
        const safe = normalizeMenuItems(menuItems);
        menuItems = safe;
        const filterTerm = (menuPageSearch?.value || '').trim().toLowerCase();
        const entries = safe
            .map((item, index) => ({ item, index }))
            .filter(({ item }) => {
                if (filterTerm === '') return true;
                return item.label.toLowerCase().includes(filterTerm) || item.url.toLowerCase().includes(filterTerm);
            });

        if (!safe.length) {
            menuItemsList.innerHTML = '<p class="menu-empty">Nu există elemente în meniu. Adaugă pagini din stânga.</p>';
            syncMenuBuilder();
            renderMenuLivePreview();
            return;
        }
        if (entries.length === 0) {
            menuItemsList.innerHTML = '<p class="menu-empty">Nu există rezultate pentru filtrul curent.</p>';
            syncMenuBuilder();
            renderMenuLivePreview();
            return;
        }

        menuItemsList.innerHTML = entries.map(({ item, index }) => `
            <div class="menu-item-row ${item.level === 1 ? 'is-sub' : ''}" data-index="${index}">
                <div class="menu-item-main">
                    <input class="menu-item-input" data-field="label" data-index="${index}" value="${esc(item.label)}" placeholder="Titlu meniu">
                    <input class="menu-item-input" data-field="url" data-index="${index}" value="${esc(item.url)}" placeholder="/pagina">
                    <select class="menu-item-level" data-field="level" data-index="${index}">
                        <option value="0" ${item.level === 0 ? 'selected' : ''}>Pagină principală</option>
                        <option value="1" ${item.level === 1 ? 'selected' : ''}>Subpagină</option>
                    </select>
                </div>
                <div class="menu-item-actions">
                    <button type="button" class="icon-btn menu-drag-handle" draggable="true" data-drag-index="${index}" title="Trage pentru mutare">⠿</button>
                    <button type="button" class="icon-btn" data-action="up" data-index="${index}" title="Mută sus">↑</button>
                    <button type="button" class="icon-btn" data-action="down" data-index="${index}" title="Mută jos">↓</button>
                    <button type="button" class="icon-btn" data-action="remove" data-index="${index}" title="Elimină">🗑</button>
                </div>
            </div>
        `).join('');

        syncMenuBuilder();
        renderMenuLivePreview();
    };

    const buildSections = () => {
        const sections = JSON.parse(JSON.stringify(savedSections));
        sections[section].html = htmlInput ? (htmlInput.value || '') : '';
        sections[section].css = cssInput ? (cssInput.value || '') : '';
        sections[section].js = jsInput ? (jsInput.value || '') : '';
        return sections;
    };

    const renderPreview = () => {
        if (!preview || !shell || !label) return;
        const sections = buildSections();
        let headerHtml = (sections.header.html || '').trim();
        let footerHtml = (sections.footer.html || '').trim();
        const menuHtml = (sections.menu.html || '').trim();
        if (headerHtml !== '' && menuHtml !== '') {
            headerHtml = headerHtml.replace(/\{\{\s*menu\s*\}\}/gi, menuHtml);
        }
        if (headerHtml !== '') {
            const mobileMenuPreview = `
<div class="bv-mobile-menu-token-preview" aria-hidden="true">
  <button type="button" class="bv-mobile-menu-token-preview__toggle"><span></span><span></span><span></span></button>
</div>`;
            headerHtml = headerHtml.replace(/\{\{\s*mobile_menu\s*\}\}/gi, mobileMenuPreview);
        }

        if (headerHtml === '') {
            headerHtml = fallbackHeaderWithMenu(menuHtml);
        }

        if (footerHtml === '') {
            footerHtml = `<footer style="background:#0f172a;color:#cbd5e1;padding:24px 32px;margin-top:40px;">
  <p style="margin:0;">Footer preview</p>
</footer>`;
        }

        const joinedCss = [sections.header.css, sections.menu.css, sections.footer.css]
            .filter((part) => String(part || '').trim() !== '')
            .join('\n');
        const joinedJs = [sections.header.js, sections.menu.js, sections.footer.js]
            .filter((part) => String(part || '').trim() !== '')
            .join('\n')
            .replace(/<\/script>/gi, '<\\/script>');

        preview.srcdoc = `<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{margin:0;font-family:Arial,sans-serif;background:#fff;color:#0f172a}
.body{padding:24px}
img{max-width:100%}
.menu-root{list-style:none;margin:0;padding:0;display:flex;gap:20px;align-items:center}
.menu-root>li{position:relative}
.menu-root>li>a{text-decoration:none;color:#fff;font-size:14px;font-weight:600}
.menu-root .submenu{display:none;list-style:none;margin:0;padding:10px 12px;position:absolute;left:0;top:calc(100% - 1px);background:#fff;border-radius:10px;min-width:200px;border:1px solid #e5e7eb;box-shadow:0 10px 22px rgba(15,23,42,.12);gap:8px;z-index:10}
.menu-root .submenu::before{content:"";position:absolute;left:0;right:0;top:-10px;height:10px}
.menu-root>li:hover>.submenu,.menu-root>li:focus-within>.submenu{display:grid}
.menu-root .submenu a{text-decoration:none;color:#0f172a;font-size:13px}
.bv-mobile-menu-token-preview{display:none}
@media (max-width:980px){
  .bv-mobile-menu-token-preview{display:inline-flex;align-items:center}
  .bv-mobile-menu-token-preview__toggle{
    width:38px;height:38px;border-radius:10px;border:1px solid #d6dde0;background:#fff;
    display:inline-flex;flex-direction:column;justify-content:center;align-items:center;gap:4px;
  }
  .bv-mobile-menu-token-preview__toggle span{
    width:18px;height:2px;border-radius:2px;background:#2f4a3c;display:block;
  }
}
</style>
<style>${joinedCss}</style>
</head>
<body>
${headerHtml}
<div class="body"><h2>Conținut pagină</h2><p>Acesta este doar preview-ul pentru secțiunea ${section}.</p></div>
${footerHtml}
<script>${joinedJs}<\/script>
</body>
</html>`;
    };

    if (!menuEnabled) {
        editors.html?.onChange?.(renderPreview);
        editors.js?.onChange?.(renderPreview);
        editors.css?.onChange?.(renderPreview);
    }

    codeTypeTabs.forEach((button) => {
        button.addEventListener('click', () => {
            const type = button.dataset.codeType || 'html';
            setActiveCodeType(type);
        });
    });

    switches.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!shell || !label) return;
            switches.forEach((s) => s.classList.remove('active'));
            btn.classList.add('active');
            shell.classList.remove('desktop', 'tablet', 'mobile');
            shell.classList.add(btn.dataset.device);
            const map = { desktop: 'Desktop', tablet: 'Tabletă', mobile: 'Telefon' };
            label.textContent = map[btn.dataset.device] || 'Desktop';
        });
    });

    toggleCode?.addEventListener('click', () => {
        if (!codeColumn || !editorGrid) return;
        const nowHidden = !codeColumn.classList.contains('is-hidden');
        codeColumn.classList.toggle('is-hidden', nowHidden);
        editorGrid.classList.toggle('code-hidden', nowHidden);
        toggleCode.textContent = nowHidden ? '⟶' : '⟵';
        if (!nowHidden) {
            const active = document.querySelector('.code-type-tab.active')?.dataset.codeType || 'html';
            editors[active]?.refresh?.();
        }
    });
    designCodeSearchBtn?.addEventListener('click', () => {
        if (menuEnabled) return;
        const editor = getActiveEditor();
        if (editor?.isSearchOpen?.()) {
            editor?.closeSearch?.();
            return;
        }
        editor?.openSearch?.();
        editor?.focus?.();
    });
    designCodeBeautifyBtn?.addEventListener('click', () => {
        if (menuEnabled) return;
        const editor = getActiveEditor();
        const ok = beautifyContent(editor);
        if (ok) {
            renderPreview();
            designCodeBeautifyBtn.classList.add('active');
            window.setTimeout(() => designCodeBeautifyBtn.classList.remove('active'), 700);
        }
    });
    designCodeFullscreenBtn?.addEventListener('click', () => {
        if (!codeColumn || menuEnabled) return;
        codeColumn.classList.toggle('is-fullscreen');
        const active = getActiveCodeType();
        editors[active]?.refresh?.();
        designCodeFullscreenBtn.textContent = codeColumn.classList.contains('is-fullscreen') ? 'Exit fullscreen' : 'Fullscreen';
    });

    insertMenuToken?.addEventListener('click', () => {
        editors.html?.insertAtCursor?.(menuToken);
        editors.html?.save?.();
        renderPreview();
    });
    insertMobileMenuToken?.addEventListener('click', () => {
        editors.html?.insertAtCursor?.(mobileMenuToken);
        editors.html?.save?.();
        renderPreview();
    });

    copyMenuEmbed?.addEventListener('click', async () => {
        const token = menuEmbedInput?.value || menuToken;
        try {
            await navigator.clipboard.writeText(token);
            copyMenuEmbed.textContent = 'Copiat';
            setTimeout(() => { copyMenuEmbed.textContent = 'Copiază embed'; }, 1200);
        } catch {
            if (menuEmbedInput) {
                menuEmbedInput.select();
            }
        }
    });

    if (menuEnabled) {
        const dragState = {
            fromIndex: -1,
            toIndex: -1,
            position: 'after',
            level: 0,
        };
        const clearDropDecor = () => {
            menuItemsList?.querySelectorAll('.menu-item-row').forEach((row) => {
                row.classList.remove('drop-before', 'drop-after', 'drop-indent', 'is-dragging');
            });
            menuItemsList?.classList.remove('is-dragging');
        };
        const desiredLevelFromPointer = (clientX) => {
            if (!menuItemsList) return 0;
            const left = menuItemsList.getBoundingClientRect().left;
            return (clientX - left) > 90 ? 1 : 0;
        };
        const applyDragMove = () => {
            const fromIndex = dragState.fromIndex;
            if (fromIndex < 0 || fromIndex >= menuItems.length) {
                return;
            }
            const source = { ...menuItems[fromIndex] };
            const next = menuItems.filter((_, idx) => idx !== fromIndex);
            let insertIndex = dragState.toIndex;
            if (insertIndex < 0) {
                insertIndex = next.length;
            } else if (dragState.position === 'after') {
                insertIndex += 1;
            }
            if (fromIndex < insertIndex) {
                insertIndex -= 1;
            }
            insertIndex = Math.max(0, Math.min(next.length, insertIndex));
            source.level = dragState.level;
            next.splice(insertIndex, 0, source);
            menuItems = normalizeMenuItems(next);
            renderMenuBuilder();
            renderPreview();
        };

        menuAddButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const label = String(button.dataset.menuLabel || '').trim();
                const url = String(button.dataset.menuUrl || '').trim();
                if (!label || !url) return;
                menuItems.push({ label, url, level: 0 });
                renderMenuBuilder();
            });
        });

        menuAddCustom?.addEventListener('click', () => {
            menuItems.push({ label: 'Pagină nouă', url: '/pagina-noua', level: 0 });
            renderMenuBuilder();
        });

        menuNormalizeBtn?.addEventListener('click', () => {
            menuItems = normalizeMenuItems(menuItems);
            renderMenuBuilder();
        });
        menuClearBtn?.addEventListener('click', () => {
            if (!window.confirm('Golești toată structura meniului?')) return;
            menuItems = [];
            renderMenuBuilder();
        });
        menuPageSearch?.addEventListener('input', () => {
            renderMenuBuilder();
        });

        menuItemsList?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-action]');
            if (!button) return;
            const action = String(button.dataset.action || '');
            const index = Number(button.dataset.index || -1);
            if (!Number.isInteger(index) || index < 0 || index >= menuItems.length) return;

            if (action === 'remove') {
                menuItems.splice(index, 1);
            } else if (action === 'up' && index > 0) {
                const tmp = menuItems[index - 1];
                menuItems[index - 1] = menuItems[index];
                menuItems[index] = tmp;
            } else if (action === 'down' && index < menuItems.length - 1) {
                const tmp = menuItems[index + 1];
                menuItems[index + 1] = menuItems[index];
                menuItems[index] = tmp;
            }

            renderMenuBuilder();
        });

        menuItemsList?.addEventListener('input', (event) => {
            const input = event.target.closest('[data-field]');
            if (!input) return;
            const index = Number(input.dataset.index || -1);
            const field = String(input.dataset.field || '');
            if (!Number.isInteger(index) || index < 0 || index >= menuItems.length) return;
            if (!['label', 'url', 'level'].includes(field)) return;

            if (field === 'level') {
                menuItems[index].level = Math.max(0, Math.min(1, Number(input.value) || 0));
            } else {
                menuItems[index][field] = input.value;
            }

            syncMenuBuilder();
            renderMenuLivePreview();
        });

        menuItemsList?.addEventListener('dragstart', (event) => {
            const handle = event.target.closest('.menu-drag-handle');
            if (!handle) {
                event.preventDefault();
                return;
            }
            const fromIndex = Number(handle.dataset.dragIndex || -1);
            if (!Number.isInteger(fromIndex) || fromIndex < 0 || fromIndex >= menuItems.length) {
                event.preventDefault();
                return;
            }

            dragState.fromIndex = fromIndex;
            dragState.toIndex = fromIndex;
            dragState.position = 'after';
            dragState.level = menuItems[fromIndex]?.level === 1 ? 1 : 0;

            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(fromIndex));
            menuItemsList.classList.add('is-dragging');
            const row = menuItemsList.querySelector(`.menu-item-row[data-index="${fromIndex}"]`);
            row?.classList.add('is-dragging');
        });

        menuItemsList?.addEventListener('dragover', (event) => {
            if (dragState.fromIndex < 0) return;
            event.preventDefault();

            clearDropDecor();
            menuItemsList.classList.add('is-dragging');
            const row = event.target.closest('.menu-item-row');
            if (!row) {
                dragState.toIndex = menuItems.length - 1;
                dragState.position = 'after';
                dragState.level = desiredLevelFromPointer(event.clientX);
                return;
            }

            const toIndex = Number(row.dataset.index || -1);
            if (!Number.isInteger(toIndex) || toIndex < 0) return;
            const rect = row.getBoundingClientRect();
            const position = event.clientY < (rect.top + rect.height / 2) ? 'before' : 'after';
            const level = desiredLevelFromPointer(event.clientX);

            dragState.toIndex = toIndex;
            dragState.position = position;
            dragState.level = level;

            row.classList.add(position === 'before' ? 'drop-before' : 'drop-after');
            if (level === 1) {
                row.classList.add('drop-indent');
            }
            const sourceRow = menuItemsList.querySelector(`.menu-item-row[data-index="${dragState.fromIndex}"]`);
            sourceRow?.classList.add('is-dragging');
        });

        menuItemsList?.addEventListener('drop', (event) => {
            if (dragState.fromIndex < 0) return;
            event.preventDefault();
            applyDragMove();
            dragState.fromIndex = -1;
            dragState.toIndex = -1;
            clearDropDecor();
        });

        menuItemsList?.addEventListener('dragend', () => {
            dragState.fromIndex = -1;
            dragState.toIndex = -1;
            clearDropDecor();
        });

        renderMenuBuilder();
    } else {
        setActiveCodeType('html');
        renderPreview();
    }

    form?.addEventListener('submit', () => {
        if (menuEnabled) {
            syncMenuBuilder();
        } else {
            Object.values(editors).forEach((editor) => editor?.save?.());
        }
    });
})();
</script>
