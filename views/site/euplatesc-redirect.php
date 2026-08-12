<?php
/**
 * Pas intermediar către EuPlătesc: formularul semnat se trimite automat prin
 * POST către gateway. Butonul rămâne vizibil pentru cazul în care JavaScript-ul
 * e blocat, ca plata să poată fi pornită manual.
 *
 * @var string $gatewayUrl
 * @var array<string, string> $fields
 * @var string $orderNumber
 */
?>
<main style="max-width:420px;padding:32px 24px;text-align:center;">
    <div style="width:44px;height:44px;margin:0 auto 18px;border:3px solid #d1d5db;border-top-color:#0f766e;border-radius:50%;animation:bv-spin 0.9s linear infinite;"></div>
    <h1 style="margin:0 0 8px;font-size:20px;line-height:1.3;">Te ducem la plata securizată</h1>
    <p style="margin:0 0 4px;color:#475569;font-size:15px;line-height:1.5;">
        Comanda <strong><?= htmlspecialchars($orderNumber, ENT_QUOTES) ?></strong> a fost înregistrată.
        Se deschide pagina EuPlătesc, unde introduci datele cardului.
    </p>
    <p style="margin:0 0 20px;color:#94a3b8;font-size:13px;line-height:1.5;">
        Nu închide fereastra și nu apăsa înapoi până la finalizarea plății.
    </p>

    <form id="euplatesc-form" method="post" action="<?= htmlspecialchars($gatewayUrl, ENT_QUOTES) ?>" accept-charset="UTF-8">
        <?php foreach ($fields as $name => $value): ?>
            <input type="hidden" name="<?= htmlspecialchars((string) $name, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) $value, ENT_QUOTES) ?>">
        <?php endforeach; ?>
        <noscript>
            <p style="color:#b45309;font-size:14px;">Activează JavaScript sau apasă butonul de mai jos.</p>
        </noscript>
        <button type="submit" style="display:inline-block;padding:12px 20px;border:0;border-radius:10px;background:#0f766e;color:#fff;font-size:15px;font-weight:600;cursor:pointer;">
            Continuă către plată
        </button>
    </form>
</main>
<style>@keyframes bv-spin { to { transform: rotate(360deg); } }</style>
<script>
    (function () {
        var form = document.getElementById('euplatesc-form');
        if (form) {
            // Mic decalaj, ca pagina să apuce să se deseneze înainte de redirect.
            window.setTimeout(function () { form.submit(); }, 250);
        }
    })();
</script>
