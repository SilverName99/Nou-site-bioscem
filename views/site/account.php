<?php
$customer = is_array($customer ?? null) ? $customer : null;
$orders = is_array($orders ?? null) ? $orders : [];
$activeView = in_array((string) ($activeView ?? 'login'), ['login', 'register'], true) ? (string) $activeView : 'login';
$accountSection = in_array((string) ($accountSection ?? 'profile'), ['profile', 'orders', 'addresses', 'points', 'settings'], true) ? (string) $accountSection : 'profile';
$dbReady = (bool) ($dbReady ?? false);
$next = (string) ($next ?? '/contul-meu');
$ordersCount = max(0, (int) ($ordersCount ?? count($orders)));
$ordersTotal = (float) ($ordersTotal ?? 0);
$addresses = is_array($addresses ?? null) ? $addresses : [];
$registrationFields = is_array($registrationFields ?? null) ? $registrationFields : [
    'first_name' => true,
    'last_name' => true,
    'email' => true,
    'phone' => true,
    'birth_date' => false,
    'gender' => false,
    'password' => true,
    'password_confirm' => true,
];
$registerAntiBot = is_array($registerAntiBot ?? null) ? $registerAntiBot : [];
$registerAntiBotToken = trim((string) ($registerAntiBot['token'] ?? ''));
$registerAntiBotRenderedAt = (int) ($registerAntiBot['rendered_at'] ?? 0);
$latestBillingOrder = is_array($latestBillingOrder ?? null) ? $latestBillingOrder : null;
$loyaltyTransactions = is_array($loyaltyTransactions ?? null) ? $loyaltyTransactions : [];
$loyaltyConfig = is_array($loyaltyConfig ?? null) ? $loyaltyConfig : [
    'enabled' => true,
    'earn_rate' => 1,
    'redeem_value' => 0.10,
    'min_redeem' => 100,
    'max_redeem_percent' => 50,
    'promo_multiplier' => 1,
    'promo_weekend_enabled' => false,
    'promo_weekend_multiplier' => 1,
];
// Sistemul de puncte oprit din admin → secțiunea și numărătoarea dispar din cont.
$loyaltyEnabled = !empty($loyaltyConfig['enabled']);
if (!$loyaltyEnabled && $accountSection === 'points') {
    $accountSection = 'profile';
}
$fullName = '';
$avatarInitials = '';
if (is_array($customer)) {
    $fullName = \App\Support\CustomerDisplay::nume(
        (string) ($customer['first_name'] ?? ''),
        (string) ($customer['last_name'] ?? ''),
        (string) ($customer['email'] ?? '')
    );
    $avatarInitials = \App\Support\CustomerDisplay::initiale(
        (string) ($customer['first_name'] ?? ''),
        (string) ($customer['last_name'] ?? ''),
        (string) ($customer['email'] ?? '')
    );
}
$gender = is_array($customer) ? (string) ($customer['gender'] ?? '') : '';
$birthDate = is_array($customer) ? (string) ($customer['birth_date'] ?? '') : '';
?>

