<?php
$coupons = is_array($coupons ?? null) ? $coupons : [];
$products = is_array($products ?? null) ? $products : [];
$categories = is_array($categories ?? null) ? $categories : [];
$users = is_array($users ?? null) ? $users : [];
$view = ($view ?? 'active') === 'used' ? 'used' : 'active';
$q = (string) ($q ?? '');
$batch = (string) ($batch ?? '');
$batches = is_array($batches ?? null) ? $batches : [];
$page = max(1, (int) ($page ?? 1));
$perPage = (int) ($perPage ?? 50);
if (!in_array($perPage, [50, 100, 250, 500], true)) {
    $perPage = 50;
}
$totalFiltered = max(0, (int) ($totalFiltered ?? 0));
$activeCount = max(0, (int) ($activeCount ?? 0));
$usedCount = max(0, (int) ($usedCount ?? 0));
$totalPages = (int) max(1, (int) ceil($totalFiltered / $perPage));

$pageUrl = static function (int $p) use ($view, $q, $batch, $perPage): string {
    $params = ['view' => $view, 'p' => $p];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($batch !== '') {
        $params['batch'] = $batch;
    }
    if ($perPage !== 50) {
        $params['per'] = $perPage;
    }
    return '/admin/coupons/unique?' . http_build_query($params);
};
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Cupoane unice</h1>
            <p>Importă loturi de coduri unice (single-use). Când un cupon e folosit într-o comandă, trece automat în „Cupoane folosite", de unde îl poți reactiva pentru teste.</p>
        </div>
    </div>

    <div class="vuc-tabs">
        <a href="/admin/coupons/unique?view=active" class="vuc-tab <?= $view === 'active' ? 'is-active' : '' ?>">
            Cupoane unice <span class="vuc-badge"><?= $activeCount ?></span>
        </a>
        <a href="/admin/coupons/unique?view=used" class="vuc-tab <?= $view === 'used' ? 'is-active' : '' ?>">
            Cupoane folosite <span class="vuc-badge"><?= $usedCount ?></span>
        </a>
    </div>

    <?php if ($view === 'active'): ?>
        <details class="vuc-import" <?= $activeCount === 0 ? 'open' : '' ?>>
            <summary>➕ Importă cupoane (fișier .xlsx / .csv / .txt sau lipește codurile)</summary>
            <form method="post" action="/admin/coupons/unique/import" enctype="multipart/form-data" class="vuc-import-form">
                <div class="vuc-grid">
                    <div class="field">
                        <label>Fișier cu coduri (.xlsx, .csv, .txt)</label>
                        <input type="file" name="codes_file" accept=".xlsx,.csv,.txt">
                        <small>O coloană / un cod pe linie. Poți combina cu lipirea de mai jos.</small>
                    </div>
                    <div class="field">
                        <label>Etichetă lot (opțional)</label>
                        <input type="text" name="batch_label" placeholder="ex: Serii PMC iulie">
                    </div>
                    <div class="field vuc-span-full">
                        <label>Sau lipește codurile aici (opțional)</label>
                        <textarea name="codes" rows="3" placeholder="PMC832301, PMC832302, ..."></textarea>
                    </div>
                </div>

                <h4 class="vuc-sub">Setări comune (se aplică tuturor codurilor importate)</h4>
                <div class="vuc-grid">
                    <div class="field">
                        <label>Prefix denumire (opțional)</label>
                        <input type="text" name="name_prefix" placeholder="ex: Voucher">
                        <small>Denumirea devine „Prefix COD". Gol = doar codul.</small>
                    </div>
                    <div class="field">
                        <label>Tip reducere</label>
                        <select name="type">
                            <option value="fixed">Valoare fixă</option>
                            <option value="percent">Procentuală</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Valoare reducere * (lei / %)</label>
                        <input type="number" step="0.01" min="0.01" name="value" required placeholder="ex: 15.00">
                    </div>
                    <div class="field">
                        <label>Valabil de la</label>
                        <input type="datetime-local" name="starts_at">
                    </div>
                    <div class="field">
                        <label>Valabil până la</label>
                        <input type="datetime-local" name="ends_at">
                    </div>
                    <div class="field">
                        <label>Minim produse în coș</label>
                        <input type="number" min="0" step="1" name="min_items_count">
                    </div>
                    <div class="field">
                        <label>Maxim produse în coș</label>
                        <input type="number" min="0" step="1" name="max_items_count">
                    </div>
                </div>

                <div class="vuc-grid">
                    <div class="field vuc-span-full">
                        <label>Doar pentru produse (opțional)</label>
                        <div class="coupon-admin-checklist">
                            <?php foreach ($products as $product): ?>
                                <label class="coupon-admin-checkline">
                                    <input type="checkbox" name="product_ids[]" value="<?= (int) ($product['id'] ?? 0) ?>">
                                    <span><?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label class="coupon-admin-active-check">
                            <input type="checkbox" name="apply_only_selected_products" value="1">
                            <span>Se aplică doar la produsele selectate, dar merge și cu alte produse în coș.</span>
                        </label>
                    </div>
                    <div class="field vuc-span-full">
                        <label>Doar pentru categorii (opțional)</label>
                        <div class="coupon-admin-checklist">
                            <?php foreach ($categories as $category): ?>
                                <label class="coupon-admin-checkline">
                                    <input type="checkbox" name="category_ids[]" value="<?= (int) ($category['id'] ?? 0) ?>">
                                    <span><?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <label class="coupon-admin-active-check">
                    <input type="checkbox" name="stacks_with_discounts" value="1" checked>
                    <span>Se cumulează cu alte reduceri / promoții</span>
                </label>
                <small style="display:block;margin:-4px 0 10px;color:#6b7280;">Debifat: cupoanele reduc doar produsele la preț întreg (produsele deja la promoție sunt ignorate).</small>

                <label class="coupon-admin-active-check">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>Cupoane active la import</span>
                </label>

                <div class="vuc-import-actions">
                    <button class="btn" type="submit">Importă cupoanele</button>
                </div>
            </form>
        </details>
    <?php endif; ?>

    <form method="get" action="/admin/coupons/unique" class="vuc-search">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES) ?>">
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Caută după cod sau denumire...">
        <select name="batch" class="vuc-batch-select">
            <option value="">Toate loturile</option>
            <?php foreach ($batches as $b): ?>
                <option value="<?= htmlspecialchars((string) $b, ENT_QUOTES) ?>" <?= $batch === (string) $b ? 'selected' : '' ?>><?= htmlspecialchars((string) $b, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="per" class="vuc-batch-select" title="Cupoane pe pagină" style="min-width:120px;">
            <?php foreach ([50, 100, 250, 500] as $pp): ?>
                <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?> / pagină</option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-secondary" type="submit">Filtrează</button>
        <?php if ($q !== '' || $batch !== '' || $perPage !== 50): ?>
            <a class="btn btn-secondary" href="/admin/coupons/unique?view=<?= htmlspecialchars($view, ENT_QUOTES) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <?php if ($view === 'used' && $usedCount > 0): ?>
        <div class="vuc-bulk">
            <form method="post" action="/admin/coupons/unique/action" onsubmit="return confirm('Reactivezi TOATE cupoanele folosite?');">
                <input type="hidden" name="action" value="reactivate_all">
                <button class="btn btn-secondary" type="submit">↺ Reactivează toate</button>
            </form>
            <form method="post" action="/admin/coupons/unique/action" onsubmit="return confirm('Ștergi TOATE cupoanele folosite? Ireversibil.');">
                <input type="hidden" name="action" value="delete_all_used">
                <button class="btn btn-secondary" type="submit">🗑 Șterge toate folosite</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($coupons === []): ?>
        <p class="vuc-empty">
            <?= $view === 'used'
                ? 'Niciun cupon unic folosit încă.'
                : ($q !== '' ? 'Niciun cupon unic pentru căutarea introdusă.' : 'Niciun cupon unic. Importă un lot mai sus.') ?>
        </p>
    <?php else: ?>
        <!-- Formular gol pentru acțiunile în masă (bifele din tabel se leagă prin form="vuc-bulk") -->
        <form id="vuc-bulk" method="post" action="/admin/coupons/unique/action">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES) ?>">
            <input type="hidden" name="batch" value="<?= htmlspecialchars($batch, ENT_QUOTES) ?>">
            <input type="hidden" name="scope" id="vuc-scope" value="selected">
        </form>

        <div class="vuc-bulkbar">
            <span class="vuc-selinfo"><strong id="vuc-selcount">0</strong> selectate</span>
            <button class="btn btn-secondary btn-sm" type="button" id="vuc-edit-toggle">✎ Editează</button>
            <?php if ($view === 'used'): ?>
                <button class="btn btn-secondary btn-sm" form="vuc-bulk" type="submit" name="action" value="bulk_reactivate" onclick="return vucBulkGuard('Reactivezi cupoanele selectate?')">↺ Reactivează selectate</button>
            <?php endif; ?>
            <button class="btn btn-secondary btn-sm" form="vuc-bulk" type="submit" name="action" value="bulk_delete" onclick="return vucBulkGuard('Ștergi cupoanele selectate? Ireversibil.')">🗑 Șterge selectate</button>
        </div>

        <?php if ($batch !== ''): ?>
            <div class="vuc-batchbar">
                <?php if ($view === 'used'): ?>
                    <button class="btn btn-secondary btn-sm" form="vuc-bulk" type="submit" name="action" value="bulk_reactivate" onclick="return vucBatchGuard('Reactivezi TOATE cupoanele din acest lot?')">↺ Reactivează tot lotul</button>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm" form="vuc-bulk" type="submit" name="action" value="bulk_delete" onclick="return vucBatchGuard('Ștergi TOATE cupoanele din acest lot? Ireversibil.')">🗑 Șterge tot lotul</button>
            </div>
        <?php endif; ?>

        <div class="vuc-editpanel" id="vuc-editpanel" hidden>
            <p class="vuc-editpanel__hint">Bifează doar câmpurile pe care vrei să le schimbi. Se aplică cupoanelor selectate.</p>
            <div class="vuc-editgrid">
                <div class="vuc-editrow">
                    <label class="vuc-editchk"><input type="checkbox" name="edit_value" value="1" form="vuc-bulk"> Valoare + tip</label>
                    <select name="type" form="vuc-bulk"><option value="fixed">Valoare fixă (lei)</option><option value="percent">Procentuală (%)</option></select>
                    <input type="number" step="0.01" min="0.01" name="value" placeholder="ex: 15.00" form="vuc-bulk">
                </div>
                <div class="vuc-editrow">
                    <label class="vuc-editchk"><input type="checkbox" name="edit_period" value="1" form="vuc-bulk"> Perioadă</label>
                    <label class="vuc-editsub">De la<input type="datetime-local" name="starts_at" form="vuc-bulk"></label>
                    <label class="vuc-editsub">Până la<input type="datetime-local" name="ends_at" form="vuc-bulk"></label>
                </div>
                <div class="vuc-editrow">
                    <label class="vuc-editchk"><input type="checkbox" name="edit_active" value="1" form="vuc-bulk"> Status</label>
                    <select name="is_active_value" form="vuc-bulk"><option value="1">Activ</option><option value="0">Inactiv</option></select>
                </div>
                <div class="vuc-editrow">
                    <label class="vuc-editchk"><input type="checkbox" name="edit_stacks" value="1" form="vuc-bulk"> Cumulare cu reduceri</label>
                    <select name="stacks_value" form="vuc-bulk"><option value="1">Se cumulează</option><option value="0">Nu se cumulează</option></select>
                </div>
            </div>
            <div class="vuc-editpanel__actions">
                <button class="btn" form="vuc-bulk" type="submit" name="action" value="bulk_edit" onclick="return vucBulkGuard('Aplici modificările la cupoanele selectate?')">Aplică la selectate</button>
                <?php if ($batch !== ''): ?>
                    <button class="btn" form="vuc-bulk" type="submit" name="action" value="bulk_edit" onclick="return vucBatchGuard('Aplici modificările la TOATE cupoanele din lotul „<?= htmlspecialchars($batch, ENT_QUOTES) ?>”?')">Aplică la tot lotul „<?= htmlspecialchars($batch, ENT_QUOTES) ?>”</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="vuc-table-wrap">
            <table class="vuc-table">
                <thead>
                    <tr>
                        <th class="vuc-check-col"><input type="checkbox" id="vuc-selall" title="Selectează tot"></th>
                        <th>Cod</th>
                        <th>Denumire</th>
                        <th>Reducere</th>
                        <th>Status</th>
                        <th><?= $view === 'used' ? 'Folosit la' : 'Lot' ?></th>
                        <?php if ($view === 'used'): ?><th>Comandă</th><?php endif; ?>
                        <th class="vuc-actions-col">Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($coupons as $c): ?>
                    <?php
                    $cid = (int) ($c['id'] ?? 0);
                    $isActive = ((int) ($c['is_active'] ?? 0)) === 1;
                    $type = (string) ($c['type'] ?? 'fixed');
                    $value = (float) ($c['value'] ?? 0);
                    $reduce = $type === 'percent'
                        ? ('-' . rtrim(rtrim(number_format($value, 2), '0'), '.') . '%')
                        : ('-' . number_format($value, 2) . ' lei');
                    ?>
                    <tr>
                        <td class="vuc-check-col"><input type="checkbox" class="vuc-rowcheck" name="ids[]" value="<?= $cid ?>" form="vuc-bulk"></td>
                        <td><code><?= htmlspecialchars((string) ($c['code'] ?? ''), ENT_QUOTES) ?></code></td>
                        <td><?= htmlspecialchars((string) ($c['name'] ?? ''), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($reduce, ENT_QUOTES) ?></td>
                        <td>
                            <?php if ($view === 'used'): ?>
                                <span class="vuc-pill vuc-pill--used">Folosit</span>
                            <?php else: ?>
                                <span class="vuc-pill <?= $isActive ? 'vuc-pill--on' : 'vuc-pill--off' ?>"><?= $isActive ? 'Activ' : 'Inactiv' ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $view === 'used'
                                ? htmlspecialchars((string) ($c['used_at_local'] ?? ($c['used_at'] ?? '')), ENT_QUOTES)
                                : htmlspecialchars((string) ($c['import_batch'] ?? ''), ENT_QUOTES) ?>
                        </td>
                        <?php if ($view === 'used'): ?>
                            <?php $ordNo = trim((string) ($c['order_number'] ?? '')); ?>
                            <td>
                                <?php if ($ordNo !== ''): ?>
                                    <a class="btn btn-secondary btn-sm" href="/admin/orders?q=<?= rawurlencode($ordNo) ?>" title="Vezi comanda">#<?= htmlspecialchars($ordNo, ENT_QUOTES) ?></a>
                                <?php else: ?>
                                    <span class="vuc-muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="vuc-actions-col">
                            <a class="btn btn-secondary btn-sm" href="/admin/coupons?coupon=<?= $cid ?>">Editează</a>
                            <?php if ($view === 'used'): ?>
                                <form method="post" action="/admin/coupons/unique/action" style="display:inline">
                                    <input type="hidden" name="action" value="reactivate">
                                    <input type="hidden" name="id" value="<?= $cid ?>">
                                    <input type="hidden" name="view" value="used">
                                    <button class="btn btn-sm" type="submit">Reactivează</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="/admin/coupons/unique/action" style="display:inline" onsubmit="return confirm('Ștergi acest cupon?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $cid ?>">
                                <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES) ?>">
                                <button class="btn btn-secondary btn-sm" type="submit">Șterge</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="vuc-pagination">
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($pageUrl($page - 1), ENT_QUOTES) ?>">‹ Anterior</a>
                <?php endif; ?>
                <span class="vuc-page-info">Pagina <?= $page ?> din <?= $totalPages ?> (<?= $totalFiltered ?> cupoane)</span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($pageUrl($page + 1), ENT_QUOTES) ?>">Următor ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<script>
