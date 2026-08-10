<?php
$templates = is_array($templates ?? null) ? $templates : [];
$newRequested = (bool) ($newRequested ?? false);
?>

<section class="panel">
    <div class="section-head" style="margin-bottom:10px;">
        <div>
            <h1>Template blog</h1>
            <p>Creează template-uri HTML/CSS/JS pentru postările de blog.</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <button class="btn" type="button" id="new-blog-template-btn">+ Template nou</button>
        </div>
    </div>

    <article class="panel" style="margin:0;">
        <h3 style="margin-top:0;">Template-uri existente</h3>
        <div class="users-table-wrap">
            <table class="table users-table">
                <thead>
                <tr>
                    <th>Nume</th>
                    <th>Slug</th>
                    <th>Status</th>
                    <th style="width:140px;">Acțiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($templates === []): ?>
                    <tr><td colspan="4">Nu există template-uri de blog.</td></tr>
                <?php else: ?>
                    <?php foreach ($templates as $tpl): ?>
                        <?php $id = (int) ($tpl['id'] ?? 0); ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) ($tpl['name'] ?? ''), ENT_QUOTES) ?></strong><br>
                                <small><?= htmlspecialchars((string) ($tpl['description'] ?? ''), ENT_QUOTES) ?></small>
                            </td>
                            <td><code><?= htmlspecialchars((string) ($tpl['slug'] ?? ''), ENT_QUOTES) ?></code></td>
                            <td>
                                <span class="status-pill <?= (int) ($tpl['is_active'] ?? 0) === 1 ? 'ok' : 'off' ?>">
                                    <?= (int) ($tpl['is_active'] ?? 0) === 1 ? 'activ' : 'inactiv' ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <a class="icon-btn users-detail-btn" href="/admin/blog/templates/builder?id=<?= $id ?>" title="Editează în builder">✎</a>
                                    <form method="post" action="/admin/blog/templates" style="display:inline-flex;align-items:center;margin:0;" onsubmit="return confirm('Ștergi template-ul?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <button class="icon-btn danger" type="submit" title="Șterge">🗑</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<div class="modal-overlay<?= $newRequested ? ' open' : '' ?>" id="blog-template-create-modal">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-head">
            <h3>Template blog nou</h3>
            <button type="button" class="icon-btn" id="close-blog-template-create-modal">✕</button>
        </div>
        <form method="post" action="/admin/blog/templates" class="field" id="blog-template-create-form">
            <input type="hidden" name="quick_create" value="1">
            <input type="hidden" name="is_active" value="1">
            <label>Nume *</label>
            <input type="text" name="name" required placeholder="Template standard blog">
            <div style="display:flex;gap:8px;align-items:center;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" id="cancel-blog-template-create-btn">Anulează</button>
                <button class="btn" type="submit">Creează template</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const openBtn = document.getElementById('new-blog-template-btn');
    const closeBtn = document.getElementById('close-blog-template-create-modal');
    const cancelBtn = document.getElementById('cancel-blog-template-create-btn');
    const modal = document.getElementById('blog-template-create-modal');
    if (!(modal instanceof HTMLElement) || !(openBtn instanceof HTMLButtonElement)) {
        return;
    }

    const open = () => modal.classList.add('open');
    const close = () => modal.classList.remove('open');

    openBtn.addEventListener('click', open);
    closeBtn?.addEventListener('click', close);
    cancelBtn?.addEventListener('click', close);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) close();
    });
})();
</script>
