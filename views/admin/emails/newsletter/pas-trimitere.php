<?php
/**
 * Pasul 4 din builderul de newsletter: trimite acum sau programează; când
 * newsletterul a plecat deja, arată unde a ajuns trimiterea.
 *
 * @var int    $nlId
 * @var string $nlStare          draft | scheduled | sending | paused | sent
 * @var string $nlProgramat      Y-m-d H:i:s sau ''
 * @var string $nlTrimisLa       Y-m-d H:i:s sau ''
 * @var string $nlEroare
 * @var array  $nlTotaluri       trimise / esuate / neconfirmate
 * @var int    $nlRamase
 * @var int    $nlTotalDestinatari
 * @var bool   $nlPoateModifica
 */
$etichetaStare = \App\Support\NewsletterService::STARI[$nlStare] ?? $nlStare;
$trimise = (int) ($nlTotaluri['trimise'] ?? 0);
$esuate = (int) ($nlTotaluri['esuate'] ?? 0);
$totalPlan = max($nlTotalDestinatari, $trimise + $nlRamase);
$procent = $totalPlan > 0 ? min(100, (int) round($trimise / $totalPlan * 100)) : 0;
$minimProgramare = date('Y-m-d\TH:i', time() + 5 * 60);
$valoareProgramare = $nlProgramat !== '' ? date('Y-m-d\TH:i', (int) strtotime($nlProgramat)) : '';

ob_start();
?>
<dl class="nlp-rezumat">
    <dt>Subiect</dt><dd id="nlp-rezumat-subiect"></dd>
    <dt>Liste</dt><dd id="nlp-rezumat-liste"></dd>
    <dt>Destinatari</dt><dd id="nlp-rezumat-destinatari">…</dd>
    <dt>Stare</dt><dd><?= adm_e($etichetaStare) ?></dd>
