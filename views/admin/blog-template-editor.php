<?php
$blogTemplate = is_array($blogTemplate ?? null) ? $blogTemplate : [];
$templateId = (int) ($blogTemplate['id'] ?? 0);
$templateName = (string) ($blogTemplate['name'] ?? '');
$templateSlug = (string) ($blogTemplate['slug'] ?? '');
$templateDescription = (string) ($blogTemplate['description'] ?? '');
$templateHtml = (string) ($blogTemplate['html_content'] ?? '');
$templateCss = (string) ($blogTemplate['css_content'] ?? '');
$templateJs = (string) ($blogTemplate['js_content'] ?? '');
$isActive = (int) ($blogTemplate['is_active'] ?? 1) === 1;
?>

<section class="panel">
    <form method="post" action="/admin/blog/templates" id="blog-template-editor-form">
        <input type="hidden" name="id" value="<?= $templateId ?>">
        <input type="hidden" name="action" value="save_builder">
        <?php
        $generalTokens = [
            ['label' => 'Titlu articol', 'token' => '{{blog_title}}'],
            ['label' => 'Slug articol', 'token' => '{{blog_slug}}'],
            ['label' => 'Conținut articol', 'token' => '{{blog_content}}'],
            ['label' => 'Conținut articol (HTML)', 'token' => '{{blog_content_html}}'],
            ['label' => 'Descriere succintă (excerpt)', 'token' => '{{blog_excerpt}}'],
            ['label' => 'Video (player MP4)', 'token' => '{{blog_video}}'],
            ['label' => 'Video (URL brut)', 'token' => '{{blog_video_url}}'],
            ['label' => 'Imagine articol', 'token' => '{{blog_image_url}}'],
            ['label' => 'Categorie', 'token' => '{{blog_category}}'],
            ['label' => 'Data publicării', 'token' => '{{blog_published_date}}'],
            ['label' => 'Data publicării (raw)', 'token' => '{{blog_published_date_raw}}'],
            ['label' => 'Timp citire', 'token' => '{{blog_reading_minutes}}'],
            ['label' => 'Timp citire formatat', 'token' => '{{blog_reading_label}}'],
            ['label' => 'Autor (nume)', 'token' => '{{blog_author_name}}'],
            ['label' => 'Autor (bio)', 'token' => '{{blog_author_bio}}'],
            ['label' => 'Avatar autor', 'token' => '{{blog_author_avatar}}'],
            ['label' => 'Link autor', 'token' => '{{blog_author_url}}'],
            ['label' => 'Link articol', 'token' => '{{blog_post_url}}'],
            ['label' => 'Data curentă', 'token' => '{{blog_now_date}}'],
            ['label' => 'An curent', 'token' => '{{blog_now_year}}'],
            ['label' => 'Eveniment: perioadă', 'token' => '{{blog_event_period}}'],
            ['label' => 'Eveniment: dată început', 'token' => '{{blog_event_start_date}}'],
            ['label' => 'Eveniment: dată sfârșit', 'token' => '{{blog_event_end_date}}'],
            ['label' => 'Eveniment: preț', 'token' => '{{blog_event_price}}'],
            ['label' => 'Eveniment: locație', 'token' => '{{blog_event_location}}'],
            ['label' => 'Eveniment: link bilete', 'token' => '{{blog_event_ticket_url}}'],
        ];
        ?>
        <div class="page-toolbar">
            <a href="/admin/blog/templates" class="btn btn-secondary">&larr; Înapoi la template-uri</a>
            <button type="button" class="btn btn-secondary code-toggle-arrow" id="toggle-code-pane" title="Ascunde/arată cod">⟵</button>
            <div class="field" style="min-width:260px;flex:1;">
                <input type="text" id="template-name" name="name" value="<?= htmlspecialchars($templateName, ENT_QUOTES) ?>" placeholder="Nume template blog" required>
            </div>
            <div class="field" style="width:220px;">
                <input type="text" id="template-slug" name="slug" value="<?= htmlspecialchars($templateSlug, ENT_QUOTES) ?>" placeholder="slug-template-blog" required>
            </div>
            <label style="display:flex;align-items:center;gap:8px;white-space:nowrap;">
                <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                Activ
            </label>
            <button class="btn" type="submit">Salvează</button>
        </div>

        <div class="field" style="margin-top:12px;">
            <label for="template-description">Descriere</label>
            <input type="text" id="template-description" name="description" value="<?= htmlspecialchars($templateDescription, ENT_QUOTES) ?>" placeholder="Ex: layout SEO articol blog">
        </div>

        <article class="panel" style="margin-top:12px;background:#f8fafc;border-color:#cbd5e1;">
            <h4 style="margin-top:0;">Coduri disponibile pentru template</h4>
            <p style="margin:0 0 10px;color:#64748b;">Poți copia aceste coduri și le poți insera în HTML/CSS/JS.</p>
            <div class="template-token-panel is-active" data-token-panel="general" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                <?php foreach ($generalTokens as $item): ?>
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
                    <iframe id="template-preview" title="Preview template blog"></iframe>
                </div>
                <small style="display:block;margin-top:10px;color:#64748b;">
                    Preview demo pentru codurile disponibile.
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
    const editorGrid = document.getElementById('template-editor-grid');
    const codeColumn = document.getElementById('code-column');
    const toggleCodePane = document.getElementById('toggle-code-pane');
    const codeSearchBtn = document.getElementById('code-search-btn');
    const codeBeautifyBtn = document.getElementById('code-beautify-btn');
    const codeFullscreenBtn = document.getElementById('code-fullscreen-btn');
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
            '{{blog_title}}': 'Cum îți optimizezi rutina de suplimente',
            '{{blog_slug}}': 'cum-iti-optimizezi-rutina-de-suplimente',
            '{{blog_content}}': 'Acesta este un articol demo pentru preview în builder.',
            '{{blog_content_html}}': '<p>Acesta este un articol <strong>demo</strong> pentru preview în builder.</p>',
            '{{blog_image_url}}': '/assets/img/product-placeholder.svg',
            '{{blog_category}}': 'Wellness',
            '{{blog_published_date}}': '31.03.2026',
            '{{blog_published_date_raw}}': '2026-03-31',
            '{{blog_reading_minutes}}': '6',
            '{{blog_reading_label}}': '6 min',
            '{{blog_author_name}}': 'Echipa Bioscem',
            '{{blog_author_bio}}': 'Articole despre nutriție și stil de viață sănătos.',
            '{{blog_author_avatar}}': '/assets/img/product-placeholder.svg',
            '{{blog_author_url}}': '/blog?autor=echipa-bioscem',
            '{{blog_post_url}}': '/blog/cum-iti-optimizezi-rutina-de-suplimente',
            '{{blog_now_date}}': '31.03.2026',
            '{{blog_now_year}}': '2026',
        };
        let output = content;
        Object.entries(map).forEach(([needle, value]) => {
            output = output.split(needle).join(value);
        });
        return output;
    };

    const renderPreview = () => {
        const rawHtml = getEditorValue('html') || '<p>Scrie codul template-ului pentru preview...</p>';
        const body = replacePlaceholders(rawHtml);
        const css = replacePlaceholders(getEditorValue('css') || '');
        const js = replacePlaceholders(getEditorValue('js') || '').replace(/<\/script>/gi, '<\\/script>');
        iframe.srcdoc = '<!doctype html>'
            + '<html lang="ro"><head>'
            + '<meta charset="utf-8" />'
            + '<meta name="viewport" content="width=device-width, initial-scale=1" />'
            + '<style>body{margin:0;font-family:Arial,sans-serif;color:#111827;padding:24px;line-height:1.6;background:#f8fafc;}img{max-width:100%;height:auto;}</style>'
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

    document.getElementById('blog-template-editor-form')?.addEventListener('submit', () => {
        Object.values(editors).forEach((editor) => editor?.save?.());
    });
})();
</script>
