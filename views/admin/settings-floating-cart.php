<?php
$settings = is_array($settings ?? null) ? $settings : [];
$isEnabled = (string) ($settings['floating_cart_enabled'] ?? '1') !== '0';
$showOnMobile = (string) ($settings['floating_cart_show_mobile'] ?? '1') !== '0';
$showOnDesktop = (string) ($settings['floating_cart_show_desktop'] ?? '1') !== '0';
$openAfterAdd = (string) ($settings['floating_cart_auto_open_on_add'] ?? '1') !== '0';
$showPointsEarn = (string) ($settings['floating_cart_show_points_earned'] ?? '1') !== '0';
$showViewCart = (string) ($settings['floating_cart_show_view_cart_button'] ?? '1') !== '0';
$showCheckout = (string) ($settings['floating_cart_show_checkout_button'] ?? '1') !== '0';
$showSubtotal = (string) ($settings['floating_cart_show_subtotal'] ?? '1') !== '0';
$showDiscount = (string) ($settings['floating_cart_show_discount'] ?? '1') !== '0';
$showPointsDiscount = (string) ($settings['floating_cart_show_points_discount'] ?? '1') !== '0';
$showShipping = (string) ($settings['floating_cart_show_shipping'] ?? '1') !== '0';
$showVat = (string) ($settings['floating_cart_show_vat'] ?? '1') !== '0';
$position = in_array((string) ($settings['floating_cart_position'] ?? 'right'), ['right', 'left'], true)
    ? (string) ($settings['floating_cart_position'] ?? 'right')
    : 'right';
$triggerLabel = trim((string) ($settings['floating_cart_trigger_label'] ?? 'Coș'));
$titleLabel = trim((string) ($settings['floating_cart_title'] ?? 'Coșul tău'));
$viewCartLabel = trim((string) ($settings['floating_cart_view_cart_label'] ?? 'Vezi coșul'));
$checkoutLabel = trim((string) ($settings['floating_cart_checkout_label'] ?? 'Checkout'));
$accentColor = trim((string) ($settings['floating_cart_accent_color'] ?? '#0f766e'));
$badgeBg = trim((string) ($settings['floating_cart_badge_bg'] ?? '#ffffff'));
$badgeText = trim((string) ($settings['floating_cart_badge_text'] ?? '#0f766e'));
$panelWidth = (int) ($settings['floating_cart_panel_width'] ?? '420');
$freeShippingThreshold = (float) str_replace(',', '.', (string) ($settings['floating_cart_free_shipping_threshold'] ?? '200'));
$pointsText = trim((string) ($settings['floating_cart_points_text'] ?? 'Primești {points} puncte la această comandă.'));
$excludedUrls = trim((string) ($settings['floating_cart_excluded_urls'] ?? ''));
if ($panelWidth < 320) {
    $panelWidth = 320;
}
if ($panelWidth > 640) {
    $panelWidth = 640;
}
?>

