<?php
$settings = is_array($settings ?? null) ? $settings : [];
$loyalty = is_array($loyalty ?? null) ? $loyalty : [
    'enabled' => true,
    'earn_rate' => 1,
    'redeem_value' => 0.10,
    'min_redeem' => 100,
    'max_redeem_percent' => 50,
    'promo_multiplier' => 1,
    'promo_weekend_multiplier' => 1,
    'promo_active' => false,
];
$transactions = is_array($transactions ?? null) ? $transactions : [];
$promoRules = is_array($promoRules ?? null) ? $promoRules : [];
$users = is_array($users ?? null) ? $users : [];
$pointsImportMissingEmails = is_array($pointsImportMissingEmails ?? null) ? $pointsImportMissingEmails : [];
$claimedPointsAccounts = is_array($claimedPointsAccounts ?? null) ? $claimedPointsAccounts : [];
$unclaimedPointsAccounts = is_array($unclaimedPointsAccounts ?? null) ? $unclaimedPointsAccounts : [];
$pointsSearch = trim((string) ($pointsSearch ?? ''));
$defaultLoyaltyPanel = trim((string) ($defaultLoyaltyPanel ?? 'settings'));
if (!in_array($defaultLoyaltyPanel, ['settings', 'adjust', 'promo', 'history', 'claimed', 'unclaimed', 'import'], true)) {
    $defaultLoyaltyPanel = 'settings';
}
?>

