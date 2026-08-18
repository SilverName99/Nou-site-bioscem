<?php
$summary = is_array($summary ?? null) ? $summary : [];
$values = is_array($values ?? null) ? $values : [];
$lines = is_array($summary['lines'] ?? null) ? $summary['lines'] : [];
$previewMode = (bool) ($previewMode ?? false);
$instanceId = (string) ($checkoutInstanceId ?? ('checkout-' . substr(sha1((string) microtime(true) . '-' . random_int(1000, 9999)), 0, 10)));
$fanCounties = is_array($fanCounties ?? null) ? $fanCounties : [];
$localitiesEndpoint = trim((string) ($localitiesEndpoint ?? '/api/fan/localities'));
$shippingQuoteEndpoint = trim((string) ($shippingQuoteEndpoint ?? '/api/checkout/shipping-quote'));
$subtotal = (float) ($summary['subtotal'] ?? 0);
$subtotalWithoutVat = (float) ($summary['subtotal_without_vat'] ?? $subtotal);
$discount = (float) ($summary['discount'] ?? 0);
$pointsDiscount = (float) ($summary['points_discount'] ?? 0);
$vat = (float) ($summary['vat'] ?? 0);
$shipping = (float) ($summary['shipping'] ?? 0);
$total = (float) ($summary['total'] ?? 0);
$coupon = is_array($summary['coupon'] ?? null) ? $summary['coupon'] : null;
$couponCode = trim((string) ($coupon['code'] ?? ''));
$couponAppliesOnlySelectedProducts = ((int) (($coupon['applies_only_selected_products'] ?? 0))) === 1;
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
$isLoggedIn = (bool) ($isLoggedIn ?? false);
$loginUrl = '/login';
// Metodele de card active vin din setări (EuPlătesc implicit, Stripe opțional).
$cardMethods = array_values(array_filter(
    (array) ($cardMethods ?? []),
    static fn ($m): bool => in_array($m, ['euplatesc', 'stripe'], true)
));
$cardLabels = [
    'euplatesc' => ['Card bancar', 'Plată online securizată prin EuPlătesc'],
    'stripe' => ['Card (Stripe)', 'Plată online securizată'],
];
$paymentMethods = array_merge($cardMethods, ['cod']);
$paymentMethod = (string) ($values['payment_method'] ?? '');
if (!in_array($paymentMethod, $paymentMethods, true)) {
    $paymentMethod = $paymentMethods[0];
}
$fanboxDisponibil = (bool) ($fanboxDisponibil ?? false);
$fanboxAles = (int) ($fanboxAles ?? 0);
$fanboxPret = $fanboxPret ?? null;
$antiBot = is_array($antiBot ?? null) ? $antiBot : [];
$antiBotToken = trim((string) ($antiBot['token'] ?? ''));
$antiBotRenderedAt = (int) ($antiBot['rendered_at'] ?? 0);
?>

