<?php
$coupons = is_array($coupons ?? null) ? $coupons : [];
$products = is_array($products ?? null) ? $products : [];
$categories = is_array($categories ?? null) ? $categories : [];
$users = is_array($users ?? null) ? $users : [];
$selectedCoupon = is_array($editingCoupon ?? null) ? $editingCoupon : null;
$selectedCouponId = (int) ($selectedCoupon['id'] ?? 0);

$selectedProductsMap = [];
$selectedCategoriesMap = [];
$selectedUsersMap = [];
$selectedApplyOnlySelectedProducts = false;
$selectedStacksWithDiscounts = true; // implicit: cupon nou se cumulează cu reducerile
if (is_array($selectedCoupon)) {
    $selectedProductsMap = array_fill_keys(array_map('intval', (array) ($selectedCoupon['product_ids'] ?? [])), true);
    $selectedCategoriesMap = array_fill_keys(array_map('intval', (array) ($selectedCoupon['category_ids'] ?? [])), true);
    $selectedUsersMap = array_fill_keys(array_map('intval', (array) ($selectedCoupon['allowed_user_ids'] ?? [])), true);
    $selectedApplyOnlySelectedProducts = ((int) ($selectedCoupon['apply_only_selected_products'] ?? 0)) === 1;
    $selectedStacksWithDiscounts = ((int) ($selectedCoupon['stacks_with_discounts'] ?? 1)) === 1;
}
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Cupoane</h1>
            <p>Configurează reduceri procentuale/valorice și reguli de aplicare în coș.</p>
        </div>
    </div>

    <div class="coupon-admin-shell">
        <aside class="coupon-admin-aside">
            <div class="coupon-admin-aside-head">
                <div>
                    <h3>Cupoane existente</h3>
                    <p>Selectează un cupon pentru editare sau creează unul nou.</p>
                </div>
                <a class="btn coupon-admin-new-btn" href="/admin/coupons">Cupon nou</a>
            </div>
            <?php if ($coupons === []): ?>
                <p class="coupon-admin-empty">Niciun cupon creat încă.</p>
            <?php else: ?>
                <div class="coupon-admin-existing-list">
                <?php foreach ($coupons as $coupon): ?>
                    <?php
                    $couponId = (int) ($coupon['id'] ?? 0);
                    $isActive = ((int) ($coupon['is_active'] ?? 0)) === 1;
                    $couponType = (string) ($coupon['type'] ?? 'fixed');
                    $couponValue = (float) ($coupon['value'] ?? 0);
                    ?>
                    <a href="/admin/coupons?coupon=<?= $couponId ?>" class="coupon-admin-existing-item <?= $couponId === $selectedCouponId ? 'active' : '' ?>">
                        <strong><?= htmlspecialchars((string) ($coupon['name'] ?? ''), ENT_QUOTES) ?></strong>
                        <small>
                            Cod: <?= htmlspecialchars((string) ($coupon['code'] ?? ''), ENT_QUOTES) ?>
                            • <?= $couponType === 'percent' ? ('-' . number_format($couponValue, 2) . '%') : ('-' . number_format($couponValue, 2) . ' lei') ?>
                        </small>
                        <span class="coupon-admin-state <?= $isActive ? 'active' : 'inactive' ?>">
                            <?= $isActive ? 'Activ' : 'Inactiv' ?>
                        </span>
                    </a>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </aside>

        <section class="coupon-admin-form-card">
            <h3><?= $selectedCouponId > 0 ? 'Editează cupon' : 'Creează cupon' ?></h3>
            <form method="post" action="/admin/coupons" class="coupon-admin-grid" id="coupon-form">
                <input type="hidden" name="id" value="<?= $selectedCouponId > 0 ? $selectedCouponId : 0 ?>">

                <div class="field coupon-admin-field">
                    <label>Denumire *</label>
                    <input type="text" name="name" required value="<?= htmlspecialchars((string) ($selectedCoupon['name'] ?? ''), ENT_QUOTES) ?>" placeholder="ex: TRANSPORT0">
                </div>
                <div class="field coupon-admin-field">
                    <label>Cod cupon *</label>
                    <input type="text" name="code" required value="<?= htmlspecialchars((string) ($selectedCoupon['code'] ?? ''), ENT_QUOTES) ?>" placeholder="ex: TRANSPORT0">
                </div>
                <div class="field coupon-admin-field">
                    <label>Tip reducere</label>
                    <?php $type = (string) ($selectedCoupon['type'] ?? 'fixed'); ?>
                    <select name="type">
                        <option value="fixed" <?= $type === 'fixed' ? 'selected' : '' ?>>Valoare fixă</option>
                        <option value="percent" <?= $type === 'percent' ? 'selected' : '' ?>>Procentuală</option>
                    </select>
                </div>
                <div class="field coupon-admin-field">
                    <label>Valoare reducere * (lei)</label>
                    <input type="number" step="0.01" min="0.01" name="value" required value="<?= htmlspecialchars((string) ($selectedCoupon['value'] ?? ''), ENT_QUOTES) ?>" placeholder="ex: 15.00">
                </div>
                <div class="field coupon-admin-field">
                    <label>Valabil de la</label>
                    <input type="datetime-local" name="starts_at" value="<?= htmlspecialchars((string) ($selectedCoupon['starts_at_local'] ?? ''), ENT_QUOTES) ?>">
                </div>
                <div class="field coupon-admin-field">
                    <label>Valabil până la</label>
                    <input type="datetime-local" name="ends_at" value="<?= htmlspecialchars((string) ($selectedCoupon['ends_at_local'] ?? ''), ENT_QUOTES) ?>">
                </div>
                <div class="field coupon-admin-field">
                    <label>Minim produse în coș</label>
                    <input type="number" min="0" step="1" name="min_items_count" value="<?= htmlspecialchars((string) ($selectedCoupon['min_items_count'] ?? ''), ENT_QUOTES) ?>">
                </div>
                <div class="field coupon-admin-field">
                    <label>Maxim produse în coș</label>
                    <input type="number" min="0" step="1" name="max_items_count" value="<?= htmlspecialchars((string) ($selectedCoupon['max_items_count'] ?? ''), ENT_QUOTES) ?>">
                </div>
                <div class="field coupon-admin-field">
                    <label>Număr maxim utilizări (total)</label>
                    <input type="number" min="0" step="1" name="max_uses_total" value="<?= htmlspecialchars((string) ($selectedCoupon['max_uses_total'] ?? ''), ENT_QUOTES) ?>">
                    <small>Lasă 0/gol pentru utilizări nelimitate.</small>
                </div>

                <div class="field coupon-admin-field coupon-admin-span-full">
                    <label>Doar pentru produse (opțional, selecție multiplă)</label>
                    <div class="coupon-admin-checklist" role="group" aria-label="Produse eligibile cupon">
                        <?php foreach ($products as $product): ?>
                            <?php $productId = (int) ($product['id'] ?? 0); ?>
                            <label class="coupon-admin-checkline">
                                <input type="checkbox" name="product_ids[]" value="<?= $productId ?>" <?= isset($selectedProductsMap[$productId]) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <small>Ctrl/Cmd pentru selecție multiplă.</small>
                    <label class="coupon-admin-active-check coupon-admin-product-only-check">
                        <input
                            type="checkbox"
                            name="apply_only_selected_products"
                            value="1"
                            <?= $selectedApplyOnlySelectedProducts ? 'checked' : '' ?>
                        >
                        <span>Cuponul se va aplica doar la produsele selectate, dar va merge și cu alte produse adăugate în coș.</span>
                    </label>
                </div>

                <div class="field coupon-admin-field coupon-admin-span-full">
                    <label>Doar pentru categorii (opțional, selecție multiplă)</label>
                    <div class="coupon-admin-checklist" role="group" aria-label="Categorii eligibile cupon">
                        <?php foreach ($categories as $category): ?>
                            <?php $categoryId = (int) ($category['id'] ?? 0); ?>
                            <label class="coupon-admin-checkline">
                                <input type="checkbox" name="category_ids[]" value="<?= $categoryId ?>" <?= isset($selectedCategoriesMap[$categoryId]) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="field coupon-admin-field coupon-admin-span-full">
                    <label>Doar pentru utilizatori (opțional, selecție multiplă)</label>
                    <input
                        type="text"
                        class="coupon-admin-check-search"
                        placeholder="Caută utilizator după nume sau email..."
                        data-coupon-user-search
                        autocomplete="off"
                    >
                    <div class="coupon-admin-checklist" role="group" aria-label="Utilizatori eligibili cupon">
                        <?php foreach ($users as $user): ?>
                            <?php
                            $userId = (int) ($user['id'] ?? 0);
                            $fullName = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
                            $email = trim((string) ($user['email'] ?? ''));
                            $label = $fullName !== '' ? $fullName : ($email !== '' ? $email : 'Utilizator');
                            $searchHaystack = strtolower(trim((string) ($label . ' ' . $email)));
                            ?>
                            <label class="coupon-admin-checkline" data-coupon-user-item data-search="<?= htmlspecialchars($searchHaystack, ENT_QUOTES) ?>">
                                <input type="checkbox" name="allowed_user_ids[]" value="<?= $userId ?>" <?= isset($selectedUsersMap[$userId]) ? 'checked' : '' ?>>
                                <span>
                                    <?= htmlspecialchars($label, ENT_QUOTES) ?>
                                    <?php if ($email !== '' && $email !== $label): ?>
                                        (<?= htmlspecialchars($email, ENT_QUOTES) ?>)
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="coupon-admin-check-empty" data-coupon-user-empty hidden>Nu există utilizatori pentru căutarea introdusă.</p>
                    <small>Dacă nu selectezi utilizatori, cuponul poate fi folosit de toți.</small>
                </div>

                <div class="field coupon-admin-field coupon-admin-span-full">
                    <label class="coupon-admin-active-check">
                        <input type="checkbox" name="stacks_with_discounts" value="1" <?= $selectedStacksWithDiscounts ? 'checked' : '' ?>>
                        <span>Se cumulează cu alte reduceri / promoții</span>
                    </label>
                    <small>Bifat: cuponul se aplică și peste produsele aflate deja la reducere. Debifat: cuponul reduce doar produsele la preț întreg (produsele la promoție sunt ignorate de cupon).</small>
                </div>

                <div class="field coupon-admin-field coupon-admin-span-full">
                    <label class="coupon-admin-active-check">
                        <input type="checkbox" name="is_active" value="1" <?= ((string) ($selectedCoupon['is_active'] ?? '1')) === '1' ? 'checked' : '' ?>>
                        <span>Cupon activ</span>
                    </label>
                </div>

                <div class="coupon-admin-actions">
                    <div>
                        <?php if ($selectedCouponId > 0): ?>
                            <button
                                class="btn btn-secondary"
                                type="submit"
                                name="action"
                                value="delete"
                                form="coupon-form"
                                onclick="return confirm('Ștergi acest cupon?');"
                            >
                                Șterge cupon
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="coupon-admin-actions-right">
                        <button class="btn coupon-admin-save-btn" type="submit" name="action" value="save">
                            <?= $selectedCouponId > 0 ? 'Salvează cupon' : 'Creează cupon' ?>
                        </button>
                    </div>
                </div>
            </form>
        </section>
    </div>
</section>

<script>
(() => {
    const searchInput = document.querySelector('[data-coupon-user-search]');
    if (!(searchInput instanceof HTMLInputElement)) {
        return;
    }

    const userItems = Array.from(document.querySelectorAll('[data-coupon-user-item]'));
    const emptyState = document.querySelector('[data-coupon-user-empty]');

    const applyFilter = () => {
        const query = (searchInput.value || '').trim().toLowerCase();
        let visibleCount = 0;
        userItems.forEach((item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }
            const haystack = (item.getAttribute('data-search') || '').toLowerCase();
            const isVisible = query === '' || haystack.includes(query);
            item.style.display = isVisible ? '' : 'none';
            if (isVisible) {
                visibleCount += 1;
            }
        });

        if (emptyState instanceof HTMLElement) {
            emptyState.hidden = visibleCount > 0;
        }
    };

    searchInput.addEventListener('input', applyFilter);
    applyFilter();
})();
</script>