<section class="panel">
    <div class="section-head" style="margin-bottom:10px;">
        <div>
            <h1>Coș flotant</h1>
            <p>Configurează comportamentul și afișarea coșului flotant în storefront.</p>
        </div>
    </div>

    <article class="panel" style="margin:12px 0;">
        <h3 style="margin-top:0;">Setări generale</h3>
        <form method="post" action="/admin/settings/floating-cart" class="form-grid">
            <input type="hidden" name="action" value="save_floating_cart_settings">

            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_enabled" <?= $isEnabled ? 'checked' : '' ?>>
                    Activează coșul flotant
                </label>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_open_on_add" <?= $openAfterAdd ? 'checked' : '' ?>>
                    Deschide automat după adăugare produs
                </label>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_desktop" <?= $showOnDesktop ? 'checked' : '' ?>>
                    Afișează pe desktop
                </label>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_mobile" <?= $showOnMobile ? 'checked' : '' ?>>
                    Afișează pe mobil
                </label>
            </div>

            <div class="field">
                <label>Text buton (opțional)</label>
                <input type="text" name="floating_cart_trigger_label" value="<?= htmlspecialchars($triggerLabel, ENT_QUOTES) ?>" placeholder="(gol = doar icon)">
                <small style="color:#64748b;">Lasă gol pentru buton cu icon-only + bulină număr produse.</small>
            </div>
            <div class="field">
                <label>Titlu panel</label>
                <input type="text" name="floating_cart_title" value="<?= htmlspecialchars($titleLabel, ENT_QUOTES) ?>" placeholder="Coșul tău">
            </div>

            <div class="field">
                <label>Poziție buton</label>
                <select name="floating_cart_position">
                    <option value="right" <?= $position === 'right' ? 'selected' : '' ?>>Dreapta jos</option>
                    <option value="left" <?= $position === 'left' ? 'selected' : '' ?>>Stânga jos</option>
                </select>
            </div>
            <div class="field">
                <label>Culoare buton</label>
                <input type="color" name="floating_cart_accent_color" value="<?= htmlspecialchars($accentColor !== '' ? $accentColor : '#0f766e', ENT_QUOTES) ?>">
            </div>

            <div class="field">
                <label>Culoare badge număr produse (fundal)</label>
                <input type="color" name="floating_cart_badge_bg" value="<?= htmlspecialchars($badgeBg !== '' ? $badgeBg : '#ffffff', ENT_QUOTES) ?>">
            </div>
            <div class="field">
                <label>Culoare badge număr produse (text)</label>
                <input type="color" name="floating_cart_badge_text" value="<?= htmlspecialchars($badgeText !== '' ? $badgeText : '#0f766e', ENT_QUOTES) ?>">
            </div>

            <div class="field">
                <label>Lățime panel (px)</label>
                <input type="number" min="320" max="640" step="1" name="floating_cart_panel_width" value="<?= (int) $panelWidth ?>">
            </div>
            <div class="field">
                <label>Offset orizontal buton (px)</label>
                <input type="number" min="0" max="500" step="1" name="floating_cart_offset_x" value="<?= (int) ($settings['floating_cart_offset_x'] ?? 18) ?>">
            </div>
            <div class="field">
                <label>Offset vertical buton (px)</label>
                <input type="number" min="0" max="500" step="1" name="floating_cart_offset_y" value="<?= (int) ($settings['floating_cart_offset_y'] ?? 18) ?>">
            </div>
            <div class="field">
                <label>Prag transport gratuit coș flotant (RON)</label>
                <input type="number" min="0" step="0.01" name="floating_cart_free_shipping_threshold" value="<?= htmlspecialchars(number_format($freeShippingThreshold, 2, '.', ''), ENT_QUOTES) ?>">
            </div>
            <div class="field">
                <label>Icon buton</label>
                <input type="text" name="floating_cart_trigger_icon" value="<?= htmlspecialchars((string) ($settings['floating_cart_trigger_icon'] ?? '🛒'), ENT_QUOTES) ?>" placeholder="🛒">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>URL-uri unde NU se afișează coșul flotant</label>
                <textarea name="floating_cart_excluded_urls" rows="4" placeholder="/checkout&#10;/contul-meu&#10;https://bioscem.ro/contact"><?= htmlspecialchars($excludedUrls, ENT_QUOTES) ?></textarea>
                <small style="color:#64748b;">Un URL pe rând. Poți pune path-uri (ex: /checkout) sau URL complet (se păstrează doar path-ul).</small>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_product_images" <?= ((string) ($settings['floating_cart_show_product_images'] ?? '1') !== '0') ? 'checked' : '' ?>>
                    Afișează imaginile produselor în listă
                </label>
            </div>
            <div class="field">
                <label>Text buton „Vezi coșul”</label>
                <input type="text" name="floating_cart_view_cart_label" value="<?= htmlspecialchars($viewCartLabel, ENT_QUOTES) ?>" placeholder="Vezi coșul">
            </div>
            <div class="field">
                <label>Text buton „Checkout”</label>
                <input type="text" name="floating_cart_checkout_label" value="<?= htmlspecialchars($checkoutLabel, ENT_QUOTES) ?>" placeholder="Checkout">
            </div>

            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_view_cart_button" <?= $showViewCart ? 'checked' : '' ?>>
                    Arată butonul „Vezi coșul”
                </label>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_checkout_button" <?= $showCheckout ? 'checked' : '' ?>>
                    Arată butonul „Checkout”
                </label>
            </div>

            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_subtotal_line" <?= $showSubtotal ? 'checked' : '' ?>>
                    Arată linia „Subtotal”
                </label>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_discount_line" <?= $showDiscount ? 'checked' : '' ?>>
                    Arată linia „Reducere”
                </label>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_points_discount_line" <?= $showPointsDiscount ? 'checked' : '' ?>>
                    Arată linia „Reducere puncte”
                </label>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_shipping_line" <?= $showShipping ? 'checked' : '' ?>>
                    Arată linia „Transport”
                </label>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_vat_line" <?= $showVat ? 'checked' : '' ?>>
                    Arată linia „TVA”
                </label>
            </div>

            <div class="field" style="grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="floating_cart_show_points_earned" <?= $showPointsEarn ? 'checked' : '' ?>>
                    Afișează punctele obținute pentru comanda curentă
                </label>
                <small style="color:#64748b;">Se calculează automat pe baza setărilor programului de fidelitate și coșului curent.</small>
            </div>
            <div class="field">
                <label>Poziție rând puncte</label>
                <select name="floating_cart_points_position">
                    <?php $pointsPosition = (string) ($settings['floating_cart_points_position'] ?? 'before_total'); ?>
                    <option value="before_total" <?= $pointsPosition === 'before_total' ? 'selected' : '' ?>>Înainte de total</option>
                    <option value="after_total" <?= $pointsPosition === 'after_total' ? 'selected' : '' ?>>După total</option>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Text puncte (folosește {points})</label>
                <input type="text" name="floating_cart_points_text" value="<?= htmlspecialchars($pointsText, ENT_QUOTES) ?>" placeholder="Primești {points} puncte la această comandă.">
            </div>

            <div class="field" style="grid-column:1/-1;">
                <button class="btn" type="submit">Salvează setările coșului flotant</button>
            </div>
        </form>
    </article>
</section>
