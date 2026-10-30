<?php
/**
 * Pasul 2 din builderul de newsletter: cui se trimite.
 *
 * @var int   $nlId
 * @var array $nlListe      listele, cu active_count
 * @var int[] $nlListeAlese
 * @var bool  $nlPoateModifica
 */
$randuri = [];
foreach ($nlListe as $lista) {
    $lid = (int) ($lista['id'] ?? 0);
    $activi = (int) ($lista['active_count'] ?? $lista['subscribers_count'] ?? 0);
    $ales = in_array($lid, $nlListeAlese, true);
    $randuri[] = [
        'atribute' => 'class="' . ($ales ? 'este-ales' : '') . '"',
        'celule' => [
            '<label><input type="checkbox" class="nlp-lista" value="' . $lid . '" data-activi="' . $activi . '"'
                . ($ales ? ' checked' : '') . ($nlPoateModifica ? '' : ' disabled') . '> '
                . '<span>' . adm_e($lista['name'] ?? '') . ((int) ($lista['is_default'] ?? 0) === 1 ? ' <small style="color:#64748b;">(toată lumea)</small>' : '') . '</span></label>',
            '<strong>' . number_format($activi, 0, ',', '.') . '</strong>',
        ],
    ];
}
?>
<section class="nlp-pas" id="nlp-pas-2" data-pas-panou="2" hidden>
    <div class="nlp-continut" style="max-width:760px;">
        <?php admin_componenta('antet-pagina', [
            'id' => 'nlp-antet-destinatari',
            'titlu' => 'Cui trimiți newsletterul?',
            'subtitlu' => 'Bifează una sau mai multe liste. Cine e în mai multe liste îl primește o singură dată.',
            'ajutor' => [
                'O listă e un grup de abonați (de exemplu „Clienți fideli”). Lista „Toți abonații” îi conține pe toți.',
                'Se numără doar abonații activi. Cine s-a dezabonat nu primește nimic, chiar dacă e în listă.',
                'Listele le creezi și le umpli din Email-uri → Newslettere → Liste abonați.',
            ],
        ]); ?>
        <?php admin_componenta('tabel', [
            'id' => 'nlp-tabel-liste',
            'legenda' => 'Listele de abonați',
            'coloane' => ['Listă', 'Abonați activi'],
            'randuri' => $randuri,
            'gol' => (static function (): string {
                ob_start();
                admin_componenta('stare-goala', [
                    'titlu' => 'Nu ai încă nicio listă de abonați',
                    'text' => 'Abonații ajung singuri în lista „Toți abonații” când se înscriu pe site. Poți și să-i imporți dintr-un fișier.',
                    'actiune' => '<a class="btn btn-secondary" href="/admin/emails/newsletters?tab=subscribers">Mergi la Liste abonați</a>',
                ]);
                return (string) ob_get_clean();
            })(),
        ]); ?>
        <p class="nlp-total" aria-live="polite">Vor primi newsletterul: <strong id="nlp-total-destinatari">…</strong></p>
        <div class="nlp-actiuni">
            <button type="button" class="btn btn-secondary" data-mergi-la="1">← Înapoi la conținut</button>
            <button type="button" class="btn" data-mergi-la="3">Continuă la verificare →</button>
        </div>
    </div>
</section>
