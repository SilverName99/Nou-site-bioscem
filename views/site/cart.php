<?php
$quantityUi = is_array($quantityUi ?? null) ? $quantityUi : ['style' => 'default', 'apply_cart_page' => false];
$quantityStyle = ((string) ($quantityUi['style'] ?? 'default') === 'stepper') ? 'stepper' : 'default';
$applyStepperInCart = true;
$previewMode = (bool) ($previewMode ?? false);
$isLoggedIn = (bool) ($isLoggedIn ?? false);
$lines = is_array($summary['lines'] ?? null) ? $summary['lines'] : [];
$subtotal = (float) ($summary['subtotal'] ?? 0);
$subtotalWithoutVat = (float) ($summary['subtotal_without_vat'] ?? $subtotal);
$vat = (float) ($summary['vat'] ?? 0);
$discount = (float) ($summary['discount'] ?? 0);
$pointsDiscount = (float) ($summary['points_discount'] ?? 0);
$total = (float) ($summary['total'] ?? 0);
$shippingThresholdRaw = (float) ($summary['shipping_free_threshold'] ?? 200);
$shippingThreshold = $shippingThresholdRaw > 0 ? $shippingThresholdRaw : 200.0;
$shippingReference = max(0.0, (float) ($summary['shipping_reference'] ?? ($subtotal - $discount - $pointsDiscount)));
$remainingForFreeShipping = max(0.0, $shippingThreshold - $shippingReference);
$freeShippingProgress = $shippingThreshold > 0 ? min(100.0, (($shippingReference / $shippingThreshold) * 100.0)) : 0.0;
$points = is_array($summary['points'] ?? null) ? $summary['points'] : [];
$pointsEnabled = !empty($points['enabled']);
$pointsAvailable = max(0, (int) ($points['available'] ?? 0));
$pointsRequested = max(0, (int) ($points['requested'] ?? 0));
$pointsMinRedeem = max(0, (int) ($points['min_redeem'] ?? 0));
$pointsMaxByCart = max(0, (int) ($points['max_points'] ?? 0));
$pointsSliderMax = max(0, min($pointsAvailable, $pointsMaxByCart > 0 ? $pointsMaxByCart : $pointsAvailable));
$pointsCanMeetMin = $pointsMinRedeem <= 0 || $pointsSliderMax >= $pointsMinRedeem;
$pointsSliderMin = $pointsCanMeetMin ? ($pointsMinRedeem > 0 ? $pointsMinRedeem : 0) : 0;
$pointsSliderValue = max($pointsSliderMin, min($pointsSliderMax, $pointsRequested > 0 ? $pointsRequested : $pointsSliderMin));
$loginUrl = '/login';
$couponCode = trim((string) (($summary['coupon']['code'] ?? '')));
$couponAppliesOnlySelectedProducts = ((int) (($summary['coupon']['applies_only_selected_products'] ?? 0))) === 1;
$heartbeatSnapshot = [];
foreach ($lines as $line) {
    if (!is_array($line)) {
        continue;
    }
    $name = trim((string) ($line['name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $qty = max(1, (int) ($line['quantity'] ?? 1));
    $lineTotal = number_format((float) ($line['line_total'] ?? 0.0), 2) . ' lei';
    $heartbeatSnapshot[] = $name . ' x' . $qty . ' - ' . $lineTotal;
}
?>

<section class="bv-cart-v2">
    <style>
        .bv-cart-v2{--cart-border:#e5e8e1;--cart-text:#26372e;--cart-muted:#7b8f83;--cart-primary:#2b8a57;--cart-bg:#fff;font-family:"DM Sans",Arial,sans-serif;color:var(--cart-text);width:min(1180px,100%);margin:0 auto;padding:8px 10px 24px;}
        .bv-cart-v2 *{box-sizing:border-box;}
        .bv-cart-v2__empty{border:1px solid var(--cart-border);border-radius:16px;background:var(--cart-bg);padding:24px;display:grid;gap:10px;}
        .bv-cart-v2__empty h2{margin:0;font-size:28px;line-height:1.15;font-family:"Playfair Display",Georgia,serif;}
        .bv-cart-v2__empty p{margin:0;color:var(--cart-muted);}
        .bv-cart-v2__empty .btn{width:max-content;}
        .bv-cart-v2__layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,.85fr);gap:26px;align-items:start;}
        .bv-cart-v2__main{border:1px solid var(--cart-border);border-radius:16px;background:var(--cart-bg);padding:10px 16px 16px;}
        .bv-cart-v2__head{display:grid;grid-template-columns:minmax(0,1.5fr) 170px 140px 44px;gap:12px;padding:12px 0 10px;border-bottom:1px solid var(--cart-border);}
        .bv-cart-v2__head div{font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#809587;}
        .bv-cart-v2__head div:nth-child(3){text-align:right;}
        .bv-cart-v2__head div:nth-child(4){text-align:center;}
        .bv-cart-v2__items{display:grid;}
        .bv-cart-v2__item{display:grid;grid-template-columns:minmax(0,1.5fr) 170px 140px 44px;gap:12px;align-items:center;padding:14px 0;border-bottom:1px solid #edf1eb;}
        .bv-cart-v2__item:last-child{border-bottom:0;}
        .bv-cart-v2__product{display:flex;align-items:center;gap:12px;min-width:0;}
        .bv-cart-v2__product-link{text-decoration:none;color:inherit;}
        .bv-cart-v2__thumb{width:58px;height:58px;border-radius:12px;border:1px solid #e7ece6;background:#f8faf7;overflow:hidden;display:flex;align-items:center;justify-content:center;flex:0 0 58px;}
        .bv-cart-v2__thumb img{width:100%;height:100%;object-fit:cover;display:block;}
        .bv-cart-v2__product-name{margin:0;font-size:16px;font-weight:700;color:#21342a;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .bv-cart-v2__product-sub{margin:2px 0 0;font-size:13px;color:var(--cart-muted);line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .bv-cart-v2__qty-cell{display:flex;align-items:center;}
        .bv-cart-v2 .qty-stepper.bv-cart-v2__qty{display:inline-flex;align-items:center;justify-content:center;gap:0;flex-wrap:nowrap;border:1px solid #d1d9e3;border-radius:999px;background:#fff;padding:1px 2px;height:30px;width:max-content;}
        .bv-cart-v2 .qty-stepper.bv-cart-v2__qty .qty-stepper__btn{border:0;background:transparent;border-radius:999px;width:27px;height:27px;line-height:1;font-size:14px;color:#1e293b;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}
        .bv-cart-v2 .qty-stepper.bv-cart-v2__qty .qty-stepper__input{width:38px;border:0;background:transparent;text-align:center;font-size:12px;font-weight:700;color:#0f172a;padding:0;margin:0;outline:none;line-height:1;height:27px;display:block;-moz-appearance:textfield;}
        .bv-cart-v2 .qty-stepper.bv-cart-v2__qty .qty-stepper__input::-webkit-outer-spin-button,
        .bv-cart-v2 .qty-stepper.bv-cart-v2__qty .qty-stepper__input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
        .bv-cart-v2__qty-input{width:69px;border:1px solid #dfe8df;border-radius:10px;padding:7px 6px;font:600 12px/1.2 "DM Sans",Arial,sans-serif;color:#203328;}
        .bv-cart-v2__price-box{display:grid;justify-items:end;gap:2px;}
        .bv-cart-v2__price{font:700 16px/1.2 "DM Sans",Arial,sans-serif;color:#1d3728;white-space:nowrap;text-align:right;}
        .bv-cart-v2__price-old{font:600 12px/1.2 "DM Sans",Arial,sans-serif;color:#7f8f86;text-decoration:line-through;white-space:nowrap;}
        .bv-cart-v2__price-discount{margin:0;font:600 11px/1.25 "DM Sans",Arial,sans-serif;color:#26734b;white-space:nowrap;}
        .bv-cart-v2__remove{justify-self:center;width:30px;height:30px;border:1px solid #ebd3d3;border-radius:999px;background:#fff;color:#c25f5f;cursor:pointer;font-size:13px;}
        .bv-cart-v2__coupon-area{margin-top:14px;padding-top:14px;border-top:1px solid #edf1eb;display:grid;gap:10px;max-width:460px;}
        .bv-cart-v2__aside{position:sticky;top:16px;border:1px solid #dfe8df;border-radius:16px;background:#fff;padding:16px;}
        .bv-cart-v2__aside h3{margin:0 0 10px;font:700 28px/1.05 "DM Sans",Arial,sans-serif;color:#22352c;letter-spacing:-.01em;}
        .bv-cart-v2__free{display:grid;gap:7px;margin-bottom:12px;}
        .bv-cart-v2__free-text{margin:0;font-size:14px;color:#698173;}
        .bv-cart-v2__free-text strong{color:#2d6a49;}
        .bv-cart-v2__progress{height:8px;background:#edf2ed;border-radius:999px;overflow:hidden;}
        .bv-cart-v2__progress > span{display:block;height:100%;background:linear-gradient(90deg,#4ca26f,#2b8a57);width:0;}
        .bv-cart-v2__rows{display:grid;gap:10px;margin:12px 0 14px;padding-top:12px;border-top:1px solid #edf2ed;}
        .bv-cart-v2__row{display:flex;align-items:center;justify-content:space-between;gap:10px;color:#6f8477;font-size:15px;}
        .bv-cart-v2__row strong{color:#203328;}
        .bv-cart-v2__row--danger strong{color:#b43737;}
        .bv-cart-v2__row--note strong{color:#698173;font-size:13px;font-weight:600;text-align:right;}
        .bv-cart-v2__total{display:flex;align-items:baseline;justify-content:space-between;gap:10px;padding-top:12px;border-top:1px solid #edf2ed;margin:0 0 12px;}
        .bv-cart-v2__total span{font:700 17px/1.3 "DM Sans",Arial,sans-serif;color:#1e3328;letter-spacing:0;}
        .bv-cart-v2__total strong{font:700 17px/1.3 "DM Sans",Arial,sans-serif;color:#1f3a2c;white-space:nowrap;letter-spacing:0;}
        .bv-cart-v2__checkout{display:inline-flex;justify-content:center;align-items:center;width:100%;text-decoration:none;border:0;border-radius:999px;padding:14px 18px;background:linear-gradient(180deg,#2f915c,#247d4e);color:#fff;font:700 16px/1.2 "DM Sans",Arial,sans-serif;}
        .bv-cart-v2__line-form label{display:block;margin:0 0 5px;color:#718779;font-size:12px;font-weight:600;}
        .bv-cart-v2__line-form .inline{display:flex;gap:8px;align-items:center;}
        .bv-cart-v2__line-form input{width:100%;border:1px solid #dce8dd;border-radius:10px;padding:10px 11px;font:500 13px/1.3 "DM Sans",Arial,sans-serif;}
        .bv-cart-v2__line-form button{border:0;border-radius:10px;padding:10px 12px;background:#2f915c;color:#fff;font:700 12px/1.2 "DM Sans",Arial,sans-serif;white-space:nowrap;cursor:pointer;}
        .bv-cart-v2__line-form--ghost button{background:#f0f5f1;color:#2f5f44;border:1px solid #d7e3d8;}
        .bv-cart-v2__line-form button.bv-cart-v2__line-form--ghost{background:#f0f5f1;color:#2f5f44;border:1px solid #d7e3d8;}
        .bv-cart-v2__coupon-note{margin:0;color:#2f6f4b;font:600 12px/1.35 "DM Sans",Arial,sans-serif;}
        .bv-cart-v2__points-login{margin:0;font-size:13px;line-height:1.45;color:#6f8477;}
        .bv-cart-v2__points-login a{color:#1f8b57;text-decoration:underline;font-weight:700;}
        .bv-cart-v2__points-slider-wrap{display:grid;gap:8px;}
        .bv-cart-v2__points-slider{--points-progress:0%;width:100%;height:10px;border-radius:999px;appearance:none;-webkit-appearance:none;background:linear-gradient(90deg,#2f915c 0%,#2f915c var(--points-progress),#dce8df var(--points-progress),#dce8df 100%);box-shadow:inset 0 0 0 1px #d4e1d7,inset 0 2px 5px rgba(27,63,43,.06);outline:none;transition:background .12s linear;}
        .bv-cart-v2__points-slider::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:22px;height:22px;border-radius:50%;background:#2f915c;border:3px solid #ffffff;box-shadow:0 2px 8px rgba(16,45,29,.30);cursor:pointer;}
        .bv-cart-v2__points-slider::-moz-range-thumb{width:22px;height:22px;border-radius:50%;background:#2f915c;border:3px solid #ffffff;box-shadow:0 2px 8px rgba(16,45,29,.30);cursor:pointer;}
        .bv-cart-v2__points-live{margin:0;font-size:12px;line-height:1.35;color:#2b6d49;font-weight:700;}
        .bv-cart-v2__points-help{margin:0;color:#8aa093;font-size:12px;line-height:1.35;}
        @media (max-width:1080px){
            .bv-cart-v2__layout{grid-template-columns:1fr;}
            .bv-cart-v2__aside{position:static;}
        }
        @media (max-width:780px){
            .bv-cart-v2__head{display:none;}
            .bv-cart-v2__item{grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"product remove" "qty price";gap:10px;padding:14px 0;}
            .bv-cart-v2__product{grid-area:product;}
            .bv-cart-v2__qty-cell{grid-area:qty;}
            .bv-cart-v2__price{grid-area:price;font-size:16px;}
            .bv-cart-v2__remove{grid-area:remove;justify-self:end;}
            .bv-cart-v2__total span{font-size:17px;}
            .bv-cart-v2__total strong{font-size:17px;}
        }
    </style>

    <?php if ($lines === []): ?>
        <article class="bv-cart-v2__empty">
            <h2>Coșul este gol</h2>
            <p>Adaugă produse în coș pentru a continua comanda.</p>
            <a class="btn" href="/magazin">Continuă cumpărăturile</a>
        </article>
    <?php else: ?>
        <div class="bv-cart-v2__layout">
            <article class="bv-cart-v2__main">
                <div class="bv-cart-v2__head">
                    <div>Produs</div>
                    <div>Cantitate</div>
                    <div>Preț</div>
                    <div></div>
                </div>
                <form method="post" action="/cos/actualizeaza" id="cart-update-form">
                    <div class="bv-cart-v2__items">
                        <?php foreach ($lines as $line): ?>
                            <?php if (!is_array($line)) {
                                continue;
                            } ?>
                            <article class="bv-cart-v2__item">
                                <a
                                    class="bv-cart-v2__product bv-cart-v2__product-link"
                                    href="/produs/<?= rawurlencode((string) ($line['slug'] ?? '')) ?>"
                                >
                                    <div class="bv-cart-v2__thumb">
                                        <img src="<?= htmlspecialchars((string) ($line['image_url'] ?? '/assets/img/product-placeholder.svg'), ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) ($line['name'] ?? 'Produs'), ENT_QUOTES) ?>">
                                    </div>
                                    <div style="min-width:0;">
                                        <p class="bv-cart-v2__product-name"><?= htmlspecialchars((string) ($line['name'] ?? 'Produs'), ENT_QUOTES) ?></p>
                                        <p class="bv-cart-v2__product-sub">
                                            <?php
                                            $bbdLabel = trim((string) ($line['bbd_label'] ?? ''));
                                            if ($bbdLabel !== '') {
                                                echo htmlspecialchars('Ofertă: ' . $bbdLabel, ENT_QUOTES);
                                            } else {
                                                echo htmlspecialchars((string) ($line['short_description'] ?? 'Suport optim pentru corp și minte.'), ENT_QUOTES);
                                            }
                                            ?>
                                        </p>
                                    </div>
                                </a>
                                <?php
                                $stocMaximLinie = isset($line['stoc_maxim']) && $line['stoc_maxim'] !== null
                                    ? max(0, (int) $line['stoc_maxim'])
                                    : null;
                                if ($stocMaximLinie !== null && $stocMaximLinie <= 0) {
                                    $stocMaximLinie = null;
                                }
                                ?>
                                <div class="bv-cart-v2__qty-cell">
                                    <?php if ($applyStepperInCart): ?>
                                        <div class="qty-stepper bv-cart-v2__qty cart-qty">
                                            <button type="button" class="qty-stepper__btn" data-role="qty-minus" data-qty-action="decrease" aria-label="Scade cantitatea">−</button>
                                            <input type="number" min="0"<?= $stocMaximLinie !== null ? ' max="' . $stocMaximLinie . '" data-stoc-maxim="' . $stocMaximLinie . '"' : '' ?> name="quantities[<?= htmlspecialchars((string) ($line['cart_item_key'] ?? $line['id'] ?? ''), ENT_QUOTES) ?>]" value="<?= (int) ($line['quantity'] ?? 1) ?>" class="qty-stepper__input">
                                            <button type="button" class="qty-stepper__btn" data-role="qty-plus" data-qty-action="increase" aria-label="Crește cantitatea">+</button>
                                        </div>
                                    <?php else: ?>
                                        <input class="bv-cart-v2__qty-input" type="number" min="0"<?= $stocMaximLinie !== null ? ' max="' . $stocMaximLinie . '" data-stoc-maxim="' . $stocMaximLinie . '"' : '' ?> name="quantities[<?= htmlspecialchars((string) ($line['cart_item_key'] ?? $line['id'] ?? ''), ENT_QUOTES) ?>]" value="<?= (int) ($line['quantity'] ?? 1) ?>">
                                    <?php endif; ?>
                                </div>
                                <?php
                                $lineTotal = max(0.0, (float) ($line['line_total'] ?? 0.0));
                                $lineCouponDiscount = max(0.0, (float) ($line['coupon_discount'] ?? 0.0));
                                $lineTotalAfterCoupon = max(0.0, (float) ($line['line_total_after_coupon'] ?? ($lineTotal - $lineCouponDiscount)));
                                ?>
                                <div class="bv-cart-v2__price-box">
                                    <?php if ($lineCouponDiscount > 0.0): ?>
                                        <span class="bv-cart-v2__price-old"><?= number_format($lineTotal, 2) ?> lei</span>
                                    <?php endif; ?>
                                    <div class="bv-cart-v2__price"><?= number_format($lineCouponDiscount > 0.0 ? $lineTotalAfterCoupon : $lineTotal, 2) ?> lei</div>
                                    <?php if ($lineCouponDiscount > 0.0): ?>
                                        <small class="bv-cart-v2__price-discount">Reducere cupon: -<?= number_format($lineCouponDiscount, 2) ?> lei</small>
                                    <?php endif; ?>
                                </div>
                                <button class="bv-cart-v2__remove" type="submit" formaction="/cos/sterge/<?= rawurlencode((string) ($line['cart_item_key'] ?? $line['id'] ?? '')) ?>" formmethod="post" title="Șterge produs">🗑</button>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </form>
                <div class="bv-cart-v2__coupon-area">
                    <form method="post" action="/cos/cupon" class="bv-cart-v2__line-form">
                        <label>Cod cupon</label>
                        <div class="inline">
                            <input type="text" name="coupon_code" placeholder="ex: BUNVENIT10" value="<?= htmlspecialchars($couponCode, ENT_QUOTES) ?>">
                            <button type="submit">Aplică</button>
                            <button type="submit" class="bv-cart-v2__line-form--ghost" formaction="/cos/cupon/sterge" formmethod="post">Elimină cupon</button>
                        </div>
                    </form>
                    <?php if ($couponCode !== '' && $couponAppliesOnlySelectedProducts): ?>
                        <p class="bv-cart-v2__coupon-note">Cuponul este activ doar pentru produsele selectate; celelalte produse rămân la preț întreg.</p>
                    <?php endif; ?>

                    <?php if ($pointsEnabled): ?>
                        <?php if (!$isLoggedIn): ?>
                            <p class="bv-cart-v2__points-login">
                                Pentru a folosi puncte, <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES) ?>">intră în cont</a>.
                            </p>
                        <?php elseif ($pointsSliderMax > 0 && $pointsCanMeetMin): ?>
                            <form method="post" action="/cos/puncte" class="bv-cart-v2__line-form">
                                <label>Folosește puncte de fidelitate</label>
                                <div class="bv-cart-v2__points-slider-wrap">
                                    <input
                                        class="bv-cart-v2__points-slider"
                                        type="range"
                                        min="<?= (int) $pointsSliderMin ?>"
                                        max="<?= (int) $pointsSliderMax ?>"
                                        step="1"
                                        value="<?= (int) $pointsSliderValue ?>"
                                        name="points"
                                        data-points-range
                                    >
                                    <p class="bv-cart-v2__points-live" data-points-live><?= (int) $pointsSliderValue ?>/<?= (int) $pointsSliderMax ?> puncte</p>
                                    <p class="bv-cart-v2__points-help">Glisează bara și apasă „Aplică”.</p>
                                </div>
                                <div class="inline">
                                    <button type="submit">Aplică</button>
                                </div>
                            </form>
                            <form method="post" action="/cos/puncte/sterge" class="bv-cart-v2__line-form bv-cart-v2__line-form--ghost">
                                <button type="submit">Elimină puncte</button>
                            </form>
                        <?php else: ?>
                            <p class="bv-cart-v2__points-login">
                                <?= htmlspecialchars((string) ($points['error'] ?? 'Nu ai puncte disponibile pentru acest coș.'), ENT_QUOTES) ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>

            <aside class="bv-cart-v2__aside">
                <h3>Sumar comandă</h3>
                <div class="bv-cart-v2__free">
                    <?php if ($remainingForFreeShipping > 0.009): ?>
                        <p class="bv-cart-v2__free-text">Mai adaugă <strong><?= number_format($remainingForFreeShipping, 2) ?> lei</strong> pentru transport gratuit</p>
                    <?php else: ?>
                        <p class="bv-cart-v2__free-text"><strong>Ai transport gratuit pe comandă.</strong></p>
                    <?php endif; ?>
                    <div class="bv-cart-v2__progress"><span style="width:<?= htmlspecialchars(number_format($freeShippingProgress, 2, '.', ''), ENT_QUOTES) ?>%;"></span></div>
                </div>
                <div class="bv-cart-v2__rows">
                    <div class="bv-cart-v2__row"><span>Subtotal</span><strong><?= number_format($subtotalWithoutVat, 2) ?> lei</strong></div>
                    <div class="bv-cart-v2__row"><span>TVA</span><strong><?= number_format($vat, 2) ?> lei</strong></div>
                    <div class="bv-cart-v2__row bv-cart-v2__row--note"><span>Transport</span><strong>Transportul va fi calculat la Checkout.</strong></div>
                    <?php if ($discount > 0): ?>
                        <div class="bv-cart-v2__row bv-cart-v2__row--danger"><span>Reducere</span><strong>-<?= number_format($discount, 2) ?> lei</strong></div>
                    <?php endif; ?>
                    <?php if ($pointsDiscount > 0): ?>
                        <div class="bv-cart-v2__row bv-cart-v2__row--danger"><span>Reducere puncte</span><strong>-<?= number_format($pointsDiscount, 2) ?> lei</strong></div>
                    <?php endif; ?>
                </div>
                <div class="bv-cart-v2__total">
                    <span>Total</span>
                    <strong><?= number_format($total, 2) ?> lei</strong>
                </div>
                <a class="bv-cart-v2__checkout" href="/checkout">Finalizează comanda</a>
            </aside>
        </div>
    <?php endif; ?>
</section>

<script>
(() => {
    const isPreview = <?= $previewMode ? 'true' : 'false' ?>;
    if (isPreview) {
        document.querySelectorAll('form').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            form.addEventListener('submit', (event) => event.preventDefault());
        });
        return;
    }

    const updateForm = document.getElementById('cart-update-form');
    let autoUpdateTimer = null;
    const triggerAutoUpdate = () => {
        if (!(updateForm instanceof HTMLFormElement)) {
            return;
        }
        if (autoUpdateTimer !== null) {
            window.clearTimeout(autoUpdateTimer);
        }
        autoUpdateTimer = window.setTimeout(() => {
            if (typeof updateForm.requestSubmit === 'function') {
                updateForm.requestSubmit();
                return;
            }
            updateForm.submit();
        }, 140);
    };

    document.querySelectorAll('.cart-qty').forEach((wrap) => {
        wrap.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            const action = target.getAttribute('data-qty-action');
            if (!action) {
                return;
            }
            const input = wrap.querySelector('input[type="number"]');
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            const current = Number(input.value || '0');
            const next = action === 'increase' ? current + 1 : Math.max(0, current - 1);
            input.value = String(next);
            triggerAutoUpdate();
        });
    });
    document.querySelectorAll('input[name^="quantities["]').forEach((input) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        input.addEventListener('change', triggerAutoUpdate);
        input.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') {
                return;
            }
            event.preventDefault();
            triggerAutoUpdate();
        });
    });

    const paintPointsSlider = (slider) => {
        if (!(slider instanceof HTMLInputElement)) {
            return;
        }
        const min = Number(slider.min || '0');
        const max = Number(slider.max || '0');
        const value = Number(slider.value || '0');
        const safeRange = max > min ? (max - min) : 1;
        const ratio = Math.max(0, Math.min(1, (value - min) / safeRange));
        const sliderWidth = Math.max(1, slider.clientWidth || 1);
        const thumbSize = 22;
        const progressPx = (ratio * Math.max(0, sliderWidth - thumbSize)) + (thumbSize / 2);
        const progress = Math.max(0, Math.min(100, (progressPx / sliderWidth) * 100));
        slider.style.setProperty('--points-progress', `${progress.toFixed(2)}%`);

        const liveNode = slider.closest('.bv-cart-v2__points-slider-wrap')?.querySelector('[data-points-live]');
        if (liveNode instanceof HTMLElement) {
            liveNode.textContent = `${Math.round(value)}/${Math.round(max)} puncte`;
        }
    };
    document.querySelectorAll('[data-points-range]').forEach((slider) => {
        if (!(slider instanceof HTMLInputElement)) {
            return;
        }
        paintPointsSlider(slider);
        slider.addEventListener('input', () => paintPointsSlider(slider));
        slider.addEventListener('change', () => paintPointsSlider(slider));
    });
    window.addEventListener('resize', () => {
        document.querySelectorAll('[data-points-range]').forEach((slider) => paintPointsSlider(slider));
    });

    const snapshot = () => {
        const rows = [];
        document.querySelectorAll('.bv-cart-v2__item').forEach((row) => {
            const name = row.querySelector('.bv-cart-v2__product-name')?.textContent?.trim() || '';
            const qtyInput = row.querySelector('input[name^="quantities["]');
            const qty = qtyInput instanceof HTMLInputElement ? qtyInput.value : '1';
            const total = row.querySelector('.bv-cart-v2__price')?.textContent?.trim() || '';
            if (name) {
                rows.push(`${name} x${qty} - ${total}`);
            }
        });
        if (rows.length === 0) {
            return <?= json_encode(implode("\n", $heartbeatSnapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        }
        return rows.join('\n');
    };

    const payload = {
        page: 'cart',
        cart_snapshot: snapshot()
    };

    fetch('/api/cart/heartbeat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        keepalive: true
    }).catch(() => {});
})();
</script>