<section class="panel customer-account-page" style="max-width:1160px;margin-left:auto;margin-right:auto;">
    <h1 style="margin-top:0;">Contul meu</h1>

    <?php if (!$dbReady): ?>
        <p>Conturile client nu sunt disponibile momentan deoarece conexiunea la baza de date nu este activă.</p>
    <?php elseif (is_array($customer)): ?>
        <div class="customer-account-shell">
            <aside class="customer-side">
                <article class="customer-summary">
                    <span class="customer-avatar"><?= htmlspecialchars($avatarInitials !== '' ? $avatarInitials : 'CL', ENT_QUOTES) ?></span>
                    <h3><?= htmlspecialchars($fullName !== '' ? $fullName : 'Client', ENT_QUOTES) ?></h3>
                    <p><?= htmlspecialchars((string) ($customer['email'] ?? ''), ENT_QUOTES) ?></p>
                    <div class="customer-stats">
                        <article>
                            <strong><?= $ordersCount ?></strong>
                            <span>Comenzi</span>
                        </article>
                        <article>
                            <strong><?= number_format($ordersTotal, 0) ?> lei</strong>
                            <span>Total</span>
                        </article>
                        <?php if ($loyaltyEnabled): ?>
                            <article>
                                <strong><?= (int) ($customer['loyalty_points'] ?? 0) ?></strong>
                                <span>Puncte</span>
                            </article>
                        <?php endif; ?>
                    </div>
                </article>

                <nav class="customer-menu">
                    <a class="<?= $accountSection === 'profile' ? 'active' : '' ?>" href="/contul-meu?section=profile"><span>Profilul meu</span><span>›</span></a>
                    <a class="<?= $accountSection === 'orders' ? 'active' : '' ?>" href="/contul-meu?section=orders"><span>Comenzile mele</span><span>›</span></a>
                    <a class="<?= $accountSection === 'addresses' ? 'active' : '' ?>" href="/contul-meu?section=addresses"><span>Adrese</span><span>›</span></a>
                    <?php if ($loyaltyEnabled): ?>
                        <a class="<?= $accountSection === 'points' ? 'active' : '' ?>" href="/contul-meu?section=points"><span>Punctele mele</span><span>›</span></a>
                    <?php endif; ?>
                    <a class="<?= $accountSection === 'settings' ? 'active' : '' ?>" href="/contul-meu?section=settings"><span>Setări</span><span>›</span></a>
                </nav>
                <form method="post" action="/contul-meu/logout">
                    <button class="btn btn-secondary account-logout-btn" type="submit">Logout</button>
                </form>
            </aside>

            <div class="customer-main">
                <?php if ($accountSection === 'orders'): ?>
                    <article class="panel" style="margin:0;">
                        <h2 style="margin-top:0;">Comenzile mele</h2>
                        <?php if ($orders === []): ?>
                            <p>Nu există comenzi în contul tău încă.</p>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>Comandă</th>
                                    <th>Data</th>
                                    <th>Status</th>
                                    <th>Plată</th>
                                    <th>Total</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <?php $paid = (string) ($order['payment_status'] ?? '') === 'paid'; ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($order['order_number'] ?? ''), ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars((string) ($order['created_at'] ?? ''), ENT_QUOTES) ?></td>
                                        <td><span class="status-pill"><?= htmlspecialchars((string) ($order['status'] ?? ''), ENT_QUOTES) ?></span></td>
                                        <td><span class="status-pill <?= $paid ? 'ok' : 'off' ?>"><?= htmlspecialchars((string) ($order['payment_status'] ?? ''), ENT_QUOTES) ?></span></td>
                                        <td><?= number_format((float) ($order['total'] ?? 0), 2) ?> lei</td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </article>
                <?php elseif ($accountSection === 'addresses'): ?>
                    <article class="panel" style="margin:0;">
                        <h2 style="margin-top:0;">Adresa de facturare</h2>
                        <button class="btn" type="button" id="address-add-toggle">+ Adaugă o adresă nouă</button>
                        <form method="post" action="/contul-meu/adrese" class="customer-form-grid" id="address-add-form" style="display:none;margin-top:12px;">
                            <div class="field">
                                <label>Etichetă adresă</label>
                                <input type="text" name="label" placeholder="Acasă, Birou...">
                            </div>
                            <div class="field">
                                <label>Nume complet</label>
                                <input type="text" name="full_name" required>
                            </div>
                            <div class="field">
                                <label>Telefon</label>
                                <input type="text" name="phone">
                            </div>
                            <div class="field">
                                <label>Cod poștal</label>
                                <input type="text" name="postcode">
                            </div>
                            <div class="field span-2">
                                <label>Adresă linia 1</label>
                                <input type="text" name="address_line1" required>
                            </div>
                            <div class="field span-2">
                                <label>Adresă linia 2</label>
                                <input type="text" name="address_line2">
                            </div>
                            <div class="field">
                                <label>Oraș</label>
                                <input type="text" name="city" required>
                            </div>
                            <div class="field">
                                <label>Județ</label>
                                <input type="text" name="county" required>
                            </div>
                            <div class="field span-2">
                                <label style="display:flex;align-items:center;gap:8px;">
                                    <input type="checkbox" name="is_default" value="1">
                                    Setează ca adresă implicită
                                </label>
                            </div>
                            <div class="field span-2">
                                <button class="btn" type="submit">Salvează adresa</button>
                            </div>
                        </form>
                        <?php if ($addresses !== []): ?>
                            <div class="customer-address-list">
                                <?php foreach ($addresses as $address): ?>
                                    <article class="customer-address-card">
                                        <div class="head">
                                            <strong><?= htmlspecialchars((string) ($address['label'] ?? 'Adresă'), ENT_QUOTES) ?></strong>
                                            <?php if ((int) ($address['is_default'] ?? 0) === 1): ?>
                                                <span class="status-pill ok">Implicită</span>
                                            <?php endif; ?>
                                        </div>
                                        <p><?= htmlspecialchars((string) ($address['full_name'] ?? ''), ENT_QUOTES) ?><?= !empty($address['phone']) ? ' · ' . htmlspecialchars((string) $address['phone'], ENT_QUOTES) : '' ?></p>
                                        <p><?= htmlspecialchars(trim((string) ($address['address_line1'] ?? '') . ' ' . (string) ($address['address_line2'] ?? '')), ENT_QUOTES) ?></p>
                                        <p><?= htmlspecialchars((string) (($address['city'] ?? '') . ', ' . ($address['county'] ?? '')), ENT_QUOTES) ?><?= !empty($address['postcode']) ? ' · ' . htmlspecialchars((string) $address['postcode'], ENT_QUOTES) : '' ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!is_array($latestBillingOrder)): ?>
                            <p>Nu există încă date de facturare salvate în comenzi.</p>
                        <?php else: ?>
                            <div class="customer-form-grid">
                                <div class="field">
                                    <label>Nume</label>
                                    <input type="text" disabled value="<?= htmlspecialchars(trim((string) ($latestBillingOrder['billing_first_name'] ?? '') . ' ' . (string) ($latestBillingOrder['billing_last_name'] ?? '')), ENT_QUOTES) ?>">
                                </div>
                                <div class="field">
                                    <label>Telefon</label>
                                    <input type="text" disabled value="<?= htmlspecialchars((string) ($latestBillingOrder['billing_phone'] ?? ''), ENT_QUOTES) ?>">
                                </div>
                                <div class="field span-2">
                                    <label>Adresă</label>
                                    <input type="text" disabled value="<?= htmlspecialchars(trim((string) ($latestBillingOrder['billing_address_line1'] ?? '') . ' ' . (string) ($latestBillingOrder['billing_address_line2'] ?? '')), ENT_QUOTES) ?>">
                                </div>
                                <div class="field">
                                    <label>Oraș</label>
                                    <input type="text" disabled value="<?= htmlspecialchars((string) ($latestBillingOrder['billing_city'] ?? ''), ENT_QUOTES) ?>">
                                </div>
                                <div class="field">
                                    <label>Județ</label>
                                    <input type="text" disabled value="<?= htmlspecialchars((string) ($latestBillingOrder['billing_county'] ?? ''), ENT_QUOTES) ?>">
                                </div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php elseif ($accountSection === 'points' && $loyaltyEnabled): ?>
                    <article class="panel" style="margin:0;">
                        <h2 style="margin-top:0;">Punctele mele</h2>
                        <p style="margin:0 0 12px;color:#64748b;">
                            Sold curent: <strong><?= (int) ($customer['loyalty_points'] ?? 0) ?> puncte</strong>
                            · 1 punct = <?= number_format((float) ($loyaltyConfig['redeem_value'] ?? 0.10), 2) ?> lei
                        </p>
                        <div class="customer-points">
                            <span>Rată standard acumulare: <?= number_format((float) ($loyaltyConfig['earn_rate'] ?? 1), 2) ?> puncte / 1 leu</span>
                            <?php if (!empty($loyaltyConfig['promo_multiplier']) && (float) $loyaltyConfig['promo_multiplier'] > 1): ?>
                                <span>Promo activă: ×<?= number_format((float) $loyaltyConfig['promo_multiplier'], 2) ?> la acumulare</span>
                            <?php endif; ?>
                            <?php if (!empty($loyaltyConfig['promo_weekend_enabled']) && (float) ($loyaltyConfig['promo_weekend_multiplier'] ?? 1) > 1): ?>
                                <span>Weekend boost: ×<?= number_format((float) $loyaltyConfig['promo_weekend_multiplier'], 2) ?> (Sâmbătă-Duminică)</span>
                            <?php endif; ?>
                        </div>

                        <h3 style="margin:14px 0 8px;">Istoric puncte</h3>
                        <?php if ($loyaltyTransactions === []): ?>
                            <p style="margin:0;color:#64748b;">Nu există tranzacții de puncte încă.</p>
                        <?php else: ?>
                            <div style="overflow:auto;">
                                <table class="table">
                                    <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Tip</th>
                                        <th>Delta</th>
                                        <th>Sold după</th>
                                        <th>Detalii</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($loyaltyTransactions as $tx): ?>
                                        <?php $delta = (int) ($tx['points_delta'] ?? 0); ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($tx['created_at'] ?? ''), ENT_QUOTES) ?></td>
                                            <td><span class="status-pill"><?= htmlspecialchars((string) ($tx['tx_type'] ?? ''), ENT_QUOTES) ?></span></td>
                                            <td style="color:<?= $delta >= 0 ? '#166534' : '#991b1b' ?>;font-weight:700;">
                                                <?= htmlspecialchars(($delta > 0 ? '+' : '') . (string) $delta, ENT_QUOTES) ?>
                                            </td>
                                            <td><?= (int) ($tx['balance_after'] ?? 0) ?></td>
                                            <td><?= htmlspecialchars((string) ($tx['reason'] ?? '-'), ENT_QUOTES) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php elseif ($accountSection === 'settings'): ?>
                    <article class="panel" style="margin:0;">
                        <h2 style="margin-top:0;">Setări cont</h2>
                        <h3 style="margin:0 0 10px;">Schimbă parola</h3>
                        <form method="post" action="/contul-meu/parola" class="security-form-grid">
                            <div class="field">
                                <label>Parola curentă</label>
                                <input type="password" name="current_password" required>
                            </div>
                            <div class="field">
                                <label>Parolă nouă</label>
                                <input type="password" name="new_password" minlength="8" required>
                            </div>
                            <div class="field">
                                <label>Confirmă parola nouă</label>
                                <input type="password" name="new_password_confirm" minlength="8" required>
                            </div>
                            <div class="field">
                                <button class="btn" type="submit">Schimbă parola</button>
                            </div>
                        </form>
                    </article>
                <?php else: ?>
                    <article class="panel" style="margin:0;">
                        <div class="customer-profile-head">
                            <h2>Profilul meu</h2>
                            <a class="btn btn-secondary" href="/contul-meu?section=profile">Editează</a>
                        </div>
                        <h3 style="margin:0 0 12px;">Informații personale</h3>
                        <form method="post" action="/contul-meu/profil" class="customer-form-grid">
                            <div class="field">
                                <label>Prenume</label>
                                <input type="text" name="first_name" required value="<?= htmlspecialchars((string) ($customer['first_name'] ?? ''), ENT_QUOTES) ?>">
                            </div>
                            <div class="field">
                                <label>Nume</label>
                                <input type="text" name="last_name" required value="<?= htmlspecialchars((string) ($customer['last_name'] ?? ''), ENT_QUOTES) ?>">
                            </div>
                            <div class="field">
                                <label>Email</label>
                                <input type="email" name="email" required value="<?= htmlspecialchars((string) ($customer['email'] ?? ''), ENT_QUOTES) ?>">
                            </div>
                            <div class="field">
                                <label>Telefon</label>
                                <input type="text" name="phone" value="<?= htmlspecialchars((string) ($customer['phone'] ?? ''), ENT_QUOTES) ?>">
                            </div>
                            <div class="field">
                                <label>Data nașterii</label>
                                <input type="date" name="birth_date" value="<?= htmlspecialchars($birthDate, ENT_QUOTES) ?>">
                            </div>
                            <div class="field">
                                <label>Gen</label>
                                <select name="gender">
                                    <option value="">Selectează</option>
                                    <option value="feminin" <?= $gender === 'feminin' ? 'selected' : '' ?>>Feminin</option>
                                    <option value="masculin" <?= $gender === 'masculin' ? 'selected' : '' ?>>Masculin</option>
                                </select>
                            </div>
                            <div class="field span-2">
                                <button class="btn" type="submit">Salvează modificările</button>
                            </div>
                        </form>
                    </article>

                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="account-auth-tabs">
            <a class="<?= $activeView === 'login' ? 'active' : '' ?>" href="/login">Login</a>
            <a class="<?= $activeView === 'register' ? 'active' : '' ?>" href="/register">Înregistrare</a>
        </div>

        <div class="account-auth-grid">
            <article class="panel" style="<?= $activeView === 'login' ? '' : 'display:none;' ?>" data-auth-view="login">
                <h3 style="margin-top:0;">Autentificare</h3>
                <form method="post" action="/login" class="field">
                    <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
                    <label>Email</label>
                    <input type="email" name="email" required>
                    <label>Parolă</label>
                    <input type="password" name="password" required>
                    <button class="btn" type="submit">Login</button>
                </form>
                <p style="margin-top:10px;"><a href="/contul-meu/resetare-parola">Ai uitat parola?</a></p>
            </article>

            <article class="panel" style="<?= $activeView === 'register' ? '' : 'display:none;' ?>" data-auth-view="register">
                <h3 style="margin-top:0;">Creează cont</h3>
                <form method="post" action="/register" class="form-grid">
                    <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
                    <input type="hidden" name="register_form_token" value="<?= htmlspecialchars($registerAntiBotToken, ENT_QUOTES) ?>">
                    <input type="hidden" name="register_form_rendered_at" value="<?= $registerAntiBotRenderedAt > 0 ? $registerAntiBotRenderedAt : time() ?>">
                    <div class="field" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                        <label for="register-hp-website">Website</label>
                        <input id="register-hp-website" type="text" name="register_hp_website" value="" tabindex="-1" autocomplete="off">
                    </div>
                    <?php if (!empty($registrationFields['first_name'])): ?>
                        <div class="field">
                            <label>Prenume</label>
                            <input type="text" name="first_name" required>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($registrationFields['last_name'])): ?>
                        <div class="field">
                            <label>Nume</label>
                            <input type="text" name="last_name" required>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($registrationFields['email'])): ?>
                        <div class="field">
                            <label>Email</label>
                            <input type="email" name="email" required>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($registrationFields['phone'])): ?>
                        <div class="field">
                            <label>Telefon</label>
                            <input type="text" name="phone">
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($registrationFields['birth_date'])): ?>
                        <div class="field">
                            <label>Data nașterii</label>
                            <input type="date" name="birth_date">
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($registrationFields['gender'])): ?>
                        <div class="field">
                            <label>Gen</label>
                            <select name="gender">
                                <option value="">Selectează</option>
                                <option value="feminin">Feminin</option>
                                <option value="masculin">Masculin</option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($registrationFields['password'])): ?>
                        <div class="field">
                            <label>Parolă</label>
                            <input type="password" name="password" minlength="8" required>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($registrationFields['password_confirm'])): ?>
                        <div class="field">
                            <label>Confirmă parola</label>
                            <input type="password" name="password_confirm" minlength="8" required>
                        </div>
                    <?php endif; ?>
                    <div style="grid-column:1/-1;">
                        <button class="btn" type="submit">Creează cont</button>
                    </div>
                </form>
            </article>
        </div>
    <?php endif; ?>
</section>
<?php if (is_array($customer)): ?>
    <script>
        (() => {
            const toggle = document.getElementById('address-add-toggle');
            const form = document.getElementById('address-add-form');
            if (!toggle || !form) return;
            toggle.addEventListener('click', () => {
                const open = form.style.display !== 'none';
                form.style.display = open ? 'none' : 'grid';
            });
        })();
    </script>
<?php endif; ?>
