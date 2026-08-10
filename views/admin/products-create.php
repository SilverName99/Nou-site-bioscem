<section class="panel">
    <h1>Produs nou</h1>
    <form method="post" action="/admin/products" class="form-grid">
        <div class="field">
            <label>Nume *</label>
            <input type="text" name="name" required>
        </div>
        <div class="field">
            <label>Slug *</label>
            <input type="text" name="slug" required>
        </div>
        <div class="field">
            <label>Preț *</label>
            <input type="number" step="0.01" name="price" required>
        </div>
        <div class="field">
            <label>Stoc *</label>
            <input type="number" name="stock" value="0" required>
        </div>
        <div class="field">
            <label>Categorie</label>
            <select name="category_id">
                <option value="">Fără categorie</option>
                <?php foreach (($categories ?? []) as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars((string) $category['name'], ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Descriere scurtă</label>
            <textarea name="short_description" rows="3"></textarea>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Descriere</label>
            <textarea name="description" rows="6"></textarea>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>URL imagine</label>
            <input type="text" name="image_url">
        </div>
        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Salvează produs</button>
        </div>
    </form>
</section>
