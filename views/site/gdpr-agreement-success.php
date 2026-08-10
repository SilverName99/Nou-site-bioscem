<?php
$backUrl = (string) ($backUrl ?? '/acorduri-gdpr');
?>

<section style="min-height:56vh;display:flex;align-items:center;justify-content:center;">
    <div style="text-align:center;max-width:760px;width:100%;padding:24px;">
        <h1 style="margin:0 0 16px;font-size:38px;line-height:1.2;">Mulțumim pentru completare! Datele au fost salvate cu succes.</h1>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-secondary" style="display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 18px;font-size:18px;">
            <span aria-hidden="true">←</span>
            <span>Înapoi</span>
        </a>
    </div>
</section>
