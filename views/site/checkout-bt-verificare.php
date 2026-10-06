<?php
/**
 * Întoarcerea de la Banca Transilvania când plata nu se poate spune încă
 * (banca n-a răspuns, plata e verificată chiar acum sau e încă în 3-D Secure).
 * Pagina e neutră: nu golește coșul, nu trimite conversii și nu spune nici
 * „succes", nici „eșec". Confirmarea vine prin notificarea băncii sau prin cron.
 *
 * Fără număr de comandă e varianta generică, arătată oricui ajunge pe adresa
 * de întoarcere fără sesiunea care a pornit plata: nu spune nimic despre plată.
 *
 * @var string $numar
 * @var string $urlReverificare
 */
$numar = trim((string) ($numar ?? ''));
$urlReverificare = trim((string) ($urlReverificare ?? ''));
?>
<section class="panel" style="max-width:560px;margin-left:auto;margin-right:auto;padding:32px 26px;text-align:center;">
    <h1 style="margin:0 0 12px;font-size:24px;color:#0f172a;">
        <?= $numar !== '' ? 'Verificăm plata la bancă' : 'Mulțumim!' ?>
    </h1>
    <?php if ($numar !== ''): ?>
        <p style="margin:0 auto;max-width:440px;color:#475569;font-size:15px;line-height:1.6;">
            Comanda <strong><?= htmlspecialchars($numar, ENT_QUOTES) ?></strong> e înregistrată, iar plata cu cardul
            se confirmă în câteva momente. Imediat ce banca o confirmă, primești emailul de confirmare a comenzii.
            Nu e nevoie să plătești din nou.
        </p>
        <?php if ($urlReverificare !== ''): ?>
            <p style="margin:22px 0 0;">
                <a href="<?= htmlspecialchars($urlReverificare, ENT_QUOTES) ?>" data-reverificare
                   style="display:inline-block;padding:12px 22px;border-radius:10px;background:#2f8d5b;color:#fff;text-decoration:none;font-weight:700;">Verifică din nou</a>
            </p>
            <p style="margin:12px 0 0;color:#94a3b8;font-size:13px;">Pagina se reîmprospătează singură peste 30 de secunde.</p>
            <script>setTimeout(function () { var a = document.querySelector('[data-reverificare]'); if (a) { window.location.href = a.href; } }, 30000);</script>
        <?php endif; ?>
    <?php else: ?>
        <p style="margin:0 auto;max-width:440px;color:#475569;font-size:15px;line-height:1.6;">
            Dacă ai finalizat plata, confirmarea comenzii ajunge pe email în câteva minute.
            Pentru orice întrebare despre comandă, scrie-ne și îți răspundem cât de repede putem.
        </p>
        <p style="margin:22px 0 0;">
            <a href="/magazin" style="display:inline-block;padding:12px 22px;border-radius:10px;background:#2f8d5b;color:#fff;text-decoration:none;font-weight:700;">Înapoi în magazin</a>
        </p>
    <?php endif; ?>
</section>
