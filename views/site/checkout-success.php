<section class="panel">
    <h1>Comandă plasată cu succes</h1>
    <?php if (($stripeReturn ?? false) === true || ($euplatescReturn ?? false) === true): ?>
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
    <?php if (trim((string) ($opInstructiuni ?? '')) !== ''): ?>
        <div style="margin-top:16px;padding:16px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc;">
            <h2 style="margin:0 0 8px;font-size:17px;">Cum plătiți prin ordin de plată</h2>
            <p style="margin:0 0 10px;">Comanda se procesează după ce plata ajunge în contul nostru.
                Treceți la detaliile plății numărul comenzii:
                <strong><?= htmlspecialchars((string) $orderNumber, ENT_QUOTES) ?></strong>.</p>
            <p style="margin:0;white-space:pre-line;"><?= htmlspecialchars(trim((string) $opInstructiuni), ENT_QUOTES) ?></p>
        </div>
    <?php endif; ?>
    <a class="btn" href="/magazin">Continuă cumpărăturile</a>
</section>