</dl>
<?php
$panouRezumat = (string) ob_get_clean();
?>
<section class="nlp-pas" id="nlp-pas-4" data-pas-panou="4" hidden>
    <div class="nlp-continut" style="max-width:880px;">
        <?php admin_componenta('antet-pagina', [
            'id' => 'nlp-antet-trimitere',
            'titlu' => 'Trimite sau programează',
            'subtitlu' => 'Ultimul pas. După ce pleacă, newsletterul nu se mai poate modifica.',
            'ajutor' => [
                '„Trimite acum”: primele emailuri pleacă imediat, restul automat, în câteva minute. Poți închide pagina.',
                '„Programează”: alegi ziua și ora; pleacă singur atunci (în cel mult 5 minute după). Până atunci îl poți modifica sau anula.',
                'Nimeni nu primește același newsletter de două ori, chiar dacă apeși de mai multe ori.',
            ],
        ]); ?>

        <?php if ($nlStare === 'sent'): ?>
            <?php admin_componenta('mesaj', [
                'tip' => 'succes',
                'titlu' => 'Newsletterul a fost trimis' . ($nlTrimisLa !== '' ? ' (început pe ' . date('d.m.Y, H:i', (int) strtotime($nlTrimisLa)) . ')' : '') . '.',
                'text' => $trimise . ' emailuri au plecat' . ($esuate > 0 ? ', ' . $esuate . ' au fost respinse de serverul de email' : '') . '. Ca să-l trimiți din nou sau altora, fă o copie („Duplică” în lista de newslettere).',
                'actiuni' => '<a class="btn btn-secondary" href="/admin/emails/newsletters?tab=stats&campaign_id=' . (int) $nlId . '">Vezi statisticile</a>',
            ]); ?>
        <?php elseif ($nlStare === 'sending'): ?>
            <?php ob_start(); ?>
            <a class="btn btn-secondary" href="/admin/emails/builder?type=campaign&amp;id=<?= (int) $nlId ?>&amp;pas=4">Actualizează</a>
            <form method="post" action="/admin/emails" style="margin:0;"
                  onsubmit="return confirm('Oprești trimiterea? Cine l-a primit deja rămâne așa; ceilalți îl primesc doar dacă apeși apoi „Reia trimiterea”.');">
                <input type="hidden" name="section" value="newsletter-campaign-pause">
                <input type="hidden" name="campaign_id" value="<?= (int) $nlId ?>">
                <button type="submit" class="btn btn-danger">Oprește trimiterea</button>
            </form>
            <?php $actiuniTrimitere = (string) ob_get_clean(); ?>
            <?php admin_componenta('mesaj', [
                'tip' => 'info',
                'titlu' => 'Se trimite: ' . $trimise . ' din ' . $totalPlan . ' au plecat (' . $procent . '%).',
                'text' => 'Restul pleacă automat, la câteva minute. Nu trebuie să apeși nimic și poți închide pagina. Dacă ai văzut o greșeală, apasă „Oprește trimiterea”.',
                'actiuni' => $actiuniTrimitere,
            ]); ?>
            <div class="nlp-progres" aria-hidden="true"><span style="width:<?= $procent ?>%;"></span></div>
        <?php elseif ($nlStare === 'paused'): ?>
            <?php ob_start(); ?>
            <form method="post" action="/admin/emails" style="margin:0;">
                <input type="hidden" name="section" value="newsletter-campaign-send-now">
                <input type="hidden" name="campaign_id" value="<?= (int) $nlId ?>">
                <input type="hidden" name="from_builder" value="1">
                <button type="submit" class="btn">Reia trimiterea</button>
            </form>
            <?php $reia = (string) ob_get_clean(); ?>
            <?php admin_componenta('mesaj', [
                'tip' => 'eroare',
                'titlu' => 'Trimiterea s-a oprit' . ($trimise > 0 ? ' după ' . $trimise . ' emailuri' : '') . '.',
                'text' => ($nlEroare !== '' ? $nlEroare . ' ' : '')
                    . (str_starts_with($nlEroare, 'Oprit de mână')
                        ? 'Când ești gata, apasă „Reia trimiterea”; pentru o variantă corectată, folosește „Duplică”.'
                        : 'Verifică setările din Email-uri → Setări trimitere (sau cere ajutor), apoi apasă „Reia trimiterea”.')
                    . ' Cei care l-au primit deja nu-l primesc a doua oară.',
                'actiuni' => $reia,
            ]); ?>
        <?php elseif ($nlStare === 'scheduled' && $nlProgramat !== ''): ?>
            <?php ob_start(); ?>
            <form method="post" action="/admin/emails" style="margin:0;">
                <input type="hidden" name="section" value="newsletter-campaign-unschedule">
                <input type="hidden" name="campaign_id" value="<?= (int) $nlId ?>">
                <input type="hidden" name="from_builder" value="1">
                <button type="submit" class="btn btn-secondary">Anulează programarea</button>
            </form>
            <?php $anuleaza = (string) ob_get_clean(); ?>
            <?php admin_componenta('mesaj', [
                'tip' => 'info',
                'titlu' => 'Programat pentru ' . date('d.m.Y, H:i', (int) strtotime($nlProgramat)) . '.',
                'text' => 'Pleacă singur la ora aceea. Până atunci îl poți modifica (salvează după modificări) sau poți anula programarea.',
                'actiuni' => $anuleaza,
            ]); ?>
        <?php endif; ?>

        <div id="nlp-blocat" hidden>
            <?php admin_componenta('mesaj', [
                'tip' => 'eroare',
                'titlu' => 'Mai sunt probleme de reparat',
                'text' => 'Lista de verificare are puncte roșii. Repară-le, apoi revino aici.',
                'actiuni' => '<button type="button" class="btn btn-secondary" data-mergi-la="3">Mergi la verificare</button>',
            ]); ?>
        </div>

        <?php admin_componenta('panou-lateral', ['id' => 'nlp-panou-rezumat', 'titlu' => 'Rezumat', 'continut' => $panouRezumat]); ?>

        <?php if ($nlPoateModifica && $nlStare !== 'paused'): ?>
            <div class="nlp-optiuni" style="margin-top:14px;">
                <?php ob_start(); ?>
                <form method="post" action="/admin/emails" id="nlp-form-trimite" data-nlp-salveaza="1" data-nlp-confirma="1">
                    <input type="hidden" name="section" value="newsletter-campaign-send-now">
                    <input type="hidden" name="campaign_id" value="<?= (int) $nlId ?>">
                    <input type="hidden" name="from_builder" value="1">
                    <p style="margin:0 0 12px;color:#475569;">Pleacă acum la toți abonații aleși.</p>
                    <button type="submit" class="btn nlp-btn-mare" data-nlp-trimite>Trimite acum</button>
                </form>
                <?php admin_componenta('panou-lateral', ['id' => 'nlp-panou-acum', 'titlu' => 'Trimite acum', 'continut' => (string) ob_get_clean()]); ?>

                <?php ob_start(); ?>
                <form method="post" action="/admin/emails" id="nlp-form-programeaza" data-nlp-salveaza="1">
                    <input type="hidden" name="section" value="newsletter-campaign-schedule">
                    <input type="hidden" name="campaign_id" value="<?= (int) $nlId ?>">
                    <input type="hidden" name="from_builder" value="1">
                    <div class="nlp-camp">
                        <label for="nlp-data-programare">Ziua și ora (ora României)</label>
                        <input type="datetime-local" id="nlp-data-programare" name="scheduled_at" required
                               min="<?= adm_e($minimProgramare) ?>" value="<?= adm_e($valoareProgramare) ?>">
                    </div>
                    <button type="submit" class="btn btn-secondary nlp-btn-mare" data-nlp-trimite>Programează</button>
                </form>
                <?php admin_componenta('panou-lateral', ['id' => 'nlp-panou-programeaza', 'titlu' => 'Programează', 'continut' => (string) ob_get_clean()]); ?>
            </div>
        <?php endif; ?>

        <div class="nlp-actiuni">
            <button type="button" class="btn btn-secondary" data-mergi-la="3">← Înapoi la verificare</button>
            <a class="btn btn-secondary" href="/admin/emails/newsletters?tab=campaigns" id="nlp-la-lista">Gata, înapoi la lista de newslettere</a>
        </div>
    </div>
</section>
