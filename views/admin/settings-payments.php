<?php
/**
 * Setări plăți: EuPlătesc (procesatorul folosit) și Stripe (rămas ca
 * alternativă, ascuns din checkout până când e bifat).
 *
 * @var array<string, mixed> $settings
 * @var string $appUrl
 */
$appUrl = rtrim((string) ($appUrl ?? ''), '/');
$euActiv = (string) ($settings['euplatesc_enabled'] ?? '0') === '1';
$stripeActiv = (string) ($settings['stripe_enabled'] ?? '0') === '1';
?>

<section class="panel">
    <h1>Setări plăți</h1>
    <p>Plata cu cardul se face prin EuPlătesc. Stripe rămâne disponibil ca variantă de rezervă, dar nu apare în checkout decât dacă îl bifezi.</p>

    <form method="post" action="/admin/settings/payments" class="form-grid">
        <article class="panel" style="grid-column:1/-1;margin:0 0 4px;background:#f8fafc;border-color:#cbd5e1;">
            <h3 style="margin:0;">EuPlătesc</h3>
            <p style="margin:6px 0 0;color:#64748b;">
                Datele se iau din panoul de comerciant EuPlătesc, secțiunea de integrare.
                Cheia secretă e un șir hexazecimal — se copiază exact, fără spații.
            </p>
        </article>

        <div class="field" style="grid-column:1/-1;">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="euplatesc_enabled" value="1" <?= $euActiv ? 'checked' : '' ?>>
                Acceptă plata cu cardul prin EuPlătesc
            </label>
            <small style="color:#64748b;">
                Fără bifă (sau fără date complete), în checkout rămâne doar plata ramburs.
            </small>
        </div>

        <div class="field">
            <label>Merchant ID (MID)</label>
            <input type="text" name="euplatesc_merchant_id"
                   value="<?= htmlspecialchars((string) ($settings['euplatesc_merchant_id'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Cheia secretă</label>
            <input type="password" name="euplatesc_secret_key" autocomplete="new-password"
                   value="<?= htmlspecialchars((string) ($settings['euplatesc_secret_key'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Monedă</label>
            <input type="text" name="euplatesc_currency" maxlength="3"
                   value="<?= htmlspecialchars((string) ($settings['euplatesc_currency'] ?? 'RON'), ENT_QUOTES) ?>">
        </div>

        <article class="panel" style="grid-column:1/-1;margin:4px 0 0;background:#ecfdf5;border-color:#a7f3d0;">
            <h4 style="margin:0 0 6px;">Adresele folosite la plată</h4>
            <p style="margin:0 0 8px;color:#475569;">
                Se trimit automat cu fiecare tranzacție, deci nu trebuie configurate nicăieri.
                Le poți trece și în panoul EuPlătesc (Setări), ca plasă de siguranță, dacă
                procesatorul îți cere adrese fixe pe cont.
            </p>
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <tr>
                    <td style="padding:4px 8px 4px 0;color:#64748b;white-space:nowrap;">URL notificare (silent / IPN)</td>
                    <td><code><?= htmlspecialchars($appUrl . '/webhook/euplatesc', ENT_QUOTES) ?></code></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;color:#64748b;white-space:nowrap;">Retur plată reușită</td>
                    <td><code><?= htmlspecialchars($appUrl . '/checkout/succes/{numar_comanda}?euplatesc=1', ENT_QUOTES) ?></code></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;color:#64748b;white-space:nowrap;">Retur plată eșuată</td>
                    <td><code><?= htmlspecialchars($appUrl . '/checkout?euplatesc_failed=1', ENT_QUOTES) ?></code></td>
                </tr>
            </table>
            <p style="margin:8px 0 0;color:#64748b;font-size:13px;">
                URL-ul de notificare e cel care marchează comanda ca plătită. Dacă plata reușește
                dar comanda rămâne „plată în așteptare", el e primul lucru de verificat.
            </p>
        </article>

        <article class="panel" style="grid-column:1/-1;margin:16px 0 4px;background:#f8fafc;border-color:#cbd5e1;">
            <h3 style="margin:0;">Stripe (rezervă)</h3>
            <p style="margin:6px 0 0;color:#64748b;">
                Se folosește doar dacă îl bifezi; atunci clientul are două butoane de card în checkout.
            </p>
        </article>

        <div class="field" style="grid-column:1/-1;">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="stripe_enabled" value="1" <?= $stripeActiv ? 'checked' : '' ?>>
                Arată și plata prin Stripe în checkout
            </label>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Stripe Publishable Key</label>
            <input type="text" name="stripe_publishable_key"
                   value="<?= htmlspecialchars((string) $settings['stripe_publishable_key'], ENT_QUOTES) ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Stripe Secret Key</label>
            <input type="password" name="stripe_secret_key" autocomplete="new-password"
                   value="<?= htmlspecialchars((string) $settings['stripe_secret_key'], ENT_QUOTES) ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Stripe Webhook Secret</label>
            <input type="password" name="stripe_webhook_secret" autocomplete="new-password"
                   value="<?= htmlspecialchars((string) ($settings['stripe_webhook_secret'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Monedă Stripe</label>
            <input type="text" name="stripe_currency" maxlength="3"
                   value="<?= htmlspecialchars((string) ($settings['stripe_currency'] ?? 'ron'), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Webhook endpoint Stripe</label>
            <input type="text" value="<?= htmlspecialchars($appUrl . '/webhook/stripe', ENT_QUOTES) ?>" readonly>
        </div>

        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Salvează setările de plată</button>
        </div>
    </form>
</section>
