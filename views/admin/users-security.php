<?php
$logs = is_array($logs ?? null) ? $logs : [];
$search = trim((string) ($search ?? ''));
$source = in_array((string) ($source ?? 'all'), ['all', 'register', 'checkout'], true) ? (string) $source : 'all';
$status = trim((string) ($status ?? 'all'));
$page = max(1, (int) ($page ?? 1));
$perPage = (int) ($perPage ?? 25);
$total = max(0, (int) ($total ?? 0));
$totalPages = max(1, (int) ($totalPages ?? 1));
$perPageOptions = is_array($perPageOptions ?? null) ? $perPageOptions : [10, 25, 50, 100, 200, 500];
$allowedStatuses = ['all', 'allowed', 'bot_honeypot', 'invalid_payload', 'token_missing', 'too_fast', 'expired', 'timestamp_mismatch', 'rate_limited'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}
$sourceLabels = [
    'register' => 'Înregistrare',
    'checkout' => 'Checkout',
];
$statusLabels = [
    'allowed' => 'Permis',
    'bot_honeypot' => 'Bot (honeypot)',
    'invalid_payload' => 'Payload invalid',
    'token_missing' => 'Token lipsă',
    'too_fast' => 'Prea rapid',
    'expired' => 'Expirat',
    'timestamp_mismatch' => 'Timestamp invalid',
    'rate_limited' => 'Rate limit',
];
$buildUrl = static function (array $params = []) use ($search, $source, $status, $page, $perPage): string {
    $query = [
        'q' => $search,
        'source' => $source,
        'status' => $status,
        'page' => $page,
        'per_page' => $perPage,
    ];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    if (($query['q'] ?? '') === '') {
        unset($query['q']);
    }
    return '/admin/users/security' . ($query !== [] ? ('?' . http_build_query($query)) : '');
};
$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);
$pages = [];
for ($i = $paginationStart; $i <= $paginationEnd; $i++) {
    $pages[] = $i;
}
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Securitate formulare</h1>
            <p>Monitorizare tentative register/checkout pentru detecție bot/spam.</p>
        </div>
    </div>

    <article class="panel" style="margin:12px 0;">
        <form method="get" action="/admin/users/security" class="admin-filters-inline">
            <label class="admin-filter-field">
                <span>Sursă</span>
                <select name="source">
                    <option value="all" <?= $source === 'all' ? 'selected' : '' ?>>Toate</option>
                    <option value="register" <?= $source === 'register' ? 'selected' : '' ?>>Înregistrare</option>
                    <option value="checkout" <?= $source === 'checkout' ? 'selected' : '' ?>>Checkout</option>
                </select>
            </label>
            <label class="admin-filter-field">
                <span>Status</span>
                <select name="status">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Toate</option>
                    <?php foreach ($allowedStatuses as $statusValue): ?>
                        <?php if ($statusValue === 'all') { continue; } ?>
                        <option value="<?= htmlspecialchars($statusValue, ENT_QUOTES) ?>" <?= $status === $statusValue ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($statusLabels[$statusValue] ?? $statusValue), ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-filter-field admin-filter-field--grow">
                <span>Căutare</span>
                <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="IP sau user-agent">
            </label>
            <label class="admin-per-page-control">
                <span>Pe pagină</span>
                <select name="per_page">
                    <?php foreach ($perPageOptions as $option): ?>
                        <option value="<?= (int) $option ?>" <?= (int) $option === $perPage ? 'selected' : '' ?>><?= (int) $option ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <input type="hidden" name="page" value="1">
            <button class="btn" type="submit">Aplică</button>
            <a class="btn btn-secondary" href="/admin/users/security">Resetează</a>
        </form>
    </article>

    <article class="panel" style="margin:12px 0;">
        <div class="users-list-toolbar" style="margin-bottom:8px;">
            <small style="color:#64748b;">Total evenimente: <strong><?= (int) $total ?></strong></small>
        </div>

        <div class="users-table-wrap">
            <table class="table users-table">
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Sursă</th>
                    <th>Status</th>
                    <th>IP</th>
                    <th>User Agent</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($logs === []): ?>
                    <tr>
                        <td colspan="5">Nu există înregistrări pentru filtrele curente.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $row): ?>
                        <?php
                        $rowSource = trim((string) ($row['source'] ?? ''));
                        $rowStatus = trim((string) ($row['status'] ?? ''));
                        $statusClass = in_array($rowStatus, ['bot_honeypot', 'invalid_payload', 'token_missing', 'too_fast', 'expired', 'timestamp_mismatch', 'rate_limited'], true)
                            ? 'off'
                            : 'ok';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($row['created_at'] ?? '-'), ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars((string) ($sourceLabels[$rowSource] ?? $rowSource ?: '-'), ENT_QUOTES) ?></td>
                            <td>
                                <span class="status-pill <?= $statusClass ?>">
                                    <?= htmlspecialchars((string) ($statusLabels[$rowStatus] ?? $rowStatus ?: '-'), ENT_QUOTES) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars((string) ($row['ip_address'] ?? '-'), ENT_QUOTES) ?></td>
                            <td style="max-width:460px;white-space:normal;word-break:break-word;">
                                <?= htmlspecialchars((string) (($row['user_agent'] ?? '') !== '' ? $row['user_agent'] : '-'), ENT_QUOTES) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="admin-pagination" style="display:flex;align-items:center;gap:6px;justify-content:flex-end;margin-top:10px;">
                <a class="btn btn-secondary <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($buildUrl(['page' => max(1, $page - 1)]), ENT_QUOTES) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>←</a>
                <?php foreach ($pages as $p): ?>
                    <a class="btn <?= $p === $page ? '' : 'btn-secondary' ?>" href="<?= htmlspecialchars($buildUrl(['page' => $p]), ENT_QUOTES) ?>"><?= (int) $p ?></a>
                <?php endforeach; ?>
                <a class="btn btn-secondary <?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($buildUrl(['page' => min($totalPages, $page + 1)]), ENT_QUOTES) ?>" <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>→</a>
            </div>
        <?php endif; ?>
    </article>
</section>
