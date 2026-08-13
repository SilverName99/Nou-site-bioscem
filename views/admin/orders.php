<?php
$orders = is_array($orders ?? null) ? $orders : [];
$promoProducts = is_array($promoProducts ?? null) ? $promoProducts : [];
$filters = is_array($filters ?? null) ? $filters : [];
$allowedStatuses = is_array($allowedOrderStatuses ?? null) ? $allowedOrderStatuses : ['pending', 'pending_payment', 'processing', 'completed', 'cancelled', 'refunded', 'failed'];
$bulkStatusOptions = array_values(array_filter(
    $allowedStatuses,
    static fn ($status): bool => (string) $status !== 'completed'
));
$ordersBackUrl = is_string($ordersBackUrl ?? null) ? (string) $ordersBackUrl : '/admin/orders';
$selectedStatus = (string) ($filters['status'] ?? '');
$dateFrom = (string) ($filters['from_date'] ?? '');
$dateTo = (string) ($filters['to_date'] ?? '');
$sortBy = (string) ($filters['sort_by'] ?? 'date');
$sortDir = (string) ($filters['sort_dir'] ?? 'desc');
$search = trim((string) ($filters['q'] ?? ''));
$paymentFilter = (string) ($filters['payment_method'] ?? '');
$statusLabels = is_array($orderStatusLabels ?? null) ? $orderStatusLabels : [];
$paymentStatusLabels = is_array($orderPaymentStatusLabels ?? null) ? $orderPaymentStatusLabels : [];
$paymentMethodLabels = is_array($orderPaymentMethodLabels ?? null) ? $orderPaymentMethodLabels : [];
$summary = is_array($ordersSummary ?? null) ? $ordersSummary : [
    'total_count' => count($orders),
    'completed_count' => 0,
    'cancelled_count' => 0,
    'pending_like_count' => 0,
    'total_value' => 0.0,
];
$formatDateTime = static function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '-';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('d.m.Y H:i', $ts);
};
$statusPillClassMap = [
    'pending' => 'warn',
    'pending_payment' => 'warn',
    'processing' => 'info',
    'completed' => 'ok',
    'cancelled' => 'off',
    'refunded' => 'muted',
    'failed' => 'off',
];
$paymentStatusPillClassMap = [
    'paid' => 'ok',
    'unpaid' => 'off',
    'pending' => 'warn',
    'failed' => 'off',
];
$paymentMethodPillClassMap = [
    'cod' => 'cod',
    'card' => 'card',
    'euplatesc' => 'card',
    'stripe' => 'card',
    'bank_transfer' => 'card',
];
$currentQuery = $_GET;
if (!is_array($currentQuery)) {
    $currentQuery = [];
}
$nextSortDir = strtolower($sortDir) === 'asc' ? 'desc' : 'asc';
$sortToggleQuery = array_merge($currentQuery, ['dir' => $nextSortDir]);
$sortToggleUrl = '/admin/orders?' . http_build_query($sortToggleQuery);
$sortToggleLabel = strtolower($sortDir) === 'asc'
    ? 'Sortare curentă: crescător. Click pentru descrescător.'
    : 'Sortare curentă: descrescător. Click pentru crescător.';
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Comenzi</h1>
            <p>Vizualizează și gestionează comenzile.</p>
        </div>
        <div style="display:flex;gap:8px;">
            <?php $exportQuery = trim((string) ($_SERVER['QUERY_STRING'] ?? '')); ?>
            <a class="btn" href="/admin/orders/export<?= $exportQuery !== '' ? ('?' . htmlspecialchars($exportQuery, ENT_QUOTES)) : '' ?>" title="Descarcă lista de comenzi (se deschide în Excel)">⬇ Export Excel</a>
            <a class="btn btn-secondary" href="/admin/orders/trash">Coș comenzi</a>
        </div>
    </div>

    <div class="orders-kpis">
        <article class="orders-kpi-card">
            <span class="orders-kpi-icon orders-kpi-icon--all">📦</span>
            <small>Total comenzi</small>
            <strong><?= (int) ($summary['total_count'] ?? 0) ?></strong>
        </article>
        <article class="orders-kpi-card">
            <span class="orders-kpi-icon orders-kpi-icon--ok">✅</span>
            <small>Finalizate</small>
            <strong class="ok"><?= (int) ($summary['completed_count'] ?? 0) ?></strong>
        </article>
        <article class="orders-kpi-card">
            <span class="orders-kpi-icon orders-kpi-icon--off">⛔</span>
            <small>Anulate</small>
            <strong class="off"><?= (int) ($summary['cancelled_count'] ?? 0) ?></strong>
        </article>
        <article class="orders-kpi-card">
            <span class="orders-kpi-icon orders-kpi-icon--warn">⏳</span>
            <small>În așteptare</small>
            <strong class="warn"><?= (int) ($summary['pending_like_count'] ?? 0) ?></strong>
        </article>
        <article class="orders-kpi-card">
            <span class="orders-kpi-icon orders-kpi-icon--sum">📈</span>
            <small>Valoare totală</small>
            <strong><?= number_format((float) ($summary['total_value'] ?? 0.0), 2) ?> RON</strong>
        </article>
    </div>

    <form method="get" action="/admin/orders" class="orders-filters orders-filters--compact">
        <div class="orders-filters-grid">
            <div class="orders-filter-field orders-filter-field--search">
                <span class="orders-filter-label">Căutare</span>
                <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="Caută după ID, client, email...">
            </div>
            <div class="orders-filter-field">
                <span class="orders-filter-label">Status</span>
                <select name="status">
                    <option value="">Toate statusurile</option>
                    <?php foreach ($allowedStatuses as $statusOption): ?>
                        <option value="<?= htmlspecialchars((string) $statusOption, ENT_QUOTES) ?>" <?= $selectedStatus === (string) $statusOption ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($statusLabels[(string) $statusOption] ?? (string) $statusOption), ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="orders-filter-field">
                <span class="orders-filter-label">Plată</span>
                <select name="payment_method">
                    <option value="">Toate</option>
                    <option value="card" <?= $paymentFilter === 'card' ? 'selected' : '' ?>>Card</option>
                    <option value="cod" <?= $paymentFilter === 'cod' ? 'selected' : '' ?>>Ramburs</option>
                </select>
            </div>
            <div class="orders-filter-field">
                <span class="orders-filter-label">De la data</span>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES) ?>">
            </div>
            <div class="orders-filter-field">
                <span class="orders-filter-label">Până la data</span>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES) ?>">
            </div>
            <div class="orders-filter-field">
                <span class="orders-filter-label">Sortare</span>
                <select name="sort">
                    <option value="date" <?= $sortBy === 'date' ? 'selected' : '' ?>>Data</option>
                    <option value="status" <?= $sortBy === 'status' ? 'selected' : '' ?>>Status</option>
                    <option value="total" <?= $sortBy === 'total' ? 'selected' : '' ?>>Preț</option>
                </select>
            </div>
            <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDir, ENT_QUOTES) ?>">
            <div class="orders-filter-actions">
                <button class="btn" type="submit">Aplică</button>
                <a class="btn btn-secondary" href="/admin/orders">Reset</a>
            </div>
        </div>
    </form>

    <form method="post" action="/admin/orders/bulk" id="orders-bulk-form">
        <input type="hidden" name="back_url" value="<?= htmlspecialchars($ordersBackUrl, ENT_QUOTES) ?>">
        <div id="orders-bulk-ids"></div>
        <div class="orders-bulk-toolbar">
            <div class="orders-bulk-toolbar__left">
                <label style="display:inline-flex;align-items:center;gap:8px;">
                    <input type="checkbox" id="orders-select-all">
                    Selectează toate
                </label>
            </div>
            <div class="orders-bulk-toolbar__right">
                <select name="bulk_action" id="orders-bulk-action">
                    <option value="">Acțiune în masă</option>
                    <option value="delete">Șterge (mută în coș)</option>
                    <option value="status">Schimbă status</option>
                    <option value="awb">Generează AWB</option>
                </select>
                <select name="bulk_status" id="orders-bulk-status" style="display:none;">
                    <option value="">Alege status</option>
                    <?php foreach ($bulkStatusOptions as $statusOption): ?>
                        <option value="<?= htmlspecialchars((string) $statusOption, ENT_QUOTES) ?>">
                            <?= htmlspecialchars((string) $statusOption, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-secondary" type="submit">Aplică pe selecție</button>
            </div>
        </div>
    </form>

    <?php if ($orders === []): ?>
        <p style="margin:0;color:#64748b;">Nu există comenzi pentru filtrele selectate.</p>
    <?php else: ?>
        <div class="orders-list-head">
            <p class="orders-count-note"><?= count($orders) ?> comenzi</p>
            <a class="orders-sort-toggle" href="<?= htmlspecialchars($sortToggleUrl, ENT_QUOTES) ?>" title="<?= htmlspecialchars($sortToggleLabel, ENT_QUOTES) ?>">↕️</a>
        </div>
        <div class="orders-table-wrap">
            <table class="table orders-table">
                <thead>
                <tr>
                    <th style="width:44px;"></th>
                    <th>Client / ID</th>
                    <th>Data</th>
                    <th>Sumă</th>
                    <th>Status</th>
                    <th>Livrare</th>
                    <th style="width:220px;">Acțiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <?php $orderJson = htmlspecialchars((string) json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES); ?>
                    <?php
                        $paymentStatus = (string) ($order['payment_status'] ?? 'unpaid');
                        $paymentStatusKey = strtolower($paymentStatus);
                        $statusRaw = (string) ($order['status'] ?? '');
                        $statusKey = strtolower($statusRaw);
                        $paymentMethodRaw = (string) ($order['payment_method'] ?? '');
                        $paymentMethodKey = strtolower($paymentMethodRaw);
                        $awb = trim((string) ($order['fan_awb'] ?? ''));
                        $trackingStatus = trim((string) ($order['fan_tracking_status'] ?? ''));
                        $trackingUrl = trim((string) ($order['fan_tracking_url'] ?? ''));
                        // Comenzile plecate din mai multe depozite au mai multe AWB-uri.
                        $awbColete = [];
                        $awbListaRaw = json_decode((string) ($order['fan_awb_list'] ?? ''), true);
                        if (is_array($awbListaRaw) && count($awbListaRaw) > 1) {
                            foreach ($awbListaRaw as $awbIntrare) {
                                if (!is_array($awbIntrare) || trim((string) ($awbIntrare['awb'] ?? '')) === '') {
                                    continue;
                                }
                                $awbColete[] = [
                                    'awb' => trim((string) $awbIntrare['awb']),
                                    'url' => trim((string) ($awbIntrare['tracking_url'] ?? '')),
                                    'gestiune' => trim((string) ($awbIntrare['gestiune'] ?? '')),
                                ];
                            }
                        }
                        // Motivul respingerii plății, salvat de procesator la eșec.
                        $paymentError = trim((string) ($order['payment_error'] ?? ''));
                        $paymentErrorScurt = $paymentError === ''
                            ? ''
                            : (function_exists('mb_strimwidth')
                                ? mb_strimwidth($paymentError, 0, 64, '…', 'UTF-8')
                                : substr($paymentError, 0, 64));
                        $pointsAwarded = (int) ($order['loyalty_points_awarded'] ?? 0);
                        $orderId = (int) ($order['id'] ?? 0);
                        $customerName = trim((string) ($order['billing_first_name'] ?? '') . ' ' . (string) ($order['billing_last_name'] ?? ''));
                        $isCancelled = $statusKey === 'cancelled';
                        $isCod = $paymentMethodKey === 'cod';
                        $statusPillClass = (string) ($statusPillClassMap[$statusKey] ?? 'info');
                        $paymentStatusPillClass = (string) ($paymentStatusPillClassMap[$paymentStatusKey] ?? 'info');
                        $paymentMethodPillClass = (string) ($paymentMethodPillClassMap[$paymentMethodKey] ?? 'muted');
                        $erpEnabled = (string) ($settings['erp_enabled'] ?? '0') === '1';
                        $erpStatus = strtolower(trim((string) ($order['erp_status'] ?? 'pending')));
                        $erpError = trim((string) ($order['erp_last_error'] ?? ''));
                        $erpProblems = trim((string) ($order['erp_problems'] ?? ''));
                        $erpLabels = [
                            'sent' => 'ERP: trimisă',
                            'pending' => 'ERP: în așteptare',
                            'failed' => 'ERP: eșuată',
                            'skipped' => 'ERP: netrimisă',
                        ];
                        // O comandă anulată/eșuată nu mai pleacă în ERP, chiar dacă a
                        // rămas marcată „în așteptare" dinainte de anulare.
                        if (in_array($statusKey, ['cancelled', 'refunded', 'failed'], true)
                            && in_array($erpStatus, ['pending', 'failed'], true)
                        ) {
                            $erpStatus = 'skipped';
                            $erpError = '';
                        }
                        $erpPill = ['sent' => 'ok', 'failed' => 'off', 'skipped' => 'muted'][$erpStatus] ?? 'warn';
                        $erpFactura = trim((string) ($order['erp_factura_numar'] ?? ''));
                    ?>
                    <tr class="<?= $isCancelled ? 'is-cancelled' : '' ?>">
                        <td class="orders-table__check">
                            <input type="checkbox" class="order-bulk-checkbox" data-order-id="<?= $orderId ?>" <?= $isCancelled ? 'disabled' : '' ?>>
                        </td>
                        <td>
                            <small style="display:block;color:#94a3b8;">#<?= (int) $orderId ?> / <?= htmlspecialchars((string) ($order['order_number'] ?? ''), ENT_QUOTES) ?></small>
                            <strong><?= htmlspecialchars($customerName !== '' ? $customerName : 'Client', ENT_QUOTES) ?></strong>
                            <small style="display:block;color:#64748b;"><?= htmlspecialchars((string) ($order['billing_email'] ?? ''), ENT_QUOTES) ?></small>
                            <?php if (trim((string) ($order['ad_source'] ?? '')) !== ''): ?>
                                <span title="Comandă provenită dintr-un anunț Google Ads<?= trim((string) ($order['ad_click_id'] ?? '')) !== '' ? (' (' . htmlspecialchars((string) $order['ad_click_id'], ENT_QUOTES) . ')') : '' ?>"
                                      style="display:inline-flex;align-items:center;gap:4px;margin-top:4px;padding:2px 8px;border-radius:999px;background:#e8f0fe;color:#1a73e8;font-size:11px;font-weight:700;line-height:1.4;">
                                    📣 Google Ads
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($formatDateTime((string) ($order['created_at'] ?? '')), ENT_QUOTES) ?></td>
                        <td><strong><?= number_format((float) ($order['total'] ?? 0), 2) ?> RON</strong></td>
                        <td>
                            <div class="orders-status-stack">
                                <span class="status-pill status-pill--<?= htmlspecialchars($statusPillClass, ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($statusLabels[$statusRaw] ?? $statusRaw), ENT_QUOTES) ?></span>
                                <?php if (!$isCod): ?>
                                    <span class="status-pill status-pill--<?= htmlspecialchars($paymentStatusPillClass, ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($paymentStatusLabels[$paymentStatusKey] ?? $paymentStatus), ENT_QUOTES) ?></span>
                                <?php endif; ?>
                                <span class="status-pill status-pill--<?= htmlspecialchars($paymentMethodPillClass, ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($paymentMethodLabels[$paymentMethodKey] ?? $paymentMethodRaw), ENT_QUOTES) ?></span>
                                <?php if ($erpEnabled): ?>
                                    <span class="status-pill status-pill--<?= htmlspecialchars($erpPill, ENT_QUOTES) ?>"
                                          title="<?= htmlspecialchars($erpError !== '' ? $erpError : ($erpProblems !== '' ? 'De rezolvat în ERP: ' . $erpProblems : ''), ENT_QUOTES) ?>">
                                        <?= htmlspecialchars((string) ($erpLabels[$erpStatus] ?? $erpStatus), ENT_QUOTES) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($paymentError !== ''): ?>
                                    <small class="orders-payment-error" title="<?= htmlspecialchars($paymentError, ENT_QUOTES) ?>">
                                        Motiv: <?= htmlspecialchars($paymentErrorScurt, ENT_QUOTES) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($erpFactura !== ''): ?>
                                <small style="display:block;color:#0f766e;">Factură ERP: <?= htmlspecialchars($erpFactura, ENT_QUOTES) ?></small>
                            <?php endif; ?>
                            <?php if (count($awbColete) > 1): ?>
                                <?php foreach ($awbColete as $awbColet): ?>
                                    <small style="display:block;">
                                        FAN:
                                        <?php if ($awbColet['url'] !== ''): ?>
                                            <a href="<?= htmlspecialchars($awbColet['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($awbColet['awb'], ENT_QUOTES) ?></a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($awbColet['awb'], ENT_QUOTES) ?>
                                        <?php endif; ?>
                                        <?php if ($awbColet['gestiune'] !== ''): ?>
                                            <span style="color:#64748b;">(<?= htmlspecialchars($awbColet['gestiune'], ENT_QUOTES) ?>)</span>
                                        <?php endif; ?>
                                    </small>
                                <?php endforeach; ?>
                                <small style="display:block;color:#64748b;"><?= htmlspecialchars($trackingStatus !== '' ? $trackingStatus : 'AWB generat', ENT_QUOTES) ?></small>
                            <?php elseif ($awb !== ''): ?>
                                <small style="display:block;">FAN: <?= htmlspecialchars($awb, ENT_QUOTES) ?></small>
                                <small style="display:block;color:#64748b;"><?= htmlspecialchars($trackingStatus !== '' ? $trackingStatus : 'AWB generat', ENT_QUOTES) ?></small>
                            <?php else: ?>
                                <small style="color:#94a3b8;">Puncte: <?= $pointsAwarded ?> / <?= (int) ($order['loyalty_points_pending_claim'] ?? 0) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="orders-col-actions">
                            <?php if ($isCancelled): ?>
                                <span class="orders-actions-disabled">Comandă anulată</span>
                            <?php else: ?>
                                <div class="order-actions">
                                    <button type="button" class="order-action-btn view-order-btn" data-order="<?= $orderJson ?>" title="Detalii">👁</button>
                                    <button type="button" class="order-action-btn client-promo-btn" data-order-id="<?= $orderId ?>" title="Toate promoționalele clientului">🎁</button>
                                    <?php if ($awb === ''): ?>
                                        <form method="post" action="/admin/orders/<?= $orderId ?>/fan-awb" onsubmit="return confirm('Sigur vrei să generezi AWB FAN pentru această comandă?');">
                                            <input type="hidden" name="back_url" value="<?= htmlspecialchars($ordersBackUrl, ENT_QUOTES) ?>">
                                            <button type="submit" class="order-action-btn" title="Generează AWB FAN">🚚</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($erpEnabled && !in_array($erpStatus, ['sent', 'skipped'], true)): ?>
                                        <form method="post" action="/admin/orders/<?= $orderId ?>/erp-retry">
                                            <button type="submit" class="order-action-btn" title="Retrimite în ERP<?= $erpError !== '' ? (' — ultima eroare: ' . htmlspecialchars($erpError, ENT_QUOTES)) : '' ?>">🔄</button>
                                        </form>
                                    <?php endif; ?>
                                    <button
                                        type="button"
                                        class="order-action-btn order-action-btn--edit"
                                        data-action="open-order-actions"
                                        data-order-id="<?= $orderId ?>"
                                        data-order-number="<?= htmlspecialchars((string) ($order['order_number'] ?? ''), ENT_QUOTES) ?>"
                                        data-current-status="<?= htmlspecialchars($statusRaw, ENT_QUOTES) ?>"
                                        data-tracking-url="<?= htmlspecialchars($trackingUrl, ENT_QUOTES) ?>"
                                        title="Editează acțiuni"
                                    >
                                        ✏️
                                    </button>
                                    <form method="post" action="/admin/orders/<?= $orderId ?>/delete" onsubmit="return confirm('Muți comanda în coș?');">
                                        <input type="hidden" name="back_url" value="<?= htmlspecialchars($ordersBackUrl, ENT_QUOTES) ?>">
                                        <button type="submit" class="order-action-btn order-action-btn--danger" title="Mută în coș">🗑</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="modal-overlay" id="order-modal">
    <div class="modal-card order-modal-card">
        <div class="modal-head">
            <h3 id="order-modal-title">Detalii comandă</h3>
            <button type="button" class="icon-btn" id="close-order-modal">✕</button>
        </div>
        <div id="order-modal-content"></div>
    </div>
</div>

<div id="client-promo-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1100;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;max-width:520px;width:100%;max-height:88vh;overflow:auto;padding:22px 24px;box-shadow:0 24px 64px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <h3 id="client-promo-title" style="margin:0;">Promoționale client</h3>
            <button type="button" id="client-promo-close" style="border:0;background:none;font-size:22px;line-height:1;cursor:pointer;color:#6b7280;">&times;</button>
        </div>
        <div id="client-promo-body"></div>
    </div>
</div>

<div class="modal-overlay" id="order-actions-modal">
    <div class="modal-card order-actions-modal-card">
        <div class="modal-head">
            <h3 id="order-actions-modal-title">Acțiuni comandă</h3>
            <button type="button" class="icon-btn" id="close-order-actions-modal">✕</button>
        </div>
        <form method="post" action="/admin/orders/0/status" id="order-actions-status-form" class="form-grid">
            <input type="hidden" name="back_url" value="<?= htmlspecialchars($ordersBackUrl, ENT_QUOTES) ?>">
            <div class="field" style="grid-column:1/-1;">
                <label>Status comandă</label>
                <select name="status" id="order-actions-status-select">
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= htmlspecialchars((string) $status, ENT_QUOTES) ?>">
                            <?= htmlspecialchars((string) ($statusLabels[(string) $status] ?? (string) $status), ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="order-actions-modal-buttons" style="grid-column:1/-1;">
                <a class="btn btn-secondary" href="#" id="order-actions-tracking-link" target="_blank" rel="noopener">↗ Deschide tracking FAN</a>
                <button class="btn" type="submit">Salvează status</button>
            </div>
        </form>
    </div>
</div>

<script>
window.promoProducts = <?= json_encode(array_map(static fn(array $p): array => ['id' => (int) ($p['id'] ?? 0), 'name' => (string) ($p['name'] ?? '')], $promoProducts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.orderProducts = <?= json_encode(array_map(static function (array $p): array {
    $price = (float) ($p['price'] ?? 0);
    $sale = (float) ($p['sale_price'] ?? 0);
    return ['id' => (int) ($p['id'] ?? 0), 'name' => (string) ($p['name'] ?? ''), 'price' => ($sale > 0 && $sale < $price) ? round($sale, 2) : round($price, 2)];
}, is_array($orderProducts ?? null) ? $orderProducts : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(() => {
    const bulkForm = document.getElementById('orders-bulk-form');
    const selectAll = document.getElementById('orders-select-all');
    const rowCheckboxes = Array.from(document.querySelectorAll('.order-bulk-checkbox'));
    const bulkAction = document.getElementById('orders-bulk-action');
    const bulkStatus = document.getElementById('orders-bulk-status');
    const bulkIds = document.getElementById('orders-bulk-ids');
    const selectableRowCheckboxes = () => rowCheckboxes.filter((input) => input instanceof HTMLInputElement && !input.disabled);

    const syncSelectAllState = () => {
        if (!(selectAll instanceof HTMLInputElement)) {
            return;
        }
        const selectable = selectableRowCheckboxes();
        if (selectable.length === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
            selectAll.disabled = true;
            return;
        }
        selectAll.disabled = false;
        const checkedCount = selectable.filter((input) => input.checked).length;
        selectAll.checked = checkedCount > 0 && checkedCount === selectable.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < selectable.length;
    };

    selectAll?.addEventListener('change', () => {
        selectableRowCheckboxes().forEach((input) => {
            input.checked = selectAll.checked;
        });
        syncSelectAllState();
    });

    rowCheckboxes.forEach((input) => {
        input.addEventListener('change', syncSelectAllState);
    });

    const syncBulkStatusVisibility = () => {
        if (!(bulkAction instanceof HTMLSelectElement) || !(bulkStatus instanceof HTMLSelectElement)) {
            return;
        }
        const isStatusAction = bulkAction.value === 'status';
        bulkStatus.style.display = isStatusAction ? '' : 'none';
        bulkStatus.required = isStatusAction;
        if (!isStatusAction) {
            bulkStatus.value = '';
        }
    };

    bulkAction?.addEventListener('change', () => {
        if (bulkStatus instanceof HTMLSelectElement) {
            syncBulkStatusVisibility();
        }
    });

    bulkForm?.addEventListener('submit', (event) => {
        if (!(bulkAction instanceof HTMLSelectElement)) {
            return;
        }
        const selectedIds = selectableRowCheckboxes().filter((input) => input.checked);
        if (selectedIds.length === 0) {
            event.preventDefault();
            window.alert('Selectează cel puțin o comandă.');
            return;
        }
        if (!bulkAction.value) {
            event.preventDefault();
            window.alert('Alege o acțiune în masă.');
            return;
        }
        if (bulkAction.value === 'status' && bulkStatus instanceof HTMLSelectElement && !bulkStatus.value) {
            event.preventDefault();
            window.alert('Selectează statusul pentru actualizarea în masă.');
            return;
        }
        if (bulkAction.value === 'delete' && !window.confirm('Muți comenzile selectate în coș?')) {
            event.preventDefault();
            return;
        }
        if (bulkAction.value === 'awb' && !window.confirm('Generezi AWB FAN pentru comenzile selectate?')) {
            event.preventDefault();
            return;
        }

        if (bulkIds instanceof HTMLElement) {
            bulkIds.innerHTML = '';
            selectedIds.forEach((input) => {
                if (!(input instanceof HTMLInputElement)) {
                    return;
                }
                const orderId = String(input.dataset.orderId || '').trim();
                if (!orderId) {
                    return;
                }
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'order_ids[]';
                hidden.value = orderId;
                bulkIds.appendChild(hidden);
            });
        }

        if (!(bulkIds instanceof HTMLElement) || bulkIds.querySelectorAll('input[name="order_ids[]"]').length === 0) {
            event.preventDefault();
            window.alert('Selectează cel puțin o comandă validă.');
        }
    });

    syncSelectAllState();
    syncBulkStatusVisibility();

    const modal = document.getElementById('order-modal');
    const closeBtn = document.getElementById('close-order-modal');
    const title = document.getElementById('order-modal-title');
    const content = document.getElementById('order-modal-content');
    const actionsModal = document.getElementById('order-actions-modal');
    const closeActionsModalBtn = document.getElementById('close-order-actions-modal');
    const actionsModalTitle = document.getElementById('order-actions-modal-title');
    const actionsStatusForm = document.getElementById('order-actions-status-form');
    const actionsStatusSelect = document.getElementById('order-actions-status-select');
    const actionsTrackingLink = document.getElementById('order-actions-tracking-link');
    const esc = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
    const toAmount = (value) => {
        const amount = Number(value);
        return Number.isFinite(amount) ? amount : 0;
    };
    const formatRon = (value) => `${toAmount(value).toFixed(2)} RON`;
    const closeOrderActionsModal = () => {
        actionsModal?.classList.remove('open');
    };

    document.querySelectorAll('[data-action="open-order-actions"]').forEach((button) => {
        button.addEventListener('click', () => {
            if (
                !(button instanceof HTMLElement)
                || !(actionsModal instanceof HTMLElement)
                || !(actionsStatusForm instanceof HTMLFormElement)
                || !(actionsStatusSelect instanceof HTMLSelectElement)
                || !(actionsTrackingLink instanceof HTMLAnchorElement)
            ) {
                return;
            }
            const orderId = Number(button.dataset.orderId || 0);
            const orderNumber = String(button.dataset.orderNumber || '').trim();
            const currentStatus = String(button.dataset.currentStatus || '').trim();
            const trackingUrl = String(button.dataset.trackingUrl || '').trim();
            if (!Number.isFinite(orderId) || orderId <= 0) {
                return;
            }

            actionsStatusForm.action = `/admin/orders/${orderId}/status`;
            actionsStatusForm.dataset.currentStatus = currentStatus;
            actionsStatusSelect.value = currentStatus !== '' ? currentStatus : actionsStatusSelect.value;
            actionsModalTitle.textContent = `Acțiuni comandă ${orderNumber !== '' ? orderNumber : ('#' + orderId)}`;

            if (trackingUrl !== '') {
                actionsTrackingLink.hidden = false;
                actionsTrackingLink.href = trackingUrl;
            } else {
                actionsTrackingLink.hidden = true;
                actionsTrackingLink.href = '#';
            }

            actionsModal.classList.add('open');
        });
    });

    closeActionsModalBtn?.addEventListener('click', closeOrderActionsModal);
    actionsModal?.addEventListener('click', (event) => {
        if (event.target === actionsModal) {
            closeOrderActionsModal();
        }
    });

    actionsStatusForm?.addEventListener('submit', (event) => {
        if (!(actionsStatusSelect instanceof HTMLSelectElement)) {
            return;
        }
        const nextStatus = String(actionsStatusSelect.value || '').trim();
        const currentStatus = String((actionsStatusForm.dataset.currentStatus || '')).trim();
        if (nextStatus === currentStatus) {
            closeOrderActionsModal();
            return;
        }
        if (nextStatus === 'completed' && currentStatus !== 'completed') {
            const ok = window.confirm('Sigur vrei să setezi comanda pe completed?');
            if (!ok) {
                event.preventDefault();
                return;
            }
        }
        closeOrderActionsModal();
    });

    document.querySelectorAll('.view-order-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const order = JSON.parse(btn.dataset.order || '{}');
            title.textContent = 'Detalii comandă ' + esc(order.order_number || '');
            const items = Array.isArray(order.items) ? order.items : [];
            const addressParts = [
                String(order.billing_address_line1 || '').trim(),
                String(order.billing_address_line2 || '').trim(),
                String(order.billing_city || '').trim(),
                String(order.billing_postcode || '').trim(),
                String(order.billing_county || '').trim(),
            ].filter((part) => part !== '');
            const orderNotes = String(order.notes || '').trim();
            const isCompany = Number(order.billing_is_company || 0) === 1;
            const companyRows = [];
            if (isCompany) {
                const companyName = String(order.billing_company_name || '').trim();
                const companyTaxId = String(order.billing_company_tax_id || '').trim();
                const companyRegNo = String(order.billing_company_registration_no || '').trim();
                if (companyName !== '') {
                    companyRows.push(`<div><small>Denumire firmă</small><p>${esc(companyName)}</p></div>`);
                }
                if (companyTaxId !== '') {
                    companyRows.push(`<div><small>CUI / Cod fiscal</small><p>${esc(companyTaxId)}</p></div>`);
                }
                if (companyRegNo !== '') {
                    companyRows.push(`<div><small>Reg. Comerțului (J)</small><p>${esc(companyRegNo)}</p></div>`);
                }
            }
            const trackingStatus = String(order.fan_tracking_status || '').trim();
            const trackingDate = String(order.fan_tracking_last_event_at || '').trim();
            const trackingLabel = trackingStatus !== ''
                ? `${esc(trackingStatus)}${trackingDate !== '' ? ' (' + esc(trackingDate) + ')' : ''}`
                : '-';
            const subtotalWithoutVat = toAmount(order.subtotal_without_vat || order.subtotal || 0);
            const vatTotal = toAmount(order.vat_total || 0);
            const couponDiscount = toAmount(order.discount_total || 0);
            const pointsDiscount = toAmount(order.loyalty_points_discount || 0);
            const pointsUsed = Math.max(0, Number(order.loyalty_points_used || 0) || 0);
            const shippingCost = toAmount(order.shipping_cost || 0);
            const shippingLabel = shippingCost <= 0.004 ? 'GRATUIT' : formatRon(shippingCost);
            const paymentMethodValue = String(order.payment_method || '').trim().toLowerCase();
            const paymentErrorValue = String(order.payment_error || '').trim();
            const euplatescId = String(order.euplatesc_ep_id || '').trim();
            const paymentStatusHtml = paymentMethodValue === 'cod'
                ? ''
                : `<div><small>Status plată</small><p>${esc(order.payment_status || 'unpaid')}</p></div>`
                  + (paymentErrorValue !== ''
                        ? `<div><small>Motiv eșec plată</small><p style="color:#b91c1c;">${esc(paymentErrorValue)}</p></div>`
                        : '')
                  + (euplatescId !== ''
                        ? `<div><small>ID tranzacție EuPlătesc</small><p>${esc(euplatescId)}</p></div>`
                        : '');
            let totalsHtml = `
                    <p><span>Subtotal (fără TVA)</span><strong>${formatRon(subtotalWithoutVat)}</strong></p>
                    <p><span>TVA</span><strong>${formatRon(vatTotal)}</strong></p>
            `;
            const couponCode = String(order.coupon_code || '').trim();
            if (couponDiscount > 0.004 || couponCode !== '') {
                const codeLabel = couponCode !== '' ? ` (${esc(couponCode)})` : '';
                totalsHtml += `<p><span>Reducere cupon${codeLabel}</span><strong>- ${formatRon(couponDiscount)}</strong></p>`;
            }
            if (pointsDiscount > 0.004) {
                const pointsLabel = pointsUsed > 0 ? ` (${pointsUsed} pct)` : '';
                totalsHtml += `<p><span>Reducere puncte</span><strong>- ${formatRon(pointsDiscount)}${pointsLabel}</strong></p>`;
            }
            totalsHtml += `
                    <p><span>Livrare</span><strong>${shippingLabel}</strong></p>
                    <p class="total"><span>Total</span><strong>${formatRon(order.total || 0)}</strong></p>
            `;
            const shipSame = Number(order.shipping_same_as_billing ?? 1) === 1;
            const shipName = [order.shipping_first_name, order.shipping_last_name].map((x) => String(x || '').trim()).filter(Boolean).join(' ');
            const shipParts = [order.shipping_address_line1, order.shipping_city, order.shipping_postcode, order.shipping_county].map((x) => String(x || '').trim()).filter(Boolean);
            const shippingHtml = shipSame
                ? `<p><small>Adresă livrare</small><br>Identică cu adresa de facturare</p>`
                : `<p><small>Adresă livrare</small><br>${shipName ? esc(shipName) + ' — ' : ''}${shipParts.length ? shipParts.map(esc).join(', ') : '-'}${String(order.shipping_phone || '').trim() ? ' — tel: ' + esc(order.shipping_phone) : ''}</p>`;

            const itemsHtml = items.map((it) => {
                const qty = Math.max(1, Number(it.quantity || 1) || 1);
                const lineTotal = toAmount(it.line_total || 0);
                let unitPrice = toAmount(it.unit_price || 0);
                if (unitPrice <= 0.0 && qty > 0) {
                    unitPrice = lineTotal / qty;
                }
                return orderItemRowHtml(Number(it.product_id || 0), it.product_name || '', qty, unitPrice);
            }).join('');

            const promoList = Array.isArray(window.promoProducts) ? window.promoProducts : [];
            const promoItems = Array.isArray(order.promo_items) ? order.promo_items : [];
            let promoHtml;
            if (promoList.length === 0 && promoItems.length === 0) {
                promoHtml = `<div class="order-promo-box" style="margin-top:14px;border-top:1px solid #eee;padding-top:12px;"><p style="margin:0 0 6px;"><small>Produse promoționale (intern)</small></p><p style="color:#6b7280;font-style:italic;font-size:13px;margin:0;">Niciun produs promoțional definit. <a href="/admin/promo-products">Adaugă în nomenclator</a>.</p></div>`;
            } else {
                const rows = (promoItems.length ? promoItems.map((it) => promoRowHtml(it.promo_product_id, it.quantity, it.name)) : [promoRowHtml('', 1, '')]).join('');
                promoHtml = `<div class="order-promo-box" style="margin-top:14px;border-top:1px solid #eee;padding-top:12px;">
                    <p style="margin:0 0 8px;"><small>Produse promoționale adăugate (intern)</small></p>
                    <div id="promo-rows-${order.id}">${rows}</div>
                    <div style="display:flex;gap:8px;margin-top:6px;align-items:center;">
                        <button type="button" onclick="addPromoRow(${order.id})" style="padding:6px 12px;background:#f1f5f9;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;font-size:13px;">+ Rând</button>
                        <button type="button" onclick="saveOrderPromo(${order.id})" style="padding:6px 14px;background:#1a7a5e;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:13px;">Salvează</button>
                        <span id="promo-status-${order.id}" style="font-size:13px;"></span>
                    </div>
                </div>`;
            }

            content.innerHTML = `
                <div class="order-modal-grid">
                    <div><small>Client</small><p id="order-client-name-${order.id}">${esc(order.billing_first_name)} ${esc(order.billing_last_name)}</p></div>
                    <div><small>Email</small><p id="order-client-email-${order.id}">${esc(order.billing_email)}</p></div>
                    <div><small>Telefon</small><p id="order-client-phone-${order.id}">${esc(order.billing_phone)}</p></div>
                    <div><small>Plată</small><p>${esc(order.payment_method)}</p></div>
                    ${paymentStatusHtml}
                    <div><small>AWB FAN</small><p>${esc(order.fan_awb || '-')}</p></div>
                    <div><small>Cupon folosit</small><p>${couponCode !== '' ? esc(couponCode) : '— (fără cupon)'}</p></div>
                </div>
                <p>
                  <small>Adresă</small>
                  <span id="order-address-display-${order.id}">${addressParts.length ? addressParts.map(esc).join(', ') : '-'}</span>
                  <button type="button" onclick="toggleAddressEdit(${order.id})" style="margin-left:8px;font-size:11px;padding:2px 7px;border:1px solid #d1d5db;border-radius:4px;background:#f9fafb;cursor:pointer;color:#374151;">✎ Editează</button>
                </p>
                <div id="order-address-form-${order.id}" style="display:none;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:10px;">
                  <div style="display:grid;gap:8px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                      <label style="font-size:12px;color:#374151;">Nume<br><input name="billing_first_name" value="${esc(order.billing_first_name||'')}" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;box-sizing:border-box;" class="addr-input-${order.id}"></label>
                      <label style="font-size:12px;color:#374151;">Prenume<br><input name="billing_last_name" value="${esc(order.billing_last_name||'')}" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;box-sizing:border-box;" class="addr-input-${order.id}"></label>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                      <label style="font-size:12px;color:#374151;">Email<br><input name="billing_email" type="email" value="${esc(order.billing_email||'')}" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;box-sizing:border-box;" class="addr-input-${order.id}"></label>
                      <label style="font-size:12px;color:#374151;">Telefon<br><input name="billing_phone" value="${esc(order.billing_phone||'')}" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;box-sizing:border-box;" class="addr-input-${order.id}"></label>
                    </div>
                    <label style="font-size:12px;color:#374151;">Adresă linie 1<br><input name="billing_address_line1" value="${esc(order.billing_address_line1||'')}" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;box-sizing:border-box;" class="addr-input-${order.id}"></label>
                    <label style="font-size:12px;color:#374151;">Adresă linie 2<br><input name="billing_address_line2" value="${esc(order.billing_address_line2||'')}" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;box-sizing:border-box;" class="addr-input-${order.id}"></label>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                      <label style="font-size:12px;color:#374151;">Oraș<br><input name="billing_city" value="${esc(order.billing_city||'')}" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;box-sizing:border-box;" class="addr-input-${order.id}"></label>
                      <label style="font-size:12px;color:#374151;">Cod poștal<br><input name="billing_postcode" value="${esc(order.billing_postcode||'')}" pattern="[0-9]{6}" maxlength="6" inputmode="numeric" required title="Exact 6 cifre" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;box-sizing:border-box;" class="addr-input-${order.id}"></label>
                      <label style="font-size:12px;color:#374151;">Județ<br><input name="billing_county" value="${esc(order.billing_county||'')}" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;box-sizing:border-box;" class="addr-input-${order.id}"></label>
                    </div>
                    <div style="display:flex;gap:8px;">
                      <button type="button" onclick="saveOrderAddress(${order.id})" style="padding:5px 14px;background:#1a7a5e;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:13px;">Salvează</button>
                      <button type="button" onclick="toggleAddressEdit(${order.id})" style="padding:5px 10px;background:#f1f5f9;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;font-size:13px;">Anulează</button>
                    </div>
                  </div>
                </div>
                ${companyRows.length ? `<div class="order-modal-grid">${companyRows.join('')}</div>` : ''}
                ${shippingHtml}
                <p><small>Tracking FAN</small><br>${trackingLabel}</p>
                <p><small>Observații</small><br>${orderNotes !== '' ? esc(orderNotes) : '-'}</p>
                <div class="order-items-box">
                    <div id="order-items-${order.id}">${itemsHtml}</div>
                    <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;align-items:center;">
                        <button type="button" onclick="addOrderItemRow(${order.id})" style="padding:6px 12px;background:#f1f5f9;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;font-size:13px;">+ Adaugă produs</button>
                        <button type="button" onclick="saveOrderItems(${order.id})" style="padding:6px 14px;background:#1a7a5e;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:13px;">Recalculează și salvează</button>
                        <span id="order-items-status-${order.id}" style="font-size:13px;"></span>
                    </div>
                </div>
                ${promoHtml}
                <div class="order-totals">
                    ${totalsHtml}
                </div>
            `;
            modal.classList.add('open');
        });
    });

    closeBtn?.addEventListener('click', () => modal?.classList.remove('open'));
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });
})();

function toggleAddressEdit(orderId) {
    const form = document.getElementById('order-address-form-' + orderId);
    if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function saveOrderAddress(orderId) {
    const form = document.getElementById('order-address-form-' + orderId);
    if (!form) return;
    const inputs = form.querySelectorAll('input[name]');
    const body = new URLSearchParams();
    inputs.forEach(inp => body.append(inp.name, inp.value));
    fetch('/admin/orders/' + orderId + '/address', {method:'POST', body})
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { alert(data.error || 'Eroare la salvare.'); return; }
            const a = data.address || {};
            const parts = [a.billing_address_line1, a.billing_address_line2, a.billing_city, a.billing_postcode, a.billing_county].filter(Boolean);
            const display = document.getElementById('order-address-display-' + orderId);
            if (display) display.textContent = parts.join(', ') || '-';
            const nameEl = document.getElementById('order-client-name-' + orderId);
            if (nameEl) nameEl.textContent = [a.billing_first_name, a.billing_last_name].filter(Boolean).join(' ');
            const emailEl = document.getElementById('order-client-email-' + orderId);
            if (emailEl) emailEl.textContent = a.billing_email || '';
            const phoneEl = document.getElementById('order-client-phone-' + orderId);
            if (phoneEl) phoneEl.textContent = a.billing_phone || '';
            // Actualizează și comanda memorată în buton, ca la redeschidere să apară corect.
            document.querySelectorAll('.view-order-btn').forEach((b) => {
                try {
                    const o = JSON.parse(b.dataset.order || '{}');
                    if (Number(o.id) === Number(orderId)) {
                        Object.assign(o, a);
                        b.dataset.order = JSON.stringify(o);
                    }
                } catch (e) {}
            });
            form.style.display = 'none';
        })
        .catch(() => alert('Eroare la comunicarea cu serverul.'));
}

