<?php
$verification = (string) ($settings['google_site_verification'] ?? '');
$gaEnabled = (string) ($settings['google_analytics_enabled'] ?? '0') === '1';
$gaId = (string) ($settings['google_analytics_id'] ?? '');
$gtmEnabled = (string) ($settings['google_tag_manager_enabled'] ?? '0') === '1';
$gtmId = (string) ($settings['google_tag_manager_id'] ?? '');
$gtmHeadCode = (string) ($settings['google_tag_manager_head_code'] ?? '');
$gtmBodyCode = (string) ($settings['google_tag_manager_body_code'] ?? '');
$gaCode = (string) ($settings['google_analytics_code'] ?? '');
$adsEnabled = (string) ($settings['google_ads_enabled'] ?? '0') === '1';
$adsId = (string) ($settings['google_ads_conversion_id'] ?? '');
$adsLabel = (string) ($settings['google_ads_conversion_label'] ?? '');
?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1>Google</h1>
            <p>Verificare proprietate, Google Analytics (GA4), Tag Manager și Google Ads. Toate într-un singur loc.</p>
        </div>
    </div>

    <form method="post" action="/admin/settings/google" class="form-grid" style="gap:24px;" id="google-settings-form">
        <input type="hidden" name="code_fields_b64" value="1">

        <!-- Verificare proprietate -->
        <div class="settings-card" style="grid-column:1/-1;border:1px solid #e5e7eb;border-radius:14px;padding:18px;background:#fff;">
            <h2 style="margin:0 0 4px;font-size:17px;">Verificare proprietate</h2>
            <p class="muted" style="margin:0 0 14px;font-size:13px;">
                Pentru Google Search Console și Merchant Center. Lipește aici meta tag-ul întreg
                (<code>&lt;meta name="google-site-verification" content="..."&gt;</code>) sau doar codul — îl curățăm automat.
                Tag-ul va apărea în <code>&lt;head&gt;</code>-ul tuturor paginilor.
            </p>
            <div class="field" style="margin:0;">
                <label>Cod / meta tag de verificare</label>
                <input type="text" name="google_site_verification"
                       placeholder='<meta name="google-site-verification" content="..." /> sau doar codul'
                       value="<?= htmlspecialchars($verification, ENT_QUOTES) ?>">
                <?php if ($verification !== ''): ?>
                    <small class="muted" style="display:block;margin-top:6px;color:#16a34a;">
                        ✓ Activ — token salvat: <code><?= htmlspecialchars($verification, ENT_QUOTES) ?></code>
                    </small>
                <?php endif; ?>
            </div>
        </div>

        <!-- Google Analytics 4 -->
        <div class="settings-card" style="grid-column:1/-1;border:1px solid #e5e7eb;border-radius:14px;padding:18px;background:#fff;">
            <label style="display:flex;align-items:center;gap:10px;margin:0 0 4px;font-weight:600;font-size:17px;cursor:pointer;">
                <input type="checkbox" name="google_analytics_enabled" <?= $gaEnabled ? 'checked' : '' ?>>
                Google Analytics 4
            </label>
            <p class="muted" style="margin:0 0 14px;font-size:13px;">
                Cea mai simplă variantă: lipește în câmpul de mai jos <strong>codul complet „Google tag (gtag.js)”</strong>
                exact cum ți-l dă Google (cu tot cu <code>&lt;script&gt;</code>). Dacă preferi, poți completa doar ID-ul
                <code>G-XXXXXXXXXX</code> și generăm noi codul. Codul lipit are prioritate.
            </p>
            <div class="field" style="margin:0 0 12px;">
                <label>Cod complet Google tag (gtag.js)</label>
                <textarea name="google_analytics_code" rows="7" spellcheck="false"
                          style="font-family:monospace;font-size:12px;"
                          placeholder="<!-- Google tag (gtag.js) -->&#10;<script async src=&quot;https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX&quot;></script>&#10;<script>...</script>"><?= htmlspecialchars($gaCode, ENT_QUOTES) ?></textarea>
            </div>
            <div class="field" style="margin:0;max-width:360px;">
                <label>sau doar ID măsurare (G-…)</label>
                <input type="text" name="google_analytics_id" placeholder="G-XXXXXXXXXX"
                       value="<?= htmlspecialchars($gaId, ENT_QUOTES) ?>">
            </div>
        </div>

        <!-- Google Tag Manager -->
        <div class="settings-card" style="grid-column:1/-1;border:1px solid #e5e7eb;border-radius:14px;padding:18px;background:#fff;">
            <label style="display:flex;align-items:center;gap:10px;margin:0 0 4px;font-weight:600;font-size:17px;cursor:pointer;">
                <input type="checkbox" name="google_tag_manager_enabled" <?= $gtmEnabled ? 'checked' : '' ?>>
                Google Tag Manager
            </label>
            <p class="muted" style="margin:0 0 14px;font-size:13px;">
                Lipește cele două coduri exact cum ți le dă Google: primul în <code>&lt;head&gt;</code>, al doilea
                imediat după <code>&lt;body&gt;</code> (varianta <code>&lt;noscript&gt;</code>). Codul lipit are prioritate;
                dacă lași câmpurile goale și completezi doar Container ID, generăm noi ambele coduri automat.
            </p>
            <div class="field" style="margin:0 0 12px;">
                <label>1. Cod pentru <code>&lt;head&gt;</code></label>
                <textarea name="google_tag_manager_head_code" rows="7" spellcheck="false"
                          style="font-family:monospace;font-size:12px;"
                          placeholder="<!-- Google Tag Manager -->&#10;<script>...</script>&#10;<!-- End Google Tag Manager -->"><?= htmlspecialchars($gtmHeadCode, ENT_QUOTES) ?></textarea>
            </div>
            <div class="field" style="margin:0 0 12px;">
                <label>2. Cod imediat după <code>&lt;body&gt;</code> (noscript)</label>
                <textarea name="google_tag_manager_body_code" rows="4" spellcheck="false"
                          style="font-family:monospace;font-size:12px;"
                          placeholder="<!-- Google Tag Manager (noscript) -->&#10;<noscript><iframe src=&quot;...&quot;></iframe></noscript>&#10;<!-- End Google Tag Manager (noscript) -->"><?= htmlspecialchars($gtmBodyCode, ENT_QUOTES) ?></textarea>
            </div>
            <div class="field" style="margin:0;max-width:360px;">
                <label>sau doar Container ID (GTM-…)</label>
                <input type="text" name="google_tag_manager_id" placeholder="GTM-XXXXXXX"
                       value="<?= htmlspecialchars($gtmId, ENT_QUOTES) ?>">
            </div>
        </div>

        <!-- Google Ads -->
        <div class="settings-card" style="grid-column:1/-1;border:1px solid #e5e7eb;border-radius:14px;padding:18px;background:#fff;">
            <label style="display:flex;align-items:center;gap:10px;margin:0 0 4px;font-weight:600;font-size:17px;cursor:pointer;">
                <input type="checkbox" name="google_ads_enabled" <?= $adsEnabled ? 'checked' : '' ?>>
                Google Ads
            </label>
            <p class="muted" style="margin:0 0 14px;font-size:13px;">
                ID-ul de conversie (format <code>AW-XXXXXXXXX</code>) încarcă tag-ul global de remarketing pe tot site-ul.
                Eticheta de conversie (opțională) declanșează evenimentul de conversie pe pagina de mulțumire la finalizarea comenzii.
            </p>
            <div style="display:flex;gap:14px;flex-wrap:wrap;">
                <div class="field" style="margin:0;flex:1;min-width:240px;">
                    <label>ID conversie (AW-…)</label>
                    <input type="text" name="google_ads_conversion_id" placeholder="AW-XXXXXXXXX"
                           value="<?= htmlspecialchars($adsId, ENT_QUOTES) ?>">
                </div>
                <div class="field" style="margin:0;flex:1;min-width:240px;">
                    <label>Etichetă conversie (opțional)</label>
                    <input type="text" name="google_ads_conversion_label" placeholder="AbC-D_efG12hIjkLmnOp"
                           value="<?= htmlspecialchars($adsLabel, ENT_QUOTES) ?>">
                </div>
            </div>
        </div>

        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Salvează setările Google</button>
        </div>
    </form>
</section>

<script>
(() => {
    // Base64-encode code textareas before submit so the server WAF (ModSecurity)
    // does not block the POST for containing <script>/<iframe> tags.
    const form = document.getElementById('google-settings-form');
    if (!(form instanceof HTMLFormElement)) return;
    const codeFields = ['google_tag_manager_head_code', 'google_tag_manager_body_code', 'google_analytics_code'];
    const b64encode = (str) => btoa(unescape(encodeURIComponent(str)));
    form.addEventListener('submit', () => {
        codeFields.forEach((name) => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el && typeof el.value === 'string' && el.value !== '') {
                el.value = b64encode(el.value);
            }
        });
    });
})();
</script>
