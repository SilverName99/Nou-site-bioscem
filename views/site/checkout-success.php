<?php
$numar = trim((string) ($orderNumber ?? ''));
$email = trim((string) ($orderEmail ?? ''));
$instructiuni = trim((string) ($opInstructiuni ?? ''));
$returCard = ($stripeReturn ?? false) === true || ($euplatescReturn ?? false) === true;
$platit = ($paymentStatus ?? '') === 'paid';

// Trei situații, trei mesaje: card confirmat, card în curs, restul.
if ($returCard && $platit) {
    $titlu = 'Plata a fost confirmată';
    $mesaj = 'Mulțumim! Comanda ta e înregistrată și intră în pregătire.';
} elseif ($returCard) {
    $titlu = 'Comandă plasată cu succes';
    $mesaj = 'Plata cu cardul se confirmă în câteva momente. Statusul comenzii se actualizează automat.';
} elseif ($instructiuni !== '') {
    $titlu = 'Comandă plasată cu succes';
    $mesaj = 'Mai e un pas: efectuează plata cu datele de mai jos, iar noi pregătim comanda imediat ce banii ajung.';
} else {
    $titlu = 'Comandă plasată cu succes';
    $mesaj = 'Mulțumim! Comanda ta a fost înregistrată și intră în pregătire.';
}
?>
<style>
.bs-succes{
    --bs-succes-accent:#075d61;
    --bs-succes-line:#e3ecec;
    --bs-succes-text:#18353a;
    --bs-succes-muted:#66787b;
    max-width:640px;
    margin:32px auto 56px;
    padding:0 16px;
    font-family:Inter,Arial,sans-serif;
    color:var(--bs-succes-text);
}
.bs-succes__card{
    background:#fff;
    border:1px solid var(--bs-succes-line);
    border-radius:18px;
    padding:36px 32px 32px;
    box-shadow:0 18px 44px rgba(11,70,72,.08);
    text-align:center;
}
.bs-succes__bifa{
    width:64px;
    height:64px;
    margin:0 auto 18px;
    border-radius:50%;
    background:#e8f5ef;
    display:flex;
    align-items:center;
    justify-content:center;
}
.bs-succes__bifa svg{
    width:32px;
    height:32px;
    fill:none;
    stroke:#1f8a5f;
    stroke-width:2.6;
    stroke-linecap:round;
    stroke-linejoin:round;
}
.bs-succes__titlu{
    margin:0 0 8px;
    font-size:28px;
    line-height:1.15;
    font-weight:700;
}
.bs-succes__mesaj{
    margin:0 auto;
    max-width:44ch;
    color:var(--bs-succes-muted);
    font-size:15px;
    line-height:1.55;
}
.bs-succes__rezumat{
    margin:26px 0 0;
    border:1px solid var(--bs-succes-line);
    border-radius:12px;
    background:#f8fbfb;
    text-align:left;
}
.bs-succes__rand{
    display:flex;
    align-items:baseline;
    justify-content:space-between;
    gap:16px;
    padding:12px 16px;
    border-bottom:1px solid var(--bs-succes-line);
    font-size:15px;
}
.bs-succes__rand:last-child{
    border-bottom:0;
}
.bs-succes__eticheta{
    color:var(--bs-succes-muted);
    /* Eticheta nu trebuie să intre în selecție când copiezi numărul comenzii. */
    user-select:none;
    -webkit-user-select:none;
}
.bs-succes__valoare{
    font-weight:700;
    text-align:right;
    word-break:break-word;
}
.bs-succes__valoare--mare{
    font-size:19px;
    color:var(--bs-succes-accent);
}
.bs-succes__plata{
    margin:20px 0 0;
    padding:20px;
    border:1px solid #cbe0df;
    border-radius:12px;
    background:#f2f9f8;
    text-align:left;
}
.bs-succes__plata h2{
    margin:0 0 6px;
    font-size:17px;
    font-weight:700;
}
.bs-succes__plata p{
    margin:0 0 14px;
    color:var(--bs-succes-muted);
    font-size:14px;
    line-height:1.55;
}
.bs-succes__date{
    margin:0;
    padding:14px 16px;
    border:1px solid #d7e7e6;
    border-radius:10px;
    background:#fff;
    /* Cifrele de cont se citesc mai bine cu lățime fixă. */
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:14px;
    line-height:1.75;
    white-space:pre-line;
    word-break:break-word;
}
.bs-succes__copiaza{
    margin-top:12px;
    padding:9px 16px;
    border:1px solid #a9cecc;
    border-radius:100px;
    background:#fff;
    color:var(--bs-succes-accent);
    font-family:inherit;
    font-size:13.5px;
    font-weight:600;
    cursor:pointer;
}
.bs-succes__copiaza:hover{
    background:#e6f3f2;
}
.bs-succes__actiuni{
    margin:26px 0 0;
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    justify-content:center;
}
.bs-succes__nota{
    margin:18px 0 0;
    color:var(--bs-succes-muted);
    font-size:13.5px;
    line-height:1.5;
}
@media(max-width:560px){
    .bs-succes{margin:20px auto 40px;}
    .bs-succes__card{padding:28px 18px 24px;border-radius:14px;}
    .bs-succes__titlu{font-size:24px;}
    .bs-succes__rand{flex-direction:column;gap:2px;}
    .bs-succes__valoare{text-align:left;}
    .bs-succes__actiuni .btn{width:100%;}
}
</style>

