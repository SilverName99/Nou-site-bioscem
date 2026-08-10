<?php
$customer = is_array($customer ?? null) ? $customer : null;
$orders = is_array($orders ?? null) ? $orders : [];
$addresses = is_array($addresses ?? null) ? $addresses : [];
$pointsHistory = is_array($pointsHistory ?? null) ? $pointsHistory : [];
$settingsCardRows = is_array($settingsCardRows ?? null) ? $settingsCardRows : [];
$accountSection = in_array((string) ($accountSection ?? 'profile'), ['profile', 'orders', 'addresses', 'points', 'settings'], true) ? (string) $accountSection : 'profile';
$ordersCount = max(0, (int) ($ordersCount ?? count($orders)));
$loyaltyPoints = max(0, (int) ($loyaltyPoints ?? ($customer['loyalty_points'] ?? 0)));
$latestOrderDateLabel = trim((string) ($latestOrderDateLabel ?? '-'));
$membershipLabel = trim((string) ($membershipLabel ?? '-'));
$fullName = trim((string) ($fullName ?? ''));
$email = trim((string) ($email ?? ''));
$phone = trim((string) ($phone ?? ''));
$avatarInitials = trim((string) ($avatarInitials ?? 'CL'));
if ($avatarInitials === '') {
    $avatarInitials = 'CL';
}
$gender = is_array($customer) ? trim((string) ($customer['gender'] ?? '')) : '';
$birthDate = is_array($customer) ? trim((string) ($customer['birth_date'] ?? '')) : '';
$isPreviewMode = !is_array($customer);
$settingsPanelRows = [];
if (is_array($settingsCardRows)) {
    foreach ($settingsCardRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $settingsPanelRows[] = [
            'label' => $label,
            'description' => trim((string) ($row['description'] ?? '')),
            'value' => trim((string) ($row['value'] ?? '')),
        ];
    }
}
$menuItems = [
    'profile' => 'Profilul meu',
    'orders' => 'Comenzile mele',
    'addresses' => 'Adrese',
    'points' => 'Punctele mele',
    'settings' => 'Setări',
];
$menuIcons = [
    'profile' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5Zm0 2c-4.42 0-8 2.01-8 4.5V21h16v-2.5C20 16.01 16.42 14 12 14Z"/></svg>',
    'orders' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7l8 4 8-4-8-4Zm-8 6 8 4v8l-8-4V9Zm16 0-8 4v8l8-4V9Z"/></svg>',
    'addresses' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-5.96 7-11a7 7 0 1 0-14 0c0 5.04 7 11 7 11Zm0-8a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z"/></svg>',
    'points' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.78 5.63 6.22.9-4.5 4.39 1.06 6.19L12 17.2l-5.56 2.92 1.06-6.19L3 9.53l6.22-.9L12 3Z"/></svg>',
    'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m19.14 12.94.86-.49a1 1 0 0 0 0-1.76l-.86-.49a7.87 7.87 0 0 0-.56-1.35l.5-.87a1 1 0 0 0-.37-1.36l-.88-.51a1 1 0 0 0-1.36.37l-.5.86a8.2 8.2 0 0 0-1.56-.01l-.49-.85a1 1 0 0 0-1.36-.37l-.88.51a1 1 0 0 0-.37 1.36l.49.85c-.2.42-.39.86-.54 1.31l-.91.53a1 1 0 0 0 0 1.76l.9.52c.15.46.34.91.55 1.33l-.49.86a1 1 0 0 0 .37 1.36l.88.51a1 1 0 0 0 1.36-.37l.49-.85c.52.05 1.04.05 1.56 0l.5.86a1 1 0 0 0 1.36.37l.88-.51a1 1 0 0 0 .37-1.36l-.5-.87c.22-.43.41-.88.56-1.35ZM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5Z"/></svg>',
];
$logoutIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4M14 8l4 4-4 4M18 12H9"/></svg>';
?>
<section class="account-section-v2" data-account-section-token="1">
    <?php if (!$isPreviewMode): ?>
        <div class="account-section-v2__shell" data-account-shell>
            <aside class="account-section-v2__side">
                <article class="account-section-v2__card account-section-v2__summary">
                    <div class="account-section-v2__summary-top">
                        <span class="account-section-v2__avatar"><?= htmlspecialchars($avatarInitials, ENT_QUOTES) ?></span>
                        <div>
                            <h3><?= htmlspecialchars($fullName !== '' ? $fullName : 'Client', ENT_QUOTES) ?></h3>
                            <p><?= htmlspecialchars($membershipLabel, ENT_QUOTES) ?></p>
                        </div>
                    </div>

                    <nav class="account-section-v2__menu" data-account-menu>
                        <?php foreach ($menuItems as $key => $label): ?>
                            <a
                                class="<?= $accountSection === $key ? 'is-active' : '' ?>"
                                href="/contul-meu?section=<?= urlencode($key) ?>"
                                data-account-nav="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                            >
                                <span class="account-section-v2__menu-main">
                                    <span class="account-section-v2__menu-icon"><?= $menuIcons[$key] ?? '' ?></span>
                                    <span><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                                </span>
                                <span class="account-section-v2__menu-arrow">›</span>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <form method="post" action="/contul-meu/logout" class="account-section-v2__logout-wrap">
                        <button class="btn btn-secondary account-section-v2__logout-btn" type="submit">
                            <span class="account-section-v2__logout-icon"><?= $logoutIcon ?></span>
                            <span>Deconectare</span>
                        </button>
                    </form>
                </article>
            </aside>

            <div class="account-section-v2__main" data-account-panels>
                <section class="account-section-v2__panel <?= $accountSection === 'profile' ? 'is-active' : '' ?>" data-account-panel="profile">
                    <article class="account-section-v2__profile-card">
                        <div class="account-section-v2__profile-head">
                            <h2>Profilul meu</h2>
                        </div>
                        <form method="post" action="/contul-meu/profil" class="account-section-v2__profile-form">
                            <div class="account-section-v2__profile-grid">
                                <div>
                                    <small>PRENUME</small>
                                    <input type="text" name="first_name" required value="<?= htmlspecialchars((string) ($customer['first_name'] ?? ''), ENT_QUOTES) ?>">
                                </div>
                                <div>
                                    <small>NUME</small>
                                    <input type="text" name="last_name" required value="<?= htmlspecialchars((string) ($customer['last_name'] ?? ''), ENT_QUOTES) ?>">
                                </div>
                                <div>
                                    <small>EMAIL</small>
                                    <input type="email" name="email" required value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">
                                </div>
                                <div>
                                    <small>TELEFON</small>
                                    <input type="text" name="phone" value="<?= htmlspecialchars($phone, ENT_QUOTES) ?>">
                                </div>
                                <div>
                                    <small>DATA NAȘTERII</small>
                                    <input type="date" name="birth_date" value="<?= htmlspecialchars($birthDate, ENT_QUOTES) ?>">
                                </div>
                                <div>
                                    <small>GEN</small>
                                    <select name="gender">
                                        <option value="">Selectează</option>
                                        <option value="feminin" <?= $gender === 'feminin' ? 'selected' : '' ?>>Feminin</option>
                                        <option value="masculin" <?= $gender === 'masculin' ? 'selected' : '' ?>>Masculin</option>
                                    </select>
                                </div>
                            </div>
                            <button class="btn account-section-v2__save-btn" type="submit">Salvează modificările</button>
                        </form>
                    </article>

                    <div class="account-section-v2__stats">
                        <article class="account-section-v2__card">
                            <strong><?= $ordersCount ?></strong>
                            <span>Comenzi</span>
                        </article>
                        <article class="account-section-v2__card">
                            <strong><?= count($addresses) ?></strong>
                            <span>Adrese</span>
                        </article>
                        <article class="account-section-v2__card">
                            <strong><?= $loyaltyPoints ?></strong>
                            <span>Puncte</span>
                        </article>
                        <article class="account-section-v2__card">
                            <strong><?= htmlspecialchars($latestOrderDateLabel, ENT_QUOTES) ?></strong>
                            <span>Ultima comandă</span>
                        </article>
                    </div>
                </section>

                <section class="account-section-v2__panel <?= $accountSection === 'orders' ? 'is-active' : '' ?>" data-account-panel="orders">
                    <article class="account-section-v2__section-card">
                        <div class="account-section-v2__profile-head">
                            <h2>Comenzile mele</h2>
                        </div>
                        <?php if ($orders === []): ?>
                            <p class="account-section-v2__empty">Nu ai comenzi înregistrate momentan.</p>
                        <?php else: ?>
                            <div class="account-section-v2__orders-list">
                                <?php foreach ($orders as $order): ?>
                                    <?php
                                    $status = trim((string) ($order['status'] ?? ''));
                                    $orderNo = trim((string) ($order['order_number'] ?? '#-'));
                                    $createdAt = trim((string) ($order['created_at'] ?? ''));
                                    $dateLabel = $createdAt !== '' ? date('d M Y', (int) (strtotime($createdAt) ?: time())) : '-';
                                    $total = (float) ($order['total'] ?? 0);
                                    ?>
                                    <article class="account-section-v2__order-item">
                                        <div>
                                            <strong><?= htmlspecialchars($orderNo, ENT_QUOTES) ?></strong>
                                            <small><?= htmlspecialchars($dateLabel, ENT_QUOTES) ?></small>
                                        </div>
                                        <div class="account-section-v2__order-right">
                                            <span class="status-pill <?= $status === 'delivered' ? 'ok' : 'off' ?>">
                                                <?= htmlspecialchars($status !== '' ? $status : 'în procesare', ENT_QUOTES) ?>
                                            </span>
                                            <strong><?= number_format($total, 2) ?> lei</strong>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                </section>

                <section class="account-section-v2__panel <?= $accountSection === 'addresses' ? 'is-active' : '' ?>" data-account-panel="addresses">
                    <article class="account-section-v2__section-card">
                        <div class="account-section-v2__profile-head">
                            <h2>Adresele mele</h2>
                            <button class="btn btn-secondary" type="button" data-account-open-address-modal>Adaugă adresă</button>
                        </div>
                        <?php if ($addresses === []): ?>
                            <p class="account-section-v2__empty">Nu ai adrese salvate încă.</p>
                        <?php else: ?>
                            <div class="account-section-v2__addresses-grid">
                                <?php foreach ($addresses as $address): ?>
                                    <article class="account-section-v2__addresses-card">
                                        <div class="account-section-v2__addresses-head">
                                            <strong><?= htmlspecialchars((string) ($address['label'] ?? 'Adresă'), ENT_QUOTES) ?></strong>
                                            <?php if ((int) ($address['is_default'] ?? 0) === 1): ?>
                                                <span class="status-pill ok">Implicită</span>
                                            <?php endif; ?>
                                        </div>
                                        <p><?= htmlspecialchars((string) ($address['full_name'] ?? ''), ENT_QUOTES) ?></p>
                                        <p><?= htmlspecialchars(trim((string) ($address['address_line1'] ?? '') . ' ' . (string) ($address['address_line2'] ?? '')), ENT_QUOTES) ?></p>
                                        <p><?= htmlspecialchars(trim((string) ($address['city'] ?? '') . ', ' . (string) ($address['county'] ?? '')), ENT_QUOTES) ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                </section>

                <section class="account-section-v2__panel <?= $accountSection === 'points' ? 'is-active' : '' ?>" data-account-panel="points">
                    <article class="account-section-v2__section-card">
                        <div class="account-section-v2__profile-head">
                            <h2>Punctele mele</h2>
                        </div>
                        <article class="account-section-v2__points-panel">
                            <strong><?= $loyaltyPoints ?></strong>
                            <span>puncte disponibile</span>
                        </article>
                        <article class="account-section-v2__points-history-card">
                            <h3>Istoric puncte</h3>
                            <?php if ($pointsHistory === []): ?>
                                <p class="account-section-v2__empty">Nu există tranzacții de puncte încă.</p>
                            <?php else: ?>
                                <?php foreach ($pointsHistory as $tx): ?>
                                    <?php
                                    $delta = (int) ($tx['points_delta'] ?? 0);
                                    $dateLabel = trim((string) ($tx['created_at'] ?? ''));
                                    $reason = trim((string) ($tx['reason'] ?? 'Tranzacție'));
                                    ?>
                                    <div class="account-section-v2__points-history-row">
                                        <div>
                                            <strong><?= htmlspecialchars($reason, ENT_QUOTES) ?></strong>
                                            <small><?= htmlspecialchars($dateLabel !== '' ? $dateLabel : '-', ENT_QUOTES) ?></small>
                                        </div>
                                        <span class="<?= $delta >= 0 ? 'delta-plus' : 'delta-minus' ?>">
                                            <?= htmlspecialchars(($delta > 0 ? '+' : '') . (string) $delta, ENT_QUOTES) ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </article>
                    </article>
                </section>

                <section class="account-section-v2__panel <?= $accountSection === 'settings' ? 'is-active' : '' ?>" data-account-panel="settings">
                    <article class="account-section-v2__section-card">
                        <div class="account-section-v2__profile-head">
                            <h2>Setări</h2>
                        </div>
                        <article class="account-section-v2__setting-block">
                            <h3>Securitate</h3>
                            <form method="post" action="/contul-meu/parola" class="account-section-v2__settings-password-form">
                                <input type="password" name="current_password" placeholder="Parola curentă" required>
                                <input type="password" name="new_password" minlength="8" placeholder="Parolă nouă" required>
                                <input type="password" name="new_password_confirm" minlength="8" placeholder="Confirmă parola nouă" required>
                                <button class="btn" type="submit">Schimbă parola</button>
                            </form>
                        </article>
                        <?php if ($settingsPanelRows !== []): ?>
                            <article class="account-section-v2__setting-block">
                                <h3>Preferințe</h3>
                                <div class="account-section-v2__settings-list">
                                    <?php foreach ($settingsPanelRows as $pref): ?>
                                        <div class="account-section-v2__settings-row">
                                            <div>
                                                <strong><?= htmlspecialchars((string) $pref['label'], ENT_QUOTES) ?></strong>
                                                <?php if ((string) $pref['description'] !== ''): ?>
                                                    <small><?= htmlspecialchars((string) $pref['description'], ENT_QUOTES) ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <span><?= htmlspecialchars((string) (($pref['value'] ?? '') !== '' ? $pref['value'] : '-'), ENT_QUOTES) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                        <?php endif; ?>
                        <article class="account-section-v2__setting-block account-section-v2__danger-zone">
                            <h3>Șterge contul</h3>
                            <p>Această acțiune este ireversibilă.</p>
                            <form class="account-section-v2__danger-actions" method="post" action="/contul-meu/sterge" onsubmit="return confirm('Sigur vrei să ștergi contul? Acțiunea este ireversibilă.');">
                                <input type="hidden" name="confirm_delete" value="1">
                                <button type="submit" class="btn account-section-v2__danger-btn">Șterge contul</button>
                            </form>
                        </article>
                    </article>
                </section>
            </div>
        </div>

        <div class="account-section-v2__modal-overlay" data-account-address-modal hidden>
            <div class="account-section-v2__modal-card">
                <div class="account-section-v2__modal-head">
                    <h3>Adaugă adresă nouă</h3>
                    <button type="button" class="account-section-v2__modal-close" data-account-close-address-modal aria-label="Închide">×</button>
                </div>
                <form method="post" action="/contul-meu/adrese" class="account-section-v2__address-form">
                    <div class="account-section-v2__address-form-grid">
                        <div class="field">
                            <label>Etichetă adresă</label>
                            <input type="text" name="label" placeholder="Acasă, Birou">
                        </div>
                        <div class="field">
                            <label>Nume complet *</label>
                            <input type="text" name="full_name" required value="<?= htmlspecialchars($fullName, ENT_QUOTES) ?>">
                        </div>
                        <div class="field">
                            <label>Telefon</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($phone, ENT_QUOTES) ?>">
                        </div>
                        <div class="field">
                            <label>Județ *</label>
                            <input type="text" name="county" required>
                        </div>
                        <div class="field">
                            <label>Oraș *</label>
                            <input type="text" name="city" required>
                        </div>
                        <div class="field">
                            <label>Stradă *</label>
                            <input type="text" name="street" required>
                        </div>
                        <div class="field">
                            <label>Număr *</label>
                            <input type="text" name="street_no" required>
                        </div>
                        <div class="field">
                            <label>Cod poștal</label>
                            <input type="text" name="postcode" required>
                        </div>
                        <div class="field account-section-v2__span-2">
                            <label>Detalii adresă</label>
                            <input type="text" name="address_line2" placeholder="Bloc, scară, apartament">
                        </div>
                        <div class="field account-section-v2__span-2">
                            <label class="account-section-v2__checkbox-label">
                                <input type="checkbox" name="is_default" value="1">
                                Setează ca adresă implicită
                            </label>
                        </div>
                        <div class="field account-section-v2__span-2">
                            <button class="btn" type="submit">Salvează adresa</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="account-section-v2__shell">
            <aside class="account-section-v2__side">
                <article class="account-section-v2__card account-section-v2__summary">
                    <div class="account-section-v2__summary-top">
                        <span class="account-section-v2__avatar">MI</span>
                        <div>
                            <h3>Maria Ionescu</h3>
                            <p>Membru din Ianuarie 2024</p>
                        </div>
                    </div>
                    <nav class="account-section-v2__menu">
                        <?php foreach ($menuItems as $key => $label): ?>
                            <a class="<?= $key === 'profile' ? 'is-active' : '' ?>" href="#">
                                <span class="account-section-v2__menu-main">
                                    <span class="account-section-v2__menu-icon"><?= $menuIcons[$key] ?? '' ?></span>
                                    <span><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                                </span>
                                <span class="account-section-v2__menu-arrow">›</span>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                    <div class="account-section-v2__logout-wrap">
                        <button class="btn btn-secondary account-section-v2__logout-btn" type="button">
                            <span class="account-section-v2__logout-icon"><?= $logoutIcon ?></span>
                            <span>Deconectare</span>
                        </button>
                    </div>
                </article>
            </aside>
            <div class="account-section-v2__main">
                <article class="account-section-v2__card account-section-v2__profile-card">
                    <div class="account-section-v2__profile-head">
                        <h2>Profilul meu</h2>
                    </div>
                    <div class="account-section-v2__profile-grid">
                        <div><small>NUME COMPLET</small><strong>Maria Ionescu</strong></div>
                        <div><small>EMAIL</small><strong>maria.ionescu@email.com</strong></div>
                        <div><small>TELEFON</small><strong>+40 721 234 567</strong></div>
                        <div><small>MEMBRU DIN</small><strong>Ianuarie 2024</strong></div>
                    </div>
                </article>
                <div class="account-section-v2__stats">
                    <article class="account-section-v2__card"><strong>12</strong><span>Comenzi</span></article>
                    <article class="account-section-v2__card"><strong>3</strong><span>Adrese</span></article>
                    <article class="account-section-v2__card"><strong>1240</strong><span>Puncte</span></article>
                    <article class="account-section-v2__card"><strong>28 Mar</strong><span>Ultima comandă</span></article>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
