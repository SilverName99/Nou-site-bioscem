<?php
$reviews = is_array($reviews ?? null) ? $reviews : [];
$counts = is_array($counts ?? null) ? $counts : ['pending' => 0, 'approved' => 0, 'all' => 0];
$status = (string) ($status ?? 'pending');
$search = trim((string) ($search ?? ''));
$panel = in_array((string) ($panel ?? 'list'), ['list', 'import'], true) ? (string) $panel : 'list';
$page = max(1, (int) ($page ?? 1));
$perPage = (int) ($perPage ?? 25);
$perPageOptions = is_array($perPageOptions ?? null) ? $perPageOptions : [10, 25, 50, 100, 200, 500];
$totalReviews = max(0, (int) ($totalReviews ?? count($reviews)));
$totalPages = max(1, (int) ($totalPages ?? 1));
$buildReviewsUrl = static function (array $params) use ($status, $search, $perPage, $panel): string {
    $query = [
        'status' => $status,
        'per_page' => $perPage,
        'panel' => $panel,
    ];
    if ($search !== '') {
        $query['q'] = $search;
    }
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }
        $query[$key] = $value;
    }
    return '/admin/products/reviews?' . http_build_query($query);
};
$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Recenzii produse</h1>
            <p>Review-urile noi intră în pending și se aprobă manual înainte de publicare.</p>
        </div>
    </div>

    <div class="store-settings-tabs" style="margin-bottom:12px;">
        <a class="btn btn-secondary <?= $panel === 'list' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildReviewsUrl(['panel' => 'list', 'page' => 1]), ENT_QUOTES) ?>">Toate recenziile</a>
        <a class="btn btn-secondary <?= $panel === 'import' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildReviewsUrl(['panel' => 'import', 'page' => 1]), ENT_QUOTES) ?>">Import recenzii</a>
    </div>

    <?php if ($panel === 'import'): ?>
        <form method="post" action="/admin/products/reviews/import" enctype="multipart/form-data" class="form-grid" style="margin-bottom:12px;">
            <div class="field">
                <label>Import recenzii (CSV/XLSX)</label>
                <input type="file" name="product_reviews_file" accept=".csv,.xlsx" required>
                <small class="muted">Mapare după <code>product_slug</code>. Acceptă delimitator <code>;</code> sau <code>,</code>.</small>
            </div>
            <div style="grid-column:1/-1;display:flex;gap:8px;">
                <button class="btn btn-secondary" type="submit">Importă recenzii</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($panel === 'list'): ?>
        <form method="get" action="/admin/products/reviews" class="admin-filters-inline" style="margin-bottom:12px;">
            <input type="hidden" name="panel" value="list">
            <div class="admin-filter-field">
                <span>Filtru status</span>
                <select name="status">
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>
                        Pending (<?= (int) ($counts['pending'] ?? 0) ?>)
                    </option>
                    <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>
                        Aprobate (<?= (int) ($counts['approved'] ?? 0) ?>)
                    </option>
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                        Toate (<?= (int) ($counts['all'] ?? 0) ?>)
                    </option>
                </select>
            </div>
            <div class="admin-filter-field admin-filter-field--search">
                <span>Căutare</span>
                <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="produs, nume, email, text review">
            </div>
            <div class="admin-filter-field">
                <span>Pe pagină</span>
                <select name="per_page">
                    <?php foreach ($perPageOptions as $opt): ?>
                        <?php $optValue = (int) $opt; ?>
                        <option value="<?= $optValue ?>" <?= $perPage === $optValue ? 'selected' : '' ?>><?= $optValue ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-filter-actions">
                <button class="btn" type="submit">Aplică filtre</button>
                <a class="btn btn-secondary" href="/admin/products/reviews?status=pending&panel=list&per_page=<?= $perPage ?>">Reset</a>
            </div>
        </form>

        <p style="margin:0 0 10px;color:#64748b;">
            Afișare <?= count($reviews) ?> din <?= $totalReviews ?> recenzii.
        </p>

        <table class="table">
            <thead>
            <tr>
                <th style="width:90px;">ID</th>
                <th style="width:220px;">Produs</th>
                <th style="width:140px;">Sursă</th>
                <th style="width:200px;">Client</th>
                <th style="width:90px;">Rating</th>
                <th>Review</th>
                <th style="width:170px;">Data</th>
                <th style="width:220px;">Acțiuni</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($reviews === []): ?>
                <tr><td colspan="8">Nu există review-uri pentru filtrul selectat.</td></tr>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                    <?php
                    $id = (int) ($review['id'] ?? 0);
                    $isApproved = (int) ($review['is_approved'] ?? 0) === 1;
                    $sourceRaw = trim((string) ($review['source'] ?? ''));
                    if ($sourceRaw === 'qr_form') {
                        $sourceLabel = 'Formular QR';
                    } elseif ($sourceRaw === 'import') {
                        $sourceLabel = 'Import';
                    } elseif ($sourceRaw === 'product_page' || $sourceRaw === '') {
                        $sourceLabel = 'Pagina produs';
                    } else {
                        $sourceLabel = ucfirst(str_replace('_', ' ', $sourceRaw));
                    }
                    ?>
                    <tr>
                        <td>#<?= $id ?></td>
                        <td>
                            <strong><?= htmlspecialchars((string) ($review['product_name'] ?? 'Produs'), ENT_QUOTES) ?></strong><br>
                            <small>/produs/<?= htmlspecialchars((string) ($review['product_slug'] ?? ''), ENT_QUOTES) ?></small>
                        </td>
                        <td><?= htmlspecialchars($sourceLabel, ENT_QUOTES) ?></td>
                        <td>
                            <strong><?= htmlspecialchars((string) ($review['user_name'] ?? 'Client'), ENT_QUOTES) ?></strong><br>
                            <small><?= htmlspecialchars((string) ($review['user_email'] ?? ''), ENT_QUOTES) ?></small>
                        </td>
                        <td><?= str_repeat('★', max(1, min(5, (int) ($review['rating'] ?? 5)))) ?></td>
                        <td><?= nl2br(htmlspecialchars((string) ($review['review_text'] ?? ''), ENT_QUOTES)) ?></td>
                        <td><?= htmlspecialchars((string) ($review['created_at'] ?? ''), ENT_QUOTES) ?></td>
                        <td>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <?php if (!$isApproved): ?>
                                    <form method="post" action="/admin/products/reviews">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
                                        <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                        <input type="hidden" name="page" value="<?= $page ?>">
                                        <input type="hidden" name="per_page" value="<?= $perPage ?>">
                                        <input type="hidden" name="panel" value="<?= htmlspecialchars($panel, ENT_QUOTES) ?>">
                                        <button class="btn btn-secondary" type="submit">Aprobă</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="/admin/products/reviews">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="action" value="pending">
                                        <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
                                        <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                        <input type="hidden" name="page" value="<?= $page ?>">
                                        <input type="hidden" name="per_page" value="<?= $perPage ?>">
                                        <input type="hidden" name="panel" value="<?= htmlspecialchars($panel, ENT_QUOTES) ?>">
                                        <button class="btn btn-secondary" type="submit">Retrage</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="/admin/products/reviews" onsubmit="return confirm('Ștergi review-ul?');">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                    <input type="hidden" name="page" value="<?= $page ?>">
                                    <input type="hidden" name="per_page" value="<?= $perPage ?>">
                                    <input type="hidden" name="panel" value="<?= htmlspecialchars($panel, ENT_QUOTES) ?>">
                                    <button class="icon-btn danger" type="submit" title="Șterge">🗑</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <nav class="product-reviews-pagination" aria-label="Paginare recenzii admin">
                <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildReviewsUrl(['page' => 1]), ENT_QUOTES) ?>" <?= $page <= 1 ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>« Prima</a>
                <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildReviewsUrl(['page' => max(1, $page - 1)]), ENT_QUOTES) ?>" <?= $page <= 1 ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>‹</a>
                <?php for ($p = $paginationStart; $p <= $paginationEnd; $p++): ?>
                    <a class="product-reviews-pagination__btn <?= $p === $page ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildReviewsUrl(['page' => $p]), ENT_QUOTES) ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildReviewsUrl(['page' => min($totalPages, $page + 1)]), ENT_QUOTES) ?>" <?= $page >= $totalPages ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>›</a>
                <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildReviewsUrl(['page' => $totalPages]), ENT_QUOTES) ?>" <?= $page >= $totalPages ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>Ultima »</a>
                <span class="product-reviews-pagination__label">Pagina <?= $page ?> / <?= $totalPages ?></span>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
