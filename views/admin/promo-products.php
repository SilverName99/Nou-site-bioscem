<?php
$items = is_array($items ?? null) ? $items : [];
$editing = is_array($editing ?? null) ? $editing : null;
$report = is_array($report ?? null) ? $report : [];
$clientQuery = (string) ($clientQuery ?? '');
$clientResults = is_array($clientResults ?? null) ? $clientResults : [];
$from = (string) ($from ?? '');
$to = (string) ($to ?? '');
$editId = (int) ($editing['id'] ?? 0);
$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
$fmtDate = static function (string $v): string {
    $v = trim($v);
    if ($v === '') {
        return '-';
    }
    $ts = strtotime($v);
    return $ts !== false ? date('d.m.Y H:i', $ts) : $v;
};
?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1>Produse promoționale</h1>
            <p>Nomenclator intern pentru ce mai adaugi la comenzi (scrisoare, mostră, pix etc.). Le selectezi apoi în detaliile fiecărei comenzi.</p>
        </div>
    </div>

    <!-- Promoționale per client -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin:0 0 22px;">
        <h3 style="margin:0 0 4px;">Promoționale per client</h3>
        <p style="margin:0 0 12px;color:#6b7280;font-size:13px;">Caută un client și vezi ce produse promoționale a primit la comenzile lui.</p>
        <form id="promo-client-form" style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
            <input type="text" id="promo-client-input" value="<?= $esc($clientQuery) ?>" placeholder="Nume client, email sau nr. comandă..." style="flex:1;min-width:240px;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;">
            <button class="btn" type="submit">Caută</button>
            <button class="btn btn-secondary" type="button" id="promo-client-reset">Reset</button>
        </form>
        <div id="promo-client-results">
            <p style="color:#6b7280;font-style:italic;">Scrie numele unui client ca să vezi ce a primit.</p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:22px;align-items:start;">
        <!-- Formular add/edit -->
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;">
            <h3 style="margin:0 0 14px;"><?= $editId > 0 ? 'Editează produs' : 'Adaugă produs' ?></h3>
            <form method="post" action="/admin/promo-products" class="form-grid" style="grid-template-columns:1fr;">
                <input type="hidden" name="id" value="<?= $editId ?>">
                <div class="field">
                    <label>Denumire *</label>
                    <input type="text" name="name" required maxlength="190" value="<?= $esc((string) ($editing['name'] ?? '')) ?>" placeholder="ex: Pix, Scrisoare de mulțumire, Mostră X">
                </div>
                <div class="field">
                    <label>Notă (opțional)</label>
                    <input type="text" name="notes" maxlength="500" value="<?= $esc((string) ($editing['notes'] ?? '')) ?>" placeholder="ex: doar la comenzi peste 200 lei">
                </div>
                <div class="field">
                    <label>Ordine afișare</label>
                    <input type="number" step="1" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>">
                </div>
                <div class="field">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_active" value="1" <?= ((int) ($editing['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                        Activ (apare la selecția din comenzi)
                    </label>
                </div>
                <div style="display:flex;gap:8px;">
                    <button class="btn" type="submit"><?= $editId > 0 ? 'Salvează' : 'Adaugă' ?></button>
                    <?php if ($editId > 0): ?>
                        <a class="btn btn-secondary" href="/admin/promo-products">Anulează</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Listă -->
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:6px 4px;overflow-x:auto;">
            <?php if ($items === []): ?>
                <p style="color:#6b7280;font-style:italic;padding:16px;">Niciun produs promoțional încă. Adaugă unul în stânga.</p>
            <?php else: ?>
                <table style="width:100%;border-collapse:collapse;font-size:14px;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:10px 12px;color:#6b7280;font-size:12px;text-transform:uppercase;">Denumire</th>
                            <th style="text-align:left;padding:10px 12px;color:#6b7280;font-size:12px;text-transform:uppercase;">Notă</th>
                            <th style="text-align:left;padding:10px 12px;color:#6b7280;font-size:12px;text-transform:uppercase;">Status</th>
                            <th style="padding:10px 12px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <?php $iid = (int) ($it['id'] ?? 0); $active = ((int) ($it['is_active'] ?? 0)) === 1; ?>
                        <tr>
                            <td style="padding:10px 12px;border-top:1px solid #eee;font-weight:600;"><?= $esc((string) ($it['name'] ?? '')) ?></td>
                            <td style="padding:10px 12px;border-top:1px solid #eee;color:#6b7280;"><?= $esc((string) ($it['notes'] ?? '')) ?: '—' ?></td>
                            <td style="padding:10px 12px;border-top:1px solid #eee;">
                                <span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;<?= $active ? 'background:#dcfce7;color:#166534;' : 'background:#f3f4f6;color:#6b7280;' ?>"><?= $active ? 'Activ' : 'Inactiv' ?></span>
                            </td>
                            <td style="padding:10px 12px;border-top:1px solid #eee;white-space:nowrap;text-align:right;">
                                <button type="button" class="btn btn-secondary btn-sm" data-promo-recipients="<?= $iid ?>" style="padding:6px 12px;font-size:13px;">👥 Cui a fost dat</button>
                                <a class="btn btn-secondary btn-sm" href="/admin/promo-products?edit=<?= $iid ?>" style="padding:6px 12px;font-size:13px;">Editează</a>
                                <form method="post" action="/admin/promo-products/<?= $iid ?>/delete" style="display:inline" onsubmit="return confirm('Ștergi acest produs promoțional?');">
                                    <button class="btn btn-secondary btn-sm" type="submit" style="padding:6px 12px;font-size:13px;">Șterge</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Raport totaluri -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin-top:22px;">
        <h3 style="margin:0 0 12px;">Raport — total adăugat pe produs</h3>
        <form method="get" action="/admin/promo-products" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;">
            <div class="field" style="margin:0;">
                <label style="font-size:12px;">De la</label>
                <input type="date" name="from" value="<?= $esc($from) ?>">
            </div>
            <div class="field" style="margin:0;">
                <label style="font-size:12px;">Până la</label>
                <input type="date" name="to" value="<?= $esc($to) ?>">
            </div>
            <button class="btn btn-secondary" type="submit">Filtrează</button>
            <?php if ($from !== '' || $to !== ''): ?>
                <a class="btn btn-secondary" href="/admin/promo-products">Reset</a>
            <?php endif; ?>
        </form>

        <?php if ($report === []): ?>
            <p style="color:#6b7280;font-style:italic;">Niciun produs promoțional adăugat la comenzi în perioada selectată.</p>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:8px 12px;color:#6b7280;font-size:12px;text-transform:uppercase;">Produs</th>
                        <th style="text-align:right;padding:8px 12px;color:#6b7280;font-size:12px;text-transform:uppercase;">Total bucăți</th>
                        <th style="text-align:right;padding:8px 12px;color:#6b7280;font-size:12px;text-transform:uppercase;">Comenzi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($report as $r): ?>
                    <tr>
                        <td style="padding:9px 12px;border-top:1px solid #eee;font-weight:600;"><?= $esc((string) ($r['name'] ?? '')) ?></td>
                        <td style="padding:9px 12px;border-top:1px solid #eee;text-align:right;font-weight:800;"><?= (int) ($r['total'] ?? 0) ?></td>
                        <td style="padding:9px 12px;border-top:1px solid #eee;text-align:right;color:#6b7280;"><?= (int) ($r['orders_count'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

<!-- Popup detalii promoționale per comandă -->
<div id="promo-detail-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;max-width:480px;width:100%;padding:22px 24px;box-shadow:0 24px 64px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <h3 id="promo-detail-title" style="margin:0;">Detalii</h3>
            <button type="button" id="promo-detail-close" style="border:0;background:none;font-size:22px;line-height:1;cursor:pointer;color:#6b7280;">&times;</button>
        </div>
        <div id="promo-detail-body"></div>
        <div style="margin-top:18px;text-align:right;">
            <a id="promo-detail-order-link" href="#" class="btn btn-secondary">Vezi comanda →</a>
        </div>
    </div>
</div>

<script>
(function(){
    var modal = document.getElementById('promo-detail-modal');
    var esc = function(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };
    function closeModal(){ if (modal) modal.style.display = 'none'; }

    // Popup detalii (deschis prin delegare, ca să meargă și pe rândurile generate dinamic)
    document.addEventListener('click', function(ev){
        var b = ev.target.closest ? ev.target.closest('[data-promo-detail]') : null;
        if (!b || !modal) { return; }
        var d; try { d = JSON.parse(b.getAttribute('data-promo-detail') || '{}'); } catch(e){ return; }
        document.getElementById('promo-detail-title').textContent = 'Comanda ' + (d.order_number || '');
        var items = Array.isArray(d.items) ? d.items : [];
        var list = items.length
            ? items.map(function(i){ return '<li>' + esc(i.name) + ' × <strong>' + (Number(i.quantity)||1) + '</strong></li>'; }).join('')
            : '<li>-</li>';
        document.getElementById('promo-detail-body').innerHTML =
            '<p style="margin:0 0 4px;"><strong>' + esc(d.client_name || '') + '</strong>' + (d.email ? ' · ' + esc(d.email) : '') + '</p>' +
            '<p style="margin:0 0 12px;color:#6b7280;font-size:13px;">' + esc(d.created_at || '') + '</p>' +
            '<p style="margin:0 0 6px;font-weight:600;">Produse promoționale primite:</p>' +
            '<ul style="margin:0;padding-left:20px;line-height:1.7;">' + list + '</ul>';
        var link = document.getElementById('promo-detail-order-link');
        if (link) { link.style.display = ''; link.href = '/admin/orders?q=' + encodeURIComponent(d.order_number || ''); }
        modal.style.display = 'flex';
    });

    // „Cui a fost dat" — recipienții unui produs promoțional (reutilizează același popup)
    document.querySelectorAll('[data-promo-recipients]').forEach(function(btn){
        btn.addEventListener('click', function(){
            if (!modal) { return; }
            var id = btn.getAttribute('data-promo-recipients');
            document.getElementById('promo-detail-title').textContent = 'Cui a fost dat';
            document.getElementById('promo-detail-body').innerHTML = '<p style="color:#6b7280;">Se încarcă...</p>';
            var link = document.getElementById('promo-detail-order-link');
            if (link) { link.style.display = 'none'; }
            modal.style.display = 'flex';
            fetch('/admin/promo-products/' + id + '/recipients')
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d.ok) { document.getElementById('promo-detail-body').innerHTML = '<p style="color:#dc2626;">' + esc(d.error || 'Eroare.') + '</p>'; return; }
                    document.getElementById('promo-detail-title').textContent = 'Cui a fost dat: ' + (d.name || '');
                    var recs = Array.isArray(d.recipients) ? d.recipients : [];
                    if (recs.length === 0) {
                        document.getElementById('promo-detail-body').innerHTML = '<p style="color:#6b7280;font-style:italic;">Nu a fost dat încă la nicio comandă.</p>';
                        return;
                    }
                    var rows = recs.map(function(x){
                        return '<div style="border-top:1px solid #eee;padding:8px 0;font-size:13px;">' +
                            '<strong>' + esc(x.client_name) + '</strong>' + (x.email ? ' · ' + esc(x.email) : '') +
                            '<br><span style="color:#94a3b8;">' + esc(x.order_number) + ' · ' + esc(x.created_at) + '</span>' +
                            ' — <strong>× ' + (Number(x.quantity)||1) + '</strong></div>';
                    }).join('');
                    document.getElementById('promo-detail-body').innerHTML =
                        '<p style="margin:0 0 10px;font-weight:700;">Total dat: ' + (Number(d.total)||0) + ' buc. la ' + recs.length + ' comenzi</p>' + rows;
                })
                .catch(function(){ document.getElementById('promo-detail-body').innerHTML = '<p style="color:#dc2626;">Eroare de comunicare.</p>'; });
        });
    });

    document.getElementById('promo-detail-close')?.addEventListener('click', closeModal);
    if (modal) { modal.addEventListener('click', function(e){ if (e.target === modal) { closeModal(); } }); }
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { closeModal(); } });

    // Căutare per client — AJAX, fără reload
    var form = document.getElementById('promo-client-form');
    var input = document.getElementById('promo-client-input');
    var resultsBox = document.getElementById('promo-client-results');
    var resetBtn = document.getElementById('promo-client-reset');

    function renderResults(results){
        if (!resultsBox) { return; }
        if (!results || results.length === 0) {
            resultsBox.innerHTML = '<p style="color:#6b7280;font-style:italic;">Niciun rezultat.</p>';
            return;
        }
        var rows = results.map(function(r){
            var items = Array.isArray(r.items) ? r.items : [];
            var text = items.map(function(i){ return esc(i.name) + ' × ' + (Number(i.quantity)||1); }).join(', ');
            var data = esc(JSON.stringify(r));
            return '<tr>' +
                '<td style="padding:9px 12px;border-top:1px solid #eee;font-weight:600;">' + esc(r.order_number) + '</td>' +
                '<td style="padding:9px 12px;border-top:1px solid #eee;color:#6b7280;white-space:nowrap;">' + esc(r.created_at) + '</td>' +
                '<td style="padding:9px 12px;border-top:1px solid #eee;">' + esc(r.client_name) + '<br><small style="color:#94a3b8;">' + esc(r.email) + '</small></td>' +
                '<td style="padding:9px 12px;border-top:1px solid #eee;">' + text + '</td>' +
                '<td style="padding:9px 12px;border-top:1px solid #eee;white-space:nowrap;text-align:right;"><button type="button" class="btn btn-secondary btn-sm" data-promo-detail="' + data + '" style="padding:6px 12px;font-size:13px;">👁 Detalii</button></td>' +
                '</tr>';
        }).join('');
        resultsBox.innerHTML =
            '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:14px;"><thead><tr>' +
            '<th style="text-align:left;padding:8px 12px;color:#6b7280;font-size:12px;text-transform:uppercase;">Comandă</th>' +
            '<th style="text-align:left;padding:8px 12px;color:#6b7280;font-size:12px;text-transform:uppercase;">Data</th>' +
            '<th style="text-align:left;padding:8px 12px;color:#6b7280;font-size:12px;text-transform:uppercase;">Client</th>' +
            '<th style="text-align:left;padding:8px 12px;color:#6b7280;font-size:12px;text-transform:uppercase;">Produse promoționale</th>' +
            '<th style="padding:8px 12px;"></th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    function doSearch(){
        var q = input ? input.value.trim() : '';
        if (q === '') { if (resultsBox) resultsBox.innerHTML = '<p style="color:#6b7280;font-style:italic;">Scrie numele unui client ca să vezi ce a primit.</p>'; return; }
        if (resultsBox) resultsBox.innerHTML = '<p style="color:#6b7280;">Se caută...</p>';
        fetch('/admin/promo-products/search?client=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.ok) { if (resultsBox) resultsBox.innerHTML = '<p style="color:#dc2626;">' + esc(data.error || 'Eroare la căutare.') + '</p>'; return; }
                renderResults(data.results || []);
            })
            .catch(function(){ if (resultsBox) resultsBox.innerHTML = '<p style="color:#dc2626;">Eroare de comunicare.</p>'; });
    }

    if (form) { form.addEventListener('submit', function(e){ e.preventDefault(); doSearch(); }); }
    if (resetBtn) { resetBtn.addEventListener('click', function(){ if (input) input.value = ''; if (resultsBox) resultsBox.innerHTML = '<p style="color:#6b7280;font-style:italic;">Scrie numele unui client ca să vezi ce a primit.</p>'; }); }
    if (input && input.value.trim() !== '') { doSearch(); }
})();
</script>
