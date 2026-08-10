<?php
$items = is_array($items ?? null) ? $items : [];
$search = trim((string) ($search ?? ''));
$page = max(1, (int) ($page ?? 1));
$perPage = (int) ($perPage ?? 25);
$totalItems = max(0, (int) ($totalItems ?? count($items)));
$totalPages = max(1, (int) ($totalPages ?? 1));
$perPageOptions = is_array($perPageOptions ?? null) ? $perPageOptions : [10, 25, 50, 100, 200, 500];
$buildUrl = static function (array $params) use ($search, $perPage): string {
    $query = [
        'per_page' => $perPage,
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
    return '/admin/users/gdpr-agreements?' . http_build_query($query);
};
$buildExportUrl = static function () use ($search): string {
    $query = [];
    if ($search !== '') {
        $query['q'] = $search;
    }
    return '/admin/users/gdpr-agreements/export' . ($query !== [] ? ('?' . http_build_query($query)) : '');
};
$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);
?>

<section class="panel">
    <div class="section-head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
        <div>
            <h1 style="margin:0;">Acorduri GDPR</h1>
            <p style="margin:6px 0 0;color:#64748b;">Înregistrări salvate din formularul public de acord GDPR.</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="btn btn-secondary" href="<?= htmlspecialchars($buildExportUrl(), ENT_QUOTES) ?>">Export Excel (cu semnături)</a>
            <a class="btn btn-secondary" href="/admin/users">Înapoi la Utilizatori</a>
        </div>
    </div>

    <form method="get" action="/admin/users/gdpr-agreements" class="admin-filters-inline" style="margin:12px 0;">
        <div class="admin-filter-field admin-filter-field--search">
            <span>Căutare</span>
            <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="nume, prenume, email, telefon, instituție">
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
            <a class="btn btn-secondary" href="/admin/users/gdpr-agreements?per_page=<?= $perPage ?>">Reset</a>
        </div>
    </form>

    <p style="margin:0 0 10px;color:#64748b;">Afișare <?= count($items) ?> din <?= $totalItems ?> acorduri.</p>

    <table class="table">
        <thead>
        <tr>
            <th style="width:80px;">ID</th>
            <th style="width:180px;">Subsemnatul</th>
            <th style="width:170px;">Date personale</th>
            <th style="width:220px;">Contact</th>
            <th>Instituție / Medic</th>
            <th style="width:130px;">Data</th>
            <th style="width:140px;">Semnătură</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($items === []): ?>
            <tr>
                <td colspan="7">Nu există acorduri GDPR pentru filtrul selectat.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <?php
                $signatureDataUrl = trim((string) ($item['signature_data_url'] ?? ''));
                $hasSignature = $signatureDataUrl !== '' && str_starts_with($signatureDataUrl, 'data:image/');
                ?>
                <tr>
                    <td>#<?= (int) ($item['id'] ?? 0) ?></td>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['subiect_nume_complet'] ?? ''), ENT_QUOTES) ?></strong><br>
                        <small>CI <?= htmlspecialchars((string) ($item['ci_serie'] ?? ''), ENT_QUOTES) ?> <?= htmlspecialchars((string) ($item['ci_numar'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['nume'] ?? ''), ENT_QUOTES) ?> <?= htmlspecialchars((string) ($item['prenume'] ?? ''), ENT_QUOTES) ?></strong><br>
                        <small>CNP: <?= htmlspecialchars((string) ($item['cnp'] ?? ''), ENT_QUOTES) ?></small><br>
                        <small>CUIM: <?= htmlspecialchars((string) ($item['cuim'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['telefon'] ?? ''), ENT_QUOTES) ?></strong><br>
                        <small><?= htmlspecialchars((string) ($item['email'] ?? ''), ENT_QUOTES) ?></small><br>
                        <small><?= htmlspecialchars((string) ($item['adresa_corespondenta'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['institutie_medicala'] ?? ''), ENT_QUOTES) ?></strong><br>
                        <small><?= htmlspecialchars((string) ($item['institutie_activitate'] ?? ''), ENT_QUOTES) ?></small><br>
                        <small><?= htmlspecialchars((string) ($item['institutie_adresa'] ?? ''), ENT_QUOTES) ?></small><br>
                        <small><?= htmlspecialchars((string) ($item['tip_medic'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($item['specializare'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['data_semnare'] ?? ''), ENT_QUOTES) ?></strong><br>
                        <small><?= htmlspecialchars((string) ($item['created_at'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td>
                        <?php if ($hasSignature): ?>
                            <img src="<?= htmlspecialchars($signatureDataUrl, ENT_QUOTES) ?>" alt="Semnătură" style="max-width:120px;max-height:56px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                        <?php else: ?>
                            <span style="color:#ef4444;">Lipsă</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
        <nav class="product-reviews-pagination" aria-label="Paginare acorduri GDPR">
            <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildUrl(['page' => 1]), ENT_QUOTES) ?>" <?= $page <= 1 ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>« Prima</a>
            <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildUrl(['page' => max(1, $page - 1)]), ENT_QUOTES) ?>" <?= $page <= 1 ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>‹</a>
            <?php for ($p = $paginationStart; $p <= $paginationEnd; $p++): ?>
                <a class="product-reviews-pagination__btn <?= $p === $page ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildUrl(['page' => $p]), ENT_QUOTES) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildUrl(['page' => min($totalPages, $page + 1)]), ENT_QUOTES) ?>" <?= $page >= $totalPages ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>›</a>
            <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildUrl(['page' => $totalPages]), ENT_QUOTES) ?>" <?= $page >= $totalPages ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>Ultima »</a>
            <span class="product-reviews-pagination__label">Pagina <?= $page ?> / <?= $totalPages ?></span>
        </nav>
    <?php endif; ?>
</section>
