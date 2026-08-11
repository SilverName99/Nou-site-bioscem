<?php
$erpEnabled = (string) ($settings['erp_enabled'] ?? '0') === '1';
$stockEnabled = (string) ($settings['erp_stock_enabled'] ?? '0') === '1';
$apiKey = (string) ($settings['erp_api_key'] ?? '');
$queue = is_array($queue ?? null) ? $queue : ['pending' => 0, 'failed' => 0, 'sent' => 0];
?>

<section class="panel">
    <h1>ERP ANDAXI</h1>
    <p>Trimite automat comenzile din magazin către ERP și citește stocul real din gestiune.</p>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">
        <div class="panel" style="flex:1;min-width:150px;margin:0;padding:12px;">
            <div style="font-size:12px;text-transform:uppercase;color:#64748b;">Trimise</div>
            <div style="font-size:22px;font-weight:700;color:#166534;"><?= (int) $queue['sent'] ?></div>
        </div>
        <div class="panel" style="flex:1;min-width:150px;margin:0;padding:12px;">
            <div style="font-size:12px;text-transform:uppercase;color:#64748b;">În așteptare</div>
            <div style="font-size:22px;font-weight:700;color:#92400e;"><?= (int) $queue['pending'] ?></div>
        </div>
        <div class="panel" style="flex:1;min-width:150px;margin:0;padding:12px;">
            <div style="font-size:12px;text-transform:uppercase;color:#64748b;">Eșuate</div>
            <div style="font-size:22px;font-weight:700;color:#991b1b;"><?= (int) $queue['failed'] ?></div>
        </div>
    </div>

    <form method="post" action="/admin/settings/erp" class="form-grid">
        <label class="form-field" style="grid-column:1 / -1;">
            <span>
                <input type="checkbox" name="erp_enabled" value="1" <?= $erpEnabled ? 'checked' : '' ?>>
                Trimite comenzile în ERP
            </span>
            <small>Cât timp e oprită, comenzile se salvează normal pe site, dar nu pleacă spre ERP.</small>
        </label>

        <label class="form-field" style="grid-column:1 / -1;">
            <span>Adresa ERP-ului</span>
            <input type="url" name="erp_url" placeholder="https://bioscem.erp.andaxi.ro"
                   value="<?= htmlspecialchars((string) ($settings['erp_url'] ?? ''), ENT_QUOTES) ?>">
            <small>Fără „/api" la final — se adaugă automat.</small>
        </label>

        <label class="form-field" style="grid-column:1 / -1;">
            <span>Cheie ERP ANDAXI</span>
            <input type="text" name="erp_api_key" autocomplete="off" spellcheck="false"
                   placeholder="<?= $apiKey !== '' ? 'Cheie salvată — lasă gol ca să o păstrezi' : 'sk_site_...' ?>"
                   value="">
            <small>
                Se copiază din ERP: Setări → Setări site → „Cheie de integrare".
                <?php if ($apiKey !== ''): ?>
                    Cheia curentă: <code><?= htmlspecialchars(substr($apiKey, 0, 12) . '…' . substr($apiKey, -4), ENT_QUOTES) ?></code>
                <?php endif; ?>
            </small>
        </label>

        <label class="form-field">
            <span>Timeout cereri (secunde)</span>
            <input type="number" name="erp_timeout" min="5" max="60"
                   value="<?= (int) ($settings['erp_timeout'] ?? 20) ?>">
        </label>

        <label class="form-field" style="grid-column:1 / -1;">
            <span>
                <input type="checkbox" name="erp_stock_enabled" value="1" <?= $stockEnabled ? 'checked' : '' ?>>
                Afișează stocul din ERP pe site
            </span>
            <small>
                Când e pornită, produsele afișează „Existent" sau „Stoc 0" după disponibilul real din
                gestiune (stoc minus rezervările comenzilor în lucru), nu după stocul din fișa produsului.
            </small>
        </label>

        <div style="grid-column:1 / -1;display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn" type="submit" name="action" value="save">Salvează</button>
            <button class="btn btn-secondary" type="submit" name="action" value="test">Testează conexiunea</button>
            <button class="btn btn-secondary" type="submit" name="action" value="retry">
                Retrimite comenzile eșuate
            </button>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Reîncercări automate</h2>
    <p>
        Dacă ERP-ul nu răspunde, comanda rămâne pe site și se reîncearcă singură
        (după 1 minut, 5 minute, 15 minute, 1 oră, 6 ore, apoi la 12 ore, maximum 12 încercări).
        Ca reîncercările să ruleze și fără să intri în admin, adaugă un cron pe server:
    </p>
    <pre style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;">*/5 * * * * php <?= htmlspecialchars(dirname(__DIR__, 2), ENT_QUOTES) ?>/scripts/erp-sync.php >/dev/null 2>&1</pre>
    <p>
        Comenzile plătite cu cardul pleacă spre ERP abia după confirmarea plății;
        cele cu ramburs pleacă imediat ce sunt plasate.
    </p>
</section>
