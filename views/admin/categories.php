<section class="panel">
    <div class="section-head">
        <div>
            <h1>Categorii produse</h1>
            <p>Creează și administrează categoriile din magazin.</p>
        </div>
    </div>

    <form method="post" action="/admin/categories" class="form-grid" style="margin-bottom:14px;">
        <div class="field">
            <label>Nume categorie *</label>
            <input type="text" name="name" required>
        </div>
        <div class="field">
            <label>Slug (opțional)</label>
            <input type="text" name="slug" placeholder="ex: suplimente">
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
            <th>Produse</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $category): ?>
            <?php $updateFormId = 'category-update-' . (int) $category['id']; ?>
            <tr>
                <td><?= (int) $category['id'] ?></td>
                <td>
                    <input
                        type="text"
                        name="name"
                        value="<?= htmlspecialchars((string) $category['name'], ENT_QUOTES) ?>"
                        form="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>"
                        required
                        style="min-width:220px;"
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
                <td><?= (int) $category['products_count'] ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <form id="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>" method="post" action="/admin/categories/<?= (int) $category['id'] ?>/update">
                            <button class="btn btn-secondary" type="submit" title="Salvează modificările">Salvează</button>
                        </form>
                        <form method="post" action="/admin/categories/<?= (int) $category['id'] ?>/delete" onsubmit="return confirm('Ștergi categoria? Produsele vor rămâne fără categorie.');">
                            <button class="icon-btn danger" type="submit" title="Șterge categorie">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
