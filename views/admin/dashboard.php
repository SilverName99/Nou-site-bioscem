<section class="panel">
    <h1>Dashboard</h1>
    <p>Panou de administrare pentru magazinul tău online.</p>

    <?php $recentUsers = is_array($recentUsers ?? null) ? $recentUsers : []; ?>
    <div class="dashboard-cards">
        <div class="dashboard-card">
            <div>
                <strong><?= (int) ($metrics['products'] ?? 0) ?></strong>
                <p>Produse</p>
            </div>
            <span class="dashboard-card-icon">
                <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>
            </span>
        </div>
        <div class="dashboard-card">
            <div>
                <strong><?= (int) ($metrics['orders'] ?? 0) ?></strong>
                <p>Comenzi</p>
            </div>
            <span class="dashboard-card-icon">
                <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="19" r="1.6"/><circle cx="17" cy="19" r="1.6"/><path d="M4 5h2l2.1 9.2h9.9l2-7.2H7.2"/></svg>
            </span>
        </div>
        <a class="dashboard-card dashboard-card-link" href="/admin/users">
            <div>
                <strong><?= (int) ($metrics['users'] ?? 0) ?></strong>
                <p>Utilizatori</p>
            </div>
            <span class="dashboard-card-icon">
                <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 18a5.5 5.5 0 0 1 11 0"/><circle cx="17" cy="9" r="2.3"/><path d="M14.5 18a4.2 4.2 0 0 1 6 0"/></svg>
            </span>
        </a>
    </div>

    <div class="recent-orders-card">
        <div class="recent-orders-head">
            <h2>Comenzi recente</h2>
            <a href="/admin/orders">Vezi toate</a>
        </div>
        <?php if (!empty($recentOrder)): ?>
            <div class="recent-order-row">
                <div>
                    <strong><?= htmlspecialchars(trim((string) $recentOrder['billing_first_name'] . ' ' . (string) $recentOrder['billing_last_name']), ENT_QUOTES) ?></strong>
                    <p><?= htmlspecialchars((string) $recentOrder['order_number'], ENT_QUOTES) ?></p>
                </div>
                <div class="right">
                    <strong><?= number_format((float) $recentOrder['total'], 2) ?> RON</strong>
                    <span class="status-pill"><?= htmlspecialchars((string) $recentOrder['status'], ENT_QUOTES) ?></span>
                </div>
            </div>
        <?php else: ?>
            <p>Nu există comenzi încă.</p>
        <?php endif; ?>
    </div>

    <div class="recent-orders-card">
        <div class="recent-orders-head">
            <h2>Utilizatori recenți</h2>
            <a href="/admin/users">Vezi toți</a>
        </div>
        <?php if ($recentUsers !== []): ?>
            <div class="recent-users-list">
                <?php foreach ($recentUsers as $user): ?>
                    <a class="recent-user-row" href="/admin/users/<?= (int) ($user['id'] ?? 0) ?>">
                        <div>
                            <strong><?= htmlspecialchars(trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')), ENT_QUOTES) ?></strong>
                            <p><?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES) ?></p>
                        </div>
                        <div class="right">
                            <strong><?= (int) ($user['orders_count'] ?? 0) ?> comenzi</strong>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>Nu există utilizatori încă.</p>
        <?php endif; ?>
    </div>
</section>