(function(){
  var selAll = document.getElementById('vuc-selall');
  var checks = Array.prototype.slice.call(document.querySelectorAll('.vuc-rowcheck'));
  var countEl = document.getElementById('vuc-selcount');
  var editToggle = document.getElementById('vuc-edit-toggle');
  var panel = document.getElementById('vuc-editpanel');
  function updateCount(){
    var n = checks.filter(function(c){return c.checked;}).length;
    if(countEl){ countEl.textContent = n; }
    if(selAll){ selAll.checked = n>0 && n===checks.length; selAll.indeterminate = n>0 && n<checks.length; }
  }
  if(selAll){ selAll.addEventListener('change', function(){ checks.forEach(function(c){ c.checked = selAll.checked; }); updateCount(); }); }
  checks.forEach(function(c){ c.addEventListener('change', updateCount); });
  if(editToggle && panel){ editToggle.addEventListener('click', function(){ panel.hidden = !panel.hidden; }); }
  var scopeEl = document.getElementById('vuc-scope');
  window.vucBulkGuard = function(msg){
    var n = checks.filter(function(c){return c.checked;}).length;
    if(n===0){ alert('Selectează cel puțin un cupon.'); return false; }
    if(scopeEl){ scopeEl.value = 'selected'; }
    return confirm(msg);
  };
  window.vucBatchGuard = function(msg){
    if(scopeEl){ scopeEl.value = 'batch'; }
    return confirm(msg);
  };
  updateCount();
})();
</script>

