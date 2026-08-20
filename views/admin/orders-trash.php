<section class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h1>Coș comenzi</h1>
            <p>Restaurează comenzile sau șterge-le definitiv.</p>
        </div>
        <a class="btn btn-secondary" href="/admin/orders">Înapoi la comenzi</a>
    </div>

    <?php if ($orders === []): ?>
        <div class="trash-empty">
            <strong>Coșul e gol.</strong>
            <div style="margin-top:6px;">Comenzile mutate în coș apar aici și pot fi restaurate oricând.</div>
        </div>
    <?php else: ?>
        <p style="color:#64748b;margin:0 0 12px;">
            <?= count($orders) ?> <?= count($orders) === 1 ? 'comandă' : 'comenzi' ?> în coș.
            Restaurarea aduce comanda înapoi în listă exact așa cum era.
        </p>

        <div class="trash-list">
            <?php foreach ($orders as $order): ?>
                <?php
                    $paymentStatus = (string) ($order['payment_status'] ?? 'unpaid');
                    $paymentClass = $paymentStatus === 'paid' ? 'ok' : ($paymentStatus === 'failed' ? 'off' : '');
                    $deletedAt = trim((string) ($order['deleted_at'] ?? ''));
                    $client = trim((string) ($order['billing_first_name'] ?? '') . ' ' . (string) ($order['billing_last_name'] ?? ''));
                    // Ora exactă rămâne în `title`: în listă e de ajuns ziua.
                    $scurt = static function (string $data): string {
                        $t = strtotime($data);
                        return $t === false ? $data : date('d.m.Y H:i', $t);
                    };
                ?>
                <article class="trash-row">
                    <div>
                        <div class="trash-row__head">
                            <span class="trash-row__number"><?= htmlspecialchars((string) $order['order_number'], ENT_QUOTES) ?></span>
                            <span class="status-pill"><?= htmlspecialchars((string) $order['status'], ENT_QUOTES) ?></span>
                            <span class="status-pill ok"><?= htmlspecialchars((string) $order['payment_method'], ENT_QUOTES) ?></span>
                            <span class="status-pill <?= $paymentClass ?>"><?= htmlspecialchars($paymentStatus, ENT_QUOTES) ?></span>
                        </div>
                        <p class="trash-row__client"><?= htmlspecialchars($client !== '' ? $client : 'Fără nume', ENT_QUOTES) ?></p>
                        <div class="trash-row__meta">
                            <span title="<?= htmlspecialchars((string) $order['created_at'], ENT_QUOTES) ?>">
                                Creată: <?= htmlspecialchars($scurt((string) $order['created_at']), ENT_QUOTES) ?>
                            </span>
                            <?php if ($deletedAt !== ''): ?>
                                <span class="trash-row__deleted" title="<?= htmlspecialchars($deletedAt, ENT_QUOTES) ?>">
                                    Ștearsă: <?= htmlspecialchars($scurt($deletedAt), ENT_QUOTES) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="trash-row__total"><?= number_format((float) $order['total'], 2) ?> RON</div>
                    <div class="trash-row__actions">
                        <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/restore">
                            <button class="btn btn-secondary" type="submit">Refacere</button>
                        </form>
                        <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/force-delete" onsubmit="return confirm('Ștergere definitivă comandă? Această acțiune nu poate fi anulată.');">
                            <button type="submit" class="icon-btn danger" title="Ștergere definitivă">🗑</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
