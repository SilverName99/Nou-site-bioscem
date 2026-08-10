<?php
$users = is_array($users ?? null) ? $users : [];
$selectedUser = is_array($selectedUser ?? null) ? $selectedUser : null;
$selectedUserOrders = is_array($selectedUserOrders ?? null) ? $selectedUserOrders : [];
$selectedBilling = is_array($selectedBilling ?? null) ? $selectedBilling : null;
$search = trim((string) ($search ?? ''));
$sort = in_array((string) ($sort ?? 'id'), ['id', 'orders', 'total', 'points'], true) ? (string) $sort : 'id';
$dir = strtolower((string) ($dir ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$selectedId = (int) ($selectedUser['id'] ?? 0);
$editingUser = is_array($editingUser ?? null) ? $editingUser : null;
$panel = in_array((string) ($panel ?? 'list'), ['list', 'import'], true) ? (string) $panel : 'list';
$page = max(1, (int) ($page ?? 1));
$perPage = (int) ($perPage ?? 25);
$totalUsers = max(0, (int) ($totalUsers ?? count($users)));
$totalPages = max(1, (int) ($totalPages ?? 1));
$perPageOptions = is_array($perPageOptions ?? null) ? $perPageOptions : [10, 25, 50, 100, 200, 500];
$buildUsersUrl = static function (array $params) use ($search, $sort, $dir, $page, $perPage, $panel): string {
    $query = [];
    if ($search !== '') {
        $query['q'] = $search;
    }
    $query['sort'] = $sort;
    $query['dir'] = $dir;
    $query['page'] = $page;
    $query['per_page'] = $perPage;
    $query['panel'] = $panel;
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
            continue;
        }
        $query[$k] = $v;
    }
    return '/admin/users' . ($query !== [] ? '?' . http_build_query($query) : '');
};
$searchSuffix = ($search !== '' || $sort !== 'id' || $dir !== 'desc' || $page > 1 || $perPage !== 25 || $panel !== 'list')
    ? '?' . http_build_query(array_filter([
        'q' => $search !== '' ? $search : null,
        'sort' => $sort !== 'id' ? $sort : null,
        'dir' => $dir !== 'desc' ? $dir : null,
        'page' => $page > 1 ? $page : null,
        'per_page' => $perPage !== 25 ? $perPage : null,
        'panel' => $panel !== 'list' ? $panel : null,
    ], static fn ($value) => $value !== null))
    : '';
$sortLink = static function (string $column) use ($sort, $dir, $buildUsersUrl): string {
    $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
    return $buildUsersUrl([
        'sort' => $column,
        'dir' => $nextDir,
    ]);
};
$sortArrow = static function (string $column) use ($sort, $dir): string {
    if ($sort !== $column) {
        return '↕';
    }
    return $dir === 'asc' ? '↑' : '↓';
};

$selectedStats = [
    'orders_count' => count($selectedUserOrders),
    'total_spent' => 0.0,
];
foreach ($selectedUserOrders as $order) {
    $selectedStats['total_spent'] += (float) ($order['total'] ?? 0);
}

$genderLabels = [
    'feminin' => 'Feminin',
    'masculin' => 'Masculin',
];
$paginationPages = [];
$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);
for ($i = $paginationStart; $i <= $paginationEnd; $i++) {
    $paginationPages[] = $i;
}

?>

