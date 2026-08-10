<section class="panel">
    <h1>Setări plăți (Stripe)</h1>
    <p>Configurează cheile Stripe și secretul de webhook pentru confirmarea automată a plăților.</p>

    <form method="post" action="/admin/settings/payments" class="form-grid">
        <div class="field" style="grid-column:1/-1;">
            <label>Stripe Publishable Key</label>
            <input type="text" name="stripe_publishable_key"
                   value="<?= htmlspecialchars((string) $settings['stripe_publishable_key'], ENT_QUOTES) ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Stripe Secret Key</label>
            <input type="password" name="stripe_secret_key"
                   value="<?= htmlspecialchars((string) $settings['stripe_secret_key'], ENT_QUOTES) ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Stripe Webhook Secret</label>
            <input type="password" name="stripe_webhook_secret"
                   value="<?= htmlspecialchars((string) ($settings['stripe_webhook_secret'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Monedă Stripe</label>
            <input type="text" name="stripe_currency" maxlength="3"
                   value="<?= htmlspecialchars((string) ($settings['stripe_currency'] ?? 'ron'), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Webhook endpoint</label>
            <input type="text" value="/webhook/stripe" readonly>
        </div>
        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Salvează setările Stripe</button>
        </div>
    </form>
</section>
