<?php
/**
 * Pasul 3 din builderul de newsletter: previzualizare (calculator și
 * telefon), lista de verificare și „Trimite un test”.
 *
 * @var int    $nlId
 * @var string $nlEmailTest
 */
ob_start();
?>
<ul class="nlp-verificari" id="nlp-verificari" aria-live="polite">
    <li class="nlp-verificare"><span></span><span>Se verifică…</span></li>
</ul>
<p style="margin:10px 0 0;"><button type="button" class="btn btn-secondary" id="nlp-verifica-din-nou">Verifică din nou</button></p>
<?php
$panouVerificare = (string) ob_get_clean();
ob_start();
?>
<form method="post" action="/admin/emails" id="nlp-form-test" data-nlp-salveaza="1">
    <input type="hidden" name="section" value="newsletter-campaign-send-test">
    <input type="hidden" name="campaign_id" value="<?= (int) $nlId ?>">
    <input type="hidden" name="from_builder" value="1">
    <div class="nlp-camp">
        <label for="nlp-email-test">Trimite-l mai întâi la adresa ta</label>
        <input type="email" id="nlp-email-test" name="test_email" required autocomplete="email"
               value="<?= adm_e($nlEmailTest) ?>" placeholder="adresa@ta.ro">
        <small>Vezi exact ce primesc abonații. În test, linkul de dezabonare e inactiv.</small>
    </div>
    <button type="submit" class="btn btn-secondary">Trimite un test</button>
</form>
<?php
$panouTest = (string) ob_get_clean();
?>
<section class="nlp-pas" id="nlp-pas-3" data-pas-panou="3" hidden>
    <div class="nlp-continut">
        <?php admin_componenta('antet-pagina', [
            'id' => 'nlp-antet-verificare',
            'titlu' => 'Verifică înainte să trimiți',
            'subtitlu' => 'Uită-te cum arată pe calculator și pe telefon, citește lista din dreapta și trimite-ți un test.',
            'ajutor' => [
                'Bifa verde: e în regulă. Semnul galben: merită o privire, dar poți trimite. Semnul roșu: repară întâi, altfel butoanele de trimitere rămân blocate.',
                'Previzualizarea arată conținutul de pe ecran, chiar dacă nu l-ai salvat încă.',
                'Testul pleacă doar la adresa scrisă de tine, nu la abonați, și nu apare în statistici.',
            ],
        ]); ?>
        <div class="nlp-doua-coloane">
            <div class="nlp-previzualizare">
                <div class="nlp-previzualizare__bara">
                    <p class="nlp-subiect-inbox" style="margin:0;">Subiect în inbox: <strong id="nlp-subiect-afisat"></strong></p>
                    <div class="nlp-comutator" role="group" aria-label="Lățimea previzualizării">
                        <button type="button" data-ecran="calculator" aria-pressed="true">Calculator</button>
                        <button type="button" data-ecran="telefon" aria-pressed="false">Telefon</button>
                    </div>
                </div>
                <div class="nlp-ecran" id="nlp-ecran">
                    <iframe id="nlp-iframe" title="Previzualizarea newsletterului" sandbox="allow-same-origin allow-popups"></iframe>
                </div>
            </div>
            <div>
                <?php admin_componenta('panou-lateral', ['id' => 'nlp-panou-verificare', 'titlu' => 'Lista de verificare', 'continut' => $panouVerificare]); ?>
                <?php admin_componenta('panou-lateral', ['id' => 'nlp-panou-test', 'titlu' => 'Trimite un test', 'continut' => $panouTest]); ?>
            </div>
        </div>
        <div class="nlp-actiuni">
            <button type="button" class="btn btn-secondary" data-mergi-la="2">← Înapoi la destinatari</button>
            <button type="button" class="btn" data-mergi-la="4">Continuă la trimitere →</button>
        </div>
    </div>
</section>
