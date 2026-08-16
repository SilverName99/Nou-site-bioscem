<?php
/**
 * Pagina de plată a diferenței rămase pe o comandă.
 *
 * @var array<string, mixed> $link
 * @var array<string, mixed> $order
 * @var bool $esteplatit
 */
$suma = round((float) ($link['amount'] ?? 0), 2);
$numarComanda = (string) ($order['order_number'] ?? '');
$token = (string) ($link['token'] ?? '');
$platitAcum = $esteplatit || isset($_GET['platit']);
$esuat = isset($_GET['esuat']);
?>
<section class="panel" style="max-width:560px;margin-left:auto;margin-right:auto;padding:32px 26px;text-align:center;">
    <?php if ($platitAcum): ?>
        <p style="margin:0 0 10px;font-size:52px;line-height:1;">✅</p>
        <h1 style="margin:0 0 12px;font-size:24px;color:#0f172a;">Plata a fost efectuată</h1>
        <p style="margin:0 auto;max-width:420px;color:#475569;font-size:15px;line-height:1.6;">
            Mulțumim! Diferența pentru comanda <strong><?= htmlspecialchars($numarComanda, ENT_QUOTES) ?></strong>
            a fost achitată. Îți pregătim comanda pentru livrare.
        </p>
        <p style="margin:24px 0 0;">
            <a href="/magazin" style="display:inline-block;padding:12px 22px;border-radius:10px;background:#2f8d5b;color:#fff;text-decoration:none;font-weight:700;">Continuă cumpărăturile</a>
        </p>
    <?php else: ?>
        <h1 style="margin:0 0 10px;font-size:24px;color:#0f172a;">Diferență de plată</h1>
        <p style="margin:0 auto 18px;max-width:440px;color:#475569;font-size:15px;line-height:1.6;">
            Pentru comanda <strong><?= htmlspecialchars($numarComanda, ENT_QUOTES) ?></strong> a rămas
            de achitat suma de mai jos, în urma produselor adăugate la cererea ta.
        </p>

        <?php if ($esuat): ?>
            <p style="margin:0 auto 18px;max-width:440px;padding:11px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;font-size:14px;line-height:1.5;">
                Plata anterioară nu a fost finalizată. Poți încerca din nou.
            </p>
        <?php endif; ?>

        <p style="margin:0 0 22px;font-size:40px;font-weight:700;color:#0f172a;line-height:1.1;">
            <?= number_format($suma, 2, ',', '.') ?> lei
        </p>

        <form method="post" action="/plata/<?= rawurlencode($token) ?>">
            <button type="submit"
                    style="display:inline-block;min-width:240px;padding:14px 26px;border:none;border-radius:12px;background:#2f8d5b;color:#fff;font-size:16px;font-weight:700;cursor:pointer;">
                Plătește cu cardul
            </button>
        </form>

        <p style="margin:18px 0 0;color:#94a3b8;font-size:13px;line-height:1.5;">
            Plata se face securizat, pe pagina procesatorului EuPlătesc.<br>
            Datele cardului nu ajung pe site-ul nostru.
        </p>
    <?php endif; ?>
</section>