<script>
(() => {
    const root = document.querySelector('[data-account-section-token="1"]');
    if (!(root instanceof HTMLElement)) return;

    const links = Array.from(root.querySelectorAll('[data-account-nav]'));
    const panels = Array.from(root.querySelectorAll('[data-account-panel]'));
    const allowedSections = new Set(links.map((link) => String(link.getAttribute('data-account-nav') || '')));

    const normalizedSection = (key) => (allowedSections.has(key) ? key : 'profile');

    const activate = (key) => {
        const safeKey = normalizedSection(String(key || 'profile'));
        panels.forEach((panel) => {
            if (!(panel instanceof HTMLElement)) return;
            const visible = panel.getAttribute('data-account-panel') === safeKey;
            panel.classList.toggle('is-active', visible);
        });
        links.forEach((link) => {
            if (!(link instanceof HTMLElement)) return;
            const active = link.getAttribute('data-account-nav') === safeKey;
            link.classList.toggle('is-active', active);
        });
    };

    links.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const key = normalizedSection(String(link.getAttribute('data-account-nav') || 'profile'));
            activate(key);
            const url = new URL(window.location.href);
            url.searchParams.set('section', key);
            if (window.history && typeof window.history.pushState === 'function') {
                window.history.pushState({ section: key }, '', url.pathname + '?' + url.searchParams.toString());
            }
        });
    });

    window.addEventListener('popstate', () => {
        const params = new URLSearchParams(window.location.search);
        const key = normalizedSection(params.get('section') || 'profile');
        activate(key);
    });

    const modal = root.querySelector('[data-account-address-modal]');
    const openModalBtn = root.querySelector('[data-account-open-address-modal]');
    const closeModalBtn = root.querySelector('[data-account-close-address-modal]');

    const setModalOpen = (open) => {
        if (!(modal instanceof HTMLElement)) return;
        modal.hidden = !open;
        modal.classList.toggle('is-open', open);
        document.body.classList.toggle('account-address-modal-open', open);
    };

    if (openModalBtn instanceof HTMLElement && modal instanceof HTMLElement) {
        openModalBtn.addEventListener('click', () => setModalOpen(true));
    }
    if (closeModalBtn instanceof HTMLElement && modal instanceof HTMLElement) {
        closeModalBtn.addEventListener('click', () => setModalOpen(false));
    }
    if (modal instanceof HTMLElement) {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                setModalOpen(false);
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setModalOpen(false);
        }
    });
})();
</script>
