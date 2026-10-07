<?php
/**
 * Setări plăți, pe două tab-uri:
 *  - „Plăți": EuPlătesc, ordinul de plată și Stripe (rezervă);
 *  - „Banca Transilvania": BT iPay (rate și puncte STAR), cu datele de acces
 *    în .env, testul de conexiune, cronul și plata de test de 1 leu.
 *
 * Cheile secrete nu se mai scriu niciodată înapoi în pagină: câmpul gol la
 * salvare înseamnă „păstrează cheia salvată".
 *
 * @var array<string, mixed> $settings
 * @var string $appUrl
 * @var string $tab
 * @var array<string, mixed> $bt
 * @var string $csrfToken
 */
$appUrl = rtrim((string) ($appUrl ?? ''), '/');
$tab = (string) ($tab ?? 'plati') === 'bt' ? 'bt' : 'plati';
$bt = is_array($bt ?? null) ? $bt : [];
$csrfToken = (string) ($csrfToken ?? '');
$e = static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES);
$campCsrf = '<input type="hidden" name="_csrf" value="' . $e($csrfToken) . '">';

$euActiv = (string) ($settings['euplatesc_enabled'] ?? '0') === '1';
$stripeActiv = (string) ($settings['stripe_enabled'] ?? '0') === '1';
$opActiv = (string) ($settings['bank_transfer_enabled'] ?? '0') === '1';
$euConfigurat = trim((string) ($settings['euplatesc_merchant_id'] ?? '')) !== ''
    && trim((string) ($settings['euplatesc_secret_key'] ?? '')) !== '';
$stripeConfigurat = trim((string) ($settings['stripe_secret_key'] ?? '')) !== '';

$btSetari = is_array($bt['setari'] ?? null) ? $bt['setari'] : [];
$btActiv = !empty($btSetari['activ']);
$btMod = (string) ($bt['mod'] ?? 'test');
$btModLive = $btMod === 'live';
$btConfigurat = !empty($bt['configurat']);

/** Eticheta „configurat / lipsește" — niciodată valoarea. */
$insigna = static function (bool $ok, string $textOk = 'configurat', string $textLipsa = 'lipsește') use ($e): string {
    return '<span class="pay-badge ' . ($ok ? 'pay-badge--ok' : 'pay-badge--off') . '">' . $e($ok ? $textOk : $textLipsa) . '</span>';
};

/** Câmp pentru o cheie secretă: gol = păstrează cheia salvată. */
$campSecret = static function (string $nume, string $eticheta, bool $configurat) use ($e, $insigna): string {
    $id = 'secret-' . $nume;
    $html = '<label for="' . $e($id) . '">' . $e($eticheta) . ' ' . $insigna($configurat) . '</label>';
    $html .= '<input type="password" id="' . $e($id) . '" name="' . $e($nume) . '" value="" autocomplete="new-password"'
        . ' placeholder="' . $e($configurat ? 'cheia salvată rămâne — scrie aici doar ca s-o înlocuiești' : 'scrie cheia') . '">';
    if ($configurat) {
        $html .= '<label class="pay-secret-clear"><input type="checkbox" name="' . $e($nume) . '_sterge" value="1"> Șterge cheia salvată</label>';
    }
    return $html;
};
?>

