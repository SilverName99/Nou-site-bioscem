<section class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h1>Coș comenzi</h1>
            <p>Restaurează comenzile sau șterge-le definitiv.</p>
        </div>
        <a class="btn btn-secondary" href="/admin/orders">Înapoi la comenzi</a>
    </div>

    <div class="order-list">
        <?php foreach ($orders as $order): ?>
            <?php
                $paymentStatus = (string) ($order['payment_status'] ?? 'unpaid');
                $paymentClass = $paymentStatus === 'paid' ? 'ok' : ($paymentStatus === 'failed' ? 'off' : '');
                $deletedAt = trim((string) ($order['deleted_at'] ?? ''));
            ?>
            <article class="order-row">
                <div class="order-main">
                    <div class="line-1">
                        <strong><?= htmlspecialchars((string) $order['order_number'], ENT_QUOTES) ?></strong>
                        <span class="status-pill"><?= htmlspecialchars((string) $order['status'], ENT_QUOTES) ?></span>
                        <span class="status-pill ok"><?= htmlspecialchars((string) $order['payment_method'], ENT_QUOTES) ?></span>
                        <span class="status-pill <?= $paymentClass ?>"><?= htmlspecialchars($paymentStatus, ENT_QUOTES) ?></span>
                    </div>
                    <p><?= htmlspecialchars(trim((string) $order['billing_first_name'] . ' ' . (string) $order['billing_last_name']), ENT_QUOTES) ?></p>
                    <small>Creată: <?= htmlspecialchars((string) $order['created_at'], ENT_QUOTES) ?></small>
                    <?php if ($deletedAt !== ''): ?>
                        <small style="display:block;color:#b91c1c;">Ștearsă la: <?= htmlspecialchars($deletedAt, ENT_QUOTES) ?></small>
                    <?php endif; ?>
                </div>
                <div class="order-total">
                    <strong><?= number_format((float) $order['total'], 2) ?> RON</strong>
                </div>
                <div class="order-actions">
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
</section>
