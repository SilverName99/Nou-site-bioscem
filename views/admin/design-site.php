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
                    <h4 style="margin:0 0 4px;">Pagini existente</h4>
                    <p class="mb2-hint">Click pe o pagină ca s-o adaugi la sfârșitul meniului. O muți și o retragi în submeniu pe urmă, din butoanele de pe rând.</p>
                    <div class="field menu-pages-search">
                        <input type="text" id="menu-page-search" placeholder="Caută pagină după titlu sau URL" autocomplete="off">
                    </div>
                    <div class="menu-pages-pool" id="menu-pages-pool">
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
                                data-menu-search="<?= htmlspecialchars(mb_strtolower($pageTitle . ' ' . $pageUrl . ' ' . $source), ENT_QUOTES) ?>"
                            >
                                <span><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></span>
                                <small><?= htmlspecialchars($pageUrl, ENT_QUOTES) ?><?= $source !== '' ? ' · ' . htmlspecialchars($source, ENT_QUOTES) : '' ?></small>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <p class="menu-empty" id="menu-pages-empty" hidden>Nicio pagină nu se potrivește.</p>
                    <button type="button" class="btn btn-secondary" id="menu-add-custom">+ Link personalizat</button>
                </div>

                <div class="menu-builder-right">
                    <div class="menu-structure-head">
                        <div>
                            <h4 style="margin:0;">Structură meniu</h4>
                            <p class="mb2-count" id="menu-count"></p>
                        </div>
                        <div class="menu-structure-tools">
                            <button type="button" class="btn btn-secondary" id="menu-clear">Golește</button>
                        </div>
                    </div>
                    <div class="menu-items-list" id="menu-items-list"></div>
                    <div class="menu-live-preview">
                        <h5>Preview meniu <span>— treci cu mouse-ul peste o categorie cu submeniu</span></h5>
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
    const menuClearBtn = document.getElementById('menu-clear');
    const menuPagesEmpty = document.getElementById('menu-pages-empty');
    const menuCount = document.getElementById('menu-count');
    const menuLivePreview = document.getElementById('menu-live-preview');
    const copyMenuEmbed = document.getElementById('copy-menu-embed');
    const menuEmbedInput = document.getElementById('menu-embed-token');
    const insertMenuToken = document.getElementById('insert-menu-token');
    const insertMobileMenuToken = document.getElementById('insert-mobile-menu-token');
    const designCodeSearchBtn = document.getElementById('design-code-search-btn');
    const designCodeBeautifyBtn = document.getElementById('design-code-beautify-btn');
    const designCodeFullscreenBtn = document.getElementById('design-code-fullscreen-btn');
    const savedMenuItems = <?= json_encode($menuItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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

    /* Meniul se editeaza ca arbore, dar se salveaza in acelasi format plat
       {label, url, level} pe care il asteapta PHP-ul. Diferenta se vede la
       mutare: un parinte isi ia subpaginile cu el, nu le lasa in urma. */
    let menuTree = (() => {
        const tree = [];
        (Array.isArray(savedMenuItems) ? savedMenuItems : []).forEach((raw) => {
            const label = String(raw?.label ?? '');
            const url = String(raw?.url ?? '');
            const level = Number(raw?.level ?? 0) === 1 ? 1 : 0;
            if (level === 1 && tree.length > 0) {
                tree[tree.length - 1].children.push({ label, url });
                return;
            }
            tree.push({ label, url, children: [] });
        });
        return tree;
    })();

    const isFilled = (node) => String(node?.label ?? '').trim() !== '' && String(node?.url ?? '').trim() !== '';

    /* Randurile incomplete raman pe ecran cat le scrii, dar nu ajung in meniu.
       Daca parintele e incomplet, subpaginile lui urca la nivelul principal,
       ca sa nu dispara fara sa se inteleaga de ce. */
    const effectiveTree = () => {
        const out = [];
        menuTree.forEach((node) => {
            const kids = node.children
                .filter(isFilled)
                .map((child) => ({ label: child.label.trim(), url: child.url.trim() }));
            if (isFilled(node)) {
                out.push({ label: node.label.trim(), url: node.url.trim(), children: kids });
                return;
            }
            kids.forEach((child) => out.push({ ...child, children: [] }));
        });
        return out;
    };

    const flatFromTree = () => {
        const out = [];
        effectiveTree().forEach((node) => {
            out.push({ label: node.label, url: node.url, level: 0 });
            node.children.forEach((child) => out.push({ label: child.label, url: child.url, level: 1 }));
        });
        return out;
    };

    const menuTreeToHtml = () => {
        const roots = effectiveTree();
        if (!roots.length) return '';
        const link = (node) => `<a href="${esc(node.url)}">${esc(node.label)}</a>`;
        return '<ul class="menu-root">' + roots.map((node) => {
            let html = `<li>${link(node)}`;
            if (node.children.length) {
                html += '<ul class="submenu">'
                    + node.children.map((child) => `<li>${link(child)}</li>`).join('')
                    + '</ul>';
            }
            return html + '</li>';
        }).join('') + '</ul>';
    };

    const syncMenuBuilder = () => {
        if (!menuEnabled || !menuItemsInput || !htmlInput) return;
        menuItemsInput.value = JSON.stringify(flatFromTree());
        const html = menuTreeToHtml();
        htmlInput.value = html;
        editors.html?.setValue?.(html);
    };

    const renderMenuLivePreview = () => {
        if (!menuLivePreview) return;
        const html = menuTreeToHtml();
        menuLivePreview.innerHTML = html === ''
            ? '<p class="menu-empty">Adaugă cel puțin o pagină ca să vezi meniul.</p>'
            : `<nav class="menu-live-preview-nav">${html}</nav>`;
    };

    const updateMenuCount = () => {
        if (!menuCount) return;
        const flat = flatFromTree();
        const principale = flat.filter((item) => item.level === 0).length;
        const sub = flat.length - principale;
        menuCount.textContent = `${principale} ${principale === 1 ? 'element principal' : 'elemente principale'}`
            + ` · ${sub} ${sub === 1 ? 'subpagină' : 'subpagini'}`;
    };

    const nodeAtPath = (path) => {
        const parts = String(path).split('.');
        const parent = menuTree[Number(parts[0])];
        if (!parent) return null;
        return parts.length === 1 ? parent : (parent.children[Number(parts[1])] || null);
    };

    const menuToolBtn = (action, path, glyph, title, disabled) =>
        `<button type="button" class="mb2-btn" data-action="${action}" data-path="${path}"`
        + ` title="${esc(title)}" aria-label="${esc(title)}"${disabled ? ' disabled' : ''}>${glyph}</button>`;

    const menuRowHtml = (node, path, kind, first, last) => {
        const tools = kind === 'parent'
            ? menuToolBtn('indent', path, '→', 'Fă-o subpagină a elementului de deasupra', first)
                + menuToolBtn('up', path, '↑', 'Mută mai sus', first)
                + menuToolBtn('down', path, '↓', 'Mută mai jos', last)
                + menuToolBtn('add-child', path, '+', 'Adaugă o subpagină aici', false)
                + menuToolBtn('remove', path, '🗑', 'Șterge', false)
            : menuToolBtn('outdent', path, '←', 'Scoate din submeniu', false)
                + menuToolBtn('up', path, '↑', 'Mută mai sus', first)
                + menuToolBtn('down', path, '↓', 'Mută mai jos', last)
                + menuToolBtn('remove', path, '🗑', 'Șterge', false);

        return `
            <div class="mb2-row mb2-row--${kind}${isFilled(node) ? '' : ' is-incomplete'}" data-path="${path}" data-kind="${kind}">
                <button type="button" class="mb2-grip" data-grip="${path}" title="Trage ca să muți" aria-label="Trage ca să muți">⠿</button>
                <div class="mb2-fields">
                    <input class="mb2-input" data-field="label" data-path="${path}" value="${esc(node.label)}" placeholder="Titlu în meniu">
                    <input class="mb2-input mb2-input--url" data-field="url" data-path="${path}" value="${esc(node.url)}" placeholder="/pagina">
                </div>
                <div class="mb2-tools">${tools}</div>
            </div>`;
    };

    const renderMenuBuilder = () => {
        if (!menuEnabled || !menuItemsList) return;

        if (!menuTree.length) {
            menuItemsList.innerHTML = '<p class="menu-empty">Meniul e gol. Adaugă pagini din stânga.</p>';
        } else {
            menuItemsList.innerHTML = menuTree.map((node, i) => {
                const children = node.children
                    .map((child, j) => menuRowHtml(child, `${i}.${j}`, 'child', j === 0, j === node.children.length - 1))
                    .join('');
                const nume = node.label.trim() || 'fără titlu';
                return `
                    <div class="mb2-node" data-index="${i}">
                        ${menuRowHtml(node, String(i), 'parent', i === 0, i === menuTree.length - 1)}
                        <div class="mb2-children">
                            ${children}
                            <div class="mb2-dropzone" data-dropzone="${i}">Trage aici ca să pui în submeniul „${esc(nume)}"</div>
                        </div>
                    </div>`;
            }).join('');
        }

        updateMenuCount();
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
        const drag = { path: '', target: null, pornit: false, pointerId: null, x0: 0, y0: 0 };

        const clearDropDecor = () => {
            menuItemsList?.querySelectorAll('.drop-before, .drop-after, .is-over').forEach((el) => {
                el.classList.remove('drop-before', 'drop-after', 'is-over');
            });
        };

        const endDrag = () => {
            drag.path = '';
            drag.target = null;
            drag.pornit = false;
            drag.pointerId = null;
            clearDropDecor();
            menuItemsList?.classList.remove('is-dragging');
            menuItemsList?.querySelectorAll('.mb2-row.is-dragging').forEach((row) => {
                row.classList.remove('is-dragging');
            });
        };

        // Dupa o mutare randurile se redeseneaza, deci butonul pe care ai apasat
        // dispare. Il cautam la pozitia noua ca sa poti apasa de mai multe ori.
        // Daca acolo e dezactivat (ai ajuns primul din lista), trecem pe vecinul
        // lui, ca sa nu ramai fara focus in mijlocul unei mutari.
        const focusTool = (action, path) => {
            const row = menuItemsList?.querySelector(`.mb2-row[data-path="${path}"]`);
            if (!row) return;
            const tinta = row.querySelector(`[data-action="${action}"]:not(:disabled)`)
                || row.querySelector('[data-action="up"]:not(:disabled)')
                || row.querySelector('[data-action="down"]:not(:disabled)')
                || row.querySelector('.mb2-grip');
            tinta?.focus();
        };

        const moveWithin = (list, from, to) => {
            if (to < 0 || to >= list.length) return from;
            const [item] = list.splice(from, 1);
            list.splice(to, 0, item);
            return to;
        };

        const addPage = (label, url) => {
            menuTree.push({ label, url, children: [] });
            renderMenuBuilder();
            const index = menuTree.length - 1;
            menuItemsList?.querySelector(`.mb2-row[data-path="${index}"]`)?.scrollIntoView({ block: 'nearest' });
            return index;
        };

        menuAddButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const label = String(button.dataset.menuLabel || '').trim();
                const url = String(button.dataset.menuUrl || '').trim();
                if (label === '' || url === '') return;
                addPage(label, url);
            });
        });

        menuAddCustom?.addEventListener('click', () => {
            const index = addPage('', '');
            menuItemsList?.querySelector(`[data-field="label"][data-path="${index}"]`)?.focus();
        });

        menuClearBtn?.addEventListener('click', () => {
            if (!window.confirm('Golești toată structura meniului?')) return;
            menuTree = [];
            renderMenuBuilder();
        });

        // Cauta in lista de pagini din stanga, nu in structura din dreapta.
        menuPageSearch?.addEventListener('input', () => {
            const term = (menuPageSearch.value || '').trim().toLowerCase();
            let vizibile = 0;
            menuAddButtons.forEach((button) => {
                const hay = String(button.dataset.menuSearch || '').toLowerCase();
                const arata = term === '' || hay.includes(term);
                button.hidden = !arata;
                if (arata) vizibile += 1;
            });
            if (menuPagesEmpty) {
                menuPagesEmpty.hidden = vizibile > 0;
            }
        });

        menuItemsList?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-action]');
            if (!button || button.disabled) return;

            const action = String(button.dataset.action || '');
            const parts = String(button.dataset.path || '').split('.').map(Number);
            const parent = menuTree[parts[0]];
            if (!parent) return;
            const isChild = parts.length > 1;

            let focusPath = String(button.dataset.path || '');
            let focusAction = action;

            if (action === 'up' || action === 'down') {
                const delta = action === 'up' ? -1 : 1;
                if (isChild) {
                    focusPath = `${parts[0]}.${moveWithin(parent.children, parts[1], parts[1] + delta)}`;
                } else {
                    focusPath = String(moveWithin(menuTree, parts[0], parts[0] + delta));
                }
            } else if (action === 'indent' && !isChild && parts[0] > 0) {
                // Nu exista nivelul trei: subpaginile celui retras il urmeaza
                // ca frati, sub acelasi parinte nou.
                const target = menuTree[parts[0] - 1];
                const moved = menuTree.splice(parts[0], 1)[0];
                const at = target.children.length;
                target.children.push({ label: moved.label, url: moved.url });
                moved.children.forEach((child) => target.children.push(child));
                focusPath = `${parts[0] - 1}.${at}`;
                focusAction = 'outdent';
            } else if (action === 'outdent' && isChild) {
                const moved = parent.children.splice(parts[1], 1)[0];
                menuTree.splice(parts[0] + 1, 0, { label: moved.label, url: moved.url, children: [] });
                focusPath = String(parts[0] + 1);
                focusAction = 'indent';
            } else if (action === 'add-child' && !isChild) {
                parent.children.push({ label: '', url: '' });
                renderMenuBuilder();
                menuItemsList.querySelector(`[data-field="label"][data-path="${parts[0]}.${parent.children.length - 1}"]`)?.focus();
                return;
            } else if (action === 'remove') {
                if (isChild) {
                    parent.children.splice(parts[1], 1);
                } else {
                    const cate = parent.children.length;
                    if (cate > 0 && !window.confirm(`Ștergi „${parent.label.trim() || 'fără titlu'}" împreună cu cele ${cate} subpagini?`)) {
                        return;
                    }
                    menuTree.splice(parts[0], 1);
                }
                renderMenuBuilder();
                return;
            } else {
                return;
            }

            renderMenuBuilder();
            focusTool(focusAction, focusPath);
        });

        menuItemsList?.addEventListener('input', (event) => {
            const input = event.target.closest('[data-field]');
            if (!input) return;
            const node = nodeAtPath(input.dataset.path);
            if (!node) return;

            node[input.dataset.field === 'url' ? 'url' : 'label'] = input.value;

            // Randul si eticheta zonei de drop se actualizeaza pe loc, ca sa nu
            // redesenam lista si sa pierzi cursorul din camp.
            input.closest('.mb2-row')?.classList.toggle('is-incomplete', !isFilled(node));
            if (input.dataset.field === 'label' && !String(input.dataset.path).includes('.')) {
                const zone = menuItemsList.querySelector(`[data-dropzone="${input.dataset.path}"]`);
                if (zone) {
                    zone.textContent = `Trage aici ca să pui în submeniul „${node.label.trim() || 'fără titlu'}"`;
                }
            }

            updateMenuCount();
            syncMenuBuilder();
            renderMenuLivePreview();
        });

        /* Mutarea merge pe evenimente de pointer, nu pe drag & drop din HTML5.
           Motivul: in Chrome, `draggable` pe un <button> nu porneste o mutare —
           butonul isi trateaza singur apasarea. In plus, asa merge si la deget
           pe tableta, iar noi controlam exact ce se intampla. */

        const tintaDinPunct = (x, y) => {
            const el = document.elementFromPoint(x, y);
            if (!el || !menuItemsList.contains(el)) return null;

            const zone = el.closest('[data-dropzone]');
            if (zone) {
                const parentIndex = Number(zone.dataset.dropzone);
                zone.classList.add('is-over');
                return {
                    kind: 'sub',
                    parentIndex,
                    index: menuTree[parentIndex]?.children.length ?? 0,
                    position: 'before',
                };
            }

            const row = el.closest('.mb2-row');
            if (!row) return null;

            const parts = String(row.dataset.path).split('.').map(Number);
            const rect = row.getBoundingClientRect();
            const position = y < rect.top + (rect.height / 2) ? 'before' : 'after';
            row.classList.add(position === 'before' ? 'drop-before' : 'drop-after');
            return parts.length === 1
                ? { kind: 'root', parentIndex: -1, index: parts[0], position }
                : { kind: 'sub', parentIndex: parts[0], index: parts[1], position };
        };

        // Cu 15 categorii lista trece de ecran, deci trebuie sa se deruleze
        // singura cand ajungi cu randul in mana la marginea ferestrei.
        const deruleazaLaMargine = (clientY) => {
            const margine = 70;
            if (clientY < margine) {
                window.scrollBy(0, -14);
            } else if (clientY > window.innerHeight - margine) {
                window.scrollBy(0, 14);
            }
        };

        const aplicaMutarea = () => {
            const from = drag.path.split('.').map(Number);
            const target = drag.target;
            const sourceParent = from.length === 2 ? menuTree[from[0]] : null;
            const sourceNode = from.length === 1 ? menuTree[from[0]] : sourceParent?.children[from[1]];
            if (!sourceNode) return false;

            // Tinem referintele, nu indicii: dupa scoaterea nodului indicii se muta.
            const targetParent = target.kind === 'sub' ? menuTree[target.parentIndex] : null;
            const targetNode = target.kind === 'root'
                ? menuTree[target.index]
                : (targetParent ? targetParent.children[target.index] : null);

            if (sourceNode === targetNode) return false;
            if (target.kind === 'sub' && sourceNode === targetParent) return false;

            if (from.length === 1) {
                menuTree.splice(from[0], 1);
            } else {
                sourceParent.children.splice(from[1], 1);
            }

            const copie = (node) => ({ label: node.label, url: node.url });
            const kids = (from.length === 1 ? sourceNode.children : []).map(copie);

            if (target.kind === 'sub' && targetParent) {
                let at = targetNode ? targetParent.children.indexOf(targetNode) : targetParent.children.length;
                if (at < 0) at = targetParent.children.length;
                if (target.position === 'after') at += 1;
                // Nu exista nivelul trei: subpaginile celui mutat il urmeaza ca frati.
                targetParent.children.splice(at, 0, copie(sourceNode), ...kids);
            } else {
                let at = targetNode ? menuTree.indexOf(targetNode) : menuTree.length;
                if (at < 0) at = menuTree.length;
                if (target.position === 'after') at += 1;
                menuTree.splice(at, 0, { ...copie(sourceNode), children: kids });
            }

            return true;
        };

        menuItemsList?.addEventListener('pointerdown', (event) => {
            const grip = event.target.closest('.mb2-grip');
            if (!grip || event.button !== 0) return;
            event.preventDefault();
            drag.path = String(grip.dataset.grip || '');
            drag.target = null;
            drag.pornit = false;
            drag.pointerId = event.pointerId;
            drag.x0 = event.clientX;
            drag.y0 = event.clientY;
            try { grip.setPointerCapture(event.pointerId); } catch { /* fara captura merge si asa */ }
        });

        menuItemsList?.addEventListener('pointermove', (event) => {
            if (drag.path === '' || event.pointerId !== drag.pointerId) return;

            if (!drag.pornit) {
                // Cativa pixeli toleranta, ca un click scapat pe maner sa nu
                // porneasca o mutare.
                if (Math.abs(event.clientX - drag.x0) + Math.abs(event.clientY - drag.y0) < 5) return;
                drag.pornit = true;
                menuItemsList.classList.add('is-dragging');
                menuItemsList.querySelector(`.mb2-row[data-path="${drag.path}"]`)?.classList.add('is-dragging');
            }

            event.preventDefault();
            clearDropDecor();
            drag.target = tintaDinPunct(event.clientX, event.clientY);
            deruleazaLaMargine(event.clientY);
        });

        const incheieMutarea = (event) => {
            if (drag.path === '' || (drag.pointerId !== null && event.pointerId !== drag.pointerId)) return;
            const mutat = drag.pornit && drag.target ? aplicaMutarea() : false;
            endDrag();
            if (mutat) renderMenuBuilder();
        };

        menuItemsList?.addEventListener('pointerup', incheieMutarea);
        menuItemsList?.addEventListener('pointercancel', () => endDrag());

        // Fara asta, browserul porneste propria mutare de text sau de imagine
        // peste a noastra.
        menuItemsList?.addEventListener('dragstart', (event) => event.preventDefault());

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
