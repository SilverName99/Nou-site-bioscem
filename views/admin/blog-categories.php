<?php
$categories = is_array($categories ?? null) ? $categories : [];
?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1>Categorii blog</h1>
            <p>Zonele de conținut (ex: Noutăți, Evenimente, Info pacienți, Info medici).</p>
        </div>
    </div>

    <form method="post" action="/admin/blog/categories" class="form-grid" style="margin-bottom:14px;">
        <div class="field">
            <label>Nume categorie *</label>
            <input type="text" name="name" required>
        </div>
        <div class="field">
            <label>Slug (opțional)</label>
            <input type="text" name="slug" placeholder="ex: noutati">
        </div>
        <div class="field">
            <label>Ordine</label>
            <input type="number" name="sort_order" value="0">
        </div>
        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Adaugă categorie</button>
        </div>
    </form>

    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Nume</th>
            <th>Slug</th>
            <th>Ordine</th>
            <th>Postări</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($categories === []): ?>
            <tr><td colspan="6">Nu există categorii.</td></tr>
        <?php else: ?>
            <?php foreach ($categories as $category): ?>
                <?php $updateFormId = 'blog-category-update-' . (int) $category['id']; ?>
                <tr>
                    <td><?= (int) $category['id'] ?></td>
                    <td>
                        <input
                            type="text"
                            name="name"
                            value="<?= htmlspecialchars((string) $category['name'], ENT_QUOTES) ?>"
                            form="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>"
                            required
                            style="min-width:200px;"
                        >
                    </td>
                    <td>
                        <input
                            type="text"
                            name="slug"
                            value="<?= htmlspecialchars((string) $category['slug'], ENT_QUOTES) ?>"
                            form="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>"
                            placeholder="slug-categorie"
                        >
                    </td>
                    <td>
                        <input
                            type="number"
                            name="sort_order"
                            value="<?= (int) ($category['sort_order'] ?? 0) ?>"
                            form="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>"
                            style="width:80px;"
                        >
                    </td>
                    <td><?= (int) ($category['posts_count'] ?? 0) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <form id="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>" method="post" action="/admin/blog/categories/<?= (int) $category['id'] ?>/update">
                                <button class="btn btn-secondary" type="submit" title="Salvează modificările">Salvează</button>
                            </form>
                            <form method="post" action="/admin/blog/categories/<?= (int) $category['id'] ?>/delete" onsubmit="return confirm('Ștergi categoria? Postările vor rămâne fără categorie.');">
                                <button class="icon-btn danger" type="submit" title="Șterge categorie">🗑</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</section>
