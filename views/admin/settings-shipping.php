<?php
$shippingTab = trim((string) ($shippingTab ?? 'fan-localities'));
if ($shippingTab === 'store-rules') {
    $shippingTab = 'delivery-settings';
}
if (!in_array($shippingTab, ['fan-localities', 'fan-streets', 'fan-extra-km', 'fan-api', 'delivery-settings'], true)) {
    $shippingTab = 'fan-localities';
}
?>

<section class="panel">
    <h1>Setări livrare (FAN)</h1>
    <p>Configurare integrare FAN Courier API real, import localități FAN și parametri colete.</p>

    <style>
        .shipping-settings-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0;
        }
        .shipping-settings-tabs .btn.is-active {
            background: #0f766e;
            border-color: #0f766e;
            color: #ffffff;
        }
        .shipping-settings-panel[hidden] {
            display: none !important;
        }
        form.shipping-settings-panel[hidden] {
            display: none !important;
        }
        .shipping-settings-group {
            display: contents;
        }
        .shipping-settings-group[hidden] {
            display: none !important;
        }
    </style>

    <div class="shipping-settings-tabs" data-shipping-settings-tabs data-default-tab="<?= htmlspecialchars($shippingTab, ENT_QUOTES) ?>">
        <button class="btn btn-secondary <?= $shippingTab === 'fan-localities' ? 'is-active' : '' ?>" type="button" data-shipping-settings-tab="fan-localities">Import localități FAN (Excel/CSV)</button>
        <button class="btn btn-secondary <?= $shippingTab === 'fan-streets' ? 'is-active' : '' ?>" type="button" data-shipping-settings-tab="fan-streets">Lista străzi (Excel/CSV)</button>
        <button class="btn btn-secondary <?= $shippingTab === 'fan-extra-km' ? 'is-active' : '' ?>" type="button" data-shipping-settings-tab="fan-extra-km">Lista localități cu km suplimentari (Excel/CSV)</button>
        <button class="btn btn-secondary <?= $shippingTab === 'fan-api' ? 'is-active' : '' ?>" type="button" data-shipping-settings-tab="fan-api">Autentificare FAN API</button>
        <button class="btn btn-secondary <?= $shippingTab === 'delivery-settings' ? 'is-active' : '' ?>" type="button" data-shipping-settings-tab="delivery-settings">Setări de livrare</button>
    </div>

    <article class="panel shipping-settings-panel" data-shipping-settings-panel="fan-localities" <?= $shippingTab !== 'fan-localities' ? 'hidden' : '' ?> style="margin:12px 0 16px;background:#f8fafc;border-color:#cbd5e1;">
        <h3 style="margin:0 0 8px;">Import localități FAN (Excel/CSV)</h3>
        <p style="margin:0 0 10px;color:#64748b;">
            Încarcă fișierul descărcat din selfAWB cu coloanele <strong>Localitate</strong> și <strong>Judet</strong>.
            Aceste date vor fi folosite în checkout pentru dropdown cu search.
        </p>
        <form method="post" action="/admin/settings/shipping/localities/import" enctype="multipart/form-data" style="display:grid;gap:8px;max-width:520px;">
            <input type="file" name="fan_localities_file" accept=".csv,.xlsx" required>
            <button class="btn" type="submit">Importă localități FAN</button>
        </form>
        <p style="margin:8px 0 0;color:#64748b;font-size:13px;">
            Total localități importate: <strong><?= (int) ($fanLocalitiesCount ?? 0) ?></strong>
        </p>
    </article>

    <article class="panel shipping-settings-panel" data-shipping-settings-panel="fan-streets" <?= $shippingTab !== 'fan-streets' ? 'hidden' : '' ?> style="margin:12px 0 16px;background:#f8fafc;border-color:#cbd5e1;">
        <h3 style="margin:0 0 8px;">Lista străzi (Excel/CSV)</h3>
        <p style="margin:0 0 10px;color:#64748b;">
            Încarcă fișierul de străzi FAN (ex: tab-ul „Streets Bucuresti”).
            Este folosit pentru validări suplimentare FAN.
        </p>
        <form method="post" action="/admin/settings/shipping/streets/import" enctype="multipart/form-data" style="display:grid;gap:8px;max-width:520px;">
            <input type="file" name="fan_streets_file" accept=".csv,.xlsx" required>
            <button class="btn" type="submit">Importă lista străzi</button>
        </form>
        <p style="margin:8px 0 0;color:#64748b;font-size:13px;">
            Total străzi importate: <strong><?= (int) ($fanStreetsCount ?? 0) ?></strong>
        </p>
    </article>

    <article class="panel shipping-settings-panel" data-shipping-settings-panel="fan-extra-km" <?= $shippingTab !== 'fan-extra-km' ? 'hidden' : '' ?> style="margin:12px 0 16px;background:#f8fafc;border-color:#cbd5e1;">
        <h3 style="margin:0 0 8px;">Lista localități cu km suplimentari (Excel/CSV)</h3>
        <p style="margin:0 0 10px;color:#64748b;">
            Încarcă fișierul cu localități care au kilometri suplimentari.
        </p>
        <form method="post" action="/admin/settings/shipping/extra-km/import" enctype="multipart/form-data" style="display:grid;gap:8px;max-width:520px;">
            <input type="file" name="fan_localities_km_file" accept=".csv,.xlsx" required>
            <button class="btn" type="submit">Importă localități cu km suplimentari</button>
        </form>
        <p style="margin:8px 0 0;color:#64748b;font-size:13px;">
            Total localități km suplimentari: <strong><?= (int) ($fanExtraKmCount ?? 0) ?></strong>
        </p>
    </article>

    <article class="panel shipping-settings-panel" data-shipping-settings-panel="fan-api" <?= $shippingTab !== 'fan-api' ? 'hidden' : '' ?> style="margin:12px 0 16px;background:#f8fafc;border-color:#cbd5e1;">
        <h3 style="margin:0 0 8px;">Autentificare FAN API</h3>
        <p style="margin:0 0 10px;color:#64748b;">
            Configurările complete au fost grupate în tab-ul <strong>Setări de livrare</strong> pentru a evita afișarea câmpurilor în celelalte secțiuni.
        </p>
        <button class="btn btn-secondary" type="button" data-shipping-go-tab="delivery-settings">Deschide Setări de livrare</button>
    </article>

    <form method="post" action="/admin/settings/shipping" class="form-grid shipping-settings-panel" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
        <input type="hidden" name="tab" id="shipping-settings-tab-input" value="<?= htmlspecialchars($shippingTab, ENT_QUOTES) ?>">
        <article class="panel shipping-settings-panel" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?> style="grid-column:1/-1;margin:0 0 12px;background:#f8fafc;border-color:#cbd5e1;">
            <h3 style="margin:0;">Setări de livrare</h3>
            <p style="margin:6px 0 0;color:#64748b;">Toate câmpurile de configurare FAN sunt centralizate aici.</p>
        </article>
        <div class="shipping-settings-group" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
        <div style="grid-column:1/-1;">
            <h3 style="margin:0 0 8px;">Reguli interne magazin</h3>
        </div>
        <div class="field">
            <label>Prag gratuit București</label>
            <input type="number" step="0.01" name="shipping_free_bucharest"
                   value="<?= htmlspecialchars((string) $settings['shipping_free_bucharest'], ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Prag gratuit provincie</label>
            <input type="number" step="0.01" name="shipping_free_province"
                   value="<?= htmlspecialchars((string) $settings['shipping_free_province'], ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Tip serviciu FAN</label>
            <?php
            $fanServiceType = trim((string) ($settings['fan_service_type'] ?? 'Standard'));
            $fanServiceOptions = [
                'Standard',
                'Cont Colector',
                'RedCode',
                'Red code-Cont Colector',
                'Express Loco 1H',
                'Express Loco 1H-Cont Colector',
                'Express Loco 2H',
                'Express Loco 2H-Cont Colector',
                'Express Loco 4H',
                'Express Loco 4H-Cont Colector',
                'Express Loco 6H',
                'Express Loco 6H-Cont Colector',
                'CollectPoint',
                'CollectPoint Cont Colector',
                'FANbox',
                'FANbox Cont Colector',
                'Produse Albe',
                'Produse Albe-Cont Colector',
                'Transport Marfa',
                'Transport Marfa-Cont Colector',
                'Transport Marfa Produse Albe',
                'Transport Marfa Produse Albe-Cont Colector',
            ];
            if ($fanServiceType !== '' && !in_array($fanServiceType, $fanServiceOptions, true)) {
                array_unshift($fanServiceOptions, $fanServiceType);
            }
            ?>
            <select name="fan_service_type">
                <?php foreach ($fanServiceOptions as $service): ?>
                    <option value="<?= htmlspecialchars($service, ENT_QUOTES) ?>" <?= $service === $fanServiceType ? 'selected' : '' ?>>
                        <?= htmlspecialchars($service, ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Plata transport (cotație FAN)</label>
            <?php $fanShippingPayer = trim((string) ($settings['fan_shipping_payer'] ?? 'recipient')); ?>
            <select name="fan_shipping_payer">
                <option value="recipient" <?= $fanShippingPayer === 'recipient' ? 'selected' : '' ?>>Destinatar</option>
                <option value="sender" <?= $fanShippingPayer === 'sender' ? 'selected' : '' ?>>Expeditor</option>
            </select>
        </div>
        <div class="field">
            <label>Plată ramburs</label>
            <?php $fanCodPayer = trim((string) ($settings['fan_cod_payer'] ?? 'sender')); ?>
            <select name="fan_cod_payer">
                <option value="sender" <?= $fanCodPayer === 'sender' ? 'selected' : '' ?>>Expeditor</option>
                <option value="recipient" <?= $fanCodPayer === 'recipient' ? 'selected' : '' ?>>Destinatar</option>
            </select>
        </div>
        <div class="field">
            <label>Tip expediere pentru cotație</label>
            <?php $fanShipmentType = trim((string) ($settings['fan_shipment_type'] ?? 'parcel')); ?>
            <select name="fan_shipment_type">
                <option value="parcel" <?= $fanShipmentType === 'parcel' ? 'selected' : '' ?>>Colet</option>
                <option value="envelope" <?= $fanShipmentType === 'envelope' ? 'selected' : '' ?>>Plic</option>
            </select>
        </div>
        <div class="field">
            <label>Număr colete</label>
            <input type="number" step="1" min="0" max="999" name="fan_parcel_count"
                   value="<?= htmlspecialchars((string) ($settings['fan_parcel_count'] ?? '1'), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Număr plicuri</label>
            <input type="number" step="1" min="0" max="999" name="fan_envelope_count"
                   value="<?= htmlspecialchars((string) ($settings['fan_envelope_count'] ?? '0'), ENT_QUOTES) ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Opțiuni FAN (selectare multiplă)</label>
            <?php
            $fanOptionCodesRaw = trim((string) ($settings['fan_option_codes'] ?? ''));
            $fanOptionCodesSelected = array_values(array_filter(array_map('trim', explode(',', mb_strtoupper($fanOptionCodesRaw)))));
            $fanOptionChoices = [
                'A' => 'A - Deschidere la livrare',
                'B' => 'B - oPOD',
                'C' => 'C - Depunere sediu FAN',
                'D' => 'D - Livrare sediu FAN',
                'E' => 'E - DropOff PayPoint',
                'F' => 'F - Livrare magazin PayPoint',
                'M' => 'M - SMS Business',
                'O' => 'O - Pick-up Prealert',
                'P' => 'P - Prealert',
                'S' => 'S - Livrare sâmbătă',
                'V' => 'V - PickUp FANbox',
                'W' => 'W - DropOff FANbox',
                'X' => 'X - ePOD',
                'Y' => 'Y - mPOS',
            ];
            ?>
            <select name="fan_option_codes[]" multiple size="8">
                <?php foreach ($fanOptionChoices as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>" <?= in_array($code, $fanOptionCodesSelected, true) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label, ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="color:#64748b;">Ține apăsat Ctrl/Cmd pentru selecție multiplă.</small>
        </div>
        <div class="field">
            <label>Valoare declarată (impactează costul de asigurare)</label>
            <?php $fanDeclaredValueMode = trim((string) ($settings['fan_declared_value_mode'] ?? 'order_total')); ?>
            <select name="fan_declared_value_mode">
                <option value="order_total" <?= $fanDeclaredValueMode === 'order_total' ? 'selected' : '' ?>>Total comandă</option>
                <option value="zero" <?= $fanDeclaredValueMode === 'zero' ? 'selected' : '' ?>>0 (fără asigurare suplimentară)</option>
                <option value="none" <?= $fanDeclaredValueMode === 'none' ? 'selected' : '' ?>>Nu trimite câmpul</option>
            </select>
        </div>
        <div class="field">
            <label>Greutate fallback (kg)</label>
            <input type="number" step="0.01" min="0.1" name="fan_default_weight_kg"
                   value="<?= htmlspecialchars((string) $settings['fan_default_weight_kg'], ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Lungime colet (cm)</label>
            <input type="number" step="0.1" min="1" name="fan_parcel_length_cm"
                   value="<?= htmlspecialchars((string) $settings['fan_parcel_length_cm'], ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Lățime colet (cm)</label>
            <input type="number" step="0.1" min="1" name="fan_parcel_width_cm"
                   value="<?= htmlspecialchars((string) $settings['fan_parcel_width_cm'], ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Înălțime colet (cm)</label>
            <input type="number" step="0.1" min="1" name="fan_parcel_height_cm"
                   value="<?= htmlspecialchars((string) $settings['fan_parcel_height_cm'], ENT_QUOTES) ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Punct de ridicare FAN (opțional)</label>
            <input type="text" name="fan_pickup_point"
                   value="<?= htmlspecialchars((string) $settings['fan_pickup_point'], ENT_QUOTES) ?>"
                   placeholder="Doar dacă folosești ridicare din punct fix">
        </div>

        <div class="field">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="shipping_include_coupons" value="1"
                    <?= ((string) $settings['shipping_include_coupons']) === '1' ? 'checked' : '' ?>>
                Include cupoanele în pragul de transport gratuit
            </label>
        </div>
        <div class="field">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="fan_live_tariff_enabled" value="1"
                    <?= ((string) ($settings['fan_live_tariff_enabled'] ?? '0')) === '1' ? 'checked' : '' ?>>
                Folosește tarif FAN live la plasarea comenzii
            </label>
        </div>
        <div class="field">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="fan_awb_auto" value="1"
                    <?= ((string) $settings['fan_awb_auto']) === '1' ? 'checked' : '' ?>>
                Generare AWB automată (în lucru, acum folosește buton manual din Comenzi)
            </label>
        </div>
        </div>

        <article class="panel shipping-settings-panel" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?> style="grid-column:1/-1;margin:12px 0 0;background:#f8fafc;border-color:#cbd5e1;">
            <h3 style="margin:0;">Autentificare FAN API</h3>
            <p style="margin:6px 0 0;color:#64748b;">Completează credentialele FAN și datele expeditorului pentru AWB.</p>
        </article>
        <div style="grid-column:1/-1;margin-top:6px;" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <h3 style="margin:0 0 8px;">Autentificare FAN API</h3>
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Client ID FAN *</label>
            <input type="number" step="1" min="1" name="fan_client_id"
                   value="<?= htmlspecialchars((string) ($settings['fan_client_id'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Username FAN API *</label>
            <input type="text" name="fan_api_username"
                   value="<?= htmlspecialchars((string) ($settings['fan_api_username'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Parolă FAN API *</label>
            <input type="password" name="fan_api_password"
                   value="<?= htmlspecialchars((string) ($settings['fan_api_password'] ?? ''), ENT_QUOTES) ?>">
        </div>

        <div style="grid-column:1/-1;margin-top:6px;" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <h3 style="margin:0 0 8px;">Date expeditor (opțional, recomandat pentru AWB)</h3>
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Nume expeditor</label>
            <input type="text" name="fan_sender_name"
                   value="<?= htmlspecialchars((string) ($settings['fan_sender_name'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Telefon expeditor</label>
            <input type="text" name="fan_sender_phone"
                   value="<?= htmlspecialchars((string) ($settings['fan_sender_phone'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Email expeditor</label>
            <input type="email" name="fan_sender_email"
                   value="<?= htmlspecialchars((string) ($settings['fan_sender_email'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Județ expeditor</label>
            <input type="text" name="fan_sender_county"
                   value="<?= htmlspecialchars((string) ($settings['fan_sender_county'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Localitate expeditor</label>
            <input type="text" name="fan_sender_locality"
                   value="<?= htmlspecialchars((string) ($settings['fan_sender_locality'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Stradă expeditor</label>
            <input type="text" name="fan_sender_street"
                   value="<?= htmlspecialchars((string) ($settings['fan_sender_street'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Nr expeditor</label>
            <input type="text" name="fan_sender_street_no"
                   value="<?= htmlspecialchars((string) ($settings['fan_sender_street_no'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field" data-shipping-settings-panel="delivery-settings" <?= $shippingTab !== 'delivery-settings' ? 'hidden' : '' ?>>
            <label>Cod poștal expeditor</label>
            <input type="text" name="fan_sender_zip_code"
                   value="<?= htmlspecialchars((string) ($settings['fan_sender_zip_code'] ?? ''), ENT_QUOTES) ?>">
        </div>

        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Salvează setările de livrare</button>
        </div>
    </form>
</section>

<script>
(() => {
    const wrap = document.querySelector('[data-shipping-settings-tabs]');
    if (!(wrap instanceof HTMLElement)) {
        return;
    }
    const tabs = Array.from(wrap.querySelectorAll('[data-shipping-settings-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-shipping-settings-panel]'));
    if (tabs.length === 0 || panels.length === 0) {
        return;
    }

    const activate = (key) => {
        tabs.forEach((tab) => {
            if (!(tab instanceof HTMLElement)) {
                return;
            }
            tab.classList.toggle('is-active', tab.getAttribute('data-shipping-settings-tab') === key);
        });
        panels.forEach((panel) => {
            if (!(panel instanceof HTMLElement)) {
                return;
            }
            panel.hidden = panel.getAttribute('data-shipping-settings-panel') !== key;
        });
        if (window.history && typeof window.history.replaceState === 'function') {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', key);
            window.history.replaceState(null, '', url.pathname + '?' + url.searchParams.toString());
        }
        const tabInput = document.getElementById('shipping-settings-tab-input');
        if (tabInput instanceof HTMLInputElement) {
            tabInput.value = key;
        }
    };

    const hash = String(window.location.hash || '').replace('#', '').trim();
    if (tabs.some((tab) => tab.getAttribute('data-shipping-settings-tab') === hash)) {
        activate(hash);
    } else {
        const defaultTab = String(wrap.getAttribute('data-default-tab') || 'fan-localities').trim();
        activate(defaultTab);
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const key = String(tab.getAttribute('data-shipping-settings-tab') || 'fan-localities').trim();
            activate(key);
        });
    });
    document.querySelectorAll('[data-shipping-go-tab]').forEach((button) => {
        button.addEventListener('click', () => {
            const key = String(button.getAttribute('data-shipping-go-tab') || '').trim();
            if (key !== '') {
                activate(key);
            }
        });
    });
})();
</script>