<section class="bv-checkout-v3" data-checkout-instance="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>" data-preview-mode="<?= $previewMode ? '1' : '0' ?>">
    <style>
        .bv-checkout-v3{--co-bg:#f6faf8;--co-surface:#ffffff;--co-border:#dbe7df;--co-muted:#5d6e65;--co-text:#173625;--co-primary:#1f8b57;--co-primary-strong:#157246;--co-danger:#c62828;font-family:"DM Sans",Arial,sans-serif;color:var(--co-text);}
        .bv-checkout-v3 *{box-sizing:border-box;}
        .bv-checkout-v3__shell{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(300px,.9fr);gap:18px;align-items:start;}
        .bv-checkout-v3__card{background:var(--co-surface);border:1px solid var(--co-border);border-radius:18px;box-shadow:0 12px 30px rgba(17,43,30,.06);}
        .bv-checkout-v3__form{padding:18px;}
        .bv-checkout-v3__form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
        .bv-checkout-v3__field{display:grid;gap:6px;}
        .bv-checkout-v3__field--full{grid-column:1/-1;}
        .bv-checkout-v3__field label{font:600 12px/1.25 "DM Sans",Arial,sans-serif;color:#2f5842;letter-spacing:.01em;}
        .bv-checkout-v3__field input,.bv-checkout-v3__field textarea,.bv-checkout-v3__field select{width:100%;border:1px solid #d2e0d7;background:#fff;color:#183728;border-radius:11px;padding:11px 12px;font:500 14px/1.3 "DM Sans",Arial,sans-serif;outline:none;transition:border-color .18s ease,box-shadow .18s ease;}
        .bv-checkout-v3__locality-wrap{position:relative;}
        .bv-checkout-v3__locality-list{position:absolute;left:0;right:0;top:calc(100% + 4px);max-height:210px;overflow:auto;z-index:20;border:1px solid #cfe0d6;border-radius:10px;background:#fff;box-shadow:0 12px 24px rgba(20,56,38,.14);display:none;}
        .bv-checkout-v3__locality-list.is-open{display:block;}
        .bv-checkout-v3__locality-item{width:100%;display:block;border:0;background:#fff;text-align:left;padding:9px 10px;cursor:pointer;font:500 13px/1.35 "DM Sans",Arial,sans-serif;color:#153322;}
        .bv-checkout-v3__locality-item:hover{background:#eff8f2;}
        .bv-checkout-v3__locality-empty{padding:9px 10px;font:500 12px/1.35 "DM Sans",Arial,sans-serif;color:#60786b;}
        .bv-checkout-v3__field textarea{resize:vertical;min-height:84px;}
        .bv-checkout-v3__checkbox{display:flex;align-items:center;gap:8px;font:600 13px/1.3 "DM Sans",Arial,sans-serif;color:#1d3b2a;}
        .bv-checkout-v3__checkbox input{width:16px;height:16px;accent-color:#1f8b57;}
        .bv-checkout-v3__company-fields{display:none;}
        .bv-checkout-v3__company-fields.is-visible{display:contents;}
        /* display:none — browserele NU completează automat câmpurile ascunse
           astfel; poziționarea în afara ecranului le lăsa vizibile pentru
           autofill și bloca clienți reali. */
        .bv-checkout-v3__hp{display:none;}
        .bv-checkout-v3__fanbox{border:1px solid #d5e5dc;border-radius:12px;padding:12px 14px;background:#f6faf8;}
        .bv-checkout-v3__fanbox-pick{display:none;margin-top:10px;}
        .bv-checkout-v3__fanbox-pick.is-visible{display:block;}
        .bv-checkout-v3__fanbox-pick select{width:100%;}
        .bv-checkout-v3__fanbox-note{margin:6px 0 0;font-size:13px;color:#64748b;}
        .bv-checkout-v3__fanbox-maptoggle{margin-top:8px;border:0;background:none;padding:0;color:#1f8b57;font:600 13px/1.2 "DM Sans",Arial,sans-serif;text-decoration:underline;cursor:pointer;}
        .bv-checkout-v3__fanbox-map{margin-top:10px;height:320px;border-radius:12px;overflow:hidden;border:1px solid #d2e0d7;}
        .bv-checkout-v3__fanbox-map .leaflet-container{height:100%;width:100%;}
        .bv-checkout-v3__field input:focus,.bv-checkout-v3__field textarea:focus,.bv-checkout-v3__field select:focus{border-color:#3aa26f;box-shadow:0 0 0 3px rgba(42,140,89,.14);}
        .bv-checkout-v3__payment{margin:6px 0 0;display:grid;grid-template-columns:1fr 1fr;gap:10px;}
        .bv-checkout-v3__method{position:relative;}
        .bv-checkout-v3__method input{position:absolute;opacity:0;pointer-events:none;}
        .bv-checkout-v3__method-label{display:flex;align-items:center;gap:9px;border:1px solid #d2e0d7;background:#fff;border-radius:12px;padding:10px 12px;cursor:pointer;user-select:none;transition:border-color .18s ease,background .18s ease,box-shadow .18s ease;}
        .bv-checkout-v3__method-label svg{width:16px;height:16px;flex:0 0 16px;color:#3b7e5d;}
        .bv-checkout-v3__method-label strong{font:600 14px/1.2 "DM Sans",Arial,sans-serif;color:#143221;}
        .bv-checkout-v3__method-label span{font:400 12px/1.2 "DM Sans",Arial,sans-serif;color:#6a7d71;}
        .bv-checkout-v3__method input:checked + .bv-checkout-v3__method-label{border-color:#2f9a64;background:#f1fbf5;box-shadow:0 0 0 3px rgba(42,140,89,.12);}
        .bv-checkout-v3__summary{padding:18px;position:sticky;top:18px;}
        .bv-checkout-v3__summary h3{margin:0 0 12px;font:700 22px/1.1 "Playfair Display",Georgia,serif;color:#163422;}
        .bv-checkout-v3__items{display:grid;gap:8px;margin:0 0 12px;padding:0;list-style:none;max-height:220px;overflow:auto;}
        .bv-checkout-v3__item{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid #edf3ee;}
        .bv-checkout-v3__item:last-child{border-bottom:0;}
        .bv-checkout-v3__coupon{margin:0 0 12px;padding:10px;border:1px solid #e2ece6;border-radius:12px;background:#f9fcfa;}
        .bv-checkout-v3__coupon-form{display:flex;gap:8px;align-items:center;}
        .bv-checkout-v3__coupon-form input{flex:1 1 auto;min-width:0;border:1px solid #d2e0d7;background:#fff;color:#183728;border-radius:10px;padding:9px 11px;font:500 13px/1.3 "DM Sans",Arial,sans-serif;outline:none;}
        .bv-checkout-v3__coupon-form input:focus{border-color:#3aa26f;box-shadow:0 0 0 3px rgba(42,140,89,.14);}
        .bv-checkout-v3__coupon-form .btn{padding:10px 14px;white-space:nowrap;}
        .bv-checkout-v3__coupon-meta{margin:7px 0 0;font:500 12px/1.3 "DM Sans",Arial,sans-serif;color:#2f5f47;}
        .bv-checkout-v3__points-box{margin:10px 0 0;padding-top:10px;border-top:1px solid #e2ece6;display:grid;gap:8px;}
        .bv-checkout-v3__points-form{display:grid;gap:8px;}
        .bv-checkout-v3__points-slider-wrap{display:grid;gap:8px;}
        .bv-checkout-v3__points-slider{--points-progress:0%;width:100%;height:10px;border-radius:999px;appearance:none;-webkit-appearance:none;background:linear-gradient(90deg,#2f915c 0%,#2f915c var(--points-progress),#dce8df var(--points-progress),#dce8df 100%);box-shadow:inset 0 0 0 1px #d4e1d7,inset 0 2px 5px rgba(27,63,43,.06);outline:none;transition:background .12s linear;}
        .bv-checkout-v3__points-slider::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:22px;height:22px;border-radius:50%;background:#2f915c;border:3px solid #ffffff;box-shadow:0 2px 8px rgba(16,45,29,.30);cursor:pointer;}
        .bv-checkout-v3__points-slider::-moz-range-thumb{width:22px;height:22px;border-radius:50%;background:#2f915c;border:3px solid #ffffff;box-shadow:0 2px 8px rgba(16,45,29,.30);cursor:pointer;}
        .bv-checkout-v3__points-live{margin:0;font:700 12px/1.35 "DM Sans",Arial,sans-serif;color:#2b6d49;}
        .bv-checkout-v3__points-help{margin:0;color:#8aa093;font:500 12px/1.35 "DM Sans",Arial,sans-serif;}
        .bv-checkout-v3__points-login{margin:0;font:500 12px/1.35 "DM Sans",Arial,sans-serif;color:#5f7367;}
        .bv-checkout-v3__points-login a{color:#1f8b57;text-decoration:underline;font-weight:700;}
        .bv-checkout-v3__item-name{margin:0;font:600 14px/1.35 "DM Sans",Arial,sans-serif;color:#1b3728;}
        .bv-checkout-v3__item-meta{margin:2px 0 0;font:400 12px/1.3 "DM Sans",Arial,sans-serif;color:#688072;}
        .bv-checkout-v3__item-value{font:700 14px/1.2 "DM Sans",Arial,sans-serif;color:#1a3929;white-space:nowrap;}
        .bv-checkout-v3__item-value-wrap{display:grid;justify-items:end;gap:2px;}
        .bv-checkout-v3__item-value-old{font:600 11px/1.2 "DM Sans",Arial,sans-serif;color:#7f8f86;text-decoration:line-through;white-space:nowrap;}
        .bv-checkout-v3__item-value-discount{font:600 11px/1.2 "DM Sans",Arial,sans-serif;color:#26734b;white-space:nowrap;}
        .bv-checkout-v3__totals{display:grid;gap:7px;margin:2px 0 14px;}
        .bv-checkout-v3__row{display:flex;justify-content:space-between;gap:10px;font:500 14px/1.35 "DM Sans",Arial,sans-serif;color:#315542;}
        .bv-checkout-v3__row strong{font-weight:700;color:#163423;}
        .bv-checkout-v3__row strong.bv-checkout-v3__shipping-pending{font-weight:500;color:#6b7f73;font-size:12px;line-height:1.35;text-align:right;}
        .bv-checkout-v3__row--danger strong{color:var(--co-danger);}
        .bv-checkout-v3__row--total{margin-top:2px;padding-top:10px;border-top:1px solid #dfe9e3;font:700 17px/1.3 "DM Sans",Arial,sans-serif;color:#123220;}
        .bv-checkout-v3__submit{display:inline-flex;align-items:center;justify-content:center;width:100%;border:0;border-radius:999px;padding:13px 18px;background:linear-gradient(180deg,var(--co-primary),var(--co-primary-strong));color:#fff;font:700 14px/1.2 "DM Sans",Arial,sans-serif;cursor:pointer;transition:transform .18s ease,box-shadow .18s ease,filter .18s ease;}
        .bv-checkout-v3__submit:hover{transform:translateY(-1px);box-shadow:0 10px 20px rgba(26,106,67,.24);filter:saturate(1.03);}
        .bv-checkout-v3__submit:disabled{opacity:.7;cursor:not-allowed;transform:none;box-shadow:none;}
        .bv-checkout-v3__note{margin:10px 0 0;text-align:center;font:400 12px/1.35 "DM Sans",Arial,sans-serif;color:#6b7f73;}
        .bv-checkout-v3__shipping-error{margin:8px 0 0;padding:8px 10px;border:1px solid #f3c6c6;background:#fff3f3;color:#8f1d1d;border-radius:10px;font:600 12px/1.35 "DM Sans",Arial,sans-serif;display:none;}
        .bv-checkout-v3__shipping-error.is-visible{display:block;}
        .bv-checkout-v3__empty{padding:18px;display:grid;gap:10px;}
        .bv-checkout-v3__empty p{margin:0;color:#546a5d;font:400 15px/1.6 "DM Sans",Arial,sans-serif;}
        .bv-checkout-v3__empty a{display:inline-flex;width:max-content;align-items:center;gap:6px;padding:10px 14px;border-radius:999px;text-decoration:none;background:#edf7f0;color:#145537;font:700 13px/1.2 "DM Sans",Arial,sans-serif;}
        @media (max-width:1000px){
            .bv-checkout-v3__shell{grid-template-columns:1fr;}
            .bv-checkout-v3__summary{position:static;}
        }
        @media (max-width:700px){
            .bv-checkout-v3__form,.bv-checkout-v3__summary{padding:14px;}
            .bv-checkout-v3__form-grid{grid-template-columns:1fr;}
            .bv-checkout-v3__payment{grid-template-columns:1fr;}
        }
    </style>

    <?php if ($lines === []): ?>
        <article class="bv-checkout-v3__card bv-checkout-v3__empty">
            <h3 style="margin:0;font:700 22px/1.1 'Playfair Display',Georgia,serif;color:#173625;">Coșul este gol</h3>
            <p>Nu poți finaliza checkout-ul fără produse în coș.</p>
            <a href="/magazin">Vezi produse</a>
        </article>
    <?php else: ?>
        <div class="bv-checkout-v3__shell">
            <article class="bv-checkout-v3__card">
                <form class="bv-checkout-v3__form" id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-form" method="post" action="/checkout" autocomplete="off" data-lpignore="true">
                    <input type="hidden" name="checkout_form_token" value="<?= htmlspecialchars($antiBotToken, ENT_QUOTES) ?>">
                    <input type="hidden" name="checkout_form_rendered_at" value="<?= $antiBotRenderedAt > 0 ? $antiBotRenderedAt : time() ?>">
                    <div class="bv-checkout-v3__hp" aria-hidden="true">
                        <input
                            id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-extra-ref"
                            type="text"
                            name="<?= htmlspecialchars(\App\Http\Controllers\SiteController::CHECKOUT_HONEYPOT_FIELD, ENT_QUOTES) ?>"
                            value=""
                            tabindex="-1"
                            autocomplete="off"
                            data-lpignore="true"
                            data-1p-ignore
                        >
                    </div>
                    <div class="bv-checkout-v3__form-grid">
                        <div class="bv-checkout-v3__field">
                            <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-first">Nume *</label>
                            <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-first" type="text" name="billing_first_name" value="<?= htmlspecialchars((string) ($values['billing_first_name'] ?? ''), ENT_QUOTES) ?>" required>
                        </div>
                        <div class="bv-checkout-v3__field">
                            <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-last">Prenume *</label>
                            <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-last" type="text" name="billing_last_name" value="<?= htmlspecialchars((string) ($values['billing_last_name'] ?? ''), ENT_QUOTES) ?>" required>
                        </div>
                        <div class="bv-checkout-v3__field">
                            <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-email">Email *</label>
                            <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-email" type="email" name="billing_email" value="<?= htmlspecialchars((string) ($values['billing_email'] ?? ''), ENT_QUOTES) ?>" required>
                        </div>
                        <div class="bv-checkout-v3__field">
                            <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-phone">Telefon *</label>
                            <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-phone" type="text" name="billing_phone" value="<?= htmlspecialchars((string) ($values['billing_phone'] ?? ''), ENT_QUOTES) ?>" required>
                        </div>
                        <div class="bv-checkout-v3__field">
                            <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-street">Stradă *</label>
                            <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-street" type="text" name="billing_street" value="<?= htmlspecialchars((string) ($values['billing_street'] ?? ''), ENT_QUOTES) ?>" required>
                        </div>
                        <div class="bv-checkout-v3__field">
                            <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-street-no">Nr. *</label>
                            <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-street-no" type="text" name="billing_street_no" value="<?= htmlspecialchars((string) ($values['billing_street_no'] ?? ''), ENT_QUOTES) ?>" required>
                        </div>
                        <div class="bv-checkout-v3__field bv-checkout-v3__field--full">
                            <label class="bv-checkout-v3__checkbox">
                                <input
                                    type="checkbox"
                                    name="billing_is_company"
                                    value="1"
                                    data-billing-is-company
                                    <?= ((int) ($values['billing_is_company'] ?? 0)) === 1 ? 'checked' : '' ?>
                                >
                                <span>Persoană juridică</span>
                            </label>
                        </div>
                        <div class="bv-checkout-v3__company-fields<?= ((int) ($values['billing_is_company'] ?? 0)) === 1 ? ' is-visible' : '' ?>" data-company-fields>
                            <div class="bv-checkout-v3__field bv-checkout-v3__field--full">
                                <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-company-name">Denumire completă (Firma)</label>
                                <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-company-name" type="text" name="billing_company_name" value="<?= htmlspecialchars((string) ($values['billing_company_name'] ?? ''), ENT_QUOTES) ?>" data-company-required>
                            </div>
                            <div class="bv-checkout-v3__field">
                                <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-company-tax-id">Cod Unic de Înregistrare (CUI) / Cod fiscal</label>
                                <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-company-tax-id" type="text" name="billing_company_tax_id" value="<?= htmlspecialchars((string) ($values['billing_company_tax_id'] ?? ''), ENT_QUOTES) ?>" data-company-required>
                            </div>
                            <div class="bv-checkout-v3__field">
                                <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-company-registration-no">Număr de ordine în Registrul Comerțului (J)</label>
                                <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-company-registration-no" type="text" name="billing_company_registration_no" value="<?= htmlspecialchars((string) ($values['billing_company_registration_no'] ?? ''), ENT_QUOTES) ?>" data-company-required>
                            </div>
                        </div>
                        <div class="bv-checkout-v3__field">
                            <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-county">Județ *</label>
                            <?php $selectedCounty = trim((string) ($values['billing_county'] ?? '')); ?>
                            <?php if ($fanCounties !== []): ?>
                                <select id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-county" name="billing_county" required data-fan-county-select>
                                    <option value="">Alege județul</option>
                                    <?php foreach ($fanCounties as $county): ?>
                                        <?php $county = trim((string) $county); if ($county === '') { continue; } ?>
                                        <option value="<?= htmlspecialchars($county, ENT_QUOTES) ?>" <?= ($selectedCounty !== '' && mb_strtolower($county) === mb_strtolower($selectedCounty)) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($county, ENT_QUOTES) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-county" type="text" name="billing_county" value="<?= htmlspecialchars($selectedCounty, ENT_QUOTES) ?>" required data-fan-county-select>
                            <?php endif; ?>
                        </div>
                        <div class="bv-checkout-v3__field">
                            <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-city">Localitate *</label>
                            <div class="bv-checkout-v3__locality-wrap">
                                <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-city" type="text" name="billing_city" autocomplete="new-password" value="<?= htmlspecialchars((string) ($values['billing_city'] ?? ''), ENT_QUOTES) ?>" required data-fan-locality-input autocorrect="off" autocapitalize="off" spellcheck="false" readonly onfocus="this.removeAttribute('readonly');">
                                <div class="bv-checkout-v3__locality-list" data-fan-locality-list role="listbox" aria-label="Sugestii localitate"></div>
                            </div>
                        </div>
                        <div class="bv-checkout-v3__field">
                            <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-postcode">Cod poștal *</label>
                            <input id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-postcode" type="text" name="billing_postcode" value="<?= htmlspecialchars((string) ($values['billing_postcode'] ?? ''), ENT_QUOTES) ?>" required pattern="[0-9]{6}" maxlength="6" inputmode="numeric" title="Codul poștal trebuie să conțină exact 6 cifre">
                        </div>
                        <?php if (!empty($fanboxDisponibil)): ?>
                        <div class="bv-checkout-v3__field bv-checkout-v3__field--full bv-checkout-v3__fanbox" data-fanbox-block>
                            <label class="bv-checkout-v3__checkbox">
                                <input type="checkbox" name="livrare_fanbox" value="1" data-fanbox-toggle <?= !empty($fanboxAles) ? 'checked' : '' ?>>
                                <span>Livrare la FANbox<?php if (($fanboxPret ?? null) !== null): ?> — <?= number_format((float) $fanboxPret, 2) ?> lei<?php endif; ?></span>
                            </label>
                            <div class="bv-checkout-v3__fanbox-pick<?= !empty($fanboxAles) ? ' is-visible' : '' ?>" data-fanbox-pick>
                                <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-fanbox">Alege punctul de ridicare *</label>
                                <select id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-fanbox" name="fan_locker_id" data-fanbox-select>
                                    <option value="">— Alege întâi județul —</option>
                                </select>
                                <p class="bv-checkout-v3__fanbox-note" data-fanbox-note></p>
                                <button type="button" class="bv-checkout-v3__fanbox-maptoggle" data-fanbox-maptoggle>Vezi pe hartă</button>
                                <div class="bv-checkout-v3__fanbox-map" data-fanbox-map hidden></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php $shippingSame = ((int) ($values['shipping_same_as_billing'] ?? 1)) === 1; ?>
                        <input type="hidden" name="has_shipping_toggle" value="1">
                        <div class="bv-checkout-v3__field bv-checkout-v3__field--full">
                            <label class="bv-checkout-v3__checkbox">
                                <input type="checkbox" name="shipping_same_as_billing" value="1" data-shipping-same <?= $shippingSame ? 'checked' : '' ?>>
                                <span>Adresa de livrare este aceeași cu adresa de facturare</span>
                            </label>
                        </div>
                        <div class="bv-checkout-v3__company-fields<?= $shippingSame ? '' : ' is-visible' ?>" data-shipping-fields>
                            <div class="bv-checkout-v3__field bv-checkout-v3__field--full">
                                <strong style="font-size:14px;color:#0f172a;">Adresă de livrare</strong>
                            </div>
                            <div class="bv-checkout-v3__field">
                                <label>Nume *</label>
                                <input type="text" name="shipping_first_name" value="<?= htmlspecialchars((string) ($values['shipping_first_name'] ?? ''), ENT_QUOTES) ?>" data-shipping-required>
                            </div>
                            <div class="bv-checkout-v3__field">
                                <label>Prenume *</label>
                                <input type="text" name="shipping_last_name" value="<?= htmlspecialchars((string) ($values['shipping_last_name'] ?? ''), ENT_QUOTES) ?>" data-shipping-required>
                            </div>
                            <div class="bv-checkout-v3__field bv-checkout-v3__field--full">
                                <label>Telefon *</label>
                                <input type="text" name="shipping_phone" value="<?= htmlspecialchars((string) ($values['shipping_phone'] ?? ''), ENT_QUOTES) ?>" data-shipping-required>
                            </div>
                            <div class="bv-checkout-v3__field">
                                <label>Stradă *</label>
                                <input type="text" name="shipping_street" value="<?= htmlspecialchars((string) ($values['shipping_street'] ?? ''), ENT_QUOTES) ?>" data-shipping-required>
                            </div>
                            <div class="bv-checkout-v3__field">
                                <label>Nr. *</label>
                                <input type="text" name="shipping_street_no" value="<?= htmlspecialchars((string) ($values['shipping_street_no'] ?? ''), ENT_QUOTES) ?>" data-shipping-required>
                            </div>
                            <div class="bv-checkout-v3__field">
                                <label>Județ *</label>
                                <?php $shipCounty = trim((string) ($values['shipping_county'] ?? '')); ?>
                                <?php if ($fanCounties !== []): ?>
                                    <select name="shipping_county" data-shipping-required>
                                        <option value="">Alege județul</option>
                                        <?php foreach ($fanCounties as $county): ?>
                                            <?php $county = trim((string) $county); if ($county === '') { continue; } ?>
                                            <option value="<?= htmlspecialchars($county, ENT_QUOTES) ?>" <?= ($shipCounty !== '' && mb_strtolower($county) === mb_strtolower($shipCounty)) ? 'selected' : '' ?>><?= htmlspecialchars($county, ENT_QUOTES) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="shipping_county" value="<?= htmlspecialchars($shipCounty, ENT_QUOTES) ?>" data-shipping-required>
                                <?php endif; ?>
                            </div>
                            <div class="bv-checkout-v3__field">
                                <label>Localitate *</label>
                                <input type="text" name="shipping_city" value="<?= htmlspecialchars((string) ($values['shipping_city'] ?? ''), ENT_QUOTES) ?>" data-shipping-required>
                            </div>
                            <div class="bv-checkout-v3__field">
                                <label>Cod poștal *</label>
                                <input type="text" name="shipping_postcode" value="<?= htmlspecialchars((string) ($values['shipping_postcode'] ?? ''), ENT_QUOTES) ?>" pattern="[0-9]{6}" maxlength="6" inputmode="numeric" data-shipping-required>
                            </div>
                        </div>
                        <div class="bv-checkout-v3__field">
                            <label for="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-notes">Observații (opțional)</label>
                            <textarea id="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-notes" name="notes" rows="3"><?= htmlspecialchars((string) ($values['notes'] ?? ''), ENT_QUOTES) ?></textarea>
                        </div>
                        <div class="bv-checkout-v3__field bv-checkout-v3__field--full">
                            <label>Metodă de plată</label>
                            <div class="bv-checkout-v3__payment">
                                <?php foreach ($cardMethods as $cardMethod): ?>
                                    <label class="bv-checkout-v3__method">
                                        <input type="radio" name="payment_method" value="<?= htmlspecialchars($cardMethod, ENT_QUOTES) ?>" <?= $paymentMethod === $cardMethod ? 'checked' : '' ?>>
                                        <span class="bv-checkout-v3__method-label">
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 10h18" stroke="currentColor" stroke-width="1.7"/></svg>
                                            <span><strong><?= htmlspecialchars($cardLabels[$cardMethod][0], ENT_QUOTES) ?></strong><br><span><?= htmlspecialchars($cardLabels[$cardMethod][1], ENT_QUOTES) ?></span></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                                <label class="bv-checkout-v3__method">
                                    <input type="radio" name="payment_method" value="cod" <?= $paymentMethod === 'cod' ? 'checked' : '' ?>>
                                    <span class="bv-checkout-v3__method-label">
                                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 7.5h18M3 12h18M3 16.5h18" stroke="currentColor" stroke-width="1.7"/><rect x="2.5" y="5" width="19" height="14" rx="2.3" stroke="currentColor" stroke-width="1.7"/></svg>
                                        <span><strong>Ramburs</strong><br><span>Plata la livrare</span></span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </form>
            </article>

            <aside class="bv-checkout-v3__card bv-checkout-v3__summary">
                <h3>Rezumat comandă</h3>
                <ul class="bv-checkout-v3__items">
                    <?php foreach ($lines as $line): ?>
                        <?php if (!is_array($line)) {
                            continue;
                        } ?>
                        <?php
                        $lineTotal = max(0.0, (float) ($line['line_total'] ?? 0.0));
                        $lineCouponDiscount = max(0.0, (float) ($line['coupon_discount'] ?? 0.0));
                        $lineTotalAfterCoupon = max(0.0, (float) ($line['line_total_after_coupon'] ?? ($lineTotal - $lineCouponDiscount)));
                        ?>
                        <li class="bv-checkout-v3__item">
                            <div>
                                <p class="bv-checkout-v3__item-name"><?= htmlspecialchars((string) ($line['name'] ?? 'Produs'), ENT_QUOTES) ?></p>
                                <p class="bv-checkout-v3__item-meta">Cantitate: <?= (int) ($line['quantity'] ?? 1) ?></p>
                            </div>
                            <div class="bv-checkout-v3__item-value-wrap">
                                <?php if ($lineCouponDiscount > 0.0): ?>
                                    <span class="bv-checkout-v3__item-value-old"><?= number_format($lineTotal, 2) ?> lei</span>
                                <?php endif; ?>
                                <strong class="bv-checkout-v3__item-value"><?= number_format($lineCouponDiscount > 0.0 ? $lineTotalAfterCoupon : $lineTotal, 2) ?> lei</strong>
                                <?php if ($lineCouponDiscount > 0.0): ?>
                                    <span class="bv-checkout-v3__item-value-discount">Reducere cupon: -<?= number_format($lineCouponDiscount, 2) ?> lei</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="bv-checkout-v3__coupon">
                    <form class="bv-checkout-v3__coupon-form" method="post" action="/cos/cupon">
                        <input type="hidden" name="redirect_to" value="/checkout">
                        <input type="text" name="coupon_code" placeholder="Cod cupon" value="<?= htmlspecialchars($couponCode, ENT_QUOTES) ?>">
                        <button class="btn" type="submit">Aplică</button>
                    </form>
                    <?php if ($couponCode !== ''): ?>
                        <p class="bv-checkout-v3__coupon-meta">
                            Cupon activ: <strong><?= htmlspecialchars($couponCode, ENT_QUOTES) ?></strong>
                            <?php if ($couponAppliesOnlySelectedProducts): ?>
                                • se aplică doar pe produsele selectate
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($pointsEnabled): ?>
                        <div class="bv-checkout-v3__points-box">
                            <?php if (!$isLoggedIn): ?>
                                <p class="bv-checkout-v3__points-login">
                                    Pentru a folosi puncte, <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES) ?>">intră în cont</a>.
                                </p>
                            <?php elseif ($pointsSliderMax > 0 && $pointsCanMeetMin): ?>
                                <form class="bv-checkout-v3__points-form" method="post" action="/cos/puncte">
                                    <input type="hidden" name="redirect_to" value="/checkout">
                                    <div class="bv-checkout-v3__points-slider-wrap">
                                        <input
                                            class="bv-checkout-v3__points-slider"
                                            type="range"
                                            min="<?= (int) $pointsSliderMin ?>"
                                            max="<?= (int) $pointsSliderMax ?>"
                                            step="1"
                                            value="<?= (int) $pointsSliderValue ?>"
                                            name="points"
                                            data-checkout-points-range
                                        >
                                        <p class="bv-checkout-v3__points-live" data-checkout-points-live><?= (int) $pointsSliderValue ?>/<?= (int) $pointsSliderMax ?> puncte</p>
                                        <p class="bv-checkout-v3__points-help">Glisează bara și apasă „Aplică”.</p>
                                    </div>
                                    <div>
                                        <button class="btn" type="submit">Aplică</button>
                                    </div>
                                </form>
                                <form method="post" action="/cos/puncte/sterge">
                                    <input type="hidden" name="redirect_to" value="/checkout">
                                    <button class="btn" type="submit">Elimină puncte</button>
                                </form>
                            <?php else: ?>
                                <p class="bv-checkout-v3__points-login">
                                    <?= htmlspecialchars((string) ($points['error'] ?? 'Nu ai puncte disponibile pentru acest coș.'), ENT_QUOTES) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="bv-checkout-v3__totals">
                    <div class="bv-checkout-v3__row"><span>Subtotal</span><strong><?= number_format($subtotalWithoutVat, 2) ?> lei</strong></div>
                    <div class="bv-checkout-v3__row"><span>TVA</span><strong data-checkout-vat data-vat-initial="<?= htmlspecialchars(number_format($vat, 2, '.', ''), ENT_QUOTES) ?>"><?= number_format($vat, 2) ?> lei</strong></div>
                    <div class="bv-checkout-v3__row"><span>Transport</span><strong data-checkout-shipping><?= number_format($shipping, 2) ?> lei</strong></div>
                    <?php if ($discount > 0): ?>
                        <div class="bv-checkout-v3__row bv-checkout-v3__row--danger"><span>Reducere</span><strong>-<?= number_format($discount, 2) ?> lei</strong></div>
                    <?php endif; ?>
                    <?php if ($pointsDiscount > 0): ?>
                        <div class="bv-checkout-v3__row bv-checkout-v3__row--danger"><span>Reducere puncte</span><strong>-<?= number_format($pointsDiscount, 2) ?> lei</strong></div>
                    <?php endif; ?>
                    <div class="bv-checkout-v3__row bv-checkout-v3__row--total"><span>Total de plată</span><strong data-checkout-total><?= number_format($total, 2) ?> lei</strong></div>
                </div>
                <button type="submit" class="bv-checkout-v3__submit" form="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-form" data-checkout-submit>
                    <?= $previewMode ? 'Preview checkout' : (in_array($paymentMethod, ['euplatesc', 'stripe'], true) ? 'Către plată' : 'Plasează comanda') ?>
                </button>
                <p class="bv-checkout-v3__shipping-error" data-checkout-shipping-error></p>
                <?php if ($cardMethods !== []): ?>
                    <p class="bv-checkout-v3__note">
                        Plată securizată prin <?= in_array('euplatesc', $cardMethods, true) ? 'EuPlătesc' : 'Stripe' ?>.
                    </p>
                <?php endif; ?>
            </aside>
        </div>
    <?php endif; ?>
</section>

<script>
(() => {
    const localitiesEndpoint = <?= json_encode($localitiesEndpoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const shippingQuoteEndpoint = <?= json_encode($shippingQuoteEndpoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const containers = document.querySelectorAll('.bv-checkout-v3[data-checkout-instance="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>"]');
    containers.forEach((container) => {
        const form = container.querySelector('form');
        if (!(form instanceof HTMLFormElement)) return;
        const couponForms = Array.from(container.querySelectorAll('form.bv-checkout-v3__coupon-form'));
        couponForms.forEach((couponForm) => {
            if (!(couponForm instanceof HTMLFormElement)) return;
            const input = couponForm.querySelector('input[name="coupon_code"]');
            couponForm.addEventListener('submit', (event) => {
                // Only block submit when the field is empty at the moment of submitting,
                // not based on its value at page load (otherwise it stays blocked forever).
                if (input instanceof HTMLInputElement && input.value.trim() === '') {
                    event.preventDefault();
                }
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

            const liveNode = slider.closest('.bv-checkout-v3__points-slider-wrap')?.querySelector('[data-checkout-points-live]');
            if (liveNode instanceof HTMLElement) {
                liveNode.textContent = `${Math.round(value)}/${Math.round(max)} puncte`;
            }
        };
        const pointsSliders = Array.from(container.querySelectorAll('[data-checkout-points-range]'));
        pointsSliders.forEach((slider) => {
            if (!(slider instanceof HTMLInputElement)) return;
            paintPointsSlider(slider);
            slider.addEventListener('input', () => paintPointsSlider(slider));
            slider.addEventListener('change', () => paintPointsSlider(slider));
        });
        window.addEventListener('resize', () => {
            pointsSliders.forEach((slider) => paintPointsSlider(slider));
        });
        const isPreview = container.getAttribute('data-preview-mode') === '1';
        if (isPreview) {
            container.querySelectorAll('form').forEach((previewForm) => {
                if (!(previewForm instanceof HTMLFormElement)) return;
                previewForm.addEventListener('submit', (event) => event.preventDefault());
            });
            const submit = container.querySelector('[data-checkout-submit]');
            if (submit instanceof HTMLButtonElement) {
                submit.addEventListener('click', (event) => event.preventDefault());
            }
            return;
        }

        const field = (name) => form.querySelector(`[name="${name}"]`);
        const payload = () => ({
            page: 'checkout',
            email: field('billing_email')?.value?.trim() || '',
            customer_name: `${field('billing_first_name')?.value?.trim() || ''} ${field('billing_last_name')?.value?.trim() || ''}`.trim(),
        });
        const send = () => {
            fetch('/api/cart/heartbeat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload()),
                keepalive: true
            }).catch(() => {});
        };
        send();
        ['billing_email', 'billing_first_name', 'billing_last_name'].forEach((name) => {
            const el = field(name);
            if (!el) return;
            el.addEventListener('change', send);
            el.addEventListener('blur', send);
        });
        const paymentInputs = Array.from(form.querySelectorAll('input[name="payment_method"]'));
        const submitButton = container.querySelector('[data-checkout-submit]');
        const companyToggle = form.querySelector('[data-billing-is-company]');
        const companyFieldsWrap = form.querySelector('[data-company-fields]');
        const companyRequiredFields = Array.from(form.querySelectorAll('[data-company-required]'));
        const updateSubmitLabel = () => {
            if (!(submitButton instanceof HTMLButtonElement)) return;
            const selected = paymentInputs.find((input) => input instanceof HTMLInputElement && input.checked);
            const method = selected instanceof HTMLInputElement ? String(selected.value || '') : '';
            submitButton.textContent = (method === 'stripe' || method === 'euplatesc') ? 'Către plată' : 'Plasează comanda';
        };
        paymentInputs.forEach((input) => {
            if (!(input instanceof HTMLInputElement)) return;
            input.addEventListener('change', updateSubmitLabel);
        });
        updateSubmitLabel();
        const syncCompanyFields = () => {
            const enabled = companyToggle instanceof HTMLInputElement && companyToggle.checked;
            if (companyFieldsWrap instanceof HTMLElement) {
                companyFieldsWrap.classList.toggle('is-visible', enabled);
            }
            companyRequiredFields.forEach((input) => {
                if (!(input instanceof HTMLInputElement)) return;
                input.required = enabled;
                if (!enabled) {
                    input.value = '';
                }
            });
        };
        if (companyToggle instanceof HTMLInputElement) {
            companyToggle.addEventListener('change', syncCompanyFields);
        }
        syncCompanyFields();

        // Adresă de livrare separată de facturare (bifă implicit = aceeași adresă)
        const shippingToggle = form.querySelector('[data-shipping-same]');
        const shippingFieldsWrap = form.querySelector('[data-shipping-fields]');
        const shippingRequiredFields = Array.from(form.querySelectorAll('[data-shipping-required]'));
        const syncShippingFields = () => {
            const different = shippingToggle instanceof HTMLInputElement && !shippingToggle.checked;
            if (shippingFieldsWrap instanceof HTMLElement) {
                shippingFieldsWrap.classList.toggle('is-visible', different);
            }
            shippingRequiredFields.forEach((input) => {
                if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement)) return;
                input.required = different;
            });
        };
        if (shippingToggle instanceof HTMLInputElement) {
            shippingToggle.addEventListener('change', syncShippingFields);
        }
        syncShippingFields();

        // ── FANbox: bifa arată selectorul, iar punctele se încarcă din județ ──
        const fanboxToggle = form.querySelector('[data-fanbox-toggle]');
        const fanboxPick = form.querySelector('[data-fanbox-pick]');
        const fanboxSelect = form.querySelector('[data-fanbox-select]');
        const fanboxNote = form.querySelector('[data-fanbox-note]');
        let fanboxJudetIncarcat = '';

        const incarcaFanbox = async (judet, localitate) => {
            if (!(fanboxSelect instanceof HTMLSelectElement)) return;
            const cheie = `${judet}|${localitate}`;
            if (cheie === fanboxJudetIncarcat) return;
            if (!judet) {
                fanboxSelect.innerHTML = '<option value="">— Alege întâi județul —</option>';
                fanboxJudetIncarcat = '';
                return;
            }
            fanboxJudetIncarcat = cheie;
            fanboxSelect.innerHTML = '<option value="">Se încarcă…</option>';
            try {
                const url = `/api/fan/lockers?county=${encodeURIComponent(judet)}&locality=${encodeURIComponent(localitate || '')}`;
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                const data = await res.json();
                const items = Array.isArray(data.items) ? data.items : [];
                if (items.length === 0) {
                    fanboxSelect.innerHTML = '<option value="">Niciun punct FANbox în acest județ</option>';
                    if (fanboxNote instanceof HTMLElement) {
                        fanboxNote.textContent = 'Alege livrarea la adresă sau un alt județ.';
                    }
                    return;
                }
                const alesAnterior = String(<?= (int) ($fanboxAles ?? 0) ?>);
                fanboxSelect.innerHTML =
                    '<option value="">— Alege punctul —</option>' +
                    items.map((p) => {
                        const sel = String(p.id) === alesAnterior ? ' selected' : '';
                        const eticheta = String(p.label || p.name || '').replace(/</g, '&lt;');
                        return `<option value="${p.id}"${sel}>${eticheta}</option>`;
                    }).join('');
                puncteCurente = items;
                if (fanboxNote instanceof HTMLElement) {
                    fanboxNote.textContent = `${items.length} puncte disponibile.`;
                }
                if (fanboxMapEl instanceof HTMLElement && !fanboxMapEl.hidden) {
                    void deseneazaHarta();
                }
            } catch {
                fanboxSelect.innerHTML = '<option value="">Nu am putut încărca punctele</option>';
                fanboxJudetIncarcat = '';
            }
        };

        // ── Harta punctelor ──────────────────────────────────────────────
        // Leaflet se încarcă abia când clientul cere harta: pentru cei care
        // livrează la adresă n-are rost să tragem o bibliotecă externă.
        const fanboxMapToggle = form.querySelector('[data-fanbox-maptoggle]');
        const fanboxMapEl = form.querySelector('[data-fanbox-map]');
        let harta = null;
        let stratMarkere = null;
        let leafletPromise = null;

        const incarcaLeaflet = () => {
            if (window.L) return Promise.resolve(window.L);
            if (leafletPromise) return leafletPromise;
            leafletPromise = new Promise((resolve, reject) => {
                const css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.appendChild(css);
                const js = document.createElement('script');
                js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                js.onload = () => resolve(window.L);
                js.onerror = () => reject(new Error('leaflet'));
                document.head.appendChild(js);
            });
            return leafletPromise;
        };

        let puncteCurente = [];

        const deseneazaHarta = async () => {
            if (!(fanboxMapEl instanceof HTMLElement)) return;
            const cuCoordonate = puncteCurente.filter(
                (p) => Number.isFinite(Number(p.lat)) && Number.isFinite(Number(p.lng)),
            );
            if (cuCoordonate.length === 0) return;
            let L;
            try {
                L = await incarcaLeaflet();
            } catch {
                fanboxMapEl.hidden = true;
                if (fanboxMapToggle instanceof HTMLElement) {
                    fanboxMapToggle.textContent = 'Harta nu s-a putut încărca';
                }
                return;
            }
            if (!harta) {
                harta = L.map(fanboxMapEl, { scrollWheelZoom: false });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '© OpenStreetMap',
                }).addTo(harta);
                stratMarkere = L.layerGroup().addTo(harta);
            }
            stratMarkere.clearLayers();
            const limite = [];
            cuCoordonate.forEach((p) => {
                const lat = Number(p.lat);
                const lng = Number(p.lng);
                limite.push([lat, lng]);
                const marker = L.marker([lat, lng]).addTo(stratMarkere);
                marker.bindPopup(
                    `<strong>${String(p.name || '')}</strong><br>${String(p.address || '')}<br>` +
                    '<em>Apasă pe marcaj pentru a alege acest punct</em>',
                );
                // Marcajul e o a doua cale de a alege: selectul rămâne sursa
                // adevărului, ca să funcționeze și fără hartă.
                marker.on('click', () => {
                    if (fanboxSelect instanceof HTMLSelectElement) {
                        fanboxSelect.value = String(p.id);
                        fanboxSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            });
            harta.fitBounds(limite, { padding: [24, 24], maxZoom: 14 });
            // Containerul a fost ascuns până acum: fără asta, harta rămâne gri.
            window.setTimeout(() => harta.invalidateSize(), 60);
        };

        if (fanboxMapToggle instanceof HTMLElement && fanboxMapEl instanceof HTMLElement) {
            fanboxMapToggle.addEventListener('click', () => {
                const deschis = !fanboxMapEl.hidden;
                fanboxMapEl.hidden = deschis;
                fanboxMapToggle.textContent = deschis ? 'Vezi pe hartă' : 'Ascunde harta';
                if (!deschis) void deseneazaHarta();
            });
        }

        const sincronizeazaFanbox = () => {
            const activ = fanboxToggle instanceof HTMLInputElement && fanboxToggle.checked;
            if (fanboxPick instanceof HTMLElement) {
                fanboxPick.classList.toggle('is-visible', activ);
            }
            if (fanboxSelect instanceof HTMLSelectElement) {
                // Punctul e obligatoriu doar când clientul a ales FANbox.
                fanboxSelect.required = activ;
                if (!activ) fanboxSelect.value = '';
            }
            if (activ) {
                const j = form.querySelector('[data-fan-county-select]');
                const l = form.querySelector('[data-fan-locality-input]');
                void incarcaFanbox(
                    j instanceof HTMLSelectElement ? j.value : '',
                    l instanceof HTMLInputElement ? l.value : '',
                );
            }
        };
        if (fanboxSelect instanceof HTMLSelectElement) {
            fanboxSelect.addEventListener('change', () => void requestShippingQuote());
        }
        if (fanboxToggle instanceof HTMLInputElement) {
            fanboxToggle.addEventListener('change', () => {
                sincronizeazaFanbox();
                // Prețul transportului depinde de alegere, deci recalculăm.
                void requestShippingQuote();
            });
        }

        const countyInput = form.querySelector('[data-fan-county-select]');
        const localityInput = form.querySelector('[data-fan-locality-input]');
        const localityList = form.querySelector('[data-fan-locality-list]');
        const streetInput = field('billing_street');
        const streetNoInput = field('billing_street_no');
        const postcodeInput = field('billing_postcode');
        const shippingValueEl = container.querySelector('[data-checkout-shipping]');
        const vatValueEl = container.querySelector('[data-checkout-vat]');
        const totalValueEl = container.querySelector('[data-checkout-total]');
        const shippingErrorEl = container.querySelector('[data-checkout-shipping-error]');
        const initialShippingText = shippingValueEl instanceof HTMLElement ? shippingValueEl.textContent : '';
        const initialTotalText = totalValueEl instanceof HTMLElement ? totalValueEl.textContent : '';
        const parseMoney = (value) => {
            // Totalurile sunt formatate „3,900.00 lei" — virgula e separator de
            // mii, nu zecimal. Ultimul dintre „." și „," e cel zecimal.
            let normalized = String(value || '')
                .replace(/lei/gi, '')
                .replace(/[^\d.,-]/g, '')
                .trim();
            const lastComma = normalized.lastIndexOf(',');
            const lastDot = normalized.lastIndexOf('.');
            if (lastComma > lastDot) {
                normalized = normalized.replace(/\./g, '').replace(',', '.');
            } else {
                normalized = normalized.replace(/,/g, '');
            }
            const amount = Number(normalized);
            return Number.isFinite(amount) ? amount : 0;
        };
        if (vatValueEl instanceof HTMLElement) {
            vatValueEl.setAttribute('data-vat-initial', String(parseMoney(vatValueEl.textContent)));
        }
        const formatMoney = (value) => `${Number(value || 0).toFixed(2)} lei`;
        const shippingPendingText = 'Completează toate câmpurile obligatorii pentru a calcula transportul.';
        const initialBaseTotal = Math.max(0, parseMoney(initialTotalText) - parseMoney(initialShippingText));
        const simpleValue = (input) => {
            if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement || input instanceof HTMLTextAreaElement)) return '';
            return String(input.value || '').trim();
        };
        const countyValue = () => {
            if (!(countyInput instanceof HTMLInputElement || countyInput instanceof HTMLSelectElement)) return '';
            return String(countyInput.value || '').trim();
        };
        const localityValue = () => {
            if (!(localityInput instanceof HTMLInputElement)) return '';
            return String(localityInput.value || '').trim();
        };
        const hasCompleteShippingAddress = () => {
            const required = [
                countyValue(),
                localityValue(),
                simpleValue(streetInput),
                simpleValue(streetNoInput),
                simpleValue(postcodeInput),
            ];
            return required.every((value) => value !== '');
        };
        const setListOpen = (open) => {
            if (!(localityList instanceof HTMLElement)) return;
            localityList.classList.toggle('is-open', !!open);
        };
        const countySupportsOptions = countyInput instanceof HTMLSelectElement;
        let quoteRequestId = 0;
        const updateShippingUi = (shippingAmount, totalAmount) => {
            if (shippingValueEl instanceof HTMLElement) {
                shippingValueEl.classList.remove('bv-checkout-v3__shipping-pending');
                shippingValueEl.textContent = formatMoney(shippingAmount);
            }
            if (vatValueEl instanceof HTMLElement) {
                const initialVat = Number(vatValueEl.getAttribute('data-vat-initial') || '0');
                vatValueEl.textContent = formatMoney(initialVat);
            }
            if (totalValueEl instanceof HTMLElement) {
                totalValueEl.textContent = formatMoney(totalAmount);
            }
        };
        const setShippingPendingUi = () => {
            if (shippingValueEl instanceof HTMLElement) {
                shippingValueEl.classList.add('bv-checkout-v3__shipping-pending');
                shippingValueEl.textContent = shippingPendingText;
            }
            if (totalValueEl instanceof HTMLElement) {
                totalValueEl.textContent = formatMoney(initialBaseTotal);
            }
        };
        const clearShippingUi = () => updateShippingUi(0, initialBaseTotal);
        const setShippingError = (message) => {
            if (!(shippingErrorEl instanceof HTMLElement)) return;
            const text = String(message || '').trim();
            if (text === '') {
                shippingErrorEl.textContent = '';
                shippingErrorEl.classList.remove('is-visible');
                return;
            }
            shippingErrorEl.textContent = text;
            shippingErrorEl.classList.add('is-visible');
        };
        const requestShippingQuote = async () => {
            if (!(localityInput instanceof HTMLInputElement)) return;
            if (typeof shippingQuoteEndpoint !== 'string' || shippingQuoteEndpoint.trim() === '') return;
            if (!hasCompleteShippingAddress()) {
                setShippingPendingUi();
                setShippingError('');
                return;
            }
            const county = countyValue();
            const locality = localityValue();
            const requestId = ++quoteRequestId;
            try {
                const response = await fetch(shippingQuoteEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json'
                    },
                    body: JSON.stringify({
                        billing_county: county,
                        billing_city: locality,
                        billing_street: simpleValue(streetInput),
                        billing_street_no: simpleValue(streetNoInput),
                        billing_postcode: simpleValue(postcodeInput),
                        // Prețul transportului depinde de FANbox vs. adresă.
                        // Bifa se trimite separat de punctul ales: tariful de
                        // FANbox se aplică din clipa în care e aleasă livrarea
                        // acolo, nu abia după ce se alege lockerul.
                        livrare_fanbox: fanboxToggle instanceof HTMLInputElement && fanboxToggle.checked ? 1 : 0,
                        fan_locker_id: fanboxToggle instanceof HTMLInputElement && fanboxToggle.checked
                            && fanboxSelect instanceof HTMLSelectElement
                            ? fanboxSelect.value
                            : '',
                    })
                });
                const payload = await response.json().catch(() => ({}));
                if (requestId !== quoteRequestId) return;
                if (!payload || payload.ok !== true) {
                    setShippingError(String(payload?.error || 'Nu am putut calcula transportul FAN pentru această adresă.'));
                    clearShippingUi();
                    return;
                }
                setShippingError('');
                const shippingAmount = Number(payload.shipping || 0);
                const totalAmount = Number.isFinite(Number(payload.total))
                    ? Number(payload.total)
                    : Math.max(0, initialBaseTotal + shippingAmount);
                updateShippingUi(shippingAmount, totalAmount);
            } catch {
                setShippingError('Nu am putut calcula transportul FAN momentan. Reîncearcă.');
                clearShippingUi();
            }
        };
        const renderLocalityItems = (items) => {
            if (!(localityList instanceof HTMLElement)) return;
            localityList.innerHTML = '';
            if (!Array.isArray(items) || items.length === 0) {
                localityList.innerHTML = '<div class="bv-checkout-v3__locality-empty">Nu am găsit localități FAN.</div>';
                setListOpen(true);
                return;
            }
            items.forEach((item) => {
                const locality = String(item?.locality || '').trim();
                const county = String(item?.county || '').trim();
                if (!locality) return;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'bv-checkout-v3__locality-item';
                btn.setAttribute('role', 'option');
                btn.textContent = county ? `${locality} (${county})` : locality;
                btn.addEventListener('click', () => {
                    if (localityInput instanceof HTMLInputElement) {
                        localityInput.value = locality;
                    }
                    if ((countyInput instanceof HTMLInputElement || countyInput instanceof HTMLSelectElement) && county) {
                        if (countySupportsOptions) {
                            const select = countyInput;
                            const hasOption = Array.from(select.options).some((opt) => String(opt.value || '') === county);
                            if (!hasOption) {
                                return;
                            }
                        }
                        countyInput.value = county;
                    }
                    setListOpen(false);
                    void requestShippingQuote();
                });
                localityList.appendChild(btn);
            });
            setListOpen(localityList.children.length > 0);
        };
        const fetchLocalities = async (q) => {
            if (!(localityInput instanceof HTMLInputElement)) return;
            if (typeof localitiesEndpoint !== 'string' || localitiesEndpoint.trim() === '') return;
            const county = countyValue();
            if (!county) {
                renderLocalityItems([]);
                return;
            }
            const params = new URLSearchParams();
            params.set('county', county);
            params.set('q', String(q || '').trim());
            params.set('limit', '12');
            try {
                const response = await fetch(`${localitiesEndpoint}?${params.toString()}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    throw new Error('request_failed');
                }
                const payload = await response.json();
                renderLocalityItems(Array.isArray(payload?.items) ? payload.items : []);
            } catch {
                renderLocalityItems([]);
            }
        };
        let localityDebounce = 0;
        if (localityInput instanceof HTMLInputElement) {
            localityInput.addEventListener('focus', () => {
                void fetchLocalities(localityInput.value || '');
            });
            localityInput.addEventListener('input', () => {
                window.clearTimeout(localityDebounce);
                localityDebounce = window.setTimeout(() => {
                    void fetchLocalities(localityInput.value || '');
                }, 170);
            });
            localityInput.addEventListener('blur', () => {
                void requestShippingQuote();
            });
        }
        sincronizeazaFanbox();
        if (countyInput instanceof HTMLInputElement || countyInput instanceof HTMLSelectElement) {
            countyInput.addEventListener('change', () => {
                if (localityInput instanceof HTMLInputElement) {
                    localityInput.value = '';
                    setListOpen(false);
                }
                // Punctele FANbox sunt pe județ: la schimbarea lui, lista veche
                // n-ar mai fi valabilă.
                fanboxJudetIncarcat = '';
                sincronizeazaFanbox();
                void requestShippingQuote();
            });
        }
        [streetInput, streetNoInput, postcodeInput].forEach((input) => {
            if (!(input instanceof HTMLInputElement)) return;
            input.addEventListener('change', () => {
                void requestShippingQuote();
            });
            input.addEventListener('blur', () => {
                void requestShippingQuote();
            });
        });
        document.addEventListener('click', (event) => {
            if (!(event.target instanceof Node)) return;
            if (!container.contains(event.target)) {
                setListOpen(false);
            }
        });

        void requestShippingQuote();

        window.addEventListener('beforeunload', () => {
            const data = JSON.stringify(payload());
            if (navigator.sendBeacon) {
                const blob = new Blob([data], { type: 'application/json' });
                navigator.sendBeacon('/api/cart/heartbeat', blob);
                return;
            }
            send();
        });

    });
})();
</script>