<style>
    .pay-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;}
    .pay-tabs a{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;text-decoration:none;font-weight:600;font-size:14px;}
    .pay-tabs a.is-active{background:#0f766e;border-color:#0f766e;color:#fff;}
    .pay-badge{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;font-weight:700;line-height:1.6;vertical-align:middle;}
    .pay-badge--ok{background:#dcfce7;color:#166534;}
    .pay-badge--off{background:#f1f5f9;color:#64748b;}
    .pay-badge--warn{background:#fef3c7;color:#92400e;}
    .pay-badge--test{background:#fee2e2;color:#991b1b;}
    .pay-secret-clear{display:flex !important;align-items:center;gap:6px;margin-top:4px;font-size:12px;color:#64748b;font-weight:400 !important;}
    .pay-overview{width:100%;border-collapse:collapse;font-size:14px;}
    .pay-overview td,.pay-overview th{padding:6px 8px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;}
    .pay-overview th{color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.03em;}
    .bt-grid{display:grid;gap:14px;}
    .bt-box{border:1px solid #cbd5e1;border-radius:10px;background:#fff;padding:14px 16px;}
    .bt-box h3{margin:0 0 8px;font-size:16px;}
    .bt-box p{margin:0 0 8px;color:#475569;font-size:14px;line-height:1.5;}
    .bt-table{width:100%;border-collapse:collapse;font-size:13px;}
    .bt-table th,.bt-table td{padding:6px 8px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;}
    .bt-table th{color:#64748b;font-weight:600;}
    .bt-code{display:block;padding:8px 10px;border-radius:6px;background:#0f172a;color:#e2e8f0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;white-space:pre-wrap;word-break:break-all;}
    .bt-actions{display:flex;flex-wrap:wrap;gap:6px;}
    .bt-actions form{margin:0;}
    .bt-help{position:relative;display:inline-block;vertical-align:middle;}
    .bt-help__btn{width:22px;height:22px;border-radius:50%;border:1px solid #0f766e;background:#fff;color:#0f766e;font-weight:800;font-size:13px;line-height:1;cursor:pointer;padding:0;}
    .bt-help__btn:focus-visible{outline:3px solid #99f6e4;outline-offset:2px;}
    .bt-help__pop{position:absolute;z-index:40;top:30px;left:-12px;width:min(560px,86vw);padding:14px 16px;border:1px solid #94a3b8;border-radius:10px;background:#fff;box-shadow:0 18px 40px rgba(15,23,42,.18);color:#1e293b;font-size:14px;line-height:1.55;}
    .bt-help__pop h4{margin:0 26px 8px 0;font-size:15px;}
    .bt-help__pop ol{margin:0 0 8px;padding-left:20px;}
    .bt-help__pop li{margin:0 0 6px;}
    .bt-help__close{position:absolute;top:8px;right:8px;width:26px;height:26px;border:0;border-radius:6px;background:#f1f5f9;color:#334155;font-size:16px;cursor:pointer;}
    .bt-warn{padding:10px 12px;border-radius:8px;border:1px solid #fca5a5;background:#fef2f2;color:#991b1b;font-size:14px;}
    .bt-ok{padding:10px 12px;border-radius:8px;border:1px solid #a7f3d0;background:#ecfdf5;color:#065f46;font-size:14px;}
</style>

<section class="panel">
    <h1>Setări plăți</h1>

    <nav class="pay-tabs" aria-label="Secțiuni plăți">
        <a href="/admin/settings/payments" class="<?= $tab === 'plati' ? 'is-active' : '' ?>"<?= $tab === 'plati' ? ' aria-current="page"' : '' ?>>EuPlătesc, Stripe, ordin de plată</a>
        <a href="/admin/settings/payments?tab=bt" class="<?= $tab === 'bt' ? 'is-active' : '' ?>"<?= $tab === 'bt' ? ' aria-current="page"' : '' ?>>Banca Transilvania (BT iPay)</a>
    </nav>

<?php if ($tab === 'plati'): ?>
    <p>Fiecare procesator de card are bifa lui. Bifa ascunde doar opțiunea din checkout: plățile deja începute cu un procesator oprit
        (confirmări, întoarceri, notificări, butoanele din comandă) merg mai departe, ca nicio comandă plătită să nu rămână în aer.</p>

    <article class="panel" style="margin:0 0 14px;background:#f8fafc;border-color:#cbd5e1;">
        <h3 style="margin:0 0 8px;">Plata cu cardul în checkout</h3>
        <table class="pay-overview">
            <tr><th>Procesator</th><th>În checkout</th><th>Date</th></tr>
            <tr>
                <td>EuPlătesc</td>
                <td><?= $insigna($euActiv && $euConfigurat, 'apare', $euActiv ? 'nu apare (date incomplete)' : 'oprit') ?></td>
                <td><?= $insigna($euConfigurat, 'configurat', 'incomplet') ?></td>
            </tr>
            <tr>
                <td>Stripe (rezervă)</td>
                <td><?= $insigna($stripeActiv && $stripeConfigurat, 'apare', $stripeActiv ? 'nu apare (lipsește cheia)' : 'oprit') ?></td>
                <td><?= $insigna($stripeConfigurat, 'configurat', 'incomplet') ?></td>
            </tr>
            <tr>
                <td>Banca Transilvania</td>
                <td>
                    <?php if (!empty($bt['vizibil_clienti'])): ?>
                        <?= $insigna(true, 'apare') ?>
                    <?php elseif (!empty($bt['vizibil_admin'])): ?>
                        <span class="pay-badge pay-badge--warn">doar pentru administratorii generali</span>
                    <?php elseif ($btActiv && !$btModLive): ?>
                        <?= $insigna(false, '', 'nu apare (mod test)') ?>
                    <?php else: ?>
                        <?= $insigna(false, '', $btActiv ? 'nu apare (date incomplete)' : 'oprit') ?>
                    <?php endif; ?>
                </td>
                <td><?= $insigna($btConfigurat, 'configurat', 'incomplet') ?> <a href="/admin/settings/payments?tab=bt" style="font-size:13px;">Setări BT →</a></td>
            </tr>
        </table>
    </article>

    <form method="post" action="/admin/settings/payments" class="form-grid" autocomplete="off">
        <?= $campCsrf ?>
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
                Fără bifă (sau fără date complete), EuPlătesc nu mai apare în checkout. Plățile deja începute se confirmă în continuare.
            </small>
        </div>

        <div class="field">
            <label>Merchant ID (MID)</label>
            <input type="text" name="euplatesc_merchant_id"
                   value="<?= htmlspecialchars((string) ($settings['euplatesc_merchant_id'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <?= $campSecret('euplatesc_secret_key', 'Cheia secretă', trim((string) ($settings['euplatesc_secret_key'] ?? '')) !== '') ?>
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
            <h3 style="margin:0;">Ordin de plată (transfer bancar)</h3>
            <p style="margin:6px 0 0;color:#64748b;">
                Clientul primește datele de plată pe pagina de confirmare și în emailul de comandă,
                cu numărul comenzii ca referință. Comanda pleacă în ERP abia după ce confirmi plata
                din lista de comenzi (butonul „Confirmă plata OP").
            </p>
        </article>

        <div class="field" style="grid-column:1/-1;">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="bank_transfer_enabled" value="1" <?= $opActiv ? 'checked' : '' ?>>
                Acceptă plata prin ordin de plată în checkout
            </label>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Instrucțiuni de plată (IBAN, bancă, beneficiar…)</label>
            <textarea name="bank_transfer_instructiuni" rows="5"
                placeholder="Beneficiar: BIOSCEM S.R.L.&#10;IBAN: RO...&#10;Banca: ...&#10;Comanda se procesează după încasare."><?= htmlspecialchars((string) ($settings['bank_transfer_instructiuni'] ?? ''), ENT_QUOTES) ?></textarea>
            <p style="margin:4px 0 0;color:#64748b;font-size:13px;">
                Textul apare exact așa la client. În șabloanele de email se poate folosi și
                variabila <code>{{payment_instructions}}</code>; dacă lipsește din șablon,
                caseta se adaugă singură la finalul emailului de comandă nouă.
            </p>
        </div>

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
            <?= $campSecret('stripe_secret_key', 'Stripe Secret Key', trim((string) ($settings['stripe_secret_key'] ?? '')) !== '') ?>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <?= $campSecret('stripe_webhook_secret', 'Stripe Webhook Secret', trim((string) ($settings['stripe_webhook_secret'] ?? '')) !== '') ?>
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
            <p style="margin:0 0 8px;color:#64748b;font-size:13px;">
                Cheile secrete nu se mai afișează. Lasă câmpul gol ca să păstrezi cheia salvată; scrie în el doar ca s-o înlocuiești.
            </p>
            <button class="btn" type="submit">Salvează setările de plată</button>
        </div>
    </form>
<?php else: ?>
    <?php
    $variabile = is_array($bt['variabile'] ?? null) ? $bt['variabile'] : [];
    $heartbeat = trim((string) ($bt['heartbeat'] ?? ''));
    $tsHeartbeat = $heartbeat !== '' ? strtotime($heartbeat) : false;
    $cronViu = $tsHeartbeat !== false && (time() - $tsHeartbeat) <= 40 * 60;
    $ultimulTest = is_array($bt['ultimul_test'] ?? null) ? $bt['ultimul_test'] : null;
    $caleEnv = (string) ($bt['cale_env'] ?? '.env');
    $teste = is_array($bt['teste'] ?? null) ? $bt['teste'] : [];
    $jurnal = is_array($bt['jurnal'] ?? null) ? $bt['jurnal'] : [];
    ?>
    <div class="bt-grid">
        <p style="margin:0;color:#475569;">
            Plata cu cardul pe pagina securizată a Băncii Transilvania. Clienții cu <strong>STAR Card</strong> pot plăti acolo
            în <strong>3 rate fără dobândă</strong> sau cu <strong>puncte STAR</strong>; site-ul nu are nimic de setat pentru asta.
            La comandă banca doar <strong>blochează</strong> suma; banii se <strong>încasează</strong> când comanda e aprobată (facturată)
            în ERP, din butonul „Încasează" din comandă sau, automat, în ziua 4. Comanda anulată înainte de încasare își eliberează
            singură suma; după încasare, rambursarea se face doar din butonul „Rambursează" din comandă.
        </p>

        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <?php if ($btModLive): ?>
                <span class="pay-badge pay-badge--ok">Mod: PRODUCȚIE (bani reali)</span>
            <?php else: ?>
                <span class="pay-badge pay-badge--test">MOD TEST (sandbox — nu se iau bani)</span>
            <?php endif; ?>
            <?php if (!empty($bt['vizibil_clienti'])): ?>
                <span class="pay-badge pay-badge--ok">În checkout: pentru toți clienții</span>
            <?php elseif (!empty($bt['vizibil_admin'])): ?>
                <span class="pay-badge pay-badge--warn">În checkout: doar pentru administratorii generali logați</span>
            <?php elseif ($btActiv && !$btModLive): ?>
                <span class="pay-badge pay-badge--off">În checkout: nu apare (modul test nu e oferit niciodată clienților)</span>
            <?php else: ?>
                <span class="pay-badge pay-badge--off">În checkout: nu apare</span>
            <?php endif; ?>
            <?php if (!empty($bt['linkuri_bt'])): ?>
                <span class="pay-badge pay-badge--ok">Linkuri noi pentru diferență: prin BT</span>
            <?php else: ?>
                <span class="pay-badge pay-badge--off">Linkuri noi pentru diferență: prin EuPlătesc</span>
            <?php endif; ?>
        </div>
        <?php if ($btActiv && !$btModLive): ?>
            <div class="bt-warn">Modul e <strong>TEST</strong>: Banca Transilvania <strong>nu apare în checkout</strong>, nici pentru administratori,
                ca nicio comandă reală să nu fie „plătită" pe platforma de test. Testele se fac cu „Plată de test 1 leu" de mai jos.</div>
        <?php endif; ?>

        <?php if (trim((string) ($bt['override'] ?? '')) !== ''): ?>
            <div class="bt-warn">
                <strong>Atenție:</strong> adresa băncii e înlocuită din .env (<code>BT_IPAY_BASE_URL_OVERRIDE</code> =
                <code><?= $e($bt['override']) ?></code>). Variabila există doar pentru teste locale; pe site-ul live șterge-o.
            </div>
        <?php endif; ?>
        <?php if (empty($bt['mod_recunoscut'])): ?>
            <div class="bt-warn"><code>BT_IPAY_MODE</code> are o valoare necunoscută; site-ul folosește modul <strong>test</strong>. Scrie <code>test</code> sau <code>live</code>.</div>
        <?php endif; ?>

        <article class="bt-box" id="bt-acces">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 8px;">
                <h3 style="margin:0;">Datele de acces de la bancă</h3>
                <?= $insigna($btConfigurat, 'configurat', 'lipsește') ?>
                <div class="bt-help">
                    <button type="button" class="bt-help__btn" aria-expanded="false" aria-controls="bt-env-ajutor"
                            aria-label="Unde și cum se scriu datele de acces BT" title="Unde se scriu datele de acces?" data-bt-help>?</button>
                    <div class="bt-help__pop" id="bt-env-ajutor" role="dialog" aria-modal="false" aria-labelledby="bt-env-ajutor-titlu" hidden>
                        <button type="button" class="bt-help__close" aria-label="Închide explicația" data-bt-help-close>×</button>
                        <div>
                            <h4 id="bt-env-ajutor-titlu">Unde se scriu datele primite de la Banca Transilvania</h4>
                            <ol>
                                <li>Ele <strong>nu se scriu în admin</strong>, ci în fișierul de configurare de pe server:<br>
                                    <code class="bt-code" style="margin-top:4px;"><?= $e($caleEnv) ?></code></li>
                                <li>Îl deschizi din <strong>hPanel → Fișiere → File Manager</strong>. Numele începe cu punct, deci e ascuns:
                                    dacă nu-l vezi, activează „Show hidden files" (Arată fișierele ascunse) în setările File Manager.
                                    Apoi click dreapta pe <code>.env</code> → <strong>Edit</strong>.</li>
                                <li>Adaugă la final liniile de mai jos și înlocuiește textul de după <code>=</code> cu datele primite de la bancă
                                    (separat pentru test și pentru producție), exact cum le-a trimis banca, fiecare pe un singur rând:
                                    <code class="bt-code" style="margin-top:4px;">BT_IPAY_MODE=test
BT_IPAY_TEST_USER=utilizatorul-de-test
BT_IPAY_TEST_PASS=parola-de-test
BT_IPAY_TEST_CALLBACK_KEY=cheia-callback-de-test
BT_IPAY_LIVE_USER=utilizatorul-de-productie
BT_IPAY_LIVE_PASS=parola-de-productie
BT_IPAY_LIVE_CALLBACK_KEY=cheia-callback-de-productie</code></li>
                                <li><strong>Test sau producție:</strong> <code>BT_IPAY_MODE=test</code> folosește platforma de test a băncii (nu se iau bani);
                                    <code>BT_IPAY_MODE=live</code> folosește platforma reală. Schimbi doar acest cuvânt.</li>
                                <li>Salvezi fișierul: modificarea se aplică <strong>imediat</strong>, de la următoarea încărcare a paginii — nu trebuie repornit nimic.
                                    Apasă apoi „Testează conexiunea".</li>
                                <li><strong>Nu trimite niciodată aceste parole pe email, WhatsApp sau chat</strong> — nici nouă, nici altcuiva.
                                    Le copiezi direct din mesajul băncii în fișier.</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            <p>Aici se vede doar dacă fiecare valoare e completată, niciodată valoarea.</p>
            <table class="bt-table">
                <tr><th></th><th>Test (sandbox)</th><th>Producție</th></tr>
                <?php foreach (['user' => 'Utilizator', 'pass' => 'Parolă', 'cheie' => 'Cheie callback (Base64)'] as $tipVar => $etichetaVar): ?>
                    <tr>
                        <td><?= $e($etichetaVar) ?></td>
                        <?php foreach (['test', 'live'] as $modVar): ?>
                            <?php $v = $variabile[$modVar][$tipVar] ?? ['variabila' => '', 'configurat' => false, 'avertisment' => '']; ?>
                            <td>
                                <?= $insigna(!empty($v['configurat'])) ?>
                                <?php if (($v['avertisment'] ?? '') !== ''): ?>
                                    <span class="pay-badge pay-badge--warn"><?= $e($v['avertisment']) ?></span>
                                <?php endif; ?>
                                <br><small style="color:#94a3b8;"><code><?= $e($v['variabila'] ?? '') ?></code></small>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td>Mod folosit</td>
                    <td colspan="2"><code>BT_IPAY_MODE</code> = <strong><?= $btModLive ? 'live (producție)' : 'test' ?></strong></td>
                </tr>
            </table>
            <p style="margin-top:8px;">Fără cheia de callback plățile merg în continuare: confirmarea vine la întoarcerea clientului și prin cron.</p>
        </article>

        <article class="bt-box" id="bt-conexiune">
            <h3>Testează conexiunea</h3>
            <p>Întreabă banca de o comandă care nu există. Răspunsul „comandă inexistentă" dovedește că utilizatorul și parola
                sunt acceptate. Nu creează nimic și nu mișcă bani. Adresa folosită: <code><?= $e($bt['url_baza'] ?? '') ?></code>.</p>
            <form method="post" action="/admin/settings/payments/bt/test-connection" style="margin:0 0 8px;">
                <?= $campCsrf ?>
                <button class="btn" type="submit" <?= $btConfigurat ? '' : 'disabled' ?>>Testează conexiunea (mod <?= $btModLive ? 'producție' : 'test' ?>)</button>
            </form>
            <?php if ($ultimulTest !== null): ?>
                <div class="<?= !empty($ultimulTest['ok']) ? 'bt-ok' : 'bt-warn' ?>">
                    Ultimul test (<?= $e(date('d.m.Y H:i', strtotime((string) ($ultimulTest['la'] ?? 'now')) ?: time())) ?>):
                    <?= $e($ultimulTest['mesaj'] ?? '') ?>
                </div>
            <?php endif; ?>
        </article>

        <form method="post" action="/admin/settings/payments/bt" class="bt-box form-grid" id="bt-setari" style="margin:0;">
            <?= $campCsrf ?>
            <h3 style="grid-column:1/-1;">Setări</h3>
            <div class="field" style="grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="bt_ipay_enabled" value="1" <?= $btActiv ? 'checked' : '' ?>>
                    Acceptă plata cu cardul prin Banca Transilvania în checkout
                </label>
                <small style="color:#64748b;">Oprit, opțiunea dispare din checkout; plățile deja începute (retur, notificări, cron, butoanele din comandă) merg mai departe.</small>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="bt_ipay_admin_only" value="1" <?= !empty($btSetari['doar_admin']) ? 'checked' : '' ?>>
                    Doar pentru administratorii generali logați (clienții nu văd opțiunea)
                </label>
                <small style="color:#64748b;">Doar în modul producție: pentru o comandă reală de probă, plasată din același browser în care ești logat ca administrator general. Cât e bifată, linkurile pentru diferență rămân pe EuPlătesc. În modul test, BT nu apare deloc în checkout.</small>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="bt_ipay_deposit_on_erp" value="1" <?= !empty($btSetari['incaseaza_la_aprobare']) ? 'checked' : '' ?>>
                    Încasează automat când comanda e aprobată (facturată) în ERP
                </label>
                <small style="color:#64748b;">Suma încasată = cât a mai rămas de încasat pe comandă (totalul de acum minus ce s-a încasat deja altfel: diferențe plătite prin link, încasări înregistrate), cel mult suma blocată. Dacă nu mai e nimic de încasat, suma blocată se eliberează (cu email către magazin).</small>
            </div>
            <div class="field">
                <label for="bt-ore-reamintire">Email de reamintire după (ore)</label>
                <input type="number" id="bt-ore-reamintire" name="bt_ipay_reminder_hours" min="12" max="109" value="<?= (int) ($btSetari['ore_reamintire'] ?? 72) ?>">
            </div>
            <div class="field">
                <label for="bt-ore-automat">Încasare automată după (ore)</label>
                <input type="number" id="bt-ore-automat" name="bt_ipay_autodeposit_hours" min="24" max="110" value="<?= (int) ($btSetari['ore_incasare_automata'] ?? 96) ?>">
                <small style="color:#64748b;">Banca cere încasarea în cel mult 5 zile (120 de ore); limita aici e 110.</small>
            </div>
            <div class="field">
                <label for="bt-minute-expirare">Plată neterminată expiră după (minute)</label>
                <input type="number" id="bt-minute-expirare" name="bt_ipay_expire_minutes" min="30" max="1440" value="<?= (int) ($btSetari['minute_expirare'] ?? 60) ?>">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label for="bt-emailuri">Emailuri pentru avertismente BT (reamintiri, încasări automate, eșecuri)</label>
                <input type="text" id="bt-emailuri" name="bt_ipay_notify_emails" value="<?= $e($settings['bt_ipay_notify_emails'] ?? '') ?>"
                       placeholder="gol = adresele formularului de contact">
            </div>
            <div style="grid-column:1/-1;">
                <button class="btn" type="submit">Salvează setările BT</button>
            </div>
        </form>

        <article class="bt-box" id="bt-adrese">
            <h3>Adrese pentru bancă</h3>
            <table class="bt-table">
                <tr>
                    <td style="white-space:nowrap;">Callback (notificarea băncii)</td>
                    <td><code><?= $e($bt['url_callback'] ?? '') ?></code><br><small style="color:#64748b;">Trimite-o băncii: ei o setează pe contul de comerciant.</small></td>
                </tr>
                <tr>
                    <td style="white-space:nowrap;">Întoarcerea clientului</td>
                    <td><code><?= $e($bt['url_retur'] ?? '') ?></code><br><small style="color:#64748b;">Se trimite automat cu fiecare plată.</small></td>
                </tr>
            </table>
        </article>

        <article class="bt-box" id="bt-cron">
            <h3>Cron (obligatoriu)</h3>
            <p>La 15 minute: verifică plățile neterminate și le expiră după <?= (int) ($btSetari['minute_expirare'] ?? 60) ?> de minute, eliberează suma
                blocată pentru comenzile anulate, reîncearcă încasările eșuate, trimite email la <?= (int) ($btSetari['ore_reamintire'] ?? 72) ?> de ore
                și încasează automat la <?= (int) ($btSetari['ore_incasare_automata'] ?? 96) ?> de ore (inclusiv precomenzile).
                În hPanel → Advanced → Cron Jobs, adaugă linia:</p>
            <code class="bt-code"><?= $e($bt['cron'] ?? '') ?></code>
            <p style="margin-top:8px;">(Programarea <code>*/15 * * * *</code>, comanda <code><?= $e($bt['cron_comanda'] ?? '') ?></code>.)</p>
            <?php if ($tsHeartbeat !== false): ?>
                <?php
                $ultimaRulare = is_array($bt['ultima_rulare'] ?? null) ? $bt['ultima_rulare'] : [];
                $rezRulare = is_array($ultimaRulare['rezultat'] ?? null) ? $ultimaRulare['rezultat'] : [];
                $eroriRulare = (int) ($rezRulare['erori'] ?? 0);
                $mesajeRulare = is_array($ultimaRulare['mesaje'] ?? null) ? $ultimaRulare['mesaje'] : [];
                ?>
                <div class="<?= $cronViu && $eroriRulare === 0 ? 'bt-ok' : 'bt-warn' ?>">
                    Ultima rulare încheiată a cronului: <?= $e(date('d.m.Y H:i', $tsHeartbeat)) ?>.
                    <?= $cronViu ? '' : 'Nu a mai rulat (sau nu a mai ajuns la capăt) de peste 40 de minute — verifică linia din hPanel.' ?>
                    <?php if ($rezRulare !== []): ?>
                        <br><small>Verificate <?= (int) ($rezRulare['verificate'] ?? 0) ?>, eliberate <?= (int) ($rezRulare['eliberate'] ?? 0) ?>,
                            reîncercări încasare <?= (int) ($rezRulare['reincercate'] ?? 0) ?>, încasate automat <?= (int) ($rezRulare['incasate_automat'] ?? 0) ?>,
                            erori <?= $eroriRulare ?><?= isset($ultimaRulare['apeluri']) ? ', apeluri la bancă ' . (int) $ultimaRulare['apeluri'] : '' ?>.</small>
                    <?php endif; ?>
                    <?php foreach ($mesajeRulare as $mesajRulare): ?>
                        <br><small><?= $e($mesajRulare) ?></small>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="bt-warn">Cronul nu a rulat niciodată. Fără el, plățile autorizate nu se încasează singure la termen.</div>
            <?php endif; ?>
        </article>

        <article class="bt-box" id="bt-test">
            <h3>Plată de test de 1 leu</h3>
            <p>Pornește o plată de 1 leu care nu ține de nicio comandă: nu trimite emailuri și nu ajunge în ERP. Ești dus pe pagina băncii,
                plătești cu un card, iar la întoarcere o poți <strong>anula (reverse)</strong> sau <strong>încasa, apoi rambursa</strong> — așa
                se verifică toate operațiile. Dacă o lași autorizată, cronul o anulează singur după 30 de minute.
                <?= $btModLive ? '<strong>Modul e PRODUCȚIE: cardul e debitat real cu 1 leu până la anulare sau rambursare.</strong>' : '' ?></p>
            <form method="post" action="/admin/settings/payments/bt/test-payment" style="margin:0 0 10px;">
                <?= $campCsrf ?>
                <button class="btn" type="submit" <?= $btConfigurat ? '' : 'disabled' ?>>Plată de test 1 leu (mod <?= $btModLive ? 'producție' : 'test' ?>)</button>
            </form>
            <?php if ($teste !== []): ?>
                <table class="bt-table">
                    <tr><th>Plată</th><th>Pornită</th><th>Stare</th><th>Sume</th><th>Acțiuni</th></tr>
                    <?php foreach ($teste as $test): ?>
                        <tr>
                            <td><code><?= $e($test['numar_bt'] ?? '') ?></code><br><small style="color:#94a3b8;"><?= $e(($test['mod'] ?? '') === 'live' ? 'producție' : 'test') ?></small></td>
                            <td><?= $e(date('d.m.Y H:i', strtotime((string) ($test['creat_la'] ?? 'now')) ?: time())) ?></td>
                            <td>
                                <span class="pay-badge pay-badge--<?= $e(in_array($test['clasa'] ?? '', ['ok', 'warn', 'off'], true) ? $test['clasa'] : 'off') ?>"><?= $e($test['eticheta'] ?? '') ?></span>
                                <?php if (($test['eroare'] ?? '') !== ''): ?>
                                    <br><small style="color:#b91c1c;"><?= $e($test['eroare']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small>încasat <?= $e(number_format((float) ($test['incasat'] ?? 0), 2, ',', '.')) ?> · rambursat <?= $e(number_format((float) ($test['rambursat'] ?? 0), 2, ',', '.')) ?></small></td>
                            <td>
                                <div class="bt-actions">
                                    <?php
                                    $actiuniTest = ['status' => 'Verifică'];
                                    if (!empty($test['poate_anula'])) {
                                        $actiuniTest['reverse'] = 'Anulează (reverse)';
                                        $actiuniTest['deposit_refund'] = 'Încasează, apoi rambursează';
                                        $actiuniTest['deposit'] = 'Doar încasează';
                                    }
                                    if (!empty($test['poate_rambursa'])) {
                                        $actiuniTest['refund'] = 'Rambursează';
                                    }
                                    ?>
                                    <?php foreach ($actiuniTest as $actiuneTest => $etichetaTest): ?>
                                        <form method="post" action="/admin/settings/payments/bt/test-action">
                                            <?= $campCsrf ?>
                                            <input type="hidden" name="tx_id" value="<?= (int) ($test['tx_id'] ?? 0) ?>">
                                            <input type="hidden" name="actiune" value="<?= $e($actiuneTest) ?>">
                                            <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;"
                                                <?= $actiuneTest !== 'status' ? 'onclick="return confirm(' . $e(json_encode('Sigur? ' . $etichetaTest . ' pentru plata de test ' . ($test['numar_bt'] ?? '') . '.')) . ');"' : '' ?>><?= $e($etichetaTest) ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </article>

        <article class="bt-box" id="bt-jurnal">
            <h3>Ultimele apeluri către bancă</h3>
            <p>Fără parole și fără date de card. Se păstrează 180 de zile.</p>
            <?php if ($jurnal === []): ?>
                <p style="color:#94a3b8;">Niciun apel încă.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="bt-table">
                        <tr><th>Când</th><th>Operație</th><th>Sursă</th><th>Plată</th><th>HTTP</th><th>Cod</th><th>ms</th><th>Mesaj</th></tr>
                        <?php foreach ($jurnal as $rand): ?>
                            <tr>
                                <td style="white-space:nowrap;"><?= $e(date('d.m H:i:s', strtotime((string) ($rand['created_at'] ?? 'now')) ?: time())) ?></td>
                                <td><?= $e($rand['action'] ?? '') ?> <small style="color:#94a3b8;"><?= $e($rand['mode'] ?? '') ?></small></td>
                                <td><?= $e($rand['source'] ?? '') ?></td>
                                <td><code><?= $e($rand['bt_order_number'] ?? '') ?></code></td>
                                <td><?= $e($rand['http_code'] ?? '') ?></td>
                                <td><?= $e($rand['error_code'] ?? '') ?></td>
                                <td><?= $e($rand['duration_ms'] ?? '') ?></td>
                                <td><small><?= $e($rand['message'] ?? '') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    </div>

    <script>
    (function () {
        'use strict';
        document.querySelectorAll('[data-bt-help]').forEach(function (buton) {
            var pop = document.getElementById(buton.getAttribute('aria-controls') || '');
            if (!pop) {
                return;
            }
            var inchide = function (focus) {
                pop.hidden = true;
                buton.setAttribute('aria-expanded', 'false');
                if (focus) {
                    buton.focus();
                }
            };
            var deschide = function () {
                pop.hidden = false;
                buton.setAttribute('aria-expanded', 'true');
                var primul = pop.querySelector('[data-bt-help-close]');
                if (primul) {
                    primul.focus();
                }
            };
            buton.addEventListener('click', function () {
                pop.hidden ? deschide() : inchide(false);
            });
            pop.querySelectorAll('[data-bt-help-close]').forEach(function (x) {
                x.addEventListener('click', function () { inchide(true); });
            });
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && !pop.hidden) {
                    inchide(true);
                }
            });
            document.addEventListener('click', function (ev) {
                if (!pop.hidden && !pop.contains(ev.target) && ev.target !== buton) {
                    inchide(false);
                }
            });
        });
    })();
    </script>
<?php endif; ?>
</section>