/* --- Editare produse comandă (cantități + adăugare) --- */
function orderItemEsc(v){return String(v==null?'':v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');}
function orderMoney(v){ return (Number(v)||0).toFixed(2) + ' lei'; }
function orderProdOptions(selId){
    const list = Array.isArray(window.orderProducts) ? window.orderProducts : [];
    return '<option value="">— alege produs —</option>' + list.map((p) => `<option value="${Number(p.id)}" data-price="${Number(p.price)||0}"${Number(p.id)===Number(selId)?' selected':''}>${orderItemEsc(p.name)}</option>`).join('');
}
function orderItemRowHtml(pid, name, qty, unit){
    const u = Number(unit)||0;
    const q = Math.max(0, Number(qty)||0);
    const noVat = u > 0 ? (u/1.21).toFixed(4) : '—';
    return `<div class="oi-row" data-pid="${Number(pid)}" data-unit="${u}" style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9;">
        <span style="flex:1;">${orderItemEsc(name)}</span>
        <span class="oi-novat" style="color:#6b7280;font-size:12px;min-width:64px;text-align:right;">(${noVat})</span>
        <input type="number" min="0" value="${q}" class="oi-qty" style="width:60px;padding:4px 6px;border:1px solid #d1d5db;border-radius:5px;" oninput="recalcOrderItem(this)">
        <span class="oi-line" style="min-width:92px;text-align:right;font-weight:600;">${orderMoney(u*q)}</span>
        <button type="button" onclick="this.closest('.oi-row').remove()" title="Elimină" style="border:none;background:#fee2e2;color:#b91c1c;border-radius:5px;padding:4px 9px;cursor:pointer;">✕</button>
    </div>`;
}
function orderNewRowHtml(){
    return `<div class="oi-row" data-unit="0" style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9;">
        <select class="oi-pid" style="flex:1;padding:4px 6px;border:1px solid #d1d5db;border-radius:5px;" onchange="orderPickProduct(this)">${orderProdOptions('')}</select>
        <span class="oi-novat" style="color:#6b7280;font-size:12px;min-width:64px;text-align:right;">(—)</span>
        <input type="number" min="1" value="1" class="oi-qty" style="width:60px;padding:4px 6px;border:1px solid #d1d5db;border-radius:5px;" oninput="recalcOrderItem(this)">
        <span class="oi-line" style="min-width:92px;text-align:right;font-weight:600;">0.00 lei</span>
        <button type="button" onclick="this.closest('.oi-row').remove()" title="Elimină" style="border:none;background:#fee2e2;color:#b91c1c;border-radius:5px;padding:4px 9px;cursor:pointer;">✕</button>
    </div>`;
}
function recalcOrderItem(el){
    const row = el.closest('.oi-row');
    if (!row) return;
    const unit = Number(row.dataset.unit)||0;
    const qty = Number(row.querySelector('.oi-qty')?.value)||0;
    const lineEl = row.querySelector('.oi-line');
    if (lineEl) lineEl.textContent = orderMoney(unit*qty);
}
function orderPickProduct(sel){
    const row = sel.closest('.oi-row');
    if (!row) return;
    const opt = sel.options[sel.selectedIndex];
    const price = opt ? (Number(opt.dataset.price)||0) : 0;
    row.dataset.unit = price;
    const noVatEl = row.querySelector('.oi-novat');
    if (noVatEl) noVatEl.textContent = price > 0 ? `(${(price/1.21).toFixed(4)})` : '(—)';
    recalcOrderItem(sel);
}
function addOrderItemRow(orderId){
    const wrap = document.getElementById('order-items-' + orderId);
    if (wrap) wrap.insertAdjacentHTML('beforeend', orderNewRowHtml());
}
function saveOrderItems(orderId){
    const wrap = document.getElementById('order-items-' + orderId);
    if (!wrap) return;
    const body = new URLSearchParams();
    let count = 0;
    wrap.querySelectorAll('.oi-row').forEach((row) => {
        const pid = row.dataset.pid || row.querySelector('.oi-pid')?.value || '';
        const qty = row.querySelector('.oi-qty')?.value || '0';
        if (pid !== '' && Number(qty) > 0) { body.append('product_id[]', pid); body.append('quantity[]', qty); count++; }
    });
    const status = document.getElementById('order-items-status-' + orderId);
    if (count === 0) { if(status){status.style.color='#dc2626';status.textContent='Adaugă cel puțin un produs.';} return; }
    if (status) { status.style.color='#6b7280'; status.textContent='Se recalculează...'; }
    fetch('/admin/orders/' + orderId + '/items', {method:'POST', body})
        .then((r) => r.json())
        .then((data) => {
            if (!data.ok) { if(status){status.style.color='#dc2626';status.textContent=data.error||'Eroare';} return; }
            if (status) { status.style.color='#16a34a'; status.textContent='Salvat ✓ (subtotal ' + orderMoney(data.subtotal) + ', transport ' + orderMoney(data.shipping) + ', total ' + orderMoney(data.total) + '). Se reîncarcă...'; }
            setTimeout(() => { window.location.reload(); }, 1200);
        })
        .catch(() => { if(status){status.style.color='#dc2626';status.textContent='Eroare server';} });
}

/* --- Produse promoționale (intern) --- */
function promoEsc(v){return String(v==null?'':v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');}
function promoOptionsHtml(selId, selName){
    const list = Array.isArray(window.promoProducts) ? window.promoProducts : [];
    let html = '<option value="">— alege —</option>' + list.map((p) => `<option value="${Number(p.id)}"${Number(p.id)===Number(selId)?' selected':''}>${promoEsc(p.name)}</option>`).join('');
    // Dacă produsul salvat pe comandă nu mai e în nomenclatorul activ (dezactivat/șters),
    // afișează-l totuși ca opțiune selectată, ca să nu „dispară” selecția salvată.
    const sel = Number(selId) || 0;
    if (sel > 0 && !list.some((p) => Number(p.id) === sel)) {
        html += `<option value="${sel}" selected>${promoEsc(selName || ('#' + sel))} (inactiv)</option>`;
    }
    return html;
}
function promoRowHtml(pid, qty, name){
    const q = Math.max(1, Number(qty||1)||1);
    return `<div class="promo-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">
        <select class="promo-pid" style="flex:1;padding:6px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">${promoOptionsHtml(pid, name)}</select>
        <input type="number" min="1" value="${q}" class="promo-qty" style="width:72px;padding:6px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
        <button type="button" onclick="this.closest('.promo-row').remove()" style="padding:6px 10px;background:#fee2e2;color:#b91c1c;border:none;border-radius:5px;cursor:pointer;">✕</button>
    </div>`;
}
function addPromoRow(orderId){
    const wrap = document.getElementById('promo-rows-' + orderId);
    if (wrap) wrap.insertAdjacentHTML('beforeend', promoRowHtml('', 1));
}
function saveOrderPromo(orderId){
    const wrap = document.getElementById('promo-rows-' + orderId);
    if (!wrap) return;
    const body = new URLSearchParams();
    wrap.querySelectorAll('.promo-row').forEach((row) => {
        const sel = row.querySelector('.promo-pid');
        const qty = row.querySelector('.promo-qty');
        const pid = sel ? sel.value : '';
        if (pid !== '') { body.append('promo_product_id[]', pid); body.append('quantity[]', qty ? qty.value : '1'); }
    });
    const status = document.getElementById('promo-status-' + orderId);
    if (status) { status.style.color='#6b7280'; status.textContent='Se salvează...'; }
    fetch('/admin/orders/' + orderId + '/promo', {method:'POST', body})
        .then((r) => r.json())
        .then((data) => {
            if (!data.ok) { if(status){status.style.color='#dc2626';status.textContent=data.error||'Eroare';} return; }
            // Actualizează comanda memorată în buton, ca la redeschidere să apară fără refresh
            document.querySelectorAll('.view-order-btn').forEach((b) => {
                try {
                    const o = JSON.parse(b.dataset.order || '{}');
                    if (Number(o.id) === Number(orderId)) {
                        o.promo_items = Array.isArray(data.items) ? data.items : [];
                        b.dataset.order = JSON.stringify(o);
                    }
                } catch (e) {}
            });
            if (status) { status.style.color='#16a34a'; status.textContent='Salvat ✓'; setTimeout(() => { if(status) status.textContent=''; }, 2500); }
        })
        .catch(() => { if(status){status.style.color='#dc2626';status.textContent='Eroare server';} });
}

/* --- Popup: toate promoționalele clientului --- */
(function(){
    var modal = document.getElementById('client-promo-modal');
    if (!modal) { return; }
    var esc = function(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); };
    function closeModal(){ modal.style.display = 'none'; }
    document.getElementById('client-promo-close')?.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e){ if (e.target === modal) { closeModal(); } });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { closeModal(); } });

    document.querySelectorAll('.client-promo-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            var orderId = btn.getAttribute('data-order-id');
            var body = document.getElementById('client-promo-body');
            var title = document.getElementById('client-promo-title');
            if (title) title.textContent = 'Promoționale client';
            if (body) body.innerHTML = '<p style="color:#6b7280;">Se încarcă...</p>';
            modal.style.display = 'flex';
            fetch('/admin/orders/' + orderId + '/client-promo')
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d.ok) { if (body) body.innerHTML = '<p style="color:#dc2626;">' + esc(d.error || 'Eroare.') + '</p>'; return; }
                    if (title) title.textContent = 'Promoționale — ' + (d.client_name || 'client');
                    var items = Array.isArray(d.items) ? d.items : [];
                    var totalHtml = items.length
                        ? '<ul style="margin:0 0 4px;padding-left:20px;line-height:1.8;">' + items.map(function(i){ return '<li>' + esc(i.name) + ' × <strong>' + (Number(i.quantity)||0) + '</strong></li>'; }).join('') + '</ul>'
                        : '<p style="color:#6b7280;font-style:italic;">Niciun produs promoțional.</p>';
                    var orders = Array.isArray(d.orders) ? d.orders : [];
                    var ordersHtml = orders.map(function(o){
                        var its = Array.isArray(o.items) ? o.items.map(function(i){ return esc(i.name) + ' × ' + (Number(i.quantity)||1); }).join(', ') : '';
                        return '<div style="border-top:1px solid #eee;padding:8px 0;font-size:13px;"><strong>' + esc(o.order_number) + '</strong> <span style="color:#94a3b8;">' + esc(o.created_at) + '</span><br>' + its + '</div>';
                    }).join('');
                    if (body) body.innerHTML =
                        (d.email ? '<p style="margin:0 0 12px;color:#6b7280;font-size:13px;">' + esc(d.email) + '</p>' : '') +
                        '<p style="margin:0 0 6px;font-weight:700;">Total primite (toate comenzile):</p>' + totalHtml +
                        (orders.length ? '<p style="margin:16px 0 4px;font-weight:700;">Pe comenzi:</p>' + ordersHtml : '');
                })
                .catch(function(){ if (body) body.innerHTML = '<p style="color:#dc2626;">Eroare de comunicare.</p>'; });
        });
    });
})();
</script>