<section class="panel">
    <div class="section-head" style="margin-bottom:10px;">
        <div>
            <h1>Utilizatori</h1>
            <p>Listă clienți + detalii la click.</p>
        </div>
    </div>

    <div class="store-settings-tabs" data-admin-simple-tabs data-default-tab="<?= htmlspecialchars($panel, ENT_QUOTES) ?>" style="margin:6px 0 12px;">
        <button class="btn btn-secondary <?= $panel === 'list' ? 'is-active' : '' ?>" type="button" data-admin-simple-tab="list">Toți utilizatorii</button>
        <button class="btn btn-secondary <?= $panel === 'import' ? 'is-active' : '' ?>" type="button" data-admin-simple-tab="import">Import utilizatori</button>
    </div>

    <article class="panel store-settings-panel" data-admin-simple-panel="import" <?= $panel !== 'import' ? 'hidden' : '' ?> style="margin:12px 0;">
        <form method="post" action="/admin/users/import" enctype="multipart/form-data" class="users-search-row" style="margin-bottom:0;">
            <input type="file" name="users_file" accept=".csv,.xlsx" required>
            <button class="btn" type="submit">Import utilizatori (CSV/XLSX)</button>
            <small style="color:#64748b;">Coloane recomandate: user_email, user_pass, first_name, last_name, phone, user_registered.</small>
        </form>
    </article>

    <article class="panel store-settings-panel" data-admin-simple-panel="list" <?= $panel !== 'list' ? 'hidden' : '' ?> style="margin:12px 0;">
        <form method="get" action="/admin/users" class="users-search-row">
            <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="Caută după nume sau email...">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES) ?>">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir, ENT_QUOTES) ?>">
            <input type="hidden" name="panel" value="list">
            <button class="btn" type="submit">Caută</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-secondary" href="/admin/users?sort=<?= urlencode($sort) ?>&dir=<?= urlencode($dir) ?>&panel=list">Resetează</a>
            <?php endif; ?>
        </form>

        <div class="users-stage nl-stage <?= is_array($selectedUser) ? 'is-editing' : '' ?>">
            <form method="post" action="/admin/users/delete-selected" id="bulk-users-form" class="nl-stage-list" data-bulk-users-form>
                <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES) ?>">
                <input type="hidden" name="dir" value="<?= htmlspecialchars($dir, ENT_QUOTES) ?>">
                <input type="hidden" name="page" value="<?= (int) $page ?>">
                <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
                <input type="hidden" name="selected_user" value="<?= (int) $selectedId ?>">
                <div class="users-bulk-toolbar">
                    <div class="users-bulk-toolbar__left">
                        <button class="btn btn-secondary" type="button" data-users-select-all>Selectează tot</button>
                        <button class="btn btn-secondary" type="button" data-users-clear-selection>Debifează tot</button>
                        <button class="btn btn-danger" type="submit" onclick="return confirm('Sigur vrei să ștergi utilizatorii selectați? Acțiunea nu se poate anula.')">
                            Șterge utilizatorii selectați
                        </button>
                    </div>
                    <div class="users-bulk-toolbar__right">
                        <span>Selecție: <strong data-users-selected-count>0</strong></span>
                    </div>
                </div>

                <div class="users-list-toolbar admin-filters-inline" style="justify-content:space-between;">
                    <small style="color:#64748b;">Total utilizatori: <strong><?= (int) $totalUsers ?></strong></small>
                    <label class="admin-per-page-control">
                        <span>Pe pagină</span>
                        <select name="per_page" onchange="window.location.href='<?= htmlspecialchars($buildUsersUrl(['user' => $selectedId > 0 ? $selectedId : null, 'page' => 1, 'per_page' => null]), ENT_QUOTES) ?>&per_page=' + this.value">
                            <?php foreach ($perPageOptions as $option): ?>
                                <option value="<?= (int) $option ?>" <?= (int) $option === (int) $perPage ? 'selected' : '' ?>><?= (int) $option ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="users-table-wrap">
                    <table class="table users-table">
                        <thead>
                        <tr>
                            <th class="users-select-cell">
                                <input type="checkbox" data-users-master-checkbox aria-label="Selectează toți utilizatorii">
                            </th>
                            <th>Client</th>
                            <th>
                                <a class="users-sort-link <?= $sort === 'points' ? 'active' : '' ?>" href="<?= htmlspecialchars($sortLink('points'), ENT_QUOTES) ?>">
                                    Puncte fidelizare <span><?= htmlspecialchars($sortArrow('points'), ENT_QUOTES) ?></span>
                                </a>
                            </th>
                            <th>
                                <a class="users-sort-link <?= $sort === 'orders' ? 'active' : '' ?>" href="<?= htmlspecialchars($sortLink('orders'), ENT_QUOTES) ?>">
                                    Comenzi <span><?= htmlspecialchars($sortArrow('orders'), ENT_QUOTES) ?></span>
                                </a>
                            </th>
                            <th>
                                <a class="users-sort-link <?= $sort === 'total' ? 'active' : '' ?>" href="<?= htmlspecialchars($sortLink('total'), ENT_QUOTES) ?>">
                                    Total <span><?= htmlspecialchars($sortArrow('total'), ENT_QUOTES) ?></span>
                                </a>
                            </th>
                            <th style="width:120px;"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($users === []): ?>
                            <tr>
                                <td colspan="6">Nu există utilizatori care corespund căutării.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <?php
                                $id = (int) ($user['id'] ?? 0);
                                $fullName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
                                $initials = strtoupper(substr((string) ($user['first_name'] ?? 'U'), 0, 1) . substr((string) ($user['last_name'] ?? ''), 0, 1));
                                ?>
                                <tr class="<?= $selectedId === $id ? 'is-selected' : '' ?>" data-users-row>
                                    <td class="users-select-cell">
                                        <input type="checkbox" name="user_ids[]" value="<?= $id ?>" class="users-row-checkbox" data-users-row-checkbox>
                                    </td>
                                    <td>
                                        <a class="users-client-cell" href="/admin/users/<?= $id ?><?= $searchSuffix ?>">
                                            <span class="users-avatar"><?= htmlspecialchars($initials !== '' ? $initials : 'U', ENT_QUOTES) ?></span>
                                            <span>
                                                <strong><?= htmlspecialchars($fullName !== '' ? $fullName : 'Utilizator', ENT_QUOTES) ?></strong>
                                                <small><?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES) ?></small>
                                            </span>
                                        </a>
                                    </td>
                                    <td><strong><?= (int) ($user['loyalty_points'] ?? 0) ?></strong> pct</td>
                                    <td><strong><?= (int) ($user['orders_count'] ?? 0) ?></strong> comenzi</td>
                                    <td><?= number_format((float) ($user['total_spent'] ?? 0), 2) ?> lei</td>
                                    <td>
                                        <a class="btn btn-secondary users-detail-btn" href="/admin/users/<?= $id ?><?= $searchSuffix ?>">Detalii</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="admin-pagination" style="display:flex;align-items:center;gap:6px;justify-content:flex-end;margin-top:10px;">
                        <a class="btn btn-secondary <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($buildUsersUrl(['page' => max(1, $page - 1)]), ENT_QUOTES) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>←</a>
                        <?php foreach ($paginationPages as $p): ?>
                            <a class="btn <?= $p === $page ? '' : 'btn-secondary' ?>" href="<?= htmlspecialchars($buildUsersUrl(['page' => $p]), ENT_QUOTES) ?>"><?= (int) $p ?></a>
                        <?php endforeach; ?>
                        <a class="btn btn-secondary <?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($buildUsersUrl(['page' => min($totalPages, $page + 1)]), ENT_QUOTES) ?>" <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>→</a>
                    </div>
                <?php endif; ?>
            </form>

            <div class="nl-stage-builder">
                <?php if (is_array($selectedUser)): ?>
                    <?php $userName = trim((string) ($selectedUser['first_name'] ?? '') . ' ' . (string) ($selectedUser['last_name'] ?? '')); ?>
                    <article class="panel users-detail-panel" style="margin:0;">
                        <div class="users-detail-head">
                            <a class="btn btn-secondary" href="/admin/users<?= $searchSuffix ?>">← Înapoi la listă</a>
                            <h3>Detalii utilizator: <?= htmlspecialchars($userName !== '' ? $userName : 'Utilizator', ENT_QUOTES) ?></h3>
                        </div>

                        <div class="admin-user-kpis">
                            <article>
                                <small>Total comenzi</small>
                                <strong><?= (int) $selectedStats['orders_count'] ?></strong>
                            </article>
                            <article>
                                <small>Total cheltuit</small>
                                <strong><?= number_format((float) $selectedStats['total_spent'], 2) ?> lei</strong>
                            </article>
                            <article>
                                <small>Puncte</small>
                                <strong><?= (int) ($selectedUser['loyalty_points'] ?? 0) ?></strong>
                            </article>
                        </div>

                        <div class="admin-user-grid" style="margin-top:12px;">
                            <article class="panel" style="margin:0;">
                                <h4 style="margin-top:0;">Editează utilizator</h4>
                                <form method="post" action="/admin/users/save" class="users-edit-form">
                                    <input type="hidden" name="id" value="<?= (int) ($selectedUser['id'] ?? 0) ?>">
                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES) ?>">
                                    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir, ENT_QUOTES) ?>">
                                    <input type="hidden" name="page" value="<?= (int) $page ?>">
                                    <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
                                    <div class="form-grid">
                                        <div>
                                            <small>Prenume</small>
                                            <input type="text" name="first_name" value="<?= htmlspecialchars((string) (($editingUser['first_name'] ?? $selectedUser['first_name'] ?? '')), ENT_QUOTES) ?>">
                                        </div>
                                        <div>
                                            <small>Nume</small>
                                            <input type="text" name="last_name" value="<?= htmlspecialchars((string) (($editingUser['last_name'] ?? $selectedUser['last_name'] ?? '')), ENT_QUOTES) ?>">
                                        </div>
                                        <div>
                                            <small>Email *</small>
                                            <input type="email" name="email" required value="<?= htmlspecialchars((string) (($editingUser['email'] ?? $selectedUser['email'] ?? '')), ENT_QUOTES) ?>">
                                        </div>
                                        <div>
                                            <small>Telefon</small>
                                            <input type="text" name="phone" value="<?= htmlspecialchars((string) (($editingUser['phone'] ?? $selectedUser['phone'] ?? '')), ENT_QUOTES) ?>">
                                        </div>
                                        <div>
                                            <small>Data nașterii</small>
                                            <input type="date" name="birth_date" value="<?= htmlspecialchars((string) (($editingUser['birth_date'] ?? $selectedUser['birth_date'] ?? '')), ENT_QUOTES) ?>">
                                        </div>
                                        <div>
                                            <small>Gen</small>
                                            <?php $editingGender = (string) (($editingUser['gender'] ?? $selectedUser['gender'] ?? '')); ?>
                                            <select name="gender">
                                                <option value="" <?= $editingGender === '' ? 'selected' : '' ?>>Selectează</option>
                                                <option value="feminin" <?= $editingGender === 'feminin' ? 'selected' : '' ?>>Feminin</option>
                                                <option value="masculin" <?= $editingGender === 'masculin' ? 'selected' : '' ?>>Masculin</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="users-edit-actions">
                                        <button class="btn" type="submit">Salvează modificările</button>
                                    </div>
                                </form>
                            </article>

                            <article class="panel" style="margin:0;">
                                <h4 style="margin-top:0;">Informații personale</h4>
                                <div class="order-modal-grid">
                                    <div><small>Prenume</small><p><?= htmlspecialchars((string) ($selectedUser['first_name'] ?? '-'), ENT_QUOTES) ?></p></div>
                                    <div><small>Nume</small><p><?= htmlspecialchars((string) ($selectedUser['last_name'] ?? '-'), ENT_QUOTES) ?></p></div>
                                    <div><small>Email</small><p><?= htmlspecialchars((string) ($selectedUser['email'] ?? '-'), ENT_QUOTES) ?></p></div>
                                    <div><small>Telefon</small><p><?= htmlspecialchars((string) ($selectedUser['phone'] ?? '-'), ENT_QUOTES) ?></p></div>
                                    <div><small>Data nașterii</small><p><?= htmlspecialchars((string) ($selectedUser['birth_date'] ?? '-'), ENT_QUOTES) ?></p></div>
                                    <div><small>Gen</small><p><?= htmlspecialchars((string) ($genderLabels[(string) ($selectedUser['gender'] ?? '')] ?? '-'), ENT_QUOTES) ?></p></div>
                                </div>
                            </article>

                            <article class="panel" style="margin:0;">
                                <h4 style="margin-top:0;">Facturare (ultima comandă)</h4>
                                <?php if (!is_array($selectedBilling)): ?>
                                    <p style="margin:0;color:#64748b;">Nu există date de facturare.</p>
                                <?php else: ?>
                                    <div class="order-modal-grid">
                                        <div><small>Nume</small><p><?= htmlspecialchars(trim((string) ($selectedBilling['billing_first_name'] ?? '') . ' ' . (string) ($selectedBilling['billing_last_name'] ?? '')), ENT_QUOTES) ?></p></div>
                                        <div><small>Email</small><p><?= htmlspecialchars((string) ($selectedBilling['billing_email'] ?? '-'), ENT_QUOTES) ?></p></div>
                                        <div><small>Telefon</small><p><?= htmlspecialchars((string) ($selectedBilling['billing_phone'] ?? '-'), ENT_QUOTES) ?></p></div>
                                        <div><small>Cod poștal</small><p><?= htmlspecialchars((string) ($selectedBilling['billing_postcode'] ?? '-'), ENT_QUOTES) ?></p></div>
                                        <div style="grid-column:1/-1;"><small>Adresă</small><p><?= htmlspecialchars(trim((string) ($selectedBilling['billing_address_line1'] ?? '-') . ' ' . (string) ($selectedBilling['billing_address_line2'] ?? '')), ENT_QUOTES) ?></p></div>
                                        <div><small>Oraș</small><p><?= htmlspecialchars((string) ($selectedBilling['billing_city'] ?? '-'), ENT_QUOTES) ?></p></div>
                                        <div><small>Județ</small><p><?= htmlspecialchars((string) ($selectedBilling['billing_county'] ?? '-'), ENT_QUOTES) ?></p></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    </article>
                <?php endif; ?>
            </div>
        </div>
    </article>