<section class="panel">
    <div class="section-head" style="margin-bottom:10px;">
        <div>
            <h1>Puncte fidelitate</h1>
            <p>Configurează programul de puncte și gestionează punctajele clienților.</p>
        </div>
    </div>

    <style>
        .loyalty-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        .loyalty-tabs .btn.is-active {
            background: #0f766e;
            color: #fff;
            border-color: #0f766e;
        }
        .loyalty-panel[hidden] {
            display: none !important;
        }
        .loyalty-points-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin: 8px 0 12px;
        }
        .loyalty-points-summary article {
            border: 1px solid #dbe4ef;
            border-radius: 10px;
            background: #f8fbff;
            padding: 10px 12px;
        }
        .loyalty-points-summary article small {
            color: #64748b;
            display: block;
            margin-bottom: 4px;
        }
        .loyalty-points-summary article strong {
            font-size: 18px;
            color: #0f172a;
        }
        .loyalty-inline-search {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 0 0 12px;
        }
        .loyalty-inline-search input {
            min-width: 260px;
            flex: 1 1 260px;
        }
        .loyalty-history-modal__meta {
            margin: 8px 0 10px;
            color: #64748b;
            font-size: 13px;
        }
        .loyalty-history-modal__empty {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }
        .loyalty-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            color: #334155;
            background: #f8fafc;
        }
        .loyalty-status-pill.pending {
            border-color: #f59e0b;
            color: #92400e;
            background: #fffbeb;
        }
        .loyalty-status-pill.completed {
            border-color: #10b981;
            color: #065f46;
            background: #ecfdf5;
        }
        .loyalty-unclaimed-note {
            margin: 8px 0 12px;
            color: #64748b;
            font-size: 13px;
        }
    </style>

    <div class="loyalty-tabs" data-loyalty-tabs data-default-tab="<?= htmlspecialchars($defaultLoyaltyPanel, ENT_QUOTES) ?>">
        <button class="btn btn-secondary <?= $defaultLoyaltyPanel === 'settings' ? 'is-active' : '' ?>" type="button" data-loyalty-tab="settings">Setări program puncte</button>
        <button class="btn btn-secondary <?= $defaultLoyaltyPanel === 'adjust' ? 'is-active' : '' ?>" type="button" data-loyalty-tab="adjust">Ajustare manuală puncte</button>
        <button class="btn btn-secondary <?= $defaultLoyaltyPanel === 'promo' ? 'is-active' : '' ?>" type="button" data-loyalty-tab="promo">Reguli promo puncte</button>
        <button class="btn btn-secondary <?= $defaultLoyaltyPanel === 'history' ? 'is-active' : '' ?>" type="button" data-loyalty-tab="history">Istoric tranzacții puncte</button>
        <button class="btn btn-secondary <?= $defaultLoyaltyPanel === 'claimed' ? 'is-active' : '' ?>" type="button" data-loyalty-tab="claimed">Puncte revendicate</button>
        <button class="btn btn-secondary <?= $defaultLoyaltyPanel === 'unclaimed' ? 'is-active' : '' ?>" type="button" data-loyalty-tab="unclaimed">Puncte nerevendicate</button>
        <button class="btn btn-secondary <?= $defaultLoyaltyPanel === 'import' ? 'is-active' : '' ?>" type="button" data-loyalty-tab="import">Import puncte</button>
    </div>

    <article class="panel loyalty-panel" data-loyalty-panel="settings" <?= $defaultLoyaltyPanel !== 'settings' ? 'hidden' : '' ?> style="margin:12px 0;">
        <h3 style="margin-top:0;">Setări program puncte</h3>
        <form method="post" action="/admin/users/points" class="field" id="loyalty-settings-form">
            <input type="hidden" name="action" value="save_settings">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="loyalty_points_enabled" <?= !empty($loyalty['enabled']) ? 'checked' : '' ?>>
                Activează sistemul de puncte
            </label>
            <label>Puncte acordate per 1 leu cheltuit</label>
            <input type="number" name="loyalty_points_earn_rate" min="0" step="0.01"
                   value="<?= htmlspecialchars((string) ($loyalty['earn_rate'] ?? 1), ENT_QUOTES) ?>"
                   data-original="<?= htmlspecialchars((string) ($loyalty['earn_rate'] ?? 1), ENT_QUOTES) ?>">
            <label>Valoare 1 punct (lei)</label>
            <input type="number" name="loyalty_points_redeem_value" min="0.0001" step="0.0001"
                   value="<?= htmlspecialchars((string) ($loyalty['redeem_value'] ?? 0.01), ENT_QUOTES) ?>"
                   data-original="<?= htmlspecialchars((string) ($loyalty['redeem_value'] ?? 0.01), ENT_QUOTES) ?>"
                   id="loyalty-redeem-value">
            <label>Minim puncte pentru folosire în coș</label>
            <input type="number" name="loyalty_points_min_redeem" min="0" step="1"
                   value="<?= htmlspecialchars((string) ($loyalty['min_redeem'] ?? 100), ENT_QUOTES) ?>">
            <label>Procent maxim din coș acoperit cu puncte (%)</label>
            <input type="number" name="loyalty_points_max_redeem_percent" min="1" max="100" step="1"
                   value="<?= htmlspecialchars((string) ($loyalty['max_redeem_percent'] ?? 50), ENT_QUOTES) ?>">
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:8px 0;">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="loyalty_points_promo_active" <?= !empty($loyalty['promo_active']) ? 'checked' : '' ?>>
                Activează reguli promo puncte
            </label>
            <label>Multiplicator promo global</label>
            <input type="number" name="loyalty_points_promo_multiplier" min="1" step="0.1"
                   value="<?= htmlspecialchars((string) ($loyalty['promo_multiplier'] ?? 1), ENT_QUOTES) ?>">
            <label>Multiplicator weekend (Sâmbătă/Duminică)</label>
            <input type="number" name="loyalty_points_weekend_multiplier" min="1" step="0.1"
                   value="<?= htmlspecialchars((string) ($loyalty['promo_weekend_multiplier'] ?? 1), ENT_QUOTES) ?>">
            <div><button class="btn" type="submit">Salvează setările puncte</button></div>
        </form>
        <script>
        (function () {
            var form = document.getElementById('loyalty-settings-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                var field = document.getElementById('loyalty-redeem-value');
                if (!field) return;
                var oldVal = parseFloat(field.dataset.original || '0');
                var newVal = parseFloat(field.value || '0');
                if (oldVal > 0 && newVal > 0 && (newVal / oldVal > 5 || oldVal / newVal > 5)) {
                    var msg = 'ATENȚIE: Valoarea 1 punct se schimbă de la ' + oldVal + ' lei la ' + newVal + ' lei.\n' +
                              'Aceasta este o modificare de ' + Math.round(Math.max(newVal/oldVal, oldVal/newVal)) + 'x față de valoarea curentă.\n\n' +
                              'Ești sigur că vrei să salvezi această schimbare?';
                    if (!confirm(msg)) {
                        e.preventDefault();
                    }
                }
            });
        })();
        </script>
    </article>

    <article class="panel loyalty-panel" data-loyalty-panel="adjust" <?= $defaultLoyaltyPanel !== 'adjust' ? 'hidden' : '' ?> style="margin:12px 0;">
        <h3 style="margin-top:0;">Ajustare manuală puncte</h3>
        <form method="post" action="/admin/users/points" class="form-grid">
            <input type="hidden" name="action" value="adjust_points">
            <div class="field" style="grid-column:1/-1;">
                <label>Utilizator</label>
                <input type="text" id="points-user-search" placeholder="Caută rapid după nume sau email...">
                <select name="user_id" required>
                    <option value="">Selectează utilizator...</option>
                    <?php foreach ($users as $user): ?>
                        <?php
                        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
                        $email = (string) ($user['email'] ?? '');
                        $points = (int) ($user['loyalty_points'] ?? 0);
                        ?>
                        <option value="<?= (int) ($user['id'] ?? 0) ?>">
                            <?= htmlspecialchars(($name !== '' ? $name : 'Utilizator') . ' (' . $email . ') - ' . $points . ' puncte', ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Puncte (+ / -)</label>
                <input type="number" name="points_delta" step="1" required placeholder="ex: 50 sau -20">
            </div>
            <div class="field">
                <label>Motiv</label>
                <input type="text" name="reason" placeholder="Ajustare promoțională / corecție">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <button class="btn" type="submit">Aplică ajustarea</button>
            </div>
        </form>
    </article>

    <article class="panel loyalty-panel" data-loyalty-panel="promo" <?= $defaultLoyaltyPanel !== 'promo' ? 'hidden' : '' ?> style="margin:12px 0;">
        <h3 style="margin-top:0;">Reguli promo puncte</h3>
        <form method="post" action="/admin/users/points" class="form-grid">
            <input type="hidden" name="action" value="save_promo_rule">
            <div class="field">
                <label>Nume regulă</label>
                <input type="text" name="rule_name" placeholder="Ex: Dublu puncte weekend" required>
            </div>
            <div class="field">
                <label>Multiplicator</label>
                <input type="number" name="rule_multiplier" min="1" step="0.1" value="2" required>
            </div>
            <div class="field">
                <label>Prag minim comandă (lei, opțional)</label>
                <input type="number" name="rule_min_order_total" min="0" step="0.01" placeholder="ex: 200">
            </div>
            <div class="field">
                <label>Categoria produs (ID, opțional)</label>
                <input type="number" name="rule_category_id" min="0" step="1" placeholder="ex: 3">
            </div>
            <div class="field">
                <label>Start (opțional)</label>
                <input type="datetime-local" name="rule_starts_at">
            </div>
            <div class="field">
                <label>Final (opțional)</label>
                <input type="datetime-local" name="rule_ends_at">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="rule_is_active" checked>
                    Regula este activă
                </label>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <button class="btn" type="submit">Adaugă regulă promo</button>
            </div>
        </form>

        <div class="users-table-wrap" style="margin-top:12px;">
            <table class="table users-table">
                <thead>
                <tr>
                    <th>Nume</th>
                    <th>Multiplicator</th>
                    <th>Prag</th>
                    <th>Categorie</th>
                    <th>Interval</th>
                    <th>Status</th>
                    <th style="width:90px;">Acțiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($promoRules === []): ?>
                    <tr><td colspan="7">Nu există reguli promo definite.</td></tr>
                <?php else: ?>
                    <?php foreach ($promoRules as $rule): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($rule['name'] ?? ''), ENT_QUOTES) ?></td>
                            <td>×<?= number_format((float) ($rule['multiplier'] ?? 1), 2) ?></td>
                            <td><?= ($rule['min_order_total'] ?? null) !== null ? number_format((float) $rule['min_order_total'], 2) . ' lei' : '-' ?></td>
                            <td><?= (int) ($rule['category_id'] ?? 0) > 0 ? (int) $rule['category_id'] : '-' ?></td>
                            <td>
                                <?= htmlspecialchars((string) (($rule['starts_at'] ?? '') !== '' ? $rule['starts_at'] : '-'), ENT_QUOTES) ?>
                                →
                                <?= htmlspecialchars((string) (($rule['ends_at'] ?? '') !== '' ? $rule['ends_at'] : '-'), ENT_QUOTES) ?>
                            </td>
                            <td>
                                <span class="status-pill <?= (int) ($rule['is_active'] ?? 0) === 1 ? 'ok' : 'off' ?>">
                                    <?= (int) ($rule['is_active'] ?? 0) === 1 ? 'activă' : 'inactivă' ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" action="/admin/users/points" onsubmit="return confirm('Ștergi regula promo?');">
                                    <input type="hidden" name="action" value="delete_promo_rule">
                                    <input type="hidden" name="rule_id" value="<?= (int) ($rule['id'] ?? 0) ?>">
                                    <button class="icon-btn danger" type="submit" title="Șterge regulă">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel loyalty-panel" data-loyalty-panel="history" <?= $defaultLoyaltyPanel !== 'history' ? 'hidden' : '' ?> style="margin:12px 0 0;">
        <h3 style="margin-top:0;">Istoric tranzacții puncte</h3>
        <div class="users-table-wrap">
            <table class="table users-table">
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Client</th>
                    <th>Tip</th>
                    <th>Delta</th>
                    <th>Sold după</th>
                    <th>Total înainte</th>
                    <th>Total după puncte</th>
                    <th>Comandă</th>
                    <th>Detalii</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($transactions === []): ?>
                    <tr><td colspan="9">Nu există tranzacții de puncte încă.</td></tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                        <?php
                        $delta = (int) ($tx['points_delta'] ?? 0);
                        $deltaLabel = $delta > 0 ? '+' . $delta : (string) $delta;
                        $name = trim((string) ($tx['first_name'] ?? '') . ' ' . (string) ($tx['last_name'] ?? ''));
                        $orderNo = trim((string) ($tx['order_number'] ?? ''));
                        $txType = (string) ($tx['tx_type'] ?? '');
                        $orderSubtotal = $tx['subtotal'] !== null ? (float) $tx['subtotal'] : null;
                        $orderTotal = $tx['total'] !== null ? (float) $tx['total'] : null;
                        $loyaltyDiscount = $tx['loyalty_points_discount'] !== null ? (float) $tx['loyalty_points_discount'] : null;
                        $totalBeforePoints = ($orderTotal !== null && $loyaltyDiscount !== null)
                            ? $orderTotal + $loyaltyDiscount
                            : null;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($tx['created_at'] ?? ''), ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars(($name !== '' ? $name : 'Utilizator') . ' · ' . (string) ($tx['email'] ?? ''), ENT_QUOTES) ?></td>
                            <td><span class="status-pill"><?= htmlspecialchars($txType, ENT_QUOTES) ?></span></td>
                            <td><strong style="color:<?= $delta >= 0 ? '#166534' : '#991b1b' ?>;"><?= htmlspecialchars($deltaLabel, ENT_QUOTES) ?></strong></td>
                            <td><?= (int) ($tx['balance_after'] ?? 0) ?></td>
                            <td><?= $totalBeforePoints !== null ? number_format($totalBeforePoints, 2, '.', '') . ' lei' : '-' ?></td>
                            <td><?= $orderTotal !== null ? number_format($orderTotal, 2, '.', '') . ' lei' : '-' ?></td>
                            <td>
                                <?php if ($orderNo !== ''): ?>
                                    <a href="/admin/orders?q=<?= urlencode($orderNo) ?>" target="_blank" style="font-family:monospace;"><?= htmlspecialchars($orderNo, ENT_QUOTES) ?></a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) ($tx['reason'] ?? '-'), ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel loyalty-panel" data-loyalty-panel="claimed" <?= $defaultLoyaltyPanel !== 'claimed' ? 'hidden' : '' ?> style="margin:12px 0;">
        <h3 style="margin-top:0;">Puncte revendicate</h3>
        <?php
        $claimedTotalPoints = 0;
        foreach ($claimedPointsAccounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            $claimedTotalPoints += max(0, (int) ($account['loyalty_points'] ?? 0));
        }
        ?>
        <div class="loyalty-points-summary">
            <article>
                <small>Conturi cu puncte</small>
                <strong><?= count($claimedPointsAccounts) ?></strong>
            </article>
            <article>
                <small>Total puncte revendicate</small>
                <strong><?= (int) $claimedTotalPoints ?> pct</strong>
            </article>
        </div>
        <form method="get" action="/admin/users/points" class="loyalty-inline-search">
            <input type="hidden" name="panel" value="claimed">
            <input type="text" name="points_q" value="<?= htmlspecialchars($pointsSearch, ENT_QUOTES) ?>" placeholder="Caută după email sau nume">
            <button class="btn" type="submit">Caută</button>
            <?php if ($pointsSearch !== ''): ?>
                <a class="btn btn-secondary" href="/admin/users/points?panel=claimed">Reset</a>
            <?php endif; ?>
        </form>
        <div class="users-table-wrap">
            <table class="table users-table">
                <thead>
                <tr>
                    <th>Client</th>
                    <th>Email</th>
                    <th>Puncte</th>
                    <th>Comenzi</th>
                    <th>Total cheltuit</th>
                    <th>Ultima comandă</th>
                    <th style="width:120px;">Acțiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($claimedPointsAccounts === []): ?>
                    <tr><td colspan="7">Nu există conturi cu puncte revendicate pentru filtrul selectat.</td></tr>
                <?php else: ?>
                    <?php foreach ($claimedPointsAccounts as $account): ?>
                        <?php
                        if (!is_array($account)) {
                            continue;
                        }
                        $uid = (int) ($account['id'] ?? 0);
                        $fullName = trim((string) ($account['first_name'] ?? '') . ' ' . (string) ($account['last_name'] ?? ''));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($fullName !== '' ? $fullName : 'Utilizator', ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars((string) ($account['email'] ?? ''), ENT_QUOTES) ?></td>
                            <td><strong><?= max(0, (int) ($account['loyalty_points'] ?? 0)) ?></strong> pct</td>
                            <td><?= max(0, (int) ($account['orders_count'] ?? 0)) ?></td>
                            <td><?= number_format((float) ($account['total_spent'] ?? 0), 2) ?> lei</td>
                            <td><?= htmlspecialchars((string) ($account['last_order_at'] ?? '-') ?: '-', ENT_QUOTES) ?></td>
                            <td>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    data-loyalty-history-btn
                                    data-user-id="<?= $uid ?>"
                                    data-user-name="<?= htmlspecialchars($fullName !== '' ? $fullName : 'Utilizator', ENT_QUOTES) ?>"
                                >
                                    Detalii
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel loyalty-panel" data-loyalty-panel="unclaimed" <?= $defaultLoyaltyPanel !== 'unclaimed' ? 'hidden' : '' ?> style="margin:12px 0;">
        <h3 style="margin-top:0;">Puncte nerevendicate</h3>
        <?php
        $unclaimedTotalPoints = 0;
        $unclaimedPendingPoints = 0;
        $unclaimedFinalizedPoints = 0;
        foreach ($unclaimedPointsAccounts as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $confirmedPoints = max(0, (int) ($entry['confirmed_points'] ?? 0));
            $pendingPoints = max(0, (int) ($entry['pending_points'] ?? 0));
            $unclaimedTotalPoints += ($confirmedPoints + $pendingPoints);
            $unclaimedPendingPoints += $pendingPoints;
            $unclaimedFinalizedPoints += $confirmedPoints;
        }
        ?>
        <div class="loyalty-points-summary">
            <article>
                <small>Email-uri fără cont</small>
                <strong><?= count($unclaimedPointsAccounts) ?></strong>
            </article>
            <article>
                <small>Total puncte neatribuite</small>
                <strong><?= (int) $unclaimedTotalPoints ?> pct</strong>
            </article>
            <article>
                <small>În așteptare (comenzi nefinalizate)</small>
                <strong><?= (int) $unclaimedPendingPoints ?> pct</strong>
            </article>
            <article>
                <small>Finalizate, pregătite de revendicare</small>
                <strong><?= (int) $unclaimedFinalizedPoints ?> pct</strong>
            </article>
        </div>
        <p class="loyalty-unclaimed-note">
            Marcajul <strong>Pending</strong> indică puncte estimate pentru comenzi nefinalizate. Aceste puncte devin eligibile la finalizarea comenzii.
        </p>
        <form method="get" action="/admin/users/points" class="loyalty-inline-search">
            <input type="hidden" name="panel" value="unclaimed">
            <input type="text" name="points_q" value="<?= htmlspecialchars($pointsSearch, ENT_QUOTES) ?>" placeholder="Caută email">
            <button class="btn" type="submit">Caută</button>
            <?php if ($pointsSearch !== ''): ?>
                <a class="btn btn-secondary" href="/admin/users/points?panel=unclaimed">Reset</a>
            <?php endif; ?>
        </form>
        <div class="users-table-wrap">
            <table class="table users-table">
                <thead>
                <tr>
                    <th>Email</th>
                    <th>Puncte nerevendicate</th>
                    <th>Comenzi</th>
                    <th>Status</th>
                    <th>Ultima comandă</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($unclaimedPointsAccounts === []): ?>
                    <tr><td colspan="5">Nu există puncte nerevendicate pentru filtrul selectat.</td></tr>
                <?php else: ?>
                    <?php foreach ($unclaimedPointsAccounts as $entry): ?>
                        <?php
                        if (!is_array($entry)) {
                            continue;
                        }
                        $confirmedPoints = max(0, (int) ($entry['confirmed_points'] ?? 0));
                        $pendingPoints = max(0, (int) ($entry['pending_points'] ?? 0));
                        $pointsTotal = $confirmedPoints + $pendingPoints;
                        $confirmedOrdersCount = max(0, (int) ($entry['confirmed_orders_count'] ?? 0));
                        $pendingOrdersCount = max(0, (int) ($entry['pending_orders_count'] ?? 0));
                        $isPendingBucket = $pendingOrdersCount > 0;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($entry['email'] ?? ''), ENT_QUOTES) ?></td>
                            <td>
                                <strong><?= $pointsTotal ?></strong> pct
                                <div style="margin-top:4px;color:#64748b;font-size:12px;">
                                    confirmate: <?= $confirmedPoints ?> · pending: <?= $pendingPoints ?>
                                </div>
                                <details style="margin-top:6px;">
                                    <summary style="font-size:12px;color:#1a7a5e;cursor:pointer;user-select:none;">✎ Modifică</summary>
                                    <form method="post" action="/admin/users/points" style="display:flex;align-items:center;gap:6px;margin-top:6px;flex-wrap:wrap;">
                                        <input type="hidden" name="action" value="adjust_unclaimed">
                                        <input type="hidden" name="email" value="<?= htmlspecialchars((string) ($entry['email'] ?? ''), ENT_QUOTES) ?>">
                                        <input type="number" name="new_total" min="0" max="<?= $pointsTotal ?>" value="<?= $pointsTotal ?>" style="width:80px;padding:3px 6px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
                                        <span style="font-size:12px;color:#64748b;">pct (max <?= $pointsTotal ?>)</span>
                                        <button type="submit" class="btn" style="padding:3px 10px;font-size:12px;" onclick="return confirm('Sigur vrei să modifici punctele pentru <?= htmlspecialchars((string) ($entry['email'] ?? ''), ENT_QUOTES) ?>?')">Salvează</button>
                                    </form>
                                </details>
                            </td>
                            <td><?= max(0, (int) ($entry['orders_count'] ?? 0)) ?></td>
                            <td>
                                <span class="loyalty-status-pill <?= $isPendingBucket ? 'pending' : 'completed' ?>">
                                    <?= $isPendingBucket ? 'Pending' : 'Finalizată' ?>
                                </span>
                                <div style="margin-top:4px;color:#64748b;font-size:12px;">
                                    comenzi finalizate: <?= $confirmedOrdersCount ?> · comenzi pending: <?= $pendingOrdersCount ?>
                                </div>
                            </td>
                            <td><?= htmlspecialchars((string) ($entry['last_order_at'] ?? '-') ?: '-', ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel loyalty-panel" data-loyalty-panel="import" <?= $defaultLoyaltyPanel !== 'import' ? 'hidden' : '' ?> style="margin:12px 0;">
        <h3 style="margin-top:0;">Import puncte</h3>
        <form method="post" action="/admin/users/points/import" enctype="multipart/form-data" class="users-search-row" style="margin-bottom:10px;">
            <input type="file" name="points_file" accept=".csv,.xlsx" required>
            <button class="btn" type="submit">Import puncte (CSV/XLSX)</button>
            <small style="color:#64748b;">Coloane acceptate: email + puncte (current_balance / received_points / points).</small>
        </form>
        <?php if ($pointsImportMissingEmails !== []): ?>
            <div class="panel" style="margin:8px 0 0;border-color:#f59e0b;background:#fffbeb;">
                <h4 style="margin:0 0 8px;color:#92400e;">Email-uri negăsite în site (import puncte)</h4>
                <p style="margin:0 0 8px;color:#92400e;">Primele <?= min(80, count($pointsImportMissingEmails)) ?> email-uri care nu au fost asociate:</p>
                <div style="max-height:200px;overflow:auto;padding:8px;border:1px solid #fcd34d;border-radius:8px;background:#fff;">
                    <ul style="margin:0;padding-left:18px;display:grid;gap:4px;">
                        <?php foreach (array_slice($pointsImportMissingEmails, 0, 80) as $missingEmail): ?>
                            <li style="font-size:13px;color:#78350f;"><?= htmlspecialchars((string) $missingEmail, ENT_QUOTES) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </article>
</section>
<div class="modal-overlay" id="loyalty-history-modal">
    <div class="modal-card" style="max-width:920px;">
        <div class="modal-head">
            <h3 id="loyalty-history-title">Istoric puncte</h3>
            <button type="button" class="icon-btn" id="loyalty-history-close-btn" aria-label="Închide">✕</button>
        </div>
        <p class="loyalty-history-modal__meta" id="loyalty-history-meta"></p>
        <div class="users-table-wrap">
            <table class="table users-table">
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Tip</th>
                    <th>Delta</th>
                    <th>Sold după</th>
                    <th>Comandă</th>
                    <th>Admin</th>
                    <th>Detalii</th>
                </tr>
                </thead>
                <tbody id="loyalty-history-body">
                <tr><td colspan="7" class="loyalty-history-modal__empty">Selectează un cont pentru a vedea istoricul.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
(() => {
    const search = document.getElementById('points-user-search');
    const select = document.querySelector('select[name="user_id"]');
    if (search instanceof HTMLInputElement && select instanceof HTMLSelectElement) {
        const options = Array.from(select.options);
        const normalize = (value) => String(value || '').toLowerCase().trim();
        search.addEventListener('input', () => {
            const term = normalize(search.value);
            options.forEach((option, index) => {
                if (index === 0) return;
                const match = term === '' || normalize(option.textContent).includes(term);
                option.hidden = !match;
            });
        });
    }

    const tabsWrap = document.querySelector('[data-loyalty-tabs]');
    if (!(tabsWrap instanceof HTMLElement)) {
        return;
    }
    const tabs = Array.from(tabsWrap.querySelectorAll('[data-loyalty-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-loyalty-panel]'));
    if (tabs.length === 0 || panels.length === 0) {
        return;
    }

    const setActive = (key) => {
        tabs.forEach((btn) => {
            if (!(btn instanceof HTMLElement)) {
                return;
            }
            btn.classList.toggle('is-active', btn.getAttribute('data-loyalty-tab') === key);
        });
        panels.forEach((panel) => {
            if (!(panel instanceof HTMLElement)) {
                return;
            }
            panel.hidden = panel.getAttribute('data-loyalty-panel') !== key;
        });
    };

    const hashKey = String(window.location.hash || '').replace('#', '').trim();
    const hasHash = tabs.some((btn) => btn.getAttribute('data-loyalty-tab') === hashKey);
    if (hasHash) {
        setActive(hashKey);
    } else {
        const defaultKey = String(tabsWrap.getAttribute('data-default-tab') || 'settings').trim();
        setActive(defaultKey !== '' ? defaultKey : 'settings');
    }

    tabs.forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.getAttribute('data-loyalty-tab') || 'settings';
            setActive(key);
            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState(null, '', '#' + key);
            }
        });
    });
})();