<div class="bs-succes">
    <div class="bs-succes__card">

        <div class="bs-succes__bifa" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="m4 12.5 5.2 5.2L20 7"/></svg>
        </div>

        <h1 class="bs-succes__titlu"><?= htmlspecialchars($titlu, ENT_QUOTES) ?></h1>
        <p class="bs-succes__mesaj"><?= htmlspecialchars($mesaj, ENT_QUOTES) ?></p>

        <div class="bs-succes__rezumat">
            <div class="bs-succes__rand">
                <span class="bs-succes__eticheta">Număr comandă</span>
                <span class="bs-succes__valoare"><?= htmlspecialchars($numar, ENT_QUOTES) ?></span>
            </div>
            <?php if (($orderTotal ?? null) !== null): ?>
                <div class="bs-succes__rand">
                    <span class="bs-succes__eticheta">Total</span>
                    <span class="bs-succes__valoare bs-succes__valoare--mare">
                        <?= htmlspecialchars(number_format((float) $orderTotal, 2, ',', '.'), ENT_QUOTES) ?>
                        <?= htmlspecialchars((string) ($orderCurrency ?? 'RON'), ENT_QUOTES) ?>
                    </span>
                </div>
            <?php endif; ?>
            <?php if ($email !== ''): ?>
                <div class="bs-succes__rand">
                    <span class="bs-succes__eticheta">Confirmare trimisă la</span>
                    <span class="bs-succes__valoare"><?= htmlspecialchars($email, ENT_QUOTES) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($instructiuni !== ''): ?>
            <div class="bs-succes__plata">
                <h2>Cum plătiți prin ordin de plată</h2>
                <p>Comanda se procesează după ce plata ajunge în contul nostru.
                    Treceți la detaliile plății numărul comenzii
                    <strong><?= htmlspecialchars($numar, ENT_QUOTES) ?></strong>.</p>
                <p class="bs-succes__date" id="bs-date-plata"><?= htmlspecialchars($instructiuni, ENT_QUOTES) ?></p>
                <button type="button" class="bs-succes__copiaza" data-bs-copiaza="bs-date-plata">
                    Copiază datele de plată
                </button>
            </div>
        <?php endif; ?>

        <div class="bs-succes__actiuni">
            <a class="btn" href="/magazin">Continuă cumpărăturile</a>
        </div>

        <?php if ($email !== ''): ?>
            <p class="bs-succes__nota">
                Dacă emailul nu ajunge în câteva minute, verifică și folderul Spam.
            </p>
        <?php endif; ?>

    </div>
</div>

<script>
(function () {
    "use strict";
    var buton = document.querySelector('[data-bs-copiaza]');
    if (!buton) {
        return;
    }
    var sursa = document.getElementById(buton.getAttribute('data-bs-copiaza'));
    if (!sursa) {
        return;
    }
    var textInitial = buton.textContent;
    var timer = null;

    // navigator.clipboard cere context sigur; pe http rămâne varianta veche.
    function copiaza(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (rezolva, respinge) {
            var camp = document.createElement('textarea');
            camp.value = text;
            camp.setAttribute('readonly', '');
            camp.style.position = 'fixed';
            camp.style.opacity = '0';
            document.body.appendChild(camp);
            camp.select();
            try {
                document.execCommand('copy') ? rezolva() : respinge();
            } catch (e) {
                respinge(e);
            }
            document.body.removeChild(camp);
        });
    }

    buton.addEventListener('click', function () {
        copiaza((sursa.innerText || sursa.textContent || '').trim()).then(function () {
            buton.textContent = 'Copiat!';
        }).catch(function () {
            buton.textContent = 'Selectează și copiază manual';
        }).then(function () {
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(function () {
                buton.textContent = textInitial;
            }, 2200);
        });
    });
})();
</script>
