<?php
$templates = is_array($templates ?? null) ? $templates : [];
$categories = is_array($categories ?? null) ? $categories : [];
?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1>Postare nouă — Pasul 1</h1>
            <p>Alege template-ul (layout-ul) și categoria. În Pasul 2 vei completa doar câmpurile cerute de template.</p>
        </div>
        <a class="btn btn-secondary" href="/admin/blog/posts">&larr; Înapoi la listă</a>
    </div>

    <form method="get" action="/admin/blog/posts/editor" class="form-grid" style="max-width:640px;">
        <div class="field" style="grid-column:1/-1;">
            <label>Template (layout) *</label>
            <select name="template_id" required>
                <option value="">— Alege template —</option>
                <option value="0">Template implicit (articol standard)</option>
                <?php foreach ($templates as $template): ?>
                    <option value="<?= (int) ($template['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($template['name'] ?? ('Template #' . (int) ($template['id'] ?? 0))), ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Categorii (zone) — poți alege oricâte *</label>
            <div class="blog-cat-checks">
                <?php if ($categories === []): ?>
                    <small class="muted">Nu există categorii. Creează una în <a href="/admin/blog/categories">Blog → Categorii</a>.</small>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <label class="blog-cat-check">
                            <input type="checkbox" name="category_ids[]" value="<?= (int) ($category['id'] ?? 0) ?>">
                            <span><?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES) ?></span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <small class="muted">Le poți modifica oricând și în Pasul 2.</small>
        </div>
        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
            <button class="btn" type="submit">Continuă spre Pasul 2 →</button>
        </div>
    </form>
</section>
<style>
.blog-cat-checks{display:flex;flex-wrap:wrap;gap:8px 14px;border:1px solid #cbd5e1;border-radius:12px;padding:12px 14px;background:#fff;}
.blog-cat-check{display:inline-flex;align-items:center;gap:7px;font-size:14px;color:#1f2937;cursor:pointer;}
.blog-cat-check input{width:16px;height:16px;cursor:pointer;}
</style>