(() => {
    const modal = document.getElementById('loyalty-history-modal');
    const closeBtn = document.getElementById('loyalty-history-close-btn');
    const title = document.getElementById('loyalty-history-title');
    const meta = document.getElementById('loyalty-history-meta');
    const body = document.getElementById('loyalty-history-body');
    const buttons = Array.from(document.querySelectorAll('[data-loyalty-history-btn]'));
    if (!(modal instanceof HTMLElement) || !(closeBtn instanceof HTMLElement) || !(title instanceof HTMLElement) || !(meta instanceof HTMLElement) || !(body instanceof HTMLElement) || buttons.length === 0) {
        return;
    }

    const closeModal = () => modal.classList.remove('open');
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    const renderRows = (history) => {
        if (!Array.isArray(history) || history.length === 0) {
            body.innerHTML = '<tr><td colspan="7" class="loyalty-history-modal__empty">Nu există tranzacții pentru acest cont.</td></tr>';
            return;
        }
        body.innerHTML = history.map((tx) => {
            const delta = Number.parseInt(String(tx.points_delta ?? 0), 10) || 0;
            const deltaLabel = delta > 0 ? `+${delta}` : String(delta);
            const color = delta >= 0 ? '#166534' : '#991b1b';
            const order = String(tx.order_number || '').trim();
            const adminEmail = String(tx.admin_email || '').trim();
            const details = String(tx.reason || '').trim();
            return `
                <tr>
                    <td>${String(tx.created_at || '')}</td>
                    <td><span class="status-pill">${String(tx.tx_type || '')}</span></td>
                    <td><strong style="color:${color};">${deltaLabel}</strong></td>
                    <td>${Number.parseInt(String(tx.balance_after ?? 0), 10) || 0}</td>
                    <td>${order !== '' ? order : '-'}</td>
                    <td>${adminEmail !== '' ? adminEmail : '-'}</td>
                    <td>${details !== '' ? details : '-'}</td>
                </tr>
            `;
        }).join('');
    };

    buttons.forEach((btn) => {
        btn.addEventListener('click', async () => {
            const userId = Number.parseInt(String(btn.getAttribute('data-user-id') || '0'), 10);
            const userName = String(btn.getAttribute('data-user-name') || 'Utilizator');
            if (!Number.isFinite(userId) || userId <= 0) {
                return;
            }

            title.textContent = `Istoric puncte - ${userName}`;
            meta.textContent = 'Se încarcă istoricul...';
            body.innerHTML = '<tr><td colspan="7" class="loyalty-history-modal__empty">Se încarcă...</td></tr>';
            modal.classList.add('open');

            try {
                const response = await fetch(`/admin/users/points/history?user_id=${encodeURIComponent(String(userId))}`, {
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const payload = await response.json();
                if (!payload || payload.ok !== true) {
                    meta.textContent = 'Nu am putut încărca istoricul.';
                    body.innerHTML = '<tr><td colspan="7" class="loyalty-history-modal__empty">A apărut o eroare la încărcare.</td></tr>';
                    return;
                }

                const user = payload.user || {};
                const email = String(user.email || '').trim();
                const points = Number.parseInt(String(user.loyalty_points || 0), 10) || 0;
                meta.textContent = `${email !== '' ? email + ' - ' : ''}${points} puncte disponibile`;
                renderRows(payload.history || []);
            } catch (_error) {
                meta.textContent = 'Nu am putut încărca istoricul.';
                body.innerHTML = '<tr><td colspan="7" class="loyalty-history-modal__empty">A apărut o eroare la încărcare.</td></tr>';
            }
        });
    });
})();
</script>
