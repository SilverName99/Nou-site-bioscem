<?php
$categoriesSeo = is_array($categoriesSeo ?? null) ? $categoriesSeo : [];
$brands = is_array($brands ?? null) ? $brands : [];
$brandsSeo = is_array($brandsSeo ?? null) ? $brandsSeo : [];
?>
<style>
.seo-google-fields{display:grid;gap:8px;margin-top:8px;max-width:620px;}
.seo-google-fields label{font-size:12px;color:#475569;}
.seo-google-fields input,.seo-google-fields textarea{width:100%;}
.seo-google-count{font-size:12px;color:#64748b;}
.seo-google-count.is-long{color:#b45309;}
.seo-google-summary{cursor:pointer;color:#0f766e;font-size:13px;}
</style>
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

    <p class="muted" style="margin:0 0 10px;font-size:13px;">
        <strong>SEO Google</strong>: titlul și descrierea care apar în Google pentru pagina categoriei
        (/categorie/slug). Titlul: cel mult circa 60 de caractere. Descrierea: circa 120–155 de caractere.
        Gol = titlul „Nume | magazin” și o descriere făcută automat din produsele categoriei.
        Schimbarea slug-ului schimbă adresa paginii din Google; adresa veche poate ajunge la „pagina nu există”, deci cereți atunci o redirecționare.
    </p>

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
            <?php
            $updateFormId = 'category-update-' . (int) $category['id'];
            $categorySeo = $categoriesSeo[(string) (int) $category['id']] ?? [];
            $categorySeoTitle = trim((string) ($categorySeo['title'] ?? ''));
            $categorySeoDescription = trim((string) ($categorySeo['description'] ?? ''));
            ?>
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
                    <details style="margin-top:6px;"<?= ($categorySeoTitle !== '' || $categorySeoDescription !== '') ? ' open' : '' ?>>
                        <summary class="seo-google-summary">SEO Google<?= ($categorySeoTitle !== '' || $categorySeoDescription !== '') ? ' (completat)' : '' ?></summary>
                        <div class="seo-google-fields" data-seo-google>
                            <label>Titlu Google
                                <input type="text" name="seo_title" maxlength="120" form="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>"
                                       value="<?= htmlspecialchars($categorySeoTitle, ENT_QUOTES) ?>" data-seo-limit="60"
                                       placeholder="<?= htmlspecialchars((string) $category['name'], ENT_QUOTES) ?> | …">
                            </label>
                            <span class="seo-google-count" data-seo-count></span>
                            <label>Descriere Google
                                <textarea name="seo_description" rows="3" maxlength="320" form="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>"
                                          data-seo-limit="155" placeholder="Gol = descriere automată din produsele categoriei"><?= htmlspecialchars($categorySeoDescription, ENT_QUOTES) ?></textarea>
                            </label>
                            <span class="seo-google-count" data-seo-count></span>
                        </div>
                    </details>
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

<?php if ($brands !== []): ?>
<section class="panel" id="marci" style="margin-top:16px;">
    <div class="section-head">
        <div>
            <h2>Mărci — SEO Google</h2>
            <p>Titlul și descrierea din Google pentru pagina fiecărei mărci (/marca/slug). Mărcile vin din câmpul „Marcă” al produselor.
                Gol = titlul „Marcă | magazin” și o descriere făcută automat din produsele mărcii.</p>
        </div>
    </div>
    <table class="table">
        <thead>
        <tr>
            <th>Marcă</th>
            <th>Pagina</th>
            <th>Produse active</th>
            <th>SEO Google</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($brands as $brand): ?>
            <?php
            $brandSlug = (string) ($brand['slug'] ?? '');
            $brandFormId = 'brand-seo-' . $brandSlug;
            $brandSeo = $brandsSeo[$brandSlug] ?? [];
            ?>
            <tr>
                <td><?= htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES) ?></td>
                <td><a href="/marca/<?= rawurlencode($brandSlug) ?>" target="_blank" rel="noopener">/marca/<?= htmlspecialchars($brandSlug, ENT_QUOTES) ?></a></td>
                <td><?= (int) ($brand['products_count'] ?? 0) ?></td>
                <td>
                    <form id="<?= htmlspecialchars($brandFormId, ENT_QUOTES) ?>" method="post" action="/admin/categories/brand-seo" class="seo-google-fields" data-seo-google>
                        <input type="hidden" name="brand_slug" value="<?= htmlspecialchars($brandSlug, ENT_QUOTES) ?>">
                        <label>Titlu Google
                            <input type="text" name="seo_title" maxlength="120" data-seo-limit="60"
                                   value="<?= htmlspecialchars(trim((string) ($brandSeo['title'] ?? '')), ENT_QUOTES) ?>"
                                   placeholder="<?= htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES) ?> | …">
                        </label>
                        <span class="seo-google-count" data-seo-count></span>
                        <label>Descriere Google
                            <textarea name="seo_description" rows="2" maxlength="320" data-seo-limit="155"
                                      placeholder="Gol = descriere automată din produsele mărcii"><?= htmlspecialchars(trim((string) ($brandSeo['description'] ?? '')), ENT_QUOTES) ?></textarea>
                        </label>
                        <span class="seo-google-count" data-seo-count></span>
                        <div><button class="btn btn-secondary" type="submit">Salvează</button></div>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<script>
// Numărătoarea de caractere de lângă câmpurile SEO (orientativ: Google taie după lățime).
(() => {
  document.querySelectorAll('[data-seo-google]').forEach((box) => {
    box.querySelectorAll('[data-seo-limit]').forEach((field) => {
      const counter = field.closest('label')?.nextElementSibling;
      if (!counter || !counter.hasAttribute('data-seo-count')) return;
      const limit = Number(field.getAttribute('data-seo-limit')) || 0;
      const update = () => {
        const length = field.value.trim().length;
        counter.textContent = length > 0 ? length + ' caractere (recomandat: cel mult ' + limit + ')' : '';
        counter.classList.toggle('is-long', limit > 0 && length > limit);
      };
      field.addEventListener('input', update);
      update();
    });
  });
})();
</script>