<style>
.vuc-tabs{display:flex;gap:10px;margin:6px 0 18px;border-bottom:1px solid #e5e7eb;}
.vuc-tab{padding:10px 16px;text-decoration:none;color:#6b7280;font-weight:600;border-bottom:2px solid transparent;display:inline-flex;align-items:center;gap:8px;}
.vuc-tab.is-active{color:#111827;border-bottom-color:#ea4968;}
.vuc-badge{background:#f3f4f6;color:#374151;border-radius:999px;padding:1px 9px;font-size:12px;font-weight:700;}
.vuc-tab.is-active .vuc-badge{background:#fdeef2;color:#ea4968;}

.vuc-import{border:1px solid #e5e7eb;border-radius:12px;padding:6px 16px;margin-bottom:18px;background:#fafafa;}
.vuc-import>summary{cursor:pointer;font-weight:700;padding:10px 0;color:#111827;}
.vuc-import-form{padding:8px 0 14px;}
.vuc-sub{margin:16px 0 8px;font-size:14px;color:#374151;}
.vuc-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:10px;}
.vuc-grid .field{display:flex;flex-direction:column;gap:5px;}
.vuc-grid .field label{font-size:13px;font-weight:600;color:#374151;}
.vuc-grid .field small{color:#9ca3af;font-size:12px;}
.vuc-span-full{grid-column:1 / -1;}
.vuc-import-actions{margin-top:14px;}

.vuc-search{display:flex;gap:8px;margin:6px 0 14px;flex-wrap:wrap;}
.vuc-search input[type=text]{flex:1;min-width:220px;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;}
.vuc-batch-select{padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;min-width:180px;}

.vuc-bulkbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0 12px;padding:10px 12px;background:#f9fafb;border:1px solid #eef0f2;border-radius:10px;}
.vuc-selinfo{color:#374151;font-size:13px;}
.vuc-batchbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 12px;padding:10px 12px;background:#fdf2f6;border:1px solid #f6d6e0;border-radius:10px;}
.vuc-batchbar__label{color:#8a2148;font-size:13px;}
.vuc-editpanel__actions{display:flex;gap:8px;flex-wrap:wrap;}
.vuc-muted{color:#9ca3af;}
.vuc-editpanel{border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin:0 0 14px;background:#fff;}
.vuc-editpanel__hint{margin:0 0 12px;font-size:13px;color:#6b7280;}
.vuc-editgrid{display:flex;flex-direction:column;gap:12px;margin-bottom:12px;}
.vuc-editrow{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.vuc-editchk{min-width:190px;font-weight:600;font-size:13.5px;color:#374151;display:inline-flex;align-items:center;gap:8px;}
.vuc-editrow select,.vuc-editrow input[type=number],.vuc-editrow input[type=datetime-local]{padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13.5px;}
.vuc-editsub{display:inline-flex;flex-direction:column;gap:3px;font-size:12px;color:#6b7280;}
.vuc-check-col{width:34px;text-align:center;}

.vuc-bulk{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;}
.vuc-bulk form{display:inline;}

.vuc-empty{color:#6b7280;font-style:italic;padding:16px 0;}

.vuc-table-wrap{overflow-x:auto;}
.vuc-table{width:100%;border-collapse:collapse;font-size:14px;}
.vuc-table th,.vuc-table td{text-align:left;padding:10px 12px;border-bottom:1px solid #eee;vertical-align:middle;}
.vuc-table th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;}
.vuc-table code{background:#f3f4f6;padding:2px 7px;border-radius:6px;font-size:13px;}
.vuc-actions-col{white-space:nowrap;display:flex;gap:6px;align-items:center;}
.btn-sm{padding:6px 12px;font-size:13px;}

.vuc-pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;}
.vuc-pill--on{background:#dcfce7;color:#166534;}
.vuc-pill--off{background:#f3f4f6;color:#6b7280;}
.vuc-pill--used{background:#fef3c7;color:#92400e;}

.vuc-pagination{display:flex;align-items:center;gap:14px;margin-top:16px;}
.vuc-page-info{color:#6b7280;font-size:13px;}

@media(max-width:720px){.vuc-grid{grid-template-columns:1fr;}}
</style>