</section>

<script>
(() => {
    const form = document.getElementById('bulk-users-form');
    if (!(form instanceof HTMLFormElement)) return;

    const master = form.querySelector('[data-users-master-checkbox]');
    const rowBoxes = () => Array.from(form.querySelectorAll('[data-users-row-checkbox]'));
    const rowNodes = () => Array.from(form.querySelectorAll('[data-users-row]'));
    const selectAllBtn = form.querySelector('[data-users-select-all]');
    const clearBtn = form.querySelector('[data-users-clear-selection]');
    const selectedCount = form.querySelector('[data-users-selected-count]');

    const syncState = () => {
        if (!(master instanceof HTMLInputElement)) return;
        const boxes = rowBoxes();
        if (boxes.length === 0) {
            master.checked = false;
            master.indeterminate = false;
            if (selectedCount instanceof HTMLElement) {
                selectedCount.textContent = '0';
            }
            return;
        }
        const checked = boxes.filter((box) => box instanceof HTMLInputElement && box.checked).length;
        master.checked = checked > 0 && checked === boxes.length;
        master.indeterminate = checked > 0 && checked < boxes.length;
        if (selectedCount instanceof HTMLElement) {
            selectedCount.textContent = String(checked);
        }

        const rows = rowNodes();
        boxes.forEach((box, index) => {
            const row = rows[index];
            if (!(box instanceof HTMLInputElement) || !(row instanceof HTMLElement)) return;
            row.classList.toggle('is-marked', box.checked);
        });
    };

    if (master instanceof HTMLInputElement) {
        master.addEventListener('change', () => {
            rowBoxes().forEach((box) => {
                if (box instanceof HTMLInputElement) {
                    box.checked = master.checked;
                }
            });
            syncState();
        });
    }

    rowBoxes().forEach((box) => {
        if (box instanceof HTMLInputElement) {
            box.addEventListener('change', syncState);
        }
    });

    if (selectAllBtn instanceof HTMLElement) {
        selectAllBtn.addEventListener('click', () => {
            rowBoxes().forEach((box) => {
                if (box instanceof HTMLInputElement) {
                    box.checked = true;
                }
            });
            syncState();
        });
    }

    if (clearBtn instanceof HTMLElement) {
        clearBtn.addEventListener('click', () => {
            rowBoxes().forEach((box) => {
                if (box instanceof HTMLInputElement) {
                    box.checked = false;
                }
            });
            syncState();
        });
    }

    form.addEventListener('submit', (event) => {
        const checked = rowBoxes().some((box) => box instanceof HTMLInputElement && box.checked);
        if (!checked) {
            event.preventDefault();
            window.alert('Selectează cel puțin un utilizator.');
        }
    });

    syncState();
})();
</script>
<script>
(() => {
    const tabsWrap = document.querySelector('[data-admin-simple-tabs]');
    if (!(tabsWrap instanceof HTMLElement)) return;
    const tabs = Array.from(tabsWrap.querySelectorAll('[data-admin-simple-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-admin-simple-panel]'));
    const setActive = (key) => {
        tabs.forEach((tab) => tab.classList.toggle('is-active', tab.getAttribute('data-admin-simple-tab') === key));
        panels.forEach((panel) => {
            panel.hidden = panel.getAttribute('data-admin-simple-panel') !== key;
        });
    };
    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const key = tab.getAttribute('data-admin-simple-tab') || 'list';
            const url = new URL(window.location.href);
            url.searchParams.set('panel', key);
            if (key === 'import') {
                url.searchParams.delete('user');
            }
            window.location.href = url.toString();
        });
    });
    setActive('<?= htmlspecialchars($panel, ENT_QUOTES) ?>');
})();
</script>
