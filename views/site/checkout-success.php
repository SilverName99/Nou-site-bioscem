<section class="panel">
    <h1>Comandă plasată cu succes</h1>
    <?php if (($stripeReturn ?? false) === true): ?>
        <?php if (($paymentStatus ?? '') === 'paid'): ?>
            <p>Plata cu cardul a fost confirmată. Mulțumim!</p>
        <?php else: ?>
            <p>Mulțumim! Plata cu cardul este în curs de confirmare. Statusul comenzii se actualizează automat.</p>
        <?php endif; ?>
    <?php else: ?>
        <p>Mulțumim! Comanda ta a fost înregistrată.</p>
    <?php endif; ?>
    <?php $labelStyle = 'user-select:none;-webkit-user-select:none;-moz-user-select:none;'; ?>
    <p><span style="<?= $labelStyle ?>">Număr comandă: </span><strong><?= htmlspecialchars((string) $orderNumber, ENT_QUOTES) ?></strong></p>
    <?php if (($orderTotal ?? null) !== null): ?>
        <p><span style="<?= $labelStyle ?>">Suma comenzii: </span><strong><?= htmlspecialchars(number_format((float) $orderTotal, 2, ',', '.'), ENT_QUOTES) ?></strong></p>
        <p><span style="<?= $labelStyle ?>">Valută: </span><strong><?= htmlspecialchars((string) ($orderCurrency ?? 'RON'), ENT_QUOTES) ?></strong></p>
    <?php endif; ?>
    <?php if (trim((string) ($orderEmail ?? '')) !== ''): ?>
        <p><span style="<?= $labelStyle ?>">Email: </span><strong><?= htmlspecialchars(trim((string) $orderEmail), ENT_QUOTES) ?></strong></p>
    <?php endif; ?>
    <a class="btn" href="/magazin">Continuă cumpărăturile</a>
</section>
