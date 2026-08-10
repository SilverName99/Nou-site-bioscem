<?php
$authors = is_array($authors ?? null) ? $authors : [];
$editingAuthor = is_array($editingAuthor ?? null) ? $editingAuthor : null;
$selectedAuthorId = (int) ($selectedAuthorId ?? 0);
$newRequested = (bool) ($newRequested ?? false);
$modalOpen = $selectedAuthorId > 0 || $newRequested;
$galleryImages = is_array($galleryImages ?? null) ? $galleryImages : [];
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Autori blog</h1>
            <p>Gestionează autorii disponibili pentru postările de blog.</p>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn" type="button" id="add-author-btn">+ Autor nou</button>
        </div>
    </div>

    <div class="users-table-wrap">
        <table class="table users-table">
            <thead>
            <tr>
                <th>Nume</th>
                <th>Slug</th>
                <th>Status</th>
                <th>Postări</th>
                <th style="width:120px;">Acțiuni</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($authors === []): ?>
                <tr><td colspan="5">Nu există autori blog.</td></tr>
            <?php else: ?>
                <?php foreach ($authors as $author): ?>
                    <?php
                    $id = (int) ($author['id'] ?? 0);
                    $isActive = (int) ($author['is_active'] ?? 1) === 1;
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars((string) ($author['name'] ?? ''), ENT_QUOTES) ?></strong>
                            <?php if (trim((string) ($author['bio'] ?? '')) !== ''): ?>
                                <br><small><?= htmlspecialchars((string) ($author['bio'] ?? ''), ENT_QUOTES) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars((string) ($author['slug'] ?? ''), ENT_QUOTES) ?></code></td>
                        <td>
                            <span class="status-pill <?= $isActive ? 'ok' : 'off' ?>">
                                <?= $isActive ? 'activ' : 'inactiv' ?>
                            </span>
                        </td>
                        <td><?= (int) ($author['posts_count'] ?? 0) ?></td>
                        <td style="display:flex;gap:8px;">
                            <a class="icon-btn" href="/admin/blog/authors?author=<?= $id ?>" title="Editează">✎</a>
                            <form method="post" action="/admin/blog/authors" onsubmit="return confirm('Ștergi autorul?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button type="submit" class="icon-btn danger" title="Șterge">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="modal-overlay<?= $modalOpen ? ' open' : '' ?>" id="blog-author-modal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><?= is_array($editingAuthor) ? 'Editează autor' : 'Autor nou' ?></h3>
            <a class="icon-btn" href="/admin/blog/authors">✕</a>
        </div>
        <form method="post" action="/admin/blog/authors" class="form-grid">
            <input type="hidden" name="action" value="save">
            <?php if (is_array($editingAuthor)): ?>
                <input type="hidden" name="id" value="<?= (int) ($editingAuthor['id'] ?? 0) ?>">
            <?php endif; ?>
            <div class="field">
                <label>Nume autor *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars((string) ($editingAuthor['name'] ?? ''), ENT_QUOTES) ?>">
            </div>
            <div class="field">
                <label>Slug</label>
                <input type="text" name="slug" value="<?= htmlspecialchars((string) ($editingAuthor['slug'] ?? ''), ENT_QUOTES) ?>" placeholder="lasă gol pentru auto">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Bio (scurt)</label>
                <textarea name="bio" rows="4"><?= htmlspecialchars((string) ($editingAuthor['bio'] ?? ''), ENT_QUOTES) ?></textarea>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Avatar autor</label>
                <input type="hidden" name="avatar_url" id="blog-author-avatar-url" value="<?= htmlspecialchars((string) ($editingAuthor['avatar_url'] ?? ''), ENT_QUOTES) ?>">
                <?php $authorAvatarPreview = trim((string) ($editingAuthor['avatar_url'] ?? '')) ?: '/assets/img/product-placeholder.svg'; ?>
                <div class="product-image-picker-inline">
                    <img id="blog-author-avatar-preview" src="<?= htmlspecialchars($authorAvatarPreview, ENT_QUOTES) ?>" alt="Preview avatar autor" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                    <div>
                        <button class="btn btn-secondary" type="button" id="open-author-avatar-picker">Selectează imagine</button>
                        <p id="blog-author-avatar-label" class="muted" style="margin:8px 0 0;font-size:12px;"><?= htmlspecialchars($authorAvatarPreview, ENT_QUOTES) ?></p>
                    </div>
                </div>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="is_active" <?= ((int) ($editingAuthor['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                    Activ
                </label>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                <a class="btn btn-secondary" href="/admin/blog/authors">Anulează</a>
                <button class="btn" type="submit">Salvează autor</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="blog-author-avatar-modal">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-head">
            <h3>Selectează imagine din Galerie</h3>
            <button type="button" class="icon-btn" id="close-author-avatar-picker" aria-label="Închide">✕</button>
        </div>
        <div class="field" style="margin-bottom:10px;">
            <input type="text" id="blog-author-avatar-search" placeholder="Caută imagine după titlu...">
        </div>
        <div class="product-picker-grid" id="blog-author-avatar-grid">
            <?php foreach ($galleryImages as $image): ?>
                <button
                    type="button"
                    class="product-picker-item blog-author-avatar-item"
                    data-image-url="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>"
                    data-search="<?= htmlspecialchars(strtolower((string) (($image['title'] ?? '') . ' ' . ($image['alt_text'] ?? '') . ' ' . ($image['image_url'] ?? ''))), ENT_QUOTES) ?>"
                >
                    <img src="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) ($image['alt_text'] ?? $image['title'] ?? ''), ENT_QUOTES) ?>" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                    <strong><?= htmlspecialchars((string) (($image['title'] ?? '') !== '' ? $image['title'] : 'Imagine fără titlu'), ENT_QUOTES) ?></strong>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
(() => {
    const addBtn = document.getElementById('add-author-btn');
    if (!(addBtn instanceof HTMLButtonElement)) return;
    addBtn.addEventListener('click', () => {
        window.location.href = '/admin/blog/authors?author=new';
    });

    const avatarModal = document.getElementById('blog-author-avatar-modal');
    const openAvatarBtn = document.getElementById('open-author-avatar-picker');
    const closeAvatarBtn = document.getElementById('close-author-avatar-picker');
    const avatarSearch = document.getElementById('blog-author-avatar-search');
    const avatarItems = Array.from(document.querySelectorAll('.blog-author-avatar-item'));
    const avatarInput = document.getElementById('blog-author-avatar-url');
    const avatarPreview = document.getElementById('blog-author-avatar-preview');
    const avatarLabel = document.getElementById('blog-author-avatar-label');

    const openAvatarModal = () => avatarModal?.classList.add('open');
    const closeAvatarModal = () => avatarModal?.classList.remove('open');
    const setAvatar = (url) => {
        const safeUrl = (url || '').trim() || '/assets/img/product-placeholder.svg';
        if (avatarInput) avatarInput.value = safeUrl === '/assets/img/product-placeholder.svg' ? '' : safeUrl;
        if (avatarPreview) avatarPreview.src = safeUrl;
        if (avatarLabel) avatarLabel.textContent = safeUrl;
    };

    openAvatarBtn?.addEventListener('click', openAvatarModal);
    closeAvatarBtn?.addEventListener('click', closeAvatarModal);
    avatarModal?.addEventListener('click', (event) => {
        if (event.target === avatarModal) closeAvatarModal();
    });
    avatarSearch?.addEventListener('input', () => {
        const query = (avatarSearch.value || '').trim().toLowerCase();
        avatarItems.forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            item.style.display = haystack.includes(query) ? '' : 'none';
        });
    });
    avatarItems.forEach((item) => {
        item.addEventListener('click', () => {
            setAvatar(item.getAttribute('data-image-url') || '');
            closeAvatarModal();
        });
    });
})();
</script>
