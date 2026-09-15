<?php
/**
 * Precomenzile: comenzile care așteaptă marfa.
 *
 * Ele există ca orice comandă — clientul a plătit sau va plăti —, dar nu s-au
 * dus în ERP, fiindcă acolo n-ar avea ce rezerva. Pagina asta e locul unde
 * cineva decide că marfa a venit și le dă drumul, una câte una.
 *
 * @var array<int, array<string, mixed>> $comenzi
 * @var array<int, array<string, mixed>> $eliberate
 * @var array<int, array<int, array<string, mixed>>> $produse
 */
$statusLabels = [
    'pending' => 'În așteptare',
    'pending_payment' => 'Plată în așteptare',
    'processing' => 'În procesare',
    'completed' => 'Finalizată',
    'cancelled' => 'Anulată',
    'refunded' => 'Rambursată',
    'failed' => 'Eșuată',
];
$bani = static fn(mixed $v): string => number_format((float) $v, 2, ',', '.') . ' RON';
$dataRo = static function (mixed $v): string {
    $t = strtotime((string) $v);
    return $t !== false ? date('d.m.Y H:i', $t) : '—';
};
$randProduse = static function (int $orderId) use ($produse): string {
    $linii = $produse[$orderId] ?? [];
    if ($linii === []) {
        return '<span style="color:#94a3b8;">—</span>';
    }
    $out = [];
    foreach ($linii as $l) {
        $nume = htmlspecialchars((string) ($l['product_name'] ?? ''), ENT_QUOTES);
        $cant = max(1, (int) ($l['quantity'] ?? 1));
        // Produsul care a pus comanda în așteptare se vede din prima: restul
        // sunt doar pasagerii lui.
        $ePrecomanda = (int) ($l['preorder_enabled'] ?? 0) === 1;
        $out[] = $ePrecomanda
            ? '<strong style="color:#b45309;">' . $cant . ' × ' . $nume . '</strong>'
            : $cant . ' × ' . $nume;
    }
    return implode('<br>', $out);
};
?>

<div class="page-head">
    <div>
        <h1>Precomenzi</h1>
        <p class="muted">
            Comenzi cu produse valabile pentru precomandă. Nu pleacă în „Comenzi site"
            din ERP până nu le eliberezi de aici — marfa încă n-a venit, iar ERP-ul
            n-ar avea ce rezerva.
        </p>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>În așteptare (<?= count($comenzi) ?>)</h2>
    </div>

    <?php if ($comenzi === []): ?>
        <p class="muted" style="padding:16px;">
            Nicio precomandă în așteptare. Când un client comandă un produs bifat
            „Valabil pentru precomandă", comanda apare aici.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Comandă</th>
                        <th>Client</th>
                        <th>Produse</th>
                        <th>Sumă</th>
                        <th>Stare</th>
                        <th>Acțiune</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($comenzi as $c): ?>
                    <?php
                    $id = (int) ($c['id'] ?? 0);
                    $status = strtolower((string) ($c['status'] ?? ''));
                    $platit = strtolower((string) ($c['payment_status'] ?? '')) === 'paid';
                    ?>
                    <tr>
                        <td>
                            <a href="/admin/orders?q=<?= urlencode((string) ($c['order_number'] ?? '')) ?>">
                                #<?= htmlspecialchars((string) ($c['order_number'] ?? $id), ENT_QUOTES) ?>
                            </a>
                            <div class="muted" style="font-size:12px;"><?= $dataRo($c['created_at'] ?? '') ?></div>
                        </td>
                        <td>
                            <?= htmlspecialchars(trim((string) ($c['billing_first_name'] ?? '') . ' ' . (string) ($c['billing_last_name'] ?? '')), ENT_QUOTES) ?>
                            <div class="muted" style="font-size:12px;">
                                <?= htmlspecialchars((string) ($c['billing_email'] ?? ''), ENT_QUOTES) ?>
                            </div>
                        </td>
                        <td style="font-size:13px;"><?= $randProduse($id) ?></td>
                        <td><?= $bani($c['total'] ?? 0) ?></td>
                        <td>
                            <span class="status-pill"><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES) ?></span>
                            <div class="muted" style="font-size:12px;margin-top:4px;">
                                <?= $platit ? 'Plătită' : 'Neplătită' ?>
                                · <?= htmlspecialchars((string) ($c['payment_method'] ?? ''), ENT_QUOTES) ?>
                            </div>
                        </td>
                        <td>
                            <form method="post" action="/admin/precomenzi/<?= $id ?>/elibereaza"
                                  onsubmit="return confirm('Trimiți comanda în ERP? Marfa trebuie să fie disponibilă.');">
                                <button class="btn" type="submit">Trimite în ERP</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($eliberate !== []): ?>
<div class="card" style="margin-top:20px;">
    <div class="card-head">
        <h2>Eliberate (<?= count($eliberate) ?>)</h2>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Comandă</th>
                    <th>Client</th>
                    <th>Produse</th>
                    <th>Sumă</th>
                    <th>Eliberată</th>
                    <th>ERP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($eliberate as $c): ?>
                <?php $id = (int) ($c['id'] ?? 0); ?>
                <tr>
                    <td>
                        <a href="/admin/orders?q=<?= urlencode((string) ($c['order_number'] ?? '')) ?>">
                            #<?= htmlspecialchars((string) ($c['order_number'] ?? $id), ENT_QUOTES) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars(trim((string) ($c['billing_first_name'] ?? '') . ' ' . (string) ($c['billing_last_name'] ?? '')), ENT_QUOTES) ?></td>
                    <td style="font-size:13px;"><?= $randProduse($id) ?></td>
                    <td><?= $bani($c['total'] ?? 0) ?></td>
                    <td><?= $dataRo($c['preorder_released_at'] ?? '') ?></td>
                    <td>
                        <?php $erp = (string) ($c['erp_status'] ?? ''); ?>
                        <?php if ($erp === 'sent'): ?>
                            <span style="color:#16a34a;">trimisă<?= $c['erp_order_id'] ? ' · ' . htmlspecialchars((string) $c['erp_order_id'], ENT_QUOTES) : '' ?></span>
                        <?php else: ?>
                            <span style="color:#b45309;"><?= htmlspecialchars($erp !== '' ? $erp : '—', ENT_QUOTES) ?></span>
                            <?php if (trim((string) ($c['erp_last_error'] ?? '')) !== ''): ?>
                                <div class="muted" style="font-size:12px;">
                                    <?= htmlspecialchars((string) $c['erp_last_error'], ENT_QUOTES) ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
