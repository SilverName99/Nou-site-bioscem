<?php
$editingPost = is_array($editingPost ?? null) ? $editingPost : null;
$templates = is_array($templates ?? null) ? $templates : [];
$categories = is_array($categories ?? null) ? $categories : [];
$authors = is_array($authors ?? null) ? $authors : [];
$galleryImages = is_array($galleryImages ?? null) ? $galleryImages : [];
$fieldMap = is_array($fieldMap ?? null) ? $fieldMap : ['0' => []];
$selectedTemplateId = (int) ($selectedTemplateId ?? 0);
$selectedCategoryId = (int) ($selectedCategoryId ?? 0);
$editingPostSeo = is_array($editingPostSeo ?? null) ? $editingPostSeo : [
    'title' => '', 'description' => '', 'canonical_url' => '', 'image_url' => '',
];
$postId = (int) ($editingPost['id'] ?? 0);
$activeFields = $fieldMap[(string) $selectedTemplateId] ?? ($fieldMap['0'] ?? []);
$alwaysVisible = ['title', 'content'];
$showField = static function (string $key) use ($activeFields, $alwaysVisible): bool {
    return in_array($key, $alwaysVisible, true) || in_array($key, $activeFields, true);
};
$featuredPreview = trim((string) ($editingPost['featured_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
$seoImagePreview = trim((string) (($editingPostSeo['image_url'] ?? '') ?: '')) ?: '/assets/img/product-placeholder.svg';
$publishedAt = trim((string) ($editingPost['published_at'] ?? ''));
if ($publishedAt === '') {
    $publishedAt = date('Y-m-d H:i:s');
}
$publishedAtInput = date('Y-m-d\TH:i', strtotime($publishedAt) ?: time());
$returnParam = $postId > 0 ? (string) $postId : 'new';
?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1><?= $postId > 0 ? 'Editează postare' : 'Postare nouă — Pasul 2' ?></h1>
            <p>Completează câmpurile cerute de template. Schimbând template-ul, câmpurile se actualizează.</p>
        </div>
        <a class="btn btn-secondary" href="/admin/blog/posts">&larr; Înapoi la listă</a>
    </div>

    <form method="post" action="/admin/blog/posts" class="form-grid" id="blog-post-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="timezone_offset_minutes" id="blog-post-timezone-offset" value="">
        <?php if ($postId > 0): ?>
            <input type="hidden" name="id" value="<?= $postId ?>">
        <?php endif; ?>

        <div class="field">
            <label>Template (layout)</label>
            <select name="template_id" id="blog-post-template-select">
                <option value="0" <?= $selectedTemplateId === 0 ? 'selected' : '' ?>>Template implicit (articol standard)</option>
                <?php foreach ($templates as $template): ?>
                    <?php $tid = (int) ($template['id'] ?? 0); ?>
                    <option value="<?= $tid ?>" <?= $tid === $selectedTemplateId ? 'selected' : '' ?>><?= htmlspecialchars((string) ($template['name'] ?? ('Template #' . $tid)), ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Categorii (zone) — poți alege oricâte</label>
            <?php $selectedCategoryIds = is_array($selectedCategoryIds ?? null) ? array_map('intval', $selectedCategoryIds) : []; ?>
            <div class="blog-cat-checks" id="blog-post-category-checks">
                <?php if ($categories === []): ?>
                    <small class="muted">Nu există categorii. Creează în <a href="/admin/blog/categories">Blog → Categorii</a>.</small>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <?php $cid = (int) ($category['id'] ?? 0); ?>
                        <label class="blog-cat-check">
                            <input type="checkbox" name="category_ids[]" value="<?= $cid ?>" <?= in_array($cid, $selectedCategoryIds, true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES) ?></span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="field" data-field="title">
            <label>Titlu *</label>
            <input type="text" name="title" required value="<?= htmlspecialchars((string) ($editingPost['title'] ?? ''), ENT_QUOTES) ?>">
        </div>

        <div class="field" data-field="author" style="<?= $showField('author') ? '' : 'display:none;' ?>">
            <label>Autor</label>
            <select name="author_id">
                <option value="">Fără autor</option>
                <?php foreach ($authors as $author): ?>
                    <?php $aid = (int) ($author['id'] ?? 0); ?>
                    <option value="<?= $aid ?>" <?= $aid === (int) ($editingPost['author_id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($author['name'] ?? ('Autor #' . $aid)), ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" data-field="reading_minutes" style="<?= $showField('reading_minutes') ? '' : 'display:none;' ?>">
            <label>Timp citire (minute)</label>
            <input type="number" name="reading_minutes" min="1" max="300" value="<?= (int) ($editingPost['reading_minutes'] ?? 5) ?>">
        </div>

        <div class="field" data-field="featured_image" style="grid-column:1/-1;<?= $showField('featured_image') ? '' : 'display:none;' ?>">
            <label>Imagine principală</label>
            <input type="hidden" name="featured_image_url" id="blog-post-featured-image-url" value="<?= htmlspecialchars((string) ($editingPost['featured_image_url'] ?? ''), ENT_QUOTES) ?>">
            <div class="product-image-picker-inline">
                <img id="blog-post-featured-image-preview" src="<?= htmlspecialchars($featuredPreview, ENT_QUOTES) ?>" alt="Preview" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                <div>
                    <button class="btn btn-secondary" type="button" id="open-blog-featured-image-picker">Selectează imagine</button>
                    <p id="blog-post-featured-image-label" class="muted" style="margin:8px 0 0;font-size:12px;"><?= htmlspecialchars($featuredPreview, ENT_QUOTES) ?></p>
                </div>
            </div>
        </div>

        <div class="field" data-field="excerpt" style="grid-column:1/-1;<?= $showField('excerpt') ? '' : 'display:none;' ?>">
            <label>Excerpt (rezumat scurt)</label>
            <textarea name="excerpt" rows="3"><?= htmlspecialchars((string) ($editingPost['excerpt'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>

        <div class="field" data-field="video" style="grid-column:1/-1;<?= $showField('video') ? '' : 'display:none;' ?>">
            <label>Video (fișier MP4)</label>
            <input type="text" name="video_url" placeholder="/uploads/gallery/nume-video.mp4" value="<?= htmlspecialchars((string) ($editingPost['video_url'] ?? ''), ENT_QUOTES) ?>">
            <small class="muted">Încarcă fișierul .mp4 în Galerie, apoi pune aici calea (ex: <code>/uploads/gallery/nume.mp4</code>).</small>
        </div>

        <div class="field" data-field="event_dates" style="grid-column:1/-1;<?= $showField('event_dates') ? '' : 'display:none;' ?>">
            <label>Perioadă eveniment</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <input type="date" name="event_start_date" value="<?= htmlspecialchars((string) ($editingPost['event_start_date'] ?? ''), ENT_QUOTES) ?>" aria-label="Data început">
                <input type="date" name="event_end_date" value="<?= htmlspecialchars((string) ($editingPost['event_end_date'] ?? ''), ENT_QUOTES) ?>" aria-label="Data sfârșit">
            </div>
        </div>

        <div class="field" data-field="event_price" style="<?= $showField('event_price') ? '' : 'display:none;' ?>">
            <label>Preț eveniment</label>
            <input type="text" name="event_price" placeholder="ex: 350 RON sau Gratuit" value="<?= htmlspecialchars((string) ($editingPost['event_price'] ?? ''), ENT_QUOTES) ?>">
        </div>

        <div class="field" data-field="event_location" style="<?= $showField('event_location') ? '' : 'display:none;' ?>">
            <label>Locație eveniment</label>
            <input type="text" name="event_location" placeholder="ex: București, Hotel X" value="<?= htmlspecialchars((string) ($editingPost['event_location'] ?? ''), ENT_QUOTES) ?>">
        </div>

        <div class="field" data-field="event_ticket_url" style="grid-column:1/-1;<?= $showField('event_ticket_url') ? '' : 'display:none;' ?>">
            <label>Link bilete / înscriere (site extern)</label>
            <input type="url" name="event_ticket_url" placeholder="https://..." value="<?= htmlspecialchars((string) ($editingPost['event_ticket_url'] ?? ''), ENT_QUOTES) ?>">
        </div>

        <div class="field" data-field="content" style="grid-column:1/-1;">
            <label>Conținut *</label>
            <div class="page-toolbar" style="margin:6px 0 8px;gap:6px;flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="formatBlock" data-value="H2">H2</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="formatBlock" data-value="H3">H3</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="formatBlock" data-value="H4">H4</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="bold">B</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="italic"><em>I</em></button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="insertUnorderedList">• Listă</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="insertOrderedList">1. Listă</button>
                <button type="button" class="btn btn-secondary" id="blog-editor-link-btn">Link</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="unlink">Unlink</button>
                <button type="button" class="btn btn-secondary" id="blog-editor-image-btn">🖼 Imagine din galerie</button>
            </div>
            <div id="blog-post-content-editor" contenteditable="true" class="blog-rich-editor" data-placeholder="Scrie conținutul articolului..."></div>
            <textarea name="content" id="blog-post-content-input" rows="12" style="display:none;"><?= htmlspecialchars((string) ($editingPost['content'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>

        <details class="panel" style="grid-column:1/-1;margin:0;">
            <summary style="cursor:pointer;font-weight:700;">Setări postare <span style="color:#64748b;font-weight:400;font-size:.9em;">(slug, status, dată, SEO)</span></summary>
            <div class="form-grid" style="margin-top:12px;">
                <div class="field">
                    <label>Slug (opțional)</label>
                    <input type="text" name="slug" value="<?= htmlspecialchars((string) ($editingPost['slug'] ?? ''), ENT_QUOTES) ?>" placeholder="slug-postare">
                </div>
                <div class="field">
                    <label>Data publicării</label>
                    <input type="datetime-local" name="published_at" value="<?= htmlspecialchars($publishedAtInput, ENT_QUOTES) ?>">
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_published" <?= ((int) ($editingPost['is_published'] ?? 1) === 1) ? 'checked' : '' ?>>
                        Publicat
                    </label>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Meta title</label>
                    <input type="text" name="seo_title" value="<?= htmlspecialchars((string) (($editingPostSeo['title'] ?? '') ?: ''), ENT_QUOTES) ?>">
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Meta description</label>
                    <textarea name="seo_description" rows="3"><?= htmlspecialchars((string) (($editingPostSeo['description'] ?? '') ?: ''), ENT_QUOTES) ?></textarea>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Canonical URL (opțional)</label>
                    <input type="url" name="seo_canonical_url" value="<?= htmlspecialchars((string) (($editingPostSeo['canonical_url'] ?? '') ?: ''), ENT_QUOTES) ?>">
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Imagine social preview (OG/Twitter)</label>
                    <input type="hidden" name="seo_image_url" id="blog-post-seo-image-url" value="<?= htmlspecialchars((string) (($editingPostSeo['image_url'] ?? '') ?: ''), ENT_QUOTES) ?>">
                    <div class="product-image-picker-inline">
                        <img id="blog-post-seo-image-preview" src="<?= htmlspecialchars($seoImagePreview, ENT_QUOTES) ?>" alt="Preview SEO" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                        <div>
                            <button class="btn btn-secondary" type="button" id="open-blog-seo-image-picker">Selectează imagine</button>
                            <p id="blog-post-seo-image-label" class="muted" style="margin:8px 0 0;font-size:12px;"><?= htmlspecialchars($seoImagePreview, ENT_QUOTES) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </details>

        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
            <a class="btn btn-secondary" href="/admin/blog/posts">Anulează</a>
            <button class="btn" type="submit">Salvează postarea</button>
        </div>
    </form>
</section>

<div class="modal-overlay" id="blog-post-image-modal">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-head">
            <h3>Selectează imagine din Galerie</h3>
            <button type="button" class="icon-btn" id="close-blog-featured-image-picker" aria-label="Închide">✕</button>
        </div>
        <div class="field" style="margin-bottom:10px;">
            <input type="text" id="blog-post-image-search" placeholder="Caută imagine după titlu...">
        </div>
        <form method="post" action="/admin/blog/posts/image-upload" enctype="multipart/form-data" class="field" style="margin-bottom:10px;">
            <label style="margin-bottom:6px;">Încarcă imagine nouă</label>
            <div class="product-image-upload-inline">
                <input type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp,.gif" required>
                <input type="hidden" name="redirect_url" value="/admin/blog/posts/editor?post=<?= htmlspecialchars($returnParam, ENT_QUOTES) ?>">
                <button type="submit" class="btn">Încarcă</button>
            </div>
            <small class="muted">Imaginea se adaugă în galerie și poate fi selectată imediat.</small>
        </form>
        <div class="product-picker-grid" id="blog-post-image-grid">
            <?php foreach ($galleryImages as $image): ?>
                <button
                    type="button"
                    class="product-picker-item blog-post-image-item"
                    data-image-url="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>"
                    data-search="<?= htmlspecialchars(strtolower((string) (($image['title'] ?? '') . ' ' . ($image['alt_text'] ?? '') . ' ' . ($image['image_url'] ?? ''))), ENT_QUOTES) ?>"
                >
                    <img src="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>" alt="" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                    <strong><?= htmlspecialchars((string) (($image['title'] ?? '') !== '' ? $image['title'] : 'Imagine fără titlu'), ENT_QUOTES) ?></strong>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
(() => {
    const fieldMap = <?= json_encode($fieldMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const alwaysVisible = <?= json_encode($alwaysVisible) ?>;
    const templateSelect = document.getElementById('blog-post-template-select');

    const applyFields = () => {
        const key = String((templateSelect && templateSelect.value !== '') ? templateSelect.value : '0');
        const active = Array.isArray(fieldMap[key]) ? fieldMap[key] : (fieldMap['0'] || []);
        document.querySelectorAll('[data-field]').forEach((el) => {
            const f = el.getAttribute('data-field') || '';
            const visible = alwaysVisible.includes(f) || active.includes(f);
            el.style.display = visible ? '' : 'none';
        });
    };
    if (templateSelect) {
        templateSelect.addEventListener('change', applyFields);
    }
    applyFields();

    // Image pickers
    const imageModal = document.getElementById('blog-post-image-modal');
    const imageItems = Array.from(document.querySelectorAll('.blog-post-image-item'));
    const imageSearchInput = document.getElementById('blog-post-image-search');
    const imageInput = document.getElementById('blog-post-featured-image-url');
    const imagePreview = document.getElementById('blog-post-featured-image-preview');
    const imageLabel = document.getElementById('blog-post-featured-image-label');
    const seoImageInput = document.getElementById('blog-post-seo-image-url');
    const seoImagePreview = document.getElementById('blog-post-seo-image-preview');
    const seoImageLabel = document.getElementById('blog-post-seo-image-label');
    let pickerMode = 'featured';
    const setFeatured = (url) => {
        const safe = (url || '').trim() || '/assets/img/product-placeholder.svg';
        if (imageInput) imageInput.value = safe === '/assets/img/product-placeholder.svg' ? '' : safe;
        if (imagePreview) imagePreview.src = safe;
        if (imageLabel) imageLabel.textContent = safe;
    };
    const setSeo = (url) => {
        const safe = (url || '').trim() || '/assets/img/product-placeholder.svg';
        if (seoImageInput) seoImageInput.value = safe === '/assets/img/product-placeholder.svg' ? '' : safe;
        if (seoImagePreview) seoImagePreview.src = safe;
        if (seoImageLabel) seoImageLabel.textContent = safe;
    };
    const openModal = () => imageModal && imageModal.classList.add('open');
    const closeModal = () => imageModal && imageModal.classList.remove('open');
    document.getElementById('open-blog-featured-image-picker')?.addEventListener('click', () => { pickerMode = 'featured'; openModal(); });
    document.getElementById('open-blog-seo-image-picker')?.addEventListener('click', () => { pickerMode = 'seo'; openModal(); });
    document.getElementById('close-blog-featured-image-picker')?.addEventListener('click', closeModal);
    imageModal?.addEventListener('click', (e) => { if (e.target === imageModal) closeModal(); });
    imageSearchInput?.addEventListener('input', () => {
        const q = (imageSearchInput.value || '').trim().toLowerCase();
        imageItems.forEach((item) => {
            const hay = item.getAttribute('data-search') || '';
            item.style.display = hay.includes(q) ? '' : 'none';
        });
    });
    imageItems.forEach((item) => {
        item.addEventListener('click', () => {
            const url = item.getAttribute('data-image-url') || '';
            if (pickerMode === 'content') {
                const alt = (item.querySelector('strong')?.textContent || item.getAttribute('data-title') || '').trim();
                insertImage(url, alt);
                closeModal();
                return;
            }
            pickerMode === 'seo' ? setSeo(url) : setFeatured(url);
            closeModal();
        });
    });

    // Rich content editor
    const editor = document.getElementById('blog-post-content-editor');
    const editorInput = document.getElementById('blog-post-content-input');
    const tzInput = document.getElementById('blog-post-timezone-offset');
    const form = document.getElementById('blog-post-form');
    if (tzInput instanceof HTMLInputElement) {
        tzInput.value = String(new Date().getTimezoneOffset());
    }
    if (editor instanceof HTMLElement && editorInput instanceof HTMLTextAreaElement) {
        editor.innerHTML = editorInput.value || '';
        editor.addEventListener('input', () => { editorInput.value = editor.innerHTML.trim(); });
    }
    const runCommand = (cmd, value = null) => {
        if (!(editor instanceof HTMLElement)) return;
        editor.focus();
        document.execCommand(cmd, false, value);
    };
    document.querySelectorAll('.blog-editor-cmd').forEach((button) => {
        button.addEventListener('click', () => {
            const cmd = button.getAttribute('data-cmd') || '';
            const value = button.getAttribute('data-value');
            if (cmd === 'formatBlock' && value) { runCommand(cmd, value); return; }
            if (cmd !== '') runCommand(cmd);
        });
    });
    document.getElementById('blog-editor-link-btn')?.addEventListener('click', () => {
        if (!(editor instanceof HTMLElement)) return;
        const url = window.prompt('URL link (ex: https://...)', 'https://');
        if (!url) return;
        runCommand('createLink', url.trim());
    });

    // Inserare imagine în conținut (alegere din Galerie + insert la cursor)
    const imageBtn = document.getElementById('blog-editor-image-btn');
    let savedRange = null;
    const saveRange = () => {
        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0 && editor instanceof HTMLElement && editor.contains(sel.anchorNode)) {
            savedRange = sel.getRangeAt(0);
        }
    };
    if (editor instanceof HTMLElement) {
        ['keyup', 'mouseup', 'blur'].forEach((ev) => editor.addEventListener(ev, saveRange));
    }
    const insertImage = (url, alt) => {
        if (!(editor instanceof HTMLElement)) return;
        editor.focus();
        const sel = window.getSelection();
        if (savedRange && sel) {
            sel.removeAllRanges();
            sel.addRange(savedRange);
        }
        const img = document.createElement('img');
        img.src = url;
        img.alt = alt || '';
        img.style.maxWidth = '100%';
        img.style.height = 'auto';
        if (sel && sel.rangeCount > 0 && editor.contains(sel.anchorNode)) {
            const range = sel.getRangeAt(0);
            range.deleteContents();
            range.insertNode(img);
            // mută cursorul după imagine
            range.setStartAfter(img);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        } else {
            editor.appendChild(img);
        }
        editorInput.value = editor.innerHTML.trim();
    };
    imageBtn?.addEventListener('click', () => {
        saveRange();
        pickerMode = 'content';
        openModal();
    });
    if (form instanceof HTMLFormElement && editor instanceof HTMLElement && editorInput instanceof HTMLTextAreaElement) {
        form.addEventListener('submit', () => {
            editorInput.value = editor.innerHTML.trim();
        });
    }
})();
</script>

<style>
.blog-rich-editor{
    min-height:260px;
    border:1px solid #cbd5e1;
    border-radius:12px;
    background:#fff;
    padding:12px;
    line-height:1.6;
}
.blog-rich-editor h1,.blog-rich-editor h2,.blog-rich-editor h3,.blog-rich-editor h4{margin:0.65em 0 0.4em;}
.blog-rich-editor p{margin:0.5em 0;}
.blog-rich-editor ul,.blog-rich-editor ol{margin:0.5em 0 0.6em 1.2em;}
.blog-rich-editor a{color:#0f7b53;}
.blog-rich-editor img{max-width:100%;height:auto;border-radius:8px;margin:6px 0;}
.blog-cat-checks{display:flex;flex-wrap:wrap;gap:8px 14px;border:1px solid #cbd5e1;border-radius:12px;padding:12px 14px;background:#fff;}
.blog-cat-check{display:inline-flex;align-items:center;gap:7px;font-size:14px;color:#1f2937;cursor:pointer;}
.blog-cat-check input{width:16px;height:16px;cursor:pointer;}
.blog-rich-editor:empty:before{
    content:attr(data-placeholder);
    color:#94a3b8;
}
</style>
