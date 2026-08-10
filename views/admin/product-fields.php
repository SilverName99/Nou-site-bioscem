<?php
$fields = is_array($fields ?? null) ? $fields : [];
$editingField = is_array($editingField ?? null) ? $editingField : null;
$selectedFieldId = (int) ($selectedFieldId ?? 0);
$newRequested = (bool) ($newRequested ?? false);
$modalOpen = $selectedFieldId > 0 || $newRequested;
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Câmpuri suplimentare</h1>
            <p>Definește câmpuri extra pentru produse și codurile lor de embed.</p>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn" type="button" id="add-field-btn">+ Câmp nou</button>
        </div>
    </div>

    <div class="users-table-wrap">
        <table class="table users-table">
            <thead>
            <tr>
                <th>Nume</th>
                <th>Cod embed</th>
                <th>Tip</th>
                <th>Status</th>
                <th style="width:120px;">Acțiuni</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($fields === []): ?>
                <tr><td colspan="5">Nu există câmpuri suplimentare încă.</td></tr>
            <?php else: ?>
                <?php foreach ($fields as $field): ?>
                    <?php
                    $id = (int) ($field['id'] ?? 0);
                    $isActive = (int) ($field['is_active'] ?? 1) === 1;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($field['name'] ?? ''), ENT_QUOTES) ?></td>
                        <td><code>{{field_<?= htmlspecialchars((string) ($field['field_key'] ?? ''), ENT_QUOTES) ?>}}</code></td>
                        <td><?= htmlspecialchars((string) ($field['field_type'] ?? 'text'), ENT_QUOTES) ?></td>
                        <td>
                            <span class="status-pill <?= $isActive ? 'ok' : 'off' ?>">
                                <?= $isActive ? 'activ' : 'inactiv' ?>
                            </span>
                        </td>
                        <td style="display:flex;gap:8px;">
                            <a class="icon-btn" href="/admin/products/fields?field=<?= $id ?>" title="Editează">✎</a>
                            <form method="post" action="/admin/products/fields" onsubmit="return confirm('Ștergi acest câmp?');">
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

<div class="modal-overlay<?= $modalOpen ? ' open' : '' ?>" id="field-modal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><?= is_array($editingField) ? 'Editează câmp' : 'Adaugă câmp' ?></h3>
            <a class="icon-btn" href="/admin/products/fields">✕</a>
        </div>
        <form method="post" action="/admin/products/fields" class="form-grid">
            <input type="hidden" name="action" value="save">
            <?php if (is_array($editingField)): ?>
                <input type="hidden" name="id" value="<?= (int) ($editingField['id'] ?? 0) ?>">
            <?php endif; ?>
            <div class="field">
                <label>Nume câmp *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars((string) ($editingField['name'] ?? ''), ENT_QUOTES) ?>" placeholder="Ex: Mod de utilizare">
            </div>
            <div class="field">
                <label>Cod câmp *</label>
                <input type="text" name="field_key" required value="<?= htmlspecialchars((string) ($editingField['field_key'] ?? ''), ENT_QUOTES) ?>" placeholder="mod_de_utilizare">
                <small style="color:#64748b;">Doar litere mici, cifre și underscore.</small>
            </div>
            <div class="field">
                <label>Tip</label>
                <?php $type = (string) ($editingField['field_type'] ?? 'text'); ?>
                <select name="field_type">
                    <option value="text" <?= $type === 'text' ? 'selected' : '' ?>>Text simplu</option>
                    <option value="textarea" <?= $type === 'textarea' ? 'selected' : '' ?>>Text lung</option>
                    <option value="html" <?= $type === 'html' ? 'selected' : '' ?>>HTML</option>
                </select>
            </div>
            <div class="field">
                <label>Placeholder (opțional)</label>
                <input type="text" name="placeholder" value="<?= htmlspecialchars((string) ($editingField['placeholder'] ?? ''), ENT_QUOTES) ?>" placeholder="Ex: Se administrează zilnic...">
            </div>
            <div class="field">
                <label>Valoare implicită (opțional)</label>
                <input type="text" name="default_value" value="<?= htmlspecialchars((string) ($editingField['default_value'] ?? ''), ENT_QUOTES) ?>" placeholder="Valoare prestabilită">
            </div>
            <div class="field">
                <label>Ordine afișare</label>
                <input type="number" name="sort_order" value="<?= (int) ($editingField['sort_order'] ?? 0) ?>">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="is_required" <?= (int) ($editingField['is_required'] ?? 0) === 1 ? 'checked' : '' ?>>
                    Obligatoriu la completare produs
                </label>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="is_active" <?= ((int) ($editingField['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                    Activ
                </label>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                <a class="btn btn-secondary" href="/admin/products/fields">Anulează</a>
                <button class="btn" type="submit">Salvează câmp</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const addBtn = document.getElementById('add-field-btn');
    const modal = document.getElementById('field-modal');
    if (!(addBtn instanceof HTMLButtonElement) || !(modal instanceof HTMLElement)) return;
    addBtn.addEventListener('click', () => {
        window.location.href = '/admin/products/fields?field=new';
    });
})();
</script>
